<?php
 
namespace KiplePay\Seamless\Helper;
 
 
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const MER_GATE_ID = 'payment/kiplepay_seamless/merchant_gateway_id';
    const MER_GATE_KEY = 'payment/kiplepay_seamless/merchant_gateway_key';
    const MER_GATE_SECRETKEY ='payment/kiplepay_seamless/merchant_gateway_secretkey';
    const KIPLEPAY_CHANNELS ='payment/kiplepay_seamless/channels_payment';
 
    public function getMerchantID()
    {
        return $this->scopeConfig->getValue(
            self::MER_GATE_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function getVerifyKey()
    {
        return $this->scopeConfig->getValue(
            self::MER_GATE_KEY,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function getSecretKey()
    {
        return $this->scopeConfig->getValue(
            self::MER_GATE_SECRETKEY,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
    
    public function getActiveChannels(){
        return $this->scopeConfig->getValue(
            self::KIPLEPAY_CHANNELS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
    
}