<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\FlagManager;

/**
 * The little the module remembers between requests, kept in Magento's flag
 * table so nothing needs a schema of its own: when MageSentinel last read the
 * store, which health rows were already emailed about, the free monthly test,
 * and the one-off proof challenge for it.
 */
class State
{
    private const SEEN = 'magesentinel_last_seen';
    private const ALERTS = 'magesentinel_alert_state';
    private const FREE = 'magesentinel_free_test';
    private const PROOF = 'magesentinel_proof';
    private const WEEKLY = 'magesentinel_weekly_sent';
    private const DAYS = 'magesentinel_health_days';

    /** How long the free-test challenge is shown on the proof route. */
    private const PROOF_TTL = 120;

    /** @var FlagManager */
    private $flags;

    public function __construct(FlagManager $flags)
    {
        $this->flags = $flags;
    }

    public function markSeen(): void
    {
        // At most one write a minute: the diagnostics route is hit on every check.
        if (time() - $this->lastSeen() > 60) {
            $this->flags->saveFlag(self::SEEN, time());
        }
    }

    public function lastSeen(): int
    {
        return (int)$this->flags->getFlagData(self::SEEN);
    }

    /** @return array<string, mixed> */
    public function alerts(): array
    {
        $data = $this->flags->getFlagData(self::ALERTS);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    public function saveAlerts(array $data): void
    {
        $this->flags->saveFlag(self::ALERTS, $data);
    }

    /** @return array<string, mixed> */
    public function freeTest(): array
    {
        $data = $this->flags->getFlagData(self::FREE);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    public function saveFreeTest(array $data): void
    {
        $this->flags->saveFlag(self::FREE, $data);
    }

    public function setProof(string $challenge): void
    {
        $this->flags->saveFlag(self::PROOF, ['value' => $challenge, 'until' => time() + self::PROOF_TTL]);
    }

    public function clearProof(): void
    {
        $this->flags->deleteFlag(self::PROOF);
    }

    public function proof(): string
    {
        $data = $this->flags->getFlagData(self::PROOF);
        if (!is_array($data) || (int)($data['until'] ?? 0) < time()) {
            return '';
        }
        return (string)($data['value'] ?? '');
    }

    public function weeklySentFor(): string
    {
        return (string)$this->flags->getFlagData(self::WEEKLY);
    }

    public function markWeeklySent(string $week): void
    {
        $this->flags->saveFlag(self::WEEKLY, $week);
    }

    /**
     * Keep the worst health result of each store day, last 14 days.
     *
     * @param string $day store-local Y-m-d
     * @param string $worst ok|watch|bad
     */
    public function recordDay(string $day, string $worst, int $bad): void
    {
        $rank = ['ok' => 0, 'watch' => 1, 'bad' => 2];
        $days = $this->healthDays();
        $was = $days[$day] ?? null;
        if ($was !== null && ($rank[$was['state']] ?? 0) > ($rank[$worst] ?? 0)) {
            return;
        }
        if ($was !== null && $was['state'] === $worst && (int)$was['bad'] >= $bad) {
            return;
        }
        $days[$day] = ['state' => $worst, 'bad' => $bad];
        ksort($days);
        $this->flags->saveFlag(self::DAYS, array_slice($days, -14, null, true));
    }

    /** @return array<string, array{state:string, bad:int}> */
    public function healthDays(): array
    {
        $data = $this->flags->getFlagData(self::DAYS);
        return is_array($data) ? $data : [];
    }
}
