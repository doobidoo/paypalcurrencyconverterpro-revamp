<?php
/**
 * PayPal Currency Converter PRO Debug Page
 *
 * This page provides detailed debugging information and tools for the PayPal Currency Converter PRO plugin.
 */

// Verify WordPress environment
if (!defined('ABSPATH')) {
    if (file_exists('../../../wp-load.php')) {
        require_once('../../../wp-load.php');
    } elseif (file_exists('../../../../wp-load.php')) {
        require_once('../../../../wp-load.php');
    } else {
        die('WordPress not found. Cannot load debug tool.');
    }
}

// Security check - only admin users can access this page
if (!current_user_can('manage_options')) {
    wp_die('You do not have sufficient permissions to access this page.');
}

// Set the plugin directory
if (!defined('PPCC_PLUGIN_DIR')) {
    define('PPCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

// Include required files
require_once(PPCC_PLUGIN_DIR . 'includes/functions.php');
require_once(PPCC_PLUGIN_DIR . 'includes/ppcc-paypal-request-logger.php');

// Initialize the logger
$logger = PPCC_PayPal_Request_Logger::instance();

// Process actions
$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

// Handle log clearing action
if ($action === 'clear_logs' && check_admin_referer('ppcc_clear_logs')) {
    $log_dir = PPCC_PLUGIN_DIR . 'logs';
    if (is_dir($log_dir)) {
        $files = scandir($log_dir);
        foreach ($files as $file) {
            if (in_array($file, array('.', '..'))) {
                continue;
            }
            if (is_file($log_dir . '/' . $file)) {
                @unlink($log_dir . '/' . $file);
            }
        }
        
        $logger->log('Logs cleared by admin', 'DEBUG_TOOL');
        $message = 'All log files have been cleared.';
    }
}

// Handle test log action
if ($action === 'test_log' && check_admin_referer('ppcc_test_log')) {
    $logger->log([
        'test' => 'This is a test log entry',
        'time' => current_time('mysql'),
        'user' => wp_get_current_user()->user_login,
    ], 'DEBUG_TOOL_TEST');
    
    $message = 'Test log entry created successfully.';
}

// Function to format JSON in a readable way
function ppcc_debug_format_json($json) {
    if (empty($json)) return 'Empty log file';
    
    $result = '';
    $level = 0;
    $in_quotes = false;
    $in_escape = false;
    $ends_line_level = NULL;
    $json_length = strlen($json);

    for ($i = 0; $i < $json_length; $i++) {
        $char = $json[$i];
        $new_line_level = NULL;
        $post = "";
        
        if ($ends_line_level !== NULL) {
            $new_line_level = $ends_line_level;
            $ends_line_level = NULL;
        }
        
        if ($in_escape) {
            $in_escape = false;
        } else if ($char === '"') {
            $in_quotes = !$in_quotes;
        } else if (!$in_quotes) {
            switch ($char) {
                case '}': case ']':
                    $level--;
                    $ends_line_level = NULL;
                    $new_line_level = $level;
                    break;
                
                case '{': case '[':
                    $level++;
                    // fall through
                case ',':
                    $ends_line_level = $level;
                    break;
                
                case ':':
                    $post = " ";
                    break;
                
                case " ": case "\	": case "\
": case "\\r":
                    $char = "";
                    $ends_line_level = $new_line_level;
                    $new_line_level = NULL;
                    break;
            }
        } else if ($char === '\\\\') {
            $in_escape = true;
        }
        
        if ($new_line_level !== NULL) {
            $result .= "\
" . str_repeat("  ", $new_line_level);
        }
        
        $result .= $char . $post;
    }

    return $result;
}

// Get log files
$log_dir = PPCC_PLUGIN_DIR . 'logs';
$log_files = array();
if (is_dir($log_dir)) {
    $files = scandir($log_dir);
    foreach ($files as $file) {
        if (in_array($file, array('.', '..'))) {
            continue;
        }
        if (is_file($log_dir . '/' . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'log') {
            $log_files[] = $file;
        }
    }
}

// Get the selected log file
$selected_log = isset($_GET['log']) ? sanitize_text_field($_GET['log']) : '';
if (!empty($selected_log) && !in_array($selected_log, $log_files)) {
    $selected_log = '';
}

// Get log content
$log_content = '';
if (!empty($selected_log)) {
    $log_file = $log_dir . '/' . $selected_log;
    if (file_exists($log_file)) {
        $log_content = file_get_contents($log_file);
    }
}

// Get settings for display
$settings = get_ppcc_option('ppcc_settings');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PayPal Currency Converter PRO Debug</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: #f0f0f1;
            color: #3c434a;
            margin: 0;
            padding: 0;
        }
        
        .wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        h1 {
            color: #1d2327;
            font-size: 23px;
            font-weight: 400;
            margin: 0;
            padding: 9px 0 4px;
            line-height: 1.3;
        }
        
        .notice {
            background: #fff;
            border-left: 4px solid #72aee6;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
            margin: 5px 0 15px;
            padding: 1px 12px;
        }
        
        .notice-success {
            border-left-color: #00a32a;
        }
        
        .notice-error {
            border-left-color: #d63638;
        }
        
        .card {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
            margin-top: 20px;
            padding: 20px;
        }
        
        .card h2 {
            margin-top: 0;
            font-size: 14px;
            padding: 8px 12px;
            margin: 0;
            line-height: 1.4;
        }
        
        table {
            border-collapse: collapse;
            width: 100%;
        }
        
        th, td {
            text-align: left;
            padding: 8px;
            border: 1px solid #ddd;
        }
        
        th {
            background-color: #f1f1f1;
        }
        
        pre {
            background: #f6f7f7;
            padding: 15px;
            border: 1px solid #ddd;
            overflow: auto;
            max-height: 500px;
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .button {
            display: inline-block;
            text-decoration: none;
            font-size: 13px;
            line-height: 2.15384615;
            min-height: 30px;
            margin: 0;
            padding: 0 10px;
            cursor: pointer;
            border-width: 1px;
            border-style: solid;
            -webkit-appearance: none;
            border-radius: 3px;
            white-space: nowrap;
            box-sizing: border-box;
            color: #2271b1;
            border-color: #2271b1;
            background: #f6f7f7;
            vertical-align: top;
        }
        
        .button:hover {
            background: #f0f0f1;
            border-color: #0a4b78;
            color: #0a4b78;
        }
        
        .button-primary {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
        }
        
        .button-primary:hover {
            background: #135e96;
            border-color: #135e96;
            color: #fff;
        }
        
        .nav-tab-wrapper {
            border-bottom: 1px solid #c3c4c7;
            margin: 0;
            padding-top: 9px;
            padding-bottom: 0;
            line-height: inherit;
        }
        
        .nav-tab {
            float: left;
            border: 1px solid #c3c4c7;
            border-bottom: none;
            margin-left: .5em;
            padding: 5px 10px;
            font-size: 14px;
            line-height: 1.71428571;
            font-weight: 600;
            background: #dcdcde;
            color: #50575e;
            text-decoration: none;
        }
        
        .nav-tab-active {
            background: #fff;
            color: #000;
            border-bottom: 1px solid #fff;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>PayPal Currency Converter PRO Debug</h1>
        
        <?php if (isset($message)): ?>
        <div class="notice notice-success">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php endif; ?>
        
        <div class="nav-tab-wrapper">
            <a href="#overview" class="nav-tab nav-tab-active">Overview</a>
            <a href="#settings" class="nav-tab">Settings</a>
            <a href="#logs" class="nav-tab">Log Files</a>
            <a href="#tools" class="nav-tab">Tools</a>
        </div>
        
        <div id="overview" class="tab-content active">
            <div class="card">
                <h2>PayPal Currency Converter PRO Debug</h2>
                <p>This page provides debugging information and tools for the PayPal Currency Converter PRO plugin.</p>
                
                <h3>System Information</h3>
                <table>
                    <tr>
                        <th>WordPress Version</th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th>WooCommerce Version</th>
                        <td><?php echo defined('WC_VERSION') ? esc_html(WC_VERSION) : 'Not installed'; ?></td>
                    </tr>
                    <tr>
                        <th>PayPal Currency Converter PRO Version</th>
                        <td><?php echo defined('PPCC_VERSION') ? esc_html(PPCC_VERSION) : 'Unknown'; ?></td>
                    </tr>
                    <tr>
                        <th>PHP Version</th>
                        <td><?php echo esc_html(phpversion()); ?></td>
                    </tr>
                    <tr>
                        <th>cURL Installed</th>
                        <td><?php echo function_exists('curl_init') ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <th>JSON Extension</th>
                        <td><?php echo function_exists('json_encode') ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <th>Shop Currency</th>
                        <td><?php echo esc_html(get_woocommerce_currency()); ?></td>
                    </tr>
                    <tr>
                        <th>Target Currency</th>
                        <td><?php echo isset($settings['target_currency']) ? esc_html($settings['target_currency']) : 'Not set'; ?></td>
                    </tr>
                    <tr>
                        <th>Conversion Rate</th>
                        <td><?php echo isset($settings['conversion_rate']) ? esc_html($settings['conversion_rate']) : 'Not set'; ?></td>
                    </tr>
                </table>
                
                <h3>PayPal Supported Currencies</h3>
                <p>The following currencies are supported by PayPal:</p>
                <?php 
                $paypal_currencies = array(
                    'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 
                    'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 
                    'CHF', 'TWD', 'THB', 'GBP', 'CNY'
                );
                
                $shop_currency = get_woocommerce_currency();
                $target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : '';
                
                echo '<ul style="columns: 5;">';
                foreach ($paypal_currencies as $currency) {
                    echo '<li>';
                    echo esc_html($currency);
                    if ($currency === $shop_currency) {
                        echo ' <strong>(Shop Currency)</strong>';
                    }
                    if ($currency === $target_currency) {
                        echo ' <strong>(Target Currency)</strong>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
                ?>
                
                <h3>Currency Status</h3>
                <table>
                    <tr>
                        <th>Shop Currency (<?php echo esc_html($shop_currency); ?>)</th>
                        <td><?php echo in_array($shop_currency, $paypal_currencies) ? 'Supported by PayPal' : 'Not supported by PayPal'; ?></td>
                    </tr>
                    <tr>
                        <th>Target Currency (<?php echo esc_html($target_currency); ?>)</th>
                        <td><?php echo in_array($target_currency, $paypal_currencies) ? 'Supported by PayPal' : '<strong style="color: red;">NOT SUPPORTED BY PAYPAL</strong>'; ?></td>
                    </tr>
                    <tr>
                        <th>Non-Decimal Currency?</th>
                        <td><?php echo in_array($target_currency, array('HUF', 'JPY', 'TWD')) ? '<strong>Yes - must have 0 decimals</strong>' : 'No - standard decimal format'; ?></td>
                    </tr>
                    <tr>
                        <th>Currency Format Check</th>
                        <td>
                        <?php
                        $test_amount = 10.99;
                        $conversion_rate = isset($settings['conversion_rate']) ? floatval($settings['conversion_rate']) : 1.0;
                        $converted_amount = $test_amount * $conversion_rate;
                        $non_decimal = in_array($target_currency, array('HUF', 'JPY', 'TWD'));
                        
                        if ($non_decimal && $converted_amount != intval($converted_amount)) {
                            echo '<span style="color: red;">Warning: Non-decimal currency requires integer values. ';
                            echo 'Current format: ' . $converted_amount . ', ';
                            echo 'Should be: ' . intval($converted_amount) . '</span>';
                        } else {
                            echo 'Format seems correct';
                        }
                        ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div id="settings" class="tab-content">
            <div class="card">
                <h2>Plugin Settings</h2>
                
                <p>These are the current settings for the PayPal Currency Converter PRO plugin:</p>
                
                <table>
                    <?php foreach ($settings as $key => $value): ?>
                    <tr>
                        <th><?php echo esc_html($key); ?></th>
                        <td><?php echo is_array($value) ? 'Array' : esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                
                <h3>Conversion Example</h3>
                <?php
                $shop_currency = get_woocommerce_currency();
                $target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : 'USD';
                $conversion_rate = isset($settings['conversion_rate']) ? floatval($settings['conversion_rate']) : 1.0;
                $test_amount = 10.99;
                $converted_amount = $test_amount * $conversion_rate;
                $decimals = in_array($target_currency, array('HUF', 'JPY', 'TWD')) ? 0 : 2;
                
                echo '<p><strong>Test: </strong>';
                echo $test_amount . ' ' . $shop_currency . ' x ' . $conversion_rate . ' = ';
                echo number_format($converted_amount, $decimals) . ' ' . $target_currency;
                echo '</p>';
                
                echo '<p><strong>Reverse: </strong>';
                echo '1 ' . $shop_currency . ' = ' . $conversion_rate . ' ' . $target_currency;
                echo '</p>';
                
                if (in_array($target_currency, array('HUF', 'JPY', 'TWD'))) {
                    echo '<p><strong style="color: red;">Note:</strong> ';
                    echo 'The target currency <strong>' . $target_currency . '</strong> is a non-decimal currency. ';
                    echo 'PayPal requires amounts to be integers with no decimal places for this currency.</p>';
                }
                ?>
            </div>
        </div>
        
        <div id="logs" class="tab-content">
            <div class="card">
                <h2>Log Files</h2>
                
                <div style="margin-bottom: 15px;">
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'clear_logs'), 'ppcc_clear_logs')); ?>" class="button" onclick="return confirm('Are you sure you want to clear all log files?');">Clear All Logs</a>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'test_log'), 'ppcc_test_log')); ?>" class="button">Add Test Log Entry</a>
                </div>
                
                <?php if (empty($log_files)): ?>
                <p>No log files found.</p>
                <?php else: ?>
                <div style="display: flex; gap: 20px;">
                    <div style="width: 200px;">
                        <h3>Available Logs</h3>
                        <ul>
                            <?php foreach ($log_files as $file): ?>
                            <li>
                                <a href="<?php echo esc_url(add_query_arg('log', $file)); ?>" <?php echo ($selected_log === $file) ? 'style="font-weight: bold;"' : ''; ?>>
                                    <?php echo esc_html($file); ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <div style="flex-grow: 1;">
                        <h3>Log Content: <?php echo esc_html($selected_log); ?></h3>
                        <?php if (empty($selected_log)): ?>
                        <p>Select a log file to view its contents.</p>
                        <?php else: ?>
                        <pre><?php echo esc_html(ppcc_debug_format_json($log_content)); ?></pre>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="tools" class="tab-content">
            <div class="card">
                <h2>Debugging Tools</h2>
                
                <h3>Convert Currency</h3>
                <p>Test currency conversion with specific values:</p>
                
                <form method="get">
                    <input type="hidden" name="page" value="ppcc-debug">
                    <input type="hidden" name="tab" value="tools">
                    
                    <table>
                        <tr>
                            <th>Amount</th>
                            <td>
                                <input type="number" name="amount" value="<?php echo isset($_GET['amount']) ? esc_attr($_GET['amount']) : '10.99'; ?>" step="0.01">
                            </td>
                        </tr>
                        <tr>
                            <th>From Currency</th>
                            <td>
                                <select name="from_currency">
                                    <?php 
                                    $currencies = get_woocommerce_currencies();
                                    $selected_from = isset($_GET['from_currency']) ? $_GET['from_currency'] : $shop_currency;
                                    
                                    foreach ($currencies as $code => $name) {
                                        echo '<option value="' . esc_attr($code) . '"' . selected($code, $selected_from, false) . '>' . 
                                            esc_html($name) . ' (' . esc_html($code) . ')' . 
                                            '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>To Currency</th>
                            <td>
                                <select name="to_currency">
                                    <?php 
                                    $selected_to = isset($_GET['to_currency']) ? $_GET['to_currency'] : $target_currency;
                                    
                                    foreach ($currencies as $code => $name) {
                                        $supported = in_array($code, $paypal_currencies) ? ' (PayPal Supported)' : '';
                                        echo '<option value="' . esc_attr($code) . '"' . selected($code, $selected_to, false) . '>' . 
                                            esc_html($name) . ' (' . esc_html($code) . ')' . $supported . 
                                            '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Conversion Rate</th>
                            <td>
                                <input type="number" name="rate" value="<?php echo isset($_GET['rate']) ? esc_attr($_GET['rate']) : (isset($settings['conversion_rate']) ? esc_attr($settings['conversion_rate']) : '1.0'); ?>" step="0.000001">
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <button type="submit" class="button button-primary">Convert</button>
                            </td>
                        </tr>
                    </table>
                </form>
                
                <?php
                // Process conversion test
                if (isset($_GET['amount']) && isset($_GET['from_currency']) && isset($_GET['to_currency']) && isset($_GET['rate'])) {
                    $amount = floatval($_GET['amount']);
                    $from_currency = sanitize_text_field($_GET['from_currency']);
                    $to_currency = sanitize_text_field($_GET['to_currency']);
                    $rate = floatval($_GET['rate']);
                    
                    $converted = $amount * $rate;
                    $decimals = in_array($to_currency, array('HUF', 'JPY', 'TWD')) ? 0 : 2;
                    
                    echo '<h3>Conversion Result</h3>';
                    echo '<p><strong>Formula: </strong>' . esc_html($amount) . ' ' . esc_html($from_currency) . ' x ' . esc_html($rate) . ' = ' . number_format($converted, $decimals) . ' ' . esc_html($to_currency) . '</p>';
                    
                    if (in_array($to_currency, array('HUF', 'JPY', 'TWD'))) {
                        echo '<p><strong>Note: </strong>' . esc_html($to_currency) . ' is a non-decimal currency. PayPal requires integer values: ' . intval($converted) . '</p>';
                        
                        if ($converted != intval($converted)) {
                            echo '<p><strong style="color: red;">Warning: </strong>Amount has decimals but needs to be an integer for this currency.</p>';
                        }
                    }
                    
                    // Log the test
                    $logger->log([
                        'amount' => $amount,
                        'from_currency' => $from_currency,
                        'to_currency' => $to_currency,
                        'rate' => $rate,
                        'converted' => $converted,
                        'formatted' => number_format($converted, $decimals),
                    ], 'CURRENCY_CONVERSION_TEST');
                }
                ?>
                
                <h3>Test PayPal Order Request (Simulation)</h3>
                <p>This tool simulates a PayPal order request with the given parameters:</p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('ppcc_test_paypal'); ?>
                    <input type="hidden" name="action" value="test_paypal">
                    
                    <table>
                        <tr>
                            <th>Order Total</th>
                            <td>
                                <input type="number" name="order_total" value="10.99" step="0.01">
                            </td>
                        </tr>
                        <tr>
                            <th>Currency</th>
                            <td>
                                <select name="currency">
                                    <?php 
                                    foreach ($paypal_currencies as $code) {
                                        echo '<option value="' . esc_attr($code) . '"' . selected($code, $target_currency, false) . '>' . 
                                            esc_html($code) . 
                                            '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <button type="submit" class="button button-primary">Simulate PayPal Request</button>
                            </td>
                        </tr>
                    </table>
                </form>
                
                <?php
                // Process PayPal test
                if (isset($_POST['action']) && $_POST['action'] === 'test_paypal' && check_admin_referer('ppcc_test_paypal')) {
                    $order_total = floatval($_POST['order_total']);
                    $currency = sanitize_text_field($_POST['currency']);
                    
                    // Format based on currency
                    $decimals = in_array($currency, array('HUF', 'JPY', 'TWD')) ? 0 : 2;
                    $formatted_total = number_format($order_total, $decimals, '.', '');
                    
                    // If non-decimal currency, ensure it's an integer
                    if (in_array($currency, array('HUF', 'JPY', 'TWD'))) {
                        $formatted_total = intval($order_total);
                    }
                    
                    // Simulate PayPal request
                    $paypal_request = array(
                        'intent' => 'CAPTURE',
                        'purchase_units' => array(
                            array(
                                'amount' => array(
                                    'currency_code' => $currency,
                                    'value' => $formatted_total,
                                ),
                            ),
                        ),
                    );
                    
                    echo '<h3>Simulated PayPal Request</h3>';
                    echo '<pre>' . esc_html(json_encode($paypal_request, JSON_PRETTY_PRINT)) . '</pre>';
                    
                    // Log the test
                    $logger->log($paypal_request, 'PAYPAL_REQUEST_SIMULATION');
                    
                    // Check for potential issues
                    $issues = array();
                    
                    if (!in_array($currency, $paypal_currencies)) {
                        $issues[] = 'Currency code "' . $currency . '" is not supported by PayPal.';
                    }
                    
                    if (in_array($currency, array('HUF', 'JPY', 'TWD')) && $order_total != intval($order_total)) {
                        $issues[] = 'Non-decimal currency "' . $currency . '" should not have decimal places.';
                    }
                    
                    if (!empty($issues)) {
                        echo '<h3>Potential Issues</h3>';
                        echo '<ul style="color: red;">';
                        foreach ($issues as $issue) {
                            echo '<li>' . esc_html($issue) . '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<p style="color: green;">No issues detected with this PayPal request.</p>';
                    }
                }
                ?>
            </div>
        </div>
    </div>
    
    <script>
        // Tab navigation
        document.addEventListener('DOMContentLoaded', function() {
            var tabLinks = document.querySelectorAll('.nav-tab');
            var tabContents = document.querySelectorAll('.tab-content');
            
            function showTab(tabId) {
                // Hide all tabs
                tabContents.forEach(function(tab) {
                    tab.classList.remove('active');
                });
                
                tabLinks.forEach(function(link) {
                    link.classList.remove('nav-tab-active');
                });
                
                // Show the selected tab
                document.getElementById(tabId).classList.add('active');
                document.querySelector('a[href="#' + tabId + '"]').classList.add('nav-tab-active');
            }
            
            // Get tab from URL hash or default to overview
            var currentTab = window.location.hash.substr(1) || 'overview';
            showTab(currentTab);
            
            // Handle tab clicks
            tabLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var tabId = this.getAttribute('href').substr(1);
                    showTab(tabId);
                    window.location.hash = tabId;
                });
            });
            
            // Handle URL parameter for tab
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('tab')) {
                showTab(urlParams.get('tab'));
            }
        });
    </script>
</body>
</html>