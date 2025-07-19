/*browser:true*/
/*global define*/
define(
  [
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/payment/additional-validators'
  ],
  function (Component, additionalValidators) {
    'use strict';

    return Component.extend({
      defaults: {
        template: 'VentiPay_Gateway/payment/form',
        active: true,
        code: 'ventipay_gateway'
      },
      initialize: function () {
        var self = this;
        self._super();
        return self;
      },
      getCode: function () {
        return this.code;
      },
      isAvailable: function () {
        return true;
      },
      isActive() {
        var active = this.getCode() === this.isChecked();
        this.active(active);
        return active;
      },
      getData() {
        return {
          method: this.getCode(),
        };
      },
      placeOrder(data, e) {
        if (e) {
          e.preventDefault();
        }

        if (!this.validate() || !additionalValidators.validate()) {
          return false;
        }

        this.isPlaceOrderActionAllowed(false);
        this.getPlaceOrderDeferredObject()
          .fail(() => {
            this.isPlaceOrderActionAllowed(true);
          })
          .done((orderId) => {
            fetch('/ventipay/checkout/start', {
              method: 'POST',
              body: JSON.stringify({ order_id: orderId }),
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
              }
            })
              .then(response => response.json())
              .then((data) => {
                window.location.href = data.url;
              })
              .catch((err) => {
                // TMP: Empty
              });
          })

        return true;
      }
    });
  }
);
