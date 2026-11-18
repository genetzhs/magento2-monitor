<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Talks to magesentinel.com when an administrator acts, or after a deploy on a connected store
 * (DeployWatch asks for one checkout check). Administrator actions: pressing
 * "Test cart & checkout" on a connected store or the free monthly test on one
 * that is not, or opening the health page of a connected store (its checkout
 * speed and, at most hourly, the MageSentinel plan the store is on). The store
 * is identified by its base URL and a SHA-256 hash of its connector token; the token itself never leaves the store.
 */
class Service
{
    public const BASE = 'https://magesentinel.com';

    /** @var ScopeConfigInterface */
    private $config;
    /** @var EncryptorInterface */
    private $encryptor;
    /** @var CurlFactory */
    private $curlFactory;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var State */
    private $state;
    /** @var Random */
    private $random;

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $config
     * @param EncryptorInterface $encryptor
     * @param CurlFactory $curlFactory
     * @param StoreManagerInterface $storeManager
     * @param State $state
     * @param Random $random
     */
    public function __construct(
        ScopeConfigInterface $config,
        EncryptorInterface $encryptor,
        CurlFactory $curlFactory,
        StoreManagerInterface $storeManager,
        State $state,
        Random $random
    ) {
        $this->config = $config;
        $this->encryptor = $encryptor;
        $this->curlFactory = $curlFactory;
        $this->storeManager = $storeManager;
        $this->state = $state;
        $this->random = $random;
    }

    /**
     *  The storefront address MageSentinel knows this store by.
     *
     * @return string
     */
    public function site(): string
    {
        try {
            return (string)$this->storeManager->getDefaultStoreView()->getBaseUrl();
        } catch (\Throwable $e) {
            return (string)$this->config->getValue('web/unsecure/base_url');
        }
    }

    /**
     * Connector state: off, quiet (set up but not read by MageSentinel for 3 hours) or on.
     *
     * @return string
     */
    public function connection(): string
    {
        if (!$this->config->isSetFlag('magesentinel/monitor/enabled') || $this->token() === '') {
            return 'off';
        }
        return time() - $this->state->lastSeen() < 3 * 3600 ? 'on' : 'quiet';
    }

    /**
     * Start a test purchase on a connected store.
     *
     * @param string $reason '' when an administrator pressed the button, 'deploy' after a deploy
     * @param string $detail
     * @return array{code:int, body:mixed}
     */
    public function startCheck(string $reason = '', string $detail = ''): array
    {
        $body = $reason === '' ? '' : (string)json_encode(['reason' => $reason, 'detail' => $detail]);
        return $this->connector('POST', '', $body);
    }

    /**
     * Progress of a test purchase on a connected store.
     *
     * @param string $jobId
     * @return array{code:int, body:mixed}
     */
    public function checkStatus(string $jobId): array
    {
        return $this->connector('GET', '/' . rawurlencode($jobId));
    }

    /**
     * The plan the store's MageSentinel workspace is on.
     *
     * On Free the connector is not called any more, so "not checking in" is expected, not a fault.
     *
     * @return array{plan?:string, active?:bool, connector?:bool}|null
     */
    public function planStatus(): ?array
    {
        $r = $this->request('GET', '/api/connector/status', [
            'X-MageSentinel-Key' => hash('sha256', $this->token()),
            'X-MageSentinel-Site' => $this->site(),
        ]);
        $data = is_array($r['body']) ? ($r['body']['data'] ?? null) : null;
        return $r['code'] === 200 && is_array($data) ? $data : null;
    }

    /**
     * MageSentinel's recent test purchases for this store.
     *
     * @return array{code:int, body:mixed}
     */
    public function history(): array
    {
        return $this->connector('GET', '/history');
    }

    /**
     * The free monthly test.
     *
     * A one-off challenge is put behind the store's own proof route for the length of the call, so MageSentinel
     * can confirm the request comes from this store and not from someone naming it.
     *
     * @param string $email
     * @param string $locale
     * @return array{code:int, body:mixed}
     */
    public function startFreeTest(string $email, string $locale): array
    {
        $challenge = $this->random->getRandomString(40);
        $this->state->setProof($challenge);
        try {
            return $this->request('POST', '/api/audits/plugin', [
                'Content-Type' => 'application/json',
            ], (string)json_encode([
                'site' => $this->site(),
                'email' => $email,
                'locale' => strpos($locale, 'el') === 0 ? 'el' : 'en',
                'challenge' => $challenge,
                'platform' => 'magento',
            ]));
        } finally {
            $this->state->clearProof();
        }
    }

    /**
     * Progress of a free test purchase.
     *
     * @param string $id
     * @return array{code:int, body:mixed}
     */
    public function freeTestStatus(string $id): array
    {
        return $this->request('GET', '/api/audits/' . rawurlencode($id));
    }

    /**
     * The connector token.
     *
     * @return string
     */
    private function token(): string
    {
        $stored = (string)$this->config->getValue('magesentinel/monitor/token');
        return $stored === '' ? '' : (string)$this->encryptor->decrypt($stored);
    }

    /**
     * Start a test purchase on a connected store.
     *
     * @param string $method
     * @param string $path
     * @param string $body JSON, or '' for none
     * @return array{code:int, body:mixed}
     */
    private function connector(string $method, string $path, string $body = ''): array
    {
        $headers = [
            'X-MageSentinel-Key' => hash('sha256', $this->token()),
            'X-MageSentinel-Site' => $this->site(),
        ];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }
        return $this->request($method, '/api/connector/checks' . $path, $headers, $body);
    }

    /**
     * Call magesentinel.com.
     *
     * @param string $method
     * @param string $path
     * @param array $headers
     * @param string $body
     * @return array{code:int, body:mixed}
     */
    private function request(string $method, string $path, array $headers = [], string $body = ''): array
    {
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(30);
            $curl->setHeaders($headers + ['Accept' => 'application/json']);
            if ($method === 'POST') {
                $curl->post(self::BASE . $path, $body);
            } else {
                $curl->get(self::BASE . $path);
            }
            return ['code' => (int)$curl->getStatus(), 'body' => json_decode((string)$curl->getBody(), true)];
        } catch (\Throwable $e) {
            return ['code' => 0, 'body' => null];
        }
    }
}
