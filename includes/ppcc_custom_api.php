<?php
/**
 * Custom API for PayPal Currency Converter PRO
 *
 * This file allows you to use a custom API to fetch exchange rates.
 * Implement the ppcc_custom_api() function to return the exchange rate.
 *
 * @package PayPal Currency Converter PRO
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Get exchange rate from custom API
 *
 * @param string $from Source currency
 * @param string $to Target currency
 * @param int $precision Decimal precision
 * @return float|bool Exchange rate or false on error
 */
function ppcc_custom_api( $from, $to, $precision ) {
    // Example implementation using a custom API
    $url = 'https://your-custom-api.com/exchange-rates?from=' . $from . '&to=' . $to;
    
    // Use the helper function to fetch remote data
    $response = ppcc_file_get_contents_curl( $url );
    
    if ( ! $response ) {
        ppcc_log( 'Custom API: Failed to fetch data from ' . $url, 'error' );
        return false;
    }
    
    // Parse the response (adjust according to your API)
    $data = json_decode( $response );
    
    if ( ! $data || ! isset( $data->rate ) ) {
        ppcc_log( 'Custom API: Invalid response from ' . $url, 'error' );
        return false;
    }
    
    // Return the rounded rate
    return round( $data->rate, $precision );
    
    /* 
     * Alternatively, you could implement a direct calculation:
     * 
     * // Hardcoded rates for example
     * $rates = array(
     *     'USD' => 1.0,
     *     'EUR' => 0.85,
     *     'GBP' => 0.75,
     *     // Add other currencies as needed
     * );
     * 
     * if (!isset($rates[$from]) || !isset($rates[$to])) {
     *     ppcc_log('Custom API: Currency not supported', 'error');
     *     return false;
     * }
     * 
     * // Calculate cross rate
     * $rate = $rates[$to] / $rates[$from];
     * return round($rate, $precision);
     */
}
