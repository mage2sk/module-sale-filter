<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * One-shot cleanup of the legacy `mage2sk_salefilter_product_index` table
 * from earlier builds of this module. The index now lives at
 * `panth_salefilter_product_index`; the old table is no longer in
 * db_schema.xml/whitelist so declarative schema leaves it alone. This
 * patch drops it if it still exists so upgraded installs do not carry a
 * stale duplicate.
 */
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
