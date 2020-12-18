<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace KiplePay\Seamless\Model\Source;

class Channel implements \Magento\Framework\Option\ArrayInterface
{
     /**
     * Returns array to be used in multiselect on back-end
     *
     * @return array
     */
    public function toOptionArray()
    {
 		$option = [];
		
	return $option;
    }
    
    
    /*
     * Get options in "key-value" format
      * @return array
       */
       public function toArray()
       {
           $choose = [];
           
           return $choose;
       }
}