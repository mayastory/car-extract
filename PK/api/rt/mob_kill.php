<?php
// Debug / placeholder: mark a mob dead to test respawn.
// NOTE: This does NOT implement battle validation yet.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/mob_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_player();
$player_id = (int)($payload['player_id'] ?? 0);
if ($player_id <= 0) json_out(['ok'=>false,'error'=>'BAD_PLAYER_ID'], 401);

$in = json_in();
$mob_id = isset($in['mob_id']) ? (int)$in['mob_id'] : (isset($_GET['mob_id']) ? (int)$_GET['mob_id'] : 0);
if ($mob_id <= 0) json_out(['ok'=>false,'error'=>'BAD_MOB_ID'], 400);

$conn = db();
$ok = mob_mark_dead_owned($conn, $mob_id, $player_id);
json_out(['ok'=>$ok]);
