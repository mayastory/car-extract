<?php
// api/lib/rathena_monster.php
// rAthena-like monster spawn scripts: script/monster/*.monster

require_once __DIR__ . '/pret_public.php';

function monster_script_dirs(): array {
  $base = realpath(__DIR__ . '/../../script/monster') ?: (__DIR__ . '/../../script/monster');
  return [
    $base,
  ];
}

function monster_find_script(string $mapId): ?string {
  $mapId = preg_replace('/[^A-Za-z0-9_-]/', '', $mapId);
  if ($mapId === null || $mapId === '') return null;

  foreach (monster_script_dirs() as $dir) {
    $p = rtrim($dir, '/') . '/' . $mapId . '.monster';
    if (is_file($p)) return $p;
  }
  return null;
}

function monster_parse_script(string $content, string $mapId = ''): array {
  $out = [];
  $lines = preg_split("/\r\n|\r|\n/", $content);
  if (!is_array($lines)) return $out;

  $idx = 0;
  foreach ($lines as $raw) {
    $line = trim((string)$raw);
    if ($line === '' || $line[0] === '#' || str_starts_with($line, '//')) continue;

    // Allow tabs/spaces between columns
    $cols = preg_split('/\s+/', $line);
    if (!is_array($cols) || count($cols) < 6) continue;

    // rAthena-ish:
    // Map,x,y,dir  monster  Name  w,h,Species,lvMin,lvMax,count,respawnSec[,flags]
    $pos = explode(',', $cols[0]);
    if (count($pos) < 4) continue;
    $m = trim($pos[0]);
    $x = (int)$pos[1];
    $y = (int)$pos[2];
    $dir = (int)$pos[3];

    if ($mapId !== '' && $m !== $mapId) {
      // Skip if the file is dedicated to one map but line doesn't match.
      continue;
    }

    $kind = strtolower(trim($cols[1]));
    if ($kind !== 'monster') continue;

    $name = (string)$cols[2];
    $rest = explode(',', $cols[3]);
    // w,h,Species,lvMin,lvMax,count,respawnSec[,flags]
    if (count($rest) < 7) continue;

    $w = max(1, (int)$rest[0]);
    $h = max(1, (int)$rest[1]);
    $species = trim((string)$rest[2]);
    $lvMin = max(1, (int)$rest[3]);
    $lvMax = max($lvMin, (int)$rest[4]);
    $count = max(1, (int)$rest[5]);
    $respawn = max(1, (int)$rest[6]);
    $flags = (count($rest) >= 8) ? trim((string)$rest[7]) : '';

    $spawnKey = sha1(($m ?: $mapId) . '|' . $idx . '|' . $name);

    $out[] = [
      'idx' => $idx,
      'map_id' => $m ?: $mapId,
      'x' => $x,
      'y' => $y,
      'dir' => $dir,
      'name' => $name,
      'w' => $w,
      'h' => $h,
      'species' => $species,
      'lv_min' => $lvMin,
      'lv_max' => $lvMax,
      'count' => $count,
      'respawn_sec' => $respawn,
      'flags' => $flags,
      'spawn_key' => $spawnKey,
    ];

    $idx++;
  }

  return $out;
}

function monster_load_spawns(string $mapId): array {
  $p = monster_find_script($mapId);
  if (!$p) return [];
  $txt = @file_get_contents($p);
  if ($txt === false) return [];
  return monster_parse_script($txt, $mapId);
}

function monster_pick_spawn_xy(string $mapId, int $baseX, int $baseY, int $w, int $h): array {
  // Try to find a non-blocked tile within (baseX..baseX+w-1, baseY..baseY+h-1)
  // using generated PRET collision.
  $tries = 40;
  $rx = $baseX;
  $ry = $baseY;
  for ($i=0; $i<$tries; $i++) {
    $x = $baseX + random_int(0, max(0, $w-1));
    $y = $baseY + random_int(0, max(0, $h-1));
    if (!pret_public_is_blocked($mapId, $x, $y)) return [$x, $y];
    $rx = $x; $ry = $y;
  }
  return [$rx, $ry];
}
