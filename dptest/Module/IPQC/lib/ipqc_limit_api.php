<?php
// IPQC Limit API
// - Update USL/LSL for a given (type, part_name, key) by updating result tables.
// - Labels/keys are used as-is (NO trim/normalization/parsing).

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);
if (!headers_sent()) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
if (!ob_get_level()) { ob_start(); }

set_error_handler(function($severity, $message, $file, $line){
  throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function(){
  $err = error_get_last();
  if (!$err) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array($err['type'], $fatalTypes, true)) return;
  while (ob_get_level()) { @ob_end_clean(); }
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode([
    'ok' => 0,
    'err' => 'FATAL',
    'message' => $err['message'],
    'file' => $err['file'],
    'line' => $err['line'],
  ], JSON_UNESCAPED_UNICODE);
});

require_once __DIR__ . '/../../../config/dp_config.php';

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
require_once __DIR__ . '/../../../lib/auth_guard.php';

function jx($ok, $payload = [], $code = 200) {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => $ok ? 1 : 0], $payload), JSON_UNESCAPED_UNICODE);
  exit;
}

function ipqc_api_is_logged_in(): bool {
  foreach (['dp_auth_is_logged_in','dp_is_logged_in','is_logged_in'] as $fn) {
    if (function_exists($fn)) {
      try { if ((bool)call_user_func($fn)) return true; } catch (Throwable $e) {}
    }
  }
  foreach (['dp_auth_user','dp_current_user','current_user','dp_get_login_user'] as $fn) {
    if (function_exists($fn)) {
      try {
        $u = call_user_func($fn);
        if (is_array($u) ? count($u) > 0 : !empty($u)) return true;
      } catch (Throwable $e) {}
    }
  }
  $keys = ['ship_user_id','ship_user_name','user','user_id','uid','username','login','login_user','member','admin','auth','dp_user','dp_user_id'];
  foreach ($keys as $k) {
    if (!isset($_SESSION[$k])) continue;
    $v = $_SESSION[$k];
    if (is_array($v)) { if (count($v) > 0) return true; }
    else if ($v !== '' && $v !== 0 && $v !== '0' && $v !== null) return true;
  }
  return false;
}

function num_or_null($v) {
  if ($v === null) return null;
  $s = (string)$v;
  $s = str_replace(',', '', $s);
  $s = trim($s);
  if ($s === '') return null;
  if (!is_numeric($s)) return null;
  return (float)$s;
}

try {
  if (!ipqc_api_is_logged_in()) {
    jx(false, ['err' => 'AUTH', 'message' => '로그인이 필요합니다.'], 401);
  }

  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jx(false, ['err' => 'METHOD', 'message' => 'POST만 지원합니다.'], 405);
  }

  $type = strtoupper((string)($_POST['type'] ?? ''));
  $part = (string)($_POST['part_name'] ?? ($_POST['model'] ?? ''));
  $key  = (string)($_POST['key'] ?? ($_POST['fai'] ?? ($_POST['point_no'] ?? '')));

  if ($type === '' || $part === '' || $key === '') {
    jx(false, ['err' => 'PARAM', 'message' => 'type, part_name, key 파라미터가 필요합니다.'], 400);
  }

  $usl = num_or_null($_POST['usl'] ?? null);
  $lsl = num_or_null($_POST['lsl'] ?? null);

  // Table map
  $headerTable = '';
  $resTable = '';
  $keyCol = '';

  if ($type === 'OMM') {
    $headerTable = 'ipqc_omm_header';
    $resTable = 'ipqc_omm_result';
    $keyCol = 'fai';
  } elseif ($type === 'CMM') {
    $headerTable = 'ipqc_cmm_header';
    $resTable = 'ipqc_cmm_result';
    $keyCol = 'point_no';
  } elseif ($type === 'AOI') {
    $headerTable = 'ipqc_aoi_header';
    $resTable = 'ipqc_aoi_result';
    $keyCol = 'fai';
  } else {
    jx(false, ['err' => 'TYPE', 'message' => '지원하지 않는 type 입니다. (OMM/CMM/AOI)'], 400);
  }

  $pdo = dp_get_pdo();

  // Optional tool filter: tools (comma separated)
  $tools = (string)($_POST['tools'] ?? '');
  $toolsList = [];
  if ($tools !== '') {
    foreach (preg_split('/\s*,\s*/', $tools) as $t) {
      if ($t === '') continue;
      $toolsList[] = $t;
    }
    $toolsList = array_values(array_unique($toolsList));
  }

  $sql = "UPDATE {$resTable} r JOIN {$headerTable} h ON h.id=r.header_id\n".
         "SET r.usl=:usl, r.lsl=:lsl\n".
         "WHERE h.part_name=:part AND r.{$keyCol}=:key";

  $params = [
    ':usl' => $usl,
    ':lsl' => $lsl,
    ':part' => $part,
    ':key' => $key,
  ];

  if (!empty($toolsList)) {
    // NOTE: PDO(MySQL) does NOT allow mixing named and positional parameters.
    // Use named placeholders for the IN() list to avoid SQLSTATE[HY093].
    $phs = [];
    $i = 0;
    foreach ($toolsList as $t) {
      $ph = ':tool' . $i;
      $phs[] = $ph;
      $i++;
    }
    $sql .= " AND h.tool IN (" . implode(',', $phs) . ")";
  }

  $st = $pdo->prepare($sql);
  // bind named first
  $st->bindValue(':usl', $usl, $usl === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
  $st->bindValue(':lsl', $lsl, $lsl === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
  $st->bindValue(':part', $part, PDO::PARAM_STR);
  $st->bindValue(':key', $key, PDO::PARAM_STR);

  if (!empty($toolsList)) {
    $i = 0;
    foreach ($toolsList as $t) {
      $st->bindValue(':tool' . $i, $t, PDO::PARAM_STR);
      $i++;
    }
  }

  $st->execute();
$aff = (int)$st->rowCount();

  jx(true, ['affected' => $aff, 'type' => $type, 'key' => $key, 'usl' => $usl, 'lsl' => $lsl]);

} catch (Throwable $e) {
  jx(false, ['err' => 'EX', 'message' => $e->getMessage()], 500);
}
