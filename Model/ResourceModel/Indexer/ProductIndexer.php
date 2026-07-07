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

class ProductIndexer extends AbstractDb
{
    private const INDEX_TABLE = 'panth_salefilter_product_index';

    private const WRITE_CHUNK_SIZE = 1000;

    private const LINK_TYPE_GROUPED = 3;

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

    protected function _construct(): void
    {
        $this->_init(self::INDEX_TABLE, 'entity_id');
    }

    public function reindexAll(bool $includeSpecialPrices, bool $includeCatalogRules): int
    {
        if (!$includeSpecialPrices && !$includeCatalogRules) {
            $this->getConnection()->delete($this->getTable(self::INDEX_TABLE));
            return 0;
        }

        return $this->reindexScopes($includeSpecialPrices, $includeCatalogRules, null);
    }

    public function reindexByIds(array $productIds, bool $includeSpecialPrices, bool $includeCatalogRules): int
    {
        $ids = $this->normalizeIds($productIds);
        if ($ids === []) {
            return 0;
        }

        if (!$includeSpecialPrices && !$includeCatalogRules) {
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

        $enabledChildren = $this->filterEnabledProducts($simpleIds, $storeId);
        $parentIds       = $this->collectParentsForChildren($enabledChildren);

        return $this->mergeIds($enabledChildren, $parentIds);
    }

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

        $select = $connection->select()
            ->from(['s' => $intTable], ['entity_id'])
            ->where('s.attribute_id = ?', (int) $attrIds['status'])
            ->where('s.store_id IN (?)', [0, $storeId])
            ->where('s.value = ?', 1)
            ->where('s.entity_id IN (?)', $productIds);

        return array_map('intval', $connection->fetchCol($select));
    }

    private function collectParentsForChildren(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }

        $connection = $this->getConnection();
        $parents    = [];

        $superLinkTable = $this->getTable('catalog_product_super_link');
        $select = $connection->select()
            ->from($superLinkTable, ['parent_id'])
            ->where('product_id IN (?)', $childIds)
            ->distinct(true);
        $parents = $this->mergeIds($parents, array_map('intval', $connection->fetchCol($select)));

        $linkTable = $this->getTable('catalog_product_link');
        $select = $connection->select()
            ->from($linkTable, ['product_id'])
            ->where('linked_product_id IN (?)', $childIds)
            ->where('link_type_id = ?', self::LINK_TYPE_GROUPED)
            ->distinct(true);
        $parents = $this->mergeIds($parents, array_map('intval', $connection->fetchCol($select)));

        $bundleTable = $this->getTable('catalog_product_bundle_selection');
        $select = $connection->select()
            ->from($bundleTable, ['parent_product_id'])
            ->where('product_id IN (?)', $childIds)
            ->distinct(true);
        $parents = $this->mergeIds($parents, array_map('intval', $connection->fetchCol($select)));

        return $parents;
    }

    private function expandToAffectedProductIds(array $productIds): array
    {
        $connection = $this->getConnection();
        $expanded = $productIds;

        $expanded = $this->mergeIds($expanded, $this->collectParentsForChildren($productIds));

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
