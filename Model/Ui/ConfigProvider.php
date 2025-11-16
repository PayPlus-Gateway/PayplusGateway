<?php

namespace Payplus\PayplusGateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;

class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'payplus_gateway';
    const CC_VAULT_CODE = 'payplus_cc_vault';
    public $config;
    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $config
    ) {
        $this->config = $config;
    }

    public function getConfig()
    {
        $scp = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

        return [
            'payment' => [
                self::CODE => [
                     'title'=> $this->config->getValue(
                         'payment/payplus_gateway/title',
                         $scp
                     ),
                    'active'=> $this->config->getValue(
                        'payment/payplus_gateway/active',
                        $scp
                    ),
                    'ccVaultCode' => self::CC_VAULT_CODE,
                    'bHidePayplusLogo'=>(bool)$this->config->getValue(
                        'payment/payplus_gateway/payment_page/hide_payplus_icon',
                        $scp
                    ),
                    'form_type'=>$this->config->getValue(
                        'payment/payplus_gateway/display_settings/iframe_or_redirect',
                        $scp
                    ),
                    'iframe_height'=>$this->config->getValue(
                        'payment/payplus_gateway/display_settings/iframe_height',
                        $scp
                    ),
                    'getPaymentLinkURL'=>'/payplus_gateway/ws/link/id/'
                ],
                'google_pay' => [
                    'title'=> $this->config->getValue(
                        'payment/payplus_gateway_google_pay/title',
                        $scp
                    ),
                    'active'=> $this->config->getValue(
                        'payment/payplus_gateway_google_pay/active',
                        $scp
                    ),
                    'bHidePayplusLogo'=>(bool)$this->config->getValue(
                        'payment/payplus_gateway_google_pay/payment_page/hide_payplus_icon',
                        $scp
                    ),
                    'getPaymentLinkURL'=>'/payplus_gateway/ws/link/id/'
                ],
                'bit' => [
                    'title'=> $this->config->getValue(
                        'payment/payplus_gateway_bit/title',
                        $scp
                    ),
                    'active'=> $this->config->getValue(
                        'payment/payplus_gateway_bit/active',
                        $scp
                    ),
                    'bHidePayplusLogo'=>(bool)$this->config->getValue(
                        'payment/payplus_gateway_bit/payment_page/hide_payplus_icon',
                        $scp
                    ),
                    'getPaymentLinkURL'=>'/payplus_gateway/ws/link/id/'
                ],
                'multipass' => [
            'title'=> $this->config->getValue(
                'payment/payplus_gateway_multipass/title',
                $scp
            ),
            'active'=> $this->config->getValue(
                'payment/payplus_gateway_multipass/active',
                $scp
            ),
            'bHidePayplusLogo'=>(bool)$this->config->getValue(
                'payment/payplus_gateway_multipass/payment_page/hide_payplus_icon',
                $scp
            ),
            'getPaymentLinkURL'=>'/payplus_gateway/ws/link/id/'
    ],
                'paypal' => [
                    'title'=> $this->config->getValue(
                        'payment/payplus_gateway_paypal/title',
                        $scp
                    ),
                    'active'=> $this->config->getValue(
                        'payment/payplus_gateway_paypal/active',
                        $scp
                    ),
                    'bHidePayplusLogo'=>(bool)$this->config->getValue(
                        'payment/payplus_gateway_paypal/payment_page/hide_payplus_icon',
                        $scp
                    ),
                    'getPaymentLinkURL'=>'/payplus_gateway/ws/link/id/'
                ],
                'apple_pay' => [
                    'title'=> $this->config->getValue(
                        'payment/payplus_gateway_apple_pay/title',
                        $scp
                    ),
                    'active'=> $this->config->getValue(
                        'payment/payplus_gateway_apple_pay/active',
                        $scp
                    ),
                    'bHidePayplusLogo'=>(bool)$this->config->getValue(
                        'payment/payplus_gateway_apple_pay/payment_page/hide_payplus_icon',
                        $scp
                    ),
                    'getPaymentLinkURL'=>'/payplus_gateway/ws/link/id/'
                ]


            ]

        ];
    }
}
