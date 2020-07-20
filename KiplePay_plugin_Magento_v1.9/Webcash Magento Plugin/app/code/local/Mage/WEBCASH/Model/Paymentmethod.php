<?php
class Mage_WEBCASH_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
{
    protected $_code  = 'webcash';
    protected $_formBlockType = 'webcash/paymentmethod_form';

    protected $_isGateway               = true;
    protected $_canAuthorize            = false;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = false;

    protected $_quote;
    protected $_order;
    protected $_allowCurrencyCode = array('MYR');


    public function isInitializeNeeded()
    {
        return true;
    }

    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_NEW;
        $stateObject->setState($state);
        $stateObject->setStatus(Mage::getSingleton('sales/order_config')->getStateDefaultStatus($state));
        $stateObject->setIsNotified(false);
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('webcash/paymentmethod/redirect', array('_secure' => true));
    }

    public function getPaymentmethodCheckoutFormFields($orderid=0)
    {
        if (!$orderid) {
            $orderid=$this->getCheckout()->getLastRealOrderId();
        }
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderid);
        $orderId = $order->getId();
        if (!isset($orderId)) {
            Mage::throwException($this->__('Order identifier is not valid!'));
            return false;
        }
        //if( !$this->isOwner_or_Admin( $order->getCustomerId()  )   )  return false;

        $address = $order->getBillingAddress();
        $shippingaddress = $order->getShippingAddress();

        //getQuoteCurrencyCode
        $currency_code = $order->getBaseCurrencyCode();
        $amount = $order->getBaseGrandTotal();
        //$amount = $this->toMYR(  $amount ,  $currency_code );
        $amount = number_format(round($amount, 2), 2, '.', '');

        $email = $address->getEmail();
        if ($email == '') {
            $email = $order->getCustomerEmail();
        }
        $amountVal = str_replace('.', '', $amount);
        $amountVal = str_replace(',', '', $amountVal);
        $hashValue = sha1($this->getConfigData('hashkey').$this->getConfigData('login').$orderid.$amountVal);

        $sArr = array(
            'ord_mercref' => $orderid,
            'ord_date' => date('Y-m-d H:i:s'),
            'ord_totalamt' => $amount ,
            'ord_gstamt' => '0.00',
            'ord_fcur' => 'MYR',
            'version' => '2.0',
            'ord_shipname' => $address->getFirstname() . ' ' . $address->getLastname(),
            'ord_telephone' => $address->getTelephone(),
            'ord_email' => $email,
            'ord_mercID' => $this->getConfigData('login'),
            'merchant_hashvalue' => $hashValue,
            'ord_returnURL' => Mage::getUrl('webcash/paymentmethod/success', array('_secure' => true)),
            'bill_desc' => "\n-- Order Detail --"
        );

        // $items = $this->getQuote()->getAllItems();
        $items = $order->getAllItems();
        if ($items) {
            $i = 1;
            foreach ($items as $item) {
                if ($item->getParentItem()) {
                    continue;
                }
                $sArr['bill_desc'] .= "\n$i. Name: ".$item->getName() . '  Sku: '.$item->getSku() . ' Qty: ' . $item->getQtyOrdered() * 1;
                $i++;
            }
        }

        $foundit=0;
        foreach ($order->getAllStatusHistory() as $_history) {
            if (strpos($_history->getComment(), "/paymentmethod/pay/") !== false) {
                $foundit=1;
                break;
            }
        }
        if (!$foundit) {
            /* quick fix only */
            /* update notification by farid 13th oct 2014 */
            $url = Mage::getUrl('sales/order/reorder/', array("order_id" => $order->getId() ));
            //$url = Mage::getUrl( "*/*/pay",  array("order_id" => $order->getRealOrderId() )  );
            $order->addStatusToHistory(
                $order->getStatus(),
                      //quick fix for temporary only
                      //"If you not complete payment yet, please <a href='$url' >Click here to pay (WEBCASH Malaysia Online Payment)</a> .",
                      "If the customer has not complete a payment yet, please provide the customer the following link to use the following link :\m '$url'  .",
                true
            );
            $order->save();
        }

        return $sArr;
    }

    public function getWEBCASHUrl()
    {
        if ($this->getConfigData('testmode') == true) {
            return 'https://staging.webcash.com.my/wcgatewayinit.php';
        } else {
            return 'https://webcash.com.my/wcgatewayinit.php';
        }
    }

    /**
    * Get checkout session namespace
    * @return Mage_Checkout_Model_Session
    */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    public function getQuote()
    {
        return $this->getCheckout()->getQuote();
    }

    public function getOrder()
    {
        if (empty($this->_order)) {
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($this->getCheckout()->getLastRealOrderId());
            $this->_order = $order;
        }
        return $this->_order;
    }

    public function toMYR($amount, $currency_code)
    {
        if ($currency_code != "MYR") {
            //if currency code is not allowed currency code, use USD as default
            $storeCurrency = Mage::getSingleton('directory/currency')->load($currency_code);
            $amount = $storeCurrency->convert($amount, 'MYR');
        }
        return $amount;
    }

    public function MYRtoXXX($amount, $currency_code)
    {
        if ($currency_code != "MYR") {
            $storeCurrency = Mage::getSingleton('directory/currency')->load($currency_code);
            // may be that don't have  MYR -> XXX rate
            // but must have  XXX -> MYR rate
            // so we can get rate by below way
            $rate = $storeCurrency->getRate("MYR");
            $amount = round(($amount / $rate), 3);
        }
        return $amount;
    }

    public function isOwner_or_Admin($order_uid)
    {
        if (Mage::getSingleton('customer/session')->getId() == $order_uid || Mage::getSingleton('admin/session')->getUser()) {
            return true;
        }
        $this->_redirect('customer/account/login');
        return false;
    }
}
