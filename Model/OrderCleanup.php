<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use MageSentinel\Monitor\Api\OrderCleanupInterface;
use MageSentinel\Monitor\Helper\Access;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Cancels the synthetic orders MageSentinel's checks create.
 *
 * A monitor that places real orders to prove checkout works leaves real orders
 * behind — one every check interval, forever, in the merchant's order list.
 * This lets the check clean up after itself.
 *
 * This is the module's only write operation. Everything else it exposes is
 * read-only, which is what makes it safe to install, so the bar here is
 * deliberately high: a second admin switch that is off by default, and every
 * restriction checked against the loaded order rather than taken on trust from
 * the caller.
 */
class OrderCleanup implements OrderCleanupInterface
{
    /**
     * Mirrors isMageSentinelIdentity() in src/monitoring/identity/guest.ts. Both
     * sides have to agree on what a synthetic address looks like; if they ever
     * drift, this side is the one that matters — it is the one protecting the
     * merchant's real orders.
     */
    private const SYNTHETIC_EMAIL = '/^magesentinel[+.][a-z0-9]+@/i';

    /** An order older than this is not ours to touch, whatever it is called. */
    private const MAX_AGE_SECONDS = 3600;

    /** @var Access */
    private $access;
    /** @var OrderRepositoryInterface */
    private $orderRepository;
    /** @var OrderManagementInterface */
    private $orderManagement;
    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Access $access,
        OrderRepositoryInterface $orderRepository,
        OrderManagementInterface $orderManagement,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger
    ) {
        $this->access = $access;
        $this->orderRepository = $orderRepository;
        $this->orderManagement = $orderManagement;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    /**
     * @param string $incrementId
     * @return mixed[]
     */
    public function cancel(string $incrementId): array
    {
        $this->access->assertAllowed();
        $this->access->assertCancelAllowed();

        $order = $this->findOrder($incrementId);
        if ($order === null) {
            // Deliberately the same answer as "exists but is not yours": the
            // endpoint must not become a way to discover which order numbers
            // are real.
            throw new LocalizedException(new Phrase('No cancellable MageSentinel order with that number.'));
        }

        $this->assertIsOurs($order);
        $this->assertUntouched($order);

        if (!$order->canCancel()) {
            throw new LocalizedException(
                new Phrase('Magento refused to cancel this order (state "%1").', [$order->getState()])
            );
        }

        $this->orderManagement->cancel((int)$order->getEntityId());

        $this->logger->info(sprintf(
            'MageSentinel cancelled synthetic order %s (%s)',
            $incrementId,
            (string)$order->getCustomerEmail()
        ));

        $cancelled = $this->orderRepository->get((int)$order->getEntityId());

        return [
            'increment_id' => $incrementId,
            'cancelled' => $cancelled->getState() === \Magento\Sales\Model\Order::STATE_CANCELED,
            'state' => $cancelled->getState(),
            'status' => $cancelled->getStatus(),
        ];
    }

    /**
     * @return OrderInterface|null
     */
    private function findOrder(string $incrementId)
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $result = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($result);

        return $order === false ? null : $order;
    }

    /**
     * The two checks that keep this from touching a real customer: the address
     * has to be one MageSentinel generated, and the order has to be recent enough
     * to belong to a check that is still running.
     */
    private function assertIsOurs(OrderInterface $order): void
    {
        $email = (string)$order->getCustomerEmail();
        if (preg_match(self::SYNTHETIC_EMAIL, $email) !== 1) {
            throw new LocalizedException(new Phrase('No cancellable MageSentinel order with that number.'));
        }

        $createdAt = strtotime((string)$order->getCreatedAt());
        if ($createdAt === false || (time() - $createdAt) > self::MAX_AGE_SECONDS) {
            throw new LocalizedException(
                new Phrase('That order is too old to be cancelled by the monitor.')
            );
        }
    }

    /**
     * Anything already invoiced or shipped has left the checkout and entered
     * the merchant's accounting and fulfilment. Cancelling it there is not
     * cleanup, it is interference.
     */
    private function assertUntouched(OrderInterface $order): void
    {
        if ((float)$order->getTotalInvoiced() > 0.0) {
            throw new LocalizedException(new Phrase('That order has been invoiced; refusing to cancel.'));
        }

        $shipments = method_exists($order, 'hasShipments') ? $order->hasShipments() : false;
        if ($shipments) {
            throw new LocalizedException(new Phrase('That order has been shipped; refusing to cancel.'));
        }
    }
}
