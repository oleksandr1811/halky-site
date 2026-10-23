<?php
declare(strict_types=1);

/**
 * Database credentials template for HalkyLauncher website.
 *
 * Copy this file to `credentials.php` in the same directory:
 *   cp config/credentials.template.php config/credentials.php
 *
 * Then fill in your real database connection details.
 */
return [
    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'halky-stats',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
        'charset' => 'utf8mb4',
    ],
    // Add these only to a private config/config.php or environment variables.
    // They are intentionally absent from this tracked template.
    'recaptcha' => [
        'site_key' => '',
        'secret' => '',
        'hostname' => 'halkylauncher.alex1811.ovh',
    ],
    'export' => [
        'max_rows' => 500000,
    ],
];
