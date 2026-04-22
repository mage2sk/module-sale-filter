<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * One-shot cleanup of stale ui_bookmark entries for the Sale Filter Index
 * grid. Earlier builds shipped a different column set + filter types;
 * admins who had saved a Default View against that older layout see
 * "Something went wrong with processing the default view" when the UI
 * tries to rehydrate the bookmark against the current column definitions.
 *
 * Removing the bookmark rows keyed on our namespace forces the grid back
 * to its factory state. Safe to re-run — the bookmark will simply be
 * re-created the next time a user saves one.
 */
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
