/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'ventipay_gateway',
                component: 'VentiPay_Gateway/js/view/payment/method-renderer/ventipay_gateway-method'
            }
        );
        return Component.extend({});
    }
);
