<?php
/**
 * Debug utility for AJAX issues in PayPal Currency Converter PRO
 */

// Disable direct access
if (!defined('ABSPATH')) {
    if (file_exists('../../../wp-load.php')) {
        require_once('../../../wp-load.php');
    } elseif (file_exists('../../../../wp-load.php')) {
        require_once('../../../../wp-load.php');
    } else {
        die('WordPress not found. Cannot load debug tool.');
    }
    
    // Verify admin user
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
} else {
    // If loaded through WordPress, check permissions
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define CSS for the page
$css = '
<style>
    body { font-family: Arial, sans-serif; line-height: 1.6; max-width: 1200px; margin: 0 auto; padding: 20px; }
    h1 { color: #0073aa; }
    h2 { color: #0073aa; margin-top: 30px; }
    .card { border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-bottom: 20px; background-color: #f9f9f9; }
    .card h3 { margin-top: 0; }
    .success { color: green; }
    .error { color: #dc3232; }
    pre { background-color: #f5f5f5; padding: 10px; overflow: auto; max-height: 400px; white-space: pre-wrap; }
    code { background-color: #f5f5f5; padding: 2px 5px; border-radius: 3px; font-family: monospace; }
    .test-button { background-color: #0073aa; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; }
    .test-button:hover { background-color: #005a87; }
    .log-entry { border-bottom: 1px solid #eee; padding: 8px 0; }
    .section { margin-bottom: 40px; }
</style>';

echo '<!DOCTYPE html>
<html>
<head>
    <title>PayPal Currency Converter PRO - AJAX Debug</title>
    ' . $css . '
</head>
<body>
    <h1>PayPal Currency Converter PRO - AJAX Debug</h1>
    <p>This tool helps diagnose issues with the update_order_review AJAX call that might be causing 500 errors during checkout.</p>';

// Plugin information
echo '<div class="section">
    <h2>Plugin Information</h2>
    <div class="card">';

echo '<h3>Plugin Status</h3>';
if (defined('PPCC_VERSION')) {
    echo '<p class="success">Plugin is loaded. Version: ' . PPCC_VERSION . '</p>';
} else {
    echo '<p class="error">Plugin is not properly loaded!</p>';
}

// Check for errors in PHP error log
echo '<h3>Recent PHP Errors</h3>';
$error_log_paths = array(
    '/Applications/XAMPP/xamppfiles/logs/php_error_log',
    '/Applications/XAMPP/xamppfiles/php/logs/php_error_log',
    ABSPATH . 'wp-content/debug.log',
    ini_get('error_log')
);

$found_errors = false;
foreach ($error_log_paths as $log_path) {
    if (file_exists($log_path) && is_readable($log_path)) {
        $log_size = filesize($log_path);
        echo '<p>Checking log: ' . $log_path . ' (' . round($log_size / 1024, 2) . ' KB)</p>';
        
        // Read the last portion of the log file
        $log_content = file_get_contents($log_path, false, null, max(0, $log_size - 50000), 50000);
        $lines = explode("\n", $log_content);
        $relevant_lines = array();
        
        foreach ($lines as $line) {
            if (stripos($line, 'paypal') !== false || 
                stripos($line, 'ppcc') !== false || 
                stripos($line, 'update_order_review') !== false ||
                stripos($line, '500') !== false ||
                stripos($line, 'fatal') !== false) {
                $relevant_lines[] = $line;
            }
        }
        
        if (!empty($relevant_lines)) {
            $found_errors = true;
            echo '<pre>';
            foreach (array_slice($relevant_lines, -15) as $line) {
                echo htmlspecialchars($line) . "\n";
            }
            echo '</pre>';
        }
    }
}

if (!$found_errors) {
    echo '<p>No relevant errors found in standard PHP error logs.</p>';
}

// Check plugin-specific debug log
$plugin_log = PPCC_PLUGIN_DIR . 'logs/checkout-debug.log';
if (file_exists($plugin_log)) {
    echo '<h3>Plugin Debug Log</h3>';
    if (is_readable($plugin_log)) {
        $log_content = file_get_contents($plugin_log);
        echo '<pre>' . htmlspecialchars($log_content) . '</pre>';
    } else {
        echo '<p class="error">Log file exists but is not readable.</p>';
    }
} else {
    echo '<h3>Plugin Debug Log</h3>';
    echo '<p>No plugin-specific debug log found. This will be created when errors occur.</p>';
}

echo '</div>'; // End card
echo '</div>'; // End section

// WooCommerce checkout test
echo '<div class="section">
    <h2>WooCommerce Checkout Test</h2>
    <div class="card">';

// Check if WooCommerce is active
if (!function_exists('WC')) {
    echo '<p class="error">WooCommerce is not active or properly loaded.</p>';
} else {
    echo '<p class="success">WooCommerce is active.</p>';
    
    // Test cart and session availability
    echo '<h3>Cart & Session Test</h3>';
    
    try {
        $cart = WC()->cart;
        $session = WC()->session;
        
        if ($cart && is_object($cart)) {
            echo '<p class="success">WC()->cart is available.</p>';
            echo '<p>Cart total: ' . wc_price($cart->get_cart_contents_total()) . '</p>';
        } else {
            echo '<p class="error">WC()->cart is not available!</p>';
        }
        
        if ($session && is_object($session)) {
            echo '<p class="success">WC()->session is available.</p>';
            
            // Check chosen payment method
            $chosen_payment_method = $session->get('chosen_payment_method');
            echo '<p>Current payment method: ' . ($chosen_payment_method ? $chosen_payment_method : 'None selected') . '</p>';
        } else {
            echo '<p class="error">WC()->session is not available!</p>';
        }
    } catch (Exception $e) {
        echo '<p class="error">Error testing WooCommerce objects: ' . $e->getMessage() . '</p>';
    }

    // Test update_order_review fragments filter
    echo '<h3>AJAX Hooks Test</h3>';
    
    global $wp_filter;
    if (isset($wp_filter['woocommerce_update_order_review_fragments'])) {
        echo '<p class="success">woocommerce_update_order_review_fragments filter is registered.</p>';
        
        // List all callbacks
        echo '<p>Callbacks registered:</p>';
        echo '<ul>';
        foreach ($wp_filter['woocommerce_update_order_review_fragments']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $cb) {
                $callback_name = '';
                if (is_array($cb['function'])) {
                    if (is_object($cb['function'][0])) {
                        $callback_name = get_class($cb['function'][0]) . '->' . $cb['function'][1];
                    } else {
                        $callback_name = $cb['function'][0] . '::' . $cb['function'][1];
                    }
                } elseif (is_string($cb['function'])) {
                    $callback_name = $cb['function'];
                } else {
                    $callback_name = 'Closure or unknown';
                }
                echo '<li>Priority ' . $priority . ': ' . $callback_name . '</li>';
            }
        }
        echo '</ul>';
    } else {
        echo '<p class="error">woocommerce_update_order_review_fragments filter not registered!</p>';
    }
}

echo '</div>'; // End card
echo '</div>'; // End section

// Plugin settings
echo '<div class="section">
    <h2>Plugin Settings</h2>
    <div class="card">';

// Display current plugin settings
$settings = get_ppcc_option('ppcc_settings');
if (is_array($settings) && !empty($settings)) {
    echo '<h3>Current Settings</h3>';
    echo '<pre>' . print_r($settings, true) . '</pre>';
} else {
    echo '<p class="error">No plugin settings found or settings are invalid.</p>';
}

echo '</div>'; // End card
echo '</div>'; // End section

// Solutions
echo '<div class="section">
    <h2>Potential Solutions</h2>
    <div class="card">';

echo '<h3>Debug Mode</h3>';
echo '<p>Enable debug mode in the plugin settings to get more detailed error logs.</p>';

echo '<h3>Common Issues & Fixes</h3>';
echo '<ol>
    <li><strong>Settings Corruption</strong>: Try resetting the plugin settings.</li>
    <li><strong>WooCommerce Session Issues</strong>: Clear your browser cookies and try again.</li>
    <li><strong>Plugin Conflict</strong>: Temporarily disable other WooCommerce extensions to identify conflicts.</li>
    <li><strong>Cart Calculation Error</strong>: Make sure the cart has items and totals are properly calculated.</li>
    <li><strong>PayPal Integration Issue</strong>: Verify your PayPal gateway is properly configured.</li>
</ol>';

echo '<h3>If Error Persists</h3>';
echo '<p>Check the error logs for the specific error message. The common causes for 500 errors during checkout are:</p>
<ul>
    <li>PHP memory limit exceeded</li>
    <li>Maximum execution time exceeded</li>
    <li>Undefined index/property errors</li>
    <li>Invalid argument type errors</li>
</ul>';

echo '</div>'; // End card
echo '</div>'; // End section

echo '</body>
</html>';