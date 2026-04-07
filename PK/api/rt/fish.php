<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/wild_encounters.php';
require_once __DIR__ . '/../lib/pret_public.php';
require_once __DIR__ . '/../lib/flag_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_player();
$player_id = (int)($payload['player_id'] ?? 0);
if ($player_id <= 0) json_out(['ok'=>false,'error'=>'BAD_PLAYER_ID'], 401);

$conn = db();

$stmt = $conn->prepare('SELECT map_id, x, y, dir FROM player WHERE player_id=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('i', $player_id);
$stmt->execute();
$res = $stmt->get_result();
$pl = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$pl) json_out(['ok'=>false,'error'=>'NO_SUCH_PLAYER'], 404);

$map_id = (string)($pl['map_id'] ?? '');
$px = (int)($pl['x'] ?? 0);
$py = (int)($pl['y'] ?? 0);
$dir = (int)($pl['dir'] ?? 0);
if ($map_id === '') $map_id = 'PalletTown';

$game_ver = player_flag_get($conn, $player_id, 'GAME_VER');
if ($game_ver !== 1 && $game_ver !== 2) {
  $game_ver = random_int(1, 2);
  player_flag_set($conn, $player_id, 'GAME_VER', $game_ver);
}

// fishing target tile: in front of player (GBA style)
$dx = 0; $dy = 0;
if ($dir === 0) { $dy = 1; }
else if ($dir === 1) { $dy = -1; }
else if ($dir === 2) { $dx = -1; }
else if ($dir === 3) { $dx = 1; }
$tx = $px + $dx;
$ty = $py + $dy;

$b = (int)pret_public_behavior_at($map_id, $tx, $ty);
if (!pret_public_is_water_behavior($b)) {
  json_out(['ok'=>false,'error'=>'NO_WATER_IN_FRONT','map_id'=>$map_id,'x'=>$px,'y'=>$py,'dir'=>$dir], 400);
}

$mapConst = pret_public_map_const($map_id);
$entry = wild_find_entry_for_map_const($mapConst, $game_ver);
if (!$entry) json_out(['ok'=>false,'error'=>'NO_ENCOUNTER_TABLE','map_id'=>$map_id,'map_const'=>$mapConst], 404);

$tbl = $entry['fishing_mons'] ?? null;
if (!$tbl || !is_array($tbl)) json_out(['ok'=>false,'error'=>'NO_FISHING_TABLE','map_id'=>$map_id,'map_const'=>$mapConst], 404);

$rate = (int)($tbl['encounter_rate'] ?? 0);
if ($rate < 0) $rate = 0;
if ($rate > 100) $rate = 100;

// Bite check (simple): if encounter_rate=0 -> never. if 100 -> always.
if ($rate > 0) {
  $roll = random_int(1, 100);
  if ($roll > $rate) {
    json_out(['ok'=>true,'bite'=>false,'map_id'=>$map_id,'tx'=>$tx,'ty'=>$ty]);
  }
} else {
  json_out(['ok'=>true,'bite'=>false,'map_id'=>$map_id,'tx'=>$tx,'ty'=>$ty]);
}

$pick = wild_pick_species_token_and_level($mapConst, $game_ver, 'fishing');
if (!$pick) json_out(['ok'=>false,'error'=>'FISH_PICK_FAIL'], 500);
$token = (string)($pick['species_token'] ?? '');
$lvl = (int)($pick['level'] ?? 1);

// Resolve species_id
$stmt = $conn->prepare('SELECT species_id, const_name, name FROM ref_species WHERE const_name=? OR name=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ss', $token, $token);
$stmt->execute();
$res = $stmt->get_result();
$sr = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$sr) json_out(['ok'=>false,'error'=>'UNKNOWN_SPECIES','species_token'=>$token], 500);

$sid = (int)($sr['species_id'] ?? 0);
$const = (string)($sr['const_name'] ?? '');
$nm = (string)($sr['name'] ?? '');
$key = '';
if ($const !== '' && stripos($const, 'SPECIES_') === 0) {
  $key = strtolower(substr($const, 8));
} else if ($nm !== '') {
  $key = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $nm));
}

json_out([
  'ok' => true,
  'bite' => true,
  'map_id' => $map_id,
  'tx' => (int)$tx,
  'ty' => (int)$ty,
  'species_id' => $sid,
  'species_key' => $key,
  'level' => $lvl,
]);
