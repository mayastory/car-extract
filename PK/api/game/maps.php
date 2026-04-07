<?php
require_once __DIR__ . '/../config.php';

// Lists generated map caches under public/pret.
// Source of truth remains Packege; this is a convenience API.

$project_root = realpath(__DIR__ . '/..' . '/..');
$idx_path = $project_root . '/public/pret/index.json';

if (!file_exists($idx_path)) {
  json_out(['ok'=>true,'maps'=>[],'hint'=>'Run tools/export_pret.py once to generate public/pret/index.json']);
}

$j = json_decode(file_get_contents($idx_path), true);
$maps = [];
if (is_array($j) && isset($j['maps']) && is_array($j['maps'])) {
  foreach ($j['maps'] as $m) {
    if (is_string($m) && $m !== '') $maps[] = $m;
  }
}

json_out(['ok'=>true,'maps'=>$maps]);
