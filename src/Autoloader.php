<?php

/**
 * Simple Class Autoloader for Cinepulse
 * Maps namespaces starting with "Cinepulse\" to the "src/" folder.
 */

spl_autoload_register(function ($class) {
    $prefix = 'Cinepulse\\';
    
    // Force the base directory to be explicitly the 'src' directory 
    // where your class files reside.
    $base_dir = __DIR__ . '/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    } else {
        // Optional Debugging: Uncomment the line below if it still fails 
        // to see exactly where PHP is looking for your file!
        // trigger_error("Autoloader failed to find class file at: " . $file, E_USER_WARNING);
    }
});