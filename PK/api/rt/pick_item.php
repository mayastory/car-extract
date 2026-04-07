<?php
// api/rt/pick_item.php
// Pick up an item ball / hidden item from script/map/item.
// One-time behavior: uses player_flag so the same item cannot be taken twice.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/map_item_runtime.php';
require_once __DIR__ . '/../lib/flag_runtime.php';
require_once __DIR__ . '/../lib/item_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_player();
$player_id = (int)($payload['player_id'] ?? 0);
$account_id = (int)($payload['account_id'] ?? 0);
if ($player_id <= 0 || $account_id <= 0) json_out(['ok'=>false,'error'=>'BAD_AUTH'], 401);

$in = json_in();
$mode = isset($in['mode']) ? trim((string)$in['mode']) : (isset($_GET['mode']) ? trim((string)$_GET['mode']) : 'front');
$wantKind = isset($in['kind']) ? trim((string)$in['kind']) : (isset($_GET['kind']) ? trim((string)$_GET['kind']) : '');

$conn = db();

// Player state
$stmt = $conn->prepare('SELECT map_id, x, y, dir FROM player WHERE player_id=? AND account_id=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ii', $player_id, $account_id);
$stmt->execute();
$res = $stmt->get_result();
$st = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$st) json_out(['ok'=>false,'error'=>'NO_SUCH_PLAYER'], 404);

$map_id = (string)($st['map_id'] ?? 'PalletTown');
$px = (int)($st['x'] ?? 0);
$py = (int)($st['y'] ?? 0);
$dir = (int)($st['dir'] ?? 0);

$dx = 0; $dy = 0;
// dir mapping matches client: 0=down,1=up,2=left,3=right
if ($dir === 0) { $dy = 1; }
else if ($dir === 1) { $dy = -1; }
else if ($dir === 2) { $dx = -1; }
else if ($dir === 3) { $dx = 1; }

$tx = $px; $ty = $py;
if ($mode === 'front') {
  $tx = $px + $dx;
  $ty = $py + $dy;
} else if ($mode === 'underfoot') {
  $tx = $px; $ty = $py;
}

$placements = map_item_load($map_id);
if (empty($placements)) json_out(['ok'=>false,'error'=>'NO_ITEM_SCRIPT','map_id'=>$map_id], 404);

// Locate placement.
$p = null;
if ($wantKind !== '') {
  $p = map_item_find_at($placements, $map_id, $tx, $ty, $wantKind);
} else {
  // Prefer visible ball first, then hidden item.
  $p = map_item_find_at($placements, $map_id, $tx, $ty, 'item_ball');
  if (!$p) $p = map_item_find_at($placements, $map_id, $tx, $ty, 'hidden_item');
}

if (!$p) {
  json_out(['ok'=>false,'error'=>'NO_ITEM_AT','map_id'=>$map_id,'x'=>$tx,'y'=>$ty,'mode'=>$mode,'kind'=>$wantKind], 404);
}

$kind = (string)($p['kind'] ?? '');
$itemTok = (string)($p['item'] ?? '');
$qty = (int)($p['qty'] ?? 1);
$flag = (string)($p['flag'] ?? '');

if ($itemTok === '' || $qty <= 0) {
  json_out(['ok'=>false,'error'=>'BAD_ITEM_DEF','detail'=>$p], 500);
}

// If this placement has no flag (shouldn't happen), derive a stable one.
if ($flag === '') {
  $flag = 'FLAG_ITEM_' . strtoupper($map_id) . '_' . $tx . '_' . $ty . '_' . strtoupper($kind);
}

try {
  flag_ensure_tables($conn);
  item_ensure_tables($conn);

  $conn->begin_transaction();

  // Lock flag row
  $cur = 0;
  $stmt = $conn->prepare('SELECT value FROM player_flag WHERE player_id=? AND flag=? FOR UPDATE');
  if (!$stmt) { $conn->rollback(); json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500); }
  $nf = flag_normalize($flag);
  $stmt->bind_param('is', $player_id, $nf);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if ($row) $cur = (int)($row['value'] ?? 1);

  if ($cur != 0) {
    $conn->rollback();
    json_out(['ok'=>false,'error'=>'ALREADY_PICKED','flag'=>$nf,'map_id'=>$map_id,'x'=>$tx,'y'=>$ty,'kind'=>$kind], 409);
  }

  $stmt = $conn->prepare('INSERT INTO player_flag(player_id, flag, value) VALUES (?,?,1)');
  if (!$stmt) { $conn->rollback(); json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500); }
  $stmt->bind_param('is', $player_id, $nf);
  $ok = $stmt->execute();
  $err = $stmt->error;
  $stmt->close();
  if (!$ok) { $conn->rollback(); json_out(['ok'=>false,'error'=>'DB_EXEC_FAIL','detail'=>$err], 500); }

  $r = player_item_add($conn, $player_id, $itemTok, max(1,$qty));
  if (!($r['ok'] ?? false)) {
    $conn->rollback();
    json_out(['ok'=>false,'error'=>'ITEM_ADD_FAIL','detail'=>$r], 500);
  }

  $conn->commit();

  json_out([
    'ok' => true,
    'picked' => [
      'kind' => $kind,
      'map_id' => $map_id,
      'x' => $tx,
      'y' => $ty,
      'item' => $itemTok,
      'qty' => max(1,$qty),
      'flag' => $nf,
    ],
    'inventory' => $r,
  ]);

} catch (Throwable $e) {
  @ $conn->rollback();
  json_out(['ok'=>false,'error'=>'EXCEPTION','detail'=>$e->getMessage()], 500);
}
