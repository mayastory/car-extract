<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../util/auth_token.php';

// SSE endpoint: streams nearby players (same map) in near-real-time.
// Uses Authorization: Bearer <play_token> if provided, otherwise falls back to HttpOnly cookie 'poke_play_token'.

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

$token = auth_get_bearer_token();
$payload = $token !== '' ? verify_token($token) : null;
if (!$payload || (string)($payload['t'] ?? '') !== 'play') {
  http_response_code(401);
  header('Content-Type: text/event-stream; charset=utf-8');
  header('Cache-Control: no-cache');
  echo "event: error\n";
  echo "data: " . json_encode(['ok'=>false,'error'=>'UNAUTH'], JSON_UNESCAPED_UNICODE) . "\n\n";
  @ob_flush(); @flush();
  exit;
}

$self_player_id = (int)($payload['player_id'] ?? 0);
if ($self_player_id <= 0) {
  http_response_code(401);
  header('Content-Type: text/event-stream; charset=utf-8');
  header('Cache-Control: no-cache');
  echo "event: error\n";
  echo "data: " . json_encode(['ok'=>false,'error'=>'BAD_PLAYER_ID'], JSON_UNESCAPED_UNICODE) . "\n\n";
  @ob_flush(); @flush();
  exit;
}

$interval_ms = isset($_GET['interval_ms']) ? (int)$_GET['interval_ms'] : 150;
$interval_ms = max(80, min(1000, $interval_ms));
$max_seconds = isset($_GET['max_seconds']) ? (int)$_GET['max_seconds'] : 55;
$max_seconds = max(10, min(300, $max_seconds));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$limit = max(1, min(200, $limit));

// SSE headers
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');

while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);

set_time_limit(0);
ignore_user_abort(true);

// Send padding to defeat proxy buffering
echo ":" . str_repeat(" ", 2048) . "\n\n";
@ob_flush(); @flush();

$conn = db();

$started = microtime(true);
$lastHash = '';
$seq = 0;

while (true) {
  if (connection_aborted()) break;
  if ((microtime(true) - $started) >= $max_seconds) break;

  // Get self map (so we automatically follow map changes without reconnect)
  $stmt = $conn->prepare('SELECT map_id FROM player WHERE player_id=? LIMIT 1');
  if (!$stmt) {
    echo "event: error\n";
    echo "data: " . json_encode(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush(); @flush();
    break;
  }
  $stmt->bind_param('i', $self_player_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$row) {
    echo "event: error\n";
    echo "data: " . json_encode(['ok'=>false,'error'=>'NO_SUCH_PLAYER'], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush(); @flush();
    break;
  }
  $map_id = (string)$row['map_id'];
  if ($map_id === '') $map_id = 'PalletTown';

  $stmt = $conn->prepare('
    SELECT player_id, display_name, gender, map_id, x, y, dir, updated_at
    FROM player
    WHERE map_id=? AND player_id<>? AND updated_at >= (NOW() - INTERVAL 12 SECOND)
    ORDER BY player_id
    LIMIT ?
  ');
  if (!$stmt) {
    echo "event: error\n";
    echo "data: " . json_encode(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush(); @flush();
    break;
  }
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

  $seq++;
  $payloadOut = [
    'ok' => true,
    'seq' => $seq,
    'map_id' => $map_id,
    'server_ms' => (int)floor(microtime(true) * 1000),
    'players' => $players,
  ];
  $json = json_encode($payloadOut, JSON_UNESCAPED_UNICODE);
  $h = md5($json);

  if ($h !== $lastHash) {
    echo "event: players\n";
    echo "data: " . $json . "\n\n";
    $lastHash = $h;
  } else {
    // heartbeat
    echo ": ping\n\n";
  }

  @ob_flush(); @flush();
  usleep($interval_ms * 1000);
}

// Let client reconnect
echo "event: end\n";
echo "data: " . json_encode(['ok'=>true,'reconnect'=>true], JSON_UNESCAPED_UNICODE) . "\n\n";
@ob_flush(); @flush();
