<?php
// logout.php (modules-refactor v3 compatible)
session_start();

// 세션 비우기
$_SESSION = [];

// 세션 쿠키 제거
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

/*
  기존 코드(루트에서 실행될 때)는 dirname(SCRIPT_NAME) 기준으로 /index 로 보냈는데,
  v3 cleanroot에서는 logout.php가 /public/legacy/ 아래에 있어서 base가 /public/legacy 로 잡히며
  /public/legacy/index (존재하지 않음) 으로 리다이렉트되어 404가 났음.

  해결:
  - /public/legacy 경로에서 실행되는 경우, base에서 /public/legacy 를 제거해서 프로젝트 루트로 복귀
  - /public 에서 실행되는 경우도 base에서 /public 제거
*/

$dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$base = $dir;

// v3 cleanroot paths
if (preg_match('#/public/legacy$#', $base)) {
    $base = preg_replace('#/public/legacy$#', '', $base);
} elseif (preg_match('#/public$#', $base)) {
    $base = preg_replace('#/public$#', '', $base);
}

// fallback: if somehow empty, go to /
if ($base === '') { $base = '/'; }

// pretty url 우선 (/index)
$pretty = rtrim($base, '/\\') . '/index';
header('Location: ' . $pretty);
exit;
