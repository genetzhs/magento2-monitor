<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Block\Adminhtml;

use MageSentinel\Monitor\Model\Alerts;
use MageSentinel\Monitor\Model\HealthChecks;
use MageSentinel\Monitor\Model\Mailer;
use MageSentinel\Monitor\Model\Sales;
use MageSentinel\Monitor\Model\Service;
use MageSentinel\Monitor\Model\State;
use Magento\Backend\Block\Template;

class Health extends Template
{
    /** @var HealthChecks */
    private $checks;
    /** @var Service */
    private $service;
    /** @var Alerts */
    private $alerts;
    /** @var Mailer */
    private $mailer;
    /** @var State */
    private $state;
    /** @var \Magento\Framework\Locale\ResolverInterface */
    private $localeResolver;
    /** @var Sales */
    private $sales;
    /** @var array<int, array<string, mixed>>|null */
    private $rows;

    public function __construct(
        Template\Context $context,
        HealthChecks $checks,
        Service $service,
        Alerts $alerts,
        Mailer $mailer,
        State $state,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        Sales $sales,
        array $data = []
    ) {
        $this->sales = $sales;
        parent::__construct($context, $data);
        $this->checks = $checks;
        $this->service = $service;
        $this->alerts = $alerts;
        $this->mailer = $mailer;
        $this->state = $state;
        $this->localeResolver = $localeResolver;
    }

    /** @return array<int, array<string, mixed>> */
    public function getRows(): array
    {
        if ($this->rows === null) {
            $this->rows = $this->checks->rows();
            // Today's strip cell should reflect what the merchant is looking at.
            $this->alerts->record($this->rows);
        }
        return $this->rows;
    }

    /** @return array{bad:int, watch:int} */
    public function getCounts(): array
    {
        $counts = ['bad' => 0, 'watch' => 0];
        foreach ($this->getRows() as $row) {
            if (isset($counts[$row['state']])) {
                $counts[$row['state']]++;
            }
        }
        return $counts;
    }

    public function getFixUrl(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }
        $parts = explode('/', $route);
        $path = implode('/', array_slice($parts, 0, 3));
        $params = [];
        for ($i = 3; $i + 1 < count($parts); $i += 2) {
            $params[$parts[$i]] = $parts[$i + 1];
        }
        return $this->getUrl($path, $params);
    }

    public function getConnection(): string
    {
        return $this->service->connection();
    }

    public function getLastSeen(): int
    {
        return $this->state->lastSeen();
    }

    public function getAlertsEnabled(): bool
    {
        return $this->alerts->enabled();
    }

    public function getAlertsPaused(): bool
    {
        return $this->alerts->paused();
    }

    public function getLastChecked(): int
    {
        return $this->alerts->lastChecked();
    }

    public function getRecipient(): string
    {
        return $this->mailer->recipient();
    }

    public function getWeeklyEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag('magesentinel/alerts/weekly');
    }

    /** @return array<string, mixed> */
    public function getFreeTest(): array
    {
        return $this->state->freeTest();
    }

    public function getSite(): string
    {
        return $this->service->site();
    }

    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'magesentinel']);
    }

    public function getRecheckUrl(): string
    {
        return $this->getUrl('magesentinel/health/recheck');
    }

    public function getDashboardUrl(): string
    {
        return Service::BASE . '/app';
    }

    public function getSignupUrl(): string
    {
        $el = strpos((string)$this->localeResolver->getLocale(), 'el') === 0;
        return Service::BASE . ($el ? '/el' : '') . '/signup?utm_source=magento&utm_medium=module';
    }

    public function getSales(): Sales
    {
        return $this->sales;
    }

    /** ok|watch|bad|unknown → the WordPress plugin's good|watch|bad. */
    public function tone(string $state): string
    {
        return $state === 'ok' ? 'good' : ($state === 'bad' ? 'bad' : 'watch');
    }

    /** @return array<int, array<string, mixed>> problems first */
    public function getSortedRows(): array
    {
        $rows = $this->getRows();
        $order = ['bad' => 0, 'watch' => 1, 'good' => 2];
        usort($rows, function ($x, $y) use ($order) {
            return $order[$this->tone($x['state'])] <=> $order[$this->tone($y['state'])];
        });
        return $rows;
    }

    /** @return array{pct:int, good:int, total:int, worst:string, title:string, sub:string} */
    public function getVerdict(): array
    {
        $rows = $this->getRows();
        $tones = array_map([$this, 'tone'], array_column($rows, 'state'));
        $total = count($tones);
        $good = count(array_keys($tones, 'good', true));
        $issues = $total - $good;
        $worst = in_array('bad', $tones, true) ? 'bad' : ($issues ? 'watch' : 'good');
        $title = $worst === 'good' ? __('Everything that takes orders looks healthy')
            : ($worst === 'bad' ? __('Something is stopping or risking orders')
            : ($issues === 1 ? __('One thing needs attention') : __('%1 things need attention', $issues)));
        return [
            'pct' => $total ? (int)floor(100 * $good / $total) : 0,
            'good' => $good, 'total' => $total, 'worst' => $worst, 'title' => (string)$title,
            'sub' => (string)__('%1 of %2 checks healthy.', $good, $total),
        ];
    }

    public function ago(int $ts): string
    {
        if (!$ts) {
            return '—';
        }
        $m = (int)round((time() - $ts) / 60);
        return $m < 60 ? (string)__('%1 min ago', max(0, $m))
            : ($m < 2880 ? (string)__('%1 h ago', (int)floor($m / 60)) : (string)__('%1 days ago', (int)floor($m / 1440)));
    }

    public function money(float $v, int $dec = 2): string
    {
        try {
            return (string)$this->_storeManager->getStore()->getBaseCurrency()->formatPrecision($v, $dec, [], false);
        } catch (\Throwable $e) {
            return number_format($v, $dec);
        }
    }

    public function num(float $n, int $dec = 0): string
    {
        $el = strpos((string)$this->localeResolver->getLocale(), 'el') === 0;
        return number_format($n, $dec, $el ? ',' : '.', $el ? '.' : ',');
    }

    /** A store date in the admin's language: 'EEE' (Mon) or 'EEE d MMM'. */
    public function dayName(string $day, string $pattern = 'EEE'): string
    {
        $ts = (int)strtotime($day . ' 12:00:00 UTC');
        if (class_exists(\IntlDateFormatter::class)) {
            $f = new \IntlDateFormatter((string)$this->localeResolver->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, $pattern);
            $out = $f->format($ts);
            if (is_string($out)) {
                return $out;
            }
        }
        return gmdate($pattern === 'EEE' ? 'D' : 'D j M', $ts);
    }

    public function ordersLabel(int $n): string
    {
        return $n === 1 ? (string)__('1 order') : (string)__('%1 orders', $this->num($n));
    }

    /** @return array<int, array{day:string, state:?string, bad:int}> the last 7 store days, today last */
    public function getHealthDays(): array
    {
        $saved = $this->state->healthDays();
        $out = [];
        $zone = $this->sales->zone();
        for ($i = 6; $i >= 0; $i--) {
            $d = (new \DateTime('now', $zone))->modify("-$i days")->format('Y-m-d');
            $st = $saved[$d]['state'] ?? null;
            $out[] = ['day' => $d, 'state' => $st === null ? null : $this->tone($st), 'bad' => (int)($saved[$d]['bad'] ?? 0)];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> MageSentinel's last 30 test purchases, oldest first */
    public function getSpeedRuns(): array
    {
        if ($this->getConnection() === 'off') {
            return [];
        }
        $key = 'magesentinel_speed_runs';
        $cached = $this->_cache->load($key);
        if (is_string($cached) && $cached !== '') {
            $runs = json_decode($cached, true);
            if (is_array($runs)) {
                return $runs;
            }
        }
        $r = $this->service->history();
        $data = is_array($r['body']) ? ($r['body']['data'] ?? null) : null;
        $runs = $r['code'] === 200 && is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
        $this->_cache->save((string)json_encode($runs), $key, [], 600);
        return $runs;
    }

    public function secs(int $ms): string
    {
        return $this->num($ms / 1000, 1) . ' s';
    }

    // ------------------------------------------------------------- drawing

    public function icon(string $name, int $size = 18): string
    {
        $paths = [
            'store' => '<path d="M3 9h18l-1.5-5h-15L3 9Z"/><path d="M5 9v11h14V9"/><path d="M9 20v-6h6v6"/>',
            'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6"/>',
            'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'truck' => '<path d="M3 6h11v10H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
            'bug' => '<rect x="8" y="6" width="8" height="14" rx="4"/><path d="M19 7l-3 2"/><path d="M5 7l3 2"/><path d="M19 13h-3"/><path d="M5 13h3"/><path d="M19 19l-3-2"/><path d="M5 19l3-2"/><path d="M12 6V3"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'disk' => '<path d="M22 12H2"/><path d="M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6l3.5-7Z"/><path d="M6 16h.01"/><path d="M10 16h.01"/>',
            'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'code' => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
            'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
            'refresh' => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/><path d="M3 21v-5h5"/>',
            'gauge' => '<path d="M12 14l4-4"/><path d="M3.3 19a10 10 0 1 1 17.4 0"/>',
            'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'receipt' => '<path d="M4 2v20l3-2 3 2 2-2 2 2 3-2 3 2V2l-3 2-3-2-2 2-2-2-3 2-3-2Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
            'sliders' => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
            'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'x' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        ];
        return '<svg class="ms-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['check']) . '</svg>';
    }

    public function rowIcon(string $id): string
    {
        $map = ['maintenance' => 'store', 'payment' => 'card', 'shipping' => 'truck', 'orders' => 'cart', 'payment_stuck' => 'receipt',
            'cron' => 'clock', 'indexers' => 'database', 'cache' => 'gauge', 'errors' => 'bug', 'upgrade' => 'box',
            'https' => 'lock', 'mode' => 'code', 'disk' => 'disk'];
        return $this->icon($map[$id] ?? 'check', 17);
    }

    public function stateIcon(string $tone): string
    {
        return $this->icon($tone === 'good' ? 'check' : ($tone === 'bad' ? 'x' : 'alert'), 16);
    }

    public function ring(int $pct): string
    {
        $tone = $pct >= 100 ? 'good' : ($pct >= 85 ? 'watch' : 'bad');
        $c = round(2 * M_PI * 42, 2);
        $off = round($c * (1 - $pct / 100), 2);
        $arc = ' cx="50" cy="50" r="42" stroke="url(#ms-rg-' . $tone . ')" stroke-dasharray="' . $c . '" stroke-dashoffset="' . $off . '"';
        return '<div class="ms-ring" data-ms-ring="' . $pct . '" role="img" aria-label="' . $this->escapeHtmlAttr((string)__('Store health %1%', $pct)) . '">'
            . '<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false"><defs>'
            . '<linearGradient id="ms-rg-good" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4ade80"/><stop offset="1" stop-color="#059669"/></linearGradient>'
            . '<linearGradient id="ms-rg-watch" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fdba74"/><stop offset="1" stop-color="#f59e0b"/></linearGradient>'
            . '<linearGradient id="ms-rg-bad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fb7185"/><stop offset="1" stop-color="#dc2626"/></linearGradient>'
            . '</defs><circle class="ms-ring-track" cx="50" cy="50" r="42"/><circle class="ms-ring-glow"' . $arc . '/><circle class="ms-ring-arc"' . $arc . '/></svg>'
            . '<b><span class="ms-ring-n">' . $pct . '</span><small>%</small></b></div>';
    }

    /** One segment per health check, in the list's order. */
    public function strip(array $rows): string
    {
        $out = '<div class="ms-strip" aria-hidden="true">';
        foreach ($rows as $r) {
            $out .= '<span class="ms-seg ms-' . $this->tone($r['state']) . '" title="' . $this->escapeHtmlAttr($r['label']) . '"></span>';
        }
        return $out . '</div>';
    }

    private function niceMax(int $peak): int
    {
        if ($peak <= 2) {
            return 2;
        }
        $mag = 10 ** (int)floor(log10($peak));
        foreach ([1, 2, 4, 5, 6, 8, 10] as $m) {
            if ($m * $mag >= $peak) {
                return $m * $mag;
            }
        }
        return 10 * $mag;
    }

    private function column(float $x, float $y, float $w, float $h, string $fill, int $i = 0): string
    {
        $r = min(4, $w / 2, $h);
        return '<path d="M' . round($x, 1) . ' ' . round($y + $h, 1)
            . ' V' . round($y + $r, 1) . ' Q' . round($x, 1) . ' ' . round($y, 1) . ' ' . round($x + $r, 1) . ' ' . round($y, 1)
            . ' H' . round($x + $w - $r, 1) . ' Q' . round($x + $w, 1) . ' ' . round($y, 1) . ' ' . round($x + $w, 1) . ' ' . round($y + $r, 1)
            . ' V' . round($y + $h, 1) . ' Z" fill="' . $fill . '" class="ms-col" style="--i:' . $i . '"/>';
    }

    private function yGrid(int $max, int $left, int $right, int $top, float $plot): string
    {
        $out = '';
        foreach ([0, 0.5, 1] as $g) {
            $y = round($top + $plot * (1 - $g), 1) + 0.5;
            $out .= '<line x1="' . $left . '" x2="' . $right . '" y1="' . $y . '" y2="' . $y . '" stroke="' . ($g ? '#eef0f5' : '#d5d9e3') . '" stroke-width="1"/>'
                . '<text x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="ms-tick">' . $this->num($max * $g) . '</text>';
        }
        return $out;
    }

    private function gradient(string $id, string $from, string $to, string $toOpacity = '1'): string
    {
        return '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $from . '"/><stop offset="1" stop-color="' . $to . '" stop-opacity="' . $toOpacity . '"/></linearGradient></defs>';
    }

    private function attr(string $v): string
    {
        return $this->escapeHtmlAttr($v);
    }

    /** @param array<int, array{day:string, count:int}> $days */
    public function weekChart(array $days): string
    {
        $n = max(1, count($days));
        $w = 560; $h = 200; $top = 24; $bottom = 26; $left = 30; $right = $w - 4;
        $plot = $h - $top - $bottom;
        $peak = $days ? (int)max(array_column($days, 'count')) : 0;
        $max = $this->niceMax($peak);
        $band = ($right - $left) / $n;
        $bw = min(40, $band * 0.56);
        $out = '<div class="ms-plot" data-ms-plot><svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . $this->attr((string)__('Orders, last 7 days')) . '">'
            . $this->gradient('ms-teal', '#2dd4bf', '#0d9488') . $this->gradient('ms-teal-p', '#a7e3dc', '#a7e3dc', '.75')
            . $this->yGrid($max, $left, $right, $top, $plot);
        $peakShown = false;
        foreach (array_values($days) as $i => $d) {
            $c = (int)$d['count'];
            $today = $i === $n - 1;
            $cx = $left + $band * ($i + 0.5);
            $bh = $c > 0 ? max(3, $plot * $c / $max) : 0;
            $y = $top + $plot - $bh;
            $out .= '<g class="ms-hitg">';
            if ($bh > 0) {
                $out .= $this->column($cx - $bw / 2, $y, $bw, $bh, $today ? 'url(#ms-teal)' : 'url(#ms-teal-p)', $i);
            }
            $isPeak = !$peakShown && $c === $peak && $c > 0 && !$today;
            if ($today || $isPeak) {
                $peakShown = $peakShown || $isPeak;
                $out .= '<text class="ms-val" x="' . round($cx, 1) . '" y="' . round($y - 8, 1) . '" text-anchor="middle">' . $this->num($c) . '</text>';
            }
            $out .= '<text class="ms-tick' . ($today ? ' now' : '') . '" x="' . round($cx, 1) . '" y="' . ($h - 7) . '" text-anchor="middle">' . $this->escapeHtml($today ? (string)__('Today') : $this->dayName($d['day'])) . '</text>'
                . '<rect class="ms-hit" x="' . round($left + $band * $i, 1) . '" y="' . $top . '" width="' . round($band, 1) . '" height="' . ($plot + $bottom) . '" data-x="' . round($cx, 1) . '" data-y="' . round($y, 1) . '" data-label="' . $this->attr($this->dayName($d['day'], 'EEE d MMM')) . '" data-value="' . $this->attr($this->ordersLabel($c)) . '"/></g>';
        }
        return $out . '</svg><div class="ms-tip" role="tooltip"></div></div>';
    }

    /** @param array<int, array{short:string, label:string, count:int}> $slots */
    public function dayChart(array $slots): string
    {
        $w = 560; $h = 200; $top = 24; $bottom = 26; $left = 30; $right = $w - 4;
        $plot = $h - $top - $bottom;
        $max = $this->niceMax((int)max(array_column($slots, 'count')));
        $band = ($right - $left) / 24;
        $bw = min(14, $band * 0.7);
        $out = '<div class="ms-plot" data-ms-plot><svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . $this->attr((string)__('Orders by hour, last 24 hours')) . '">'
            . $this->gradient('ms-violet', '#a78bfa', '#7c3aed')
            . $this->yGrid($max, $left, $right, $top, $plot);
        foreach ($slots as $i => $s) {
            $c = (int)$s['count'];
            $cx = $left + $band * ($i + 0.5);
            $bh = $c > 0 ? max(3, $plot * $c / $max) : 0;
            $y = $top + $plot - $bh;
            $out .= '<g class="ms-hitg">';
            if ($bh > 0) {
                $out .= $this->column($cx - $bw / 2, $y, $bw, $bh, 'url(#ms-violet)', $i);
            }
            $tick = $i % 6 === 0 ? $s['short'] : ($i === 23 ? (string)__('Now') : '');
            if ($tick !== '') {
                $out .= '<text class="ms-tick' . ($i === 23 ? ' now' : '') . '" x="' . round($cx, 1) . '" y="' . ($h - 7) . '" text-anchor="' . ($i === 23 ? 'end' : 'middle') . '">' . $this->escapeHtml($tick) . '</text>';
            }
            $out .= '<rect class="ms-hit" x="' . round($left + $band * $i, 1) . '" y="' . $top . '" width="' . round($band, 1) . '" height="' . ($plot + $bottom) . '" data-x="' . round($cx, 1) . '" data-y="' . round($y, 1) . '" data-label="' . $this->attr($s['label']) . '" data-value="' . $this->attr($this->ordersLabel($c)) . '"/></g>';
        }
        return $out . '</svg><div class="ms-tip" role="tooltip"></div></div>';
    }

    /** @param array{days:array<int,string>, this:array<int,float>, last:array<int,float>} $rev */
    public function revenueChart(array $rev): string
    {
        $w = 560; $h = 190; $top = 16; $bottom = 26; $left = 8; $right = $w - 8; $plot = $h - $top - $bottom;
        $max = max(1.0, max($rev['this']), max($rev['last'])) * 1.12;
        $x = static function (int $i) use ($left, $right) {
            return round($left + ($right - $left) * $i / 6, 1);
        };
        $y = static function (float $v) use ($top, $plot, $max) {
            return round($top + $plot - $plot * $v / $max, 1);
        };
        $pts = static function (array $vals) use ($x, $y) {
            $o = [];
            foreach (array_values($vals) as $i => $v) {
                $o[] = $x($i) . ',' . $y((float)$v);
            }
            return implode(' ', $o);
        };
        $out = '<div class="ms-plot" data-ms-plot><svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . $this->attr((string)__('Revenue this week vs last week')) . '">'
            . $this->gradient('ms-rev', '#4f6ef7', '#4f6ef7', '0');
        for ($g = 1; $g <= 3; $g++) {
            $gy = round($top + $plot * $g / 4, 1);
            $out .= '<line x1="' . $left . '" x2="' . $right . '" y1="' . $gy . '" y2="' . $gy . '" stroke="#eef1f6"/>';
        }
        $area = $x(0) . ',' . ($top + $plot) . ' ' . $pts($rev['this']) . ' ' . $x(6) . ',' . ($top + $plot);
        $out .= '<polygon class="ms-rev-area" points="' . $area . '" fill="url(#ms-rev)" fill-opacity=".18"/>'
            . '<polyline class="ms-rev-last" points="' . $pts($rev['last']) . '" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5 4"/>'
            . '<polyline class="ms-rev-this" points="' . $pts($rev['this']) . '" fill="none" stroke="#4f6ef7" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
        foreach (array_values($rev['this']) as $i => $v) {
            $today = $i === 6;
            $out .= '<g class="ms-hitg"><circle cx="' . $x($i) . '" cy="' . $y((float)$v) . '" r="' . ($today ? 4.5 : 3) . '" fill="' . ($today ? '#4f6ef7' : '#fff') . '" stroke="#4f6ef7" stroke-width="2"/>'
                . '<text class="ms-tick' . ($today ? ' now' : '') . '" x="' . $x($i) . '" y="' . ($h - 7) . '" text-anchor="middle">' . $this->escapeHtml($today ? (string)__('Today') : $this->dayName($rev['days'][$i])) . '</text>'
                . '<rect class="ms-hit" x="' . round($x($i) - ($right - $left) / 12, 1) . '" y="' . $top . '" width="' . round(($right - $left) / 6, 1) . '" height="' . ($plot + $bottom) . '" data-x="' . $x($i) . '" data-y="' . $y((float)$v) . '" data-label="' . $this->attr($this->dayName($rev['days'][$i], 'EEE d MMM')) . '" data-value="' . $this->attr($this->money((float)$v, 0) . ' · ' . __('last week %1', $this->money((float)$rev['last'][$i], 0))) . '"/></g>';
        }
        return $out . '</svg><div class="ms-tip" role="tooltip"></div></div>';
    }

    /** @return array<string, mixed> */
    public function getJsConfig(): array
    {
        return [
            'startUrl' => $this->getUrl('magesentinel/health/start'),
            'statusUrl' => $this->getUrl('magesentinel/health/status'),
            'formKey' => $this->getFormKey(),
            'labels' => [
                'store' => (string)__('Opening the store'), 'discovery' => (string)__('Finding a product'), 'search' => (string)__('Finding a product'),
                'product' => (string)__('Product page'), 'cart' => (string)__('Adding to cart'), 'cart-validation' => (string)__('Checking the cart'),
                'checkout' => (string)__('Opening checkout'), 'customer' => (string)__('Filling in customer details'), 'shipping' => (string)__('Choosing shipping'),
                'payment' => (string)__('Choosing payment'), 'login' => (string)__('Signing in'),
                'order' => (string)__('Placing the order'), 'order-verification' => (string)__('Confirming the order'), 'order-cleanup' => (string)__('Cancelling the test order'),
                '_short' => [
                    'store' => (string)__('Store'), 'discovery' => (string)__('Search'), 'search' => (string)__('Search'), 'product' => (string)__('Product'),
                    'cart' => (string)__('Cart'), 'cart-validation' => (string)__('Cart'), 'checkout' => (string)__('Checkout'), 'customer' => (string)__('Customer'),
                    'shipping' => (string)__('Shipping'), 'payment' => (string)__('Payment'), 'login' => (string)__('Sign in'),
                    'order' => (string)__('Order'), 'order-verification' => (string)__('Confirmed'), 'order-cleanup' => (string)__('Cancelled'),
                ],
                '_queued' => (string)__('Waiting for a free browser…'), '_start' => (string)__('Starting…'),
                'PASS' => (string)__('Passed: a customer can buy.'), 'FAIL' => (string)__('Failed: a customer could not buy.'),
                'DEGRADED' => (string)__('Passed, with warnings.'), 'WARNING' => (string)__('Passed, with warnings.'),
                'UNKNOWN' => (string)__('Inconclusive: the check could not reach a verdict.'),
                '_slowest' => (string)__('slowest'), '_share' => (string)__('{step} took {share}% of the check'),
                '_view' => (string)__('See the full result'), '_slow' => (string)__('Still running. The result will appear in MageSentinel.'),
                '_error' => (string)__('Could not reach MageSentinel. Try again.'),
            ] + ($this->getConnection() === 'off' ? [
                '_cta' => (string)__('Want an email the next time this happens? MageSentinel runs this check every hour. 14 days free, no card.'),
                '_ctaLink' => (string)__('Start the free trial'),
                '_ctaUrl' => $this->getSignupUrl() . '&utm_content=free-test-result',
            ] : []),
        ];
    }
}
