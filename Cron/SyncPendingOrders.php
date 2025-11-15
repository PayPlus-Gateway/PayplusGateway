<?php

namespace Payplus\PayplusGateway\Cron;

use Payplus\PayplusGateway\Model\Service\OrderSyncService;
use Payplus\PayplusGateway\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class SyncPendingOrders
{
    protected $orderSyncService;
    protected $logger;
    protected $scopeConfig;

    public function __construct(
        OrderSyncService $orderSyncService,
        Logger $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->orderSyncService = $orderSyncService;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Execute cron job
     */
    public function execute()
    {
        // Check if cron is enabled
        $isEnabled = $this->scopeConfig->isSetFlag(
            'payment/payplus_gateway/orders_config/enable_order_sync_cron',
            ScopeInterface::SCOPE_STORE
        );

        if (!$isEnabled) {
            $this->logger->debugOrder('OrderSync Cron: Disabled in configuration', []);
            return;
        }

        $this->logger->debugOrder('OrderSync Cron: Starting sync process', []);
        
        try {
            $report = $this->orderSyncService->syncTodayOrders();
            
            $this->logger->debugOrder('OrderSync Cron: Completed', [
                'total_checked' => $report['total_checked'],
                'successful' => $report['successful'],
                'failed' => $report['failed'],
                'skipped' => $report['skipped']
            ]);
        } catch (\Exception $e) {
            $this->logger->debugOrder('OrderSync Cron: Error', ['error' => $e->getMessage()]);
        }
    }
}

