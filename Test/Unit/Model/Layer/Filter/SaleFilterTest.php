<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model\Layer\Filter;

use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\Item;
use Magento\Catalog\Model\Layer\Filter\Item\DataBuilder;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\Catalog\Model\Layer\State;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
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
use Panth\SaleFilter\Model\LayerProductIds;
use Panth\SaleFilter\Plugin\Catalog\Model\Layer\ApplySaleFilterPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SaleFilterTest extends TestCase
{
    private State $state;
    private Layer $layer;

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

    private function layerIds(array $ids): LayerProductIds
    {
        $layerIds = $this->createStub(LayerProductIds::class);
        $layerIds->method('getIds')->willReturn($ids);
        return $layerIds;
    }

    private function filter(
        ?Config $config = null,
        ?ProductCollection $layerCollection = null,
        ?LayerProductIds $layerIds = null,
        ?LoggerInterface $logger = null,
        array $onSaleIds = ['4', '6']
    ): SaleFilter {
        $itemFactory = $this->createStub(ItemFactory::class);
        $itemFactory->method('create')->willReturnCallback(fn() => $this->newItem());

        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->layer = $this->createStub(Layer::class);
        $this->layer->method('getState')->willReturn($this->state);
        $this->layer->method('getProductCollection')
            ->willReturn($layerCollection ?? $this->createStub(ProductCollection::class));

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($onSaleIds);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new SaleFilter(
            $itemFactory,
            $storeManager,
            $this->layer,
            $this->createStub(DataBuilder::class),
            $this->createStub(HttpContext::class),
            $resource,
            $config ?? $this->config(),
            $logger ?? $this->createStub(LoggerInterface::class),
            $layerIds ?? $this->layerIds([])
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
        $filter = $this->filter(null, null, $this->layerIds([4, 5]));
        $filter->apply($this->request('1'));

        $this->assertSame([], $this->itemsData($filter));
    }

    public function testItemsAreHiddenWhenPluginAlreadyFilteredCollection(): void
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getFlag')->willReturnMap([[ApplySaleFilterPlugin::COUNT_FLAG, 3]]);

        $this->assertSame([], $this->itemsData($this->filter(null, $collection, $this->layerIds([4, 5]))));
    }

    public function testCountsFollowTheProductsMatchingOtherActiveFilters(): void
    {
        $data = $this->itemsData($this->filter(null, null, $this->layerIds([4, 7, 6])));

        $this->assertSame([
            ['label' => 'On Sale', 'value' => Config::VALUE_ON_SALE, 'count' => 2],
            ['label' => 'Regular Price', 'value' => Config::VALUE_NOT_ON_SALE, 'count' => 1],
        ], $data);
    }

    public function testCountsAreResolvedForTheFilterLayer(): void
    {
        $layerIds = $this->createMock(LayerProductIds::class);
        $layerIds->expects($this->once())->method('getIds')
            ->with($this->callback(fn($layer) => $layer === $this->layer))
            ->willReturn([6]);

        $data = $this->itemsData($this->filter(null, null, $layerIds));

        $this->assertSame([['label' => 'On Sale', 'value' => 1, 'count' => 1]], $data);
    }

    public function testZeroCountOptionsAndHiddenOptionAreOmitted(): void
    {
        $this->assertSame([], $this->itemsData($this->filter(null, null, $this->layerIds([]))));

        $data = $this->itemsData($this->filter($this->config(false), null, $this->layerIds([4, 6, 8])));
        $this->assertSame([['label' => 'On Sale', 'value' => 1, 'count' => 2]], $data);

        $data = $this->itemsData($this->filter(null, null, $this->layerIds([8, 9]), null, []));
        $this->assertSame([['label' => 'Regular Price', 'value' => 0, 'count' => 2]], $data);
    }

    public function testFailureIsLoggedAndNoItemsReturned(): void
    {
        $layerIds = $this->createStub(LayerProductIds::class);
        $layerIds->method('getIds')->willThrowException(new \RuntimeException('engine down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('engine down'));

        $this->assertSame([], $this->itemsData($this->filter(null, null, $layerIds, $logger)));
    }
}
