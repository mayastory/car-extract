<?php
// api/config.php
function cfg(): array {
  $local = __DIR__ . '/config.local.php';
  if (file_exists($local)) {
    $c = require $local;
    if (is_array($c)) return $c;
  }

  // Environment overrides (useful for Docker / CI / different hosts)
  $envHost = getenv('POKE_DB_HOST');
  $envUser = getenv('POKE_DB_USER');
  $envPass = getenv('POKE_DB_PASS');
  $envDb   = getenv('POKE_DB_NAME');

  $host = ($envHost !== false && is_string($envHost) && $envHost !== '') ? $envHost : '127.0.0.1';
  $user = ($envUser !== false && is_string($envUser) && $envUser !== '') ? $envUser : 'root';
  $pass = ($envPass !== false && is_string($envPass)) ? $envPass : '';
  $db   = ($envDb   !== false && is_string($envDb)   && $envDb   !== '') ? $envDb   : 'pokemon';

  return [
    'host' => $host,
    'user' => $user,
    'pass' => $pass,
    'db'   => $db,
  ];
}
function db(): mysqli {
  static $conn = null;
  if ($conn instanceof mysqli) return $conn;

  $c = cfg();
  $conn = new mysqli($c['host'], $c['user'], $c['pass'], $c['db']);
  if ($conn->connect_errno) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'DB_CONNECT_FAIL','detail'=>$conn->connect_error], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $conn->set_charset('utf8mb4');
  return $conn;
}
function json_in(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
function json_out($arr, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function require_fields(array $in, array $fields): void {
  foreach ($fields as $f) {
    if (!array_key_exists($f, $in)) json_out(['ok'=>false,'error'=>'MISSING_FIELD','field'=>$f], 400);
  }
}
