<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Plugin;

use MageSentinel\Monitor\Helper\Access;
use MageSentinel\Monitor\Model\Payment\TestMethod;
use Magento\Payment\Helper\Data as PaymentHelper;

/**
 * Shows the hidden test method to MageSentinel's own checks, and to nobody else.
 */
class TestMethodAvailability
{
    /** @var Access */
    private $access;
    /** @var PaymentHelper */
    private $paymentHelper;

    /**
     * Constructor.
     *
     * @param Access $access
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(Access $access, PaymentHelper $paymentHelper)
    {
        $this->access = $access;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Magento\Payment\Model\MethodList::getAvailableMethods(): add the method for a trusted check, drop it otherwise.
     *
     * @param object $subject
     * @param array $result
     * @param mixed $quote
     * @return array
     */
    public function afterGetAvailableMethods($subject, array $result, $quote = null): array
    {
        $result = array_values(array_filter($result, static function ($m) {
            return $m->getCode() !== TestMethod::CODE;
        }));
        if ($this->access->isTestGatewayRequest()) {
            $method = $this->paymentHelper->getMethodInstance(TestMethod::CODE);
            if ($quote !== null && method_exists($quote, 'getStoreId')) {
                $method->setStore((int)$quote->getStoreId());
            }
            $result[] = $method;
        }
        return $result;
    }

    /**
     * TestMethod::isAvailable(): only for a trusted check.
     *
     * @param TestMethod $subject
     * @param bool $result
     * @return bool
     */
    public function afterIsAvailable(TestMethod $subject, $result): bool
    {
        return $this->access->isTestGatewayRequest();
    }

    /**
     * TestMethod::isActive(): only for a trusted check.
     *
     * @param TestMethod $subject
     * @param bool $result
     * @return bool
     */
    public function afterIsActive(TestMethod $subject, $result): bool
    {
        return $this->access->isTestGatewayRequest();
    }
}
