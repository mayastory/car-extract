<?php
// api/lib/pret_public.php
// Helper to read generated PRET map JSON (public/pret/maps/*.json)

function pret_public_root(): string {
  static $root = null;
  if ($root !== null) return $root;

  $cfgPath = __DIR__ . '/../../config/pret_paths.php';
  if (is_file($cfgPath)) {
    $cfg = include $cfgPath;
    if (is_array($cfg) && !empty($cfg['public_pret_root'])) {
      $root = (string)$cfg['public_pret_root'];
      return $root;
    }
  }

  // Fallback to <project_root>/public/pret
  $root = realpath(__DIR__ . '/../../public/pret') ?: (__DIR__ . '/../../public/pret');
  return $root;
}

function pret_public_map_path(string $mapId): string {
  $mapId = preg_replace('/[^A-Za-z0-9_\-]/', '', $mapId);
  $root = pret_public_root();
  return rtrim($root, '/\\') . '/maps/' . $mapId . '.json';
}

function pret_public_load_map(string $mapId): ?array {
  // IMPORTANT:
  // We intentionally do NOT negative-cache missing maps.
  // During gameplay, maps are generated on-demand by api/pret/map.php.
  // If we cache "null" for a missing file here, server-side validators
  // (e.g., edge transition checks in rt/upsert.php) can keep failing even
  // after the client successfully generated the map JSON.
  static $cache = []; // mapId => array
  $k = $mapId;
  if (isset($cache[$k])) return $cache[$k];

  $p = pret_public_map_path($mapId);
  if (!is_file($p)) return null;

  $j = json_decode((string)file_get_contents($p), true);
  if (!is_array($j)) return null;

  $cache[$k] = $j;
  return $cache[$k];
}

function pret_public_is_blocked(string $mapId, int $x, int $y): bool {
  $m = pret_public_load_map($mapId);
  if (!$m) return false; // unknown => don't block
  $w = (int)($m['width'] ?? 0);
  $h = (int)($m['height'] ?? 0);
  if ($w <= 0 || $h <= 0) return false;
  if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) return true;
  $col = $m['collision'] ?? null;
  if (!is_array($col) || count($col) !== $w*$h) return false;
  $v = (int)($col[$y*$w + $x] ?? 0);
  return $v !== 0;
}


function pret_public_behavior_at(string $mapId, int $x, int $y): int {
  $m = pret_public_load_map($mapId);
  if (!$m) return 0;
  $w = (int)($m['width'] ?? 0);
  $h = (int)($m['height'] ?? 0);
  if ($w <= 0 || $h <= 0) return 0;
  if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) return 0;

  $beh = $m['behavior'] ?? null;
  if (!is_array($beh) || count($beh) !== $w*$h) return 0;
  return (int)($beh[$y*$w + $x] ?? 0);
}

function pret_public_is_grass_behavior(int $b): bool {
  // Packege/include/constants/metatile_behaviors.h
  // FRLG: MB_TALL_GRASS = 0x02
  // FRLG: MB_CYCLING_ROAD_PULL_DOWN_GRASS = 0xD1
  return ($b === 0x02) || ($b === 0xD1);
}

function pret_public_is_cave_behavior(int $b): bool {
  // FRLG: MB_CAVE = 0x08
  // FRLG: MB_SAND_CAVE = 0x2B
  return ($b === 0x08) || ($b === 0x2B);
}

function pret_public_is_indoor_encounter_behavior(int $b): bool {
  // FRLG: MB_INDOOR_ENCOUNTER = 0x0B
  return ($b === 0x0B);
}

function pret_public_is_land_encounter_behavior(int $b): bool {
  // Land spawns: grass/cave/indoor only.
  // NOTE: MB_SAND(0x21) beach is intentionally excluded.
  return pret_public_is_grass_behavior($b) || pret_public_is_cave_behavior($b) || pret_public_is_indoor_encounter_behavior($b);
}

function pret_public_land_encounter_kind(int $b): string {
  if (pret_public_is_grass_behavior($b)) return 'grass';
  if (pret_public_is_cave_behavior($b)) return 'cave';
  if (pret_public_is_indoor_encounter_behavior($b)) return 'indoor';
  return '';
}

function pret_public_is_water_behavior(int $b): bool {
  // Packege/include/constants/metatile_behaviors.h
  // FRLG water-ish behaviors that should allow WATER encounters/fishing.
  // Keep this *strict* and sourced from the decomp constants:
  // 0x10 POND_WATER
  // 0x11 FAST_WATER
  // 0x12 DEEP_WATER
  // 0x13 WATERFALL
  // 0x14 SEAWEED
  // 0x15 OCEAN_WATER
  // 0x16 PUDDLE
  // 0x17 SHALLOW_WATER
  // 0x19 SOOTOPOLIS_DEEP_WATER
  // 0x1A SOOTOPOLIS_PUDDLE
  // 0x1B CYCLING_ROAD_WATER
  // 0x50..0x53 (currents)
  if ($b >= 0x50 && $b <= 0x53) return true;
  return in_array($b, [0x10,0x11,0x12,0x13,0x14,0x15,0x16,0x17,0x19,0x1A,0x1B], true);
}

function pret_public_find_near_tiles(string $mapId, int $cx, int $cy, int $radius, callable $pred): array {
  $m = pret_public_load_map($mapId);
  if (!$m) return [];
  $w = (int)($m['width'] ?? 0);
  $h = (int)($m['height'] ?? 0);
  if ($w <= 0 || $h <= 0) return [];

  $r = max(1, $radius);
  $x0 = max(0, $cx - $r);
  $y0 = max(0, $cy - $r);
  $x1 = min($w - 1, $cx + $r);
  $y1 = min($h - 1, $cy + $r);

  $tiles = [];
  for ($y = $y0; $y <= $y1; $y++) {
    for ($x = $x0; $x <= $x1; $x++) {
      if (pret_public_is_blocked($mapId, $x, $y)) continue;
      $b = pret_public_behavior_at($mapId, $x, $y);
      if ($pred($x, $y, $b)) $tiles[] = [$x, $y, $b];
    }
  }
  return $tiles;
}
