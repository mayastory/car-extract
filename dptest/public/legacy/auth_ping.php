<?php
// auth_ping.php : 단일세션(동시접속 차단) 상태 확인용 (AJAX)
// URL: /<APP>/auth_ping.php  (root .htaccess가 public/legacy로 라우팅)

session_start();
require_once __DIR__ . '/../../Module/bootstrap.php';
require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';

header('Content-Type: application/json; charset=utf-8');

function dp_auth_ping__base(): string {
    $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    $uriPath = str_replace('\\', '/', $uriPath);
    $parts = array_values(array_filter(explode('/', trim($uriPath, '/')), 'strlen'));
    $seg0 = $parts[0] ?? '';
    $internal = ['public','legacy','Module','module','lib','inc','assets','config','pages'];
    if ($seg0 === '' || in_array($seg0, $internal, true)) return '';
    return '/' . $seg0;
}

$base = dp_auth_ping__base();
$login = ($base !== '' ? $base : '') . '/index';

if (empty($_SESSION['ship_user_id'])) {
    echo json_encode(['ok'=>false,'reason'=>'nologin','login'=>$login], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (function_exists('dp_auth__validate_single_session') && !dp_auth__validate_single_session()) {
    // 세션 강제 종료(리다이렉트 없이)
    if (function_exists('dp_auth__logout_local')) {
        dp_auth__logout_local();
    } else {
        $_SESSION = [];
        @session_destroy();
    }
    echo json_encode(['ok'=>false,'reason'=>'kicked','login'=>$login.'?kicked=1'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
