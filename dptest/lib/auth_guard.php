<?php
// lib/auth_guard.php
// ✅ 로그인/권한 가드 + 단일 세션(동시접속 차단)
// - 로그인 시 DB(account.session_token)과 세션(ship_session_token)이 일치해야 통과
// - 다른 기기/브라우저에서 로그인하면 DB 토큰이 갱신되어 기존 세션은 다음 요청부터 자동 로그아웃

declare(strict_types=1);

if (!function_exists('dp_auth__app_base')) {
    // returns "" or "/JTMES" (no trailing slash)
    function dp_auth__app_base(): string {
        $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
        $uriPath = str_replace('\\', '/', $uriPath);
        $parts = array_values(array_filter(explode('/', trim($uriPath, '/')), 'strlen'));
        $seg0 = $parts[0] ?? '';

        $internal = ['public','legacy','Module','module','lib','inc','assets','config','pages'];
        if ($seg0 === '' || in_array($seg0, $internal, true)) return '';

        return '/' . $seg0;
    }
}

if (!function_exists('dp_auth__login_url')) {
    function dp_auth__login_url(array $params = []): string {
        $base = dp_auth__app_base();
        $url = ($base !== '' ? $base : '') . '/index'; // pretty url (rewrite)
        if (!empty($params)) {
            $qs = http_build_query($params);
            $url .= (strpos($url, '?') === false ? '?' : '&') . $qs;
        }
        return $url;
    }
}

if (!function_exists('dp_auth__logout_local')) {
    function dp_auth__logout_local(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
    }
}

if (!function_exists('dp_auth__session_user_no')) {
    function dp_auth__session_user_no(): ?int {
        foreach (['ship_user_no','user_no','dp_user_no','account_no','no','No'] as $k) {
            if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) return (int)$_SESSION[$k];
        }
        return null;
    }
}

if (!function_exists('dp_auth__session_user_id')) {
    function dp_auth__session_user_id(): ?string {
        foreach (['ship_user_id','user_id','userid','username','id','ID'] as $k) {
            if (!empty($_SESSION[$k])) return (string)$_SESSION[$k];
        }
        return null;
    }
}

if (!function_exists('dp_auth__session_token')) {
    function dp_auth__session_token(): string {
        foreach (['ship_session_token','session_token','dp_session_token'] as $k) {
            if (!empty($_SESSION[$k])) return (string)$_SESSION[$k];
        }
        return '';
    }
}

if (!function_exists('dp_auth__validate_single_session')) {
    function dp_auth__validate_single_session(): bool {
        if (!function_exists('dp_get_pdo')) return true;

        $no = dp_auth__session_user_no();
        $id = dp_auth__session_user_id();
        if ($no === null && ($id === null || $id === '')) return true;

        $sessToken = dp_auth__session_token();

        try {
            $pdo = dp_get_pdo();
            if ($no !== null) {
                $st = $pdo->prepare('SELECT session_token FROM `account` WHERE No = :no LIMIT 1');
                $st->execute([':no' => $no]);
            } else {
                $st = $pdo->prepare('SELECT session_token FROM `account` WHERE ID = :id LIMIT 1');
                $st->execute([':id' => $id]);
            }
            $dbToken = $st->fetchColumn();
        } catch (Throwable $e) {
            // 컬럼/테이블 없거나 DB 오류면 기능 비활성처럼 통과
            return true;
        }

        $dbToken = is_string($dbToken) ? trim($dbToken) : '';
        if ($dbToken === '') return true; // DB 토큰 없으면 기능 미사용으로 간주

        if ($sessToken === '') return false;

        return hash_equals($dbToken, $sessToken);
    }
}

if (!function_exists('dp_auth__force_logout_redirect')) {
    function dp_auth__force_logout_redirect(): void {
        dp_auth__logout_local();
        $to = dp_auth__login_url(['kicked' => 1]);
        if (!headers_sent()) {
            header('Location: ' . $to);
            exit;
        }
        echo "<script>location.href=" . json_encode($to, JSON_UNESCAPED_SLASHES) . ";</script>";
        exit;
    }
}

if (!function_exists('dp_auth_is_logged_in')) {
    function dp_auth_is_logged_in(): bool {
        $keys = [
            'ship_user_id',
            'dp_admin_id',
            'user_id',
            'userid',
            'user',
            'username',
            'logged_in',
            'is_login',
            'is_logged_in',
        ];
        foreach ($keys as $k) {
            if (!empty($_SESSION[$k])) return true;
        }
        return false;
    }
}

if (!function_exists('dp_auth_guard')) {
    function dp_auth_guard(): void {
        // ✅ 기존 공용 로그인 강제 함수가 있으면 먼저 실행(미로그인 시 여기서 redirect/exit)
        if (function_exists('dp_require_login')) {
            try { dp_require_login(); } catch (Throwable $e) {}
            // dp_require_login 이 exit 하지 않고 돌아왔다면 "로그인 상태"라는 뜻이므로 이어서 단일세션 검사도 수행
        }

        if (dp_auth_is_logged_in()) {
            if (!dp_auth__validate_single_session()) {
                dp_auth__force_logout_redirect();
            }
            return;
        }

        // 미로그인: 로그인 페이지로
        $redir = $_SERVER['REQUEST_URI'] ?? '';
        $to = dp_auth__login_url(['redirect' => $redir]);
        if (!headers_sent()) {
            header('Location: ' . $to);
            exit;
        }
        echo "<script>location.href=" . json_encode($to, JSON_UNESCAPED_SLASHES) . ";</script>";
        exit;
    }
}
