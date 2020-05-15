<?php
class Mage_WEBCASH_Block_PaymentMethod_Form extends Mage_Payment_Block_Form {
    
    protected function _construct() {   
        parent::_construct();
    }
    
    protected function _toHtml() {  

    $skeleton = Mage::getSingleton("webcash/paymentmethod")->getConfigData('paymentdescription');
    if( $skeleton == "" ) {
        $skeleton = "You will be redirected to WEBCASH website when you place an order.";
    }
    return "<fieldset class=\"form-list\">
              <ul id=\"payment_form_webcash\" style=\"display: none;\">
               <li>" . $skeleton . "</li>
              </ul>
            </fieldset>";
    }  
}
