<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/recaptcha.php';
require_once __DIR__ . '/includes/rate-limit.php';
require_once __DIR__ . '/includes/app-config.php';

sendSecurityHeaders(false);
startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

requireCsrfToken($_POST['csrf_token'] ?? null);
enforceRateLimit('telemetry-export', 3, 3600);

$captcha = trim((string)($_POST['g-recaptcha-response'] ?? ''));
if (!verifyRecaptcha($captcha)) {
    http_response_code(403);
    exit('Verification failed.');
}

$from = trim((string)($_POST['from'] ?? ''));
$to = trim((string)($_POST['to'] ?? ''));
$type = trim((string)($_POST['type'] ?? 'all'));
$version = trim((string)($_POST['version'] ?? ''));
$versionMode = trim((string)($_POST['version_mode'] ?? 'contains'));

$date = static function (string $value): ?string {
    if ($value === '') return null;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $parsed && $parsed->format('Y-m-d') === $value ? $value : null;
};
$from = $date($from);
$to = $date($to);
if ($from !== null && $to !== null && $from > $to) [$from, $to] = [$to, $from];
$type = in_array($type, ['all', 'run', 'first-run'], true) ? $type : 'all';
$versionMode = in_array($versionMode, ['contains', 'prefix', 'exact'], true) ? $versionMode : 'contains';
$version = mb_substr($version, 0, 64);

$where = [];
$params = [];
if ($from !== null) { $where[] = 'created_at >= :from_date'; $params[':from_date'] = $from . ' 00:00:00'; }
if ($to !== null) { $where[] = 'created_at < :to_date'; $params[':to_date'] = (new DateTimeImmutable($to . ' +1 day'))->format('Y-m-d') . ' 00:00:00'; }
if ($type !== 'all') { $where[] = 'event_type = :event_type'; $params[':event_type'] = $type; }
if ($version !== '') {
    $versionValue = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $version);
    $operator = '=';
    if ($versionMode === 'contains') { $operator = 'LIKE'; $versionValue = '%' . $versionValue . '%'; }
    if ($versionMode === 'prefix') { $operator = 'LIKE'; $versionValue .= '%'; }
    $where[] = "version $operator :version ESCAPE '\\'";
    $params[':version'] = $versionValue;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $pdo = getDbConnection();
    $config = getAppConfig();
    $maxRows = (int)($config['export']['max_rows'] ?? 500000);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM telemetry_events $whereSql");
    $countStmt->execute($params);
    $count = (int)$countStmt->fetchColumn();
    if ($count > $maxRows) {
        http_response_code(413);
        exit('Export is too large. Narrow the filters and try again.');
    }

    $stmt = $pdo->prepare("SELECT event_type, version, created_at FROM telemetry_events $whereSql ORDER BY created_at ASC, version ASC, event_type ASC");
    $stmt->execute($params);
} catch (Throwable) {
    http_response_code(500);
    exit('Export is temporarily unavailable.');
}

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="halkylauncher-telemetry.sql"');
header('X-Content-Type-Options: nosniff');

$write = static function (string $line): void {
    echo $line;
    if (function_exists('ob_flush')) @ob_flush();
    flush();
};
$quote = static function (mixed $value) use ($pdo): string {
    if ($value === null) return 'NULL';
    return $pdo->quote((string)$value);
};

$write("-- HalkyLauncher anonymous telemetry export\n");
$write("-- Generated at " . gmdate('Y-m-d\\TH:i:s\\Z') . "\n\n");
$write("CREATE TABLE IF NOT EXISTS telemetry_events (event_type VARCHAR(16) NOT NULL, version VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL);\n\n");

$batch = [];
while ($row = $stmt->fetch()) {
    $batch[] = '(' . $quote($row['event_type']) . ', ' . $quote($row['version']) . ', ' . $quote($row['created_at']) . ')';
    if (count($batch) >= 500) {
        $write("INSERT INTO telemetry_events (event_type, version, created_at) VALUES\n" . implode(",\n", $batch) . ";\n\n");
        $batch = [];
    }
}
if ($batch) {
    $write("INSERT INTO telemetry_events (event_type, version, created_at) VALUES\n" . implode(",\n", $batch) . ";\n");
}
