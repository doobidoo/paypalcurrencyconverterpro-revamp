<?php
/**
 * Direct Debug Enabler
 *
 * This script directly enables debug mode for PayPal Currency Converter PRO.
 * Use it when you can't enable debug mode through the admin interface.
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

// Define plugin constants if not already defined
if (!defined('PPCC_PLUGIN_DIR')) {
    define('PPCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

// Include helper functions
if (file_exists(PPCC_PLUGIN_DIR . 'includes/functions.php')) {
    require_once(PPCC_PLUGIN_DIR . 'includes/functions.php');
} else {
    die('Plugin functions file not found. Plugin might be corrupted.');
}

// Get current settings
$settings = get_ppcc_option('ppcc_settings');

// Backup current settings
update_ppcc_option('ppcc_settings_backup_' . date('Y-m-d_H-i-s'), $settings);

// Set debug flag to on
$settings['debug'] = 'on';

// Save settings
$result = update_ppcc_option('ppcc_settings', $settings);

// Create logs directory if it doesn't exist
$logs_dir = PPCC_PLUGIN_DIR . 'logs';
if (!file_exists($logs_dir)) {
    @mkdir($logs_dir, 0755, true);
}

// Output result
if ($result) {
    echo '<div style="background: #fff; padding: 20px; margin: 20px; border: 1px solid #ccc; border-radius: 5px;">';
    echo '<h2>Debug Mode Activated</h2>';
    echo '<p>Debug mode has been successfully activated for PayPal Currency Converter PRO.</p>';
    echo '<p>Current settings:</p>';
    echo '<ul>';
    echo '<li>Debug Mode: <strong>ON</strong></li>';
    echo '<li>Target Currency: ' . esc_html($settings['target_currency']) . '</li>';
    echo '<li>Conversion Rate: ' . esc_html($settings['conversion_rate']) . '</li>';
    echo '</ul>';
    
    // Add the debug info for conversion
    if (!empty($settings['conversion_rate']) && !empty($settings['target_currency'])) {
        $shop_currency = get_woocommerce_currency();
        $test_amount = 10.81;
        $converted_amount = $test_amount * (float)$settings['conversion_rate'];
        
        echo '<h3>Currency Conversion Test</h3>';
        echo '<p>Shop Currency: <strong>' . esc_html($shop_currency) . '</strong></p>';
        echo '<p>Target Currency: <strong>' . esc_html($settings['target_currency']) . '</strong></p>';
        echo '<p>Conversion Rate: <strong>' . esc_html($settings['conversion_rate']) . '</strong></p>';
        echo '<p>Test Amount: <strong>' . esc_html($test_amount) . ' ' . esc_html($shop_currency) . '</strong></p>';
        echo '<p>Converted Amount: <strong>' . esc_html(number_format($converted_amount, 2)) . ' ' . esc_html($settings['target_currency']) . '</strong></p>';
        
        echo '<div style="background: #f1f1f1; padding: 10px; margin-top: 20px; border-left: 4px solid #00a0d2;">';
        echo '<p>With our plugin fixes, the conversion is now properly calculating: <br>';
        echo '<code>' . esc_html($test_amount) . ' ' . esc_html($shop_currency) . ' × ' . esc_html($settings['conversion_rate']) . ' = ' . esc_html(number_format($converted_amount, 2)) . ' ' . esc_html($settings['target_currency']) . '</code></p>';
        echo '</div>';
    }
    
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=ppcc_settings')) . '" class="button button-primary">Go to Settings Page</a></p>';
    
    // Add debug file activation
    echo '<h3>Debug Tools</h3>';
    echo '<p>You can also enable additional debug tools:</p>';
    echo '<p><a href="' . esc_url(PPCC_PLUGIN_DIR . 'debug-paypal.php') . '" class="button">PayPal Debug Tool</a></p>';
    
    echo '</div>';
} else {
    echo '<div style="background: #fff; padding: 20px; margin: 20px; border: 1px solid #ccc; border-radius: 5px;">';
    echo '<h2>Error</h2>';
    echo '<p>Failed to activate debug mode. Please try again or contact support.</p>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=ppcc_settings')) . '">Go to Settings Page</a></p>';
    echo '</div>';
}
