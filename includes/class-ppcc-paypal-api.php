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
        add_action('wp_footer', array($this, 'add_paypal_client_side_fix'), 100);
    }
    
    /**
     * Modify PayPal arguments for WooCommerce PayPal integration
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
        
        // Get handling fee from session
        $handling_fee = 0;
        if (function_exists('WC') && WC()->session) {
            $handling_fee = WC()->session->get('ppcc_handling_fee', 0);
        }
        
        // Convert handling fee
        $converted_handling_fee = round($handling_fee * $conversion_rate, 2);
        
        // Convert amount values
        $amount_fields = ['amount', 'shipping', 'tax', 'discount_amount'];
        foreach ($amount_fields as $field) {
            if (isset($args[$field])) {
                $args[$field] = number_format(floatval($args[$field]) * $conversion_rate, 2, '.', '');
            }
        }
        
        // Add handling fee
        if ($converted_handling_fee > 0) {
            $args['handling'] = number_format($converted_handling_fee, 2, '.', '');
        }
        
        // Convert line item amounts
        foreach ($args as $key => $value) {
            if (preg_match('/^amount_\d+$/', $key) && is_numeric($value)) {
                $args[$key] = number_format(floatval($value) * $conversion_rate, 2, '.', '');
            }
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
     * Modify PayPal request body for PayPal Checkout SDK
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
        
        // Get handling fee from session
        $handling_fee = 0;
        if (function_exists('WC') && WC()->session) {
            $handling_fee = WC()->session->get('ppcc_handling_fee', 0);
        }
        
        // Convert handling fee
        $converted_handling_fee = round($handling_fee * $conversion_rate, 2);
        
        // Update purchase units
        if (isset($data['purchase_units']) && is_array($data['purchase_units'])) {
            foreach ($data['purchase_units'] as &$unit) {
                // Update currency code
                if (isset($unit['amount']['currency_code'])) {
                    $unit['amount']['currency_code'] = $target_currency;
                }
                
                // Convert amount value
                if (isset($unit['amount']['value'])) {
                    $original_value = floatval($unit['amount']['value']);
                    $converted_value = $original_value * $conversion_rate;
                    $unit['amount']['value'] = number_format($converted_value, 2, '.', '');
                }
                
                // Update breakdown
                if (isset($unit['amount']['breakdown']) && is_array($unit['amount']['breakdown'])) {
                    $breakdown = &$unit['amount']['breakdown'];
                    
                    // Convert each value in the breakdown
                    foreach ($breakdown as $key => &$item) {
                        if (isset($item['currency_code'])) {
                            $item['currency_code'] = $target_currency;
                        }
                        if (isset($item['value'])) {
                            $original_value = floatval($item['value']);
                            $converted_value = $original_value * $conversion_rate;
                            $item['value'] = number_format($converted_value, 2, '.', '');
                        }
                    }
                    
                    // Add or update handling fee in breakdown
                    if ($converted_handling_fee > 0) {
                        if (!isset($breakdown['handling'])) {
                            $breakdown['handling'] = [
                                'currency_code' => $target_currency,
                                'value' => number_format($converted_handling_fee, 2, '.', ''),
                            ];
                        } else {
                            $breakdown['handling']['currency_code'] = $target_currency;
                            $breakdown['handling']['value'] = number_format($converted_handling_fee, 2, '.', '');
                        }
                    }
                } else if (isset($unit['amount']) && $converted_handling_fee > 0) {
                    // If breakdown doesn't exist, create it
                    $original_amount = isset($unit['amount']['value']) ? floatval($unit['amount']['value']) : 0;
                    $item_total = $original_amount - $converted_handling_fee;
                    
                    if ($item_total < 0) {
                        $item_total = 0;
                    }
                    
                    $unit['amount']['breakdown'] = [
                        'item_total' => [
                            'currency_code' => $target_currency,
                            'value' => number_format($item_total, 2, '.', '')
                        ],
                        'handling' => [
                            'currency_code' => $target_currency,
                            'value' => number_format($converted_handling_fee, 2, '.', '')
                        ]
                    ];
                }
                
                // Update items
                if (isset($unit['items']) && is_array($unit['items'])) {
                    foreach ($unit['items'] as &$item) {
                        if (isset($item['unit_amount']['currency_code'])) {
                            $item['unit_amount']['currency_code'] = $target_currency;
                        }
                        if (isset($item['unit_amount']['value'])) {
                            $original_value = floatval($item['unit_amount']['value']);
                            $converted_value = $original_value * $conversion_rate;
                            $item['unit_amount']['value'] = number_format($converted_value, 2, '.', '');
                        }
                        if (isset($item['tax']['currency_code'])) {
                            $item['tax']['currency_code'] = $target_currency;
                        }
                        if (isset($item['tax']['value'])) {
                            $original_value = floatval($item['tax']['value']);
                            $converted_value = $original_value * $conversion_rate;
                            $item['tax']['value'] = number_format($converted_value, 2, '.', '');
                        }
                    }
                }
            }
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
     */
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
                if (typeof window.paypal === 'undefined') {
                    return;
                }
                
                console.log('PPCC: Applying PayPal SDK fix for handling fees');
                
                var ppccSettings = {
                    shopCurrency: '<?php echo esc_js($shop_currency); ?>',
                    targetCurrency: '<?php echo esc_js($target_currency); ?>',
                    conversionRate: <?php echo esc_js($conversion_rate); ?>,
                    handlingPercentage: <?php echo esc_js($handling_percentage); ?>,
                    handlingAmount: <?php echo esc_js($converted_handling_amount); ?>
                };
                
                // Wait for PayPal to be fully loaded
                var paypalCheckInterval = setInterval(function() {
                    if (window.paypal && window.paypal.Buttons && window.paypal.Buttons.driver) {
                        clearInterval(paypalCheckInterval);
                        applyPayPalFix();
                    }
                }, 100);
                
                function applyPayPalFix() {
                    try {
                        // Override PayPal SDK createOrder function
                        var originalCreateOrder = window.paypal.Buttons.driver('create', 'createOrder');
                        if (originalCreateOrder) {
                            window.paypal.Buttons.driver('create', 'createOrder', function() {
                                return function(data, actions) {
                                    // Get cart total from the page
                                    var cartTotal = parseFloat($('.cart-subtotal .amount:last').text().replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
                                    var shippingTotal = parseFloat($('.shipping .amount:last').text().replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
                                    var taxTotal = parseFloat($('.tax-total .amount:last').text().replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
                                    var orderTotal = parseFloat($('.order-total .amount:last').text().replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
                                    
                                    // Calculate handling fee
                                    var handlingFee = calculateHandlingFee(cartTotal, shippingTotal);
                                    
                                    // Convert all values to target currency
                                    var convertedCartTotal = (cartTotal * ppccSettings.conversionRate).toFixed(2);
                                    var convertedShippingTotal = (shippingTotal * ppccSettings.conversionRate).toFixed(2);
                                    var convertedTaxTotal = (taxTotal * ppccSettings.conversionRate).toFixed(2);
                                    var convertedHandlingFee = handlingFee.toFixed(2);
                                    var convertedOrderTotal = ((orderTotal * ppccSettings.conversionRate) + handlingFee).toFixed(2);
                                    
                                    console.log('PPCC Debug: PayPal order creation', {
                                        cartTotal: cartTotal,
                                        shippingTotal: shippingTotal,
                                        taxTotal: taxTotal,
                                        handlingFee: handlingFee,
                                        orderTotal: orderTotal,
                                        converted: {
                                            cartTotal: convertedCartTotal,
                                            shippingTotal: convertedShippingTotal,
                                            taxTotal: convertedTaxTotal,
                                            handlingFee: convertedHandlingFee,
                                            orderTotal: convertedOrderTotal
                                        }
                                    });
                                    
                                    // Create order with handling fee
                                    return actions.order.create({
                                        purchase_units: [{
                                            amount: {
                                                currency_code: ppccSettings.targetCurrency,
                                                value: convertedOrderTotal,
                                                breakdown: {
                                                    item_total: {
                                                        currency_code: ppccSettings.targetCurrency,
                                                        value: convertedCartTotal
                                                    },
                                                    shipping: {
                                                        currency_code: ppccSettings.targetCurrency,
                                                        value: convertedShippingTotal
                                                    },
                                                    tax_total: {
                                                        currency_code: ppccSettings.targetCurrency,
                                                        value: convertedTaxTotal
                                                    },
                                                    handling: {
                                                        currency_code: ppccSettings.targetCurrency,
                                                        value: convertedHandlingFee
                                                    }
                                                }
                                            }
                                        }]
                                    }).then(function(orderId) {
                                        console.log('PPCC: PayPal Order created successfully with ID:', orderId);
                                        return orderId;
                                    }).catch(function(error) {
                                        console.error('PPCC: PayPal Order creation error:', error);
                                        if (error && error.message && error.message.indexOf('currency') >= 0) {
                                            console.error('PPCC: This appears to be a currency-related error. Check your PayPal currency configuration.');
                                        }
                                        throw error;
                                    });
                                };
                            });
                        }
                    } catch (e) {
                        console.error('PPCC: Error applying PayPal SDK fix:', e);
                    }
                }
                
                // Calculate handling fee
                function calculateHandlingFee(cartTotal, shippingTotal) {
                    var baseAmount = cartTotal;
                    
                    // Check if shipping should be included in handling fee calculation
                    var includeShipping = <?php echo isset($this->settings['shipping_handling_fee']) && $this->settings['shipping_handling_fee'] === 'on' ? 'true' : 'false'; ?>;
                    if (includeShipping) {
                        baseAmount += shippingTotal;
                    }
                    
                    // Calculate fee based on percentage and fixed amount
                    var fee = (baseAmount * ppccSettings.handlingPercentage / 100) + ppccSettings.handlingAmount;
                    
                    // Convert to target currency
                    return fee;
                }
            })(jQuery);
            </script>
            <?php
        }
    }
}

// Initialize the class
new PPCC_PayPal_API();
