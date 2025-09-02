<?php

namespace Payplus\PayplusGateway\Model\Custom;

use DateTime;
use Magento\Sales\Model\Order;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Payplus\PayplusGateway\Model\Ui\ConfigProvider;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use function PHPUnit\Framework\isNull;

class OrderResponse
{
    public $order;
    public $orderSender;
    public  $config;
    public  $statusGlobal;
    public  $stateOGlobal;
    public  $statusApprovalGlobal;
    public  $stateApprovalOGlobal;
    public function __construct($order)
    {
        $this->order = $order;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->orderSender = $objectManager->create(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class);
        $this->config  = $objectManager->create(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->statusGlobal = $this->config->getValue(
            'payment/payplus_gateway/api_configuration/status_order_payplus',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $this->statusGlobal = ($this->statusGlobal) ? $this->statusGlobal : 'complete';
        $this->stateOGlobal = $this->config->getValue(
            'payment/payplus_gateway/api_configuration/state_order_payplus',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        $this->stateApprovalOGlobal = !empty($this->config->getValue(
            'payment/payplus_gateway/api_configuration/state_approval_order_payplus',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )) ? $this->config->getValue(
            'payment/payplus_gateway/api_configuration/state_approval_order_payplus',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) : 'processing';
        $this->statusApprovalGlobal = !empty($this->config->getValue(
            'payment/payplus_gateway/api_configuration/status_approval_order_payplus',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )) ? $this->config->getValue(
            'payment/payplus_gateway/api_configuration/status_approval_order_payplus',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) : 'holded';
        $this->stateOGlobal = ($this->stateOGlobal) ?  $this->stateOGlobal : 'complete';
    }
    public function processResponse($params, $direct = false)
    {
        $payment = $this->order->getPayment();
        $status = false;

        // Check if this is a multipass transaction
        $isMultipass = isset($params['method']) && strtolower($params['method']) === 'multipass';
        $isMultipleTransaction = isset($params['is_multiple_transaction']) &&
            ($params['is_multiple_transaction'] === true || $params['is_multiple_transaction'] === 'true');

        // ...existing code...

        if ($params['status_code'] != '000') {
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_VOID;
            $payment->deny();
        } else {
            $this->order->setCanSendNewEmailFlag(true);
            $this->order->setSendEmail(true);
            if ($params['type'] == 'Approval') {
                $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
                $payment->registerAuthorizationNotification($params['amount']);
                $payment->setIsTransactionPending(true);
                $payment->setIsTransactionClosed(false);
                $this->order->setState($this->stateApprovalOGlobal);
                $this->order->setStatus($this->statusApprovalGlobal);
            }

            if ($params['type'] == 'Charge') {
                $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
                $payment->registerCaptureNotification($params['amount']);

                $this->order->setState($this->stateOGlobal);
                $this->order->setStatus($this->statusGlobal);
            }
            $status = true;
        }

        $payment->setCcStatus($params['status_code']);

        // Safe handling of four_digits - may not exist in multipass transactions
        if (isset($params['four_digits']) && !empty($params['four_digits'])) {
            $payment->setCcLast4($params['four_digits']);
        } elseif ($isMultipass || $isMultipleTransaction) {
            // For multipass, set a placeholder or leave empty
            $payment->setCcLast4('');
        }

        $payment->setTransactionId($params['transaction_uid']);
        $payment->setParentTransactionId($params['transaction_uid']);
        $payment->addTransaction($transactionType);

        // Safe handling of expiry fields - may not exist in multipass transactions
        if (isset($params['expiry_month']) && !empty($params['expiry_month'])) {
            $payment->setCcExpMonth($params['expiry_month']);
        }
        if (isset($params['expiry_year']) && !empty($params['expiry_year'])) {
            $payment->setCcExpYear($params['expiry_year']);
        }

        $paymentAdditionalInformation = ['paymentPageResponse' => $params];

        // Add multipass transaction info
        if ($isMultipass || $isMultipleTransaction) {
            $paymentAdditionalInformation['is_multipass_transaction'] = true;
            $paymentAdditionalInformation['multipass_method'] = $params['method'] ?? 'multipass';

            // Add transaction summary comment
            $transactionSummary = "Multipass Transaction: " . ($params['method'] ?? 'multipass') . "\n";
            $transactionSummary .= "Transaction UID: " . $params['transaction_uid'] . "\n";
            $transactionSummary .= "Amount: " . $params['amount'] . " " . ($params['currency'] ?? 'ILS') . "\n";
            $transactionSummary .= "Status: " . $params['status'] . " (" . $params['status_code'] . ")";

            // Add brand code if available
            if (isset($params['brand_code']) && !empty($params['brand_code'])) {
                $transactionSummary .= "\nBrand Code: " . $params['brand_code'];
            }

            $this->order->addStatusHistoryComment($transactionSummary);

            // Debug: Add full response for troubleshooting
            // $debugComment = "=== DEBUG: Full Response Data ===\n" . print_r($params, true);
            // $this->order->addStatusHistoryComment($debugComment);
        }

        // Token handling - only for credit card transactions with required fields
        if (
            isset($params['token_uid'])
            && $params['token_uid']
            && $this->order->getCustomerId()
            && $this->order->getCustomerIsGuest() == 0
            && isset($params['expiry_month'])
            && isset($params['expiry_year'])
            && isset($params['four_digits'])
            && isset($params['brand_name'])
            && !$isMultipass // Don't create vault tokens for multipass transactions
        ) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $paymentTokenFactory = $objectManager->create(\Magento\Vault\Model\PaymentTokenFactory::class);
            /**
             * @var \Magento\Vault\Model\PaymentToken
             */
            $paymentToken = $paymentTokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
            $expiryDate = DateTime::createFromFormat('y-m', $params['expiry_year'] . '-' . $params['expiry_month']);
            $paymentToken->setGatewayToken($params['token_uid']);
            $paymentToken->setExpiresAt($expiryDate->format('Y-m-01 00:00:00'));
            $paymentToken->setPaymentMethodCode(ConfigProvider::CC_VAULT_CODE);

            $paymentToken->setTokenDetails(json_encode([
                'type' => $params['brand_name'],
                'maskedCC' => $params['four_digits'],
                'expirationDate' => $params['expiry_year'] . '/' . $params['expiry_month'],
                'customer_uid' => $params['customer_uid'] ?? '',
            ]));
            $paymentAdditionalInformation['is_active_payment_token_enabler'] = true;
            $extensionAttributes = $payment->getExtensionAttributes();
            $extensionAttributes->setVaultPaymentToken($paymentToken);
        }

        $payment->setAdditionalInformation($paymentAdditionalInformation);
        $this->order->save();

        try {
            $this->orderSender->send($this->order);
        } catch (\Exception $e) {
            // Log email sending error but don't fail the transaction
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $logger = $objectManager->create(\Payplus\PayplusGateway\Logger\Logger::class);
            $logger->debugOrder('Failed to send order email', [
                'order_id' => $this->order->getIncrementId(),
                'error' => $e->getMessage()
            ]);
        }

        return $status;
    }
}
