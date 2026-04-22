<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Source;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Dropdown options for the grid's Active Catalog Rules column.
 *
 * Lists rule names that are currently active AND within their date window.
 * The column holds GROUP_CONCAT(name) — Magento's `select` UI filter
 * matches against the stored value by equality, so the user can at least
 * narrow to rows that contain one specific active rule; pair with a text
 * search for multi-rule rows.
 */
class CatalogRule implements OptionSourceInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $connection = $this->resource->getConnection();
        $names = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalogrule'), ['name'])
                ->where('is_active = ?', 1)
                ->where('from_date IS NULL OR from_date <= CURDATE()')
                ->where('to_date   IS NULL OR to_date   >= CURDATE()')
                ->order('name ASC')
        );

        $options = [];
        foreach (array_unique(array_filter($names)) as $name) {
            $options[] = ['value' => (string) $name, 'label' => (string) $name];
        }

        return $options;
    }
}
