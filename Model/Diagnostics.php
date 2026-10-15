<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use MageSentinel\Monitor\Api\DiagnosticsInterface;
use MageSentinel\Monitor\Helper\Access;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Read-only store diagnostics.
 *
 * Everything here answers one question: "when checkout broke, what else was
 * true about this store?". The endpoint never writes, never exposes customer
 * data, and never returns credentials — the point is to explain failures, not to
 * become another way to attack the store.
 */
class Diagnostics implements DiagnosticsInterface
{
    /** @var Access */
    private $access;
    /** @var ProductMetadataInterface */
    private $productMetadata;
    /** @var State */
    private $appState;
    /** @var ModuleListInterface */
    private $moduleList;
    /** @var ScopeConfigInterface */
    private $config;
    /** @var PaymentConfig */
    private $paymentConfig;
    /** @var ShippingConfig */
    private $shippingConfig;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var ResourceConnection */
    private $resource;
    /** @var DeploymentConfig */
    private $deploymentConfig;
    /** @var TypeListInterface */
    private $cacheTypeList;
    /** @var \MageSentinel\Monitor\Model\State */
    private $moduleState;

    /**
     * Most report files we will open in one call. See crashReports().
     */
    const CRASH_REPORT_LIMIT = 200;

    public function __construct(
        Access $access,
        ProductMetadataInterface $productMetadata,
        State $appState,
        ModuleListInterface $moduleList,
        ScopeConfigInterface $config,
        PaymentConfig $paymentConfig,
        ShippingConfig $shippingConfig,
        StoreManagerInterface $storeManager,
        ResourceConnection $resource,
        DeploymentConfig $deploymentConfig,
        TypeListInterface $cacheTypeList,
        \MageSentinel\Monitor\Model\State $moduleState
    ) {
        $this->access = $access;
        $this->productMetadata = $productMetadata;
        $this->appState = $appState;
        $this->moduleList = $moduleList;
        $this->config = $config;
        $this->paymentConfig = $paymentConfig;
        $this->shippingConfig = $shippingConfig;
        $this->storeManager = $storeManager;
        $this->resource = $resource;
        $this->deploymentConfig = $deploymentConfig;
        $this->cacheTypeList = $cacheTypeList;
        $this->moduleState = $moduleState;
    }

    /**
     * New keys are appended, never inserted: Magento serialises an untyped
     * array return positionally, so the client unpacks this by index.
     *
     * @param string|null $sku
     * @return mixed[]
     */
    public function get($sku = null): array
    {
        $this->access->assertAllowed();
        // The admin page and the health emails read this to know MageSentinel is watching.
        $this->safe(function () {
            $this->moduleState->markSeen();
        });

        return [
            'connector_version' => '2.0.2',
            'collected_at' => gmdate('c'),
            'magento' => $this->magento(),
            'modules' => $this->modules(),
            'payment_methods' => $this->paymentMethods(),
            'shipping_methods' => $this->shippingMethods(),
            'cron' => $this->cron(),
            'indexers' => $this->indexers(),
            'queue' => $this->queue(),
            'errors' => $this->recentErrors(),
            'deployment' => $this->deployment(),
            'sales' => $this->sales(),
            'stock' => $this->stock($sku),
            'config_changes' => $this->configChanges(),
            'platform' => $this->platform(),
            'crash_reports' => $this->crashReports(),
            'search' => $this->search(),
            'page_cache' => $this->pageCache(),
        ];
    }

    /**
     * Real trade, as counts only.
     *
     * This is the one thing a synthetic checkout structurally cannot see: our
     * own run proves the funnel works for us, not that it works for the
     * shoppers actually on the site. A store whose real orders stop while our
     * check keeps passing is losing money in a way no external monitor can
     * detect -- a card type failing, one country's shipping breaking, mobile
     * layout swallowing the pay button.
     *
     * Counts and nothing else. Order rows carry names, addresses and emails;
     * the promise this module makes is that it never exposes customer data, and
     * an hourly histogram keeps that promise while a row dump breaks it.
     *
     * @return mixed[]
     */
    private function sales(): array
    {
        return $this->safe(function () {
            $connection = $this->resource->getConnection();
            $orders = $this->resource->getTableName('sales_order');
            $now = time();

            // Hour buckets for the last day, so a drop has a shape rather than
            // a single number that could always have been low.
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($orders, [
                        'bucket' => "DATE_FORMAT(created_at, '%Y-%m-%dT%H:00:00Z')",
                        'count' => 'COUNT(*)',
                    ])
                    ->where('created_at > ?', gmdate('Y-m-d H:i:s', $now - 86400))
                    ->group('bucket')
                    ->order('bucket ASC')
            );
            $last24h = [];
            $total24h = 0;
            foreach ($rows as $row) {
                $count = (int)$row['count'];
                $last24h[] = ['hour' => (string)$row['bucket'], 'count' => $count];
                $total24h += $count;
            }

            // The same window a week ago is the only fair comparison: order
            // volume is strongly hour-of-day and day-of-week shaped, so
            // "fewer than yesterday" means nothing on a Monday morning.
            $sameDayLastWeek = (int)$connection->fetchOne(
                $connection->select()
                    ->from($orders, 'COUNT(*)')
                    ->where('created_at > ?', gmdate('Y-m-d H:i:s', $now - 86400 - 604800))
                    ->where('created_at <= ?', gmdate('Y-m-d H:i:s', $now - 604800))
            );

            $lastOrderAt = $connection->fetchOne(
                $connection->select()->from($orders, 'MAX(created_at)')
            );

            return [
                'last_24h' => $last24h,
                'total_24h' => $total24h,
                'same_window_last_week' => $sameDayLastWeek,
                'last_order_at' => $lastOrderAt ?: null,
                'minutes_since_last_order' => $lastOrderAt
                    ? (int)round(($now - strtotime((string)$lastOrderAt)) / 60)
                    : null,
                'payment_stuck' => $this->paymentStuck(),
                'abandonment' => $this->abandonment(),
            ];
        }, ['error' => 'sales tables unavailable']);
    }

    /**
     * Orders that reached the store and then stalled before payment cleared.
     *
     * MageSentinel's own checks pay with an offline method by design -- they must
     * never move real money -- so a broken card gateway is invisible to the
     * browser run and perfectly visible here.
     *
     * @return mixed[]
     */
    private function paymentStuck(): array
    {
        return $this->safe(function () {
            $connection = $this->resource->getConnection();
            $orders = $this->resource->getTableName('sales_order');

            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($orders, ['state', 'count' => 'COUNT(*)'])
                    ->where('created_at > ?', gmdate('Y-m-d H:i:s', time() - 86400))
                    ->group('state')
            );

            $byState = [];
            $stuck = 0;
            $total = 0;
            foreach ($rows as $row) {
                $state = (string)$row['state'];
                $count = (int)$row['count'];
                $byState[$state] = $count;
                $total += $count;
                if ($state === 'pending_payment' || $state === 'payment_review') {
                    $stuck += $count;
                }
            }

            return ['by_state' => $byState, 'stuck' => $stuck, 'total' => $total];
        }, null);
    }

    /**
     * Carts that were built and never became orders, in the last hour.
     *
     * Abandonment is never zero and the absolute number means little; what
     * matters is the ratio moving. Reported raw so the reading can compare it
     * against this store's own history rather than an industry average.
     *
     * @return mixed[]
     */
    private function abandonment(): array
    {
        return $this->safe(function () {
            $connection = $this->resource->getConnection();
            $quote = $this->resource->getTableName('quote');
            $since = gmdate('Y-m-d H:i:s', time() - 3600);

            $open = (int)$connection->fetchOne(
                $connection->select()
                    ->from($quote, 'COUNT(*)')
                    ->where('items_count > 0')
                    ->where('is_active = 1')
                    ->where('updated_at > ?', $since)
            );
            $converted = (int)$connection->fetchOne(
                $connection->select()
                    ->from($quote, 'COUNT(*)')
                    ->where('items_count > 0')
                    ->where('is_active = 0')
                    ->where('updated_at > ?', $since)
            );

            return ['carts_open_1h' => $open, 'carts_converted_1h' => $converted];
        }, null);
    }

    /**
     * What the store believes about the SKU the monitor is buying.
     *
     * Without this the run can only guess from the storefront, and it has
     * guessed wrong: a product whose swatch click was swallowed by a cookie
     * modal was reported to the merchant as out of stock. Server-side truth
     * turns that accusation back into a browser problem.
     *
     * @param string|null $sku
     * @return mixed[]|null
     */
    private function stock($sku)
    {
        if (!is_string($sku) || $sku === '') {
            return null;
        }

        return $this->safe(function () use ($sku) {
            $connection = $this->resource->getConnection();
            $product = $this->resource->getTableName('catalog_product_entity');
            $stockItem = $this->resource->getTableName('cataloginventory_stock_item');

            $row = $connection->fetchRow(
                $connection->select()
                    ->from(['p' => $product], ['sku', 'type_id'])
                    ->joinLeft(
                        ['s' => $stockItem],
                        's.product_id = p.entity_id',
                        ['qty', 'is_in_stock', 'min_sale_qty', 'manage_stock', 'backorders']
                    )
                    ->where('p.sku = ?', $sku)
                    ->limit(1)
            );

            if (!$row) {
                return ['sku' => $sku, 'found' => false];
            }

            return [
                'sku' => (string)$row['sku'],
                'found' => true,
                'type' => (string)$row['type_id'],
                'qty' => $row['qty'] === null ? null : (float)$row['qty'],
                'is_in_stock' => (bool)$row['is_in_stock'],
                'manage_stock' => $row['manage_stock'] === null ? null : (bool)$row['manage_stock'],
                'backorders' => $row['backorders'] === null ? null : (int)$row['backorders'],
            ];
        }, ['sku' => $sku, 'error' => 'stock tables unavailable']);
    }

    /**
     * Which checkout-relevant settings were touched recently, and when.
     *
     * Paths and timestamps only -- never values. `core_config_data` holds
     * gateway credentials and API keys in the same rows as the harmless flags,
     * so returning a value is how a diagnostics endpoint becomes a credential
     * leak. The path alone answers the question that matters: "somebody changed
     * payment configuration forty minutes before the first failure".
     *
     * @return mixed[]
     */
    private function configChanges(): array
    {
        return $this->safe(function () {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('core_config_data');

            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['path', 'scope', 'scope_id', 'updated_at'])
                    ->where('updated_at > ?', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                    ->where($this->pathPrefixes($connection, ['payment/', 'carriers/', 'checkout/', 'tax/']))
                    ->order('updated_at DESC')
                    ->limit(40)
            );

            $changes = [];
            foreach ($rows as $row) {
                $changes[] = [
                    'path' => (string)$row['path'],
                    'scope' => (string)$row['scope'],
                    'scope_id' => (int)$row['scope_id'],
                    'changed_at' => (string)$row['updated_at'],
                ];
            }
            return $changes;
        }, []);
    }

    /**
     * One OR-ed LIKE clause. Zend_Db's where() binds a single value, so a
     * multi-placeholder condition would quietly bind only the first.
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string[] $prefixes
     */
    private function pathPrefixes($connection, array $prefixes): string
    {
        $parts = [];
        foreach ($prefixes as $prefix) {
            $parts[] = $connection->quoteInto('path LIKE ?', $prefix . '%');
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * The handful of whole-store conditions that explain a total failure in one
     * line, and that are invisible from the outside or easy to misread.
     *
     * @return mixed[]
     */
    private function platform(): array
    {
        $root = rtrim((string)($this->deploymentConfig->get('directories/document_root') ?: BP), '/');

        return [
            // A store in maintenance answers every request with a 503. Reading
            // the flag is the difference between an incident and a planned
            // window.
            'maintenance' => $this->safe(function () use ($root) {
                return is_file($root . '/var/.maintenance.flag');
            }, null),
            'invalidated_caches' => $this->safe(function () {
                $codes = [];
                foreach ($this->cacheTypeList->getInvalidated() as $type) {
                    $codes[] = (string)$type->getId();
                }
                return $codes;
            }, null),
            // Magento fails in confusing, unrelated-looking ways when var/ can
            // no longer be written to, and nothing in the storefront says so.
            'disk' => $this->safe(function () use ($root) {
                $free = $this->quiet(fn() => disk_free_space($root));
                $total = $this->quiet(fn() => disk_total_space($root));
                if ($free === false || $total === false || !$total) {
                    return null;
                }
                return [
                    'free_bytes' => (int)$free,
                    'total_bytes' => (int)$total,
                    'used_percent' => (int)round(100 * (1 - $free / $total)),
                ];
            }, null),
        ];
    }

    /**
     * @return mixed[]
     */
    private function magento(): array
    {
        try {
            $mode = $this->appState->getMode();
        } catch (\Throwable $e) {
            $mode = 'unknown';
        }

        return [
            'edition' => $this->productMetadata->getEdition(),
            'version' => $this->productMetadata->getVersion(),
            'mode' => $mode,
            'php_version' => PHP_VERSION,
            'base_url' => $this->safe(function () {
                return $this->storeManager->getStore()->getBaseUrl();
            }),
            'currency' => $this->safe(
                function () {
                    return $this->storeManager->getStore()->getCurrentCurrencyCode();
                }
            ),
            'locale' => (string)$this->config->getValue('general/locale/code'),
            'timezone' => (string)$this->config->getValue('general/locale/timezone'),
        ];
    }

    /**
     * Third-party modules only. Core module names are noise; what matters when a
     * checkout breaks is which extensions are in play.
     *
     * @return mixed[]
     */
    private function modules(): array
    {
        $thirdParty = [];
        $coreCount = 0;

        foreach ($this->moduleList->getAll() as $name => $module) {
            if (strpos($name, 'Magento_') === 0) {
                $coreCount++;
                continue;
            }
            $thirdParty[] = [
                'name' => $name,
                'version' => $module['setup_version'] ?? null,
            ];
        }

        return [
            'core_count' => $coreCount,
            'third_party_count' => count($thirdParty),
            'third_party' => $thirdParty,
        ];
    }

    /**
     * @return mixed[]
     */
    private function paymentMethods(): array
    {
        $methods = [];
        foreach ($this->paymentConfig->getActiveMethods() as $code => $method) {
            $methods[] = [
                'code' => $code,
                'title' => (string)$this->config->getValue("payment/{$code}/title"),
                'active' => true,
            ];
        }
        return $methods;
    }

    /**
     * @return mixed[]
     */
    private function shippingMethods(): array
    {
        $methods = [];
        foreach ($this->shippingConfig->getActiveCarriers() as $code => $carrier) {
            $methods[] = [
                'code' => $code,
                'title' => (string)$this->config->getValue("carriers/{$code}/title"),
                'active' => true,
            ];
        }
        return $methods;
    }

    /**
     * Cron health. A store whose cron stopped will fail in ways that look random
     * from the outside — stale prices, unsent emails, queues that never drain.
     *
     * @return mixed[]
     */
    private function cron(): array
    {
        return $this->safe(function () {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('cron_schedule');

            $select = $connection->select()
                ->from($table, ['status', 'count' => 'COUNT(*)', 'last' => 'MAX(executed_at)'])
                ->where('scheduled_at > ?', gmdate('Y-m-d H:i:s', time() - 86400))
                ->group('status');

            $rows = $connection->fetchAll($select);
            $byStatus = [];
            foreach ($rows as $row) {
                $byStatus[$row['status']] = [
                    'count' => (int)$row['count'],
                    'last' => $row['last'],
                ];
            }

            $lastSuccess = $connection->fetchOne(
                $connection->select()
                    ->from($table, 'MAX(executed_at)')
                    ->where('status = ?', 'success')
            );

            // "1 failure" is not actionable; the job code is. Cheap, because
            // the table is already open.
            $failing = [];
            $jobRows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['job_code', 'count' => 'COUNT(*)', 'message' => 'MAX(messages)'])
                    ->where('scheduled_at > ?', gmdate('Y-m-d H:i:s', time() - 86400))
                    ->where('status IN (?)', ['error', 'missed'])
                    ->group('job_code')
                    ->order('count DESC')
                    ->limit(10)
            );
            foreach ($jobRows as $jobRow) {
                $failing[] = [
                    'job_code' => (string)$jobRow['job_code'],
                    'count' => (int)$jobRow['count'],
                    'message' => $jobRow['message'] === null
                        ? null
                        : substr((string)$jobRow['message'], 0, 200),
                ];
            }

            return [
                'failing_jobs' => $failing,
                'last_success_at' => $lastSuccess ?: null,
                'minutes_since_success' => $lastSuccess
                    ? (int)round((time() - strtotime((string)$lastSuccess)) / 60)
                    : null,
                'last_24h' => $byStatus,
            ];
        }, ['error' => 'cron_schedule unavailable']);
    }

    /**
     * Indexer health. A stuck or invalidated indexer means stale search results,
     * wrong prices, broken category navigation — all of which break checkout in
     * ways that look like the store is up but the cart is broken.
     *
     * @return mixed[]
     */
    private function indexers(): array
    {
        return $this->safe(function () {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('indexer_state');

            // indexer_state carries the code as `indexer_id` and the timestamp
            // as `updated` — there is no `indexer_code` or `updated_at` column.
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['indexer_id', 'status', 'updated'])
                    ->order('indexer_id ASC')
            );

            $indexers = [];
            $stale = 0;
            foreach ($rows as $row) {
                $status = (string)$row['status'];
                if ($status === 'invalid') {
                    $stale++;
                }
                $indexers[] = [
                    'code' => (string)$row['indexer_id'],
                    'status' => $status,
                    'updated_at' => $row['updated'] ?? null,
                ];
            }

            return [
                'total' => count($indexers),
                'stale' => $stale,
                'valid' => count($indexers) - $stale,
                'items' => $indexers,
            ];
        }, ['error' => 'indexer_state unavailable']);
    }

    /**
     * @return mixed[]
     */
    private function queue(): array
    {
        return $this->safe(function () {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('queue_message_status');

            $rows = $connection->fetchAll(
                $connection->select()->from($table, ['status', 'count' => 'COUNT(*)'])->group('status')
            );

            $byStatus = [];
            foreach ($rows as $row) {
                $byStatus[(string)$row['status']] = (int)$row['count'];
            }
            return ['by_status' => $byStatus];
        }, ['error' => 'message queue tables unavailable']);
    }

    /**
     * Recent exception log lines. Truncated, and only the tail — this is a hint
     * for correlation, not a log shipping service.
     *
     * @return mixed[]
     */
    private function recentErrors(): array
    {
        return $this->safe(function () {
            $dir = $this->deploymentConfig->get('directories/document_root') ?: BP;
            $entries = [];

            foreach (['var/log/exception.log', 'var/log/system.log'] as $relative) {
                $path = rtrim((string)$dir, '/') . '/' . $relative;
                if (!is_readable($path)) {
                    continue;
                }
                $lines = $this->tail($path, 20);
                $entries[] = [
                    'file' => $relative,
                    'size_bytes' => filesize($path) ?: 0,
                    'modified_at' => gmdate('c', (int)filemtime($path)),
                    'tail' => array_map(
                        static function ($line) {
                            return substr($line, 0, 500);
                        },
                        $lines
                    ),
                ];
            }
            return $entries;
        }, []);
    }

    /**
     * Fatal errors Magento recorded, as counts and nothing else.
     *
     * `var/reports` holds a full stack trace per fatal: absolute paths, SQL,
     * request URLs, and in a checkout crash the customer's own data. None of
     * that leaves the store. The class name is read out of each file and the
     * rest is discarded; what travels is how many, of what type, and when.
     *
     * This is stricter than recentErrors() above, which does ship a log tail.
     * A log line is one message; a report file is the whole call stack with
     * its arguments, and counts already answer what the card asks.
     *
     * The 200-file cap is not tidiness. A neglected store accumulates tens of
     * thousands of these, and this runs inside a monitoring check on a
     * merchant's production server. Hitting the cap is reported rather than
     * hidden: a "200" that is really 40,000 would read as a bad week instead
     * of an emergency.
     *
     * @return mixed[]|null
     */
    private function crashReports()
    {
        return $this->safe(function () {
            $root = rtrim((string)($this->deploymentConfig->get('directories/document_root') ?: BP), '/');
            $dir = $root . '/var/reports';
            // Magento creates var/reports lazily, on the first fatal. A store
            // that has never crashed therefore has no directory at all, and
            // reporting that as "unknown" would hide the card on exactly the
            // healthy stores whose zero is worth seeing. Missing is zero;
            // present-but-unreadable is genuinely unknown.
            if (!is_dir($dir)) {
                return [
                    'window_hours' => 24,
                    'total' => 0,
                    'truncated' => false,
                    'by_type' => [],
                    'newest_at' => null,
                    'oldest_at' => null,
                ];
            }
            if (!is_readable($dir)) {
                return null;
            }

            $cutoff = time() - 86400;
            $files = [];
            $names = $this->quiet(fn() => scandir($dir));
            if ($names === false) {
                return null;
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir . '/' . $name;
                if (!is_file($path)) {
                    continue;
                }
                $mtime = $this->quiet(fn() => filemtime($path));
                if ($mtime === false || $mtime < $cutoff) {
                    continue;
                }
                $files[] = ['path' => $path, 'mtime' => (int)$mtime];
            }

            usort($files, static function ($a, $b) {
                return $b['mtime'] - $a['mtime'];
            });

            $truncated = count($files) > self::CRASH_REPORT_LIMIT;
            $files = array_slice($files, 0, self::CRASH_REPORT_LIMIT);

            $counts = [];
            foreach ($files as $file) {
                $type = $this->crashReportType($file['path']);
                $counts[$type] = isset($counts[$type]) ? $counts[$type] + 1 : 1;
            }
            arsort($counts);

            $byType = [];
            foreach ($counts as $type => $count) {
                $byType[] = ['type' => (string)$type, 'count' => (int)$count];
            }

            return [
                'window_hours' => 24,
                'total' => count($files),
                'truncated' => $truncated,
                'by_type' => $byType,
                'newest_at' => $files ? gmdate('c', $files[0]['mtime']) : null,
                'oldest_at' => $files ? gmdate('c', $files[count($files) - 1]['mtime']) : null,
            ];
        }, null);
    }

    /**
     * The exception class a report was written for.
     *
     * Magento 2.3+ writes JSON, older versions a serialised array, and themes
     * and third-party error handlers write neither. "unknown" is a real bucket
     * rather than a reason to drop the file: the count is the load-bearing
     * number, and a report we could not parse still happened.
     */
    private function crashReportType(string $path): string
    {
        $head = (string)$this->quiet(fn() => file_get_contents($path, false, null, 0, 4096));
        if ($head === '') {
            return 'unknown';
        }

        $decoded = json_decode($head, true);
        if (is_array($decoded)) {
            foreach (['0', 'message'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    $head = $decoded[$key];
                    break;
                }
            }
        }

        // A fully-qualified class name followed by a colon: the shape every
        // format above puts at the head of the message.
        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)\s*:/', $head, $m)) {
            return $m[1];
        }
        return 'unknown';
    }

    /**
     * Whether the store's search engine is answering.
     *
     * A checkout can pass every assertion while Elasticsearch is red: category
     * pages degrade, search returns nothing, and the storefront gives no sign
     * that anything is wrong.
     *
     * `reachable: false` is the finding, not an error — that is the whole
     * point of the block. The two-second timeout is not optional: a hung
     * search host would otherwise hang a monitoring check on a merchant's
     * production server. The host comes from the store's own configuration and
     * never from anything MageSentinel supplies, so this is not a request we can
     * be pointed at.
     *
     * @return mixed[]|null
     */
    private function search()
    {
        return $this->safe(function () {
            $engine = (string)$this->config->getValue('catalog/search/engine');
            if ($engine === '') {
                return null;
            }

            $host = (string)$this->config->getValue('catalog/search/' . $engine . '_server_hostname');
            $port = (string)$this->config->getValue('catalog/search/' . $engine . '_server_port');
            $prefix = (string)$this->config->getValue('catalog/search/' . $engine . '_index_prefix');

            $result = [
                'engine' => $engine,
                'host' => $host === '' ? null : ($port === '' ? $host : $host . ':' . $port),
                'reachable' => false,
                'status' => null,
                'version' => null,
                'index_prefix' => $prefix === '' ? null : $prefix,
            ];
            if ($host === '') {
                return $result;
            }

            $base = preg_match('#^https?://#', $host) ? $host : 'http://' . $host;
            if ($port !== '') {
                $base .= ':' . $port;
            }

            // ignore_errors so a 4xx/5xx still returns a body: an engine that
            // answers with an error is reachable, and saying otherwise would
            // point the merchant at the network instead of the cluster.
            $context = stream_context_create([
                'http' => ['timeout' => 2, 'ignore_errors' => true],
            ]);

            $health = $this->quiet(fn() => file_get_contents($base . '/_cluster/health', false, $context));
            if ($health === false) {
                return $result;
            }
            $result['reachable'] = true;

            $decoded = json_decode((string)$health, true);
            if (is_array($decoded) && isset($decoded['status'])) {
                $result['status'] = (string)$decoded['status'];
            }

            $root = $this->quiet(fn() => file_get_contents($base . '/', false, $context));
            $rootDecoded = is_string($root) ? json_decode($root, true) : null;
            if (is_array($rootDecoded) && isset($rootDecoded['version']['number'])) {
                $result['version'] = (string)$rootDecoded['version']['number'];
            }

            return $result;
        }, null);
    }

    /**
     * How the full-page cache is set up, and how well it works where that can
     * honestly be measured.
     *
     * The configuration is the reliable finding: a full-page cache writing to
     * the filesystem is one of the commonest hidden causes of a slow checkout,
     * and it cannot be seen from outside.
     *
     * @return mixed[]|null
     */
    private function pageCache()
    {
        return $this->safe(function () {
            $application = $this->config->getValue('system/full_page_cache/caching_application');
            $ttl = $this->config->getValue('system/full_page_cache/ttl');
            // Most stores never give the page cache its own backend entry, and
            // Magento then serves it from the default one. Reading only the
            // page_cache key reported "not configured" on a store whose page
            // cache was working fine — the answer to "what is the page cache
            // stored in" is the default backend in that case, not nothing.
            $backend = (string)$this->deploymentConfig->get('cache/frontend/page_cache/backend');
            if ($backend === '') {
                $backend = (string)$this->deploymentConfig->get('cache/frontend/default/backend');
            }

            $enabled = null;
            foreach ($this->cacheTypeList->getTypes() as $type) {
                if ((string)$type->getId() === 'full_page') {
                    $enabled = (bool)$type->getStatus();
                }
            }

            return [
                'application' => $application === null
                    ? null
                    : ((int)$application === 2 ? 'varnish' : 'builtin'),
                'backend' => $backend === '' ? null : $backend,
                'ttl_seconds' => $ttl === null ? null : (int)$ttl,
                'enabled' => $enabled,
                'hit_rate' => $this->pageCacheHitRate($backend),
            ];
        }, null);
    }

    /**
     * Redis keyspace hits and misses for the page cache's own database, or
     * null when the setup cannot answer the question.
     *
     * Magento exposes no full-page-cache hit rate. Under Varnish it needs
     * varnishstat, which PHP cannot reach; on the filesystem the counter does
     * not exist. Redis has one, but per instance — so this is emitted only
     * when the page cache has a database index of its own, where the number
     * describes the page cache rather than whatever else shares the server.
     *
     * Everywhere else the field is null. It is never estimated, and the caller
     * must not render its absence as a zero: "we cannot measure this" and
     * "nothing is being cached" are opposite findings.
     *
     * @return mixed[]|null
     */
    private function pageCacheHitRate(string $backend)
    {
        return $this->safe(function () use ($backend) {
            if (stripos($backend, 'redis') === false) {
                return null;
            }
            $options = $this->deploymentConfig->get('cache/frontend/page_cache/backend_options');
            if (!is_array($options) || !isset($options['database'])) {
                return null;
            }
            if (!class_exists('\Credis_Client')) {
                return null;
            }

            $client = new \Credis_Client(
                isset($options['server']) ? (string)$options['server'] : '127.0.0.1',
                isset($options['port']) ? (int)$options['port'] : 6379,
                2
            );
            $info = $client->info('stats');
            $client->close();
            if (!is_array($info)) {
                return null;
            }

            $hits = isset($info['keyspace_hits']) ? (int)$info['keyspace_hits'] : null;
            $misses = isset($info['keyspace_misses']) ? (int)$info['keyspace_misses'] : null;
            if ($hits === null || $misses === null || ($hits + $misses) === 0) {
                return null;
            }

            return [
                'hits' => $hits,
                'misses' => $misses,
                'ratio' => round($hits / ($hits + $misses), 4),
            ];
        }, null);
    }

    /**
     * @return mixed[]
     */
    private function deployment(): array
    {
        return [
            'static_version' => $this->safe(
                function () {
                    return (string)$this->deploymentConfig->get('static_content_version');
                }
            ),
            'db_prefix' => $this->safe(
                function () {
                    return (string)$this->deploymentConfig->get('db/table_prefix');
                }
            ),
            'cache_backend' => $this->safe(
                function () {
                    return (string)$this->deploymentConfig->get('cache/frontend/default/backend');
                }
            ),
            'session_save' => $this->safe(function () {
                return (string)$this->deploymentConfig->get('session/save');
            }),
        ];
    }

    /**
     * @return string[]
     */
    private function tail(string $path, int $lines): array
    {
        $handle = $this->quiet(fn() => fopen($path, 'r'));
        if ($handle === false) {
            return [];
        }

        $buffer = [];
        while (($line = fgets($handle)) !== false) {
            $buffer[] = rtrim($line, "\r\n");
            if (count($buffer) > $lines) {
                array_shift($buffer);
            }
        }
        fclose($handle);

        return $buffer;
    }

    /**
     * Diagnostics must never break the endpoint: a store with an unusual setup
     * should still return everything else that worked.
     *
     * @param mixed $fallback
     * @return mixed
     */
    private function safe(callable $fn, $fallback = null)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Runs a filesystem or network call that reports failure by returning false,
     * without letting its PHP warning reach the error handler.
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
