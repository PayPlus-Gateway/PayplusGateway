<?php
namespace Payplus\PayplusGateway\Block;

class StaticRenderer extends \Magento\Backend\Block\AbstractBlock
{
    protected function _construct()
    {
        $scp = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        
        // Check if Apple Pay script is enabled (works for both iframe and redirect modes)
        $isApplePayScriptEnabled = $this->_scopeConfig->isSetFlag(
            'payment/payplus_gateway/display_settings/import_applepay_script',
            $scp
        );
        
        if ($isApplePayScriptEnabled) {
            $api_test_mode = $this
                ->_scopeConfig
                ->getValue('payment/payplus_gateway/api_configuration/dev_mode', $scp) == 1;
            $om = \Magento\Framework\App\ObjectManager::getInstance();
            $page = $om->get(\Magento\Framework\View\Page\Config::class);
            $url = 'https://payments' . ($api_test_mode ? 'dev' : '') . '.payplus.co.il';
            $url .= '/statics/applePay/scriptV2.js';
            $page->addRemotePageAsset($url, 'js');
        }
    }
}
