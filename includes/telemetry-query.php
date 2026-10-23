<?php
declare(strict_types=1);

function telemetryFilters(): array {
    $from = trim((string)($_GET['from'] ?? $_POST['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? $_POST['to'] ?? ''));
    $type = trim((string)($_GET['type'] ?? $_POST['type'] ?? 'all'));
    $version = trim((string)($_GET['version'] ?? $_POST['version'] ?? ''));
    $versionMode = trim((string)($_GET['version_mode'] ?? $_POST['version_mode'] ?? 'contains'));
    $granularity = trim((string)($_GET['granularity'] ?? 'day'));
    $top = filter_input(INPUT_GET, 'top', FILTER_VALIDATE_INT, ['options' => ['default' => 12]]);
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1]]);
    $pageSize = filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT, ['options' => ['default' => 25]]);

    $date = static function (string $value): ?string {
        if ($value === '') return null;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed && $parsed->format('Y-m-d') === $value ? $value : null;
    };
    $from = $date($from);
    $to = $date($to);
    if ($from !== null && $to !== null && $from > $to) [$from, $to] = [$to, $from];

    return [
        'from' => $from,
        'to' => $to,
        'type' => in_array($type, ['all', 'run', 'first-run'], true) ? $type : 'all',
        'version' => mb_substr($version, 0, 64),
        'version_mode' => in_array($versionMode, ['contains', 'prefix', 'exact'], true) ? $versionMode : 'contains',
        'granularity' => in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'day',
        'top' => max(3, min((int)$top, 50)),
        'page' => max(1, min((int)$page, 100000)),
        'page_size' => max(10, min((int)$pageSize, 100)),
    ];
}

function telemetryWhere(array $filters, array &$params): string {
    $where = [];
    if ($filters['from'] !== null) { $where[] = 'created_at >= :from_date'; $params[':from_date'] = $filters['from'] . ' 00:00:00'; }
    if ($filters['to'] !== null) { $where[] = 'created_at < :to_date'; $params[':to_date'] = (new DateTimeImmutable($filters['to'] . ' +1 day'))->format('Y-m-d') . ' 00:00:00'; }
    if ($filters['type'] !== 'all') { $where[] = 'event_type = :event_type'; $params[':event_type'] = $filters['type']; }
    if ($filters['version'] !== '') {
        $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['version']);
        $operator = '=';
        if ($filters['version_mode'] === 'contains') { $operator = 'LIKE'; $value = '%' . $value . '%'; }
        if ($filters['version_mode'] === 'prefix') { $operator = 'LIKE'; $value .= '%'; }
        $where[] = "version $operator :version ESCAPE '\\'";
        $params[':version'] = $value;
    }
    return $where ? 'WHERE ' . implode(' AND ', $where) : '';
}

function telemetryDateExpression(string $granularity): string {
    return match ($granularity) {
        'week' => "DATE_FORMAT(created_at, '%x-W%v')",
        'month' => "DATE_FORMAT(created_at, '%Y-%m')",
        default => 'DATE(created_at)',
    };
}

function queryUrl(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) if ($value === null || $value === '') unset($params[$key]);
    return '?' . http_build_query($params);
}
