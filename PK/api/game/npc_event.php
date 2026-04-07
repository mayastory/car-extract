<?php
// api/game/npc_event.php
// Execute an NPC 'script' body for the current player (server-side).
// This enables rAthena-like commands such as getitem/delitem/warp to be authoritative.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/_npc_common.php';
require_once __DIR__ . '/../lib/script_runner.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$payload = auth_require_player();
$player_id = (int)($payload['player_id'] ?? 0);
$account_id = (int)($payload['account_id'] ?? 0);
if ($player_id <= 0 || $account_id <= 0) json_out(['ok'=>false,'error'=>'BAD_AUTH'], 401);

$in = json_in();
$npc_id = isset($in['npc_id']) ? trim((string)$in['npc_id']) : (isset($_GET['npc_id']) ? trim((string)$_GET['npc_id']) : '');
$map_id = isset($in['map_id']) ? trim((string)$in['map_id']) : (isset($_GET['map_id']) ? trim((string)$_GET['map_id']) : '');
$force = isset($in['force']) ? (bool)$in['force'] : (isset($_GET['force']) ? ((int)$_GET['force'] === 1) : false);

$apply_warp = isset($in['apply_warp']) ? (bool)$in['apply_warp'] : (isset($_GET['apply_warp']) ? ((int)$_GET['apply_warp'] === 1) : true);

if ($npc_id === '') json_out(['ok'=>false,'error'=>'MISSING_NPC_ID'], 400);

$idx = npc_scan_all($force);
$npcs = is_array($idx['npcs'] ?? null) ? $idx['npcs'] : [];
$found = null;

foreach ($npcs as $n) {
  if (!is_array($n)) continue;
  if (($n['id'] ?? '') === $npc_id) { $found = $n; break; }
}

if (!$found) json_out(['ok'=>false,'error'=>'NPC_NOT_FOUND','npc_id'=>$npc_id], 404);

// Optional safety: if caller provided map_id, enforce it matches NPC map
if ($map_id !== '' && (string)($found['map'] ?? '') !== $map_id) {
  json_out(['ok'=>false,'error'=>'NPC_MAP_MISMATCH','need'=>$found['map'] ?? null,'got'=>$map_id], 400);
}

if (($found['type'] ?? '') !== 'script') {
  // shop etc: return as-is (client-side shop UI can use items list)
  json_out(['ok'=>true,'npc'=>$found,'note'=>'NPC is not script']);
}

$body = (string)($found['body'] ?? '');
$conn = db();

$run = script_run_body($conn, $player_id, $body, [
  'apply_warp' => $apply_warp,
  'max_steps' => 500,
]);

json_out(['ok'=>true,'npc'=>$found,'run'=>$run]);
