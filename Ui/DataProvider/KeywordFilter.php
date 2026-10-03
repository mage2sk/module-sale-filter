<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\View\Element\UiComponent\DataProvider\FilterApplierInterface;
use Panth\SaleFilter\Model\ResourceModel\Index\Collection as IndexCollection;

class KeywordFilter implements FilterApplierInterface
{
    public function apply(Collection $collection, Filter $filter)
    {
        if ($collection instanceof IndexCollection) {
            $collection->applyKeywordSearch((string) $filter->getValue());
        }
    }
}
