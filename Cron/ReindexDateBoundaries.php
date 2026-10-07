<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Cron;

use Magento\Framework\Indexer\IndexerRegistry;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\ResourceModel\Indexer\ProductIndexer as ProductIndexerResource;
use Psr\Log\LoggerInterface;

class ReindexDateBoundaries
{
    private const INDEXER_ID = 'panth_salefilter_product';

    public function __construct(
        private readonly ProductIndexerResource $resource,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isIncludeSpecialPrices() && !$this->config->isIncludeCatalogRules()) {
            return;
        }

        try {
            $ids = $this->resource->getDateBoundaryProductIds();
            if ($ids === []) {
                return;
            }

            $this->indexerRegistry->get(self::INDEXER_ID)->reindexList($ids);

            $this->logger->info(sprintf(
                '[panth_salefilter] Date boundary reindex: %d products',
                count($ids)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(
                '[panth_salefilter] Date boundary reindex failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
