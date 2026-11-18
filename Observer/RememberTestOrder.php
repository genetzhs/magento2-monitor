<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Observer;

use MageSentinel\Monitor\Helper\Access;
use MageSentinel\Monitor\Model\Payment\TestMethod;
use MageSentinel\Monitor\Model\TestOrders;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * sales_model_service_quote_submit_success: mark the order placed with the hidden method and remember it.
 */
class RememberTestOrder implements ObserverInterface
{
    /** @var TestOrders */
    private $testOrders;
    /** @var Access */
    private $access;

    /**
     * Constructor.
     *
     * @param TestOrders $testOrders
     * @param Access $access
     */
    public function __construct(TestOrders $testOrders, Access $access)
    {
        $this->testOrders = $testOrders;
        $this->access = $access;
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getPayment() || $order->getPayment()->getMethod() !== TestMethod::CODE) {
            return;
        }
        if (!$this->access->isTestGatewayRequest()) {
            return; // cannot happen through the checkout; never mark what we did not place
        }
        $this->testOrders->remember($order);
    }
}
