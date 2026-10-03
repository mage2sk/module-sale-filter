<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\LayeredNavigation;

use Magento\Catalog\Model\Layer\Filter\FilterInterface;
use Magento\LayeredNavigation\Block\Navigation\FilterRenderer;
use Panth\SaleFilter\Block\LayeredNavigation\FilterRenderer as SaleFilterRenderer;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilter;
use Psr\Log\LoggerInterface;

class FilterRendererPlugin
{
    public const DATA_TEMPLATE    = 'panth_salefilter_template';
    public const DATA_BLOCK_CLASS = 'panth_salefilter_block';

    private const DEFAULT_TEMPLATE = 'Panth_SaleFilter::layer/filter/sale.phtml';

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function aroundRender(FilterRenderer $subject, callable $proceed, FilterInterface $filter)
    {
        if (!$filter instanceof SaleFilter) {
            return $proceed($filter);
        }

        $layout = $subject->getLayout();
        if ($layout === null) {
            return $proceed($filter);
        }

        $template = (string) ($subject->getData(self::DATA_TEMPLATE) ?: self::DEFAULT_TEMPLATE);
        $blockClass = (string) ($subject->getData(self::DATA_BLOCK_CLASS) ?: SaleFilterRenderer::class);
        if (!is_a($blockClass, SaleFilterRenderer::class, true)) {
            $blockClass = SaleFilterRenderer::class;
        }

        try {
            $block = $layout->createBlock($blockClass, '', ['data' => ['filter' => $filter]]);
            $block->setTemplate($template);
            $block->setFilterTitle($subject->getFilterTitle() ?: $filter->getName());

            return $block->toHtml();
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Panth_SaleFilter] Sale filter template failed, falling back to the theme renderer: '
                . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $proceed($filter);
    }
}
