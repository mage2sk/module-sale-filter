<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class DropLegacyIndexTable implements SchemaPatchInterface
{
    public function __construct(private readonly SchemaSetupInterface $schemaSetup)
    {
    }

    public function apply(): self
    {
        $connection = $this->schemaSetup->getConnection();
        $legacy     = $this->schemaSetup->getTable('mage2sk_salefilter_product_index');

        if ($connection->isTableExists($legacy)) {
            $connection->dropTable($legacy);
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
