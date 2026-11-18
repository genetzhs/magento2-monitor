<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * "MageSentinel test order": an offline method that takes no payment.
 *
 * Its config says active = 0, so it is absent from every list Magento builds
 * (checkout, admin order creation, payment config readers, other extensions).
 * Plugin\TestMethodAvailability switches it on only inside a request that
 * carries this store's connector token, with "Test orders through a hidden
 * payment method" turned on.
 */
class TestMethod extends AbstractMethod
{
    public const CODE = 'magesentinel_test';

    /** @var string */
    protected $_code = self::CODE;
    /** @var bool */
    protected $_isOffline = true;
    /** @var bool */
    protected $_canUseInternal = false;
    /** @var bool */
    protected $_canUseForMultishipping = false;
}
