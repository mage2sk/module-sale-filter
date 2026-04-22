<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * Dropdown options for the grid's Website / Website ID filters.
 *
 * Each provides a different option shape so the same source can feed a
 * column filtering by website_id OR by website code.
 */
class Website implements OptionSourceInterface
{
    public function __construct(
        private readonly WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    /**
     * Default form: value = website_id (used by the Website ID column).
     *
     * @return array<int, array{value: string|int, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->websiteRepository->getList() as $website) {
            if ((int) $website->getId() === 0) {
                continue; // skip the admin pseudo-website
            }
            $options[] = [
                'value' => (int) $website->getId(),
                'label' => sprintf('%s (#%d)', $website->getName(), $website->getId()),
            ];
        }

        return $options;
    }
}
