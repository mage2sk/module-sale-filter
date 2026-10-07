<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model\ResourceModel\Index;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\SaleFilter\Model\ResourceModel\Index\Collection;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    private array $wheres = [];
    private array $havings = [];
    private array $conditions = [];

    private function collection(): Collection
    {
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function ($cond) use ($select) {
            $this->wheres[] = (string) $cond;
            return $select;
        });
        $select->method('having')->willReturnCallback(function ($cond) use ($select) {
            $this->havings[] = (string) $cond;
            return $select;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('prepareSqlCondition')->willReturnCallback(function ($field, $condition) {
            $this->conditions[] = [$field, $condition];
            return $field . ' COND';
        });

        $reflection = new \ReflectionClass(Collection::class);
        $collection = $reflection->newInstanceWithoutConstructor();
        $selectProperty = new \ReflectionProperty(\Magento\Framework\Data\Collection\AbstractDb::class, '_select');
        $selectProperty->setValue($collection, $select);
        $connProperty = new \ReflectionProperty(\Magento\Framework\Data\Collection\AbstractDb::class, '_conn');
        $connProperty->setValue($collection, $connection);

        return $collection;
    }

    public function testGridIdExpressionConcatenatesKeyColumns(): void
    {
        $sql = $this->collection()->getExpressionColumnSql('grid_id');

        $this->assertSame(
            "CONCAT(main_table.entity_id, '_', main_table.customer_group_id, '_', main_table.website_id)",
            $sql
        );
    }

    public function testDiscountPercentExpressionUsesPriceJoins(): void
    {
        $sql = $this->collection()->getExpressionColumnSql('discount_percent');

        $this->assertStringStartsWith('CASE', $sql);
        $this->assertStringContainsString('crp.rule_price', $sql);
        $this->assertStringContainsString('spd.value', $sql);
        $this->assertStringContainsString('rp.value', $sql);
    }

    public function testGridIdFilterUsesWhereNotHaving(): void
    {
        $collection = $this->collection();

        $result = $collection->addFieldToFilter('grid_id', ['like' => '%_0_%']);

        $this->assertSame($collection, $result);
        $this->assertSame([], $this->havings);
        $this->assertCount(1, $this->wheres);
        $this->assertStringStartsWith('(CONCAT(main_table.entity_id', $this->wheres[0]);
        $this->assertSame(['like' => '%_0_%'], $this->conditions[0][1]);
    }

    public function testDiscountPercentRangeFilterUsesWhereOnExpression(): void
    {
        $collection = $this->collection();

        $collection->addFieldToFilter('discount_percent', ['from' => '10', 'to' => '50']);

        $this->assertSame([], $this->havings);
        $this->assertCount(1, $this->wheres);
        $this->assertStringStartsWith('(CASE', $this->wheres[0]);
        $this->assertSame(['from' => '10', 'to' => '50'], $this->conditions[0][1]);
    }
}
