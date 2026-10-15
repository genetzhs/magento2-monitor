<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Controller\Adminhtml\Health;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageSentinel_Monitor::health';

    /** @var PageFactory */
    private $pageFactory;

    public function __construct(Action\Context $context, PageFactory $pageFactory)
    {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('MageSentinel_Monitor::health');
        $page->getConfig()->getTitle()->prepend(__('Store health'));
        return $page;
    }
}
