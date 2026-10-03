<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Console\Command;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupSearchResultsInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Panth\SaleFilter\Console\Command\StatusCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class StatusCommandTest extends TestCase
{
    private function command(array $websites, array $groups, ?ResourceConnection $resource = null): StatusCommand
    {
        $websiteItems = [];
        foreach ($websites as $id => $name) {
            $w = $this->createStub(WebsiteInterface::class);
            $w->method('getId')->willReturn($id);
            $w->method('getName')->willReturn($name);
            $websiteItems[] = $w;
        }
        $websiteRepo = $this->createStub(WebsiteRepositoryInterface::class);
        $websiteRepo->method('getList')->willReturn($websiteItems);

        $groupItems = [];
        foreach ($groups as $id => $code) {
            $g = $this->createStub(GroupInterface::class);
            $g->method('getId')->willReturn($id);
            $g->method('getCode')->willReturn($code);
            $groupItems[] = $g;
        }
        $results = $this->createStub(GroupSearchResultsInterface::class);
        $results->method('getItems')->willReturn($groupItems);
        $groupRepo = $this->createStub(GroupRepositoryInterface::class);
        $groupRepo->method('getList')->willReturn($results);
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        return new StatusCommand(
            $this->createStub(State::class),
            $websiteRepo,
            $groupRepo,
            $resource ?? $this->resource([]),
            $builder
        );
    }

    private function resource(array $counts): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(...($counts ?: ['0']));
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    public function testRendersRowPerWebsiteAndGroupSkippingAdmin(): void
    {
        $tester = new CommandTester($this->command(
            [0 => 'Admin', 1 => 'Main'],
            [0 => 'NOT LOGGED IN', 1 => 'General'],
            $this->resource(['12', '3'])
        ));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('On Sale Count', $display);
        $this->assertMatchesRegularExpression('/Main \(#1\)\s*\|\s*NOT LOGGED IN \(#0\)\s*\|\s*12/', $display);
        $this->assertMatchesRegularExpression('/General \(#1\)\s*\|\s*3/', $display);
        $this->assertStringNotContainsString('Admin', $display);
    }

    public function testNoScopesPrintsComment(): void
    {
        $tester = new CommandTester($this->command([0 => 'Admin'], [1 => 'General']));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No websites or customer groups found', $tester->getDisplay());
    }

    public function testDatabaseErrorFails(): void
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new \RuntimeException('no db'));
        $tester = new CommandTester($this->command([1 => 'Main'], [1 => 'General'], $resource));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Failed to read status: no db', $tester->getDisplay());
    }
}
