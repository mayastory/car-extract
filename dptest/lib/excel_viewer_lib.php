<?php
// lib/excel_viewer_lib.php
// ✅ Excel Viewer 공용 유틸

declare(strict_types=1);

if (!function_exists('ev_h')) {
    function ev_h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * base64url encode
 */
function ev_b64u_enc(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * base64url decode
 */
function ev_b64u_dec(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

/**
 * 허용 루트 디렉토리 (DPTest 기준)
 * - 여기에 있는 폴더만 트리로 노출/접근 허용
 */
function ev_allowed_roots(): array {
    $base = realpath(__DIR__ . '/..');
    // DPTest 루트가 realpath 실패하면 fallback
    if (!$base) $base = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');

    $candidates = [
        'exports',
        'oqc templates',
        'templates',
        'var',
    ];

    $roots = [];
    foreach ($candidates as $rel) {
        $p = realpath($base . DIRECTORY_SEPARATOR . $rel);
        if ($p && is_dir($p)) {
            $roots[$rel] = $p;
        }
    }
    return $roots;
}

/**
 * 요청된 상대경로를 절대경로로 안전하게 변환
 * @return array{ok:bool, abs?:string, err?:string, rel?:string, rootKey?:string}
 */
function ev_resolve_path(string $encodedRel): array {
    $rel = trim(ev_b64u_dec($encodedRel));
    $rel = str_replace(['\\', '\0'], ['/', ''], $rel);
    $rel = ltrim($rel, '/');

    // rootKey/.. 형태로 오길 기대
    $parts = array_values(array_filter(explode('/', $rel), fn($x) => $x !== ''));
    if (!$parts) return ['ok' => false, 'err' => '경로가 비어있음'];

    $rootKey = array_shift($parts);
    $roots = ev_allowed_roots();
    if (!isset($roots[$rootKey])) {
        return ['ok' => false, 'err' => '허용되지 않은 루트'];
    }
    $rootAbs = $roots[$rootKey];

    $joined = $rootAbs;
    foreach ($parts as $seg) {
        if ($seg === '.' || $seg === '') continue;
        if ($seg === '..') return ['ok' => false, 'err' => '상위 이동 금지'];
        // 위험 문자 차단
        if (preg_match('/[<>:"|?*]/', $seg)) return ['ok' => false, 'err' => '경로 문자 오류'];
        $joined .= DIRECTORY_SEPARATOR . $seg;
    }

    $real = realpath($joined);
    // 파일이 아직 없으면 realpath가 false일 수 있어 디렉토리 기준으로 검증
    if ($real === false) {
        $parent = realpath(dirname($joined));
        if (!$parent || strpos($parent, $rootAbs) !== 0) {
            return ['ok' => false, 'err' => '경로 확인 실패'];
        }
        $real = $joined;
    }

    if (strpos($real, $rootAbs) !== 0) {
        return ['ok' => false, 'err' => '루트 밖 접근 금지'];
    }

    return ['ok' => true, 'abs' => $real, 'rel' => $rel, 'rootKey' => $rootKey];
}

/**
 * 엑셀 파일 확장자 허용
 */
function ev_is_excel_file(string $path): bool {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['xlsx', 'xlsm', 'xls'], true);
}
