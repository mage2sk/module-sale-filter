<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model\Layer\Filter;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\Item;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\Catalog\Model\Layer\State;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Block\Html\Pager;
use Panth\SaleFilter\Model\Config;
use Panth\SaleFilter\Model\Layer\Filter\SaleFilter;
use Panth\SaleFilter\Model\ResourceModel\Indexer\ProductIndexer;
use Panth\SaleFilter\Model\SearchResultIds;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SaleFilterTest extends TestCase
{
    private State $state;
    private array $countFilters = [];

    protected function setUp(): void
    {
        $this->state = new State();
    }

    private function config(bool $showNotOnSale = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isShowNotOnSaleOption')->willReturn($showNotOnSale);
        $config->method('getOnSaleOptionLabel')->willReturn('On Sale');
        $config->method('getNotOnSaleOptionLabel')->willReturn('Regular Price');
        $config->method('getFilterLabel')->willReturn('Sale Status');
        $config->method('normalizeFilterValue')->willReturnCallback(
            static fn($raw) => in_array($raw, ['0', '1'], true) ? (int) $raw : null
        );
        return $config;
    }

    private function newItem(): Item
    {
        return new Item($this->createStub(UrlInterface::class), $this->createStub(Pager::class));
    }

    private function filter(
        ?Config $config = null,
        ?ProductCollection $layerCollection = null,
        array $sizes = [0],
        ?LoggerInterface $logger = null
    ): SaleFilter {
        $itemFactory = $this->createStub(ItemFactory::class);
        $itemFactory->method('create')->willReturnCallback(fn() => $this->newItem());

        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn(10);
        $category->method('getLevel')->willReturn(2);
        $layer = $this->createStub(Layer::class);
        $layer->method('getState')->willReturn($this->state);
        $layer->method('getProductCollection')->willReturn($layerCollection ?? $this->createStub(ProductCollection::class));
        $layer->method('getCurrentCategory')->willReturn($category);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn(['4', '6']);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $productCollectionFactory = $this->createStub(CollectionFactory::class);
        $productCollectionFactory->method('create')->willReturnCallback(function () use (&$sizes) {
            $collection = $this->createStub(ProductCollection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(function ($f, $c) use ($collection) {
                $this->countFilters[] = [$f, $c];
                return $collection;
            });
            $collection->method('getSize')->willReturn(array_shift($sizes) ?? 0);
            return $collection;
        });

        return new SaleFilter(
            $itemFactory,
            $storeManager,
            $layer,
            $this->createStub(DataBuilder::class),
            $this->createStub(HttpContext::class),
            $resource,
            $config ?? $this->config(),
            $logger ?? $this->createStub(LoggerInterface::class),
            $productCollectionFactory,
            $this->createStub(Visibility::class),
            $this->createStub(Stock::class),
            $this->createStub(RequestInterface::class),
            $this->createStub(SearchResultIds::class),
            $this->createStub(ProductIndexer::class)
        );
    }

    private function request(mixed $value): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([[Config::FILTER_REQUEST_VAR, null, $value]]);
        return $request;
    }

    private function itemsData(SaleFilter $filter): array
    {
        $method = new \ReflectionMethod($filter, '_getItemsData');
        return $method->invoke($filter);
    }

    public function testRequestVarAndName(): void
    {
        $filter = $this->filter();

        $this->assertSame(Config::FILTER_REQUEST_VAR, $filter->getRequestVar());
        $this->assertSame('Sale Status', $filter->getName());
    }

    public function testApplyOnSaleAddsStateItem(): void
    {
        $filter = $this->filter();

        $this->assertSame($filter, $filter->apply($this->request('1')));

        $items = $this->state->getFilters();
        $this->assertCount(1, $items);
        $this->assertSame('On Sale', $items[0]->getLabel());
        $this->assertSame(Config::VALUE_ON_SALE, $items[0]->getValue());
        $this->assertSame($filter, $items[0]->getFilter());
    }

    public function testApplyNotOnSaleUsesRegularLabel(): void
    {
        $filter = $this->filter();
        $filter->apply($this->request('0'));

        $this->assertSame('Regular Price', $this->state->getFilters()[0]->getLabel());
    }

    public function testApplyIgnoresInvalidAndHiddenValues(): void
    {
        $this->filter()->apply($this->request('abc'));
        $this->filter($this->config(false))->apply($this->request('0'));

        $this->assertSame([], $this->state->getFilters());
    }

    public function testItemsAreHiddenOnceFilterIsActive(): void
    {
        $filter = $this->filter(null, null, [5, 5]);
        $filter->apply($this->request('1'));

        $this->assertSame([], $this->itemsData($filter));
    }

    public function testItemsAreHiddenWhenPluginAlreadyFilteredCollection(): void
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getFlag')->willReturnMap([[ApplySaleFilterPlugin::COUNT_FLAG, 3]]);

        $this->assertSame([], $this->itemsData($this->filter(null, $collection, [5, 5])));
    }

    public function testItemsDataContainsBothOptionsWithCounts(): void
    {
        $data = $this->itemsData($this->filter(null, null, [3, 12]));

        $this->assertSame([
            ['label' => 'On Sale', 'value' => Config::VALUE_ON_SALE, 'count' => 3],
            ['label' => 'Regular Price', 'value' => Config::VALUE_NOT_ON_SALE, 'count' => 12],
        ], $data);
        $this->assertContains(['entity_id', ['in' => [4, 6]]], $this->countFilters);
        $this->assertContains(['entity_id', ['nin' => [4, 6]]], $this->countFilters);
    }

    public function testZeroCountOptionsAndHiddenOptionAreOmitted(): void
    {
        $this->assertSame([], $this->itemsData($this->filter(null, null, [0, 0])));

        $data = $this->itemsData($this->filter($this->config(false), null, [2, 9]));
        $this->assertSame([['label' => 'On Sale', 'value' => 1, 'count' => 2]], $data);
    }
}
