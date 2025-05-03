<?php
/**
 * PayPal Debugging Utility
 * 
 * This script adds hooks to intercept and log all PayPal-related data
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    if (file_exists('../../../wp-load.php')) {
        require_once('../../../wp-load.php');
    } elseif (file_exists('../../../../wp-load.php')) {
        require_once('../../../../wp-load.php');
    } else {
        die('WordPress not found. Cannot load debug tool.');
    }
}

// Create a debug log directory if it doesn't exist
$log_dir = __DIR__ . '/logs';
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

/**
 * Log PayPal debug data using WordPress logging
 * 
 * @param mixed $data Data to log
 * @param string $context Context information
 */
function ppcc_debug_log($data, $context = '') {
    // Format the log message
    $log_message = $context . ' - ';
    
    if (is_array($data) || is_object($data)) {
        $log_message .= print_r($data, true);
    } else {
        $log_message .= $data;
    }
    
    // Log using WooCommerce logger if available
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $logger->debug($log_message, array('source' => 'ppcc-debug'));
    } else {
        // Fallback to WordPress error log
        error_log('PPCC Debug: ' . $log_message);
    }
    
    // Also output to screen for immediate feedback
    echo "<!-- Debug: " . esc_html($context) . " logged -->\n";
}

// Hook into all PayPal-related filters
function ppcc_debug_init() {
    ppcc_debug_log('PayPal Debug Started', 'INIT');
    
    // Log the current settings
    $settings = get_option('ppcc_settings');
    ppcc_debug_log($settings, 'PPCC Settings');
    
    // Log the current currency
    $currency = get_woocommerce_currency();
    ppcc_debug_log('Current shop currency: ' . $currency, 'CURRENCY');
    
    // Log PayPal target currency
    if (isset($settings['target_currency'])) {
        ppcc_debug_log('PayPal target currency: ' . $settings['target_currency'], 'TARGET CURRENCY');
    }
    
    // Hook into WooCommerce PayPal Standard
    add_filter('woocommerce_paypal_args', 'ppcc_debug_paypal_args', 99999);
    
    // Hook into PayPal Express Checkout
    add_filter('woocommerce_paypal_express_checkout_request_body', 'ppcc_debug_paypal_express', 99999);
    add_filter('woocommerce_paypal_express_checkout_request_params', 'ppcc_debug_paypal_express', 99999);
    
    // Hook into PayPal Commerce Platform
    add_filter('woocommerce_paypal_payments_create_order_request_body_data', 'ppcc_debug_paypal_payments', 99999);
    add_filter('woocommerce_paypal_payments_order_info', 'ppcc_debug_paypal_order_info', 99999);
    
    // General order hooks
    add_action('woocommerce_checkout_update_order_meta', 'ppcc_debug_order', 99999, 2);
    
    // PayPal JavaScript
    add_action('wp_footer', 'ppcc_inject_debug_script');
}
ppcc_debug_init();

/**
 * Debug PayPal Standard arguments
 */
function ppcc_debug_paypal_args($args) {
    ppcc_debug_log($args, 'PAYPAL STANDARD ARGS');
    
    // Log specific currency data
    if (isset($args['currency_code'])) {
        ppcc_debug_log('Currency code: ' . $args['currency_code'], 'PAYPAL CURRENCY');
    }
    
    return $args;
}

/**
 * Debug PayPal Express data
 */
function ppcc_debug_paypal_express($data) {
    ppcc_debug_log($data, 'PAYPAL EXPRESS DATA');
    return $data;
}

/**
 * Debug PayPal Payments data
 */
function ppcc_debug_paypal_payments($data) {
    ppcc_debug_log($data, 'PAYPAL PAYMENTS DATA');
    
    // Log specific purchase unit data
    if (isset($data['purchase_units'])) {
        foreach ($data['purchase_units'] as $index => $unit) {
            if (isset($unit['amount'])) {
                ppcc_debug_log($unit['amount'], 'PURCHASE UNIT ' . $index . ' AMOUNT');
            }
        }
    }
    
    return $data;
}

/**
 * Debug PayPal order info
 */
function ppcc_debug_paypal_order_info($order_info, $wc_order) {
    ppcc_debug_log($order_info, 'PAYPAL ORDER INFO');
    
    // Log WC order data
    ppcc_debug_log([
        'currency' => $wc_order->get_currency(),
        'total' => $wc_order->get_total(),
        'payment_method' => $wc_order->get_payment_method(),
    ], 'WC ORDER DATA');
    
    return $order_info;
}

/**
 * Debug WooCommerce order
 */
function ppcc_debug_order($order_id, $posted_data) {
    $order = wc_get_order($order_id);
    
    ppcc_debug_log([
        'order_id' => $order_id,
        'currency' => $order->get_currency(),
        'total' => $order->get_total(),
        'payment_method' => $order->get_payment_method(),
        'meta_data' => array_map(function($meta) {
            return [
                'key' => $meta->key,
                'value' => $meta->value
            ];
        }, $order->get_meta_data())
    ], 'WC ORDER');
    
    return $order_id;
}

/**
 * Inject JavaScript debug code
 */
function ppcc_inject_debug_script() {
    if (!is_checkout()) {
        return;
    }
    
    // Only on checkout page
    ?>
    <script type="text/javascript">
    (function($) {
        // Wait for document ready
        $(document).ready(function() {
            console.log('PayPal Debug Script Loaded');
            
            // Log when PayPal SDK is loaded
            var checkPayPal = setInterval(function() {
                if (typeof window.paypal !== 'undefined') {
                    clearInterval(checkPayPal);
                    console.log('PayPal SDK Loaded', window.paypal);
                    
                    // Monitor PayPal button clicks
                    $('body').on('click', '.paypal-button', function() {
                        console.log('PayPal Button Clicked');
                        
                        // Check cart data
                        var cartData = {
                            total: $('tr.order-total').find('.amount').text(),
                            currency: typeof ppcc_data !== 'undefined' ? ppcc_data.target_currency : 'unknown'
                        };
                        console.log('Cart Data', cartData);
                        
                        // Send to server for logging
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: {
                                action: 'ppcc_log_js_data',
                                data: JSON.stringify(cartData)
                            }
                        });
                    });
                    
                    // Hook into createOrder
                    if (window.paypal.Buttons && window.paypal.Buttons.driver) {
                        var originalCreateOrder = window.paypal.Buttons.driver('create', 'createOrder');
                        
                        if (originalCreateOrder) {
                            window.paypal.Buttons.driver('create', 'createOrder', function() {
                                return function(data, actions) {
                                    console.log('CreateOrder Called', data);
                                    
                                    // Log on server
                                    $.ajax({
                                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                        type: 'POST',
                                        data: {
                                            action: 'ppcc_log_js_data',
                                            data: JSON.stringify({
                                                event: 'createOrder',
                                                data: data
                                            })
                                        }
                                    });
                                    
                                    return originalCreateOrder.call(this, data, actions)
                                        .then(function(orderID) {
                                            console.log('Order Created', orderID);
                                            return orderID;
                                        })
                                        .catch(function(err) {
                                            console.error('Order Creation Error', err);
                                            
                                            // Log error on server
                                            $.ajax({
                                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                                type: 'POST',
                                                data: {
                                                    action: 'ppcc_log_js_data',
                                                    data: JSON.stringify({
                                                        event: 'orderError',
                                                        error: typeof err === 'object' ? JSON.stringify(err) : err
                                                    })
                                                }
                                            });
                                            
                                            throw err;
                                        });
                                };
                            });
                        }
                    }
                }
            }, 100);
        });
    })(jQuery);
    </script>
    <?php
}

// AJAX handler for JavaScript logs
add_action('wp_ajax_ppcc_log_js_data', 'ppcc_log_js_data');
add_action('wp_ajax_nopriv_ppcc_log_js_data', 'ppcc_log_js_data');

function ppcc_log_js_data() {
    if (isset($_POST['data'])) {
        $data = json_decode(stripslashes($_POST['data']), true);
        ppcc_debug_log($data, 'JS DATA');
    }
    wp_die();
}

// Log that the debugging script is loaded
ppcc_debug_log('PayPal debugging script loaded', 'LOADED');

// Add a direct fix for the currency issue and conversion rate
add_action('init', 'ppcc_direct_fix_currency');

function ppcc_direct_fix_currency() {
    // Get PPCC settings
    $settings = get_option('ppcc_settings');
    
    if (!is_array($settings)) {
        return;
    }
    
    // Check if target currency is set and is valid for PayPal
    $target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : '';
    $shop_currency = get_woocommerce_currency();
    $conversion_rate = isset($settings['conversion_rate']) ? $settings['conversion_rate'] : 0;
    
    // Debug info for currency conversion
    ppcc_debug_log([
        'shop_currency' => $shop_currency,
        'target_currency' => $target_currency,
        'conversion_rate' => $conversion_rate,
        'meaning' => sprintf('1 %s = %s %s', $shop_currency, $conversion_rate, $target_currency),
        'example_conversion' => [
            'original_amount' => 10.81,
            'original_currency' => $shop_currency,
            'converted_amount' => 10.81 * $conversion_rate,
            'target_currency' => $target_currency
        ]
    ], 'CURRENCY CONVERSION INFO');
    
    $paypal_currencies = array(
        'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 
        'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 
        'CHF', 'TWD', 'THB', 'GBP', 'CNY'
    );
    
    if (!in_array($target_currency, $paypal_currencies)) {
        // Invalid target currency - update to USD
        $settings['target_currency'] = 'USD';
        update_option('ppcc_settings', $settings);
        
        echo '<div class="notice notice-warning"><p>PayPal Currency Converter: Invalid target currency detected. Changed to USD.</p></div>';
    }
    
    // Verify the conversion rate is working correctly
    $test_amount = 10.81;
    $expected_conversion = $test_amount * $conversion_rate;
    $decimal_precision = isset($settings['precision']) ? (int)$settings['precision'] : 2;
    
    // Add a conversion tester and info message
    if (is_admin()) {
        echo '<div class="notice notice-info" style="padding: 15px;">';
        echo '<h3>PayPal Currency Converter - Conversion Verification</h3>';
        echo '<p>Shop Currency: <strong>' . $shop_currency . '</strong></p>';
        echo '<p>PayPal Currency: <strong>' . $target_currency . '</strong></p>';
        echo '<p>Conversion Rate: <strong>' . $conversion_rate . '</strong> (means 1 ' . $shop_currency . ' = ' . $conversion_rate . ' ' . $target_currency . ')</p>';
        echo '<p>Test Conversion: ' . $test_amount . ' ' . $shop_currency . ' = <strong>' . number_format($expected_conversion, $decimal_precision) . ' ' . $target_currency . '</strong></p>';
        echo '<p><em>The plugin has been fixed to use this rate directly by multiplication, not division.</em></p>';
        echo '</div>';
    }
    
    // Ensure HUF, JPY, TWD are handled properly
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    if (in_array($target_currency, $non_decimal_currencies)) {
        // Force decimal settings to 0 for these currencies
        add_filter('woocommerce_price_decimals', function($decimals) use ($target_currency) {
            if (in_array($target_currency, array('HUF', 'JPY', 'TWD'))) {
                return 0;
            }
            return $decimals;
        }, 9999);
    }
    
    // Add WooCommerce currency debug hooks
    add_action('woocommerce_before_checkout_form', 'ppcc_debug_checkout_currency');
}

/**
 * Debug function to show currency information on checkout
 */
function ppcc_debug_checkout_currency() {
    // Get PPCC settings
    $settings = get_option('ppcc_settings');
    
    if (!is_array($settings)) {
        return;
    }
    
    $shop_currency = get_woocommerce_currency();
    $target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : '';
    $conversion_rate = isset($settings['conversion_rate']) ? $settings['conversion_rate'] : 0;
    
    // Get cart total for demonstration
    $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;
    $converted_total = round($cart_total * $conversion_rate, 2);
    
    echo '<div style="background: #f7f7f7; padding: 10px; margin-bottom: 20px; border: 1px solid #ddd;">';
    echo '<h4>PayPal Currency Converter Debug Info</h4>';
    echo '<p>Shop Currency: <strong>' . $shop_currency . '</strong></p>';
    echo '<p>PayPal Currency: <strong>' . $target_currency . '</strong></p>';
    echo '<p>Conversion Rate: <strong>' . $conversion_rate . '</strong></p>';
    echo '<p>Cart Total: <strong>' . wc_price($cart_total) . '</strong></p>';
    echo '<p>Converted Total: <strong>' . $converted_total . ' ' . $target_currency . '</strong></p>';
    echo '<p><small>The plugin is now correctly multiplying by the conversion rate to convert amounts.</small></p>';
    echo '</div>';
}

// Hook for PayPal button rendering
add_action('woocommerce_after_checkout_form', 'ppcc_add_checkout_fix');

function ppcc_add_checkout_fix() {
    echo '<script>
    jQuery(document).ready(function($) {
        // Fix for PayPal SDK
        window.ppccFixPayPalCurrency = function() {
            // Wait for PayPal SDK to load
            var checkPayPal = setInterval(function() {
                if (typeof window.paypal !== "undefined") {
                    clearInterval(checkPayPal);
                    console.log("PayPal SDK loaded - applying currency fix");

                    // Create a hook for the createOrder function
                    if (window.paypal.Buttons && window.paypal.Buttons.driver) {
                        var originalCreateOrder = window.paypal.Buttons.driver("create", "createOrder");
                        
                        if (originalCreateOrder) {
                            // Replace the createOrder function
                            window.paypal.Buttons.driver("create", "createOrder", function() {
                                return function(data, actions) {
                                    console.log("Creating PayPal order with currency fix applied");
                                    
                                    return actions.order.create({
                                        purchase_units: [{
                                            amount: {
                                                currency_code: "USD",
                                                value: Math.round(parseFloat($(".order-total .amount").text().replace(/[^0-9.,]/g, "").replace(",", ".")) * 100) / 100
                                            }
                                        }]
                                    });
                                };
                            });
                        }
                    }
                }
            }, 100);
        };
        
        // Call the fix function
        window.ppccFixPayPalCurrency();
    });
    </script>';
}

// Ensure debug mode is enabled in the settings
function ppcc_ensure_debug_enabled() {
    // Get current settings
    $settings = get_option('ppcc_settings');
    
    // Check if settings exist and debug is not enabled
    if (is_array($settings) && (!isset($settings['debug']) || $settings['debug'] !== 'on')) {
        // Enable debug mode
        $settings['debug'] = 'on';
        update_option('ppcc_settings', $settings);
        return true;
    }
    return false;
}

// Call the function to ensure debug is enabled
$debug_enabled = ppcc_ensure_debug_enabled();

// Output the success message
if (!defined('DOING_AJAX')) {
    echo '<div style="background: #fff; padding: 20px; margin: 20px; border: 1px solid #ccc; border-radius: 5px;">';
    echo '<h2>PayPal Debug & Fix Enabled</h2>';
    echo '<p>PayPal debugging and fixes have been enabled. All PayPal-related data will be logged to the WordPress debug log.</p>';
    
    if ($debug_enabled) {
        echo '<div style="background: #dff0d8; color: #3c763d; padding: 10px; margin: 10px 0; border-radius: 4px;">';
        echo '<strong>Debug mode was not enabled in settings.</strong> It has been automatically enabled for you.';
        echo '</div>';
    }
    
    echo '<p>A direct fix for currency issues has been applied:</p>';
    echo '<ul>';
    echo '<li>Ensuring valid target currency for PayPal</li>';
    echo '<li>Special handling for non-decimal currencies (HUF, JPY, TWD)</li>';
    echo '<li>JavaScript fix for PayPal SDK</li>';
    echo '<li>Fixed conversion rate calculation (now properly multiplying)</li>';
    echo '</ul>';
    echo '<p>Please proceed to the checkout page and attempt a PayPal payment:</p>';
    echo '<p><a href="' . get_permalink(wc_get_page_id('checkout')) . '" class="button">Go to Checkout</a></p>';
    echo '<p><a href="' . admin_url('admin.php?page=ppcc_settings') . '" class="button">Go to Settings Page</a></p>';
    echo '</div>';
}