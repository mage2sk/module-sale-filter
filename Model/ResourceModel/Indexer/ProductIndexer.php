<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\ResourceModel\Indexer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Zend_Db_Expr;

class ProductIndexer extends AbstractDb
{
    private const INDEX_TABLE = 'panth_salefilter_product_index';

    private const WRITE_CHUNK_SIZE = 1000;

    private const LINK_TYPE_GROUPED = 3;

    private const TYPE_BUNDLE = 'bundle';

    private const BUNDLE_PERCENT_MAX = 100;

    private const ATTR_CODES = [
        'price',
        'special_price',
        'special_from_date',
        'special_to_date',
        'status',
    ];

    private array $attributeIds = [];

    private ?array $websites = null;

    private ?array $groups = null;

    private ?string $linkField = null;

    private array $changedIds = [];

    public function __construct(
        Context $context,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly TimezoneInterface $timezone,
        private readonly MetadataPool $metadataPool,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    protected function _construct(): void
    {
        $this->_init(self::INDEX_TABLE, 'entity_id');
    }

    public function getChangedIds(): array
    {
        return array_values($this->changedIds);
    }

    public function reindexAll(bool $includeSpecialPrices, bool $includeCatalogRules): int
    {
        $this->changedIds = [];
        if (!$includeSpecialPrices && !$includeCatalogRules) {
            $this->rememberChanged($this->fetchIndexedIds(null));
            $this->getConnection()->delete($this->getTable(self::INDEX_TABLE));
            return 0;
        }

        return $this->reindexScopes($includeSpecialPrices, $includeCatalogRules, null);
    }

    public function reindexByIds(array $productIds, bool $includeSpecialPrices, bool $includeCatalogRules): int
    {
        $this->changedIds = [];
        $ids = $this->normalizeIds($productIds);
        if ($ids === []) {
            return 0;
        }

        if (!$includeSpecialPrices && !$includeCatalogRules) {
            $this->rememberChanged($this->fetchIndexedIds($ids));
            $this->getConnection()->delete(
                $this->getTable(self::INDEX_TABLE),
                ['entity_id IN (?)' => $ids]
            );
            return 0;
        }

        $scopedIds = $this->expandToAffectedProductIds($ids);

        return $this->reindexScopes($includeSpecialPrices, $includeCatalogRules, $scopedIds);
    }

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

                $previousIds = $this->fetchScopeIds($websiteId, $customerGroupId, $restrictToIds);
                $this->rememberChanged(array_diff($previousIds, $onSaleIds));
                $this->rememberChanged(array_diff($onSaleIds, $previousIds));

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
                $this->selectCatalogRuleOnSale($websiteId, $storeId, $customerGroupId, $restrictToIds)
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

        $enabledChildren = $this->filterEnabledProducts($simpleIds, $storeId);
        $parentIds       = $this->collectParentsForChildren($enabledChildren);

        return $this->mergeIds($enabledChildren, $parentIds);
    }

    private function selectCatalogRuleOnSale(
        int $websiteId,
        int $storeId,
        int $customerGroupId,
        ?array $restrictToIds
    ): array {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('catalogrule_product_price'), ['product_id'])
            ->where('website_id = ?', $websiteId)
            ->where('customer_group_id = ?', $customerGroupId)
            ->where('rule_date = ?', $this->timezone->scopeDate($storeId)->format('Y-m-d'))
            ->distinct(true);

        if ($restrictToIds !== null && $restrictToIds !== []) {
            $select->where('product_id IN (?)', $restrictToIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    private function selectSpecialPriceOnSale(int $storeId, ?array $restrictToIds): array
    {
        $connection = $this->getConnection();
        $attrIds    = $this->loadAttributeIds();

        foreach (['price', 'special_price'] as $required) {
            if (!isset($attrIds[$required])) {
                return [];
            }
        }

        $productTable = $this->getTable('catalog_product_entity');
        $decimalTable = $this->getTable('catalog_product_entity_decimal');
        $datetimeTbl  = $this->getTable('catalog_product_entity_datetime');

        $today = $this->timezone->scopeDate($storeId)->format('Y-m-d');

        $select = $connection->select()->from(['e' => $productTable], ['entity_id']);

        $specialPrice = $this->joinScopedValue($select, 'sp', $decimalTable, $attrIds['special_price'], $storeId);
        $price        = $this->joinScopedValue($select, 'p', $decimalTable, $attrIds['price'], $storeId);

        if (isset($attrIds['special_from_date'])) {
            $from = $this->joinScopedValue($select, 'sf', $datetimeTbl, $attrIds['special_from_date'], $storeId);
            $select->where(
                sprintf('%1$s IS NULL OR %1$s <= ?', $from),
                $today . ' 23:59:59'
            );
        }

        if (isset($attrIds['special_to_date'])) {
            $to = $this->joinScopedValue($select, 'st', $datetimeTbl, $attrIds['special_to_date'], $storeId);
            $select->where(
                sprintf('%1$s IS NULL OR %1$s >= ?', $to),
                $today . ' 00:00:00'
            );
        }

        $select->where(sprintf('%s > 0', $specialPrice))
            ->where(
                sprintf(
                    '(e.type_id = %1$s AND %2$s < %3$d) OR (e.type_id <> %1$s AND %2$s < %4$s)',
                    $connection->quote(self::TYPE_BUNDLE),
                    $specialPrice,
                    self::BUNDLE_PERCENT_MAX,
                    $price
                )
            );

        if ($restrictToIds !== null && $restrictToIds !== []) {
            $select->where('e.entity_id IN (?)', $restrictToIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    private function joinScopedValue(
        Select $select,
        string $alias,
        string $table,
        int $attributeId,
        int $storeId
    ): string {
        $defaultAlias = $alias . '_default';
        $select->joinLeft(
            [$defaultAlias => $table],
            sprintf(
                '%1$s.%3$s = e.%3$s AND %1$s.attribute_id = %2$d AND %1$s.store_id = 0',
                $defaultAlias,
                $attributeId,
                $this->getLinkField()
            ),
            []
        );

        if ($storeId <= 0) {
            return $defaultAlias . '.value';
        }

        $storeAlias = $alias . '_store';
        $select->joinLeft(
            [$storeAlias => $table],
            sprintf(
                '%1$s.%4$s = e.%4$s AND %1$s.attribute_id = %2$d AND %1$s.store_id = %3$d',
                $storeAlias,
                $attributeId,
                $storeId,
                $this->getLinkField()
            ),
            []
        );

        return sprintf('IF(%1$s.value_id IS NULL, %2$s.value, %1$s.value)', $storeAlias, $defaultAlias);
    }

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

        $select = $connection->select()
            ->from(['e' => $this->getTable('catalog_product_entity')], ['entity_id'])
            ->where('e.entity_id IN (?)', $productIds);

        $status = $this->joinScopedValue(
            $select,
            'status',
            $this->getTable('catalog_product_entity_int'),
            (int) $attrIds['status'],
            $storeId
        );
        $select->where(sprintf('%s = ?', $status), 1);

        return array_map('intval', $connection->fetchCol($select));
    }

    public function getConfigurableParentIds(array $childIds): array
    {
        $childIds = $this->normalizeIds($childIds);
        if ($childIds === []) {
            return [];
        }

        return $this->fetchParentEntityIds('catalog_product_super_link', 'parent_id', 'product_id', $childIds);
    }

    public function getDateBoundaryProductIds(): array
    {
        $connection = $this->getConnection();
        $days = [];
        foreach ($this->loadWebsites() as $website) {
            if ((int) $website->getId() === 0) {
                continue;
            }
            $today = $this->timezone->scopeDate($this->resolveDefaultStoreId($website));
            $days[$today->format('Y-m-d')] = true;
            $days[(clone $today)->modify('-1 day')->format('Y-m-d')] = true;
        }
        if ($days === []) {
            return [];
        }
        $dayList = array_keys($days);
        sort($dayList);

        $ids = [];
        $attrIds = $this->loadAttributeIds();
        $dateAttributes = array_values(array_filter([
            $attrIds['special_from_date'] ?? null,
            $attrIds['special_to_date'] ?? null,
        ]));
        if ($dateAttributes !== []) {
            $linkField = $this->getLinkField();
            $select = $connection->select()
                ->from(['d' => $this->getTable('catalog_product_entity_datetime')], [])
                ->join(
                    ['e' => $this->getTable('catalog_product_entity')],
                    sprintf('e.%1$s = d.%1$s', $linkField),
                    ['entity_id']
                )
                ->where('d.attribute_id IN (?)', $dateAttributes)
                ->where('d.value >= ?', reset($dayList) . ' 00:00:00')
                ->where('d.value <= ?', end($dayList) . ' 23:59:59')
                ->distinct(true);
            $ids = $this->mergeIds($ids, $connection->fetchCol($select));
        }

        $select = $connection->select()
            ->from($this->getTable('catalogrule_product_price'), ['product_id'])
            ->where('rule_date IN (?)', $dayList)
            ->group(['product_id', 'website_id', 'customer_group_id'])
            ->having('COUNT(DISTINCT rule_date) < ?', count($dayList));

        return $this->mergeIds($ids, $connection->fetchCol($select));
    }

    private function collectParentsForChildren(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }

        $parents = $this->fetchParentEntityIds('catalog_product_super_link', 'parent_id', 'product_id', $childIds);
        $parents = $this->mergeIds(
            $parents,
            $this->fetchParentEntityIds('catalog_product_link', 'product_id', 'linked_product_id', $childIds, true)
        );

        return $this->mergeIds(
            $parents,
            $this->fetchParentEntityIds(
                'catalog_product_bundle_selection',
                'parent_product_id',
                'product_id',
                $childIds
            )
        );
    }

    private function expandToAffectedProductIds(array $productIds): array
    {
        $expanded = $this->mergeIds($productIds, $this->collectParentsForChildren($productIds));
        $expanded = $this->mergeIds(
            $expanded,
            $this->fetchChildEntityIds('catalog_product_super_link', 'parent_id', 'product_id', $productIds)
        );
        $expanded = $this->mergeIds(
            $expanded,
            $this->fetchChildEntityIds('catalog_product_link', 'product_id', 'linked_product_id', $productIds, true)
        );

        return $this->mergeIds(
            $expanded,
            $this->fetchChildEntityIds(
                'catalog_product_bundle_selection',
                'parent_product_id',
                'product_id',
                $productIds
            )
        );
    }

    private function fetchParentEntityIds(
        string $table,
        string $parentColumn,
        string $childColumn,
        array $childIds,
        bool $groupedOnly = false
    ): array {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['rel' => $this->getTable($table)], [])
            ->join(
                ['parent' => $this->getTable('catalog_product_entity')],
                sprintf('parent.%s = rel.%s', $this->getLinkField(), $parentColumn),
                ['entity_id']
            )
            ->where(sprintf('rel.%s IN (?)', $childColumn), $childIds)
            ->distinct(true);
        if ($groupedOnly) {
            $select->where('rel.link_type_id = ?', self::LINK_TYPE_GROUPED);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    private function fetchChildEntityIds(
        string $table,
        string $parentColumn,
        string $childColumn,
        array $parentIds,
        bool $groupedOnly = false
    ): array {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['rel' => $this->getTable($table)], [$childColumn])
            ->join(
                ['parent' => $this->getTable('catalog_product_entity')],
                sprintf('parent.%s = rel.%s', $this->getLinkField(), $parentColumn),
                []
            )
            ->where('parent.entity_id IN (?)', $parentIds)
            ->distinct(true);
        if ($groupedOnly) {
            $select->where('rel.link_type_id = ?', self::LINK_TYPE_GROUPED);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    private function fetchScopeIds(int $websiteId, int $customerGroupId, ?array $restrictToIds): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(self::INDEX_TABLE), ['entity_id'])
            ->where('website_id = ?', $websiteId)
            ->where('customer_group_id = ?', $customerGroupId)
            ->where('is_on_sale = ?', 1);
        if ($restrictToIds !== null && $restrictToIds !== []) {
            $select->where('entity_id IN (?)', $restrictToIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    private function fetchIndexedIds(?array $restrictToIds): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(self::INDEX_TABLE), ['entity_id'])
            ->distinct(true);
        if ($restrictToIds !== null) {
            $select->where('entity_id IN (?)', $restrictToIds ?: [0]);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    private function rememberChanged(array $ids): void
    {
        foreach ($ids as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $this->changedIds[$int] = $int;
            }
        }
    }

    private function getLinkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        }

        return $this->linkField;
    }

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

        if ($onSaleIds !== []) {
            $where[$connection->quoteInto('entity_id NOT IN (?)', $onSaleIds)] = null;
        }

        if ($restrictToIds !== null && $restrictToIds !== []) {
            $where[$connection->quoteInto('entity_id IN (?)', $restrictToIds)] = null;
        }

        $conds = [];
        foreach ($where as $clause => $val) {
            $conds[] = $val === null ? $clause : $connection->quoteInto($clause, $val);
        }

        $connection->delete($table, implode(' AND ', $conds));
    }

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

            $written += $connection->insertOnDuplicate($table, $rows, ['is_on_sale']);
        }

        return $written;
    }

    private function loadWebsites(): array
    {
        if ($this->websites === null) {
            $this->websites = $this->websiteRepository->getList();
        }

        return $this->websites;
    }

    private function loadCustomerGroups(): array
    {
        if ($this->groups === null) {
            $criteria     = $this->searchCriteriaBuilder->create();
            $this->groups = $this->groupRepository->getList($criteria)->getItems();
        }

        return $this->groups;
    }

    private function resolveDefaultStoreId(WebsiteInterface $website): int
    {
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

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('store'), ['store_id'])
            ->where('website_id = ?', (int) $website->getId())
            ->where('store_id > 0')
            ->order('store_id ASC')
            ->limit(1);

        return (int) $connection->fetchOne($select);
    }

    private function loadAttributeIds(): array
    {
        if ($this->attributeIds !== []) {
            return $this->attributeIds;
        }

        $connection = $this->getConnection();

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
