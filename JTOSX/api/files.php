<?php
require_once __DIR__ . '/../core/http.php';
require_once __DIR__ . '/../core/path.php';

$src = $_GET['src'] ?? 'documents';
$src = is_string($src) ? $src : 'documents';

function list_files(string $dir, string $urlPrefix, string $filePrefix): array {
  $items = [];
  if (!is_dir($dir)) return $items;
  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = $dir . '/' . $f;
    if (!is_file($p)) continue;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $items[] = [
      'name' => $f,
      'size' => filesize($p),
      'mtime' => filemtime($p),
      'ext' => $ext,
      'url' => osx_public_url($urlPrefix . '/' . rawurlencode($f)),
      'file' => $filePrefix . '/' . $f,
    ];
  }
  usort($items, fn($a,$b)=>($b['mtime']<=>$a['mtime']));
  return $items;
}

$root = osx_fs_root();

if ($src === 'uploads') {
  $dir = osx_fs_path('public/uploads');
  // Flatten a few known subfolders
  $items = [];
  foreach (['photos','music'] as $sub) {
    $subDir = $dir . '/' . $sub;
    foreach (list_files($subDir, '/uploads/'.$sub, 'uploads/'.$sub) as $it) {
      $it['group'] = $sub;
      $items[] = $it;
    }
  }
  usort($items, fn($a,$b)=>($b['mtime']<=>$a['mtime']));
  osx_json(['ok'=>true,'items'=>$items]);
}

// default: documents
$dir = osx_fs_path('public/documents');
$items = list_files($dir, '/documents', 'documents');
osx_json(['ok'=>true,'items'=>$items]);
