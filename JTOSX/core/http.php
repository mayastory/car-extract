<?php
// core/http.php

function osx_json($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function osx_text(string $text, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: text/plain; charset=UTF-8');
  echo $text;
  exit;
}

function osx_require_method(string $method): void {
  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
    osx_json(['ok' => false, 'error' => 'bad_method'], 405);
  }
}
