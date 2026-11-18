<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use MageSentinel\Monitor\Model\Payment\TestMethod;
use Magento\Framework\FlagManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Orders placed with the hidden test method: the list of those still open,
 * the locks that must all open before one is cancelled, and the cancel itself.
 * Nothing here ever deletes an order.
 */
class TestOrders
{
    public const FLAG = 'magesentinel_test_orders';
    public const MARK = 'magesentinel_mark';
    public const SYNTHETIC_EMAIL = '/^magesentinel[+.][a-z0-9]+@/i';
    public const REST_MAX_AGE = 3600;
    public const SWEEP_MIN_AGE = 1800;
    public const SWEEP_MAX_AGE = 604800;
    private const SAFE_STATES = ['new', 'pending_payment', 'holded', 'canceled'];

    /** @var FlagManager */
    private $flags;
    /** @var OrderRepositoryInterface */
    private $orders;
    /** @var OrderManagementInterface */
    private $management;
    /** @var LoggerInterface */
    private $logger;

    /**
     * Constructor.
     *
     * @param FlagManager $flags
     * @param OrderRepositoryInterface $orders
     * @param OrderManagementInterface $management
     * @param LoggerInterface $logger
     */
    public function __construct(
        FlagManager $flags,
        OrderRepositoryInterface $orders,
        OrderManagementInterface $management,
        LoggerInterface $logger
    ) {
        $this->flags = $flags;
        $this->orders = $orders;
        $this->management = $management;
        $this->logger = $logger;
    }

    /**
     * Open test orders: entity id => [created timestamp, sha256 of the order's mark].
     *
     * @return array<int,array{0:int,1:string}>
     */
    public function open(): array
    {
        $list = $this->flags->getFlagData(self::FLAG);
        return is_array($list) ? $list : [];
    }

    /**
     * Mark a just-placed order as ours and remember it.
     *
     * @param OrderInterface $order
     * @return void
     */
    public function remember(OrderInterface $order): void
    {
        $mark = bin2hex(random_bytes(16));
        $payment = $order->getPayment();
        $payment->setAdditionalInformation(self::MARK, $mark);
        $this->orders->save($order);
        $list = $this->open();
        $list[(int)$order->getEntityId()] = [time(), hash('sha256', $mark)];
        $this->flags->saveFlag(self::FLAG, $list);
    }

    /**
     * Why this order may not be cancelled, or null when every lock opens.
     *
     * @param OrderInterface $order
     * @param int $maxAge
     * @return string|null
     */
    public function refusal(OrderInterface $order, int $maxAge): ?string
    {
        $id = (int)$order->getEntityId();
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== TestMethod::CODE) {
            return 'payment_method';
        }
        $open = $this->open();
        if (!isset($open[$id])) {
            return 'not_ours';
        }
        $mark = (string)$payment->getAdditionalInformation(self::MARK);
        if ($mark === '' || !hash_equals((string)$open[$id][1], hash('sha256', $mark))) {
            return 'mark';
        }
        if (preg_match(self::SYNTHETIC_EMAIL, (string)$order->getCustomerEmail()) !== 1) {
            return 'email';
        }
        if ((float)$order->getTotalPaid() > 0 || (float)$order->getTotalInvoiced() > 0) {
            return 'paid';
        }
        if ((float)$order->getTotalRefunded() > 0) {
            return 'refunds';
        }
        if (method_exists($order, 'hasShipments') && $order->hasShipments()) {
            return 'shipped';
        }
        if (!in_array((string)$order->getState(), self::SAFE_STATES, true)) {
            return 'state';
        }
        $created = strtotime((string)$order->getCreatedAt() . ' UTC');
        if ($created === false || time() - $created > $maxAge) {
            return 'age';
        }
        return null;
    }

    /**
     * Cancel (Magento returns the stock on cancel) and stop tracking it. Never deletes: order numbers stay
     * whole and anything that already saw the order (ERP, invoicing, myDATA) sees an ordinary cancelled order.
     * Only when every lock in refusal() opens.
     *
     * @param OrderInterface $order
     * @param int $maxAge
     * @return bool
     */
    public function cancel(OrderInterface $order, int $maxAge = self::REST_MAX_AGE): bool
    {
        $id = (int)$order->getEntityId();
        $why = $this->refusal($order, $maxAge);
        if ($why !== null) {
            $this->logger->info(sprintf('MageSentinel refused to cancel order %s: %s', $order->getIncrementId(), $why));
            return false;
        }
        if ($order->getState() !== 'canceled') {
            $this->management->cancel($id);
        }
        $this->forget($id);
        $this->logger->info(sprintf('MageSentinel cancelled test order %s', $order->getIncrementId()));
        return true;
    }

    /**
     * Safety net: cancel test orders the check did not, after 30 minutes.
     *
     * @return int
     */
    public function sweep(): int
    {
        $n = 0;
        foreach ($this->open() as $id => [$ts]) {
            if (time() - (int)$ts < self::SWEEP_MIN_AGE) {
                continue;
            }
            try {
                $order = $this->orders->get((int)$id);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->forget((int)$id);
                continue;
            }
            if ($this->cancel($order, self::SWEEP_MAX_AGE)) {
                $n++;
            } else {
                $this->forget((int)$id); // refused by a lock: stop tracking it, never touch it
            }
        }
        return $n;
    }

    /**
     * Drop an id from the open list.
     *
     * @param int $id
     * @return void
     */
    private function forget(int $id): void
    {
        $list = $this->open();
        unset($list[$id]);
        $this->flags->saveFlag(self::FLAG, $list);
    }
}
