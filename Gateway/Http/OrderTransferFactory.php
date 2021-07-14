<?php

namespace Payplus\PayplusGateway\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;

class OrderTransferFactory extends TransferFactoryBase implements TransferFactoryInterface
{
    protected $gatewayMethod = '/api/v1.0/PaymentPages/generateLink';
    /**
     * @var TransferBuilder
     */
    private $transferBuilder;

    /**
     * @param TransferBuilder $transferBuilder
     */
    public function __construct(
        TransferBuilder $transferBuilder,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->transferBuilder = $transferBuilder;
        $this->storeManager= $storeManager;
        parent::__construct($config);
    }
    
    public function create(array $data)
    {
        $request = $data['orderDetails'];
        
        $scp = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $getStoreURL = $this->storeManager->getStore()->getBaseUrl();
        $request['payment_page_uid'] = $this->config->getValue(
            'payment/payplus_gateway/api_configuration/payment_page_uid',
            $scp
        );
        $request['refURL_success'] = $getStoreURL.'payplus_gateway/ws/returnfromgateway';
        $request['refURL_failure'] = $getStoreURL.'checkout/onepage/failure';
        $request['refURL_cancel'] = $getStoreURL.'checkout/#payment';
        if ($this->config->getValue('payment/payplus_gateway/orders_config/payment_action', $scp) > 0) {
            $request['charge_method'] = $this->config->getValue(
                'payment/payplus_gateway/orders_config/payment_action',
                $scp
            );
        }
        if ($this->config->getValue('payment/payplus_gateway/payment_page/send_add_data_param', $scp) == 1) {
            $request['add_data'] = 1;
        }
        if ($this->config->getValue('payment/payplus_gateway/payment_page/use_callback', $scp) == 1) {
            $request['refURL_callback'] = $getStoreURL.'payplus_gateway/ws/callbackpoint';
        }
        if ($this->config->getValue('payment/payplus_gateway/payment_page/hide_id_card_number', $scp)) {
            $request['hide_identification_id'] = true;
        }
        if ($this->config->getValue('payment/payplus_gateway/payment_page/hide_payments', $scp)) {
            $request['hide_payments_field'] = true;
        }
        if ($this->config->getValue('payment/payplus_gateway/orders_config/email_upon_success', $scp)) {
            $request['sendEmailApproval'] = true;
        }
        if ($this->config->getValue('payment/payplus_gateway/orders_config/sendEmailFailure', $scp)) {
            $request['sendEmailFailure'] = true;
        }
        if ($this->config->getValue('payment/payplus_cc_vault/active', $scp)
            && $data['meta']['create_token']
            ) {
            $request['create_token'] = true;
        }

        $transfer = $this->transferBuilder
            ->setBody($request)
            ->setUri($this->gatewayMethod)
            ->build();
        return $transfer;
    }
}