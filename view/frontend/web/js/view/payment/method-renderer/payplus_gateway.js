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
                            // Validate URL is a string to prevent jQuery errors
                            if (typeof Response.redirectUrl !== 'string' || !Response.redirectUrl.trim()) {
                                alert($t('Error processing your request'));
                                return false;
                            }
                            
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
                // Validate URL parameter to prevent jQuery errors
                if (typeof paymentUrl !== 'string' || !paymentUrl.trim()) {
                    alert($t('Error processing your request'));
                    return false;
                }

                // Try to get header height with multiple fallback selectors
                var headerHeight = 0;
                var headerFound = false;
                var headerSelectors = [
                    '.page-header',
                    '.header',
                    '.page-wrapper .header-container',
                    '.page-top',
                    '.header-container',
                    '.top-container',
                    '.navbar',
                    'header',
                    '.site-header',
                    '.main-header'
                ];

                // Try each selector until we find a header
                for (var i = 0; i < headerSelectors.length; i++) {
                    var header = $(headerSelectors[i]);
                    if (header.length > 0 && header.is(':visible')) {
                        headerHeight = header.outerHeight();
                        headerFound = true;
                        console.log('PayPlus: Found header with selector:', headerSelectors[i], 'Height:', headerHeight);
                        break;
                    }
                }

                // If no header found or header height is unreasonable, use fallback full-page mode
                if (!headerFound || headerHeight < 10 || headerHeight > $(window).height() / 2) {
                    console.log('PayPlus: Header not found or invalid height (' + headerHeight + 'px). Using full-page fallback mode.');
                    headerHeight = 0;
                    headerFound = false;
                }

                // Calculate available height (viewport height minus header)
                var availableHeight = $(window).height() - headerHeight;

                // Create full-width overlay
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

                // Create header bar with close button (always shown, even in fallback mode)
                var headerBar = $('<div>', {
                    css: {
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        padding: '15px 20px',
                        backgroundColor: '#fff',
                        borderBottom: '1px solid #ddd',
                        flexShrink: 0 // Prevent header bar from shrinking
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
                    }
                });

                closeButton.hover(
                    function() { $(this).css('backgroundColor', '#e9e9e9'); },
                    function() { $(this).css('backgroundColor', '#f5f5f5'); }
                );

                closeButton.click(function() {
                    overlay.remove();
                    $(window).off('resize.payplus message.payplus');
                    $('.checkout-container, .page-main').show();
                    // Redirect back to cart
                    window.location.href = url.build('checkout/cart');
                });

                headerBar.append(title, closeButton);

                // Create iframe container (takes remaining space)
                var iframeContainer = $('<div>', {
                    css: {
                        flex: '1',
                        position: 'relative',
                        backgroundColor: '#fff',
                        minHeight: '400px' // Ensure minimum height
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
                        backgroundColor: '#fff',
                        zIndex: 1
                    }
                });

                // Add CSS animation for loading spinner
                if (!$('#payplus-spinner-css').length) {
                    $('<style id="payplus-spinner-css">@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>').appendTo('head');
                }

                // Create iframe with error handling
                var iframe = $('<iframe>', {
                    css: {
                        width: '100%',
                        height: '100%',
                        border: 'none',
                        display: 'none',
                        zIndex: 2
                    }
                });

                // Handle iframe load success
                iframe.on('load', function() {
                    console.log('PayPlus: Iframe loaded successfully');
                    loadingIndicator.hide();
                    $(this).show();
                });

                // Handle iframe load errors
                iframe.on('error', function() {
                    console.error('PayPlus: Iframe failed to load');
                    loadingIndicator.html('<div style="text-align: center; padding: 50px; color: #d32f2f;"><p>Failed to load payment form. Please try again.</p><button onclick="window.location.reload()" style="background: #007bdb; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">Retry</button></div>');
                });

                // Set iframe source after setup to ensure error handlers are in place
                iframe.attr('src', paymentUrl);

                // Assemble the overlay
                iframeContainer.append(loadingIndicator, iframe);
                overlay.append(headerBar, iframeContainer);
                
                // Add to body
                $('body').append(overlay);

                // Hide checkout content
                $('.checkout-container, .page-main').hide();

                // Handle window resize to maintain proper positioning
                $(window).on('resize.payplus', function() {
                    if (headerFound) {
                        // Only recalculate header height if we initially found one
                        var newHeaderHeight = 0;
                        for (var i = 0; i < headerSelectors.length; i++) {
                            var header = $(headerSelectors[i]);
                            if (header.length > 0 && header.is(':visible')) {
                                newHeaderHeight = header.outerHeight();
                                break;
                            }
                        }
                        if (newHeaderHeight > 0) {
                            headerHeight = newHeaderHeight;
                        }
                    }
                    var newAvailableHeight = $(window).height() - headerHeight;
                    
                    overlay.css({
                        top: headerHeight + 'px',
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

                console.log('PayPlus: Full-screen iframe initialized', {
                    headerFound: headerFound,
                    headerHeight: headerHeight,
                    availableHeight: availableHeight,
                    paymentUrl: paymentUrl.substring(0, 50) + '...'
                });
            },
        });


    }
);
