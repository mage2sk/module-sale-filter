<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\Layer;

use Panth\SaleFilter\Model\Config;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Eav\Model\Config as EavConfig;
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
        private readonly LoggerInterface $logger,
        private readonly StockHelper $stockHelper,
        private readonly EavConfig $eavConfig
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
            // resolveFilteredIds now applies the stock filter AND mirrors
            // every other active layered-nav filter, so COUNT_FLAG ends up
            // matching the grid's actual row count — no separate post-
            // intersection pass needed.
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

        // Respect the merchant's out-of-stock display setting. When
        // `cataloginventory/options/show_out_of_stock = 0` the helper joins
        // `cataloginventory_stock_status` and filters to `stock_status = 1`,
        // matching what Magento core does to the grid's own collection via
        // `CatalogInventory\Model\Plugin\LayerPreparation`. When the merchant
        // shows OOS, this is a no-op and the count includes OOS rows just
        // like the grid does.
        $this->stockHelper->addIsInStockFilterToCollection($collection);

        // Mirror every other active layered-nav filter (brand, price, custom
        // attribute, drill-down categories) so the toolbar's "X Items" total
        // matches the grid once the shopper has stacked filters.
        $this->mirrorActiveLayerFilters($subject, $collection);

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
     * Re-apply every currently-active sibling layer filter onto the fresh
     * count collection.
     *
     * Why we read the REQUEST, not {@see Layer::getState()}:
     *   `afterGetProductCollection` runs when the GRID first reads the
     *   collection — which on Magento 2.4 happens BEFORE the layered-
     *   navigation block renders and therefore BEFORE every sibling
     *   filter's `apply()` populates layer state. At plugin time the
     *   state has at most our own SaleFilter; asking the state for
     *   "other active filters" always returns empty, so a state-based
     *   mirror would silently skip the filter the shopper just clicked.
     *
     *   The storefront request carries every active filter as a URL
     *   parameter (`?color=49&pattern=196&price=30-50&cat=27`). We
     *   iterate those params, skip our own and the reserved toolbar
     *   keys (p, limit, mode, order, dir), resolve each to an attribute
     *   via the eav config, and translate it the same way each filter's
     *   own `apply()` would have done.
     *
     *   In the filter class (SaleFilter::mirrorActiveLayerFilters) we
     *   can read state because the sidebar renders AFTER every other
     *   filter has applied. Both paths converge on the same EAV-index +
     *   super-link approach for attribute values.
     */
    private function mirrorActiveLayerFilters(Layer $subject, ProductCollection $collection): void
    {
        $reserved = [
            Config::FILTER_REQUEST_VAR, // our sale_filter
            'p', 'page', 'limit', 'product_list_limit',
            'mode', 'product_list_mode',
            'order', 'product_list_order',
            'dir', 'product_list_dir',
            'q', 'id',
        ];

        $params = [];
        try {
            $params = (array) $this->request->getParams();
        } catch (\Throwable) {
            return;
        }

        foreach ($params as $key => $value) {
            if (!is_string($key) || $key === '' || in_array($key, $reserved, true)) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }

            try {
                if ($key === 'price') {
                    $parts = explode('-', (string) $value);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    [$min, $max] = $parts;
                    if ($min !== '' && is_numeric($min)) {
                        $collection->addFieldToFilter('price', ['gteq' => (float) $min]);
                    }
                    if ($max !== '' && is_numeric($max)) {
                        $collection->addFieldToFilter('price', ['lt' => (float) $max]);
                    }
                    continue;
                }

                if ($key === 'cat') {
                    $catId = (int) $value;
                    if ($catId > 0) {
                        $collection->addCategoriesFilter(['in' => [$catId]]);
                    }
                    continue;
                }

                $attribute = $this->resolveFilterableAttribute($key);
                if ($attribute === null) {
                    continue;
                }
                $this->applyAttributeFilterViaEavIndex(
                    $collection,
                    (int) $attribute->getId(),
                    $value
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'Panth SaleFilter: could not mirror request param "%s" (%s)',
                        $key,
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Resolve a storefront filter-param key to a product attribute model
     * if it maps to a layered-nav-filterable EAV attribute. Returns null
     * for unknown keys so unrelated params (UTM, tracking, etc.) are
     * ignored without triggering an error.
     */
    private function resolveFilterableAttribute(string $code): ?\Magento\Catalog\Model\ResourceModel\Eav\Attribute
    {
        try {
            $attribute = $this->eavConfig->getAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $code
            );
        } catch (\Throwable) {
            return null;
        }

        if (!$attribute || !$attribute->getId()) {
            return null;
        }
        // Only layered-nav-filterable attributes participate — a
        // non-filterable attribute like `url_key` arriving as a param
        // (search form edge case) would otherwise try to apply.
        if ((int) $attribute->getIsFilterable() === 0
            && (int) $attribute->getIsFilterableInSearch() === 0
        ) {
            return null;
        }

        return $attribute;
    }

    /**
     * Apply a layered-nav attribute filter (brand, color, size, …) to the
     * fresh count collection using `catalog_product_index_eav` so super-
     * attribute values on configurable products are matched on the parent
     * rows too. Duplicates {@see \Panth\SaleFilter\Model\Layer\Filter\SaleFilter::applyAttributeFilterViaEavIndex}
     * — see that method's docblock for the full rationale. Kept separate
     * here because sharing would require a trait or helper for an
     * infrequently-touched 20-line block.
     */
    private function applyAttributeFilterViaEavIndex(
        ProductCollection $collection,
        int $attributeId,
        mixed $value
    ): void {
        $values = is_array($value) ? $value : [$value];
        $values = array_values(array_filter(array_map(
            static fn ($v) => is_scalar($v) ? (int) $v : 0,
            $values
        ), static fn (int $v): bool => $v > 0));

        if ($values === []) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $eavTable = $this->resourceConnection->getTableName('catalog_product_index_eav');
        $superLinkTable = $this->resourceConnection->getTableName('catalog_product_super_link');

        $select = $connection->select()
            ->from(['idx' => $eavTable], ['entity_id'])
            ->where('idx.attribute_id = ?', $attributeId)
            ->where('idx.value IN (?)', $values)
            ->distinct(true);
        $matchingIds = array_map('intval', $connection->fetchCol($select));

        if ($matchingIds !== []) {
            // Widen to configurable parents via super-link so super-
            // attribute hits on children (color on configurable_simple)
            // also count the parent rows the grid actually renders.
            $parentSelect = $connection->select()
                ->from(['sl' => $superLinkTable], ['parent_id'])
                ->where('sl.product_id IN (?)', $matchingIds)
                ->distinct(true);
            $parents = array_map('intval', $connection->fetchCol($parentSelect));
            if ($parents !== []) {
                $matchingIds = array_unique(array_merge($matchingIds, $parents));
            }
        }

        // When the EAV index holds no rows for this attribute/value on
        // either children or parents (sample data sometimes misses super-
        // attribute index entries), OR when intersecting with the
        // collection's current row set would zero it out, skip the
        // constraint rather than force an empty set. A slightly-wider
        // count is better than a silently broken filter.
        if ($matchingIds === []
            || !$this->intersectionHasRows($collection, $matchingIds)
        ) {
            return;
        }

        $collection->addFieldToFilter('entity_id', ['in' => $matchingIds]);
    }

    /**
     * Clone the count collection, apply the candidate id filter, read
     * `getSize()`. Used to ensure we don't zero out the collection by
     * mirroring a super-attribute filter whose EAV index rows live on
     * children outside the current category scope.
     *
     * @param int[] $ids
     */
    private function intersectionHasRows(ProductCollection $collection, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        try {
            $probe = clone $collection;
            $probe->addFieldToFilter('entity_id', ['in' => $ids]);
            return ((int) $probe->getSize()) > 0;
        } catch (\Throwable) {
            return false;
        }
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
