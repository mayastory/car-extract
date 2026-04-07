<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/pret_public.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_player();
$player_id = (int)($payload['player_id'] ?? 0);
$account_id = (int)($payload['account_id'] ?? 0);
if ($player_id <= 0 || $account_id <= 0) json_out(['ok'=>false,'error'=>'BAD_AUTH'], 401);

$in = json_in();
require_fields($in, ['map_id','x','y','dir']);

$map_id = trim((string)$in['map_id']);
$x = (int)$in['x'];
$y = (int)$in['y'];
$dir = (int)$in['dir'];
$tick = isset($in['tick']) ? (int)$in['tick'] : 0;

if ($map_id === '') $map_id = 'PalletTown';
if ($dir < 0 || $dir > 3) $dir = 0;

$conn = db();

function ensure_move_guard_tables(mysqli $conn): void {
  // lightweight safety: create on-demand
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
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function move_guard_get_last_move_ms(mysqli $conn, int $player_id): int {
  $stmt = $conn->prepare('SELECT last_move_ms FROM player_move_guard WHERE player_id=? LIMIT 1');
  if (!$stmt) return 0;
  $stmt->bind_param('i', $player_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ? (int)$row['last_move_ms'] : 0;
}

function move_guard_set_last_move_ms(mysqli $conn, int $player_id, int $ms): void {
  $stmt = $conn->prepare('INSERT INTO player_move_guard(player_id,last_move_ms) VALUES (?,?) ON DUPLICATE KEY UPDATE last_move_ms=VALUES(last_move_ms)');
  if (!$stmt) return;
  $stmt->bind_param('ii', $player_id, $ms);
  $stmt->execute();
  $stmt->close();
}

ensure_move_guard_tables($conn);

function cheat_log(mysqli $conn, int $player_id, string $kind, string $detail): void {
  $stmt = $conn->prepare('INSERT INTO cheat_log(player_id, kind, detail) VALUES (?,?,?)');
  if (!$stmt) return;
  $stmt->bind_param('iss', $player_id, $kind, $detail);
  $stmt->execute();
  $stmt->close();
}

function clamp_int(int $v, int $lo, int $hi): int {
  if ($v < $lo) return $lo;
  if ($v > $hi) return $hi;
  return $v;
}

function validate_edge_transition(string $fromMap, int $fromX, int $fromY, string $toMap, int $toX, int $toY): bool {
  $fm = pret_public_load_map($fromMap);
  $tm = pret_public_load_map($toMap);
  if (!$fm || !$tm) return false;

  $fw = (int)($fm['width'] ?? 0);
  $fh = (int)($fm['height'] ?? 0);
  $tw = (int)($tm['width'] ?? 0);
  $th = (int)($tm['height'] ?? 0);
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

  $tol = 2;
  foreach ($warps as $w) {
    if (!is_array($w)) continue;
    if ((int)($w['x'] ?? -999) !== $fromX) continue;
    if ((int)($w['y'] ?? -999) !== $fromY) continue;
    $dm = trim((string)($w['dest_map_id'] ?? ''));
    if ($dm === '' || $dm !== $toMap) continue;
    $dx = isset($w['dest_x']) ? (int)$w['dest_x'] : null;
    $dy = isset($w['dest_y']) ? (int)$w['dest_y'] : null;
    if ($dx === null || $dy === null) return true;
    if (abs($toX - $dx) <= $tol && abs($toY - $dy) <= $tol) return true;
  }
  return false;
}

// Current state
$stmt = $conn->prepare('SELECT map_id, x, y, updated_at, client_tick FROM player WHERE player_id=? AND account_id=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ii', $player_id, $account_id);
$stmt->execute();
$res = $stmt->get_result();
$cur = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$cur) json_out(['ok'=>false,'error'=>'NO_SUCH_PLAYER'], 404);

$oldMap = (string)$cur['map_id'];
$oldX = (int)$cur['x'];
$oldY = (int)$cur['y'];
$oldTick = (int)$cur['client_tick'];
$oldUpdated = (string)$cur['updated_at'];

// Bounds check (uses maps_info if present)
$maxW = null; $maxH = null;
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
    // hard reject out-of-bounds (likely packet tamper)
    cheat_log($conn, $player_id, 'OOB', "map=$map_id x=$x y=$y w=$maxW h=$maxH");
    json_out(['ok'=>false,'error'=>'OOB'], 400);
  }
}

// Movement sanity check (client is not authoritative yet, but we at least stop big warps)
$dist = abs($x - $oldX) + abs($y - $oldY);
$dt = 0.0;
try {
  $t0 = strtotime($oldUpdated);
  if ($t0 !== false) $dt = max(0.0, microtime(true) - $t0);
} catch (Throwable $e) {}

$moveSeconds = 0.2666667; // should match client-ish
$maxStep = 2;
if ($dt > 0) {
  $maxStep = max(2, (int)floor($dt / $moveSeconds) + 1);
  $maxStep = min($maxStep, 12); // hard cap
}

// If map changed, allow only if movement is small or long enough time passed.
$mapChanged = ($map_id !== $oldMap);

$edgeOk = false;
$warpOk = false;
$fastMapChangeOk = false;
if ($mapChanged) {
  $edgeOk = validate_edge_transition($oldMap, $oldX, $oldY, $map_id, $x, $y);
  $warpOk = validate_warp_transition($oldMap, $oldX, $oldY, $map_id, $x, $y);
  $fastMapChangeOk = ($edgeOk || $warpOk);

  if ($dt < 0.5 && !$fastMapChangeOk) {
    // Most common cheat: instant map teleport spam
    cheat_log($conn, $player_id, 'MAP_WARP', "from=$oldMap to=$map_id dt=$dt tick=$tick");
    json_out(['ok'=>false,'error'=>'MAP_WARP_RATE_LIMIT'], 429);
  }

  // If it's a real warp/edge, ignore dist/maxStep (coordinates reset across maps).
  // Otherwise, keep a mild limit even if enough time passed.
  if (!$fastMapChangeOk) {
    if ($dt < 1.5) {
      cheat_log($conn, $player_id, 'MAP_WARP', "from=$oldMap to=$map_id dt=$dt tick=$tick");
      json_out(['ok'=>false,'error'=>'MAP_WARP_RATE_LIMIT'], 429);
    }
    // fallthrough: allow after a long idle (admin/dev tools etc.)
  }
} else {
  // Per-step rate limit (blocks speedhack that still moves 1 tile per packet but too fast)
  $nowMs = (int)floor(microtime(true) * 1000);
  $lastMs = move_guard_get_last_move_ms($conn, $player_id);
  if ($lastMs > 0 && $dist > 0) {
    $baseMs = 267; // ~= 0.2667s per tile (GBA-ish)
    $graceMs = 60;
    $minMs = max(0, $baseMs * $dist - $graceMs);
    if (($nowMs - $lastMs) < $minMs) {
      cheat_log($conn, $player_id, 'SPEED_RATE', "dt_ms=" . ($nowMs - $lastMs) . " min_ms=$minMs dist=$dist map=$map_id");
      json_out(['ok'=>false,'error'=>'SPEED_RATE'], 429);
    }
  }

  if ($dist > $maxStep) {

    cheat_log($conn, $player_id, 'SPEED', "dist=$dist max=$maxStep dt=$dt from=($oldX,$oldY) to=($x,$y) map=$map_id");
    json_out(['ok'=>false,'error'=>'SPEED'], 400);
  }
}

// Tick monotonic (optional): ignore rewind spam
if ($tick < $oldTick - 5) {
  cheat_log($conn, $player_id, 'TICK_REWIND', "old=$oldTick new=$tick");
  // don't fail hard; accept but do not update client_tick backward
  $tick = $oldTick;
}

$stmt = $conn->prepare('UPDATE player SET map_id=?, x=?, y=?, dir=?, updated_at=CURRENT_TIMESTAMP, client_tick=? WHERE player_id=? AND account_id=?');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('siiiiii', $map_id, $x, $y, $dir, $tick, $player_id, $account_id);
$ok = $stmt->execute();
$err = $stmt->error;
$stmt->close();
if (!$ok) json_out(['ok'=>false,'error'=>'DB_EXEC_FAIL','detail'=>$err], 500);

// POST_UPDATE_MOVE_GUARD
// only advance last_move_ms when position actually changed
if (($map_id !== $oldMap) || ($dist > 0)) {
  $nowMs2 = isset($nowMs) ? $nowMs : (int)floor(microtime(true) * 1000);
  move_guard_set_last_move_ms($conn, $player_id, $nowMs2);
}

json_out(['ok'=>true,'tick'=>$tick,'dt'=>$dt,'maxStep'=>$maxStep,'dist'=>$dist,'mapChanged'=>$mapChanged,'edgeOk'=>$edgeOk,'warpOk'=>$warpOk]);
