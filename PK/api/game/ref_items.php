<?php
require_once __DIR__ . '/../config.php';

// rAthena-style: server is source of truth. Clients cache these.

$limit = isset($_GET['limit']) ? max(1, min(5000, (int)$_GET['limit'])) : 5000;
$since = isset($_GET['since_id']) ? max(0, (int)$_GET['since_id']) : 0;

$conn = db();

$stmt = $conn->prepare(
  "SELECT item_id, const_name, name, price, pocket, hold_effect, hold_effect_param, description, item_type, field_use_func, battle_usage, battle_use_func, secondary_id, importance, registrability\n" .
  "FROM ref_item WHERE item_id > ? ORDER BY item_id ASC LIMIT ?"
);
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ii', $since, $limit);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

json_out(['ok'=>true,'since_id'=>$since,'limit'=>$limit,'items'=>$rows]);
