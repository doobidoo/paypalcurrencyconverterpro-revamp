<?php
/**
 * Order converter class
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class to handle order currency conversion
 */
class PPCC_Order_Converter {
    /**
     * Plugin settings
     *
     * @var array
     */
    protected $settings;
    
    /**
     * PayPal supported currencies
     *
     * @var array
     */
    protected $pp_currencies = array(
        'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 
        'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 
        'CHF', 'TWD', 'THB', 'GBP', 'CNY'
    );
    
    /**
     * Constructor
     *
     * @param array $settings Plugin settings
     */
    public function __construct( $settings ) {
        $this->settings = $settings;
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Hooks for handling order creation and modification
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'maybe_convert_order_currency' ), 10, 2 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'add_conversion_order_note' ), 10, 3 );
        
        // Add meta box to order screen
        add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box' ) );
        
        // Hook into order display
        add_filter( 'woocommerce_get_formatted_order_total', array( $this, 'display_original_total' ), 10, 4 );
        
        // Filter to add conversion info to emails
        add_action( 'woocommerce_email_order_meta', array( $this, 'add_conversion_info_to_emails' ), 10, 3 );
        
        // Hook into order status changes
        add_action( 'woocommerce_order_status_on-hold', array( $this, 'handle_order_status' ), 10, 2 );
        
        // Handle automatic order status changes for PayPal
        if ( 'on' === $this->settings['suppress_order_on_hold_email'] ) {
            add_filter( 'woocommerce_email_recipient_customer_on_hold_order', array( $this, 'suppress_on_hold_email' ), 10, 2 );
        }
    }
    
    /**
     * Convert order currency if PayPal is selected
     *
     * @param int $order_id Order ID
     * @param array $posted_data Posted data
     */
    public function maybe_convert_order_currency( $order_id, $posted_data ) {
        // Get the order
        $order = wc_get_order( $order_id );
        
        // Check if PayPal is selected as payment method
        if ( ! $this->is_paypal_gateway( $order->get_payment_method() ) ) {
            return;
        }
        
        // Get conversion data
        $shop_currency = get_woocommerce_currency();
        $target_currency = $this->settings['target_currency'];
        $conversion_rate = $this->settings['conversion_rate'];
        
        // If already using a supported currency, no need to convert
        if ( in_array( $shop_currency, $this->pp_currencies ) && $shop_currency === $target_currency ) {
            // Even when not converting, ensure we save the currency information for debugging
            if ( function_exists( 'ppcc_log' ) ) {
                ppcc_log( sprintf( 
                    'Order #%d - Using original currency: %s (no conversion needed)',
                    $order_id,
                    $shop_currency
                ), 'debug' );
            }
            return;
        }
        
        // Double-check that target currency is valid for PayPal
        if ( ! in_array( $target_currency, $this->pp_currencies ) ) {
            // Target currency is not supported by PayPal, log error and use USD as fallback
            if ( function_exists( 'ppcc_log' ) ) {
                ppcc_log( sprintf( 
                    'Order #%d - Invalid target currency: %s is not supported by PayPal. Using USD as fallback.',
                    $order_id,
                    $target_currency
                ), 'error' );
            }
            $target_currency = 'USD';
            // Update settings temporarily
            $this->settings['target_currency'] = 'USD';
        }
        
        // Get proper decimal precision for the target currency
        $decimals = $this->get_currency_decimals( $target_currency );
        
        // Store original currency info
        $order->update_meta_data( '_ppcc_original_currency', $shop_currency );
        $order->update_meta_data( '_ppcc_original_total', $order->get_total() );
        $order->update_meta_data( '_ppcc_conversion_rate', $conversion_rate );
        $order->update_meta_data( '_ppcc_target_currency', $target_currency );
        $order->update_meta_data( '_ppcc_currency_decimals', $decimals );
        
        // Set new currency
        $order->set_currency( $target_currency );
        
        // Convert all order items and totals
        $this->convert_order_items( $order, $conversion_rate, $decimals );
        $this->convert_order_shipping( $order, $conversion_rate, $decimals );
        $this->convert_order_taxes( $order, $conversion_rate, $decimals );
        $this->convert_order_fees( $order, $conversion_rate, $decimals );
        
        // Update total - make sure it's properly formatted for PayPal
        // The stored conversion rate is correctly defined as 1 shop_currency = X target_currency
        // So we simply multiply by the conversion rate (not divide)
        $original_total = $order->get_total();
        $new_total = $this->format_converted_price( $original_total * $conversion_rate, $decimals );
        
        // Log the conversion with detailed calculation info
        if ( function_exists( 'ppcc_log' ) ) {
            ppcc_log( sprintf( 
                'Order total conversion: %s %s * %s = %s %s (decimals: %s, is integer: %s, expected: %s %s)',
                $original_total,
                $shop_currency,
                $conversion_rate,
                $new_total,
                $target_currency,
                $decimals,
                (is_int($new_total) ? 'yes' : 'no'),
                number_format($original_total * $conversion_rate, $decimals),
                $target_currency
            ), 'debug' );
        }
        
        // Set the new total
        $order->set_total( $new_total );
        
        // Save the order
        $order->save();
        
        // Log the conversion
        if ( function_exists( 'ppcc_log' ) ) {
            ppcc_log( sprintf( 
                'Order #%d converted from %s to %s with rate %s. Original total: %s %s, Converted total: %s %s',
                $order_id,
                $shop_currency,
                $target_currency,
                $conversion_rate,
                $order->get_meta( '_ppcc_original_total' ),
                $shop_currency,
                $new_total,
                $target_currency
            ) );
        }
    }
    
    /**
     * Get number of decimals for a currency
     *
     * @param string $currency Currency code
     * @return int Number of decimals (0 or 2)
     */
    private function get_currency_decimals( $currency ) {
        // Currencies that don't support decimals in PayPal
        $non_decimal_currencies = array( 'HUF', 'JPY', 'TWD' );
        
        // Log for debugging
        if (function_exists('ppcc_log')) {
            ppcc_log('Getting decimals for currency: ' . $currency . ' - ' . 
                    (in_array($currency, $non_decimal_currencies) ? '0 decimals' : '2 decimals'), 'debug');
        }
        
        return in_array( $currency, $non_decimal_currencies ) ? 0 : 2;
    }
    
    /**
     * Format a converted price with the proper number of decimals
     *
     * @param float $price The price to format
     * @param int $decimals Number of decimals
     * @return float|int Formatted price
     */
    private function format_converted_price( $price, $decimals = null ) {
        if ( $decimals === null ) {
            $decimals = wc_get_price_decimals();
        }
        
        // Log the input for debugging
        if (function_exists('ppcc_log')) {
            ppcc_log(sprintf(
                'Formatting price: %s with %d decimals (before rounding)',
                $price,
                $decimals
            ), 'debug');
        }
        
        // For JPY, HUF, and TWD PayPal requires integers with no decimals
        $rounded_price = round( $price, $decimals );
        
        // If decimals are 0, make sure we return an integer to avoid PayPal's DECIMALS_NOT_SUPPORTED error
        if ($decimals === 0) {
            $final_price = intval($rounded_price);
            
            // Log the conversion
            if (function_exists('ppcc_log')) {
                ppcc_log(sprintf(
                    'Converted to integer: %s → %d (0 decimals required)',
                    $price,
                    $final_price
                ), 'debug');
            }
            
            return $final_price;
        }
        
        // Log the result
        if (function_exists('ppcc_log')) {
            ppcc_log(sprintf(
                'Final formatted price: %s (with %d decimals)',
                $rounded_price,
                $decimals
            ), 'debug');
        }
        
        return $rounded_price;
    }
    
    /**
     * Convert order line items
     *
     * @param WC_Order $order The order
     * @param float $conversion_rate Conversion rate
     * @param int $decimals Number of decimals for target currency (optional)
     */
    private function convert_order_items( $order, $conversion_rate, $decimals = null ) {
        if ( $decimals === null ) {
            $decimals = wc_get_price_decimals();
        }
        
        foreach ( $order->get_items() as $item_id => $item ) {
            // Get the original amounts
            $original_subtotal = $item->get_subtotal();
            $original_total = $item->get_total();
            $original_subtotal_tax = $item->get_subtotal_tax();
            $original_total_tax = $item->get_total_tax();
            
            // Convert to target currency - simply multiply by conversion rate
            $new_subtotal = $this->format_converted_price( $original_subtotal * $conversion_rate, $decimals );
            $new_total = $this->format_converted_price( $original_total * $conversion_rate, $decimals );
            $new_subtotal_tax = $this->format_converted_price( $original_subtotal_tax * $conversion_rate, $decimals );
            $new_total_tax = $this->format_converted_price( $original_total_tax * $conversion_rate, $decimals );
            
            // Store the original values as meta
            wc_update_order_item_meta( $item_id, '_ppcc_original_subtotal', $original_subtotal );
            wc_update_order_item_meta( $item_id, '_ppcc_original_total', $original_total );
            wc_update_order_item_meta( $item_id, '_ppcc_original_subtotal_tax', $original_subtotal_tax );
            wc_update_order_item_meta( $item_id, '_ppcc_original_total_tax', $original_total_tax );
            
            // Update the item with new values
            wc_update_order_item_meta( $item_id, '_line_subtotal', $new_subtotal );
            wc_update_order_item_meta( $item_id, '_line_total', $new_total );
            wc_update_order_item_meta( $item_id, '_line_subtotal_tax', $new_subtotal_tax );
            wc_update_order_item_meta( $item_id, '_line_tax', $new_total_tax );
        }
    }
    
    /**
     * Convert order shipping
     *
     * @param WC_Order $order The order
     * @param float $conversion_rate Conversion rate
     * @param int $decimals Number of decimals for target currency (optional)
     */
    private function convert_order_shipping( $order, $conversion_rate, $decimals = null ) {
        if ( $decimals === null ) {
            $decimals = wc_get_price_decimals();
        }
        
        foreach ( $order->get_shipping_methods() as $shipping_id => $shipping ) {
            // Get original amounts
            $original_cost = $shipping->get_total();
            $original_tax = $shipping->get_total_tax();
            
            // Convert to target currency - simply multiply by conversion rate
            $new_cost = $this->format_converted_price( $original_cost * $conversion_rate, $decimals );
            $new_tax = $this->format_converted_price( $original_tax * $conversion_rate, $decimals );
            
            // Store the original values as meta
            wc_update_order_item_meta( $shipping_id, '_ppcc_original_cost', $original_cost );
            wc_update_order_item_meta( $shipping_id, '_ppcc_original_tax', $original_tax );
            
            // Update the shipping with new values
            wc_update_order_item_meta( $shipping_id, 'cost', $new_cost );
            wc_update_order_item_meta( $shipping_id, 'total_tax', $new_tax );
        }
    }
    
    /**
     * Convert order taxes
     *
     * @param WC_Order $order The order
     * @param float $conversion_rate Conversion rate
     * @param int $decimals Number of decimals for target currency (optional)
     */
    private function convert_order_taxes( $order, $conversion_rate, $decimals = null ) {
        if ( $decimals === null ) {
            $decimals = wc_get_price_decimals();
        }
        
        // Get tax items and update them
        foreach ( $order->get_items( 'tax' ) as $tax_id => $tax_item ) {
            // Get original amounts
            $original_tax_amount = $tax_item->get_tax_total();
            $original_shipping_tax_amount = $tax_item->get_shipping_tax_total();
            
            // Convert to target currency - simply multiply by conversion rate
            $new_tax_amount = $this->format_converted_price( $original_tax_amount * $conversion_rate, $decimals );
            $new_shipping_tax_amount = $this->format_converted_price( $original_shipping_tax_amount * $conversion_rate, $decimals );
            
            // Store the original values as meta
            wc_update_order_item_meta( $tax_id, '_ppcc_original_tax_amount', $original_tax_amount );
            wc_update_order_item_meta( $tax_id, '_ppcc_original_shipping_tax_amount', $original_shipping_tax_amount );
            
            // Update the tax item with new values
            wc_update_order_item_meta( $tax_id, 'tax_amount', $new_tax_amount );
            wc_update_order_item_meta( $tax_id, 'shipping_tax_amount', $new_shipping_tax_amount );
        }
    }
    
    /**
     * Convert order fees
     *
     * @param WC_Order $order The order
     * @param float $conversion_rate Conversion rate
     * @param int $decimals Number of decimals for target currency (optional)
     */
    private function convert_order_fees( $order, $conversion_rate, $decimals = null ) {
        if ( $decimals === null ) {
            $decimals = wc_get_price_decimals();
        }
        
        foreach ( $order->get_fees() as $fee_id => $fee ) {
            // Get original amounts
            $original_amount = $fee->get_total();
            $original_tax = $fee->get_total_tax();
            
            // Convert to target currency - simply multiply by conversion rate
            $new_amount = $this->format_converted_price( $original_amount * $conversion_rate, $decimals );
            $new_tax = $this->format_converted_price( $original_tax * $conversion_rate, $decimals );
            
            // Store the original values as meta
            wc_update_order_item_meta( $fee_id, '_ppcc_original_amount', $original_amount );
            wc_update_order_item_meta( $fee_id, '_ppcc_original_tax', $original_tax );
            
            // Update the fee with new values
            wc_update_order_item_meta( $fee_id, '_line_total', $new_amount );
            wc_update_order_item_meta( $fee_id, '_line_tax', $new_tax );
        }
    }
    
    /**
     * Add conversion note to order
     *
     * @param int $order_id Order ID
     * @param array $posted_data Posted data
     * @param WC_Order $order Order object
     */
    public function add_conversion_order_note( $order_id, $posted_data, $order ) {
        // Check if this order was converted
        if ( ! $order->meta_exists( '_ppcc_original_currency' ) ) {
            return;
        }
        
        // Get conversion details
        $original_currency = $order->get_meta( '_ppcc_original_currency' );
        $original_total = $order->get_meta( '_ppcc_original_total' );
        $conversion_rate = $order->get_meta( '_ppcc_conversion_rate' );
        $target_currency = $order->get_currency();
        
        // Add order note
        $order->add_order_note( 
            sprintf( 
                __( 'Order currency converted from %1$s %2$s to %3$s %4$s with conversion rate %5$s', 'ppcc-pro' ),
                wc_price( $original_total, array( 'currency' => $original_currency ) ),
                $original_currency,
                wc_price( $order->get_total(), array( 'currency' => $target_currency ) ),
                $target_currency,
                $conversion_rate
            )
        );
    }
    
    /**
     * Add meta box to order screen
     */
    public function add_order_meta_box() {
        add_meta_box(
            'ppcc_order_meta_box',
            __( 'PayPal Currency Conversion', 'ppcc-pro' ),
            array( $this, 'render_order_meta_box' ),
            'shop_order',
            'side',
            'high'
        );
    }
    
    /**
     * Render meta box content
     *
     * @param WP_Post $post Post object
     */
    public function render_order_meta_box( $post ) {
        $order = wc_get_order( $post->ID );
        
        // Check if this order was converted
        if ( ! $order->meta_exists( '_ppcc_original_currency' ) ) {
            echo '<p>' . __( 'No currency conversion applied to this order.', 'ppcc-pro' ) . '</p>';
            return;
        }
        
        // Get conversion details
        $original_currency = $order->get_meta( '_ppcc_original_currency' );
        $original_total = $order->get_meta( '_ppcc_original_total' );
        $conversion_rate = $order->get_meta( '_ppcc_conversion_rate' );
        $target_currency = $order->get_currency();
        
        // Display conversion details
        ?>
        <div class="ppcc-conversion-details">
            <p>
                <strong><?php _e( 'Original Currency:', 'ppcc-pro' ); ?></strong>
                <?php echo $original_currency; ?>
            </p>
            <p>
                <strong><?php _e( 'Original Total:', 'ppcc-pro' ); ?></strong>
                <?php echo wc_price( $original_total, array( 'currency' => $original_currency ) ); ?>
            </p>
            <p>
                <strong><?php _e( 'Conversion Rate:', 'ppcc-pro' ); ?></strong>
                <?php echo $conversion_rate; ?>
            </p>
            <p>
                <strong><?php _e( 'Converted Currency:', 'ppcc-pro' ); ?></strong>
                <?php echo $target_currency; ?>
            </p>
            <p>
                <strong><?php _e( 'Converted Total:', 'ppcc-pro' ); ?></strong>
                <?php echo wc_price( $order->get_total(), array( 'currency' => $target_currency ) ); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Display original total in order details
     *
     * @param string $formatted_total Formatted total
     * @param WC_Order $order Order object
     * @param string $tax_display Tax display mode
     * @param bool $display_refunded Display refunded
     * @return string
     */
    public function display_original_total( $formatted_total, $order, $tax_display, $display_refunded ) {
        // Check if this order was converted
        if ( ! $order->meta_exists( '_ppcc_original_currency' ) ) {
            return $formatted_total;
        }
        
        // Get conversion details
        $original_currency = $order->get_meta( '_ppcc_original_currency' );
        $original_total = $order->get_meta( '_ppcc_original_total' );
        
        // Add original total to display
        $formatted_total .= ' <small class="ppcc-original-total">(' . 
            wc_price( $original_total, array( 'currency' => $original_currency ) ) . ' ' . 
            __( 'original', 'ppcc-pro' ) . ')</small>';
        
        return $formatted_total;
    }
    
    /**
     * Add conversion info to emails
     *
     * @param WC_Order $order Order object
     * @param bool $sent_to_admin Sent to admin
     * @param bool $plain_text Plain text
     */
    public function add_conversion_info_to_emails( $order, $sent_to_admin, $plain_text ) {
        // Check if this order was converted and email note is enabled
        if ( ! $order->meta_exists( '_ppcc_original_currency' ) || 'on' !== $this->settings['email_order_completed_note'] ) {
            return;
        }
        
        // Get conversion details
        $original_currency = $order->get_meta( '_ppcc_original_currency' );
        $original_total = $order->get_meta( '_ppcc_original_total' );
        $conversion_rate = $order->get_meta( '_ppcc_conversion_rate' );
        $target_currency = $order->get_currency();
        
        // Format the email note using the template from settings
        $handling_amount = $this->settings['handling_amount'] * $conversion_rate;
        $converted_total = $order->get_total();
        
        $email_note = sprintf(
            $this->settings['order_email_note'],
            $conversion_rate,
            $target_currency,
            $original_currency,
            wc_price( $converted_total, array( 'currency' => $target_currency ) ),
            $this->settings['handling_percentage'],
            wc_price( $handling_amount, array( 'currency' => $target_currency ) )
        );
        
        // Output the conversion info
        if ( $plain_text ) {
            echo "\n\n" . wp_strip_all_tags( $email_note ) . "\n\n";
        } else {
            echo '<h2>' . __( 'Currency Conversion Information', 'ppcc-pro' ) . '</h2>';
            echo '<p>' . $email_note . '</p>';
        }
    }
    
    /**
     * Handle order status
     *
     * @param int $order_id Order ID
     * @param WC_Order $order Order object
     */
    public function handle_order_status( $order_id, $order = null ) {
        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        
        // Only process PayPal orders
        if ( ! $this->is_paypal_gateway( $order->get_payment_method() ) ) {
            return;
        }
        
        // Add a note about the currency conversion
        if ( $order->meta_exists( '_ppcc_original_currency' ) ) {
            $original_currency = $order->get_meta( '_ppcc_original_currency' );
            $original_total = $order->get_meta( '_ppcc_original_total' );
            $conversion_rate = $order->get_meta( '_ppcc_conversion_rate' );
            $target_currency = $order->get_currency();
            
            // Add detailed debugging information to help diagnose issues
            if (function_exists('ppcc_log')) {
                ppcc_log(sprintf(
                    'Order #%d payment processing - Original: %s %s, Target: %s %s, Rate: %s',
                    $order->get_id(),
                    $original_total,
                    $original_currency,
                    $order->get_total(),
                    $target_currency,
                    $conversion_rate
                ), 'debug');
            }
            
            $order->add_order_note( 
                sprintf( 
                    __( 'PPCC - Cart paid with %1$s: Original amount %2$s %3$s converted to %7$s %8$s (Rate: 1 %3$s = %4$s %5$s)', 'ppcc-pro' ),
                    $order->get_payment_method_title(),
                    $original_total,
                    $original_currency,
                    $conversion_rate,
                    $target_currency,
                    $original_currency,
                    $order->get_total(),
                    $target_currency
                )
            );
        }
        
        // Check if we should auto-complete or auto-process the order
        $this->maybe_autocomplete_order( $order );
    }
    
    /**
     * Maybe auto-complete the order
     *
     * @param WC_Order $order Order object
     */
    private function maybe_autocomplete_order( $order ) {
        // Check if the order contains only virtual products
        $virtual_order = true;
        
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && ! $product->is_virtual() ) {
                $virtual_order = false;
                break;
            }
        }
        
        // Auto-complete virtual orders if enabled
        if ( $virtual_order && 'on' === $this->settings['autocomplete'] ) {
            $order->update_status( 
                'completed', 
                __( 'Order automatically completed - PPCC virtual product handling.', 'ppcc-pro' )
            );
            $order->reduce_order_stock();
        } 
        // Auto-process non-virtual orders if enabled
        elseif ( ! $virtual_order && 'on' === $this->settings['autoprocessing'] ) {
            $order->update_status(
                'processing',
                __( 'Order automatically processed - PPCC standard product handling.', 'ppcc-pro' )
            );
            $order->reduce_order_stock();
        }
    }
    
    /**
     * Suppress on-hold email for PayPal orders
     *
     * @param string $recipient Email recipient
     * @param WC_Order $order Order object
     * @return string
     */
    public function suppress_on_hold_email( $recipient, $order ) {
        if ( $this->is_paypal_gateway( $order->get_payment_method() ) ) {
            return '';
        }
        return $recipient;
    }
    
    /**
     * Check if payment method is a PayPal gateway
     *
     * @param string $payment_method Payment method
     * @return bool
     */
    private function is_paypal_gateway( $payment_method ) {
        return ppcc_is_paypal_gateway( $payment_method );
    }
}
