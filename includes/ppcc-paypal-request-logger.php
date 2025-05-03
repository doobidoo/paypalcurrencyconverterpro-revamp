<?php
/**
 * PayPal API Request Logger
 *
 * This file adds comprehensive logging for PayPal API requests and responses
 * to help diagnose issues with currency conversions and PayPal integration.
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * PayPal API Request Logger Class
 */
class PPCC_PayPal_Request_Logger {
    /**
     * The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Log directory
     */
    protected $log_dir;
    
    /**
     * Current request ID for grouping related logs
     */
    protected $request_id;
    
    /**
     * Settings
     */
    protected $settings;
    
    /**
     * Main instance
     *
     * @return PPCC_PayPal_Request_Logger
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        // Generate a unique ID for this request session
        $this->request_id = uniqid('ppcc_', true);
        
        // Set log directory
        $this->log_dir = PPCC_PLUGIN_DIR . 'logs';
        
        // Create directory if it doesn't exist
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
        
        // Get settings
        $this->settings = get_ppcc_option('ppcc_settings');
        
        // Initialize hooks
        $this->init_hooks();
        
        // Log initialization
        $this->log('PayPal Request Logger initialized', 'INIT');
        
        // Log current settings
        $this->log_settings();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // PayPal Standard
        add_filter('woocommerce_paypal_args', array($this, 'log_paypal_standard_args'), 999999, 1);
        
        // PayPal Express Checkout
        add_filter('woocommerce_paypal_express_checkout_request_body', array($this, 'log_paypal_express_request'), 999999, 1);
        add_filter('woocommerce_paypal_express_checkout_request_params', array($this, 'log_paypal_express_params'), 999999, 1);
        
        // PayPal Commerce Platform
        add_filter('woocommerce_paypal_payments_create_order_request_body_data', array($this, 'log_paypal_payments_request'), 999999, 1);
        add_filter('woocommerce_paypal_payments_order_info', array($this, 'log_paypal_order_info'), 999999, 2);
        
        // Direct API calls - hook into WordPress HTTP API
        add_filter('http_request_args', array($this, 'log_http_request'), 10, 2);
        add_filter('pre_http_request', array($this, 'log_pre_http_response'), 10, 3);
        add_filter('http_response', array($this, 'log_http_response'), 10, 3);
        
        // Hook into the browser console for JS debugging
        add_action('wp_footer', array($this, 'add_js_logger'), 999);
        
        // Add AJAX handler for JavaScript logs
        add_action('wp_ajax_ppcc_log_js_api', array($this, 'ajax_log_js_api'));
        add_action('wp_ajax_nopriv_ppcc_log_js_api', array($this, 'ajax_log_js_api'));
    }
    
    /**
     * Log settings
     */
    private function log_settings() {
        $shop_currency = get_woocommerce_currency();
        $target_currency = isset($this->settings['target_currency']) ? $this->settings['target_currency'] : 'USD';
        $conversion_rate = isset($this->settings['conversion_rate']) ? $this->settings['conversion_rate'] : '1.0';
        
        $this->log([
            'shop_currency' => $shop_currency,
            'target_currency' => $target_currency,
            'conversion_rate' => $conversion_rate,
            'meaning' => "1 {$shop_currency} = {$conversion_rate} {$target_currency}",
            'non_decimal_currency' => in_array($target_currency, ['HUF', 'JPY', 'TWD']),
            'precision' => isset($this->settings['precision']) ? $this->settings['precision'] : 2,
            'handling_percentage' => isset($this->settings['handling_percentage']) ? $this->settings['handling_percentage'] : 0,
            'handling_amount' => isset($this->settings['handling_amount']) ? $this->settings['handling_amount'] : 0,
        ], 'SETTINGS');
        
        // Log supported PayPal currencies
        $paypal_currencies = array(
            'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 
            'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 
            'CHF', 'TWD', 'THB', 'GBP', 'CNY'
        );
        
        $this->log([
            'paypal_supported_currencies' => $paypal_currencies,
            'is_target_currency_supported' => in_array($target_currency, $paypal_currencies),
        ], 'PAYPAL_CURRENCIES');
    }
    
    /**
     * Check if a URL is PayPal related
     *
     * @param string $url URL to check
     * @return bool Is PayPal URL
     */
    private function is_paypal_url($url) {
        $paypal_domains = array(
            'paypal.com',
            'sandbox.paypal.com',
            'api-m.paypal.com',
            'api-3t.paypal.com',
            'api.paypal.com',
            'paypalapi',
            'payflowpro.paypal',
        );
        
        foreach ($paypal_domains as $domain) {
            if (strpos($url, $domain) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Log PayPal Standard arguments
     *
     * @param array $args PayPal Standard arguments
     * @return array Unchanged arguments
     */
    public function log_paypal_standard_args($args) {
        $this->log($args, 'PAYPAL_STANDARD_ARGS');
        
        // Specifically check for currency issues
        if (isset($args['currency_code'])) {
            $this->log_currency_check($args['currency_code']);
        }
        
        // Check for amount formatting issues with non-decimal currencies
        if (isset($args['amount'])) {
            $this->log_amount_check($args['amount'], isset($args['currency_code']) ? $args['currency_code'] : '');
        }
        
        return $args;
    }
    
    /**
     * Log PayPal Express Checkout request body
     *
     * @param array $body Request body
     * @return array Unchanged request body
     */
    public function log_paypal_express_request($body) {
        $this->log($body, 'PAYPAL_EXPRESS_REQUEST_BODY');
        
        // Check for currency info deep in the request
        if (isset($body['PAYMENTREQUEST_0_CURRENCYCODE'])) {
            $this->log_currency_check($body['PAYMENTREQUEST_0_CURRENCYCODE']);
        }
        
        // Check amounts
        if (isset($body['PAYMENTREQUEST_0_AMT'])) {
            $this->log_amount_check($body['PAYMENTREQUEST_0_AMT'], isset($body['PAYMENTREQUEST_0_CURRENCYCODE']) ? $body['PAYMENTREQUEST_0_CURRENCYCODE'] : '');
        }
        
        return $body;
    }
    
    /**
     * Log PayPal Express Checkout request parameters
     *
     * @param array $params Request parameters
     * @return array Unchanged request parameters
     */
    public function log_paypal_express_params($params) {
        $this->log($params, 'PAYPAL_EXPRESS_REQUEST_PARAMS');
        return $params;
    }
    
    /**
     * Log PayPal Payments request body data
     *
     * @param array $data Request body data
     * @return array Unchanged data
     */
    public function log_paypal_payments_request($data) {
        $this->log($data, 'PAYPAL_PAYMENTS_REQUEST');
        
        // Check for currency and amount in purchase units
        if (isset($data['purchase_units']) && is_array($data['purchase_units'])) {
            foreach ($data['purchase_units'] as $index => $unit) {
                if (isset($unit['amount']) && isset($unit['amount']['currency_code'])) {
                    $this->log([
                        'unit_index' => $index,
                        'currency_code' => $unit['amount']['currency_code'],
                        'value' => isset($unit['amount']['value']) ? $unit['amount']['value'] : 'not set',
                    ], 'PURCHASE_UNIT_CURRENCY');
                    
                    $this->log_currency_check($unit['amount']['currency_code']);
                    
                    if (isset($unit['amount']['value'])) {
                        $this->log_amount_check($unit['amount']['value'], $unit['amount']['currency_code']);
                    }
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Log PayPal order info
     *
     * @param array $order_info Order info
     * @param WC_Order $wc_order WooCommerce order
     * @return array Unchanged order info
     */
    public function log_paypal_order_info($order_info, $wc_order) {
        // Basic order info
        $this->log([
            'order_id' => $wc_order->get_id(),
            'order_currency' => $wc_order->get_currency(),
            'order_total' => $wc_order->get_total(),
            'payment_method' => $wc_order->get_payment_method(),
        ], 'WC_ORDER_INFO');
        
        // Full PayPal order info
        $this->log($order_info, 'PAYPAL_ORDER_INFO');
        
        return $order_info;
    }
    
    /**
     * Log HTTP request if PayPal related
     *
     * @param array $args Request arguments
     * @param string $url Request URL
     * @return array Unchanged arguments
     */
    public function log_http_request($args, $url) {
        if ($this->is_paypal_url($url)) {
            $this->log([
                'url' => $url,
                'method' => isset($args['method']) ? $args['method'] : 'GET',
                'headers' => isset($args['headers']) ? $args['headers'] : [],
                'body' => isset($args['body']) ? $args['body'] : '',
                'timeout' => isset($args['timeout']) ? $args['timeout'] : 5,
            ], 'HTTP_REQUEST_TO_PAYPAL');
        }
        return $args;
    }
    
    /**
     * Log HTTP pre-response if PayPal related
     *
     * @param mixed $response Response value
     * @param array $args Request arguments
     * @param string $url Request URL
     * @return mixed Unchanged response
     */
    public function log_pre_http_response($response, $args, $url) {
        if ($response !== false && $this->is_paypal_url($url)) {
            $this->log([
                'url' => $url,
                'short_circuit' => true,
                'response' => $response,
            ], 'HTTP_PRE_RESPONSE_FROM_PAYPAL');
        }
        return $response;
    }
    
    /**
     * Log HTTP response if PayPal related
     *
     * @param array $response Response array
     * @param array $args Request arguments
     * @param string $url Request URL
     * @return array Unchanged response
     */
    public function log_http_response($response, $args, $url) {
        if ($this->is_paypal_url($url)) {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            // Check for API errors
            $error = false;
            if ($status_code >= 400) {
                $error = true;
            } elseif (is_string($body) && (
                    stripos($body, 'error') !== false || 
                    stripos($body, 'exception') !== false || 
                    stripos($body, 'currency') !== false)) {
                // This is a potential error or contains currency information
                $error = true;
            }
            
            $log_level = $error ? 'ERROR' : 'HTTP_RESPONSE';
            
            $this->log([
                'url' => $url,
                'status_code' => $status_code,
                'headers' => wp_remote_retrieve_headers($response),
                'body' => $body,
                'cookies' => wp_remote_retrieve_cookies($response),
            ], $log_level . '_FROM_PAYPAL');
        }
        return $response;
    }
    
    /**
     * Add JavaScript logger for PayPal SDK interactions
     */
    public function add_js_logger() {
        if (!is_checkout()) {
            return;
        }
        
        // Only add on checkout page
        ?>
        <script type="text/javascript">
        (function() {
            // Create a unique ID for this session
            var ppccSessionId = '<?php echo $this->request_id; ?>';
            
            // Wait for document ready
            jQuery(document).ready(function($) {
                console.log('PPCC PayPal API Logger Initialized');
                
                // Monitor for PayPal SDK
                var checkPayPal = setInterval(function() {
                    if (typeof window.paypal !== 'undefined') {
                        clearInterval(checkPayPal);
                        console.log('PayPal SDK Detected - Setting up monitors');
                        
                        // Log the PayPal SDK configuration
                        logToServer('PAYPAL_SDK_LOADED', {
                            config: window.paypal,
                            version: window.paypal.version || 'unknown'
                        });
                        
                        // Hook into PayPal order creation
                        if (window.paypal.Buttons && typeof window.paypal.Buttons === 'function') {
                            var originalButtons = window.paypal.Buttons;
                            
                            window.paypal.Buttons = function(config) {
                                // Log the button configuration
                                logToServer('PAYPAL_BUTTON_CONFIG', config);
                                
                                // Hook into createOrder
                                if (config && config.createOrder) {
                                    var originalCreateOrder = config.createOrder;
                                    
                                    config.createOrder = function(data, actions) {
                                        // Log the createOrder call
                                        logToServer('PAYPAL_CREATE_ORDER_CALLED', {
                                            data: data
                                        });
                                        
                                        // Wrap the actions.order.create method to capture the request
                                        var originalOrderCreate = actions.order.create;
                                        actions.order.create = function(orderData) {
                                            // Log the order creation data
                                            logToServer('PAYPAL_ORDER_CREATE_DATA', orderData);
                                            
                                            return originalOrderCreate(orderData)
                                                .then(function(orderId) {
                                                    // Log successful order creation
                                                    logToServer('PAYPAL_ORDER_CREATED', {
                                                        order_id: orderId,
                                                        order_data: orderData
                                                    });
                                                    return orderId;
                                                })
                                                .catch(function(err) {
                                                    // Log error in order creation
                                                    logToServer('PAYPAL_ORDER_ERROR', {
                                                        error: err,
                                                        order_data: orderData
                                                    });
                                                    throw err;
                                                });
                                        };
                                        
                                        // Call the original handler
                                        return originalCreateOrder(data, actions);
                                    };
                                }
                                
                                // Hook into onApprove
                                if (config && config.onApprove) {
                                    var originalOnApprove = config.onApprove;
                                    
                                    config.onApprove = function(data, actions) {
                                        // Log the approval
                                        logToServer('PAYPAL_PAYMENT_APPROVED', data);
                                        
                                        // Call the original handler
                                        return originalOnApprove(data, actions);
                                    };
                                }
                                
                                // Hook into onError
                                if (config && config.onError) {
                                    var originalOnError = config.onError;
                                    
                                    config.onError = function(err) {
                                        // Log the error
                                        logToServer('PAYPAL_ERROR', {
                                            error: typeof err === 'object' ? JSON.stringify(err) : err
                                        });
                                        
                                        // Call the original handler
                                        return originalOnError(err);
                                    };
                                }
                                
                                // Call the original function
                                return originalButtons(config);
                            };
                            
                            // Copy over original properties
                            for (var prop in originalButtons) {
                                if (originalButtons.hasOwnProperty(prop)) {
                                    window.paypal.Buttons[prop] = originalButtons[prop];
                                }
                            }
                        }
                    }
                }, 100);
                
                // Function to log to server
                function logToServer(context, data) {
                    // Add checkout info
                    var checkout_info = {
                        cart_total: $('.order-total .amount').text(),
                        currency: $('meta[name="currency"]').attr('content') || '<?php echo get_woocommerce_currency(); ?>',
                        payment_method: $('input[name="payment_method"]:checked').val() || 'unknown'
                    };
                    
                    // Add current shop currency and target currency from PPCC if available
                    if (typeof ppcc_data !== 'undefined') {
                        checkout_info.ppcc_shop_currency = ppcc_data.shop_currency;
                        checkout_info.ppcc_target_currency = ppcc_data.target_currency;
                        checkout_info.ppcc_conversion_rate = ppcc_data.conversion_rate;
                    }
                    
                    // Add timestamp
                    var timestamp = new Date().toISOString();
                    
                    // Prepare the log data
                    var log_data = {
                        session_id: ppccSessionId,
                        timestamp: timestamp,
                        context: context,
                        checkout_info: checkout_info,
                        data: data
                    };
                    
                    // Log to console for debugging
                    console.log('PPCC PayPal API Log:', context, log_data);
                    
                    // Send to server
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'ppcc_log_js_api',
                            log_data: JSON.stringify(log_data)
                        },
                        success: function(response) {
                            console.log('PPCC log sent to server:', response);
                        },
                        error: function(xhr, status, error) {
                            console.error('PPCC log error:', status, error);
                        }
                    });
                }
            });
        })();
        </script>
        <?php
    }
    
    /**
     * AJAX handler for JavaScript API logs
     */
    public function ajax_log_js_api() {
        if (isset($_POST['log_data'])) {
            $log_data = json_decode(stripslashes($_POST['log_data']), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $context = isset($log_data['context']) ? $log_data['context'] : 'JS_API';
                $data = isset($log_data['data']) ? $log_data['data'] : $log_data;
                $this->log($data, $context);
                
                // Check for known error responses
                if (strpos($context, 'ERROR') !== false || $context === 'PAYPAL_ORDER_ERROR') {
                    if (isset($data['error']) && is_string($data['error']) && 
                        strpos($data['error'], 'CURRENCY_NOT_SUPPORTED') !== false) {
                        // Found the currency error! Log specifically
                        $this->log([
                            'error_type' => 'CURRENCY_NOT_SUPPORTED',
                            'error_message' => $data['error'],
                            'order_data' => isset($data['order_data']) ? $data['order_data'] : '',
                            'checkout_info' => isset($log_data['checkout_info']) ? $log_data['checkout_info'] : '',
                        ], 'CURRENCY_ERROR_DETECTED');
                    }
                }
                
                // Return success
                wp_send_json_success([
                    'message' => 'Log received',
                    'time' => current_time('mysql')
                ]);
            } else {
                $this->log([
                    'error' => 'Invalid JSON',
                    'raw_data' => $_POST['log_data']
                ], 'JS_API_JSON_ERROR');
                
                wp_send_json_error([
                    'message' => 'Invalid JSON data',
                    'json_error' => json_last_error_msg()
                ]);
            }
        } else {
            wp_send_json_error([
                'message' => 'No log data provided'
            ]);
        }
        
        wp_die();
    }
    
    /**
     * Log currency check
     *
     * @param string $currency Currency code
     */
    private function log_currency_check($currency) {
        // List of PayPal supported currencies
        $paypal_currencies = array(
            'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 
            'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 
            'CHF', 'TWD', 'THB', 'GBP', 'CNY'
        );
        
        $is_supported = in_array(strtoupper($currency), $paypal_currencies);
        
        $this->log([
            'currency' => $currency,
            'is_supported_by_paypal' => $is_supported,
            'matches_target_currency' => isset($this->settings['target_currency']) ? (strtoupper($currency) === strtoupper($this->settings['target_currency'])) : false,
        ], $is_supported ? 'CURRENCY_CHECK' : 'CURRENCY_ERROR');
        
        // If not supported, log in more detail
        if (!$is_supported) {
            $this->log([
                'currency' => $currency,
                'shop_currency' => get_woocommerce_currency(),
                'supported_currencies' => $paypal_currencies,
                'notice' => 'This currency is not supported by PayPal and will cause API errors.',
                'fix' => 'Update target_currency in settings to a supported currency.'
            ], 'CURRENCY_NOT_SUPPORTED_ERROR');
        }
    }
    
    /**
     * Log amount check for non-decimal currencies
     *
     * @param mixed $amount Amount
     * @param string $currency Currency code
     */
    private function log_amount_check($amount, $currency) {
        $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
        $is_non_decimal = in_array(strtoupper($currency), $non_decimal_currencies);
        
        $has_decimals = (is_string($amount) && strpos($amount, '.') !== false) || 
                        (is_float($amount) && fmod($amount, 1) !== 0.0);
        
        $this->log([
            'amount' => $amount,
            'amount_type' => gettype($amount),
            'currency' => $currency,
            'is_non_decimal_currency' => $is_non_decimal,
            'has_decimals' => $has_decimals,
            'is_properly_formatted' => !($is_non_decimal && $has_decimals),
        ], ($is_non_decimal && $has_decimals) ? 'AMOUNT_FORMAT_ERROR' : 'AMOUNT_CHECK');
        
        // If non-decimal currency has decimals, log error
        if ($is_non_decimal && $has_decimals) {
            $this->log([
                'amount' => $amount,
                'currency' => $currency,
                'error' => "Non-decimal currency {$currency} should not have decimal places.",
                'fix' => "Convert {$amount} to integer: " . intval($amount),
                'expected_format' => intval($amount),
            ], 'NON_DECIMAL_CURRENCY_ERROR');
        }
    }
    
    /**
     * Log message
     *
     * @param mixed $data Data to log
     * @param string $context Context
     * @param string $level Log level (debug, info, warning, error)
     */
    public function log($data, $context = '', $level = 'info') {
            try {
                // Add timestamp
                $timestamp = date('Y-m-d H:i:s');
                
                // Format the log data
                $log_data = [
                    'timestamp' => $timestamp,
                    'request_id' => $this->request_id,
                    'context' => $context,
                    'data' => $data,
                ];
                
                // Format as JSON
                $log_entry = json_encode($log_data, JSON_PRETTY_PRINT);
                
                // Determine filename based on context
                $context_slug = sanitize_title($context);
                if (strpos(strtolower($context), 'error') !== false || $level === 'error') {
                    $filename = 'paypal-errors.log';
                } elseif (strpos($context, 'CURRENCY') !== false) {
                    $filename = 'paypal-currency.log';
                } else {
                    $filename = 'paypal-requests.log';
                }
                
                // Full path to the log file
                $log_file = $this->log_dir . '/' . $filename;
                
                // Append to log file
                file_put_contents($log_file, $log_entry . "\n\n", FILE_APPEND);
                
                // If this is an error, also log to WooCommerce logger
                if (strpos(strtolower($context), 'error') !== false || $level === 'error') {
                    if (function_exists('wc_get_logger')) {
                        $logger = wc_get_logger();
                        $logger->error($context . ': ' . (is_string($data) ? $data : json_encode($data)), ['source' => 'ppcc']);
                    }
                }
                
                return true;
            } catch (Exception $e) {
                // Fallback to error log
                error_log('PPCC Logger Error: ' . $e->getMessage());
                error_log('Failed to log: ' . $context . ' - ' . (is_string($data) ? $data : json_encode($data)));
                
                return false;
            }
        }
    }

    // Initialize the logger
    function ppcc_init_paypal_request_logger() {
        return PPCC_PayPal_Request_Logger::instance();
    }

    // Global function to access the logger
    function ppcc_api_log($data, $context = '', $level = 'info') {
        $logger = PPCC_PayPal_Request_Logger::instance();
        return $logger->log($data, $context, $level);
    }