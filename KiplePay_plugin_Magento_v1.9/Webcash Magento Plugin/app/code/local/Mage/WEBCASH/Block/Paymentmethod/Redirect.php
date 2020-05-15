<?php
class Mage_WEBCASH_Block_PaymentMethod_Redirect extends Mage_Core_Block_Abstract {
    
    protected function _toHtml() { 

		$Params = $this->getRequest()->getParams();
        $orderid = isset( $Params['order_id'] )? $Params['order_id']*1 : 0;
        
        $pm = Mage::getModel('webcash/paymentmethod');
				
		
        $form = new Varien_Data_Form();
        $form->setAction($pm->getWEBCASHUrl())
                ->setId('webcash_paymentmethod_checkout')
                ->setName('webcash_paymentmethod_checkout')
                ->setMethod('POST')
                ->setUseContainer(true);

        foreach ($pm->getPaymentmethodCheckoutFormFields( $orderid ) as $field => $value) {
            $form->addField($field, 'hidden', array( 'name' => $field, 'value' => $value ));
        }

        $html = '<html><body>'."\n";
        $html .= $this->__('You will be redirected to WEBCASH in a few seconds.')."\n";
        $html .= $form->toHtml();
        $html .= '<script type="text/javascript">document.getElementById("webcash_paymentmethod_checkout").submit();</script>';
        $html .= '</body></html>';
        return $html;
    }
}

