<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Catalog\Model\Product\Type as CatalogProductType;
use Magento\Framework\Data\OptionSourceInterface;

class ProductType implements OptionSourceInterface
{
    public function __construct(private readonly CatalogProductType $productType)
    {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->productType->getOptionArray() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }
}
