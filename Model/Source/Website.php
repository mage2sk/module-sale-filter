<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

class Website implements OptionSourceInterface
{
    public function __construct(
        private readonly WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->websiteRepository->getList() as $website) {
            if ((int) $website->getId() === 0) {
                continue;
            }
            $options[] = [
                'value' => (int) $website->getId(),
                'label' => sprintf('%s (#%d)', $website->getName(), $website->getId()),
            ];
        }

        return $options;
    }
}
