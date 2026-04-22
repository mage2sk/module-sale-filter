<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Indexer;

use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\ResourceModel\Indexer\ProductIndexer as ProductIndexerResource;
use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\App\Cache\Type\Block as BlockCacheType;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\PageCache\Model\Cache\Type as PageCacheType;
use Psr\Log\LoggerInterface;
use Throwable;
use Traversable;

/**
 * Panth Sale Filter product indexer action.
 *
 * Thin orchestrator that delegates the SQL-heavy work to {@see ProductIndexerResource}
 * and is responsible for:
 *  - Implementing both the full-reindex and Mview action contracts.
 *  - Reading the admin flags from {@see Config} so the ResourceModel only sees booleans.
 *  - Flushing caches once a scope has been rebuilt so storefront sees fresh data.
 *  - Error handling: per spec §Edge Cases #1, a product with no price is skipped,
 *    not fatal - log and keep going.
 */
class ProductIndexer implements IndexerActionInterface, MviewActionInterface
{
    /** @var string[] Cache tags cleaned after each (re)index run. */
    private const CACHE_TAGS_TO_CLEAN = [
        Config::CACHE_TAG,
        AppConfig::CACHE_TAG,
        BlockCacheType::CACHE_TAG,
        PageCacheType::CACHE_TAG,
    ];

    public function __construct(
        private readonly ProductIndexerResource $resource,
        private readonly Config $config,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CacheManager $cacheManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Full reindex: rebuild the sale-filter index for every product in every scope.
     *
     * @return void
     * @throws LocalizedException Re-thrown with context if the ResourceModel fails.
     */
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

    /**
     * Reindex a subset of product ids (partial reindex / targeted list).
     *
     * @param array<int, int|string> $ids Product entity ids.
     * @return void
     * @throws LocalizedException Re-thrown with context if the ResourceModel fails.
     */
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
            // Spec §Edge Cases #1: skip products that can't be indexed, don't crash
            // the whole batch. We still bubble a LocalizedException so the indexer
            // state reflects the failure, but the inner logger records the details.
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

    /**
     * Reindex a single product id.
     *
     * @param int $id Product entity id.
     * @return void
     * @throws LocalizedException Re-thrown with context if the ResourceModel fails.
     */
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

    /**
     * Mview hook: called by the materialized-view scheduler with the changed ids.
     *
     * @param int[]|Traversable<int> $ids
     * @return void
     * @throws LocalizedException
     */
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

    /**
     * Clean tagged cache entries + invalidate the block/full_page cache types.
     *
     * Collected in one helper so every entry point produces the same side-effects.
     *
     * @return void
     */
    private function invalidateCaches(): void
    {
        try {
            $this->cacheManager->clean(self::CACHE_TAGS_TO_CLEAN);
            $this->cacheTypeList->invalidate([BlockCacheType::TYPE_IDENTIFIER, PageCacheType::TYPE_IDENTIFIER]);
        } catch (Throwable $e) {
            // Cache clean failures must never break a reindex - log and move on.
            $this->logger->warning(
                '[panth_salefilter] Cache invalidation after reindex failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Cast arbitrary id input to a clean list of positive ints.
     *
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
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
