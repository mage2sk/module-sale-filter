<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Cron;

use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Panth\SaleFilter\Cron\ReindexDateBoundaries;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\ResourceModel\Indexer\ProductIndexer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReindexDateBoundariesTest extends TestCase
{
    private function config(bool $special, bool $rules): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isIncludeSpecialPrices')->willReturn($special);
        $config->method('isIncludeCatalogRules')->willReturn($rules);
        return $config;
    }

    public function testDoesNothingWhenBothSourcesDisabled(): void
    {
        $resource = $this->createMock(ProductIndexer::class);
        $resource->expects($this->never())->method('getDateBoundaryProductIds');

        (new ReindexDateBoundaries(
            $resource,
            $this->createStub(IndexerRegistry::class),
            $this->config(false, false),
            $this->createStub(LoggerInterface::class)
        ))->execute();
    }

    public function testNoBoundaryProductsSkipsReindex(): void
    {
        $resource = $this->createStub(ProductIndexer::class);
        $resource->method('getDateBoundaryProductIds')->willReturn([]);
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->never())->method('get');

        (new ReindexDateBoundaries($resource, $registry, $this->config(true, false), $this->createStub(LoggerInterface::class)))
            ->execute();
    }

    public function testReindexesBoundaryProductsAndLogsCount(): void
    {
        $resource = $this->createStub(ProductIndexer::class);
        $resource->method('getDateBoundaryProductIds')->willReturn([3, 8, 13]);
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('reindexList')->with([3, 8, 13]);
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->once())->method('get')->with('panth_salefilter_product')->willReturn($indexer);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('3 products'));

        (new ReindexDateBoundaries($resource, $registry, $this->config(false, true), $logger))->execute();
    }

    public function testFailureIsLoggedNotThrown(): void
    {
        $resource = $this->createStub(ProductIndexer::class);
        $resource->method('getDateBoundaryProductIds')->willThrowException(new \RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('db down'));

        (new ReindexDateBoundaries(
            $resource,
            $this->createStub(IndexerRegistry::class),
            $this->config(true, true),
            $logger
        ))->execute();
    }
}
