<?php
declare(strict_types=1);

/** Load database configuration from private config or environment variables. */
function getDatabaseConfig(): array {
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $credentialsFile = getenv('HALKY_CREDENTIALS_FILE');
    $credentialsFile = $credentialsFile !== false && $credentialsFile !== ''
        ? $credentialsFile
        : __DIR__ . '/../config/credentials.php';

    $credentials = file_exists($credentialsFile) ? (array)require $credentialsFile : [];
    $db = (array)($credentials['db'] ?? []);
    $value = static function (string $env, string $key, string $fallback = '') use ($db): string {
        $environment = getenv($env);
        return $environment !== false ? (string)$environment : (string)($db[$key] ?? $fallback);
    };

    $config = [
        'host' => $value('DB_HOST', 'host'),
        'port' => $value('DB_PORT', 'port', '3306'),
        'name' => $value('DB_NAME', 'name'),
        'user' => $value('DB_USER', 'user'),
        'pass' => $value('DB_PASS', 'pass'),
        'charset' => $value('DB_CHARSET', 'charset', 'utf8mb4'),
    ];

    foreach (['host', 'port', 'name', 'user', 'pass', 'charset'] as $required) {
        if ($config[$required] === '') {
            throw new RuntimeException('Database configuration is incomplete.');
        }
    }

    return $config;
}

function getDbConnection(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = getDatabaseConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'], $config['port'], $config['name'], $config['charset']
    );
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

if (!function_exists('db')) {
    function db(): PDO {
        return getDbConnection();
    }
}
