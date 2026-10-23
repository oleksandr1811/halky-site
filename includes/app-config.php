<?php
declare(strict_types=1);

/**
 * Loads non-database application settings from private config or environment.
 * Never falls back to a tracked template for production secrets.
 */
function getAppConfig(): array {
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $file = getenv('HALKY_APP_CONFIG_FILE');
    $file = $file !== false && $file !== '' ? $file : __DIR__ . '/../config/config.php';
    $config = file_exists($file) ? (array)require $file : [];
    $recaptcha = (array)($config['recaptcha'] ?? []);
    $export = (array)($config['export'] ?? []);

    $config['recaptcha'] = [
        'site_key' => getenv('RECAPTCHA_SITE_KEY') !== false
            ? (string)getenv('RECAPTCHA_SITE_KEY')
            : (string)($recaptcha['site_key'] ?? ''),
        'secret' => getenv('RECAPTCHA_SECRET') !== false
            ? (string)getenv('RECAPTCHA_SECRET')
            : (string)($recaptcha['secret'] ?? ''),
        'hostname' => getenv('RECAPTCHA_HOSTNAME') !== false
            ? (string)getenv('RECAPTCHA_HOSTNAME')
            : (string)($recaptcha['hostname'] ?? ''),
    ];

    $maxRows = getenv('EXPORT_MAX_ROWS') !== false
        ? (int)getenv('EXPORT_MAX_ROWS')
        : (int)($export['max_rows'] ?? 500000);

    $config['export'] = [
        'max_rows' => max(1, min($maxRows, 1000000)),
    ];

    return $config;
}
