/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define([
  "Magento_Checkout/js/model/payment/additional-validators",
  "jquery",
  "ko",
  "mage/url",
  "Magento_Vault/js/view/payment/vault-enabler",
  "Magento_Checkout/js/view/payment/default",
], function (additionalValidators, $, ko, url, VaultEnabler, Component) {
  "use strict";

  return Component.extend({
    redirectAfterPlaceOrder: false,
    getIframeURL: ko.observable(null),
    iframeHeight: window.checkoutConfig.payment.payplus_gateway.iframe_height,
    defaults: {
      template: "Payplus_PayplusGateway/payment/form",
      transactionResult: "",
    },

    initialize: function () {
      var self = this;

      self._super();
      this.vaultEnabler = new VaultEnabler();
      this.vaultEnabler.setPaymentCode(this.getVaultCode());
      return self;
    },

    initObservable: function () {
      this._super().observe(["transactionResult"]);
      return this;
    },

    /**
     * @returns {Boolean}
     */
    isVaultEnabled: function () {
      return this.vaultEnabler.isVaultEnabled();
    },

    /**
     * Returns vault code.
     *
     * @returns {String}
     */
    getVaultCode: function () {
      return window.checkoutConfig.payment[this.getCode()].ccVaultCode;
    },

    bShowPayplusLogo: (method) => {
      let bHidePayplusLogo =
        window.checkoutConfig.payment[method].bHidePayplusLogo;
      return bHidePayplusLogo;
    },
    getActive: function (method) {
      let active = window.checkoutConfig.payment[method].active;
      return active;
    },
    getTitle: function (method) {
      let title = window.checkoutConfig.payment[method].title;
      return title;
    },

    getCode: function () {
      return "payplus_gateway";
    },

    getData: function () {
      let payplusDataMethod = $(".payplus-select-method:checked").attr(
        "payplus-data-method"
      );
      var data = {
        method: this.item.method,
        additional_data: {
          transaction_result: this.transactionResult(),
          payplusmethodreq: payplusDataMethod,
        },
      };

      data["additional_data"] = _.extend(
        data["additional_data"],
        this.additionalData
      );
      this.vaultEnabler.visitAdditionalData(data);

      return data;
    },

    placeOrder: function (data, event) {
      var self = this;
      if (event) {
        event.preventDefault();
      }

      if (
        this.validate() &&
        additionalValidators.validate() &&
        this.isPlaceOrderActionAllowed() === true
      ) {
        this.isPlaceOrderActionAllowed(false);

        // Check if auto-cancel feature is enabled
        var autoCancelEnabled =
          window.checkoutConfig.payment.payplus_gateway
            .auto_cancel_pending_orders;

        if (autoCancelEnabled) {
          // First cleanup any pending orders before placing new one
          this.cleanupPendingOrders().always(function () {
            // Proceed with placing the order regardless of cleanup result
            self.proceedWithOrder();
          });
        } else {
          // Proceed directly without cleanup
          this.proceedWithOrder();
        }

        return true;
      }

      return false;
    },

    proceedWithOrder: function () {
      var self = this;
      this.getPlaceOrderDeferredObject()
        .done(function (response) {
          self.afterPlaceOrder(response);

          if (self.redirectAfterPlaceOrder) {
            redirectOnSuccessAction.execute();
          }
        })
        .always(function () {
          self.isPlaceOrderActionAllowed(true);
        });
    },

    cleanupPendingOrders: function () {
      var deferred = $.Deferred();

      $.ajax({
        url: url.build("/payplus_gateway/ws/cleanuppendingorders"),
        type: "POST",
        dataType: "json",
        timeout: 5000, // 5 second timeout
        success: function (response) {
          if (response.success && response.canceled_orders > 0) {
            console.log(
              "PayPlus: Cleaned up " +
                response.canceled_orders +
                " pending orders"
            );
          }
          deferred.resolve(response);
        },
        error: function (xhr, status, error) {
          console.warn(
            "PayPlus: Cleanup request failed, continuing with order placement"
          );
          deferred.resolve({ success: false, error: error });
        },
      });

      return deferred.promise();
    },

    afterPlaceOrder: function (Response) {
      if (isNaN(Response)) {
        alert("Error processing your request");
        return false;
      }

      $.ajax({
        url: url.build(`/payplus_gateway/ws/getredirect/orderid/${Response}`),
        dataType: "json",
        type: "get",
        success: (Response) => {
          // Reload cart data AFTER GetRedirect has restored the quote
          require(["Magento_Customer/js/customer-data"], function (
            customerData
          ) {
            customerData.reload(["cart"], false);

            // Also invalidate and reload to force fresh data
            customerData.invalidate(["cart"]);
            customerData.reload(["cart"], false);
          });

          if (Response.status == "success" && Response.redirectUrl) {
            if (
              window.checkoutConfig.payment.payplus_gateway.form_type ==
              "iframe"
            ) {
              this.getIframeURL(Response.redirectUrl);
            } else {
              window.location.replace(Response.redirectUrl);
            }
          } else {
            alert("Error."); // for now
          }
        },
      });
    },
  });
});
