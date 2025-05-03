<?php
/**
 * Direct Settings Management
 *
 * Allows direct editing of key plugin settings without going through the admin interface
 */

// Exit if accessed directly without authentication
if (!defined('ABSPATH')) {
    if (file_exists('../../../wp-load.php')) {
        require_once('../../../wp-load.php');
    } elseif (file_exists('../../../../wp-load.php')) {
        require_once('../../../../wp-load.php');
    } else {
        die('WordPress not found. Cannot load settings manager.');
    }
    
    // Verify admin user
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
}

// Define PPCC_PLUGIN_DIR if not already defined
if (!defined('PPCC_PLUGIN_DIR')) {
    define('PPCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

// Get PPCC option helper function
function get_ppcc_settings_option($option_name) {
    if (defined('PPCC_NETWORK_ACTIVATED') && PPCC_NETWORK_ACTIVATED) {
        // Get network site option
        return get_site_option($option_name);
    } else {
        // Get blog option
        if (function_exists('get_blog_option')) {
            return get_blog_option(get_current_blog_id(), $option_name);
        } else {
            return get_option($option_name);
        }
    }
}

// Update PPCC option helper function
function update_ppcc_settings_option($option_name, $option_value) {
    if (defined('PPCC_NETWORK_ACTIVATED') && PPCC_NETWORK_ACTIVATED) {
        // Update network site option
        return update_site_option($option_name, $option_value);
    } else {
        // Update blog option
        return update_option($option_name, $option_value);
    }
}

// Process form submission
$message = '';
$error = '';

if (isset($_POST['ppcc_save_settings'])) {
    // Get current settings
    $settings = get_ppcc_settings_option('ppcc_settings');
    
    if (!is_array($settings)) {
        $settings = array();
    }
    
    // Update handling fee settings
    if (isset($_POST['handling_percentage'])) {
        $settings['handling_percentage'] = floatval($_POST['handling_percentage']);
    }
    
    if (isset($_POST['handling_amount'])) {
        $settings['handling_amount'] = floatval($_POST['handling_amount']);
    }
    
    if (isset($_POST['handling_taxable'])) {
        $settings['handling_taxable'] = $_POST['handling_taxable'];
    } else {
        $settings['handling_taxable'] = 'off';
    }
    
    if (isset($_POST['shipping_handling_fee'])) {
        $settings['shipping_handling_fee'] = $_POST['shipping_handling_fee'];
    } else {
        $settings['shipping_handling_fee'] = 'off';
    }
    
    // Update conversion rate
    if (isset($_POST['conversion_rate'])) {
        $settings['conversion_rate'] = floatval($_POST['conversion_rate']);
    }
    
    // Update target currency
    if (isset($_POST['target_currency'])) {
        $settings['target_currency'] = sanitize_text_field($_POST['target_currency']);
    }
    
    // Update settings
    $result = update_ppcc_settings_option('ppcc_settings', $settings);
    
    if ($result) {
        $message = 'Settings updated successfully.';
    } else {
        $error = 'Failed to update settings.';
    }
}

// Get current settings
$settings = get_ppcc_settings_option('ppcc_settings');

// PayPal supported currencies
$pp_currencies = array(
    'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 
    'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 
    'CHF', 'TWD', 'THB', 'GBP', 'CNY'
);

// Default values
$handling_percentage = isset($settings['handling_percentage']) ? $settings['handling_percentage'] : 0;
$handling_amount = isset($settings['handling_amount']) ? $settings['handling_amount'] : 0;
$handling_taxable = isset($settings['handling_taxable']) ? $settings['handling_taxable'] : 'off';
$shipping_handling_fee = isset($settings['shipping_handling_fee']) ? $settings['shipping_handling_fee'] : 'off';
$conversion_rate = isset($settings['conversion_rate']) ? $settings['conversion_rate'] : 1.0;
$target_currency = isset($settings['target_currency']) ? $settings['target_currency'] : 'USD';
$retrieval_count = isset($settings['retrieval_count']) ? $settings['retrieval_count'] : 0;

// Get current shop currency
$shop_currency = get_woocommerce_currency();

// HTML for the page
?>
<!DOCTYPE html>
<html>
<head>
    <title>PayPal Currency Converter PRO - Direct Settings</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            color: #0073aa;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .form-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .form-table th {
            text-align: left;
            padding: 15px 10px;
            width: 200px;
            vertical-align: top;
        }
        .form-table td {
            padding: 15px 10px;
        }
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            max-width: 400px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        input[type="checkbox"] {
            margin-right: 10px;
        }
        input[type="submit"] {
            background-color: #0073aa;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        input[type="submit"]:hover {
            background-color: #005a87;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .card {
            background-color: #f9f9f9;
            border: 1px solid #e5e5e5;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .card h2 {
            margin-top: 0;
            color: #23282d;
        }
        .back-link {
            margin-top: 20px;
            display: block;
        }
    </style>
</head>
<body>
    <h1>PayPal Currency Converter PRO - Direct Settings</h1>
    
    <?php if ($message) : ?>
        <div class="success"><?php echo esc_html($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error) : ?>
        <div class="error"><?php echo esc_html($error); ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h2>Current Settings</h2>
        <p>Shop Currency: <strong><?php echo esc_html($shop_currency); ?></strong></p>
        <p>Target Currency: <strong><?php echo esc_html($target_currency); ?></strong></p>
        <p>Conversion Rate: <strong><?php echo esc_html($conversion_rate); ?></strong> (1 <?php echo esc_html($shop_currency); ?> = <?php echo esc_html($conversion_rate); ?> <?php echo esc_html($target_currency); ?>)</p>
        <p>Handling Fee Percentage: <strong><?php echo esc_html($handling_percentage); ?>%</strong></p>
        <p>Handling Fee Amount: <strong><?php echo esc_html($handling_amount); ?></strong></p>
        <p>Handling Fee Taxable: <strong><?php echo $handling_taxable === 'on' ? 'Yes' : 'No'; ?></strong></p>
        <p>Include Shipping in Handling Fee Calculation: <strong><?php echo $shipping_handling_fee === 'on' ? 'Yes' : 'No'; ?></strong></p>
    </div>
    
    <form method="post" action="">
        <div class="card">
            <h2>Currency Settings</h2>
            
            <table class="form-table">
                <tr>
                    <th>Target Currency</th>
                    <td>
                        <select name="target_currency">
                            <?php foreach ($pp_currencies as $currency) : ?>
                                <option value="<?php echo esc_attr($currency); ?>" <?php selected($target_currency, $currency); ?>>
                                    <?php echo esc_html($currency); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Select the currency that PayPal will use for processing the payment.</p>
                    </td>
                </tr>
                <tr>
                    <th>Conversion Rate</th>
                    <td>
                        <input type="number" name="conversion_rate" value="<?php echo esc_attr($conversion_rate); ?>" step="0.00000001" min="0.00000001">
                        <p class="description">The conversion rate from shop currency (<?php echo esc_html($shop_currency); ?>) to target currency.
                        <br>Formula: 1 <?php echo esc_html($shop_currency); ?> = X <?php echo esc_html($target_currency); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2>Handling Fee Settings</h2>
            <p>To disable handling fees, set both percentage and amount to 0.</p>
            
            <table class="form-table">
                <tr>
                    <th>Handling Fee Percentage</th>
                    <td>
                        <input type="number" name="handling_percentage" value="<?php echo esc_attr($handling_percentage); ?>" step="0.01" min="0">
                        <p class="description">Percentage of the cart total to add as a handling fee.</p>
                    </td>
                </tr>
                <tr>
                    <th>Handling Fee Amount</th>
                    <td>
                        <input type="number" name="handling_amount" value="<?php echo esc_attr($handling_amount); ?>" step="0.01" min="0">
                        <p class="description">Fixed amount to add as a handling fee.</p>
                    </td>
                </tr>
                <tr>
                    <th>Handling Fee Taxable</th>
                    <td>
                        <input type="checkbox" name="handling_taxable" <?php checked($handling_taxable, 'on'); ?>>
                        Apply taxes to the handling fee
                    </td>
                </tr>
                <tr>
                    <th>Include Shipping in Calculation</th>
                    <td>
                        <input type="checkbox" name="shipping_handling_fee" <?php checked($shipping_handling_fee, 'on'); ?>>
                        Include shipping cost when calculating percentage-based handling fee
                    </td>
                </tr>
            </table>
        </div>
        
        <input type="submit" name="ppcc_save_settings" value="Save Settings">
    </form>
    
    <a href="<?php echo esc_url(admin_url('admin.php?page=ppcc_settings')); ?>" class="back-link">← Back to Plugin Settings</a>
</body>
</html>