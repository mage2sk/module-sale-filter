<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Block\Adminhtml\Button;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class Reindex implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getButtonData(): array
    {
        return [
            'label'      => __('Refresh Index'),
            'class'      => 'primary',
            'on_click'   => sprintf(
                "setLocation('%s')",
                $this->urlBuilder->getUrl('panth_salefilter/index/reindex')
            ),
            'sort_order' => 10,
        ];
    }
}
