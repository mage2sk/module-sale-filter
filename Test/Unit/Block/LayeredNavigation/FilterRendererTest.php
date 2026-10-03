<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Block\LayeredNavigation;

use Magento\Catalog\Model\Layer\Filter\FilterInterface;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Panth\SaleFilter\Block\LayeredNavigation\FilterRenderer;
use Panth\SaleFilter\Model\Config;
use PHPUnit\Framework\TestCase;

class FilterRendererTest extends TestCase
{
    private function block(array $data = [], ?Config $config = null): FilterRenderer
    {
        return new FilterRenderer(
            $this->createStub(Context::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(HttpContext::class),
            $this->createStub(RequestInterface::class),
            $config ?? $this->createStub(Config::class),
            $this->createStub(Resolver::class),
            $data
        );
    }

    public function testGetFilterOnlyReturnsFilterInstances(): void
    {
        $filter = $this->createStub(FilterInterface::class);

        $this->assertSame($filter, $this->block(['filter' => $filter])->getFilter());
        $this->assertNull($this->block(['filter' => 'not a filter'])->getFilter());
        $this->assertNull($this->block()->getFilter());
    }

    public function testLabelAndCountComeFromConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getFilterLabel')->willReturn('Deals');
        $config->method('isShowCount')->willReturn(true);
        $block = $this->block([], $config);

        $this->assertSame('Deals', $block->getFilterLabel());
        $this->assertTrue($block->isShowCount());
    }

    public function testIdentitiesUseModuleCacheTag(): void
    {
        $this->assertSame([Config::CACHE_TAG], $this->block()->getIdentities());
    }
}
