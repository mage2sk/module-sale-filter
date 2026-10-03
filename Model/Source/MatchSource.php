<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class MatchSource implements OptionSourceInterface
{
    public const SOURCE_SPECIAL      = 'Special Price';
    public const SOURCE_CATALOG_RULE = 'Catalog Rule';
    public const SOURCE_BOTH         = 'Both';
    public const SOURCE_PARENT       = 'Parent Aggregation';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SOURCE_SPECIAL,      'label' => __('Special Price')],
            ['value' => self::SOURCE_CATALOG_RULE, 'label' => __('Catalog Rule')],
            ['value' => self::SOURCE_BOTH,         'label' => __('Both')],
            ['value' => self::SOURCE_PARENT,       'label' => __('Parent Aggregation')],
        ];
    }
}
