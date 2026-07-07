<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\SaleFilter\Model\ResourceModel\Index as IndexResource;

class Index extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(IndexResource::class);
    }
}
