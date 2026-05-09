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

// 기본 경로. 서버 환경에서 필요하면 config/dp_config.php 쪽에서 MSOP_ROOT_PATH 상수를 정의해도 된다.
if (!defined('MSOP_ROOT_PATH')) {
  define('MSOP_ROOT_PATH', '\\\\192.168.1.135\\품질\\1. IT 사업부\\MEM25-project\\(Memphis) MSoP\\A2.0');
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

function msop_rev_no(string $filename): int {
  if (preg_match('/REV[._\-\s]?(\d+)/i', $filename, $m)) return (int)$m[1];
  return 0;
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
  $bestRev = -1;
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

    $rev = msop_rev_no($fn);
    $mtime = (int)@filemtime($path);
    if ($rev > $bestRev || ($rev === $bestRev && $mtime > $bestMtime)) {
      $bestPath = $path;
      $bestFile = $fn;
      $bestRev = $rev;
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
  $out['rev'] = $bestRev;
  return $out;
}

function msop_extract_fai_no(string $fai): string {
  if (preg_match('/(\d+)/', $fai, $m)) {
    $n = ltrim($m[1], '0');
    return $n === '' ? '0' : $n;
  }
  return '';
}

function msop_find_binary(array $names): string {
  $isWin = (stripos(PHP_OS, 'WIN') === 0);
  foreach ($names as $name) {
    $cmd = $isWin ? ('where ' . escapeshellarg($name) . ' 2>NUL') : ('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
    $res = @shell_exec($cmd);
    if (is_string($res) && trim($res) !== '') {
      $lines = preg_split('/\r?\n/', trim($res));
      if (!empty($lines[0])) return trim($lines[0], "\" ");
    }
  }
  return '';
}

function msop_shell_enabled(): bool {
  $disabled = (string)ini_get('disable_functions');
  foreach (['shell_exec', 'exec'] as $fn) {
    if (!function_exists($fn)) return false;
    if ($disabled !== '' && preg_match('/(^|,)\s*' . preg_quote($fn, '/') . '\s*(,|$)/i', $disabled)) return false;
  }
  return true;
}

function msop_find_fai_page(string $pdfPath, string $fai): array {
  $num = msop_extract_fai_no($fai);
  $ret = ['page' => null, 'note' => ''];
  if ($num === '') {
    $ret['note'] = 'FAI 번호를 추출하지 못해 전체 문서를 표시합니다.';
    return $ret;
  }
  if (!msop_shell_enabled()) {
    $ret['note'] = '서버에서 PDF 텍스트 검색 도구를 실행할 수 없어 전체 문서를 표시합니다.';
    return $ret;
  }

  $pdfinfo = msop_find_binary(['pdfinfo.exe', 'pdfinfo']);
  $pdftotext = msop_find_binary(['pdftotext.exe', 'pdftotext']);
  if ($pdfinfo === '' || $pdftotext === '') {
    $ret['note'] = 'pdftotext/pdfinfo가 없어 전체 문서를 표시합니다.';
    return $ret;
  }

  $infoCmd = escapeshellarg($pdfinfo) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
  $info = @shell_exec($infoCmd);
  if (!is_string($info) || !preg_match('/Pages:\s*(\d+)/i', $info, $m)) {
    $ret['note'] = 'PDF 페이지 수를 읽지 못해 전체 문서를 표시합니다.';
    return $ret;
  }

  $pages = max(1, min(1000, (int)$m[1]));
  $headerRe = '/MSOP\s*[:]\s*FAI\s*' . preg_quote($num, '/') . '\b/i';
  $generic1 = '/\bFAI\s*' . preg_quote($num, '/') . '\b/i';
  $generic2 = '/\bFAI' . preg_quote($num, '/') . '\b/i';

  $fallbackPage = null;
  for ($p = 1; $p <= $pages; $p++) {
    $cmd = escapeshellarg($pdftotext) . ' -f ' . $p . ' -l ' . $p . ' -layout ' . escapeshellarg($pdfPath) . ' - 2>&1';
    $txt = @shell_exec($cmd);
    if (!is_string($txt) || $txt === '') continue;
    $u = preg_replace('/\s+/u', ' ', $txt);
    if (preg_match($headerRe, $u)) {
      $ret['page'] = $p;
      return $ret;
    }
    if ($fallbackPage === null && (preg_match($generic1, $u) || preg_match($generic2, $u))) {
      $fallbackPage = $p;
    }
  }

  if ($fallbackPage !== null) {
    $ret['page'] = $fallbackPage;
    return $ret;
  }

  $ret['note'] = "FAI {$num} 페이지를 찾지 못해 전체 문서를 표시합니다.";
  return $ret;
}

$model = trim((string)($_GET['model'] ?? ''));
$fai = trim((string)($_GET['fai'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));

$found = $model !== '' ? msop_find_latest_pdf($model) : ['ok'=>false, 'root'=>MSOP_ROOT_PATH, 'keys'=>[], 'path'=>'', 'file'=>'', 'rev'=>null, 'error'=>'모델을 선택해주세요.'];

if ($action === 'pdf') {
  if (empty($found['ok']) || empty($found['path']) || !is_file($found['path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $found['error'] ?: 'PDF not found';
    exit;
  }

  $path = $found['path'];
  $file = $found['file'] ?: basename($path);
  @ini_set('zlib.output_compression', '0');
  while (ob_get_level()) { @ob_end_clean(); }
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($file)) . '"');
  header('Content-Length: ' . (string)filesize($path));
  header('X-Content-Type-Options: nosniff');
  readfile($path);
  exit;
}

$pageInfo = ['page' => null, 'note' => ''];
if (!empty($found['ok']) && $fai !== '') {
  $pageInfo = msop_find_fai_page((string)$found['path'], $fai);
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
  $q = ['action' => 'pdf', 'model' => $model];
  if ($fai !== '') $q['fai'] = $fai;
  $pdfUrl = basename(__FILE__) . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
  if (!empty($pageInfo['page'])) $pdfUrl .= '#page=' . (int)$pageInfo['page'];
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
    select,input{height:34px;padding:0 10px;background:rgba(0,0,0,.35);border:1px solid var(--line);border-radius:10px;color:var(--text);outline:none;min-width:180px}input{min-width:220px}.btn{height:34px;padding:0 14px;border:1px solid rgba(255,255,255,.14);border-radius:10px;background:linear-gradient(180deg,rgba(29,185,84,.95),rgba(18,133,61,.95));color:white;font-weight:800;cursor:pointer}.btn:hover{filter:brightness(1.08)}
    .info{padding:10px 14px;font-size:12px;color:#d9ffe6;display:flex;gap:10px;flex-wrap:wrap;align-items:center}.chip{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:rgba(0,0,0,.32);border:1px solid rgba(120,255,160,.25)}.chip.warn{border-color:rgba(255,204,0,.35);color:#ffe68a}.chip.bad{border-color:rgba(255,82,82,.5);color:#ffb7b7}
    .viewer{flex:1;min-height:0;overflow:hidden}.viewer iframe{width:100%;height:100%;border:0;background:#111;border-radius:14px}.empty{height:100%;display:flex;align-items:center;justify-content:center;text-align:center;color:var(--muted);padding:40px;font-size:14px}.path{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <div>
        <div class="title">MSOP PDF 뷰어</div>
        <div class="sub">모델 기준 최신 Rev PDF를 새 창에서 표시합니다. FAI가 넘어오면 가능한 경우 해당 페이지로 이동합니다.</div>
      </div>
    </div>

    <form class="card filter" method="get" action="<?= h(basename(__FILE__)) ?>">
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
      <div class="f">
        <label>FAI / Point No. 선택 입력</label>
        <input name="fai" value="<?= h($fai) ?>" placeholder="예: FAI 14 / 14-P2"/>
      </div>
      <button class="btn" type="submit">열기</button>
    </form>

    <div class="card info">
      <span class="chip">ROOT: <span class="path"><?= h($found['root'] ?? MSOP_ROOT_PATH) ?></span></span>
      <?php if (!empty($found['keys'])): ?><span class="chip">KEY: <?= h(implode(', ', $found['keys'])) ?></span><?php endif; ?>
      <?php if (!empty($found['ok'])): ?>
        <span class="chip">PDF: <?= h($found['file']) ?></span>
        <span class="chip">Rev: <?= h((string)$found['rev']) ?></span>
        <?php if (!empty($pageInfo['page'])): ?><span class="chip">Page: <?= (int)$pageInfo['page'] ?></span><?php endif; ?>
        <?php if (!empty($pageInfo['note'])): ?><span class="chip warn"><?= h($pageInfo['note']) ?></span><?php endif; ?>
      <?php else: ?>
        <span class="chip bad"><?= h($found['error'] ?? 'PDF를 찾지 못했습니다.') ?></span>
      <?php endif; ?>
    </div>

    <div class="card viewer">
      <?php if ($pdfUrl !== ''): ?>
        <iframe src="<?= h($pdfUrl) ?>"></iframe>
      <?php else: ?>
        <div class="empty">MSOP PDF를 찾지 못했습니다.<br/>경로와 파일명을 확인해주세요.</div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
