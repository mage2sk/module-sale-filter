<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model\Indexer;

use Magento\Framework\Exception\LocalizedException;
use Panth\SaleFilter\Model\Cache\TagInvalidator;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\Indexer\ProductIndexer;
use Panth\SaleFilter\Model\ResourceModel\Indexer\ProductIndexer as Resource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductIndexerTest extends TestCase
{
    private function config(bool $special = true, bool $rules = false): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isIncludeSpecialPrices')->willReturn($special);
        $config->method('isIncludeCatalogRules')->willReturn($rules);
        return $config;
    }

    private function indexer(Resource $resource, ?TagInvalidator $invalidator = null, ?LoggerInterface $logger = null): ProductIndexer
    {
        return new ProductIndexer(
            $resource,
            $this->config(),
            $invalidator ?? $this->createStub(TagInvalidator::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testExecuteFullPassesConfigAndInvalidatesChangedIds(): void
    {
        $resource = $this->createMock(Resource::class);
        $resource->expects($this->once())->method('reindexAll')->with(true, false)->willReturn(12);
        $resource->method('getChangedIds')->willReturn([4, 5]);
        $invalidator = $this->createMock(TagInvalidator::class);
        $invalidator->expects($this->once())->method('invalidateProducts')->with([4, 5]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('12 rows'));

        $this->indexer($resource, $invalidator, $logger)->executeFull();
    }

    public function testExecuteFullWrapsFailures(): void
    {
        $resource = $this->createStub(Resource::class);
        $resource->method('reindexAll')->willThrowException(new \RuntimeException('lock'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Sale filter full reindex failed: lock');
        $this->indexer($resource)->executeFull();
    }

    public function testExecuteListNormalizesIds(): void
    {
        $resource = $this->createMock(Resource::class);
        $resource->expects($this->once())->method('reindexByIds')->with([3, 7], true, false)->willReturn(2);
        $resource->method('getChangedIds')->willReturn([]);

        $this->indexer($resource)->executeList(['3', 7, 0, -4, 3, 'x']);
    }

    public function testExecuteListWithNoValidIdsIsNoop(): void
    {
        $resource = $this->createMock(Resource::class);
        $resource->expects($this->never())->method('reindexByIds');

        $this->indexer($resource)->executeList([0, null, '']);
    }

    public function testExecuteListWrapsFailures(): void
    {
        $resource = $this->createStub(Resource::class);
        $resource->method('reindexByIds')->willThrowException(new \RuntimeException('deadlock'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('partial reindex failed: deadlock');
        $this->indexer($resource)->executeList([1]);
    }

    public function testExecuteRowSkipsInvalidIdAndWrapsFailure(): void
    {
        $resource = $this->createMock(Resource::class);
        $resource->expects($this->once())->method('reindexByIds')->with([9], true, false)
            ->willThrowException(new \RuntimeException('bad'));
        $indexer = $this->indexer($resource);

        $indexer->executeRow(0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('reindex failed for product 9: bad');
        $indexer->executeRow('9');
    }

    public function testExecuteAcceptsTraversableAndIgnoresEmpty(): void
    {
        $resource = $this->createMock(Resource::class);
        $resource->expects($this->once())->method('reindexByIds')->with([1, 2], true, false)->willReturn(2);
        $resource->method('getChangedIds')->willReturn([]);
        $indexer = $this->indexer($resource);

        $indexer->execute([]);
        $indexer->execute(new \ArrayIterator(['a' => 1, 'b' => 2]));
    }

    public function testCacheInvalidationFailureDoesNotFailReindex(): void
    {
        $resource = $this->createStub(Resource::class);
        $resource->method('reindexByIds')->willReturn(1);
        $resource->method('getChangedIds')->willReturn([1]);
        $invalidator = $this->createStub(TagInvalidator::class);
        $invalidator->method('invalidateProducts')->willThrowException(new \RuntimeException('cache'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('cache'));

        $this->indexer($resource, $invalidator, $logger)->executeRow(1);
    }
}
