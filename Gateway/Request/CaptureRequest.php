<?php

namespace Payplus\PayplusGateway\Gateway\Request;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

class CaptureRequest implements BuilderInterface
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @param ConfigInterface $config
     */
    public function __construct(
        ConfigInterface $config
    ) {
        $this->config = $config;
    }

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];

        $order = $paymentDO->getOrder();

        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new \LogicException('Order payment should be provided.');
        }
        $orderDetails =[
            'transaction_uid'=>$payment->getLastTransId(),
            'amount'=>$buildSubject['amount']
        ];
        foreach ($order->getItems() as $item) {
            $orderDetails['items'][] = [
                'name'          => $item->getName(),
                'price'         => $item->getPrice(),
                'quantity'   => $item->getQtyOrdered(),
            ];
        }

        $shippingAmount  = $payment->getBaseShippingAmount();
        if ($shippingAmount) {
            $orderDetails['items'][] = [
                'name'         => 'Shipping',
                'price'         => $shippingAmount,
                'shipping'   => true,
            ];
        }

        return $orderDetails;
    }
}
