<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth_token.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$in = json_in();
require_fields($in, ['username','password']);
$username = trim((string)$in['username']);
$password = (string)$in['password'];

if ($username === '') json_out(['ok'=>false,'error'=>'BAD_USERNAME'], 400);
if ($password === '' || strlen($password) < 4) json_out(['ok'=>false,'error'=>'BAD_PASSWORD'], 400);

$conn = db();

$stmt = $conn->prepare('SELECT account_id, username, password_hash, is_banned FROM account WHERE username=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('s', $username);
$stmt->execute();
$res = $stmt->get_result();
$acc = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$acc) json_out(['ok'=>false,'error'=>'NO_SUCH_USER'], 404);
if ((int)$acc['is_banned'] === 1) json_out(['ok'=>false,'error'=>'BANNED'], 403);

$account_id = (int)$acc['account_id'];
$hash = (string)($acc['password_hash'] ?? '');

$ok = false;

// If legacy/DEV account exists without a real password, allow "first password set"
if ($hash === '' || $hash === 'LOCAL_ONLY_NO_LOGIN') {
  $newHash = password_hash($password, PASSWORD_DEFAULT);
  $stmt = $conn->prepare('UPDATE account SET password_hash=? WHERE account_id=?');
  if ($stmt) {
    $stmt->bind_param('si', $newHash, $account_id);
    $stmt->execute();
    $stmt->close();
    $hash = $newHash;
    $ok = true;
  } else {
    json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
  }
} else {
  $ok = password_verify($password, $hash);
}

if (!$ok) json_out(['ok'=>false,'error'=>'BAD_PASSWORD'], 401);

// Account token (do NOT embed player_id here)
$accToken = sign_token([
  'v' => 1,
  't' => 'acc',
  'account_id' => $account_id,
], 86400 * 7);

// Fetch up to 4 character slots
$stmt = $conn->prepare('SELECT player_id, slot, display_name, gender, map_id, x, y, dir, updated_at FROM player WHERE account_id=? ORDER BY slot ASC LIMIT 4');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('i', $account_id);
$stmt->execute();
$res = $stmt->get_result();
$players = [];
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $players[] = [
      'player_id' => (int)$row['player_id'],
      'slot' => (int)$row['slot'],
      'display_name' => (string)$row['display_name'],
      'gender' => (string)$row['gender'],
      'map_id' => (string)$row['map_id'],
      'x' => (int)$row['x'],
      'y' => (int)$row['y'],
      'dir' => (int)$row['dir'],
      'updated_at' => (string)$row['updated_at'],
    ];
  }
}
$stmt->close();

$conn->query('UPDATE account SET last_login_at=CURRENT_TIMESTAMP WHERE account_id=' . $account_id);

json_out([
  'ok' => true,
  'token_kind' => 'acc',
  'account_token' => $accToken,
  'account' => [
    'account_id' => $account_id,
    'username' => (string)$acc['username'],
  ],
  'slots' => 4,
  'players' => $players,
]);
