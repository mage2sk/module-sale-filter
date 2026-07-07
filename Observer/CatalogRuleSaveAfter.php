<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Panth\SaleFilter\Model\Cache\TagInvalidator;
use Psr\Log\LoggerInterface;

class CatalogRuleSaveAfter implements ObserverInterface
{
    private const INDEXER_ID_SELF         = 'panth_salefilter_product';
    private const INDEXER_ID_CATALOG_RULE = 'catalogrule_rule';

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
        }
    }
}
