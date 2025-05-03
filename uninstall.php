<?php
/**
 * Uninstall PayPal Currency Converter PRO
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Define plugin constants if not already defined
if ( ! defined( 'PPCC_NETWORK_ACTIVATED' ) ) {
    // Check if plugin was network activated
    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
        require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
    }
    
    define( 'PPCC_NETWORK_ACTIVATED', is_plugin_active_for_network( 'paypalcurrencyconverterpro-revamp/paypalcc.php' ) );
}

// Include helper functions if they're not already included
if ( ! function_exists( 'get_ppcc_option' ) ) {
    require_once( plugin_dir_path( __FILE__ ) . 'includes/functions.php' );
}

// Check if we should keep settings (controlled by a setting in the plugin)
$keep_settings = false;
$settings = get_ppcc_option( 'ppcc_settings' );

if ( $settings && isset( $settings['keep_data_on_uninstall'] ) ) {
    $keep_settings = ( $settings['keep_data_on_uninstall'] === 'on' );
}

if ( ! $keep_settings ) {
    // Delete options
    delete_ppcc_option( 'ppcc_settings' );
    delete_ppcc_option( 'ppcc-options' ); // Legacy option
    
    // Clear scheduled events
    wp_clear_scheduled_hook( 'ppcc_cexr_update' );
    
    // If this is a multisite and the plugin is network activated
    if ( is_multisite() && PPCC_NETWORK_ACTIVATED ) {
        global $wpdb;
        
        // Get all blogs in the network
        $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
        
        foreach ( $blog_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            
            // Delete options for this blog
            delete_option( 'ppcc_settings' );
            delete_option( 'ppcc-options' ); // Legacy option
            
            // Clear scheduled events for this blog
            wp_clear_scheduled_hook( 'ppcc_cexr_update' );
            
            restore_current_blog();
        }
    }
}
