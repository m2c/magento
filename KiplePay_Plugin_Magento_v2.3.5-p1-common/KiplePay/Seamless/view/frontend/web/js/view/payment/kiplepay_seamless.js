/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'kiplepayseamlessdeco',
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        $,
        ms,
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'kiplepay_seamless',
                component: 'KiplePay_Seamless/js/view/payment/method-renderer/kiplepay_seamless'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
