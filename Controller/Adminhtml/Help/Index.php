<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Controller\Adminhtml\Help;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the module's "How It Works" admin documentation page.
 *
 * ACL-gated on {@code Panth_SaleFilter::help} so only roles that have been
 * granted the permission (by default: System > Permissions > User Roles)
 * can read it. Any other admin URL pattern would be blocked by Magento's
 * backend cookie + form-key machinery anyway, but the ACL check ensures
 * lower-privilege admins don't see internals the store owner might want
 * kept quiet.
 */
class Index extends Action
{
    /**
     * @see \Panth\SaleFilter\etc\acl.xml
     */
    public const ADMIN_RESOURCE = 'Panth_SaleFilter::help';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @return Page
     */
    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Panth_SaleFilter::help');
        $page->getConfig()->getTitle()->prepend((string) __('Sale Filter — How It Works'));

        return $page;
    }
}
