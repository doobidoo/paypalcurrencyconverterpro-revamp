<?php
/**
 * Main plugin class
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Core class for PayPal Currency Converter PRO
 */
class PPCC_Core {
    /**
     * The single instance of the class
     *
     * @var PPCC_Core
     */
    protected static $_instance = null;
    
    /**
     * Plugin settings
     *
     * @var array
     */
    protected $settings = array();
    
    /**
     * Default settings
     *
     * @var array
     */
    protected static $default_settings = array(
        'target_currency' => 'USD',
        'conversion_rate' => 1.0,    // Float value not string
        'auto_update' => 'off',
        'update_frequency' => 'daily',
        'time_stamp' => 0,
        'exrlog' => 'on', 
        'api_selection' => 'currencyconverterapi',
        'precision' => 2,            // Changed from 5 to 2 - Most currencies use 2 decimals
        'handling_percentage' => 0.0, // Float value not string
        'handling_amount' => 0.00,    // Float value not string
        'handling_taxable' => 'on',
        'shipping_handling_fee' => 'on',
        'handling_title' => 'Handling',
        'ppcc_use_custom_currency' => 'off',
        'ppcc_custom_currency_code' => '',
        'ppcc_custom_currency_symbol' => '',
        'ppcc_custom_currency_name' => '',
        'autocomplete' => 'on',
        'autoprocessing' => 'on',
        'suppress_order_on_hold_email' => 'on',
        'email_order_completed_note' => 'off',
        'order_email_note' => 'This order is paid with PayPal, converted with the currency exchange rate <em>1 %3$s = %1$s %2$s</em>.<br>Billed Total: <strong>%4$s</strong><br>Including handling fee percentage <strong>%5$s</strong> plus <strong>%6$s</strong> fix.',
        'keep_data_on_uninstall' => 'off',
        'debug' => 'off',
        // API credentials
        'oer_api_id' => '',
        'xignite_api_id' => '',
        'apilayer_api_id' => '',
        'fixer_io_api_id' => '',
        'currencyconverterapi_id' => '',
    );
    
    /**
     * Main PPCC_Core Instance
     *
     * Ensures only one instance is loaded or can be loaded.
     *
     * @static
     * @return PPCC_Core - Main instance
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        // Make sure WooCommerce is active before proceeding
        if (!function_exists('WC')) {
            return;
        }
        
        // Load settings
        $this->settings = get_ppcc_option('ppcc_settings');
        
        // Check if settings exist, if not use legacy or defaults
        if (empty($this->settings) || !is_array($this->settings)) {
            $legacy_settings = get_ppcc_option('ppcc-options');
            if (!empty($legacy_settings) && is_array($legacy_settings)) {
                $this->settings = self::migrate_from_legacy($legacy_settings);
                update_ppcc_option('ppcc_settings', $this->settings);
            } else {
                $this->settings = self::$default_settings;
                update_ppcc_option('ppcc_settings', $this->settings);
            }
        } else {
            // Make a backup of current settings
            update_ppcc_option('ppcc_settings_backup', $this->settings);
            
            // Ensure numeric settings are stored as proper numeric types
            if (isset($this->settings['conversion_rate']) && !is_float($this->settings['conversion_rate'])) {
                $this->settings['conversion_rate'] = (float)$this->settings['conversion_rate'];
            }
            if (isset($this->settings['handling_percentage']) && !is_float($this->settings['handling_percentage'])) {
                $this->settings['handling_percentage'] = (float)$this->settings['handling_percentage'];
            }
            if (isset($this->settings['handling_amount']) && !is_float($this->settings['handling_amount'])) {
                $this->settings['handling_amount'] = (float)$this->settings['handling_amount'];
            }
            if (isset($this->settings['precision']) && !is_int($this->settings['precision'])) {
                $this->settings['precision'] = (int)$this->settings['precision'];
            }
            
            // Ensure precision is reasonable (max 2 for regular currencies, 0 for non-decimal currencies)
            if (isset($this->settings['precision']) && $this->settings['precision'] > 2) {
                // Check if target currency is a non-decimal currency
                $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
                if (isset($this->settings['target_currency']) && in_array($this->settings['target_currency'], $non_decimal_currencies)) {
                    $this->settings['precision'] = 0; // Force 0 precision for non-decimal currencies
                } else {
                    $this->settings['precision'] = 2; // Default to 2 for most currencies
                }
                
                // Log the change
                if (function_exists('ppcc_log')) {
                    ppcc_log('Adjusted precision to a more reasonable value: ' . $this->settings['precision']);
                }
            }
            
            // Save the corrected settings
            update_ppcc_option('ppcc_settings', $this->settings);
        }
        
        // Init hooks
        $this->init_hooks();
        
        // Initialize components
        $this->init_components();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        try {
            // Admin hooks
            if (is_admin()) {
                add_action('admin_init', array($this, 'admin_init'));
                add_action('admin_menu', array($this, 'admin_menu'));
                add_action('admin_notices', array($this, 'admin_notices'));
            }
            
            // Plugin lifecycle hooks
            
            // WooCommerce hooks - these are the core hooks that handle currency conversion
            add_filter('woocommerce_currencies', array($this, 'add_custom_currency'));
            add_filter('woocommerce_currency_symbol', array($this, 'add_custom_currency_symbol'), 10, 2);
            
            // Add PayPal-specific hooks for non-decimal currencies
            add_filter('woocommerce_price_format', array($this, 'maybe_fix_price_format'), 10, 2);
            add_filter('woocommerce_price_decimals', array($this, 'maybe_fix_price_decimals'), 10, 1);
            
            // Add specific filter for PayPal to ensure conversion rate is applied
            add_filter('woocommerce_paypal_args', array($this, 'apply_conversion_to_paypal_args'), 999, 2);
            
            // Hook into PayPal Commerce Platform (newer PayPal integration)
            add_filter('woocommerce_paypal_payments_create_order_request_body_data', array($this, 'apply_conversion_to_paypal_request_body'), 999, 1);
            
            // Scheduler
            add_action('ppcc_cexr_update', array($this, 'scheduled_update'));
            
            // AJAX handler
            add_action('wp_ajax_ppcc', array($this, 'ajax_handler'));
            add_action('wp_ajax_nopriv_ppcc', array($this, 'ajax_handler'));
            
            // Filter to access settings in PayPal gateways without dynamic properties
            add_filter('woocommerce_payment_gateway_get_ppcc_settings', array($this, 'get_ppcc_settings_for_gateway'), 10, 2);
        } catch (Exception $e) {
            // Log the error
            if (function_exists('ppcc_log')) {
                ppcc_log('Error initializing hooks: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Apply conversion to PayPal arguments for standard PayPal gateway
     * 
     * @param array $args PayPal arguments
     * @param WC_Order $order Order object
     * @return array Modified arguments
     */
    public function apply_conversion_to_paypal_args($args, $order = null) {
        // Get current shop currency
        $shop_currency = get_woocommerce_currency();
        $target_currency = $this->settings['target_currency'];
        
        // If already using a supported currency and it matches target, no need to modify
        if ($shop_currency === $target_currency) {
            return $args;
        }
        
        // We need to ensure the currency code is set to our target currency
        $args['currency_code'] = $target_currency;
        
        // For non-decimal currencies, ensure all amounts are integers
        $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
        $is_non_decimal = in_array($target_currency, $non_decimal_currencies);
        
        // Get conversion rate
        $conversion_rate = (float)$this->settings['conversion_rate'];
        
        // If no valid conversion rate, abort
        if ($conversion_rate <= 0) {
            // Log the issue
            if (function_exists('ppcc_log')) {
                ppcc_log('Invalid conversion rate in PayPal args: ' . $conversion_rate, 'error');
            }
            return $args;
        }
        
        // Log the original args
        if (function_exists('ppcc_log')) {
            ppcc_log('Original PayPal args: ' . print_r($args, true), 'debug');
        }
        
        // Convert all amount fields
        $amount_fields = array(
            'amount', 'tax', 'tax_cart', 'shipping', 'discount_amount_cart',
            'handling_cart', 'shipping_discount', 'insurance_amount'
        );
        
        foreach ($amount_fields as $field) {
            if (isset($args[$field])) {
                $original_value = (float)$args[$field];
                $converted_value = $original_value * $conversion_rate;
                
                // Apply appropriate formatting
                if ($is_non_decimal) {
                    $args[$field] = intval(round($converted_value));
                } else {
                    $args[$field] = round($converted_value, 2);
                }
                
                // Log the conversion
                if (function_exists('ppcc_log')) {
                    ppcc_log(sprintf(
                        'Converted PayPal %s: %s %s → %s %s',
                        $field,
                        $original_value,
                        $shop_currency,
                        $args[$field],
                        $target_currency
                    ), 'debug');
                }
            }
        }
        
        // Handle line items (amount_X fields)
        for ($i = 1; isset($args["amount_{$i}"]); $i++) {
            $original_value = (float)$args["amount_{$i}"];
            $converted_value = $original_value * $conversion_rate;
            
            // Apply appropriate formatting
            if ($is_non_decimal) {
                $args["amount_{$i}"] = intval(round($converted_value));
            } else {
                $args["amount_{$i}"] = round($converted_value, 2);
            }
            
            // Log the conversion
            if (function_exists('ppcc_log')) {
                ppcc_log(sprintf(
                    'Converted PayPal line item %d: %s %s → %s %s',
                    $i,
                    $original_value,
                    $shop_currency,
                    $args["amount_{$i}"],
                    $target_currency
                ), 'debug');
            }
        }
        
        // Log the modified args
        if (function_exists('ppcc_log')) {
            ppcc_log('Modified PayPal args: ' . print_r($args, true), 'debug');
        }
        
        return $args;
    }
    
    /**
     * Apply conversion to PayPal Commerce Platform request body data
     * 
     * @param array $data Request body data
     * @return array Modified request body data
     */
    public function apply_conversion_to_paypal_request_body($data) {
        // Get current shop currency
        $shop_currency = get_woocommerce_currency();
        $target_currency = $this->settings['target_currency'];
        
        // If already using a supported currency and it matches target, no need to modify
        if ($shop_currency === $target_currency) {
            return $data;
        }
        
        // For non-decimal currencies, ensure all amounts are integers
        $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
        $is_non_decimal = in_array($target_currency, $non_decimal_currencies);
        
        // Get conversion rate
        $conversion_rate = (float)$this->settings['conversion_rate'];
        
        // If no valid conversion rate, abort
        if ($conversion_rate <= 0) {
            // Log the issue
            if (function_exists('ppcc_log')) {
                ppcc_log('Invalid conversion rate in PayPal request body: ' . $conversion_rate, 'error');
            }
            return $data;
        }
        
        // Log the original data
        if (function_exists('ppcc_log')) {
            ppcc_log('Original PayPal request body: ' . print_r($data, true), 'debug');
        }
        
        // Update currency in request body
        if (isset($data['currency_code'])) {
            $data['currency_code'] = $target_currency;
        }
        
        // Convert purchase units
        if (isset($data['purchase_units']) && is_array($data['purchase_units'])) {
            foreach ($data['purchase_units'] as &$unit) {
                // Convert amount
                if (isset($unit['amount'])) {
                    // Update currency code
                    if (isset($unit['amount']['currency_code'])) {
                        $unit['amount']['currency_code'] = $target_currency;
                    }
                    
                    // Convert value
                    if (isset($unit['amount']['value'])) {
                        $original_value = (float)$unit['amount']['value'];
                        $converted_value = $original_value * $conversion_rate;
                        
                        // Apply appropriate formatting
                        if ($is_non_decimal) {
                            $unit['amount']['value'] = (string)intval(round($converted_value));
                        } else {
                            $unit['amount']['value'] = (string)round($converted_value, 2);
                        }
                        
                        // Log the conversion
                        if (function_exists('ppcc_log')) {
                            ppcc_log(sprintf(
                                'Converted purchase unit amount: %s %s → %s %s',
                                $original_value,
                                $shop_currency,
                                $unit['amount']['value'],
                                $target_currency
                            ), 'debug');
                        }
                    }
                    
                    // Convert breakdown elements
                    if (isset($unit['amount']['breakdown']) && is_array($unit['amount']['breakdown'])) {
                        foreach ($unit['amount']['breakdown'] as $breakdown_key => &$breakdown_value) {
                            if (isset($breakdown_value['value'])) {
                                $original_value = (float)$breakdown_value['value'];
                                $converted_value = $original_value * $conversion_rate;
                                
                                // Apply appropriate formatting
                                if ($is_non_decimal) {
                                    $breakdown_value['value'] = (string)intval(round($converted_value));
                                } else {
                                    $breakdown_value['value'] = (string)round($converted_value, 2);
                                }
                                
                                // Update currency code
                                if (isset($breakdown_value['currency_code'])) {
                                    $breakdown_value['currency_code'] = $target_currency;
                                }
                            }
                        }
                    }
                }
                
                // Convert items
                if (isset($unit['items']) && is_array($unit['items'])) {
                    foreach ($unit['items'] as &$item) {
                        // Unit amount
                        if (isset($item['unit_amount']['value'])) {
                            $original_value = (float)$item['unit_amount']['value'];
                            $converted_value = $original_value * $conversion_rate;
                            
                            // Apply appropriate formatting
                            if ($is_non_decimal) {
                                $item['unit_amount']['value'] = (string)intval(round($converted_value));
                            } else {
                                $item['unit_amount']['value'] = (string)round($converted_value, 2);
                            }
                            
                            // Update currency code
                            if (isset($item['unit_amount']['currency_code'])) {
                                $item['unit_amount']['currency_code'] = $target_currency;
                            }
                        }
                        
                        // Tax
                        if (isset($item['tax']['value'])) {
                            $original_value = (float)$item['tax']['value'];
                            $converted_value = $original_value * $conversion_rate;
                            
                            // Apply appropriate formatting
                            if ($is_non_decimal) {
                                $item['tax']['value'] = (string)intval(round($converted_value));
                            } else {
                                $item['tax']['value'] = (string)round($converted_value, 2);
                            }
                            
                            // Update currency code
                            if (isset($item['tax']['currency_code'])) {
                                $item['tax']['currency_code'] = $target_currency;
                            }
                        }
                    }
                }
            }
        }
        
        // Log the modified data
        if (function_exists('ppcc_log')) {
            ppcc_log('Modified PayPal request body: ' . print_r($data, true), 'debug');
        }
        
        return $data;
    }
    
    /**
     * Initialize components
     */
    public function init_components() {
        try {
            // Only initialize if we have valid settings
            if (empty($this->settings) || !is_array($this->settings)) {
                return;
            }
            
            // Order converter
            if (class_exists('PPCC_Order_Converter')) {
                new PPCC_Order_Converter($this->settings);
            }
            
            // Admin
            if (is_admin() && class_exists('PPCC_Admin')) {
                new PPCC_Admin($this->settings);
            }
            
            // Checkout
            if (class_exists('PPCC_Checkout')) {
                new PPCC_Checkout($this->settings);
            }
            
            // Exchange rates
            if (class_exists('PPCC_Exchange_Rates')) {
                new PPCC_Exchange_Rates($this->settings);
            }
        } catch (Exception $e) {
            // Log the error
            if (function_exists('ppcc_log')) {
                ppcc_log('Error initializing components: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Admin initialization
     */
    public function admin_init() {
        // Register settings
        register_setting( 'ppcc_settings', 'ppcc_settings', array( $this, 'validate_settings' ) );
    }
    
    /**
     * Add admin menu
     */
    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'PayPal Currency Converter PRO', 'ppcc-pro' ),
            __( 'Currency Converter', 'ppcc-pro' ),
            'manage_options',
            'ppcc_settings',
            array( 'PPCC_Admin', 'settings_page' )
        );
    }
    
    /**
     * Admin notices
     */
    public function admin_notices() {
        // Show upgrade notice if applicable
        if ( get_transient( 'ppcc_upgraded_from_legacy' ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>' . 
                __( 'PayPal Currency Converter PRO has been upgraded to version 4.0. Your settings have been migrated automatically. <a href="admin.php?page=ppcc_settings">Review your settings</a>.', 'ppcc-pro' ) . 
                '</p></div>';
        }
        
        // Check for required PHP extensions
        if ( ! function_exists( 'curl_init' ) && $this->settings['api_selection'] != 'custom' ) {
            echo '<div class="error"><p>' . 
                __( 'PayPal Currency Converter PRO requires the PHP cURL extension to be installed.', 'ppcc-pro' ) . 
                '</p></div>';
        }
        
        // Check for conflicting plugins
        $this->check_conflicting_plugins();
    }
    
    /**
     * Check for conflicting plugins
     */
    private function check_conflicting_plugins() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }
        
        $conflicting_plugins = array(
            'woocommerce-all-currencies/woocommerce-all-currencies.php' => 'WooCommerce All Currencies',
            'woocommerce-custom-currencies/woocommerce-custom-currencies.php' => 'WooCommerce Custom Currencies',
        );
        
        foreach ( $conflicting_plugins as $plugin_path => $plugin_name ) {
            if ( is_plugin_active( $plugin_path ) ) {
                echo '<div class="error"><p>' . 
                    sprintf( 
                        __( 'PayPal Currency Converter PRO may conflict with %s. Please deactivate it for proper functioning.', 'ppcc-pro' ),
                        $plugin_name
                    ) . 
                    '</p></div>';
            }
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook( 'ppcc_cexr_update' );
    }
    
    /**
     * AJAX handler
     */
    public function ajax_handler() {
        // Handle AJAX requests
        if ( isset( $_GET['ppcc_function'] ) ) {
            switch ( $_GET['ppcc_function'] ) {
                case 'cexr_update':
                    $this->scheduled_update();
                    break;
            }
        }
        
        wp_die();
    }
    
    /**
     * Add custom currency to WooCommerce
     */
    public function add_custom_currency( $currencies ) {
        if ( 'on' == $this->settings['ppcc_use_custom_currency'] && ! empty( $this->settings['ppcc_custom_currency_code'] ) ) {
            $currencies[ $this->settings['ppcc_custom_currency_code'] ] = $this->settings['ppcc_custom_currency_name'];
        }
        return $currencies;
    }
    
    /**
     * Add custom currency symbol to WooCommerce
     */
    public function add_custom_currency_symbol( $currency_symbol, $currency ) {
        if ( 'on' == $this->settings['ppcc_use_custom_currency'] && $currency == $this->settings['ppcc_custom_currency_code'] ) {
            return $this->settings['ppcc_custom_currency_symbol'];
        }
        return $currency_symbol;
    }
    
    /**
     * Maybe fix price format for non-decimal currencies
     * 
     * @param string $format Price format
     * @param string $currency Currency code
     * @return string Modified format
     */
    public function maybe_fix_price_format( $format, $currency = '' ) {
        if ( empty( $currency ) ) {
            $currency = get_woocommerce_currency();
        }
        
        $non_decimal_currencies = array( 'HUF', 'JPY', 'TWD' );
        
        if ( in_array( $currency, $non_decimal_currencies ) ) {
            // Log that we're modifying the price format
            if ( function_exists( 'ppcc_log' ) ) {
                ppcc_log( 'Modifying price format for ' . $currency . ' (non-decimal currency)', 'debug' );
            }
            
            // Make sure we're using 0 decimals
            return str_replace( '%2$s', '%1$s', $format );
        }
        
        return $format;
    }
    
    /**
     * Maybe fix price decimals for non-decimal currencies
     * 
     * @param int $decimals Number of decimals
     * @return int Modified number of decimals
     */
    public function maybe_fix_price_decimals( $decimals ) {
        $currency = get_woocommerce_currency();
        $non_decimal_currencies = array( 'HUF', 'JPY', 'TWD' );
        
        if ( in_array( $currency, $non_decimal_currencies ) ) {
            // Log that we're modifying the decimals
            if ( function_exists( 'ppcc_log' ) ) {
                ppcc_log( 'Setting decimals to 0 for ' . $currency . ' (non-decimal currency)', 'debug' );
            }
            
            return 0;
        }
        
        return $decimals;
    }
    
    /**
     * Scheduled update for exchange rates
     */
    public function scheduled_update() {
        $exchange_rates = new PPCC_Exchange_Rates( $this->settings );
        $exchange_rates->update_exchange_rate();
    }
    
    /**
     * Validate settings
     */
    public function validate_settings( $input ) {
        // Basic validation
        $output = array();
        
        // Checkboxes
        $checkboxes = array(
            'ppcc_use_custom_currency',
            'auto_update',
            'exrlog',
            'currencyconverterapi_pro',
            'autocomplete',
            'autoprocessing',
            'suppress_order_on_hold_email',
            'email_order_completed_note',
            'handling_taxable',
            'shipping_handling_fee',
            'debug',
            'keep_data_on_uninstall',
        );
        
        foreach ( $checkboxes as $checkbox ) {
            $output[ $checkbox ] = isset( $input[ $checkbox ] ) ? 'on' : 'off';
        }
        
        // Text fields with sanitization
        $text_fields = array(
            'target_currency',
            'api_selection',
            'handling_title',
            'ppcc_custom_currency_code',
            'ppcc_custom_currency_name',
            'ppcc_custom_currency_symbol',
            'order_email_note',
        );
        
        foreach ( $text_fields as $field ) {
            $output[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
        }
        
        // API credentials
        $api_fields = array(
            'oer_api_id',
            'xignite_api_id',
            'apilayer_api_id',
            'fixer_io_api_id',
            'currencyconverterapi_id',
        );
        
        foreach ( $api_fields as $field ) {
            $output[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
        }
        
        // Numeric fields with proper handling for high-precision values
        $output['conversion_rate'] = isset( $input['conversion_rate'] ) && is_numeric($input['conversion_rate']) 
            ? floatval( $input['conversion_rate'] ) 
            : 1.0;
        $output['handling_percentage'] = isset( $input['handling_percentage'] ) && is_numeric($input['handling_percentage']) 
            ? floatval( $input['handling_percentage'] ) 
            : 0.0;
        $output['handling_amount'] = isset( $input['handling_amount'] ) && is_numeric($input['handling_amount']) 
            ? floatval( $input['handling_amount'] ) 
            : 0.00;
            
        // Ensure precision is reasonable (max 2 for regular currencies, 0 for non-decimal currencies)
        $precision = isset( $input['precision'] ) && is_numeric($input['precision']) 
            ? absint( $input['precision'] ) 
            : 2;
        
        // Check if target currency is a non-decimal currency
        $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
        $target_currency = isset($input['target_currency']) ? $input['target_currency'] : '';
        
        if (in_array($target_currency, $non_decimal_currencies)) {
            $output['precision'] = 0; // Force 0 precision for non-decimal currencies
        } else {
            // Cap precision at 2 for most currencies
            $output['precision'] = min($precision, 2);
        }
        
        // Ensure conversion rate is valid and has required precision
        if ( $output['conversion_rate'] <= 0 ) {
            $output['conversion_rate'] = 1.0;
        }
        
        // Timestamp
        $output['time_stamp'] = current_time( 'timestamp' );
        
        return $output;
    }
    
    /**
     * Set default settings
     */
    public static function set_default_settings() {
        update_ppcc_option( 'ppcc_settings', self::$default_settings );
    }
    
    /**
     * Get PPCC settings for a gateway
     * This is used to avoid dynamic property deprecation warnings
     * 
     * @param array|null $settings Current settings or null
     * @param string $gateway_id Gateway ID
     * @return array PPCC settings
     */
    public function get_ppcc_settings_for_gateway( $settings, $gateway_id ) {
        // Check if we have transient settings for this gateway
        $gateway_settings = get_transient( 'ppcc_settings_for_' . $gateway_id );
        
        if ( $gateway_settings ) {
            return $gateway_settings;
        }
        
        // Fallback to global settings
        return $this->settings;
    }
    
    /**
     * Migrate from legacy settings
     */
    public static function migrate_from_legacy( $legacy_settings ) {
        // Start with defaults
        $new_settings = self::$default_settings;
        
        // Map old settings to new settings
        $mapping = array(
            'target_currency' => 'target_currency',
            'conversion_rate' => 'conversion_rate',
            'auto_update' => 'auto_update',
            'time_stamp' => 'time_stamp',
            'exrlog' => 'exrlog',
            'oer_api_id' => 'oer_api_id',
            'xignite_api_id' => 'xignite_api_id',
            'apilayer_api_id' => 'apilayer_api_id',
            'fixer_io_api_id' => 'fixer_io_api_id',
            'currencyconverterapi_id' => 'currencyconverterapi_id',
            'currencyconverterapi_pro' => 'currencyconverterapi_pro',
            'api_selection' => 'api_selection',
            'precision' => 'precision',
            'handling_percentage' => 'handling_percentage',
            'handling_amount' => 'handling_amount',
            'handling_taxable' => 'handling_taxable',
            'shipping_handling_fee' => 'shipping_handling_fee',
            'handling_title' => 'handling_title',
            'ppcc_use_custom_currency' => 'ppcc_use_custom_currency',
            'ppcc_custom_currency_code' => 'ppcc_custom_currency_code',
            'ppcc_custom_currency_symbol' => 'ppcc_custom_currency_symbol',
            'ppcc_custom_currency_name' => 'ppcc_custom_currency_name',
            'autocomplete' => 'autocomplete',
            'autoprocessing' => 'autoprocessing',
            'email_order_completed_note' => 'email_order_completed_note',
            'order_email_note' => 'order_email_note',
            'suppress_order_on_hold_email' => 'suppress_order_on_hold_email',
        );
        
        foreach ( $mapping as $old_key => $new_key ) {
            if ( isset( $legacy_settings[ $old_key ] ) ) {
                $new_settings[ $new_key ] = $legacy_settings[ $old_key ];
            }
        }
        
        // Ensure numeric values are properly stored as numeric types
        if (isset($new_settings['conversion_rate']) && !is_float($new_settings['conversion_rate'])) {
            $new_settings['conversion_rate'] = (float)$new_settings['conversion_rate'];
        }
        if (isset($new_settings['handling_percentage']) && !is_float($new_settings['handling_percentage'])) {
            $new_settings['handling_percentage'] = (float)$new_settings['handling_percentage'];
        }
        if (isset($new_settings['handling_amount']) && !is_float($new_settings['handling_amount'])) {
            $new_settings['handling_amount'] = (float)$new_settings['handling_amount'];
        }
        if (isset($new_settings['precision']) && !is_int($new_settings['precision'])) {
            $new_settings['precision'] = (int)$new_settings['precision'];
        }
        
        // Cap precision at 2 for regular currencies, 0 for non-decimal currencies
        $non_decimal_currencies = array('HUF', 'JPY', 'TWD');
        if (isset($new_settings['target_currency']) && in_array($new_settings['target_currency'], $non_decimal_currencies)) {
            $new_settings['precision'] = 0;
        } else if (isset($new_settings['precision']) && $new_settings['precision'] > 2) {
            $new_settings['precision'] = 2;
        }
        
        return $new_settings;
    }
}