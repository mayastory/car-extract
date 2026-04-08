<?php
// api/rt/map_items.php
// Returns map item placements (item balls / hidden items) filtered by the player's one-time flags.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../util/auth.php';
require_once __DIR__ . '/../lib/map_item_runtime.php';
require_once __DIR__ . '/../lib/flag_runtime.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$mapOverride = isset($_GET['map']) ? trim((string)$_GET['map']) : '';
$debug = isset($_GET['debug']) ? ((int)($_GET['debug'] ?? 0) === 1) : false;

$conn = db();
$player_id = 0;
$account_id = 0;
$map_id = '';
$authBypass = false;

$token = auth_get_bearer_token();
$payload = ($token !== '') ? verify_token($token) : null;
if (is_array($payload) && (string)($payload['t'] ?? '') === 'play') {
  $player_id = (int)($payload['player_id'] ?? 0);
  $account_id = (int)($payload['account_id'] ?? 0);
}
if ($player_id > 0 && $account_id > 0) {
  $stmt = $conn->prepare('SELECT map_id FROM player WHERE player_id=? AND account_id=? LIMIT 1');
  if ($stmt) {
    $stmt->bind_param('ii', $player_id, $account_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) $map_id = (string)($row['map_id'] ?? '');
  }
} else {
  $authBypass = true;
}
if ($mapOverride !== '') $map_id = $mapOverride;
if ($map_id === '') $map_id = 'PalletTown';

$placements = map_item_load($map_id);

$items = [];
foreach ($placements as $p) {
  if (!is_array($p)) continue;

  $kind = (string)($p['kind'] ?? '');
  if ($kind === '') continue;

  $flag = (string)($p['flag'] ?? '');
  if ($player_id > 0 && $flag !== '' && player_flag_has($conn, $player_id, $flag)) {
    continue; // already picked / resolved
  }

  // By default we only expose visible items. Hidden items are returned only in debug mode.
  $visible = ($kind === 'item_ball');
  if (!$debug && !$visible) {
    continue;
  }

  $items[] = [
    'kind' => $kind,
    'x' => (int)($p['x'] ?? 0),
    'y' => (int)($p['y'] ?? 0),
    'dir' => (int)($p['dir'] ?? 0),
    'item' => (string)($p['item'] ?? ''),
    'qty' => (int)($p['qty'] ?? 1),
    'flag' => $flag,
    'script' => (string)($p['script'] ?? ''),
    'visible' => $visible,
  ];
}

json_out([
  'ok' => true,
  'map_id' => $map_id,
  'items' => $items,
  'debug' => $debug,
  'auth_bypass' => $authBypass,
]);
