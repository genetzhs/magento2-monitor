<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Shipping\Model\Config as ShippingConfig;

/**
 * The store-health page and the alert emails read the same rows from here.
 *
 * Each row answers one question that, when the answer is wrong, quietly stops
 * the store taking money: can anyone pay, can anything ship, is cron running.
 * These are readings from the inside. They cannot tell whether a shopper can
 * actually get through checkout (a broken payment script, a theme update that
 * hides the button); that is what MageSentinel's browser check is for.
 *
 * Every row is computed independently and a failure to read one is reported
 * as "unknown", never as a fatal: this page is opened precisely when the store
 * is in an odd state.
 */
class HealthChecks
{
    public const OK = 'ok';
    public const WATCH = 'watch';
    public const BAD = 'bad';
    public const UNKNOWN = 'unknown';

    /** Most report files opened per reading; a neglected store has tens of thousands. */
    private const REPORT_LIMIT = 500;

    /** @var ScopeConfigInterface */
    private $config;
    /** @var PaymentConfig */
    private $paymentConfig;
    /** @var ShippingConfig */
    private $shippingConfig;
    /** @var ResourceConnection */
    private $resource;
    /** @var TypeListInterface */
    private $cacheTypeList;
    /** @var State */
    private $appState;
    /** @var ModuleListInterface */
    private $moduleList;

    public function __construct(
        ScopeConfigInterface $config,
        PaymentConfig $paymentConfig,
        ShippingConfig $shippingConfig,
        ResourceConnection $resource,
        TypeListInterface $cacheTypeList,
        State $appState,
        ModuleListInterface $moduleList
    ) {
        $this->config = $config;
        $this->paymentConfig = $paymentConfig;
        $this->shippingConfig = $shippingConfig;
        $this->resource = $resource;
        $this->cacheTypeList = $cacheTypeList;
        $this->appState = $appState;
        $this->moduleList = $moduleList;
    }

    /**
     * Rows of: id, label, state (ok|watch|bad|unknown), detail, fix (an admin
     * route the block turns into a link, or null).
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        $checks = [
            'maintenance' => [__('Store open to shoppers'), 'maintenance', null],
            'payment' => [__('Payment methods'), 'payment', 'adminhtml/system_config/edit/section/payment'],
            'shipping' => [__('Shipping methods'), 'shipping', 'adminhtml/system_config/edit/section/carriers'],
            'orders' => [__('Orders coming in'), 'orders', 'sales/order'],
            'payment_stuck' => [__('Payments completing'), 'paymentStuck', 'sales/order'],
            'cron' => [__('Cron running'), 'cron', null],
            'indexers' => [__('Indexers up to date'), 'indexers', 'indexer/indexer/list'],
            'cache' => [__('Caches enabled and fresh'), 'cache', 'adminhtml/cache'],
            'errors' => [__('Fatal errors, last 24 hours'), 'errors', null],
            'upgrade' => [__('Database matches the code'), 'upgrade', null],
            'https' => [__('HTTPS certificate'), 'https', null],
            'mode' => [__('Production mode'), 'mode', null],
            'disk' => [__('Free disk space'), 'disk', null],
        ];

        $rows = [];
        foreach ($checks as $id => [$label, $method, $fix]) {
            try {
                [$state, $detail] = $this->{$method}();
            } catch (\Throwable $e) {
                [$state, $detail] = [self::UNKNOWN, __('Could not read this on your server.')];
            }
            $rows[] = [
                'id' => $id,
                'label' => (string)$label,
                'state' => $state,
                'detail' => (string)$detail,
                'fix' => $state === self::OK ? null : $fix,
            ];
        }
        return $rows;
    }

    private function maintenance(): array
    {
        if (is_file(BP . '/var/.maintenance.flag')) {
            return [self::BAD, __('Maintenance mode is on: shoppers see the maintenance page. Run bin/magento maintenance:disable.')];
        }
        return [self::OK, __('Maintenance mode is off.')];
    }

    private function payment(): array
    {
        $titles = [];
        foreach (array_keys($this->paymentConfig->getActiveMethods()) as $code) {
            // "free" is Magento's zero-total method, always on; it cannot take money.
            if ($code === 'free' || $code === 'substitution') {
                continue;
            }
            $titles[] = (string)($this->config->getValue("payment/{$code}/title") ?: $code);
        }
        if (!$titles) {
            return [self::BAD, __('No payment method is enabled, so no order can be paid.')];
        }
        return [self::OK, __('%1 enabled: %2', count($titles), implode(', ', array_slice($titles, 0, 5)))];
    }

    private function shipping(): array
    {
        $titles = [];
        foreach (array_keys($this->shippingConfig->getActiveCarriers()) as $code) {
            $titles[] = (string)($this->config->getValue("carriers/{$code}/title") ?: $code);
        }
        if (!$titles) {
            return [self::BAD, __('No shipping method is enabled, so physical products cannot be ordered.')];
        }
        return [self::OK, __('%1 enabled: %2', count($titles), implode(', ', array_slice($titles, 0, 5)))];
    }

    /**
     * Compared with the same 24 hours a week ago: order volume is shaped by hour
     * and weekday, so "fewer than yesterday" means nothing on a Monday morning.
     * Only judged on stores that normally sell often enough for a gap to mean
     * something.
     */
    private function orders(): array
    {
        $c = $this->resource->getConnection();
        $orders = $this->resource->getTableName('sales_order');
        $now = time();
        $lastWeek = (int)$c->fetchOne(
            $c->select()->from($orders, 'COUNT(*)')
                ->where('created_at > ?', gmdate('Y-m-d H:i:s', $now - 86400 - 604800))
                ->where('created_at <= ?', gmdate('Y-m-d H:i:s', $now - 604800))
        );
        $last = $c->fetchOne($c->select()->from($orders, 'MAX(created_at)'));
        if (!$last) {
            return [self::OK, __('No orders yet.')];
        }
        $minutes = (int)round(($now - strtotime((string)$last . ' UTC')) / 60);
        $ago = $this->ago($minutes);
        if ($lastWeek >= 12) {
            $usualGap = 1440 / $lastWeek;
            if ($minutes > max(180, 6 * $usualGap)) {
                return [self::BAD, __('Last order %1 ago. This time last week the store had %2 orders a day, so this gap is unusual.', $ago, $lastWeek)];
            }
            if ($minutes > max(90, 3 * $usualGap)) {
                return [self::WATCH, __('Last order %1 ago, longer than usual for this store.', $ago)];
            }
        }
        return [self::OK, __('Last order %1 ago.', $ago)];
    }

    /**
     * MageSentinel's own checks pay offline by design, so a failing card gateway
     * shows up here, in orders that reached the store and never got paid.
     */
    private function paymentStuck(): array
    {
        $c = $this->resource->getConnection();
        $rows = $c->fetchPairs(
            $c->select()->from($this->resource->getTableName('sales_order'), ['state', 'COUNT(*)'])
                ->where('created_at > ?', gmdate('Y-m-d H:i:s', time() - 86400))
                ->group('state')
        );
        $total = array_sum(array_map('intval', $rows));
        $stuck = (int)($rows['pending_payment'] ?? 0) + (int)($rows['payment_review'] ?? 0);
        if ($total === 0) {
            return [self::OK, __('No orders in the last 24 hours.')];
        }
        if ($stuck >= 5 && $stuck / $total >= 0.3) {
            return [self::BAD, __('%1 of %2 orders in the last 24 hours are stuck waiting for payment. A payment gateway may be failing.', $stuck, $total)];
        }
        if ($stuck >= 3) {
            return [self::WATCH, __('%1 of %2 orders in the last 24 hours are waiting for payment.', $stuck, $total)];
        }
        return [self::OK, __('%1 of %2 orders in the last 24 hours are waiting for payment.', $stuck, $total)];
    }

    private function cron(): array
    {
        $c = $this->resource->getConnection();
        $last = $c->fetchOne(
            $c->select()->from($this->resource->getTableName('cron_schedule'), 'MAX(executed_at)')
                ->where('status = ?', 'success')
        );
        if (!$last) {
            return [self::BAD, __('Cron has never run. Order emails, indexing and scheduled prices depend on it.')];
        }
        $minutes = (int)round((time() - strtotime((string)$last . ' UTC')) / 60);
        if ($minutes > 60) {
            return [self::BAD, __('Last cron job finished %1 ago. Order emails, indexing and scheduled prices wait for it.', $this->ago($minutes))];
        }
        if ($minutes > 15) {
            return [self::WATCH, __('Last cron job finished %1 ago.', $this->ago($minutes))];
        }
        return [self::OK, __('Last cron job finished %1 ago.', $this->ago($minutes))];
    }

    private function indexers(): array
    {
        $c = $this->resource->getConnection();
        $invalid = $c->fetchCol(
            $c->select()->from($this->resource->getTableName('indexer_state'), 'indexer_id')
                ->where('status = ?', 'invalid')
        );
        if (count($invalid) >= 3) {
            return [self::BAD, __('%1 indexers need a reindex: %2. Prices, stock or search may be out of date.', count($invalid), implode(', ', $invalid))];
        }
        if ($invalid) {
            return [self::WATCH, __('Needs a reindex: %1.', implode(', ', $invalid))];
        }
        return [self::OK, __('All indexers are valid.')];
    }

    private function cache(): array
    {
        $disabled = [];
        foreach ($this->cacheTypeList->getTypes() as $id => $type) {
            if (!$type->getStatus()) {
                $disabled[] = (string)$type->getCacheType() ?: $id;
            }
        }
        $invalidated = array_keys($this->cacheTypeList->getInvalidated());
        if (in_array('Page Cache', $disabled, true) || in_array('full_page', $disabled, true)) {
            return [self::BAD, __('Full page cache is off, so every visit is built from scratch: %1.', implode(', ', $disabled))];
        }
        if ($disabled) {
            return [self::WATCH, __('Disabled: %1.', implode(', ', $disabled))];
        }
        if ($invalidated) {
            return [self::WATCH, __('Need a refresh: %1.', implode(', ', $invalidated))];
        }
        return [self::OK, __('All caches are on and fresh.')];
    }

    /**
     * Magento writes one file per fatal error to var/report. Counted by file
     * date only; the files hold stack traces and customer data and are never read.
     */
    private function errors(): array
    {
        $dir = BP . '/var/report';
        if (!is_dir($dir)) {
            return [self::OK, __('None.')];
        }
        $since = time() - 86400;
        $count = 0;
        $seen = 0;
        $capped = false;
        foreach (new \DirectoryIterator($dir) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (++$seen > self::REPORT_LIMIT * 20) {
                $capped = true;
                break;
            }
            if ($file->getMTime() >= $since) {
                $count++;
            }
        }
        $label = $capped ? $count . '+' : (string)$count;
        if ($count >= 20) {
            return [self::BAD, __('%1 fatal error reports in var/report. Shoppers saw an error page each time.', $label)];
        }
        if ($count > 0) {
            return [self::WATCH, __('%1 fatal error reports in var/report.', $label)];
        }
        return [self::OK, __('None.')];
    }

    /** Code ahead of the database: Magento refuses requests until setup:upgrade runs. */
    private function upgrade(): array
    {
        $c = $this->resource->getConnection();
        $db = $c->fetchPairs(
            $c->select()->from($this->resource->getTableName('setup_module'), ['module', 'data_version'])
        );
        $behind = [];
        foreach ($this->moduleList->getAll() as $name => $module) {
            $code = $module['setup_version'] ?? null;
            if ($code && (!isset($db[$name]) || version_compare((string)$db[$name], (string)$code, '<'))) {
                $behind[] = $name;
            }
        }
        if ($behind) {
            return [self::BAD, __('Run bin/magento setup:upgrade. Waiting: %1.', implode(', ', array_slice($behind, 0, 5)))];
        }
        return [self::OK, __('No upgrade waiting.')];
    }

    private function https(): array
    {
        $url = (string)$this->config->getValue('web/secure/base_url');
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (stripos($url, 'https://') !== 0 || $host === '') {
            return [self::BAD, __('The secure base URL is not HTTPS. Browsers warn shoppers on the checkout.')];
        }
        if ((string)$this->config->getValue('web/secure/use_in_frontend') !== '1') {
            return [self::WATCH, __('HTTPS is not used on the storefront (Use Secure URLs on Storefront is off).')];
        }
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            return [self::UNKNOWN, __('Could not reach %1 from this server to read the certificate.', $host)];
        }
        $params = stream_context_get_params($socket);
        fclose($socket);
        $cert = isset($params['options']['ssl']['peer_certificate'])
            ? openssl_x509_parse($params['options']['ssl']['peer_certificate'])
            : false;
        if (!$cert || empty($cert['validTo_time_t'])) {
            return [self::UNKNOWN, __('Could not read the certificate of %1.', $host)];
        }
        $days = (int)floor(((int)$cert['validTo_time_t'] - time()) / 86400);
        if ($days < 0) {
            return [self::BAD, __('The certificate of %1 has expired. Browsers block the store.', $host)];
        }
        if ($days < 7) {
            return [self::BAD, __('The certificate of %1 expires in %2 days.', $host, $days)];
        }
        if ($days < 21) {
            return [self::WATCH, __('The certificate of %1 expires in %2 days.', $host, $days)];
        }
        return [self::OK, __('Valid for %1 more days.', $days)];
    }

    private function mode(): array
    {
        $mode = $this->appState->getMode();
        if ($mode === State::MODE_DEVELOPER) {
            return [self::WATCH, __('The store runs in developer mode: slower, and errors are shown to shoppers. Fine on staging, not on a live store.')];
        }
        return [self::OK, __('Mode: %1.', $mode)];
    }

    private function disk(): array
    {
        $free = @disk_free_space(BP);
        if ($free === false) {
            return [self::UNKNOWN, __('Could not read the free space.')];
        }
        $gb = round($free / 1073741824, 1);
        if ($gb < 1) {
            return [self::BAD, __('%1 GB free. When it runs out, sessions and orders cannot be saved.', $gb)];
        }
        if ($gb < 5) {
            return [self::WATCH, __('%1 GB free.', $gb)];
        }
        return [self::OK, __('%1 GB free.', $gb)];
    }

    private function ago(int $minutes): string
    {
        if ($minutes < 60) {
            return (string)__('%1 min', max(0, $minutes));
        }
        if ($minutes < 2880) {
            return (string)__('%1 h', (int)floor($minutes / 60));
        }
        return (string)__('%1 days', (int)floor($minutes / 1440));
    }
}
