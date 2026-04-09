<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    json_out(['ok' => true]);
}

$in = json_in();
require_fields($in, ['map_id', 'x', 'y', 'dir']);

$map_id = trim((string)($in['map_id'] ?? ''));
$x = (int)($in['x'] ?? 0);
$y = (int)($in['y'] ?? 0);
$dir = (int)($in['dir'] ?? 0);
$tick = isset($in['tick']) ? (int)$in['tick'] : 0;

if ($map_id === '') $map_id = 'PalletTown';
if ($dir < 0 || $dir > 3) $dir = 0;

$conn = db();
$token = auth_get_bearer_token();
if ($token === '') {
    json_out(['ok' => false, 'error' => 'UNAUTH'], 401);
}
$tmp = verify_token($token);
if (!$tmp || (string)($tmp['t'] ?? '') !== 'play') {
    json_out(['ok' => false, 'error' => 'UNAUTH'], 401);
}

$player_id = (int)($tmp['player_id'] ?? 0);
$account_id = (int)($tmp['account_id'] ?? 0);
if ($player_id <= 0 || $account_id <= 0) {
    json_out(['ok' => false, 'error' => 'BAD_PLAYER_ID'], 401);
}

$stmt = $conn->prepare('SELECT player_id, account_id, map_id, x, y, dir, COALESCE(client_tick,0) AS client_tick FROM player WHERE player_id=? AND account_id=? LIMIT 1');
if (!$stmt) json_out(['ok' => false, 'error' => 'DB_PREPARE_FAIL', 'detail' => $conn->error], 500);
$stmt->bind_param('ii', $player_id, $account_id);
$stmt->execute();
$res = $stmt->get_result();
$cur = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$cur) {
    json_out(['ok' => false, 'error' => 'NO_SUCH_PLAYER'], 404);
}

$oldMap = (string)($cur['map_id'] ?? '');
$oldX = (int)($cur['x'] ?? 0);
$oldY = (int)($cur['y'] ?? 0);
$oldDir = (int)($cur['dir'] ?? 0);
$oldTick = (int)($cur['client_tick'] ?? 0);

$maxW = null;
$maxH = null;
$stmt = $conn->prepare('SELECT w, h FROM maps_info WHERE mapname=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $map_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $mi = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($mi) {
        $maxW = (int)$mi['w'];
        $maxH = (int)$mi['h'];
    }
}
if ($maxW !== null && $maxH !== null && $maxW > 0 && $maxH > 0) {
    if ($x < 0 || $y < 0 || $x >= $maxW || $y >= $maxH) {
        json_out(['ok' => false, 'error' => 'OOB'], 400);
    }
}

if ($tick < $oldTick - 5) {
    $tick = $oldTick;
}

if ($map_id === $oldMap && $x === $oldX && $y === $oldY && $dir === $oldDir) {
    $stmt = $conn->prepare('UPDATE player SET dir=?, client_tick=? WHERE player_id=? AND account_id=?');
    if (!$stmt) json_out(['ok' => false, 'error' => 'DB_PREPARE_FAIL', 'detail' => $conn->error], 500);
    $stmt->bind_param('iiii', $dir, $tick, $player_id, $account_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    if (!$ok) json_out(['ok' => false, 'error' => 'DB_EXEC_FAIL', 'detail' => $err], 500);
    json_out([
        'ok' => true,
        'tick' => $tick,
        'dt' => 0.0,
        'dist' => 0,
        'mapChanged' => false,
        'noop' => true,
        'player_id' => $player_id,
    ]);
}

$dist = abs($x - $oldX) + abs($y - $oldY);
$mapChanged = ($map_id !== $oldMap);

$stmt = $conn->prepare('UPDATE player SET map_id=?, x=?, y=?, dir=?, updated_at=CURRENT_TIMESTAMP, client_tick=? WHERE player_id=? AND account_id=?');
if (!$stmt) json_out(['ok' => false, 'error' => 'DB_PREPARE_FAIL', 'detail' => $conn->error], 500);
$stmt->bind_param('siiiiii', $map_id, $x, $y, $dir, $tick, $player_id, $account_id);
$ok = $stmt->execute();
$err = $stmt->error;
$stmt->close();
if (!$ok) json_out(['ok' => false, 'error' => 'DB_EXEC_FAIL', 'detail' => $err], 500);

json_out([
    'ok' => true,
    'tick' => $tick,
    'dt' => 0.0,
    'dist' => $dist,
    'mapChanged' => $mapChanged,
    'player_id' => $player_id,
]);
