<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Cron;

use MageSentinel\Monitor\Model\Alerts as AlertsModel;

class Alerts
{
    /** @var AlertsModel */
    private $alerts;

    /**
     * Constructor.
     *
     * @param AlertsModel $alerts
     */
    public function __construct(AlertsModel $alerts)
    {
        $this->alerts = $alerts;
    }

    /**
     * Run the health checks and email what changed.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->alerts->run();
    }
}
