<?php

declare(strict_types=1);

if (!defined('JTMES_ROOT')) {
    define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

session_start();
require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();
require_once JTMES_ROOT . '/vendor/autoload.php';
require_once __DIR__ . '/shipinglist_file_report_lib.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

function sfr_json_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sfr_preview_can_build(PDO $pdo): bool {
    if (!empty($_SESSION['dp_admin_id'])) return true;

    $no = function_exists('dp_auth__session_user_no') ? dp_auth__session_user_no() : null;
    $id = function_exists('dp_auth__session_user_id')
        ? trim((string)(dp_auth__session_user_id() ?? ''))
        : trim((string)($_SESSION['ship_user_id'] ?? ''));

    try {
        if ($no) {
            $st = $pdo->prepare("SELECT `role`,`lv` FROM `account` WHERE `No`=:v LIMIT 1");
            $st->execute([':v' => (int)$no]);
        } elseif ($id !== '') {
            $st = $pdo->prepare("SELECT `role`,`lv` FROM `account` WHERE `ID`=:v LIMIT 1");
            $st->execute([':v' => $id]);
        } else {
            return false;
        }
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return strtolower(trim((string)($r['role'] ?? ''))) === 'admin' || (int)($r['lv'] ?? 0) >= 2;
    } catch (Throwable $e) {
        return false;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sfr_json_error('POST 요청만 허용됩니다.', 405);
}

try {
    $pdo = dp_get_pdo();
} catch (Throwable $e) {
    sfr_json_error('DB 접속에 실패했습니다.', 500);
}

if (!sfr_preview_can_build($pdo)) {
    sfr_json_error('권한이 없습니다. lv 2 이상만 성적서 생성이 가능합니다.', 403);
}

if (!isset($_FILES['shipping_file']) || !is_array($_FILES['shipping_file'])) {
    sfr_json_error('출하 엑셀 파일을 선택해 주세요.');
}

$f = $_FILES['shipping_file'];
if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    sfr_json_error('파일 업로드에 실패했습니다.');
}

$filename = (string)($f['name'] ?? '');
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($ext, ['xls', 'xlsx'], true)) {
    sfr_json_error('xls 또는 xlsx 파일만 선택할 수 있습니다.');
}

$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    sfr_json_error('업로드 파일을 확인할 수 없습니다.');
}

$fromDate = trim((string)($_POST['from_date'] ?? ''));
$toDate   = trim((string)($_POST['to_date'] ?? ''));
$shipTo   = trim((string)($_POST['ship_to'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    sfr_json_error('조회기간을 확인해 주세요.');
}

// 파일명 전체를 하드코딩하지 않고 LGIT / Jahwa 표식만 사용한다.
// 둘 다 있거나 둘 다 없으면 현재 납품처 선택값을 그대로 유지한다.
$detected = sfr_detect_ship_to_from_filename($filename);
if ($detected !== '') $shipTo = $detected;
if ($shipTo === '') sfr_json_error('납품처를 선택해 주세요.');

try {
    $reader = IOFactory::createReaderForFile($tmp);
    if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
    $book = $reader->load($tmp);
} catch (Throwable $e) {
    sfr_json_error('엑셀 파일을 읽을 수 없습니다.', 400);
}

$headerFound = false;
$sheetCandidates = [];
try {
    foreach ($book->getWorksheetIterator() as $sheet) {
        $matrix = $sheet->toArray(null, true, true, false);
        $parsed = sfr_extract_sheet_rows($matrix, 30);
        if (empty($parsed['header_found'])) continue;
        $headerFound = true;
        $sheetCandidates[] = [
            'sheet' => $sheet->getTitle(),
            'rows' => (array)($parsed['rows'] ?? []),
            'headers' => (array)($parsed['headers'] ?? []),
        ];
    }
} finally {
    if (isset($book) && method_exists($book, 'disconnectWorksheets')) $book->disconnectWorksheets();
    unset($book, $reader);
}

if (!$headerFound) {
    sfr_json_error('엑셀에서 「포장번호」 컬럼을 찾지 못했습니다.');
}

$chosen = sfr_choose_sheet_candidate($sheetCandidates);
$fileRows = (array)($chosen['rows'] ?? []);
$headers = (array)($chosen['headers'] ?? []);
$chosenSheet = (string)($chosen['sheet'] ?? '');

if (!$fileRows) {
    sfr_json_error('「포장번호」 컬럼에 출하 데이터가 없습니다.');
}
if (($headers['qty'] ?? null) === null) {
    sfr_json_error('포장번호가 있는 시트에서 출하수량 컬럼(총납품수량/출고수량/출하수량)을 찾지 못했습니다.');
}

$packNos = [];
foreach ($fileRows as $r) {
    $p = sfr_normalize_pack_no($r['pack_no'] ?? '');
    if ($p !== '') $packNos[$p] = true;
}
$packNos = array_keys($packNos);
if (!$packNos) sfr_json_error('유효한 포장번호가 없습니다.');

$cutoff = '08:30:00';
$fromTs = strtotime($fromDate . ' ' . $cutoff . ' -1 day');
$toTs   = strtotime($toDate   . ' ' . $cutoff);
if ($fromTs === false || $toTs === false || $fromTs >= $toTs) {
    sfr_json_error('조회기간이 올바르지 않습니다.');
}
$fromDt = date('Y-m-d H:i:s', $fromTs);
$toDt   = date('Y-m-d H:i:s', $toTs);

$dbRows = [];
try {
    // 너무 긴 IN 절을 피하려고 미리보기 조회만 300개씩 나눈다.
    foreach (array_chunk($packNos, 300) as $chunkIndex => $chunk) {
        [$packSql, $packParams] = sfr_selection_sql($chunk, 'pv' . $chunkIndex . '_');
        $sql = "
            SELECT pack_barcode, pack_no, TRIM(part_name) AS part_name, qty
            FROM ShipingList
            WHERE ship_datetime >= :from_dt
              AND ship_datetime <  :to_dt
              AND ship_to = :ship_to
              {$packSql}
            ORDER BY id
        ";
        $params = array_merge([
            ':from_dt' => $fromDt,
            ':to_dt' => $toDt,
            ':ship_to' => $shipTo,
        ], $packParams);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) $dbRows[] = $r;
    }
} catch (Throwable $e) {
    sfr_json_error('출하 DB 대조 중 오류가 발생했습니다.', 500);
}

$preview = sfr_build_preview($fileRows, $dbRows);
$canConfirm = !empty($preview['rows'])
    && empty($preview['unmatched_pack_nos'])
    && empty($preview['ambiguous_pack_nos'])
    && (int)($preview['totals']['diff'] ?? 0) === 0;

foreach ($preview['rows'] as $r) {
    if (empty($r['match'])) {
        $canConfirm = false;
        break;
    }
}

$token = '';
if ($canConfirm) {
    try {
        $token = sfr_selection_save([
            'pack_nos' => (array)($preview['matched_pack_nos'] ?? $packNos),
            'ship_to' => $shipTo,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'filename' => $filename,
        ]);
    } catch (Throwable $e) {
        sfr_json_error('파일선택 정보를 저장할 수 없습니다.', 500);
    }
}

echo json_encode([
    'ok' => true,
    'filename' => $filename,
    'selected_sheet' => $chosenSheet,
    'ship_to' => $shipTo,
    'detected_ship_to' => $detected,
    'selection_token' => $token,
    'can_confirm' => $canConfirm,
    'preview' => $preview,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
