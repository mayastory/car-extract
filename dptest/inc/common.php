<?php
// inc/common.php : 세션/헬퍼/URL/로그인 체크 공용
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/dp_config.php';
require_once __DIR__ . '/../lib/auth_guard.php';

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('col')) {
    function col(array $row, string $key): string {
        return h($row[$key] ?? '');
    }
}

if (!function_exists('dp_base_url')) {
    // 예: /DPTest/shipinglist -> /DPTest/
    function dp_base_url(): string {
    // Rename-safe base path resolver.
    // - If you deploy under /jtmes, /dptest, /anything : returns "/<folder>/"
    // - If deployed at domain root: returns "/"
    // - You can override by defining DP_BASE_URL (with trailing slash recommended)
    if (defined('DP_BASE_URL')) return (string)DP_BASE_URL;

    // Prefer REQUEST_URI (real browser path) over SCRIPT_NAME (can be /public/... with rewrites)
    $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    $uriPath = str_replace('\\', '/', $uriPath);
    $parts = array_values(array_filter(explode('/', trim($uriPath, '/')), 'strlen'));
    $seg0 = $parts[0] ?? '';

    // If first segment is a known internal directory, we are probably running at web-root.
    $internal = ['public','legacy','Module','module','lib','inc','assets','config','pages'];
    if ($seg0 === '' || in_array($seg0, $internal, true)) return '/';

    // If DOCUMENT_ROOT is known and the segment exists as a directory, treat it as app base.
    $doc = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($doc !== '') {
        $cand = rtrim(str_replace('\\', '/', $doc), '/') . '/' . $seg0;
        if (is_dir($cand)) return '/' . $seg0 . '/';
    }

    // Fallback to SCRIPT_NAME first segment (legacy behavior)
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $script = str_replace('\\', '/', $script);
    $parts2 = array_values(array_filter(explode('/', trim($script, '/')), 'strlen'));
    $segS = $parts2[0] ?? '';
    if ($segS !== '' && !in_array($segS, $internal, true)) return '/' . $segS . '/';

    return '/';
}
}
if (!function_exists('dp_url')) {
    function dp_url(string $path): string {
        $base = dp_base_url();
        $path = trim($path);
        if ($path === '') return $base;
        if (preg_match('#^(https?:)?//#i', $path)) return $path;
        if ($path[0] === '/') return $path;
        return $base . $path;
    }
}
if (!function_exists('dp_require_login')) {
    function dp_require_login(): void {
        if (empty($_SESSION['ship_user_id'])) {
            header('Location: ' . dp_url('index'));
            exit;
        }
        // ✅ 단일 세션(동시접속 차단) 검사 (로그인 상태라면 토큰 일치 필수)
        if (function_exists('dp_auth__validate_single_session') && !dp_auth__validate_single_session()) {
            if (function_exists('dp_auth__force_logout_redirect')) {
                dp_auth__force_logout_redirect();
            } else {
                header('Location: ' . dp_url('index') . '?kicked=1');
                exit;
            }
        }
    }
}
?>