<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\ResourceModel\Index;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Collection backing the admin UI-component listing.
 *
 * Extends Magento's UI-component {@see SearchResult} so it satisfies both
 * the CollectionFactory instanceof check AND the SearchResultInterface
 * contract the DataProvider expects.
 *
 * Joins the raw index table with catalog_product, store_website,
 * customer_group, the two price EAV decimals, catalogrule_product_price,
 * plus a correlated subquery over catalogrule / catalogrule_product so the
 * admin sees the full pricing picture alongside every on-sale row.
 *
 * The natural composite PK is flattened into a synthetic `grid_id` column
 * so the UI component has a single-column row identifier.
 *
 * Filter columns that map to SQL expressions / joined tables are rewritten
 * in {@see self::addFieldToFilter()} so the WHERE clauses resolve:
 *   - joined-table columns -> qualified alias in WHERE
 *   - ambiguous columns    -> main_table.<col> in WHERE
 *   - match_source         -> per-case WHERE on the underlying columns
 *   - applicable_rules     -> WHERE EXISTS over catalogrule_product + catalogrule
 *   - discount_percent     -> HAVING on the alias (simple numeric range)
 *   - grid_id              -> HAVING on the alias
 */
class Collection extends SearchResult
{
    /** Columns that only exist as SELECT aliases — filter via HAVING. */
    private const HAVING_COLUMNS = [
        'grid_id',
        'discount_percent',
    ];

    /**
     * Remap virtual column names to their underlying qualified alias.
     *
     * @var array<string, string>
     */
    private const FIELD_MAP = [
        'entity_id'           => 'main_table.entity_id',
        'customer_group_id'   => 'main_table.customer_group_id',
        'website_id'          => 'main_table.website_id',
        'is_on_sale'          => 'main_table.is_on_sale',
        'updated_at'          => 'main_table.updated_at',
        'sku'                 => 'product.sku',
        'product_type'        => 'product.type_id',
        'website_code'        => 'website.code',
        'website_name'        => 'website.name',
        'customer_group_code' => 'cg.customer_group_code',
        'regular_price'       => 'rp.value',
        'special_price'       => 'spd.value',
        'rule_price'          => 'crp.rule_price',
    ];

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        EventManagerInterface $eventManager,
        $mainTable = 'panth_salefilter_product_index',
        $resourceModel = \Panth\SaleFilter\Model\ResourceModel\Index::class
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel
        );
        $this->_idFieldName = 'grid_id';
    }

    /**
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $select             = $this->getSelect();
        $priceAttrId        = $this->resolveAttributeId('price');
        $specialPriceAttrId = $this->resolveAttributeId('special_price');

        $select->columns([
            'grid_id' => new \Zend_Db_Expr(
                "CONCAT(main_table.entity_id, '_', main_table.customer_group_id, '_', main_table.website_id)"
            ),
        ]);

        $select->joinLeft(
            ['product' => $this->getTable('catalog_product_entity')],
            'product.entity_id = main_table.entity_id',
            ['sku' => 'sku', 'product_type' => 'type_id']
        );

        $select->joinLeft(
            ['website' => $this->getTable('store_website')],
            'website.website_id = main_table.website_id',
            ['website_code' => 'code', 'website_name' => 'name']
        );

        $select->joinLeft(
            ['cg' => $this->getTable('customer_group')],
            'cg.customer_group_id = main_table.customer_group_id',
            ['customer_group_code' => 'customer_group_code']
        );

        $select->joinLeft(
            ['rp' => $this->getTable('catalog_product_entity_decimal')],
            sprintf(
                'rp.entity_id = main_table.entity_id AND rp.attribute_id = %d AND rp.store_id = 0',
                $priceAttrId
            ),
            ['regular_price' => 'rp.value']
        );

        $select->joinLeft(
            ['spd' => $this->getTable('catalog_product_entity_decimal')],
            sprintf(
                'spd.entity_id = main_table.entity_id AND spd.attribute_id = %d AND spd.store_id = 0',
                $specialPriceAttrId
            ),
            ['special_price' => 'spd.value']
        );

        $select->joinLeft(
            ['crp' => $this->getTable('catalogrule_product_price')],
            'crp.product_id = main_table.entity_id'
            . ' AND crp.website_id = main_table.website_id'
            . ' AND crp.customer_group_id = main_table.customer_group_id'
            . ' AND crp.rule_date = CURDATE()',
            ['rule_price' => 'crp.rule_price']
        );

        $catalogRule        = $this->getTable('catalogrule');
        $catalogRuleProduct = $this->getTable('catalogrule_product');
        $select->columns([
            'applicable_rules' => new \Zend_Db_Expr(
                "(SELECT GROUP_CONCAT(DISTINCT cr.name ORDER BY cr.name SEPARATOR ', ')
                  FROM {$catalogRuleProduct} crl
                  INNER JOIN {$catalogRule} cr ON cr.rule_id = crl.rule_id
                  WHERE crl.product_id = main_table.entity_id
                    AND crl.website_id = main_table.website_id
                    AND crl.customer_group_id = main_table.customer_group_id
                    AND cr.is_active = 1
                    AND crl.from_time <= UNIX_TIMESTAMP(NOW())
                    AND (crl.to_time = 0 OR crl.to_time >= UNIX_TIMESTAMP(NOW())))"
            ),
        ]);

        $select->columns([
            'match_source' => new \Zend_Db_Expr(
                "CASE
                    WHEN (spd.value IS NOT NULL AND spd.value > 0 AND rp.value IS NOT NULL AND spd.value < rp.value)
                         AND crp.product_id IS NOT NULL THEN 'Both'
                    WHEN (spd.value IS NOT NULL AND spd.value > 0 AND rp.value IS NOT NULL AND spd.value < rp.value)
                         THEN 'Special Price'
                    WHEN crp.product_id IS NOT NULL THEN 'Catalog Rule'
                    ELSE 'Parent Aggregation'
                END"
            ),
        ]);

        $select->columns([
            'discount_percent' => new \Zend_Db_Expr(
                "CASE
                    WHEN rp.value IS NULL OR rp.value = 0 THEN NULL
                    WHEN crp.rule_price IS NOT NULL AND crp.rule_price < rp.value
                         AND (spd.value IS NULL OR spd.value = 0 OR crp.rule_price <= spd.value)
                         THEN ROUND(((rp.value - crp.rule_price) / rp.value) * 100, 2)
                    WHEN spd.value IS NOT NULL AND spd.value > 0 AND spd.value < rp.value
                         THEN ROUND(((rp.value - spd.value) / rp.value) * 100, 2)
                    ELSE NULL
                END"
            ),
        ]);

        return $this;
    }

    /**
     * Route filter fields to the correct SQL layer.
     *
     * @param string|array<int, string> $field
     * @param mixed                     $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if (is_string($field)) {
            if ($field === 'match_source') {
                $this->applyMatchSourceFilter($this->extractSingleValue($condition));
                return $this;
            }

            if ($field === 'applicable_rules') {
                $this->applyApplicableRulesFilter($this->extractSingleValue($condition));
                return $this;
            }

            if (in_array($field, self::HAVING_COLUMNS, true)) {
                $this->getSelect()->having($this->_getConditionSql($field, $condition));
                return $this;
            }

            if (isset(self::FIELD_MAP[$field])) {
                return parent::addFieldToFilter(self::FIELD_MAP[$field], $condition);
            }
        }

        return parent::addFieldToFilter($field, $condition);
    }

    /**
     * Translate a match_source value into a WHERE clause against the underlying joined columns.
     */
    private function applyMatchSourceFilter(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $isSpecialExpr      = 'spd.value IS NOT NULL AND spd.value > 0 AND rp.value IS NOT NULL AND spd.value < rp.value';
        $hasRulePriceExpr   = 'crp.product_id IS NOT NULL';
        $notSpecialExpr     = "NOT ($isSpecialExpr)";
        $noRulePriceExpr    = 'crp.product_id IS NULL';

        switch ($value) {
            case 'Special Price':
                $this->getSelect()->where("($isSpecialExpr) AND ($noRulePriceExpr)");
                break;
            case 'Catalog Rule':
                $this->getSelect()->where("($notSpecialExpr) AND ($hasRulePriceExpr)");
                break;
            case 'Both':
                $this->getSelect()->where("($isSpecialExpr) AND ($hasRulePriceExpr)");
                break;
            case 'Parent Aggregation':
                $this->getSelect()->where("($notSpecialExpr) AND ($noRulePriceExpr)");
                break;
        }
    }

    /**
     * Translate a selected applicable_rules value into a WHERE EXISTS subquery.
     */
    private function applyApplicableRulesFilter(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $catalogRule        = $this->getTable('catalogrule');
        $catalogRuleProduct = $this->getTable('catalogrule_product');
        $escaped            = $this->getConnection()->quote($value);

        $this->getSelect()->where(
            "EXISTS (SELECT 1
                     FROM {$catalogRuleProduct} crl
                     INNER JOIN {$catalogRule} cr ON cr.rule_id = crl.rule_id
                     WHERE crl.product_id = main_table.entity_id
                       AND crl.website_id = main_table.website_id
                       AND crl.customer_group_id = main_table.customer_group_id
                       AND cr.is_active = 1
                       AND crl.from_time <= UNIX_TIMESTAMP(NOW())
                       AND (crl.to_time = 0 OR crl.to_time >= UNIX_TIMESTAMP(NOW()))
                       AND cr.name = {$escaped})"
        );
    }

    /**
     * UI dropdowns wrap the chosen value as ['eq' => 'X']. Unwrap to a scalar.
     *
     * @param mixed $condition
     */
    private function extractSingleValue($condition): ?string
    {
        if (is_string($condition)) {
            return $condition;
        }
        if (is_array($condition)) {
            if (isset($condition['eq'])) return (string) $condition['eq'];
            if (isset($condition['like'])) return trim((string) $condition['like'], '%');
            if (count($condition) === 1) return (string) reset($condition);
        }
        return null;
    }

    private function resolveAttributeId(string $code): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->getTable('eav_attribute')], ['attribute_id'])
            ->join(
                ['t' => $this->getTable('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->where('t.entity_type_code = ?', 'catalog_product')
            ->where('a.attribute_code = ?', $code)
            ->limit(1);

        return (int) $connection->fetchOne($select);
    }
}
