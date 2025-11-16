<?php

namespace Payplus\PayplusGateway\Model\Adminhtml\Source;

class PaymentAction implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => __('PayPlus default configuration')
            ],
            [
                'value' => 1,
                'label' => __('Charge')
            ],
            [
                'value' => 2,
                'label' => __('Authorize')
            ]
        ];
    }
}
