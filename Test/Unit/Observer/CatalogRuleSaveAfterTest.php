<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Panth\SaleFilter\Observer\CatalogRuleSaveAfter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatalogRuleSaveAfterTest extends TestCase
{
    private function observer(string $eventName, ?int $productId = null): Observer
    {
        $data = ['name' => $eventName];
        if ($productId !== null) {
            $data['product'] = new DataObject(['id' => $productId]);
        }
        return new Observer(['event' => new Event($data)]);
    }

    public function testScheduledIndexerIsLeftToMview(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(true);
        $indexer->expects($this->never())->method('reindexAll');
        $indexer->expects($this->never())->method('reindexRow');
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')
            ->with($this->anything(), $this->callback(static fn($ctx) => $ctx['mode'] === 'schedule'));

        (new CatalogRuleSaveAfter($registry, $logger))->execute($this->observer('catalogrule_rule_save_commit_after'));
    }

    public function testProductSaveReindexesSingleRow(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(false);
        $indexer->expects($this->once())->method('reindexRow')->with(42);
        $indexer->expects($this->never())->method('reindexAll');
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);

        (new CatalogRuleSaveAfter($registry, $this->createStub(LoggerInterface::class)))
            ->execute($this->observer('catalog_product_save_after', 42));
    }

    public function testProductEventWithoutProductDoesNothing(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(false);
        $indexer->expects($this->never())->method('reindexRow');
        $indexer->expects($this->never())->method('reindexAll');
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);

        (new CatalogRuleSaveAfter($registry, $this->createStub(LoggerInterface::class)))
            ->execute($this->observer('catalog_product_delete_after'));
    }

    public function testRuleEventForcesCatalogRuleRebuildThenFullReindex(): void
    {
        $self = $this->createMock(IndexerInterface::class);
        $self->method('isScheduled')->willReturn(false);
        $self->expects($this->once())->method('reindexAll');
        $rule = $this->createMock(IndexerInterface::class);
        $rule->expects($this->once())->method('reindexAll');
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturnMap([
            ['panth_salefilter_product', $self],
            ['catalogrule_rule', $rule],
        ]);

        (new CatalogRuleSaveAfter($registry, $this->createStub(LoggerInterface::class)))
            ->execute($this->observer('catalogrule_rule_delete_commit_after'));
    }

    public function testOtherEventsOnlyRunFullReindex(): void
    {
        $self = $this->createMock(IndexerInterface::class);
        $self->method('isScheduled')->willReturn(false);
        $self->expects($this->once())->method('reindexAll');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->once())->method('get')->with('panth_salefilter_product')->willReturn($self);

        (new CatalogRuleSaveAfter($registry, $this->createStub(LoggerInterface::class)))
            ->execute($this->observer('clean_catalog_images_cache_after'));
    }

    public function testCatalogRuleRebuildFailureStillRunsOwnReindex(): void
    {
        $self = $this->createMock(IndexerInterface::class);
        $self->method('isScheduled')->willReturn(false);
        $self->expects($this->once())->method('reindexAll');
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturnCallback(static function ($id) use ($self) {
            if ($id === 'catalogrule_rule') {
                throw new \RuntimeException('missing');
            }
            return $self;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('missing'));

        (new CatalogRuleSaveAfter($registry, $logger))->execute($this->observer('catalogrule_rule_save_commit_after'));
    }

    public function testRegistryFailureIsLogged(): void
    {
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willThrowException(new \RuntimeException('nope'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->anything(), $this->callback(
                static fn($ctx) => $ctx['error'] === 'nope' && $ctx['trigger'] === 'unknown'
            ));

        (new CatalogRuleSaveAfter($registry, $logger))->execute($this->observer(''));
    }
}
