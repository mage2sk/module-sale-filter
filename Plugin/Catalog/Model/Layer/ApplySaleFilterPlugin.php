<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\Layer;

use Panth\SaleFilter\Model\Config;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Apply the "On Sale" / "Regular" filter to the layer's product collection on
 * the very first `getProductCollection()` call.
 *
 * Why not `AbstractFilter::apply()`:
 *   That hook fires from the sidebar navigation block, which renders *after*
 *   the toolbar has already called `$collection->getSize()` for the pager.
 *   Waiting for `apply()` means the pager caches the unfiltered total.
 *
 * Why not `addFieldToFilter('entity_id', ['in' => …])`:
 *   The default category/search collection in Magento 2.4+ is the ES-backed
 *   `Fulltext\Collection`, which routes `addFieldToFilter` through the
 *   SearchCriteria builder. `entity_id` isn't a filterable ES field so the
 *   filter is silently dropped and the grid renders unfiltered.
 *
 * Why not just `getSelect()->where('e.entity_id IN (…)', $ids)`:
 *   That correctly restricts the grid (via SQL) on a non-ES collection, but
 *   on Fulltext the default `SearchResultApplier` already added a
 *   `WHERE e.entity_id IN (page-slice-ids)` for the current page. The
 *   intersection typically has 4-8 products, not the full page-size 12.
 *   Also `getSize()` on a Fulltext collection comes from the ES search-
 *   result count, which is unaffected by any SQL WHERE we add.
 *
 * What we do:
 *   1. Compute the exact list of entity_ids that should appear — intersected
 *      with the current category + visibility — in a single SQL round-trip
 *      against `catalog_category_product_index_store{N}` JOIN our on-sale
 *      index. Store the list as `ITEMS_FLAG` and its size as `COUNT_FLAG`.
 *   2. Tag the grid collection with `WHERE e.entity_id IN (…)` as a safety
 *      net for any code path that skips the ES applier (non-Fulltext
 *      collections, custom widgets). On Fulltext the SearchResultApplier
 *      override reads `ITEMS_FLAG` and filters before paging slicing.
 *   3. `GetSizePlugin` reads `COUNT_FLAG` and returns the post-filter total
 *      instead of the unfiltered ES count.
 *
 * A flag on the collection keeps the plugin idempotent — `Layer::getProduct-
 * Collection()` is called dozens of times per request.
 */
class ApplySaleFilterPlugin
{
    public const APPLIED_FLAG = 'panth_salefilter_applied';
    public const ITEMS_FLAG   = 'panth_salefilter_ids';
    public const COUNT_FLAG   = 'panth_salefilter_size';

    /**
     * @param RequestInterface $request
     * @param Config $config
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param CustomerSession $customerSession
     * @param ProductVisibility $productVisibility
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly ProductVisibility $productVisibility,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Layer $subject
     * @param mixed $collection
     * @return mixed
     */
    public function afterGetProductCollection(Layer $subject, $collection)
    {
        if (!$collection instanceof ProductCollection) {
            return $collection;
        }
        if ($collection->getFlag(self::APPLIED_FLAG)) {
            return $collection;
        }
        $collection->setFlag(self::APPLIED_FLAG, true);

        if (!$this->config->isEnabled()) {
            return $collection;
        }

        $raw = $this->request->getParam(Config::FILTER_REQUEST_VAR);
        if ($raw === null || $raw === '') {
            return $collection;
        }

        $value = (int) $raw;
        if ($value !== Config::VALUE_ON_SALE && $value !== Config::VALUE_NOT_ON_SALE) {
            return $collection;
        }
        if ($value === Config::VALUE_NOT_ON_SALE && !$this->config->isShowNotOnSaleOption()) {
            return $collection;
        }

        try {
            $allowedIds = $this->resolveFilteredIds($subject, $value);

            $collection->setFlag(self::ITEMS_FLAG, $allowedIds);
            $collection->setFlag(self::COUNT_FLAG, count($allowedIds));

            // Safety net for non-Fulltext product collections (where the
            // custom SearchResultApplier doesn't run). Harmless on Fulltext
            // because the applier's own WHERE is always a subset of this.
            $collection->getSelect()->where('e.entity_id IN (?)', $allowedIds ?: [0]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth SaleFilter: plugin failed to apply filter (%s)', $e->getMessage()),
                ['exception' => $e]
            );
        }

        return $collection;
    }

    /**
     * Return the ordered list of visible, in-scope product ids that match the
     * selected sale status.
     *
     * Category layer: join `catalog_category_product_index_store{N}` with our
     * on-sale index, scoped to the current category and the layer's
     * visibility filter. One SQL round-trip; no collection bootstrap.
     *
     * Non-category layer (search / widget): fall back to the raw on-sale id
     * list for `?sale_filter=1`. For `?sale_filter=0` on search we return an
     * empty list — computing "all visible products minus on-sale" storewide
     * is expensive and of little value because search results are already
     * relevance-ranked. Search users rarely need a regular-price filter.
     *
     * @param Layer $subject
     * @param int $value
     * @return array<int, int>
     */
    private function resolveFilteredIds(Layer $subject, int $value): array
    {
        $category   = $subject->getCurrentCategory();
        $categoryId = ($category && (int) $category->getId() > 0 && (int) $category->getLevel() >= 2)
            ? (int) $category->getId()
            : null;

        $connection      = $this->resourceConnection->getConnection();
        $indexTable      = $this->resourceConnection->getTableName('panth_salefilter_product_index');
        $customerGroupId = (int) $this->customerSession->getCustomerGroupId();
        $websiteId       = (int) $this->storeManager->getStore()->getWebsiteId();
        $storeId         = (int) $this->storeManager->getStore()->getId();

        if ($categoryId === null) {
            if ($value === Config::VALUE_ON_SALE) {
                return $this->fetchOnSaleIds();
            }
            return [];
        }

        $catTable = $this->resourceConnection->getTableName(
            sprintf('catalog_category_product_index_store%d', $storeId)
        );

        $visibility = $this->productVisibility->getVisibleInCatalogIds();

        $joinCondition = sprintf(
            'idx.entity_id = cci.product_id'
            . ' AND idx.customer_group_id = %d'
            . ' AND idx.website_id = %d'
            . ' AND idx.is_on_sale = 1',
            $customerGroupId,
            $websiteId
        );

        if ($value === Config::VALUE_ON_SALE) {
            $select = $connection->select()
                ->from(['cci' => $catTable], ['product_id'])
                ->join(['idx' => $indexTable], $joinCondition, [])
                ->where('cci.category_id = ?', $categoryId)
                ->where('cci.visibility IN (?)', $visibility)
                ->order('cci.position ASC')
                ->distinct(true);
        } else {
            $select = $connection->select()
                ->from(['cci' => $catTable], ['product_id'])
                ->joinLeft(['idx' => $indexTable], $joinCondition, [])
                ->where('cci.category_id = ?', $categoryId)
                ->where('cci.visibility IN (?)', $visibility)
                ->where('idx.entity_id IS NULL')
                ->order('cci.position ASC')
                ->distinct(true);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * @return array<int, int>
     */
    private function fetchOnSaleIds(): array
    {
        $connection      = $this->resourceConnection->getConnection();
        $table           = $this->resourceConnection->getTableName('panth_salefilter_product_index');
        $customerGroupId = (int) $this->customerSession->getCustomerGroupId();
        $websiteId       = (int) $this->storeManager->getStore()->getWebsiteId();

        $select = $connection->select()
            ->from(['idx' => $table], ['entity_id'])
            ->where('idx.customer_group_id = ?', $customerGroupId)
            ->where('idx.website_id = ?', $websiteId)
            ->where('idx.is_on_sale = ?', 1);

        return array_map('intval', $connection->fetchCol($select));
    }
}
