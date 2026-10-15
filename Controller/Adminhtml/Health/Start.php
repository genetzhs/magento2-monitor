<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Controller\Adminhtml\Health;

use MageSentinel\Monitor\Model\Mailer;
use MageSentinel\Monitor\Model\Service;
use MageSentinel\Monitor\Model\State;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Locale\ResolverInterface;

/**
 * "Test cart & checkout". A connected store asks MageSentinel to run its
 * checkout monitor now; a store that is not connected uses its free monthly
 * test instead, which walks the checkout without placing an order and emails
 * the result.
 */
class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageSentinel_Monitor::health';

    /** @var JsonFactory */
    private $jsonFactory;
    /** @var Service */
    private $service;
    /** @var State */
    private $state;
    /** @var Mailer */
    private $mailer;
    /** @var ResolverInterface */
    private $locale;

    public function __construct(
        Action\Context $context,
        JsonFactory $jsonFactory,
        Service $service,
        State $state,
        Mailer $mailer,
        ResolverInterface $locale
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->service = $service;
        $this->state = $state;
        $this->mailer = $mailer;
        $this->locale = $locale;
    }

    public function execute()
    {
        $json = $this->jsonFactory->create();
        if ($this->service->connection() === 'off') {
            return $json->setData($this->free());
        }
        $r = $this->service->startCheck();
        if ($r['code'] === 202 && !empty($r['body']['data']['jobId'])) {
            return $json->setData(['ok' => true, 'jobId' => (string)$r['body']['data']['jobId']]);
        }
        $messages = [
            401 => __('MageSentinel did not recognise this store. Check the token under Settings.'),
            409 => __('This store has no active checkout monitor in MageSentinel.'),
            429 => __('A test purchase was just run. Try again in a couple of minutes.'),
        ];
        return $json->setData(['ok' => false, 'message' => (string)($messages[$r['code']] ?? __('Could not reach MageSentinel. Try again.'))]);
    }

    /** @return array<string, mixed> */
    private function free(): array
    {
        $r = $this->service->startFreeTest($this->mailer->recipient(), (string)$this->locale->getLocale());
        $body = is_array($r['body']) ? $r['body'] : [];
        if ($r['code'] === 202 && !empty($body['data']['id'])) {
            $this->state->saveFreeTest([
                'id' => (string)$body['data']['id'],
                'at' => time(),
                'next' => (int)strtotime((string)($body['data']['nextAt'] ?? '')),
            ]);
            return ['ok' => true, 'jobId' => 'audit:' . $body['data']['id']];
        }
        if ($r['code'] === 429 && ($body['reason'] ?? '') === 'month') {
            $next = (int)strtotime((string)($body['nextAt'] ?? ''));
            if (!empty($body['id'])) {
                $this->state->saveFreeTest(['id' => (string)$body['id'], 'at' => 0, 'next' => $next]);
            }
            return ['ok' => false, 'message' => (string)__('This month\'s free test has been used. The next one is on %1.', $next ? date('Y-m-d', $next) : '-')];
        }
        $messages = [
            400 => __('MageSentinel could not use this store address or email. Check the alert email under Settings.'),
            403 => __('MageSentinel could not reach this store from the internet to confirm the request.'),
            429 => __('Too many tests right now. Try again later.'),
        ];
        return ['ok' => false, 'message' => (string)($messages[$r['code']] ?? __('Could not reach MageSentinel. Try again.'))];
    }
}
