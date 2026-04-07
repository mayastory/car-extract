<?php
require_once __DIR__ . '/../config.php';

$limit = isset($_GET['limit']) ? max(1, min(5000, (int)$_GET['limit'])) : 5000;
$since = isset($_GET['since_id']) ? max(0, (int)$_GET['since_id']) : 0;

$conn = db();

$stmt = $conn->prepare(
  "SELECT species_id, const_name, name FROM ref_species WHERE species_id > ? ORDER BY species_id ASC LIMIT ?"
);
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ii', $since, $limit);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

json_out(['ok'=>true,'since_id'=>$since,'limit'=>$limit,'species'=>$rows]);
