<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Plugin\Catalog\Model\ResourceModel\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use Panth\SaleFilter\Plugin\Catalog\Model\ResourceModel\Product\GetSizePlugin;
use PHPUnit\Framework\TestCase;

class GetSizePluginTest extends TestCase
{
    public function testFlagOverridesSizeWithoutCallingProceed(): void
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getFlag')->willReturnMap([[ApplySaleFilterPlugin::COUNT_FLAG, '4']]);
        $called = false;

        $size = (new GetSizePlugin())->aroundGetSize($collection, function () use (&$called) {
            $called = true;
            return 99;
        });

        $this->assertSame(4, $size);
        $this->assertFalse($called);
    }

    public function testZeroFlagIsStillAnOverride(): void
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getFlag')->willReturn(0);

        $this->assertSame(0, (new GetSizePlugin())->aroundGetSize($collection, static fn() => 99));
    }

    public function testMissingFlagDelegatesToProceed(): void
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getFlag')->willReturn(null);

        $this->assertSame(17, (new GetSizePlugin())->aroundGetSize($collection, static fn() => 17));
    }
}
