<?php

/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Payplus\PayplusGateway\Model\Ui;

use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Model\Ui\TokenUiComponentInterface;
use Magento\Vault\Model\Ui\TokenUiComponentProviderInterface;
use Magento\Vault\Model\Ui\TokenUiComponentInterfaceFactory;
use Magento\Framework\UrlInterface;

class TokenUiComponentProvider implements TokenUiComponentProviderInterface
{
    /**
     * @var TokenUiComponentInterfaceFactory
     */
    private $componentFactory;
    private $cardsMap = [
        'mastercard' => 'MC',
        'amex' => 'AE',
        'maestro' => 'MI',
        'dinners' => 'DN',
        'JCB' => 'JCB',
        'visa' => 'VI',
        'discover' => 'DI',
    ];
    public $urlBuilder;
    /**
     * @param TokenUiComponentInterfaceFactory $componentFactory
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        TokenUiComponentInterfaceFactory $componentFactory,
        UrlInterface $urlBuilder
    ) {
        $this->componentFactory = $componentFactory;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Get UI component for token
     * @param PaymentTokenInterface $paymentToken
     * @return TokenUiComponentInterface
     */
    public function getComponentForToken(PaymentTokenInterface $paymentToken)
    {
        $jsonDetails = json_decode($paymentToken->getTokenDetails() ?: '{}', true);
        $this->translateBrandToInitials($jsonDetails);
        $component = $this->componentFactory->create(
            [
                'config' => [
                    'code' => ConfigProvider::CC_VAULT_CODE,
                    TokenUiComponentProviderInterface::COMPONENT_DETAILS => $jsonDetails,
                    TokenUiComponentProviderInterface::COMPONENT_PUBLIC_HASH => $paymentToken->getPublicHash()
                ],
                'name' => 'Payplus_PayplusGateway/js/view/payment/method-renderer/vault'
            ]
        );
        return $component;
    }

    private function translateBrandToInitials(&$jsonDetails)
    {
        $jsonDetails['type'] = (!isset($jsonDetails['type']) || !$jsonDetails['type']) ? 'generic':$jsonDetails['type'];
        if (array_key_exists($jsonDetails['type'], $this->cardsMap)) {
            $jsonDetails['type'] = $this->cardsMap[$jsonDetails['type']];
        } else {
            $jsonDetails['type'] = 'generic';
        }
    }
}
