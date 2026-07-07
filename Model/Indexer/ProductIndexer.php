<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Indexer;

use Panth\SaleFilter\Model\Cache\TagInvalidator;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\ResourceModel\Indexer\ProductIndexer as ProductIndexerResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Traversable;

class ProductIndexer implements IndexerActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly ProductIndexerResource $resource,
        private readonly Config $config,
        private readonly TagInvalidator $cacheInvalidator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function executeFull(): void
    {
        $start = microtime(true);

        try {
            $rows = $this->resource->reindexAll(
                $this->config->isIncludeSpecialPrices(),
                $this->config->isIncludeCatalogRules()
            );

            $this->invalidateCaches();

            $this->logger->info(sprintf(
                '[panth_salefilter] Full reindex complete: %d rows in %.3fs',
                $rows,
                microtime(true) - $start
            ));
        } catch (Throwable $e) {
            $this->logger->error(
                '[panth_salefilter] Full reindex failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw new LocalizedException(
                __('Sale filter full reindex failed: %1', $e->getMessage()),
                $e
            );
        }
    }

    public function executeList(array $ids): void
    {
        $normalized = $this->normalizeIds($ids);
        if ($normalized === []) {
            return;
        }

        $start = microtime(true);

        try {
            $rows = $this->resource->reindexByIds(
                $normalized,
                $this->config->isIncludeSpecialPrices(),
                $this->config->isIncludeCatalogRules()
            );

            $this->invalidateCaches();

            $this->logger->info(sprintf(
                '[panth_salefilter] Partial reindex complete: %d ids -> %d rows in %.3fs',
                count($normalized),
                $rows,
                microtime(true) - $start
            ));
        } catch (Throwable $e) {
            $this->logger->error(
                '[panth_salefilter] Partial reindex failed: ' . $e->getMessage(),
                ['ids' => $normalized, 'exception' => $e]
            );
            throw new LocalizedException(
                __('Sale filter partial reindex failed: %1', $e->getMessage()),
                $e
            );
        }
    }

    public function executeRow($id): void
    {
        $productId = (int) $id;
        if ($productId <= 0) {
            return;
        }

        try {
            $this->resource->reindexByIds(
                [$productId],
                $this->config->isIncludeSpecialPrices(),
                $this->config->isIncludeCatalogRules()
            );

            $this->invalidateCaches();
        } catch (Throwable $e) {
            $this->logger->error(
                '[panth_salefilter] Row reindex failed for product ' . $productId . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw new LocalizedException(
                __('Sale filter reindex failed for product %1: %2', $productId, $e->getMessage()),
                $e
            );
        }
    }

    public function execute($ids): void
    {
        if ($ids instanceof Traversable) {
            $ids = iterator_to_array($ids, false);
        }

        if (!is_array($ids) || $ids === []) {
            return;
        }

        $this->executeList($ids);
    }

    private function invalidateCaches(): void
    {
        try {
            $this->cacheInvalidator->invalidate();
        } catch (Throwable $e) {
            $this->logger->warning(
                '[panth_salefilter] Cache invalidation after reindex failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    private function normalizeIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $clean[$int] = $int;
            }
        }

        return array_values($clean);
    }
}
