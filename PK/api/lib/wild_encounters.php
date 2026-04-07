<?php
// api/lib/wild_encounters.php
// Read Packege/src/data/wild_encounters.json (decompiled source data) as "truth" for wild encounters.
// This file provides lookup + weighted slot picking (FR/LG).

function wild_encounters_path(): string {
  // Prefer local Packege folder shipped with project (as reference source).
  $p = realpath(__DIR__ . '/../../Packege/src/data/wild_encounters.json');
  if ($p && is_file($p)) return $p;
  return __DIR__ . '/../../Packege/src/data/wild_encounters.json';
}

function wild_encounters_load(): ?array {
  static $cache = null;
  static $cache_mtime = 0;

  $p = wild_encounters_path();
  if (!is_file($p)) return null;

  $mtime = (int)@filemtime($p);
  if ($cache !== null && $mtime === $cache_mtime) return $cache;

  $txt = @file_get_contents($p);
  if ($txt === false) return null;

  $j = json_decode((string)$txt, true);
  if (!is_array($j)) return null;

  $cache = $j;
  $cache_mtime = $mtime;
  return $cache;
}

function wild_ver_normalize(int $gameVer): string {
  // 1=FireRed, 2=LeafGreen (we store this in player_flag: GAME_VER)
  return ($gameVer === 2) ? 'LEAFGREEN' : 'FIRERED';
}

function wild_find_entry_for_map_const(string $mapConst, int $gameVer): ?array {
  $db = wild_encounters_load();
  if (!$db) return null;

  $mapConst = strtoupper(trim($mapConst));
  if ($mapConst === '') return null;

  $groups = $db['wild_encounter_groups'] ?? null;
  if (!is_array($groups) || count($groups) < 1) return null;

  // Most FRLG dumps place everything under one group "gWildMonHeaders"
  foreach ($groups as $g) {
    $encs = $g['encounters'] ?? null;
    if (!is_array($encs)) continue;

    $want = wild_ver_normalize($gameVer);
    foreach ($encs as $e) {
      if (!is_array($e)) continue;
      if (strtoupper((string)($e['map'] ?? '')) !== $mapConst) continue;

      $base = strtoupper((string)($e['base_label'] ?? ''));
      if ($want === 'FIRERED' && str_contains($base, 'FIRERED')) return $e;
      if ($want === 'LEAFGREEN' && str_contains($base, 'LEAFGREEN')) return $e;
    }
  }
  return null;
}

function wild_slot_weights_land(): array {
  // FRLG standard grass slots (12)
  return [20,20,10,10,10,10,5,5,4,4,1,1];
}

function wild_slot_weights_water(): array {
  // FRLG standard water slots (5)
  return [60,30,5,4,1];
}

function wild_pick_weighted_index(array $weights): int {
  $sum = 0;
  foreach ($weights as $w) $sum += max(0, (int)$w);
  if ($sum <= 0) return 0;

  $r = random_int(1, $sum);
  $acc = 0;
  for ($i=0; $i<count($weights); $i++) {
    $acc += max(0, (int)$weights[$i]);
    if ($r <= $acc) return $i;
  }
  return 0;
}

function wild_pick_mon_slot(array $mons, bool $isWater=false): ?array {
  if (!is_array($mons) || count($mons) === 0) return null;

  $weights = $isWater ? wild_slot_weights_water() : wild_slot_weights_land();
  // If the data doesn't match expected slot count, fallback to uniform.
  if (count($mons) !== count($weights)) {
    $idx = random_int(0, max(0, count($mons)-1));
    return is_array($mons[$idx] ?? null) ? $mons[$idx] : null;
  }

  $idx = wild_pick_weighted_index($weights);
  return is_array($mons[$idx] ?? null) ? $mons[$idx] : null;
}

function wild_pick_species_token_and_level(string $mapConst, int $gameVer, string $kind='land'): ?array {
  $e = wild_find_entry_for_map_const($mapConst, $gameVer);
  if (!$e) return null;

  $k = strtolower(trim($kind));
  $tbl = null;
  $isWater = false;

  if ($k === 'water') {
    $tbl = $e['water_mons'] ?? null;
    $isWater = true;
  } else if ($k === 'fishing') {
    $tbl = $e['fishing_mons'] ?? null;
    $isWater = true; // treat as water-ish for weights fallback
  } else if ($k === 'rock_smash' || $k === 'rock') {
    $tbl = $e['rock_smash_mons'] ?? null;
  } else {
    $tbl = $e['land_mons'] ?? null;
  }

  if (!is_array($tbl)) return null;

  $mons = $tbl['mons'] ?? null;
  if (!is_array($mons) || count($mons) === 0) return null;

  $slot = wild_pick_mon_slot($mons, $isWater);
  if (!$slot) return null;

  $spec = (string)($slot['species'] ?? '');
  $min = (int)($slot['min_level'] ?? 1);
  $max = (int)($slot['max_level'] ?? $min);
  if ($min < 1) $min = 1;
  if ($max < $min) $max = $min;
  $lvl = ($min === $max) ? $min : random_int($min, $max);

  return [
    'species_token' => $spec,
    'level' => $lvl,
  ];
}
