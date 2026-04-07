<?php
// api/pret/_common.php
header('Content-Type: application/json; charset=utf-8');

function pret_cfg(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $cfgPath = __DIR__ . '/../../config/pret_paths.php';
  if (!file_exists($cfgPath)) {
    $cfg = [
      'packege_root' => realpath(__DIR__ . '/../../Packege') ?: (__DIR__ . '/../../Packege'),
      'public_pret_root' => realpath(__DIR__ . '/../../public/pret') ?: (__DIR__ . '/../../public/pret'),
      'cache_root' => realpath(__DIR__ . '/../../cache/pret') ?: (__DIR__ . '/../../cache/pret'),
    ];
    return $cfg;
  }
  $cfg = require $cfgPath;
  return $cfg;
}

function jexit(array $obj, int $code=200): void {
  http_response_code($code);
  echo json_encode($obj, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

function safe_id(string $s): string {
  // allow alnum, underscore, dash only
  $s = trim($s);
  $s = preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
  return $s ?? '';
}

function ensure_dir(string $p): bool {
  if (is_dir($p)) return true;
  return @mkdir($p, 0777, true);
}

function read_file_bin(string $p): string {
  $b = @file_get_contents($p);
  if ($b === false) throw new Exception("read fail: $p");
  return $b;
}

function norm_key(string $s): string {
  return strtolower(preg_replace('/[^a-z0-9]/', '', $s));
}

function tileset_folder_map(string $tilesetsRoot): array {
  $map = [];
  if (!is_dir($tilesetsRoot)) return $map;
  $dirs = scandir($tilesetsRoot);
  foreach ($dirs as $d) {
    if ($d==='.'||$d==='..') continue;
    $p = $tilesetsRoot . '/' . $d;
    if (!is_dir($p)) continue;
    $map[norm_key($d)] = $d;
  }
  return $map;
}

function tileset_id_to_folder(string $id, array $folderMap): string {
  // id like gTileset_PalletTown
  $name = preg_replace('/^gTileset_/', '', $id);
  if ($name === null) $name = $id;
  $key = norm_key($name);
  if (isset($folderMap[$key])) return $folderMap[$key];

  // Fallback: CamelCase -> snake
  $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
  $snake = preg_replace('/([A-Z])([A-Z][a-z])/', '$1_$2', $snake);
  $snake = strtolower($snake ?? $name);
  $key2 = norm_key($snake);
  if (isset($folderMap[$key2])) return $folderMap[$key2];

  return $snake;
}

// ---------- GBA palette helpers ----------
function bgr555_to_rgba(int $c): array {
  // 0bbbbbg ggggrrrr (little endian 15-bit)
  $r = ($c & 0x1F);
  $g = ($c >> 5) & 0x1F;
  $b = ($c >> 10) & 0x1F;
  // scale 0..31 -> 0..255
  $r = (int)round($r * 255 / 31);
  $g = (int)round($g * 255 / 31);
  $b = (int)round($b * 255 / 31);
  return [$r,$g,$b,255];
}

function load_pal(string $path): array {
    $bin = read_file_bin($path);

    // 1) JASC-PAL (text) format: commonly used by tools/exporters
    //    Lines: JASC-PAL \n 0100 \n <count> \n R G B ...
    $head = substr($bin, 0, 8);
    if ($head === "JASC-PAL") {
        $txt = preg_replace("/\r\n?/", "\n", $bin);
        $lines = array_values(array_filter(explode("\n", $txt), fn($l) => trim($l) !== ""));
        // basic validation
        if (count($lines) >= 4 && trim($lines[0]) === "JASC-PAL") {
            $count = intval(trim($lines[2]));
            $colors = [];
            for ($i = 0; $i < $count && (3 + $i) < count($lines); $i++) {
                $parts = preg_split("/\s+/", trim($lines[3 + $i]));
                if (count($parts) < 3) continue;
                $r = max(0, min(255, intval($parts[0])));
                $g = max(0, min(255, intval($parts[1])));
                $b = max(0, min(255, intval($parts[2])));
                $colors[] = [$r, $g, $b, 255];
            }
            // ensure at least 16 colors
            while (count($colors) < 16) $colors[] = [0,0,0,255];
            return array_slice($colors, 0, 16);
        }
        // fallthrough to binary if malformed
    }

    // 2) Binary GBA BGR555 (little-endian), 16 colors = 32 bytes
    //    Some packs store raw palettes this way.
    $colors = [];
    $n = intdiv(strlen($bin), 2);
    $n = min($n, 16);
    for ($i = 0; $i < $n; $i++) {
        $lo = ord($bin[$i * 2]);
        $hi = ord($bin[$i * 2 + 1]);
        $v  = ($hi << 8) | $lo;
        $r5 = ($v >> 0)  & 0x1F;
        $g5 = ($v >> 5)  & 0x1F;
        $b5 = ($v >> 10) & 0x1F;
        $r  = intdiv($r5 * 255, 31);
        $g  = intdiv($g5 * 255, 31);
        $b  = intdiv($b5 * 255, 31);
        $colors[] = [$r, $g, $b, 255];
    }
    while (count($colors) < 16) $colors[] = [0,0,0,255];
    return $colors;
}


// Convenience alias used by some endpoints.
function die_json($obj, int $code=200): void {
  if (is_array($obj)) {
    jexit($obj, $code);
  }
  jexit(['ok'=>0,'err'=>'EX','detail'=>strval($obj)], $code);
}

// Load a decoded palette bank from a tileset palette directory.
// Packege exports usually store palettes as:
//   palettes/00.pal .. palettes/15.pal
// Returns a 16-color palette as [[r,g,b,a], ...] or null if missing.
function load_pal_dec_dir(string $palDir, int $bank): ?array {
  $bank = max(0, min(255, $bank));
  // common: 00.pal, 01.pal ...
  $cand = [
    $palDir . '/' . sprintf('%02d.pal', $bank),
    $palDir . '/' . sprintf('%d.pal', $bank),
    $palDir . '/palette_' . sprintf('%02d.pal', $bank),
    $palDir . '/pal_' . sprintf('%02d.pal', $bank),
  ];
  foreach ($cand as $path) {
    if (is_file($path)) {
      try { return load_pal($path); } catch (Exception $e) { return null; }
    }
  }
  // fallback: try glob match (rare naming differences)
  $g = glob($palDir . '/*' . sprintf('%02d', $bank) . '*.pal');
  if ($g && is_file($g[0])) {
    try { return load_pal($g[0]); } catch (Exception $e) { return null; }
  }
  return null;
}

// Normalize palette into RGBA tuples.
// Accepts:
//  - [[r,g,b,a], ...]
//  - [u16,u16,...] (BGR555)
function palette_to_rgba($pal): array {
  if (!is_array($pal)) return array_fill(0, 16, [0,0,0,255]);

  // already RGBA tuples?
  if (isset($pal[0]) && is_array($pal[0]) && count($pal[0]) >= 3) {
    $out = [];
    foreach ($pal as $c) {
      $r = (int)($c[0] ?? 0);
      $g = (int)($c[1] ?? 0);
      $b = (int)($c[2] ?? 0);
      $a = (int)($c[3] ?? 255);
      $out[] = [$r,$g,$b,$a];
      if (count($out) >= 16) break;
    }
    while (count($out) < 16) $out[] = [0,0,0,255];
    return $out;
  }

  // list of ints (BGR555)
  $out = [];
  foreach ($pal as $v) {
    if (!is_int($v) && !ctype_digit(strval($v))) continue;
    $out[] = bgr555_to_rgba((int)$v);
    if (count($out) >= 16) break;
  }
  while (count($out) < 16) $out[] = [0,0,0,255];
  return $out;
}



function load_png_indexed(string $path) {
  $img = @imagecreatefrompng($path);
  if (!$img) throw new Exception("png load fail: $path");
  return $img; // may be palette
}

function tile_px_index($img, int $x, int $y): int {
  // tiles.png dump formats vary by extractor:
  // 1) Indexed PNG (palette): imagecolorat() returns palette index (0..255)
  // 2) Truecolor grayscale PNG: each pixel's gray level encodes the original 4bpp index.
  //
  // Our renderer needs the **4bpp index** (0..15). For indexed PNG we take the palette
  // index. For truecolor we derive the index from the grayscale value.

  $v = imagecolorat($img, $x, $y);

  if (function_exists('imageistruecolor') && imageistruecolor($img)) {
    // Truecolor PNG: imagecolorat() returns 0xRRGGBB (plus alpha bits).
    // Many dumped tiles.png are 16-step grayscale placeholders where the *shade* encodes the index.
    $r = ($v >> 16) & 0xFF;
    $g = ($v >> 8) & 0xFF;
    $b = ($v) & 0xFF;
    if ($r === $g && $g === $b) {
      // Map 255..0 (white..black) to 0..15 (index0..15).
      $idx = (int) round((255 - $r) / 17);
      if ($idx < 0) $idx = 0;
      if ($idx > 15) $idx = 15;
      return $idx;
    }
    return 0;
  }

  // Indexed/palette image: $v is already an index.
  return $v & 0xFF;
}

// Render a single 8x8 4bpp tile from tiles.png using a 16-color palette.
// If $transparentZero is true, palette index 0 is treated as fully transparent.
function render_tile8($srcImg, int $tileId, array $paletteRGBA, bool $hflip, bool $vflip, bool $transparentZero=false) {
  $cols = 16; // tiles.png uses 16 tiles per row (8px each) => 128px width
  $sx = ($tileId % $cols) * 8;
  $sy = intdiv($tileId, $cols) * 8;

  $dst = imagecreatetruecolor(8,8);
  imagesavealpha($dst, true);
  $transparent = imagecolorallocatealpha($dst, 0,0,0,127);
  imagefill($dst,0,0,$transparent);

  // allocate 16 colors
  $colCache = [];
  for ($i=0; $i<16; $i++){
    [$r,$g,$b,$a] = $paletteRGBA[$i];
    // alpha in GD: 0 opaque, 127 transparent
    $ga = 0;
    // If requested, make index 0 transparent.
    if ($transparentZero && $i === 0) $ga = 127;
    $colCache[$i] = imagecolorallocatealpha($dst, $r,$g,$b,$ga);
  }

  for($y=0;$y<8;$y++){
    for($x=0;$x<8;$x++){
      $ix = tile_px_index($srcImg, $sx+$x, $sy+$y);
      $dx = $hflip ? (7-$x) : $x;
      $dy = $vflip ? (7-$y) : $y;
      imagesetpixel($dst, $dx, $dy, $colCache[$ix & 0xF]);
    }
  }
  return $dst;
}

function metatile_entries(string $metatilesBin, int $index): array {
  // FRLG/Pret exports use 8 u16 per metatile (16 bytes):
  //  - first 4: bottom layer 2x2 tiles
  //  - next 4:  top layer 2x2 tiles (index 0 is transparent)
  $off = $index * 16;
  if ($off+16 > strlen($metatilesBin)) return array_fill(0, 8, 0);
  $b = substr($metatilesBin, $off, 16);
  $u = [];
  for($i=0;$i<8;$i++){
    $u16 = ord($b[$i*2]) | (ord($b[$i*2+1])<<8);
    $u[] = $u16;
  }
  return $u;
}
