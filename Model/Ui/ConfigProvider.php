<?php

namespace Payplus\PayplusGateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;

class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'payplus_gateway';
    const CC_VAULT_CODE = 'payplus_cc_vault';

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
                ]
            ]
        ];
    }
}
