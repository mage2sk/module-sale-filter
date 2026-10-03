<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\FilterBuilderFactory;
use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\Search\SearchInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Panth\SaleFilter\Model\SearchResultIds;
use PHPUnit\Framework\TestCase;

class SearchResultIdsTest extends TestCase
{
    private function doc(mixed $id): DocumentInterface
    {
        $doc = $this->createStub(DocumentInterface::class);
        $doc->method('getId')->willReturn($id);
        return $doc;
    }

    private function build(SearchInterface $search, ?SearchCriteria $criteria = null): SearchResultIds
    {
        $criteria ??= $this->createStub(SearchCriteria::class);
        $criteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('create')->willReturn($criteria);
        $criteriaFactory = $this->createStub(SearchCriteriaBuilderFactory::class);
        $criteriaFactory->method('create')->willReturn($criteriaBuilder);

        $filterBuilder = $this->createStub(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnSelf();
        $filterBuilder->method('setValue')->willReturnSelf();
        $filterBuilder->method('create')->willReturn($this->createStub(Filter::class));
        $filterFactory = $this->createStub(FilterBuilderFactory::class);
        $filterFactory->method('create')->willReturn($filterBuilder);

        $visibility = $this->createStub(Visibility::class);
        $visibility->method('getVisibleInSearchIds')->willReturn([3, 4]);

        return new SearchResultIds($search, $criteriaFactory, $filterFactory, $visibility);
    }

    private function searchReturning(array $docs): SearchInterface
    {
        $result = $this->createStub(SearchResultInterface::class);
        $result->method('getItems')->willReturn($docs);
        $search = $this->createStub(SearchInterface::class);
        $search->method('search')->willReturn($result);
        return $search;
    }

    public function testBlankQueryReturnsEmptyWithoutSearching(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->never())->method('search');

        $this->assertSame([], $this->build($search)->getIds('   '));
    }

    public function testIdsAreDeduplicatedAndNonPositiveDropped(): void
    {
        $search = $this->searchReturning([
            $this->doc(5), $this->doc('7'), $this->doc(5), $this->doc(0), $this->doc(-2), $this->doc(9),
        ]);

        $this->assertSame([5, 7, 9], $this->build($search)->getIds('shirt'));
    }

    public function testCriteriaIsConfiguredForQuickSearch(): void
    {
        $criteria = $this->createMock(SearchCriteria::class);
        $criteria->expects($this->once())->method('setRequestName')->with('quick_search_container');
        $criteria->expects($this->once())->method('setPageSize')->with(10000);
        $criteria->expects($this->once())->method('setCurrentPage')->with(0);
        $criteria->expects($this->once())->method('setSortOrders')->with([]);

        $this->assertSame([], $this->build($this->searchReturning([]), $criteria)->getIds('bag'));
    }

    public function testResultsAreCachedPerTrimmedQuery(): void
    {
        $result = $this->createStub(SearchResultInterface::class);
        $result->method('getItems')->willReturn([$this->doc(11)]);
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->once())->method('search')->willReturn($result);

        $service = $this->build($search);
        $this->assertSame([11], $service->getIds('hat'));
        $this->assertSame([11], $service->getIds(' hat '));
    }
}
