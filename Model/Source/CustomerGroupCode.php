<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Dropdown options for the grid's Customer Group column (value = group code).
 */
class CustomerGroupCode implements OptionSourceInterface
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        $groups  = $this->groupRepository->getList($this->searchCriteriaBuilder->create())->getItems();
        foreach ($groups as $group) {
            $code = (string) $group->getCode();
            $options[] = [
                'value' => $code,
                'label' => $code,
            ];
        }

        return $options;
    }
}
