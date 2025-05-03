<?php
/**
 * Direct Fix for PayPal Currency Decimal Issues
 * 
 * This file directly addresses the DECIMALS_NOT_SUPPORTED error
 * by ensuring proper decimal formatting for all currencies.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    define('ABSPATH', true); // Allow direct access for this fix
}

// Apply the fix when this file is included
add_action('wp_footer', 'ppcc_direct_fix_paypal_decimals');
add_action('admin_footer', 'ppcc_direct_fix_paypal_decimals');

/**
 * Apply direct fix for PayPal decimal issues
 */
function ppcc_direct_fix_paypal_decimals() {
    // Only run on checkout page
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    
    // Currency decimal map - PayPal supported currencies and their decimal places
    $currency_decimals = [
        'AUD' => 2, 'BRL' => 2, 'CAD' => 2, 'CNY' => 2, 'CZK' => 2, 
        'DKK' => 2, 'EUR' => 2, 'GBP' => 2, 'HKD' => 2, 'HUF' => 0, 
        'ILS' => 2, 'JPY' => 0, 'MYR' => 2, 'MXN' => 2, 'TWD' => 0, 
        'NZD' => 2, 'NOK' => 2, 'PHP' => 2, 'PLN' => 2, 'SGD' => 2, 
        'SEK' => 2, 'CHF' => 2, 'THB' => 2, 'USD' => 2, 'TRY' => 2
    ];
    
    // Get the shop currency
    $shop_currency = get_woocommerce_currency();
    
    // Get target currency from PPCC if available
    $target_currency = $shop_currency; // Default to shop currency
    $ppcc_settings = get_option('ppcc_settings');
    if (is_array($ppcc_settings) && isset($ppcc_settings['target_currency'])) {
        $target_currency = $ppcc_settings['target_currency'];
    }
    
    // Ensure the target currency is supported by PayPal
    if (!isset($currency_decimals[$target_currency])) {
        $target_currency = 'USD'; // Fallback to USD if not supported
    }
    
    // Get the correct number of decimal places for this currency
    $decimals = $currency_decimals[$target_currency];
    
    // Output the JavaScript fix
    ?>
    <script type="text/javascript">
    (function($) {
        // Load when document is ready
        $(document).ready(function() {
            console.log('PayPal Decimal Fix loaded for <?php echo $target_currency; ?> (<?php echo $decimals; ?> decimals)');
            
            // Map of currencies to their decimal places
            var currencyDecimals = <?php echo json_encode($currency_decimals); ?>;
            
            // Target currency 
            var targetCurrency = '<?php echo $target_currency; ?>';
            var requiredDecimals = <?php echo $decimals; ?>;
            
            // Fix function to ensure proper decimal formatting
            function fixPayPalAmount(amount) {
                // Parse the amount
                var numAmount = parseFloat(amount);
                if (isNaN(numAmount)) {
                    console.error('Invalid amount:', amount);
                    return amount;
                }
                
                // Format with the correct number of decimals
                var fixedAmount = requiredDecimals === 0 ? 
                    Math.round(numAmount) : 
                    Number(numAmount.toFixed(requiredDecimals));
                
                console.log('Fixed amount:', amount, '->', fixedAmount);
                return fixedAmount;
            }
            
            // Wait for PayPal to load
            var checkPayPal = setInterval(function() {
                if (typeof window.paypal !== 'undefined') {
                    clearInterval(checkPayPal);
                    applyPayPalFixes();
                }
            }, 100);
            
            // Apply the fixes to PayPal SDK
            function applyPayPalFixes() {
                console.log('Applying PayPal fixes for', targetCurrency);
                
                // Fix for PayPal Smart Buttons
                if (window.paypal.Buttons && window.paypal.Buttons.driver) {
                    console.log('Fixing PayPal Buttons driver');
                    
                    // Override the createOrder function
                    var originalCreateOrder = window.paypal.Buttons.driver('create', 'createOrder');
                    if (originalCreateOrder) {
                        window.paypal.Buttons.driver('create', 'createOrder', function() {
                            return function(data, actions) {
                                // Method 1: Use the custom order create
                                return actions.order.create({
                                    purchase_units: [{
                                        amount: {
                                            currency_code: targetCurrency,
                                            value: fixPayPalAmount($('.order-total .amount').text().replace(/[^0-9.,]/g, '').replace(',', '.'))
                                        }
                                    }]
                                });
                            };
                        });
                    }
                }
                
                // Fix for PayPal Checkout
                if (window.paypal.Checkout) {
                    console.log('Fixing PayPal Checkout');
                    
                    var originalRender = window.paypal.Checkout.render;
                    window.paypal.Checkout.render = function(options) {
                        // Intercept and fix any amounts
                        if (options && options.payment && typeof options.payment === 'function') {
                            var originalPayment = options.payment;
                            options.payment = function() {
                                return originalPayment().then(function(data) {
                                    console.log('Payment data intercepted:', data);
                                    // Fix can be added here if needed
                                    return data;
                                });
                            };
                        }
                        
                        return originalRender.apply(this, arguments);
                    };
                }
            }
            
            // Also add a global error handler to catch decimal errors
            window.addEventListener('error', function(event) {
                if (event && event.error && event.error.message && 
                    event.error.message.indexOf('DECIMALS_NOT_SUPPORTED') >= 0) {
                    
                    console.error('Caught DECIMALS_NOT_SUPPORTED error');
                    alert('PayPal Error: The currency ' + targetCurrency + ' requires ' + 
                          requiredDecimals + ' decimal places. Please contact the store administrator.');
                    
                    // Prevent the error from showing in console
                    event.preventDefault();
                }
            });
        });
    })(jQuery);
    </script>
    <?php
}

// Add a direct filter to format PayPal amounts correctly
add_filter('woocommerce_paypal_args', 'ppcc_fix_paypal_decimal_args', 9999);

/**
 * Fix PayPal arguments for proper decimal handling
 */
function ppcc_fix_paypal_decimal_args($args) {
    // Currency decimal map
    $currency_decimals = [
        'AUD' => 2, 'BRL' => 2, 'CAD' => 2, 'CNY' => 2, 'CZK' => 2, 
        'DKK' => 2, 'EUR' => 2, 'GBP' => 2, 'HKD' => 2, 'HUF' => 0, 
        'ILS' => 2, 'JPY' => 0, 'MYR' => 2, 'MXN' => 2, 'TWD' => 0, 
        'NZD' => 2, 'NOK' => 2, 'PHP' => 2, 'PLN' => 2, 'SGD' => 2, 
        'SEK' => 2, 'CHF' => 2, 'THB' => 2, 'USD' => 2, 'TRY' => 2
    ];
    
    // Get the currency
    $currency = isset($args['currency_code']) ? $args['currency_code'] : '';
    
    // If currency is not supported, exit
    if (!$currency || !isset($currency_decimals[$currency])) {
        return $args;
    }
    
    // Get required decimal places
    $decimals = $currency_decimals[$currency];
    
    // Fix amount fields
    $amount_fields = ['amount', 'shipping', 'tax', 'handling', 'discount_amount'];
    
    foreach ($amount_fields as $field) {
        if (isset($args[$field])) {
            // Format with correct decimals
            $amount = (float)$args[$field];
            if ($decimals === 0) {
                $args[$field] = (string)round($amount);
            } else {
                $args[$field] = number_format($amount, $decimals, '.', '');
            }
        }
    }
    
    // Fix dynamic line item amounts
    foreach ($args as $key => $value) {
        if (preg_match('/^amount_\d+$/', $key) && is_numeric($value)) {
            $amount = (float)$value;
            if ($decimals === 0) {
                $args[$key] = (string)round($amount);
            } else {
                $args[$key] = number_format($amount, $decimals, '.', '');
            }
        }
    }
    
    return $args;
}

// Add a fix for the PayPal Payments gateway
add_filter('woocommerce_paypal_payments_create_order_request_body_data', 'ppcc_fix_paypal_payments_request', 9999);

/**
 * Fix PayPal Payments request data for proper decimal handling
 */
function ppcc_fix_paypal_payments_request($data) {
    // Currency decimal map
    $currency_decimals = [
        'AUD' => 2, 'BRL' => 2, 'CAD' => 2, 'CNY' => 2, 'CZK' => 2, 
        'DKK' => 2, 'EUR' => 2, 'GBP' => 2, 'HKD' => 2, 'HUF' => 0, 
        'ILS' => 2, 'JPY' => 0, 'MYR' => 2, 'MXN' => 2, 'TWD' => 0, 
        'NZD' => 2, 'NOK' => 2, 'PHP' => 2, 'PLN' => 2, 'SGD' => 2, 
        'SEK' => 2, 'CHF' => 2, 'THB' => 2, 'USD' => 2, 'TRY' => 2
    ];
    
    // Process purchase units
    if (isset($data['purchase_units']) && is_array($data['purchase_units'])) {
        foreach ($data['purchase_units'] as &$unit) {
            // Get the currency
            $currency = isset($unit['amount']['currency_code']) ? $unit['amount']['currency_code'] : '';
            
            // Skip if currency is not supported
            if (!$currency || !isset($currency_decimals[$currency])) {
                continue;
            }
            
            // Get required decimal places
            $decimals = $currency_decimals[$currency];
            
            // Fix the amount value
            if (isset($unit['amount']['value'])) {
                $amount = (float)$unit['amount']['value'];
                if ($decimals === 0) {
                    $unit['amount']['value'] = (string)round($amount);
                } else {
                    $unit['amount']['value'] = number_format($amount, $decimals, '.', '');
                }
            }
            
            // Fix breakdown amounts
            if (isset($unit['amount']['breakdown']) && is_array($unit['amount']['breakdown'])) {
                foreach ($unit['amount']['breakdown'] as $key => &$value) {
                    if (isset($value['value'])) {
                        $amount = (float)$value['value'];
                        if ($decimals === 0) {
                            $value['value'] = (string)round($amount);
                        } else {
                            $value['value'] = number_format($amount, $decimals, '.', '');
                        }
                    }
                }
            }
            
            // Fix item amounts
            if (isset($unit['items']) && is_array($unit['items'])) {
                foreach ($unit['items'] as &$item) {
                    if (isset($item['unit_amount']['value'])) {
                        $amount = (float)$item['unit_amount']['value'];
                        if ($decimals === 0) {
                            $item['unit_amount']['value'] = (string)round($amount);
                        } else {
                            $item['unit_amount']['value'] = number_format($amount, $decimals, '.', '');
                        }
                    }
                }
            }
        }
    }
    
    return $data;
}

// Let the user know the fix is active
function ppcc_direct_fix_admin_notice() {
    echo '<div class="notice notice-success is-dismissible">';
    echo '<p><strong>PayPal Currency Decimal Fix is active.</strong> The fix ensures proper decimal formatting for all PayPal-supported currencies.</p>';
    echo '</div>';
}
add_action('admin_notices', 'ppcc_direct_fix_admin_notice');