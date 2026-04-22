<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Layer\Filter;

use Panth\SaleFilter\Model\Config;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Layered-navigation filter driven by the {@code panth_salefilter_product_index}
 * table.
 *
 * Two modes are supported via config (panth_salefilter/general/show_not_on_sale_option):
 *   - single option  (default): shows only the "On Sale" option
 *   - two options             : adds a second "Regular Price" (not-on-sale) option
 *     so shoppers can toggle between discounted and full-price products.
 *
 * The request param {@code sale_filter} holds the selection:
 *   - "1" -> constrain the layer's product collection to on-sale rows
 *   - "0" -> constrain it to NOT-on-sale rows (only honored when the
 *            "Show Not On Sale Option" config is enabled)
 *
 * Filtering is an INNER/LEFT JOIN against the index table (never a correlated
 * subquery) so pagination and facet counts remain intact.
 */
class SaleFilter extends AbstractFilter
{
    public function __construct(
        ItemFactory $filterItemFactory,
        StoreManagerInterface $storeManager,
        Layer $layer,
        DataBuilder $itemDataBuilder,
        private readonly CustomerSession $customerSession,
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductVisibility $productVisibility,
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory,
            $storeManager,
            $layer,
            $itemDataBuilder,
            $data
        );
        $this->_requestVar = Config::FILTER_REQUEST_VAR;
    }

    /**
     * Apply the selected option to the layer's product collection.
     */
    public function apply(RequestInterface $request)
    {
        $raw = $request->getParam($this->_requestVar);
        if ($raw === null || $raw === '') {
            return $this;
        }

        $value = (int) $raw;
        if ($value !== Config::VALUE_ON_SALE && $value !== Config::VALUE_NOT_ON_SALE) {
            return $this;
        }

        // "Not on sale" is only honored when admin enabled that option.
        if ($value === Config::VALUE_NOT_ON_SALE && !$this->config->isShowNotOnSaleOption()) {
            return $this;
        }

        try {
            $websiteId       = (int) $this->_storeManager->getStore()->getWebsiteId();
            $customerGroupId = (int) $this->customerSession->getCustomerGroupId();

            $collection = $this->getLayer()->getProductCollection();
            $table      = $this->resourceConnection->getTableName('panth_salefilter_product_index');

            if ($value === Config::VALUE_ON_SALE) {
                // INNER JOIN against the index — returns only on-sale products.
                $collection->getSelect()->join(
                    ['salefilter_idx' => $table],
                    sprintf(
                        'salefilter_idx.entity_id = e.entity_id'
                        . ' AND salefilter_idx.customer_group_id = %d'
                        . ' AND salefilter_idx.website_id = %d'
                        . ' AND salefilter_idx.is_on_sale = 1',
                        $customerGroupId,
                        $websiteId
                    ),
                    []
                );
                $label = $this->config->getOnSaleOptionLabel();
            } else {
                // LEFT JOIN + NULL check — returns products missing from the index,
                // i.e. not on sale for this (group, website).
                $collection->getSelect()->joinLeft(
                    ['salefilter_idx' => $table],
                    sprintf(
                        'salefilter_idx.entity_id = e.entity_id'
                        . ' AND salefilter_idx.customer_group_id = %d'
                        . ' AND salefilter_idx.website_id = %d'
                        . ' AND salefilter_idx.is_on_sale = 1',
                        $customerGroupId,
                        $websiteId
                    ),
                    []
                )->where('salefilter_idx.entity_id IS NULL');
                $label = $this->config->getNotOnSaleOptionLabel();
            }

            /** @var \Magento\Catalog\Model\Layer\Filter\Item $filterItem */
            $filterItem = $this->_filterItemFactory->create()
                ->setFilter($this)
                ->setLabel($label)
                ->setValue($value)
                ->setCount(0);

            $this->getLayer()->getState()->addFilter($filterItem);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth SaleFilter: unable to apply filter (%s)', $e->getMessage()),
                ['exception' => $e]
            );
        }

        return $this;
    }

    /**
     * Filter header title (renders above the option list and in breadcrumbs).
     */
    public function getName()
    {
        return $this->config->getFilterLabel();
    }

    /**
     * Build the sidebar items (options) for this filter.
     *
     * Always emits the "On Sale" option when count > 0. When the admin has
     * enabled the toggle, also emits the "Not On Sale" option when it would
     * match products in the current layer.
     *
     * @return array<int, array{label: string, value: int, count: int}>
     */
    protected function _getItemsData()
    {
        try {
            // If this filter has already been applied, hide the options.
            foreach ($this->getLayer()->getState()->getFilters() as $filter) {
                if ($filter->getFilter() === $this) {
                    return [];
                }
            }

            $items = [];

            $onSaleCount = $this->countForScope(true);
            if ($onSaleCount > 0) {
                $items[] = [
                    'label' => $this->config->getOnSaleOptionLabel(),
                    'value' => Config::VALUE_ON_SALE,
                    'count' => $onSaleCount,
                ];
            }

            // "Not on sale" option only surfaces when
            //   (a) the admin toggle is enabled, AND
            //   (b) there is at least one regular-priced product in the
            //       current layer (clicking a zero-count option just
            //       yields an empty grid, so hide it).
            if ($this->config->isShowNotOnSaleOption()) {
                $notOnSaleCount = $this->countForScope(false);
                if ($notOnSaleCount > 0) {
                    $items[] = [
                        'label' => $this->config->getNotOnSaleOptionLabel(),
                        'value' => Config::VALUE_NOT_ON_SALE,
                        'count' => $notOnSaleCount,
                    ];
                }
            }

            return $items;
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth SaleFilter: unable to build items data (%s)', $e->getMessage()),
                ['exception' => $e]
            );

            return [];
        }
    }

    /**
     * Count products in the current layer that ARE or are NOT on sale.
     *
     * Builds a fresh, non-paginated product collection scoped to the current
     * category / visibility / status — cloning the layer's product collection
     * would inherit the toolbar's `entity_id IN (page-slice)` WHERE clause,
     * so both on-sale and not-on-sale counts would only see the visible page
     * (e.g. 12 of 24 products).
     *
     * Uses a per-scope alias so successive on-sale + not-on-sale calls in
     * the same request never collide on correlation name.
     *
     * @param bool $onSale true counts on-sale, false counts not-on-sale.
     */
    private function countForScope(bool $onSale): int
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $collection->setVisibility($this->productVisibility->getVisibleInCatalogIds());

        $category = $this->getLayer()->getCurrentCategory();
        if ($category && (int) $category->getId() > 0 && (int) $category->getLevel() >= 2) {
            $collection->addCategoryFilter($category);
        }

        $select = clone $collection->getSelect();
        $select->reset(Select::COLUMNS);
        $select->reset(Select::ORDER);
        $select->reset(Select::LIMIT_COUNT);
        $select->reset(Select::LIMIT_OFFSET);

        $customerGroupId = (int) $this->customerSession->getCustomerGroupId();
        $websiteId       = (int) $this->_storeManager->getStore()->getWebsiteId();
        $table           = $this->resourceConnection->getTableName('panth_salefilter_product_index');
        $alias           = $onSale ? 'panth_sf_yes' : 'panth_sf_no';

        $join = sprintf(
            '%s.entity_id = e.entity_id'
            . ' AND %s.customer_group_id = %d'
            . ' AND %s.website_id = %d'
            . ' AND %s.is_on_sale = 1',
            $alias, $alias, $customerGroupId, $alias, $websiteId, $alias
        );

        if ($onSale) {
            $select->join([$alias => $table], $join, []);
        } else {
            $select->joinLeft([$alias => $table], $join, [])
                ->where(sprintf('%s.entity_id IS NULL', $alias));
        }

        $select->columns('COUNT(DISTINCT e.entity_id) AS cnt');

        return (int) $this->resourceConnection->getConnection()->fetchOne($select);
    }
}
