<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Api;

interface OrderCleanupInterface
{
    /**
     * Cancels a synthetic MageSentinel test order.
     *
     * Cancel, never delete: deleting a sales_order row leaves gaps in the
     * increment_id sequence and breaks anything that has already consumed the
     * order — ERP exports, myDATA submissions, analytics. Cancelling is a
     * first-class Magento operation, restores stock and leaves an auditable
     * record.
     *
     * Every constraint is enforced here rather than trusted from the caller:
     * the monitor is outside the store's trust boundary, so an endpoint that
     * cancels whatever id it is handed would be a way to cancel real customers'
     * orders with a leaked token.
     *
     * @param string $incrementId
     * @return mixed[]
     */
    public function cancel(string $incrementId): array;
}
