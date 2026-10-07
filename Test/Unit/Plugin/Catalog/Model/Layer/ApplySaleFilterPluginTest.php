<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Plugin\Catalog\Model\Layer;

use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Search as SearchLayer;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\SaleFilter\Model\ActiveSaleFilter;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\LayerProductIds;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApplySaleFilterPluginTest extends TestCase
{
    private array $flags = [];
    private array $selectWheres = [];
    private array $indexWheres = [];
    private ActiveSaleFilter $active;

    protected function setUp(): void
    {
        $this->active = new ActiveSaleFilter();
    }

    public function testResolvedIdsRestrictTheCoreSearchRequest(): void
    {
        $this->plugin(['sale_filter' => '1'], [5], null, $this->layerIds([4, 5]))
            ->afterGetProductCollection($this->createStub(Layer::class), $this->layerCollection());

        $this->assertSame([5], $this->active->getAllowedIds());
    }

    public function testNoRestrictionWithoutActiveFilter(): void
    {
        $this->plugin([], [5], null, $this->layerIds([4, 5]))
            ->afterGetProductCollection($this->createStub(Layer::class), $this->layerCollection());

        $this->assertNull($this->active->getAllowedIds());
    }

    private function config(bool $enabled = true, bool $showNotOnSale = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('isShowNotOnSaleOption')->willReturn($showNotOnSale);
        $config->method('normalizeFilterValue')->willReturnCallback(
            static fn($raw) => in_array($raw, ['0', '1'], true) ? (int) $raw : null
        );
        return $config;
    }

    private function request(array $params): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn($key, $default = null) => $params[$key] ?? $default
        );
        $request->method('getParams')->willReturn($params);
        return $request;
    }

    private function layerCollection(): ProductCollection
    {
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->selectWheres[] = [$cond, $value];
            return $select;
        });
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getFlag')->willReturnCallback(fn($key) => $this->flags[$key] ?? null);
        $collection->method('setFlag')->willReturnCallback(function ($key, $value) use ($collection) {
            $this->flags[$key] = $value;
            return $collection;
        });
        $collection->method('getSelect')->willReturn($select);
        return $collection;
    }

    private function resource(array $onSaleIds): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->indexWheres[$cond] = $value;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($onSaleIds);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function plugin(
        array $params,
        array $onSaleIds = [],
        ?Config $config = null,
        ?LayerProductIds $layerIds = null,
        ?LoggerInterface $logger = null
    ): ApplySaleFilterPlugin {
        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $http = $this->createStub(HttpContext::class);
        $http->method('getValue')->willReturn(1);

        return new ApplySaleFilterPlugin(
            $this->request($params),
            $config ?? $this->config(),
            $this->resource($onSaleIds),
            $storeManager,
            $http,
            $logger ?? $this->createStub(LoggerInterface::class),
            $layerIds ?? $this->layerIds([]),
            $this->active
        );
    }

    private function layerIds(array $ids): LayerProductIds
    {
        $layerIds = $this->createStub(LayerProductIds::class);
        $layerIds->method('getIds')->willReturn($ids);
        return $layerIds;
    }

    public function testNonCollectionResultIsReturnedUnchanged(): void
    {
        $result = new \stdClass();
        $this->assertSame($result, $this->plugin(['sale_filter' => '1'])
            ->afterGetProductCollection($this->createStub(Layer::class), $result));
    }

    public function testAlreadyAppliedCollectionIsSkipped(): void
    {
        $collection = $this->layerCollection();
        $this->flags[ApplySaleFilterPlugin::APPLIED_FLAG] = true;

        $this->plugin(['sale_filter' => '1'])->afterGetProductCollection($this->createStub(Layer::class), $collection);

        $this->assertArrayNotHasKey(ApplySaleFilterPlugin::ITEMS_FLAG, $this->flags);
        $this->assertSame([], $this->selectWheres);
    }

    public function testDisabledModuleMarksAppliedButDoesNotFilter(): void
    {
        $collection = $this->layerCollection();

        $this->plugin(['sale_filter' => '1'], [1], $this->config(false))
            ->afterGetProductCollection($this->createStub(Layer::class), $collection);

        $this->assertTrue($this->flags[ApplySaleFilterPlugin::APPLIED_FLAG]);
        $this->assertArrayNotHasKey(ApplySaleFilterPlugin::ITEMS_FLAG, $this->flags);
    }

    public function testInvalidOrHiddenValueDoesNotFilter(): void
    {
        $this->plugin(['sale_filter' => 'x'])->afterGetProductCollection($this->createStub(Layer::class), $this->layerCollection());
        $this->plugin(['sale_filter' => '0'], [], $this->config(true, false))
            ->afterGetProductCollection($this->createStub(Layer::class), $this->layerCollection());

        $this->assertSame([], $this->selectWheres);
    }

    public function testOnSaleKeepsSearchEngineOrderOfMatchingProducts(): void
    {
        $this->plugin(['sale_filter' => '1'], ['9', '2', '5'], null, $this->layerIds([2, 4, 5, 7, 9]))
            ->afterGetProductCollection($this->createStub(Layer::class), $this->layerCollection());

        $this->assertSame([2, 5, 9], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
        $this->assertSame(3, $this->flags[ApplySaleFilterPlugin::COUNT_FLAG]);
        $this->assertSame([['e.entity_id IN (?)', [2, 5, 9]]], $this->selectWheres);
        $this->assertSame(1, $this->indexWheres['idx.customer_group_id = ?']);
        $this->assertSame(2, $this->indexWheres['idx.website_id = ?']);
        $this->assertSame(1, $this->indexWheres['idx.is_on_sale = ?']);
    }

    public function testNotOnSaleKeepsTheRemainingProducts(): void
    {
        $this->plugin(['sale_filter' => '0'], [5], null, $this->layerIds([4, 5, 9]))
            ->afterGetProductCollection($this->createStub(Layer::class), $this->layerCollection());

        $this->assertSame([4, 9], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
        $this->assertSame(2, $this->flags[ApplySaleFilterPlugin::COUNT_FLAG]);
    }

    public function testNotOnSaleWithEmptyIndexKeepsEveryLayerProduct(): void
    {
        $this->plugin(['sale_filter' => '0'], [], null, $this->layerIds([3, 1]))
            ->afterGetProductCollection($this->createStub(Layer::class), $this->layerCollection());

        $this->assertSame([3, 1], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
    }

    public function testNoMatchingLayerProductsGivesEmptyState(): void
    {
        $this->plugin(['sale_filter' => '1', 'color' => '59'], [1, 2], null, $this->layerIds([]))
            ->afterGetProductCollection($this->createStub(SearchLayer::class), $this->layerCollection());

        $this->assertSame([], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
        $this->assertSame(0, $this->flags[ApplySaleFilterPlugin::COUNT_FLAG]);
        $this->assertSame([['e.entity_id IN (?)', [0]]], $this->selectWheres);
    }

    public function testLayerIdsAreResolvedForTheSubjectLayer(): void
    {
        $layer = $this->createStub(Layer::class);
        $layerIds = $this->createMock(LayerProductIds::class);
        $layerIds->expects($this->once())->method('getIds')->with($this->identicalTo($layer))->willReturn([8]);

        $this->plugin(['sale_filter' => '1'], [8], null, $layerIds)
            ->afterGetProductCollection($layer, $this->layerCollection());

        $this->assertSame([8], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
    }

    public function testFailureIsLoggedAndCollectionReturned(): void
    {
        $layerIds = $this->createStub(LayerProductIds::class);
        $layerIds->method('getIds')->willThrowException(new \RuntimeException('broken'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('broken'));
        $collection = $this->layerCollection();

        $result = $this->plugin(['sale_filter' => '1'], [], null, $layerIds, $logger)
            ->afterGetProductCollection($this->createStub(Layer::class), $collection);

        $this->assertSame($collection, $result);
        $this->assertArrayNotHasKey(ApplySaleFilterPlugin::ITEMS_FLAG, $this->flags);
    }
}
