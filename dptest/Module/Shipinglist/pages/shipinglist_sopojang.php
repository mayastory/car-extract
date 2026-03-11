<?php
// 소포장내역 (요약형 / 엑셀식 블록)
// - ShipingList 목록 필터(from_date~to_date, ship_to, part_name 등) 연동
// - 모달(embed=1)용 본문 페이지

if (!defined('JTMES_ROOT')) {
    $cands = [
        realpath(dirname(__DIR__, 3) ?: ''),
        realpath(dirname(__DIR__, 2) ?: ''),
        realpath(dirname(__DIR__, 1) ?: ''),
        realpath(__DIR__),
    ];
    foreach ($cands as $cand) {
        if ($cand && is_dir($cand . '/config')) {
            define('JTMES_ROOT', $cand);
            break;
        }
    }
    if (!defined('JTMES_ROOT')) {
        define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
    }
}

date_default_timezone_set('Asia/Seoul');
session_start();
require_once JTMES_ROOT . '/config/dp_config.php';

if (empty($_SESSION['ship_user_id'])) {
    header('Location: index.php');
    exit;
}

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

function normalize_date_ymd_sp(?string $s): string {
    $s = trim((string)($s ?? ''));
    if ($s === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^\d{8}$/', $s)) {
        return substr($s,0,4) . '-' . substr($s,4,2) . '-' . substr($s,6,2);
    }
    if (preg_match('/^\d{6}-\d{2}-\d{2}$/', $s)) {
        return substr($s,0,4) . '-' . substr($s,4,2) . '-' . substr($s,6,2);
    }
    return '';
}

function ymd_valid_sp(string $v): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return $dt && $dt->format('Y-m-d') === $v;
}

function parse_cavity_sp($v): ?int {
    if ($v === null) return null;
    if (is_int($v)) return ($v >= 1 && $v <= 8) ? $v : null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (!preg_match('/(\d+)/', $s, $m)) return null;
    $n = (int)$m[1];
    return ($n >= 1 && $n <= 8) ? $n : null;
}

function fmt_qty_sp($n): string {
    return number_format((int)$n);
}

function kday_short_sp(string $ymd): string {
    static $days = ['일','월','화','수','목','금','토'];
    $ts = strtotime($ymd);
    if ($ts === false) return '';
    return $days[(int)date('w', $ts)] ?? '';
}

function md_kor_sp(string $ymd): string {
    if (!ymd_valid_sp($ymd)) return $ymd;
    return date('m/d', strtotime($ymd)) . '(' . kday_short_sp($ymd) . ')';
}

function period_label_sp(string $fromDate, string $toDate): string {
    if ($fromDate !== '' && $toDate !== '' && $fromDate !== $toDate) {
        return md_kor_sp($fromDate) . ' ~ ' . md_kor_sp($toDate);
    }
    if ($toDate !== '') return md_kor_sp($toDate);
    if ($fromDate !== '') return md_kor_sp($fromDate);
    return '-';
}

function norm_part_key_sp(string $partName): string {
    $s = strtoupper(trim($partName));
    $s = preg_replace('/\s+/', ' ', $s ?? '');
    if ($s === '') return '';
    // alias 정리
    if (strpos($s, 'MEM-') !== 0 && preg_match('/^(IR-BASE|X-CARRIER|Y-CARRIER|Z-CARRIER|Z-STOPPER)\b/', $s, $m)) {
        $s = 'MEM-' . $m[1];
    }
    if (str_contains($s, 'Y-CARRIER DAMPERLESS')) return 'MEM-Y-CARRIER';
    if (str_contains($s, 'MEM-Y-CARRIER')) return 'MEM-Y-CARRIER';
    if (str_contains($s, 'MEM-IR-BASE')) return 'MEM-IR-BASE';
    if (str_contains($s, 'MEM-X-CARRIER')) return 'MEM-X-CARRIER';
    if (str_contains($s, 'MEM-Z-CARRIER')) return 'MEM-Z-CARRIER';
    if (str_contains($s, 'MEM-Z-STOPPER')) return 'MEM-Z-STOPPER';
    return $s;
}

$PART_SPECS = [
    'MEM-IR-BASE'   => ['label' => 'IR-BASE',   'cav_map' => [1=>1,2=>2,3=>3,4=>4]],
    'MEM-X-CARRIER' => ['label' => 'X-CARRIER', 'cav_map' => [1=>1,2=>2,3=>3,4=>4]],
    'MEM-Y-CARRIER' => ['label' => 'Y-CARRIER', 'cav_map' => [1=>1,2=>2,3=>3,4=>4]],
    'MEM-Z-CARRIER' => ['label' => 'Z-CARRIER', 'cav_map' => [5=>1,6=>2,7=>3,8=>4,1=>1,2=>2,3=>3,4=>4]],
    'MEM-Z-STOPPER' => ['label' => 'Z-STOPPER', 'cav_map' => [5=>1,6=>2,7=>3,8=>4,1=>1,2=>2,3=>3,4=>4]],
];
$PART_ORDER = array_keys($PART_SPECS);

try {
    $pdo = dp_get_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="background:#1f1f1f;color:#eee;font-family:sans-serif;padding:16px;">DB 접속 실패: '.h($e->getMessage()).'</body>';
    exit;
}

$today = date('Y-m-d');
$fromDate = normalize_date_ymd_sp($_GET['from_date'] ?? '');
$toDate   = normalize_date_ymd_sp($_GET['to_date'] ?? '');
if (!ymd_valid_sp($fromDate)) $fromDate = $today;
if (!ymd_valid_sp($toDate))   $toDate = $today;

$filterShipTo  = trim((string)($_GET['ship_to'] ?? ''));
$filterPackBc  = trim((string)($_GET['pack_bc'] ?? ''));
$filterSmallNo = trim((string)($_GET['small_no'] ?? ''));
$filterTrayNo  = trim((string)($_GET['tray_no'] ?? ''));
$filterPname   = trim((string)($_GET['part_name'] ?? ''));
$embed         = (($_GET['embed'] ?? '') === '1');

$__spExportParams = $_GET;
unset($__spExportParams['subtab']);
$__spExportParams['from_date'] = $fromDate;
$__spExportParams['to_date']   = $toDate;
$__spExportQuery = http_build_query($__spExportParams);
$exportHrefShipSummary = 'shipinglist_sopojang_export.php' . ($__spExportQuery !== '' ? ('?' . $__spExportQuery . '&view=ship-summary') : '?view=ship-summary');
$exportHrefSopojangList = 'shipinglist_sopojang_export.php' . ($__spExportQuery !== '' ? ('?' . $__spExportQuery . '&view=sopojang-list') : '?view=sopojang-list');

$where = [];
$params = [];

$fromDt = $fromDate . ' 00:00:00';
$toDt   = date('Y-m-d 00:00:00', strtotime($toDate . ' +1 day'));
$where[] = 'ship_datetime >= :from_dt';
$where[] = 'ship_datetime < :to_dt';
$params[':from_dt'] = $fromDt;
$params[':to_dt'] = $toDt;

if ($filterShipTo !== '') { $where[] = 'ship_to LIKE :ship_to'; $params[':ship_to'] = '%' . $filterShipTo . '%'; }
if ($filterPackBc !== '') { $where[] = 'pack_barcode LIKE :pack_bc'; $params[':pack_bc'] = '%' . $filterPackBc . '%'; }
if ($filterSmallNo !== '') { $where[] = 'small_pack_no LIKE :small_no'; $params[':small_no'] = '%' . $filterSmallNo . '%'; }
if ($filterTrayNo !== '') { $where[] = 'tray_no LIKE :tray_no'; $params[':tray_no'] = '%' . $filterTrayNo . '%'; }
if ($filterPname !== '') { $where[] = 'part_name LIKE :part_name'; $params[':part_name'] = '%' . $filterPname . '%'; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        part_name,
        revision,
        cavity,
        SUM(qty) AS total_qty
    FROM ShipingList
    {$whereSql}
    GROUP BY TRIM(part_name), TRIM(revision), cavity
";
$st = $pdo->prepare($sql);
$st->execute($params);
$dbRows = $st->fetchAll(PDO::FETCH_ASSOC);

$blocks = [];
foreach ($PART_ORDER as $pk) {
    $blocks[$pk] = [
        'key' => $pk,
        'label' => $PART_SPECS[$pk]['label'],
        'rows' => [],   // tool => [1=>0..4=>0]
        'col_ttl' => [1=>0,2=>0,3=>0,4=>0],
        'grand_total' => 0,
        'ship_to' => $filterShipTo,
    ];
}

$shipToDetected = [];
foreach ($dbRows as $r) {
    $pk = norm_part_key_sp((string)($r['part_name'] ?? ''));
    if (!isset($blocks[$pk])) continue;

    $tool = trim((string)($r['revision'] ?? ''));
    if ($tool === '') $tool = '-';
    $tool = strtoupper($tool);

    $rawCav = parse_cavity_sp($r['cavity'] ?? null);
    if ($rawCav === null) continue;
    $dispCav = (int)($PART_SPECS[$pk]['cav_map'][$rawCav] ?? 0);
    if ($dispCav < 1 || $dispCav > 4) continue;

    $qty = (int)($r['total_qty'] ?? 0);
    if ($qty <= 0) continue;

    if (!isset($blocks[$pk]['rows'][$tool])) {
        $blocks[$pk]['rows'][$tool] = [1=>0,2=>0,3=>0,4=>0];
    }
    $blocks[$pk]['rows'][$tool][$dispCav] += $qty;
    $blocks[$pk]['col_ttl'][$dispCav] += $qty;
    $blocks[$pk]['grand_total'] += $qty;
}

// 납품처 표시를 위해 실제 단일 ship_to 대표값 1개 추출 (필터가 비어있을 때)
if ($filterShipTo === '') {
    $sqlShipTo = "SELECT ship_to, SUM(qty) q FROM ShipingList {$whereSql} GROUP BY ship_to ORDER BY q DESC LIMIT 1";
    try {
        $st2 = $pdo->prepare($sqlShipTo);
        $st2->execute($params);
        $topShipTo = trim((string)($st2->fetchColumn() ?? ''));
        foreach ($blocks as &$b) { $b['ship_to'] = $topShipTo; }
        unset($b);
    } catch (Throwable $e) {
        // ignore
    }
}

// 정렬: 영문/숫자 자연정렬 + 마지막 '-' 뒤로
$toolSort = function(string $a, string $b): int {
    if ($a === '-' && $b !== '-') return 1;
    if ($b === '-' && $a !== '-') return -1;
    $na = preg_match('/^\d+$/', $a) ? ('#' . str_pad($a, 8, '0', STR_PAD_LEFT)) : ('~' . $a);
    $nb = preg_match('/^\d+$/', $b) ? ('#' . str_pad($b, 8, '0', STR_PAD_LEFT)) : ('~' . $b);
    return strnatcasecmp($na, $nb);
};

$renderBlocks = [];
foreach ($PART_ORDER as $pk) {
    $b = $blocks[$pk];
    if ((int)$b['grand_total'] <= 0) continue; // ✅ 사용자 기준: 출하 없는 모델은 숨김
    $tools = array_keys($b['rows']);
    usort($tools, $toolSort);
    $sortedRows = [];
    foreach ($tools as $t) {
        $vals = $b['rows'][$t];
        $sortedRows[] = [
            'tool' => $t,
            'c1' => (int)($vals[1] ?? 0),
            'c2' => (int)($vals[2] ?? 0),
            'c3' => (int)($vals[3] ?? 0),
            'c4' => (int)($vals[4] ?? 0),
            'ttl' => (int)($vals[1] ?? 0) + (int)($vals[2] ?? 0) + (int)($vals[3] ?? 0) + (int)($vals[4] ?? 0),
        ];
    }
    $b['rows'] = $sortedRows;
    $renderBlocks[] = $b;
}

$periodLabel = period_label_sp($fromDate, $toDate);
$etaLabel = $periodLabel;
$shipToLabel = trim((string)($filterShipTo !== '' ? $filterShipTo : ($renderBlocks[0]['ship_to'] ?? '')));
if ($shipToLabel === '') $shipToLabel = 'LGIT'; // 기존 화면 느낌 fallback

function pack_part_code_sp(?string $packBarcode): string {
    $s = trim((string)($packBarcode ?? ''));
    if ($s === '') return '';
    $parts = explode('/', $s, 2);
    return trim((string)($parts[0] ?? ''));
}

// =========================================================
// 출하 요약(품명/품번/차수/출하수량/박스) 집계
// - 품번: pack_barcode 앞부분(/ 이전)
// - 박스: pack_barcode 우선, 없으면 small_pack_no 기준 distinct count
// =========================================================
$SUMMARY_PART_ROW_ORDER = [
    'MEM-IR-BASE'   => ['B','C','F','G','H','L','M'],
    'MEM-X-CARRIER' => ['A','B','C','D','E','F','G','H','J','L'],
    'MEM-Y-CARRIER' => ['A','B','H','J','K'],
    'MEM-Z-CARRIER' => ['A','E','F','H','K','P','Q'],
    'MEM-Z-STOPPER' => ['A','B','D','E'],
];

$summaryGroups = [];
foreach ($PART_ORDER as $pk) {
    $summaryGroups[$pk] = [
        'key'        => $pk,
        'label'      => $PART_SPECS[$pk]['label'],
        'part_code'  => '',
        'departure_no' => '',
        'rows_map'   => [], // rev => ['rev','qty','box']
        'rows'       => [],
        'total_qty'  => 0,
        'total_box'  => 0,
    ];
}

$sqlSummary = "
    SELECT
        part_name,
        revision,
        SUM(qty) AS total_qty,
        COUNT(DISTINCT NULLIF(TRIM(COALESCE(NULLIF(pack_barcode,''), small_pack_no)), '')) AS box_count,
        MIN(NULLIF(TRIM(pack_barcode), '')) AS sample_pack_barcode
    FROM ShipingList
    {$whereSql}
    GROUP BY TRIM(part_name), TRIM(revision)
";
try {
    $stS = $pdo->prepare($sqlSummary);
    $stS->execute($params);
    $summaryRowsDb = $stS->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $summaryRowsDb = [];
}

foreach ($summaryRowsDb as $r) {
    $pk = norm_part_key_sp((string)($r['part_name'] ?? ''));
    if (!isset($summaryGroups[$pk])) continue;

    $rev = strtoupper(trim((string)($r['revision'] ?? '')));
    if ($rev === '') $rev = '-';

    $qty = (int)($r['total_qty'] ?? 0);
    $box = (int)($r['box_count'] ?? 0);
    if ($qty <= 0 && $box <= 0) continue;

    if (!isset($summaryGroups[$pk]['rows_map'][$rev])) {
        $summaryGroups[$pk]['rows_map'][$rev] = ['rev' => $rev, 'qty' => 0, 'box' => 0];
    }
    $summaryGroups[$pk]['rows_map'][$rev]['qty'] += $qty;
    $summaryGroups[$pk]['rows_map'][$rev]['box'] += $box;

    $summaryGroups[$pk]['total_qty'] += $qty;
    $summaryGroups[$pk]['total_box'] += $box;

    if ($summaryGroups[$pk]['part_code'] === '') {
        $pc = pack_part_code_sp((string)($r['sample_pack_barcode'] ?? ''));
        if ($pc !== '') $summaryGroups[$pk]['part_code'] = $pc;
    }
}

$summaryRenderGroups = [];
foreach ($PART_ORDER as $pk) {
    $g = $summaryGroups[$pk];
    if ((int)$g['total_qty'] <= 0) continue; // 출하 없는 모델 숨김

    $ordered = [];
    $seenRev = [];
    foreach (($SUMMARY_PART_ROW_ORDER[$pk] ?? []) as $rv) {
        if (isset($g['rows_map'][$rv])) {
            $ordered[] = $g['rows_map'][$rv];
            $seenRev[$rv] = true;
        }
    }
    $remain = [];
    foreach ($g['rows_map'] as $rv => $row) {
        if (!isset($seenRev[$rv])) $remain[] = $row;
    }
    if ($remain) {
        usort($remain, function($a, $b) use ($toolSort) {
            return $toolSort((string)($a['rev'] ?? ''), (string)($b['rev'] ?? ''));
        });
        foreach ($remain as $rr) $ordered[] = $rr;
    }

    $g['rows'] = $ordered;
    $summaryRenderGroups[] = $g;
}

// 상단 요약줄 (기간/모델별 EA/모델별 BOX) - 내부 탭 공통 표시
$topSummaryQtyParts = [];
$topSummaryBoxParts = [];
foreach ($PART_ORDER as $pk) {
    $g = $summaryGroups[$pk] ?? null;
    if (!$g) continue;
    $tq = (int)($g['total_qty'] ?? 0);
    if ($tq <= 0) continue; // 출하 있는 모델만
    $tb = (int)($g['total_box'] ?? 0);
    $topSummaryQtyParts[] = $pk . ' : ' . fmt_qty_sp($tq) . ' EA';
    $topSummaryBoxParts[] = $pk . ' : ' . number_format($tb) . ' 박스';
}
$topSummaryQtyText = $topSummaryQtyParts ? implode(' | ', $topSummaryQtyParts) : '-';
$topSummaryBoxText = $topSummaryBoxParts ? implode(' | ', $topSummaryBoxParts) : '-';

$hasAnyData = (!empty($renderBlocks) || !empty($summaryRenderGroups));
$defaultSubTab = 'ship-summary';

?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>소포장내역</title>
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{
    margin:0;
    background:<?= $embed ? '#202124' : '#f3f4f6' ?>;
    color:#111;
    font-family:Arial,"Malgun Gothic","맑은 고딕",sans-serif;
    font-size:12px;
}
.page{
    min-height:100%;
    padding:<?= $embed ? '10px 10px 14px' : '16px' ?>;
    overflow:auto;
}
.page-title{
    margin:0 0 10px;
    font-size:14px;
    font-weight:700;
    color:#e8eaed;
}

.sp-top-summary{
    margin:0 0 10px;
    color:#dfe3e7;
    white-space:nowrap;
    overflow-x:auto;
    overflow-y:hidden;
    padding:2px 2px 8px;
    line-height:1.34;
    border-bottom:1px solid #34383d;
}
.sp-top-summary .row1{ color:#cfd8dc; }
.sp-top-summary .row2{ color:#e8eaed; }
.sp-top-summary .row3{ color:#b9d9ff; }

/* 내부 탭 (출하 요약 / 소포장 내역) */
.sp-subtabs{
    display:flex;
    gap:4px;
    margin:0 0 10px;
}
.sp-subtab{
    appearance:none;
    border:1px solid #2b313a;
    background:linear-gradient(180deg,#272c35 0%,#1f2430 100%);
    color:#e8eaed;
    font-weight:700;
    font-size:12px;
    padding:8px 14px;
    border-radius:6px 6px 0 0;
    cursor:pointer;
    line-height:1;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06);
}
.sp-subtab:hover{ filter:brightness(1.05); }
.sp-subtab.active{
    border-color:#2e7d32;
    background:linear-gradient(180deg,#1f5530 0%,#1a4628 100%);
    color:#fff;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.14), 0 0 0 1px rgba(46,125,50,.16);
}
.sp-subpanel{ display:none; }
.sp-subpanel.active{ display:block; }

/* 기존 소포장내역 블록 */
.blocks{
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    gap:12px;
    min-width:560px;
}
.block{
    display:inline-block;
    width:auto;
    max-width:100%;
    background:#fff;
    border:1px solid #b6bcc4;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
}
.tbl{
    width:auto;
    border-collapse:collapse;
    table-layout:fixed;
    background:#fff;
}
.tbl td, .tbl th{
    border:1px solid #adb3bb;
    padding:4px 6px;
    line-height:1.25;
}
.tbl .center{text-align:center}
.tbl .num{text-align:right; font-variant-numeric: tabular-nums;}
.tbl .bold{font-weight:700}
.tbl .header1 td{
    background:#dbe5f1;
    font-weight:700;
    text-align:center;
}
.tbl .header2 td.part{
    background:#fff2cc;
    font-weight:700;
    text-align:left;
}
.tbl .header2 td.meta{
    background:#f7f7f7;
    font-weight:700;
    text-align:center;
}
.tbl .cols th{
    background:#ddebf7;
    font-weight:700;
}
.tbl .cols th.tool-col{
    background:#e2f0d9;
}
.tbl td.tool-cell{
    background:#f3fbef;
    font-weight:700;
    text-align:center;
}
.tbl tr.ttl-row td{
    background:#eef1f5;
    font-weight:700;
}
.tbl tr.ttl-row td:first-child{
    background:#ddebf7;
}
.tbl td.empty{
    color:#666;
    text-align:center;
}
.no-data{
    background:#15181c;
    border:1px dashed #4f5761;
    color:#eef2f6;
    padding:18px;
    text-align:center;
    border-radius:6px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.03);
}
/* 열폭 */
.w-tool{width:78px}
.w-cav{width:112px}
.w-ttl{width:124px}

/* embed 모드 대비: 바깥 어두운 배경과 간격 */
body[data-embed="1"] .block{ border-radius:4px; overflow:hidden; }

/* 출하 요약 표 */
.shipsum-wrap{
    display:inline-block;
    width:auto;
    max-width:100%;
    min-width:0;
    background:#fff;
    border:1px solid #b6bcc4;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
    overflow:auto;
}
.shipsum{
    width:auto;
    border-collapse:collapse;
    table-layout:fixed;
    background:#fff;
}
.shipsum th, .shipsum td{
    border:2px solid #202020;
    padding:4px 6px;
    line-height:1.25;
    font-size:12px;
}
.shipsum th{
    background:#cfe8f5;
    text-align:center;
    font-weight:700;
}
.shipsum td{
    background:#fff;
}
.shipsum td.center{text-align:center}
.shipsum td.num{text-align:right; font-variant-numeric: tabular-nums;}
.shipsum td.part,
.shipsum td.partcode,
.shipsum td.depart{
    font-weight:700;
    text-align:center;
    vertical-align:top;
    padding-top:14px;
    background:#fff;
}
.shipsum td.rev{
    font-weight:700;
    text-align:center;
    width:70px;
}
.shipsum tr.total-row td.sum-label,
.shipsum tr.total-row td.sum-qty,
.shipsum tr.total-row td.sum-box{
    background:#ffff00;
    font-weight:700;
}
.shipsum .w-part{width:88px}
.shipsum .w-partcode{width:96px}
.shipsum .w-dep{width:132px}
.shipsum .w-rev{width:68px}
.shipsum .w-qty{width:76px}
.shipsum .w-box{width:56px}

@media (max-width: 860px){
    .w-cav{width:92px}
    .w-ttl{width:100px}
    .shipsum .w-part{width:78px}
    .shipsum .w-partcode{width:88px}
    .shipsum .w-dep{width:110px}
}

/* compact override (v10) */
.sp-subpanel .shipsum-wrap,
.sp-subpanel .block{ max-width:100%; }
.sp-subpanel .shipsum th,
.sp-subpanel .shipsum td{ padding:3px 5px; }

.sp-export-row{display:flex;justify-content:flex-end;gap:8px;margin:0 0 8px 0;}
.sp-export-btn{display:inline-flex;align-items:center;padding:6px 10px;border-radius:8px;border:1px solid #b53b3b;background:#8f2323;color:#fff;text-decoration:none;font-weight:700;line-height:1;box-shadow:inset 0 1px 0 rgba(255,255,255,.08);}
.sp-export-btn:hover{background:#a52a2a;border-color:#cf4a4a;color:#fff;}
.sp-export-btn:active{transform:translateY(1px);}
</style>
</head>
<body data-embed="<?= $embed ? '1' : '0' ?>">
<div class="page">
    <?php if (!$embed): ?>
        <h1 class="page-title">소포장내역</h1>
    <?php endif; ?>

    <div class="sp-subtabs" role="tablist" aria-label="소포장내역 내부 탭">
        <button type="button" class="sp-subtab<?= $defaultSubTab === 'ship-summary' ? ' active' : '' ?>" data-sp-panel="ship-summary" aria-selected="<?= $defaultSubTab === 'ship-summary' ? 'true' : 'false' ?>">출하 요약</button>
        <button type="button" class="sp-subtab<?= $defaultSubTab === 'sopojang-blocks' ? ' active' : '' ?>" data-sp-panel="sopojang-blocks" aria-selected="<?= $defaultSubTab === 'sopojang-blocks' ? 'true' : 'false' ?>">소포장 내역</button>
    </div>

    <div class="sp-top-summary">
        <div class="row1">기간: <?= h($fromDate) ?> ~ <?= h($toDate) ?></div>
        <div class="row2"><?= h($topSummaryQtyText) ?></div>
        <div class="row3"><?= h($topSummaryBoxText) ?></div>
    </div>

    <section class="sp-subpanel<?= $defaultSubTab === 'ship-summary' ? ' active' : '' ?>" data-sp-panel-id="ship-summary">
        <div class="sp-export-row">
            <a class="sp-export-btn" href="<?= h($exportHrefShipSummary) ?>" target="_blank" rel="noopener">엑셀 출력</a>
        </div>
        <?php if (!$summaryRenderGroups): ?>
            <div class="no-data">해당 조건의 출하 데이터가 없습니다. (출하 있는 모델만 표시됩니다)</div>
        <?php else: ?>
            <div class="shipsum-wrap">
                <table class="shipsum">
                    <colgroup>
                        <col class="w-part"><col class="w-partcode"><col class="w-dep"><col class="w-rev"><col class="w-qty"><col class="w-box">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>품명</th>
                            <th>품번</th>
                            <th>Departure no</th>
                            <th>차수</th>
                            <th>출하수량</th>
                            <th>박스</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($summaryRenderGroups as $g):
                        $lineCount = count($g['rows']) + 1; // 합계행 포함
                        $first = true;
                    ?>
                        <?php foreach ($g['rows'] as $r): ?>
                            <tr>
                                <?php if ($first): ?>
                                    <td class="part" rowspan="<?= (int)$lineCount ?>"><?= h($g['label']) ?></td>
                                    <td class="partcode" rowspan="<?= (int)$lineCount ?>"><?= h($g['part_code'] !== '' ? $g['part_code'] : '-') ?></td>
                                    <td class="depart" rowspan="<?= (int)$lineCount ?>"><?= h($g['departure_no'] !== '' ? $g['departure_no'] : '') ?></td>
                                    <?php $first = false; ?>
                                <?php endif; ?>
                                <td class="rev"><?= h($r['rev']) ?></td>
                                <td class="num"><?= h(fmt_qty_sp((int)$r['qty'])) ?></td>
                                <td class="num"><?= h(number_format((int)$r['box'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td class="rev sum-label">합계</td>
                            <td class="num sum-qty"><?= h(fmt_qty_sp((int)$g['total_qty'])) ?></td>
                            <td class="num sum-box"><?= h(number_format((int)$g['total_box'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="sp-subpanel<?= $defaultSubTab === 'sopojang-blocks' ? ' active' : '' ?>" data-sp-panel-id="sopojang-blocks">
        <div class="sp-export-row">
            <a class="sp-export-btn" href="<?= h($exportHrefSopojangList) ?>" target="_blank" rel="noopener">엑셀 출력</a>
        </div>
        <?php if (!$renderBlocks): ?>
            <div class="no-data">해당 조건의 출하 데이터가 없습니다. (출하 있는 모델만 표시됩니다)</div>
        <?php else: ?>
        <div class="blocks">
            <?php foreach ($renderBlocks as $b): ?>
                <section class="block">
                    <table class="tbl">
                        <colgroup>
                            <col class="w-tool"><col class="w-cav"><col class="w-cav"><col class="w-cav"><col class="w-cav"><col class="w-ttl">
                        </colgroup>
                        <tr class="header1">
                            <td><?= h($periodLabel) ?></td>
                            <td><?= h($shipToLabel) ?></td>
                            <td colspan="2">출하 수량</td>
                            <td colspan="2">(완료)</td>
                        </tr>
                        <tr class="header2">
                            <td class="part"><?= h($b['label']) ?></td>
                            <td class="meta">ETA :</td>
                            <td class="meta" colspan="2"><?= h($etaLabel) ?></td>
                            <td class="meta" colspan="2"><?= h($shipToLabel) ?></td>
                        </tr>
                        <tr class="cols">
                            <th class="tool-col center">Tool</th>
                            <th class="center">Cav 1</th>
                            <th class="center">Cav 2</th>
                            <th class="center">Cav 3</th>
                            <th class="center">Cav 4</th>
                            <th class="center">TTL</th>
                        </tr>
                        <?php foreach ($b['rows'] as $r): ?>
                            <tr>
                                <td class="tool-cell"><?= h($r['tool']) ?></td>
                                <?php foreach ([1,2,3,4] as $ci): $v = (int)$r['c'.$ci]; ?>
                                    <td class="<?= $v>0 ? 'num' : 'empty' ?>"><?= $v>0 ? h(fmt_qty_sp($v)) : '-' ?></td>
                                <?php endforeach; ?>
                                <td class="num bold"><?= h(fmt_qty_sp($r['ttl'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="ttl-row">
                            <td class="center">TTL</td>
                            <td class="num"><?= h(fmt_qty_sp((int)$b['col_ttl'][1])) ?></td>
                            <td class="num"><?= h(fmt_qty_sp((int)$b['col_ttl'][2])) ?></td>
                            <td class="num"><?= h(fmt_qty_sp((int)$b['col_ttl'][3])) ?></td>
                            <td class="num"><?= h(fmt_qty_sp((int)$b['col_ttl'][4])) ?></td>
                            <td class="num"><?= h(fmt_qty_sp((int)$b['grand_total'])) ?></td>
                        </tr>
                    </table>
                </section>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</div>

<script>
(function(){
    const tabs = Array.from(document.querySelectorAll('.sp-subtab'));
    const panels = Array.from(document.querySelectorAll('.sp-subpanel'));
    function activate(id){
        tabs.forEach(btn => {
            const on = btn.getAttribute('data-sp-panel') === id;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(p => p.classList.toggle('active', p.getAttribute('data-sp-panel-id') === id));
    }
    tabs.forEach(btn => btn.addEventListener('click', () => activate(btn.getAttribute('data-sp-panel'))));
})();
</script>
</body>
</html>
