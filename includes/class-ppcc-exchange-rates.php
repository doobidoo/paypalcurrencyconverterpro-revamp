<?php
/**
 * Exchange rates class
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class to handle exchange rates
 */
class PPCC_Exchange_Rates {
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
        $this->settings = $settings;
        
        // Set up scheduled event if enabled
        if ( 'on' === $this->settings['auto_update'] ) {
            $this->maybe_setup_scheduled_update();
        }
    }
    
    /**
     * Set up scheduled update if not already set
     */
    private function maybe_setup_scheduled_update() {
        if ( ! wp_next_scheduled( 'ppcc_cexr_update' ) ) {
            wp_schedule_event( time(), 'daily', 'ppcc_cexr_update' );
        }
    }
    
    /**
     * Update exchange rate
     *
     * @return bool|string Exchange rate on success, false on error
     */
    public function update_exchange_rate() {
        $shop_currency = get_woocommerce_currency();
        $target_currency = $this->settings['target_currency'];
        
        // Log what we're about to do
        $this->log_message( "Attempting to update exchange rate from $shop_currency to $target_currency using provider: {$this->settings['api_selection']}" );
        
        // Get exchange rate from selected provider
        $exchange_rate = $this->get_exchange_rate( $shop_currency, $target_currency );
        
        if ( $exchange_rate ) {
            // Just for debugging, log the actual value
            $this->log_message( "Retrieved exchange rate: $exchange_rate" );
            
            // For some providers, a rate of exactly 1 might indicate an error
            // But only if the currencies are different
            if ( $exchange_rate === 1 && $shop_currency !== $target_currency ) {
                $this->log_message( "Warning: Exchange rate is exactly 1.0 between different currencies ($shop_currency and $target_currency). This might indicate an API error." );
            }
            
            // Update settings with new rate
            $this->settings['conversion_rate'] = $exchange_rate;
            $this->settings['time_stamp'] = current_time( 'timestamp' );
            
            // Update options
            update_ppcc_option( 'ppcc_settings', $this->settings );
            
            // Log update if enabled
            if ( 'on' === $this->settings['exrlog'] ) {
                $this->log_update( $shop_currency, $target_currency, $exchange_rate );
            }
            
            return $exchange_rate;
        } else {
            $this->log_error( "Failed to retrieve exchange rate from {$this->settings['api_selection']} provider" );
            return false;
        }
    }
    
    /**
     * Get exchange rate from selected provider with fallback
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @return float|bool Exchange rate on success, false on error
     */
    public function get_exchange_rate( $from, $to ) {
        $precision = $this->settings['precision'];
        $rate = false;
        $fallback_providers = array();
        
        // Get primary provider
        $primary_provider = $this->settings['api_selection'];
        
        // If primary provider isn't 'custom', create fallback list
        if ($primary_provider !== 'custom') {
            // Only add providers that have API keys configured
            if (!empty($this->settings['oer_api_id']) && $primary_provider !== 'oer_api_id') {
                $fallback_providers[] = 'oer_api_id';
            }
            if (!empty($this->settings['fixer_io_api_id']) && $primary_provider !== 'fixer_io_api_id') {
                $fallback_providers[] = 'fixer_io_api_id';
            }
            if (!empty($this->settings['currencyconverterapi_id']) && $primary_provider !== 'currencyconverterapi') {
                $fallback_providers[] = 'currencyconverterapi';
            }
            if (!empty($this->settings['xignite_api_id']) && $primary_provider !== 'xignite') {
                $fallback_providers[] = 'xignite';
            }
            if (!empty($this->settings['apilayer_api_id']) && $primary_provider !== 'apilayer') {
                $fallback_providers[] = 'apilayer';
            }
        }
        
        // First try the primary provider
        $rate = $this->get_rate_from_provider($primary_provider, $from, $to, $precision);
        
        // If primary provider failed, try fallbacks
        if ($rate === false && !empty($fallback_providers)) {
            $this->log_message('Primary exchange rate provider failed, trying fallbacks');
            
            foreach ($fallback_providers as $provider) {
                $rate = $this->get_rate_from_provider($provider, $from, $to, $precision);
                if ($rate !== false) {
                    $this->log_message('Successfully fetched exchange rate from fallback provider: ' . $provider);
                    break;
                }
            }
        }
        
        // If all providers failed, use the last saved rate
        if ($rate === false && isset($this->settings['conversion_rate']) && $this->settings['conversion_rate'] > 0) {
            $this->log_message('All exchange rate providers failed, using last saved rate');
            $rate = $this->settings['conversion_rate'];
        }
        
        return $rate;
    }
    
    /**
     * Get exchange rate from specific provider
     *
     * @param string $provider Provider key
     * @param string $from Source currency
     * @param string $to Target currency
     * @param int $precision Decimal precision
     * @return float|bool Exchange rate on success, false on error
     */
    private function get_rate_from_provider($provider, $from, $to, $precision) {
        switch ($provider) {
            case 'oer_api_id':
                return $this->get_rate_from_openexchangerates($from, $to, $precision);
                
            case 'fixer_io_api_id':
                return $this->get_rate_from_fixer($from, $to, $precision);
                
            case 'xignite':
                return $this->get_rate_from_xignite($from, $to, $precision);
                
            case 'apilayer':
                return $this->get_rate_from_apilayer($from, $to, $precision);
                
            case 'currencyconverterapi':
                return $this->get_rate_from_currencyconverterapi($from, $to, $precision);
                
            case 'custom':
                return $this->settings['conversion_rate'];
                
            case 'custom_api':
                if (file_exists(PPCC_PLUGIN_DIR . 'includes/ppcc_custom_api.php')) {
                    include_once PPCC_PLUGIN_DIR . 'includes/ppcc_custom_api.php';
                    if (function_exists('ppcc_custom_api')) {
                        return ppcc_custom_api($from, $to, $precision);
                    }
                }
                $this->log_error('Custom API file not found or ppcc_custom_api function does not exist');
                return false;
                
            default:
                $this->log_error('No valid exchange rate source selected');
                return false;
        }
    }
    
    /**
     * Get exchange rate from OpenExchangeRates
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @param int $precision Decimal precision
     * @return float|bool Exchange rate on success, false on error
     */
    private function get_rate_from_openexchangerates( $from, $to, $precision ) {
        if ( empty( $this->settings['oer_api_id'] ) ) {
            $this->log_error( 'OpenExchangeRates API ID not provided' );
            return false;
        }
        
        $url = 'https://openexchangerates.org/api/latest.json?app_id=' . $this->settings['oer_api_id'];
        $response = $this->fetch_remote_data( $url );
        
        if ( ! $response ) {
            return false;
        }
        
        $data = json_decode( $response );
        
        if ( isset( $data->error ) ) {
            $this->log_error( 'OpenExchangeRates API error: ' . $data->description );
            return false;
        }
        
        if ( ! isset( $data->rates->$from ) || ! isset( $data->rates->$to ) ) {
            $this->log_error( "OpenExchangeRates API missing rates for $from or $to" );
            return false;
        }
        
        return round( $data->rates->$to / $data->rates->$from, $precision );
    }
    
    /**
     * Get exchange rate from Fixer.io
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @param int $precision Decimal precision
     * @return float|bool Exchange rate on success, false on error
     */
    private function get_rate_from_fixer( $from, $to, $precision ) {
        if ( empty( $this->settings['fixer_io_api_id'] ) ) {
            $this->log_error( 'Fixer.io API access key not provided' );
            return false;
        }
        
        // Important: Free Fixer.io plans only support HTTP (not HTTPS) and EUR as base currency
        $base_url = 'http://data.fixer.io/api/latest';
        
        // Build the request URL - add all required parameters
        $url = $base_url . '?access_key=' . $this->settings['fixer_io_api_id'] . '&format=1';
        
        // Free plan always uses EUR as base, so ensure both currencies are in the response
        // We'll do the conversion calculation ourselves
        $symbols = $from . ',' . $to;
        if ($from !== 'EUR' && $to !== 'EUR') {
            $symbols .= ',EUR'; // Make sure EUR is included if neither from nor to is EUR
        }
        $url .= '&symbols=' . urlencode($symbols);
        
        // Make the API request
        $this->log_message( "Requesting Fixer.io exchange rates with URL: $url" );
        $response = $this->fetch_remote_data( $url );
        
        if ( ! $response ) {
            $this->log_error( 'Failed to get response from Fixer.io API' );
            return false;
        }
        
        // Decode JSON response
        $data = json_decode( $response );
        
        // Check for API errors
        if ( ! isset( $data->success ) || $data->success !== true ) {
            $error_info = isset( $data->error->info ) ? $data->error->info : 'Unknown error';
            $error_code = isset( $data->error->code ) ? $data->error->code : 'unknown';
            $error_type = isset( $data->error->type ) ? $data->error->type : 'unknown';
            
            $this->log_error( "Fixer.io API error ($error_code - $error_type): $error_info" );
            
            // Handle common Fixer.io API errors
            switch ( $error_code ) {
                case 101:
                    $this->log_error( 'Invalid API access key or not subscribed. Please check your Fixer.io subscription.' );
                    break;
                case 104:
                    $this->log_error( 'Your Fixer.io subscription plan does not support this API endpoint.' );
                    break;
                case 105:
                    $this->log_error( 'Your Fixer.io subscription plan does not support HTTPS encryption. Free plans can only use HTTP.' );
                    // Try again with HTTP if we used HTTPS
                    if ( strpos( $url, 'https://' ) === 0 ) {
                        $this->log_error( 'Retrying with HTTP protocol instead of HTTPS. Note: Free Fixer.io plans require HTTP protocol.' );
                        $url = str_replace( 'https://', 'http://', $url );
                        return $this->get_rate_from_fixer( $from, $to, $precision );
                    }
                    break;
                case 106:
                    $this->log_error( 'Your Fixer.io request did not specify an access key.' );
                    break;
                case 201:
                    $this->log_error( 'Invalid base currency for Fixer.io API - free plan only supports EUR as base.' );
                    break;
                case 202:
                    $this->log_error( 'Invalid symbols specified for Fixer.io API.' );
                    break;
            }
            
            return false;
        }
        
        // Free Fixer.io plan always uses EUR as base, so we need to check for that
        $base_currency = isset($data->base) ? $data->base : 'EUR';
        if ($base_currency !== 'EUR') {
            $this->log_message("Fixer.io returned base currency: $base_currency");
        }
        
        // Check if the API response contains the required rates
        if ( ! isset( $data->rates->$from ) || ! isset( $data->rates->$to ) ) {
            $this->log_error( "Fixer.io API missing rates for '$from' or '$to'. Response: " . json_encode( $data ) );
            return false;
        }
        
        // Calculate the exchange rate based on the rates relative to the base currency (EUR)
        // If from or to is EUR, we can use the rate directly
        if ($from === 'EUR') {
            $exchange_rate = $data->rates->$to;
        } else if ($to === 'EUR') {
            $exchange_rate = 1 / $data->rates->$from;
        } else {
            // Calculate cross rate: to_currency_rate / from_currency_rate
            $exchange_rate = $data->rates->$to / $data->rates->$from;
        }
        
        $this->log_message(sprintf(
            "Fixer.io exchange rate calculation: %s to %s = %s (using cross-rate calculation via EUR)",
            $from, 
            $to, 
            $exchange_rate
        ));
        
        return round( $exchange_rate, $precision );
    }
    
    /**
     * Get exchange rate from Xignite
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @param int $precision Decimal precision
     * @return float|bool Exchange rate on success, false on error
     */
    private function get_rate_from_xignite( $from, $to, $precision ) {
        if ( empty( $this->settings['xignite_api_id'] ) ) {
            $this->log_error( 'Xignite API token not provided' );
            return false;
        }
        
        $query = $from . $to;
        $url = 'https://globalcurrencies.xignite.com/xGlobalCurrencies.json/GetRealTimeRate?Symbol=' . 
               $query . '&_token=' . $this->settings['xignite_api_id'] . '&_fields=Outcome,Mid';
               
        $response = $this->fetch_remote_data( $url );
        
        if ( ! $response ) {
            return false;
        }
        
        $data = json_decode( $response );
        
        if ( ! isset( $data->Outcome ) || $data->Outcome !== 'Success' ) {
            $this->log_error( 'Xignite API error: ' . ( isset( $data->Message ) ? $data->Message : 'Unknown error' ) );
            return false;
        }
        
        if ( ! isset( $data->Mid ) ) {
            $this->log_error( 'Xignite API missing Mid rate' );
            return false;
        }
        
        return round( $data->Mid, $precision );
    }
    
    /**
     * Get exchange rate from APILayer
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @param int $precision Decimal precision
     * @return float|bool Exchange rate on success, false on error
     */
    private function get_rate_from_apilayer( $from, $to, $precision ) {
        if ( empty( $this->settings['apilayer_api_id'] ) ) {
            $this->log_error( 'APILayer access key not provided' );
            return false;
        }
        
        $query = $to . ',' . $from;
        $url = 'https://api.currencylayer.com/live?access_key=' . $this->settings['apilayer_api_id'] . '&currencies=' . $query . '&format=1';
        $response = $this->fetch_remote_data( $url );
        
        if ( ! $response ) {
            return false;
        }
        
        $data = json_decode( $response );
        
        if ( ! isset( $data->success ) || $data->success !== true ) {
            $this->log_error( 'APILayer API error: ' . ( isset( $data->error->info ) ? $data->error->info : 'Unknown error' ) );
            return false;
        }
        
        $USDto = "USD" . $to;
        $USDfrom = "USD" . $from;
        
        if ( ! isset( $data->quotes->$USDto ) || ! isset( $data->quotes->$USDfrom ) ) {
            $this->log_error( "APILayer API missing quotes for $USDto or $USDfrom" );
            return false;
        }
        
        return round( $data->quotes->$USDto / $data->quotes->$USDfrom, $precision );
    }
    
    /**
     * Get exchange rate from CurrencyConverterAPI
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @param int $precision Decimal precision
     * @return float|bool Exchange rate on success, false on error
     */
    private function get_rate_from_currencyconverterapi( $from, $to, $precision ) {
        if ( empty( $this->settings['currencyconverterapi_id'] ) ) {
            $this->log_error( 'CurrencyConverterAPI key not provided' );
            return false;
        }
        
        $query = $from . '_' . $to;
        // All CurrencyConverterAPI requests require a paid subscription
        $url = 'https://api.currconv.com/api/v7/convert?q=' . $query . '&compact=ultra&apiKey=' . $this->settings['currencyconverterapi_id'];
        
        $response = $this->fetch_remote_data( $url );
        
        if ( ! $response ) {
            $this->log_error( 'CurrencyConverterAPI request failed - check your API key and ensure you have a valid subscription' );
            return false;
        }
        
        $data = json_decode( $response );
        
        if ( isset( $data->error ) ) {
            $error_msg = is_string( $data->error ) ? $data->error : json_encode( $data->error );
            $this->log_error( 'CurrencyConverterAPI error: ' . $error_msg );
            return false;
        }
        
        if ( ! isset( $data->$query ) ) {
            $this->log_error( "CurrencyConverterAPI missing rate for $query. Response: " . json_encode( $data ) );
            return false;
        }
        
        return round( $data->$query, $precision );
    }
    
    /**
     * Fetch remote data using cURL
     *
     * @param string $url URL to fetch
     * @return string|bool Response on success, false on error
     */
    private function fetch_remote_data( $url ) {
        if ( ! function_exists( 'curl_init' ) ) {
            $this->log_error( 'cURL is not installed on this server' );
            return false;
        }
        
        $ch = curl_init();
        
        curl_setopt( $ch, CURLOPT_HEADER, 0 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'PayPal Currency Converter PRO/' . PPCC_VERSION );
        
        // Use WordPress certificates if available
        if ( file_exists( ABSPATH . WPINC . '/certificates/ca-bundle.crt' ) ) {
            curl_setopt( $ch, CURLOPT_CAINFO, ABSPATH . WPINC . '/certificates/ca-bundle.crt' );
        }
        
        $data = curl_exec( $ch );
        
        if ( $data === false ) {
            $error_msg = curl_error( $ch );
            $error_code = curl_errno( $ch );
            
            // Log the error with more details about what it means
            $this->log_error( 'cURL error (' . $error_code . '): ' . $error_msg );
            
            // Provide more specific information for common cURL errors
            switch ( $error_code ) {
                case 6:
                    $this->log_error( 'Could not resolve host. Check your internet connection and DNS settings.' );
                    break;
                case 7:
                    $this->log_error( 'Failed to connect to host. The API server might be down or you might have connectivity issues.' );
                    break;
                case 28:
                    $this->log_error( 'Operation timed out. The API server is taking too long to respond.' );
                    break;
                case 35:
                    $this->log_error( 'SSL connect error. Your server might not have the required SSL certificates, or there might be an issue with the API endpoint\'s SSL certificate.' );
                    break;
                case 51:
                    $this->log_error( 'The remote server\'s SSL certificate is not valid. Try using HTTP instead of HTTPS for free Fixer.io plans.' );
                    break;
                case 60:
                    $this->log_error( 'SSL certificate problem. Your server might not trust the API endpoint\'s certificate or it might be missing intermediate certificates.' );
                    break;
            }
            
            // Additional debug info
            $info = curl_getinfo( $ch );
            $this->log_message( 'cURL request details: ' . json_encode( $info ) );
            
            curl_close( $ch );
            return false;
        }
        
        // Get response info for debugging
        $info = curl_getinfo( $ch );
        $http_code = $info['http_code'];
        
        if ( $http_code >= 400 ) {
            $this->log_error( "API request failed with HTTP status code: $http_code" );
            $this->log_message( "Response body: " . substr( $data, 0, 200 ) . (strlen($data) > 200 ? '...' : '') );
        }
        
        curl_close( $ch );
        
        return $data;
    }
    
    /**
     * Log update to file and send email notification
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @param float $rate Exchange rate
     */
    private function log_update( $from, $to, $rate ) {
        $timestamp = current_time( 'timestamp' );
        $log_message = sprintf(
            'Exchange rate updated at %s - %s/%s = %s - Update #%d',
            date( 'Y-m-d H:i:s', $timestamp ),
            $to,
            $from,
            $rate,
            $this->settings['retrieval_count'] + 1
        );
        
        // Log to file
        $this->log_message( $log_message );
        
        // Send email notification
        if ( function_exists( 'wp_mail' ) ) {
            $subject = __( 'PayPal Currency Converter PRO - Exchange Rate Update', 'ppcc-pro' );
            $message = $log_message . "\n\n" . 
                       __( 'Exchange rate provider: ', 'ppcc-pro' ) . $this->settings['api_selection'];
                       
            wp_mail( get_option( 'admin_email' ), $subject, $message );
        }
    }
    
    /**
     * Log error message
     *
     * @param string $message Error message
     */
    private function log_error( $message ) {
        $this->log_message( 'ERROR: ' . $message );
    }
    
    /**
     * Log message to file
     *
     * @param string $message Message to log
     */
    private function log_message( $message ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->info( $message, array( 'source' => 'ppcc' ) );
        }
    }
}
