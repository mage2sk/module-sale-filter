<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the index rows surfaced in the admin grid.
 *
 * Real primary key on the table is composite; framework needs a single
 * id column so we pass `entity_id` and rely on the collection's
 * expression field ({@see Index\Collection::_initSelect()}) to synthesize
 * a unique `grid_id` the UI component can key off.
 */
class Index extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_salefilter_product_index', 'entity_id');
    }
}
