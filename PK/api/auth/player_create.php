<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/flag_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_account();
$account_id = (int)($payload['account_id'] ?? 0);
if ($account_id <= 0) json_out(['ok'=>false,'error'=>'BAD_ACCOUNT_ID'], 401);

$in = json_in();
require_fields($in, ['slot','display_name']);
$slot = (int)$in['slot'];
$name = trim((string)$in['display_name']);
$gender = isset($in['gender']) ? strtoupper(trim((string)$in['gender'])) : 'M';

if ($slot < 0 || $slot > 3) json_out(['ok'=>false,'error'=>'BAD_SLOT'], 400);
if ($name === '') json_out(['ok'=>false,'error'=>'BAD_NAME'], 400);
if (!preg_match('/^[A-Za-z0-9 _\-가-힣]{2,16}$/u', $name)) {
  json_out(['ok'=>false,'error'=>'BAD_NAME_CHARS','hint'=>'2~16자, 영문/숫자/공백/_-/(한글) 허용'], 400);
}
if ($gender !== 'M' && $gender !== 'F') $gender = 'M';

$conn = db();

// slot already used?
$stmt = $conn->prepare('SELECT player_id FROM player WHERE account_id=? AND slot=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ii', $account_id, $slot);
$stmt->execute();
$res = $stmt->get_result();
$exists = $res ? $res->fetch_assoc() : null;
$stmt->close();
if ($exists) json_out(['ok'=>false,'error'=>'SLOT_OCCUPIED'], 409);

// name unique (global)
$stmt = $conn->prepare('SELECT player_id FROM player WHERE display_name=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('s', $name);
$stmt->execute();
$res = $stmt->get_result();
$dup = $res ? $res->fetch_assoc() : null;
$stmt->close();
if ($dup) json_out(['ok'=>false,'error'=>'NAME_TAKEN'], 409);

$spawnMap = 'PalletTown';
$spawnX = 10;
$spawnY = 10;
$spawnDir = 0;

$stmt = $conn->prepare('INSERT INTO player (account_id, slot, display_name, gender, map_id, x, y, dir) VALUES (?,?,?,?,?,?,?,?)');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('iisssiii', $account_id, $slot, $name, $gender, $spawnMap, $spawnX, $spawnY, $spawnDir);
$ok = $stmt->execute();
$err = $stmt->error;
$newId = $stmt->insert_id;
$stmt->close();
if (!$ok) json_out(['ok'=>false,'error'=>'DB_EXEC_FAIL','detail'=>$err], 500);

// Assign game version (FR/LG) once per character.
$gv = random_int(1, 2);
player_flag_set($conn, (int)$newId, 'GAME_VER', (int)$gv);

// Return updated player list (for slot UI refresh)
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

json_out([
  'ok'=>true,
  'player'=>[
    'player_id'=>(int)$newId,
    'slot'=>$slot,
    'display_name'=>$name,
    'gender'=>$gender,
    'map_id'=>$spawnMap,
    'x'=>$spawnX,
    'y'=>$spawnY,
    'dir'=>$spawnDir,
    'game_ver'=>(int)$gv,
  ],
  'slots'=>4,
  'players'=>$players,
]);
