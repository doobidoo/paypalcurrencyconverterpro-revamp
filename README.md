# PayPal Currency Converter PRO for WooCommerce

## Version 4.0.0

PayPal Currency Converter PRO for WooCommerce allows you to accept payments in any currency through PayPal by automatically converting your store currency to a PayPal-supported currency during checkout.

## Key Features

- **Complete Order Currency Conversion**: Converts the entire order to a PayPal-supported currency at checkout
- **Multiple Exchange Rate Providers**: Choose from multiple exchange rate providers including CurrencyConverterAPI, Fixer.io, OpenExchangeRates and more
- **Automatic Updates**: Schedule automatic exchange rate updates
- **Custom Currencies**: Define and use your own custom currencies in your store
- **Handling Fees**: Add percentage and/or fixed handling fees to cover conversion costs
- **Order Management**: Auto-complete virtual orders and auto-process physical product orders
- **Detailed Conversion Information**: Show detailed conversion information throughout the checkout and order process
- **Comprehensive Admin Interface**: Easy-to-use admin interface for configuring all aspects of the plugin

## Description

PayPal Currency Converter PRO for WooCommerce 4.0 is a complete revamp of the plugin that takes a fundamentally different approach to solving the PayPal currency compatibility issues. Instead of modifying PayPal API requests, it converts the entire WooCommerce order to the target currency before checkout completion.

This approach ensures compatibility with PayPal's changing API and provides a more reliable payment experience for your customers.

## How It Works

1. When a customer selects PayPal as their payment method, the plugin converts the order to the target currency
2. Original currency and amount information is stored in the order metadata
3. The customer is sent to PayPal with the converted currency and amount
4. After payment, the order is processed according to your settings

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.0 or higher
- cURL PHP extension (required for exchange rate providers)

## Installation

1. Upload the `paypalcurrencyconverterpro-revamp` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Currency Converter to configure the plugin

## Configuration

### General Settings

- **Source Currency**: Your WooCommerce store currency (automatically detected)
- **Target Currency**: Select the PayPal-supported currency you want to convert to
- **Custom Currency**: Enable and configure a custom currency for your store
- **Conversion Rate**: Set or fetch the current exchange rate
- **Precision**: Select the decimal precision for the conversion rate

### Exchange Rate Providers

- **Custom Exchange Rate**: Manually set your own exchange rate
- **Currency Converter API**: Use the free or paid Currency Converter API
- **Fixer.io**: Use the European Central Bank exchange rates via Fixer.io
- **Open Exchange Rates**: Use Open Exchange Rates API
- **Xignite**: Use Xignite Global Currencies API
- **Currency Layer API**: Use Currency Layer API
- **Custom API**: Use your own custom API integration

### Handling Fees

- **Percentage Fee**: Add a percentage-based handling fee
- **Fixed Fee**: Add a fixed handling fee
- **Fee Title**: Set a custom title for the handling fee
- **Apply to Shipping**: Include shipping costs in percentage calculation
- **Taxable**: Apply taxes to the handling fee

### Order Processing

- **Auto-Complete Virtual Orders**: Automatically complete orders containing only virtual products
- **Auto-Process Standard Orders**: Automatically process orders containing physical products
- **Suppress On-Hold Emails**: Suppress on-hold email notifications for PayPal orders
- **Email Order Completed Note**: Add conversion details to order emails
- **Email Note Template**: Customize the conversion note in emails

## Frequently Asked Questions

### Will this plugin work with PayPal Commerce Platform (PCP)?

Yes, the plugin works with PayPal Commerce Platform as well as PayPal Standard, PayPal Express Checkout, PayPal Digital Goods, and PayPal Advanced.

### What happens to my existing orders after installing this plugin?

Existing orders are not affected. The currency conversion only applies to new orders where PayPal is selected as the payment method.

### Can I use my own custom currency with this plugin?

Yes, you can define and use your own custom currency. Enable the "Custom Currency" option in the General Settings, provide the currency code, symbol, and name, then select it in your WooCommerce settings.

### How often should I update the exchange rate?

It depends on the volatility of your currency pair. For most cases, a daily update is sufficient. You can set up automatic updates or manually update the rate as needed.

### Will customers see both currencies during checkout?

Yes, when a customer selects PayPal as their payment method, they will see detailed conversion information including the original amount, converted amount, and exchange rate.

### Does this plugin handle refunds?

Yes, refunds are processed in the currency the order was paid in (the converted currency). The plugin handles the currency conversion automatically.

## Support

For support, please contact us through [CodeCanyon](https://codecanyon.net/item/paypal-currency-converter-pro-for-woocommerce/6343249/support).

## Changelog

### 4.0.0
* Complete rewrite of the plugin
* New approach: convert the entire order to PayPal-supported currency
* Enhanced admin interface with tabs
* Improved conversion display during checkout
* Better handling of custom currencies
* Added support for PayPal Commerce Platform
* Improved exchange rate providers integration
* Added Dashboard widget for quick rate updates
* Enhanced order management features
* Added export/import settings functionality

### 3.7.7
* Fixed compatibility issues with WooCommerce 7.0+
* Improved PayPal API handling
* Minor bug fixes and improvements

### 3.7.6
* Added support for WooCommerce 6.0+
* Fixed PHP 8.0 compatibility issues
* Improved error handling for API requests

### 3.7.5
* Added support for CurrencyConverterAPI
* Improved compatibility with PayPal Express Checkout
* Fixed minor bugs and improved stability

### 3.7.4
* Fixed compatibility issues with WooCommerce 4.0+
* Improved handling of order status changes
* Minor bug fixes and code improvements

### 3.7.3
* Added support for custom API integration
* Improved compatibility with PayPal Digital Goods
* Fixed issues with handling fees calculation

### 3.7.2
* Added support for Xignite and Currency Layer APIs
* Improved handling of non-decimal currencies
* Fixed minor bugs and improved stability

### 3.7.1
* Added support for WooCommerce Subscriptions
* Improved custom currency handling
* Fixed issues with order status management

### 3.7.0
* Added support for PayPal Advanced
* Improved handling fee management
* Added threshold options for handling fees
* Fixed minor bugs and improved stability

## License

This plugin is licensed under the [Envato Regular License](http://codecanyon.net/licenses/regular).