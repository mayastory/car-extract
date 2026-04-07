<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../util/auth_token.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_player();
$self_player_id = (int)($payload['player_id'] ?? 0);
if ($self_player_id <= 0) json_out(['ok'=>false,'error'=>'BAD_PLAYER_ID'], 401);

$conn = db();

// Determine current map of self
$stmt = $conn->prepare('SELECT map_id FROM player WHERE player_id=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('i', $self_player_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$row) json_out(['ok'=>false,'error'=>'NO_SUCH_PLAYER'], 404);

$map_id = (string)$row['map_id'];
if ($map_id === '') $map_id = 'PalletTown';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$limit = max(1, min(200, $limit));

$stmt = $conn->prepare('
  SELECT player_id, display_name, gender, map_id, x, y, dir, updated_at
  FROM player
  WHERE map_id=? AND player_id<>? AND updated_at >= (NOW() - INTERVAL 12 SECOND)
  ORDER BY player_id
  LIMIT ?
');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('sii', $map_id, $self_player_id, $limit);
$stmt->execute();
$res = $stmt->get_result();
$players = [];
if ($res) {
  while ($p = $res->fetch_assoc()) {
    $players[] = [
      'player_id' => (int)$p['player_id'],
      'display_name' => (string)$p['display_name'],
      'gender' => (string)$p['gender'],
      'map_id' => (string)$p['map_id'],
      'x' => (int)$p['x'],
      'y' => (int)$p['y'],
      'dir' => (int)$p['dir'],
      'updated_at' => (string)$p['updated_at'],
    ];
  }
}
$stmt->close();

json_out([
  'ok' => true,
  'map_id' => $map_id,
  'server_ms' => (int)floor(microtime(true) * 1000),
  'players' => $players,
]);
