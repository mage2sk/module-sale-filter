<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model\Cache;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use Panth\SaleFilter\Model\Cache\TagInvalidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TagInvalidatorTest extends TestCase
{
    private array $selectProductIds = [];

    private function resource(array $paths): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value) use ($select) {
            $this->selectProductIds[] = $value;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($paths);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function contextFactory(CacheContext $context): CacheContextFactory
    {
        $factory = $this->createStub(CacheContextFactory::class);
        $factory->method('create')->willReturn($context);
        return $factory;
    }

    public function testInvalidIdsAreIgnoredWithoutTouchingCache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('clean');
        $factory = $this->createMock(CacheContextFactory::class);
        $factory->expects($this->never())->method('create');

        (new TagInvalidator(
            $cache,
            $this->createStub(ManagerInterface::class),
            $factory,
            $this->createStub(ResourceConnection::class),
            $this->createStub(LoggerInterface::class)
        ))->invalidateProducts([0, -1, 'abc', null]);
    }

    public function testRegistersProductsAndAncestorCategoriesExcludingRoot(): void
    {
        $context = new CacheContext();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clean')->with($this->callback(
            static fn(array $tags) => in_array(Product::CACHE_TAG . '_5', $tags, true)
        ));
        $events = $this->createMock(ManagerInterface::class);
        $events->expects($this->once())->method('dispatch')->with('clean_cache_by_tags', ['object' => $context]);

        (new TagInvalidator(
            $cache,
            $events,
            $this->contextFactory($context),
            $this->resource(['1/2/10', '1/2/10/11']),
            $this->createStub(LoggerInterface::class)
        ))->invalidateProducts([5, '5', 9]);

        $this->assertSame([5, 9], $context->getRegisteredEntity(Product::CACHE_TAG));
        $this->assertSame([2, 10, 11], $context->getRegisteredEntity(Product::CACHE_PRODUCT_CATEGORY_TAG));
        $this->assertSame([[5, 9]], $this->selectProductIds);
    }

    public function testIdsAreProcessedInChunksOf500(): void
    {
        $factory = $this->createMock(CacheContextFactory::class);
        $factory->expects($this->exactly(3))->method('create')->willReturnCallback(static fn() => new CacheContext());

        (new TagInvalidator(
            $this->createStub(CacheInterface::class),
            $this->createStub(ManagerInterface::class),
            $factory,
            $this->resource([]),
            $this->createStub(LoggerInterface::class)
        ))->invalidateProducts(range(1, 1001));

        $this->assertCount(3, $this->selectProductIds);
        $this->assertCount(500, $this->selectProductIds[0]);
        $this->assertSame([1001], $this->selectProductIds[2]);
    }

    public function testCleanFailureIsLogged(): void
    {
        $context = new CacheContext();
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willThrowException(new \RuntimeException('redis gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('redis gone'));

        (new TagInvalidator(
            $cache,
            $this->createStub(ManagerInterface::class),
            $this->contextFactory($context),
            $this->resource([]),
            $logger
        ))->invalidateProducts([3]);
    }
}
