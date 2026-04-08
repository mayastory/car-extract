<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
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

$player_id = (int)($payload['player_id'] ?? 0);
$game_ver = 0;
if ($player_id <= 0) json_out(['ok'=>false,'error'=>'BAD_PLAYER_ID'], 401);

$stmt = $conn->prepare('SELECT player_id, slot, display_name, gender, map_id, x, y, dir, updated_at, client_tick FROM player WHERE player_id=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('i', $player_id);
$stmt->execute();
$res = $stmt->get_result();
$pl = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$pl) json_out(['ok'=>false,'error'=>'NO_SUCH_PLAYER'], 404);

$gv = player_flag_get($conn, $player_id, 'GAME_VER');
if ($gv !== 1 && $gv !== 2) {
  $gv = random_int(1, 2);
  player_flag_set($conn, $player_id, 'GAME_VER', (int)$gv);
}
$game_ver = (int)$gv;

json_out([
  'ok' => true,
  'dev_auth_bypass' => $devAuthBypass,
  'state' => [
    'player_id' => (int)$pl['player_id'],
    'slot' => (int)$pl['slot'],
    'display_name' => (string)$pl['display_name'],
    'gender' => (string)$pl['gender'],
    'game_ver' => (int)$game_ver,
    'map_id' => (string)$pl['map_id'],
    'x' => (int)$pl['x'],
    'y' => (int)$pl['y'],
    'dir' => (int)$pl['dir'],
    'updated_at' => (string)$pl['updated_at'],
    'client_tick' => (int)$pl['client_tick'],
    'tick' => (int)$pl['client_tick'],
  ],
]);
