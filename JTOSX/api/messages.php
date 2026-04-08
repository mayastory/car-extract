<?php
require_once __DIR__ . '/../core/http.php';
require_once __DIR__ . '/../core/path.php';

$path = osx_fs_path('storage/messages/messages.json');
if (!is_dir(dirname($path))) @mkdir(dirname($path), 0777, true);

function read_msgs(string $path): array {
  if (!is_file($path)) return [];
  $raw = file_get_contents($path);
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
  osx_json(['ok'=>true,'messages'=>read_msgs($path)]);
}

if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) osx_json(['ok'=>false,'error'=>'bad_json'], 400);

  if (!empty($data['clear'])) {
    file_put_contents($path, json_encode([], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    osx_json(['ok'=>true,'messages'=>[]]);
  }

  $text = trim((string)($data['text'] ?? ''));
  $from = (string)($data['from'] ?? 'me');
  if ($text === '') osx_json(['ok'=>false,'error'=>'empty'], 400);

  $msgs = read_msgs($path);
  $msgs[] = ['id'=>bin2hex(random_bytes(6)),'from'=>$from,'text'=>$text,'ts'=>time()];
  file_put_contents($path, json_encode($msgs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  osx_json(['ok'=>true,'messages'=>$msgs]);
}

osx_json(['ok'=>false,'error'=>'bad_method'], 405);
