<?php
/**
 * Checkout class
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class to handle checkout process
 */
class PPCC_Checkout {
    /**
     * Plugin settings
     *
     * @var array
     */
    protected $settings;
    
    /**
     * Constructor
     *
     * @param array $settings Plugin settings
     */
    public function __construct( $settings ) {
        // Ensure settings are valid
        if (!is_array($settings)) {
            ppcc_log('Invalid settings passed to PPCC_Checkout constructor', 'error');
            $settings = array();
        }
        
        // Validate crucial numeric settings
        if (isset($settings['conversion_rate']) && !is_numeric($settings['conversion_rate'])) {
            ppcc_log('Invalid conversion_rate in settings: ' . var_export($settings['conversion_rate'], true), 'error');
            $settings['conversion_rate'] = 1.0;
        } else if (isset($settings['conversion_rate'])) {
            $settings['conversion_rate'] = (float)$settings['conversion_rate'];
        }
        
        $this->settings = $settings;
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        
        // Add handling fees to cart
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_handling_fee' ) );
        
        // Add conversion info to checkout
        add_filter( 'woocommerce_gateway_description', array( $this, 'add_conversion_info_to_description' ), 10, 2 );
        
        // Update order review fragments
        add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'update_checkout_fragments' ) );
        
        // Add data to payment methods
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'prepare_payment_methods' ) );
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if ( is_checkout() ) {
            wp_enqueue_script(
                'ppcc-checkout',
                PPCC_PLUGIN_URL . 'assets/js/ppcc-checkout.js',
                array( 'jquery', 'woocommerce' ),
                PPCC_VERSION,
                true
            );
            
            // Localize script with conversion data
            $conversion_data = $this->get_conversion_data();
            wp_localize_script( 'ppcc-checkout', 'ppcc_data', $conversion_data );
            
            // Add custom CSS for styling conversion info
            wp_enqueue_style(
                'ppcc-checkout-style',
                PPCC_PLUGIN_URL . 'assets/css/ppcc-checkout.css',
                array(),
                PPCC_VERSION
            );
        }
    }
    
    /**
     * Get conversion data for JavaScript
     *
     * @return array Conversion data
     */
    private function get_conversion_data() {
        $shop_currency = get_woocommerce_currency();
        $target_currency = $this->settings['target_currency'];
        $conversion_rate = $this->settings['conversion_rate'];
        
        // Debug the data we're passing to JavaScript
        ppcc_log('Conversion data for JS: shop=' . $shop_currency . ', target=' . $target_currency . 
               ', rate=' . $conversion_rate . ', cart_total=' . WC()->cart->get_cart_contents_total(), 'debug');
        
        return array(
            'shop_currency' => $shop_currency,
            'target_currency' => $target_currency,
            'conversion_rate' => $conversion_rate,
            'handling_percentage' => $this->settings['handling_percentage'],
            'handling_amount' => $this->settings['handling_amount'],
            'currency_symbol' => get_woocommerce_currency_symbol( $target_currency ),
            'decimals' => wc_get_price_decimals(),
            'thousand_separator' => wc_get_price_thousand_separator(),
            'decimal_separator' => wc_get_price_decimal_separator(),
            // Add cart data directly to help debug
            'cart_total' => WC()->cart->get_cart_contents_total(),
            'tax_total' => WC()->cart->get_total_tax(),
            'order_total' => WC()->cart->get_total('edit')
        );
    }
    
    /**
     * Add handling fee to cart
     *
     * @param WC_Cart $cart Cart object
     */
    public function add_handling_fee( $cart ) {
        try {
            // Skip if in admin and not an AJAX request
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                return;
            }
            
            // Skip if not on checkout or PayPal not selected
            if ( ! is_checkout() || ! $this->is_paypal_selected() ) {
                return;
            }
            
            // Validate cart object
            if (!is_object($cart) || !method_exists($cart, 'add_fee')) {
                ppcc_log('Invalid cart object in add_handling_fee', 'error');
                return;
            }
            
            // Make sure settings are set
            if ( ! is_array( $this->settings ) || empty( $this->settings ) ) {
                ppcc_log('Settings not available in add_handling_fee', 'error');
                return;
            }
            
            // Get handling fee settings with defaults
            $handling_percentage = isset( $this->settings['handling_percentage'] ) ? floatval( $this->settings['handling_percentage'] ) : 0;
            $handling_amount = isset( $this->settings['handling_amount'] ) ? floatval( $this->settings['handling_amount'] ) : 0;
            $handling_title = isset( $this->settings['handling_title'] ) ? $this->settings['handling_title'] : 'Handling';
            $handling_taxable = isset( $this->settings['handling_taxable'] ) && $this->settings['handling_taxable'] === 'on';
            $shipping_handling_fee = isset( $this->settings['shipping_handling_fee'] ) && $this->settings['shipping_handling_fee'] === 'on';
            
            // Check if we should apply handling fee
            if ( $handling_percentage === 0 && $handling_amount === 0 ) {
                return;
            }
            
            // Verify cart properties exist
            if (!property_exists($cart, 'cart_contents_total') || 
                ($shipping_handling_fee && !property_exists($cart, 'shipping_total'))) {
                ppcc_log('Missing required cart properties in add_handling_fee', 'error');
                return;
            }
            
            // Check lower threshold
            if ( isset( $this->settings['lower_treshold'] ) && $this->settings['lower_treshold'] === 'on' ) {
                $lower_threshold_amount = isset( $this->settings['lower_treshold_amount'] ) ? floatval( $this->settings['lower_treshold_amount'] ) : 0;
                if ( $cart->cart_contents_total > $lower_threshold_amount ) {
                    return;
                }
            }
            
            // Check upper threshold
            if ( isset( $this->settings['upper_treshold'] ) && $this->settings['upper_treshold'] === 'on' ) {
                $upper_threshold_amount = isset( $this->settings['upper_treshold_amount'] ) ? floatval( $this->settings['upper_treshold_amount'] ) : 0;
                if ( $cart->cart_contents_total < $upper_threshold_amount ) {
                    return;
                }
            }
            
            // Calculate handling fee
            $handling_fee_base = $cart->cart_contents_total;
            if ( $shipping_handling_fee ) {
                $handling_fee_base += $cart->shipping_total;
            }
            
            $fee = ( $handling_fee_base * $handling_percentage / 100 ) + $handling_amount;
            
            // Format fee label
            $fee_label = $handling_title;
            if ( $handling_percentage > 0 && $handling_amount > 0 ) {
                $fee_label .= ' (' . $handling_percentage . '% + ' . 
                           get_woocommerce_currency_symbol() . $handling_amount . ')';
            } elseif ( $handling_percentage > 0 ) {
                $fee_label .= ' (' . $handling_percentage . '%)';
            }
            
            // Add the fee
            if ( $fee != 0 ) {
                $cart->add_fee( $fee_label, $fee, $handling_taxable );
            }
        } catch (Exception $e) {
            ppcc_log('Exception in add_handling_fee: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Add conversion info to payment gateway description
     *
     * @param string $description Gateway description
     * @param string $id Gateway ID
     * @return string
     */
    public function add_conversion_info_to_description( $description, $id ) {
        // Only modify PayPal gateway descriptions
        if ( ! $this->is_paypal_gateway( $id ) ) {
            return $description;
        }
        
        $shop_currency = get_woocommerce_currency();
        $target_currency = $this->settings['target_currency'];
        
        // If already using a supported currency, no need to add conversion info
        if ( $shop_currency === $target_currency ) {
            return $description;
        }
        
        // Create conversion info HTML
        $conversion_info = $this->get_conversion_info_html();
        
        return $description . $conversion_info;
    }
    
    /**
     * Get conversion info HTML
     *
     * @return string
     */
    private function get_conversion_info_html() {
        $shop_currency = get_woocommerce_currency();
        $target_currency = $this->settings['target_currency'];
        $conversion_rate = $this->settings['conversion_rate'];
        
        // Start conversion info div
        $html = '<div class="ppcc-conversion-info">';
        
        // Add info about currency conversion
        $html .= '<p class="ppcc-conversion-notice">' . 
                sprintf( 
                    __( 'Your order will be processed in %s', 'ppcc-pro' ),
                    '<strong>' . $target_currency . '</strong>'
                ) .
                '</p>';
        
        // Add conversion details table
        $html .= '<table class="ppcc-conversion-details">';
        
        // Original currency info - new row
        $html .= '<tr class="ppcc-info-row">
                    <th colspan="2">' . sprintf( __( 'Original Order in %s:', 'ppcc-pro' ), '<strong>' . $shop_currency . '</strong>' ) . '</th>
                </tr>';

        // Cart total row
        $html .= '<tr>
                    <th>' . __( 'Cart Total:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-original-cart-total">' . wc_price( WC()->cart->get_cart_contents_total() ) . '</span></td>
                </tr>';
        
        // Shipping total row if needed
        $html .= '<tr>
                    <th>' . __( 'Shipping Total:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-original-shipping-total">' . wc_price( WC()->cart->get_shipping_total() ) . '</span></td>
                </tr>';
        
        // Handling fee row if enabled
        if ( floatval( $this->settings['handling_percentage'] ) > 0 || floatval( $this->settings['handling_amount'] ) > 0 ) {
            $html .= '<tr>
                        <th>' . __( 'Handling Fee:', 'ppcc-pro' ) . '</th>
                        <td><span class="ppcc-original-handling-total">' . wc_price( WC()->cart->get_fee_total() ) . '</span></td>
                    </tr>';
        }
        
        // Tax row
        $html .= '<tr>
                    <th>' . __( 'Tax Total:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-original-tax-total">' . wc_price( WC()->cart->get_total_tax() ) . '</span></td>
                </tr>';
        
        // Original order total row
        $html .= '<tr class="ppcc-order-total-row">
                    <th>' . __( 'Order Total:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-original-order-total">' . wc_price( WC()->cart->get_total('edit') ) . '</span></td>
                </tr>';

        // Separator row
        $html .= '<tr class="ppcc-separator"><td colspan="2"></td></tr>';
        
        // Converted currency info - new row
        $html .= '<tr class="ppcc-info-row">
                    <th colspan="2">' . sprintf( __( 'Converted Order in %s:', 'ppcc-pro' ), '<strong>' . $target_currency . '</strong>' ) . '</th>
                </tr>';

        // Converted cart total row
        $html .= '<tr>
                    <th>' . __( 'Cart Total:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-cart-total"></span></td>
                </tr>';
        
        // Converted shipping total row
        $html .= '<tr>
                    <th>' . __( 'Shipping Total:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-shipping-total"></span></td>
                </tr>';
        
        // Converted handling fee row if enabled
        if ( floatval( $this->settings['handling_percentage'] ) > 0 || floatval( $this->settings['handling_amount'] ) > 0 ) {
            $html .= '<tr>
                        <th>' . __( 'Handling Fee:', 'ppcc-pro' ) . '</th>
                        <td><span class="ppcc-handling-total"></span></td>
                    </tr>';
        }
        
        // Converted tax row
        $html .= '<tr>
                    <th>' . __( 'Tax Total:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-tax-total"></span></td>
                </tr>';
        
        // Converted order total row
        $html .= '<tr class="ppcc-order-total-row">
                    <th>' . __( 'Order Total:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-order-total"></span></td>
                </tr>';
        
        // Separator row before conversion rate
        $html .= '<tr class="ppcc-separator"><td colspan="2"></td></tr>';
        
        // Conversion rate row - display clearly using 1 unit of shop currency
        $html .= '<tr class="ppcc-rate-row">
                    <th>' . __( 'Conversion Rate:', 'ppcc-pro' ) . '</th>
                    <td><span class="ppcc-conversion-rate">' . 
                        '1 ' . $shop_currency . ' = ' . number_format($conversion_rate, 8) . ' ' . $target_currency . 
                    '</span></td>
                </tr>';
        
        $html .= '</table>';
        
        // Hidden spans for JavaScript
        $html .= '<div id="ppcc-checkout-data" style="display: none;">
                    <span class="ppcc-shadow-cart-total"></span>
                    <span class="ppcc-shadow-shipping-total"></span>
                    <span class="ppcc-shadow-handling-total"></span>
                    <span class="ppcc-shadow-tax-total"></span>
                    <span class="ppcc-shadow-order-total"></span>
                </div>';
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Update checkout fragments with currency conversion data
     *
     * @param array $fragments Checkout fragments
     * @return array
     */
    public function update_checkout_fragments( $fragments ) {
        try {
            // Check if fragments is actually an array to avoid issues
            if (!is_array($fragments)) {
                ppcc_log('Fragments not an array in update_checkout_fragments: ' . gettype($fragments), 'error');
                return is_array($fragments) ? $fragments : array();
            }
            
            if ( ! $this->is_paypal_selected() ) {
                return $fragments;
            }
            
            // Verify WC() is available and cart is initialized
            if (!function_exists('WC') || !WC()->cart) {
                ppcc_log('WC() or WC()->cart not available in update_checkout_fragments', 'error');
                return $fragments;
            }
            
            // Get cart totals
            $cart = WC()->cart;
            $shop_currency = get_woocommerce_currency();
            
            // Debug all cart values
            ppcc_log('Cart values for checkout:'.
                     'cart_contents=' . var_export($cart->get_cart_contents_total(), true) . ', ' . 
                     'shipping=' . var_export($cart->get_shipping_total(), true) . ', ' .
                     'fee=' . var_export($cart->get_fee_total(), true) . ', ' .
                     'tax=' . var_export($cart->get_total_tax(), true) . ', ' .
                     'total=' . var_export($cart->get_total('edit'), true), 'debug');
            
            // Check settings are available
            if (!is_array($this->settings) || !isset($this->settings['target_currency']) || !isset($this->settings['conversion_rate'])) {
                ppcc_log('Required settings missing in update_checkout_fragments', 'error', $this->settings);
                return $fragments;
            }
            
            $target_currency = $this->settings['target_currency'];
            
            // Ensure conversion rate is a float
            $conversion_rate = $this->settings['conversion_rate'];
            if (!is_numeric($conversion_rate)) {
                // Log the issue
                ppcc_log('Invalid conversion rate format: ' . var_export($conversion_rate, true), 'error');
                // Default to 1.0 to prevent calculation errors
                $conversion_rate = 1.0;
            } else {
                $conversion_rate = (float)$conversion_rate;
            }
            
            // Make sure all values are treated as floats
            $cart_contents_total = (float)$cart->get_cart_contents_total();
            $shipping_total = (float)$cart->get_shipping_total();
            $fee_total = (float)$cart->get_fee_total();
            $tax_total = (float)$cart->get_total_tax();
            $total = (float)$cart->get_total();
            
            // Debug values
            ppcc_log('Cart totals for conversion: ' . 
                     'contents=' . $cart_contents_total . '(' . gettype($cart_contents_total) . '), ' . 
                     'shipping=' . $shipping_total . '(' . gettype($shipping_total) . '), ' .
                     'fee=' . $fee_total . '(' . gettype($fee_total) . '), ' .
                     'tax=' . $tax_total . '(' . gettype($tax_total) . '), ' .
                     'total=' . $total . '(' . gettype($total) . '), ' .
                     'conversion_rate=' . $conversion_rate . '(' . gettype($conversion_rate) . ')', 'debug');
                     
            // Debug handling fee settings
            ppcc_log('Handling fee settings: ' . 
                     'percentage=' . (isset($this->settings['handling_percentage']) ? $this->settings['handling_percentage'] : 'not set') . ', ' .
                     'amount=' . (isset($this->settings['handling_amount']) ? $this->settings['handling_amount'] : 'not set') . ', ' .
                     'taxable=' . (isset($this->settings['handling_taxable']) ? $this->settings['handling_taxable'] : 'not set') . ', ' .
                     'shipping_handling_fee=' . (isset($this->settings['shipping_handling_fee']) ? $this->settings['shipping_handling_fee'] : 'not set'), 
                     'debug');
            
            // Calculate conversion correctly - a direct multiplication by the conversion rate
            // If rate is 0.04478253, then 1 ZAR = 0.04478253 CHF
            // So 10 ZAR = 10 * 0.04478253 = 0.4478253 CHF
            
            // A direct multiplication is the standard in currency conversion
            $converted_cart_total = $cart_contents_total * $conversion_rate;
            $converted_shipping_total = $shipping_total * $conversion_rate;
            $converted_fee_total = $fee_total * $conversion_rate;
            $converted_tax_total = $tax_total * $conversion_rate;
            $converted_total = $total * $conversion_rate;
            
            // Log the actual values for debugging
            ppcc_log('Direct conversion: ' . $cart_contents_total . ' ZAR at rate ' . $conversion_rate . ' = ' . 
                    $converted_cart_total . ' CHF', 'debug');
            
            // Format values
            $formatted_cart_total = $this->format_price( $converted_cart_total, $target_currency );
            $formatted_shipping_total = $this->format_price( $converted_shipping_total, $target_currency );
            $formatted_fee_total = $this->format_price( $converted_fee_total, $target_currency );
            $formatted_tax_total = $this->format_price( $converted_tax_total, $target_currency );
            $formatted_total = $this->format_price( $converted_total, $target_currency );
            
            // Debug converted values
            ppcc_log('Converted values: ' . 
                     'cart=' . $converted_cart_total . ', ' . 
                     'shipping=' . $converted_shipping_total . ', ' .
                     'fee=' . $converted_fee_total . ', ' .
                     'tax=' . $converted_tax_total . ', ' .
                     'total=' . $converted_total, 'debug');
                    
            // Add data to fragments 
            $fragments['.ppcc-cart-total'] = '<span class="ppcc-cart-total">' . $formatted_cart_total . '</span>';
            $fragments['.ppcc-shipping-total'] = '<span class="ppcc-shipping-total">' . $formatted_shipping_total . '</span>';
            $fragments['.ppcc-handling-total'] = '<span class="ppcc-handling-total">' . $formatted_fee_total . '</span>';
            $fragments['.ppcc-tax-total'] = '<span class="ppcc-tax-total">' . $formatted_tax_total . '</span>';
            $fragments['.ppcc-order-total'] = '<span class="ppcc-order-total">' . $formatted_total . '</span>';
            
            // Shadow values for JavaScript
            $fragments['.ppcc-shadow-cart-total'] = '<span class="ppcc-shadow-cart-total">' . $formatted_cart_total . '</span>';
            $fragments['.ppcc-shadow-shipping-total'] = '<span class="ppcc-shadow-shipping-total">' . $formatted_shipping_total . '</span>';
            $fragments['.ppcc-shadow-handling-total'] = '<span class="ppcc-shadow-handling-total">' . $formatted_fee_total . '</span>';
            $fragments['.ppcc-shadow-tax-total'] = '<span class="ppcc-shadow-tax-total">' . $formatted_tax_total . '</span>';
            $fragments['.ppcc-shadow-order-total'] = '<span class="ppcc-shadow-order-total">' . $formatted_total . '</span>';
            
            return $fragments;
        } catch (Exception $e) {
            // Log the error
            ppcc_log('Exception in update_checkout_fragments: ' . $e->getMessage(), 'error');
            // Return original fragments to prevent breaking the checkout
            return is_array($fragments) ? $fragments : array();
        }
    }
    
    /**
     * Prepare payment methods with conversion data
     *
     * @param array $gateways Available payment gateways
     * @return array
     */
    public function prepare_payment_methods( $gateways ) {
        foreach ( $gateways as $id => $gateway ) {
            if ( $this->is_paypal_gateway( $id ) ) {
                // Make sure gateway knows about currency conversion
                if (!in_array('ppcc_currency_conversion', $gateway->supports)) {
                    $gateway->supports[] = 'ppcc_currency_conversion';
                }
                
                // Store settings in a proper way using a method
                if ( method_exists( $gateway, 'update_meta_data' ) ) {
                    $gateway->update_meta_data( '_ppcc_settings', $this->settings );
                } else {
                    // Use WordPress transient API to store settings for this gateway
                    // This avoids the deprecated dynamic property warning
                    set_transient( 'ppcc_settings_for_' . $id, $this->settings, HOUR_IN_SECONDS );
                    
                    // Add filter to retrieve settings when needed
                    if (!has_filter( 'woocommerce_gateway_' . $id . '_settings' )) {
                        add_filter( 'woocommerce_gateway_' . $id . '_settings', function( $settings ) use ( $id ) {
                            $ppcc_settings = get_transient( 'ppcc_settings_for_' . $id );
                            if ( $ppcc_settings ) {
                                $settings['ppcc'] = $ppcc_settings;
                            }
                            
                            // For non-decimal currencies, ensure decimal settings are set properly
                            $currency = get_woocommerce_currency();
                            $non_decimal_currencies = array( 'HUF', 'JPY', 'TWD' );
                            
                            if ( in_array( $currency, $non_decimal_currencies ) ) {
                                // Log that we're fixing PayPal settings
                                if ( function_exists( 'ppcc_log' ) ) {
                                    ppcc_log( 'Fixing decimal settings for ' . $id . ' with currency ' . $currency, 'debug' );
                                }
                                
                                // Force 0 decimals for PayPal gateways
                                if ( isset( $settings['currency_decimals'] ) ) {
                                    $settings['currency_decimals'] = 0;
                                }
                                
                                // Set PayPal-specific settings
                                if ( $id === 'ppcp-gateway' || $id === 'paypal' ) {
                                    if ( isset( $settings['decimal_places'] ) ) {
                                        $settings['decimal_places'] = 0;
                                    }
                                    
                                    if ( isset( $settings['decimals'] ) ) {
                                        $settings['decimals'] = 0;
                                    }
                                    
                                    // Set to string '0' for some settings that expect strings
                                    if ( isset( $settings['decimal_separator'] ) ) {
                                        $settings['decimal_separator'] = '';
                                    }
                                }
                            }
                            
                            return $settings;
                        });
                    }
                }
            }
        }
        
        return $gateways;
    }
    
    /**
     * Format price with currency
     *
     * @param float $price Price
     * @param string $currency Currency code
     * @return string
     */
    private function format_price( $price, $currency ) {
        return wc_price( $price, array( 'currency' => $currency ) );
    }
    
    /**
     * Check if current payment method is PayPal
     *
     * @return bool
     */
    private function is_paypal_selected() {
        try {
            // Skip if not on checkout page
            if ( ! is_checkout() ) {
                return false;
            }
            
            // Make sure WC is loaded and session exists
            if ( ! function_exists( 'WC' ) ) {
                ppcc_log('WC() function not available', 'error');
                return false;
            }
            
            if ( ! WC()->session ) {
                ppcc_log('WC()->session not available', 'error');
                return false;
            }
            
            // Get chosen payment method
            $chosen_payment_method = null;
            try {
                $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
            } catch (Exception $e) {
                ppcc_log('Error getting chosen_payment_method: ' . $e->getMessage(), 'error');
                return false;
            }
            
            if ( ! $chosen_payment_method ) {
                // If no method chosen yet, check for default
                if ( ! WC()->payment_gateways ) {
                    return false;
                }
                
                try {
                    $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
                    $default_gateway = get_option( 'woocommerce_default_gateway' );
                    
                    if ( ! empty( $default_gateway ) && isset( $available_gateways[ $default_gateway ] ) ) {
                        $chosen_payment_method = $default_gateway;
                    } elseif ( ! empty( $available_gateways ) ) {
                        $chosen_payment_method = key( $available_gateways );
                    }
                } catch (Exception $e) {
                    ppcc_log('Error getting available gateways: ' . $e->getMessage(), 'error');
                    return false;
                }
            }
            
            // Check if the chosen method is a PayPal gateway
            return $chosen_payment_method && $this->is_paypal_gateway( $chosen_payment_method );
        } catch (Exception $e) {
            ppcc_log('Exception in is_paypal_selected: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Check if given gateway ID is a PayPal gateway
     *
     * @param string $gateway_id Gateway ID
     * @return bool
     */
    private function is_paypal_gateway( $gateway_id ) {
        return ppcc_is_paypal_gateway( $gateway_id );
    }
}
