<?php
/**
 * Helper functions for PayPal Currency Converter PRO
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Log message to WooCommerce log with enhanced context information
 *
 * @param string $message Message to log
 * @param string $level Log level (emergency|alert|critical|error|warning|notice|info|debug)
 * @param array $context Additional context data (optional)
 */
function ppcc_log($message, $level = 'info', $context = array()) {

    // Validate log level
    $valid_levels = array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug');
    if (!in_array($level, $valid_levels)) {
        // Invalid level provided, default to 'info'
        error_log('Invalid log level provided to ppcc_log: ' . $level);
        $level = 'info';
    }
    // Always log errors, otherwise respect debug setting
    $settings = get_ppcc_option('ppcc_settings');
    $debug_enabled = isset($settings['debug']) && $settings['debug'] === 'on';
    
    if (!$debug_enabled && !in_array($level, array('emergency', 'alert', 'critical', 'error'))) {
        return;
    }
    
    // Add timestamp
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[$timestamp] $message";
    
    // Add request info for AJAX requests
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'unknown';
        $formatted_message .= " | AJAX action: $action";
        
        if ($action === 'update_order_review') {
            // Add more context for update_order_review ajax
            $formatted_message .= " | POST data keys: " . implode(', ', array_keys($_POST));
        }
    }
    
    // Add backtrace information for errors
    if (in_array($level, array('emergency', 'alert', 'critical', 'error'))) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        if (isset($trace[1])) {
            $caller = isset($trace[1]['class']) ? $trace[1]['class'] . '::' . $trace[1]['function'] : $trace[1]['function'];
            $file = isset($trace[1]['file']) ? basename($trace[1]['file']) : 'unknown';
            $line = isset($trace[1]['line']) ? $trace[1]['line'] : 'unknown';
            $formatted_message .= " | Called from: $caller in $file:$line";
        }
    }
    
    // Add any provided context data
    if (!empty($context) && is_array($context)) {
        $context_data = json_encode($context);
        $formatted_message .= " | Context: $context_data";
    }
    
    // Log using WooCommerce logger if available
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $logger->log($level, $formatted_message, array('source' => 'ppcc'));
        
        // For critical errors during checkout, also log to a dedicated checkout debug file
        if (in_array($level, array('emergency', 'alert', 'critical', 'error')) && 
            (is_checkout() || (defined('DOING_AJAX') && DOING_AJAX))) {
            
            // Create additional log with more detail to troubleshoot 500 errors
            $debug_file = PPCC_PLUGIN_DIR . 'logs/checkout-debug.log';
            
            // Create directory if it doesn't exist
            if (!file_exists(dirname($debug_file))) {
                @mkdir(dirname($debug_file), 0755, true);
            }
            
            // Add detailed request information
            $debug_info = $formatted_message . "\n";
            $debug_info .= "REQUEST URI: " . $_SERVER['REQUEST_URI'] . "\n";
            $debug_info .= "REQUEST METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
            $debug_info .= "SERVER SOFTWARE: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
            $debug_info .= "USER AGENT: " . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown') . "\n";
            
            // Get partial stack trace
            $debug_info .= "STACK TRACE:\n";
            $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 5);
            foreach ($trace as $i => $t) {
                $debug_info .= "#$i " . 
                    (isset($t['file']) ? basename($t['file']) : 'unknown') . ":" . 
                    (isset($t['line']) ? $t['line'] : 'unknown') . " " . 
                    (isset($t['class']) ? $t['class'] . '::' : '') . 
                    $t['function'] . "()\n";
            }
            
            // Add separator for readability
            $debug_info .= str_repeat('-', 50) . "\n";
            
            // Write to file
            @file_put_contents($debug_file, $debug_info, FILE_APPEND);
        }
    } else {
        // Fall back to error_log if WC logger isn't available
        error_log('PPCC: ' . $formatted_message);
    }
}

/**
 * Get option from site or network
 *
 * @param string $option_name Option name
 * @return mixed Option value
 */
function get_ppcc_option($option_name) {
    if (defined('PPCC_NETWORK_ACTIVATED') && PPCC_NETWORK_ACTIVATED) {
        // Get network site option
        return get_site_option($option_name);
    } else {
        // Get blog option
        if (function_exists('get_blog_option')) {
            return get_blog_option(get_current_blog_id(), $option_name);
        } else {
            return get_option($option_name);
        }
    }
}

/**
 * Update option for site or network
 *
 * @param string $option_name Option name
 * @param mixed $option_value Option value
 * @return bool Success
 */
function update_ppcc_option($option_name, $option_value) {
    if (defined('PPCC_NETWORK_ACTIVATED') && PPCC_NETWORK_ACTIVATED) {
        // Update network site option
        return update_site_option($option_name, $option_value);
    } else {
        // Update blog option
        return update_option($option_name, $option_value);
    }
}

/**
 * Delete option for site or network
 *
 * @param string $option_name Option name
 * @return bool Success
 */
function delete_ppcc_option($option_name) {
    if (defined('PPCC_NETWORK_ACTIVATED') && PPCC_NETWORK_ACTIVATED) {
        // Delete network site option
        return delete_site_option($option_name);
    } else {
        // Delete blog option
        return delete_option($option_name);
    }
}

/**
 * Check if WooCommerce is active
 * This function is already defined in paypalcc.php
 *
 * @return bool
 */
if (!function_exists('ppcc_is_woocommerce_active')) {
    function ppcc_is_woocommerce_active() {
        $active_plugins = (array) get_option('active_plugins', array());
        
        if (is_multisite()) {
            $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
        }
        
        return in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
    }
}

/**
 * Check if cURL is installed
 *
 * @return bool
 */
function ppcc_is_curl_installed() {
    return function_exists('curl_init');
}

/**
 * Get remote data using cURL
 *
 * @param string $url URL to fetch
 * @return string|bool Response or false on error
 */
function ppcc_file_get_contents_curl($url) {
    if (!ppcc_is_curl_installed()) {
        ppcc_log('cURL is not installed', 'error');
        return false;
    }
    
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PayPal Currency Converter PRO/' . PPCC_VERSION);
    
    // Use WordPress certificates if available
    if (file_exists(ABSPATH . WPINC . '/certificates/ca-bundle.crt')) {
        curl_setopt($ch, CURLOPT_CAINFO, ABSPATH . WPINC . '/certificates/ca-bundle.crt');
    }
    
    $data = curl_exec($ch);
    
    if ($data === false) {
        $error_msg = curl_error($ch);
        $error_code = curl_errno($ch);
        ppcc_log('cURL error (' . $error_code . '): ' . $error_msg, 'error');
    }
    
    curl_close($ch);
    
    return $data;
}

/**
 * Format currency with proper decimals
 *
 * @param float $amount Amount to format
 * @param string $currency Currency code
 * @return float|int Formatted amount
 */
function ppcc_format_currency($amount, $currency) {
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    $decimals = in_array($currency, $non_decimal_currencies) ? 0 : 2;
    
    // Round to the appropriate number of decimals
    $rounded_amount = round($amount, $decimals);
    
    // For non-decimal currencies, PayPal requires integers with no decimal places
    if (in_array($currency, $non_decimal_currencies)) {
        return intval($rounded_amount);
    }
    
    return $rounded_amount;
}

/**
 * Check if payment method is a PayPal gateway
 *
 * @param string $payment_method Payment method ID
 * @return bool
 */
function ppcc_is_paypal_gateway($payment_method) {
    $paypal_gateways = array(
        'paypal',                  // Standard PayPal
        'ppec_paypal',             // PayPal Express Checkout
        'ppcp-gateway',            // PayPal Commerce Platform
        'paypal_express',          // Another PayPal Express implementation
        'paypal_pro',              // PayPal Pro
        'paypal_advanced',         // PayPal Advanced
        'paypal_digital_goods',    // PayPal Digital Goods
    );
    
    return in_array($payment_method, $paypal_gateways);
}

/**
 * Get PayPal supported currencies
 *
 * @return array Supported currencies
 */
function ppcc_get_paypal_currencies() {
    return array(
        'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 
        'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 
        'CHF', 'TWD', 'THB', 'GBP', 'CNY'
    );
}

/**
 * Get PPCC settings for a specific PayPal gateway
 * 
 * Helper function to retrieve settings stored by transients
 * 
 * @param string $gateway_id Gateway ID
 * @return array|false PPCC settings or false if not found
 */
function ppcc_get_gateway_settings( $gateway_id ) {
    // Check if we have transient settings for this gateway
    $settings = get_transient( 'ppcc_settings_for_' . $gateway_id );
    
    if ( $settings ) {
        return $settings;
    }
    
    // Fallback to global settings if available
    $global_settings = get_ppcc_option( 'ppcc_settings' );
    
    if ( $global_settings && is_array( $global_settings ) ) {
        return $global_settings;
    }
    
    return false;
}

/**
 * Filter and fix PayPal request values for non-decimal currencies
 * 
 * @param mixed $value The value being filtered
 * @return mixed The filtered value
 */
function ppcc_fix_paypal_amount($value) {
    // Only process if we have a numeric value
    if (!is_numeric($value)) {
        return $value;
    }
    
    // Get current currency
    $currency = get_woocommerce_currency();
    
    // List of currencies that don't support decimals
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // If this is a non-decimal currency, ensure it's an integer
    if (in_array($currency, $non_decimal_currencies)) {
        return intval(round($value));
    }
    
    return $value;
}

// Add filters for PayPal Standard
add_filter('woocommerce_paypal_args', 'ppcc_fix_paypal_args', 999);

/**
 * Fix PayPal request arguments to ensure proper decimal handling
 * 
 * @param array $args PayPal arguments
 * @return array Fixed arguments
 */
function ppcc_fix_paypal_args($args) {
    // Get current currency
    $currency = get_woocommerce_currency();
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // Log the PayPal args for debugging
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal args before fix: ' . print_r($args, true), 'debug');
    }
    
    // If this is a non-decimal currency, ensure all amounts are integers
    if (in_array($currency, $non_decimal_currencies)) {
        // Fix amount fields
        $amount_fields = array(
            'amount', 'tax', 'tax_cart', 'shipping', 'discount_amount_cart',
            'handling_cart', 'shipping_discount', 'insurance_amount'
        );
        
        foreach ($amount_fields as $field) {
            if (isset($args[$field])) {
                $args[$field] = intval(round(floatval($args[$field])));
            }
        }
        
        // Fix item amounts
        for ($i = 1; isset($args["amount_{$i}"]); $i++) {
            $args["amount_{$i}"] = intval(round(floatval($args["amount_{$i}"])));
        }
        
        // Fix any other fields that might have amounts with "amt" in the name
        foreach ($args as $key => $value) {
            if (strpos($key, 'amt') !== false && is_numeric($value)) {
                $args[$key] = intval(round(floatval($value)));
            }
        }
        
        // Log the fixed PayPal args
        if (function_exists('ppcc_log')) {
            ppcc_log('PayPal args after fix: ' . print_r($args, true), 'debug');
        }
    }
    
    return $args;
}

// Add filters for PayPal Express Checkout and other PayPal gateways
add_filter('woocommerce_paypal_express_checkout_request_body', 'ppcc_fix_paypal_express_request', 999);
add_filter('woocommerce_paypal_express_checkout_request_params', 'ppcc_fix_paypal_express_request', 999);

// PayPal Commerce Platform (newer gateway)
add_filter('woocommerce_paypal_payments_checkout_button_renderer_smart_button', 'ppcc_fix_paypal_payments_data', 999);
add_filter('woocommerce_rest_pre_insert_shop_order_object', 'ppcc_fix_paypal_order_data', 999);
add_filter('woocommerce_paypal_payments_order_info', 'ppcc_fix_paypal_payments_order_info', 10, 2);
add_filter('woocommerce_paypal_payments_create_order_request_body_data', 'ppcc_fix_paypal_payments_request_body', 999);

// General Filters
add_filter('woocommerce_calculated_total', 'ppcc_fix_paypal_amount', 999);
add_filter('woocommerce_order_amount_total', 'ppcc_fix_paypal_amount', 999);

// Fix JavaScript SDK parameters
add_filter('woocommerce_paypal_payments_smart_button_script_params', 'ppcc_fix_paypal_payments_script_params', 999);

/**
 * Fix PayPal Express Checkout request to ensure proper decimal handling
 * 
 * @param array $request Request parameters
 * @return array Fixed parameters
 */
function ppcc_fix_paypal_express_request($request) {
    // Get current currency
    $currency = get_woocommerce_currency();
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // If this is a non-decimal currency, ensure all amounts are integers
    if (in_array($currency, $non_decimal_currencies)) {
        // Log the original request
        if (function_exists('ppcc_log')) {
            ppcc_log('PayPal Express request before fix: ' . print_r($request, true), 'debug');
        }
        
        // Recursively process the request
        $request = ppcc_fix_amounts_recursive($request);
        
        // Log the fixed request
        if (function_exists('ppcc_log')) {
            ppcc_log('PayPal Express request after fix: ' . print_r($request, true), 'debug');
        }
    }
    
    return $request;
}

/**
 * Recursively fix amounts in an array to ensure integer values for non-decimal currencies
 * 
 * @param mixed $data The data to process
 * @return mixed The processed data
 */
function ppcc_fix_amounts_recursive($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = ppcc_fix_amounts_recursive($value);
            } else if (is_numeric($value) && 
                       (strpos($key, 'amount') !== false || 
                        strpos($key, 'amt') !== false || 
                        strpos($key, 'tax') !== false || 
                        strpos($key, 'total') !== false || 
                        strpos($key, 'price') !== false)) {
                $data[$key] = intval(round(floatval($value)));
            }
        }
    }
    
    return $data;
}

/**
 * Fix PayPal Payments data for non-decimal currencies
 * 
 * @param array $data PayPal Payments data
 * @return array Fixed data
 */
function ppcc_fix_paypal_payments_data($data) {
    // Get current currency
    $currency = get_woocommerce_currency();
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // If this is a non-decimal currency, we need to ensure no decimals are passed
    if (in_array($currency, $non_decimal_currencies)) {
        // Log the original data
        if (function_exists('ppcc_log')) {
            ppcc_log('PayPal Payments data before fix: ' . print_r($data, true), 'debug');
        }
        
        // For smart buttons, adjust the data-attributes
        if (isset($data['attributes'])) {
            foreach ($data['attributes'] as $key => $value) {
                if ((strpos($key, 'data-amount') !== false || 
                     strpos($key, 'data-order-total') !== false) && 
                    is_numeric($value)) {
                    $data['attributes'][$key] = intval(round(floatval($value)));
                }
            }
        }
        
        // Log the fixed data
        if (function_exists('ppcc_log')) {
            ppcc_log('PayPal Payments data after fix: ' . print_r($data, true), 'debug');
        }
    }
    
    return $data;
}

/**
 * Fix PayPal order data for non-decimal currencies
 * 
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Request Fixed request
 */
function ppcc_fix_paypal_order_data($request) {
    // Get current currency
    $currency = get_woocommerce_currency();
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // Only process for non-decimal currencies and when the payment method is PayPal
    if (in_array($currency, $non_decimal_currencies) && 
        (isset($_POST['payment_method']) && ppcc_is_paypal_gateway($_POST['payment_method']))) {
        
        // Log the original request
        if (function_exists('ppcc_log')) {
            ppcc_log('PayPal order request before fix: ' . print_r($request, true), 'debug');
        }
        
        // Fix total and other amount fields
        if (is_object($request) && method_exists($request, 'get_total')) {
            $total = $request->get_total();
            if (is_numeric($total)) {
                $request->set_total(intval(round(floatval($total))));
            }
            
            // Fix line items, shipping, tax, etc.
            if (method_exists($request, 'get_items')) {
                foreach ($request->get_items() as $item) {
                    if (method_exists($item, 'get_total') && method_exists($item, 'set_total')) {
                        $item_total = $item->get_total();
                        if (is_numeric($item_total)) {
                            $item->set_total(intval(round(floatval($item_total))));
                        }
                    }
                }
            }
        }
        
        // Log the fixed request
        if (function_exists('ppcc_log')) {
            ppcc_log('PayPal order request after fix: ' . print_r($request, true), 'debug');
        }
    }
    
    return $request;
}

/**
 * Fix PayPal Payments order info for non-decimal currencies
 * 
 * @param array $order_info Order info array
 * @param WC_Order $wc_order WooCommerce order
 * @return array Fixed order info
 */
function ppcc_fix_paypal_payments_order_info($order_info, $wc_order) {
    // Get the currency
    $currency = $wc_order->get_currency();
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // Only process for non-decimal currencies
    if (!in_array($currency, $non_decimal_currencies)) {
        return $order_info;
    }
    
    // Log the original order info
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal Payments order info before fix: ' . print_r($order_info, true), 'debug');
    }
    
    // Fix the purchase units values to ensure they're integers
    if (isset($order_info['purchase_units']) && is_array($order_info['purchase_units'])) {
        foreach ($order_info['purchase_units'] as &$unit) {
            // Fix amount
            if (isset($unit['amount']['value'])) {
                $unit['amount']['value'] = intval(round(floatval($unit['amount']['value'])));
            }
            
            // Fix breakdown
            if (isset($unit['amount']['breakdown'])) {
                foreach ($unit['amount']['breakdown'] as $key => &$value) {
                    if (isset($value['value'])) {
                        $value['value'] = intval(round(floatval($value['value'])));
                    }
                }
            }
            
            // Fix items
            if (isset($unit['items']) && is_array($unit['items'])) {
                foreach ($unit['items'] as &$item) {
                    if (isset($item['unit_amount']['value'])) {
                        $item['unit_amount']['value'] = intval(round(floatval($item['unit_amount']['value'])));
                    }
                }
            }
        }
    }
    
    // Log the fixed order info
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal Payments order info after fix: ' . print_r($order_info, true), 'debug');
    }
    
    return $order_info;
}

/**
 * Fix PayPal Payments request body data for non-decimal currencies
 * 
 * @param array $data Request body data
 * @return array Fixed request body data
 */
function ppcc_fix_paypal_payments_request_body($data) {
    // Get the currency
    $currency = get_woocommerce_currency();
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // Only process for non-decimal currencies
    if (!in_array($currency, $non_decimal_currencies)) {
        return $data;
    }
    
    // Log the original request body
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal Payments request body before fix: ' . print_r($data, true), 'debug');
    }
    
    // Process the data to ensure all monetary values are integers
    $data = ppcc_fix_amounts_recursive($data);
    
    // Explicitly set currency_minor_units to 0 for non-decimal currencies
    if (isset($data['payment_source']) && isset($data['payment_source']['paypal'])) {
        $data['payment_source']['paypal']['currency_minor_units'] = 0;
    }
    
    // Log the fixed request body
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal Payments request body after fix: ' . print_r($data, true), 'debug');
    }
    
    return $data;
}

/**
 * Fix PayPal Payments script parameters for non-decimal currencies
 * 
 * @param array $params Script parameters
 * @return array Fixed script parameters
 */
function ppcc_fix_paypal_payments_script_params($params) {
    // Get the currency
    $currency = get_woocommerce_currency();
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // Only process for non-decimal currencies
    if (!in_array($currency, $non_decimal_currencies)) {
        return $params;
    }
    
    // Log the original script params
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal Payments script params before fix: ' . print_r($params, true), 'debug');
    }
    
    // Fix any amount parameters
    if (isset($params['amount'])) {
        $params['amount'] = intval(round(floatval($params['amount'])));
    }
    
    // Ensure the currency format specifies 0 decimals
    if (!isset($params['currency_format'])) {
        $params['currency_format'] = array(
            'decimals' => 0,
            'decimal_separator' => '',
            'thousand_separator' => ',',
        );
    } else {
        $params['currency_format']['decimals'] = 0;
    }
    
    // Log the fixed script params
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal Payments script params after fix: ' . print_r($params, true), 'debug');
    }
    
    return $params;
}

// Add a specific fix for PayPal JavaScript config
add_filter('script_loader_tag', 'ppcc_fix_paypal_script', 10, 3);

/**
 * Fix decimals in PayPal JavaScript config
 */
function ppcc_fix_paypal_script($tag, $handle, $src) {
    // Only process PayPal scripts
    if (strpos($handle, 'paypal') === false) {
        return $tag;
    }
    
    // Get current currency
    $currency = get_woocommerce_currency();
    $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
    
    // Only process for non-decimal currencies
    if (!in_array($currency, $non_decimal_currencies)) {
        return $tag;
    }
    
    // Log the script tag
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal script tag before fix: ' . substr($tag, 0, 200) . '...', 'debug');
    }
    
    // Replace any currency_format settings to use 0 decimals
    $tag = preg_replace('/(currency_format[^:]*:[^0-9]*)([0-9]+)/', '$10', $tag);
    
    // Fix any potential data-amount attributes
    $tag = preg_replace('/data-amount="([0-9]*\.[0-9]+)"/', function($matches) {
        return 'data-amount="' . intval(round(floatval($matches[1]))) . '"';
    }, $tag);
    
    // Log the modified script tag
    if (function_exists('ppcc_log')) {
        ppcc_log('PayPal script tag after fix: ' . substr($tag, 0, 200) . '...', 'debug');
    }
    
    return $tag;
}
