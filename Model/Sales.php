<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * The figures on the Store health page, read from the store's own orders:
 * the last 24 hours against the same hours a week ago, orders per day and per
 * hour, and revenue this week against last. MageSentinel's test orders are
 * counted apart and never in the trade figures.
 */
class Sales
{
    private const NOT_PAID = "'canceled','pending_payment','payment_review'";

    /** @var ResourceConnection */
    private $resource;
    /** @var TimezoneInterface */
    private $timezone;
    /** @var array<string, array<string, float>>|null */
    private $hours;

    public function __construct(ResourceConnection $resource, TimezoneInterface $timezone)
    {
        $this->resource = $resource;
        $this->timezone = $timezone;
    }

    public function zone(): \DateTimeZone
    {
        return new \DateTimeZone((string)$this->timezone->getConfigTimezone() ?: 'UTC');
    }

    /** Store-local date (Y-m-d) of a timestamp. */
    public function day(int $ts): string
    {
        return (new \DateTime('@' . $ts))->setTimezone($this->zone())->format('Y-m-d');
    }

    /**
     * Trade orders per UTC hour for the last 15 days.
     *
     * @return array<string, array<string, float>> keyed 'Y-m-d H:00:00' (UTC)
     */
    private function hours(): array
    {
        if ($this->hours !== null) {
            return $this->hours;
        }
        $c = $this->resource->getConnection();
        $rows = $c->fetchAll(
            $c->select()->from($this->resource->getTableName('sales_order'), [
                'h' => "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')",
                'orders' => 'COUNT(*)',
                'revenue' => 'SUM(CASE WHEN state NOT IN (' . self::NOT_PAID . ') THEN base_grand_total ELSE 0 END)',
                'paid' => 'SUM(CASE WHEN state NOT IN (' . self::NOT_PAID . ') THEN 1 ELSE 0 END)',
                'failed' => "SUM(CASE WHEN state = 'canceled' THEN 1 ELSE 0 END)",
                'pending' => "SUM(CASE WHEN state IN ('pending_payment','payment_review') THEN 1 ELSE 0 END)",
            ])
                ->where('created_at > ?', gmdate('Y-m-d H:00:00', time() - 15 * 86400))
                ->where('customer_email NOT LIKE ?', 'magesentinel+%')
                ->where('customer_email NOT LIKE ?', 'magesentinel.%')
                ->group('h')
        );
        $this->hours = [];
        foreach ($rows as $r) {
            $this->hours[(string)$r['h']] = array_map('floatval', $r);
        }
        return $this->hours;
    }

    /** Sum a field over the UTC hours in [from, to). */
    private function sum(string $field, int $from, int $to): float
    {
        $total = 0.0;
        foreach ($this->hours() as $h => $r) {
            $ts = strtotime($h . ' UTC');
            if ($ts >= $from && $ts < $to) {
                $total += $r[$field];
            }
        }
        return $total;
    }

    /** @return array{orders:int, last_week:int, revenue:float, paid:int, failed:int, pending:int} */
    public function last24h(): array
    {
        $to = (int)(floor(time() / 3600) + 1) * 3600;
        $from = $to - 86400;
        return [
            'orders' => (int)$this->sum('orders', $from, $to),
            'last_week' => (int)$this->sum('orders', $from - 7 * 86400, $to - 7 * 86400),
            'revenue' => $this->sum('revenue', $from, $to),
            'paid' => (int)$this->sum('paid', $from, $to),
            'failed' => (int)$this->sum('failed', $from, $to),
            'pending' => (int)$this->sum('pending', $from, $to),
        ];
    }

    /** @return array<int, array{day:string, count:int, revenue:float}> 14 store days, oldest first, today last */
    private function days(): array
    {
        $out = [];
        $today = new \DateTime('now', $this->zone());
        for ($i = 13; $i >= 0; $i--) {
            $d = (clone $today)->modify("-$i days")->format('Y-m-d');
            $out[$d] = ['day' => $d, 'count' => 0, 'revenue' => 0.0];
        }
        foreach ($this->hours() as $h => $r) {
            $d = $this->day((int)strtotime($h . ' UTC'));
            if (isset($out[$d])) {
                $out[$d]['count'] += (int)$r['orders'];
                $out[$d]['revenue'] += $r['revenue'];
            }
        }
        return array_values($out);
    }

    /** @return array<int, array{day:string, count:int, revenue:float}> */
    public function week(): array
    {
        return array_slice($this->days(), 7);
    }

    /** @return array{days:array<int,string>, this:array<int,float>, last:array<int,float>} */
    public function revenueWeeks(): array
    {
        $d = $this->days();
        return [
            'days' => array_column(array_slice($d, 7), 'day'),
            'this' => array_column(array_slice($d, 7), 'revenue'),
            'last' => array_column(array_slice($d, 0, 7), 'revenue'),
        ];
    }

    /** @return array<int, array{short:string, label:string, count:int}> the last 24 hours, oldest first */
    public function hourSlots(): array
    {
        $hours = $this->hours();
        $zone = $this->zone();
        $now = (int)floor(time() / 3600) * 3600;
        $slots = [];
        for ($i = 23; $i >= 0; $i--) {
            $ts = $now - $i * 3600;
            $start = (new \DateTime('@' . $ts))->setTimezone($zone)->format('H:00');
            $end = (new \DateTime('@' . ($ts + 3600)))->setTimezone($zone)->format('H:00');
            $slots[] = ['short' => $start, 'label' => $start . '–' . $end, 'count' => (int)($hours[gmdate('Y-m-d H:00:00', $ts)]['orders'] ?? 0)];
        }
        return $slots;
    }

    /** @return array{count:int, cancelled:int, last:int} MageSentinel test orders, last 7 days */
    public function tests(): array
    {
        $c = $this->resource->getConnection();
        $row = $c->fetchRow(
            $c->select()->from($this->resource->getTableName('sales_order'), [
                'n' => 'COUNT(*)',
                'cancelled' => "SUM(CASE WHEN state = 'canceled' THEN 1 ELSE 0 END)",
                'last' => 'MAX(created_at)',
            ])
                ->where('created_at > ?', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                ->where("customer_email LIKE 'magesentinel+%' OR customer_email LIKE 'magesentinel.%'")
        );
        $last = (string)($row['last'] ?? '');
        return [
            'count' => (int)($row['n'] ?? 0),
            'cancelled' => (int)($row['cancelled'] ?? 0),
            'last' => $last !== '' ? (int)strtotime($last . ' UTC') : 0,
        ];
    }
}
