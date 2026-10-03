<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class ResetIndexGridBookmark implements DataPatchInterface
{
    private const NAMESPACE_ID = 'panth_salefilter_index_listing';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function apply(): self
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('ui_bookmark');

        if ($connection->isTableExists($table)) {
            $connection->delete($table, ['namespace = ?' => self::NAMESPACE_ID]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
