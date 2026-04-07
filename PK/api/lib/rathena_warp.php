<?php
/**
 * rAthena-like WARP loader for pokemon_hybrid_web
 *
 * Goal: migrate warp definitions out of decomp "Packege" map.json (warp_events)
 * into editable text scripts under:
 *   /script/map/warp/*.warp
 *
 * NOTE:
 * - We do NOT use "auto" folders or "/npc" fallback folders.
 * - Keep scripts directly editable under /script.
 *
 * Supported line format (tabs/spaces ok, // comments ok):
 *
 *   MapName,x,y,dir    warp    WarpName    w,h,DestMap,DestX,DestY[,DestDir]
 *
 * Example:
 *   PalletTown,6,7,0   warp    toHouse     1,1,PalletTown_PlayersHouse_1F,4,8
 *
 * Fallback (when destination is dynamic / unknown):
 *   MapName,x,y,dir    warp    WarpName    w,h,DestMap,@WARP_ID_DYNAMIC
 *
 * Notes:
 * - dir is kept for consistency but not used by warp trigger.
 * - width/height define a rectangle area (default 1x1).
 */

function rathena_find_warp_file(string $baseDir, string $mapId): ?string {
  $direct = $baseDir . '/' . $mapId . '.warp';
  if (is_file($direct)) return $direct;

  // allow subfolders: script/map/warp/town/PalletTown.warp etc
  if (!is_dir($baseDir)) return null;
  try {
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
      if (!$f->isFile()) continue;
      if (strtolower($f->getExtension()) !== 'warp') continue;
      if ($f->getBasename('.warp') === $mapId) return $f->getPathname();
    }
  } catch (Throwable $e) {
    return null;
  }
  return null;
}

function rathena_load_warps_for_map(string $projectRoot, string $mapId, array $mapConstToFolder = []): array {
  // Preferred structure (2026-02): script/map/warp
  $found = rathena_find_warp_file($projectRoot . '/script/map/warp', $mapId);


  if ($found === null) return [];

  $txt = @file_get_contents($found);
  if ($txt === false) return [];

  $out = [];
  $lines = preg_split("/\r\n|\n|\r/", $txt);
  foreach ($lines as $ln) {
    // strip // comments
    $ln = preg_replace('~//.*$~', '', $ln);
    $ln = trim($ln);
    if ($ln === '') continue;

    // split by whitespace: first token is "map,x,y,dir"
    $parts = preg_split('/\s+/', $ln, 4);
    if (count($parts) < 3) continue;
    $head = $parts[0];
    $kw = strtolower($parts[1]);
    if ($kw !== 'warp') continue;

    $name = $parts[2];
    $rest = $parts[3] ?? '';
    $rest = trim($rest);
    if ($rest === '') continue;

    $h = array_map('trim', explode(',', $head));
    if (count($h) < 3) continue;
    $m = $h[0];
    if ($m !== $mapId) continue; // only this map
    $x = (int)($h[1] ?? 0);
    $y = (int)($h[2] ?? 0);
    $dir = (int)($h[3] ?? 0);

    $a = array_map('trim', explode(',', $rest));
    if (count($a) < 5) continue;
    $w = max(1, (int)$a[0]);
    $hgt = max(1, (int)$a[1]);
    $destMap = (string)$a[2];

    $destMapId = null;
    if ($destMap !== '' && $destMap !== '(NONE)') {
      if (strpos($destMap, 'MAP_') === 0) {
        $destMapId = $mapConstToFolder[$destMap] ?? null;
      } else {
        $destMapId = $destMap;
      }
    }

    $destX = null; $destY = null; $destDir = null;
    $destWarpToken = null;

    // token can be "@something" OR numeric destX,destY
    $t3 = $a[3] ?? '';
    if (strlen($t3) && $t3[0] === '@') {
      $destWarpToken = substr($t3, 1);
    } else {
      $destX = (int)$t3;
      $destY = (int)($a[4] ?? 0);
      if (isset($a[5]) && $a[5] !== '') $destDir = (int)$a[5];
    }

    // expand rectangle area (w x h). Store base + size; client checks rectangle.
    $out[] = [
      'warp_id' => count($out),
      'x' => $x,
      'y' => $y,
      'dir' => $dir,
      'w' => $w,
      'h' => $hgt,
      'dest_map_id' => $destMapId,
      'dest_x' => $destX,
      'dest_y' => $destY,
      'dest_dir' => $destDir,
      'dest_warp_token' => $destWarpToken,
      'name' => $name,
    ];
  }
  return $out;
}
