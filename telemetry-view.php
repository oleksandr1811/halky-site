<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/telemetry-query.php';
require_once __DIR__ . '/includes/app-config.php';
require_once __DIR__ . '/includes/recaptcha.php';

sendSecurityHeaders(true);
startSecureSession();
$filters = telemetryFilters();
$params = [];
$whereSql = telemetryWhere($filters, $params);
$error = null;
$summary = [];
$timeline = [];
$versions = [];
$events = [];
$totalEventRows = 0;
$totalPages = 1;

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function formatDate(?string $value): string {
    if (!$value) return '—';
    try { return (new DateTimeImmutable($value))->format('Y-m-d H:i'); } catch (Throwable) { return '—'; }
}
function numberValue(mixed $value): int { return (int)($value ?? 0); }

try {
    $pdo = getDbConnection();
    $summaryStmt = $pdo->prepare("SELECT COUNT(*) AS total_events, SUM(event_type = 'run') AS runs, SUM(event_type = 'first-run') AS installs, COUNT(DISTINCT version) AS versions, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen FROM telemetry_events $whereSql");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: [];

    $dateExpression = telemetryDateExpression($filters['granularity']);
    $timelineStmt = $pdo->prepare("SELECT $dateExpression AS period, COUNT(*) AS total, SUM(event_type = 'run') AS runs, SUM(event_type = 'first-run') AS installs FROM telemetry_events $whereSql GROUP BY period ORDER BY period ASC LIMIT 366");
    $timelineStmt->execute($params);
    $timeline = $timelineStmt->fetchAll();

    $versionStmt = $pdo->prepare("SELECT version, COUNT(*) AS total, SUM(event_type = 'run') AS runs, SUM(event_type = 'first-run') AS installs, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen FROM telemetry_events $whereSql GROUP BY version ORDER BY total DESC, version ASC LIMIT {$filters['top']}");
    $versionStmt->execute($params);
    $versions = $versionStmt->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM telemetry_events $whereSql");
    $countStmt->execute($params);
    $totalEventRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, min(100000, (int)ceil($totalEventRows / $filters['page_size'])));
    $page = min($filters['page'], $totalPages);
    $offset = ($page - 1) * $filters['page_size'];
    $eventStmt = $pdo->prepare("SELECT event_type, version, created_at FROM telemetry_events $whereSql ORDER BY created_at DESC, version ASC, event_type ASC LIMIT :page_size OFFSET :offset");
    foreach ($params as $key => $value) $eventStmt->bindValue($key, $value);
    $eventStmt->bindValue(':page_size', $filters['page_size'], PDO::PARAM_INT);
    $eventStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $eventStmt->execute();
    $events = $eventStmt->fetchAll();
} catch (Throwable) {
    http_response_code(500);
    $error = 'Telemetry data is temporarily unavailable.';
}

$totalEvents = numberValue($summary['total_events'] ?? 0);
$totalRuns = numberValue($summary['runs'] ?? 0);
$totalInstalls = numberValue($summary['installs'] ?? 0);
$totalVersions = numberValue($summary['versions'] ?? 0);
$maxTimeline = max(1, ...array_map(static fn(array $row): int => numberValue($row['total']), $timeline ?: [['total' => 1]]));
$maxVersion = max(1, ...array_map(static fn(array $row): int => numberValue($row['total']), $versions ?: [['total' => 1]]));
$siteKey = recaptchaSiteKey();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Telemetry overview</title>
  <meta name="description" content="Anonymous HalkyLauncher telemetry dashboard.">
  <?php if ($siteKey !== ''): ?><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif; ?>
  <style>
    :root{color-scheme:dark;--bg:#080a12;--panel:#111625;--panel2:#151c2e;--line:#26304a;--text:#f4f7ff;--muted:#98a5bf;--accent:#a78bfa;--accent2:#f472b6;--green:#6ee7b7;--yellow:#fcd34d;--shadow:0 24px 70px #0008;--radius:22px;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}*{box-sizing:border-box}body{margin:0;min-width:320px;background:radial-gradient(900px 500px at 10% -5%,#7c3aed35,transparent 65%),radial-gradient(850px 500px at 95% 0,#ec489928,transparent 60%),var(--bg);color:var(--text);line-height:1.5}a{color:inherit}.shell{width:min(1440px,calc(100% - 32px));margin:auto;padding:34px 0 70px}.hero,.panel,.metric{border:1px solid var(--line);background:linear-gradient(145deg,#ffffff0d,#ffffff04);box-shadow:var(--shadow);backdrop-filter:blur(16px)}.hero{display:flex;justify-content:space-between;gap:24px;align-items:end;padding:30px;border-radius:28px;margin-bottom:18px}.eyebrow{color:var(--accent);font-size:.75rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase}.hero h1{font-size:clamp(2rem,5vw,4rem);line-height:1;margin:12px 0}.hero p{color:var(--muted);max-width:720px;margin:0}.hero-actions{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border-radius:12px;border:1px solid var(--line);background:#ffffff09;color:var(--text);font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.btn:hover,.btn:focus-visible{border-color:var(--accent);background:#a78bfa18;outline:none}.btn.primary{border-color:transparent;background:linear-gradient(120deg,var(--accent),var(--accent2));color:#180b28}.panel{border-radius:var(--radius);padding:22px;margin-top:18px}.panel h2{font-size:1.05rem;margin:0 0 4px}.panel-head{display:flex;justify-content:space-between;gap:12px;align-items:start;margin-bottom:18px}.muted{color:var(--muted);font-size:.88rem}.filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.field label{display:block;font-size:.75rem;color:var(--muted);font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-bottom:7px}.field input,.field select{width:100%;min-height:42px;padding:9px 11px;border:1px solid var(--line);border-radius:11px;background:#080c17;color:var(--text);font:inherit}.field input:focus,.field select:focus{outline:2px solid var(--accent);outline-offset:1px}.filter-actions{display:flex;align-items:end;gap:10px}.metrics{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-top:18px}.metric{border-radius:18px;padding:18px}.metric .label{font-size:.72rem;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.08em}.metric .value{font-size:clamp(1.4rem,3vw,2.2rem);font-weight:900;margin-top:8px;word-break:break-word}.metric .hint{font-size:.76rem;color:var(--muted);margin-top:5px}.two-col{display:grid;grid-template-columns:1.15fr .85fr;gap:18px}.chart{display:grid;gap:10px}.bar-row{display:grid;grid-template-columns:minmax(70px,110px) 1fr 70px;gap:10px;align-items:center;font-size:.82rem}.bar-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.bar-track{height:12px;background:#ffffff0d;border-radius:99px;overflow:hidden}.bar-fill{height:100%;min-width:2px;border-radius:inherit;background:linear-gradient(90deg,var(--accent),var(--accent2));width:var(--width)}.bar-number{text-align:right;color:var(--muted);font-variant-numeric:tabular-nums}.legend{display:flex;gap:15px;flex-wrap:wrap;color:var(--muted);font-size:.8rem;margin-bottom:14px}.dot{width:9px;height:9px;border-radius:50%;display:inline-block;margin-right:5px}.dot.total{background:var(--accent)}.dot.runs{background:var(--green)}.dot.installs{background:var(--accent2)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:620px}th,td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left}th{color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.08em}td{font-size:.88rem}.pill{display:inline-flex;border-radius:99px;padding:4px 9px;font-size:.75rem;font-weight:800}.pill.run{color:var(--green);background:#6ee7b714}.pill.install{color:var(--accent2);background:#f472b614}.notice{padding:14px 16px;border-radius:14px;background:#fcd34d12;border:1px solid #fcd34d40;color:#fde68a}.error{background:#fb718518;border-color:#fb718550;color:#fecaca}.empty{text-align:center;padding:30px;color:var(--muted)}.pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px}.export{display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center}.export form{display:grid;gap:12px;justify-items:start}.export small{color:var(--muted)}.recaptcha-slot{min-height:78px}.footer-note{margin-top:20px;text-align:center;color:var(--muted);font-size:.75rem} :focus-visible{outline:2px solid var(--accent);outline-offset:3px}@media(max-width:1100px){.metrics{grid-template-columns:repeat(3,1fr)}.filters{grid-template-columns:repeat(2,1fr)}.two-col{grid-template-columns:1fr}}@media(max-width:650px){.shell{width:min(100% - 20px,1440px);padding-top:18px}.hero{display:block;padding:22px}.hero-actions{margin-top:20px}.filters,.metrics{grid-template-columns:1fr}.panel{padding:16px}.filter-actions{align-items:stretch;flex-direction:column}.export{grid-template-columns:1fr}.pagination{align-items:stretch;flex-direction:column}.pagination .btn{width:100%}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important;animation:none!important}}@media(forced-colors:active){.panel,.metric,.hero{border:1px solid ButtonText;box-shadow:none}.bar-fill{background:Highlight}}
  </style>
</head>
<body>
<main class="shell">
  <header class="hero">
    <div><div class="eyebrow">HalkyLauncher · public telemetry</div><h1>Telemetry overview</h1><p>Anonymous launcher events, summarized by date and version. Values include only players who enabled telemetry in the launcher.</p></div>
    <div class="hero-actions"><a class="btn" href="/">Back to website</a><a class="btn" href="telemetry-api.php">API JSON</a></div>
  </header>

  <?php if ($error): ?><div class="panel notice error" role="alert"><?php echo e($error); ?></div><?php endif; ?>

  <section class="panel" aria-labelledby="filters-title">
    <div class="panel-head"><div><h2 id="filters-title">Explore the data</h2><div class="muted">All filters are validated server-side. Invalid dates are ignored.</div></div><span class="muted"><?php echo number_format($totalEventRows); ?> matching rows</span></div>
    <form class="filters" method="get">
      <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?php echo e($filters['from'] ?? ''); ?>"></div>
      <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?php echo e($filters['to'] ?? ''); ?>"></div>
      <div class="field"><label for="type">Event type</label><select id="type" name="type"><option value="all" <?php echo $filters['type'] === 'all' ? 'selected' : ''; ?>>All events</option><option value="run" <?php echo $filters['type'] === 'run' ? 'selected' : ''; ?>>Runs</option><option value="first-run" <?php echo $filters['type'] === 'first-run' ? 'selected' : ''; ?>>First runs</option></select></div>
      <div class="field"><label for="version">Version</label><input id="version" name="version" maxlength="64" value="<?php echo e($filters['version']); ?>" placeholder="e.g. 1.2"></div>
      <div class="field"><label for="version_mode">Version match</label><select id="version_mode" name="version_mode"><option value="contains" <?php echo $filters['version_mode'] === 'contains' ? 'selected' : ''; ?>>Contains</option><option value="prefix" <?php echo $filters['version_mode'] === 'prefix' ? 'selected' : ''; ?>>Starts with</option><option value="exact" <?php echo $filters['version_mode'] === 'exact' ? 'selected' : ''; ?>>Exact</option></select></div>
      <div class="field"><label for="granularity">Chart grouping</label><select id="granularity" name="granularity"><option value="day" <?php echo $filters['granularity'] === 'day' ? 'selected' : ''; ?>>Day</option><option value="week" <?php echo $filters['granularity'] === 'week' ? 'selected' : ''; ?>>Week</option><option value="month" <?php echo $filters['granularity'] === 'month' ? 'selected' : ''; ?>>Month</option></select></div>
      <div class="field"><label for="top">Top versions</label><input id="top" name="top" type="number" min="3" max="50" value="<?php echo e($filters['top']); ?>"></div>
      <div class="field"><label for="page_size">Rows per page</label><select id="page_size" name="page_size"><option>10</option><option <?php echo $filters['page_size'] === 25 ? 'selected' : ''; ?>>25</option><option <?php echo $filters['page_size'] === 50 ? 'selected' : ''; ?>>50</option><option <?php echo $filters['page_size'] === 100 ? 'selected' : ''; ?>>100</option></select></div>
      <div class="filter-actions"><button class="btn primary" type="submit">Apply filters</button><a class="btn" href="telemetry-view.php">Reset</a></div>
    </form>
  </section>

  <section class="metrics" aria-label="Summary metrics">
    <article class="metric"><div class="label">Total events</div><div class="value"><?php echo number_format($totalEvents); ?></div><div class="hint">Matching records</div></article>
    <article class="metric"><div class="label">Runs</div><div class="value"><?php echo number_format($totalRuns); ?></div><div class="hint">event_type = run</div></article>
    <article class="metric"><div class="label">First runs</div><div class="value"><?php echo number_format($totalInstalls); ?></div><div class="hint">event_type = first-run</div></article>
    <article class="metric"><div class="label">Versions</div><div class="value"><?php echo number_format($totalVersions); ?></div><div class="hint">Unique versions</div></article>
    <article class="metric"><div class="label">First event</div><div class="value" style="font-size:1.1rem"><?php echo e(formatDate($summary['first_seen'] ?? null)); ?></div><div class="hint">Earliest matching event</div></article>
    <article class="metric"><div class="label">Latest event</div><div class="value" style="font-size:1.1rem"><?php echo e(formatDate($summary['last_seen'] ?? null)); ?></div><div class="hint">Latest matching event</div></article>
  </section>

  <section class="two-col">
    <article class="panel"><div class="panel-head"><div><h2>Event timeline</h2><div class="muted">Grouped by <?php echo e($filters['granularity']); ?></div></div></div><div class="legend"><span><i class="dot total"></i>Total events</span><span><i class="dot runs"></i>Runs</span><span><i class="dot installs"></i>First runs</span></div><div class="chart" role="img" aria-label="Event totals by time period"><?php if ($timeline): foreach ($timeline as $row): ?><div class="bar-row"><span class="bar-label"><?php echo e($row['period']); ?></span><div class="bar-track"><div class="bar-fill" style="--width:<?php echo max(2, round(numberValue($row['total']) / $maxTimeline * 100)); ?>%"></div></div><span class="bar-number"><?php echo number_format(numberValue($row['total'])); ?></span></div><?php endforeach; else: ?><div class="empty">No events match these filters.</div><?php endif; ?></div></article>
    <article class="panel"><div class="panel-head"><div><h2>Top versions</h2><div class="muted">Up to <?php echo number_format($filters['top']); ?> versions</div></div></div><div class="chart" aria-label="Events by version"><?php if ($versions): foreach ($versions as $row): ?><div class="bar-row"><span class="bar-label" title="<?php echo e($row['version']); ?>"><?php echo e($row['version']); ?></span><div class="bar-track"><div class="bar-fill" style="--width:<?php echo max(2, round(numberValue($row['total']) / $maxVersion * 100)); ?>%"></div></div><span class="bar-number"><?php echo number_format(numberValue($row['total'])); ?></span></div><?php endforeach; else: ?><div class="empty">No versions match these filters.</div><?php endif; ?></div></article>
  </section>

  <section class="panel"><div class="panel-head"><div><h2>Version breakdown</h2><div class="muted">Runs and first runs for each version</div></div></div><div class="table-wrap"><table><thead><tr><th>Version</th><th>Total</th><th>Runs</th><th>First runs</th><th>First seen</th><th>Latest</th></tr></thead><tbody><?php foreach ($versions as $row): ?><tr><td><strong><?php echo e($row['version']); ?></strong></td><td><?php echo number_format(numberValue($row['total'])); ?></td><td><span class="pill run"><?php echo number_format(numberValue($row['runs'])); ?></span></td><td><span class="pill install"><?php echo number_format(numberValue($row['installs'])); ?></span></td><td class="muted"><?php echo e(formatDate($row['first_seen'] ?? null)); ?></td><td class="muted"><?php echo e(formatDate($row['last_seen'] ?? null)); ?></td></tr><?php endforeach; ?><?php if (!$versions): ?><tr><td colspan="6" class="empty">No version data available.</td></tr><?php endif; ?></tbody></table></div></section>

  <section class="panel"><div class="panel-head"><div><h2>Recent matching events</h2><div class="muted">Newest events first · page <?php echo min($filters['page'], $totalPages); ?> of <?php echo $totalPages; ?></div></div></div><div class="table-wrap"><table><thead><tr><th>Type</th><th>Version</th><th>Created at</th></tr></thead><tbody><?php foreach ($events as $row): ?><tr><td><span class="pill <?php echo $row['event_type'] === 'run' ? 'run' : 'install'; ?>"><?php echo e($row['event_type']); ?></span></td><td><?php echo e($row['version']); ?></td><td class="muted"><?php echo e(formatDate($row['created_at'] ?? null)); ?></td></tr><?php endforeach; ?><?php if (!$events): ?><tr><td colspan="3" class="empty">No event data available.</td></tr><?php endif; ?></tbody></table></div><nav class="pagination" aria-label="Event pages"><a class="btn" href="<?php echo e(queryUrl(['page' => max(1, $filters['page'] - 1)])); ?>">Previous</a><span class="muted">Showing <?php echo number_format(count($events)); ?> of <?php echo number_format($totalEventRows); ?></span><a class="btn" href="<?php echo e(queryUrl(['page' => min($totalPages, $filters['page'] + 1)])); ?>">Next</a></nav></section>

  <section class="panel export">
    <div><h2>Download telemetry SQL</h2></div>
    <form method="post" action="telemetry-export.php">
      <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
      <?php foreach (['from', 'to', 'type', 'version', 'version_mode'] as $key): ?>
        <input type="hidden" name="<?php echo e($key); ?>" value="<?php echo e($filters[$key]); ?>">
      <?php endforeach; ?>
      <?php if ($siteKey !== ''): ?>
        <div class="recaptcha-slot">
          <div class="g-recaptcha" data-sitekey="<?php echo e($siteKey); ?>"></div>
        </div>
      <?php endif; ?>
      <button class="btn primary" type="submit">
        <?php echo $siteKey !== '' ? 'Verify and download SQL' : 'Download SQL'; ?>
      </button>
    </form>
  </section>
  <p class="footer-note">Telemetry is anonymous and opt-in. The dashboard shows only events received from launchers with telemetry enabled.</p>
</main>
</body>
</html>
