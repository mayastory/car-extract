<?php
// api/pret/_tileset_builder.php
declare(strict_types=1);

function pret_parse_jasc_pal(string $path): array {
    // returns [ [r,g,b] x 16 ] or empty
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!$lines || count($lines) < 4) return [];
    $lines = array_map('trim', $lines);
    if (strtoupper($lines[0]) !== 'JASC-PAL') return [];
    $n = (int)$lines[2];
    $out = [];
    for ($i=0; $i<$n && (3+$i)<count($lines); $i++) {
        $parts = preg_split('/\s+/', $lines[3+$i]);
        if (!$parts || count($parts) < 3) continue;
        $out[] = [ (int)$parts[0], (int)$parts[1], (int)$parts[2] ];
    }
    return $out;
}

function pret_palette_is_dummy(array $pal16): bool {
    if (count($pal16) < 16) return true;
    $mag = 0;
    foreach ($pal16 as $rgb) {
        if (!is_array($rgb) || count($rgb) < 3) continue;
        if ((int)$rgb[0] === 255 && (int)$rgb[1] === 0 && (int)$rgb[2] === 255) $mag++;
    }
    return $mag >= 8; // heuristic
}

function pret_load_tileset_palettes(string $tilesetDir): array {
    $palDir = $tilesetDir . DIRECTORY_SEPARATOR . 'palettes';
    $pals = array_fill(0, 16, []);
    for ($i=0; $i<16; $i++) {
        $p = $palDir . DIRECTORY_SEPARATOR . sprintf('%02d.pal', $i);
        if (!is_file($p)) continue;
        $pal = pret_parse_jasc_pal($p);
        if (count($pal) >= 16) $pals[$i] = array_slice($pal, 0, 16);
    }
    return $pals;
}

function pret_merge_palettes(array $primaryPals, array $secondaryPals): array {
    // Rule:
    //  - palette 0..6: keep primary unless primary is dummy (magenta-heavy) and secondary exists
    //  - palette 7..15: prefer secondary if exists, otherwise primary
    $out = array_fill(0, 16, []);
    for ($i=0; $i<16; $i++) {
        $p = $primaryPals[$i] ?? [];
        $s = $secondaryPals[$i] ?? [];
        if ($i >= 7) {
            $out[$i] = (count($s) >= 16) ? $s : $p;
        } else {
            if (count($p) >= 16 && !pret_palette_is_dummy($p)) {
                $out[$i] = $p;
            } else {
                $out[$i] = (count($s) >= 16) ? $s : $p;
            }
        }
        // fallback: if still empty, make grayscale
        if (count($out[$i]) < 16) {
            $tmp = [];
            for ($k=0;$k<16;$k++) $tmp[] = [$k*16,$k*16,$k*16];
            $out[$i] = $tmp;
        }
    }
    return $out;
}

function pret_read_u16le(string $bytes, int $off): int {
    $a = ord($bytes[$off] ?? "\x00");
    $b = ord($bytes[$off+1] ?? "\x00");
    return $a | ($b << 8);
}

function pret_tileset_key(string $primaryFolder, string $secondaryFolder): string {
    $key = $primaryFolder . '__' . $secondaryFolder;
    $key = str_replace(DIRECTORY_SEPARATOR, '/', $key);
    $key = preg_replace('/[^a-z0-9_\/-]+/i', '_', (string)$key);
    $key = str_replace('/', '__', $key);
    $key = strtolower($key);
    $key = preg_replace('/__+/', '__', $key);
    return $key;
}

function pret_build_tileset_png(string $packegeRoot, string $primarySymbol, string $secondarySymbol): array {
    $primaryFolder = pret_tileset_symbol_to_folder($primarySymbol);
    $secondaryFolder = pret_tileset_symbol_to_folder($secondarySymbol);

    $primaryDir = $packegeRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tilesets' . DIRECTORY_SEPARATOR . 'primary' . DIRECTORY_SEPARATOR . $primaryFolder;
    $secondaryDir = $packegeRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tilesets' . DIRECTORY_SEPARATOR . 'secondary' . DIRECTORY_SEPARATOR . $secondaryFolder;

    $key = pret_tileset_key($primaryFolder, $secondaryFolder);
    $pretPublic = pret_public_pret_root();
    $outDir = $pretPublic . DIRECTORY_SEPARATOR . 'tilesets';
    pret_mkdir($outDir);

    $outPng = $outDir . DIRECTORY_SEPARATOR . $key . '.png';
    $url = '/pret/tilesets/' . $key . '.png';

    if (is_file($outPng) && filesize($outPng) > 1024) {
        return ['ok'=>true, 'created'=>false, 'key'=>$key, 'path'=>$outPng, 'url'=>$url,
            'primary'=>$primaryFolder, 'secondary'=>$secondaryFolder];
    }

    // required files
    $pTiles = $primaryDir . DIRECTORY_SEPARATOR . 'tiles.png';
    $sTiles = $secondaryDir . DIRECTORY_SEPARATOR . 'tiles.png';
    $pMetas = $primaryDir . DIRECTORY_SEPARATOR . 'metatiles.bin';
    $sMetas = $secondaryDir . DIRECTORY_SEPARATOR . 'metatiles.bin';

    if (!is_file($pTiles) || !is_file($sTiles) || !is_file($pMetas) || !is_file($sMetas)) {
        return ['ok'=>false, 'error'=>'tileset_files_missing', 'primaryDir'=>$primaryDir, 'secondaryDir'=>$secondaryDir];
    }

    $imgP = @imagecreatefrompng($pTiles);
    $imgS = @imagecreatefrompng($sTiles);
    if (!$imgP || !$imgS) {
        return ['ok'=>false, 'error'=>'png_load_failed'];
    }

    $pW = imagesx($imgP); $pH = imagesy($imgP);
    $sW = imagesx($imgS); $sH = imagesy($imgS);
    $pCols = intdiv($pW, 8);
    $sCols = intdiv($sW, 8);
    $pTileCount = $pCols * intdiv($pH, 8);

    $pMetaBytes = file_get_contents($pMetas);
    $sMetaBytes = file_get_contents($sMetas);
    if ($pMetaBytes === false || $sMetaBytes === false) return ['ok'=>false, 'error'=>'metatiles_read_failed'];

    $pMetaCount = intdiv(strlen($pMetaBytes), 16);
    $sMetaCount = intdiv(strlen($sMetaBytes), 16);
    $total = $pMetaCount + $sMetaCount;

    // palettes
    $pPals = pret_load_tileset_palettes($primaryDir);
    $sPals = pret_load_tileset_palettes($secondaryDir);
    $pals = pret_merge_palettes($pPals, $sPals);

    // output sheet
    $cols = 16;
    $rows = (int)ceil($total / $cols);
    $sheetW = $cols * 16;
    $sheetH = $rows * 16;

    $sheet = imagecreatetruecolor($sheetW, $sheetH);
    imagealphablending($sheet, false);
    imagesavealpha($sheet, true);
    $transparent = imagecolorallocatealpha($sheet, 0, 0, 0, 127);
    imagefill($sheet, 0, 0, $transparent);

    // pre-allocate colors for each palette/color index
    $colorCache = [];
    for ($pi=0;$pi<16;$pi++){
        $colorCache[$pi] = [];
        for ($ci=0;$ci<16;$ci++){
            $rgb = $pals[$pi][$ci] ?? [0,0,0];
            $colorCache[$pi][$ci] = imagecolorallocatealpha($sheet, (int)$rgb[0], (int)$rgb[1], (int)$rgb[2], 0);
        }
    }

    // helper to read pixel index from tile images
    $readTilePixel = function($img, int $tx, int $ty): int {
        $v = imagecolorat($img, $tx, $ty);
        return $v & 0xFF;
    };

    for ($m=0; $m<$total; $m++) {
        $isPrimary = ($m < $pMetaCount);
        $metaOff = ($isPrimary ? $m : ($m-$pMetaCount)) * 16;
        $bytes = $isPrimary ? $pMetaBytes : $sMetaBytes;

        // 8 u16: bottom[0..3], top[4..7]
        $tiles = [];
        for ($i=0; $i<8; $i++) {
            $tiles[$i] = pret_read_u16le($bytes, $metaOff + $i*2);
        }

        $mx = ($m % $cols) * 16;
        $my = intdiv($m, $cols) * 16;

        for ($layer=0; $layer<2; $layer++) {
            for ($q=0; $q<4; $q++) {
                $entry = $tiles[$layer*4 + $q];
                $tileId = $entry & 0x3FF;
                $hflip = (($entry & 0x400) !== 0);
                $vflip = (($entry & 0x800) !== 0);
                $pal = ($entry >> 12) & 0xF;

                // resolve tileset image + index
                if ($tileId < $pTileCount) {
                    $srcImg = $imgP;
                    $idx = $tileId;
                    $c = $pCols;
                } else {
                    $srcImg = $imgS;
                    $idx = $tileId - $pTileCount;
                    $c = $sCols;
                }

                $sx = ($idx % $c) * 8;
                $sy = intdiv($idx, $c) * 8;

                $dx0 = ($q % 2) * 8;
                $dy0 = intdiv($q, 2) * 8;

                for ($py=0; $py<8; $py++) {
                    for ($px=0; $px<8; $px++) {
                        $rx = $hflip ? (7-$px) : $px;
                        $ry = $vflip ? (7-$py) : $py;
                        $ci = $readTilePixel($srcImg, $sx + $rx, $sy + $ry);

                        if ($layer === 1 && $ci === 0) continue; // top layer transparency

                        $col = $colorCache[$pal][$ci] ?? $colorCache[0][0];
                        imagesetpixel($sheet, $mx + $dx0 + $px, $my + $dy0 + $py, $col);
                    }
                }
            }
        }
    }

    // write png
    pret_mkdir(dirname($outPng));
    imagepng($sheet, $outPng);
    imagedestroy($sheet);
    imagedestroy($imgP);
    imagedestroy($imgS);

    return ['ok'=>true, 'created'=>true, 'key'=>$key, 'path'=>$outPng, 'url'=>$url,
        'primary'=>$primaryFolder, 'secondary'=>$secondaryFolder, 'metatiles_total'=>$total];
}
