<?php
require_once __DIR__ . '/../core/http.php';
require_once __DIR__ . '/../core/path.php';

$path = osx_fs_path('storage/calendar/events.json');
if (!is_dir(dirname($path))) @mkdir(dirname($path), 0777, true);

function read_events(string $path): array {
  if (!is_file($path)) return [];
  $raw = file_get_contents($path);
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function write_events(string $path, array $events): void {
  file_put_contents($path, json_encode($events, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
  osx_json(['ok'=>true,'events'=>read_events($path)]);
}

if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) osx_json(['ok'=>false,'error'=>'bad_json'], 400);
  $date = (string)($data['date'] ?? '');
  $title = trim((string)($data['title'] ?? ''));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) osx_json(['ok'=>false,'error'=>'bad_date'], 400);
  if ($title === '') osx_json(['ok'=>false,'error'=>'empty'], 400);

  $events = read_events($path);
  $events[] = ['id'=>bin2hex(random_bytes(6)), 'date'=>$date, 'title'=>$title, 'ts'=>time()];
  write_events($path, $events);
  osx_json(['ok'=>true,'events'=>$events]);
}

osx_json(['ok'=>false,'error'=>'bad_method'], 405);
