<?php
require_once __DIR__ . '/core/path.php';

header('Content-Type: text/plain; charset=UTF-8');

$base = osx_base_path();
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$req = $_SERVER['REQUEST_URI'] ?? '';
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

function test_file($rel){
  $rel = ltrim($rel,'/');
  $p = __DIR__ . '/' . $rel;                // real file at app root
  $alt = __DIR__ . '/public/' . $rel;       // legacy copy under ./public
  $use = file_exists($p) ? $p : $alt;
  return [
    'url' => osx_public_url('/'.$rel),
    'fs' => $use,
    'alt' => $alt,
    'exists' => file_exists($use) ? 'YES' : 'NO',
    'size' => file_exists($use) ? filesize($use) : 0,
    'using' => ($use === $p) ? 'root' : 'public',
  ];
}

$out = [];
$out[] = "OK diag";
$out[] = "SCRIPT_NAME: $script";
$out[] = "REQUEST_URI: $req";
$out[] = "BASE: " . ($base === '' ? '(root)' : $base);
$out[] = "DOC_ROOT: $docRoot";
$out[] = "";
$out[] = "Static file probes:";
$out[] = "- This build includes REAL static files at /js, /css, /desktop, etc (preferred).";
$out[] = "- A copy is also kept under ./public for reference/backward-compat.";
foreach (['js/osx-shell.js','css/osx.css','calendar.png','headshot.jpg','desktop/versions/sierra-wallpaper.jpg'] as $rel){
  $t = test_file($rel);
  $out[] = "- $rel";
  $out[] = "  url: {$t['url']}";
  $out[] = "  fs : {$t['fs']}";
  $out[] = "  using: {$t['using']}  exists: {$t['exists']} size: {$t['size']}";
}

$out[] = "";
$out[] = "If /js/osx-shell.js shows HTML, your parent .htaccess is intercepting this folder.";
$out[] = "In that case add an exception in the parent .htaccess BEFORE its catch-all rule:";
$out[] = "  RewriteRule ^" . ltrim($base,'/') . "(/|$) - [L]";

echo implode("\n", $out);
