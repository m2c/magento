<?php
class Mage_WEBCASH_Model_Mysql4_PaymentMethod_Debug extends Mage_Core_Model_Mysql4_Abstract {
    protected function _construct() {
        $this->_init('webcash/paymentmethod_debug', 'debug_id');
    }
}