<?php

namespace Payplus\PayplusGateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Payplus\PayplusGateway\Model\Service\OrderSyncService;
use Payplus\PayplusGateway\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class BeforeOrderCancel implements ObserverInterface
{
    protected $orderSyncService;
    protected $logger;
    protected $scopeConfig;
    const MAX_SYNC_ATTEMPTS = 5;
    const SYNC_COUNTER_PREFIX = 'PAYPLUS_SYNC_COUNT:';
    const CONFIG_PATH_ENABLE_SYNC_ON_CANCEL = 'payment/payplus_gateway/orders_config/enable_sync_on_cancel';

    public function __construct(
        OrderSyncService $orderSyncService,
        Logger $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->orderSyncService = $orderSyncService;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        
        if (!$order || !$order->getId()) {
            return;
        }

        // Check if feature is enabled
        $storeId = $order->getStoreId();
        if (!$this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_ENABLE_SYNC_ON_CANCEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        )) {
            $this->logger->debugOrder('OrderSync: Feature disabled in configuration', [
                'order_id' => $order->getIncrementId()
            ]);
            return;
        }

        // Check if order is from today
        $orderDate = date('Y-m-d', strtotime($order->getCreatedAt()));
        $today = date('Y-m-d');
        
        if ($orderDate !== $today) {
            $this->logger->debugOrder('OrderSync: Order not from today, skipping', [
                'order_id' => $order->getIncrementId(),
                'order_date' => $orderDate,
                'today' => $today
            ]);
            return;
        }

        // Check if status is changing to cancelled
        $newStatus = $order->getStatus();
        $newState = $order->getState();
        $oldStatus = $order->getOrigData('status');
        $oldState = $order->getOrigData('state');
        
        // Check if order is being set to canceled
        // Proceed if: new status/state is canceled AND (old is not canceled OR order has data changes)
        // The hasDataChanges check helps catch cases where origData might not be updated correctly after reload
        $hasDataChanges = $order->hasDataChanges();
        $isSetToCanceled = ($newStatus === 'canceled' && $newState === 'canceled');
        $wasNotCanceled = ($oldStatus !== 'canceled' || $oldState !== 'canceled');
        
        // If order is being set to canceled and either wasn't canceled before OR has data changes, proceed
        $isChangingToCanceled = $isSetToCanceled && ($wasNotCanceled || $hasDataChanges);
        
        $this->logger->debugOrder('OrderSync: Checking order status change', [
            'order_id' => $order->getIncrementId(),
            'new_status' => $newStatus,
            'new_state' => $newState,
            'old_status' => $oldStatus,
            'old_state' => $oldState,
            'is_changing_to_canceled' => $isChangingToCanceled,
            'has_data_changes' => $hasDataChanges,
            'is_set_to_canceled' => $isSetToCanceled,
            'was_not_canceled' => $wasNotCanceled
        ]);
        
        // Only proceed if status is changing TO canceled
        if (!$isChangingToCanceled) {
            $this->logger->debugOrder('OrderSync: Not changing to cancelled, skipping', [
                'order_id' => $order->getIncrementId(),
                'new_status' => $newStatus,
                'old_status' => $oldStatus,
                'is_changing_to_canceled' => $isChangingToCanceled
            ]);
            return;
        }

        // Check if this is a Payplus order
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== 'payplus_gateway') {
            $this->logger->debugOrder('OrderSync: Not a Payplus order, skipping', [
                'order_id' => $order->getIncrementId(),
                'payment_method' => $payment ? $payment->getMethod() : 'no payment'
            ]);
            return;
        }

        // Get current sync count
        $syncCount = $this->getSyncCount($order);

        // If we've already done 5 syncs, don't run again
        if ($syncCount >= self::MAX_SYNC_ATTEMPTS) {
            $this->logger->debugOrder('OrderSync: Max sync attempts reached, skipping', [
                'order_id' => $order->getIncrementId(),
                'sync_count' => $syncCount
            ]);
            return;
        }

        // Run sync for this specific order
        try {
            $this->logger->debugOrder('OrderSync: Running sync before cancel', [
                'order_id' => $order->getIncrementId(),
                'sync_count' => $syncCount + 1
            ]);

            // Store the status before sync to check if it changed
            $statusBeforeSync = $order->getStatus();
            $stateBeforeSync = $order->getState();
            
            $syncSuccess = $this->orderSyncService->syncSingleOrder($order);
            
            // If sync was successful, the order was saved with the new status (processing/complete)
            // But the current save operation will overwrite it with "cancelled"
            // We need to reload the order to get the status that sync set, then prevent the cancellation
            if ($syncSuccess) {
                // Reload the order to get the status that sync set
                $orderRepository = \Magento\Framework\App\ObjectManager::getInstance()
                    ->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
                $orderAfterSync = $orderRepository->get($order->getId());
                
                $statusAfterSync = $orderAfterSync->getStatus();
                $stateAfterSync = $orderAfterSync->getState();
                
                // If sync changed the status away from cancelled, prevent the cancellation
                if ($statusAfterSync !== 'canceled' && $stateAfterSync !== 'canceled') {
                    $this->logger->debugOrder('OrderSync: Sync successful, preventing cancellation', [
                        'order_id' => $order->getIncrementId(),
                        'status_before' => $statusBeforeSync,
                        'status_after' => $statusAfterSync,
                        'state_before' => $stateBeforeSync,
                        'state_after' => $stateAfterSync
                    ]);
                    
                    // Change the order status back to what sync set it to, preventing cancellation
                    $order->setStatus($statusAfterSync);
                    $order->setState($stateAfterSync);
                    
                    // Increment counter and return - order will be saved with the correct status
                    $this->incrementSyncCount($order);
                    return; // Exit early - order will be saved with correct status from sync
                }
            }

        } catch (\Exception $e) {
            $this->logger->debugOrder('OrderSync: Error during sync before cancel', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage()
            ]);
        } finally {
            // Increment sync count regardless of success/failure (unless we returned early)
            $this->incrementSyncCount($order);
        }

        // Let the status change proceed normally (only if sync didn't change the status)
    }

    /**
     * Get the current sync count for an order
     *
     * @param \Magento\Sales\Model\Order $order
     * @return int
     */
    protected function getSyncCount($order)
    {
        $history = $order->getStatusHistoryCollection();
        $maxCount = 0;

        foreach ($history as $comment) {
            $commentText = $comment->getComment();
            if ($commentText && strpos($commentText, self::SYNC_COUNTER_PREFIX) === 0) {
                $countStr = str_replace(self::SYNC_COUNTER_PREFIX, '', $commentText);
                $count = (int)$countStr;
                if ($count > $maxCount) {
                    $maxCount = $count;
                }
            }
        }

        return $maxCount;
    }

    /**
     * Increment the sync count for an order
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    protected function incrementSyncCount($order)
    {
        $currentCount = $this->getSyncCount($order);
        $newCount = $currentCount + 1;
        
        // Add a comment with the sync count (this will be saved with the order)
        $order->addCommentToStatusHistory(
            self::SYNC_COUNTER_PREFIX . $newCount,
            false,
            false
        );
    }
}

