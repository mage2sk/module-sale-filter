<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Catalog\Model\Product\Type as CatalogProductType;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Dropdown options for the grid's Type filter — lists every catalog product
 * type id Magento has registered (simple, configurable, virtual, downloadable,
 * bundle, grouped + any 3rd-party types).
 */
class ProductType implements OptionSourceInterface
{
    public function __construct(private readonly CatalogProductType $productType)
    {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->productType->getOptionArray() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }
}
