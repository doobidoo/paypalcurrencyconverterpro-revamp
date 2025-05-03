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
}

// Initialize PayPal hooks
add_action('init', array('PPCC_PayPal_Hooks', 'init'));
