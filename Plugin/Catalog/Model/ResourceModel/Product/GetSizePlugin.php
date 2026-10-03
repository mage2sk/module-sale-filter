<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\ResourceModel\Product;

use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;

class GetSizePlugin
{
    public function aroundGetSize(ProductCollection $subject, callable $proceed)
    {
        $override = $subject->getFlag(ApplySaleFilterPlugin::COUNT_FLAG);
        if ($override !== null) {
            return (int) $override;
        }
        return $proceed();
    }
}
