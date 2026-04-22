<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\SaleFilter\Model\ResourceModel\Index as IndexResource;

/**
 * Thin row model for a single {@code panth_salefilter_product_index} record.
 *
 * The underlying table has a composite primary key
 * (entity_id + customer_group_id + website_id), so this model is read-only
 * and only used by the admin grid to expose rows via the UI component
 * listing. Writes go through {@see \Panth\SaleFilter\Model\ResourceModel\Indexer\ProductIndexer}.
 */
class Index extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(IndexResource::class);
    }
}
