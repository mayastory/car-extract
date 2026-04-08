<?php
require_once __DIR__ . '/../core/http.php';
require_once __DIR__ . '/../core/path.php';

$dir = osx_fs_path('public/uploads/music');
if (!is_dir($dir)) @mkdir($dir, 0777, true);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
  $items = [];
  foreach (glob($dir . '/*') as $p) {
    if (!is_file($p)) continue;
    $bn = basename($p);
    $items[] = [
      'name' => $bn,
      'url'  => osx_public_url('/uploads/music/' . rawurlencode($bn)),
      'file' => 'uploads/music/' . $bn,
      'mtime'=> filemtime($p),
      'size' => filesize($p),
    ];
  }
  usort($items, fn($a,$b)=>$b['mtime']<=>$a['mtime']);
  osx_json(['ok'=>true,'items'=>$items]);
}

if ($method === 'POST') {
  if (!isset($_FILES['file'])) osx_json(['ok'=>false,'error'=>'no_file'], 400);
  $f = $_FILES['file'];
  if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) osx_json(['ok'=>false,'error'=>'upload_error'], 400);
  $name = basename($f['name'] ?? 'audio');
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['mp3','wav','m4a','aac','ogg','flac'], true)) osx_json(['ok'=>false,'error'=>'bad_ext'], 400);
  $safe = preg_replace('~[^a-zA-Z0-9._-]+~', '_', $name);
  $targetName = time() . '_' . $safe;
  $target = $dir . '/' . $targetName;
  move_uploaded_file($f['tmp_name'], $target);
  osx_json(['ok'=>true,'url'=>osx_public_url('/uploads/music/' . rawurlencode($targetName)), 'file'=>'uploads/music/' . $targetName]);
}

osx_json(['ok'=>false,'error'=>'bad_method'], 405);
