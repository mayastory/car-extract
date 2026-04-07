<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/flag_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

// Accept either acc or play token
$token = auth_get_bearer_token();
if ($token === '' && isset($_GET['token'])) $token = (string)$_GET['token'];
if ($token === '') json_out(['ok'=>false,'error'=>'UNAUTH'], 401);

$payload = verify_token($token);
if (!$payload) json_out(['ok'=>false,'error'=>'UNAUTH'], 401);

$t = isset($payload['t']) ? (string)$payload['t'] : '';
$account_id = (int)($payload['account_id'] ?? 0);
$player_id = (int)($payload['player_id'] ?? 0);

$conn = db();
$account = null;
if ($account_id > 0) {
  $stmt = $conn->prepare('SELECT account_id, username, is_banned, created_at, last_login_at FROM account WHERE account_id=? LIMIT 1');
  if ($stmt) {
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $account = $res ? $res->fetch_assoc() : null;
    $stmt->close();
  }
}

$player = null;
if ($player_id > 0) {
  $stmt = $conn->prepare('SELECT player_id, account_id, slot, display_name, gender, map_id, x, y, dir, updated_at FROM player WHERE player_id=? LIMIT 1');
  if ($stmt) {
    $stmt->bind_param('i', $player_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $player = $res ? $res->fetch_assoc() : null;
    $stmt->close();
  }
}


if ($player_id > 0 && $player) {
  $gv = player_flag_get($conn, $player_id, 'GAME_VER');
  if ($gv !== 1 && $gv !== 2) {
    $gv = random_int(1, 2);
    player_flag_set($conn, $player_id, 'GAME_VER', (int)$gv);
  }
  $player['game_ver'] = (int)$gv;
}
json_out([
  'ok' => true,
  'token' => auth_token_debug_info($payload),
  'account' => $account,
  'player' => $player,
]);
