<?php
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define path to check
$error_log_path = '/Applications/XAMPP/xamppfiles/logs/php_error_log';

echo '<h1>PHP Error Log Check</h1>';

if (file_exists($error_log_path)) {
    echo '<p>Error log exists at: ' . $error_log_path . '</p>';
    
    // Get file size
    $size = filesize($error_log_path);
    echo '<p>Log file size: ' . number_format($size / 1024 / 1024, 2) . ' MB</p>';
    
    // Read the last 50KB of the file
    $log_content = file_get_contents($error_log_path, false, null, max(0, $size - 50000), 50000);
    
    // Split into lines and filter for our plugin
    $lines = explode("\n", $log_content);
    $filtered_lines = [];
    
    foreach ($lines as $line) {
        if (strpos($line, 'paypalcurrencyconverterpro') !== false || 
            strpos($line, 'class-ppcc') !== false || 
            strpos($line, 'fatal error') !== false) {
            $filtered_lines[] = $line;
        }
    }
    
    // Display the filtered lines
    if (count($filtered_lines) > 0) {
        echo '<h2>Relevant Error Entries (' . count($filtered_lines) . ')</h2>';
        echo '<pre style="background-color: #f5f5f5; padding: 10px; overflow: auto; max-height: 500px;">';
        foreach (array_slice($filtered_lines, -20) as $line) {
            echo htmlspecialchars($line) . "\n";
        }
        echo '</pre>';
    } else {
        echo '<p>No relevant errors found.</p>';
    }
} else {
    echo '<p>Error log not found at: ' . $error_log_path . '</p>';
    
    // Try alternative locations
    $alternative_paths = [
        '/Applications/XAMPP/xamppfiles/htdocs/wordpress/wp-content/debug.log',
        '/Applications/XAMPP/xamppfiles/php/logs/php_error_log',
        '/var/log/php_errors.log'
    ];
    
    echo '<h2>Checking Alternative Locations</h2>';
    foreach ($alternative_paths as $path) {
        if (file_exists($path)) {
            echo '<p>Log found at: ' . $path . '</p>';
            // Read the last 10KB of the file
            $alt_content = file_get_contents($path, false, null, max(0, filesize($path) - 10000), 10000);
            echo '<pre style="background-color: #f5f5f5; padding: 10px; overflow: auto; max-height: 200px;">';
            echo htmlspecialchars($alt_content);
            echo '</pre>';
        } else {
            echo '<p>No log at: ' . $path . '</p>';
        }
    }
}

// Try to create a test error to see where it gets logged
echo '<h2>Creating Test Error</h2>';
$current_log_file = ini_get('error_log');
echo '<p>Current error_log setting: ' . ($current_log_file ? $current_log_file : 'Not set') . '</p>';

// Generate a test error
$test_error_message = 'Test error from check_errors.php: ' . date('Y-m-d H:i:s');
error_log($test_error_message);
echo '<p>Tried to log: "' . $test_error_message . '"</p>';

// Direct error generation
echo '<p>Triggering direct PHP error...</p>';
try {
    // This will cause an error
    $undefined_var++;
} catch (Throwable $e) {
    echo '<p>Caught error: ' . $e->getMessage() . '</p>';
}

// Check if we can look at the classes to find the issue
echo '<h2>Plugin Class Check</h2>';
$plugin_dir = dirname(__FILE__);

try {
    echo '<p>Loading class-ppcc-core.php...</p>';
    require_once($plugin_dir . '/includes/class-ppcc-core.php');
    echo '<p style="color:green;">✓ Successfully loaded class-ppcc-core.php</p>';
    
    if (class_exists('PPCC_Core')) {
        echo '<p style="color:green;">✓ PPCC_Core class exists</p>';
        
        // Try to see what's in the class
        $reflection = new ReflectionClass('PPCC_Core');
        echo '<p>Methods in PPCC_Core:</p>';
        echo '<ul>';
        foreach ($reflection->getMethods() as $method) {
            echo '<li>' . $method->getName() . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p style="color:red;">✗ PPCC_Core class not found after including the file</p>';
    }
} catch (Throwable $e) {
    echo '<p style="color:red;">✗ Error loading class-ppcc-core.php: ' . $e->getMessage() . '</p>';
    echo '<p>File: ' . $e->getFile() . '</p>';
    echo '<p>Line: ' . $e->getLine() . '</p>';
}
