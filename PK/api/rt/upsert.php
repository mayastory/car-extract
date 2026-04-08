<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/pret_public.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok' => true]);

$payload = auth_require_player();
$player_id = (int)($payload['player_id'] ?? 0);
$account_id = (int)($payload['account_id'] ?? 0);
if ($player_id <= 0 || $account_id <= 0) json_out(['ok' => false, 'error' => 'BAD_AUTH'], 401);

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

function ensure_move_guard_tables(mysqli $conn): void {
    @$conn->query("CREATE TABLE IF NOT EXISTS cheat_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        player_id INT NOT NULL,
        kind VARCHAR(32) NOT NULL,
        detail TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_kind (kind),
        KEY idx_player (player_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS player_move_guard (
        player_id INT NOT NULL PRIMARY KEY,
        last_move_ms BIGINT NOT NULL DEFAULT 0,
        last_map_change_ms BIGINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Backfill if the table already existed with the old schema.
    @$conn->query("ALTER TABLE player_move_guard ADD COLUMN last_map_change_ms BIGINT NOT NULL DEFAULT 0");
}

function cheat_log(mysqli $conn, int $player_id, string $kind, string $detail = ''): void {
    $stmt = $conn->prepare('INSERT INTO cheat_log(player_id, kind, detail) VALUES (?,?,?)');
    if (!$stmt) return;
    $stmt->bind_param('iss', $player_id, $kind, $detail);
    @$stmt->execute();
    $stmt->close();
}

function move_guard_get(mysqli $conn, int $player_id): array {
    $stmt = $conn->prepare('SELECT last_move_ms, last_map_change_ms FROM player_move_guard WHERE player_id=? LIMIT 1');
    if (!$stmt) return ['last_move_ms' => 0, 'last_map_change_ms' => 0];
    $stmt->bind_param('i', $player_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return [
        'last_move_ms' => $row ? (int)$row['last_move_ms'] : 0,
        'last_map_change_ms' => $row ? (int)$row['last_map_change_ms'] : 0,
    ];
}

function move_guard_set(mysqli $conn, int $player_id, ?int $lastMoveMs = null, ?int $lastMapChangeMs = null): void {
    $cur = move_guard_get($conn, $player_id);
    $lm = ($lastMoveMs === null) ? (int)$cur['last_move_ms'] : $lastMoveMs;
    $lc = ($lastMapChangeMs === null) ? (int)$cur['last_map_change_ms'] : $lastMapChangeMs;
    $stmt = $conn->prepare('INSERT INTO player_move_guard(player_id, last_move_ms, last_map_change_ms) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_move_ms=VALUES(last_move_ms), last_map_change_ms=VALUES(last_map_change_ms)');
    if (!$stmt) return;
    $stmt->bind_param('iii', $player_id, $lm, $lc);
    @$stmt->execute();
    $stmt->close();
}

function clamp_int(int $v, int $lo, int $hi): int {
    if ($v < $lo) return $lo;
    if ($v > $hi) return $hi;
    return $v;
}

function pret_map_dims(?array $m): array {
    return [
        'w' => (int)($m['width'] ?? 0),
        'h' => (int)($m['height'] ?? 0),
    ];
}

function validate_edge_transition(string $fromMap, int $fromX, int $fromY, string $toMap, int $toX, int $toY): bool {
    $fm = pret_public_load_map($fromMap);
    $tm = pret_public_load_map($toMap);
    if (!$fm || !$tm) return false;

    $fd = pret_map_dims($fm);
    $td = pret_map_dims($tm);
    $fw = $fd['w'];
    $fh = $fd['h'];
    $tw = $td['w'];
    $th = $td['h'];
    if ($fw <= 0 || $fh <= 0 || $tw <= 0 || $th <= 0) return false;

    $conns = $fm['connections'] ?? null;
    if (!is_array($conns)) return false;
    $tol = 2;

    foreach ($conns as $c) {
        if (!is_array($c)) continue;
        if (trim((string)($c['map_id'] ?? '')) !== $toMap) continue;
        $dir = trim((string)($c['direction'] ?? ''));
        $off = (int)($c['offset'] ?? 0);

        if ($dir === 'right') {
            if ($fromX < ($fw - 1 - $tol)) continue;
            if ($toX > $tol) continue;
            $expY = clamp_int($fromY - $off, 0, $th - 1);
            if (abs($toY - $expY) > $tol) continue;
            return true;
        }
        if ($dir === 'left') {
            if ($fromX > $tol) continue;
            if ($toX < ($tw - 1 - $tol)) continue;
            $expY = clamp_int($fromY - $off, 0, $th - 1);
            if (abs($toY - $expY) > $tol) continue;
            return true;
        }
        if ($dir === 'down') {
            if ($fromY < ($fh - 1 - $tol)) continue;
            if ($toY > $tol) continue;
            $expX = clamp_int($fromX - $off, 0, $tw - 1);
            if (abs($toX - $expX) > $tol) continue;
            return true;
        }
        if ($dir === 'up') {
            if ($fromY > $tol) continue;
            if ($toY < ($th - 1 - $tol)) continue;
            $expX = clamp_int($fromX - $off, 0, $tw - 1);
            if (abs($toX - $expX) > $tol) continue;
            return true;
        }
    }
    return false;
}

function validate_warp_transition(string $fromMap, int $fromX, int $fromY, string $toMap, int $toX, int $toY): bool {
    $fm = pret_public_load_map($fromMap);
    if (!$fm) return false;
    $warps = $fm['warps'] ?? null;
    if (!is_array($warps)) return false;

    // Client/server sync around doors is still settling, so allow one-tile source slack.
    $srcTol = 1;
    $dstTol = 3;

    foreach ($warps as $w) {
        if (!is_array($w)) continue;
        $wx = (int)($w['x'] ?? -999);
        $wy = (int)($w['y'] ?? -999);
        if (abs($fromX - $wx) > $srcTol || abs($fromY - $wy) > $srcTol) continue;

        $dm = trim((string)($w['dest_map_id'] ?? ''));
        if ($dm === '' || $dm !== $toMap) continue;

        $dx = array_key_exists('dest_x', $w) ? (int)$w['dest_x'] : null;
        $dy = array_key_exists('dest_y', $w) ? (int)$w['dest_y'] : null;
        if ($dx === null || $dy === null) return true;
        if (abs($toX - $dx) <= $dstTol && abs($toY - $dy) <= $dstTol) return true;
    }
    return false;
}

$stmt = $conn->prepare('SELECT map_id, x, y, dir, updated_at, client_tick FROM player WHERE player_id=? AND account_id=? LIMIT 1');
if (!$stmt) json_out(['ok' => false, 'error' => 'DB_PREPARE_FAIL', 'detail' => $conn->error], 500);
$stmt->bind_param('ii', $player_id, $account_id);
$stmt->execute();
$res = $stmt->get_result();
$cur = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$cur) json_out(['ok' => false, 'error' => 'NO_SUCH_PLAYER'], 404);

$oldMap = (string)$cur['map_id'];
$oldX = (int)$cur['x'];
$oldY = (int)$cur['y'];
$oldDir = (int)($cur['dir'] ?? 0);
$oldTick = (int)$cur['client_tick'];

// 개발 단계: 이동/워프/속도 가드 전부 비활성화.
// 위치 저장은 서버 DB를 그대로 신뢰하고, 인증/DB 오류/OOB만 남긴다.
if ($map_id === $oldMap && $x === $oldX && $y === $oldY && $dir === $oldDir) {
    if ($tick < $oldTick - 5) $tick = $oldTick;
    $stmt = $conn->prepare('UPDATE player SET dir=?, client_tick=? WHERE player_id=? AND account_id=?');
    if (!$stmt) json_out(['ok' => false, 'error' => 'DB_PREPARE_FAIL', 'detail' => $conn->error], 500);
    $stmt->bind_param('iiii', $dir, $tick, $player_id, $account_id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    if (!$ok) json_out(['ok' => false, 'error' => 'DB_EXEC_FAIL', 'detail' => $err], 500);
    json_out(['ok' => true, 'tick' => $tick, 'dt' => 0.0, 'maxStep' => 0, 'dist' => 0, 'mapChanged' => false, 'edgeOk' => false, 'warpOk' => false, 'noop' => true, 'guardDisabled' => true]);
}

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

$dist = abs($x - $oldX) + abs($y - $oldY);
$mapChanged = ($map_id !== $oldMap);
$edgeOk = false;
$warpOk = false;
$dt = 0.0;
$maxStep = 9999;

if ($tick < $oldTick - 5) $tick = $oldTick;

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
    'dt' => $dt,
    'maxStep' => $maxStep,
    'dist' => $dist,
    'mapChanged' => $mapChanged,
    'edgeOk' => $edgeOk,
    'warpOk' => $warpOk,
    'guardDisabled' => true,
]);
