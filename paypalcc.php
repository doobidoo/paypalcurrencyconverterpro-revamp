<?php
/**
 * Plugin Name: PayPal Currency Converter PRO for WooCommerce
 * Plugin URI: https://codecanyon.net/item/paypal-currency-converter-pro-for-woocommerce/6343249
 * Description: Convert any currency to allowed PayPal currencies for PayPal's Payment Gateway within WooCommerce
 * Version: 4.0.0
 * Author: Intelligent-IT
 * Author URI: https://codecanyon.net/user/intelligent-it
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * WC requires at least: 3.0
 * @author Henry Krupp <henry.krupp@gmail.com> 
 * @copyright 2024 Intelligent IT 
 * @license http://codecanyon.net/licenses/regular Codecanyon Regular
 * @version 4.0.0
 */
 
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Define plugin constants
define('PPCC_VERSION', '4.0.0');
define('PPCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PPCC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Function to check if WooCommerce is active
function ppcc_is_woocommerce_active() {
    $active_plugins = (array) get_option('active_plugins', array());
    
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    
    return in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
}

// Multisite handling
// See if site is network activated
if (!function_exists('is_plugin_active_for_network')) {
    // Makes sure the plugin is defined before trying to use it
    require_once(ABSPATH . '/wp-admin/includes/plugin.php');
}

if (is_plugin_active_for_network(plugin_basename(__FILE__))) {
    define("PPCC_NETWORK_ACTIVATED", true);
} else {
    define("PPCC_NETWORK_ACTIVATED", false);
}

// Include helper functions
require_once(PPCC_PLUGIN_DIR . 'includes/functions.php');

// Include PayPal request logger
require_once(PPCC_PLUGIN_DIR . 'includes/ppcc-paypal-request-logger.php');

// Include PayPal direct API hooks
require_once(PPCC_PLUGIN_DIR . 'includes/paypal-hooks.php');

// Load plugin text domain
function ppcc_load_textdomain() {
    load_plugin_textdomain('ppcc-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'ppcc_load_textdomain');

// Initialization
function ppcc_init() {
    // Check if WooCommerce is active
    if (!ppcc_is_woocommerce_active()) {
        add_action('admin_notices', 'ppcc_woocommerce_missing_notice');
        return;
    }
    
    // Initialize the PayPal API request logger
    ppcc_init_paypal_request_logger();
    
    // Include files only when WooCommerce is active
    require_once(PPCC_PLUGIN_DIR . 'includes/class-ppcc-core.php');
    require_once(PPCC_PLUGIN_DIR . 'includes/class-ppcc-order-converter.php');
    require_once(PPCC_PLUGIN_DIR . 'includes/class-ppcc-admin.php');
    require_once(PPCC_PLUGIN_DIR . 'includes/class-ppcc-exchange-rates.php');
    require_once(PPCC_PLUGIN_DIR . 'includes/class-ppcc-checkout.php');
    
    // Include the PayPal direct fix for decimal issues
    if (file_exists(PPCC_PLUGIN_DIR . 'includes/paypal-direct-fix.php')) {
        require_once(PPCC_PLUGIN_DIR . 'includes/paypal-direct-fix.php');
    }
    
    // Enable backward compatibility (PayPal Standard is hidden from WooCommerce 5.5)
    add_filter('woocommerce_should_load_paypal_standard', '__return_true');
    
    // Initialize the plugin
    if (class_exists('PPCC_Core')) {
        PPCC_Core::instance();
    } else {
        add_action('admin_notices', 'ppcc_class_not_found_notice');
    }
}
add_action('plugins_loaded', 'ppcc_init', 20);

// WooCommerce missing notice
function ppcc_woocommerce_missing_notice() {
    echo '<div class="error"><p>' . 
        __('PayPal Currency Converter PRO requires WooCommerce to be active.', 'ppcc-pro') . 
        '</p></div>';
}

// Class not found notice
function ppcc_class_not_found_notice() {
    echo '<div class="error"><p>' . 
        __('PayPal Currency Converter PRO: Core class not found. Plugin files may be corrupted or incomplete.', 'ppcc-pro') . 
        '</p></div>';
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'ppcc_deactivate');

// Plugin deactivation
function ppcc_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook('ppcc_cexr_update');
}

// Activation hook
register_activation_hook(__FILE__, 'ppcc_activate');

function ppcc_activate() {
    // Check if WooCommerce is active
    if (!ppcc_is_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('PayPal Currency Converter PRO requires WooCommerce to be active. Please activate WooCommerce first.', 'ppcc-pro'), 'Plugin Activation Error', array('back_link' => true));
        return;
    }
    
    // Check for old settings and migrate if needed
    ppcc_maybe_migrate_settings();
    
    // Create logs directory
    $logs_dir = PPCC_PLUGIN_DIR . 'logs';
    if (!file_exists($logs_dir)) {
        @mkdir($logs_dir, 0755, true);
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Upgrade process handling
function ppcc_maybe_migrate_settings() {
    // Check if PPCC_Core class exists
    if (!class_exists('PPCC_Core') && file_exists(PPCC_PLUGIN_DIR . 'includes/class-ppcc-core.php')) {
        require_once(PPCC_PLUGIN_DIR . 'includes/class-ppcc-core.php');
    }
    
    if (!class_exists('PPCC_Core')) {
        // Can't migrate without the core class
        return;
    }
    
    $old_options = get_ppcc_option('ppcc-options');
    if (!empty($old_options) && is_array($old_options)) {
        // Migrate old settings to new format
        $new_options = PPCC_Core::migrate_from_legacy($old_options);
        update_ppcc_option('ppcc_settings', $new_options);
        
        // Add upgrade notice
        set_transient('ppcc_upgraded_from_legacy', 1, 60 * 60 * 24 * 30); // 30 days
    } else {
        // New installation, set defaults
        if (method_exists('PPCC_Core', 'set_default_settings')) {
            PPCC_Core::set_default_settings();
        }
    }
}
