<?php
// api/game/player_items.php
// Player inventory (server authoritative)

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_player();
$player_id = (int)($payload['player_id'] ?? 0);
$account_id = (int)($payload['account_id'] ?? 0);
if ($player_id <= 0 || $account_id <= 0) json_out(['ok'=>false,'error'=>'BAD_AUTH'], 401);

$conn = db();

// Ensure table exists (in case DB wasn't initialized with full_reset.sql)
require_once __DIR__ . '/../lib/item_runtime.php';
item_ensure_tables($conn);

$limit = isset($_GET['limit']) ? max(1, min(5000, (int)$_GET['limit'])) : 2000;

$sql = "SELECT pi.item_id, pi.qty,
               ri.const_name, ri.name, ri.name_ko, ri.price, ri.pocket, ri.description, ri.description_ko
        FROM player_item pi
        LEFT JOIN ref_item ri ON ri.item_id = pi.item_id
        WHERE pi.player_id=?
        ORDER BY pi.item_id ASC
        LIMIT ?";

$stmt = $conn->prepare($sql);
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ii', $player_id, $limit);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
if ($res) {
  while ($r = $res->fetch_assoc()) {
    $items[] = $r;
  }
}
$stmt->close();

json_out(['ok'=>true,'player_id'=>$player_id,'limit'=>$limit,'items'=>$items]);
