<?php
// excel_viewer_api.php
// ✅ 좌측 트리용 디렉토리/파일 리스트 JSON API

declare(strict_types=1);
@date_default_timezone_set('Asia/Seoul');
session_start();

require_once __DIR__ . '/config/dp_config.php';
require_once __DIR__ . '/lib/auth_guard.php';
dp_auth_guard();
require_once __DIR__ . '/lib/excel_viewer_lib.php';

header('Content-Type: application/json; charset=UTF-8');

$op = $_GET['op'] ?? 'list';

if ($op === 'roots') {
    $roots = ev_allowed_roots();
    $out = [];
    foreach ($roots as $k => $abs) {
        $out[] = [
            'name' => $k,
            'key'  => ev_b64u_enc($k),
        ];
    }
    echo json_encode(['ok' => true, 'roots' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($op !== 'list') {
    echo json_encode(['ok' => false, 'err' => 'invalid op'], JSON_UNESCAPED_UNICODE);
    exit;
}

$p = (string)($_GET['p'] ?? '');
if ($p === '') {
    echo json_encode(['ok' => false, 'err' => 'missing p'], JSON_UNESCAPED_UNICODE);
    exit;
}

$resolved = ev_resolve_path($p);
if (!$resolved['ok']) {
    echo json_encode(['ok' => false, 'err' => $resolved['err'] ?? 'resolve failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$abs = $resolved['abs'];
if (!is_dir($abs)) {
    echo json_encode(['ok' => false, 'err' => 'not a directory'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 숨김/시스템 폴더 기본 제외
$denyNames = ['.git', '.svn', '.vs', 'node_modules'];

$items = [];
try {
    $dh = opendir($abs);
    if ($dh === false) throw new RuntimeException('opendir failed');
    while (($name = readdir($dh)) !== false) {
        if ($name === '.' || $name === '..') continue;
        if (in_array($name, $denyNames, true)) continue;
        if ($name !== '' && $name[0] === '.') continue;
        $full = $abs . DIRECTORY_SEPARATOR . $name;

        // 루트키/상대경로로 다시 만들어서 encode
        $relBase = $resolved['rel'] ?? '';
        $relChild = $relBase . '/' . $name;
        $enc = ev_b64u_enc($relChild);

        if (is_dir($full)) {
            $items[] = [
                'type' => 'dir',
                'name' => $name,
                'p'    => $enc,
            ];
        } else {
            if (!ev_is_excel_file($full)) continue; // ✅ 엑셀만 노출
            $items[] = [
                'type' => 'file',
                'name' => $name,
                'p'    => $enc,
                'size' => @filesize($full) ?: 0,
                'mtime'=> @filemtime($full) ?: 0,
            ];
        }
    }
    closedir($dh);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'err' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// 정렬: 폴더 먼저, 이름순
usort($items, function($a, $b){
    if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
    return strnatcasecmp((string)$a['name'], (string)$b['name']);
});

echo json_encode([
    'ok' => true,
    'items' => $items,
    'rel' => $resolved['rel'] ?? '',
], JSON_UNESCAPED_UNICODE);
