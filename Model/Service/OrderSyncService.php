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
                        
                        // Process the order like callback does
                        $orderResponse = new OrderResponse($order);
                        $orderResponse->processResponse($response['data'], true);
                        
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
}

