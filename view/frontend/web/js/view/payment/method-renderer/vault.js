define([
    'Magento_Vault/js/view/payment/method-renderer/vault',
], function (VaultComponent) {
    'use strict';
    return VaultComponent.extend({
        defaults: {
            template: 'Payplus_PayplusGateway/payment/vault'
        },
        /**
 * Get last 4 digits of card
 * @returns {String}
 */
        getMaskedCard: function () {
            return "**** **** **** " + this.details.maskedCC;
        },

        /**
         * Get expiration date
         * @returns {String}
         */
        getExpirationDate: function () {
            return this.details.expirationDate;
        },

        /**
 * Return the payment method code.
 * @returns {string}
 */
        getCode: function () {
            return 'payplus_cc_vault';
        },

        getToken: function () {
            return this.publicHash;
        },
        showCvvVerify: function () {
            return false;
        },

        getCardType: function () {
            return this.details.type;
        },

        getIcons: function (type) {
            if (type == 'generic') {
                return {
                    url : require.toUrl('Payplus_PayplusGateway/images/generic-card.gif'),
                    width : 46,
                    height : 30
                };
            }
            return window.checkoutConfig.payment.ccform.icons.hasOwnProperty(type) ?
                window.checkoutConfig.payment.ccform.icons[type]
                : false;
        },
    });
});