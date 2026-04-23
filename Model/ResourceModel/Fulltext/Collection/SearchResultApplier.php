<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\ResourceModel\Fulltext\Collection;

use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection\SearchResultApplierInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Data\Collection;
use Magento\Framework\DB\Select;

/**
 * Search-result applier that honours the `sale_filter` layer filter.
 *
 * Magento's default ES applier slices `searchResult->getItems()` down to the
 * current page *first*, then writes `WHERE e.entity_id IN (page-slice-ids)`
 * into the collection select. That's fine without our filter — but ES
 * returns only the page slice (12 items out of 24 in the category), so
 * intersecting those 12 with our on-sale set can yield just 4-8 products per
 * page even when the true filtered total would fill the page.
 *
 * When the collection is tagged with `ITEMS_FLAG` (the full, already-ordered
 * list of entity_ids that pass the sale filter), we skip the ES items list
 * and slice *our* list into the current page. The grid then genuinely shows
 * the first N filtered products on page 1, the next N on page 2, etc.
 *
 * With no tag on the collection (every other category / search request in
 * the store) we reproduce the default applier behaviour — so nothing else
 * on the site changes.
 */
class SearchResultApplier implements SearchResultApplierInterface
{
    /**
     * @param Collection $collection
     * @param SearchResultInterface $searchResult
     * @param int $size
     * @param int $currentPage
     */
    public function __construct(
        private readonly Collection $collection,
        private readonly SearchResultInterface $searchResult,
        private readonly int $size = 0,
        private readonly int $currentPage = 1
    ) {
    }

    /**
     * @return void
     */
    public function apply()
    {
        $allowedIds = $this->collection->getFlag(ApplySaleFilterPlugin::ITEMS_FLAG);
        if (is_array($allowedIds)) {
            $this->applyFromIdList($allowedIds);
            return;
        }

        $this->applyFromSearchResult();
    }

    /**
     * @param array<int, int> $ids
     * @return void
     */
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

    /**
     * Default (no-filter) path — mirrors
     * {@see \Magento\Elasticsearch\Model\ResourceModel\Fulltext\Collection\SearchResultApplier::apply}.
     *
     * @return void
     */
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

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function sliceIds(array $ids, int $size, int $currentPage): array
    {
        [$offset, $pageSize] = $this->resolveSlice(count($ids), $size, $currentPage);
        return array_slice($ids, $offset, $pageSize);
    }

    /**
     * @param array<int, object> $items
     * @return array<int, object>
     */
    private function sliceItems(array $items, int $size, int $currentPage): array
    {
        [$offset, $pageSize] = $this->resolveSlice(count($items), $size, $currentPage);
        return array_slice($items, $offset, $pageSize);
    }

    /**
     * Resolve `[offset, size]` for the requested page, clamping `$currentPage`
     * to the last page (mirroring Magento's default behaviour).
     *
     * @return array{0: int, 1: int}
     */
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
