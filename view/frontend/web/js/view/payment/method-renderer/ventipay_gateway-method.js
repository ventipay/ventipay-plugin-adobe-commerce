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
          .done(async (orderId) => {
            try {
              const fetching = await fetch('/payment_ventipay/checkout/create', {
                method: 'POST',
                body: JSON.stringify({ orderId }),
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                },
              });
              const data = await fetching.json();

              window.location.href = data.url;
            } catch (error) {
            }
          })

        return true;
      }
    });
  }
);
