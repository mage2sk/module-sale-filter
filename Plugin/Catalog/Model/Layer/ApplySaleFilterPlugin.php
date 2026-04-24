<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\Layer;

use Panth\SaleFilter\Model\Config;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
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
     * Customer group is read from {@see HttpContext} rather than the
     * customer session because Magento's {@see \Magento\Customer\Model\Layout\DepersonalizePlugin}
     * resets the customer session to guest BEFORE cacheable pages render,
     * so `$customerSession->getCustomerGroupId()` always returns 0 during
     * category view. The HTTP Context value is populated by
     * {@see \Magento\Customer\Model\App\Action\ContextPlugin::beforeExecute}
     * and survives depersonalization — matching what the FPC's X-Magento-Vary
     * cookie is hashed from, so per-group filter output is consistent with
     * the per-group FPC cache entry.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpContext $httpContext,
        private readonly ProductVisibility $productVisibility,
        private readonly ProductCollectionFactory $productCollectionFactory,
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

            // Intersect with the layer's ALREADY-filtered id set so stock
            // filtering (`show_out_of_stock = 0`) and other active layered-
            // nav filters are honoured in both the grid and the toolbar's
            // `X Items` count. Without this step the raw on-sale id list
            // inflates COUNT_FLAG above the pager's real row count — the
            // shopper sees "69 Items" in the header while paging through
            // only 48 rows because the 21 OOS ones get stripped later.
            $allowedIds = $this->intersectWithLayerIds($collection, $allowedIds);

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
     * Intersect a list of on-sale ids with the ids the layer's collection
     * would actually render — i.e. after stock filtering and every other
     * active layered-nav filter.
     *
     * We read the layer's already-filtered id set via {@see ProductCollection::getAllIds()}
     * BEFORE we mutate the SELECT with our own `WHERE entity_id IN (...)`
     * below. That call strips LIMIT/OFFSET/ORDER internally, so it returns
     * every row the grid would paginate through — not the toolbar's current
     * page slice. On a clean ES-backed Fulltext collection it still works
     * because the ES-derived `e.entity_id IN (search-hits)` filter is part
     * of the base SELECT.
     *
     * @param ProductCollection $collection
     * @param array<int, int> $allowedIds
     * @return array<int, int>
     */
    private function intersectWithLayerIds(ProductCollection $collection, array $allowedIds): array
    {
        if ($allowedIds === []) {
            return [];
        }

        try {
            $layerIds = $collection->getAllIds();
        } catch (\Throwable) {
            // If we can't read the layer's visible ids (collection not yet
            // loadable, custom driver, etc.), fall back to the raw on-sale
            // list — better to slightly overcount than to drop the filter.
            return $allowedIds;
        }

        if ($layerIds === []) {
            return [];
        }

        $layerSet = array_flip(array_map('intval', $layerIds));
        $out = [];
        foreach ($allowedIds as $id) {
            if (isset($layerSet[(int) $id])) {
                $out[] = (int) $id;
            }
        }
        return $out;
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
        $category = $subject->getCurrentCategory();
        $hasCategoryScope = $category
            && (int) $category->getId() > 0
            && (int) $category->getLevel() >= 2;

        // Non-category layers (search, widget) fall back to the raw on-sale
        // id list. Computing "all visible products minus on-sale" storewide
        // is expensive and rarely useful outside a category context.
        if (!$hasCategoryScope) {
            return $value === Config::VALUE_ON_SALE ? $this->fetchOnSaleIds() : [];
        }

        $onSaleIds = $this->fetchOnSaleIds();
        // `addFieldToFilter('entity_id', ['in' => []])` is invalid SQL — fall
        // back to a sentinel id that can never match a real product.
        $membership = $onSaleIds ?: [0];

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $collection->setVisibility($this->productVisibility->getVisibleInCatalogIds());
        $collection->addCategoryFilter($category);
        $collection->addFieldToFilter(
            'entity_id',
            [$value === Config::VALUE_ON_SALE ? 'in' : 'nin' => $membership]
        );

        $this->applySort($collection);

        // Iterate once rather than `getAllIds()` — the latter resets ORDER
        // in `_getAllIdsSelect`, losing the sort we just applied. The product
        // collection lazy-loads entity rows only (no heavy attribute EAV hits)
        // so this is fast for normal category sizes.
        $ids = [];
        foreach ($collection as $product) {
            $ids[] = (int) $product->getId();
        }
        return $ids;
    }

    /**
     * Translate the storefront toolbar's sort params (`product_list_order` /
     * `product_list_dir`) onto the product collection. Silently ignores
     * unrecognised sort codes — they'll fall through to the collection's
     * default order (usually category position).
     */
    private function applySort(ProductCollection $collection): void
    {
        $sortAttr = (string) $this->request->getParam('product_list_order', '');
        $sortDir  = strtoupper((string) $this->request->getParam('product_list_dir', 'asc'));
        if ($sortDir !== 'ASC' && $sortDir !== 'DESC') {
            $sortDir = 'ASC';
        }

        // Default (no explicit sort param) = category position. Matches
        // Magento's default Category\Layer sort behaviour.
        if ($sortAttr === '' || $sortAttr === 'position') {
            $collection->addAttributeToSort('position', $sortDir);
            return;
        }

        // Whitelist the common storefront sort codes; price/name are EAV
        // attributes, so `addAttributeToSort` handles the joins correctly.
        if (in_array($sortAttr, ['price', 'name'], true)) {
            $collection->addAttributeToSort($sortAttr, $sortDir);
            return;
        }

        // Unknown sort code: leave the collection's default order in place.
    }

    /**
     * @return array<int, int>
     */
    private function fetchOnSaleIds(): array
    {
        $connection      = $this->resourceConnection->getConnection();
        $table           = $this->resourceConnection->getTableName('panth_salefilter_product_index');
        $customerGroupId = (int) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);
        $websiteId       = (int) $this->storeManager->getStore()->getWebsiteId();

        $select = $connection->select()
            ->from(['idx' => $table], ['entity_id'])
            ->where('idx.customer_group_id = ?', $customerGroupId)
            ->where('idx.website_id = ?', $websiteId)
            ->where('idx.is_on_sale = ?', 1);

        return array_map('intval', $connection->fetchCol($select));
    }
}
