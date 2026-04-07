<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../util/auth_token.php';
require_once __DIR__ . '/../lib/flag_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_account();
$account_id = (int)($payload['account_id'] ?? 0);
if ($account_id <= 0) json_out(['ok'=>false,'error'=>'BAD_ACCOUNT_ID'], 401);

$in = json_in();
$slot = isset($in['slot']) ? (int)$in['slot'] : null;
$player_id_in = isset($in['player_id']) ? (int)$in['player_id'] : null;

if ($slot === null && $player_id_in === null) json_out(['ok'=>false,'error'=>'MISSING_FIELD','field'=>'slot|player_id'], 400);

$conn = db();

if ($player_id_in !== null) {
  $stmt = $conn->prepare('SELECT player_id, slot, display_name, gender, map_id, x, y, dir, updated_at FROM player WHERE account_id=? AND player_id=? LIMIT 1');
  if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
  $stmt->bind_param('ii', $account_id, $player_id_in);
} else {
  if ($slot < 0 || $slot > 3) json_out(['ok'=>false,'error'=>'BAD_SLOT'], 400);
  $stmt = $conn->prepare('SELECT player_id, slot, display_name, gender, map_id, x, y, dir, updated_at FROM player WHERE account_id=? AND slot=? LIMIT 1');
  if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
  $stmt->bind_param('ii', $account_id, $slot);
}

$stmt->execute();
$res = $stmt->get_result();
$pl = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$pl) json_out(['ok'=>false,'error'=>'NO_PLAYER_IN_SLOT'], 404);

$player_id = (int)$pl['player_id'];

// Ensure GAME_VER (1=FR, 2=LG) per character.
$gv = player_flag_get($conn, $player_id, 'GAME_VER');
if ($gv !== 1 && $gv !== 2) {
  $gv = random_int(1, 2);
  player_flag_set($conn, $player_id, 'GAME_VER', (int)$gv);
}

$playToken = sign_token([
  'v' => 1,
  't' => 'play',
  'account_id' => $account_id,
  'player_id' => $player_id,
  'slot' => (int)$pl['slot'],
], 86400 * 1);

// Also set HttpOnly cookie for SSE/EventSource (cannot set Authorization header)
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('poke_play_token', $playToken, [
  'expires' => time() + 86400,
  'path' => '/',
  'secure' => $secure,
  'httponly' => true,
  'samesite' => 'Lax',
]);


json_out([
  'ok' => true,
  'token_kind' => 'play',
  'play_token' => $playToken,
  'player' => [
    'player_id' => $player_id,
    'slot' => (int)$pl['slot'],
    'display_name' => (string)$pl['display_name'],
    'gender' => (string)$pl['gender'],
    'map_id' => (string)$pl['map_id'],
    'x' => (int)$pl['x'],
    'y' => (int)$pl['y'],
    'dir' => (int)$pl['dir'],
    'game_ver' => (int)$gv,
    'updated_at' => (string)$pl['updated_at'],
  ],
]);
