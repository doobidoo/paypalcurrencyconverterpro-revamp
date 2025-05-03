/**
 * PayPal Currency Converter PRO - Admin JS
 * 
 * Handles the admin interface functionality
 */
(function($) {
    'use strict';
    
    // Initialize on document ready
    $(document).ready(function() {
        ppccAdmin.init();
    });
    
    // Main object for admin functionality
    var ppccAdmin = {
        // Initialize
        init: function() {
            this.initTabs();
            this.initCustomCurrencyToggle();
            this.initFetchRate();
            this.initUpdateRate();
            this.initResetSettings();
            this.initExportSettings();
        },
        
        // Initialize tabs
        initTabs: function() {
            // Set up tab navigation
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                
                // Get the tab ID from href
                var tabId = $(this).attr('href').replace('#', '');
                
                // Remove active class from all tabs and content
                $('.nav-tab').removeClass('nav-tab-active');
                $('.ppcc-tab').removeClass('active').hide();
                
                // Add active class to current tab and show content
                $(this).addClass('nav-tab-active');
                $('#' + tabId).addClass('active').show();
                
                // Save active tab to localStorage
                if (typeof(Storage) !== "undefined") {
                    localStorage.setItem('ppcc_active_tab', tabId);
                }
            });
            
            // Check if we have a saved tab
            if (typeof(Storage) !== "undefined" && localStorage.getItem('ppcc_active_tab')) {
                var savedTab = localStorage.getItem('ppcc_active_tab');
                
                // Activate the saved tab
                $('.nav-tab[href="#' + savedTab + '"]').trigger('click');
            }
        },
        
        // Initialize custom currency toggle
        initCustomCurrencyToggle: function() {
            $('#ppcc-settings-form input[name="ppcc_settings[ppcc_use_custom_currency]"]').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.ppcc-custom-currency-fields').show();
                } else {
                    $('.ppcc-custom-currency-fields').hide();
                }
            });
        },
        
        // Initialize fetch rate button
        initFetchRate: function() {
            $('#ppcc-fetch-rate').on('click', function(e) {
                e.preventDefault();
                
                var $button = $(this);
                var $rateInput = $('#ppcc-conversion-rate');
                
                // Disable button and show loading
                $button.prop('disabled', true).text('Fetching...');
                
                // Get the source and target currencies
                var sourceCurrency = ppcc_admin_data.shop_currency;
                var targetCurrency = $('#ppcc-target-currency').val();
                
                // Call AJAX to fetch current rate
                $.ajax({
                    url: ppcc_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ppcc_update_rate',
                        nonce: ppcc_admin_data.nonce,
                        source: sourceCurrency,
                        target: targetCurrency
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the rate input - ensure we don't lose precision
                            $rateInput.val(parseFloat(response.data.new_rate));
                            
                            // Show success message
                            alert('Exchange rate updated successfully to ' + response.data.new_rate);
                            
                            // Update last update time
                            $('.ppcc-update-time').text(response.data.last_update);
                        } else {
                            // Show error message
                            alert('Failed to update exchange rate: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred while trying to fetch the exchange rate.');
                    },
                    complete: function() {
                        // Re-enable button
                        $button.prop('disabled', false).text('Fetch Current Rate');
                    }
                });
            });
        },
        
        // Initialize update rate button in dashboard widget
        initUpdateRate: function() {
            $('.ppcc-update-rate-btn').on('click', function(e) {
                e.preventDefault();
                
                var $button = $(this);
                var nonce = $button.data('nonce');
                
                // Disable button and show loading
                $button.prop('disabled', true).text('Updating...');
                
                // Call AJAX to update rate
                $.ajax({
                    url: ppcc_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ppcc_update_rate',
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the rate display without losing precision
                            $('.ppcc-rate-value').text(response.data.new_rate);
                            
                            // Update last update time
                            $('.ppcc-update-time').text(response.data.last_update);
                            
                            // Show success message
                            alert('Exchange rate updated successfully to ' + response.data.new_rate);
                        } else {
                            // Show error message
                            alert('Failed to update exchange rate: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred while trying to update the exchange rate.');
                    },
                    complete: function() {
                        // Re-enable button
                        $button.prop('disabled', false).text('Update Rate Now');
                    }
                });
            });
        },
        
        // Initialize reset settings button
        initResetSettings: function() {
            $('#ppcc-reset-settings').on('click', function(e) {
                e.preventDefault();
                
                if (confirm('Are you sure you want to reset all settings to their default values? This cannot be undone.')) {
                    // Reload the page with reset flag
                    window.location.href = window.location.href + '&reset=1';
                }
            });
        },
        
        // Initialize export settings button
        initExportSettings: function() {
            $('#ppcc-export-settings').on('click', function(e) {
                e.preventDefault();
                
                // Call AJAX to get settings data
                $.ajax({
                    url: ppcc_admin_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ppcc_export_settings',
                        nonce: ppcc_admin_data.admin_nonce // Use the admin nonce for this action
                    },
                    success: function(response) {
                        if (response.success) {
                            // Create a blob and download it
                            var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(response.data.settings, null, 2));
                            var downloadAnchorNode = document.createElement('a');
                            downloadAnchorNode.setAttribute("href", dataStr);
                            downloadAnchorNode.setAttribute("download", "ppcc-settings.json");
                            document.body.appendChild(downloadAnchorNode);
                            downloadAnchorNode.click();
                            downloadAnchorNode.remove();
                        } else {
                            alert('Failed to export settings: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred while trying to export settings.');
                    }
                });
            });
        }
    };
})(jQuery);
