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
    private const DEPLOY = 'magesentinel_deploy_fingerprint';

    /** How long the free-test challenge is shown on the proof route. */
    private const PROOF_TTL = 120;

    /** @var FlagManager */
    private $flags;

    /**
     * Constructor.
     *
     * @param FlagManager $flags
     */
    public function __construct(FlagManager $flags)
    {
        $this->flags = $flags;
    }

    /**
     * Note that MageSentinel read the store.
     *
     * @return void
     */
    public function markSeen(): void
    {
        // At most one write a minute: the diagnostics route is hit on every check.
        if (time() - $this->lastSeen() > 60) {
            $this->flags->saveFlag(self::SEEN, time());
        }
    }

    /**
     * When MageSentinel last read the store.
     *
     * @return int
     */
    public function lastSeen(): int
    {
        return (int)$this->flags->getFlagData(self::SEEN);
    }

    /**
     * Which health rows were already emailed about.
     *
     * @return array<string, mixed>
     */
    public function alerts(): array
    {
        $data = $this->flags->getFlagData(self::ALERTS);
        return is_array($data) ? $data : [];
    }

    /**
     * Save which health rows were emailed about.
     *
     * @param array $data
     * @return void
     */
    public function saveAlerts(array $data): void
    {
        $this->flags->saveFlag(self::ALERTS, $data);
    }

    /**
     * State of this month's free test.
     *
     * @return array<string, mixed>
     */
    public function freeTest(): array
    {
        $data = $this->flags->getFlagData(self::FREE);
        return is_array($data) ? $data : [];
    }

    /**
     * Save this month's free test.
     *
     * @param array $data
     * @return void
     */
    public function saveFreeTest(array $data): void
    {
        $this->flags->saveFlag(self::FREE, $data);
    }

    /**
     * Show a free-test challenge for two minutes.
     *
     * @param string $challenge
     * @return void
     */
    public function setProof(string $challenge): void
    {
        $this->flags->saveFlag(self::PROOF, ['value' => $challenge, 'until' => time() + self::PROOF_TTL]);
    }

    /**
     * Remove the free-test challenge.
     *
     * @return void
     */
    public function clearProof(): void
    {
        $this->flags->deleteFlag(self::PROOF);
    }

    /**
     * The free-test challenge, while it is still valid.
     *
     * @return string
     */
    public function proof(): string
    {
        $data = $this->flags->getFlagData(self::PROOF);
        if (!is_array($data) || (int)($data['until'] ?? 0) < time()) {
            return '';
        }
        return (string)($data['value'] ?? '');
    }

    /**
     * The week the last weekly summary was sent for.
     *
     * @return string
     */
    public function weeklySentFor(): string
    {
        return (string)$this->flags->getFlagData(self::WEEKLY);
    }

    /**
     * Note the week a summary was sent for.
     *
     * @param string $week
     * @return void
     */
    public function markWeeklySent(string $week): void
    {
        $this->flags->saveFlag(self::WEEKLY, $week);
    }

    /**
     * Record a health reading in its store day.
     *
     * Keep each store day's health, last 14 days: its worst result (and the checks behind it), how many readings
     * were free of problems, and the worst result of each hour as a 24-character string (-, o, w, b).
     *
     * @param string $day store-local Y-m-d
     * @param int $hour store-local hour, 0-23
     * @param string $worst ok|watch|bad
     * @param int $bad
     * @param string[] $what labels of the checks that were bad
     * @return void
     */
    public function recordDay(string $day, int $hour, string $worst, int $bad, array $what = []): void
    {
        $rank = ['ok' => 0, 'watch' => 1, 'bad' => 2];
        $days = $this->healthDays();
        $was = $days[$day] ?? ['state' => 'ok', 'bad' => 0, 'what' => [], 'n' => 0, 'clean' => 0, 'h' => ''];
        $entry = $was;
        if (!isset($days[$day]) || ($rank[$worst] ?? 0) > ($rank[$was['state']] ?? 0)
            || ($worst === $was['state'] && $bad > (int)$was['bad'])) {
            $entry['state'] = $worst;
            $entry['bad'] = $bad;
            $entry['what'] = array_values($what);
        }
        $entry['n'] = (int)($was['n'] ?? 0) + 1;
        $entry['clean'] = (int)($was['clean'] ?? 0) + ($worst === 'bad' ? 0 : 1);
        $hours = str_pad((string)($was['h'] ?? ''), 24, '-');
        $mark = ['ok' => 'o', 'watch' => 'w', 'bad' => 'b'][$worst] ?? 'o';
        $order = ['-' => -1, 'o' => 0, 'w' => 1, 'b' => 2];
        if ($order[$mark] > ($order[$hours[$hour]] ?? -1)) {
            $hours[$hour] = $mark;
        }
        $entry['h'] = $hours;
        $days[$day] = $entry;
        ksort($days);
        $this->flags->saveFlag(self::DAYS, array_slice($days, -14, null, true));
    }

    /**
     * Saved health per store day.
     *
     * @return array<string, array{state:string, bad:int, what?:string[], n?:int, clean?:int, h?:string}>
     */
    public function healthDays(): array
    {
        $data = $this->flags->getFlagData(self::DAYS);
        return is_array($data) ? $data : [];
    }

    /**
     * Fingerprint of the last deploy seen.
     *
     * @return string
     */
    public function deploy(): string
    {
        return (string)$this->flags->getFlagData(self::DEPLOY);
    }

    /**
     * Remember the deploy fingerprint.
     *
     * @param string $hash
     * @return void
     */
    public function saveDeploy(string $hash): void
    {
        $this->flags->saveFlag(self::DEPLOY, $hash);
    }
}
