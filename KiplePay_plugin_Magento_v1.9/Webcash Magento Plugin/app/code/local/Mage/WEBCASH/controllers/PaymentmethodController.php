<?php
class Mage_WEBCASH_PaymentMethodController extends Mage_Core_Controller_Front_Action {
    //Order instance
    protected $_order;

    /**
     * When a customer chooses WEBCASH on Checkout/Payment page
     * 
     */
    public function redirectAction() { 
        $this->getResponse()->setBody($this->getLayout()->createBlock('webcash/paymentmethod_redirect')->toHtml());
    }
    
    /**
     * When WEBCASH return the order information at this point is in POST variables
     * 
     * @return boolean
     */
    public function successAction() {
        $connection = Mage::getSingleton('core/resource')->getConnection('core_read');
        $findMerId = "SELECT `value` FROM `mg_core_config_data` Where  `path` IN ('payment/webcash/login', 'payment/webcash/hashkey')";
        $findMerIdVal = $connection->fetchAll($findMerId); 
        
        if(!isset($_REQUEST['returncode'])){
            $this->_redirect('');
            return;
        }
        
        $P = $_REQUEST;
        //$this->_ack($P);
        $TypeOfReturn = "ReturnURL";
        
        $order = Mage::getModel('sales/order')->loadByIncrementId( $P['ord_mercref'] );
        $orderId = $order->getId();
        if(!isset($orderId)){
            Mage::throwException($this->__('Order identifier is not valid!'));
            return false;
        }
        $N = Mage::getModel('webcash/paymentmethod');
        
        if( $order->getPayment()->getMethod() !=="webcash" ) {
            Mage::throwException($this->__('Payment Method is not WEBCASH !'));
            return false;               
        }
        $amountVal = str_replace('.', '', $P['ord_totalamt']);
        $amountVal = str_replace(',', '', $amountVal);
        
        $chkOrdKey = sha1($findMerIdVal['1']['value'].$findMerIdVal['0']['value'].$P['ord_mercref'].$amountVal.$P['returncode']);
        if($P['ord_key'] != $chkOrdKey){
            $order->setState(
                Mage_Sales_Model_Order::STATE_CANCELED,
                Mage_Sales_Model_Order::STATE_CANCELED,
                'Customer Redirect from WEBCASH - ReturnURL (FAILED)' . "\n<br>Amount: " . $P['ord_fcur'] . " " . $P['ord_famt'] . "\n<br>PaidDate: " . $P['ord_date'], $notified = true );
            $order->save();
            $this->_redirect('checkout/cart');
            exit;
        }

        if($P['returncode'] == 'E1' || $P['returncode'] == 'E2'){
            $status = 0;
        }
        if($P['returncode'] == '100'){
            $status = 1;
        }
        
        if($status == 0){
            $order->setState(
                Mage_Sales_Model_Order::STATE_CANCELED,
                Mage_Sales_Model_Order::STATE_CANCELED,
                'Customer Redirect from WEBCASH - ReturnURL (FAILED)' . "\n<br>Amount: " . $P['ord_fcur'] . " " . $P['ord_famt'] . "\n<br>PaidDate: " . $P['ord_date'], $notified = true );
            $order->save();
            $this->_redirect('checkout/cart');
        }
        
        if($status == 1){
            $currency_code = $order->getOrderCurrencyCode();
            if( $currency_code !=="MYR" ) {
                $amount = $N->MYRtoXXX( $P['ord_famt'] ,  $currency_code );
                $etcAmt = "  <b>( $currency_code $amount )</b>";
                if( $order->getBaseGrandTotal() > $amount ) {
                    $order->addStatusToHistory( $order->getStatus(), "Amount order is not valid!" );
                }
            }

            $order->getPayment()->setTransactionId( $P['ord_mercref'] );
            $P['status'] = 'Success';
            if($this->_createInvoice($order,$N,$P,$TypeOfReturn)) {
                $order->sendNewOrderEmail();
            }
                
            $order->save();
            $this->_redirect('checkout/onepage/success');
            return;
        }
    }
    
    protected function _matchkey( $entype, $merchantID , $vkey , $P ) {
        $enf = ( $entype == "sha1" )? "sha1" : "md5";           
        $skey = $enf( $P['tranID'].$P['orderid'].$P['status'].$merchantID.$P['amount'].$P['currency'] );
        $skey = $enf( $P['paydate'].$merchantID.$skey.$P['appcode'].$vkey   );
        return ( $skey === $P['skey'] )? 1 : 0;
    }
  
    /**
     * Creating Invoice
     * 
     * @param Mage_Sales_Model_Order $order
     * @return Boolean
     */
    protected function _createInvoice(Mage_Sales_Model_Order $order,$N,$P,$TypeOfReturn) {
        if( $order->canInvoice() && ($order->hasInvoices() < 1));
            else 
        return false;
        //---------------------------------------------
        // convert order into invoice
        //---------------------------------------------
        // print_r( "INVOCE ".$newOrderStatus );           
        //need to convert from order into invoice
        $invoice = $order->prepareInvoice();
        $invoice->register()->capture();
        Mage::getModel('core/resource_transaction')
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();

        
        $order->setState(
            Mage_Sales_Model_Order::STATE_PROCESSING,
            Mage_Sales_Model_Order::STATE_PROCESSING,
                "Response from WEBCASH - ".$TypeOfReturn." (CAPTURED)"
                . "\n<br>Invoice #".$invoice->getIncrementId().""
                . "\n<br>Amount: ".$P['ord_fcur']." ".$P['ord_famt']
                . "\n<br>TransactionID: " . $P['ord_mercref']
                . "\n<br>WEBCASH Ref Number: ".$P['wcID']
                . "\n<br>Status: " . $P['status']
                . "\n<br>PaidDate: " . $P['ord_date']
                ,
                true
        );
        return true;               
    }

    public function _ack($P) {
        $P['treq'] = 1;
        while ( list($k,$v) = each($P) ) {
          $postData[]= $k."=".$v;
        }

        $postdata   = implode("&",$postData);
        $url        = "https://webcash.com.my/wcgatewayinit.php";
        $ch         = curl_init();
        curl_setopt($ch, CURLOPT_POST           , 1     );
        curl_setopt($ch, CURLOPT_POSTFIELDS     , $postdata );
        curl_setopt($ch, CURLOPT_URL            , $url );
        curl_setopt($ch, CURLOPT_HEADER         , 1  );
        curl_setopt($ch, CURLINFO_HEADER_OUT    , TRUE   );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER , 1  );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER , FALSE);
        $result = curl_exec( $ch );
        curl_close( $ch );
        return;
    }
  
    public function failureAction() {       
        $this->loadLayout();
        $this->renderLayout();
    }
  
    public function checklogin() {
        $U = Mage::getSingleton('customer/session');
        if( !$U->isLoggedIn() ) {
            $this->_redirect('customer/account/login');
            return false;
        }       
        return true;
    }
    
    public function payAction() {
        $this->getResponse()->setBody( $this->getLayout()->createBlock('webcash/paymentmethod_redirect')->toHtml() );
    }
}