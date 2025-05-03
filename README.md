# PayPal Currency Converter PRO for WooCommerce

Convert any currency to allowed PayPal currencies for PayPal's Payment Gateway within WooCommerce.

## Version 4.0.1 - Handling Fee Fix

This update addresses an issue with handling fees not being properly included in PayPal API requests, which was resulting in "Could not create order" errors.

### Fixed Issues

1. **PayPal API Currency Mismatch** - Fixed an issue where the requests to PayPal were using the store currency (ZAR) instead of the target currency (CHF).

2. **Handling Fee Implementation** - Improved how handling fees are applied and included in PayPal API requests. The plugin now correctly formats the handling fee according to PayPal's API requirements.

3. **Missing Cart Properties** - Fixed "Missing required cart properties" error by improving how cart totals are retrieved, making the handling fee calculation more robust.

### Changes Made

1. **Enhanced Checkout Class** - Modified the `add_handling_fee` method to safely access cart totals and store handling fee in the session for later use in API requests.

2. **PayPal API Integration** - Added a dedicated `PPCC_PayPal_API` class to properly handle all PayPal API interactions.

3. **Client-Side Fix** - Added JavaScript to ensure handling fees are correctly included in PayPal Smart Button checkout requests.

4. **Request Modification** - Added filters to modify PayPal API requests before they are sent, ensuring correct currency and handling fee values.

### Usage

No additional configuration is needed. The plugin will automatically handle currency conversion and apply handling fees correctly during PayPal checkout.

### Troubleshooting

If you encounter any issues:

1. Check the logs in `wp-content/plugins/paypalcurrencyconverterpro-revamp/logs/` for detailed error information.

2. Ensure your PayPal account is set up to accept the target currency (CHF in this case).

3. Verify that your handling fee settings are correct in the plugin settings page.

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.0 or higher

## Support

For support, please contact us at support@intelligent-it.asia or through our CodeCanyon profile.
