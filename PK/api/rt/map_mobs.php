<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/monster_runtime.php';
require_once __DIR__ . '/../lib/flag_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

function dev_pick_player(mysqli $conn, int $forcePlayerId = 0): ?array {
  if ($forcePlayerId > 0) {
    $stmt = $conn->prepare('SELECT p.player_id, p.account_id, p.slot, p.display_name, p.gender, p.map_id, p.x, p.y, p.dir, p.updated_at, p.client_tick, a.username, a.is_banned, a.created_at AS account_created_at, a.last_login_at FROM player p LEFT JOIN account a ON a.account_id=p.account_id WHERE p.player_id=? LIMIT 1');
    if ($stmt) {
      $stmt->bind_param('i', $forcePlayerId);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      if ($row) return $row;
    }
  }
  $sql = 'SELECT p.player_id, p.account_id, p.slot, p.display_name, p.gender, p.map_id, p.x, p.y, p.dir, p.updated_at, p.client_tick, a.username, a.is_banned, a.created_at AS account_created_at, a.last_login_at FROM player p LEFT JOIN account a ON a.account_id=p.account_id ORDER BY p.player_id ASC LIMIT 1';
  $res = $conn->query($sql);
  return $res ? ($res->fetch_assoc() ?: null) : null;
}

$conn = db();
$devAuthBypass = false;
$payload = null;
$token = auth_get_bearer_token();
if ($token !== '') {
  $tmp = verify_token($token);
  if ($tmp && (string)($tmp['t'] ?? '') === 'play') {
    $payload = $tmp;
  }
}
if (!$payload) {
  $fallback = dev_pick_player($conn, (int)($_GET['player_id'] ?? 0));
  if ($fallback) {
    $payload = [
      'player_id' => (int)$fallback['player_id'],
      'account_id' => (int)$fallback['account_id'],
      'slot' => (int)$fallback['slot'],
      't' => 'play',
    ];
    $devAuthBypass = true;
  }
}
$self_player_id = (int)($payload['player_id'] ?? 0);
if ($self_player_id <= 0) json_out(['ok'=>false,'error'=>'BAD_PLAYER_ID'], 401);

$stmt = $conn->prepare('SELECT map_id, x, y FROM player WHERE player_id=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('i', $self_player_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$row) json_out(['ok'=>false,'error'=>'NO_SUCH_PLAYER'], 404);

$map_id = (string)$row['map_id'];
$px = (int)($row['x'] ?? 0);
$py = (int)($row['y'] ?? 0);
$game_ver = player_flag_get($conn, $self_player_id, 'GAME_VER');
if ($game_ver !== 1 && $game_ver !== 2) {
  $game_ver = random_int(1, 2);
  player_flag_set($conn, $self_player_id, 'GAME_VER', $game_ver);
}
if ($map_id === '') $map_id = 'PalletTown';
if (isset($_GET['map']) && $_GET['map'] !== '') {
  $map_id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['map']);
}
$spawns = monster_load_spawns($map_id);
$mobs_script = mob_tick_map($conn, $map_id, $spawns);
$mobs_wild = mob_tick_wild_map($conn, $map_id, $self_player_id, $game_ver, $px, $py);
$mobs = array_merge($mobs_script, $mobs_wild);

$speciesIds = [];
foreach ($mobs as $m) {
  $sid = (int)($m['species_id'] ?? 0);
  if ($sid > 0) $speciesIds[$sid] = 1;
}
$idToKey = [];
if (count($speciesIds) > 0) {
  $ids = array_keys($speciesIds);
  $idList = implode(',', array_map('intval', $ids));
  $stmt = $conn->prepare("SELECT species_id, const_name, name FROM ref_species WHERE species_id IN ($idList)");
  if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
      $cid = (int)$r['species_id'];
      $const = (string)($r['const_name'] ?? '');
      $nm = (string)($r['name'] ?? '');
      $key = '';
      if ($const !== '' && stripos($const, 'SPECIES_') === 0) {
        $key = strtolower(substr($const, 8));
      } else if ($nm !== '') {
        $key = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $nm));
      }
      $idToKey[$cid] = $key;
    }
    $stmt->close();
  }
}
for ($i = 0; $i < count($mobs); $i++) {
  $sid = (int)($mobs[$i]['species_id'] ?? 0);
  if ($sid > 0 && isset($idToKey[$sid])) $mobs[$i]['species_key'] = $idToKey[$sid];
}

json_out([
  'ok' => true,
  'dev_auth_bypass' => $devAuthBypass,
  'map_id' => $map_id,
  'game_ver' => (int)$game_ver,
  'spawn_count' => count($spawns),
  'mob_count' => count($mobs),
  'mobs' => $mobs,
]);
