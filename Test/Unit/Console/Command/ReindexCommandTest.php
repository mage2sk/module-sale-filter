<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Panth\SaleFilter\Console\Command\ReindexCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReindexCommandTest extends TestCase
{
    private function tester(IndexerRegistry $registry): CommandTester
    {
        $state = $this->createStub(State::class);
        $state->method('setAreaCode')->willThrowException(new LocalizedException(__('Area code is already set')));

        return new CommandTester(new ReindexCommand($state, $registry));
    }

    public function testConfiguresNameAndForceOption(): void
    {
        $command = new ReindexCommand($this->createStub(State::class), $this->createStub(IndexerRegistry::class));

        $this->assertSame('panth:salefilter:reindex', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('force'));
        $this->assertSame('f', $command->getDefinition()->getOption('force')->getShortcut());
    }

    public function testSuccessfulReindex(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('reindexAll');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->once())->method('get')->with('panth_salefilter_product')->willReturn($indexer);

        $tester = $this->tester($registry);
        $code = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Reindexing Panth Sale Filter', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/Reindex complete in \d+\.\d{2}s\./', $tester->getDisplay());
        $this->assertStringNotContainsString('invalidated', $tester->getDisplay());
    }

    public function testForceInvalidatesTheIndexerBeforeReindexing(): void
    {
        $calls = [];
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('invalidate')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'invalidate';
        });
        $indexer->expects($this->once())->method('reindexAll')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'reindexAll';
        });
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);

        $tester = $this->tester($registry);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        $this->assertSame(['invalidate', 'reindexAll'], $calls);
        $this->assertStringContainsString('--force: indexer invalidated.', $tester->getDisplay());
    }

    public function testMissingIndexerFails(): void
    {
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willThrowException(new \InvalidArgumentException('unknown'));

        $tester = $this->tester($registry);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('not found: unknown', $tester->getDisplay());
    }

    public function testReindexErrorFails(): void
    {
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('reindexAll')->willThrowException(new \RuntimeException('locked'));
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);

        $tester = $this->tester($registry);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Reindex failed: locked', $tester->getDisplay());
    }
}
