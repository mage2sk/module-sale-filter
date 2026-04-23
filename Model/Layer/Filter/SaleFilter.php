<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Layer\Filter;

use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Layered-navigation filter driven by the {@code panth_salefilter_product_index}
 * table.
 *
 * Two modes are supported via config (panth_salefilter/general/show_not_on_sale_option):
 *   - single option  (default): shows only the "On Sale" option.
 *   - two options             : adds a "Regular" (not-on-sale) option so
 *     shoppers can toggle between discounted and full-price products.
 *
 * The request param {@code sale_filter} holds the selection:
 *   - "1" -> constrain the layer's collection to on-sale rows
 *   - "0" -> constrain it to NOT-on-sale rows (only honored when the
 *            "Show Not On Sale Option" config is enabled)
 *
 * The heavy lifting (computing the filtered id set, tagging the collection,
 * supplying a post-filter size) is done by {@see ApplySaleFilterPlugin} which
 * runs on every `Layer::getProductCollection()` call — early enough to beat
 * the toolbar's `getSize()`. This `apply()` method only registers the state
 * filter item that powers the "Now Shopping by" chip and the sidebar's
 * "hide options when active" logic.
 */
class SaleFilter extends AbstractFilter
{
    /**
     * Customer group is resolved via {@see HttpContext} rather than the
     * customer session — Magento's {@see \Magento\Customer\Model\Layout\DepersonalizePlugin}
     * wipes the session to guest for cacheable pages, so the session
     * always reports group 0 here. HTTP Context keeps the real group id
     * and matches the per-group X-Magento-Vary FPC entry.
     */
    public function __construct(
        ItemFactory $filterItemFactory,
        StoreManagerInterface $storeManager,
        Layer $layer,
        DataBuilder $itemDataBuilder,
        private readonly HttpContext $httpContext,
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
     * Register the selected option as an active state filter so that the
     * "Now Shopping by" block shows a removable chip and `_getItemsData()`
     * knows to hide both options while the filter is active.
     *
     * @param RequestInterface $request
     * @return $this
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

        if ($value === Config::VALUE_NOT_ON_SALE && !$this->config->isShowNotOnSaleOption()) {
            return $this;
        }

        try {
            $label = $value === Config::VALUE_ON_SALE
                ? $this->config->getOnSaleOptionLabel()
                : $this->config->getNotOnSaleOptionLabel();

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
     * @return string
     */
    public function getName()
    {
        return $this->config->getFilterLabel();
    }

    /**
     * Build the sidebar items (options) for this filter.
     *
     * Emits the "On Sale" option when at least one on-sale product exists in
     * the current layer's scope. When the admin has enabled the toggle, also
     * emits the "Not On Sale" option with the remainder-of-scope count.
     *
     * @return array<int, array{label: string, value: int, count: int}>
     */
    protected function _getItemsData()
    {
        try {
            foreach ($this->getLayer()->getState()->getFilters() as $filter) {
                if ($filter->getFilter() === $this) {
                    return [];
                }
            }

            // If the plugin has already stashed a count on the layer's
            // collection, the page IS the filtered result — hide both options
            // (they'd just offer to swap to the already-active choice).
            $collection = $this->getLayer()->getProductCollection();
            if ($collection->getFlag(ApplySaleFilterPlugin::COUNT_FLAG) !== null) {
                return [];
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
     * Count products in the current layer's scope that ARE or are NOT on sale.
     *
     * Builds a fresh, non-paginated product collection scoped to the current
     * category / visibility / status — cloning the layer's product collection
     * would inherit the toolbar's page-slice WHERE and cap counts at page
     * size.
     *
     * @param bool $onSale
     * @return int
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

        $onSaleIds = $this->fetchOnSaleIds() ?: [0];
        $collection->addFieldToFilter('entity_id', [$onSale ? 'in' : 'nin' => $onSaleIds]);

        return (int) $collection->getSize();
    }

    /**
     * @return array<int, int>
     */
    private function fetchOnSaleIds(): array
    {
        $connection      = $this->resourceConnection->getConnection();
        $table           = $this->resourceConnection->getTableName('panth_salefilter_product_index');
        $customerGroupId = (int) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);
        $websiteId       = (int) $this->_storeManager->getStore()->getWebsiteId();

        $select = $connection->select()
            ->from(['idx' => $table], ['entity_id'])
            ->where('idx.customer_group_id = ?', $customerGroupId)
            ->where('idx.website_id = ?', $websiteId)
            ->where('idx.is_on_sale = ?', 1);

        return array_map('intval', $connection->fetchCol($select));
    }
}
