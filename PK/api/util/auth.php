<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_token.php';

function auth_get_bearer_token(): string {
  // 1) Standard Authorization: Bearer <token>
  $h = '';
  if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $h = (string)$_SERVER['HTTP_AUTHORIZATION'];
  } elseif (function_exists('getallheaders')) {
    $hdrs = getallheaders();
    if (is_array($hdrs)) {
      foreach ($hdrs as $k => $v) {
        if (strtolower((string)$k) === 'authorization') {
          $h = (string)$v;
          break;
        }
      }
    }
  }
  $h = trim($h);
  if ($h !== '' && stripos($h, 'bearer ') === 0) {
    $t = trim(substr($h, 7));
    if ($t !== '') return $t;
  }

  // 2) Cookie fallback (for EventSource/SSE which can't set headers)
  if (isset($_COOKIE['poke_play_token'])) {
    $t = trim((string)$_COOKIE['poke_play_token']);
    if ($t !== '') return $t;
  }

  // 3) Query fallback (dev only)
  if (isset($_GET['play_token'])) {
    $t = trim((string)$_GET['play_token']);
    if ($t !== '') return $t;
  }
  if (isset($_GET['token'])) {
    $t = trim((string)$_GET['token']);
    if ($t !== '') return $t;
  }

  return '';
}


function auth_require_token(?string $kind = null): array {
  $t = auth_get_bearer_token();
  if ($t === '') json_out(['ok'=>false,'error'=>'NO_AUTH_TOKEN'], 401);
  $payload = verify_token($t);
  if (!$payload) json_out(['ok'=>false,'error'=>'BAD_AUTH_TOKEN'], 401);
  if ($kind !== null) {
    $tk = isset($payload['t']) ? (string)$payload['t'] : '';
    if ($tk !== $kind) json_out(['ok'=>false,'error'=>'BAD_TOKEN_KIND','need'=>$kind,'got'=>$tk], 401);
  }
  return $payload;
}

function auth_require_account(): array {
  return auth_require_token('acc');
}

function auth_require_player(): array {
  return auth_require_token('play');
}

function auth_token_debug_info(array $payload): array {
  return [
    'v' => $payload['v'] ?? null,
    't' => $payload['t'] ?? null,
    'account_id' => $payload['account_id'] ?? null,
    'player_id' => $payload['player_id'] ?? null,
    'slot' => $payload['slot'] ?? null,
    'iat' => $payload['iat'] ?? null,
    'exp' => $payload['exp'] ?? null,
  ];
}
