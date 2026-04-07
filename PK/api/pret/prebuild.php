<?php
// api/pret/prebuild.php
// Warm/cache generator: builds public/pret/maps/*.json and public/pret/tilesets/*.png by calling map.php for many maps.
// Use in browser:
//   /api/pret/prebuild.php?filter=overworld&limit=25&offset=0
// It returns JSON with progress + next_url.
//
// Notes:
// - Reads sources from Packege/ (decomp) and writes into public/pret.
// - Requires either allow_url_fopen OR curl extension to call local map.php.

require __DIR__ . '/_common.php';
require_once __DIR__ . '/../config.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

function pret_scheme_host_base(): array {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $scheme = $https ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  // /pokemon_hybrid_web/api/pret/prebuild.php -> /pokemon_hybrid_web/api/pret
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/pret/prebuild.php')), '/');
  return [$scheme, $host, $base];
}

function http_get_json(string $url, int $timeoutSec=20): array {
  // 1) allow_url_fopen
  if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
      'http' => [
        'method' => 'GET',
        'timeout' => $timeoutSec,
        'header' => "Accept: application/json\r\n",
      ],
      'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
      ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw !== false) {
      $j = json_decode($raw, true);
      if (is_array($j)) return $j;
      return ['ok'=>0,'err'=>'BAD_JSON','detail'=>'non-json response','url'=>$url,'raw_snip'=>substr((string)$raw,0,200)];
    }
  }

  // 2) curl fallback
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
      return ['ok'=>0,'err'=>'CURL_FAIL','detail'=>$err,'url'=>$url,'http_code'=>$code];
    }
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
    return ['ok'=>0,'err'=>'BAD_JSON','detail'=>'non-json response','url'=>$url,'http_code'=>$code,'raw_snip'=>substr((string)$raw,0,200)];
  }

  return ['ok'=>0,'err'=>'NO_HTTP_CLIENT','detail'=>'Enable allow_url_fopen or curl extension to warm caches on server side.'];
}

function is_overworld_map(string $id): bool {
  // Heuristic: towns/cities/routes/islands/ports/capes (you can expand later)
  // Examples: PalletTown, ViridianCity, Route1, OneIsland, TwoIsland, VermilionCity, SeviiIslands_?? (varies)
  if (preg_match('/^Route\d+/i', $id)) return true;
  if (preg_match('/(Town|City)$/i', $id)) return true;
  if (preg_match('/Island$/i', $id)) return true;
  if (preg_match('/(Port|Cape)$/i', $id)) return true;
  if (preg_match('/(Ferry|Harbor|Harbour)$/i', $id)) return true;
  return false;
}

try {
  $cfg = pret_cfg();
  $pack = $cfg['packege_root'] ?? '';
  if (!$pack || !is_dir($pack)) jexit(['ok'=>0,'err'=>'PACKEGE_ROOT_NOT_FOUND','detail'=>$pack], 500);

  $mapsDir = $pack . '/data/maps';
  if (!is_dir($mapsDir)) jexit(['ok'=>0,'err'=>'PACKEGE_MAPS_DIR_NOT_FOUND','detail'=>$mapsDir], 500);

  $filter = safe_id($_GET['filter'] ?? 'overworld');
  $limit  = (int)($_GET['limit'] ?? 25);
  $offset = (int)($_GET['offset'] ?? 0);
  if ($limit < 1) $limit = 1;
  if ($limit > 200) $limit = 200;
  if ($offset < 0) $offset = 0;

  // build map list
  $all = [];
  foreach (scandir($mapsDir) as $d) {
    if ($d==='.'||$d==='..') continue;
    $p = $mapsDir . '/' . $d;
    if (!is_dir($p)) continue;
    if (!is_file($p . '/map.json')) continue;
    $all[] = $d;
  }
  sort($all);

  $selected = [];
  if ($filter === 'all') {
    $selected = $all;
  } else {
    foreach ($all as $id) {
      if (is_overworld_map($id)) $selected[] = $id;
    }
    // if filter leaves nothing, fallback to all
    if (!count($selected)) $selected = $all;
  }

  $total = count($selected);
  $end = min($total, $offset + $limit);

  [$scheme,$host,$base] = pret_scheme_host_base();

  $results = [];
  $okCount = 0;
  $failCount = 0;

  for ($i=$offset; $i<$end; $i++) {
    $id = $selected[$i];
    $url = $scheme . '://' . $host . $base . '/map.php?map=' . rawurlencode($id);
    $j = http_get_json($url, 25);
    $r = [
      'map' => $id,
      'ok' => (int)($j['ok'] ?? 0),
    ];
    if (($j['ok'] ?? 0) == 1) {
      $okCount++;
      $r['tilesetUrl'] = $j['tilesetUrl'] ?? null;
      $r['mapUrl'] = $j['mapUrl'] ?? null;
      $r['usedMetatiles'] = $j['usedMetatiles'] ?? null;
      $r['tilesets'] = $j['tilesets'] ?? null;
    } else {
      $failCount++;
      $r['err'] = $j['err'] ?? ($j['error'] ?? 'FAIL');
      $r['detail'] = $j['detail'] ?? ($j['error'] ?? null);
    }
    $results[] = $r;
  }

  $done = ($end >= $total);
  $nextOffset = $done ? null : $end;
  $nextUrl = null;
  if (!$done) {
    $qs = http_build_query(['filter'=>$filter,'limit'=>$limit,'offset'=>$nextOffset]);
    $nextUrl = $scheme . '://' . $host . $base . '/prebuild.php?' . $qs;
  }

  jexit([
    'ok' => 1,
    'filter' => $filter,
    'packege_root' => $pack,
    'progress' => [
      'total' => $total,
      'offset' => $offset,
      'limit' => $limit,
      'processed' => ($end - $offset),
      'ok' => $okCount,
      'fail' => $failCount,
      'done' => $done ? 1 : 0,
      'next_offset' => $nextOffset,
      'next_url' => $nextUrl,
    ],
    'results' => $results,
  ]);

} catch (Throwable $e) {
  jexit(['ok'=>0,'err'=>'EX','detail'=>$e->getMessage()], 500);
}
