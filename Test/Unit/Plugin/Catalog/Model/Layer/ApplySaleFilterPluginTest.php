<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Plugin\Catalog\Model\Layer;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Search as SearchLayer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\ResourceModel\Indexer\ProductIndexer;
use Panth\SaleFilter\Model\SearchResultIds;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApplySaleFilterPluginTest extends TestCase
{
    private array $flags = [];
    private array $selectWheres = [];
    private array $indexWheres = [];
    private array $fieldFilters = [];
    private array $sorts = [];

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
        $select->method('distinct')->willReturnSelf();
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

    private function scopedCollection(array $allIds = [], array $productIds = []): ProductCollection
    {
        $products = array_map(static fn($id) => new DataObject(['id' => $id]), $productIds);
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use ($collection) {
            $this->fieldFilters[] = [$field, $cond];
            return $collection;
        });
        $collection->method('addAttributeToSort')->willReturnCallback(function ($attr, $dir) use ($collection) {
            $this->sorts[] = [$attr, $dir];
            return $collection;
        });
        $collection->method('getAllIds')->willReturn($allIds);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));
        return $collection;
    }

    private function plugin(
        array $params,
        array $onSaleIds = [],
        ?Config $config = null,
        ?ProductCollection $scoped = null,
        array $searchIds = [],
        ?LoggerInterface $logger = null
    ): ApplySaleFilterPlugin {
        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $http = $this->createStub(HttpContext::class);
        $http->method('getValue')->willReturn(1);
        $visibility = $this->createStub(Visibility::class);
        $visibility->method('getVisibleInCatalogIds')->willReturn([2, 4]);
        $visibility->method('getVisibleInSearchIds')->willReturn([3, 4]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($scoped ?? $this->scopedCollection());
        $search = $this->createStub(SearchResultIds::class);
        $search->method('getIds')->willReturn($searchIds);

        return new ApplySaleFilterPlugin(
            $this->request($params),
            $config ?? $this->config(),
            $this->resource($onSaleIds),
            $storeManager,
            $http,
            $visibility,
            $factory,
            $logger ?? $this->createStub(LoggerInterface::class),
            $this->createStub(Stock::class),
            $this->createStub(EavConfig::class),
            $search,
            $this->createStub(ProductIndexer::class)
        );
    }

    private function categoryLayer(int $id = 10, int $level = 3): Layer
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getLevel')->willReturn($level);
        $layer = $this->createStub(Layer::class);
        $layer->method('getCurrentCategory')->willReturn($category);
        return $layer;
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

    public function testStoreWideOnSaleUsesIndexForCurrentScope(): void
    {
        $collection = $this->layerCollection();

        $this->plugin(['sale_filter' => '1'], ['5', '8'])
            ->afterGetProductCollection($this->categoryLayer(1, 1), $collection);

        $this->assertSame([5, 8], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
        $this->assertSame(2, $this->flags[ApplySaleFilterPlugin::COUNT_FLAG]);
        $this->assertSame([['e.entity_id IN (?)', [5, 8]]], $this->selectWheres);
        $this->assertSame(1, $this->indexWheres['idx.customer_group_id = ?']);
        $this->assertSame(2, $this->indexWheres['idx.website_id = ?']);
    }

    public function testStoreWideNotOnSaleListsCatalogProductsThatAreNotOnSale(): void
    {
        $scoped = $this->scopedCollection([], [4, 9]);
        $categoryFilters = 0;
        $scoped->method('addCategoryFilter')->willReturnCallback(function () use ($scoped, &$categoryFilters) {
            $categoryFilters++;
            return $scoped;
        });
        $visibility = [];
        $scoped->method('setVisibility')->willReturnCallback(function ($ids) use ($scoped, &$visibility) {
            $visibility = $ids;
            return $scoped;
        });

        $this->plugin(['sale_filter' => '0'], [5], null, $scoped)
            ->afterGetProductCollection($this->createStub(Layer::class), $this->layerCollection());

        $this->assertSame([4, 9], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
        $this->assertSame(2, $this->flags[ApplySaleFilterPlugin::COUNT_FLAG]);
        $this->assertContains(['entity_id', ['nin' => [5]]], $this->fieldFilters);
        $this->assertSame([2, 4], $visibility);
        $this->assertSame(0, $categoryFilters);
        $this->assertSame([['e.entity_id IN (?)', [4, 9]]], $this->selectWheres);
    }

    public function testCategoryScopeIntersectsAndSorts(): void
    {
        $scoped = $this->scopedCollection([], [7, 3]);

        $this->plugin(
            ['sale_filter' => '1', 'price' => '10-50', 'cat' => '0', 'product_list_order' => 'price', 'product_list_dir' => 'desc'],
            [3, 7],
            null,
            $scoped
        )->afterGetProductCollection($this->categoryLayer(), $this->layerCollection());

        $this->assertSame([7, 3], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
        $this->assertContains(['price', ['gteq' => 10.0]], $this->fieldFilters);
        $this->assertContains(['price', ['lt' => 50.0]], $this->fieldFilters);
        $this->assertContains(['entity_id', ['in' => [3, 7]]], $this->fieldFilters);
        $this->assertSame([['price', 'DESC']], $this->sorts);
    }

    public function testCategoryScopeNotOnSaleUsesNinWithPlaceholder(): void
    {
        $scoped = $this->scopedCollection([], [4]);

        $this->plugin(['sale_filter' => '0', 'product_list_dir' => 'sideways'], [], null, $scoped)
            ->afterGetProductCollection($this->categoryLayer(), $this->layerCollection());

        $this->assertContains(['entity_id', ['nin' => [0]]], $this->fieldFilters);
        $this->assertSame([['position', 'ASC']], $this->sorts);
        $this->assertSame([4], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
    }

    public function testSearchScopeKeepsRelevanceOrder(): void
    {
        $layer = $this->createStub(SearchLayer::class);
        $scoped = $this->scopedCollection(['9', '2']);

        $this->plugin(['sale_filter' => '1', 'q' => 'tee'], [2, 9], null, $scoped, [2, 5, 9])
            ->afterGetProductCollection($layer, $this->layerCollection());

        $this->assertSame([2, 9], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
        $this->assertContains(['entity_id', ['in' => [2, 5, 9]]], $this->fieldFilters);
        $this->assertSame([], $this->sorts);
    }

    public function testSearchWithoutResultsMatchesNothing(): void
    {
        $this->plugin(['sale_filter' => '1', 'q' => 'zzz'], [1], null, null, [])
            ->afterGetProductCollection($this->createStub(SearchLayer::class), $this->layerCollection());

        $this->assertSame([], $this->flags[ApplySaleFilterPlugin::ITEMS_FLAG]);
        $this->assertSame(0, $this->flags[ApplySaleFilterPlugin::COUNT_FLAG]);
    }

    public function testFailureIsLoggedAndCollectionReturned(): void
    {
        $layer = $this->createStub(Layer::class);
        $layer->method('getCurrentCategory')->willThrowException(new \RuntimeException('broken'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('broken'));
        $collection = $this->layerCollection();

        $result = $this->plugin(['sale_filter' => '1'], [], null, null, [], $logger)
            ->afterGetProductCollection($layer, $collection);

        $this->assertSame($collection, $result);
        $this->assertArrayNotHasKey(ApplySaleFilterPlugin::ITEMS_FLAG, $this->flags);
    }
}
