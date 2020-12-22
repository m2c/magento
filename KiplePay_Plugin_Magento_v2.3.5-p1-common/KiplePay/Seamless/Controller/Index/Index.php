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
    public $isSplitOrders = false;

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
    ) {
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
        if (isset($_POST['payment_options']) && $_POST['payment_options'] == "1") {
            // Attempt to store the cart into magento system
            // This function should be execute during KiplePay selection page AFTER the address selection
            // Begin calling Magento API
            try {
                $om =   \Magento\Framework\App\ObjectManager::getInstance();

                ### At first time, create quote and order
                $cartData = $om->create('\Magento\Checkout\Model\Cart')->getQuote();

                $quote = $om->create('\Magento\Quote\Model\Quote');
                $quote->load($cartData->getId());

                $quote->getPayment()->setMethod('kiplepay_seamless');

                $customerSess = $om->create('\Magento\Customer\Model\Session');
                $checkoutHelperData = $om->create('\Magento\Checkout\Helper\Data');

                if ($_POST['current_email'] == '') {
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

                if ($customerType == \Magento\Checkout\Model\Type\Onepage::METHOD_GUEST) {
                    $quote->setCustomerId(null)
                        ->setCustomerEmail($_POST['current_email'])
                        ->setCustomerIsGuest(true)
                        ->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
                }


                if ($quote) {
                    $cartManagement = $om->create('\Magento\Quote\Model\QuoteManagement');
                    $order = $cartManagement->submit($quote);

                    if ($order) {
                        $order_step2 = $om->create('\Magento\Sales\Model\Order')
                            ->load($order->getId());

                        $order_step2->setState("pending_payment")->setStatus("pending_payment");

                        $order_step2->save();
                    }
                }

                ### Begin to save quote and order in session
                $checkoutSession = $om->create('\Magento\Checkout\Model\Session');

                ### initial order created, save their data in session
                if ($order) {
                    $checkoutSession->setLastQuoteId($cartData->getId())->setLastSuccessQuoteId($cartData->getId());
                    $checkoutSession->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId())
                        ->setLastOrderStatus('pending_payment');
                }

                ### When 2nd attempt to make payment but above order create is error then use the session
                if (!$order) {
                    $sess_quotedata = $checkoutSession->getData();

                    if (isset($sess_quotedata['last_real_order_id']) && $sess_quotedata['last_real_order_id'] != null) {
                        $lastOId = $sess_quotedata['last_real_order_id'];

                        $order = $om->create('\Magento\Sales\Api\Data\OrderInterface');
                        $order->loadByIncrementId($lastOId);
                    }
                }

                $vkey = $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getVerifyKey();

                if ($vkey) {
                    $redirectGatewayURL = 'https://uat.kiplepay.com/wcgatewayinit.php';
                } else {
                    $redirectGatewayURL = 'https://kiplepay.com/wcgatewayinit.php';
                }

                $base_url = $this->_objectManager->get('Magento\Store\Model\StoreManagerInterface')->getStore()->getBaseUrl();
                $cartAmount = number_format(floatval($order->getGrandTotal()), 2, '.', '');

                $orderParams = $this->getOtherParams($order, $cartAmount);
                $params = array(
                    'ord_mercref' => $orderParams['ord_mercref'],
                    'ord_date' => date('Y-m-d H:i:s'),
                    'ord_totalamt' => $orderParams['ord_totalamt'],
                    'ord_gstamt' => '0.00',
                    'ord_fcur' => 'MYR',
                    'version' => '2.0',
                    'ord_shipname' => $order->getBillingAddress()->getFirstname()." ".$order->getBillingAddress()->getLastname(),
                    'ord_telephone' => $order->getBillingAddress()->getTelephone(),    // To Do - Change to customer mobile number
                    'ord_email' => $order->getCustomerEmail(),
                    'ord_mercID' => $orderParams['ord_mercID'],
                    'merchant_hashvalue' => $orderParams['merchant_hashvalue'],
                    'ord_returnURL' => $base_url.'seamless/',
                    'is_split_orders' => $orderParams['is_split_orders'],
                    'all_split_totalamt' => $orderParams['all_split_totalamt'],
                    'ord_mmercID' => $orderParams['ord_mmercID'],
                    'dynamic_callback_url' => $orderParams['dynamic_callback_url'],
                    'promo_bin' => $orderParams['promo_bin'],
                );
                if (!empty($_COOKIE['PHPSESSID'])) {
                    header('Set-Cookie: PHPSESSID' . '=' . $_COOKIE['PHPSESSID'] . '; SameSite=None; Secure');
                }
            } catch (\Exception $e) {
                $this->messageManager->addError($e->getMessage());
                $url_checkoutredirection = 'checkout/cart';
                $this->_redirect($url_checkoutredirection);
                return;
            }

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
                        <input type="hidden" name="version" value="<?= $params['version']?>" />
                        <input type="hidden" name="merchant_hashvalue" value="<?= $params['merchant_hashvalue']?>" />
                        <input type="hidden" name="ord_returnURL" value="<?= $base_url.'seamless/' ?>" />
                        <input type="hidden" name="is_split_orders" value="<?= $params['is_split_orders'] ?>" />
                        <input type="hidden" name="all_split_totalamt" value="<?= $params['all_split_totalamt'] ?>" />
                        <input type="hidden" name="ord_mmercID" value="<?= $params['ord_mmercID'] ?>" />
                        <input type="hidden" name="dynamic_callback_url" value="<?= $params['dynamic_callback_url'] ?>" />
                        <input type="hidden" name="promo_bin" value="<?= $params['promo_bin'] ?>" />
                    </form>
                </body>
        <?php //$this->getResponse()->setBody(json_encode($params));
            exit;
        } elseif (isset($_REQUEST['returncode'])) { //response from KiplePay
            try {
                $P = $_REQUEST;

                $findOrderId = explode('-', $P['ord_mercref']);

                $om =   \Magento\Framework\App\ObjectManager::getInstance();

                $order = $om->create('Magento\Sales\Api\Data\OrderInterface');
                $order->loadByIncrementId($findOrderId[2]);

                if (!empty($P['ord_mmercID'])) {
                    $merchantID = $P['ord_mmercID'];
                } else {
                    $merchantID = $P['ord_mercID'];
                }

                $merchantSecretKey = $this->getSecretKey($merchantID);

                $amountVal = str_replace(['.', ',', '|'], '', $P['ord_totalamt']);

                $returnHashKey = sha1($merchantSecretKey . $merchantID . $P['ord_mercref'] . $amountVal . $P['returncode']);

                if ($P['returncode'] == '100') {
                    if ($P['ord_key'] == $returnHashKey) {
                        $quoteId = $order->getQuoteId();
                        if ($order->getId() && $order->getState() != 'processing') {
                            $order->setState('processing', true);
                            $order->setStatus('processing', true);

                            $order->addStatusHistoryComment(__('Response from KiplePay - (Transaction Status : CAPTURED).<br/>You have confirmed the order to the customer.'))
                                ->setIsCustomerNotified(true);
                        }

                        $this->messageManager->addSuccess('Order has been successfully placed!');

                        $this->checkoutSession->setLastQuoteId($quoteId)->setLastSuccessQuoteId($quoteId);
                        $this->checkoutSession->setLastOrderId($order->getId());

                        //page redirect
                        $url_checkoutredirection = 'checkout/onepage/success';
                    } else {
                        $this->messageManager->addError('Key is not valid.');
                        $commentMsg = "Key is not valid.";
                        if ($order->getId() && $order->getState() != 'canceled') {
                            $order->registerCancellation($commentMsg)->save();
                        }
                        $order->addStatusHistoryComment(__($commentMsg))
                            ->setIsCustomerNotified(false);

                        $this->checkoutSession->restoreQuote();

                        $this->messageManager->addError('Payment Error: Signature key not match');
                        $url_checkoutredirection = 'checkout/cart';
                    }
                } elseif ($P['returncode'] == 'E1' || $P['returncode'] == 'E2' || $P['returncode'] == '-1') {
                    $commentMsg = "Fail to complete payment.";
                    if ($order->getId() && $order->getState() != 'canceled') {
                        $order->registerCancellation($commentMsg)->save();
                    }
                    $order->addStatusHistoryComment(__($commentMsg))
                        ->setIsCustomerNotified(false);

                    $this->checkoutSession->restoreQuote();

                    $this->messageManager->addError('Fail to complete payment.');
                    $url_checkoutredirection = 'checkout/cart';
                } else {
                    $commentMsg = "Order has been canceled by you";
                    if ($order->getId() && $order->getState() != 'canceled') {
                        $order->registerCancellation($commentMsg)->save();
                    }

                    $this->checkoutSession->restoreQuote();
                    $this->messageManager->addError($commentMsg);

                    $this->messageManager->addError('Order has been canceled by you!');

                    $url_checkoutredirection = 'checkout/cart';
                }
                $order->save();
                $this->_redirect($url_checkoutredirection);
            } catch (\Exception $e) {
                $this->messageManager->addError($e->getMessage());
                $url_checkoutredirection = 'checkout/cart';
                $this->_redirect($url_checkoutredirection);
                return;
            }
        } elseif (empty($_POST)) {
            $this->_redirect('/');
        }
    }

    /**
     * Get some key order information
     * @param $order
     * @param $cartAmount
     * @return array
     */
    public function getOtherParams($order, $cartAmount)
    {
        $this->isSplitOrders();
        $merchantID = $this->getMerchantID();
        $orderAmount = $this->getOrderAmount($cartAmount);

        if ($this->isSplitOrders) {
            $hashMerchant = $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getMerchantID();
            $masterMerchantID = $hashMerchant;
            $totalAmount = 0;
            $amounts = explode('|', $orderAmount);
            foreach ($amounts as $amount) {
                $totalAmount += $amount;
            }
            $splitTotalAmount = number_format(floatval($totalAmount), 2, '.', '');
        } else {
            $hashMerchant = $merchantID;
            $splitTotalAmount = 0;
            $masterMerchantID = '';
        }
        $merchantSecretKey = $this->getSecretKey($hashMerchant);


        $merchantRefNo = $hashMerchant .'-'. uniqid() .'-'. $order->getIncrementId();
        $amountVal = str_replace(['.', ',', '|'], '', $orderAmount);
        $hashValue = sha1($merchantSecretKey . $hashMerchant . $merchantRefNo . $amountVal);
        return [
                'is_split_orders' => $this->isSplitOrders,
                'ord_mercID' => $merchantID,
                'ord_totalamt' => $orderAmount,
                'all_split_totalamt' => $splitTotalAmount,
                'ord_mercref' => $merchantRefNo,
                'merchant_hashvalue' => $hashValue,
                'ord_mmercID' => $masterMerchantID,
                'dynamic_callback_url' => $this->getDynamicUrl(),
                'promo_bin' => $this->getPromoBin(),
        ];
    }

    /**
     * Determine whether the order is associated with multiple merchants（split order)
     */
    public function isSplitOrders()
    {
        $this->isSplitOrders = false;
        return;
    }

    /**
     * if is split orders,merchant need to be separated by '|'
     * @return string
     */
    public function getMerchantID()
    {
        if ($this->isSplitOrders) {
            return '89990321|89997643|89990325';
        } else {
            // If you do not use the configured merchant ID,
            // you need to modify the code here
            return $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getMerchantID();
        }
    }

    /**
     * If the hashMerchant and the configured merchant are equal,
     * use the configured key,
     * @param $hashMerchant
     * @return string
     */
    public function getSecretKey($hashMerchant)
    {
        $settingMerchant = $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getMerchantID();
        if ($hashMerchant == $settingMerchant) {
            // the configured merchant secret key
            return  $this->_objectManager->create('KiplePay\Seamless\Helper\Data')->getSecretKey();
        } else {
            // Need to obtain the secret key of the corresponding merchant
            return 'webcash@123!';
        }
    }

    /**
     * if is split orders,amount need to be separated by '|'
     * @param $cartAmount
     * @return string
     */
    public function getOrderAmount($cartAmount)
    {
        if ($this->isSplitOrders) {
            return '1.77|0.22|0.33';
        } else {
            return $cartAmount;
        }
    }

    /**
     * get dynamic callback url
     * if you do not use this feature,please return ''
     * @return string
     */
    public function getDynamicUrl()
    {
        return '';
    }

    /**
     * get promo bin
     * if you do not use this feature,please return ''
     * @return string
     */
    public function getPromoBin()
    {
        return '';
    }
}
