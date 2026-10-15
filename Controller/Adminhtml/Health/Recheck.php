<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Controller\Adminhtml\Health;

use MageSentinel\Monitor\Model\Alerts;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;

/** "Check again": runs the 15-minute check now (and emails if something turned red). */
class Recheck extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageSentinel_Monitor::health';

    /** @var Alerts */
    private $alerts;

    public function __construct(Action\Context $context, Alerts $alerts)
    {
        parent::__construct($context);
        $this->alerts = $alerts;
    }

    public function execute()
    {
        $this->alerts->run();
        $this->messageManager->addSuccessMessage(__('Checked just now.'));
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
