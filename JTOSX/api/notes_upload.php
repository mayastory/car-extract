<?php
// api/notes_upload.php
// Upload images for private notes (session notes).

require_once __DIR__ . '/../core/http.php';
require_once __DIR__ . '/../core/path.php';

osx_require_method('POST');

$sessionId = (string)($_POST['session_id'] ?? '');
$noteId    = (string)($_POST['note_id'] ?? '');

if (!preg_match('/^[0-9a-fA-F-]{36}$/', $sessionId)) {
  osx_json(['ok' => false, 'error' => 'bad_session'], 400);
}
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $noteId)) {
  osx_json(['ok' => false, 'error' => 'bad_note_id'], 400);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  osx_json(['ok' => false, 'error' => 'missing_file'], 400);
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  osx_json(['ok' => false, 'error' => 'upload_error', 'code' => (int)($f['error'] ?? -1)], 400);
}

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > 5 * 1024 * 1024) {
  osx_json(['ok' => false, 'error' => 'too_large'], 413);
}

$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
  osx_json(['ok' => false, 'error' => 'bad_upload'], 400);
}

$mime = '';
if (function_exists('finfo_open')) {
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $mime = (string)finfo_file($fi, $tmp);
    finfo_close($fi);
  }
}

$allowed = [
  'image/jpeg' => 'jpg',
  'image/jpg'  => 'jpg',
  'image/png'  => 'png',
  'image/gif'  => 'gif',
  'image/webp' => 'webp',
];

$ext = $allowed[$mime] ?? null;
if ($ext === null) {
  // Fallback to extension from original name
  $orig = strtolower((string)($f['name'] ?? ''));
  if (preg_match('/\.(jpe?g|png|gif|webp)$/', $orig, $m)) {
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
  }
}

if ($ext === null) {
  osx_json(['ok' => false, 'error' => 'bad_type', 'mime' => $mime], 415);
}

// Save under public/uploads so router.php can serve it.
$relDir = 'uploads/notes/' . $noteId;
$absDir = osx_fs_path('public/' . $relDir);
if (!is_dir($absDir)) @mkdir($absDir, 0777, true);

$fn = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$absPath = $absDir . '/' . $fn;

if (!@move_uploaded_file($tmp, $absPath)) {
  osx_json(['ok' => false, 'error' => 'save_failed'], 500);
}

$url = osx_public_url('/' . $relDir . '/' . $fn);

osx_json(['ok' => true, 'url' => $url]);
