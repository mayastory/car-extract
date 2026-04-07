<?php
/**
 * rAthena-like CONNECT (map boundary scroll) loader for pokemon_hybrid_web
 *
 * Goal: migrate "connections" out of decomp "Packege" map.json into editable scripts.
 *
 * Preferred structure (2026-02):
 *   /script/map/connect/*.connect
 *
 * NOTE:
 * - We do NOT use "auto" folders or "/npc" fallback folders.
 * - Keep scripts directly editable under /script.
 *
 * Supported line format (tabs/spaces ok, // comments ok):
 *
 *   SrcMap    connect    <up|down|left|right>    DestMap    Offset
 *
 * Example:
 *   PalletTown   connect   up     Route1        0 // src=MAP_ROUTE1
 *
 * Notes:
 * - DestMap can be a map folder id (Route1) OR a MAP_* constant (MAP_ROUTE1).
 * - Offset meaning matches decomp map.json connections.
 */

function rathena_find_connect_file(string $baseDir, string $mapId): ?string {
  $direct = $baseDir . '/' . $mapId . '.connect';
  if (is_file($direct)) return $direct;

  if (!is_dir($baseDir)) return null;
  try {
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
      if (!$f->isFile()) continue;
      if (strtolower($f->getExtension()) !== 'connect') continue;
      if ($f->getBasename('.connect') === $mapId) return $f->getPathname();
    }
  } catch (Throwable $e) {
    return null;
  }
  return null;
}

function rathena_load_connects_for_map(string $projectRoot, string $mapId, array $mapConstToFolder = []): array {
  // Preferred structure (2026-02): script/map/connect
  $found = rathena_find_connect_file($projectRoot . '/script/map/connect', $mapId);

  if ($found === null) return [];

  $txt = @file_get_contents($found);
  if ($txt === false) return [];

  $out = [];
  $lines = preg_split("/\r\n|\n|\r/", $txt);
  foreach ($lines as $ln) {
    $ln = preg_replace('~//.*$~', '', $ln);
    $ln = trim($ln);
    if ($ln === '') continue;

    // 0: SrcMap, 1: connect, 2: direction, 3: DestMap, 4: offset
    $parts = preg_split('/\s+/', $ln);
    if (count($parts) < 5) continue;

    $src = trim($parts[0]);
    if ($src !== $mapId) continue;

    $kw = strtolower(trim($parts[1]));
    if ($kw !== 'connect') continue;

    $dir = strtolower(trim($parts[2]));
    if ($dir !== 'up' && $dir !== 'down' && $dir !== 'left' && $dir !== 'right') continue;

    $destRaw = trim($parts[3]);
    $offset = (int)trim($parts[4]);

    $destId = null;
    $destConst = null;
    if ($destRaw !== '' && $destRaw !== '(NONE)') {
      if (strpos($destRaw, 'MAP_') === 0) {
        $destConst = $destRaw;
        $destId = $mapConstToFolder[$destRaw] ?? null;
      } else {
        $destId = $destRaw;
      }
    }

    $out[] = [
      'direction' => $dir,
      'offset' => $offset,
      'map_id' => $destId,
      'map_const' => $destConst,
    ];
  }

  return $out;
}
