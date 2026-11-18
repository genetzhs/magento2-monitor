<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Cron;

use MageSentinel\Monitor\Model\TestOrders;

/**
 * Every 15 minutes: delete test orders older than 30 minutes that the check did not.
 */
class SweepTestOrders
{
    /** @var TestOrders */
    private $testOrders;

    /**
     * Constructor.
     *
     * @param TestOrders $testOrders
     */
    public function __construct(TestOrders $testOrders)
    {
        $this->testOrders = $testOrders;
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->testOrders->sweep();
    }
}
