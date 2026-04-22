<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\ResourceModel\Indexer;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Zend_Db_Expr;

/**
 * SQL-layer of the Sale Filter indexer.
 *
 * Rebuilds {@code panth_salefilter_product_index} rows - one per
 * (product_id, customer_group_id, website_id) combo flagged with {@code is_on_sale=1}
 * when the product is currently discounted via an active catalog price rule OR a
 * special price, scoped to that website/group/store.
 *
 * Composite products (configurable / grouped / bundle) inherit "on sale" from
 * any enabled child. The child -> parent walk uses core link tables.
 *
 * All writes are chunked in batches of {@see self::WRITE_CHUNK_SIZE} and wrapped
 * in a transaction per (website, group) scope so a failure never leaves the
 * storefront with a half-populated index (spec §Edge Cases #13).
 */
class ProductIndexer extends AbstractDb
{
    /** Name of the index table populated by this class. */
    private const INDEX_TABLE = 'panth_salefilter_product_index';

    /** Row chunk size for INSERT ... ON DUPLICATE KEY UPDATE writes. */
    private const WRITE_CHUNK_SIZE = 1000;

    /** Catalog product link type id for "grouped product" associations. */
    private const LINK_TYPE_GROUPED = 3;

    /** EAV attribute codes we look up once and cache in {@see $attributeIds}. */
    private const ATTR_CODES = [
        'price',
        'special_price',
        'special_from_date',
        'special_to_date',
        'status',
    ];

    /** @var array<string, int> entity_type=catalog_product attribute_code -> attribute_id. */
    private array $attributeIds = [];

    /** @var array<int, WebsiteInterface>|null lazy-cached website list. */
    private ?array $websites = null;

    /** @var array<int, GroupInterface>|null lazy-cached customer-group list. */
    private ?array $groups = null;

    public function __construct(
        Context $context,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly DateTime $dateTime,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * Required AbstractDb init - pk is composite, we pass `entity_id` for framework compatibility.
     */
    protected function _construct(): void
    {
        $this->_init(self::INDEX_TABLE, 'entity_id');
    }

    /**
     * Full reindex: compute on-sale status for every product in every scope and truncate+refill.
     *
     * @param bool $includeSpecialPrices
     * @param bool $includeCatalogRules
     * @return int Number of rows (product x group x website) flagged on sale.
     * @throws LocalizedException
     */
    public function reindexAll(bool $includeSpecialPrices, bool $includeCatalogRules): int
    {
        if (!$includeSpecialPrices && !$includeCatalogRules) {
            // Nothing to compute - truncate the index so the storefront shows "none on sale".
            $this->getConnection()->delete($this->getTable(self::INDEX_TABLE));
            return 0;
        }

        return $this->reindexScopes($includeSpecialPrices, $includeCatalogRules, null);
    }

    /**
     * Partial reindex: only recompute rows touching the given product ids.
     *
     * @param array<int, int> $productIds
     * @param bool            $includeSpecialPrices
     * @param bool            $includeCatalogRules
     * @return int Rows flagged on sale that intersect $productIds.
     * @throws LocalizedException
     */
    public function reindexByIds(array $productIds, bool $includeSpecialPrices, bool $includeCatalogRules): int
    {
        $ids = $this->normalizeIds($productIds);
        if ($ids === []) {
            return 0;
        }

        if (!$includeSpecialPrices && !$includeCatalogRules) {
            // Neither source is configured - drop our rows for these products.
            $this->getConnection()->delete(
                $this->getTable(self::INDEX_TABLE),
                ['entity_id IN (?)' => $ids]
            );
            return 0;
        }

        // Expand the scope so we also (re)evaluate the parents of any affected simples.
        $scopedIds = $this->expandToAffectedProductIds($ids);

        return $this->reindexScopes($includeSpecialPrices, $includeCatalogRules, $scopedIds);
    }

    /**
     * Count currently on-sale rows for a given website/group - used by the StatusCommand.
     *
     * @param int $websiteId
     * @param int $customerGroupId
     * @return int
     */
    public function countOnSale(int $websiteId, int $customerGroupId): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(self::INDEX_TABLE), [new Zend_Db_Expr('COUNT(*)')])
            ->where('website_id = ?', $websiteId)
            ->where('customer_group_id = ?', $customerGroupId)
            ->where('is_on_sale = ?', 1);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Inner reindex loop - iterate (website, group) scopes and rebuild each.
     *
     * @param bool                   $includeSpecialPrices
     * @param bool                   $includeCatalogRules
     * @param array<int, int>|null   $restrictToIds Null means "all products", otherwise limit SQL.
     * @return int Total rows flagged on sale across every scope.
     * @throws LocalizedException
     */
    private function reindexScopes(
        bool $includeSpecialPrices,
        bool $includeCatalogRules,
        ?array $restrictToIds
    ): int {
        $totalOnSale = 0;
        $connection  = $this->getConnection();

        foreach ($this->loadWebsites() as $website) {
            $websiteId = (int) $website->getId();
            if ($websiteId === 0) {
                // Admin website has no storefront - skip.
                continue;
            }
            $storeId = $this->resolveDefaultStoreId($website);

            foreach ($this->loadCustomerGroups() as $group) {
                $customerGroupId = (int) $group->getId();
                $onSaleIds = $this->collectOnSaleIdsForScope(
                    $websiteId,
                    $storeId,
                    $customerGroupId,
                    $includeSpecialPrices,
                    $includeCatalogRules,
                    $restrictToIds
                );

                $connection->beginTransaction();
                try {
                    $this->purgeStale($websiteId, $customerGroupId, $onSaleIds, $restrictToIds);
                    $written = $this->writeOnSaleRows($websiteId, $customerGroupId, $onSaleIds);
                    $connection->commit();
                    $totalOnSale += $written;
                } catch (\Throwable $e) {
                    $connection->rollBack();
                    throw new LocalizedException(
                        __('Sale filter reindex failed for website %1 group %2: %3', $websiteId, $customerGroupId, $e->getMessage()),
                        $e
                    );
                }
            }
        }

        return $totalOnSale;
    }

    /**
     * Collect all product ids that should be flagged on-sale for this (website, group) scope.
     *
     * Combines catalog-rule matches + special-price matches (per flags), then walks up
     * to composite parents (configurable / grouped / bundle) keeping only enabled children.
     *
     * @param int                  $websiteId
     * @param int                  $storeId
     * @param int                  $customerGroupId
     * @param bool                 $includeSpecialPrices
     * @param bool                 $includeCatalogRules
     * @param array<int, int>|null $restrictToIds
     * @return array<int, int> Unique product ids on sale in this scope.
     */
    private function collectOnSaleIdsForScope(
        int $websiteId,
        int $storeId,
        int $customerGroupId,
        bool $includeSpecialPrices,
        bool $includeCatalogRules,
        ?array $restrictToIds
    ): array {
        $simpleIds = [];

        if ($includeCatalogRules) {
            $simpleIds = $this->mergeIds(
                $simpleIds,
                $this->selectCatalogRuleOnSale($websiteId, $customerGroupId, $restrictToIds)
            );
        }

        if ($includeSpecialPrices) {
            $simpleIds = $this->mergeIds(
                $simpleIds,
                $this->selectSpecialPriceOnSale($storeId, $restrictToIds)
            );
        }

        if ($simpleIds === []) {
            return [];
        }

        // Walk to parents - configurable / grouped / bundle - only keeping enabled children.
        $enabledChildren = $this->filterEnabledProducts($simpleIds, $storeId);
        $parentIds       = $this->collectParentsForChildren($enabledChildren);

        // Final set: the enabled simples themselves + any parent whose child triggered the rule.
        return $this->mergeIds($enabledChildren, $parentIds);
    }

    /**
     * catalog_rule matches for (website, group) today.
     *
     * @param int                  $websiteId
     * @param int                  $customerGroupId
     * @param array<int, int>|null $restrictToIds
     * @return array<int, int>
     */
    private function selectCatalogRuleOnSale(
        int $websiteId,
        int $customerGroupId,
        ?array $restrictToIds
    ): array {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('catalogrule_product_price'), ['product_id'])
            ->where('website_id = ?', $websiteId)
            ->where('customer_group_id = ?', $customerGroupId)
            ->where('rule_date = ?', $this->dateTime->date('Y-m-d'))
            ->distinct(true);

        if ($restrictToIds !== null && $restrictToIds !== []) {
            $select->where('product_id IN (?)', $restrictToIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Special-price matches for the given store (within window, < price, > 0).
     *
     * @param int                  $storeId
     * @param array<int, int>|null $restrictToIds
     * @return array<int, int>
     */
    private function selectSpecialPriceOnSale(int $storeId, ?array $restrictToIds): array
    {
        $connection = $this->getConnection();
        $attrIds    = $this->loadAttributeIds();

        // Required attributes must exist - if missing, EAV is broken, no results.
        foreach (['price', 'special_price'] as $required) {
            if (!isset($attrIds[$required])) {
                return [];
            }
        }

        $productTable = $this->getTable('catalog_product_entity');
        $decimalTable = $this->getTable('catalog_product_entity_decimal');
        $datetimeTbl  = $this->getTable('catalog_product_entity_datetime');

        $storeScopeIds = [0, $storeId];
        $now = $this->dateTime->gmtDate();

        $select = $connection->select()
            ->from(['e' => $productTable], ['entity_id'])
            ->join(
                ['sp' => $decimalTable],
                'sp.entity_id = e.entity_id'
                    . ' AND sp.attribute_id = ' . (int) $attrIds['special_price']
                    . ' AND sp.store_id IN (' . implode(',', $storeScopeIds) . ')',
                []
            )
            ->join(
                ['p' => $decimalTable],
                'p.entity_id = e.entity_id'
                    . ' AND p.attribute_id = ' . (int) $attrIds['price']
                    . ' AND p.store_id IN (' . implode(',', $storeScopeIds) . ')',
                []
            );

        if (isset($attrIds['special_from_date'])) {
            $select->joinLeft(
                ['sf' => $datetimeTbl],
                'sf.entity_id = e.entity_id'
                    . ' AND sf.attribute_id = ' . (int) $attrIds['special_from_date']
                    . ' AND sf.store_id IN (' . implode(',', $storeScopeIds) . ')',
                []
            )->where('sf.value IS NULL OR sf.value <= ?', $now);
        }

        if (isset($attrIds['special_to_date'])) {
            $select->joinLeft(
                ['st' => $datetimeTbl],
                'st.entity_id = e.entity_id'
                    . ' AND st.attribute_id = ' . (int) $attrIds['special_to_date']
                    . ' AND st.store_id IN (' . implode(',', $storeScopeIds) . ')',
                []
            )->where('st.value IS NULL OR st.value >= ?', $now);
        }

        $select->where('sp.value > ?', 0)
            ->where('sp.value < p.value')
            ->distinct(true);

        if ($restrictToIds !== null && $restrictToIds !== []) {
            $select->where('e.entity_id IN (?)', $restrictToIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Filter a list of ids down to those currently enabled (status=1) for the given store.
     *
     * Per spec: "only enabled children count" when aggregating a parent's sale status.
     *
     * @param array<int, int> $productIds
     * @param int             $storeId
     * @return array<int, int>
     */
    private function filterEnabledProducts(array $productIds, int $storeId): array
    {
        if ($productIds === []) {
            return [];
        }

        $attrIds = $this->loadAttributeIds();
        if (!isset($attrIds['status'])) {
            return $productIds;
        }

        $connection = $this->getConnection();
        $intTable   = $this->getTable('catalog_product_entity_int');

        // Prefer store-scope value, fall back to default (store_id=0).
        $select = $connection->select()
            ->from(['s' => $intTable], ['entity_id'])
            ->where('s.attribute_id = ?', (int) $attrIds['status'])
            ->where('s.store_id IN (?)', [0, $storeId])
            ->where('s.value = ?', 1)
            ->where('s.entity_id IN (?)', $productIds);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Walk child ids up to their composite parents via core link tables.
     *
     * @param array<int, int> $childIds
     * @return array<int, int>
     */
    private function collectParentsForChildren(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }

        $connection = $this->getConnection();
        $parents    = [];

        // Configurable: catalog_product_super_link (product_id = child, parent_id = configurable).
        $superLinkTable = $this->getTable('catalog_product_super_link');
        $select = $connection->select()
            ->from($superLinkTable, ['parent_id'])
            ->where('product_id IN (?)', $childIds)
            ->distinct(true);
        $parents = $this->mergeIds($parents, array_map('intval', $connection->fetchCol($select)));

        // Grouped: catalog_product_link with link_type_id = 3.
        $linkTable = $this->getTable('catalog_product_link');
        $select = $connection->select()
            ->from($linkTable, ['product_id'])
            ->where('linked_product_id IN (?)', $childIds)
            ->where('link_type_id = ?', self::LINK_TYPE_GROUPED)
            ->distinct(true);
        $parents = $this->mergeIds($parents, array_map('intval', $connection->fetchCol($select)));

        // Bundle: catalog_product_bundle_selection (product_id = child, parent_product_id = bundle).
        $bundleTable = $this->getTable('catalog_product_bundle_selection');
        $select = $connection->select()
            ->from($bundleTable, ['parent_product_id'])
            ->where('product_id IN (?)', $childIds)
            ->distinct(true);
        $parents = $this->mergeIds($parents, array_map('intval', $connection->fetchCol($select)));

        return $parents;
    }

    /**
     * For a partial reindex we must also re-evaluate any parent or child touched by the request,
     * otherwise a simple product change would not bubble up to its configurable/bundle parent.
     *
     * @param array<int, int> $productIds
     * @return array<int, int>
     */
    private function expandToAffectedProductIds(array $productIds): array
    {
        $connection = $this->getConnection();
        $expanded = $productIds;

        // Include parents of given products (treat given ids as potential children).
        $expanded = $this->mergeIds($expanded, $this->collectParentsForChildren($productIds));

        // Include children of given products (treat given ids as potential parents).
        $superLink = $this->getTable('catalog_product_super_link');
        $select = $connection->select()
            ->from($superLink, ['product_id'])
            ->where('parent_id IN (?)', $productIds)
            ->distinct(true);
        $expanded = $this->mergeIds($expanded, array_map('intval', $connection->fetchCol($select)));

        $link = $this->getTable('catalog_product_link');
        $select = $connection->select()
            ->from($link, ['linked_product_id'])
            ->where('product_id IN (?)', $productIds)
            ->where('link_type_id = ?', self::LINK_TYPE_GROUPED)
            ->distinct(true);
        $expanded = $this->mergeIds($expanded, array_map('intval', $connection->fetchCol($select)));

        $bundle = $this->getTable('catalog_product_bundle_selection');
        $select = $connection->select()
            ->from($bundle, ['product_id'])
            ->where('parent_product_id IN (?)', $productIds)
            ->distinct(true);
        $expanded = $this->mergeIds($expanded, array_map('intval', $connection->fetchCol($select)));

        return $expanded;
    }

    /**
     * Remove stale index rows - rows that we previously flagged on_sale=1 but should no longer.
     *
     * For a scoped (partial) reindex this is limited to the products we touched so we never
     * nuke unrelated rows (spec: "don't nuke rows for unrelated products").
     *
     * @param int                  $websiteId
     * @param int                  $customerGroupId
     * @param array<int, int>      $onSaleIds Current on-sale product ids for this scope.
     * @param array<int, int>|null $restrictToIds Null for full reindex, else partial scope.
     * @return void
     */
    private function purgeStale(
        int $websiteId,
        int $customerGroupId,
        array $onSaleIds,
        ?array $restrictToIds
    ): void {
        $connection = $this->getConnection();
        $table      = $this->getTable(self::INDEX_TABLE);

        $where = [
            'website_id = ?'        => $websiteId,
            'customer_group_id = ?' => $customerGroupId,
            'is_on_sale = ?'        => 1,
        ];

        // Don't delete the products we're about to re-flag.
        if ($onSaleIds !== []) {
            $where[$connection->quoteInto('entity_id NOT IN (?)', $onSaleIds)] = null;
        }

        // Partial scope: never touch rows outside the requested id set.
        if ($restrictToIds !== null && $restrictToIds !== []) {
            $where[$connection->quoteInto('entity_id IN (?)', $restrictToIds)] = null;
        }

        // Compact the where clauses that use quoteInto (value=null) into plain where strings.
        $conds = [];
        foreach ($where as $clause => $val) {
            $conds[] = $val === null ? $clause : $connection->quoteInto($clause, $val);
        }

        $connection->delete($table, implode(' AND ', $conds));
    }

    /**
     * Insert (or update) the on-sale rows for this scope, chunked.
     *
     * @param int             $websiteId
     * @param int             $customerGroupId
     * @param array<int, int> $onSaleIds
     * @return int Number of rows written with is_on_sale=1.
     */
    private function writeOnSaleRows(int $websiteId, int $customerGroupId, array $onSaleIds): int
    {
        if ($onSaleIds === []) {
            return 0;
        }

        $connection = $this->getConnection();
        $table      = $this->getTable(self::INDEX_TABLE);
        $columns    = ['entity_id', 'customer_group_id', 'website_id', 'is_on_sale'];
        $written    = 0;

        foreach (array_chunk($onSaleIds, self::WRITE_CHUNK_SIZE) as $chunk) {
            $rows = [];
            foreach ($chunk as $productId) {
                $rows[] = [
                    'entity_id'         => (int) $productId,
                    'customer_group_id' => $customerGroupId,
                    'website_id'        => $websiteId,
                    'is_on_sale'        => 1,
                ];
            }

            // insertOnDuplicate gives us INSERT ... ON DUPLICATE KEY UPDATE atomically.
            $written += $connection->insertOnDuplicate($table, $rows, ['is_on_sale']);
        }

        return $written;
    }

    /**
     * Load all websites once per request and cache.
     *
     * @return WebsiteInterface[]
     */
    private function loadWebsites(): array
    {
        if ($this->websites === null) {
            $this->websites = $this->websiteRepository->getList();
        }

        return $this->websites;
    }

    /**
     * Load all customer groups once per request and cache.
     *
     * @return GroupInterface[]
     */
    private function loadCustomerGroups(): array
    {
        if ($this->groups === null) {
            $criteria     = $this->searchCriteriaBuilder->create();
            $this->groups = $this->groupRepository->getList($criteria)->getItems();
        }

        return $this->groups;
    }

    /**
     * Resolve the default store id for a given website - used to read store-scoped EAV values.
     *
     * @param WebsiteInterface $website
     * @return int
     */
    private function resolveDefaultStoreId(WebsiteInterface $website): int
    {
        // WebsiteInterface doesn't expose getDefaultStore() directly; fall back through the group.
        if (method_exists($website, 'getDefaultStore')) {
            $store = $website->getDefaultStore();
            if ($store !== null) {
                return (int) $store->getId();
            }
        }

        if (method_exists($website, 'getDefaultGroupId')) {
            $groupId = (int) $website->getDefaultGroupId();
            if ($groupId > 0) {
                $connection = $this->getConnection();
                $select = $connection->select()
                    ->from($this->getTable('store'), ['store_id'])
                    ->where('group_id = ?', $groupId)
                    ->where('store_id > 0')
                    ->order('store_id ASC')
                    ->limit(1);
                $storeId = (int) $connection->fetchOne($select);
                if ($storeId > 0) {
                    return $storeId;
                }
            }
        }

        // Last resort: any non-admin store on this website.
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('store'), ['store_id'])
            ->where('website_id = ?', (int) $website->getId())
            ->where('store_id > 0')
            ->order('store_id ASC')
            ->limit(1);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Load catalog_product attribute ids for our fixed set of codes, once, and cache.
     *
     * @return array<string, int>
     */
    private function loadAttributeIds(): array
    {
        if ($this->attributeIds !== []) {
            return $this->attributeIds;
        }

        $connection = $this->getConnection();

        // Join against eav_entity_type to scope exclusively to catalog_product.
        $select = $connection->select()
            ->from(['a' => $this->getTable('eav_attribute')], ['attribute_code', 'attribute_id'])
            ->join(
                ['t' => $this->getTable('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->where('t.entity_type_code = ?', 'catalog_product')
            ->where('a.attribute_code IN (?)', self::ATTR_CODES);

        $rows = $connection->fetchPairs($select);

        $this->attributeIds = [];
        foreach ($rows as $code => $id) {
            $this->attributeIds[(string) $code] = (int) $id;
        }

        return $this->attributeIds;
    }

    /**
     * Merge two id lists preserving uniqueness, returning a 0-indexed list.
     *
     * @param array<int, int> $a
     * @param array<int, int> $b
     * @return array<int, int>
     */
    private function mergeIds(array $a, array $b): array
    {
        $merged = [];
        foreach ($a as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $merged[$int] = $int;
            }
        }
        foreach ($b as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $merged[$int] = $int;
            }
        }

        return array_values($merged);
    }

    /**
     * Cast arbitrary id input to a clean list of positive ints.
     *
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $clean[$int] = $int;
            }
        }

        return array_values($clean);
    }
}
