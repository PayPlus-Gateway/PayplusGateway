<?php
namespace Payplus\PayplusGateway\Block;

class StaticRenderer extends \Magento\Backend\Block\AbstractBlock
{
    protected function _construct()
    {
        $scp = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        if ($this
            ->_scopeConfig
            ->getValue('payment/payplus_gateway/display_settings/iframe_or_redirect', $scp) == 'iframe'
            && $this->_scopeConfig->getValue('payment/payplus_gateway/display_settings/import_applepay_script', $scp)
        ) {
            $api_test_mode = $this
                ->_scopeConfig
                ->getValue('payment/payplus_gateway/api_configuration/dev_mode', $scp) == 1;
            $om = \Magento\Framework\App\ObjectManager::getInstance();
            $page = $om->get(\Magento\Framework\View\Page\Config::class);
            $url = 'https://payments' . ($api_test_mode ? 'dev' : '') . '.payplus.co.il';
            $url .= '/statics/applePay/script.js';
            $page->addRemotePageAsset($url, 'js');
        }
    }
}
