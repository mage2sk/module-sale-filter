<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model\Source;

use Magento\Catalog\Model\Product\Type as CatalogProductType;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupSearchResultsInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Panth\SaleFilter\Model\Source\CatalogRule;
use Panth\SaleFilter\Model\Source\CustomerGroup;
use Panth\SaleFilter\Model\Source\CustomerGroupCode;
use Panth\SaleFilter\Model\Source\MatchSource;
use Panth\SaleFilter\Model\Source\ProductType;
use Panth\SaleFilter\Model\Source\Website;
use Panth\SaleFilter\Model\Source\WebsiteCode;
use PHPUnit\Framework\TestCase;

class SourceModelsTest extends TestCase
{
    private function groupRepository(array $groups): array
    {
        $items = [];
        foreach ($groups as $id => $code) {
            $group = $this->createStub(GroupInterface::class);
            $group->method('getId')->willReturn($id);
            $group->method('getCode')->willReturn($code);
            $items[] = $group;
        }
        $results = $this->createStub(GroupSearchResultsInterface::class);
        $results->method('getItems')->willReturn($items);
        $repo = $this->createStub(GroupRepositoryInterface::class);
        $repo->method('getList')->willReturn($results);
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        return [$repo, $builder];
    }

    private function websiteRepository(array $websites): WebsiteRepositoryInterface
    {
        $items = [];
        foreach ($websites as $id => [$code, $name]) {
            $website = $this->createStub(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $website->method('getCode')->willReturn($code);
            $website->method('getName')->willReturn($name);
            $items[] = $website;
        }
        $repo = $this->createStub(WebsiteRepositoryInterface::class);
        $repo->method('getList')->willReturn($items);
        return $repo;
    }

    public function testCustomerGroupLabelsIncludeId(): void
    {
        [$repo, $builder] = $this->groupRepository([0 => 'NOT LOGGED IN', 1 => 'General']);

        $this->assertSame([
            ['value' => 0, 'label' => 'NOT LOGGED IN (#0)'],
            ['value' => 1, 'label' => 'General (#1)'],
        ], (new CustomerGroup($repo, $builder))->toOptionArray());
    }

    public function testCustomerGroupCodeUsesCodeForValueAndLabel(): void
    {
        [$repo, $builder] = $this->groupRepository([2 => 'Wholesale']);

        $this->assertSame(
            [['value' => 'Wholesale', 'label' => 'Wholesale']],
            (new CustomerGroupCode($repo, $builder))->toOptionArray()
        );
    }

    public function testWebsiteSourceSkipsAdminWebsite(): void
    {
        $repo = $this->websiteRepository([0 => ['admin', 'Admin'], 1 => ['base', 'Main Website']]);

        $this->assertSame(
            [['value' => 1, 'label' => 'Main Website (#1)']],
            (new Website($repo))->toOptionArray()
        );
    }

    public function testWebsiteCodeSourceSkipsAdminWebsite(): void
    {
        $repo = $this->websiteRepository([0 => ['admin', 'Admin'], 1 => ['base', 'Main'], 2 => ['eu', 'EU']]);

        $this->assertSame([
            ['value' => 'base', 'label' => 'base'],
            ['value' => 'eu', 'label' => 'eu'],
        ], (new WebsiteCode($repo))->toOptionArray());
    }

    public function testMatchSourceListsAllConstants(): void
    {
        $values = array_column((new MatchSource())->toOptionArray(), 'value');

        $this->assertSame([
            MatchSource::SOURCE_SPECIAL,
            MatchSource::SOURCE_CATALOG_RULE,
            MatchSource::SOURCE_BOTH,
            MatchSource::SOURCE_PARENT,
        ], $values);
    }

    public function testProductTypeConvertsMapToOptions(): void
    {
        $type = $this->createStub(CatalogProductType::class);
        $type->method('getOptionArray')->willReturn(['simple' => 'Simple Product', 'configurable' => 'Configurable']);

        $this->assertSame([
            ['value' => 'simple', 'label' => 'Simple Product'],
            ['value' => 'configurable', 'label' => 'Configurable'],
        ], (new ProductType($type))->toOptionArray());
    }

    public function testCatalogRuleDropsEmptyAndDuplicateNames(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn(['Black Friday', '', 'Black Friday', 'Summer', null]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame([
            ['value' => 'Black Friday', 'label' => 'Black Friday'],
            ['value' => 'Summer', 'label' => 'Summer'],
        ], (new CatalogRule($resource))->toOptionArray());
    }
}
