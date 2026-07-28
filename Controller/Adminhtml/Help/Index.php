<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Controller\Adminhtml\Help;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Panth_SaleFilter::help';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Panth_SaleFilter::help');
        $page->getConfig()->getTitle()->prepend((string) __('Sale Filter - How It Works'));

        return $page;
    }
}
