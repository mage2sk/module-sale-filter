<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Dropdown options for the grid's Group ID column (value = customer_group_id).
 */
class CustomerGroup implements OptionSourceInterface
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        $groups  = $this->groupRepository->getList($this->searchCriteriaBuilder->create())->getItems();
        foreach ($groups as $group) {
            $options[] = [
                'value' => (int) $group->getId(),
                'label' => sprintf('%s (#%d)', $group->getCode(), $group->getId()),
            ];
        }

        return $options;
    }
}
