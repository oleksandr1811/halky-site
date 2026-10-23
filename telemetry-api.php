<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';
require_once __DIR__ . '/includes/rate-limit.php';

sendSecurityHeaders(false);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

enforceRateLimit('telemetry-api', 300, 3600);
require_once __DIR__ . '/includes/database.php';

try {
    $pdo = getDbConnection();

    $stmt = $pdo->query(
        "SELECT
            SUM(CASE WHEN event_type = 'first-run' THEN 1 ELSE 0 END) AS installs,
            SUM(CASE WHEN event_type = 'run' THEN 1 ELSE 0 END) AS runs
         FROM telemetry_events"
    );
    $row = $stmt->fetch() ?: [];

    echo json_encode([
        'installs' => (string)((int)($row['installs'] ?? 0)),
        'runs' => (string)((int)($row['runs'] ?? 0)),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'installs' => '0',
        'runs' => '0',
    ], JSON_UNESCAPED_UNICODE);
}
