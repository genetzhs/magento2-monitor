<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Plugin;

/**
 * Keeps every order taken by the hidden test method out of Sales > Orders, for good.
 */
class HideTestOrdersFromGrid
{
    /**
     * Before load.
     *
     * @param \Magento\Framework\Data\Collection\AbstractDb $subject
     * @param bool $printQuery
     * @param bool $logQuery
     * @return array
     */
    public function beforeLoad($subject, $printQuery = false, $logQuery = false): array
    {
        if (!$subject->isLoaded()) {
            $subject->addFieldToFilter(
                'main_table.payment_method',
                [['neq' => \MageSentinel\Monitor\Model\Payment\TestMethod::CODE], ['null' => true]]
            );
        }
        return [$printQuery, $logQuery];
    }
}
