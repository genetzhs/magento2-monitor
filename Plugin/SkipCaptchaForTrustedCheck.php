<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Plugin;

use MageSentinel\Monitor\Helper\Access;

/**
 * The merchant's own CAPTCHA exemption for MageSentinel's checks.
 *
 * Off by default. With "Let MageSentinel's checks past your CAPTCHA" switched
 * on, a request carrying this store's connector token is answered as if the
 * CAPTCHA were off for it — reCAPTCHA (Magento_ReCaptcha*) and Magento's image
 * CAPTCHA (Magento_Captcha) alike. Every other visitor sees exactly what they
 * saw before. MageSentinel never solves or gets around a CAPTCHA; this is the
 * store saying "this request is mine".
 *
 * The subjects are not type-hinted: the ReCaptcha modules are often removed
 * from a store, and this class must still load when they are.
 */
class SkipCaptchaForTrustedCheck
{
    /** @var Access */
    private $access;

    public function __construct(Access $access)
    {
        $this->access = $access;
    }

    /**
     * Magento\ReCaptchaUi\Model\IsCaptchaEnabledInterface::isCaptchaEnabledFor()
     * — decides both whether the widget renders and whether a submit is verified.
     *
     * @param object $subject
     */
    public function afterIsCaptchaEnabledFor($subject, bool $result): bool
    {
        return $result && !$this->access->isTrustedCheck();
    }

    /**
     * Magento\Captcha\Model\DefaultModel::isRequired() — every image-CAPTCHA
     * form checks this before it asks for, or verifies, the typed word.
     *
     * @param object $subject
     * @param bool|mixed $result
     * @return bool|mixed
     */
    public function afterIsRequired($subject, $result)
    {
        return $result && $this->access->isTrustedCheck() ? false : $result;
    }
}
