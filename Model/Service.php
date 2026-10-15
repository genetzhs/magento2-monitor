<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Talks to magesentinel.com, and only when an administrator presses a button:
 * "Test cart & checkout" on a connected store, or the free monthly test on one
 * that is not. The store is identified by its base URL and a SHA-256 hash of
 * its connector token; the token itself never leaves the store.
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

    /** The storefront address MageSentinel knows this store by. */
    public function site(): string
    {
        try {
            return (string)$this->storeManager->getDefaultStoreView()->getBaseUrl();
        } catch (\Throwable $e) {
            return (string)$this->config->getValue('web/unsecure/base_url');
        }
    }

    /** off: no connector; quiet: set up but MageSentinel has not read it for 3 hours; on. */
    public function connection(): string
    {
        if (!$this->config->isSetFlag('magesentinel/monitor/enabled') || $this->token() === '') {
            return 'off';
        }
        return time() - $this->state->lastSeen() < 3 * 3600 ? 'on' : 'quiet';
    }

    /** @return array{code:int, body:mixed} */
    public function startCheck(): array
    {
        return $this->connector('POST', '');
    }

    /** @return array{code:int, body:mixed} */
    public function checkStatus(string $jobId): array
    {
        return $this->connector('GET', '/' . rawurlencode($jobId));
    }

    /** @return array{code:int, body:mixed} */
    public function history(): array
    {
        return $this->connector('GET', '/history');
    }

    /**
     * The free monthly test. A one-off challenge is put behind the store's
     * own proof route for the length of the call, so MageSentinel can confirm
     * the request comes from this store and not from someone naming it.
     *
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

    /** @return array{code:int, body:mixed} */
    public function freeTestStatus(string $id): array
    {
        return $this->request('GET', '/api/audits/' . rawurlencode($id));
    }

    private function token(): string
    {
        $stored = (string)$this->config->getValue('magesentinel/monitor/token');
        return $stored === '' ? '' : (string)$this->encryptor->decrypt($stored);
    }

    /** @return array{code:int, body:mixed} */
    private function connector(string $method, string $path): array
    {
        return $this->request($method, '/api/connector/checks' . $path, [
            'X-MageSentinel-Key' => hash('sha256', $this->token()),
            'X-MageSentinel-Site' => $this->site(),
        ]);
    }

    /**
     * @param array<string, string> $headers
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
