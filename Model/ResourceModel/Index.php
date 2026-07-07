<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Index extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_salefilter_product_index', 'entity_id');
    }
}
