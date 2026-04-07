<?php
require_once __DIR__ . '/../config.php';

function token_secret(): string {
  $c = cfg();
  if (isset($c['token_secret']) && is_string($c['token_secret']) && $c['token_secret'] !== '') {
    return $c['token_secret'];
  }
  $env = getenv('POKE_TOKEN_SECRET');
  if ($env !== false && is_string($env) && $env !== '') {
    return $env;
  }
  // DEV default. Please change in api/config.local.php.
  return 'dev_secret_change_me';
}

function b64url_enc(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_dec(string $s): string {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  $out = base64_decode($s, true);
  return $out === false ? '' : $out;
}

function sign_token(array $payload, int $ttl_sec = 86400): string {
  $header = ['alg' => 'HS256', 'typ' => 'JWT'];
  $now = time();
  if (!isset($payload['iat'])) $payload['iat'] = $now;
  if (!isset($payload['exp'])) $payload['exp'] = $now + $ttl_sec;

  $h = b64url_enc(json_encode($header, JSON_UNESCAPED_UNICODE));
  $p = b64url_enc(json_encode($payload, JSON_UNESCAPED_UNICODE));
  $sig = hash_hmac('sha256', $h . '.' . $p, token_secret(), true);
  return $h . '.' . $p . '.' . b64url_enc($sig);
}

function verify_token(string $token): ?array {
  $parts = explode('.', $token);
  if (count($parts) !== 3) return null;
  [$h, $p, $s] = $parts;

  $sig = b64url_dec($s);
  if ($sig === '') return null;

  $expected = hash_hmac('sha256', $h . '.' . $p, token_secret(), true);
  if (!hash_equals($expected, $sig)) return null;

  $payload = json_decode(b64url_dec($p), true);
  if (!is_array($payload)) return null;
  if (isset($payload['exp']) && time() > intval($payload['exp'])) return null;
  return $payload;
}
