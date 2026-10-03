<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model\Cache;

use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContextFactory;
use Psr\Log\LoggerInterface;

class TagInvalidator
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly EventManager $eventManager,
        private readonly CacheContextFactory $cacheContextFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function invalidateProducts(array $productIds): void
    {
        $ids = [];
        foreach ($productIds as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $ids[$int] = $int;
            }
        }
        if ($ids === []) {
            return;
        }

        foreach (array_chunk(array_values($ids), self::CHUNK_SIZE) as $chunk) {
            $this->clean(
                $chunk,
                $this->resolveCategoryIds($chunk)
            );
        }
    }

    private function clean(array $productIds, array $categoryIds): void
    {
        try {
            $context = $this->cacheContextFactory->create();
            if ($productIds !== []) {
                $context->registerEntities(CatalogProduct::CACHE_TAG, $productIds);
            }
            if ($categoryIds !== []) {
                $context->registerEntities(CatalogProduct::CACHE_PRODUCT_CATEGORY_TAG, $categoryIds);
            }

            $tags = $context->getIdentities();
            if ($tags === []) {
                return;
            }

            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $context]);
            $this->cache->clean($tags);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[panth_salefilter] Cache clean failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    private function resolveCategoryIds(array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['ccp' => $this->resourceConnection->getTableName('catalog_category_product')], [])
            ->join(
                ['cce' => $this->resourceConnection->getTableName('catalog_category_entity')],
                'cce.entity_id = ccp.category_id',
                ['path']
            )
            ->where('ccp.product_id IN (?)', $productIds)
            ->distinct(true);

        $categoryIds = [];
        foreach ($connection->fetchCol($select) as $path) {
            foreach (explode('/', (string) $path) as $position => $id) {
                $int = (int) $id;
                if ($position > 0 && $int > 0) {
                    $categoryIds[$int] = $int;
                }
            }
        }

        return array_values($categoryIds);
    }
}
