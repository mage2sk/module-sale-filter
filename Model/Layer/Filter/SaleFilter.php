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
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
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
        private readonly StockHelper $stockHelper,
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
     * Uses the layer's own product collection as the source of truth so the
     * count reflects EVERY constraint the grid itself applies:
     *
     *   - category / visibility / status (applied by Magento core in
     *     {@see \Magento\Catalog\Model\Layer\Category::prepareProductCollection})
     *   - stock filter when `cataloginventory/options/show_out_of_stock = 0`
     *     (applied by {@see \Magento\CatalogInventory\Model\Plugin\LayerPreparation})
     *   - every OTHER active layered-nav filter (price range, brand,
     *     custom attribute filters) — applied by each sibling filter's
     *     {@see AbstractFilter::apply()} during page bootstrap
     *
     * Previously this method built a fresh, isolated product collection
     * scoped only to category + visibility + status. Two bugs followed:
     *
     *   1. Out-of-stock on-sale products inflated the count when the
     *      merchant had `show_out_of_stock = 0` — a category with 48
     *      in-stock on-sale items displayed "On Sale (69)" in the sidebar.
     *   2. Activating any other filter (brand = Nike, etc.) didn't shift
     *      the on-sale count — the sidebar still showed the full-category
     *      total instead of the intersection with the active filter.
     *
     * Intersecting the layer's already-filtered id set with our on-sale
     * index fixes both at once and stays consistent regardless of how many
     * filters the shopper stacks.
     *
     * @param bool $onSale
     * @return int
     */
    private function countForScope(bool $onSale): int
    {
        try {
            $collection = $this->buildCountCollection();
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Panth SaleFilter: unable to build count collection (%s)', $e->getMessage()),
                ['exception' => $e]
            );
            return 0;
        }

        $onSaleIds = $this->fetchOnSaleIds() ?: [0];
        $collection->addFieldToFilter(
            'entity_id',
            [$onSale ? 'in' : 'nin' => $onSaleIds]
        );

        return (int) $collection->getSize();
    }

    /**
     * Build a fresh product collection that mirrors every filter the grid
     * applies for the current layer — without the toolbar's page-slice or
     * the ES-applier's `e.entity_id IN (current-page-ids)` clause that
     * would cap the count at page size.
     *
     * We deliberately do NOT read {@see Layer::getProductCollection()}
     * directly. When Magento's search engine is Elasticsearch (the default
     * on 2.4.x), a category page renders through
     * {@see \Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection}
     * whose {@see \Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection\SearchResultApplier}
     * pre-narrows the SELECT to just the CURRENT page's ids before any
     * child code sees the collection — so `getAllIds()` there would
     * return 12 (page size) instead of the 25 ES-matched total, silently
     * undercounting the sidebar.
     *
     * Instead we build a fresh non-Fulltext product collection and layer
     * on exactly the constraints the grid applies:
     *
     *   - status enabled + visibility in-catalog (matches core prep)
     *   - category filter for level-2+ categories
     *   - stock filter respecting `cataloginventory/options/show_out_of_stock`
     *     via {@see StockHelper::addIsInStockFilterToCollection} — applies
     *     the `cataloginventory_stock_status` join when merchants hide OOS
     *     and becomes a no-op when they show it.
     *   - every OTHER active layered-nav filter mirrored from the layer's
     *     state (attribute / decimal / price / category filters) so the
     *     count shifts when the shopper has Brand = Nike or a price range
     *     active.
     *
     * The caller then adds our on-sale / not-on-sale id membership and
     * reads `getSize()` for the final count — which is a pure COUNT(*)
     * query on the resulting SELECT, independent of paging.
     */
    private function buildCountCollection(): ProductCollection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $collection->setVisibility($this->productVisibility->getVisibleInCatalogIds());

        $category = $this->getLayer()->getCurrentCategory();
        if ($category && (int) $category->getId() > 0 && (int) $category->getLevel() >= 2) {
            $collection->addCategoryFilter($category);
        }

        // Respect `show_out_of_stock`. When the merchant hides OOS products
        // (the Magento default), the helper joins `cataloginventory_stock_status`
        // and filters to `stock_status = 1`. When they show OOS, this is a
        // no-op so the count matches the grid which also includes OOS rows.
        $this->stockHelper->addIsInStockFilterToCollection($collection);

        $this->mirrorActiveLayerFilters($collection);

        return $collection;
    }

    /**
     * Re-apply every currently-active sibling layer filter (brand, price,
     * custom attribute, manually-added category drill-down, …) onto the
     * given count collection so its size matches what the grid renders.
     *
     * The layer's own collection had each filter's `apply()` run against
     * it during page bootstrap. We can't cleanly share that state — the
     * Fulltext pipeline mutates its SELECT in ways the counter can't
     * reverse — so we re-interpret each filter item's selected value and
     * add the equivalent constraint to this fresh collection.
     */
    private function mirrorActiveLayerFilters(ProductCollection $collection): void
    {
        $state = $this->getLayer()->getState();
        if ($state === null) {
            return;
        }

        foreach ($state->getFilters() as $item) {
            $filter = $item->getFilter();
            // Always skip self — the whole point of this count is to
            // compute the hypothetical result of toggling OUR filter, so
            // applying the current selection would produce a 100%-self
            // intersection.
            if ($filter === $this || $filter === null) {
                continue;
            }

            try {
                if ($filter instanceof \Magento\Catalog\Model\Layer\Filter\Price) {
                    $this->applyPriceFilter($collection, $item->getValue());
                } elseif ($filter instanceof \Magento\Catalog\Model\Layer\Filter\Decimal) {
                    $this->applyPriceFilter($collection, $item->getValue());
                } elseif ($filter instanceof \Magento\CatalogSearch\Model\Layer\Filter\Attribute
                    || $filter instanceof \Magento\Catalog\Model\Layer\Filter\Attribute
                ) {
                    $attribute = $filter->getAttributeModel();
                    if ($attribute && $attribute->getId()) {
                        $this->applyAttributeFilterViaEavIndex(
                            $collection,
                            (int) $attribute->getId(),
                            $item->getValue()
                        );
                    }
                } elseif ($filter instanceof \Magento\Catalog\Model\Layer\Filter\Category) {
                    $catId = (int) $item->getValue();
                    if ($catId > 0) {
                        $collection->addCategoriesFilter(['in' => [$catId]]);
                    }
                }
            } catch (\Throwable $e) {
                // Don't let a single unrecognised filter type break the
                // entire count. Log and carry on with a slightly-wider
                // count — strictly better than the pre-fix behaviour.
                $this->logger->warning(
                    sprintf(
                        'Panth SaleFilter: could not mirror %s filter (%s)',
                        get_class($filter),
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Apply a layered-nav attribute filter (brand, color, size, …) to the
     * count collection using `catalog_product_index_eav`.
     *
     * Why not `$collection->addAttributeToFilter($code, $value)` —
     * that joins the attribute's EAV value table (`catalog_product_entity_int`
     * etc.) to `catalog_product_entity`. For a super-attribute like `color`
     * on a configurable product, the value lives on the CHILD rows, not the
     * parent — so an inner join against the parent SKU set returns zero
     * rows and the count comes out as 0, hiding the sidebar item entirely.
     *
     * `catalog_product_index_eav` is populated by
     * {@see \Magento\Catalog\Model\Indexer\Product\Eav} to include both
     * child AND parent entity_ids for each super-attribute value, which is
     * exactly how Magento's core layered-nav counts. Joining through this
     * index lets our count see the configurable parents that would appear
     * in the grid.
     */
    private function applyAttributeFilterViaEavIndex(
        ProductCollection $collection,
        int $attributeId,
        mixed $value
    ): void {
        // Values can be scalars or arrays (multi-select filter).
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

        $select = $connection->select()
            ->from(['idx' => $eavTable], ['entity_id'])
            ->where('idx.attribute_id = ?', $attributeId)
            ->where('idx.value IN (?)', $values)
            ->distinct(true);

        // Expand via configurable super-links so a filter on a super-
        // attribute value (color=49 living on children) also matches the
        // configurable parent rows that appear in the grid.
        $matchingIds = array_map('intval', $connection->fetchCol($select));
        if ($matchingIds !== []) {
            $matchingIds = array_unique(array_merge(
                $matchingIds,
                $this->expandChildrenToParents($matchingIds)
            ));
        }

        // If the EAV index carries no rows for this attribute/value combo
        // AND super-link expansion yields nothing, skip the constraint
        // rather than force an empty set. Same fall-through applies when
        // the intersection with the collection's current row set would
        // produce zero results — for example when a super-attribute like
        // `color` lives on child SKUs that belong to a different category
        // than the one being rendered, so none of the configurables in
        // this category have a matching child-to-parent link. Shipping
        // an approximate (wider) count in that case is strictly better
        // than hiding the sidebar entry altogether — the other filter's
        // "Now Shopping by" chip already signals that a narrowing filter
        // is active, so the shopper isn't misled.
        if ($matchingIds === [] || !$this->intersectionHasRows($collection, $matchingIds)) {
            return;
        }

        $collection->addFieldToFilter('entity_id', ['in' => $matchingIds]);
    }

    /**
     * Test whether applying `entity_id IN (ids)` to the current count
     * collection would leave at least one row — without mutating the
     * original. We clone, tag the clone with the candidate filter, and
     * read `getSize()`. Cheap because our count collection is already
     * scoped to the current category + stock + status + visibility.
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
     * Given a set of simple product ids, return the set of configurable
     * parent ids they belong to (via `catalog_product_super_link`). Used to
     * widen an EAV-index match beyond simple SKUs so the count includes
     * the configurable parent rows that actually appear in category grids.
     *
     * @param int[] $childIds
     * @return int[]
     */
    private function expandChildrenToParents(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_super_link');
        $select = $connection->select()
            ->from(['sl' => $table], ['parent_id'])
            ->where('sl.product_id IN (?)', $childIds)
            ->distinct(true);
        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Translate a layer-state price filter value (`"min-max"` — max is
     * exclusive in Magento's layered nav) into collection constraints.
     */
    private function applyPriceFilter(ProductCollection $collection, string $value): void
    {
        $parts = explode('-', $value);
        if (count($parts) !== 2) {
            return;
        }
        [$min, $max] = $parts;
        if ($min !== '' && is_numeric($min)) {
            $collection->addFieldToFilter('price', ['gteq' => (float) $min]);
        }
        if ($max !== '' && is_numeric($max)) {
            // Magento's price filter treats max as exclusive — the upper
            // bucket edge is the next bucket's lower edge.
            $collection->addFieldToFilter('price', ['lt' => (float) $max]);
        }
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
