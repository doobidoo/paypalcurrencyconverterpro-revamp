<?php
/**
 * PayPal Direct API Hooks
 *
 * This file adds hooks for direct PayPal SDK calls to log requests and responses.
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * PayPal Direct API Hook Class
 */
class PPCC_PayPal_Hooks {
    /**
     * Hook into PayPal PHP SDK
     */
    public static function init() {
        // Log initialization
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log('PayPal API hooks initialized', 'PAYPAL_HOOKS_INIT');
        }
        
        // Add filter to capture cURL requests made by WordPress
        add_filter('http_api_curl', array(__CLASS__, 'capture_curl_request'), 10, 3);
        
        // Add hook for WC PayPal Standard gateway
        add_action('woocommerce_checkout_order_created', array(__CLASS__, 'check_order_currency'), 10, 1);
        
        // Add hook to log any currency conversion issues
        add_filter('woocommerce_order_get_currency', array(__CLASS__, 'log_order_currency'), 10, 2);
        
        // Hook into PayPal checkout API requests to modify the API payload
        add_filter('woocommerce_paypal_args', array(__CLASS__, 'modify_paypal_standard_args'), 999);        
        add_filter('woocommerce_rest_pre_insert_shop_order_object', array(__CLASS__, 'modify_order_currency'), 10, 2);
        add_filter('http_request_args', array(__CLASS__, 'modify_paypal_api_request'), 999, 2);
        
        // Add admin notice
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
        
        // Add checkout notice
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'checkout_notice'));
    }
    
    /**
     * Capture cURL requests
     *
     * @param resource $handle cURL handle
     * @param array $r Request arguments
     * @param string $url Request URL
     */
    public static function capture_curl_request($handle, $r, $url) {
        // Only process PayPal URLs
        if (strpos($url, 'paypal.com') === false) {
            return $handle;
        }
        
        // Get the cURL request info
        $request_headers = isset($r['headers']) ? $r['headers'] : array();
        $request_body = isset($r['body']) ? $r['body'] : '';
        
        // Log the request
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'url' => $url,
                'method' => isset($r['method']) ? $r['method'] : 'GET',
                'headers' => $request_headers,
                'body' => $request_body,
            ], 'CURL_PAYPAL_REQUEST');
        }
        
        // Add a callback to capture the response
        if (function_exists('curl_setopt')) {
            // Set cURL option to get verbose output
            curl_setopt($handle, CURLOPT_VERBOSE, true);
            
            // Create a temporary file to store verbose output
            $verbose_file = fopen('php://temp', 'w+');
            curl_setopt($handle, CURLOPT_STDERR, $verbose_file);
            
            // Store the file handle for later access
            $GLOBALS['ppcc_curl_verbose_file'] = $verbose_file;
            
            // Add a callback function to be called when the cURL request completes
            curl_setopt($handle, CURLOPT_HEADERFUNCTION, array(__CLASS__, 'capture_curl_header'));
        }
        
        return $handle;
    }
    
    /**
     * Capture cURL headers
     *
     * @param resource $handle cURL handle
     * @param string $header Header line
     * @return int Length of the header
     */
    public static function capture_curl_header($handle, $header) {
        // Store headers for later processing
        if (!isset($GLOBALS['ppcc_curl_headers'])) {
            $GLOBALS['ppcc_curl_headers'] = array();
        }
        
        $GLOBALS['ppcc_curl_headers'][] = trim($header);
        
        // Check for PayPal error responses in headers
        if (strpos($header, 'HTTP/') === 0) {
            $status_code = (int) substr($header, 9, 3);
            
            if ($status_code >= 400) {
                // This is an error response, log it
                if (function_exists('ppcc_api_log')) {
                    ppcc_api_log([
                        'status_code' => $status_code,
                        'header' => $header,
                    ], 'CURL_PAYPAL_ERROR', 'error');
                }
            }
        }
        
        // Return the length of the header
        return strlen($header);
    }
    
    /**
     * Check order currency when order is created
     *
     * @param WC_Order $order Order object
     */
    public static function check_order_currency($order) {
        // Only check for PayPal gateways
        if (!function_exists('ppcc_is_paypal_gateway') || !ppcc_is_paypal_gateway($order->get_payment_method())) {
            return;
        }
        
        // Get currency info
        $shop_currency = get_woocommerce_currency();
        $order_currency = $order->get_currency();
        
        // Check if order currency is supported by PayPal
        $paypal_currencies = ppcc_get_paypal_currencies();
        
        if (!in_array($order_currency, $paypal_currencies)) {
            // Log the error
            if (function_exists('ppcc_api_log')) {
                ppcc_api_log([
                    'order_id' => $order->get_id(),
                    'shop_currency' => $shop_currency,
                    'order_currency' => $order_currency,
                    'error' => 'Order currency is not supported by PayPal',
                    'supported_currencies' => $paypal_currencies,
                ], 'ORDER_CURRENCY_ERROR', 'error');
            }
        }
        
        // Get all PayPal related meta from the order
        $paypal_meta = array();
        foreach ($order->get_meta_data() as $meta) {
            if (strpos($meta->key, 'paypal') !== false || strpos($meta->key, 'ppcc') !== false) {
                $paypal_meta[$meta->key] = $meta->value;
            }
        }
        
        // Log order creation with currency info
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'order_id' => $order->get_id(),
                'shop_currency' => $shop_currency,
                'order_currency' => $order_currency,
                'order_total' => $order->get_total(),
                'payment_method' => $order->get_payment_method(),
                'paypal_meta' => $paypal_meta,
            ], 'ORDER_CREATED');
        }
    }
    
    /**
     * Log order currency
     *
     * @param string $currency Currency code
     * @param WC_Order $order Order object
     * @return string Unchanged currency code
     */
    public static function log_order_currency($currency, $order) {
        static $logged_orders = array();
        
        // Only log once per order
        if (in_array($order->get_id(), $logged_orders)) {
            return $currency;
        }
        
        // Only check for PayPal gateways
        if (!function_exists('ppcc_is_paypal_gateway') || !ppcc_is_paypal_gateway($order->get_payment_method())) {
            return $currency;
        }
        
        // Get PayPal supported currencies
        $paypal_currencies = ppcc_get_paypal_currencies();
        
        // Log currency check
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'order_id' => $order->get_id(),
                'currency' => $currency,
                'is_supported_by_paypal' => in_array($currency, $paypal_currencies),
            ], 'ORDER_CURRENCY_CHECK');
        }
        
        // Add order to logged orders
        $logged_orders[] = $order->get_id();
        
        return $currency;
    }
    
    /**
     * Display admin notice about debugging
     */
    public static function admin_notice() {
        $screen = get_current_screen();
        
        // Only show on WooCommerce screens
        if (!$screen || strpos($screen->id, 'woocommerce') === false) {
            return;
        }
        
        // Show notice
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>PayPal Currency Converter Debug:</strong> PayPal API debugging is active. All PayPal requests and responses are being logged.</p>';
        echo '<p>Log files are stored in: <code>' . PPCC_PLUGIN_DIR . 'logs/</code></p>';
        echo '</div>';
    }
    
    /**
     * Display checkout notice about debugging
     */
    public static function checkout_notice() {
        // Show only if current user can manage options
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get settings
        $settings = get_ppcc_option('ppcc_settings');
        
        // Get currency info
        $shop_currency = get_woocommerce_currency();
        $target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : 'USD';
        
        // Show notice
        echo '<div class="woocommerce-info">';
        echo '<strong>PayPal Currency Debug:</strong> ';
        echo 'Shop Currency: ' . $shop_currency . ', ';
        echo 'PayPal Target Currency: ' . $target_currency . ', ';
        echo 'Logging Active';
        echo '</div>';
    }
    
    /**
     * Modify PayPal API request to use the target currency
     *
     * @param array $request_args HTTP request arguments
     * @param string $url Request URL
     * @return array Modified request arguments
     */
    public static function modify_paypal_api_request($request_args, $url) {
        // Only process PayPal API calls
        if (strpos($url, 'paypal.com') === false) {
            return $request_args;
        }

        // Check if this is a orders API call
        if (strpos($url, '/v2/checkout/orders') !== false) {
            // Get PayPal settings
            $settings = get_ppcc_option('ppcc_settings');
            if (!is_array($settings) || empty($settings)) {
                return $request_args;
            }

            $shop_currency = get_woocommerce_currency();
            $target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : 'USD';
            $conversion_rate = isset($settings['conversion_rate']) ? floatval($settings['conversion_rate']) : 1.0;

            // If there's no conversion needed, return the original request
            if ($shop_currency === $target_currency) {
                return $request_args;
            }

            // Log the original request for debugging
            if (function_exists('ppcc_api_log')) {
                ppcc_api_log([
                    'original_request' => $request_args,
                    'url' => $url,
                    'shop_currency' => $shop_currency,
                    'target_currency' => $target_currency,
                    'conversion_rate' => $conversion_rate
                ], 'PAYPAL_API_REQUEST_BEFORE_MODIFICATION');
            }

            // Check if the request has a body
            if (empty($request_args['body'])) {
                return $request_args;
            }

            // Decode the JSON body
            $body = json_decode($request_args['body'], true);
            if (!is_array($body)) {
                return $request_args;
            }

            // Update the currency and values in the request body
            if (isset($body['purchase_units']) && is_array($body['purchase_units'])) {
                foreach ($body['purchase_units'] as &$unit) {
                    // Get the handling fee from the session
                    $handling_fee = 0;
                    if (function_exists('WC') && WC()->session) {
                        $handling_fee = WC()->session->get('ppcc_handling_fee', 0);
                    }

                    // Convert the handling fee to the target currency
                    $converted_handling_fee = round($handling_fee * $conversion_rate, 2);

                    // Update the currency code
                    if (isset($unit['amount']['currency_code'])) {
                        $unit['amount']['currency_code'] = $target_currency;
                    }

                    // Convert the amount value
                    if (isset($unit['amount']['value'])) {
                        $original_value = floatval($unit['amount']['value']);
                        $converted_value = $original_value * $conversion_rate;
                        $unit['amount']['value'] = number_format($converted_value, 2, '.', '');
                    }

                    // Update the breakdown if it exists
                    if (isset($unit['amount']['breakdown'])) {
                        $breakdown = &$unit['amount']['breakdown'];

                        // Convert each value in the breakdown
                        foreach ($breakdown as $key => &$item) {
                            if (isset($item['currency_code'])) {
                                $item['currency_code'] = $target_currency;
                            }
                            if (isset($item['value'])) {
                                $original_value = floatval($item['value']);
                                $converted_value = $original_value * $conversion_rate;
                                $item['value'] = number_format($converted_value, 2, '.', '');
                            }
                        }

                        // Add or update the handling fee in the breakdown
                        if ($converted_handling_fee > 0) {
                            if (!isset($breakdown['handling'])) {
                                $breakdown['handling'] = [
                                    'currency_code' => $target_currency,
                                    'value' => number_format($converted_handling_fee, 2, '.', '')
                                ];
                            } else {
                                $breakdown['handling']['currency_code'] = $target_currency;
                                $breakdown['handling']['value'] = number_format($converted_handling_fee, 2, '.', '');
                            }
                        }
                    }

                    // Update any items in the purchase unit
                    if (isset($unit['items']) && is_array($unit['items'])) {
                        foreach ($unit['items'] as &$item) {
                            if (isset($item['unit_amount']['currency_code'])) {
                                $item['unit_amount']['currency_code'] = $target_currency;
                            }
                            if (isset($item['unit_amount']['value'])) {
                                $original_value = floatval($item['unit_amount']['value']);
                                $converted_value = $original_value * $conversion_rate;
                                $item['unit_amount']['value'] = number_format($converted_value, 2, '.', '');
                            }
                            if (isset($item['tax']['currency_code'])) {
                                $item['tax']['currency_code'] = $target_currency;
                            }
                            if (isset($item['tax']['value'])) {
                                $original_value = floatval($item['tax']['value']);
                                $converted_value = $original_value * $conversion_rate;
                                $item['tax']['value'] = number_format($converted_value, 2, '.', '');
                            }
                        }
                    }
                }
            }

            // Encode the modified body
            $request_args['body'] = json_encode($body);

            // Log the modified request for debugging
            if (function_exists('ppcc_api_log')) {
                ppcc_api_log([
                    'modified_request' => $request_args,
                    'converted_body' => $body
                ], 'PAYPAL_API_REQUEST_AFTER_MODIFICATION');
            }
        }

        return $request_args;
    }

    /**
     * Modify PayPal Standard arguments
     *
     * @param array $args PayPal arguments
     * @return array Modified arguments
     */
    public static function modify_paypal_standard_args($args) {
        // Get PayPal settings
        $settings = get_ppcc_option('ppcc_settings');
        if (!is_array($settings) || empty($settings)) {
            return $args;
        }

        $shop_currency = get_woocommerce_currency();
        $target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : 'USD';
        $conversion_rate = isset($settings['conversion_rate']) ? floatval($settings['conversion_rate']) : 1.0;

        // If there's no conversion needed, return the original args
        if ($shop_currency === $target_currency) {
            return $args;
        }

        // Log the original args for debugging
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'original_args' => $args,
                'shop_currency' => $shop_currency,
                'target_currency' => $target_currency,
                'conversion_rate' => $conversion_rate
            ], 'PAYPAL_STANDARD_ARGS_BEFORE_MODIFICATION');
        }

        // Change currency
        $args['currency_code'] = $target_currency;

        // Get the handling fee from the session
        $handling_fee = 0;
        if (function_exists('WC') && WC()->session) {
            $handling_fee = WC()->session->get('ppcc_handling_fee', 0);
        }

        // Convert the handling fee to the target currency
        $converted_handling_fee = round($handling_fee * $conversion_rate, 2);

        // Convert amount values
        $amount_fields = ['amount', 'shipping', 'tax', 'discount_amount'];
        foreach ($amount_fields as $field) {
            if (isset($args[$field])) {
                $args[$field] = number_format(floatval($args[$field]) * $conversion_rate, 2, '.', '');
            }
        }

        // Add handling fee
        if ($converted_handling_fee > 0) {
            $args['handling'] = number_format($converted_handling_fee, 2, '.', '');
        }

        // Convert line item amounts
        foreach ($args as $key => $value) {
            if (preg_match('/^amount_\d+$/', $key)) {
                $args[$key] = number_format(floatval($value) * $conversion_rate, 2, '.', '');
            }
        }

        // Log the modified args for debugging
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'modified_args' => $args
            ], 'PAYPAL_STANDARD_ARGS_AFTER_MODIFICATION');
        }

        return $args;
    }

    /**
     * Modify order currency before it's created
     *
     * @param WC_Order $order Order object
     * @param array $request Request data
     * @return WC_Order Modified order
     */
    public static function modify_order_currency($order, $request) {
        // Only modify if it's a PayPal payment
        $payment_method = $order->get_payment_method();
        if (!$payment_method || !function_exists('ppcc_is_paypal_gateway') || !ppcc_is_paypal_gateway($payment_method)) {
            return $order;
        }

        // Get PayPal settings
        $settings = get_ppcc_option('ppcc_settings');
        if (!is_array($settings) || empty($settings)) {
            return $order;
        }

        $shop_currency = get_woocommerce_currency();
        $target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : 'USD';

        // If there's no conversion needed, return the original order
        if ($shop_currency === $target_currency) {
            return $order;
        }

        // Set the order currency to the target currency
        $order->set_currency($target_currency);

        // Log the currency change
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'order_id' => $order->get_id(),
                'original_currency' => $shop_currency,
                'new_currency' => $target_currency
            ], 'ORDER_CURRENCY_MODIFIED');
        }

        return $order;
    }
}

// Initialize PayPal hooks
add_action('init', array('PPCC_PayPal_Hooks', 'init'));
