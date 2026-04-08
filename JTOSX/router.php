<?php
// router.php - front controller for pretty URLs + static mapping to ./public (Next.js-style)
require_once __DIR__ . '/core/path.php';

$base = osx_base_path();
$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

// strip base (when mounted in a subfolder like /jtosx)
if ($base && strncasecmp($path, $base, strlen($base)) === 0) {
  $path = substr($path, strlen($base));
  if ($path === '') $path = '/';
}

if ($path === '') $path = '/';
$rel = ltrim($path, '/');

// ---- Static file serving (map URL /x to filesystem ./public/x) ----
// Prevent traversal
if (strpos($rel, '..') !== false || strpos($rel, "\\") !== false) {
  http_response_code(400);
  exit;
}

$publicDir = osx_fs_path('public');
$static = ($rel === '') ? null : osx_safe_join($publicDir, $rel);

// If a request clearly looks like a static asset (has an extension) but the file
// doesn't exist, return 404 instead of falling through to index.php (which would
// return HTML and break JS/CSS loads).
$reqExt = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
if ($reqExt !== '' && (!$static || !is_file($static))) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Not Found\n";
  exit;
}

if ($static && is_file($static)) {
  $ext = strtolower(pathinfo($static, PATHINFO_EXTENSION));
  $types = [
    'js' => 'application/javascript; charset=UTF-8',
    'css' => 'text/css; charset=UTF-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'pdf' => 'application/pdf',
    'json' => 'application/json; charset=UTF-8',
    'txt' => 'text/plain; charset=UTF-8',
    'woff2' => 'font/woff2',
    'woff' => 'font/woff',
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'm4a' => 'audio/mp4',
  ];
  $ct = $types[$ext] ?? 'application/octet-stream';

  header('Content-Type: ' . $ct);
  header('Content-Length: ' . filesize($static));
  // basic caching for assets
  if (in_array($ext, ['js','css','png','jpg','jpeg','webp','gif','svg','ico','woff2','woff','ttf','otf'], true)) {
    header('Cache-Control: public, max-age=86400');
  } else {
    header('Cache-Control: no-store');
  }

  readfile($static);
  exit;
}

// ---- Route → initial app (ground truth: alanagoyal-main/lib/shell-routing.ts) ----
$defaultApp  = 'notes';
$defaultNote = 'about-me';

$appId    = $defaultApp;
$noteSlug = $defaultNote;
$filePath = null;

$segments = array_values(array_filter(explode('/', trim($path, '/'))));
$first    = $segments[0] ?? '';
$known    = ['settings','messages','notes','iterm','finder','photos','calendar','music','textedit','preview'];

if ($path === '/' || $path === '') {
  $appId = 'notes';
} else if (in_array($first, $known, true)) {
  $appId = $first;
  if ($appId === 'notes' && isset($segments[1]) && $segments[1] !== '') {
    $noteSlug = urldecode($segments[1]);
  }
}

if (($appId === 'textedit' || $appId === 'preview') && isset($_GET['file'])) {
  $filePath = (string)$_GET['file'];
}

$OSX_INITIAL = [
  'appId' => $appId,
  'noteSlug' => $noteSlug,
  'filePath' => $filePath,
];

require __DIR__ . '/index.php';
