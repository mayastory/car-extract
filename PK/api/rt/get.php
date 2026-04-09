<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/flag_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$conn = db();
$token = auth_get_bearer_token();
if ($token === '') {
  json_out(['ok'=>false,'error'=>'UNAUTH'], 401);
}
$tmp = verify_token($token);
if (!$tmp || (string)($tmp['t'] ?? '') !== 'play') {
  json_out(['ok'=>false,'error'=>'UNAUTH'], 401);
}

$account_id = (int)($tmp['account_id'] ?? 0);
$player_id = (int)($tmp['player_id'] ?? 0);
if ($player_id <= 0 || $account_id <= 0) {
  json_out(['ok'=>false,'error'=>'BAD_PLAYER_ID'], 401);
}

$stmt = $conn->prepare('SELECT player_id, account_id, slot, display_name, gender, map_id, x, y, dir, updated_at, client_tick FROM player WHERE player_id=? AND account_id=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ii', $player_id, $account_id);
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
