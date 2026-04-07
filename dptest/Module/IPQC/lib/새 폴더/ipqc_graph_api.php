<?php
// IPQC OMM Graph API (JMP-style)
// - returns JSON only (even on fatal errors)
// - supports multi-select: tools[] / cavities[] / keys[] (max 4)

// --- Force JSON on fatal errors too
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);
if (!headers_sent()) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
if (!ob_get_level()) { ob_start(); }
set_error_handler(function($severity, $message, $file, $line){
  // Convert warnings/notices to exceptions so we can emit JSON
  throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function(){
  $err = error_get_last();
  if (!$err) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array($err['type'], $fatalTypes, true)) return;
  while (ob_get_level()) { @ob_end_clean(); }
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode([
    'ok' => 0,
    'err' => 'FATAL',
    'message' => $err['message'],
    'file' => $err['file'],
    'line' => $err['line'],
  ], JSON_UNESCAPED_UNICODE);
});

// --- Project bootstrap
require_once __DIR__ . '/../../../config/dp_config.php';

// Ensure PHP session is available for AUTH checks (wrappers under /public/legacy may hit this directly)
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
require_once __DIR__ . '/../../../lib/auth_guard.php';

function jx($ok, $payload = [], $code = 200) {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => $ok ? 1 : 0], $payload), JSON_UNESCAPED_UNICODE);
  exit;
}

function csv_list($input): array {
  if ($input === null) return [];
  if (is_array($input)) {
    $out = [];
    foreach ($input as $v) {
      $v = trim((string)$v);
      if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
  }
  $s = trim((string)$input);
  if ($s === '') return [];
  $parts = preg_split('/\s*,\s*/', $s);
  $out = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p !== '') $out[] = $p;
  }
  return array_values(array_unique($out));
}

function normalize_cavity_to_int($v): ?int {
  if ($v === null) return null;
  if (is_int($v)) return $v;
  $s = trim((string)$v);
  if ($s === '') return null;
  // allow: 2, "2CAV", "CAV2" etc.
  if (preg_match('/(\d+)/', $s, $m)) {
    $n = (int)$m[1];
    if ($n >= 1 && $n <= 4) return $n;
  }
  return null;
}

function normalize_cavity_labels(array $cavityInts): array {
  $out = [];
  foreach ($cavityInts as $n) {
    $n = (int)$n;
    if ($n >= 1 && $n <= 4) $out[] = $n . 'CAV';
  }
  return $out;
}

function dateRangeFromYearMonths(array $years, array $months): array {
  // Returns [startDateTime, endDateTime)
  if (!$years || !$months) {
    // default: last 12 months
    $end = new DateTime('tomorrow');
    $start = (clone $end)->modify('-365 days');
    return [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 00:00:00')];
  }
  $minY = min(array_map('intval', $years));
  $maxY = max(array_map('intval', $years));
  $minM = min(array_map('intval', $months));
  $maxM = max(array_map('intval', $months));

  $start = DateTime::createFromFormat('Y-n-j H:i:s', $minY . '-' . $minM . '-1 00:00:00');
  $end = DateTime::createFromFormat('Y-n-j H:i:s', $maxY . '-' . $maxM . '-1 00:00:00');
  if (!$start || !$end) {
    $end = new DateTime('tomorrow');
    $start = (clone $end)->modify('-365 days');
    return [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 00:00:00')];
  }
  $end->modify('+1 month');
  return [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 00:00:00')];
}


/**
 * Determine whether the current request is authenticated.
 * We avoid hard-exiting guards (HTML redirects) and instead return JSON.
 */
function ipqc_api_is_logged_in(): bool {
  // Prefer project-provided helpers if available
  foreach (['dp_auth_is_logged_in','dp_is_logged_in','is_logged_in'] as $fn) {
    if (function_exists($fn)) {
      try { if ((bool)call_user_func($fn)) return true; } catch (Throwable $e) {}
    }
  }

  // Some projects expose a "current user" getter
  foreach (['dp_auth_user','dp_current_user','current_user','dp_get_login_user'] as $fn) {
    if (function_exists($fn)) {
      try {
        $u = call_user_func($fn);
        if (is_array($u) ? count($u) > 0 : !empty($u)) return true;
      } catch (Throwable $e) {}
    }
  }

  // Fallback: common session keys (include JTMES login session keys)
  $keys = [
    // JTMES (ShipingList) login
    'ship_user_id','ship_user_name',

    // generic
    'user','user_id','uid','username','login','login_user','member','admin',
    'auth','dp_user','dp_user_id','account','account_id'
  ];
  foreach ($keys as $k) {
    if (!isset($_SESSION[$k])) continue;
    $v = $_SESSION[$k];
    if (is_array($v)) { if (count($v) > 0) return true; }
    else if ($v !== '' && $v !== 0 && $v !== '0' && $v !== null) return true;
  }
  return false;
}

try {
  if (!ipqc_api_is_logged_in()) {
    jx(false, ['err' => 'AUTH', 'message' => '로그인이 필요합니다.'], 401);
  }

  $mode = strtolower(trim((string)($_GET['mode'] ?? 'meta')));
  $part = trim((string)($_GET['part_name'] ?? ($_GET['part'] ?? '')));
  if ($part === '') {
    jx(false, ['err' => 'NO_PART', 'message' => 'part_name(part) 파라미터가 필요합니다.'], 400);
  }

  $years  = csv_list($_GET['years']  ?? null);
  $months = csv_list($_GET['months'] ?? null);
  [$dateFrom, $dateTo] = dateRangeFromYearMonths($years, $months);

  // Multi-select tool/cavity
  $toolsIn = csv_list($_GET['tools'] ?? ($_GET['tool'] ?? null));

  $cavityInts = [];
  $cavIn = csv_list($_GET['cavities'] ?? null);
  if (!$cavIn) {
    // legacy: cavity=ALL or cavity=2 or cavity=2CAV
    $legacyCav = $_GET['cavity'] ?? null;
    if ($legacyCav !== null && !is_array($legacyCav)) {
      $legacyCavStr = trim((string)$legacyCav);
      if ($legacyCavStr !== '' && !in_array(strtoupper($legacyCavStr), ['ALL','전체'], true)) {
        $n = normalize_cavity_to_int($legacyCavStr);
        if ($n !== null) $cavityInts[] = $n;
      }
    }
  } else {
    foreach ($cavIn as $c) {
      if (in_array(strtoupper($c), ['ALL','전체'], true)) continue;
      $n = normalize_cavity_to_int($c);
      if ($n !== null) $cavityInts[] = $n;
    }
  }
  $cavityInts = array_values(array_unique(array_filter($cavityInts, fn($x)=>$x!==null)));

  $pdo = dp_get_pdo();

  if ($mode === 'facets') {
    // tool list + cavity list for the current range
    $w = ['h.part_name=?', 'h.meas_date >= ?', 'h.meas_date < ?'];
    $args = [$part, $dateFrom, $dateTo];

    // (optional) key restriction if caller provides keys
    $keysIn = csv_list($_GET['keys'] ?? ($_GET['fais'] ?? null));
    if ($keysIn) {
      $in = implode(',', array_fill(0, count($keysIn), '?'));
      $w[] = "m.fai IN ($in)";
      $args = array_merge($args, $keysIn);
    }

    $where = implode(' AND ', $w);

    $sqlTotal = "SELECT COUNT(*) AS cnt
      FROM ipqc_omm_header h
      JOIN ipqc_omm_measurements m ON m.header_id=h.id
      WHERE $where";
    $st = $pdo->prepare($sqlTotal);
    $st->execute($args);
    $total = (int)$st->fetchColumn();

    $sqlTools = "SELECT h.tool AS v, COUNT(*) AS cnt
      FROM ipqc_omm_header h
      JOIN ipqc_omm_measurements m ON m.header_id=h.id
      WHERE $where
      GROUP BY h.tool
      ORDER BY h.tool";
    $st = $pdo->prepare($sqlTools);
    $st->execute($args);
    $tools = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $tools[] = ['value' => (string)$r['v'], 'count' => (int)$r['cnt']];
    }

    $sqlCav = "SELECT h.cavity AS v, COUNT(*) AS cnt
      FROM ipqc_omm_header h
      JOIN ipqc_omm_measurements m ON m.header_id=h.id
      WHERE $where
      GROUP BY h.cavity
      ORDER BY h.cavity";
    $st = $pdo->prepare($sqlCav);
    $st->execute($args);
    $cavities = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $n = normalize_cavity_to_int($r['v']);
      if ($n === null) continue;
      $cavities[] = ['value' => $n . 'CAV', 'count' => (int)$r['cnt']];
    }

    jx(true, [
      'mode' => 'facets',
      'part_name' => $part,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'total_rows' => $total,
      'tools' => $tools,
      'cavities' => $cavities,
    ]);
  }

  if ($mode === 'meta') {
    // keys list (FAI/SPC)
    $w = ['h.part_name=?', 'h.meas_date >= ?', 'h.meas_date < ?'];
    $args = [$part, $dateFrom, $dateTo];

    if ($toolsIn) {
      $in = implode(',', array_fill(0, count($toolsIn), '?'));
      $w[] = "h.tool IN ($in)";
      $args = array_merge($args, $toolsIn);
    }
    if ($cavityInts) {
      $in = implode(',', array_fill(0, count($cavityInts), '?'));
      $w[] = "h.cavity IN ($in)";
      $args = array_merge($args, $cavityInts);
    }

    $where = implode(' AND ', $w);

    $sql = "SELECT m.fai AS k, COUNT(*) AS cnt
      FROM ipqc_omm_header h
      JOIN ipqc_omm_measurements m ON m.header_id=h.id
      WHERE $where
      GROUP BY m.fai
      ORDER BY cnt DESC
      LIMIT 300";

    $st = $pdo->prepare($sql);
    $st->execute($args);

    $items = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $items[] = ['key' => (string)$r['k'], 'count' => (int)$r['cnt']];
    }

    jx(true, [
      'mode' => 'meta',
      'part_name' => $part,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'items' => $items,
    ]);
  }

  if ($mode === 'series') {
    $keysIn = csv_list($_GET['keys'] ?? ($_GET['fais'] ?? null));
    if (!$keysIn) {
      jx(false, ['err' => 'NO_KEYS', 'message' => 'keys(또는 fais) 파라미터가 필요합니다.'], 400);
    }
    $keysIn = array_slice($keysIn, 0, 4);

    // If caller did not pass tools/cavities, do not restrict (API will still group by tool/cavity)

    $w = ['h.part_name=?', 'h.meas_date >= ?', 'h.meas_date < ?'];
    $args = [$part, $dateFrom, $dateTo];

    if ($toolsIn) {
      $in = implode(',', array_fill(0, count($toolsIn), '?'));
      $w[] = "h.tool IN ($in)";
      $args = array_merge($args, $toolsIn);
    }
    if ($cavityInts) {
      $in = implode(',', array_fill(0, count($cavityInts), '?'));
      $w[] = "h.cavity IN ($in)";
      $args = array_merge($args, $cavityInts);
    }

    $in = implode(',', array_fill(0, count($keysIn), '?'));
    $w[] = "m.fai IN ($in)";
    $args = array_merge($args, $keysIn);

    $where = implode(' AND ', $w);

    $sql = "SELECT
        h.tool AS tool,
        h.cavity AS cavity,
        DATE(h.meas_date) AS d,
        m.fai AS k,
        AVG(m.mean) AS mean,
        MIN(m.minv) AS minv,
        MAX(m.maxv) AS maxv
      FROM ipqc_omm_header h
      JOIN ipqc_omm_measurements m ON m.header_id=h.id
      WHERE $where
      GROUP BY h.tool, h.cavity, d, k
      ORDER BY h.tool, h.cavity, d, k";

    $st = $pdo->prepare($sql);
    $st->execute($args);

    $rows = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $cavInt = normalize_cavity_to_int($r['cavity']);
      $rows[] = [
        'key' => (string)$r['k'],
        'tool' => (string)$r['tool'],
        'cavity' => $cavInt === null ? '' : ($cavInt . 'CAV'),
        'date' => (string)$r['d'],
        'mean' => $r['mean'] === null ? null : (float)$r['mean'],
        'min'  => $r['minv'] === null ? null : (float)$r['minv'],
        'max'  => $r['maxv'] === null ? null : (float)$r['maxv'],
      ];
    }

    jx(true, [
      'mode' => 'series',
      'part_name' => $part,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'tools' => $toolsIn,
      'cavities' => normalize_cavity_labels($cavityInts),
      'keys' => $keysIn,
      'rows' => $rows,
    ]);
  }

  jx(false, ['err' => 'BAD_MODE', 'message' => '지원하지 않는 mode 입니다.'], 400);

} catch (Throwable $e) {
  // Ensure JSON even for thrown errors
  while (ob_get_level()) { @ob_end_clean(); }
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => 0,
    'err' => 'EXCEPTION',
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}