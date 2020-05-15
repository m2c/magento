<?php

namespace KiplePay\Seamless\Controller\Index;

use Magento\Framework\Controller\ResultFactory; 
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $resultPageFactory;

     /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;
    
     /**
     * @var OrderSender
     */
    protected $orderSender;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        OrderSender $orderSender,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
        
    ) 
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->invoiceSender = $invoiceSender;
        $this->transactionFactory = $transactionFactory;        
        $this->checkoutSession = $checkoutSession;
        $this->orderSender = $orderSender;
        $this->quoteRepository = $quoteRepository;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        if( isset($_POST['payment_options']) && $_POST['payment_options'] == "1" ) {
            // Attempt to store the cart into magento system
            // This function should be execute during KiplePay selection page AFTER the address selection
            // Begin calling Magento API

            $om =   \Magento\Framework\App\ObjectManager::getInstance();

            ### At first time, create quote and order
            $cartData = $om->create('\Magento\Checkout\Model\Cart')->getQuote();
            $quote = $om->create('\Magento\Quote\Model\Quote');
            $quote->load($cartData->getId());
            $quote->getPayment()->setMethod('kiplepay_seamless'); // Todo: Will Appear KiplePay Seamless

            $customerSess = $om->create('\Magento\Customer\Model\Session');
            $checkoutHelperData = $om->create('\Magento\Checkout\Helper\Data');

            if( $_POST['current_email'] == ''){
                $quote_extra = $this->quoteRepository->getActive($cartData->getId());
                $_POST['current_email'] = $quote_extra->getBillingAddress()->getEmail();
            }            
            
            $customerType = '';
            if ($customerSess->isLoggedIn()) {
                $customerType = \Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER;
            }
            if (!$quote->getCheckoutMethod()) {
                if ($checkoutHelperData->isAllowedGuestCheckout($quote)) {
                    $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST);
                } else {
                    $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_REGISTER);
                }

                $customerType = $quote->getCheckoutMethod();
            }

            if ( $customerType == \Magento\Checkout\Model\Type\Onepage::METHOD_GUEST) {

                $quote->setCustomerId(null)
                    ->setCustomerEmail($_POST['current_email'])
                    ->setCustomerIsGuest(true)
                    ->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
            }

            
            if( $quote ){
                $cartManagement = $om->create('\Magento\Quote\Model\QuoteManagement');
                $order = $cartManagement->submit($quote);

                
                if( $order ){
                    $orderArr = [];
                    $orderArr = [
                        'oid' => $order->getId(),
                        "flname" => $order->getCustomerFirstName()." ".$order->getCustomerLastName(),
                        'lastorderid' => $order->getIncrementId() ];

                    $order_step2 = $om->create('\Magento\Sales\Model\Order')
                                     ->load($order->getId());

                        $order_step2->setState("pending_payment")->setStatus("pending_payment");

                                $order_step2->save();

                }
                
            }

            ### Begin to save quote and order in session
            $checkoutSession = $om->create('\Magento\Checkout\Model\Session');

            ### initial order created, save their data in session
            if( $order ){
                    $checkoutSession->setLastQuoteId($cartData->getId())->setLastSuccessQuoteId($cartData->getId());
                    $checkoutSession->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId())
                        ->setLastOrderStatus('pending_payment');
            }

            ### When 2nd attempt to make payment but above order create is error then use the session
            if( !$order ){
                $sess_quotedata = $checkoutSession->getData();

                if( isset($sess_quotedata['last_real_order_id']) && $sess_quotedata['last_real_order_id'] != null){

                    $lastOId = $sess_quotedata['last_real_order_id'];

                    $order = $om->create('\Magento\Sales\Api\Data\OrderInterface');
                    $order->loadByIncrementId($lastOId);
                    $orderArr = [];
                    $orderArr = [
                        'orderid'       => $lastOId,
                        'customer_name' => $order->getBillingAddress()->getFirstname()." ".$order->getBillingAddress()->getLastname(),
                        'customer_email'=> $order->getCustomerEmail(),
                        'customer_tel'  => $order->getBillingAddress()->getTelephone(),
                        'amount'        => $order->getGrandTotal(),
                        'currency'      => $order->getOrderCurrencyCode()

                    ];
                }

            }
            
            $customer_countryid =  $_POST['current_countryid'];
            
            $merchantid = $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getMerchantID();
            $vkey = $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getVerifyKey();
            $merchantSecretKey = $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getSecretKey();

            if ($vkey) {
                $redirectGatewayURL = 'https://uat.kiplepay.com/wcgatewayinit.php';     
            } else {
                $redirectGatewayURL = 'https://kiplepay.com/wcgatewayinit.php';    
            }

            $base_url = $this->_objectManager->get('Magento\Store\Model\StoreManagerInterface')->getStore()->getBaseUrl();

            ### Make sure amount is always same format
            $order_amount = number_format(floatval($order->getGrandTotal()),2,'.','');


            $amountVal = str_replace('.', '', $order_amount);
            $amountVal = str_replace(',', '', $amountVal);

            $merchantRefNo = $merchantid .'-'. uniqid() .'-'. $order->getIncrementId();
            $hashValue = sha1($merchantSecretKey . $merchantid . $merchantRefNo . $amountVal);

            $params = array(
                'ord_mercref' => $merchantRefNo,
                'ord_date' => date('Y-m-d H:i:s'),
                'ord_totalamt' => $order_amount,
                'ord_gstamt' => '0.00',
                'ord_fcur' => 'MYR',
                'ord_shipname' => $order->getBillingAddress()->getFirstname()." ".$order->getBillingAddress()->getLastname(),
                'ord_telephone' => $order->getBillingAddress()->getTelephone(),    // To Do - Change to customer mobile number
                'ord_email' => $order->getCustomerEmail(),
                'ord_mercID' => $merchantid,
                'merchant_hashvalue' => $hashValue,
                'ord_returnURL' => $base_url.'seamless/'
            );

            ?>

                <html>
                <head>
                    <script type='text/javascript'>
                        function fnSubmit() {
                            window.document.kiplepaygateway.submit();
                            return;
                        }
                    </SCRIPT>
                </head>
                <body onload='return fnSubmit()'> 
                    <div style="text-align:center; margin-top:10%; font-family:arial; font-size:13px;"> 
                        <div style="margin-bottom:30px;"><img src="https://kiplepay.com/webroot/img/ajax-loader.gif"></div>
                        <div> Please wait while redirecting to Kiplepay Gateway... </div>
                    </div>
                    <form name='kiplepaygateway' id='kiplepaygateway' action='<?= $redirectGatewayURL ?>' method='post'>
                        <input type="hidden" name="ord_mercID" value="<?= $params['ord_mercID']; ?>" />
                        <input type="hidden" name="ord_mercref" value="<?= $params['ord_mercref'] ?>" />
                        <input type="hidden" name="ord_totalamt" value="<?= $params['ord_totalamt']?>" />
                        <input type="hidden" name="ord_gstamt" value="<?= $params['ord_gstamt']?>" />
                        <input type="hidden" name="currency" value="<?= $params['ord_fcur'] ?>" />
                        <input type="hidden" name="ord_shipname" value="<?= $params['ord_shipname']?>" />
                        <input type="hidden" name="ord_telephone" value="<?= $params['ord_telephone'] ?>" />
                        <input type="hidden" name="ord_email" value="<?= $params['ord_email']?>" />
                        <input type="hidden" name="ord_date" value="<?= $params['ord_date']?>" />
                        <input type="hidden" name="merchant_hashvalue" value="<?= $params['merchant_hashvalue']?>" />
                        <input type="hidden" name="ord_returnURL" value="<?= $base_url.'seamless/' ?>" />
                    </form>
                </body>
        <?php //$this->getResponse()->setBody(json_encode($params));
            exit;   
        }
        else if( isset($_REQUEST['returncode'] ) ) //response from KiplePay 
        {             
            $P = $_REQUEST;

            $findOrderId = explode('-', $P['ord_mercref']);

            $om =   \Magento\Framework\App\ObjectManager::getInstance();

            $order = $om->create('Magento\Sales\Api\Data\OrderInterface');
            $order->loadByIncrementId($findOrderId[2]);


            $merchantid = $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getMerchantID();
            $merchantSecretKey = $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getSecretKey();

            $amountVal = str_replace('.', '', $P['ord_totalamt']);
            $amountVal = str_replace(',', '', $amountVal);
        
            $returnHashKey = sha1($merchantSecretKey . $merchantid . $P['ord_mercref'] . $amountVal . $P['returncode']);

            if($P['returncode'] == '100') {
                if($P['ord_key'] == $returnHashKey) {
                    $quoteId = $order->getQuoteId();
                    if ($order->getId() && $order->getState() != 'processing') {
                        $order->setState('processing',true);
                        $order->setStatus('processing',true);

                        $order->addStatusHistoryComment(__('Response from KiplePay - (Transaction Status : CAPTURED).<br/>You have confirmed the order to the customer.' ))
                              ->setIsCustomerNotified(true);
                    
                        //$payment = $order->getPayment();
                        //$mp_amount = $_POST['amount'];
                        //$mp_txnid = $_POST['tranID'];

                        //Create New Invoice and Transaction functions
                        //$this->update_invoice_transaction( $order, $payment, $mp_txnid );
                    }

                    $this->messageManager->addSuccess('Order has been successfully placed!');
                    
                    $this->checkoutSession->setLastQuoteId($quoteId)->setLastSuccessQuoteId($quoteId);
                    $this->checkoutSession->setLastOrderId($order->getId());
                   
                    //page redirect
                    $url_checkoutredirection = 'checkout/onepage/success';
                } else {
                    $this->messageManager->addError('Key is not valid.');
                    $order->setState('fraud',true);
                    $order->setStatus('fraud',true);

                    $history_msg = 'Payment Error: Signature key not match';

                    $order->addStatusHistoryComment(__( $history_msg ))
                          ->setIsCustomerNotified(false);

                    $this->messageManager->addSuccess('Payment Error: Signature key not match');  
                    $url_checkoutredirection = 'checkout/cart';
                }   
            } else if ($P['returncode'] == 'E1' || $P['returncode'] == 'E2') {
                $order->setState('canceled',true);
                $order->setStatus('canceled',true);

                $order->addStatusHistoryComment(__('Response from KiplePay - (Transaction Status : FAILED)'))
                        ->setIsCustomerNotified(false);

                $this->messageManager->addSuccess('Fail to complete payment.');        
                $url_checkoutredirection = 'checkout/cart';   

            } else {
                $commentMsg = "Order has been canceled by you";
                if ($order->getId() && $order->getState() != 'canceled') {
                    $order->registerCancellation($commentMsg)->save();
                }                    

                $this->checkoutSession->restoreQuote();
                $this->messageManager->addError($commentMsg);

                $this->messageManager->addSuccess('Order has been canceled by you!');
                
                $url_checkoutredirection = 'checkout/cart';  
            }
            $order->save();
            $this->_redirect($url_checkoutredirection);
        }
        else if( empty($_POST) ){
           $this->_redirect('/');
        }
    }

    /*public function _ack($P) {

        $P['treq'] = 1;
        while ( list($k,$v) = each($P) ) {
          $postData[]= $k."=".$v;
        }
        $postdata   = implode("&",$postData);
        $url        = "";
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
    }*/
    
    public function update_invoice_transaction($order, $payment, $e){ //$a:$order_id, $b:$order, $c:$payment, $d:$mp_amount, $e:$mp_txnid
        if($order->canInvoice()) {
            $payment
                    ->setTransactionId($e)
                    ->setShouldCloseParentTransaction(1)
                    ->setIsTransactionClosed(0);
            $invoice = $order->prepareInvoice();
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                
                $transaction = $this->transactionFactory->create();
                
                $transaction->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();
                
        }
    
        try {
            $this->orderSender->send($order);
            $quote = $this->quoteRepository->get($order->getQuoteId())->setIsActive(false);
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__('We cannot send the new order email.'));
        }
    
    }

}