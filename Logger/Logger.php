<?php
namespace Payplus\PayplusGateway\Logger;

class Logger extends \Monolog\Logger
{
    public function debugOrder($stage, $data)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $config = $objectManager->create(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $debugOrder = (bool)$config->getValue(
            'payment/payplus_gateway/orders_config/debug_payplus_orders',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if ($debugOrder === true) {
            $this->info($stage, $data);
        }
    }
}
