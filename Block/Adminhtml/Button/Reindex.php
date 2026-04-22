<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Block\Adminhtml\Button;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * "Refresh Index" button shown in the top-right corner of the grid.
 *
 * Submits to the Reindex controller via a standard form POST so Magento's
 * admin CSRF token is applied automatically.
 */
class Reindex implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @return array<string, mixed>
     */
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
