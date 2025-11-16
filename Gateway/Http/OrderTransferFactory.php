<?php

namespace Payplus\PayplusGateway\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;

class OrderTransferFactory extends TransferFactoryBase implements TransferFactoryInterface
{
    public $config;
    public $storeManager;
    public $_store;
    public $_logger;
    
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
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Locale\Resolver $store,
        \Payplus\PayplusGateway\Logger\Logger $logger
    ) {
        $this->transferBuilder = $transferBuilder;
        $this->storeManager= $storeManager;
        $this->_store= $store;
        $this->_logger= $logger;
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
            $request['add_data'] = "1";
        }
        if ($this->config->getValue('payment/payplus_gateway/payment_page/use_callback', $scp) == 1) {
            $testUrlCallback=$this->config->getValue('payment/payplus_gateway/payment_page/test_url_callback', $scp);
            if(!empty($testUrlCallback)){
                $request['refURL_callback'] =$testUrlCallback;
            }else{
                $request['refURL_callback'] = $getStoreURL.'payplus_gateway/ws/callbackpoint';

            }
            if ($this->config->getValue('payment/payplus_gateway/payment_page/success_page_action', $scp) == 0) {
                unset($request['refURL_success']);
            } elseif ($this->config->getValue('payment/payplus_gateway/payment_page/success_page_action', $scp) == 2) {
                $request['refURL_success'] = $this
                ->config
                ->getValue('payment/payplus_gateway/payment_page/success_page_custom_url', $scp);
            }
        }
        if ($this->config->getValue('payment/payplus_gateway/payment_page/error_page_action', $scp) == 0) {
            unset($request['refURL_failure']);
        } elseif ($this->config->getValue('payment/payplus_gateway/payment_page/error_page_action', $scp) == 2) {
            $request['refURL_failure'] = $this
                ->config
                ->getValue('payment/payplus_gateway/payment_page/error_page_custom_url', $scp);
        }
        if ($this->config->getValue('payment/payplus_gateway/payment_page/cancel_page_action', $scp) == 0) {
            unset($request['refURL_cancel']);
        } elseif ($this->config->getValue('payment/payplus_gateway/payment_page/cancel_page_action', $scp) == 2) {
            $request['refURL_cancel'] = $this
                ->config
                ->getValue('payment/payplus_gateway/payment_page/cancel_page_custom_url', $scp);
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

        if (($this->config->getValue('payment/payplus_cc_vault/active', $scp)
            && $data['meta']['create_token'] )|| $this->config->getValue('payment/payplus_enable_cc_vault_always/active', $scp)
            ) {
            $request['create_token'] = true;
        }

        $localeLetter = $this->_store->getLocale();
        $request['language_code'] = substr($localeLetter, 0, 2);


        if ($this
            ->config
            ->getValue('payment/payplus_gateway/invoices_config/invoice_language_same_as_terminal', $scp)
            ) {

            $request['invoice_language'] = substr($localeLetter, 0, 2);


        }

        $transfer = $this->transferBuilder
            ->setBody($request)
            ->setUri($this->gatewayMethod)
            ->build();

        $this->_logger->debugOrder("Order request", $request);
        return $transfer;
    }
}
