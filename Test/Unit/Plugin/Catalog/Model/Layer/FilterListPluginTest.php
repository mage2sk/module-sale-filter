<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Plugin\Catalog\Model\Layer;

use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\FilterList;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilter;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilterFactory;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\FilterListPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FilterListPluginTest extends TestCase
{
    private function config(bool $enabled, int $position = 100): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getPosition')->willReturn($position);
        return $config;
    }

    private function attributeFilter(int $position): object
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getPosition')->willReturn($position);
        return new class ($attribute) {
            public function __construct(private $attribute)
            {
            }

            public function getAttributeModel()
            {
                return $this->attribute;
            }
        };
    }

    private function factory(SaleFilter $saleFilter, int $times = 1): SaleFilterFactory
    {
        $factory = $this->createMock(SaleFilterFactory::class);
        $factory->expects($this->exactly($times))->method('create')->willReturn($saleFilter);
        return $factory;
    }

    public function testDisabledReturnsResultUntouched(): void
    {
        $factory = $this->createMock(SaleFilterFactory::class);
        $factory->expects($this->never())->method('create');
        $plugin = new FilterListPlugin($this->config(false), $factory, $this->createStub(LoggerInterface::class));

        $result = ['a' => 'x'];
        $this->assertSame($result, $plugin->afterGetFilters(
            $this->createStub(FilterList::class),
            $result,
            $this->createStub(Layer::class)
        ));
    }

    public function testInsertsBeforeFirstFilterWithHigherPosition(): void
    {
        $sale = $this->createStub(SaleFilter::class);
        $low = $this->attributeFilter(10);
        $high = $this->attributeFilter(200);
        $category = new \stdClass();
        $plugin = new FilterListPlugin($this->config(true, 50), $this->factory($sale), $this->createStub(LoggerInterface::class));

        $result = $plugin->afterGetFilters(
            $this->createStub(FilterList::class),
            ['c' => $category, 'l' => $low, 'h' => $high],
            $this->createStub(Layer::class)
        );

        $this->assertSame([$category, $low, $sale, $high], $result);
    }

    public function testAppendsAtEndWhenNoHigherPosition(): void
    {
        $sale = $this->createStub(SaleFilter::class);
        $low = $this->attributeFilter(10);
        $plugin = new FilterListPlugin($this->config(true, 50), $this->factory($sale), $this->createStub(LoggerInterface::class));

        $result = $plugin->afterGetFilters($this->createStub(FilterList::class), [$low], $this->createStub(Layer::class));

        $this->assertSame([$low, $sale], $result);
    }

    public function testFilterIsCreatedOncePerLayer(): void
    {
        $sale = $this->createStub(SaleFilter::class);
        $layer = $this->createStub(Layer::class);
        $plugin = new FilterListPlugin($this->config(true), $this->factory($sale, 1), $this->createStub(LoggerInterface::class));
        $list = $this->createStub(FilterList::class);

        $first = $plugin->afterGetFilters($list, [], $layer);
        $second = $plugin->afterGetFilters($list, [], $layer);

        $this->assertSame([$sale], $first);
        $this->assertSame($first, $second);
    }

    public function testFactoryFailureIsLoggedAndOriginalReturned(): void
    {
        $factory = $this->createStub(SaleFilterFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $plugin = new FilterListPlugin($this->config(true), $factory, $logger);

        $result = $plugin->afterGetFilters($this->createStub(FilterList::class), ['x'], $this->createStub(Layer::class));

        $this->assertSame(['x'], $result);
    }
}
