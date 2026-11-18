<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem\Driver\File;
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

    /** Most files looked at under pub/media per reading; the walk runs every 15 minutes. */
    private const MEDIA_LIMIT = 20000;

    /**
     * What injected checkout skimmers look like in CMS content and design
     * config: obfuscated code that loads or runs a payload. Legitimate theme
     * and tag-manager snippets don't need any of these.
     */
    private const SKIMMER_PATTERNS = [
        'eval + atob loader' => '/eval\s*\(\s*atob\s*\(/i',
        'eval + char-code loader' => '/eval\s*\(\s*String\.from[C]harCode/i',
        'write + unescape loader' => '/document\.write\s*\(\s*unescape\s*\(/i',
        'Function + atob loader' => '/new\s+Function\s*\(\s*atob\s*\(/i',
        'WebSocket exfiltration' => '/new\s+WebSocket\s*\(\s*[\'"]wss?:/i',
        'hex-escaped script' => '/(\\\\x[0-9a-f]{2}){20,}/i',
    ];

    /** Design config fields Magento prints on every page, a favourite hiding place. */
    private const DESIGN_PATHS = [
        'design/head/includes',
        'design/footer/absolute_footer',
        'design/head/scripts',
        'design/footer/copyright',
    ];

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
    /** @var DeploymentConfig */
    private $deploymentConfig;
    /** @var File */
    private $file;

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $config
     * @param PaymentConfig $paymentConfig
     * @param ShippingConfig $shippingConfig
     * @param ResourceConnection $resource
     * @param TypeListInterface $cacheTypeList
     * @param State $appState
     * @param ModuleListInterface $moduleList
     * @param DeploymentConfig $deploymentConfig
     * @param File $file
     */
    public function __construct(
        ScopeConfigInterface $config,
        PaymentConfig $paymentConfig,
        ShippingConfig $shippingConfig,
        ResourceConnection $resource,
        TypeListInterface $cacheTypeList,
        State $appState,
        ModuleListInterface $moduleList,
        DeploymentConfig $deploymentConfig,
        File $file
    ) {
        $this->config = $config;
        $this->paymentConfig = $paymentConfig;
        $this->shippingConfig = $shippingConfig;
        $this->resource = $resource;
        $this->cacheTypeList = $cacheTypeList;
        $this->appState = $appState;
        $this->moduleList = $moduleList;
        $this->deploymentConfig = $deploymentConfig;
        $this->file = $file;
    }

    /**
     * The health checks, in display order.
     *
     * Rows of: id, label, state (ok|watch|bad|unknown), detail, fix (an admin route the block turns into a link,
     * or null).
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
            'emails' => [__('Order emails sent'), 'emails', 'sales/order'],
            'cron' => [__('Cron running'), 'cron', null],
            'indexers' => [__('Indexers up to date'), 'indexers', 'indexer/indexer/list'],
            'cache' => [__('Caches enabled and fresh'), 'cache', 'adminhtml/cache'],
            'redis' => [__('Redis reachable'), 'redis', null],
            'search' => [__('Search engine reachable'), 'search', null],
            'skimmers' => [__('No injected scripts'), 'skimmers', 'cms/block'],
            'media_php' => [__('No PHP files in media'), 'mediaPhp', null],
            'admin_path' => [__('Admin address not the default'), 'adminPath', null],
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

    /**
     * Maintenance mode left on.
     *
     * @return array
     */
    private function maintenance(): array
    {
        if ($this->quiet(fn() => $this->file->isFile(BP . '/var/.maintenance.flag'))) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('Maintenance mode is on: shoppers see the maintenance page. Run bin/magento maintenance:disable.')];
        }
        return [self::OK, __('Maintenance mode is off.')];
    }

    /**
     * Whether any payment method is enabled.
     *
     * @return array
     */
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

    /**
     * Whether any shipping method is enabled.
     *
     * @return array
     */
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
     * Whether orders stopped coming in, against the same hours last week.
     *
     * Compared with the same 24 hours a week ago: order volume is shaped by hour and weekday, so "fewer than
     * yesterday" means nothing on a Monday morning. Only judged on stores that normally sell often enough for a
     * gap to mean something.
     *
     * @return array
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
                // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
                return [self::BAD, __('Last order %1 ago. This time last week the store had %2 orders a day, so this gap is unusual.', $ago, $lastWeek)];
            }
            if ($minutes > max(90, 3 * $usualGap)) {
                return [self::WATCH, __('Last order %1 ago, longer than usual for this store.', $ago)];
            }
        }
        return [self::OK, __('Last order %1 ago.', $ago)];
    }

    /**
     * Orders stuck waiting for payment.
     *
     * MageSentinel's own checks pay offline by design, so a failing card gateway shows up here, in orders that
     * reached the store and never got paid.
     *
     * @return array
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
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('%1 of %2 orders in the last 24 hours are stuck waiting for payment. A payment gateway may be failing.', $stuck, $total)];
        }
        if ($stuck >= 3) {
            return [self::WATCH, __('%1 of %2 orders in the last 24 hours are waiting for payment.', $stuck, $total)];
        }
        return [self::OK, __('%1 of %2 orders in the last 24 hours are waiting for payment.', $stuck, $total)];
    }

    /**
     * Orders that asked for a confirmation email and never got one.
     *
     * Given 15 minutes, since stores with asynchronous sending hand emails to cron.
     *
     * @return array
     */
    private function emails(): array
    {
        $c = $this->resource->getConnection();
        $orders = $this->resource->getTableName('sales_order');
        $base = $c->select()->from($orders, 'COUNT(*)')
            ->where('created_at > ?', gmdate('Y-m-d H:i:s', time() - 86400))
            ->where('created_at <= ?', gmdate('Y-m-d H:i:s', time() - 900))
            ->where('send_email = 1');
        $total = (int)$c->fetchOne($base);
        if ($total === 0) {
            return [self::OK, __('No orders needing an email in the last 24 hours.')];
        }
        $unsent = (int)$c->fetchOne((clone $base)->where('email_sent IS NULL OR email_sent = 0'));
        if ($unsent >= 3 && $unsent / $total >= 0.5) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('%1 of %2 orders in the last 24 hours got no confirmation email. Check the mail setup and cron.', $unsent, $total)];
        }
        if ($unsent > 0) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::WATCH, __('%1 of %2 orders in the last 24 hours got no confirmation email.', $unsent, $total)];
        }
        return [self::OK, __('All %1 orders in the last 24 hours got their email.', $total)];
    }

    /**
     * Whether cron is running.
     *
     * @return array
     */
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
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('Last cron job finished %1 ago. Order emails, indexing and scheduled prices wait for it.', $this->ago($minutes))];
        }
        if ($minutes > 15) {
            return [self::WATCH, __('Last cron job finished %1 ago.', $this->ago($minutes))];
        }
        return [self::OK, __('Last cron job finished %1 ago.', $this->ago($minutes))];
    }

    /**
     * Indexers that need a reindex.
     *
     * @return array
     */
    private function indexers(): array
    {
        $c = $this->resource->getConnection();
        $invalid = $c->fetchCol(
            $c->select()->from($this->resource->getTableName('indexer_state'), 'indexer_id')
                ->where('status = ?', 'invalid')
        );
        if (count($invalid) >= 3) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('%1 indexers need a reindex: %2. Prices, stock or search may be out of date.', count($invalid), implode(', ', $invalid))];
        }
        if ($invalid) {
            return [self::WATCH, __('Needs a reindex: %1.', implode(', ', $invalid))];
        }
        return [self::OK, __('All indexers are valid.')];
    }

    /**
     * Disabled or stale caches.
     *
     * @return array
     */
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
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
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
     * Every Redis server in env.php (cache, page cache, sessions).
     *
     * A Redis that is down makes Magento throw on every request, or log everyone out.
     *
     * @return array
     */
    private function redis(): array
    {
        $servers = [];
        foreach ((array)$this->deploymentConfig->get('cache/frontend', []) as $name => $frontend) {
            if (stripos((string)($frontend['backend'] ?? ''), 'redis') !== false) {
                $options = (array)($frontend['backend_options'] ?? []);
                $servers[(string)$name] = [
                    (string)($options['server'] ?? '127.0.0.1'),
                    (int)($options['port'] ?? 6379),
                ];
            }
        }
        if ($this->deploymentConfig->get('session/save') === 'redis') {
            $session = (array)$this->deploymentConfig->get('session/redis', []);
            $servers['session'] = [(string)($session['host'] ?? '127.0.0.1'), (int)($session['port'] ?? 6379)];
        }
        if (!$servers) {
            return [self::OK, __('Redis is not used by this store.')];
        }
        $down = [];
        foreach ($servers as $name => [$host, $port]) {
            if (!$this->ping($host, $port)) {
                $down[] = $name;
            }
        }
        if ($down) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('Redis does not answer for: %1. Pages or sessions fail while it is down.', implode(', ', $down))];
        }
        return [self::OK, __('Answering for: %1.', implode(', ', array_keys($servers)))];
    }

    /**
     * When OpenSearch or Elasticsearch is down, category and search pages come back empty.
     *
     * @return array
     */
    private function search(): array
    {
        $engine = (string)$this->config->getValue('catalog/search/engine');
        if ($engine === '' || stripos($engine, 'mysql') !== false) {
            return [self::OK, __('No separate search engine is used.')];
        }
        $prefix = 'catalog/search/' . $engine;
        $host = (string)($this->config->getValue($prefix . '_server_hostname') ?: 'localhost');
        $port = (int)($this->config->getValue($prefix . '_server_port') ?: 9200);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- string parsing only, no I/O
        $host = (string)(parse_url(strpos($host, '://') === false ? 'http://' . $host : $host, PHP_URL_HOST) ?: $host);
        if (!$this->ping($host, $port)) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('%1 at %2:%3 does not answer. Category and search pages may show no products.', $engine, $host, $port)];
        }
        return [self::OK, __('%1 answers at %2:%3.', $engine, $host, $port)];
    }

    /**
     * Obfuscated card-skimmer loaders in CMS content and design fields.
     *
     * Looks for the obfuscated loaders that checkout skimmers (Magecart) inject into CMS blocks, pages and the
     * design config's head and footer fields. Matches are named by place, never printed: the content is the
     * attacker's.
     *
     * @return array
     */
    private function skimmers(): array
    {
        $c = $this->resource->getConnection();
        $sources = [];
        $tables = ['cms_block' => ['block_id', 'identifier'], 'cms_page' => ['page_id', 'identifier']];
        foreach ($tables as $table => [$id, $name]) {
            $rows = $c->fetchPairs(
                $c->select()->from($this->resource->getTableName($table), [$name, 'content'])
                    ->where('is_active = 1')
                    ->where('content LIKE ?', '%<script%')
            );
            foreach ($rows as $identifier => $content) {
                $sources[($table === 'cms_block' ? 'block ' : 'page ') . $identifier] = (string)$content;
            }
        }
        $config = $c->fetchAll(
            $c->select()->from($this->resource->getTableName('core_config_data'), ['path', 'scope_id', 'value'])
                ->where('path IN (?)', self::DESIGN_PATHS)
        );
        foreach ($config as $row) {
            $sources[$row['path'] . ' (scope ' . $row['scope_id'] . ')'] = (string)$row['value'];
        }

        $found = [];
        foreach ($sources as $place => $content) {
            foreach (self::SKIMMER_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $content)) {
                    $found[] = $place . ': ' . $label;
                    break;
                }
            }
        }
        if ($found) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('Obfuscated script found, the way card skimmers hide: %1. Check it before the next order.', implode('; ', array_slice($found, 0, 5)))];
        }
        return [self::OK, __('Checked %1 CMS blocks, pages and design fields with scripts.', count($sources))];
    }

    /**
     * Pub/media only holds uploads; a PHP file there is almost always a web shell.
     *
     * Names the first few, and stops after MEDIA_LIMIT files.
     *
     * @return array
     */
    private function mediaPhp(): array
    {
        $dir = BP . '/pub/media';
        if (!$this->quiet(fn() => $this->file->isDirectory($dir))) {
            return [self::OK, __('No media folder.')];
        }
        $found = [];
        $seen = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iterator as $file) {
            if (++$seen > self::MEDIA_LIMIT) {
                break;
            }
            if (preg_match('/\.(php\d?|phtml|phar)$/i', $file->getFilename())) {
                $found[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        if ($found) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('%1 PHP files in pub/media, where only uploads belong. Likely a web shell: %2.', count($found), implode(', ', array_slice($found, 0, 3)))];
        }
        return [self::OK, __('None found.')];
    }

    /**
     * Bots try passwords on /admin all day.
     *
     * A custom frontName stops nearly all of it; it is no substitute for 2FA, so this is a watch, never a bad.
     *
     * @return array
     */
    private function adminPath(): array
    {
        if ((string)$this->config->getValue('admin/url/use_custom_path') === '1') {
            return [self::OK, __('The admin uses a custom URL.')];
        }
        $name = strtolower(trim((string)$this->deploymentConfig->get('backend/frontName')));
        if (in_array($name, ['', 'admin', 'backend', 'administrator'], true)) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::WATCH, __('The admin is at /%1, the first address bots try. Set a custom backend frontName in env.php.', $name ?: 'admin')];
        }
        return [self::OK, __('The admin is not at a default address.')];
    }

    /**
     * Magento writes one file per fatal error to var/report.
     *
     * Counted by file date only; the files hold stack traces and customer data and are never read.
     *
     * @return array
     */
    private function errors(): array
    {
        $dir = BP . '/var/report';
        if (!$this->quiet(fn() => $this->file->isDirectory($dir))) {
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
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('%1 fatal error reports in var/report. Shoppers saw an error page each time.', $label)];
        }
        if ($count > 0) {
            return [self::WATCH, __('%1 fatal error reports in var/report.', $label)];
        }
        return [self::OK, __('None.')];
    }

    /**
     * Code ahead of the database: Magento refuses requests until setup:upgrade runs.
     *
     * @return array
     */
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
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::BAD, __('Run bin/magento setup:upgrade. Waiting: %1.', implode(', ', array_slice($behind, 0, 5)))];
        }
        return [self::OK, __('No upgrade waiting.')];
    }

    /**
     * The HTTPS certificate of the secure base URL and its expiry.
     *
     * @return array
     */
    private function https(): array
    {
        $url = (string)$this->config->getValue('web/secure/base_url');
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- string parsing only, no I/O
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (stripos($url, 'https://') !== 0 || $host === '') {
            return [self::BAD, __('The secure base URL is not HTTPS. Browsers warn shoppers on the checkout.')];
        }
        if ((string)$this->config->getValue('web/secure/use_in_frontend') !== '1') {
            return [self::WATCH, __('HTTPS is not used on the storefront (Use Secure URLs on Storefront is off).')];
        }
        $params = $this->peerCertificate($host);
        if ($params === null) {
            return [self::UNKNOWN, __('Could not reach %1 from this server to read the certificate.', $host)];
        }
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

    /**
     * Developer mode on a live store.
     *
     * @return array
     */
    private function mode(): array
    {
        $mode = $this->appState->getMode();
        if ($mode === State::MODE_DEVELOPER) {
            // phpcs:ignore Generic.Files.LineLength -- one translatable sentence
            return [self::WATCH, __('The store runs in developer mode: slower, and errors are shown to shoppers. Fine on staging, not on a live store.')];
        }
        return [self::OK, __('Mode: %1.', $mode)];
    }

    /**
     * Free disk space.
     *
     * @return array
     */
    private function disk(): array
    {
        $free = $this->quiet(fn() => disk_free_space(BP));
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

    /**
     * Whether a TCP port answers within 3 seconds.
     *
     * Magento has no API for a bare TCP connect, so this uses PHP's socket functions directly.
     *
     * @param string $host
     * @param int $port
     * @return bool
     */
    private function ping(string $host, int $port): bool
    {
        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- a TCP connect, not file access
            $socket = $this->quiet(fn() => fsockopen($host, $port, $errno, $errstr, 3));
            if (!$socket) {
                return false;
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- closes the socket opened above
            fclose($socket);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The stream context params of a TLS connection to the host, holding its certificate; null when unreachable.
     *
     * Magento has no API that exposes a peer certificate, so this uses PHP's stream functions directly.
     *
     * @param string $host
     * @return array|null
     */
    private function peerCertificate(string $host): ?array
    {
        try {
            $options = [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ];
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- TLS handshake, not file access
            $context = stream_context_create(['ssl' => $options]);
            $socket = $this->quiet(
                // phpcs:ignore Magento2.Functions.DiscouragedFunction -- TLS handshake, not file access
                fn() => stream_socket_client(
                    "ssl://{$host}:443",
                    $errno,
                    $errstr,
                    5,
                    STREAM_CLIENT_CONNECT,
                    $context
                )
            );
            if (!$socket) {
                return null;
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- reads the certificate captured above
            $params = stream_context_get_params($socket);
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- closes the socket opened above
            fclose($socket);
            return $params;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Minutes as a short duration.
     *
     * @param int $minutes
     * @return string
     */
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

    /**
     * Run a call with PHP warnings silenced; false when it throws.
     *
     * Runs a filesystem or network call that reports failure by returning false, without letting its PHP warning
     * reach the error handler.
     *
     * @param callable $call
     * @return mixed
     */
    private function quiet(callable $call)
    {
        set_error_handler(static function () {
            return true;
        });
        try {
            return $call();
        } catch (\Throwable $e) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
