<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model\ResourceModel\Fulltext\Collection;

use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Data\Collection\AbstractDb as Collection;
use Magento\Framework\DB\Select;
use Panth\SaleFilter\Model\ResourceModel\Fulltext\Collection\SearchResultApplier;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use PHPUnit\Framework\TestCase;

class SearchResultApplierTest extends TestCase
{
    private array $wheres = [];
    private array $orders = [];

    private function collection(mixed $flag): Collection
    {
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $select->method('reset')->willReturnSelf();
        $select->method('order')->willReturnCallback(function ($expr) use ($select) {
            $this->orders[] = (string) $expr;
            return $select;
        });
        $collection = $this->createStub(Collection::class);
        $collection->method('getFlag')->willReturnMap([[ApplySaleFilterPlugin::ITEMS_FLAG, $flag]]);
        $collection->method('getSelect')->willReturn($select);
        return $collection;
    }

    private function searchResult(array $ids): SearchResultInterface
    {
        $docs = [];
        foreach ($ids as $id) {
            $doc = $this->createStub(DocumentInterface::class);
            $doc->method('getId')->willReturn($id);
            $docs[] = $doc;
        }
        $result = $this->createStub(SearchResultInterface::class);
        $result->method('getItems')->willReturn($docs);
        return $result;
    }

    public function testEmptyAllowedIdsMatchesNothing(): void
    {
        (new SearchResultApplier($this->collection([]), $this->searchResult([1])))->apply();

        $this->assertSame([['NULL', null]], $this->wheres);
    }

    public function testAllowedIdsArePagedAndOrdered(): void
    {
        (new SearchResultApplier($this->collection([10, 20, 30, 40, 50]), $this->searchResult([]), 2, 2))->apply();

        $this->assertSame([['e.entity_id IN (?)', [30, 40]]], $this->wheres);
        $this->assertSame(['FIELD(e.entity_id,30,40)'], $this->orders);
    }

    public function testPageBeyondLastIsClampedToLastPage(): void
    {
        (new SearchResultApplier($this->collection([1, 2, 3]), $this->searchResult([]), 2, 9))->apply();

        $this->assertSame([['e.entity_id IN (?)', [3]]], $this->wheres);
    }

    public function testPageBelowOneIsClampedToFirstPage(): void
    {
        (new SearchResultApplier($this->collection([1, 2, 3]), $this->searchResult([]), 2, 0))->apply();

        $this->assertSame([['e.entity_id IN (?)', [1, 2]]], $this->wheres);
    }

    public function testZeroSizeReturnsAllIds(): void
    {
        (new SearchResultApplier($this->collection([5, 6, 7]), $this->searchResult([]), 0, 3))->apply();

        $this->assertSame([['e.entity_id IN (?)', [5, 6, 7]]], $this->wheres);
    }

    public function testWithoutFlagUsesSearchResultOrder(): void
    {
        (new SearchResultApplier($this->collection(null), $this->searchResult([9, '4', 7]), 2, 1))->apply();

        $this->assertSame([['e.entity_id IN (?)', [9, 4]]], $this->wheres);
        $this->assertSame(['FIELD(e.entity_id,9,4)'], $this->orders);
    }

    public function testEmptySearchResultMatchesNothing(): void
    {
        (new SearchResultApplier($this->collection(null), $this->searchResult([])))->apply();

        $this->assertSame([['NULL', null]], $this->wheres);
        $this->assertSame([], $this->orders);
    }
}
