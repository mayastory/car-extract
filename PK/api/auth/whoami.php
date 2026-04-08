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
$tokenProvided = ($token !== '');
if ($token === '' && isset($_GET['token'])) {
  $token = (string)$_GET['token'];
  $tokenProvided = ($token !== '');
}
if ($token !== '') {
  $tmp = verify_token($token);
  if ($tmp && (string)($tmp['t'] ?? '') === 'play') {
    $payload = $tmp;
  } else {
    json_out(['ok'=>false,'error'=>'UNAUTH'], 401);
  }
}
if (!$payload && !$tokenProvided) {
  $fallback = dev_pick_player($conn, (int)($_GET['player_id'] ?? 0));
  if ($fallback) {
    $payload = [
      't' => 'play',
      'account_id' => (int)$fallback['account_id'],
      'player_id' => (int)$fallback['player_id'],
      'slot' => (int)$fallback['slot'],
    ];
    $devAuthBypass = true;
  }
}
if (!$payload) json_out(['ok'=>false,'error'=>'UNAUTH'], 401);

$t = isset($payload['t']) ? (string)$payload['t'] : '';
if ($t !== 'play') json_out(['ok'=>false,'error'=>'UNAUTH'], 401);
$account_id = (int)($payload['account_id'] ?? 0);
$player_id = (int)($payload['player_id'] ?? 0);
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
  'dev_auth_bypass' => $devAuthBypass,
  'token' => auth_token_debug_info($payload),
  'account' => $account,
  'player' => $player,
]);
