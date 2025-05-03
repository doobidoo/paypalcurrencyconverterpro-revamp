<?php
/**
 * Admin class
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class to handle admin interface
 */
class PPCC_Admin {
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
        // Admin scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        
        // Add meta box to display conversion details in order edit screen
        add_action( 'add_meta_boxes', array( $this, 'add_order_meta_boxes' ) );
        
        // Add dashboard widget
        add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
        
        // Add order columns
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'populate_order_column' ), 10, 2 );
        
        // AJAX handlers
        add_action( 'wp_ajax_ppcc_update_rate', array( $this, 'ajax_update_rate' ) );
        add_action( 'wp_ajax_ppcc_export_settings', array( $this, 'ajax_export_settings' ) );
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_assets( $hook ) {
        // Only load on our settings page or order page
        if ( $hook !== 'woocommerce_page_ppcc_settings' && $hook !== 'post.php' ) {
            return;
        }
        
        // Check if JS file exists
        $js_path = PPCC_PLUGIN_URL . 'assets/js/ppcc-admin.js';
        $css_path = PPCC_PLUGIN_URL . 'assets/css/ppcc-admin.css';
        
        // Make sure settings are available
        if ( !is_array($this->settings) || empty($this->settings) ) {
            $this->settings = array(
                'target_currency' => 'USD',
                'conversion_rate' => '1.0'
            );
        }
        
        // Register and enqueue scripts
        wp_register_script(
            'ppcc-admin',
            $js_path,
            array( 'jquery', 'jquery-ui-tabs' ),
            PPCC_VERSION,
            true
        );
        
        // Localize script with settings data
        $admin_data = array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'ppcc_update_rate_nonce' ), // Fix nonce name to match what AJAX handler expects
            'admin_nonce' => wp_create_nonce( 'ppcc_admin_nonce' ), // Keep this for backward compatibility
            'shop_currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'target_currency' => isset($this->settings['target_currency']) ? $this->settings['target_currency'] : 'USD',
            'conversion_rate' => isset($this->settings['conversion_rate']) ? $this->settings['conversion_rate'] : '1.0',
            'debug_enabled' => isset($this->settings['debug']) && $this->settings['debug'] === 'on',
            'enable_debug_url' => plugin_dir_url(dirname(__FILE__)) . 'enable-debug-simple.php',
            'settings_page_url' => admin_url( 'admin.php?page=ppcc_settings' ),
        );
        
        wp_localize_script( 'ppcc-admin', 'ppcc_admin_data', $admin_data );
        wp_enqueue_script( 'ppcc-admin' );
        
        // Add inline script for debug checkbox handling
        $debug_script = 'jQuery(document).ready(function($) {
            // Debug checkbox handler
            if ($("#ppcc-debug-checkbox").length > 0) {
                var checkboxState = $("#ppcc-debug-checkbox").is(":checked");
                console.log("Debug checkbox initial state:", checkboxState);
                
                // Listen for form submission
                $("#ppcc-settings-form").on("submit", function() {
                    console.log("Form submitted, debug checkbox state:", $("#ppcc-debug-checkbox").is(":checked"));
                    
                    // Store checkbox state in sessionStorage
                    sessionStorage.setItem("ppcc_debug_checkbox_state", $("#ppcc-debug-checkbox").is(":checked") ? "on" : "off");
                });
                
                // Check if we have just saved settings
                if (window.location.search.indexOf("settings-updated=true") > -1) {
                    var savedState = sessionStorage.getItem("ppcc_debug_checkbox_state");
                    var currentState = $("#ppcc-debug-checkbox").is(":checked") ? "on" : "off";
                    
                    console.log("After save - Saved state:", savedState, "Current state:", currentState);
                    
                    // If the states do not match, show a warning
                    if (savedState === "on" && currentState === "off") {
                        $("<div class=\'notice notice-warning is-dismissible\'><p>" + 
                          "The debug checkbox setting did not save correctly. Please use the Enable Debug Directly button below." + 
                          "</p></div>").insertAfter(".wrap h1:first");
                    }
                }
                
                // Add click handler for debug enable button
                $("#ppcc-debug-direct-enable").on("click", function(e) {
                    // We are letting the link redirect, so no need to do anything here
                    console.log("Debug direct enable clicked");
                });
            }
        });';
        
        wp_add_inline_script('ppcc-admin', $debug_script);
        
        // Register and enqueue styles
        wp_register_style(
            'ppcc-admin-style',
            $css_path,
            array(),
            PPCC_VERSION
        );
        wp_enqueue_style( 'ppcc-admin-style' );
    }
    
    /**
     * Add meta boxes to order edit screen
     */
    public function add_order_meta_boxes() {
        add_meta_box(
            'ppcc-order-conversion',
            __( 'Currency Conversion Details', 'ppcc-pro' ),
            array( $this, 'render_order_meta_box' ),
            'shop_order',
            'side',
            'high'
        );
    }
    
    /**
     * Render order meta box
     *
     * @param WP_Post $post Post object
     */
    public function render_order_meta_box( $post ) {
        $order = wc_get_order( $post->ID );
        
        // Check if this order was converted
        if ( ! $order->meta_exists( '_ppcc_original_currency' ) ) {
            echo '<p>' . __( 'No currency conversion was applied to this order.', 'ppcc-pro' ) . '</p>';
            return;
        }
        
        $original_currency = $order->get_meta( '_ppcc_original_currency' );
        $original_total = $order->get_meta( '_ppcc_original_total' );
        $conversion_rate = $order->get_meta( '_ppcc_conversion_rate' );
        $target_currency = $order->get_currency();
        
        ?>
        <div class="ppcc-order-conversion-details">
            <table class="widefat">
                <tbody>
                    <tr>
                        <th><?php _e( 'Original Currency:', 'ppcc-pro' ); ?></th>
                        <td><?php echo $original_currency; ?></td>
                    </tr>
                    <tr>
                        <th><?php _e( 'Original Total:', 'ppcc-pro' ); ?></th>
                        <td><?php echo wc_price( $original_total, array( 'currency' => $original_currency ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e( 'Conversion Rate:', 'ppcc-pro' ); ?></th>
                        <td><?php echo $conversion_rate; ?> <?php echo $target_currency . '/' . $original_currency; ?></td>
                    </tr>
                    <tr>
                        <th><?php _e( 'Converted Total:', 'ppcc-pro' ); ?></th>
                        <td><?php echo wc_price( $order->get_total(), array( 'currency' => $target_currency ) ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'ppcc_dashboard_widget',
            __( 'PayPal Currency Converter PRO', 'ppcc-pro' ),
            array( $this, 'render_dashboard_widget' )
        );
    }
    
    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        $shop_currency = get_woocommerce_currency();
        $target_currency = $this->settings['target_currency'];
        $conversion_rate = $this->settings['conversion_rate'];
        $last_update = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $this->settings['time_stamp'] );
        
        ?>
        <div class="ppcc-dashboard-widget">
            <div class="ppcc-widget-header">
                <h3><?php echo sprintf( __( 'Exchange Rate: %s to %s', 'ppcc-pro' ), $shop_currency, $target_currency ); ?></h3>
            </div>
            
            <div class="ppcc-widget-content">
                <div class="ppcc-rate-display">
                    <span class="ppcc-rate-value"><?php echo $conversion_rate; ?></span>
                    <span class="ppcc-rate-label"><?php echo $target_currency . '/' . $shop_currency; ?></span>
                </div>
                
                <div class="ppcc-last-update">
                    <span class="ppcc-update-label"><?php _e( 'Last updated:', 'ppcc-pro' ); ?></span>
                    <span class="ppcc-update-time"><?php echo $last_update; ?></span>
                </div>
                
                <div class="ppcc-widget-actions">
                    <a href="<?php echo admin_url( 'admin.php?page=ppcc_settings' ); ?>" class="button">
                        <?php _e( 'Settings', 'ppcc-pro' ); ?>
                    </a>
                    <button class="button button-primary ppcc-update-rate-btn" data-nonce="<?php echo wp_create_nonce( 'ppcc_update_rate_nonce' ); ?>">
                        <?php _e( 'Update Rate Now', 'ppcc-pro' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Add column to orders table
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_order_column( $columns ) {
        $new_columns = array();
        
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            
            // Add our column after order total
            if ( $key === 'order_total' ) {
                $new_columns['ppcc_conversion'] = __( 'Currency Conversion', 'ppcc-pro' );
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Populate custom order column
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public function populate_order_column( $column, $post_id ) {
        if ( $column !== 'ppcc_conversion' ) {
            return;
        }
        
        $order = wc_get_order( $post_id );
        
        // Check if this order was converted
        if ( ! $order->meta_exists( '_ppcc_original_currency' ) ) {
            echo '<span class="ppcc-no-conversion">' . __( 'N/A', 'ppcc-pro' ) . '</span>';
            return;
        }
        
        $original_currency = $order->get_meta( '_ppcc_original_currency' );
        $original_total = $order->get_meta( '_ppcc_original_total' );
        $conversion_rate = $order->get_meta( '_ppcc_conversion_rate' );
        $target_currency = $order->get_currency();
        
        echo '<div class="ppcc-order-conversion">';
        echo '<span class="ppcc-original">' . wc_price( $original_total, array( 'currency' => $original_currency ) ) . '</span>';
        echo '<span class="ppcc-arrow">→</span>';
        echo '<span class="ppcc-converted">' . wc_price( $order->get_total(), array( 'currency' => $target_currency ) ) . '</span>';
        echo '<div class="ppcc-rate">' . sprintf( __( 'Rate: %s', 'ppcc-pro' ), $conversion_rate ) . '</div>';
        echo '</div>';
    }
    
    /**
     * AJAX handler for updating exchange rate
     */
    public function ajax_update_rate() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ppcc_update_rate_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed', 'ppcc-pro' ) ) );
        }
        
        // Check user permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to do this', 'ppcc-pro' ) ) );
        }
        
        // Enable logging to capture any issues
        update_ppcc_option('ppcc_settings_debug_mode', 'on');
        
        // Update the exchange rate
        $exchange_rates = new PPCC_Exchange_Rates( $this->settings );
        
        // Start output buffer to capture any PHP warnings or notices
        ob_start();
        $new_rate = $exchange_rates->update_exchange_rate();
        $errors = ob_get_clean();
        
        if ( $new_rate ) {
            // Get updated settings
            $updated_settings = get_ppcc_option( 'ppcc_settings' );
            
            // Preserve full precision in the response
            wp_send_json_success( array(
                'message' => __( 'Exchange rate updated successfully', 'ppcc-pro' ),
                'new_rate' => number_format((float)$new_rate, 8, '.', ''), // Ensure we keep 8 decimal places
                'last_update' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated_settings['time_stamp'] )
            ) );
        } else {
            // Check WooCommerce logs for more detailed error info
            $log_message = '';
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                // Get the latest errors from logs if possible
                // This is just a suggestion - actual implementation would depend on your logging system
            }
            
            $error_message = __( 'Failed to update exchange rate. ', 'ppcc-pro' );
            $error_message .= __( 'Please check your API settings and ensure your API key is correct. ', 'ppcc-pro' );
            
            // Add provider-specific guidance
            $api_selection = $this->settings['api_selection'];
            if ( $api_selection === 'fixer_io_api_id' ) {
                $error_message .= __( 'For Fixer.io: Make sure you\'re using HTTP (not HTTPS) and be aware that free plans only support EUR as base currency. ', 'ppcc-pro' );
            } elseif ( $api_selection === 'currencyconverterapi' ) {
                $error_message .= __( 'For CurrencyConverterAPI: This service now requires a paid subscription. ', 'ppcc-pro' );
            }
            
            $error_message .= __( 'Check the WooCommerce logs for more details.', 'ppcc-pro' );
            
            // Include any PHP errors captured during the process
            if ( !empty( $errors ) ) {
                $error_message .= __( ' Debug info: ', 'ppcc-pro' ) . esc_html( $errors );
            }
            
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }
    
    /**
     * AJAX handler for exporting settings
     */
    public function ajax_export_settings() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ppcc_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed', 'ppcc-pro' ) ) );
        }
        
        // Check user permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to do this', 'ppcc-pro' ) ) );
        }
        
        // Get settings
        $settings = get_ppcc_option( 'ppcc_settings' );
        
        if ( $settings ) {
            wp_send_json_success( array(
                'message' => __( 'Settings exported successfully', 'ppcc-pro' ),
                'settings' => $settings
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to export settings.', 'ppcc-pro' ) ) );
        }
    }
    
    /**
     * Render settings page
     */
    public static function settings_page() {
        // Check if we need to reset settings
        if ( isset( $_GET['reset'] ) && $_GET['reset'] == 1 && current_user_can( 'manage_options' ) ) {
            // Get default settings from PPCC_Core
            if ( class_exists( 'PPCC_Core' ) ) {
                PPCC_Core::set_default_settings();
                wp_redirect( admin_url( 'admin.php?page=ppcc_settings&reset-complete=1' ) );
                exit;
            }
        }
        
        // Get current settings
        $settings = get_ppcc_option( 'ppcc_settings' );
        
        // Create instance of the class
        $admin = new self( $settings );
        
        // Render the settings page
        $admin->render_settings();
    }
    
    /**
     * Render settings page content
     */
    private function render_settings() {
        ?>
        <div class="wrap woocommerce">
            <h1><?php _e( 'PayPal Currency Converter PRO', 'ppcc-pro' ); ?></h1>
            
            <?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e( 'Settings saved successfully.', 'ppcc-pro' ); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ( isset( $_GET['reset-complete'] ) && $_GET['reset-complete'] ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e( 'Settings have been reset to default values.', 'ppcc-pro' ); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php" id="ppcc-settings-form">
                <?php settings_fields( 'ppcc_settings' ); ?>
                
                <div id="ppcc-settings-tabs" class="nav-tab-wrapper">
                    <a href="#general" class="nav-tab nav-tab-active"><?php _e( 'General Settings', 'ppcc-pro' ); ?></a>
                    <a href="#exchange-rates" class="nav-tab"><?php _e( 'Exchange Rates', 'ppcc-pro' ); ?></a>
                    <a href="#handling-fees" class="nav-tab"><?php _e( 'Handling Fees', 'ppcc-pro' ); ?></a>
                    <a href="#order-processing" class="nav-tab"><?php _e( 'Order Processing', 'ppcc-pro' ); ?></a>
                    <a href="#advanced" class="nav-tab"><?php _e( 'Advanced', 'ppcc-pro' ); ?></a>
                    <a href="#help" class="nav-tab"><?php _e( 'Help & Info', 'ppcc-pro' ); ?></a>
                </div>
                
                <div class="ppcc-tab-content">
                    <!-- General Settings Tab -->
                    <div id="general" class="ppcc-tab active">
                        <h2><?php _e( 'General Settings', 'ppcc-pro' ); ?></h2>
                        <p><?php _e( 'Configure the basic settings for currency conversion.', 'ppcc-pro' ); ?></p>
                        
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Source Currency', 'ppcc-pro' ); ?></th>
                                <td>
                                    <input type="text" value="<?php echo esc_attr( get_woocommerce_currency() ); ?>" disabled />
                                    <p class="description"><?php _e( 'Your WooCommerce store currency. This cannot be changed here.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Target Currency', 'ppcc-pro' ); ?></th>
                                <td>
                                    <select name="ppcc_settings[target_currency]" id="ppcc-target-currency">
                                        <?php foreach ( $this->pp_currencies as $currency ) : ?>
                                            <option value="<?php echo esc_attr( $currency ); ?>" <?php selected( $this->settings['target_currency'], $currency ); ?>>
                                                <?php echo esc_html( $currency ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php _e( 'Select the target currency for PayPal payments.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Custom Currency', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[ppcc_use_custom_currency]" value="on" <?php checked( $this->settings['ppcc_use_custom_currency'], 'on' ); ?> />
                                        <?php _e( 'Use custom currency', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'Enable this to use a custom currency in your store.', 'ppcc-pro' ); ?></p>
                                    
                                    <div class="ppcc-custom-currency-fields" <?php echo $this->settings['ppcc_use_custom_currency'] !== 'on' ? 'style="display: none;"' : ''; ?>>
                                        <p>
                                            <label><?php _e( 'Currency Code:', 'ppcc-pro' ); ?></label>
                                            <input type="text" name="ppcc_settings[ppcc_custom_currency_code]" value="<?php echo esc_attr( $this->settings['ppcc_custom_currency_code'] ); ?>" placeholder="XYZ" maxlength="3" style="width: 60px;" />
                                            <span class="description"><?php _e( 'Enter a 3-letter ISO currency code.', 'ppcc-pro' ); ?></span>
                                        </p>
                                        
                                        <p>
                                            <label><?php _e( 'Currency Symbol:', 'ppcc-pro' ); ?></label>
                                            <input type="text" name="ppcc_settings[ppcc_custom_currency_symbol]" value="<?php echo esc_attr( $this->settings['ppcc_custom_currency_symbol'] ); ?>" placeholder="¤" style="width: 60px;" />
                                        </p>
                                        
                                        <p>
                                            <label><?php _e( 'Currency Name:', 'ppcc-pro' ); ?></label>
                                            <input type="text" name="ppcc_settings[ppcc_custom_currency_name]" value="<?php echo esc_attr( $this->settings['ppcc_custom_currency_name'] ); ?>" placeholder="Custom Currency" />
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Conversion Rate', 'ppcc-pro' ); ?></th>
                                <td>
                                    <input type="number" name="ppcc_settings[conversion_rate]" id="ppcc-conversion-rate" value="<?php echo esc_attr( $this->settings['conversion_rate'] ); ?>" step="any" min="0.00000001" />
                                    <button type="button" id="ppcc-fetch-rate" class="button"><?php _e( 'Fetch Current Rate', 'ppcc-pro' ); ?></button>
                                    <p class="description">
                                        <?php 
                                        printf( 
                                            __( 'Current conversion rate: 1 %s = %s %s', 'ppcc-pro' ), 
                                            get_woocommerce_currency(),
                                            $this->settings['conversion_rate'],
                                            $this->settings['target_currency']
                                        ); 
                                        ?>
                                    </p>
                                    <p class="description">
                                        <?php _e( 'Last updated:', 'ppcc-pro' ); ?> 
                                        <?php echo date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $this->settings['time_stamp'] ); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Precision', 'ppcc-pro' ); ?></th>
                                <td>
                                    <select name="ppcc_settings[precision]">
                                        <?php for ( $i = 2; $i <= 8; $i++ ) : ?>
                                            <option value="<?php echo esc_attr( $i ); ?>" <?php selected( $this->settings['precision'], $i ); ?>>
                                                <?php echo esc_html( $i ); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <p class="description"><?php _e( 'Select the decimal precision for the conversion rate.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Exchange Rates Tab -->
                    <div id="exchange-rates" class="ppcc-tab">
                        <h2><?php _e( 'Exchange Rate Providers', 'ppcc-pro' ); ?></h2>
                        <p><?php _e( 'Select an exchange rate provider and configure automatic updates.', 'ppcc-pro' ); ?></p>
                        
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Exchange Rate Provider', 'ppcc-pro' ); ?></th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text"><?php _e( 'Exchange Rate Provider', 'ppcc-pro' ); ?></legend>
                                        
                                        <p>
                                            <label>
                                                <input type="radio" name="ppcc_settings[api_selection]" value="custom" <?php checked( $this->settings['api_selection'], 'custom' ); ?> />
                                                <?php _e( 'Custom Exchange Rate', 'ppcc-pro' ); ?>
                                            </label>
                                            <p class="description"><?php _e( 'Use the manually entered conversion rate.', 'ppcc-pro' ); ?></p>
                                        </p>
                                        
                                        <p>
                                            <label>
                                                <input type="radio" name="ppcc_settings[api_selection]" value="currencyconverterapi" <?php checked( $this->settings['api_selection'], 'currencyconverterapi' ); ?> />
                                                <?php _e( 'Currency Converter API', 'ppcc-pro' ); ?>
                                            </label>
                                            <input type="text" name="ppcc_settings[currencyconverterapi_id]" value="<?php echo esc_attr( $this->settings['currencyconverterapi_id'] ); ?>" placeholder="<?php _e( 'API Key', 'ppcc-pro' ); ?>" class="regular-text" />
                                            <p class="description">
                                                <strong><?php _e( 'Note: This service now requires a paid subscription.', 'ppcc-pro' ); ?></strong>
                                                <?php _e( 'Get your API key at', 'ppcc-pro' ); ?> 
                                                <a href="https://www.currencyconverterapi.com/" target="_blank">currencyconverterapi.com</a>
                                            </p>
                                        </p>
                                        
                                        <p>
                                            <label>
                                                <input type="radio" name="ppcc_settings[api_selection]" value="fixer_io_api_id" <?php checked( $this->settings['api_selection'], 'fixer_io_api_id' ); ?> />
                                                <?php _e( 'Fixer.io (European Central Bank)', 'ppcc-pro' ); ?>
                                            </label>
                                            <input type="text" name="ppcc_settings[fixer_io_api_id]" value="<?php echo esc_attr( $this->settings['fixer_io_api_id'] ); ?>" placeholder="<?php _e( 'API Key', 'ppcc-pro' ); ?>" class="regular-text" />
                                            <p class="description">
                                                <?php _e( 'Get your API key at', 'ppcc-pro' ); ?> 
                                                <a href="https://fixer.io/" target="_blank">fixer.io</a>
                                                <br><strong><?php _e( 'Note: Free Fixer.io plans have two limitations:', 'ppcc-pro' ); ?></strong>
                                                <br>1. <?php _e( 'They only support HTTP (not HTTPS)', 'ppcc-pro' ); ?>
                                                <br>2. <?php _e( 'They only use EUR as the base currency (conversions are calculated via EUR)', 'ppcc-pro' ); ?>
                                            </p>
                                        </p>
                                        
                                        <p>
                                            <label>
                                                <input type="radio" name="ppcc_settings[api_selection]" value="oer_api_id" <?php checked( $this->settings['api_selection'], 'oer_api_id' ); ?> />
                                                <?php _e( 'Open Exchange Rates', 'ppcc-pro' ); ?>
                                            </label>
                                            <input type="text" name="ppcc_settings[oer_api_id]" value="<?php echo esc_attr( $this->settings['oer_api_id'] ); ?>" placeholder="<?php _e( 'App ID', 'ppcc-pro' ); ?>" class="regular-text" />
                                            <p class="description">
                                                <?php _e( 'Get your App ID at', 'ppcc-pro' ); ?> 
                                                <a href="https://openexchangerates.org/" target="_blank">openexchangerates.org</a>
                                            </p>
                                        </p>
                                        
                                        <p>
                                            <label>
                                                <input type="radio" name="ppcc_settings[api_selection]" value="xignite" <?php checked( $this->settings['api_selection'], 'xignite' ); ?> />
                                                <?php _e( 'Xignite', 'ppcc-pro' ); ?>
                                            </label>
                                            <input type="text" name="ppcc_settings[xignite_api_id]" value="<?php echo esc_attr( $this->settings['xignite_api_id'] ); ?>" placeholder="<?php _e( 'API Token', 'ppcc-pro' ); ?>" class="regular-text" />
                                            <p class="description">
                                                <?php _e( 'Get your API token at', 'ppcc-pro' ); ?> 
                                                <a href="https://www.xignite.com/" target="_blank">xignite.com</a>
                                            </p>
                                        </p>
                                        
                                        <p>
                                            <label>
                                                <input type="radio" name="ppcc_settings[api_selection]" value="apilayer" <?php checked( $this->settings['api_selection'], 'apilayer' ); ?> />
                                                <?php _e( 'Currency Layer API', 'ppcc-pro' ); ?>
                                            </label>
                                            <input type="text" name="ppcc_settings[apilayer_api_id]" value="<?php echo esc_attr( $this->settings['apilayer_api_id'] ); ?>" placeholder="<?php _e( 'Access Key', 'ppcc-pro' ); ?>" class="regular-text" />
                                            <p class="description">
                                                <?php _e( 'Get your access key at', 'ppcc-pro' ); ?> 
                                                <a href="https://currencylayer.com/" target="_blank">currencylayer.com</a>
                                            </p>
                                        </p>
                                        
                                        <p>
                                            <label>
                                                <input type="radio" name="ppcc_settings[api_selection]" value="custom_api" <?php checked( $this->settings['api_selection'], 'custom_api' ); ?> />
                                                <?php _e( 'Custom API', 'ppcc-pro' ); ?>
                                            </label>
                                            <p class="description">
                                                <?php _e( 'Use a custom API defined in ppcc_custom_api.php file.', 'ppcc-pro' ); ?>
                                            </p>
                                        </p>
                                    </fieldset>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Automatic Updates', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[auto_update]" value="on" <?php checked( $this->settings['auto_update'], 'on' ); ?> />
                                        <?php _e( 'Enable automatic exchange rate updates', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, the exchange rate will be automatically updated based on the schedule.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Logging', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[exrlog]" value="on" <?php checked( $this->settings['exrlog'], 'on' ); ?> />
                                        <?php _e( 'Enable logging of exchange rate updates', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, logs will be written to the WooCommerce log file and email notifications will be sent.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'AJAX URL for Cron Job', 'ppcc-pro' ); ?></th>
                                <td>
                                    <code><?php echo site_url(); ?>/wp-admin/admin-ajax.php?action=ppcc&ppcc_function=cexr_update</code>
                                    <p class="description"><?php _e( 'You can use this URL with an external cron job service to update exchange rates.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'WordPress Cron Hook', 'ppcc-pro' ); ?></th>
                                <td>
                                    <code>ppcc_cexr_update</code>
                                    <p class="description"><?php _e( 'You can use this hook with a WordPress cron plugin.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Handling Fees Tab -->
                    <div id="handling-fees" class="ppcc-tab">
                        <h2><?php _e( 'Handling Fees', 'ppcc-pro' ); ?></h2>
                        <p><?php _e( 'Configure handling fees and thresholds.', 'ppcc-pro' ); ?></p>
                        
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Percentage Fee', 'ppcc-pro' ); ?></th>
                                <td>
                                    <input type="number" name="ppcc_settings[handling_percentage]" value="<?php echo esc_attr( $this->settings['handling_percentage'] ); ?>" step="0.01" min="-10" max="10" style="width: 80px;" /> %
                                    <p class="description"><?php _e( 'Add a percentage-based handling fee (or discount with negative values).', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Fixed Fee', 'ppcc-pro' ); ?></th>
                                <td>
                                    <input type="number" name="ppcc_settings[handling_amount]" value="<?php echo esc_attr( $this->settings['handling_amount'] ); ?>" step="0.01" min="-10000" max="10000" style="width: 80px;" />
                                    <?php echo get_woocommerce_currency_symbol(); ?>
                                    <p class="description"><?php _e( 'Add a fixed handling fee (or discount with negative values).', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Fee Title', 'ppcc-pro' ); ?></th>
                                <td>
                                    <input type="text" name="ppcc_settings[handling_title]" value="<?php echo esc_attr( $this->settings['handling_title'] ); ?>" class="regular-text" />
                                    <p class="description"><?php _e( 'The title for the handling fee as displayed to customers.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Apply to Shipping', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[shipping_handling_fee]" value="on" <?php checked( $this->settings['shipping_handling_fee'], 'on' ); ?> />
                                        <?php _e( 'Include shipping cost in percentage calculation', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, the percentage handling fee will also be applied to shipping costs.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Taxable', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[handling_taxable]" value="on" <?php checked( $this->settings['handling_taxable'], 'on' ); ?> />
                                        <?php _e( 'Apply taxes to handling fee', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, taxes will be applied to the handling fee.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <!-- Order Processing Tab -->
                    <div id="order-processing" class="ppcc-tab">
                        <h2><?php _e( 'Order Processing', 'ppcc-pro' ); ?></h2>
                        <p><?php _e( 'Configure how orders are processed after payment.', 'ppcc-pro' ); ?></p>
                        
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Auto-Complete Virtual Orders', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[autocomplete]" value="on" <?php checked( $this->settings['autocomplete'], 'on' ); ?> />
                                        <?php _e( 'Automatically complete orders containing only virtual products', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, orders with only virtual products will be automatically marked as completed after payment.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Auto-Process Standard Orders', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[autoprocessing]" value="on" <?php checked( $this->settings['autoprocessing'], 'on' ); ?> />
                                        <?php _e( 'Automatically process orders containing physical products', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, orders with physical products will be automatically marked as processing after payment.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Suppress On-Hold Emails', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[suppress_order_on_hold_email]" value="on" <?php checked( $this->settings['suppress_order_on_hold_email'], 'on' ); ?> />
                                        <?php _e( 'Suppress the order on-hold email notifications for PayPal orders', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, on-hold email notifications for PayPal orders will be suppressed.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Email Order Completed Note', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[email_order_completed_note]" value="on" <?php checked( $this->settings['email_order_completed_note'], 'on' ); ?> />
                                        <?php _e( 'Add conversion details to order emails', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, conversion details will be added to order emails.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Email Note Template', 'ppcc-pro' ); ?></th>
                                <td>
                                    <textarea name="ppcc_settings[order_email_note]" class="large-text" rows="4"><?php echo esc_textarea( $this->settings['order_email_note'] ); ?></textarea>
                                    <p class="description">
                                        <?php _e( 'Template for the conversion note in emails. Available placeholders:', 'ppcc-pro' ); ?><br>
                                        %s = <?php _e( 'Conversion rate', 'ppcc-pro' ); ?><br>
                                        %s = <?php _e( 'Target currency', 'ppcc-pro' ); ?><br>
                                        %s = <?php _e( 'Original currency', 'ppcc-pro' ); ?><br>
                                        %s = <?php _e( 'Converted total', 'ppcc-pro' ); ?><br>
                                        %s = <?php _e( 'Handling percentage', 'ppcc-pro' ); ?><br>
                                        %s = <?php _e( 'Handling amount', 'ppcc-pro' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <!-- Advanced Tab -->
                    <div id="advanced" class="ppcc-tab">
                        <h2><?php _e( 'Advanced Settings', 'ppcc-pro' ); ?></h2>
                        <p><?php _e( 'Configure advanced settings for the plugin.', 'ppcc-pro' ); ?></p>
                        
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Payment Gateway Integration', 'ppcc-pro' ); ?></th>
                                <td>
                                    <p><?php _e( 'PayPal Currency Converter PRO 4.0 works with the following PayPal gateways:', 'ppcc-pro' ); ?></p>
                                    <ul class="ppcc-gateway-list">
                                        <li>PayPal Standard</li>
                                        <li>PayPal Express Checkout</li>
                                        <li>PayPal Digital Goods</li>
                                        <li>PayPal Advanced</li>
                                        <li>PayPal Commerce Platform</li>
                                    </ul>
                                    <p class="description"><?php _e( 'The plugin automatically detects these gateways and applies currency conversion.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Plugin Data', 'ppcc-pro' ); ?></th>
                                <td>
                                    <button type="button" id="ppcc-export-settings" class="button"><?php _e( 'Export Settings', 'ppcc-pro' ); ?></button>
                                    <button type="button" id="ppcc-reset-settings" class="button"><?php _e( 'Reset to Defaults', 'ppcc-pro' ); ?></button>
                                    <p class="description"><?php _e( 'Export your settings or reset them to default values.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Debug Mode', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[debug]" value="on" <?php checked( isset($this->settings['debug']) ? $this->settings['debug'] : 'off', 'on' ); ?> id="ppcc-debug-checkbox" />
                                        <?php _e( 'Enable debug mode', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, detailed logs will be written to help troubleshoot any issues.', 'ppcc-pro' ); ?></p>
                                    <?php if (isset($this->settings['debug']) && $this->settings['debug'] === 'on'): ?>
                                        <div style="background: #dff0d8; color: #3c763d; padding: 10px; margin-top: 10px; border-radius: 4px;">
                                            <strong><?php _e( 'Debug mode is currently ENABLED.', 'ppcc-pro' ); ?></strong>
                                            <?php _e( 'Debug logs will be written to the WooCommerce logs directory.', 'ppcc-pro' ); ?>
                                        </div>
                                    <?php else: ?>
                                        <p>
                                            <a href="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'enable-debug-simple.php'); ?>" class="button button-secondary" id="ppcc-debug-direct-enable">
                                                <?php _e( 'Enable Debug Directly', 'ppcc-pro' ); ?>
                                            </a>
                                            <span class="description"><?php _e( 'Use this if the checkbox doesn\'t save correctly.', 'ppcc-pro' ); ?></span>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e( 'Keep Data on Uninstall', 'ppcc-pro' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ppcc_settings[keep_data_on_uninstall]" value="on" <?php checked( isset($this->settings['keep_data_on_uninstall']) ? $this->settings['keep_data_on_uninstall'] : 'off', 'on' ); ?> />
                                        <?php _e( 'Keep plugin settings when uninstalling', 'ppcc-pro' ); ?>
                                    </label>
                                    <p class="description"><?php _e( 'When enabled, your settings will be preserved if you uninstall the plugin. Useful if you plan to reinstall later.', 'ppcc-pro' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Help & Info Tab -->
                    <div id="help" class="ppcc-tab">
                        <h2><?php _e( 'Help & Information', 'ppcc-pro' ); ?></h2>
                        
                        <div class="ppcc-info-section">
                            <h3><?php _e( 'About PayPal Currency Converter PRO', 'ppcc-pro' ); ?></h3>
                            <p><?php _e( 'PayPal Currency Converter PRO for WooCommerce 4.0 is a completely revamped version that converts your orders to PayPal-supported currencies during checkout.', 'ppcc-pro' ); ?></p>
                            <p><?php _e( 'This approach ensures compatibility with PayPal\'s API changes and provides a more reliable payment experience.', 'ppcc-pro' ); ?></p>
                            <p>
                                <strong><?php _e( 'Version:', 'ppcc-pro' ); ?></strong> <?php echo PPCC_VERSION; ?><br>
                                <strong><?php _e( 'Author:', 'ppcc-pro' ); ?></strong> Intelligent-IT<br>
                                <strong><?php _e( 'Website:', 'ppcc-pro' ); ?></strong> <a href="https://codecanyon.net/user/intelligent-it" target="_blank">codecanyon.net/user/intelligent-it</a>
                            </p>
                        </div>
                        
                        <div class="ppcc-info-section">
                            <h3><?php _e( 'How It Works', 'ppcc-pro' ); ?></h3>
                            <ol>
                                <li><?php _e( 'When a customer selects PayPal as their payment method, the plugin converts the order to the target currency.', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'The original currency and amount are stored in the order metadata for reference.', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'The customer is sent to PayPal with the converted currency and amount.', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'After payment, the order is processed according to your settings.', 'ppcc-pro' ); ?></li>
                            </ol>
                        </div>
                        
                        <div class="ppcc-info-section">
                            <h3><?php _e( 'Key Features', 'ppcc-pro' ); ?></h3>
                            <ul>
                                <li><?php _e( 'Convert any currency to PayPal-supported currencies', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'Support for custom currencies', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'Multiple exchange rate providers', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'Automatic exchange rate updates', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'Handling fees with percentage and fixed amounts', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'Order status management', 'ppcc-pro' ); ?></li>
                                <li><?php _e( 'Detailed currency conversion information', 'ppcc-pro' ); ?></li>
                            </ul>
                        </div>
                        
                        <div class="ppcc-info-section">
                            <h3><?php _e( 'Support', 'ppcc-pro' ); ?></h3>
                            <p><?php _e( 'For support, please contact us through CodeCanyon.', 'ppcc-pro' ); ?></p>
                            <p><a href="https://codecanyon.net/item/paypal-currency-converter-pro-for-woocommerce/6343249/support" target="_blank" class="button button-primary"><?php _e( 'Get Support', 'ppcc-pro' ); ?></a></p>
                        </div>
                        
                        <div class="ppcc-info-section">
                            <h3><?php _e( 'System Information', 'ppcc-pro' ); ?></h3>
                            <table class="widefat" style="width: auto;">
                                <tbody>
                                    <tr>
                                        <td><strong><?php _e( 'WordPress Version:', 'ppcc-pro' ); ?></strong></td>
                                        <td><?php echo get_bloginfo( 'version' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e( 'WooCommerce Version:', 'ppcc-pro' ); ?></strong></td>
                                        <td><?php echo WC()->version; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e( 'PHP Version:', 'ppcc-pro' ); ?></strong></td>
                                        <td><?php echo phpversion(); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e( 'cURL Enabled:', 'ppcc-pro' ); ?></strong></td>
                                        <td><?php echo function_exists( 'curl_init' ) ? __( 'Yes', 'ppcc-pro' ) : __( 'No', 'ppcc-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e( 'Plugin Version:', 'ppcc-pro' ); ?></strong></td>
                                        <td><?php echo PPCC_VERSION; ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e( 'Save Changes', 'ppcc-pro' ); ?>">
                </p>
            </form>
        </div>
        <?php
    }
}
