/**
 * PayPal Currency Converter PRO - Checkout JS
 * 
 * Handles the display of converted currency values on the checkout page
 */
(function($) {
    'use strict';
    
    // Initialize on document ready
    $(document).ready(function() {
        // Debug ppcc_data if available
        if (typeof ppcc_data !== 'undefined') {
            console.log('PPCC Data:', ppcc_data);
        } else {
            console.log('PPCC Data not found');
        }
        
        ppccCheckout.init();
        
        // Apply PayPal SDK fixes for non-decimal currencies
        ppccCheckout.fixPayPalSdk();
    });
    
    // Initialize when checkout is updated
    $(document.body).on('updated_checkout', function() {
        ppccCheckout.updateDisplay();
        ppccCheckout.fixPayPalSdk();
    });
    
    // Initialize when payment method changes
    $(document.body).on('payment_method_selected', function() {
        ppccCheckout.updateDisplay();
        ppccCheckout.fixPayPalSdk();
    });
    
    // Main object for checkout functionality
    var ppccCheckout = {
        // Initialize
        init: function() {
            this.updateDisplay();
            this.bindEvents();
        },
        
        // Bind events
        bindEvents: function() {
            // Update display when payment method changes
            $('body').on('change', 'input[name="payment_method"]', function() {
                ppccCheckout.updateDisplay();
            });
            
            // Handle when woocommerce updates checkout fragments
            $(document.body).on('update_checkout', function() {
                // Set a timeout to ensure our shadow elements get updated
                setTimeout(function() {
                    ppccCheckout.updateDisplay();
                }, 200);
            });
        },
        
        // Update the display of converted values
        updateDisplay: function() {
            // Check if a PayPal payment method is selected
            if (!this.isPayPalSelected()) {
                this.hideConversionInfo();
                return;
            }
            
            // Show conversion info
            this.showConversionInfo();
            
            // If we have ppcc_data from localization, use it to calculate our own values
            if (typeof ppcc_data !== 'undefined') {
                this.calculateConversionTotals();
            } else {
                // Fallback to using shadow elements
                this.updateConversionTotals();
            }
        },
        
        // Calculate totals based on current cart values and conversion rate
        calculateConversionTotals: function() {
            if (typeof ppcc_data === 'undefined') {
                return;
            }
            
            // Get cart totals - either from original displays or from cart
            var cartTotal = 0;
            var originalCartTotal = $('.ppcc-original-cart-total');
            
            if (originalCartTotal.length > 0) {
                console.log('Original cart total element found: ' + originalCartTotal.text());
                cartTotal = this.parsePrice(originalCartTotal.text());
            } else {
                // Fallback to standard WooCommerce elements
                var subtotalElement = $('.cart-subtotal .amount');
                console.log('Fallback cart total element: ' + subtotalElement.text());
                cartTotal = this.parsePrice(subtotalElement.text());
            }
            
            console.log('Final parsed cart total: ' + cartTotal);
            
            // Do the same with all other totals
            var shippingTotal = 0;
            var originalShippingTotal = $('.ppcc-original-shipping-total');
            if (originalShippingTotal.length > 0) {
                console.log('Original shipping total element found: ' + originalShippingTotal.text());
                shippingTotal = this.parsePrice(originalShippingTotal.text());
            } else {
                var shippingElement = $('.shipping .amount');
                console.log('Fallback shipping element: ' + (shippingElement.length > 0 ? shippingElement.text() : 'not found'));
                shippingTotal = shippingElement.length > 0 ? this.parsePrice(shippingElement.text()) : 0;
            }
            
            console.log('Final parsed shipping total: ' + shippingTotal);
            
            // Handling fee
            var handlingTotal = 0;
            var originalHandlingTotal = $('.ppcc-original-handling-total');
            if (originalHandlingTotal.length > 0) {
                console.log('Original handling total element found: ' + originalHandlingTotal.text());
                handlingTotal = this.parsePrice(originalHandlingTotal.text());
            } else {
                // Try to get from fee elements
                $('.fee').each(function() {
                    var feeElement = $(this).find('.amount');
                    console.log('Fee element: ' + feeElement.text());
                    handlingTotal += ppccCheckout.parsePrice(feeElement.text());
                });
            }
            
            console.log('Final parsed handling total: ' + handlingTotal);
            
            // Tax
            var taxTotal = 0;
            var originalTaxTotal = $('.ppcc-original-tax-total');
            if (originalTaxTotal.length > 0) {
                console.log('Original tax total element found: ' + originalTaxTotal.text());
                taxTotal = this.parsePrice(originalTaxTotal.text());
            } else {
                var taxElement = $('.tax-total .amount');
                console.log('Fallback tax element: ' + (taxElement.length > 0 ? taxElement.text() : 'not found'));
                taxTotal = taxElement.length > 0 ? this.parsePrice(taxElement.text()) : 0;
            }
            
            console.log('Final parsed tax total: ' + taxTotal);
            
            // Order total
            var orderTotal = 0;
            var originalOrderTotal = $('.ppcc-original-order-total');
            if (originalOrderTotal.length > 0) {
                console.log('Original order total element found: ' + originalOrderTotal.text());
                orderTotal = this.parsePrice(originalOrderTotal.text());
            } else {
                var totalElement = $('.order-total .amount');
                console.log('Fallback total element: ' + (totalElement.length > 0 ? totalElement.text() : 'not found'));
                orderTotal = totalElement.length > 0 ? this.parsePrice(totalElement.text()) : 0;
            }
            
            console.log('Final parsed order total: ' + orderTotal);
            
            // Check if cart data is directly available in ppcc_data
            if (ppcc_data.cart_total !== undefined) {
                console.log('Using cart data from ppcc_data:', {
                    cart_total: ppcc_data.cart_total,
                    tax_total: ppcc_data.tax_total,
                    order_total: ppcc_data.order_total
                });
                
                // If direct data is available, use it instead of parsed values
                cartTotal = parseFloat(ppcc_data.cart_total);
                taxTotal = parseFloat(ppcc_data.tax_total);
                orderTotal = parseFloat(ppcc_data.order_total);
                
                console.log('Using direct cart data:', {
                    cartTotal: cartTotal,
                    taxTotal: taxTotal,
                    orderTotal: orderTotal
                });
            } else {
                console.log('No direct cart data available, using parsed values:', {
                    cartTotal: cartTotal,
                    shippingTotal: shippingTotal,
                    handlingTotal: handlingTotal,
                    taxTotal: taxTotal,
                    orderTotal: orderTotal
                });
            }
            
            // Check if we have non-decimal currency
            var isNonDecimalCurrency = ['HUF', 'JPY', 'TWD'].indexOf(ppcc_data.target_currency) !== -1;
            var decimals = isNonDecimalCurrency ? 0 : parseInt(ppcc_data.decimals || 2);
            
            // Convert to target currency - standard direct multiplication by conversion rate
            // Debug the conversion rates
            console.log('Conversion calculation', {
                'Amount': cartTotal,
                'Rate': ppcc_data.conversion_rate,
                'Result': cartTotal * ppcc_data.conversion_rate
            });
            
            // Apply direct multiplication - this is the standard currency conversion formula
            var convertedCartTotal = this.formatPrice(cartTotal * ppcc_data.conversion_rate, decimals);
            var convertedShippingTotal = this.formatPrice(shippingTotal * ppcc_data.conversion_rate, decimals);
            var convertedHandlingTotal = this.formatPrice(handlingTotal * ppcc_data.conversion_rate, decimals);
            var convertedTaxTotal = this.formatPrice(taxTotal * ppcc_data.conversion_rate, decimals);
            var convertedOrderTotal = this.formatPrice(orderTotal * ppcc_data.conversion_rate, decimals);
            
            // Log converted values for debugging
            console.log('Converted values:', {
                convertedCartTotal: convertedCartTotal,
                convertedShippingTotal: convertedShippingTotal,
                convertedHandlingTotal: convertedHandlingTotal,
                convertedTaxTotal: convertedTaxTotal,
                convertedOrderTotal: convertedOrderTotal
            });
            
            // Format with currency
            var formattedCartTotal = this.formatMoney(convertedCartTotal, ppcc_data.target_currency);
            var formattedShippingTotal = this.formatMoney(convertedShippingTotal, ppcc_data.target_currency);
            var formattedHandlingTotal = this.formatMoney(convertedHandlingTotal, ppcc_data.target_currency);
            var formattedTaxTotal = this.formatMoney(convertedTaxTotal, ppcc_data.target_currency);
            var formattedOrderTotal = this.formatMoney(convertedOrderTotal, ppcc_data.target_currency);
            
            // Update visible elements
            $('.ppcc-cart-total').html(formattedCartTotal);
            $('.ppcc-shipping-total').html(formattedShippingTotal);
            $('.ppcc-handling-total').html(formattedHandlingTotal);
            $('.ppcc-tax-total').html(formattedTaxTotal);
            $('.ppcc-order-total').html(formattedOrderTotal);
        },
        
        // Parse price from formatted string
        parsePrice: function(priceString) {
            if (!priceString) {
                return 0;
            }
            
            // Debug the input string
            console.log('Parsing price from: ' + priceString);
            
            // Remove currency symbol and thousand separators, replace decimal separator with dot
            var numericString = priceString.replace(/[^\d.,]/g, '') // Remove non-numeric chars except decimal and thousand separators
                                         .replace(/\./g, '_')  // temporarily replace dots (thousand separators in some locales)
                                         .replace(/,/g, '.')   // replace commas with dots (decimal separator)
                                         .replace(/_/g, '');   // remove the temporary dots
            
            var parsedValue = parseFloat(numericString) || 0;
            console.log('Parsed numeric value: ' + parsedValue);
            
            return parsedValue;
        },
        
        // Format price with decimal precision
        formatPrice: function(price, decimals) {
            // For currencies that don't support decimals, ensure we return an integer
            if (decimals === 0) {
                return Math.round(price);
            }
            return parseFloat(price.toFixed(decimals));
        },
        
        // Format price with currency symbol
        formatMoney: function(price, currency) {
            if (typeof ppcc_data !== 'undefined') {
                var symbol = ppcc_data.currency_symbol || currency;
                var isNonDecimalCurrency = ['HUF', 'JPY', 'TWD'].indexOf(currency) !== -1;
                var decimals = isNonDecimalCurrency ? 0 : (ppcc_data.decimals || 2);
                var thousand_separator = ppcc_data.thousand_separator || ',';
                var decimal_separator = ppcc_data.decimal_separator || '.';
                
                // For non-decimal currencies, ensure we have an integer
                if (isNonDecimalCurrency) {
                    price = Math.round(price);
                }
                
                var parts = price.toFixed(decimals).split('.');
                var formatted = parts[0].replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1' + thousand_separator);
                
                if (parts.length > 1 && !isNonDecimalCurrency) {
                    formatted += decimal_separator + parts[1];
                }
                
                return symbol + formatted;
            }
            
            // Simple fallback
            return currency + ' ' + price.toFixed(2);
        },
        
        // Check if a PayPal payment method is selected
        isPayPalSelected: function() {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            var paypalMethods = ['paypal', 'ppec_paypal', 'ppcp-gateway', 'paypal_express', 'paypal_pro', 'paypal_advanced', 'paypal_digital_goods'];
            
            return paypalMethods.indexOf(selectedMethod) !== -1;
        },
        
        // Hide conversion info
        hideConversionInfo: function() {
            $('.ppcc-conversion-info').hide();
        },
        
        // Show conversion info
        showConversionInfo: function() {
            $('.ppcc-conversion-info').show();
        },
        
        // Update the conversion totals displayed in the info box (fallback method)
        updateConversionTotals: function() {
            console.log('Updating conversion totals from shadow elements');
            
            // Get values from shadow elements (added by AJAX fragments)
            var cartTotal = $('.ppcc-shadow-cart-total').text();
            var shippingTotal = $('.ppcc-shadow-shipping-total').text();
            var handlingTotal = $('.ppcc-shadow-handling-total').text();
            var taxTotal = $('.ppcc-shadow-tax-total').text();
            var orderTotal = $('.ppcc-shadow-order-total').text();
            
            console.log('Shadow values:', {
                cartTotal: cartTotal,
                shippingTotal: shippingTotal,
                handlingTotal: handlingTotal,
                taxTotal: taxTotal,
                orderTotal: orderTotal
            });
            
            // Update visible elements
            $('.ppcc-cart-total').html(cartTotal);
            $('.ppcc-shipping-total').html(shippingTotal);
            $('.ppcc-handling-total').html(handlingTotal);
            $('.ppcc-tax-total').html(taxTotal);
            $('.ppcc-order-total').html(orderTotal);
        },
        
        // Fix PayPal SDK for non-decimal currencies
        fixPayPalSdk: function() {
            // Only proceed if we have the ppcc_data object
            if (typeof ppcc_data === 'undefined') {
                return;
            }
            
            // Check if we're dealing with a non-decimal currency
            var isNonDecimalCurrency = ['HUF', 'JPY', 'TWD'].indexOf(ppcc_data.target_currency) !== -1;
            
            if (!isNonDecimalCurrency) {
                return;
            }
            
            // Wait for PayPal object to be available
            var checkPayPal = setInterval(function() {
                if (typeof window.paypal !== 'undefined') {
                    clearInterval(checkPayPal);
                    
                    console.log('Applying PayPal SDK fixes for non-decimal currency: ' + ppcc_data.target_currency);
                    
                    // Create a hook for the createOrder function
                    if (window.paypal.Buttons && window.paypal.Buttons.driver) {
                        var originalCreateOrder = window.paypal.Buttons.driver('create', 'createOrder');
                        
                        if (originalCreateOrder) {
                            window.paypal.Buttons.driver('create', 'createOrder', function() {
                                return function(data, actions) {
                                    // Before calling createOrder, ensure we're using a valid currency
                                    if (typeof ppcc_data !== 'undefined') {
                                        console.log('Current target currency:', ppcc_data.target_currency);
                                        
                                        // Verify we're using a supported PayPal currency
                                        var supported_currencies = ['AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 
                                                                  'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 
                                                                  'CHF', 'TWD', 'THB', 'GBP', 'CNY'];
                                        
                                        // Quick check if we're using a non-decimal currency
                                        var isNonDecimalCurrency = ['HUF', 'JPY', 'TWD'].indexOf(ppcc_data.target_currency) !== -1;
                                        console.log('Is non-decimal currency:', isNonDecimalCurrency);
                                    }
                                    
                                    // Call the original createOrder function
                                    try {
                                        return originalCreateOrder.call(this, data, actions)
                                            .then(function(orderID) {
                                                console.log('PayPal order created: ' + orderID);
                                                return orderID;
                                            }).catch(function(error) {
                                                console.error('PayPal order creation error:', error);
                                                // Log more details about the error
                                                if (error && error.message) {
                                                    console.error('Error message:', error.message);
                                                }
                                                if (error && error.details) {
                                                    console.error('Error details:', error.details);
                                                }
                                                
                                                // Special handling for the decimals error
                                                if (error && error.message && 
                                                    (error.message.indexOf('DECIMALS_NOT_SUPPORTED') >= 0 || 
                                                     error.message.indexOf('decimals') >= 0)) {
                                                    console.error('DETECTED DECIMAL ERROR - Attempting fallback');
                                                    alert('PayPal error detected: Currency format issue. Please contact the store administrator.');
                                                }
                                                
                                                throw error;
                                            });
                                    } catch (e) {
                                        console.error('Exception in createOrder call:', e);
                                        throw e;
                                    }
                                };
                            });
                        }
                    }
                    
                    // Modify any direct SDK-created orders
                    if (window.paypal.createOrder) {
                        var originalCreateOrderFunc = window.paypal.createOrder;
                        
                        window.paypal.createOrder = function(data, actions) {
                            // Ensure all amounts are integers for non-decimal currencies
                            if (data && data.purchase_units) {
                                data.purchase_units.forEach(function(unit) {
                                    if (unit.amount && unit.amount.value) {
                                        unit.amount.value = Math.round(parseFloat(unit.amount.value));
                                    }
                                });
                            }
                            
                            return originalCreateOrderFunc(data, actions);
                        };
                    }
                }
            }, 100);
        }
    };
})(jQuery);
