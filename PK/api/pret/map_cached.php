<?php

require __DIR__ . '/_common.php';

/**
 * Stable cached PRET map loader.
 *
 * Goal:
 * - Never depend on Packege or generator state.
 * - Never hard-fail just because rAthena merge files are missing.
 * - Never try to rewrite cache files on read-only deployments.
 * - Return the minimum shape overworld.js needs.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $mapId = safe_id($_GET['map'] ?? '');
    if ($mapId === '') {
        jexit(['ok' => 0, 'err' => 'NO_MAP'], 400);
    }

    $cfg = pret_cfg();
    $pubPret = $cfg['public_pret_root'];
    $mapFile = rtrim($pubPret, '/\\') . '/maps/' . $mapId . '.json';

    if (!is_file($mapFile)) {
        jexit([
            'ok' => 0,
            'err' => 'MAP_CACHE_MISSING',
            'map' => $mapId,
            'hint' => 'Generate caches first: open /api/pret/prebuild.php (batch) or /api/pret/map.php?map=... (single).',
        ], 404);
    }

    $raw = @file_get_contents($mapFile);
    if ($raw === false) {
        throw new Exception('failed to read cache: ' . $mapFile);
    }

    $map = json_decode($raw, true);
    if (!is_array($map)) {
        throw new Exception('cache json parse fail: ' . $mapFile);
    }

    $tilesetUrl = null;
    if (isset($map['tileset']) && $map['tileset'] !== '') {
        $tilesetUrl = './' . ltrim((string)$map['tileset'], './');
    } elseif (isset($map['tileset_lower']) && $map['tileset_lower'] !== '') {
        $tilesetUrl = './' . ltrim((string)$map['tileset_lower'], './');
    }

    $tilesetFrames = null;
    if (!empty($map['tilesetFrames']) && is_array($map['tilesetFrames'])) {
        $tilesetFrames = $map['tilesetFrames'];
    } elseif (!empty($map['tilesetFramesLower']) && is_array($map['tilesetFramesLower'])) {
        $tilesetFrames = $map['tilesetFramesLower'];
    }

    $tilesetUpper = null;
    if (isset($map['tilesetUpper']) && $map['tilesetUpper'] !== '') {
        $tilesetUpper = './' . ltrim((string)$map['tilesetUpper'], './');
    }

    $tilesetUpperFrames = null;
    if (!empty($map['tilesetUpperFrames']) && is_array($map['tilesetUpperFrames'])) {
        $tilesetUpperFrames = $map['tilesetUpperFrames'];
    }

    if ($tilesetUrl === null && empty($tilesetFrames)) {
        jexit([
            'ok' => 0,
            'err' => 'CACHE_STALE',
            'map' => $mapId,
            'have_ver' => (string)($map['meta']['gen_ver'] ?? ''),
        ], 409);
    }

    $label = $mapId;
    if (function_exists('db')) {
        try {
            $conn = db();
            if ($conn instanceof PDO) {
                $stmt = $conn->prepare('SELECT mapkname, name_en, mapname FROM maps_info WHERE mapname=? LIMIT 1');
                $stmt->execute([$mapId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $k = trim((string)($row['mapkname'] ?? ''));
                    $en = trim((string)($row['name_en'] ?? ''));
                    $label = $k !== '' ? $k : ($en !== '' ? $en : $mapId);
                }
            }
        } catch (Throwable $ignore) {
            // Label lookup is optional. Do not fail cached map delivery.
        }
    }

    jexit([
        'ok' => 1,
        'map' => $mapId,
        'mapUrl' => './pret/maps/' . $mapId . '.json',
        'label' => $label,
        'tilesetUrl' => $tilesetUrl,
        'tilesetFrames' => $tilesetFrames,
        'tilesetUpper' => $tilesetUpper,
        'tilesetUpperFrames' => $tilesetUpperFrames,
        'meta' => [
            'gen_ver' => (string)($map['meta']['gen_ver'] ?? ''),
            'cached_only' => true,
        ],
    ]);
} catch (Throwable $e) {
    jexit([
        'ok' => 0,
        'err' => 'EX',
        'detail' => $e->getMessage(),
    ], 500);
}
