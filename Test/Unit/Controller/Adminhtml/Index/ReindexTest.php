<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Controller\Adminhtml\Index;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Message\ManagerInterface;
use Panth\SaleFilter\Controller\Adminhtml\Index\Reindex;
use PHPUnit\Framework\TestCase;

class ReindexTest extends TestCase
{
    private function controller(IndexerRegistry $registry, ManagerInterface $messages, Redirect $redirect): Reindex
    {
        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturnMap([[ResultFactory::TYPE_REDIRECT, [], $redirect]]);
        $context = $this->createStub(Context::class);
        $context->method('getResultFactory')->willReturn($resultFactory);
        $context->method('getMessageManager')->willReturn($messages);

        return new Reindex($context, $registry);
    }

    private function redirect(): Redirect
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())->method('setPath')->with('panth_salefilter/index/index')->willReturnSelf();
        return $redirect;
    }

    public function testSuccessfulReindexAddsSuccessMessage(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($this->once())->method('reindexAll');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->once())->method('get')->with('panth_salefilter_product')->willReturn($indexer);
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addSuccessMessage')
            ->with($this->stringContains('Sale Filter index rebuilt in'));
        $messages->expects($this->never())->method('addErrorMessage');
        $redirect = $this->redirect();

        $this->assertSame($redirect, $this->controller($registry, $messages, $redirect)->execute());
    }

    public function testFailureAddsErrorMessageAndStillRedirects(): void
    {
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('reindexAll')->willThrowException(new \RuntimeException('table locked'));
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->never())->method('addSuccessMessage');
        $messages->expects($this->once())->method('addErrorMessage')
            ->with('Sale Filter reindex failed: table locked');
        $redirect = $this->redirect();

        $this->assertSame($redirect, $this->controller($registry, $messages, $redirect)->execute());
    }
}
