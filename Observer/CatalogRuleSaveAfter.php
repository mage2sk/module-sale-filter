<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Panth\SaleFilter\Model\Cache\TagInvalidator;
use Psr\Log\LoggerInterface;

/**
 * Keeps the sale filter index fresh on upstream data changes — catalog rule
 * save / delete, product save / delete.
 *
 * Wired in etc/events.xml against:
 *   - catalogrule_rule_save_commit_after
 *   - catalogrule_rule_delete_commit_after
 *   - catalog_product_save_after
 *   - catalog_product_delete_after
 *
 * Mode-aware dispatch:
 *   - Update by Schedule: let the MView framework + cron handle the catch-up.
 *   - Update on Save: reindex synchronously. For rule events we first force
 *     the built-in `catalogrule_rule` indexer to rebuild
 *     `catalogrule_product_price` (which our indexer reads), otherwise our
 *     reindex would see the pre-save rule state even though the rule itself
 *     was saved. With that rebuild done, our own `reindexAll()` sees fresh
 *     discount data.
 *
 * Cache invalidation is delegated to {@see TagInvalidator}, which walks every
 * configured cache frontend (default + page_cache + any custom frontends)
 * and cleans by tag. This matters on installs that run the `default` cache
 * and `page_cache` (FPC) on separate Redis databases — a single-frontend
 * clean would leave one of them stale.
 *
 * Defensive: never re-throws. A broken reindex or cache clean must not crash
 * the request that saved the entity.
 */
class CatalogRuleSaveAfter implements ObserverInterface
{
    private const INDEXER_ID_SELF         = 'panth_salefilter_product';
    private const INDEXER_ID_CATALOG_RULE = 'catalogrule_rule';

    /**
     * Event names that indicate a catalog-rule change (vs a product change).
     */
    private const RULE_EVENT_NAMES = [
        'catalogrule_rule_save_commit_after',
        'catalogrule_rule_delete_commit_after',
    ];

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly TagInvalidator $cacheInvalidator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $eventName = (string) ($observer->getEvent()->getName() ?: 'unknown');

        try {
            $indexer = $this->indexerRegistry->get(self::INDEXER_ID_SELF);

            if (!$indexer->isScheduled()) {
                $this->reindexRealtime($indexer, $observer, $eventName);
            }

            $this->cacheInvalidator->invalidate();

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
     * Realtime (Update on Save) dispatch.
     *
     * For product events we only reindex the affected row. For rule events we
     * first force the built-in `catalogrule_rule` indexer to rebuild
     * `catalogrule_product_price` (Magento's own catalog-rule reindex path
     * only fires this synchronously when the catalog-rule indexer is itself
     * in realtime mode — and many stores keep it on Update by Schedule), then
     * our full reindex reads the fresh price data and writes correct
     * on-sale flags.
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

        if (in_array($eventName, self::RULE_EVENT_NAMES, true)) {
            $this->forceCatalogRuleRebuild();
        }

        $indexer->reindexAll();
    }

    /**
     * Force the built-in `catalogrule_rule` indexer to rebuild
     * `catalogrule_product_price`. Without this, a rule save in this same
     * request keeps the pre-save price rows in the table — our indexer reads
     * those rows and produces stale on-sale flags.
     *
     * We call `reindexAll` directly on the indexer rather than on the
     * rule-product processor because the processor honours the indexer's
     * schedule mode: if catalogrule_rule is on Update by Schedule, the
     * processor just marks the indexer invalid and waits for cron. We need
     * the data synchronously, so we bypass that and run executeFull
     * ourselves.
     */
    private function forceCatalogRuleRebuild(): void
    {
        try {
            $catalogRuleIndexer = $this->indexerRegistry->get(self::INDEXER_ID_CATALOG_RULE);
            $catalogRuleIndexer->reindexAll();
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Panth_SaleFilter] failed to force catalog-rule reindex: ' . $e->getMessage(),
                ['exception' => $e]
            );
            // Continue — our own reindex will still run; it just reads stale
            // price data until the next mview cron pass cleans up.
        }
    }
}
