<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Catalog\Model\ResourceModel\Product;

use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;

/**
 * Return the post-filter size when `ApplySaleFilterPlugin` has stashed a
 * pre-computed count on the collection — otherwise the toolbar pager shows
 * the unfiltered total (the default `getSize()` comes from the ES search
 * result on Fulltext collections, which doesn't see our SQL-level WHERE).
 */
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
