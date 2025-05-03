<?php
/**
 * Plugin Name: Simple PayPal Currency Converter PRO for WooCommerce (Debug Mode)
 * Plugin URI: https://codecanyon.net/item/paypal-currency-converter-pro-for-woocommerce/6343249
 * Description: Debug version of PayPal Currency Converter PRO
 * Version: 4.0.0-debug
 * Author: Intelligent-IT
 * Author URI: https://codecanyon.net/user/intelligent-it
 */
 
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// Define plugin constants
define( 'PPCC_VERSION', '4.0.0' );
define( 'PPCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include only the functions file for minimal functionality
require_once( PPCC_PLUGIN_DIR . 'includes/functions.php' );

// Multisite handling
if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
    require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
}
if ( is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
    define( 'PPCC_NETWORK_ACTIVATED', true );
} else {
    define( 'PPCC_NETWORK_ACTIVATED', false );
}

// Initialization
function ppcc_debug_init() {
    // Add admin notice to show debug mode is active
    add_action( 'admin_notices', 'ppcc_debug_mode_notice' );
}
add_action( 'plugins_loaded', 'ppcc_debug_init', 20 );

function ppcc_debug_mode_notice() {
    echo '<div class="notice notice-info">
        <p>PayPal Currency Converter PRO is running in debug mode. Only basic functionality is available.</p>
    </div>';
}