<?php
require __DIR__ . '/_common.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/rathena_warp.php';
require_once __DIR__ . '/../lib/rathena_connect.php';

/**
 * IMPORTANT: API must always return valid JSON.
 * - Disable display_errors so notices/warnings do not corrupt JSON.
 * - Convert warnings/notices into exceptions so we can jexit() cleanly.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Build a MAP_* constant -> folder name map by scanning Packege/data/maps/*/map.json
// Used to resolve map connections (e.g. MAP_ROUTE1 -> Route1).
function pret_map_const_to_folder(string $packMapsDir): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  $cache = [];
  foreach (glob($packMapsDir . '/*', GLOB_ONLYDIR) as $dir) {
    $p = $dir . '/map.json';
    if (!file_exists($p)) continue;
    $o = json_decode(file_get_contents($p), true);
    if (!is_array($o)) continue;
    $id = $o['id'] ?? '';
    if ($id !== '') {
      $cache[$id] = basename($dir);
    }
  }
  return $cache;
}

if (!function_exists('imagecreatefrompng')) {
  jexit(['ok'=>0,'err'=>'NO_GD','detail'=>'PHP GD extension is not enabled (imagecreatefrompng missing). Enable GD in php.ini (extension=gd) and restart Apache.'], 500);
}

try {
  $cfg = pret_cfg();
  $pack = $cfg['packege_root'];
  $pubPret = $cfg['public_pret_root'];

  
  // bump to force tileset regen when generator changes
  // Cache-buster for generated PNG/JSON. Bump when renderer logic changes.
  $GEN_VER = 'r16_split_upper';
$mapId = safe_id($_GET['map'] ?? '');
  if ($mapId==='') jexit(['ok'=>0,'err'=>'NO_MAP'], 400);

  $mapJsonPath = $pack . '/data/maps/' . $mapId . '/map.json';
  if (!file_exists($mapJsonPath)) jexit(['ok'=>0,'err'=>'MAP_NOT_FOUND','map'=>$mapId], 404);

  // read map.json
  $mapObj = json_decode(file_get_contents($mapJsonPath), true);
  if (!is_array($mapObj)) throw new Exception("map.json parse fail: $mapId");

  $layoutId = $mapObj['layout'] ?? null;
  if (!$layoutId) throw new Exception("map.json missing layout: $mapId");

  // load layouts index
  $layoutsPath = $pack . '/data/layouts/layouts.json';
  if (!file_exists($layoutsPath)) throw new Exception("layouts.json not found");
  $layoutsObj = json_decode(file_get_contents($layoutsPath), true);
  if (!is_array($layoutsObj) || !isset($layoutsObj['layouts'])) throw new Exception("layouts.json parse fail");

  $layout = null;
  foreach ($layoutsObj['layouts'] as $it){
    if (($it['id'] ?? '') === $layoutId) { $layout = $it; break; }
  }
  if (!$layout) throw new Exception("layout not found: $layoutId");

  $width = (int)($layout['width'] ?? 0);
  $height = (int)($layout['height'] ?? 0);
  $borderW = (int)($layout['border_width'] ?? 0);
  $borderH = (int)($layout['border_height'] ?? 0);
  if ($width<=0 || $height<=0) throw new Exception("invalid layout size: $layoutId");

  $primaryId = $layout['primary_tileset'] ?? '';
  $secondaryId = $layout['secondary_tileset'] ?? '';
  if (!$primaryId || !$secondaryId) throw new Exception("missing tileset id");

  $primaryRoot = $pack . '/data/tilesets/primary';
  $secondaryRoot = $pack . '/data/tilesets/secondary';
  $pMap = tileset_folder_map($primaryRoot);
  $sMap = tileset_folder_map($secondaryRoot);

  $pFolder = tileset_id_to_folder($primaryId, $pMap);
  $sFolder = tileset_id_to_folder($secondaryId, $sMap);

  $pDir = $primaryRoot . '/' . $pFolder;
  $sDir = $secondaryRoot . '/' . $sFolder;

  if (!is_dir($pDir)) throw new Exception("primary tileset dir not found: $pDir");
  if (!is_dir($sDir)) throw new Exception("secondary tileset dir not found: $sDir");

  $blockPath = $pack . '/' . ($layout['blockdata_filepath'] ?? '');
  $borderPath = $pack . '/' . ($layout['border_filepath'] ?? '');
  if (!file_exists($blockPath)) throw new Exception("blockdata not found: $blockPath");
  if (!file_exists($borderPath)) throw new Exception("border not found: $borderPath");

  $blockBin = read_file_bin($blockPath);
  $borderBin = read_file_bin($borderPath);

  // Parse blockdata (u16 le)
  // FRLG: bits 0..9 = metatile id, 10..11 = collision, 12..15 = elevation.
  $tiles = [];
  $collisions = [];
  $elevations = [];
  $uniq = [];
  $total = $width*$height;
  for ($i=0; $i<$total; $i++){
    $u16 = ord($blockBin[$i*2]) | (ord($blockBin[$i*2+1])<<8);
    $id = ($u16 & 0x03FF);
    $tiles[] = $id;
    $collisions[] = (($u16 & 0x0C00) >> 10);
    $elevations[] = (($u16 & 0xF000) >> 12);
    $uniq[$id] = 1;
  }
  // include border metatiles
  $bCount = intdiv(strlen($borderBin), 2);
  for ($i=0; $i<$bCount; $i++){
    $u16 = ord($borderBin[$i*2]) | (ord($borderBin[$i*2+1])<<8);
    $id = ($u16 & 0x03FF);
    $uniq[$id] = 1;
  }
  $used = array_keys($uniq);
  sort($used);

  // build remap
  $remap = [];
  for ($i=0; $i<count($used); $i++) $remap[$used[$i]] = $i;

  // ensure output dirs
  ensure_dir($pubPret);
  ensure_dir($pubPret . '/maps');
  ensure_dir($pubPret . '/tilesets');

  // map json output path
  $mapOutFile = $pubPret . '/maps/' . $mapId . '.json';

  $tilesetKeyBase = $pFolder . '__' . $sFolder . '__' . $mapId . '__' . $GEN_VER;
  $tilesetFile0 = $pubPret . '/tilesets/' . $tilesetKeyBase . '.png';

  
$tilesetUpperFile0 = $pubPret . '/tilesets/' . $tilesetKeyBase . '__upper.png';
  // tileset animation (FRLG fieldmap tile animations) - only for primary 'general'
  $animEnabled = ($pFolder === 'general');
  $animFrameCount = $animEnabled ? 8 : 0;

  // build list of tileset frame files (frame0 uses legacy name without suffix)
  $tilesetFrameFiles = [];
  $tilesetUpperFrameFiles = [];
  if ($animFrameCount > 0) {
    for ($f=0; $f<$animFrameCount; $f++){
      $tilesetFrameFiles[] = ($f===0)
        ? $tilesetFile0
        : ($pubPret . '/tilesets/' . $tilesetKeyBase . '__f' . $f . '.png');
    }
  } else {
    $tilesetFrameFiles[] = $tilesetFile0;
  }

  // generate tileset sheet(s) (cached on disk)
  $needGen = false;
  foreach($tilesetFrameFiles as $fp){
    if (!file_exists($fp)) { $needGen = true; break; }
  }

  if ($needGen) {
      // Tileset sources (derived from folders; avoids external tables)
  $pTilesPng = $pDir . '/tiles.png';
  $sTilesPng = $sDir . '/tiles.png';
  $pMetaBin  = $pDir . '/metatiles.bin';
  $sMetaBin  = $sDir . '/metatiles.bin';
  $pPalDir   = $pDir . '/palettes';
  $sPalDir   = $sDir . '/palettes';

  if (!is_file($pTilesPng) || !is_file($sTilesPng) || !is_file($pMetaBin) || !is_file($sMetaBin)) {
    die_json(['ok'=>0,'err'=>'EX','detail'=>'tileset file missing: ' . $pTilesPng . ' / ' . $sTilesPng . ' / ' . $pMetaBin . ' / ' . $sMetaBin]);
  }

  $pImg = load_png_indexed($pTilesPng);
  $sImg = load_png_indexed($sTilesPng);
  if (!$pImg || !$sImg) {
    die_json(['ok'=>0,'err'=>'EX','detail'=>'png load fail: ' . ($pImg ? '' : $pTilesPng) . ' ' . ($sImg ? '' : $sTilesPng)]);
  }

  $pTileCount = (int)(intdiv(imagesx($pImg), 8) * intdiv(imagesy($pImg), 8));
  $sTileCount = (int)(intdiv(imagesx($sImg), 8) * intdiv(imagesy($sImg), 8));

  $pMeta = file_get_contents($pMetaBin);
  $sMeta = file_get_contents($sMetaBin);
  if ($pMeta === false || $sMeta === false) {
    die_json(['ok'=>0,'err'=>'EX','detail'=>'metatiles.bin load fail: ' . $pMetaBin . ' / ' . $sMetaBin]);
  }

  $pMetaCount = intdiv(strlen($pMeta), 16); // each metatile: 16 bytes (8 u16 tile entries)
  $sMetaCount = intdiv(strlen($sMeta), 16);
// include metatiles from layout AND border (so borders won't fall back to 0)
    $usedSet = [];
    foreach($tiles as $mtId){ $usedSet[$mtId] = true; }
    $borderTotal = (int)$borderW * (int)$borderH;
    for ($i=0; $i<$borderTotal; $i++){
      $j = $i*2;
      if ($j+1 >= strlen($borderBin)) continue;
      $u16 = ord($borderBin[$j]) | (ord($borderBin[$j+1])<<8);
      $id = ($u16 & 0x03FF);
      $usedSet[$id] = true;
    }

    $used = array_map('intval', array_keys($usedSet));
    sort($used);
    $remap = [];
    foreach($used as $i=>$mtId){ $remap[$mtId] = $i; }

    // tile cache (per frame)
    $tileCache = [];
    $getTile = function($srcImg, int $tileId, array $pal, bool $hflip, bool $vflip, string $setKey, bool $transparentZero) use (&$tileCache){
      $palKey = spl_object_id((object)$pal);
      $k = $setKey.'-'.$tileId.'-'.$hflip.'-'.$vflip.'-'.$transparentZero.'-'.$palKey;
      if(isset($tileCache[$k])) return $tileCache[$k];
      $tile = render_tile8($srcImg, $tileId, $pal, $hflip, $vflip, $transparentZero);
      $tileCache[$k] = $tile;
      return $tile;
    };

    // anim sources (indexed PNGs); pack them into 16-col sheet so render_tile8 can use them
    $packAnim = function($srcImg, int $tileCount){
      $colsIn = max(1, (int)(imagesx($srcImg)/8));
      $outCols = 16;
      $outRows = (int)ceil($tileCount / $outCols);
      $out = imagecreate($outCols*8, $outRows*8); // palette image
      imagepalettecopy($out, $srcImg);
      imagefill($out, 0, 0, 0);
      for($t=0;$t<$tileCount;$t++){
        $sx = ($t % $colsIn) * 8;
        $sy = intdiv($t, $colsIn) * 8;
        $dx = ($t % $outCols) * 8;
        $dy = intdiv($t, $outCols) * 8;
        imagecopy($out, $srcImg, $dx, $dy, $sx, $sy, 8, 8);
      }
      return $out;
    };

    // FRLG tile animation ranges (8x8 tile indices in PRIMARY tileset)
    $WATER_START = 416; $WATER_COUNT = 48; // 416..463
    $SAND_START  = 464; $SAND_COUNT  = 18; // 464..481
    $FLOWER_START= 508; $FLOWER_COUNT= 4;  // 508..511
    $animBase = $pack . '/data/tilesets/primary/general/anim';
    $hasAnim = $animEnabled && is_dir($animBase);

    // generate missing frames
    foreach($tilesetFrameFiles as $frame => $tilesetFile){
      if (file_exists($tilesetFile)) continue;

      // clear caches per frame (because anim tiles change)
      $tileCache = [];
      $metaCache = [];

      // load frame-specific anim sheets (packed to 16 cols)
      $waterPacked = null; $sandPacked = null; $flowerPacked = null;
      if ($hasAnim){
        $wf = $frame % 8;
        $sf = $frame % 8;
        $ff = $frame % 5;
        $wPath = $animBase . '/water_current_landwatersedge/' . $wf . '.png';
        $sPath = $animBase . '/sandwatersedge/' . $sf . '.png';
        $fPath = $animBase . '/flower/' . $ff . '.png';

        if (file_exists($wPath)) { $wImg = load_png_indexed($wPath); $waterPacked = $packAnim($wImg, $WATER_COUNT); imagedestroy($wImg); }
        if (file_exists($sPath)) { $sImgA = load_png_indexed($sPath); $sandPacked  = $packAnim($sImgA, $SAND_COUNT); imagedestroy($sImgA); }
        if (file_exists($fPath)) { $fImgA = load_png_indexed($fPath); $flowerPacked= $packAnim($fImgA, $FLOWER_COUNT); imagedestroy($fImgA); }
      }

      
      // Blit an 8x8 tile into the destination.
      // For top-layer tiles we render palette index 0 as fully transparent (see $transparentZero),
      // but a plain imagecopy would overwrite the bottom layer with transparent pixels.
      // This per-pixel blit skips fully transparent pixels so the bottom layer shows through (prevents "black blocks").
      $blitTile = function($dst, $src, int $dx, int $dy, bool $skipTransparentZero): void {
        for ($y=0; $y<8; $y++){
          for ($x=0; $x<8; $x++){
            $cRaw = imagecolorat($src, $x, $y);
            if ($skipTransparentZero){
              $cu = $cRaw;
              if ($cu < 0) $cu = $cu + 0x100000000;
              $a = ($cu >> 24) & 0x7F; // GD alpha: 0 opaque .. 127 transparent
              if ($a >= 127) continue;
            }
            imagesetpixel($dst, $dx+$x, $dy+$y, $cRaw);
          }
        }
      };

$getMetatile = function(int $mtId) use (&$metaCache, $blitTile, $pMeta, $sMeta, $pMetaCount, $pTileCount, $getTile, $pImg, $sImg, $pPalDir, $sPalDir,
        $hasAnim, $waterPacked, $sandPacked, $flowerPacked, $WATER_START, $WATER_COUNT, $SAND_START, $SAND_COUNT, $FLOWER_START, $FLOWER_COUNT, $frame
      ){
        if(isset($metaCache[$mtId])) return $metaCache[$mtId];

        // choose primary/secondary metatile data
        $isSecMeta = ($mtId >= $pMetaCount);
        $metaBin = $isSecMeta ? $sMeta : $pMeta;
        $metaLocal = $isSecMeta ? ($mtId - $pMetaCount) : $mtId;
        $off = $metaLocal * 16;
        if ($off+16 > strlen($metaBin)) {
          $empty = imagecreatetruecolor(16,16);
          imagesavealpha($empty,true);
          imagealphablending($empty,false);
          $t = imagecolorallocatealpha($empty,0,0,0,127);
          imagefill($empty,0,0,$t);
          return $metaCache[$mtId] = $empty;
        }

        $metaEntries = metatile_entries($metaBin, $metaLocal);
        $mtImg = imagecreatetruecolor(16,16);
        imagesavealpha($mtImg,true);
        imagealphablending($mtImg,false);
        $transparent = imagecolorallocatealpha($mtImg,0,0,0,127);
        imagefill($mtImg,0,0,$transparent);

        for($q=0;$q<8;$q++){
          $e = $metaEntries[$q];
          $tileIdRaw = ($e & 0x03FF);
          $h = (($e >> 10) & 1) ? true : false;
          $v = (($e >> 11) & 1) ? true : false;
          $bank = (($e >> 12) & 0x0F);

          // Upper layer uses tileId=0 as 'no tile' (do not draw).
          if ($q >= 4 && $tileIdRaw === 0) { continue; }

          $tileIsSec = ($tileIdRaw >= $pTileCount);
          $tileLocal = $tileIsSec ? ($tileIdRaw - $pTileCount) : $tileIdRaw;

          $srcImg2 = $tileIsSec ? $sImg : $pImg;
          $pal = $tileIsSec ? load_pal_dec_dir($sPalDir, $bank) : load_pal_dec_dir($pPalDir, $bank);
          if (!$pal) { $pal = $tileIsSec ? load_pal_dec_dir($sPalDir, 0) : load_pal_dec_dir($pPalDir, 0); }
          $palRGBA = palette_to_rgba($pal);

          $setKey = $tileIsSec ? 'S' : 'P';

          // apply tile animation substitutions (primary only)
          if ($hasAnim && !$tileIsSec) {
            if ($waterPacked && $tileLocal >= $WATER_START && $tileLocal < ($WATER_START+$WATER_COUNT)) {
              $srcImg2 = $waterPacked; $tileLocal = $tileLocal - $WATER_START; $setKey = 'W'.$frame;
            } else if ($sandPacked && $tileLocal >= $SAND_START && $tileLocal < ($SAND_START+$SAND_COUNT)) {
              $srcImg2 = $sandPacked; $tileLocal = $tileLocal - $SAND_START; $setKey = 'A'.$frame;
            } else if ($flowerPacked && $tileLocal >= $FLOWER_START && $tileLocal < ($FLOWER_START+$FLOWER_COUNT)) {
              $srcImg2 = $flowerPacked; $tileLocal = $tileLocal - $FLOWER_START; $setKey = 'F'.$frame;
            }
          }

          $transparentZero = ($q >= 4); // upper layer: treat index 0 as transparent
          $tile = $getTile($srcImg2, $tileLocal, $palRGBA, $h, $v, $setKey, $transparentZero);

          $dx = ($q % 2) * 8;
          $dy = intdiv($q, 2) * 8;
          // r16: upper layer drawn without y-shift
          $blitTile($mtImg, $tile, $dx, $dy, $transparentZero);
          }

        $metaCache[$mtId] = $mtImg;
        return $mtImg;
      };

      // build tileset sheet of used metatiles
      $cols = 16;
      $rows = (int)ceil(count($used) / $cols);
      $sheet = imagecreatetruecolor($cols*16, $rows*16);
      imagesavealpha($sheet,true);
      imagealphablending($sheet,false);
      $transparent = imagecolorallocatealpha($sheet,0,0,0,127);
      imagefill($sheet,0,0,$transparent);

      foreach($used as $i=>$mtId){
        $mtImg = $getMetatile($mtId);
        $sx = ($i % $cols) * 16;
        $sy = intdiv($i, $cols) * 16;
        imagecopy($sheet, $mtImg, $sx, $sy, 0,0, 16,16);
      }

      imagepng($sheet, $tilesetFile);
      imagedestroy($sheet);

        
if ($waterPacked) imagedestroy($waterPacked);
      if ($sandPacked) imagedestroy($sandPacked);
      if ($flowerPacked) imagedestroy($flowerPacked);
    }

    imagedestroy($pImg);
    imagedestroy($sImg);
  }

  // expose to response
  $tilesetKey = $tilesetKeyBase;
  $tilesetFile = $tilesetFrameFiles[0];
  $tilesetFramesRel = [];
  $tilesetUpperFramesRel = [];
  if ($animFrameCount > 0){
    foreach($tilesetFrameFiles as $fp){
      $tilesetFramesRel[] = 'pret/tilesets/' . basename($fp);
    }
    foreach($tilesetUpperFrameFiles as $fp){
      if (file_exists($fp)) $tilesetUpperFramesRel[] = 'pret/tilesets/' . basename($fp);
    }
  }
  $tilesetUpperRel = file_exists($tilesetUpperFile0) ? ('pret/tilesets/' . basename($tilesetUpperFile0)) : null;
  // write map json (always rewrite; cheap)
  $layer = [];
  foreach($tiles as $mtId){
    $layer[] = $remap[$mtId] ?? 0;
  }

  // collision: 0..3 -> 0/1 (0=walkable, 1=blocked)
$collisionOut = [];
foreach($collisions as $c){ $collisionOut[] = ($c ? 1 : 0); }

  // metatile behavior (for ledges/jumps etc.)
  // We follow the same primary/secondary split used by the renderer:
  //   mtId < pMetaCount => primary, else secondary.
  $behaviorOut = [];
  try {
    $pMetaBin2 = @file_get_contents($pDir . '/metatiles.bin');
    $sMetaBin2 = @file_get_contents($sDir . '/metatiles.bin');
    $pMetaCount2 = ($pMetaBin2 !== false) ? intdiv(strlen($pMetaBin2), 16) : 0;
    if ($pMetaCount2 <= 0) { $pMetaCount2 = 0; }

    $pAttrBin = @file_get_contents($pDir . '/metatile_attributes.bin');
    $sAttrBin = @file_get_contents($sDir . '/metatile_attributes.bin');
    $pAttrLen = ($pAttrBin !== false) ? strlen($pAttrBin) : 0;
    $sAttrLen = ($sAttrBin !== false) ? strlen($sAttrBin) : 0;

    // For each tile, read the behavior byte (offset 0) from the attributes entry (4 bytes per metatile)
    foreach($tiles as $mtId){
      $mtId = (int)$mtId;
      $isSec = ($pMetaCount2 > 0) ? ($mtId >= $pMetaCount2) : false;
      $local = $isSec ? ($mtId - $pMetaCount2) : $mtId;
      $bin   = $isSec ? $sAttrBin : $pAttrBin;
      $len   = $isSec ? $sAttrLen : $pAttrLen;

      $b = 0;
      $off = $local * 4;
      if ($bin !== false && ($off >= 0) && ($off + 1) <= $len){
        $b = ord($bin[$off]);
      }
      $behaviorOut[] = $b;
    }
  } catch (Throwable $e) {
    // On any failure, just output zeros (client will treat as normal ground)
    $behaviorOut = array_fill(0, count($tiles), 0);
  }

// border: layout-provided border_width/height (FRLG usually 2x2)
  $borderW = (int)($layout['border_width'] ?? 2);
  $borderH = (int)($layout['border_height'] ?? 2);
if ($borderW <= 0) $borderW = 2;
if ($borderH <= 0) $borderH = 2;
$borderTotal = $borderW * $borderH;
$borderOutData = [];
for ($i=0; $i<$borderTotal; $i++){
  $j = $i*2;
  if ($j+1 >= strlen($borderBin)){
    $borderOutData[] = 0;
    continue;
  }
  $u16 = ord($borderBin[$j]) | (ord($borderBin[$j+1])<<8);
  $id = ($u16 & 0x03FF);
  $borderOutData[] = $remap[$id] ?? 0;
}
$borderOut = ['w'=>$borderW,'h'=>$borderH,'data'=>$borderOutData];

// connections: convert MAP_* constants to folder names
$connectionsOut = [];
$mapConstToFolder = pret_map_const_to_folder($pack . '/data/maps');
$connArr = $mapObj['connections'] ?? [];
if (is_array($connArr)){
  foreach($connArr as $c){
    if (!is_array($c)) continue;
    $dir = (string)($c['direction'] ?? '');
    $off = (int)($c['offset'] ?? 0);
    $mapConst = (string)($c['map'] ?? '');
    $folder = $mapConstToFolder[$mapConst] ?? null;
    $connectionsOut[] = ['direction'=>$dir,'offset'=>$off,'map_id'=>$folder,'map_const'=>$mapConst];
  }
}

// rAthena-like connect override (migrate boundary scroll connections out of decomp)
$projectRoot = realpath(__DIR__ . '/..' . '/..'); // api/pret -> project root
if ($projectRoot) {
  $scriptConns = rathena_load_connects_for_map($projectRoot, $mapId, $mapConstToFolder);
  if (is_array($scriptConns) && count($scriptConns) > 0) {
    $connectionsOut = [];
    foreach ($scriptConns as $sc) {
      $connectionsOut[] = [
        'direction' => (string)($sc['direction'] ?? ''),
        'offset' => (int)($sc['offset'] ?? 0),
        'map_id' => ($sc['map_id'] ?? null),
        'map_const' => ($sc['map_const'] ?? null),
      ];
    }
  }
}

// warp events (doors / holes / warps)
$warpOut = [];
$warpArr = $mapObj['warp_events'] ?? [];
if (is_array($warpArr)){
  $wi = 0;
  foreach($warpArr as $w){
    if (!is_array($w)) { $wi++; continue; }
    $destConst = (string)($w['dest_map'] ?? '');

    // Some Packege map.jsons don't include warp_id; in that case, use the array index as warp_id.
    $warpId = array_key_exists('warp_id', $w) ? (int)$w['warp_id'] : $wi;

    // Resolve destination map folder:
    // - Usually dest_map is a MAP_* constant -> use const-to-folder map.
    // - If it's already a folder id, fallback to that if it exists.
    $destId = ($mapConstToFolder[$destConst] ?? null);
    if ($destId === null && $destConst !== '') {
      $p = $pack . '/data/maps/' . $destConst . '/map.json';
      if (file_exists($p)) $destId = $destConst;
    }

    $warpOut[] = [
      'warp_id' => $warpId,
      'x' => (int)($w['x'] ?? 0),
      'y' => (int)($w['y'] ?? 0),
      'elevation' => (int)($w['elevation'] ?? 0),
      'dest_map_const' => $destConst,
      'dest_map_id' => $destId,
      'dest_warp_id' => (int)($w['dest_warp_id'] ?? 0),
    ];
    $wi++;
  }
}


// rAthena-like warp override (migrate warp definitions out of decomp)
if ($projectRoot) {
  $scriptWarps = rathena_load_warps_for_map($projectRoot, $mapId, $mapConstToFolder);
  if (is_array($scriptWarps) && count($scriptWarps) > 0) {
    // Convert to the existing client schema (keep warp_id stable by line order)
    $warpOut = [];
    foreach ($scriptWarps as $sw) {
      $warpOut[] = [
        'warp_id' => (int)($sw['warp_id'] ?? 0),
        'x' => (int)($sw['x'] ?? 0),
        'y' => (int)($sw['y'] ?? 0),
        'elevation' => 0,
        'dest_map_id' => ($sw['dest_map_id'] ?? null),
        'dest_warp_id' => -1,
        'dest_x' => ($sw['dest_x'] ?? null),
        'dest_y' => ($sw['dest_y'] ?? null),
        'dest_dir' => ($sw['dest_dir'] ?? null),
        'dest_warp_token' => ($sw['dest_warp_token'] ?? null),
        'w' => (int)($sw['w'] ?? 1),
        'h' => (int)($sw['h'] ?? 1),
        'name' => ($sw['name'] ?? null),
      ];
    }
  }
}

$out = [
  'map_id' => $mapId,
  'map_const' => ($mapObj['id'] ?? null),
  'width' => $width,
  'height' => $height,
  'tileSize' => 16,
  'tilesetCols' => 16,
  'tileset' => 'pret/tilesets/' . basename($tilesetFile),
  'tilesetFrames' => $tilesetFramesRel,
  'tilesetUpper' => $tilesetUpperRel,
  'tilesetUpperFrames' => $tilesetUpperFramesRel,
  'tileAnimFps' => (count($tilesetFramesRel) ? 7.5 : null),
  'spawn' => ['x'=>10,'y'=>10,'dir'=>0],

  // gameplay helpers
  'collision' => $collisionOut,
  'behavior' => $behaviorOut,
  'border' => $borderOut,
  'connections' => $connectionsOut,
  'warp_events' => $warpOut,

  'layers' => [
    ['name'=>'ground','data'=>$layer],
  ],
  'meta' => [
    'layout' => $layoutId,
    'primary_tileset' => $pFolder,
    'secondary_tileset' => $sFolder,
    'used_metatiles' => count($used),
    'gen_ver' => $GEN_VER,
  ],
];

  $w = file_put_contents($mapOutFile, json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  if ($w === false) { throw new Exception('failed to write map json: ' . $mapOutFile); }

  // Optional: map label from DB (maps_info)
  $mapLabel = $mapId;
  try {
    $conn = db();
    $stmt = $conn->prepare('SELECT COALESCE(mapkname, name_en, mapname) AS label FROM maps_info WHERE mapname=? LIMIT 1');
    if ($stmt) {
      $stmt->bind_param('s', $mapId);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      if ($row && isset($row['label'])) $mapLabel = (string)$row['label'];
    }
  } catch (Throwable $e) {}

  jexit([
    'ok'=>1,
    'map'=>$mapId,
    'label'=>$mapLabel,
    'mapUrl'=>'./pret/maps/' . $mapId . '.json',
    'tilesetUrl'=>'./pret/tilesets/' . basename($tilesetFile),
    'tilesetFrames'=> $tilesetFramesRel,
    'usedMetatiles'=>count($used),
    'tilesets'=>['primary'=>$pFolder,'secondary'=>$sFolder],
  ]);
} catch (Exception $e) {
  jexit(['ok'=>0,'err'=>'EX','detail'=>$e->getMessage()], 500);
}