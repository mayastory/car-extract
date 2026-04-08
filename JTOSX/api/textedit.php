<?php
require_once __DIR__ . '/../core/http.php';
require_once __DIR__ . '/../core/path.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function norm_file_key(string $s): string {
  $s = str_replace('\\', '/', $s);
  $s = preg_replace('~\.{2,}~', '.', $s);
  $s = ltrim($s, '/');
  return $s;
}

if ($method === 'GET') {
  $file = norm_file_key($_GET['file'] ?? '');
  if ($file === '') osx_json(['ok'=>false,'error'=>'no_file'], 400);

  $root = osx_fs_root();
  $content = null;

  // allow: documents/* (read-only), storage/textedit/* (editable)
  if (strpos($file, 'documents/') === 0) {
    $p = osx_safe_join($root . '/public/documents', substr($file, strlen('documents/')));
    if ($p && is_file($p) && filesize($p) < 2_000_000) $content = file_get_contents($p);
  } else {
    $p = osx_safe_join($root . '/storage/textedit', basename($file));
    if ($p && is_file($p) && filesize($p) < 2_000_000) $content = file_get_contents($p);
  }

  if ($content === null) osx_json(['ok'=>false,'error'=>'not_found'], 404);
  osx_json(['ok'=>true,'file'=>$file,'content'=>$content]);
}

if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) osx_json(['ok'=>false,'error'=>'bad_json'], 400);
  $file = norm_file_key($data['file'] ?? '');
  $content = (string)($data['content'] ?? '');
  if ($file === '') osx_json(['ok'=>false,'error'=>'no_file'], 400);
  if (strlen($content) > 2_000_000) osx_json(['ok'=>false,'error'=>'too_large'], 413);

  $baseName = basename($file);
  if ($baseName === '' || $baseName === '.' || $baseName === '..') osx_json(['ok'=>false,'error'=>'bad_file'], 400);

  $targetDir = osx_fs_path('storage/textedit');
  if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
  $target = $targetDir . '/' . $baseName;
  file_put_contents($target, $content);
  osx_json(['ok'=>true,'saved'=> 'storage/textedit/' . $baseName]);
}

osx_json(['ok'=>false,'error'=>'bad_method'], 405);
