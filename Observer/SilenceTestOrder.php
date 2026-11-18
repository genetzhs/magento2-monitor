<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Observer;

use MageSentinel\Monitor\Model\Payment\TestMethod;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * sales_model_service_quote_submit_before: a test order sends no order email.
 */
class SilenceTestOrder implements ObserverInterface
{
    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if ($order && $order->getPayment() && $order->getPayment()->getMethod() === TestMethod::CODE) {
            $order->setCanSendNewEmailFlag(false);
            $order->setSendEmail(false);
        }
    }
}
