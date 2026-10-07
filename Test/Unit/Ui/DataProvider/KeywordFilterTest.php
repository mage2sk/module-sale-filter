<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Panth\SaleFilter\Model\ResourceModel\Index\Collection as IndexCollection;
use Panth\SaleFilter\Ui\DataProvider\KeywordFilter;
use PHPUnit\Framework\TestCase;

class KeywordFilterTest extends TestCase
{
    public function testIndexCollectionReceivesKeyword(): void
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('getValue')->willReturn('SKU-12');
        $collection = $this->createMock(IndexCollection::class);
        $collection->expects($this->once())->method('applyKeywordSearch')->with('SKU-12')->willReturnSelf();

        (new KeywordFilter())->apply($collection, $filter);
    }

    public function testOtherCollectionsAreIgnored(): void
    {
        $filter = $this->createMock(Filter::class);
        $filter->expects($this->never())->method('getValue');

        $this->assertNull((new KeywordFilter())->apply($this->createStub(Collection::class), $filter));
    }
}
