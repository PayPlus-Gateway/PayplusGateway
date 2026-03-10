<?php

namespace Payplus\PayplusGateway\Model\Adminhtml\Source;

class TerminalMagentoCustom implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => __('Terminal default configuration')
            ],
            [
                'value' => 1,
                'label' => __('Magento default configuration')
            ],
            [
                'value' => 2,
                'label' => __('Custom')
            ]
        ];
    }
}
