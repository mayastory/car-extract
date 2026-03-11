<?php
// logout.php
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

// ✅ pretty url 우선 (리라이트 있으면 /index 로 깔끔)
// ✅ 혹시 리라이트가 안 먹어도 index.php는 항상 존재하니까 안전하게 2단계 처리
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // /DPTest
$pretty = $base . '/index';
$real   = $base . '/index.php';

header('Location: ' . $pretty);
exit;
