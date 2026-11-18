<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Every 15 minutes: read the health rows and email when one turns red, and
 * again when it is fixed. One email per problem, never one per run.
 *
 * Once MageSentinel is connected and reading the store, it sends the alerts
 * (email or Slack), so these pause to spare duplicates; if MageSentinel stops
 * reading the store for three hours they resume on their own.
 */
class Alerts
{
    /** @var HealthChecks */
    private $checks;
    /** @var State */
    private $state;
    /** @var Mailer */
    private $mailer;
    /** @var Service */
    private $service;
    /** @var ScopeConfigInterface */
    private $config;
    /** @var Sales */
    private $sales;

    /**
     * Constructor.
     *
     * @param HealthChecks $checks
     * @param State $state
     * @param Mailer $mailer
     * @param Service $service
     * @param ScopeConfigInterface $config
     * @param Sales $sales
     */
    public function __construct(
        HealthChecks $checks,
        State $state,
        Mailer $mailer,
        Service $service,
        ScopeConfigInterface $config,
        Sales $sales
    ) {
        $this->sales = $sales;
        $this->checks = $checks;
        $this->state = $state;
        $this->mailer = $mailer;
        $this->service = $service;
        $this->config = $config;
    }

    /**
     * Whether health emails are on.
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return $this->config->isSetFlag('magesentinel/alerts/enabled');
    }

    /**
     *  Emails are paused while MageSentinel itself is watching the store.
     *
     * @return bool
     */
    public function paused(): bool
    {
        return $this->service->connection() === 'on';
    }

    /**
     * Run the health checks and email what changed.
     *
     * @return array<int, array<string, mixed>> the rows it read
     */
    public function run(): array
    {
        $rows = $this->checks->rows();
        $saved = $this->state->alerts();
        $told = (array)($saved['told'] ?? []);
        $bad = [];
        foreach ($rows as $row) {
            if ($row['state'] === HealthChecks::BAD) {
                $bad[$row['id']] = $row;
            }
        }
        $new = array_diff_key($bad, $told);
        $fixed = array_diff_key($told, $bad);

        if ($this->enabled() && !$this->paused()) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- string parsing only, no I/O
            $host = (string)parse_url($this->service->site(), PHP_URL_HOST);
            if ($new) {
                $this->mailer->send(
                    (string)__('[%1] Store problem: %2', $host, implode(', ', array_column($new, 'label'))),
                    (string)__('Something on %1 needs a look', $host),
                    // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
                    (string)__('The MageSentinel health check found a problem that can stop shoppers from buying. Each row says what is wrong.'),
                    array_values($new),
                    // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
                    (string)__('You get one email per problem and another when it is fixed. Change the address or switch these off under Stores > Configuration > MageSentinel.')
                );
            }
            if ($fixed) {
                $rowsById = array_column($rows, null, 'id');
                $now = [];
                foreach (array_keys($fixed) as $id) {
                    $now[] = $rowsById[$id]
                        ?? ['label' => (string)($told[$id] ?? $id), 'detail' => '', 'state' => 'ok'];
                }
                $this->mailer->send(
                    (string)__('[%1] Fixed: %2', $host, implode(', ', array_column($now, 'label'))),
                    (string)__('Back to normal on %1', $host),
                    (string)__('These problems from an earlier email are fixed.'),
                    $now,
                    // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
                    (string)__('Change the address or switch these emails off under Stores > Configuration > MageSentinel.')
                );
            }
        }

        $this->state->saveAlerts([
            'told' => array_map(static function ($row) {
                return (string)$row['label'];
            }, $bad),
            'checked_at' => time(),
        ]);
        $this->record($rows);
        return $rows;
    }

    /**
     * Fold a reading into today's worst result, for the 7-day strip.
     *
     * @param array $rows
     * @return void
     */
    public function record(array $rows): void
    {
        $states = array_column($rows, 'state');
        $bad = count(array_keys($states, HealthChecks::BAD, true));
        $worst = $bad ? 'bad' : (array_diff($states, [HealthChecks::OK]) ? 'watch' : 'ok');
        $what = [];
        foreach ($rows as $row) {
            if ($row['state'] === HealthChecks::BAD) {
                $what[] = (string)$row['label'];
            }
        }
        $hour = (int)(new \DateTime('now', $this->sales->zone()))->format('G');
        $this->state->recordDay($this->sales->day(time()), $hour, $worst, $bad, $what);
    }

    /**
     * When cron last ran the health checks.
     *
     * @return int
     */
    public function lastChecked(): int
    {
        return (int)($this->state->alerts()['checked_at'] ?? 0);
    }
}
