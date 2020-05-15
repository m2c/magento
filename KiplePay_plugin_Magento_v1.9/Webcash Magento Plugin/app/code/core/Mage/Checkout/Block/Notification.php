<?php
class Mage_Checkout_Block_Notification extends Mage_Core_Block_Template
{
    public function getRealOrderId() {
        $order = Mage::getModel('sales/order')->load($this->getLastOrderId());
        #print_r($order->getData());
        return $order->getIncrementId();
    }
}
