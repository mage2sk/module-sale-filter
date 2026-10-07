<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Plugin\Elasticsearch\SearchAdapter;

use Magento\Elasticsearch\ElasticAdapter\SearchAdapter\Mapper;
use Magento\Framework\Search\RequestInterface;
use Panth\SaleFilter\Model\ActiveSaleFilter;
use Panth\SaleFilter\Plugin\Elasticsearch\SearchAdapter\MapperPlugin;
use PHPUnit\Framework\TestCase;

class MapperPluginTest extends TestCase
{
    private ActiveSaleFilter $active;

    protected function setUp(): void
    {
        $this->active = new ActiveSaleFilter();
    }

    private function apply(array $query, string $name = 'catalog_view_container'): mixed
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getName')->willReturn($name);

        return (new MapperPlugin($this->active))
            ->afterBuildQuery($this->createStub(Mapper::class), $query, $request);
    }

    private function boolQuery(): array
    {
        return ['index' => 'x', 'body' => ['query' => ['bool' => ['must' => [['term' => ['a' => 1]]]]], 'aggregations' => []]];
    }

    public function testQueryIsUntouchedWithoutActiveFilter(): void
    {
        $this->assertSame($this->boolQuery(), $this->apply($this->boolQuery()));
    }

    public function testOtherRequestsAreUntouched(): void
    {
        $this->active->setAllowedIds([5]);

        $this->assertSame($this->boolQuery(), $this->apply($this->boolQuery(), 'advanced_search_container'));
    }

    public function testIdsFilterIsAddedToCatalogAndSearchRequests(): void
    {
        $this->active->setAllowedIds([5, '9']);

        foreach (['catalog_view_container', 'quick_search_container'] as $name) {
            $result = $this->apply($this->boolQuery(), $name);
            $this->assertSame([['ids' => ['values' => ['5', '9']]]], $result['body']['query']['bool']['filter']);
            $this->assertSame([['term' => ['a' => 1]]], $result['body']['query']['bool']['must']);
            $this->assertSame([], $result['body']['aggregations']);
        }
    }

    public function testEmptyListMatchesNothing(): void
    {
        $this->active->setAllowedIds([]);

        $result = $this->apply($this->boolQuery());

        $this->assertSame([['ids' => ['values' => ['0']]]], $result['body']['query']['bool']['filter']);
    }

    public function testExistingFiltersAreKept(): void
    {
        $this->active->setAllowedIds([1]);
        $list = $this->boolQuery();
        $list['body']['query']['bool']['filter'] = [['term' => ['b' => 2]]];
        $single = $this->boolQuery();
        $single['body']['query']['bool']['filter'] = ['term' => ['b' => 2]];

        $expected = [['term' => ['b' => 2]], ['ids' => ['values' => ['1']]]];
        $this->assertSame($expected, $this->apply($list)['body']['query']['bool']['filter']);
        $this->assertSame($expected, $this->apply($single)['body']['query']['bool']['filter']);
    }

    public function testNonBoolQueryIsWrapped(): void
    {
        $this->active->setAllowedIds([2]);

        $wrapped = $this->apply(['body' => ['query' => ['match_all' => []]]]);
        $this->assertSame(
            ['bool' => ['filter' => [['ids' => ['values' => ['2']]]], 'must' => [['match_all' => []]]]],
            $wrapped['body']['query']
        );

        $bare = $this->apply(['body' => []]);
        $this->assertSame(['bool' => ['filter' => [['ids' => ['values' => ['2']]]]]], $bare['body']['query']);
    }

    public function testBypassSkipsTheRestriction(): void
    {
        $this->active->setAllowedIds([2]);

        $result = $this->active->runWithoutRestriction(fn () => $this->apply($this->boolQuery()));

        $this->assertSame($this->boolQuery(), $result);
        $this->assertSame([2], $this->active->getAllowedIds());
    }

    public function testBypassIsReleasedAfterException(): void
    {
        $this->active->setAllowedIds([4]);
        try {
            $this->active->runWithoutRestriction(static function () {
                throw new \RuntimeException('x');
            });
        } catch (\RuntimeException) {
            $this->assertSame([4], $this->active->getAllowedIds());
            return;
        }
        $this->fail('exception expected');
    }
}
