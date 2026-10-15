<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Cron;

use MageSentinel\Monitor\Model\Weekly as WeeklyModel;

class Weekly
{
    /** @var WeeklyModel */
    private $weekly;

    public function __construct(WeeklyModel $weekly)
    {
        $this->weekly = $weekly;
    }

    public function execute(): void
    {
        $this->weekly->maybeSend();
    }
}
