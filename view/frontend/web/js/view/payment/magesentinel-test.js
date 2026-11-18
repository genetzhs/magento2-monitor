define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], function (Component, rendererList) {
    'use strict';
    rendererList.push({
        type: 'magesentinel_test',
        component: 'MageSentinel_Monitor/js/view/payment/method-renderer/magesentinel-test'
    });
    return Component.extend({});
});
