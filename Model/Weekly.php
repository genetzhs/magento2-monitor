<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Optional Monday email: last week's orders, revenue, average order and failed
 * payments against the week before, plus the health rows as they stand. Built
 * from the store's own orders and sent through its own mail; off until the
 * merchant switches it on.
 */
class Weekly
{
    /** @var ScopeConfigInterface */
    private $config;
    /** @var ResourceConnection */
    private $resource;
    /** @var TimezoneInterface */
    private $timezone;
    /** @var State */
    private $state;
    /** @var Mailer */
    private $mailer;
    /** @var HealthChecks */
    private $checks;
    /** @var Service */
    private $service;
    /** @var CurrencyFactory */
    private $currencyFactory;

    public function __construct(
        ScopeConfigInterface $config,
        ResourceConnection $resource,
        TimezoneInterface $timezone,
        State $state,
        Mailer $mailer,
        HealthChecks $checks,
        Service $service,
        CurrencyFactory $currencyFactory
    ) {
        $this->config = $config;
        $this->resource = $resource;
        $this->timezone = $timezone;
        $this->state = $state;
        $this->mailer = $mailer;
        $this->checks = $checks;
        $this->service = $service;
        $this->currencyFactory = $currencyFactory;
    }

    /** Called hourly; sends once, on Monday from 08:00 store time. */
    public function maybeSend(): void
    {
        if (!$this->config->isSetFlag('magesentinel/alerts/weekly')) {
            return;
        }
        $now = $this->timezone->date();
        if ((int)$now->format('N') !== 1 || (int)$now->format('G') < 8) {
            return;
        }
        $week = $now->format('o-W');
        if ($this->state->weeklySentFor() === $week) {
            return;
        }
        if ($this->send()) {
            $this->state->markWeeklySent($week);
        }
    }

    public function send(): bool
    {
        $now = time();
        $cur = $this->stats($now - 7 * 86400, $now);
        $prev = $this->stats($now - 14 * 86400, $now - 7 * 86400);
        $money = function (float $v): string {
            try {
                $code = (string)$this->config->getValue('currency/options/base');
                return $this->currencyFactory->create()->load($code)->formatTxt($v, ['display' => \Magento\Framework\Currency\Data\Currency::NO_SYMBOL]) . ' ' . $code;
            } catch (\Throwable $e) {
                return number_format($v, 2);
            }
        };
        $delta = static function (float $a, float $b): string {
            if ($b <= 0) {
                return '';
            }
            $p = (int)round(($a - $b) / $b * 100);
            return ' (' . ($p >= 0 ? '+' : '') . $p . '%)';
        };
        $rows = [
            ['label' => (string)__('Orders'), 'detail' => (string)__('%1, week before %2', $cur['orders'] . $delta($cur['orders'], $prev['orders']), $prev['orders']), 'state' => 'ok'],
            ['label' => (string)__('Revenue'), 'detail' => (string)__('%1, week before %2', $money($cur['revenue']) . $delta($cur['revenue'], $prev['revenue']), $money($prev['revenue'])), 'state' => 'ok'],
            ['label' => (string)__('Average order'), 'detail' => (string)__('%1, week before %2', $money($cur['aov']), $money($prev['aov'])), 'state' => 'ok'],
            ['label' => (string)__('Cancelled or unpaid'), 'detail' => (string)__('%1, week before %2', $cur['failed'], $prev['failed']), 'state' => $cur['failed'] > max(3, 2 * $prev['failed']) ? 'watch' : 'ok'],
        ];
        foreach ($this->checks->rows() as $row) {
            if ($row['state'] !== HealthChecks::OK) {
                $rows[] = $row;
            }
        }
        $host = (string)parse_url($this->service->site(), PHP_URL_HOST);
        return $this->mailer->send(
            (string)__('[%1] Your week: %2 orders', $host, $cur['orders']),
            (string)__('Last week on %1', $host),
            (string)__('The last 7 days against the 7 before, from your own orders. Health checks that need a look are listed after the figures.'),
            $rows,
            (string)__('Switch this summary off under Stores > Configuration > MageSentinel.')
        );
    }

    /** @return array{orders:int, revenue:float, aov:float, failed:int} */
    private function stats(int $from, int $to): array
    {
        $c = $this->resource->getConnection();
        $table = $this->resource->getTableName('sales_order');
        $row = $c->fetchRow(
            $c->select()->from($table, [
                'orders' => 'COUNT(*)',
                'revenue' => "SUM(CASE WHEN state NOT IN ('canceled','pending_payment','payment_review') THEN base_grand_total ELSE 0 END)",
                'paid' => "SUM(CASE WHEN state NOT IN ('canceled','pending_payment','payment_review') THEN 1 ELSE 0 END)",
                'failed' => "SUM(CASE WHEN state IN ('canceled','pending_payment','payment_review') THEN 1 ELSE 0 END)",
            ])
                ->where('created_at > ?', gmdate('Y-m-d H:i:s', $from))
                ->where('created_at <= ?', gmdate('Y-m-d H:i:s', $to))
                // MageSentinel's own test orders are not trade.
                ->where('customer_email NOT LIKE ?', 'magesentinel+%')
                ->where('customer_email NOT LIKE ?', 'magesentinel.%')
        );
        $revenue = (float)($row['revenue'] ?? 0);
        $paid = (int)($row['paid'] ?? 0);
        return [
            'orders' => (int)($row['orders'] ?? 0),
            'revenue' => $revenue,
            'aov' => $paid ? $revenue / $paid : 0.0,
            'failed' => (int)($row['failed'] ?? 0),
        ];
    }
}
