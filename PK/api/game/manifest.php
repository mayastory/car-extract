<?php
require_once __DIR__ . '/../config.php';

$conn = db();

function table_sig(mysqli $conn, string $table, string $pk): array {
  $sql = "SELECT COUNT(*) AS c, COALESCE(MAX($pk),0) AS m FROM `$table`";
  $res = $conn->query($sql);
  $row = $res ? $res->fetch_assoc() : ['c'=>0,'m'=>0];
  return ['count'=>(int)$row['c'], 'max_id'=>(int)$row['m']];
}

$manifest = [
  'ok' => true,
  'data_version' => 1,
  'endpoints' => [
    'maps' => '/api/game/maps.php',
    'ref_species' => '/api/game/ref_species.php',
    'ref_move' => '/api/game/ref_moves.php',
    'ref_item' => '/api/game/ref_items.php',
    'player_items' => '/api/game/player_items.php',
    'npc_event' => '/api/game/npc_event.php',
  ],
  'realtime' => [
    'ws' => 'ws://{host}:3090',
    'protocol' => 'shared/proto/packets.md',
  ],
  'tables' => [
    'ref_species' => table_sig($conn,'ref_species','species_id'),
    'ref_move'    => table_sig($conn,'ref_move','move_id'),
    'ref_item'    => table_sig($conn,'ref_item','item_id'),
    'ref_ability' => table_sig($conn,'ref_ability','ability_id'),
    'ref_type'    => table_sig($conn,'ref_type','type_id'),
    'ref_nature'  => table_sig($conn,'ref_nature','nature_id'),
  ],
  // Packege is the source of truth; public/pret is a generated cache
  'packege' => [
    'root' => 'Packege',
    'generated_cache' => 'public/pret',
  ],
];

json_out($manifest);
