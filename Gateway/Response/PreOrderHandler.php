<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Payplus\PayplusGateway\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;

class PreOrderHandler implements HandlerInterface
{
    const TXN_ID = 'TXN_ID';

    /**
     * Handles transaction id
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        $paymentDO = $handlingSubject['payment'];
        $payment = $paymentDO->getPayment();
        $order = $paymentDO->getOrder();
        
        // Ensure we always get a fresh page_request_uid for each new order
        // Clear any existing additional_data first to prevent reuse
        $payment->setAdditionalData(null);
        
        // Validate that the API response contains a new page_request_uid
        if (!isset($response['data']['page_request_uid']) || empty($response['data']['page_request_uid'])) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Payplus API did not return a valid page_request_uid for this order.')
            );
        }
        
        $newPageRequestUid = $response['data']['page_request_uid'];
        
        // Critical: Check if this page_request_uid is already used by another order
        // This prevents duplicate page_request_uid issues where multiple orders get the same UID
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $connection = $objectManager->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
        $paymentTable = $connection->getTableName('sales_order_payment');
        $orderTable = $connection->getTableName('sales_order');
        
        $select = $connection->select()
            ->from(['p' => $paymentTable], ['p.parent_id'])
            ->join(['o' => $orderTable], 'p.parent_id = o.entity_id', ['o.increment_id'])
            ->where('p.method = ?', 'payplus_gateway')
            ->where('p.additional_data = ?', $newPageRequestUid)
            ->where('o.entity_id != ?', $order->getId());
        
        $existingOrder = $connection->fetchOne($select);
        
        if ($existingOrder) {
            // Log the duplicate detection
            $logger = $objectManager->get(\Payplus\PayplusGateway\Logger\Logger::class);
            $logger->debugOrder('CRITICAL: Duplicate page_request_uid detected!', [
                'new_order_id' => $order->getIncrementId(),
                'new_order_entity_id' => $order->getId(),
                'page_request_uid' => $newPageRequestUid,
                'existing_order_id' => $existingOrder,
                'message' => 'Payplus API returned a page_request_uid that is already in use by another order. This should not happen!'
            ]);
            
            // Throw exception to prevent saving duplicate page_request_uid
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Payplus API returned a page_request_uid that is already in use by order #%1. Please try placing the order again.', $existingOrder)
            );
        }
        
        // Set the new page_request_uid from the API response
        $payment->setAdditionalData($newPageRequestUid);
        $payment->setAdditionalInformation(['awaiting_payment'=>true]);
        $payment->setStatus('pre_payment')->update();
        $payment->setIsTransactionClosed(false);
    }
}
