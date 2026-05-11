<?php
// MSOP PDF viewer for IPQC viewer (separate window, no modal).
// Finds the latest Rev PDF with the same model-key logic used by the legacy JMP Assist MSOP flow.
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }

session_start();
$ROOT = JTMES_ROOT;

require_once $ROOT . '/config/dp_config.php';
require_once $ROOT . '/lib/auth_guard.php';

if (function_exists('dp_auth_guard')) {
  dp_auth_guard();
} elseif (function_exists('require_login')) {
  require_login();
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function msop_client_ip(): string {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  if (strpos($ip, '::ffff:') === 0) $ip = substr($ip, 7);
  return trim($ip);
}

function msop_ip_in_cidr(string $ip, string $cidr): bool {
  if (strpos($cidr, '/') === false) return $ip === $cidr;
  [$subnet, $mask] = explode('/', $cidr, 2);
  $mask = (int)$mask;
  $ipLong = ip2long($ip);
  $subLong = ip2long($subnet);
  if ($ipLong === false || $subLong === false || $mask < 0 || $mask > 32) return false;
  $maskLong = $mask === 0 ? 0 : ((-1 << (32 - $mask)) & 0xFFFFFFFF);
  return (($ipLong & $maskLong) === ($subLong & $maskLong));
}

function msop_is_private_lan_ip(string $ip): bool {
  if ($ip === '127.0.0.1' || $ip === '::1') return true;
  if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
  return msop_ip_in_cidr($ip, '10.0.0.0/8')
      || msop_ip_in_cidr($ip, '172.16.0.0/12')
      || msop_ip_in_cidr($ip, '192.168.0.0/16');
}

function msop_access_allowed(): bool {
  $ip = msop_client_ip();
  // MSOP은 민감 문서이므로 내부망/허용 공인 IP에서만 접근 허용.
  // 필요 시 config/dp_config.php에서 MSOP_ALLOWED_IPS 상수를 쉼표 구분으로 재정의 가능.
  if (!defined('MSOP_ALLOWED_IPS')) {
    define('MSOP_ALLOWED_IPS', '220.74.62.141,127.0.0.1,::1');
  }
  if (!defined('MSOP_ALLOW_PRIVATE_LAN')) {
    define('MSOP_ALLOW_PRIVATE_LAN', true);
  }

  $allowed = array_filter(array_map('trim', explode(',', (string)MSOP_ALLOWED_IPS)), function($v){ return $v !== ''; });
  foreach ($allowed as $rule) {
    if (strpos($rule, '/') !== false) {
      if (msop_ip_in_cidr($ip, $rule)) return true;
    } elseif ($ip === $rule) {
      return true;
    }
  }
  if (MSOP_ALLOW_PRIVATE_LAN && msop_is_private_lan_ip($ip)) return true;
  return false;
}

if (!msop_access_allowed()) {
  http_response_code(403);
  header('Content-Type: text/html; charset=utf-8');
  $clientIp = h(msop_client_ip());
  echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>MSOP 접근 제한</title>';
  echo '<style>html,body{margin:0;height:100%;background:#06150b;color:#d8ffe0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,"Noto Sans KR",sans-serif}body{display:flex;align-items:center;justify-content:center}.box{width:min(620px,calc(100% - 32px));padding:24px;border:1px solid rgba(255,255,255,.14);border-radius:16px;background:rgba(0,0,0,.55);box-shadow:0 12px 40px rgba(0,0,0,.35)}h1{margin:0 0 10px;font-size:24px}.muted{color:rgba(255,255,255,.68);font-size:13px;line-height:1.6}.ip{display:inline-block;margin-top:10px;padding:6px 10px;border-radius:999px;border:1px solid rgba(255,82,82,.45);color:#ffb7b7;background:rgba(0,0,0,.3)}</style>';
  echo '</head><body><div class="box"><h1>MSOP 접근 제한</h1><div class="muted">MSOP 문서는 내부망 또는 허용된 IP에서만 볼 수 있습니다.<br>접속 네트워크를 확인해주세요.</div><div class="ip">현재 접속 IP: ' . $clientIp . '</div></div></body></html>';
  exit;
}

// 기본 경로. MSOP PDF 원본은 웹루트 밖에 둔다. 필요하면 config/dp_config.php에서 MSOP_ROOT_PATH 상수로 덮어쓸 수 있다.
if (!defined('MSOP_ROOT_PATH')) {
  define('MSOP_ROOT_PATH', 'D:\JTMES_PRIVATE\MSOP');
}

function msop_norm_key($s): string {
  return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$s));
}

function msop_model_candidates(string $model): array {
  $raw = trim($model);
  $alias = [
    'MEM-IR-BASE'       => 'IR BASE',
    'MEM-X-CARRIER'     => 'X CARRIER',
    'MEM-Y-CARRIER'     => 'Y CARRIER',
    'MEM-Z-CARRIER'     => 'Z CARRIER',
    'MEM-Z-STOPPER'     => 'Z STOPPER',
    'MEM-Z-STOPPERPPER' => 'Z STOPPER',
  ];
  $u = strtoupper($raw);
  if (isset($alias[$u])) $raw = $alias[$u];

  $tokens = preg_split('/[\s_\-\/]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
  $tokens = array_values(array_filter($tokens, function($t) {
    $u = strtoupper((string)$t);
    return !in_array($u, ['MEMPHIS', 'MEM'], true);
  }));

  $joined = implode('', $tokens);
  $keys = [];
  foreach ([$joined, $raw] as $v) {
    $k = msop_norm_key($v);
    if ($k !== '') $keys[] = $k;
  }

  $compact = msop_norm_key($joined);
  if ($compact === 'IRBASE') $keys = array_merge(['IRBASE', 'MEMIRBASE'], $keys);
  if ($compact === 'XCARRIER') $keys = array_merge(['XCARRIER', 'MEMXCARRIER'], $keys);
  if ($compact === 'YCARRIER') $keys = array_merge(['YCARRIER', 'MEMYCARRIER'], $keys);
  if ($compact === 'ZCARRIER') $keys = array_merge(['ZCARRIER', 'MEMZCARRIER'], $keys);
  if ($compact === 'ZSTOPPER') $keys = array_merge(['ZSTOPPER', 'MEMZSTOPPER'], $keys);

  return array_values(array_unique(array_filter($keys)));
}

function msop_rev_info(string $filename): array {
  // 파일명 예: Memphis A3.0 IR Base..., ... REV_02 ...
  // REV 표기가 있으면 우선, 없으면 A2.0/A3.1/A4.0 같은 문서 버전을 최신 판별에 사용한다.
  if (preg_match('/REV[._\-\s]?(\d+)/i', $filename, $m)) {
    $n = (int)$m[1];
    return ['score' => 100000 + $n, 'label' => 'REV ' . $n];
  }
  if (preg_match('/(^|[^A-Z0-9])A\s*(\d+)\s*[._-]\s*(\d+)/i', $filename, $m)) {
    $maj = (int)$m[2];
    $min = (int)$m[3];
    return ['score' => ($maj * 1000) + $min, 'label' => 'A' . $maj . '.' . $min];
  }
  if (preg_match('/(^|[^A-Z0-9])R\s*(\d+)/i', $filename, $m)) {
    $n = (int)$m[2];
    return ['score' => $n, 'label' => 'R' . $n];
  }
  return ['score' => 0, 'label' => ''];
}

function msop_find_latest_pdf(string $model): array {
  $root = MSOP_ROOT_PATH;
  $keys = msop_model_candidates($model);
  $out = [
    'ok' => false,
    'root' => $root,
    'keys' => $keys,
    'path' => '',
    'file' => '',
    'rev' => null,
    'error' => '',
  ];

  if ($root === '' || !is_dir($root)) {
    $out['error'] = 'MSOP 폴더를 찾을 수 없습니다.';
    return $out;
  }
  if (empty($keys)) {
    $out['error'] = '모델 키를 만들 수 없습니다.';
    return $out;
  }

  $bestPath = '';
  $bestFile = '';
  $bestRevScore = -1;
  $bestRevLabel = null;
  $bestMtime = -1;

  $items = @scandir($root);
  if (!is_array($items)) {
    $out['error'] = 'MSOP 폴더 목록을 읽을 수 없습니다.';
    return $out;
  }

  foreach ($items as $fn) {
    if ($fn === '.' || $fn === '..') continue;
    if (!preg_match('/\.pdf$/i', $fn)) continue;
    $nameKey = msop_norm_key($fn);
    $matched = false;
    foreach ($keys as $key) {
      if ($key !== '' && strpos($nameKey, $key) !== false) { $matched = true; break; }
    }
    if (!$matched) continue;

    $path = rtrim($root, "\\/") . DIRECTORY_SEPARATOR . $fn;
    if (!is_file($path) || !is_readable($path)) continue;

    $revInfo = msop_rev_info($fn);
    $revScore = (int)($revInfo['score'] ?? 0);
    $revLabel = (string)($revInfo['label'] ?? '');
    $mtime = (int)@filemtime($path);
    if ($revScore > $bestRevScore || ($revScore === $bestRevScore && $mtime > $bestMtime)) {
      $bestPath = $path;
      $bestFile = $fn;
      $bestRevScore = $revScore;
      $bestRevLabel = ($revLabel !== '' ? $revLabel : null);
      $bestMtime = $mtime;
    }
  }

  if ($bestPath === '') {
    $out['error'] = '해당 모델의 MSOP PDF를 찾지 못했습니다.';
    return $out;
  }

  $out['ok'] = true;
  $out['path'] = $bestPath;
  $out['file'] = $bestFile;
  $out['rev'] = $bestRevLabel;
  return $out;
}

function msop_extract_fai_no(string $fai): string {
  if (preg_match('/(\d+)/', $fai, $m)) {
    $n = ltrim($m[1], '0');
    return $n === '' ? '0' : $n;
  }
  return '';
}

function msop_fai_list_from_request(): array {
  $raw = $_GET['fai'] ?? [];
  $vals = [];
  $push = function($v) use (&$vals) {
    if (is_array($v)) {
      foreach ($v as $vv) {
        $s = trim((string)$vv);
        if ($s !== '') $vals[] = $s;
      }
      return;
    }
    $s = trim((string)$v);
    if ($s === '') return;
    // Manual input fallback: comma, line break, pipe, slash-separated list syntax is tolerated.
    $parts = preg_split('/\s*(?:\|\||[,;\r\n]+)\s*/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($parts) && count($parts) > 1) {
      foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') $vals[] = $part;
      }
    } else {
      $vals[] = $s;
    }
  };
  $push($raw);

  $out = [];
  $seen = [];
  foreach ($vals as $v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '__ALL__') continue;
    if (isset($seen[$v])) continue;
    $seen[$v] = true;
    $out[] = $v;
  }
  return array_slice($out, 0, 80);
}

function msop_fai_join(array $faiList): string {
  return implode(', ', array_values(array_filter(array_map('strval', $faiList), function($v){ return trim($v) !== ''; })));
}

function msop_model_to_mapkey(string $model): string {
  $m = trim($model);
  if ($m === '') return '';
  if (preg_match('/z\s*[-_]?\s*stopper/i', $m)) return 'ZSTOPPER';
  if (preg_match('/z\s*[-_]?\s*carrier/i', $m)) return 'ZCARRIER';
  if (preg_match('/y\s*[-_]?\s*carrier/i', $m)) return 'YCARRIER';
  if (preg_match('/x\s*[-_]?\s*carrier/i', $m)) return 'XCARRIER';
  if (preg_match('/ir\s*[-_]?\s*base/i', $m)) return 'IRBASE';
  return '';
}

function msop_order_map(): array {
  static $map = null;
  if ($map !== null) return $map;
  $map = [];
  $cands = [JTMES_ROOT . '/lib/ipqc_order_map.php', JTMES_ROOT . '/ipqc_order_map.php'];
  foreach ($cands as $file) {
    if (is_file($file)) {
      $tmp = include $file;
      if (is_array($tmp)) $map = $tmp;
      break;
    }
  }
  return $map;
}

function msop_fai_options_for_model_type(string $model, string $type, array $selected): array {
  $type = strtoupper(trim($type));
  if (!in_array($type, ['AOI','OMM','REAL_OMM','CMM','OQC'], true)) $type = 'OMM';
  $orderType = ($type === 'REAL_OMM') ? 'AOI' : $type;
  $mk = msop_model_to_mapkey($model);
  $vals = [];

  $map = msop_order_map();
  if ($mk !== '' && isset($map[$mk]) && is_array($map[$mk]) && isset($map[$mk][$orderType]) && is_array($map[$mk][$orderType])) {
    foreach ($map[$mk][$orderType] as $v) {
      if (is_array($v)) continue;
      $s = trim((string)$v);
      if ($s !== '' && $s !== '__ALL__') $vals[] = $s;
    }
  }

  foreach ($selected as $v) {
    $s = trim((string)$v);
    if ($s !== '' && $s !== '__ALL__') $vals[] = $s;
  }

  $out = [];
  $seen = [];
  foreach ($vals as $v) {
    if (isset($seen[$v])) continue;
    $seen[$v] = true;
    $out[] = $v;
  }
  return array_slice($out, 0, 800);
}

function msop_fai_is_selected(string $value, array $selected): bool {
  foreach ($selected as $v) {
    if ((string)$v === $value) return true;
  }
  return false;
}

function msop_fai_summary_label(array $faiList): string {
  $faiList = array_values(array_filter(array_map('strval', $faiList), function($v){ return trim($v) !== ''; }));
  $n = count($faiList);
  if ($n === 0) return '(선택 없음)';
  if ($n === 1) return $faiList[0];
  return $faiList[0] . ' 외 ' . ($n - 1) . '개';
}

function msop_shell_enabled(): bool {
  $disabled = (string)ini_get('disable_functions');
  foreach (['shell_exec', 'exec'] as $fn) {
    if (!function_exists($fn)) return false;
    if ($disabled !== '' && preg_match('/(^|,)\s*' . preg_quote($fn, '/') . '\s*(,|$)/i', $disabled)) return false;
  }
  return true;
}

function msop_find_python(): string {
  if (defined('MSOP_PYTHON_BIN') && trim((string)MSOP_PYTHON_BIN) !== '') {
    return trim((string)MSOP_PYTHON_BIN);
  }
  if (!msop_shell_enabled()) return '';
  $isWin = (stripos(PHP_OS, 'WIN') === 0);
  $candidates = $isWin ? ['py -3', 'python', 'python3'] : ['python3', 'python'];
  foreach ($candidates as $cmdName) {
    $cmd = $isWin ? ($cmdName . ' --version 2>&1') : ('command -v ' . escapeshellarg($cmdName) . ' 2>/dev/null && ' . escapeshellarg($cmdName) . ' --version 2>&1');
    $res = @shell_exec($cmd);
    if (is_string($res) && preg_match('/Python\s+\d+/i', $res)) return $cmdName;
  }
  return '';
}

function msop_cache_dir(): string {
  if (defined('MSOP_CACHE_DIR') && trim((string)MSOP_CACHE_DIR) !== '') {
    $dir = trim((string)MSOP_CACHE_DIR);
  } else {
    $dir = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'jtmes_msop_cache';
  }
  if (!is_dir($dir)) @mkdir($dir, 0770, true);
  return $dir;
}

function msop_extract_fai_pdf(string $pdfPath, array $faiList): array {
  $ret = ['ok' => false, 'page' => null, 'path' => '', 'error' => ''];
  $faiList = array_values(array_filter(array_map('trim', $faiList), function($v){ return $v !== '' && $v !== '__ALL__'; }));
  if (empty($faiList)) {
    $ret['error'] = 'FAI 선택값이 없습니다.';
    return $ret;
  }
  if (!is_file($pdfPath) || !is_readable($pdfPath)) {
    $ret['error'] = 'PDF 파일을 읽을 수 없습니다.';
    return $ret;
  }
  if (!msop_shell_enabled()) {
    $ret['error'] = '서버에서 외부 실행이 비활성화되어 있습니다.';
    return $ret;
  }

  $helper = __DIR__ . DIRECTORY_SEPARATOR . 'ipqc_msop_extract_page.py';
  if (!is_file($helper)) {
    $ret['error'] = 'MSOP Python helper 파일이 없습니다.';
    return $ret;
  }

  $python = msop_find_python();
  if ($python === '') {
    $ret['error'] = 'Python 실행 파일을 찾지 못했습니다.';
    return $ret;
  }

  $cacheDir = msop_cache_dir();
  if ($cacheDir === '' || !is_dir($cacheDir) || !is_writable($cacheDir)) {
    $ret['error'] = 'MSOP 캐시 폴더에 쓸 수 없습니다.';
    return $ret;
  }

  $labelJson = json_encode($faiList, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if (!is_string($labelJson) || $labelJson === '') $labelJson = json_encode([msop_fai_join($faiList)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  $nums = [];
  foreach ($faiList as $lab) {
    $num = msop_extract_fai_no($lab);
    if ($num !== '') $nums[] = $num;
  }
  $numKey = implode('_', array_values(array_unique($nums)));
  if ($numKey === '') $numKey = sha1(msop_fai_join($faiList));

  $key = sha1($pdfPath . '|' . (string)@filemtime($pdfPath) . '|' . $labelJson);
  $outPath = rtrim($cacheDir, "\\/") . DIRECTORY_SEPARATOR . 'msop_' . $key . '.pdf';
  if (is_file($outPath) && filesize($outPath) > 0) {
    $ret['ok'] = true;
    $ret['path'] = $outPath;
    $ret['page'] = null;
    return $ret;
  }

  $cmd = $python . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($labelJson) . ' ' . escapeshellarg($outPath) . ' 2>&1';
  $json = @shell_exec($cmd);
  if (!is_string($json) || trim($json) === '') {
    $ret['error'] = 'MSOP helper 실행 결과가 없습니다.';
    return $ret;
  }

  $data = json_decode(trim($json), true);
  if (!is_array($data)) {
    $ret['error'] = 'MSOP helper 결과를 해석하지 못했습니다: ' . mb_substr(trim($json), 0, 300, 'UTF-8');
    return $ret;
  }
  if (!empty($data['ok']) && !empty($data['out']) && is_file((string)$data['out']) && filesize((string)$data['out']) > 0) {
    $ret['ok'] = true;
    $ret['page'] = isset($data['page']) ? (int)$data['page'] : null;
    $ret['pages'] = isset($data['pages']) && is_array($data['pages']) ? $data['pages'] : [];
    $ret['missing'] = isset($data['missing']) && is_array($data['missing']) ? $data['missing'] : [];
    $ret['path'] = (string)$data['out'];
    return $ret;
  }

  $ret['error'] = (string)($data['error'] ?? 'FAI 페이지를 찾지 못했습니다.');
  return $ret;
}

$model = trim((string)($_GET['model'] ?? ''));
$type = strtoupper(trim((string)($_GET['type'] ?? 'OMM')));
if (!in_array($type, ['AOI','OMM','REAL_OMM','CMM','OQC'], true)) $type = 'OMM';
$faiList = msop_fai_list_from_request();
$faiOptions = msop_fai_options_for_model_type($model, $type, $faiList);
$fai = msop_fai_join($faiList);
$action = trim((string)($_GET['action'] ?? ''));

$found = $model !== '' ? msop_find_latest_pdf($model) : ['ok'=>false, 'root'=>MSOP_ROOT_PATH, 'keys'=>[], 'path'=>'', 'file'=>'', 'rev'=>null, 'error'=>'모델을 선택해주세요.'];

if ($action === 'pdf') {
  if (empty($found['ok']) || empty($found['path']) || !is_file($found['path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $found['error'] ?: 'PDF not found';
    exit;
  }

  $srcPath = (string)$found['path'];
  $streamPath = $srcPath;
  $streamFile = (string)($found['file'] ?: basename($srcPath));

  // 예전 JMP Assist.py처럼 FAI 번호 기준으로 페이지를 찾되, 복수 FAI 선택 시 찾은 페이지들을 한 PDF로 묶어서 표시한다.
  if (!empty($faiList)) {
    $one = msop_extract_fai_pdf($srcPath, $faiList);
    if (!empty($one['ok']) && !empty($one['path']) && is_file((string)$one['path'])) {
      $streamPath = (string)$one['path'];
      $nums = [];
      foreach ($faiList as $lab) {
        $num = msop_extract_fai_no($lab);
        if ($num !== '' && !in_array($num, $nums, true)) $nums[] = $num;
      }
      $base = preg_replace('/\.pdf$/i', '', basename($streamFile));
      $suffix = count($nums) === 1 ? $nums[0] : 'multi';
      $streamFile = $base . '_FAI_' . $suffix . '.pdf';
    }
  }

  @ini_set('zlib.output_compression', '0');
  while (ob_get_level()) { @ob_end_clean(); }
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($streamFile)) . '"');
  header('Content-Length: ' . (string)filesize($streamPath));
  header('X-Content-Type-Options: nosniff');
  readfile($streamPath);
  exit;
}

$models = [
  'Memphis IR BASE',
  'Memphis X Carrier',
  'Memphis Y Carrier',
  'Memphis Z Carrier',
  'Memphis Z Stopper',
];
$pdfUrl = '';
if (!empty($found['ok'])) {
  $q = ['action' => 'pdf', 'model' => $model, 'type' => $type];
  foreach ($faiList as $lab) $q['fai'][] = $lab;
  $pdfUrl = basename(__FILE__) . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>MSOP PDF 뷰어</title>
  <style>
    :root{--bg:#06150b;--card:#202124;--card2:#2b2b2b;--line:rgba(255,255,255,.12);--text:rgba(255,255,255,.92);--muted:rgba(255,255,255,.65);--accent:#1db954;--bad:#ff5252;--warn:#ffcc00;}
    *{box-sizing:border-box} html,body{height:100%;margin:0} body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,"Noto Sans KR",sans-serif;background:var(--bg);color:var(--text);overflow:hidden}
    body:before{content:"";position:fixed;inset:0;opacity:.23;pointer-events:none;background-image:linear-gradient(rgba(65,255,120,.13) 1px,transparent 1px),linear-gradient(90deg,rgba(65,255,120,.13) 1px,transparent 1px);background-size:22px 22px;}
    .wrap{position:relative;z-index:1;height:100%;display:flex;flex-direction:column;padding:16px;gap:12px}
    .head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px}.title{font-size:22px;font-weight:800;color:#d8ffe0;text-shadow:0 2px 12px rgba(0,0,0,.55)}.sub{font-size:12px;color:var(--muted);margin-top:4px}
    .card{background:rgba(0,0,0,.54);border:1px solid var(--line);border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.35)}
    .filter{padding:12px 14px;display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}.f{display:flex;flex-direction:column;gap:6px}.f label{font-size:11px;color:var(--muted)}
    select{height:34px;padding:0 10px;background:rgba(0,0,0,.35);border:1px solid var(--line);border-radius:10px;color:var(--text);outline:none;min-width:180px}.btn{height:34px;padding:0 14px;border:1px solid rgba(255,255,255,.14);border-radius:10px;background:linear-gradient(180deg,rgba(29,185,84,.95),rgba(18,133,61,.95));color:white;font-weight:800;cursor:pointer}.btn:hover{filter:brightness(1.08)}
    .f-fai{min-width:340px}.msbox{position:relative;min-width:340px}.ms-toggle{width:100%;height:34px;padding:0 12px;border:1px solid var(--line);border-radius:10px;background:rgba(0,0,0,.35);color:var(--text);font-weight:700;text-align:left;cursor:pointer}.ms-toggle:after{content:'▾';float:right;color:var(--muted)}.msbox.open .ms-toggle:after{content:'▴'}.ms-panel{display:none;position:absolute;z-index:50;top:40px;left:0;width:420px;max-width:calc(100vw - 40px);padding:10px;border:1px solid rgba(120,255,160,.22);border-radius:12px;background:#0b1710;box-shadow:0 18px 45px rgba(0,0,0,.55)}.msbox.open .ms-panel{display:block}.ms-search{width:100%;height:32px;padding:0 10px;margin-bottom:8px;background:rgba(0,0,0,.45);border:1px solid var(--line);border-radius:9px;color:var(--text);outline:none}.ms-btnrow{display:flex;gap:6px;margin-bottom:8px}.mini{height:28px;padding:0 10px;border:1px solid var(--line);border-radius:8px;background:rgba(255,255,255,.06);color:var(--text);cursor:pointer}.ms-list{max-height:270px;overflow:auto;display:grid;grid-template-columns:1fr;gap:4px;padding-right:2px}.ms-item{display:flex;align-items:center;gap:8px;min-height:28px;padding:5px 8px;border-radius:8px;color:rgba(255,255,255,.9);cursor:pointer}.ms-item:hover{background:rgba(29,185,84,.12)}.ms-item input{accent-color:#1db954}.ms-empty{padding:12px;color:var(--muted);font-size:12px}
    .viewer{flex:1;min-height:0;overflow:hidden}.viewer iframe{width:100%;height:100%;border:0;background:#111;border-radius:14px}.empty{height:100%;display:flex;align-items:center;justify-content:center;text-align:center;color:var(--muted);padding:40px;font-size:14px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <div>
        <div class="title">MSOP PDF 뷰어</div>
      </div>
    </div>

    <form class="card filter" method="get" action="<?= h(basename(__FILE__)) ?>">
      <input type="hidden" name="type" value="<?= h($type) ?>">
      <div class="f">
        <label>모델</label>
        <select name="model">
          <?php foreach ($models as $m): ?>
            <option value="<?= h($m) ?>" <?= $model === $m ? 'selected' : '' ?>><?= h($m) ?></option>
          <?php endforeach; ?>
          <?php if ($model !== '' && !in_array($model, $models, true)): ?>
            <option value="<?= h($model) ?>" selected><?= h($model) ?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="f f-fai">
        <label>FAI / Point No. 선택</label>
        <div class="msbox" id="faiChooser">
          <button type="button" class="ms-toggle"><span id="faiSummary"><?= h(msop_fai_summary_label($faiList)) ?></span></button>
          <div class="ms-panel">
            <input type="text" class="ms-search" id="faiSearch" placeholder="FAI 검색" autocomplete="off">
            <div class="ms-btnrow">
              <button type="button" class="mini" data-fai-act="all">전체선택</button>
              <button type="button" class="mini" data-fai-act="none">전체해제</button>
            </div>
            <div class="ms-list" id="faiList">
              <?php if (!empty($faiOptions)): ?>
                <?php foreach ($faiOptions as $opt): ?>
                  <label class="ms-item" data-label="<?= h(strtolower((string)$opt)) ?>">
                    <input type="checkbox" class="fai-check" value="<?= h($opt) ?>" <?= msop_fai_is_selected((string)$opt, $faiList) ? 'checked' : '' ?>>
                    <span><?= h($opt) ?></span>
                  </label>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="ms-empty">선택 가능한 FAI가 없습니다.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div id="faiHidden">
          <?php foreach ($faiList as $opt): ?>
            <input type="hidden" name="fai[]" value="<?= h($opt) ?>">
          <?php endforeach; ?>
        </div>
      </div>
      <button class="btn" type="submit">열기</button>
    </form>

    <div class="card viewer">
      <?php if ($pdfUrl !== ''): ?>
        <iframe src="<?= h($pdfUrl) ?>"></iframe>
      <?php else: ?>
        <div class="empty">MSOP PDF를 찾지 못했습니다.<br/>경로와 파일명을 확인해주세요.</div>
      <?php endif; ?>
    </div>
  </div>
  <script>
  (function(){
    var box = document.getElementById('faiChooser');
    if(!box) return;
    var form = box.closest('form');
    var toggle = box.querySelector('.ms-toggle');
    var search = document.getElementById('faiSearch');
    var hidden = document.getElementById('faiHidden');
    var summary = document.getElementById('faiSummary');
    function checks(){ return Array.prototype.slice.call(box.querySelectorAll('.fai-check')); }
    function selected(){
      return checks().filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
    }
    function sync(){
      var vals = selected();
      if(hidden){
        hidden.innerHTML = '';
        vals.forEach(function(v){
          var inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = 'fai[]';
          inp.value = v;
          hidden.appendChild(inp);
        });
      }
      if(summary){
        if(vals.length === 0) summary.textContent = '(선택 없음)';
        else if(vals.length === 1) summary.textContent = vals[0];
        else summary.textContent = vals[0] + ' 외 ' + (vals.length - 1) + '개';
      }
    }
    if(toggle){
      toggle.addEventListener('click', function(e){
        e.preventDefault();
        box.classList.toggle('open');
        if(box.classList.contains('open') && search){ setTimeout(function(){ search.focus(); }, 0); }
      });
    }
    checks().forEach(function(c){ c.addEventListener('change', sync); });
    if(search){
      search.addEventListener('input', function(){
        var q = String(search.value || '').trim().toLowerCase();
        Array.prototype.slice.call(box.querySelectorAll('.ms-item')).forEach(function(item){
          var label = String(item.getAttribute('data-label') || item.textContent || '').toLowerCase();
          item.style.display = (!q || label.indexOf(q) >= 0) ? '' : 'none';
        });
      });
    }
    box.querySelectorAll('[data-fai-act]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var act = btn.getAttribute('data-fai-act');
        checks().forEach(function(c){
          var item = c.closest('.ms-item');
          var visible = !item || item.style.display !== 'none';
          if(!visible) return;
          c.checked = (act === 'all');
        });
        sync();
      });
    });
    document.addEventListener('click', function(e){
      if(box.contains(e.target)) return;
      box.classList.remove('open');
    });
    if(form){ form.addEventListener('submit', sync); }
    sync();
  })();
  </script>
</body>
</html>
