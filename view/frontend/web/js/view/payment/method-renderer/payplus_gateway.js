/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'Magento_Checkout/js/model/payment/additional-validators',
        'jquery',
        'ko',
        'mage/url',
        'Magento_Vault/js/view/payment/vault-enabler',
        'Magento_Checkout/js/view/payment/default',
        'mage/translate'
    ],

    function (
        additionalValidators,
        $,
        ko,
        url,
        VaultEnabler,
        Component,
        $t
    ) {

        'use strict';

        return Component.extend({
            redirectAfterPlaceOrder: false,
            getIframeURL: ko.observable(null),
            iframeHeight: window.checkoutConfig.payment.payplus_gateway.iframe_height,
            defaults: {
                template: 'Payplus_PayplusGateway/payment/form',
                transactionResult: '',
            },


            initialize: function () {
                var self = this;

                self._super();
                this.vaultEnabler = new VaultEnabler();
                this.vaultEnabler.setPaymentCode(this.getVaultCode());
                return self;
            },

            initObservable: function () {

                this._super()
                    .observe([
                        'transactionResult'
                    ]);
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
                let bHidePayplusLogo =window.checkoutConfig.payment[method].bHidePayplusLogo
                return bHidePayplusLogo;
            },
            getActive:function (method){

                let active =window.checkoutConfig.payment[method].active
                return active;
            },
            getTitle:function (method){
                let title =window.checkoutConfig.payment[method].title
                return title;
            },

            getCode: function () {
                return 'payplus_gateway';
            },

            getData: function () {
                let payplusDataMethod = $('.payplus-select-method:checked').attr("payplus-data-method");
                var data = {
                    'method': this.item.method,
                    'additional_data': {
                        'transaction_result': this.transactionResult(),
                        'payplusmethodreq': payplusDataMethod,
                    }
                };

                data['additional_data'] = _.extend(data['additional_data'], this.additionalData);
                this.vaultEnabler.visitAdditionalData(data);

                return data;
            },

            placeOrder: function (data, event) {
                var self = this;
                if (event) {
                    event.preventDefault();
                }

                if (this.validate() &&
                    additionalValidators.validate() &&
                    this.isPlaceOrderActionAllowed() === true
                ) {
                    this.isPlaceOrderActionAllowed(false);

                    this.getPlaceOrderDeferredObject()
                        .done(
                            function (response) {
                                self.afterPlaceOrder(response);

                                if (self.redirectAfterPlaceOrder) {
                                    redirectOnSuccessAction.execute();
                                }
                            }
                        ).always(
                            function () {
                                self.isPlaceOrderActionAllowed(true);
                            }
                        );

                    return true;
                }

                return false;
            },


            afterPlaceOrder: function (Response) {

                if (isNaN(Response)) {
                    alert($t('Error processing your request'));
                    return false;
                }
                $.ajax({
                    url: url.build(`/payplus_gateway/ws/getredirect/orderid/${Response}`),
                    dataType: 'json',
                    type: 'get',
                    success: (Response) => {
                        if (Response.status == 'success' && Response.redirectUrl) {
                            if (window.checkoutConfig.payment.payplus_gateway.form_type == 'iframe') {
                                this.getIframeURL(Response.redirectUrl);
                            } else if (window.checkoutConfig.payment.payplus_gateway.form_type == 'iframe_inline') {
                                this.showFullScreenIframe(Response.redirectUrl);
                            } else {
                                window.location.replace(Response.redirectUrl);
                            }
                        } else {
                            alert($t("Error.")); // for now
                        }
                    }
                });
            },

            showFullScreenIframe: function(paymentUrl) {
                // Get header height to position iframe below it
                var headerHeight = 0;
                var header = $('.page-header, .header, .page-wrapper .header-container, .page-top');
                if (header.length > 0) {
                    headerHeight = header.outerHeight();
                }

                // Calculate available height (viewport height minus header)
                var availableHeight = $(window).height() - headerHeight;

                // Create full-width overlay starting after header
                var overlay = $('<div>', {
                    id: 'payplus-fullscreen-overlay',
                    css: {
                        position: 'fixed',
                        top: headerHeight + 'px',
                        left: 0,
                        width: '100%',
                        height: availableHeight + 'px',
                        backgroundColor: 'rgba(0, 0, 0, 0.9)',
                        zIndex: 10000,
                        display: 'flex',
                        flexDirection: 'column'
                    }
                });

                // Create header bar with close button
                var headerBar = $('<div>', {
                    css: {
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        padding: '15px 20px',
                        backgroundColor: '#fff',
                        borderBottom: '1px solid #ddd'
                    }
                });

                var title = $('<h3>', {
                    text: $t('Complete Your Payment'),
                    css: {
                        margin: 0,
                        fontSize: '18px',
                        color: '#333'
                    }
                });

                // Create close button
                var closeButton = $('<button>', {
                    text: $t('Close'),
                    css: {
                        background: '#f5f5f5',
                        border: '1px solid #ddd',
                        borderRadius: '4px',
                        padding: '8px 15px',
                        fontSize: '14px',
                        cursor: 'pointer',
                        color: '#666'
                    },
                    hover: function() {
                        $(this).css('backgroundColor', '#e9e9e9');
                    },
                    click: function() {
                        overlay.remove();
                        // Optionally redirect back to cart
                        window.location.href = url.build('checkout/cart');
                    }
                });

                headerBar.append(title, closeButton);

                // Create iframe container (takes remaining space)
                var iframeContainer = $('<div>', {
                    css: {
                        flex: '1',
                        position: 'relative',
                        backgroundColor: '#fff'
                    }
                });

                // Create loading indicator
                var loadingIndicator = $('<div>', {
                    html: '<div style="text-align: center; padding: 50px;"><div style="display: inline-block; width: 40px; height: 40px; border: 3px solid #f3f3f3; border-top: 3px solid #007bdb; border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin-top: 15px; color: #666; font-size: 16px;">' + $t('Loading payment form...') + '</p></div>',
                    css: {
                        position: 'absolute',
                        top: 0,
                        left: 0,
                        width: '100%',
                        height: '100%',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        backgroundColor: '#fff'
                    }
                });

                // Add CSS animation for loading spinner
                if (!$('#payplus-spinner-css').length) {
                    $('<style id="payplus-spinner-css">@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>').appendTo('head');
                }

                // Create iframe
                var iframe = $('<iframe>', {
                    src: paymentUrl,
                    css: {
                        width: '100%',
                        height: '100%',
                        border: 'none',
                        display: 'none'
                    },
                    load: function() {
                        loadingIndicator.hide();
                        $(this).show();
                    }
                });

                // Assemble the overlay
                iframeContainer.append(loadingIndicator, iframe);
                overlay.append(headerBar, iframeContainer);
                
                // Add to body
                $('body').append(overlay);

                // Hide checkout content
                $('.checkout-container, .page-main').hide();

                // Handle window resize to maintain proper positioning
                $(window).on('resize.payplus', function() {
                    var newHeaderHeight = 0;
                    var header = $('.page-header, .header, .page-wrapper .header-container, .page-top');
                    if (header.length > 0) {
                        newHeaderHeight = header.outerHeight();
                    }
                    var newAvailableHeight = $(window).height() - newHeaderHeight;
                    
                    overlay.css({
                        top: newHeaderHeight + 'px',
                        height: newAvailableHeight + 'px'
                    });
                });

                // Listen for payment completion messages
                $(window).on('message.payplus', function(event) {
                    var data = event.originalEvent.data;
                    if (data && data.payplus) {
                        // Cleanup
                        overlay.remove();
                        $(window).off('resize.payplus message.payplus');
                        $('.checkout-container, .page-main').show();
                        
                        switch(data.status) {
                            case 'success':
                                window.location.href = url.build('checkout/onepage/success');
                                break;
                            case 'failure':
                                window.location.href = url.build('checkout/onepage/failure');
                                break;
                            case 'cancel':
                                window.location.href = url.build('checkout/cart');
                                break;
                        }
                    }
                });
            },
        });


    }
);
