<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Cron;

use MageSentinel\Monitor\Model\Alerts as AlertsModel;

class Alerts
{
    /** @var AlertsModel */
    private $alerts;

    public function __construct(AlertsModel $alerts)
    {
        $this->alerts = $alerts;
    }

    public function execute(): void
    {
        $this->alerts->run();
    }
}
