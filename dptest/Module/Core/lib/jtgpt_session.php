<?php
if (!function_exists('jtgpt_session_init')) {
    function jtgpt_session_init(): void {
        if (!isset($_SESSION['__jtgpt']) || !is_array($_SESSION['__jtgpt'])) {
            $_SESSION['__jtgpt'] = ['history' => [], 'state' => []];
        }
        if (!isset($_SESSION['__jtgpt']['history']) || !is_array($_SESSION['__jtgpt']['history'])) {
            $_SESSION['__jtgpt']['history'] = [];
        }
        if (!isset($_SESSION['__jtgpt']['state']) || !is_array($_SESSION['__jtgpt']['state'])) {
            $_SESSION['__jtgpt']['state'] = [];
        }
    }
}

if (!function_exists('jtgpt_session_history')) {
    function jtgpt_session_history(int $limit = 12): array {
        jtgpt_session_init();
        $hist = $_SESSION['__jtgpt']['history'];
        if ($limit > 0 && count($hist) > $limit) {
            $hist = array_slice($hist, -$limit);
        }
        return array_values($hist);
    }
}

if (!function_exists('jtgpt_session_push')) {
    function jtgpt_session_push(string $role, string $text, array $meta = []): void {
        jtgpt_session_init();
        $_SESSION['__jtgpt']['history'][] = [
            'role' => $role,
            'text' => $text,
            'meta' => $meta,
            'ts'   => time(),
        ];
        if (count($_SESSION['__jtgpt']['history']) > 24) {
            $_SESSION['__jtgpt']['history'] = array_slice($_SESSION['__jtgpt']['history'], -24);
        }
    }
}

if (!function_exists('jtgpt_session_state')) {
    function jtgpt_session_state(): array {
        jtgpt_session_init();
        return $_SESSION['__jtgpt']['state'];
    }
}

if (!function_exists('jtgpt_session_get')) {
    function jtgpt_session_get(string $key, $default = null) {
        jtgpt_session_init();
        return array_key_exists($key, $_SESSION['__jtgpt']['state']) ? $_SESSION['__jtgpt']['state'][$key] : $default;
    }
}

if (!function_exists('jtgpt_session_set')) {
    function jtgpt_session_set(string $key, $value): void {
        jtgpt_session_init();
        $_SESSION['__jtgpt']['state'][$key] = $value;
    }
}

if (!function_exists('jtgpt_session_merge_state')) {
    function jtgpt_session_merge_state(array $patch): void {
        jtgpt_session_init();
        foreach ($patch as $k => $v) {
            $_SESSION['__jtgpt']['state'][$k] = $v;
        }
    }
}
