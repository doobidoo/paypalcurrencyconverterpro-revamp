<?php
/**
 * Simple Debug Enabler
 *
 * This script directly enables debug mode for PayPal Currency Converter PRO.
 * It's a simplified version with minimal code to avoid syntax errors.
 */

// Exit if accessed directly without authentication
if (!defined('ABSPATH')) {
    if (file_exists('../../../wp-load.php')) {
        require_once('../../../wp-load.php');
    } elseif (file_exists('../../../../wp-load.php')) {
        require_once('../../../../wp-load.php');
    } else {
        die('WordPress not found. Cannot activate debug mode.');
    }
    
    // Verify admin user
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
}

// Get current settings
$settings = get_option('ppcc_settings');

// Set debug flag to on
if (is_array($settings)) {
    $settings['debug'] = 'on';
    
    // Save settings
    update_option('ppcc_settings', $settings);
    
    echo '<div style="background: #fff; padding: 20px; margin: 20px; border: 1px solid #ccc; border-radius: 5px;">';
    echo '<h2>Debug Mode Activated</h2>';
    echo '<p>Debug mode has been successfully activated for PayPal Currency Converter PRO.</p>';
    echo '<p><a href="' . admin_url('admin.php?page=ppcc_settings') . '">Return to Settings</a></p>';
    echo '</div>';
} else {
    echo '<div style="background: #fff; padding: 20px; margin: 20px; border: 1px solid #ccc; border-radius: 5px;">';
    echo '<h2>Error</h2>';
    echo '<p>Failed to activate debug mode. Settings structure is invalid.</p>';
    echo '<p><a href="' . admin_url('admin.php?page=ppcc_settings') . '">Return to Settings</a></p>';
    echo '</div>';
}
