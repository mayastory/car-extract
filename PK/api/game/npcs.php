<?php
// api/game/npcs.php
// Returns NPC placements for the current map.
// If a valid play token is provided, it filters FR/LG-specific NPCs using the character's GAME_VER.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../util/auth_token.php';
require_once __DIR__ . '/../lib/flag_runtime.php';
require_once __DIR__ . '/_npc_common.php';

$map = trim((string)($_GET['map'] ?? ''));
if ($map === '') json_out(['ok'=>false,'error'=>'NO_MAP'], 400);

$force = ((string)($_GET['refresh'] ?? '')) === '1';

// Determine game_ver (1=FR, 2=LG). Prefer token; fallback to query param if provided.
$game_ver = isset($_GET['game_ver']) ? (int)$_GET['game_ver'] : 0;

$token = auth_get_bearer_token();
$pid = 0;
$conn = null;
if ($token) {
  $pl = verify_token($token);
  if (is_array($pl) && ($pl['t'] ?? '') === 'play') {
    $pid = (int)($pl['player_id'] ?? 0);
    if ($pid > 0) {
      $conn = db();
      $gv = player_flag_get($conn, $pid, 'GAME_VER');
      if ($gv !== 1 && $gv !== 2) {
        $gv = random_int(1, 2);
        player_flag_set($conn, $pid, 'GAME_VER', (int)$gv);
      }
      $game_ver = (int)$gv;
    }
  }
}

$list = npc_list_for_map($map, $force);

// Filter by GAME_VER
if ($game_ver === 1 || $game_ver === 2) {
  $list = array_values(array_filter($list, function($n) use ($game_ver){
    $ov = (int)($n['only_game_ver'] ?? 0);
    return ($ov === 0 || $ov === $game_ver);
  }));
} else {
  // Unknown game_ver: return common only
  $list = array_values(array_filter($list, function($n){
    return ((int)($n['only_game_ver'] ?? 0)) === 0;
  }));
}

// Optional per-player visibility controls embedded in NPC headers:
//   <sprite>,hide_if_flag=FLAG_X,{  => hide NPC when FLAG_X is set
//   <sprite>,show_if_flag=FLAG_X,{  => show NPC only when FLAG_X is set
if ($pid > 0 && $conn instanceof mysqli) {
  $flagCache = [];
  $hasFlag = function(string $flag) use ($conn, $pid, &$flagCache): bool {
    $flag = trim((string)$flag);
    if ($flag === '' || $flag === '0' || $flag === '0x0') return false;
    if (array_key_exists($flag, $flagCache)) return (bool)$flagCache[$flag];
    $v = player_flag_get($conn, $pid, $flag) != 0;
    $flagCache[$flag] = $v;
    return (bool)$v;
  };

  $list = array_values(array_filter($list, function($n) use ($hasFlag){
    $hide = (string)($n['hide_if_flag'] ?? '');
    if ($hide !== '' && $hasFlag($hide)) return false;
    $show = (string)($n['show_if_flag'] ?? '');
    if ($show !== '' && !$hasFlag($show)) return false;
    return true;
  }));
}

json_out([
  'ok' => true,
  'map' => $map,
  'game_ver' => (int)$game_ver,
  'count' => count($list),
  'npcs' => $list,
]);
