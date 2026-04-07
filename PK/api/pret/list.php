<?php
require __DIR__ . '/_common.php';
require_once __DIR__ . '/../config.php';

$cfg = pret_cfg();
$root = $cfg['packege_root'];
$mapsDir = $root . '/data/maps';

if (!is_dir($mapsDir)) {
  jexit(['ok'=>0,'err'=>'PACKEGE_MAPS_DIR_NOT_FOUND','detail'=>$mapsDir], 500);
}

$dirs = scandir($mapsDir);
$mapIds = [];
foreach ($dirs as $d) {
  if ($d==='.'||$d==='..') continue;
  $p = $mapsDir . '/' . $d;
  if (!is_dir($p)) continue;
  if (!file_exists($p . '/map.json')) continue;
  $mapIds[] = $d;
}

sort($mapIds);

// Try to attach labels from DB (maps_info)
$labelMap = [];
try {
  $conn = db();
  $res = $conn->query('SELECT mapname, COALESCE(mapkname, name_en, mapname) AS label FROM maps_info');
  if ($res) {
    while ($row = $res->fetch_assoc()) {
      $labelMap[(string)$row['mapname']] = (string)$row['label'];
    }
  }
} catch (Throwable $e) {
  // ignore
}

$maps = [];
foreach ($mapIds as $id) {
  $maps[] = [
    'id' => $id,
    'label' => $labelMap[$id] ?? $id,
  ];
}

jexit(['ok'=>1,'count'=>count($maps),'maps'=>$maps,'map_ids'=>$mapIds]);
