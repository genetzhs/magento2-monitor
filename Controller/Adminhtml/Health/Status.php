<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Controller\Adminhtml\Health;

use MageSentinel\Monitor\Model\Service;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Locale\ResolverInterface;

/**
 * Progress of a test purchase, in one shape for both kinds: a connected
 * store's check and the free test (an audit).
 */
class Status extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageSentinel_Monitor::health';

    /** @var JsonFactory */
    private $jsonFactory;
    /** @var Service */
    private $service;
    /** @var ResolverInterface */
    private $locale;

    public function __construct(Action\Context $context, JsonFactory $jsonFactory, Service $service, ResolverInterface $locale)
    {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->service = $service;
        $this->locale = $locale;
    }

    public function execute()
    {
        $json = $this->jsonFactory->create();
        $job = (string)preg_replace('/[^A-Za-z0-9:_-]/', '', (string)$this->getRequest()->getParam('job'));
        $fail = ['ok' => false, 'message' => (string)__('Could not reach MageSentinel. Try again.')];
        if (strpos($job, 'audit:') === 0) {
            $data = $this->audit(substr($job, 6));
            return $json->setData($data ?? $fail);
        }
        $r = $this->service->checkStatus($job);
        if ($r['code'] !== 200 || empty($r['body']['data'])) {
            return $json->setData($fail);
        }
        return $json->setData(['ok' => true] + (array)$r['body']['data']);
    }

    /** @return array<string, mixed>|null */
    private function audit(string $id): ?array
    {
        $r = $this->service->freeTestStatus($id);
        $d = $r['code'] === 200 ? ($r['body']['data'] ?? null) : null;
        if (!is_array($d)) {
            return null;
        }
        $state = ['queued' => 'waiting', 'running' => 'active', 'done' => 'completed', 'error' => 'failed'][$d['status'] ?? ''] ?? 'waiting';
        $map = ['passed' => 'passed', 'failed' => 'failed', 'skipped' => 'skipped'];
        $progress = null;
        $result = null;
        if ($state === 'active' && is_array($d['progress'] ?? null)) {
            $p = $d['progress'];
            $names = array_values(array_map('strval', (array)($p['names'] ?? [])));
            $statuses = [];
            $durations = [];
            foreach ((array)($p['done'] ?? []) as $step) {
                $statuses[] = $map[$step['status'] ?? ''] ?? 'passed';
                $durations[] = (int)($step['durationMs'] ?? 0);
            }
            $index = count($statuses);
            $current = (string)($p['current']['name'] ?? ($names[$index] ?? ''));
            if ($current !== '') {
                $progress = ['step' => $current, 'index' => $index, 'total' => max(count($names), $index + 1),
                    'phase' => 'running', 'statuses' => $statuses, 'names' => $names, 'durations' => $durations];
            }
        }
        if ($state === 'completed' && is_array($d['result'] ?? null)) {
            $steps = array_values(array_filter((array)($d['result']['steps'] ?? []), static function ($x) {
                return strpos((string)($x['name'] ?? ''), 'order') !== 0;
            }));
            $names = array_map(static function ($x) {
                return (string)$x['name'];
            }, $steps);
            if ($names) {
                $progress = ['step' => end($names), 'index' => count($names) - 1, 'total' => count($names), 'phase' => 'done',
                    'statuses' => array_map(static function ($x) use ($map) {
                        return $map[$x['status'] ?? ''] ?? 'pending';
                    }, $steps),
                    'names' => $names,
                    'durations' => array_map(static function ($x) {
                        return (int)($x['durationMs'] ?? 0);
                    }, $steps)];
            }
            $el = strpos((string)$this->locale->getLocale(), 'el') === 0;
            $result = [
                'status' => ['pass' => 'PASS', 'fail' => 'FAIL'][$d['result']['verdict'] ?? ''] ?? 'UNKNOWN',
                'url' => Service::BASE . ($el ? '/el' : '') . '/audit/' . rawurlencode($id),
            ];
        }
        return ['ok' => true, 'state' => $state, 'progress' => $progress, 'result' => $result];
    }
}
