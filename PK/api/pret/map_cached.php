<?php
require __DIR__ . '/_common.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/rathena_warp.php';
require_once __DIR__ . '/../lib/rathena_connect.php';

/**
 * Cached PRET map loader (NO Packege required)
 *
 * - Reads:   public/pret/maps/<Map>.json  (generated earlier)
 * - Merges:  script/map/warp/*.warp and script/map/connect/*.connect (rAthena-style scripts; falls back to npc/... for older zips)
 * - Writes back the merged JSON to the same cache file (so the client can load it as a static URL)
 *
 * If cache is missing, returns MAP_CACHE_MISSING (client can fallback to pret/map.php generator).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
  $mapId = safe_id($_GET['map'] ?? '');
  if ($mapId === '') jexit(['ok'=>0,'err'=>'NO_MAP'], 400);

  $cfg = pret_cfg();
  $pubPret = $cfg['public_pret_root'];

  $mapFile = rtrim($pubPret, '/\\') . '/maps/' . $mapId . '.json';
  if (!is_file($mapFile)) {
    jexit([
      'ok'=>0,
      'err'=>'MAP_CACHE_MISSING',
      'map'=>$mapId,
      'hint'=>'Generate caches first: open /api/pret/prebuild.php (batch) or /api/pret/map.php?map=... (single). After caches exist, Packege can be removed.'
    ], 404);
  }

  $raw = @file_get_contents($mapFile);
  if ($raw === false) throw new Exception('failed to read cache: ' . $mapFile);

  $map = json_decode($raw, true);
  if (!is_array($map)) throw new Exception('cache json parse fail: ' . $mapFile);

  // stale cache guard: accept the current generator version and current key names
  $needVer = 'r16_split_upper';
  $haveVer = isset($map['meta']['gen_ver']) ? (string)$map['meta']['gen_ver'] : '';
  $hasMain = isset($map['tileset']) || (!empty($map['tilesetFrames'])) || isset($map['tileset_lower']) || (!empty($map['tilesetFramesLower']));
  if (!$hasMain || ($needVer !== '' && $haveVer !== $needVer)) {
    jexit(['ok'=>0,'err'=>'CACHE_STALE','need_ver'=>$needVer,'have_ver'=>$haveVer], 409);
  }


  $projectRoot = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');

  // Merge rAthena scripts (do NOT require Packege)
  $connects = rathena_load_connects_for_map($projectRoot, $mapId, []);
  $warps    = rathena_load_warps_for_map($projectRoot, $mapId, []);

  if (is_array($connects) && count($connects) > 0) $map['connections'] = $connects;
  if (is_array($warps) && count($warps) > 0) $map['warp_events'] = $warps;

  // Write back (so client can load ./pret/maps/<Map>.json as-is)
  $w = @file_put_contents($mapFile, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  if ($w === false) throw new Exception('failed to write merged cache: ' . $mapFile);

  // Map label from DB (prefer Korean name if available)
  $label = $mapId;
  try{
    $conn = db();
    $stmt = $conn->prepare("SELECT mapkname, name_en, mapname FROM maps_info WHERE mapname=? LIMIT 1");
    $stmt->execute([$mapId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if($row){
      $k = trim((string)($row["mapkname"] ?? ""));
      $en = trim((string)($row["name_en"] ?? ""));
      $label = $k !== "" ? $k : ($en !== "" ? $en : $mapId);
    }
  }catch(Exception $e){
    // ignore if DB/table not present
  }

  // Minimal compatible response (same keys as pret/map.php enough for overworld.js)
  jexit([
    'ok'=>1,
    'map'=>$mapId,
    'mapUrl'=>'./pret/maps/' . $mapId . '.json',
    'label' => $label,
    'tilesetUrl'=> isset($map['tileset']) ? ('./' . ltrim((string)$map['tileset'], './')) : null,
    'tilesetFrames'=> $map['tilesetFrames'] ?? null,
  ]);
} catch (Throwable $e) {
  jexit(['ok'=>0,'err'=>'EX','detail'=>$e->getMessage()], 500);
}
