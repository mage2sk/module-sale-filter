<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Observer;

use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Panth\SaleFilter\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Keeps the sale filter index fresh on upstream data changes — catalog rules
 * applied, product save, product delete.
 *
 * Wired in etc/events.xml against:
 *   - catalogrule_after_apply
 *   - catalog_product_save_after
 *   - catalog_product_delete_after
 *
 * Mode-aware dispatch:
 *   - Update by Schedule: do nothing here (mview subscriptions + cron handle
 *     the catch-up). Cache tags still flushed so the storefront sees the new
 *     indexed state on the next cron-driven reindex.
 *   - Update on Save: call `reindexRow` / `reindexAll` directly on the indexer
 *     so the change is reflected immediately (calling `invalidate()` only
 *     marks the index stale — it does NOT actually rebuild it, so the
 *     storefront would keep serving pre-save data until someone ran the
 *     reindex command manually).
 *
 * Defensive: never re-throws. A broken reindex must not crash the request
 * that was saving the entity.
 */
class CatalogRuleSaveAfter implements ObserverInterface
{
    private const INDEXER_ID = 'panth_salefilter_product';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CacheManager $cacheManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $eventName = (string) ($observer->getEvent()->getName() ?: 'unknown');

        try {
            $indexer = $this->indexerRegistry->get(self::INDEXER_ID);

            if (!$indexer->isScheduled()) {
                $this->reindexRealtime($indexer, $observer, $eventName);
            }

            $this->cacheManager->clean([
                Config::CACHE_TAG,
                'block_html',
                'full_page',
            ]);

            $this->logger->debug('[Panth_SaleFilter] upstream event handled', [
                'trigger' => $eventName,
                'mode'    => $indexer->isScheduled() ? 'schedule' : 'realtime',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_SaleFilter] failed to handle upstream event', [
                'trigger' => $eventName,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Realtime (Update on Save) dispatch. Catalog-rule events trigger a full
     * reindex because a rule can affect arbitrary product sets; product save/
     * delete only reindex the affected row.
     */
    private function reindexRealtime($indexer, Observer $observer, string $eventName): void
    {
        if ($eventName === 'catalog_product_save_after'
            || $eventName === 'catalog_product_delete_after') {
            $product = $observer->getEvent()->getProduct();
            $productId = $product ? (int) $product->getId() : 0;
            if ($productId > 0) {
                $indexer->reindexRow($productId);
                return;
            }
        }

        // Catalog-rule apply / unknown event: rebuild everything. Cheap in
        // practice because our indexer writes in batches and most rule changes
        // already come through an explicit `indexer:reindex catalogrule_rule`
        // pass.
        $indexer->reindexAll();
    }
}
