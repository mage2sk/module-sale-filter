<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Admin button target: rebuild the sale-filter index on demand.
 *
 * GET /admin/panth_salefilter/index/reindex → redirects back to the grid.
 * Gated by ACL resource Panth_SaleFilter::index_reindex.
 */
class Reindex extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_SaleFilter::index_reindex';

    private const INDEXER_ID = 'panth_salefilter_product';

    public function __construct(
        Context $context,
        private readonly IndexerRegistry $indexerRegistry
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('panth_salefilter/index/index');

        try {
            $indexer = $this->indexerRegistry->get(self::INDEXER_ID);
            $start = microtime(true);
            $indexer->reindexAll();
            $this->messageManager->addSuccessMessage(
                (string) __('Sale Filter index rebuilt in %1 seconds.', number_format(microtime(true) - $start, 2))
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                (string) __('Sale Filter reindex failed: %1', $e->getMessage())
            );
        }

        return $redirect;
    }
}
