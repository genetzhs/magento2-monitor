<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Cron;

use MageSentinel\Monitor\Model\Weekly as WeeklyModel;

class Weekly
{
    /** @var WeeklyModel */
    private $weekly;

    /**
     * Constructor.
     *
     * @param WeeklyModel $weekly
     */
    public function __construct(WeeklyModel $weekly)
    {
        $this->weekly = $weekly;
    }

    /**
     * Send the weekly summary when it is due.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->weekly->maybeSend();
    }
}
