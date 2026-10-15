<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;

/**
 * Guards the diagnostics endpoint.
 *
 * The module is disabled by default and refuses every request until an
 * administrator both enables it and sets a token. Installing the module must not,
 * by itself, expose anything.
 */
class Access
{
    private const XML_ENABLED = 'magesentinel/monitor/enabled';
    private const XML_TOKEN = 'magesentinel/monitor/token';
    private const XML_ALLOW_CANCEL = 'magesentinel/monitor/allow_cancel';
    private const XML_ALLOW_CAPTCHA_SKIP = 'magesentinel/monitor/allow_captcha_skip';
    private const HEADER = 'X-MageSentinel-Token';

    /** @var ScopeConfigInterface */
    private $config;
    /** @var EncryptorInterface */
    private $encryptor;
    /** @var Http */
    private $request;
    /** @var bool|null */
    private $trustedCheck;

    public function __construct(
        ScopeConfigInterface $config,
        EncryptorInterface $encryptor,
        Http $request
    ) {
        $this->config = $config;
        $this->encryptor = $encryptor;
        $this->request = $request;
    }

    /**
     * @throws AuthorizationException
     */
    public function assertAllowed(): void
    {
        if (!$this->config->isSetFlag(self::XML_ENABLED)) {
            throw new AuthorizationException(new Phrase('MageSentinel diagnostics are disabled.'));
        }
        if (!$this->hasValidToken()) {
            throw new AuthorizationException(new Phrase('Invalid MageSentinel token.'));
        }
    }

    /**
     * Whether this request is one of MageSentinel's own checks that the
     * merchant chose to exempt from the store's CAPTCHA: the connector on, the
     * exemption on (a third switch, off by default), and the store's own token
     * in the header. Never throws — every other request is an ordinary visitor.
     */
    public function isTrustedCheck(): bool
    {
        if ($this->trustedCheck === null) {
            $this->trustedCheck = $this->config->isSetFlag(self::XML_ENABLED)
                && $this->config->isSetFlag(self::XML_ALLOW_CAPTCHA_SKIP)
                && $this->hasValidToken();
        }
        return $this->trustedCheck;
    }

    private function hasValidToken(): bool
    {
        $expected = (string)$this->config->getValue(self::XML_TOKEN);
        if ($expected !== '') {
            $expected = $this->encryptor->decrypt($expected);
        }

        $provided = (string)$this->request->getHeader(self::HEADER);

        // No token configured means no access: an empty shared secret would let
        // anyone read the store's internals.
        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * A second switch, independent of the first and off by default.
     *
     * Cancelling orders is the module's only write operation. Installing the
     * module, or turning on diagnostics, must never be enough to grant it — a
     * merchant who wants read-only insight should be able to have exactly that,
     * and the one who wants self-cleaning test orders should have to say so.
     *
     * @throws AuthorizationException
     */
    public function assertCancelAllowed(): void
    {
        if (!$this->config->isSetFlag(self::XML_ALLOW_CANCEL)) {
            throw new AuthorizationException(
                new Phrase('MageSentinel is not allowed to cancel orders on this store.')
            );
        }
    }
}
