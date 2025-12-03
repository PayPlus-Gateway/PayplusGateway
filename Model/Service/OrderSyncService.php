<?php

namespace Payplus\PayplusGateway\Model\Service;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Payplus\PayplusGateway\Model\Custom\APIConnector;
use Payplus\PayplusGateway\Model\Custom\OrderResponse;
use Payplus\PayplusGateway\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class OrderSyncService
{
    protected $orderCollectionFactory;
    protected $apiConnector;
    protected $logger;
    protected $scopeConfig;

    public function __construct(
        CollectionFactory $orderCollectionFactory,
        APIConnector $apiConnector,
        Logger $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->apiConnector = $apiConnector;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Sync pending/cancelled orders from today
     * 
     * @return array Report data
     */
    public function syncTodayOrders()
    {
        $report = [
            'total_checked' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'processed_orders' => []
        ];

        try {
            // Get today's date range
            $todayStart = date('Y-m-d 00:00:00');
            $todayEnd = date('Y-m-d 23:59:59');

            // Get orders collection for today with cancelled or pending_payment status
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('status', ['in' => ['canceled', 'pending_payment']])
                       ->addFieldToFilter('created_at', ['gteq' => $todayStart])
                       ->addFieldToFilter('created_at', ['lteq' => $todayEnd])
                       ->addFieldToFilter('state', ['in' => ['canceled', 'pending_payment', 'new']]);

            // Join with payment to filter only Payplus orders
            try {
                $collection->getSelect()
                    ->join(
                        ['payment' => $collection->getTable('sales_order_payment')],
                        'main_table.entity_id = payment.parent_id',
                        []
                    )
                    ->where('payment.method = ?', 'payplus_gateway');
            } catch (\Exception $e) {
                $this->logger->debugOrder('OrderSync: Error in collection join', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            $orders = $collection->getItems();

            // Log query details for debugging
            $this->logger->debugOrder('OrderSync: Query details', [
                'today_start' => $todayStart,
                'today_end' => $todayEnd,
                'orders_found' => count($orders),
                'sql' => (string)$collection->getSelect()
            ]);

            foreach ($orders as $order) {
                $report['total_checked']++;
                
                try {
                    $payment = $order->getPayment();
                    
                    // Only process Payplus orders
                    if (!$payment || $payment->getMethod() !== 'payplus_gateway') {
                        $report['skipped']++;
                        continue;
                    }

                    // Get payment_request_uid from additional_data
                    $paymentData = $payment->getData();
                    $paymentRequestUid = $paymentData['additional_data'] ?? null;

                    // Fallback: try to get from paymentPageResponse
                    if (!$paymentRequestUid) {
                        $additionalInfo = $payment->getAdditionalInformation();
                        if (isset($additionalInfo['paymentPageResponse']['page_request_uid'])) {
                            $paymentRequestUid = $additionalInfo['paymentPageResponse']['page_request_uid'];
                        }
                    }

                    if (!$paymentRequestUid) {
                        $report['skipped']++;
                        $report['errors'][] = [
                            'order_id' => $order->getIncrementId(),
                            'error' => 'No payment_request_uid found'
                        ];
                        continue;
                    }

                    // Call IPN check
                    $response = $this->apiConnector->checkTransactionAgainstIPN([
                        'payment_request_uid' => $paymentRequestUid
                    ]);

                    // Check if response is successful
                    if (isset($response['results']['status']) && 
                        $response['results']['status'] === 'success' &&
                        isset($response['results']['code']) && 
                        $response['results']['code'] == 0 &&
                        isset($response['data']) &&
                        isset($response['data']['status_code']) &&
                        $response['data']['status_code'] === '000') {
                        
                        // CRITICAL: Validate that the IPN response matches this order
                        // Check more_info (order increment ID) matches
                        $ipnMoreInfo = $response['data']['more_info'] ?? null;
                        $orderIncrementId = $order->getIncrementId();
                        
                        if ($ipnMoreInfo !== $orderIncrementId) {
                            $report['failed']++;
                            $report['errors'][] = [
                                'order_id' => $orderIncrementId,
                                'error' => 'IPN response more_info does not match order ID',
                                'ipn_more_info' => $ipnMoreInfo,
                                'order_increment_id' => $orderIncrementId,
                                'page_request_uid' => $paymentRequestUid
                            ];
                            $this->logger->debugOrder('OrderSync: IPN response more_info mismatch - possible duplicate page_request_uid', [
                                'order_id' => $orderIncrementId,
                                'ipn_more_info' => $ipnMoreInfo,
                                'page_request_uid' => $paymentRequestUid,
                                'message' => 'This indicates a duplicate page_request_uid issue - IPN response belongs to a different order'
                            ]);
                            continue;
                        }
                        
                        // Check amount matches (with tolerance for rounding differences)
                        $ipnAmount = isset($response['data']['amount']) ? (float)$response['data']['amount'] : null;
                        $orderAmount = (float)$order->getGrandTotal();
                        $amountDifference = abs($ipnAmount - $orderAmount);
                        
                        // Allow 0.01 tolerance for rounding differences
                        if ($ipnAmount === null || $amountDifference > 0.01) {
                            $report['failed']++;
                            $report['errors'][] = [
                                'order_id' => $orderIncrementId,
                                'error' => 'IPN response amount does not match order amount',
                                'ipn_amount' => $ipnAmount,
                                'order_amount' => $orderAmount,
                                'difference' => $amountDifference,
                                'page_request_uid' => $paymentRequestUid
                            ];
                            $this->logger->debugOrder('OrderSync: IPN response amount mismatch - possible duplicate page_request_uid', [
                                'order_id' => $orderIncrementId,
                                'ipn_amount' => $ipnAmount,
                                'order_amount' => $orderAmount,
                                'difference' => $amountDifference,
                                'page_request_uid' => $paymentRequestUid,
                                'message' => 'This indicates a duplicate page_request_uid issue - IPN response belongs to a different order'
                            ]);
                            continue;
                        }
                        
                        // Process the order like callback does
                        $orderResponse = new OrderResponse($order);
                        $orderResponse->processResponse($response['data'], true);
                        
                        // Add order note with IPN response details
                        $ipnNote = "=== OrderSync: Order moved to processing due to successful IPN check ===\n";
                        $ipnNote .= "Order Number: " . $orderIncrementId . "\n";
                        $ipnNote .= "IPN more_info: " . ($ipnMoreInfo ?? 'N/A') . "\n";
                        $ipnNote .= "Match: " . ($ipnMoreInfo === $orderIncrementId ? '✓ YES' : '✗ NO') . "\n";
                        $ipnNote .= "Transaction UID: " . ($response['data']['transaction_uid'] ?? 'N/A') . "\n";
                        $ipnNote .= "Status: " . ($response['data']['status'] ?? 'N/A') . " (" . ($response['data']['status_code'] ?? 'N/A') . ")\n";
                        $ipnNote .= "Amount: " . ($response['data']['amount'] ?? 'N/A') . " " . ($response['data']['currency'] ?? 'ILS') . "\n";
                        $ipnNote .= "Payment Method: " . ($response['data']['method'] ?? 'N/A') . "\n";
                        if (isset($response['data']['approval_num']) && !empty($response['data']['approval_num'])) {
                            $ipnNote .= "Approval Number: " . $response['data']['approval_num'] . "\n";
                        }
                        if (isset($response['data']['voucher_num']) && !empty($response['data']['voucher_num'])) {
                            $ipnNote .= "Voucher Number: " . $response['data']['voucher_num'] . "\n";
                        }
                        if (isset($response['data']['date']) && !empty($response['data']['date'])) {
                            $ipnNote .= "Transaction Date: " . $response['data']['date'] . "\n";
                        }
                        $ipnNote .= "Page Request UID: " . $paymentRequestUid . "\n";
                        $ipnNote .= "Synced at: " . date('Y-m-d H:i:s');
                        
                        $order->addStatusHistoryComment($ipnNote, false);
                        $order->save();
                        
                        $report['successful']++;
                        $report['processed_orders'][] = [
                            'order_id' => $order->getIncrementId(),
                            'status' => 'success',
                            'transaction_uid' => $response['data']['transaction_uid'] ?? null,
                            'amount' => $response['data']['amount'] ?? null
                        ];

                        $this->logger->debugOrder('OrderSync: Successfully processed order', [
                            'order_id' => $order->getIncrementId(),
                            'transaction_uid' => $response['data']['transaction_uid'] ?? null
                        ]);
                    } else {
                        $report['failed']++;
                        $report['errors'][] = [
                            'order_id' => $order->getIncrementId(),
                            'error' => 'IPN check failed or transaction not approved',
                            'response' => $response
                        ];
                    }

                } catch (\Exception $e) {
                    $report['failed']++;
                    $report['errors'][] = [
                        'order_id' => $order->getIncrementId() ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                    $this->logger->debugOrder('OrderSync: Error processing order', [
                        'order_id' => $order->getIncrementId() ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

        } catch (\Exception $e) {
            $report['errors'][] = [
                'order_id' => 'general',
                'error' => $e->getMessage()
            ];
            $this->logger->debugOrder('OrderSync: General error', ['error' => $e->getMessage()]);
        }

        return $report;
    }

    /**
     * Sync a single order
     * 
     * @param \Magento\Sales\Model\Order $order
     * @return bool True if sync was successful and order was updated, false otherwise
     */
    public function syncSingleOrder($order)
    {
        try {
            $payment = $order->getPayment();
            
            // Only process Payplus orders
            if (!$payment || $payment->getMethod() !== 'payplus_gateway') {
                $this->logger->debugOrder('OrderSync: Not a Payplus order, skipping', [
                    'order_id' => $order->getIncrementId()
                ]);
                return false;
            }

            // Get payment_request_uid from additional_data
            $paymentData = $payment->getData();
            $paymentRequestUid = $paymentData['additional_data'] ?? null;

            // Fallback: try to get from paymentPageResponse
            if (!$paymentRequestUid) {
                $additionalInfo = $payment->getAdditionalInformation();
                if (isset($additionalInfo['paymentPageResponse']['page_request_uid'])) {
                    $paymentRequestUid = $additionalInfo['paymentPageResponse']['page_request_uid'];
                }
            }

            if (!$paymentRequestUid) {
                $this->logger->debugOrder('OrderSync: No payment_request_uid found', [
                    'order_id' => $order->getIncrementId()
                ]);
                return false;
            }

            // Call IPN check
            $response = $this->apiConnector->checkTransactionAgainstIPN([
                'payment_request_uid' => $paymentRequestUid
            ]);

            // Check if response is successful
            if (isset($response['results']['status']) && 
                $response['results']['status'] === 'success' &&
                isset($response['results']['code']) && 
                $response['results']['code'] == 0 &&
                isset($response['data']) &&
                isset($response['data']['status_code']) &&
                $response['data']['status_code'] === '000') {
                
                // CRITICAL: Validate that the IPN response matches this order
                // Check more_info (order increment ID) matches
                $ipnMoreInfo = $response['data']['more_info'] ?? null;
                $orderIncrementId = $order->getIncrementId();
                
                if ($ipnMoreInfo !== $orderIncrementId) {
                    $this->logger->debugOrder('OrderSync: IPN response more_info mismatch - possible duplicate page_request_uid', [
                        'order_id' => $orderIncrementId,
                        'ipn_more_info' => $ipnMoreInfo,
                        'page_request_uid' => $paymentRequestUid,
                        'message' => 'This indicates a duplicate page_request_uid issue - IPN response belongs to a different order'
                    ]);
                    return false;
                }
                
                // Check amount matches (with tolerance for rounding differences)
                $ipnAmount = isset($response['data']['amount']) ? (float)$response['data']['amount'] : null;
                $orderAmount = (float)$order->getGrandTotal();
                $amountDifference = abs($ipnAmount - $orderAmount);
                
                // Allow 0.01 tolerance for rounding differences
                if ($ipnAmount === null || $amountDifference > 0.01) {
                    $this->logger->debugOrder('OrderSync: IPN response amount mismatch - possible duplicate page_request_uid', [
                        'order_id' => $orderIncrementId,
                        'ipn_amount' => $ipnAmount,
                        'order_amount' => $orderAmount,
                        'difference' => $amountDifference,
                        'page_request_uid' => $paymentRequestUid,
                        'message' => 'This indicates a duplicate page_request_uid issue - IPN response belongs to a different order'
                    ]);
                    return false;
                }
                
                // Process the order like callback does
                $orderResponse = new OrderResponse($order);
                $orderResponse->processResponse($response['data'], true);
                
                // Add order note with IPN response details
                $ipnNote = "=== OrderSync: Order moved to processing due to successful IPN check ===\n";
                $ipnNote .= "Order Number: " . $orderIncrementId . "\n";
                $ipnNote .= "IPN more_info: " . ($ipnMoreInfo ?? 'N/A') . "\n";
                $ipnNote .= "Match: " . ($ipnMoreInfo === $orderIncrementId ? '✓ YES' : '✗ NO') . "\n";
                $ipnNote .= "Transaction UID: " . ($response['data']['transaction_uid'] ?? 'N/A') . "\n";
                $ipnNote .= "Status: " . ($response['data']['status'] ?? 'N/A') . " (" . ($response['data']['status_code'] ?? 'N/A') . ")\n";
                $ipnNote .= "Amount: " . ($response['data']['amount'] ?? 'N/A') . " " . ($response['data']['currency'] ?? 'ILS') . "\n";
                $ipnNote .= "Payment Method: " . ($response['data']['method'] ?? 'N/A') . "\n";
                if (isset($response['data']['approval_num']) && !empty($response['data']['approval_num'])) {
                    $ipnNote .= "Approval Number: " . $response['data']['approval_num'] . "\n";
                }
                if (isset($response['data']['voucher_num']) && !empty($response['data']['voucher_num'])) {
                    $ipnNote .= "Voucher Number: " . $response['data']['voucher_num'] . "\n";
                }
                if (isset($response['data']['date']) && !empty($response['data']['date'])) {
                    $ipnNote .= "Transaction Date: " . $response['data']['date'] . "\n";
                }
                $ipnNote .= "Page Request UID: " . $paymentRequestUid . "\n";
                $ipnNote .= "Synced at: " . date('Y-m-d H:i:s');
                
                $order->addStatusHistoryComment($ipnNote, false);
                $order->save();
                
                $this->logger->debugOrder('OrderSync: Successfully processed order', [
                    'order_id' => $order->getIncrementId(),
                    'transaction_uid' => $response['data']['transaction_uid'] ?? null
                ]);
                
                return true;
            } else {
                $this->logger->debugOrder('OrderSync: IPN check failed or transaction not approved', [
                    'order_id' => $order->getIncrementId(),
                    'response' => $response
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->debugOrder('OrderSync: Error processing order', [
                'order_id' => $order->getIncrementId() ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

