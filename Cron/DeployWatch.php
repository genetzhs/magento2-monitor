<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Cron;

use MageSentinel\Monitor\Model\DeployWatch as DeployWatchModel;

/**
 * Every 5 minutes: a checkout check after a deploy or setup:upgrade.
 */
class DeployWatch
{
    /** @var DeployWatchModel */
    private $watch;

    /**
     * Constructor.
     *
     * @param DeployWatchModel $watch
     */
    public function __construct(DeployWatchModel $watch)
    {
        $this->watch = $watch;
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->watch->run();
    }
}
