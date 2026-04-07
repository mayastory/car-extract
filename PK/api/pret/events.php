<?php
// Lightweight PRET map-events endpoint (no GD required)
// Reads: Packege/data/maps/<MapId>/map.json
// Returns: warp_events with dest_map_id resolved to folder when possible.

require_once __DIR__ . '/_common.php';

function _pc_scan_map_const_to_folder(string $packMapsDir): array {
  $out = [];
  if (!is_dir($packMapsDir)) return $out;
  $dirs = glob($packMapsDir . '/*', GLOB_ONLYDIR) ?: [];
  foreach ($dirs as $dir) {
    $p = $dir . '/map.json';
    if (!is_file($p)) continue;
    $raw = @file_get_contents($p);
    if ($raw === false) continue;
    $j = json_decode($raw, true);
    if (!is_array($j)) continue;
    $id = $j['id'] ?? null;
    if (!is_string($id) || $id === '') continue;
    $folder = basename($dir);
    $out[$id] = $folder;
  }
  return $out;
}

function _pc_int_or_null($v) {
  if (is_int($v)) return $v;
  if (is_string($v) && preg_match('/^-?\d+$/', $v)) return intval($v, 10);
  return null;
}

$mapId = trim((string)($_GET['map'] ?? ''));
if ($mapId === '') {
  jexit(['ok'=>0,'err'=>'NO_MAP_PARAM']);
}

$pack = pret_cfg('packege_root');
$packMapsDir = $pack . '/data/maps';
$mapJson = $packMapsDir . '/' . $mapId . '/map.json';
if (!is_file($mapJson)) {
  jexit(['ok'=>0,'err'=>'MAP_NOT_FOUND','map'=>$mapId]);
}

$raw = file_get_contents($mapJson);
if ($raw === false) {
  jexit(['ok'=>0,'err'=>'READ_FAIL','map'=>$mapId]);
}

$m = json_decode($raw, true);
if (!is_array($m)) {
  jexit(['ok'=>0,'err'=>'JSON_DECODE_FAIL','map'=>$mapId]);
}

// Resolve MAP_* constants to folder names
$constToFolder = _pc_scan_map_const_to_folder($packMapsDir);

$warps = [];
if (isset($m['warp_events']) && is_array($m['warp_events'])) {
  foreach ($m['warp_events'] as $idx => $w) {
    if (!is_array($w)) continue;
    $x = _pc_int_or_null($w['x'] ?? null);
    $y = _pc_int_or_null($w['y'] ?? null);
    if ($x === null || $y === null) continue;
    $elev = _pc_int_or_null($w['elevation'] ?? 0) ?? 0;

    $destConst = $w['dest_map'] ?? null;
    $destWarp = _pc_int_or_null($w['dest_warp_id'] ?? null);
    $destFolder = null;
    if (is_string($destConst) && $destConst !== '') {
      $destFolder = $constToFolder[$destConst] ?? null;
    }

    $warps[] = [
      'warp_id' => is_int($idx) ? $idx : _pc_int_or_null($idx),
      'x' => $x,
      'y' => $y,
      'elevation' => $elev,
      'dest_map_const' => is_string($destConst) ? $destConst : null,
      'dest_map_id' => is_string($destFolder) ? $destFolder : null,
      'dest_warp_id' => $destWarp,
    ];
  }
}

jexit([
  'ok' => 1,
  'map' => $mapId,
  'map_const' => is_string($m['id'] ?? null) ? $m['id'] : null,
  'warps' => $warps,
]);
