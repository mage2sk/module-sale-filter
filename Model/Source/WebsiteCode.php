<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * Dropdown options keyed by website code (for the Website column).
 */
class WebsiteCode implements OptionSourceInterface
{
    public function __construct(
        private readonly WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->websiteRepository->getList() as $website) {
            if ((int) $website->getId() === 0) {
                continue;
            }
            $options[] = [
                'value' => (string) $website->getCode(),
                'label' => (string) $website->getCode(),
            ];
        }

        return $options;
    }
}
