<?php

namespace Payplus\PayplusGateway\Plugin\Cron;

use Magento\Cron\Model\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Plugin to conditionally include payplus_order_sync cron job based on configuration
 */
class ConfigPlugin
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Filter out payplus_order_sync cron job if it's disabled in configuration
     *
     * @param ConfigInterface $subject
     * @param array $result
     * @return array
     */
    public function afterGetJobs(ConfigInterface $subject, array $result)
    {
        // Check if the cron job is enabled
        $isEnabled = $this->scopeConfig->isSetFlag(
            'payment/payplus_gateway/orders_config/enable_order_sync_cron',
            ScopeInterface::SCOPE_STORE
        );

        // If disabled, remove the cron job from all groups
        if (!$isEnabled) {
            foreach ($result as $group => $jobs) {
                if (isset($jobs['payplus_order_sync'])) {
                    unset($result[$group]['payplus_order_sync']);
                    // If group becomes empty, we could remove it, but it's safer to leave it
                }
            }
        }

        return $result;
    }
}

