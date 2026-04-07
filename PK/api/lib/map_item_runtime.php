<?php
// api/lib/map_item_runtime.php
// Load map item placements from rAthena-like script files.
//
// Sources (priority):
//   script/map/item/<Map>.item
//   (NO auto folder; keep scripts editable under script/map/item)
//
// Format (tab-separated, '#' comment lines allowed):
//   Map,x,y,dir<TAB>kind<TAB>ITEM_TOKEN,qty,flag=FLAG_NAME[,underfoot=0][,script=Label]
//
// The generator writes kind values such as:
//   - item_ball
//   - hidden_item
// (others may exist for debug: pokemon_ball, battle_ball, event_ball)

function map_item_project_root(): string {
  // /api/lib -> project root
  return dirname(__DIR__, 2);
}

function map_item_paths(string $mapId): array {
  $root = map_item_project_root();
  $mapId = trim($mapId);
  $mapId = preg_replace('/[^A-Za-z0-9_\-]/', '', $mapId);
  return [
    $root . '/script/map/item/' . $mapId . '.item',
  ];
}

function map_item_load_file(string $path): array {
  if ($path === '' || !file_exists($path)) return [];
  $raw = @file($path, FILE_IGNORE_NEW_LINES);
  if (!is_array($raw)) return [];

  $out = [];
  foreach ($raw as $ln) {
    $ln = trim((string)$ln);
    if ($ln === '' || $ln[0] === '#') continue;

    // split on tabs first; tolerate multiple spaces
    $parts = preg_split('/\t+/', $ln);
    if (!is_array($parts) || count($parts) < 3) {
      // fallback: split by whitespace
      $parts = preg_split('/\s+/', $ln, 3);
    }
    if (!is_array($parts) || count($parts) < 3) continue;

    $pos = trim((string)$parts[0]);
    $kind = trim((string)$parts[1]);
    $arg = trim((string)$parts[2]);

    $posParts = explode(',', $pos);
    if (count($posParts) < 4) continue;
    $map = trim((string)$posParts[0]);
    $x = (int)trim((string)$posParts[1]);
    $y = (int)trim((string)$posParts[2]);
    $dir = (int)trim((string)$posParts[3]);

    // args: ITEM,qty,kv=...
    $argParts = array_map('trim', explode(',', $arg));
    $itemTok = $argParts[0] ?? '';
    $qty = isset($argParts[1]) ? (int)$argParts[1] : 1;

    $kv = [];
    for ($i=2; $i<count($argParts); $i++) {
      $p = $argParts[$i];
      if ($p === '') continue;
      if (strpos($p, '=') !== false) {
        [$k,$v] = explode('=', $p, 2);
        $kv[trim($k)] = trim($v);
      } else {
        $kv[$p] = true;
      }
    }

    $out[] = [
      'map' => $map,
      'x' => $x,
      'y' => $y,
      'dir' => $dir,
      'kind' => $kind,
      'item' => $itemTok,
      'qty' => max(1, (int)$qty),
      'flag' => (string)($kv['flag'] ?? ''),
      'underfoot' => isset($kv['underfoot']) ? (int)$kv['underfoot'] : null,
      'script' => (string)($kv['script'] ?? ''),
      'raw' => $ln,
    ];
  }

  return $out;
}

function map_item_load(string $mapId): array {
  $paths = map_item_paths($mapId);
  foreach ($paths as $p) {
    $rows = map_item_load_file($p);
    if (!empty($rows)) return $rows;
  }
  return [];
}

function map_item_find_at(array $placements, string $mapId, int $x, int $y, ?string $kind=null): ?array {
  foreach ($placements as $p) {
    if (!is_array($p)) continue;
    if ((string)($p['map'] ?? '') !== $mapId) continue;
    if ((int)($p['x'] ?? -999) !== $x) continue;
    if ((int)($p['y'] ?? -999) !== $y) continue;
    if ($kind !== null && $kind !== '' && (string)($p['kind'] ?? '') !== $kind) continue;
    return $p;
  }
  return null;
}
