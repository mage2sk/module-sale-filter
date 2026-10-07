<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Plugin\LayeredNavigation;

use Magento\Catalog\Model\Layer\Filter\FilterInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\LayeredNavigation\Block\Navigation\FilterRenderer;
use Panth\SaleFilter\Block\LayeredNavigation\FilterRenderer as SaleFilterRenderer;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilter;
use Panth\SaleFilter\Plugin\LayeredNavigation\FilterRendererPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FilterRendererPluginTest extends TestCase
{
    private function subject(?LayoutInterface $layout, array $data = [], string $title = ''): FilterRenderer
    {
        $subject = $this->createStub(FilterRenderer::class);
        $subject->method('getLayout')->willReturn($layout);
        $subject->method('getData')->willReturnCallback(static fn($key) => $data[$key] ?? null);
        $subject->method('__call')->willReturnCallback(static fn($m) => $m === 'getFilterTitle' ? $title : null);
        return $subject;
    }

    private function saleFilter(): SaleFilter
    {
        $filter = $this->createStub(SaleFilter::class);
        $filter->method('getName')->willReturn('Sale Status');
        return $filter;
    }

    public function testNonSaleFilterUsesDefaultRenderer(): void
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->expects($this->never())->method('createBlock');
        $filter = $this->createStub(FilterInterface::class);

        $html = (new FilterRendererPlugin($this->createStub(LoggerInterface::class)))
            ->aroundRender($this->subject($layout), static fn($f) => 'default', $filter);

        $this->assertSame('default', $html);
    }

    public function testMissingLayoutFallsBack(): void
    {
        $html = (new FilterRendererPlugin($this->createStub(LoggerInterface::class)))
            ->aroundRender($this->subject(null), static fn($f) => 'default', $this->saleFilter());

        $this->assertSame('default', $html);
    }

    public function testRendersOwnBlockWithDefaults(): void
    {
        $filter = $this->saleFilter();
        $block = $this->createMock(SaleFilterRenderer::class);
        $block->expects($this->once())->method('setTemplate')->with('Panth_SaleFilter::layer/filter/sale.phtml')->willReturnSelf();
        $block->expects($this->once())->method('__call')->with('setFilterTitle', ['Sale Status'])->willReturnSelf();
        $block->method('toHtml')->willReturn('<sale/>');
        $layout = $this->createMock(LayoutInterface::class);
        $layout->expects($this->once())
            ->method('createBlock')
            ->with(SaleFilterRenderer::class, '', ['data' => ['filter' => $filter]])
            ->willReturn($block);

        $html = (new FilterRendererPlugin($this->createStub(LoggerInterface::class)))
            ->aroundRender($this->subject($layout), static fn($f) => 'default', $filter);

        $this->assertSame('<sale/>', $html);
    }

    public function testCustomTemplateAndTitleAreHonouredButInvalidBlockClassIsReplaced(): void
    {
        $block = $this->createMock(SaleFilterRenderer::class);
        $block->expects($this->once())->method('setTemplate')->with('Vendor_Theme::sale.phtml')->willReturnSelf();
        $block->expects($this->once())->method('__call')->with('setFilterTitle', ['Deals'])->willReturnSelf();
        $block->method('toHtml')->willReturn('<custom/>');
        $layout = $this->createMock(LayoutInterface::class);
        $layout->expects($this->once())
            ->method('createBlock')
            ->with(SaleFilterRenderer::class)
            ->willReturn($block);

        $subject = $this->subject($layout, [
            FilterRendererPlugin::DATA_TEMPLATE    => 'Vendor_Theme::sale.phtml',
            FilterRendererPlugin::DATA_BLOCK_CLASS => \stdClass::class,
        ], 'Deals');

        $html = (new FilterRendererPlugin($this->createStub(LoggerInterface::class)))
            ->aroundRender($subject, static fn($f) => 'default', $this->saleFilter());

        $this->assertSame('<custom/>', $html);
    }

    public function testRenderFailureIsLoggedAndFallsBack(): void
    {
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('createBlock')->willThrowException(new \RuntimeException('no template'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('no template'));

        $html = (new FilterRendererPlugin($logger))
            ->aroundRender($this->subject($layout), static fn($f) => 'fallback', $this->saleFilter());

        $this->assertSame('fallback', $html);
    }
}
