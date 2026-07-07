<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\ResourceModel\Fulltext\Collection;

use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection\SearchResultApplierInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Data\Collection;
use Magento\Framework\DB\Select;

class SearchResultApplier implements SearchResultApplierInterface
{
    public function __construct(
        private readonly Collection $collection,
        private readonly SearchResultInterface $searchResult,
        private readonly int $size = 0,
        private readonly int $currentPage = 1
    ) {
    }

    public function apply()
    {
        $allowedIds = $this->collection->getFlag(ApplySaleFilterPlugin::ITEMS_FLAG);
        if (is_array($allowedIds)) {
            $this->applyFromIdList($allowedIds);
            return;
        }

        $this->applyFromSearchResult();
    }

    private function applyFromIdList(array $ids): void
    {
        if (empty($ids)) {
            $this->collection->getSelect()->where('NULL');
            return;
        }

        $pageIds = $this->sliceIds($ids, $this->size, $this->currentPage);
        if (empty($pageIds)) {
            $this->collection->getSelect()->where('NULL');
            return;
        }

        $orderList = implode(',', $pageIds);
        $this->collection->getSelect()
            ->where('e.entity_id IN (?)', $pageIds)
            ->reset(Select::ORDER)
            ->order(new \Zend_Db_Expr(sprintf('FIELD(e.entity_id,%s)', $orderList)));
    }

    private function applyFromSearchResult(): void
    {
        $items = $this->searchResult->getItems();
        if (empty($items)) {
            $this->collection->getSelect()->where('NULL');
            return;
        }

        $pageItems = $this->sliceItems($items, $this->size, $this->currentPage);

        $ids = array_map(static fn($item) => (int) $item->getId(), $pageItems);
        $orderList = implode(',', $ids);
        $this->collection->getSelect()
            ->where('e.entity_id IN (?)', $ids)
            ->reset(Select::ORDER)
            ->order(new \Zend_Db_Expr(sprintf('FIELD(e.entity_id,%s)', $orderList)));
    }

    private function sliceIds(array $ids, int $size, int $currentPage): array
    {
        [$offset, $pageSize] = $this->resolveSlice(count($ids), $size, $currentPage);
        return array_slice($ids, $offset, $pageSize);
    }

    private function sliceItems(array $items, int $size, int $currentPage): array
    {
        [$offset, $pageSize] = $this->resolveSlice(count($items), $size, $currentPage);
        return array_slice($items, $offset, $pageSize);
    }

    private function resolveSlice(int $total, int $size, int $currentPage): array
    {
        if ($size === 0) {
            return [0, $total];
        }

        $maxAllowedPageNumber = (int) ceil($total / $size);
        if ($currentPage < 1) {
            $currentPage = 1;
        }
        if ($maxAllowedPageNumber > 0 && $currentPage > $maxAllowedPageNumber) {
            $currentPage = $maxAllowedPageNumber;
        }

        return [($currentPage - 1) * $size, $size];
    }
}
