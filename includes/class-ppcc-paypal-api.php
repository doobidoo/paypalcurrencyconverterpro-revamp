<?php
/**
 * PayPal API Integration
 *
 * This class handles the PayPal API integration and ensures proper handling of handling fees.
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Class to handle PayPal API integration
 */
class PPCC_PayPal_API {
    /**
     * Plugin settings
     *
     * @var array
     */
    protected $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_ppcc_option('ppcc_settings');
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Modify data before PayPal request is sent
        add_filter('woocommerce_paypal_args', array($this, 'modify_paypal_args'), 999, 1);
        add_filter('woocommerce_paypal_payments_create_order_request_body_data', array($this, 'modify_paypal_request_body'), 999, 1);
        
        // Hook into PayPal Smart Button rendering to modify client-side handling
        // add_action('wp_footer', array($this, 'add_paypal_client_side_fix'), 100); // <<< MODIFIED: Commented out client-side override
    }
    
    /**
     * Modify PayPal arguments for WooCommerce PayPal integration (Standard/Older)
     *
     * @param array $args PayPal arguments
     * @return array Modified arguments
     */
    public function modify_paypal_args($args) {
        if (!is_array($this->settings) || empty($this->settings)) {
            return $args;
        }
        
        $shop_currency = get_woocommerce_currency();
        $target_currency = isset($this->settings['target_currency']) ? $this->settings['target_currency'] : 'USD';
        $conversion_rate = isset($this->settings['conversion_rate']) ? floatval($this->settings['conversion_rate']) : 1.0;
        
        // If no conversion is needed, return original args
        if ($shop_currency === $target_currency) {
            return $args;
        }
        
        // Log the original arguments for debugging
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'original_args' => $args,
                'shop_currency' => $shop_currency,
                'target_currency' => $target_currency,
                'conversion_rate' => $conversion_rate,
            ], 'PAYPAL_ARGS_BEFORE_MODIFICATION');
        }
        
        // Update the currency code
        $args['currency_code'] = $target_currency;
        
        // Get handling fee from session (ensure it's calculated server-side)
        $handling_fee = 0;
        if (function_exists('WC') && WC()->session) {
            $handling_fee = WC()->session->get('ppcc_handling_fee', 0); // Assumes this is set correctly elsewhere in original currency
        }
        
        // Convert handling fee
        $converted_handling_fee = round($handling_fee * $conversion_rate, 2);
        
        // Convert amount values
        // Note: This assumes a simple structure. Complex carts might need more careful handling.
        $total_converted_amount = 0;
        $item_indices = [];
        
        // Convert item amounts and track indices
        foreach ($args as $key => $value) {
            if (preg_match('/^item_name_(\d+)$/', $key, $matches)) {
                $item_indices[] = $matches[1];
            }
        }
        
        foreach ($item_indices as $index) {
            if (isset($args['amount_' . $index])) {
                $original_item_amount = floatval($args['amount_' . $index]);
                $converted_item_amount = round($original_item_amount * $conversion_rate, 2);
                $args['amount_' . $index] = number_format($converted_item_amount, 2, '.', '');
                $total_converted_amount += $converted_item_amount;
            }
        }
        
        // Convert other potential amounts (shipping, tax, discount)
        $converted_shipping = 0;
        if (isset($args['shipping'])) {
            $original_shipping = floatval($args['shipping']);
            $converted_shipping = round($original_shipping * $conversion_rate, 2);
            $args['shipping'] = number_format($converted_shipping, 2, '.', '');
            $total_converted_amount += $converted_shipping;
        }
        
        $converted_tax = 0;
        if (isset($args['tax'])) {
            $original_tax = floatval($args['tax']);
            $converted_tax = round($original_tax * $conversion_rate, 2);
            $args['tax'] = number_format($converted_tax, 2, '.', '');
            $total_converted_amount += $converted_tax;
        }
        
        // Note: Discount handling might need review depending on how PayPal Standard expects it.
        // Assuming discount_amount is negative or handled separately.
        if (isset($args['discount_amount'])) {
             $original_discount = floatval($args['discount_amount']);
             $converted_discount = round($original_discount * $conversion_rate, 2);
             $args['discount_amount'] = number_format($converted_discount, 2, '.', '');
             // Adjust total if discount is positive? Or assume it's subtracted elsewhere?
             // $total_converted_amount -= $converted_discount; // Be careful here
        }

        // Add handling fee
        if ($converted_handling_fee > 0) {
            $args['handling_cart'] = number_format($converted_handling_fee, 2, '.', ''); // Use handling_cart for PayPal Standard
            $total_converted_amount += $converted_handling_fee;
        }
        
        // Set the main 'amount' if it exists (often used for simple payments)
        // If this is a cart submission, 'amount' might not be used directly, rely on item amounts + shipping/tax/handling.
        // If 'amount' IS used, it MUST match the sum of other components.
        // It might be safer to REMOVE 'amount' if individual items are specified.
        if (isset($args['amount'])) {
             // Check if the original amount matches the sum of components before conversion
             // This is complex. For now, let's assume if 'amount' exists, it's the total.
             $original_total = floatval($args['amount']);
             $converted_total = round($original_total * $conversion_rate, 2) + $converted_handling_fee; // Recalculate total including handling fee
             $args['amount'] = number_format($converted_total, 2, '.', '');
        }
        
        // Log the modified arguments for debugging
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'modified_args' => $args,
            ], 'PAYPAL_ARGS_AFTER_MODIFICATION');
        }
        
        return $args;
    }
    
    /**
     * Modify PayPal request body for PayPal Checkout SDK (WooCommerce PayPal Payments plugin)
     *
     * @param array $data Request body data
     * @return array Modified data
     */
    public function modify_paypal_request_body($data) {
        if (!is_array($this->settings) || empty($this->settings)) {
            return $data;
        }
        
        $shop_currency = get_woocommerce_currency();
        $target_currency = isset($this->settings['target_currency']) ? $this->settings['target_currency'] : 'USD';
        $conversion_rate = isset($this->settings['conversion_rate']) ? floatval($this->settings['conversion_rate']) : 1.0;
        
        // If no conversion is needed, return original data
        if ($shop_currency === $target_currency) {
            return $data;
        }
        
        // Log the original data for debugging
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'original_data' => $data,
                'shop_currency' => $shop_currency,
                'target_currency' => $target_currency,
                'conversion_rate' => $conversion_rate,
            ], 'PAYPAL_REQUEST_BODY_BEFORE_MODIFICATION');
        }
        
        // Get handling fee from session (ensure it's calculated server-side)
        $handling_fee = 0;
        if (function_exists('WC') && WC()->session) {
            $handling_fee = WC()->session->get('ppcc_handling_fee', 0); // Assumes this is set correctly elsewhere in original currency
        }
        
        // Convert handling fee
        $converted_handling_fee = round($handling_fee * $conversion_rate, 2);
        
        // Update purchase units
        if (isset($data['purchase_units']) && is_array($data['purchase_units'])) {
            foreach ($data['purchase_units'] as &$unit) {
                // Initialize converted breakdown totals
                $converted_item_total = 0;
                $converted_shipping_total = 0;
                $converted_tax_total = 0;
                $converted_discount_total = 0; // Assuming discount is positive value
                $converted_shipping_discount = 0;

                // Update currency code for the main amount
                if (isset($unit['amount']['currency_code'])) {
                    $unit['amount']['currency_code'] = $target_currency;
                }
                
                // Update items and calculate converted item total
                if (isset($unit['items']) && is_array($unit['items'])) {
                    foreach ($unit['items'] as &$item) {
                        if (isset($item['unit_amount']['currency_code'])) {
                            $item['unit_amount']['currency_code'] = $target_currency;
                        }
                        if (isset($item['unit_amount']['value'])) {
                            $original_value = floatval($item['unit_amount']['value']);
                            $converted_value = round($original_value * $conversion_rate, 2);
                            $item['unit_amount']['value'] = number_format($converted_value, 2, '.', '');
                            // Add to item total (considering quantity)
                            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 1;
                            $converted_item_total += ($converted_value * $quantity);
                        }
                        // Convert tax per item if present
                        if (isset($item['tax']['currency_code'])) {
                            $item['tax']['currency_code'] = $target_currency;
                        }
                        if (isset($item['tax']['value'])) {
                            $original_tax_value = floatval($item['tax']['value']);
                            $converted_tax_value = round($original_tax_value * $conversion_rate, 2);
                            $item['tax']['value'] = number_format($converted_tax_value, 2, '.', '');
                            // Note: PayPal breakdown expects total tax, not per item. This might need adjustment based on how WC structures it.
                        }
                    }
                    // Round the final item total after summing
                    $converted_item_total = round($converted_item_total, 2);
                }
                
                // Update breakdown if it exists
                if (isset($unit['amount']['breakdown']) && is_array($unit['amount']['breakdown'])) {
                    $breakdown = &$unit['amount']['breakdown'];
                    
                    // Convert item_total (or use calculated sum if more reliable)
                    if (isset($breakdown['item_total'])) {
                        $breakdown['item_total']['currency_code'] = $target_currency;
                        // Option 1: Convert existing value
                        // $original_item_total_val = floatval($breakdown['item_total']['value']);
                        // $converted_item_total = round($original_item_total_val * $conversion_rate, 2);
                        // Option 2: Use sum calculated from items (potentially more accurate)
                        $breakdown['item_total']['value'] = number_format($converted_item_total, 2, '.', '');
                    } else {
                         // If breakdown exists but item_total doesn't, add the calculated one
                         $breakdown['item_total'] = [
                            'currency_code' => $target_currency,
                            'value' => number_format($converted_item_total, 2, '.', '')
                         ];
                    }

                    // Convert shipping
                    if (isset($breakdown['shipping'])) {
                        $breakdown['shipping']['currency_code'] = $target_currency;
                        $original_shipping_val = floatval($breakdown['shipping']['value']);
                        $converted_shipping_total = round($original_shipping_val * $conversion_rate, 2);
                        $breakdown['shipping']['value'] = number_format($converted_shipping_total, 2, '.', '');
                    }

                    // Convert tax_total
                    if (isset($breakdown['tax_total'])) {
                        $breakdown['tax_total']['currency_code'] = $target_currency;
                        $original_tax_total_val = floatval($breakdown['tax_total']['value']);
                        $converted_tax_total = round($original_tax_total_val * $conversion_rate, 2);
                        $breakdown['tax_total']['value'] = number_format($converted_tax_total, 2, '.', '');
                    }

                    // Convert discount
                    if (isset($breakdown['discount'])) {
                        $breakdown['discount']['currency_code'] = $target_currency;
                        $original_discount_val = floatval($breakdown['discount']['value']);
                        $converted_discount_total = round($original_discount_val * $conversion_rate, 2);
                        $breakdown['discount']['value'] = number_format($converted_discount_total, 2, '.', '');
                    }
                    
                    // Convert shipping_discount
                    if (isset($breakdown['shipping_discount'])) {
                        $breakdown['shipping_discount']['currency_code'] = $target_currency;
                        $original_shipping_discount_val = floatval($breakdown['shipping_discount']['value']);
                        $converted_shipping_discount = round($original_shipping_discount_val * $conversion_rate, 2);
                        $breakdown['shipping_discount']['value'] = number_format($converted_shipping_discount, 2, '.', '');
                    }

                    // Add handling fee to breakdown
                    if ($converted_handling_fee > 0) {
                        $breakdown['handling'] = [
                            'currency_code' => $target_currency,
                            'value' => number_format($converted_handling_fee, 2, '.', ''),
                        ];
                    } else {
                        // Ensure handling is removed if fee is zero
                        unset($breakdown['handling']);
                    }

                } else { 
                    // If breakdown doesn't exist, create it using calculated/converted values
                    $unit['amount']['breakdown'] = [];
                    $breakdown = &$unit['amount']['breakdown']; // Get reference to newly created array

                    $breakdown['item_total'] = [
                        'currency_code' => $target_currency,
                        'value' => number_format($converted_item_total, 2, '.', '')
                    ];
                    
                    // Need to get original shipping/tax if breakdown wasn't present initially
                    // This part is tricky - assuming WC provides these totals somewhere if not in breakdown
                    // For now, let's assume if breakdown is missing, maybe it's a simple payment?
                    // If it's a complex cart without breakdown, this logic will fail.
                    // We'll rely on the calculated totals initialized above (which might be 0)
                    
                    if ($converted_shipping_total > 0) {
                         $breakdown['shipping'] = [
                            'currency_code' => $target_currency,
                            'value' => number_format($converted_shipping_total, 2, '.', '')
                         ];
                    }
                    if ($converted_tax_total > 0) {
                         $breakdown['tax_total'] = [
                            'currency_code' => $target_currency,
                            'value' => number_format($converted_tax_total, 2, '.', '')
                         ];
                    }
                    if ($converted_discount_total > 0) {
                         $breakdown['discount'] = [
                            'currency_code' => $target_currency,
                            'value' => number_format($converted_discount_total, 2, '.', '')
                         ];
                    }
                     if ($converted_shipping_discount > 0) {
                         $breakdown['shipping_discount'] = [
                            'currency_code' => $target_currency,
                            'value' => number_format($converted_shipping_discount, 2, '.', '')
                         ];
                    }
                    if ($converted_handling_fee > 0) {
                        $breakdown['handling'] = [
                            'currency_code' => $target_currency,
                            'value' => number_format($converted_handling_fee, 2, '.', ''),
                        ];
                    }
                }
                
                // Recalculate the total amount value based on the final breakdown
                // Sum = item_total + tax_total + shipping + handling - discount - shipping_discount
                $final_calculated_total = 
                    (isset($breakdown['item_total']['value']) ? floatval($breakdown['item_total']['value']) : 0) +
                    (isset($breakdown['tax_total']['value']) ? floatval($breakdown['tax_total']['value']) : 0) +
                    (isset($breakdown['shipping']['value']) ? floatval($breakdown['shipping']['value']) : 0) +
                    (isset($breakdown['handling']['value']) ? floatval($breakdown['handling']['value']) : 0) -
                    (isset($breakdown['discount']['value']) ? floatval($breakdown['discount']['value']) : 0) -
                    (isset($breakdown['shipping_discount']['value']) ? floatval($breakdown['shipping_discount']['value']) : 0);
                
                // Ensure the final total matches the breakdown sum *exactly*
                $unit['amount']['value'] = number_format(round($final_calculated_total, 2), 2, '.', '');

            }
            // Release reference
            unset($unit);
            if (isset($breakdown)) unset($breakdown);
            if (isset($item)) unset($item);
        }
        
        // Log the modified data for debugging
        if (function_exists('ppcc_api_log')) {
            ppcc_api_log([
                'modified_data' => $data,
            ], 'PAYPAL_REQUEST_BODY_AFTER_MODIFICATION');
        }
        
        return $data;
    }
    
    /**
     * Add client-side fix for PayPal Checkout
     * This adds JavaScript to ensure the handling fee is correctly included in the PayPal API requests
     * <<< MODIFIED: This entire function can likely be removed or significantly refactored if server-side hooks work.
     * Keeping it commented out for reference for now.
     */
    /*
    public function add_paypal_client_side_fix() {
        // Only add on checkout page
        if (!is_checkout()) {
            return;
        }
        
        // Get settings
        if (!is_array($this->settings) || empty($this->settings)) {
            return;
        }
        
        $shop_currency = get_woocommerce_currency();
        $target_currency = isset($this->settings['target_currency']) ? $this->settings['target_currency'] : 'USD';
        $conversion_rate = isset($this->settings['conversion_rate']) ? floatval($this->settings['conversion_rate']) : 1.0;
        
        // Only proceed if conversion is needed
        if ($shop_currency === $target_currency) {
            return;
        }
        
        // Get handling fee and converting it
        $handling_fee = 0;
        $handling_percentage = isset($this->settings['handling_percentage']) ? floatval($this->settings['handling_percentage']) : 0;
        $handling_amount = isset($this->settings['handling_amount']) ? floatval($this->settings['handling_amount']) : 0;
        
        if ($handling_percentage > 0 || $handling_amount > 0) {
            // Ensure handling fee is converted to target currency
            $converted_handling_amount = round($handling_amount * $conversion_rate, 2);
            
            // Output JS to fix PayPal SDK
            ?>
            <script type="text/javascript">
            (function($) {
                // ... (rest of the original JS function commented out) ...
            })(jQuery);
            </script>
            <?php
        }
    }
    */
}

// Initialize the class
new PPCC_PayPal_API();

