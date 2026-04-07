<?php
// api/lib/mob_runtime.php
// Runtime spawn logic backed by DB (mob_instance)

require_once __DIR__ . '/rathena_monster.php';
require_once __DIR__ . '/wild_encounters.php';

function ensure_mob_instance_table(mysqli $conn): void {
  // Create table if missing (lets you test without running SQL reset).
  $sql = "CREATE TABLE IF NOT EXISTS `mob_instance` (
    `mob_id` BIGINT NOT NULL AUTO_INCREMENT,
    `map_id` VARCHAR(64) NOT NULL,
    `spawn_key` VARCHAR(80) NOT NULL,
    `spawn_name` VARCHAR(64) NULL,
    `species_id` INT NOT NULL,
    `level` INT NOT NULL DEFAULT 1,
    `x` INT NOT NULL,
    `y` INT NOT NULL,
    `dir` TINYINT NOT NULL DEFAULT 0,
    `home_x` INT NULL DEFAULT NULL,
    `home_y` INT NULL DEFAULT NULL,
    `roam_radius` TINYINT NOT NULL DEFAULT 3,
    `next_move_at` TIMESTAMP NULL DEFAULT NULL,
    `state` TINYINT NOT NULL DEFAULT 0,
    `respawn_sec` INT NOT NULL DEFAULT 30,
    `dead_at` TIMESTAMP NULL DEFAULT NULL,
    `respawn_at` TIMESTAMP NULL DEFAULT NULL,
    `owner_player_id` INT NULL DEFAULT NULL,
    `kind` VARCHAR(16) NOT NULL DEFAULT 'script',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`mob_id`),
    KEY `idx_mob_map` (`map_id`),
    KEY `idx_mob_spawn` (`map_id`,`spawn_key`),
    KEY `idx_mob_state` (`map_id`,`state`),
    KEY `idx_mob_respawn` (`state`,`respawn_at`),
    KEY `idx_mob_owner` (`owner_player_id`,`map_id`),
    KEY `idx_mob_kind` (`kind`,`map_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  @$conn->query($sql);

  // If table existed before, ensure new columns exist.
  mob_ensure_columns($conn);
}

function mob_column_exists(mysqli $conn, string $col): bool {
  $cfg = cfg();
  $dbName = (string)($cfg['db'] ?? '');
  if ($dbName === '') return false;

  $stmt = $conn->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=? AND table_name=\'mob_instance\' AND column_name=? LIMIT 1');
  if (!$stmt) return false;
  $stmt->bind_param('ss', $dbName, $col);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_row() : null;
  $stmt->close();
  return $row ? true : false;
}

function mob_ensure_columns(mysqli $conn): void {
  // NOTE: phpMyAdmin import users may have older mob_instance schema.
  if (!mob_column_exists($conn, 'owner_player_id')) {
    @$conn->query('ALTER TABLE mob_instance ADD COLUMN owner_player_id INT NULL DEFAULT NULL');
    @$conn->query('ALTER TABLE mob_instance ADD KEY idx_mob_owner (owner_player_id, map_id)');
  }
  if (!mob_column_exists($conn, 'kind')) {
    @$conn->query("ALTER TABLE mob_instance ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'script'");
    @$conn->query('ALTER TABLE mob_instance ADD KEY idx_mob_kind (kind, map_id)');
  }

  // Wild roaming (player-private visible encounters)
  if (!mob_column_exists($conn, 'home_x')) {
    @$conn->query('ALTER TABLE mob_instance ADD COLUMN home_x INT NULL DEFAULT NULL');
  }
  if (!mob_column_exists($conn, 'home_y')) {
    @$conn->query('ALTER TABLE mob_instance ADD COLUMN home_y INT NULL DEFAULT NULL');
  }
  if (!mob_column_exists($conn, 'roam_radius')) {
    @$conn->query('ALTER TABLE mob_instance ADD COLUMN roam_radius TINYINT NOT NULL DEFAULT 3');
  }
  if (!mob_column_exists($conn, 'next_move_at')) {
    @$conn->query('ALTER TABLE mob_instance ADD COLUMN next_move_at TIMESTAMP NULL DEFAULT NULL');
  }

  if (!mob_column_exists($conn, 'terrain')) {
    // For wild mobs: 'grass' | 'cave' | 'indoor' | 'water'
    @$conn->query("ALTER TABLE mob_instance ADD COLUMN terrain VARCHAR(8) NULL DEFAULT NULL");
    @$conn->query('ALTER TABLE mob_instance ADD KEY idx_mob_terrain (kind, map_id, terrain)');
  }
}




function mob_tile_ok_for_terrain(int $b, string $terrain): bool {
  $t = strtolower(trim($terrain));
  if ($t === 'water') return pret_public_is_water_behavior($b);
  if ($t === 'cave') return pret_public_is_cave_behavior($b);
  if ($t === 'indoor') return pret_public_is_indoor_encounter_behavior($b);
  // default: grass
  return pret_public_is_grass_behavior($b);
}

function resolve_species_id(mysqli $conn, string $token): int {
  static $cache = [];
  $k = strtoupper(trim($token));
  if ($k === '') return 0;
  if (isset($cache[$k])) return (int)$cache[$k];

  if (ctype_digit($k)) {
    $cache[$k] = (int)$k;
    return (int)$cache[$k];
  }

  // Try const_name
  $stmt = $conn->prepare('SELECT species_id FROM ref_species WHERE const_name=? LIMIT 1');
  if ($stmt) {
    $stmt->bind_param('s', $k);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) {
      $cache[$k] = (int)$row['species_id'];
      return (int)$cache[$k];
    }
  }

  // Try english name or korean name
  $stmt = $conn->prepare('SELECT species_id FROM ref_species WHERE name=? OR name_ko=? LIMIT 1');
  if ($stmt) {
    $tok = trim($token);
    $stmt->bind_param('ss', $tok, $tok);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) {
      $cache[$k] = (int)$row['species_id'];
      return (int)$cache[$k];
    }
  }

  $cache[$k] = 0;
  return 0;
}

function rand_level(int $min, int $max): int {
  $min = max(1, $min);
  $max = max($min, $max);
  if ($min === $max) return $min;
  return random_int($min, $max);
}

function mob_tick_map(mysqli $conn, string $mapId, array $spawns): array {
  ensure_mob_instance_table($conn);

  // Load current mob rows for map
  $stmt = $conn->prepare("SELECT mob_id, spawn_key, spawn_name, species_id, level, x, y, dir, state, respawn_sec, respawn_at, terrain, home_x, home_y, roam_radius, next_move_at FROM mob_instance WHERE map_id=? AND kind='script' AND owner_player_id IS NULL");
  if (!$stmt) return [];
  $stmt->bind_param('s', $mapId);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($res && ($r = $res->fetch_assoc())) {
    $rows[] = $r;
  }
  $stmt->close();

  // Build spawn_key set
  $spawnKeys = [];
  foreach ($spawns as $s) {
    if (!empty($s['spawn_key'])) $spawnKeys[(string)$s['spawn_key']] = $s;
  }

  // Prune stale mobs (spawn removed from scripts)
  $stale = [];
  foreach ($rows as $r) {
    $sk = (string)$r['spawn_key'];
    if ($sk !== '' && !isset($spawnKeys[$sk])) {
      $stale[] = (int)$r['mob_id'];
    }
  }
  if (count($stale) > 0) {
    $ids = implode(',', array_map('intval', $stale));
    @$conn->query("DELETE FROM mob_instance WHERE mob_id IN ($ids)");
    // reload list (simpler)
    return mob_tick_map($conn, $mapId, $spawns);
  }

  // Respawn due mobs
  $now = time();
  foreach ($rows as $r) {
    if ((int)$r['state'] !== 1) continue;
    $ra = $r['respawn_at'] ? strtotime((string)$r['respawn_at']) : null;
    if ($ra !== null && $ra <= $now) {
      $sk = (string)$r['spawn_key'];
      if (!isset($spawnKeys[$sk])) continue;
      $sp = $spawnKeys[$sk];
      [$nx, $ny] = monster_pick_spawn_xy($mapId, (int)$sp['x'], (int)$sp['y'], (int)$sp['w'], (int)$sp['h']);
      $lvl = rand_level((int)$sp['lv_min'], (int)$sp['lv_max']);
      $dir = (int)$sp['dir'];
      $respawnSec = max(1, (int)$sp['respawn_sec']);
      $stmt = $conn->prepare('UPDATE mob_instance SET state=0, x=?, y=?, dir=?, level=?, respawn_sec=?, dead_at=NULL, respawn_at=NULL WHERE mob_id=?');
      if ($stmt) {
        $mobId = (int)$r['mob_id'];
        $stmt->bind_param('iiiiii', $nx, $ny, $dir, $lvl, $respawnSec, $mobId);
        $stmt->execute();
        $stmt->close();
      }
    }
  }

  // Count alive by spawn_key
  $aliveCount = [];
  foreach ($rows as $r) {
    if ((int)$r['state'] !== 0) continue;
    $sk = (string)$r['spawn_key'];
    if (!isset($aliveCount[$sk])) $aliveCount[$sk] = 0;
    $aliveCount[$sk] += 1;
  }

  // Create missing mobs
  foreach ($spawns as $sp) {
    $sk = (string)($sp['spawn_key'] ?? '');
    if ($sk === '') continue;
    $want = max(1, (int)($sp['count'] ?? 1));
    $cur = (int)($aliveCount[$sk] ?? 0);
    $missing = $want - $cur;
    if ($missing <= 0) continue;

    $speciesId = resolve_species_id($conn, (string)($sp['species'] ?? ''));
    if ($speciesId <= 0) continue;

    $spawnName = (string)($sp['name'] ?? '');
    $baseX = (int)($sp['x'] ?? 0);
    $baseY = (int)($sp['y'] ?? 0);
    $w = (int)($sp['w'] ?? 1);
    $h = (int)($sp['h'] ?? 1);
    $dir = (int)($sp['dir'] ?? 0);
    $respawnSec = max(1, (int)($sp['respawn_sec'] ?? 30));

    $stmt = $conn->prepare("INSERT INTO mob_instance(map_id, spawn_key, spawn_name, species_id, level, x, y, dir, state, respawn_sec, owner_player_id, kind) VALUES (?,?,?,?,?,?,?,?,0,?,?, 'script')");
    if (!$stmt) continue;
    for ($i=0; $i<$missing; $i++) {
      [$nx, $ny] = monster_pick_spawn_xy($mapId, $baseX, $baseY, $w, $h);
      $lvl = rand_level((int)($sp['lv_min'] ?? 1), (int)($sp['lv_max'] ?? 1));
      $ownerNull = null;
      $stmt->bind_param('sssiiiiiii', $mapId, $sk, $spawnName, $speciesId, $lvl, $nx, $ny, $dir, $respawnSec, $ownerNull);
      @$stmt->execute();
    }
    $stmt->close();
  }

  // Return alive mobs
  $stmt = $conn->prepare("SELECT mob_id, spawn_key, spawn_name, species_id, level, x, y, dir FROM mob_instance WHERE map_id=? AND state=0 AND kind='script' AND owner_player_id IS NULL ORDER BY y, x, mob_id");
  if (!$stmt) return [];
  $stmt->bind_param('s', $mapId);
  $stmt->execute();
  $res = $stmt->get_result();
  $mobs = [];
  while ($res && ($r = $res->fetch_assoc())) {
    $mobs[] = [
      'mob_id' => (int)$r['mob_id'],
      'spawn_key' => (string)$r['spawn_key'],
      'spawn_name' => (string)$r['spawn_name'],
      'species_id' => (int)$r['species_id'],
      'level' => (int)$r['level'],
      'x' => (int)$r['x'],
      'y' => (int)$r['y'],
      'dir' => (int)$r['dir'],
    ];
  }
  $stmt->close();
  return $mobs;
}

function mob_mark_dead(mysqli $conn, int $mobId): bool {
  ensure_mob_instance_table($conn);
  // Get respawn_sec
  $stmt = $conn->prepare('SELECT respawn_sec FROM mob_instance WHERE mob_id=? LIMIT 1');
  if (!$stmt) return false;
  $stmt->bind_param('i', $mobId);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$row) return false;
  $sec = max(1, (int)$row['respawn_sec']);

  $respawnAt = date('Y-m-d H:i:s', time() + $sec);
  $stmt = $conn->prepare('UPDATE mob_instance SET state=1, dead_at=NOW(), respawn_at=? WHERE mob_id=?');
  if (!$stmt) return false;
  $stmt->bind_param('si', $respawnAt, $mobId);
  $ok = $stmt->execute();
  $stmt->close();
  return (bool)$ok;
}



function mob_cleanup_wild_other_maps(mysqli $conn, int $playerId, string $keepMapId): void {
  ensure_mob_instance_table($conn);
  $playerId = (int)$playerId;
  $keepMapId = trim((string)$keepMapId);
  if ($playerId <= 0 || $keepMapId === '') return;

  // Ensure we don't accumulate "private" wild mobs across map changes.
  // They are re-generated deterministically on the current map anyway.
  $stmt = $conn->prepare("DELETE FROM mob_instance WHERE kind='wild' AND owner_player_id=? AND map_id<>?");
  if (!$stmt) return;
  $stmt->bind_param('is', $playerId, $keepMapId);
  @$stmt->execute();
  $stmt->close();
}

function mob_tick_wild_map(mysqli $conn, string $mapId, int $playerId, int $gameVer, int $centerX, int $centerY): array {
  ensure_mob_instance_table($conn);
  mob_cleanup_wild_other_maps($conn, $playerId, $mapId);
  if ($playerId <= 0) return [];
  $mapId = trim((string)$mapId);
  if ($mapId === '') return [];

  // Load PRET map const (MAP_ROUTE1, etc.) from exported Packege/src data.
  $pm = pret_public_load_map($mapId);
  $mapConst = $pm ? (string)($pm['map_const'] ?? '') : '';
  if ($mapConst === '') return [];

  // Terrain scan (ONLY these behaviors are allowed to spawn).
  // IMPORTANT: do NOT spawn based on "near player" radius. The whole map is seeded once and then mobs roam.
  // This prevents visible "pop-in" while the camera scrolls.
  static $terrainCache = [];
  $cacheKey = $mapId;
  $grassTiles = [];
  $caveTiles = [];
  $indoorTiles = [];
  $waterTiles = [];
  if (isset($terrainCache[$cacheKey])) {
    $grassTiles = $terrainCache[$cacheKey]['grass'] ?? [];
    $caveTiles = $terrainCache[$cacheKey]['cave'] ?? [];
    $indoorTiles = $terrainCache[$cacheKey]['indoor'] ?? [];
    $waterTiles = $terrainCache[$cacheKey]['water'] ?? [];
  } else {
    $w = (int)($pm['width'] ?? 0);
    $h = (int)($pm['height'] ?? 0);
    $beh = is_array($pm['behavior'] ?? null) ? $pm['behavior'] : [];
    $col = is_array($pm['collision'] ?? null) ? $pm['collision'] : [];
    if ($w > 0 && $h > 0) {
      for ($y=0; $y<$h; $y++) {
        $base = $y * $w;
        for ($x=0; $x<$w; $x++) {
          $idx = $base + $x;
          $c = (int)($col[$idx] ?? 0);
          if ($c !== 0) continue; // blocked
          $b = (int)($beh[$idx] ?? 0);
          if (pret_public_is_grass_behavior($b)) $grassTiles[] = [$x, $y];
          if (pret_public_is_cave_behavior($b)) $caveTiles[] = [$x, $y];
          if (pret_public_is_indoor_encounter_behavior($b)) $indoorTiles[] = [$x, $y];
          if (pret_public_is_water_behavior($b)) $waterTiles[] = [$x, $y];
        }
      }
    }
    $terrainCache[$cacheKey] = ['grass'=>$grassTiles, 'cave'=>$caveTiles, 'indoor'=>$indoorTiles, 'water'=>$waterTiles];
  }

  // Land spawns: grass + cave + indoor (explicitly excludes beach sand: MB_SAND)
  $landGroups = [];
  if (count($grassTiles) > 0) $landGroups[] = ['terrain'=>'grass', 'tiles'=>$grassTiles];
  if (count($caveTiles) > 0) $landGroups[] = ['terrain'=>'cave', 'tiles'=>$caveTiles];
  if (count($indoorTiles) > 0) $landGroups[] = ['terrain'=>'indoor', 'tiles'=>$indoorTiles];

  // Desired visible mobs per map (player-private). Keep it modest to avoid clutter.
  $wantLand  = (count($landGroups) > 0) ? 6 : 0;
  $wantWater = (count($waterTiles) > 0) ? 3 : 0;


  // Load existing wild mobs for this player+map
  $stmt = $conn->prepare("SELECT mob_id, spawn_key, spawn_name, species_id, level, x, y, dir, state, respawn_sec, respawn_at, terrain, home_x, home_y, roam_radius, next_move_at FROM mob_instance WHERE map_id=? AND kind='wild' AND owner_player_id=?");
  if (!$stmt) return [];
  $stmt->bind_param('si', $mapId, $playerId);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
  $stmt->close();

  $byName = [];
  foreach ($rows as $r) {
    $sn = (string)($r['spawn_name'] ?? '');
    if ($sn === '') continue;
    // If duplicates exist, keep the newest mob_id (best-effort).
    $cur = $byName[$sn] ?? null;
    if (!$cur || (int)($r['mob_id'] ?? 0) > (int)($cur['mob_id'] ?? 0)) $byName[$sn] = $r;
  }

  $now = time();

  $pickTile = function(array $tiles) {
    if (count($tiles) < 1) return null;
    return $tiles[random_int(0, count($tiles)-1)];
  };

  $pickGroup = function(string $slotKey, array $groups) {
    $n = count($groups);
    if ($n < 1) return null;
    $h = (int)crc32($slotKey);
    if ($h < 0) $h = -$h;
    $idx = $h % $n;
    return $groups[$idx];
  };

  $spawnOrRespawn = function(string $slotName, string $slotKey, array $tiles, string $encKind, string $terrain) use ($conn, $playerId, $mapId, $mapConst, $gameVer, $now, $pickTile, &$byName) {
    $row = $byName[$slotName] ?? null;

    // Decide if this slot is currently usable (terrain exists + encounter data exists)
    $tile = $pickTile($tiles);
    if (!$tile) {
      // No valid terrain nearby: delete existing slot mob (if any)
      if ($row) {
        $mid = (int)($row['mob_id'] ?? 0);
        if ($mid > 0) @$conn->query('DELETE FROM mob_instance WHERE mob_id=' . (int)$mid);
        unset($byName[$slotName]);
      }
      return;
    }

    // Find encounter (species+level) for this map/version.
    $pick = wild_pick_species_token_and_level($mapConst, $gameVer, $encKind);
    if (!$pick) {
      // No encounter table for this terrain: delete existing slot mob (if any)
      if ($row) {
        $mid = (int)($row['mob_id'] ?? 0);
        if ($mid > 0) @$conn->query('DELETE FROM mob_instance WHERE mob_id=' . (int)$mid);
        unset($byName[$slotName]);
      }
      return;
    }

    $sid = resolve_species_id($conn, (string)$pick['species_token']);
    if ($sid <= 0) return;
    $lvl = max(1, (int)($pick['level'] ?? 1));

    $x = (int)$tile[0];
    $y = (int)$tile[1];
    $dir = random_int(0, 3);
    $respawnSec = 20;

    if (!$row) {
      $sk = sha1($slotKey);
      // For player-private wild mobs we keep a "home" tile and a roam radius.
      // They can move, but only inside this spawn range.
      $roam = ($terrain === 'water') ? 4 : 3;
      $nextMoveAt = date('Y-m-d H:i:s', time() + random_int(1, 3));

      $stmt = $conn->prepare("INSERT INTO mob_instance(map_id, spawn_key, spawn_name, species_id, level, x, y, dir, home_x, home_y, roam_radius, next_move_at, state, respawn_sec, owner_player_id, kind, terrain) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?, 'wild', ?)");
      if (!$stmt) return;
      $stmt->bind_param('sssiiiiiiiisiis', $mapId, $sk, $slotName, $sid, $lvl, $x, $y, $dir, $x, $y, $roam, $nextMoveAt, $respawnSec, $playerId, $terrain);
      @$stmt->execute();
      $stmt->close();

      // Reload row for return stability
      $stmt = $conn->prepare("SELECT mob_id, spawn_key, spawn_name, species_id, level, x, y, dir, state, respawn_sec, respawn_at, terrain, home_x, home_y, roam_radius, next_move_at FROM mob_instance WHERE map_id=? AND kind='wild' AND owner_player_id=? AND spawn_name=? ORDER BY mob_id DESC LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('sis', $mapId, $playerId, $slotName);
        $stmt->execute();
        $res = $stmt->get_result();
        $nr = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($nr) $byName[$slotName] = $nr;
      }
      return;
    }

    // Existing slot:
    $mid = (int)($row['mob_id'] ?? 0);
    if ($mid <= 0) return;

    $state = (int)($row['state'] ?? 0);
    $ra = $row['respawn_at'] ? strtotime((string)$row['respawn_at']) : null;

    // NOTE: We do NOT relocate based on player distance. Mobs are seeded for the whole map.
    // If alive but not on the correct terrain (export mismatch / edge cases), relocate.
    $curB = pret_public_behavior_at($mapId, (int)$row['x'], (int)$row['y']);
    $badTerrain = false;
    if ($state === 0) {
      $terr = (string)($row['terrain'] ?? $terrain);
      if ($terr === '') $terr = ($encKind === 'water') ? 'water' : 'grass';
      $badTerrain = !mob_tile_ok_for_terrain((int)$curB, $terr);
    }

    $shouldRespawn = ($state === 1 && $ra !== null && $ra <= $now);

    if ($shouldRespawn || $badTerrain) {
      $roam = ($terrain === 'water') ? 4 : 3;
      $nextMoveAt = date('Y-m-d H:i:s', time() + random_int(1, 3));
      $stmt = $conn->prepare('UPDATE mob_instance SET state=0, species_id=?, level=?, x=?, y=?, dir=?, home_x=?, home_y=?, roam_radius=?, terrain=?, next_move_at=?, respawn_sec=?, dead_at=NULL, respawn_at=NULL WHERE mob_id=?');
      if ($stmt) {
        $stmt->bind_param('iiiiiiiissii', $sid, $lvl, $x, $y, $dir, $x, $y, $roam, $terrain, $nextMoveAt, $respawnSec, $mid);
        @$stmt->execute();
        $stmt->close();
      }
      // Refresh cached row
      $byName[$slotName]['state'] = 0;
      $byName[$slotName]['species_id'] = $sid;
      $byName[$slotName]['level'] = $lvl;
      $byName[$slotName]['x'] = $x;
      $byName[$slotName]['y'] = $y;
      $byName[$slotName]['dir'] = $dir;
      $byName[$slotName]['home_x'] = $x;
      $byName[$slotName]['home_y'] = $y;
      $byName[$slotName]['roam_radius'] = $roam;
      $byName[$slotName]['next_move_at'] = $nextMoveAt;
      $byName[$slotName]['respawn_sec'] = $respawnSec;
      $byName[$slotName]['terrain'] = $terrain;
      $byName[$slotName]['respawn_at'] = null;
      return;
    }

    // If alive but on invalid terrain (map changes / export mismatch), relocate.
    if ($state === 0) {
      // Caller ensured tiles are valid for this slot. Nothing else to do.
      return;
    }
  };

  // Reconcile LAND slots
  for ($i=0; $i<$wantLand; $i++) {
    $slotName = 'WILD_LAND_' . $i;
    $slotKey  = "wild|land|$playerId|$mapId|$i";
    $g = $pickGroup($slotKey, $landGroups);
    if ($g) $spawnOrRespawn($slotName, $slotKey, $g['tiles'], 'land', $g['terrain']);
  }
  // Delete extra LAND slots (if grass disappeared or want reduced)
  foreach (array_keys($byName) as $sn) {
    if (str_starts_with($sn, 'WILD_LAND_')) {
      $idx = (int)substr($sn, 10);
      if ($idx >= $wantLand) {
        $mid = (int)($byName[$sn]['mob_id'] ?? 0);
        if ($mid > 0) @$conn->query('DELETE FROM mob_instance WHERE mob_id=' . (int)$mid);
        unset($byName[$sn]);
      }
    }
  }

  // Reconcile WATER slots
  for ($i=0; $i<$wantWater; $i++) {
    $slotName = 'WILD_WATER_' . $i;
    $slotKey  = "wild|water|$playerId|$mapId|$i";
    $spawnOrRespawn($slotName, $slotKey, $waterTiles, 'water', 'water');
  }
  // Delete extra WATER slots
  foreach (array_keys($byName) as $sn) {
    if (str_starts_with($sn, 'WILD_WATER_')) {
      $idx = (int)substr($sn, 11);
      if ($idx >= $wantWater) {
        $mid = (int)($byName[$sn]['mob_id'] ?? 0);
        if ($mid > 0) @$conn->query('DELETE FROM mob_instance WHERE mob_id=' . (int)$mid);
        unset($byName[$sn]);
      }
    }
  }

  // --- Roaming movement (player-private visible encounters) ---
  // We move wild mobs *server-side* so the client only needs to render (no auto/ folder, no NPC fallback).
  // Mobs roam only within their spawn range (home_x/home_y +/- roam_radius) AND must stay on the same
  // terrain behavior (grass-only for land, water-only for water).
  $stmt = $conn->prepare("SELECT mob_id, spawn_name, x, y, dir, home_x, home_y, roam_radius, next_move_at, terrain FROM mob_instance WHERE map_id=? AND state=0 AND kind='wild' AND owner_player_id=?");
  if ($stmt) {
    $stmt->bind_param('si', $mapId, $playerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $alive = [];
    while ($res && ($r = $res->fetch_assoc())) $alive[] = $r;
    $stmt->close();

    // Occupied tiles (avoid stacking)
    $occ = [];
    foreach ($alive as $r) {
      $occ[((int)$r['x']) . ',' . ((int)$r['y'])] = (int)$r['mob_id'];
    }

    foreach ($alive as $r) {
      $mid = (int)($r['mob_id'] ?? 0);
      if ($mid <= 0) continue;
      $sx = (int)($r['x'] ?? 0);
      $sy = (int)($r['y'] ?? 0);
      $sdir = (int)($r['dir'] ?? 0);
      $sn = (string)($r['spawn_name'] ?? '');
      $encKind = (str_starts_with($sn, 'WILD_WATER_')) ? 'water' : 'land';

      $homeX = array_key_exists('home_x', $r) && $r['home_x'] !== null ? (int)$r['home_x'] : $sx;
      $homeY = array_key_exists('home_y', $r) && $r['home_y'] !== null ? (int)$r['home_y'] : $sy;
      $roam = array_key_exists('roam_radius', $r) && $r['roam_radius'] !== null ? (int)$r['roam_radius'] : 0;
      $terr = (string)($r['terrain'] ?? '');
      if ($terr === '') $terr = ($encKind === 'water') ? 'water' : 'grass';
      if ($roam <= 0) $roam = ($terr === 'water') ? 4 : 3;

      $nma = (string)($r['next_move_at'] ?? '');
      $due = true;
      if ($nma !== '') {
        $t = strtotime($nma);
        if ($t !== false && $t > $now) $due = false;
      }

      // Ensure home/roam are populated at least once.
      if (!array_key_exists('home_x', $r) || !array_key_exists('home_y', $r) || $r['home_x'] === null || $r['home_y'] === null) {
        $stmt2 = $conn->prepare('UPDATE mob_instance SET home_x=?, home_y=?, roam_radius=?, next_move_at=? WHERE mob_id=?');
        if ($stmt2) {
          $nextMoveAt0 = date('Y-m-d H:i:s', time() + random_int(1, 3));
          $stmt2->bind_param('iiisi', $homeX, $homeY, $roam, $nextMoveAt0, $mid);
          @$stmt2->execute();
          $stmt2->close();
        }
        // keep going; movement below will also update.
      }

      if (!$due) continue;

      $nx = $sx; $ny = $sy; $ndir = $sdir;
      $moved = false;

      // 0..99 : 70% attempt to move, 30% turn in place
      if (random_int(0, 99) < 70) {
        $dirs = [
          [0, -1, 0],
          [1,  0, 1],
          [0,  1, 2],
          [-1, 0, 3],
        ];

        // Try up to 6 random directions to find a valid tile.
        for ($tries = 0; $tries < 6; $tries++) {
          $d = $dirs[random_int(0, 3)];
          $tx = $sx + (int)$d[0];
          $ty = $sy + (int)$d[1];
          $td = (int)$d[2];

          // keep inside spawn range (Manhattan)
          if ((abs($tx - $homeX) + abs($ty - $homeY)) > $roam) continue;

          // don't step onto the player's current tile
          if ($tx === (int)$centerX && $ty === (int)$centerY) continue;

          // don't stack on other mobs
          $k = $tx . ',' . $ty;
          if (isset($occ[$k]) && (int)$occ[$k] !== $mid) continue;

          // blocked tile? (collision)
          if (pret_public_is_blocked($mapId, $tx, $ty)) continue;

          // terrain behavior strict (keep mobs inside their environment)
          $b = pret_public_behavior_at($mapId, $tx, $ty);
          if (!mob_tile_ok_for_terrain((int)$b, $terr)) continue;

          $nx = $tx; $ny = $ty; $ndir = $td;
          $moved = true;
          // update occupancy
          unset($occ[$sx . ',' . $sy]);
          $occ[$nx . ',' . $ny] = $mid;
          break;
        }
      } else {
        $ndir = random_int(0, 3);
      }

      $nextMoveAt = date('Y-m-d H:i:s', time() + random_int(1, 3));
      if ($moved || $ndir !== $sdir) {
        $stmt3 = $conn->prepare('UPDATE mob_instance SET x=?, y=?, dir=?, next_move_at=? WHERE mob_id=?');
        if ($stmt3) {
          $stmt3->bind_param('iiisi', $nx, $ny, $ndir, $nextMoveAt, $mid);
          @$stmt3->execute();
          $stmt3->close();
        }
      } else {
        // still schedule the next move so polling doesn't make them move too fast
        $stmt4 = $conn->prepare('UPDATE mob_instance SET next_move_at=? WHERE mob_id=?');
        if ($stmt4) {
          $stmt4->bind_param('si', $nextMoveAt, $mid);
          @$stmt4->execute();
          $stmt4->close();
        }
      }
    }
  }

  // Return alive wild mobs for this player (only state=0)
  $stmt = $conn->prepare("SELECT mob_id, spawn_key, spawn_name, species_id, level, x, y, dir FROM mob_instance WHERE map_id=? AND state=0 AND kind='wild' AND owner_player_id=? ORDER BY y, x, mob_id");
  if (!$stmt) return [];
  $stmt->bind_param('si', $mapId, $playerId);
  $stmt->execute();
  $res = $stmt->get_result();
  $mobs = [];
  while ($res && ($r = $res->fetch_assoc())) {
    $mobs[] = [
      'mob_id' => (int)$r['mob_id'],
      'spawn_key' => (string)$r['spawn_key'],
      'spawn_name' => (string)$r['spawn_name'],
      'species_id' => (int)$r['species_id'],
      'level' => (int)$r['level'],
      'x' => (int)$r['x'],
      'y' => (int)$r['y'],
      'dir' => (int)$r['dir'],
      'kind' => 'wild',
      'owner_player_id' => $playerId,
    ];
  }
  $stmt->close();
  return $mobs;
}



function mob_mark_dead_owned(mysqli $conn, int $mobId, int $playerId): bool {
  ensure_mob_instance_table($conn);

  $stmt = $conn->prepare('SELECT respawn_sec, kind, owner_player_id FROM mob_instance WHERE mob_id=? LIMIT 1');
  if (!$stmt) return false;
  $stmt->bind_param('i', $mobId);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$row) return false;

  $kind = (string)($row['kind'] ?? 'script');
  $owner = isset($row['owner_player_id']) ? (int)$row['owner_player_id'] : 0;

  if ($kind === 'wild') {
    if ($playerId <= 0 || $owner !== $playerId) return false;
  } else {
    // shared/script mobs: only allow if no owner
    if ($owner !== 0) return false;
  }

  $sec = max(1, (int)($row['respawn_sec'] ?? 30));
  $respawnAt = date('Y-m-d H:i:s', time() + $sec);

  $stmt = $conn->prepare('UPDATE mob_instance SET state=1, dead_at=NOW(), respawn_at=? WHERE mob_id=?');
  if (!$stmt) return false;
  $stmt->bind_param('si', $respawnAt, $mobId);
  $ok = $stmt->execute();
  $stmt->close();
  return (bool)$ok;
}