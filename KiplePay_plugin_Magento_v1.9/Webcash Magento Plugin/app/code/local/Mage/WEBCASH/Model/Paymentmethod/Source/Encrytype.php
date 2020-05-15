<?php
class Mage_WEBCASH_Model_PaymentMethod_Source_Encrytype {
    public function toOptionArray() {
        return array(
            array(
                'value' => "md5",
                'label' => Mage::helper('webcash')->__('MD5')
            ),
            array(
                'value' => "sha1",
                'label' => Mage::helper('webcash')->__('SHA1')
            )
        );
    }
}