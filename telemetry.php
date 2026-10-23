<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';
require_once __DIR__ . '/includes/rate-limit.php';

sendSecurityHeaders(false);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

enforceRateLimit('telemetry-callback', 120, 3600);
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 4096) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'request_too_large'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'unsupported_media_type'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
try {
    $data = json_decode($raw ?: '', true, 8, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable) {
    $data = null;
}

if (!is_array($data) || array_keys($data) !== array_intersect(array_keys($data), ['type', 'version'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = $data['type'] ?? null;
$version = $data['version'] ?? null;

if (!is_string($type) || !in_array($type, ['run', 'first-run'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_type'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_string($version) || $version === '' || strlen($version) > 64 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\- ]{0,63}$/', $version)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_version'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/includes/database.php';

try {
    $pdo = getDbConnection();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO telemetry_events (event_type, version, created_at) VALUES (:event_type, :version, NOW())'
    );
    $stmt->execute([
        ':event_type' => $type,
        ':version' => $version,
    ]);

    if ($type === 'first-run') {
        $stmt->execute([
            ':event_type' => 'run',
            ':version' => $version,
        ]);
    }

    $pdo->commit();

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
