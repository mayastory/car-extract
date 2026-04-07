<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_account();
$account_id = (int)($payload['account_id'] ?? 0);
if ($account_id <= 0) json_out(['ok'=>false,'error'=>'BAD_ACCOUNT_ID'], 401);

$conn = db();
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

json_out(['ok'=>true,'slots'=>4,'players'=>$players]);
