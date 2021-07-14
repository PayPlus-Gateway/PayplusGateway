<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Payplus\PayplusGateway\Model\Adminhtml\Source;

/**
 * Class PaymentAction
 */
class IframeOrRedirect implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 'iframe',
                'label' => __('Iframe')
            ],
            [
                'value' => 'redirect',
                'label' => __('Redirect')
            ]
        ];
    }
}
