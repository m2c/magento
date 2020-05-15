<?php
class Mage_WEBCASH_Model_PaymentMethod_Source_Cctype extends Mage_Payment_Model_Source_Cctype {
    public function getAllowedTypes() {
        return array('VI', 'MC', 'AE', 'DI', 'OT');
    }
}