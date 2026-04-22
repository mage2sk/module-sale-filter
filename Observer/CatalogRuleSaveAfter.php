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
 * Invalidates the sale filter indexer + flushes the custom cache tag whenever
 * upstream data changes — catalog rules applied/saved, or product save/delete.
 *
 * Wired in etc/events.xml against:
 *   - catalogrule_after_apply
 *   - catalog_product_save_after
 *   - catalog_product_delete_after
 *
 * Defensive: never re-throws. A broken invalidation must not crash the request
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
        try {
            $indexer = $this->indexerRegistry->get(self::INDEXER_ID);
            if (!$indexer->isScheduled()) {
                // Non-scheduled: flag as invalid so the next cron / manual run rebuilds.
                $indexer->invalidate();
            }

            $this->cacheManager->clean([
                Config::CACHE_TAG,
                'block_html',
                'full_page',
            ]);

            $this->logger->debug('[Panth_SaleFilter] indexer invalidated', [
                'trigger' => $observer->getEvent()->getName(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_SaleFilter] failed to invalidate indexer on upstream event', [
                'trigger' => $observer->getEvent()->getName() ?: 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
