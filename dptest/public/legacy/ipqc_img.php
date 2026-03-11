<?php
/**
 * Serve IPQC Graph Builder toolbar icons.
 *
 * Why:
 * - /Module is protected by /Module/.htaccess (403), so the browser can't request images directly.
 * - Root .htaccess rewrites /ipqc_img.php to /public/legacy/ipqc_img.php.
 *
 * Usage:
 *   /ipqc_img.php?f=icon_01.png
 */

$fn = (string)($_GET['f'] ?? '');
// Strict allowlist: icon_01.png ~ icon_15.png only
if (!preg_match('/^icon_(0[1-9]|1[0-5])\\.png$/', $fn)) {
  http_response_code(404);
  exit;
}

$base = realpath(__DIR__ . '/../../Module/IPQC/img');
if ($base === false) {
  http_response_code(404);
  exit;
}

$path = realpath($base . DIRECTORY_SEPARATOR . $fn);
if ($path === false || strpos($path, $base) !== 0 || !is_file($path)) {
  http_response_code(404);
  exit;
}

// Simple caching
$mtime = @filemtime($path);
if ($mtime !== false) {
  $lm = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
  header('Last-Modified: ' . $lm);
  if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime) {
    http_response_code(304);
    exit;
  }
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

$fp = fopen($path, 'rb');
if ($fp === false) {
  http_response_code(404);
  exit;
}

// Send length if possible
$size = @filesize($path);
if ($size !== false) header('Content-Length: ' . $size);

fpassthru($fp);
fclose($fp);
exit;
