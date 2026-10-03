<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\OptionSourceInterface;

class CustomerGroupCode implements OptionSourceInterface
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

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
