<?php
// api/notes.php
// Notes API inspired by alanagoyal.com notes app.
// Storage backend: filesystem JSON under ./storage/notes

require_once __DIR__ . '/../core/http.php';
require_once __DIR__ . '/../core/path.php';

// ---- helpers ----

function notes_uuidv4(): string {
  $data = random_bytes(16);
  // set version to 0100
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  // set bits 6-7 to 10
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  $hex = bin2hex($data);
  return sprintf('%s-%s-%s-%s-%s',
    substr($hex, 0, 8),
    substr($hex, 8, 4),
    substr($hex, 12, 4),
    substr($hex, 16, 4),
    substr($hex, 20, 12)
  );
}

function notes_iso_now(): string {
  return gmdate('c');
}

function notes_is_uuid(string $s): bool {
  return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $s);
}

function notes_slugify(string $s): string {
  $s = trim($s);
  $s = preg_replace('~[^a-zA-Z0-9_-]+~', '-', $s);
  $s = trim($s, '-');
  $s = strtolower($s);
  return $s === '' ? 'note' : $s;
}

function notes_excerpt(string $content, int $max = 140): string {
  $lines = preg_split("/\r\n|\r|\n/", (string)$content);
  $out = '';
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === '') continue;
    if (preg_match('/^#\s+/', $ln)) continue;
    $out = $ln;
    break;
  }
  $out = preg_replace('/\s+/', ' ', $out);
  if (mb_strlen($out, 'UTF-8') > $max) {
    $out = mb_substr($out, 0, $max, 'UTF-8') . '…';
  }
  return $out;
}

function notes_title_from_content(string $content, string $fallback = 'Untitled'): string {
  $lines = preg_split("/\r\n|\r|\n/", (string)$content);
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === '') continue;
    if (preg_match('/^#\s+(.+)$/', $ln, $m)) {
      $t = trim($m[1]);
      return $t !== '' ? $t : $fallback;
    }
  }
  return $fallback;
}

function notes_storage_dirs(): array {
  $root = osx_fs_path('storage/notes');
  $publicDir = $root . '/public';
  $sessionDir = $root . '/sessions';
  if (!is_dir($publicDir)) @mkdir($publicDir, 0777, true);
  if (!is_dir($sessionDir)) @mkdir($sessionDir, 0777, true);
  return [$root, $publicDir, $sessionDir];
}

function notes_admin_ok(): bool {
  $token = (string)($_SERVER['HTTP_X_NOTES_ADMIN_TOKEN'] ?? '');
  $want = (string)(getenv('NOTES_ADMIN_TOKEN') ?: '');
  if ($want === '') return false;
  return hash_equals($want, $token);
}

function notes_read_json(string $path): ?array {
  if (!is_file($path)) return null;
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}

function notes_write_json(string $path, array $data): void {
  $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
  @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
  @rename($tmp, $path);
}

function notes_public_path(string $slug): string {
  [, $publicDir] = notes_storage_dirs();
  return $publicDir . '/' . $slug . '.json';
}

function notes_session_path(string $sessionId, string $slug): string {
  [, , $sessionDir] = notes_storage_dirs();
  $d = $sessionDir . '/' . $sessionId;
  if (!is_dir($d)) @mkdir($d, 0777, true);
  return $d . '/' . $slug . '.json';
}

function notes_list_public(): array {
  [, $publicDir] = notes_storage_dirs();
  $items = [];
  foreach (glob($publicDir . '/*.json') ?: [] as $p) {
    $j = notes_read_json($p);
    if (!is_array($j)) continue;
    $j['public'] = true;
    $j['session_id'] = null;
    $items[] = $j;
  }
  return $items;
}

function notes_list_session(string $sessionId): array {
  if (!notes_is_uuid($sessionId)) return [];
  [, , $sessionDir] = notes_storage_dirs();
  $d = $sessionDir . '/' . $sessionId;
  if (!is_dir($d)) return [];
  $items = [];
  foreach (glob($d . '/*.json') ?: [] as $p) {
    $j = notes_read_json($p);
    if (!is_array($j)) continue;
    $j['public'] = false;
    $j['session_id'] = $sessionId;
    $items[] = $j;
  }
  return $items;
}

function notes_normalize_note(array $n): array {
  $n['id'] = (string)($n['id'] ?? '');
  if (!notes_is_uuid($n['id'])) $n['id'] = notes_uuidv4();

  $n['slug'] = notes_slugify((string)($n['slug'] ?? ''));
  $n['title'] = (string)($n['title'] ?? '');
  $n['content'] = (string)($n['content'] ?? '');

  $n['emoji'] = (string)($n['emoji'] ?? '');
  $n['category'] = array_key_exists('category', $n) ? ($n['category'] === null ? null : (string)$n['category']) : null;

  $n['created_at'] = (string)($n['created_at'] ?? '');
  $n['updated_at'] = (string)($n['updated_at'] ?? '');
  if ($n['created_at'] === '') $n['created_at'] = notes_iso_now();
  if ($n['updated_at'] === '') $n['updated_at'] = $n['created_at'];

  $n['excerpt'] = notes_excerpt($n['content']);
  return $n;
}

function notes_pick_note(string $slug, ?string $sessionId): ?array {
  $slug = notes_slugify($slug);

  $p = notes_public_path($slug);
  $j = notes_read_json($p);
  if (is_array($j)) {
    $j['public'] = true;
    $j['session_id'] = null;
    return notes_normalize_note($j);
  }

  if ($sessionId && notes_is_uuid($sessionId)) {
    $sp = notes_session_path($sessionId, $slug);
    $sj = notes_read_json($sp);
    if (is_array($sj)) {
      $sj['public'] = false;
      $sj['session_id'] = $sessionId;
      return notes_normalize_note($sj);
    }
  }

  return null;
}

function notes_seed_public_if_empty(): void {
  [, $publicDir] = notes_storage_dirs();
  $has = glob($publicDir . '/*.json');
  if ($has && count($has) > 0) return;

  $now = notes_iso_now();
  $seed = [
    [
      'id' => notes_uuidv4(),
      'slug' => 'about-me',
      'title' => 'About Me',
      'emoji' => '👋',
      'public' => true,
      'category' => 'older',
      'created_at' => $now,
      'updated_at' => $now,
      'content' => "# About Me\n\nThis is a **public** note.\n\n- [x] Task lists are clickable for *your* private notes\n- Paste an image into a private note to upload\n\nTry creating a new note from the sidebar.",
    ],
    [
      'id' => notes_uuidv4(),
      'slug' => 'quick-links',
      'title' => 'Quick Links',
      'emoji' => '🔗',
      'public' => true,
      'category' => 'older',
      'created_at' => $now,
      'updated_at' => $now,
      'content' => "# Quick Links\n\n- [alanagoyal.com/notes](https://www.alanagoyal.com/notes)\n- [Apple Human Interface Guidelines](https://developer.apple.com/design/human-interface-guidelines/)\n\n---\n\n> Public notes are read-only unless you set `NOTES_ADMIN_TOKEN` and send `x-notes-admin-token`.",
    ],
  ];

  foreach ($seed as $n) {
    $n = notes_normalize_note($n);
    $path = notes_public_path($n['slug']);
    notes_write_json($path, $n);
  }
}

notes_seed_public_if_empty();

// ---- routing ----

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
  $sessionId = $_GET['session_id'] ?? null;
  $sessionId = is_string($sessionId) ? $sessionId : null;
  if ($sessionId !== null && !notes_is_uuid($sessionId)) $sessionId = null;

  $slug = $_GET['slug'] ?? null;
  if ($slug !== null) {
    $note = notes_pick_note((string)$slug, $sessionId);
    if (!$note) osx_json(['ok' => false, 'error' => 'not_found'], 404);
    osx_json(['ok' => true, 'note' => $note]);
  }

  $public = notes_list_public();
  $session = $sessionId ? notes_list_session($sessionId) : [];

  $items = [];
  foreach (array_merge($public, $session) as $n) {
    $n = notes_normalize_note($n);
    $items[] = [
      'id' => $n['id'],
      'slug' => $n['slug'],
      'title' => $n['title'],
      'emoji' => $n['emoji'],
      'category' => $n['category'],
      'public' => (bool)($n['public'] ?? false),
      'session_id' => $n['session_id'] ?? null,
      'created_at' => $n['created_at'],
      'updated_at' => $n['updated_at'],
      'excerpt' => $n['excerpt'],
      'content' => $n['content'],
    ];
  }
  usort($items, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
  osx_json(['ok' => true, 'items' => $items]);
}

if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) osx_json(['ok' => false, 'error' => 'bad_json'], 400);

  $action = strtolower((string)($data['action'] ?? 'upsert'));
  $sessionId = isset($data['session_id']) ? (string)$data['session_id'] : '';
  $isPublic = (bool)($data['public'] ?? false);

  if ($action === 'delete') {
    $slug = notes_slugify((string)($data['slug'] ?? ''));
    if ($slug === '') osx_json(['ok' => false, 'error' => 'missing_slug'], 400);
    if (!notes_is_uuid($sessionId)) osx_json(['ok' => false, 'error' => 'missing_session'], 400);

    $path = notes_session_path($sessionId, $slug);
    if (!is_file($path)) osx_json(['ok' => false, 'error' => 'not_found'], 404);

    @unlink($path);
    osx_json(['ok' => true]);
  }

  // upsert
  if ($isPublic) {
    if (!notes_admin_ok()) osx_json(['ok' => false, 'error' => 'forbidden'], 403);
  } else {
    if (!notes_is_uuid($sessionId)) osx_json(['ok' => false, 'error' => 'missing_session'], 400);
  }

  $slug = (string)($data['slug'] ?? '');
  $title = (string)($data['title'] ?? '');
  $content = (string)($data['content'] ?? '');
  $emoji = (string)($data['emoji'] ?? '');
  $category = array_key_exists('category', $data) ? (string)($data['category'] ?? '') : null;

  if ($slug === '' && $title !== '') $slug = notes_slugify($title);
  $slug = notes_slugify($slug);

  if ($slug === '') osx_json(['ok' => false, 'error' => 'missing_slug'], 400);
  if (strlen($content) > 2_000_000) osx_json(['ok' => false, 'error' => 'too_large'], 413);

  $path = $isPublic ? notes_public_path($slug) : notes_session_path($sessionId, $slug);
  $existing = notes_read_json($path);

  $now = notes_iso_now();
  $note = is_array($existing) ? $existing : [];

  $note['slug'] = $slug;
  $note['public'] = $isPublic;
  $note['session_id'] = $isPublic ? null : $sessionId;

  $titleProvided = array_key_exists('title', $data);
  $emojiProvided = array_key_exists('emoji', $data);

  if ($titleProvided) $note['title'] = $title; // allow empty title (matches alanagoyal)
  if ($emojiProvided && $emoji !== '') $note['emoji'] = $emoji;
  $note['content'] = $content;

  if ($category !== null) {
    $note['category'] = $category === '' ? null : $category;
  }

  if (!isset($note['id']) || !notes_is_uuid((string)$note['id'])) $note['id'] = notes_uuidv4();
  if (!isset($note['created_at']) || (string)$note['created_at'] === '') $note['created_at'] = $now;
  $note['updated_at'] = $now;

  $note = notes_normalize_note($note);
  notes_write_json($path, $note);

  osx_json(['ok' => true, 'note' => $note]);
}

osx_json(['ok' => false, 'error' => 'bad_method'], 405);
