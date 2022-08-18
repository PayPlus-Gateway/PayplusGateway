<?php

namespace Payplus\PayplusGateway\Model\Custom;

use DateTime;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Payplus\PayplusGateway\Model\Ui\ConfigProvider;

use function PHPUnit\Framework\isNull;

class OrderResponse
{
    public $order;
    public $orderSender;
    public function __construct($order) {
        $this->order = $order;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->orderSender = $objectManager->create(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class);
    }

    public function processResponse($params, $direct = false)
    {

        $payment = $this->order->getPayment();
        $status = false;

        if (!$direct) {
            if ($payment->getData('additional_data') != $params['page_request_uid']) {
                return $status;
            }
            if ($this->order->getStatus() != 'pending_payment') {
                return $status;
            }
        }

        if ($params['status_code'] !='000') {
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_VOID;
            $payment->deny();
        } else {
            $this->order->setCanSendNewEmailFlag(true);
            $this->order->setSendEmail(true);
            if ($params['type'] =='Approval') {
                $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
                $payment->registerAuthorizationNotification($params['amount']);
                $payment->setIsTransactionPending(true);
                $payment->setIsTransactionClosed(false);
                $this->order->setState('pending_payment');
                $this->order->setStatus('pending');
            }

            if ($params['type'] =='Charge') {
                $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
                $payment->registerCaptureNotification($params['amount']);


            }

            $status = true;
        }

        $payment->setCcStatus($params['status_code']);
        $payment->setCcLast4($params['four_digits']);
        $payment->setTransactionId($params['transaction_uid']);
        $payment->setParentTransactionId($params['transaction_uid']);
        $payment->addTransaction($transactionType);
        $payment->setCcExpMonth($params['expiry_month']);
        $payment->setCcExpYear($params['expiry_year']);
        $paymentAdditionalInformation = ['paymentPageResponse'=>$params];

        if (isset($params['token_uid'])
            && $params['token_uid']
            && $this->order->getCustomerId()
            && $this->order->getCustomerIsGuest() == 0
            ) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $paymentTokenFactory = $objectManager->create(\Magento\Vault\Model\PaymentTokenFactory::class);
            /**
             * @var \Magento\Vault\Model\PaymentToken
             */
            $paymentToken = $paymentTokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
            $expiryDate = DateTime::createFromFormat('y-m', $params['expiry_year'].'-'.$params['expiry_month']);
            $paymentToken->setGatewayToken($params['token_uid']);
            $paymentToken->setExpiresAt($expiryDate->format('Y-m-01 00:00:00'));
            $paymentToken->setPaymentMethodCode(ConfigProvider::CC_VAULT_CODE);

            $paymentToken->setTokenDetails(json_encode([
                'type' => $params['brand_name'],
                'maskedCC' => $params['four_digits'],
                'expirationDate' => $params['expiry_year'].'/'.$params['expiry_month'],
                'customer_uid'=> $params['customer_uid'],
            ]));
            $paymentAdditionalInformation['is_active_payment_token_enabler'] = true;
            $extensionAttributes = $payment->getExtensionAttributes();
            $extensionAttributes->setVaultPaymentToken($paymentToken);
        }
        $payment->setAdditionalInformation($paymentAdditionalInformation);
        $this->order->save();
        $this->orderSender->send($this->order);
        return $status;
    }
}
