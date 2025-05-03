<?php
/**
 * Emergency Debug Fix
 * 
 * This is a very simple script that directly modifies the ppcc_settings option
 * to enable debug mode without any UI or complex code.
 */

// Basic WordPress integration
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

// Only allow admin access
if (!current_user_can('manage_options')) {
    die('You need to be an administrator to use this tool.');
}

// Get and modify the settings
$settings = get_option('ppcc_settings');

// If settings exist, enable debug mode
if (is_array($settings)) {
    // Backup current settings
    update_option('ppcc_settings_backup_emergency', $settings);
    
    // Enable debug mode
    $settings['debug'] = 'on';
    
    // Save settings
    $result = update_option('ppcc_settings', $settings);
    
    if ($result) {
        echo "SUCCESS: Debug mode has been enabled.";
    } else {
        echo "ERROR: Failed to save settings.";
    }
} else {
    echo "ERROR: Settings not found or invalid.";
}

// Show redirect link
echo "<p><a href='" . admin_url('admin.php?page=ppcc_settings') . "'>Go back to settings</a></p>";
