<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Search as SearchLayer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\FilterBuilderFactory;
use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\Search\SearchInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Search\Model\Query;
use Magento\Search\Model\QueryFactory;
use Panth\SaleFilter\Model\ActiveSaleFilter;
use Panth\SaleFilter\Model\LayerProductIds;
use PHPUnit\Framework\TestCase;

class LayerProductIdsTest extends TestCase
{
    private array $filters = [];
    private array $criteriaCalls = [];
    private int $searches = 0;
    private ?array $restrictionDuringSearch = [];
    private ActiveSaleFilter $active;

    protected function setUp(): void
    {
        $this->active = new ActiveSaleFilter();
    }

    public function testOwnSearchIsNotRestrictedByTheActiveSaleFilter(): void
    {
        $this->active->setAllowedIds([3]);

        $ids = $this->service([], [$this->doc(3), $this->doc(8)])->getIds($this->categoryLayer());

        $this->assertSame([3, 8], $ids);
        $this->assertNull($this->restrictionDuringSearch);
        $this->assertSame([3], $this->active->getAllowedIds());
    }

    private function doc(mixed $id): DocumentInterface
    {
        $doc = $this->createStub(DocumentInterface::class);
        $doc->method('getId')->willReturn($id);
        return $doc;
    }

    private function attribute(int $id, string $backend, int $filterable, int $inSearch, int $sortable = 0): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getId')->willReturn($id);
        $attribute->method('getBackendType')->willReturn($backend);
        $attribute->method('getIsFilterable')->willReturn($filterable);
        $attribute->method('getIsFilterableInSearch')->willReturn($inSearch);
        $attribute->method('getUsedForSortBy')->willReturn($sortable);
        return $attribute;
    }

    private function eav(): EavConfig
    {
        $attributes = [
            'color' => $this->attribute(93, 'int', 1, 1),
            'material' => $this->attribute(94, 'varchar', 1, 0),
            'weight_range' => $this->attribute(95, 'decimal', 1, 1),
            'description' => $this->attribute(96, 'text', 0, 0),
            'name' => $this->attribute(73, 'varchar', 0, 0, 1),
            'price' => $this->attribute(77, 'decimal', 1, 1, 1),
        ];
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(
            function ($type, $code) use ($attributes) {
                if ($code === 'broken') {
                    throw new \RuntimeException('no attribute');
                }
                return $attributes[$code] ?? $this->attribute(0, 'static', 0, 0);
            }
        );
        return $eav;
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

    private function service(array $params, array $docs = [], bool $short = false): LayerProductIds
    {
        $criteria = $this->createStub(SearchCriteria::class);
        foreach (['setRequestName', 'setSortOrders', 'setPageSize', 'setCurrentPage'] as $method) {
            $criteria->method($method)->willReturnCallback(function ($value) use ($criteria, $method) {
                $this->criteriaCalls[$method] = $value;
                return $criteria;
            });
        }
        $criteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('create')->willReturn($criteria);
        $criteriaFactory = $this->createStub(SearchCriteriaBuilderFactory::class);
        $criteriaFactory->method('create')->willReturn($criteriaBuilder);

        $field = null;
        $filterBuilder = $this->createStub(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnCallback(function ($name) use (&$field, $filterBuilder) {
            $field = $name;
            return $filterBuilder;
        });
        $filterBuilder->method('setValue')->willReturnCallback(function ($value) use (&$field, $filterBuilder) {
            $this->filters[$field] = $value;
            return $filterBuilder;
        });
        $filterBuilder->method('create')->willReturn($this->createStub(Filter::class));
        $filterFactory = $this->createStub(FilterBuilderFactory::class);
        $filterFactory->method('create')->willReturn($filterBuilder);

        $result = $this->createStub(SearchResultInterface::class);
        $result->method('getItems')->willReturn($docs);
        $search = $this->createStub(SearchInterface::class);
        $search->method('search')->willReturnCallback(function () use ($result) {
            $this->searches++;
            $this->restrictionDuringSearch = $this->active->getAllowedIds();
            return $result;
        });

        $visibility = $this->createStub(Visibility::class);
        $visibility->method('getVisibleInCatalogIds')->willReturn([2, 4]);
        $visibility->method('getVisibleInSearchIds')->willReturn([3, 4]);

        $query = $this->createStub(Query::class);
        $query->method('getQueryText')->willReturn($params['q'] ?? null);
        $query->method('isQueryTextShort')->willReturn($short);
        $queryFactory = $this->createStub(QueryFactory::class);
        $queryFactory->method('get')->willReturn($query);

        return new LayerProductIds(
            $search,
            $criteriaFactory,
            $filterFactory,
            $visibility,
            $this->request($params),
            $this->eav(),
            $queryFactory,
            $this->active
        );
    }

    private function categoryLayer(int $id = 18, string $defaultSort = 'position'): Layer
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getDefaultSortBy')->willReturn($defaultSort);
        $layer = $this->createStub(Layer::class);
        $layer->method('getCurrentCategory')->willReturn($category);
        return $layer;
    }

    public function testCategoryRequestUsesCatalogContainerAndCategoryFilter(): void
    {
        $ids = $this->service([], [$this->doc(5), $this->doc('7'), $this->doc(5), $this->doc(0)])
            ->getIds($this->categoryLayer());

        $this->assertSame([5, 7], $ids);
        $this->assertSame(['visibility' => [2, 4], 'category_ids' => 18], $this->filters);
        $this->assertSame('catalog_view_container', $this->criteriaCalls['setRequestName']);
        $this->assertSame(10000, $this->criteriaCalls['setPageSize']);
        $this->assertSame(0, $this->criteriaCalls['setCurrentPage']);
    }

    public function testPriceFilterUsesIndexedPriceRangeLikeCore(): void
    {
        $this->service(['price' => '30-40', 'sale_filter' => '1'])->getIds($this->categoryLayer());

        $this->assertSame(30.0, $this->filters['price.from']);
        $this->assertEqualsWithDelta(39.999, $this->filters['price.to'], 0.0000001);
        $this->assertArrayNotHasKey('price', $this->filters);
        $this->assertArrayNotHasKey('sale_filter', $this->filters);
    }

    public function testOpenEndedAndEqualPriceBounds(): void
    {
        $this->service(['price' => '100-'])->getIds($this->categoryLayer());
        $this->assertSame(100.0, $this->filters['price.from']);
        $this->assertArrayNotHasKey('price.to', $this->filters);

        $this->filters = [];
        $this->service(['price' => '-20,30-40'])->getIds($this->categoryLayer());
        $this->assertArrayNotHasKey('price.from', $this->filters);
        $this->assertEqualsWithDelta(19.999, $this->filters['price.to'], 0.0000001);

        $this->filters = [];
        $this->service(['price' => '25-25'])->getIds($this->categoryLayer());
        $this->assertSame(25.0, $this->filters['price.from']);
        $this->assertSame(25.0, $this->filters['price.to']);
    }

    public function testInvalidPriceIsIgnored(): void
    {
        foreach (['abc', '10-x', '-5--1', '1-2-3', ''] as $price) {
            $this->filters = [];
            $this->service(['price' => $price])->getIds($this->categoryLayer());
            $this->assertArrayNotHasKey('price.from', $this->filters, $price);
            $this->assertArrayNotHasKey('price.to', $this->filters, $price);
        }
        $this->service(['price' => ['10-20']])->getIds($this->categoryLayer());
        $this->assertArrayNotHasKey('price.from', $this->filters);
    }

    public function testAttributeFiltersAreAddedToTheSameRequest(): void
    {
        $this->service([
            'color' => '49',
            'material' => ['33', '', ['x']],
            'weight_range' => '1-5',
            'description' => 'cotton',
            'broken' => '1',
            'unknown' => '3',
            'p' => '2',
            'product_list_limit' => '24',
        ])->getIds($this->categoryLayer());

        $this->assertSame(49, $this->filters['color']);
        $this->assertSame(['33'], $this->filters['material']);
        $this->assertSame(1.0, $this->filters['weight_range.from']);
        $this->assertSame(5.0, $this->filters['weight_range.to']);
        foreach (['description', 'broken', 'unknown', 'p', 'product_list_limit'] as $skipped) {
            $this->assertArrayNotHasKey($skipped, $this->filters);
        }
    }

    public function testUnmatchedAttributeValueIsStillApplied(): void
    {
        $ids = $this->service(['color' => '59'], [])->getIds($this->categoryLayer());

        $this->assertSame([], $ids);
        $this->assertSame(59, $this->filters['color']);
    }

    public function testCatParamOverridesCurrentCategory(): void
    {
        $this->service(['cat' => '21'])->getIds($this->categoryLayer());
        $this->assertSame(21, $this->filters['category_ids']);

        $this->filters = [];
        $this->service(['cat' => '2x'])->getIds($this->categoryLayer());
        $this->assertSame(18, $this->filters['category_ids']);
    }

    public function testLayerWithoutCategoryHasNoCategoryFilter(): void
    {
        $this->service([])->getIds($this->createStub(Layer::class));

        $this->assertArrayNotHasKey('category_ids', $this->filters);
        $this->assertSame([2, 4], $this->filters['visibility']);
    }

    public function testSearchLayerUsesQuickSearchContainer(): void
    {
        $ids = $this->service(['q' => ' tee ', 'material' => '33', 'color' => '49', 'cat' => '12'], [$this->doc(9)])
            ->getIds($this->createStub(SearchLayer::class));

        $this->assertSame([9], $ids);
        $this->assertSame('quick_search_container', $this->criteriaCalls['setRequestName']);
        $this->assertSame('tee', $this->filters['search_term']);
        $this->assertSame([3, 4], $this->filters['visibility']);
        $this->assertSame(12, $this->filters['category_ids']);
        $this->assertSame(49, $this->filters['color']);
        $this->assertArrayNotHasKey('material', $this->filters);
        $this->assertSame(['relevance' => 'DESC', 'entity_id' => 'ASC'], $this->criteriaCalls['setSortOrders']);
    }

    public function testBlankSearchReturnsEmptyWithoutSearching(): void
    {
        $this->assertSame([], $this->service(['q' => '  '])->getIds($this->createStub(SearchLayer::class)));
        $this->assertSame([], $this->service(['q' => ['x']])->getIds($this->createStub(SearchLayer::class)));
        $this->assertSame(0, $this->searches);
    }

    public function testShortSearchQueryListsNothingLikeCore(): void
    {
        $ids = $this->service(['q' => 'a'], [$this->doc(4)], true)->getIds($this->createStub(SearchLayer::class));

        $this->assertSame([], $ids);
        $this->assertSame(0, $this->searches);
    }

    public function testPositionSortHasStableEntityIdTieBreak(): void
    {
        $this->service([])->getIds($this->categoryLayer());

        $this->assertSame(['position' => 'ASC', 'entity_id' => 'ASC'], $this->criteriaCalls['setSortOrders']);
    }

    public function testToolbarSortAndDirectionAreHonoured(): void
    {
        $this->service(['product_list_order' => 'price', 'product_list_dir' => 'desc'])->getIds($this->categoryLayer());
        $this->assertSame(['price' => 'DESC', 'entity_id' => 'ASC'], $this->criteriaCalls['setSortOrders']);

        $this->service(['product_list_order' => 'name', 'product_list_dir' => 'sideways'])->getIds($this->categoryLayer());
        $this->assertSame(['name' => 'ASC', 'entity_id' => 'ASC'], $this->criteriaCalls['setSortOrders']);
    }

    public function testUnsortableOrderFallsBackToCategoryDefault(): void
    {
        $this->service(['product_list_order' => 'description'])->getIds($this->categoryLayer(18, 'name'));
        $this->assertSame(['name' => 'ASC', 'entity_id' => 'ASC'], $this->criteriaCalls['setSortOrders']);

        $this->service(['product_list_order' => 'relevance'])->getIds($this->categoryLayer(18, 'entity_id'));
        $this->assertSame(['position' => 'ASC', 'entity_id' => 'ASC'], $this->criteriaCalls['setSortOrders']);

        $before = $this->searches;
        $this->service(['product_list_order' => 'position'])->getIds($this->createStub(SearchLayer::class));
        $this->assertSame($before, $this->searches);
    }

    public function testSearchPositionOrderFallsBackToRelevance(): void
    {
        $this->service(['q' => 'bag', 'product_list_order' => 'position', 'product_list_dir' => 'asc'])
            ->getIds($this->createStub(SearchLayer::class));

        $this->assertSame(['relevance' => 'ASC', 'entity_id' => 'ASC'], $this->criteriaCalls['setSortOrders']);
    }

    public function testResultsAreCachedForTheSameRequest(): void
    {
        $service = $this->service(['color' => '49'], [$this->doc(11)]);
        $layer = $this->categoryLayer();

        $this->assertSame([11], $service->getIds($layer));
        $this->assertSame([11], $service->getIds($layer));
        $this->assertSame(1, $this->searches);
    }
}
