<?php
// core/path.php

function osx_base_path(): string {
  // Base path where this app is mounted (e.g. /osx or /jtosx).
  // Robust against odd SCRIPT_NAME values (reverse proxies / rewrites), and normalizes casing.
  if (defined('OSX_BASE_PATH')) {
    $v = rtrim((string)constant('OSX_BASE_PATH'), '/');
    return $v === '/' ? '' : $v;
  }

  $script = str_replace("\\", "/", (string)($_SERVER["SCRIPT_NAME"] ?? ""));

  // If we are executing a nested script, strip to the app root.
  foreach (['/api/', '/(desktop)/', '/core/', '/public/'] as $marker) {
    $pos = strpos($script, $marker);
    if ($pos !== false) {
      $base = rtrim(substr($script, 0, $pos), '/');
      $computed = ($base === '/' ? '' : $base);
      break;
    }
  }

  if (!isset($computed)) {
    // router.php or index.php at app root
    $dir = rtrim(dirname($script), '/');
    $computed = ($dir === '/' ? '' : $dir);
  }

  $reqPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

  // Prefer matching the URL segment that looks like the folder name.
  // This also supports being mounted under an extra prefix (e.g. /JTMES/JTOSX).
  $folderName = basename(osx_fs_root());
  if ($folderName && $folderName !== '.' && $folderName !== '/') {
    $re = '~/' . preg_quote($folderName, '~') . '(?=/|$)~i';
    if (preg_match($re, $reqPath, $m, PREG_OFFSET_CAPTURE)) {
      $pos = (int)$m[0][1];
      $seg = (string)$m[0][0];
      $base = rtrim(substr($reqPath, 0, $pos + strlen($seg)), '/');
      return $base === '/' ? '' : $base;
    }
  }

  // If request begins with computed (case-insensitive), return the request's casing
  if ($computed !== '') {
    $len = strlen($computed);
    if ($len > 0 && strncasecmp($reqPath, $computed, $len) === 0) {
      return substr($reqPath, 0, $len);
    }
  }

  return $computed;
}

function osx_public_url(string $path): string {
  $base = osx_base_path();
  if ($path === '') return $base . '/';
  if ($path[0] !== '/') $path = '/' . $path;
  return $base . $path;
}

function osx_fs_root(): string {
  return realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
}

function osx_fs_path(string $rel): string {
  $rel = ltrim($rel, '/');
  return osx_fs_root() . '/' . $rel;
}

function osx_safe_join(string $baseDir, string $rel): ?string {
  // Prevent path traversal
  $rel = str_replace("\\", "/", $rel);
  $rel = ltrim($rel, '/');
  $full = $baseDir . '/' . $rel;
  $realBase = realpath($baseDir);
  $realFull = realpath($full);
  if (!$realBase || !$realFull) return null;
  if (strpos($realFull, $realBase) !== 0) return null;
  return $realFull;
}
