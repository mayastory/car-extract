<?php
// 소포장 출하 엑셀(.xls) 출력 (템플릿 기반, 값 주입 전용)
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/../../../config/dp_config.php';
require_once __DIR__ . '/../../../lib/auth_guard.php';
dp_auth_guard();

function sp_export_json_error(string $msg, int $code = 500): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $msg;
    exit;
}

function sp_export_parse_date(?string $v, string $fallback): string {
    $v = trim((string)$v);
    if ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    if ($v !== '' && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) return $m[1] . '-' . $m[2] . '-' . $m[3];
    return $fallback;
}

function sp_export_ymd_valid(string $v): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return $dt && $dt->format('Y-m-d') === $v;
}

function sp_export_kr_weekday(string $ymd): string {
    $ts = strtotime($ymd . ' 00:00:00');
    static $arr = ['일','월','화','수','목','금','토'];
    return ($ts !== false) ? ($arr[(int)date('w', $ts)] ?? '') : '';
}

function sp_export_korean_date_label(string $ymd): string {
    if (!sp_export_ymd_valid($ymd)) return $ymd;
    return date('m/d', strtotime($ymd)) . '(' . sp_export_kr_weekday($ymd) . ')';
}

function sp_export_period_label(string $fromDate, string $toDate): string {
    if ($fromDate !== '' && $toDate !== '' && $fromDate !== $toDate) {
        return sp_export_korean_date_label($fromDate) . ' ~ ' . sp_export_korean_date_label($toDate);
    }
    return sp_export_korean_date_label($toDate !== '' ? $toDate : $fromDate);
}

function sp_export_range_sheet_title(string $fromDate, string $toDate): string {
    $title = ($fromDate === $toDate) ? $fromDate : ($fromDate . ' ~ ' . $toDate);
    $title = strtr($title, ['\\'=>'-','/'=>'-','*'=>'-','?'=>'-',':'=>'-','['=>'-',']'=>'-']);
    if ($title === '') $title = 'Sheet1';
    if (function_exists('mb_substr')) return (string)mb_substr($title, 0, 31, 'UTF-8');
    return (string)substr($title, 0, 31);
}

function sp_export_mem_sheet_name(string $projectCode): string {
    $projectCode = strtoupper(trim($projectCode));
    if ($projectCode === '') $projectCode = 'MEM';
    return $projectCode . ' 소포장 내역';
}

function sp_export_parse_pack_barcode(?string $packBarcode): array {
    $s = trim((string)$packBarcode);
    if ($s === '') return ['part_code' => '', 'departure_no' => '', 'seq' => ''];
    $parts = explode('/', str_replace('\\', '/', $s));
    return [
        'part_code' => trim((string)($parts[0] ?? '')),
        'departure_no' => trim((string)($parts[1] ?? '')),
        'seq' => trim((string)($parts[2] ?? '')),
    ];
}

function sp_export_pick(array $row, array $keys, $default = '') {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') return $row[$k];
    }
    return $default;
}

function sp_export_norm_part_key_web(string $partName): string {
    $s = strtoupper(trim($partName));
    $s = preg_replace('/\s+/', ' ', $s ?? '');
    if ($s === '') return '';
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

function sp_export_norm_part_name(?string $name): ?string {
    $s = strtoupper(trim((string)$name));
    if ($s === '') return null;
    $web = sp_export_norm_part_key_web($s);
    if (!in_array($web, ['MEM-IR-BASE','MEM-X-CARRIER','MEM-Y-CARRIER','MEM-Z-CARRIER','MEM-Z-STOPPER'], true)) return null;
    return str_replace('MEM-', '', $web);
}

function sp_export_parse_cavity($v): ?int {
    if ($v === null) return null;
    if (is_int($v)) return ($v >= 1 && $v <= 8) ? $v : null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (!preg_match('/(\d+)/', $s, $m)) return null;
    $n = (int)$m[1];
    return ($n >= 1 && $n <= 8) ? $n : null;
}

function sp_export_find_template_file(string $rootDir): ?string {
    $candidates = [];
    foreach ((glob($rootDir . '/Templates/Sopojang Templates/*.xls') ?: []) as $f) $candidates[] = $f;
    foreach ((glob($rootDir . '/*X종*출하*.xls') ?: []) as $f) $candidates[] = $f;
    if (!$candidates) return null;
    usort($candidates, static function($a, $b) {
        $pa = basename((string)$a); $pb = basename((string)$b);
        $sa = (strpos($pa, 'YYMMDD_PROJECT') !== false ? 0 : 1);
        $sb = (strpos($pb, 'YYMMDD_PROJECT') !== false ? 0 : 1);
        if ($sa !== $sb) return $sa <=> $sb;
        return strcmp($pa, $pb);
    });
    return $candidates[0] ?? null;
}

function sp_export_part_no(string $part): string {
    // 하드코딩 매핑 금지: legacy fallback 함수는 빈값 반환(실제 주입은 raw 데이터 기반으로 처리)
    return '';
}

function sp_export_row_customer_part_no(array $row): string {
    $v = trim((string)sp_export_pick($row, ['customer_part_no','cust_part_no','customer_pn'], ''));
    if ($v !== '') return $v;
    $pb = (string)sp_export_pick($row, ['pack_barcode','package_barcode','barcode','pbarcode'], '');
    $parsed = sp_export_parse_pack_barcode($pb);
    $pc = trim((string)($parsed['part_code'] ?? ''));
    return $pc;
}

function sp_export_model_display_for_note(string $part): string {
    $p = strtoupper(trim($part));
    return match ($p) {
        'IR-BASE', 'MEM-IR-BASE' => 'IR BASE',
        'X-CARRIER', 'MEM-X-CARRIER' => 'X Carrier',
        'Y-CARRIER', 'MEM-Y-CARRIER' => 'Y Carrier',
        'Z-CARRIER', 'MEM-Z-CARRIER' => 'Z Carrier',
        'Z-STOPPER', 'MEM-Z-STOPPER' => 'Z Stopper',
        default => str_replace('-', ' ', preg_replace('/^MEM-/', '', $p) ?? $p),
    };
}


function sp_export_set_cell_value_by_col_row($ws, int $col, int $row, $value): void {
    $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
    $ws->setCellValue($coord, $value);
}

function sp_export_clear_range($ws, string $range): void {
    [$a, $b] = explode(':', $range);
    preg_match('/^([A-Z]+)(\d+)$/', $a, $ma);
    preg_match('/^([A-Z]+)(\d+)$/', $b, $mb);
    if (!$ma || !$mb) return;
    $c1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ma[1]);
    $c2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($mb[1]);
    $r1 = (int)$ma[2]; $r2 = (int)$mb[2];
    for ($r = $r1; $r <= $r2; $r++) {
        for ($c = $c1; $c <= $c2; $c++) {
            sp_export_set_cell_value_by_col_row($ws, $c, $r, null);
        }
    }
}

function sp_export_find_sheet_by_title_like($spreadsheet, array $patterns) {
    foreach ($spreadsheet->getWorksheetIterator() as $ws) {
        $t = (string)$ws->getTitle();
        foreach ($patterns as $p) {
            if ($p !== '' && mb_strpos($t, $p) !== false) return $ws;
        }
    }
    return null;
}

// ========== 입력 파라미터 ==========
$today = date('Y-m-d');
$fromDate = sp_export_parse_date($_GET['from_date'] ?? '', $today);
$toDate   = sp_export_parse_date($_GET['to_date'] ?? '', $fromDate);
if ($fromDate > $toDate) { $tmp = $fromDate; $fromDate = $toDate; $toDate = $tmp; }
$shipToFilter  = trim((string)($_GET['ship_to'] ?? ''));
$filterPackBc  = trim((string)($_GET['pack_bc'] ?? ''));
$filterSmallNo = trim((string)($_GET['small_no'] ?? ''));
$filterTrayNo  = trim((string)($_GET['tray_no'] ?? ''));
$filterPname   = trim((string)($_GET['part_name'] ?? ''));
$projectCodeReq = strtoupper(trim((string)($_GET['project'] ?? 'MEM')));
if ($projectCodeReq === '' || !preg_match('/^[A-Z0-9_-]+$/', $projectCodeReq)) $projectCodeReq = 'MEM';

$startDate = $fromDate; // 기존 변수명 호환 (에러 방지)
$endDate   = $toDate;   // 기존 변수명 호환 (에러 방지)
$from      = $fromDate;
$to        = $toDate;

// ========== DB ==========
$pdo = dp_get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ShipingList 날짜 컬럼 호환 (ship_datetime 우선)
$shipDateCol = 'ship_datetime';
try {
    $cols = $pdo->query('SHOW COLUMNS FROM ShipingList')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $colNames = array_map(static fn($x) => (string)($x['Field'] ?? ''), $cols);
    if (!in_array('ship_datetime', $colNames, true)) {
        if (in_array('ship_date', $colNames, true)) $shipDateCol = 'ship_date';
        elseif (in_array('created_at', $colNames, true)) $shipDateCol = 'created_at';
    }
} catch (Throwable $e) {
    // ignore
}

$where = [];
$params = [];
$where[] = "{$shipDateCol} >= :from_dt";
$where[] = "{$shipDateCol} < :to_dt";
$params[':from_dt'] = $fromDate . ' 00:00:00';
$params[':to_dt']   = date('Y-m-d 00:00:00', strtotime($toDate . ' +1 day'));
if ($shipToFilter !== '')  { $where[] = 'ship_to LIKE :ship_to';      $params[':ship_to'] = '%' . $shipToFilter . '%'; }
if ($filterPackBc !== '')  { $where[] = 'pack_barcode LIKE :pack_bc';  $params[':pack_bc'] = '%' . $filterPackBc . '%'; }
if ($filterSmallNo !== '') { $where[] = 'small_pack_no LIKE :small_no';$params[':small_no'] = '%' . $filterSmallNo . '%'; }
if ($filterTrayNo !== '')  { $where[] = 'tray_no LIKE :tray_no';       $params[':tray_no'] = '%' . $filterTrayNo . '%'; }
if ($filterPname !== '')   { $where[] = 'part_name LIKE :part_name';   $params[':part_name'] = '%' . $filterPname . '%'; }
$whereSql = implode(' AND ', $where);

$PART_SPECS = [
    'MEM-IR-BASE'   => ['label' => 'IR-BASE',   'cav_map' => [1=>1,2=>2,3=>3,4=>4]],
    'MEM-X-CARRIER' => ['label' => 'X-CARRIER', 'cav_map' => [1=>1,2=>2,3=>3,4=>4]],
    'MEM-Y-CARRIER' => ['label' => 'Y-CARRIER', 'cav_map' => [1=>1,2=>2,3=>3,4=>4]],
    'MEM-Z-CARRIER' => ['label' => 'Z-CARRIER', 'cav_map' => [5=>1,6=>2,7=>3,8=>4,1=>1,2=>2,3=>3,4=>4]],
    'MEM-Z-STOPPER' => ['label' => 'Z-STOPPER', 'cav_map' => [5=>1,6=>2,7=>3,8=>4,1=>1,2=>2,3=>3,4=>4]],
];
$PART_ORDER_WEB = array_keys($PART_SPECS); // MEM-* keys (웹 기준)
$SUMMARY_PART_ROW_ORDER = [
    'MEM-IR-BASE'   => ['B','C','F','G','H','L','M'],
    'MEM-X-CARRIER' => ['A','B','C','D','E','F','G','H','J','L'],
    'MEM-Y-CARRIER' => ['A','B','H','J','K'],
    'MEM-Z-CARRIER' => ['A','E','F','H','K','P','Q'],
    'MEM-Z-STOPPER' => ['A','B','D','E'],
];
$PART_ORDER = ['IR-BASE','X-CARRIER','Y-CARRIER','Z-CARRIER','Z-STOPPER'];

// 1) 웹 탭용 소포장 블록 집계 (웹과 동일 구조)
$sqlMatrix = "
    SELECT part_name, revision, cavity, SUM(qty) AS total_qty
    FROM ShipingList
    WHERE {$whereSql}
    GROUP BY TRIM(part_name), TRIM(revision), cavity
";
$st = $pdo->prepare($sqlMatrix);
$st->execute($params);
$dbRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$blocks = [];
foreach ($PART_ORDER_WEB as $pk) {
    $blocks[$pk] = [
        'key' => $pk,
        'label' => $PART_SPECS[$pk]['label'],
        'rows' => [],
        'col_ttl' => [1=>0,2=>0,3=>0,4=>0],
        'grand_total' => 0,
        'ship_to' => $shipToFilter,
    ];
}
foreach ($dbRows as $r) {
    $pk = sp_export_norm_part_key_web((string)($r['part_name'] ?? ''));
    if (!isset($blocks[$pk])) continue;
    $tool = strtoupper(trim((string)($r['revision'] ?? '')));
    if ($tool === '') $tool = '-';
    $rawCav = sp_export_parse_cavity($r['cavity'] ?? null);
    if ($rawCav === null) continue;
    $dispCav = (int)($PART_SPECS[$pk]['cav_map'][$rawCav] ?? 0);
    if ($dispCav < 1 || $dispCav > 4) continue;
    $qty = (int)($r['total_qty'] ?? 0);
    if ($qty <= 0) continue;
    if (!isset($blocks[$pk]['rows'][$tool])) $blocks[$pk]['rows'][$tool] = [1=>0,2=>0,3=>0,4=>0];
    $blocks[$pk]['rows'][$tool][$dispCav] += $qty;
    $blocks[$pk]['col_ttl'][$dispCav] += $qty;
    $blocks[$pk]['grand_total'] += $qty;
}

if ($shipToFilter === '') {
    try {
        $st2 = $pdo->prepare("SELECT ship_to, SUM(qty) q FROM ShipingList WHERE {$whereSql} GROUP BY ship_to ORDER BY q DESC LIMIT 1");
        $st2->execute($params);
        $topShip = trim((string)($st2->fetchColumn() ?? ''));
        if ($topShip !== '') {
            foreach ($blocks as &$b) $b['ship_to'] = $topShip;
            unset($b);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$toolSort = static function(string $a, string $b): int {
    if ($a === '-' && $b !== '-') return 1;
    if ($b === '-' && $a !== '-') return -1;
    $na = preg_match('/^\d+$/', $a) ? ('#' . str_pad($a, 8, '0', STR_PAD_LEFT)) : ('~' . $a);
    $nb = preg_match('/^\d+$/', $b) ? ('#' . str_pad($b, 8, '0', STR_PAD_LEFT)) : ('~' . $b);
    return strnatcasecmp($na, $nb);
};

$renderBlocks = [];
foreach ($PART_ORDER_WEB as $pk) {
    $b = $blocks[$pk];
    if ((int)$b['grand_total'] <= 0) continue;
    $tools = array_keys($b['rows']);
    usort($tools, $toolSort);
    $rowsSorted = [];
    foreach ($tools as $t) {
        $vals = $b['rows'][$t];
        $rowsSorted[] = [
            'tool' => $t,
            'c1' => (int)($vals[1] ?? 0),
            'c2' => (int)($vals[2] ?? 0),
            'c3' => (int)($vals[3] ?? 0),
            'c4' => (int)($vals[4] ?? 0),
            'ttl' => (int)($vals[1] ?? 0) + (int)($vals[2] ?? 0) + (int)($vals[3] ?? 0) + (int)($vals[4] ?? 0),
        ];
    }
    $b['rows'] = $rowsSorted;
    $renderBlocks[] = $b;
}

// 2) 웹 탭용 출하 요약 집계 (웹과 동일)
$sqlSummary = "
    SELECT
      part_name,
      revision,
      SUM(qty) AS total_qty,
      COUNT(DISTINCT NULLIF(TRIM(COALESCE(NULLIF(pack_barcode,''), small_pack_no)), '')) AS box_count,
      MIN(NULLIF(TRIM(pack_barcode), '')) AS sample_pack_barcode
    FROM ShipingList
    WHERE {$whereSql}
    GROUP BY TRIM(part_name), TRIM(revision)
";
$stS = $pdo->prepare($sqlSummary);
$stS->execute($params);
$summaryRowsDb = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summaryGroups = [];
foreach ($PART_ORDER_WEB as $pk) {
    $summaryGroups[$pk] = [
        'key' => $pk, 'label' => $PART_SPECS[$pk]['label'], 'part_code' => '', 'departure_no' => '',
        'rows_map' => [], 'rows' => [], 'total_qty' => 0, 'total_box' => 0,
    ];
}
foreach ($summaryRowsDb as $r) {
    $pk = sp_export_norm_part_key_web((string)($r['part_name'] ?? ''));
    if (!isset($summaryGroups[$pk])) continue;
    $rev = strtoupper(trim((string)($r['revision'] ?? '')));
    if ($rev === '') $rev = '-';
    $qty = (int)($r['total_qty'] ?? 0);
    $box = (int)($r['box_count'] ?? 0);
    if ($qty <= 0 && $box <= 0) continue;
    if (!isset($summaryGroups[$pk]['rows_map'][$rev])) $summaryGroups[$pk]['rows_map'][$rev] = ['rev'=>$rev,'qty'=>0,'box'=>0];
    $summaryGroups[$pk]['rows_map'][$rev]['qty'] += $qty;
    $summaryGroups[$pk]['rows_map'][$rev]['box'] += $box;
    $summaryGroups[$pk]['total_qty'] += $qty;
    $summaryGroups[$pk]['total_box'] += $box;
    if ($summaryGroups[$pk]['part_code'] === '') {
        $parsed = sp_export_parse_pack_barcode((string)($r['sample_pack_barcode'] ?? ''));
        if (($parsed['part_code'] ?? '') !== '') $summaryGroups[$pk]['part_code'] = (string)$parsed['part_code'];
        if (($parsed['departure_no'] ?? '') !== '') $summaryGroups[$pk]['departure_no'] = (string)$parsed['departure_no'];
    }
}
$summaryRenderGroups = [];
foreach ($PART_ORDER_WEB as $pk) {
    $g = $summaryGroups[$pk];
    if ((int)$g['total_qty'] <= 0) continue;
    $ordered = []; $seen = [];
    foreach (($SUMMARY_PART_ROW_ORDER[$pk] ?? []) as $rv) {
        if (isset($g['rows_map'][$rv])) { $ordered[] = $g['rows_map'][$rv]; $seen[$rv] = true; }
    }
    $remain = [];
    foreach ($g['rows_map'] as $rv => $row) if (!isset($seen[$rv])) $remain[] = $row;
    if ($remain) {
        usort($remain, static fn($a,$b) => $toolSort((string)($a['rev'] ?? ''), (string)($b['rev'] ?? '')));
        foreach ($remain as $row) $ordered[] = $row;
    }
    $g['rows'] = $ordered;
    $summaryRenderGroups[] = $g;
}

// 3) 원시 데이터
$sqlRaw = "SELECT * FROM ShipingList WHERE {$whereSql} ORDER BY {$shipDateCol} ASC, id ASC";
$stRaw = $pdo->prepare($sqlRaw);
$stRaw->execute($params);
$rawRows = $stRaw->fetchAll(PDO::FETCH_ASSOC) ?: [];

// rawRows 기반 특이사항/보조정보
$partQtyTotal = [];      // [IR-BASE => qty]
$partMetaForNote = [];   // [IR-BASE => parsed barcode]
$partNoByPart = [];      // [IR-BASE => customer part no] (raw/barcode 기반)
foreach ($rawRows as $r) {
    $part = sp_export_norm_part_name((string)($r['part_name'] ?? $r['part'] ?? $r['model_name'] ?? ''));
    if ($part === null) continue;
    $qtyRaw = sp_export_pick($r, ['qty','qty_ea','quantity','ship_qty'], 0);
    $qty = is_numeric($qtyRaw) ? (int)$qtyRaw : (int)preg_replace('/[^0-9\-]/', '', (string)$qtyRaw);
    if (!isset($partQtyTotal[$part])) $partQtyTotal[$part] = 0;
    $partQtyTotal[$part] += max(0, $qty);

    if (!isset($partMetaForNote[$part])) {
        $pb = (string)sp_export_pick($r, ['pack_barcode','package_barcode','barcode','pbarcode'], '');
        $partMetaForNote[$part] = sp_export_parse_pack_barcode($pb);
    }
    if (!isset($partNoByPart[$part]) || trim((string)$partNoByPart[$part]) === '') {
        $pn = sp_export_row_customer_part_no($r);
        if ($pn !== '') $partNoByPart[$part] = $pn;
    }
}

// MEM 소포장 내역 시트 채움용 구조 (IR-BASE style keys)
$cavityAgg = []; // [IR-BASE][tool][1..4]
foreach ($renderBlocks as $b) {
    $partShort = strtoupper((string)($b['label'] ?? ''));
    if (!isset($cavityAgg[$partShort])) $cavityAgg[$partShort] = [];
    foreach (($b['rows'] ?? []) as $row) {
        $tool = strtoupper(trim((string)($row['tool'] ?? '-')));
        $cavityAgg[$partShort][$tool] = [
            1 => (int)($row['c1'] ?? 0),
            2 => (int)($row['c2'] ?? 0),
            3 => (int)($row['c3'] ?? 0),
            4 => (int)($row['c4'] ?? 0),
        ];
    }
}
$partTotals = [];
foreach ($PART_ORDER as $part) {
    $partTotals[$part] = [1=>0,2=>0,3=>0,4=>0,'ttl'=>0];
    foreach (($cavityAgg[$part] ?? []) as $tool => $cavs) {
        for ($cv=1; $cv<=4; $cv++) {
            $v = (int)($cavs[$cv] ?? 0);
            $partTotals[$part][$cv] += $v;
            $partTotals[$part]['ttl'] += $v;
        }
    }
}

$periodLabel = sp_export_period_label($fromDate, $toDate);
$dateLabel = $periodLabel;
$customerLabel = trim((string)($shipToFilter !== '' ? $shipToFilter : ($renderBlocks[0]['ship_to'] ?? '')));
if ($customerLabel === '') $customerLabel = '엘지이노텍(주)';
$importDateKorean = ($fromDate === $toDate) ? $fromDate : ($fromDate . ' ~ ' . $toDate);

// ========== Excel 로드 ==========
require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$rootDir = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
$templateFile = sp_export_find_template_file($rootDir);
if (!$templateFile || !is_file($templateFile)) {
    sp_export_json_error('소포장 템플릿 xls 파일을 찾지 못했습니다. (Templates/Sopojang Templates/*.xls)');
}

try {
    $spreadsheet = IOFactory::load($templateFile);
} catch (Throwable $e) {
    sp_export_json_error('템플릿 로드 실패: ' . $e->getMessage());
}

// ========= 채움 함수 (템플릿 시트 유지 + 동적 주입/스타일 생성) =========
$fillSummaryCompact = static function(Worksheet $ws, array $summaryRenderGroups, string $projectCodeReq = 'MEM'): array {
    $projectCodeReq = strtoupper(trim($projectCodeReq));
    if ($projectCodeReq === '') $projectCodeReq = 'MEM';

    $copyRowStyle = static function(Worksheet $srcWs, int $srcRow, Worksheet $dstWs, int $dstRow, array $cols): void {
        foreach ($cols as $col) {
            $dstWs->duplicateStyle($srcWs->getStyle($col.$srcRow), $col.$dstRow);
        }
        $h = $srcWs->getRowDimension($srcRow)->getRowHeight();
        if ($h !== null && $h > 0) $dstWs->getRowDimension($dstRow)->setRowHeight($h);
    };

    $setSummaryRowStyle = static function(Worksheet $ws, int $row, bool $isTotal): void {
        $styleData = [
            'font' => ['size' => 11, 'bold' => true],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ];
        $ws->getStyle("A{$row}:F{$row}")->applyFromArray($styleData);
        $ws->getStyle("E{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0_);[RED]\(#,##0\)');
        if ($isTotal) {
            $ws->getStyle("D{$row}:F{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFFF00');
        } else {
            $ws->getStyle("D{$row}:F{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
        }
        $ws->getStyle("A{$row}:C{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
        $ws->getRowDimension($row)->setRowHeight(21);
    };

    // 기존 요약 병합(A:C) 해제
    foreach (array_keys($ws->getMergeCells()) as $rng) {
        if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $rng, $m)) {
            $r1 = (int)$m[2];
            $r2 = (int)$m[4];
            if ($r2 >= 3 && in_array($m[1], ['A','B','C'], true) && in_array($m[3], ['A','B','C'], true)) {
                try { $ws->unmergeCells($rng); } catch (Throwable $e) { }
            }
        }
    }

    $map = [];
    foreach ($summaryRenderGroups as $g) $map[(string)($g['key'] ?? '')] = $g;

    $orderedKeys = [];
    foreach (['IR-BASE','X-CARRIER','Y-CARRIER','Z-CARRIER','Z-STOPPER'] as $partShort) {
        $orderedKeys[] = $projectCodeReq . '-' . $partShort;
    }

    $groupsToRender = [];
    foreach ($orderedKeys as $pk) {
        $g = $map[$pk] ?? null;
        if (!$g) continue;
        if ((int)($g['total_qty'] ?? 0) <= 0) continue;
        $g['rows'] = array_values($g['rows'] ?? []);
        $groupsToRender[] = $g;
    }

    $needRows = 2;
    foreach ($groupsToRender as $g) $needRows += max(1, count($g['rows']) + 1);
    $curMax = max(2, (int)$ws->getHighestRow());
    if ($needRows > $curMax) $ws->insertNewRowBefore($curMax + 1, $needRows - $curMax);

    $clearEnd = max($needRows + 5, (int)$ws->getHighestRow());
    for ($r = 3; $r <= $clearEnd; $r++) {
        foreach (['A','B','C','D','E','F'] as $c) $ws->setCellValue($c.$r, null);
    }

    $copyRowStyle($ws, 1, $ws, 1, ['A','B','C','D','E','F']);
    $copyRowStyle($ws, 2, $ws, 2, ['A','B','C','D','E','F']);
    // 예시 템플릿 기준 색상 정규화 (출하 요약 헤더)
    $ws->getStyle('A2:F2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCFFFF');
    $ws->getStyle('A2:F2')->getFont()->setBold(true);

    $r = 3;
    $summaryLayout = [];
    foreach ($groupsToRender as $g) {
        $rows = array_values(array_filter($g['rows'] ?? [], static function($x) {
            return ((int)($x['qty'] ?? 0) !== 0) || ((int)($x['box'] ?? 0) !== 0) || trim((string)($x['rev'] ?? '')) !== '';
        }));
        $blockRows = max(1, count($rows) + 1);
        $startRow = $r;
        $endRow = $r + $blockRows - 1;

        foreach (['A','B','C'] as $mc) {
            if ($endRow > $startRow) {
                try { $ws->mergeCells($mc.$startRow.':'.$mc.$endRow); } catch (Throwable $e) { }
            }
        }

        for ($rr = $startRow; $rr <= $endRow; $rr++) $setSummaryRowStyle($ws, $rr, $rr === $endRow);

        $ws->setCellValue('A'.$startRow, (string)($g['label'] ?? ''));
        $ws->setCellValue('B'.$startRow, (string)($g['part_code'] ?? ''));
        $ws->setCellValue('C'.$startRow, (string)($g['departure_no'] ?? ''));

        foreach ($rows as $idx => $row) {
            $rr = $startRow + $idx;
            $ws->setCellValue('D'.$rr, (string)($row['rev'] ?? ''));
            $ws->setCellValue('E'.$rr, (int)($row['qty'] ?? 0));
            $ws->setCellValue('F'.$rr, (int)($row['box'] ?? 0));
        }

        $ws->setCellValue('D'.$endRow, '합계');
        $ws->setCellValue('E'.$endRow, (int)($g['total_qty'] ?? 0));
        $ws->setCellValue('F'.$endRow, (int)($g['total_box'] ?? 0));

        $summaryLayout[(string)($g['key'] ?? '')] = ['start' => $startRow, 'end' => $endRow];
        $r = $endRow + 1;
    }

    return $summaryLayout;
};

$fillMemMatrix = static function(Worksheet $ws, array $cavityAgg, array $partTotals, string $dateLabel, string $customerLabel): array {
    $parent = $ws->getParent();

    $copyCellsStyle = static function(Worksheet $srcWs, int $srcRow, Worksheet $dstWs, int $dstRow, array $srcCols, array $dstCols): void {
        $count = min(count($srcCols), count($dstCols));
        for ($i = 0; $i < $count; $i++) {
            $dstWs->duplicateStyle($srcWs->getStyle($srcCols[$i].$srcRow), $dstCols[$i].$dstRow);
        }
        $h = $srcWs->getRowDimension($srcRow)->getRowHeight();
        if ($h !== null && $h > 0) $dstWs->getRowDimension($dstRow)->setRowHeight($h);
    };

    $applyFallbackMemRowStyle = static function(Worksheet $ws, int $row, string $kind): void {
        $thin = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;
        $white = 'FFFFFFFF';
        $green = 'FFB7F0C8';
        $cyan  = 'FFCCFFFF';
        $yellow = 'FFFFFFCC';

        if ($kind === 'head1' || $kind === 'head2' || $kind === 'title') {
            $fillColor = ($kind === 'title') ? $yellow : $cyan;
            $ws->getStyle("A{$row}:M{$row}")->applyFromArray([
                'font' => ['size' => 10, 'bold' => true],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'color' => ['argb' => $fillColor]],
            ]);
            $ws->getRowDimension($row)->setRowHeight(19.5);
            return;
        }

        $base = [
            'font' => ['size' => 10],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders' => ['allBorders' => ['borderStyle' => $thin, 'color' => ['argb' => 'FF000000']]],
        ];
        foreach ([['A','B','C','D','E','F'], ['H','I','J','K','L','M']] as $block) {
            $ws->getStyle($block[0].$row.':'.$block[5].$row)->applyFromArray($base);
            if ($kind === 'data') {
                $ws->getStyle($block[0].$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($green);
                $ws->getStyle($block[5].$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($white);
                $ws->getStyle($block[1].$row.':'.$block[4].$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
                $ws->getStyle($block[1].$row.':'.$block[5].$row)->getNumberFormat()->setFormatCode('#,##0_);[RED]\(#,##0\)');
            } else {
                $ws->getStyle($block[0].$row.':'.$block[5].$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($white);
                if ($kind === 'ttl') $ws->getStyle($block[1].$row.':'.$block[5].$row)->getNumberFormat()->setFormatCode('#,##0_);[RED]\(#,##0\)');
            }
        }
        $ws->getRowDimension($row)->setRowHeight($kind === 'toolHeader' ? 18.75 : 12.75);
    };

    $normalizeMemRowFill = static function(Worksheet $ws, int $row, string $kind): void {
        $setFill = static function(Worksheet $ws, string $range, ?string $argb): void {
            $fill = $ws->getStyle($range)->getFill();
            if ($argb === null) {
                $fill->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
            } else {
                $fill->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($argb);
            }
        };
        foreach ([['A','B','C','D','E','F'], ['H','I','J','K','L','M']] as $b) {
            $all = $b[0] . $row . ':' . $b[5] . $row;
            if ($kind === 'head2') {
                $setFill($ws, $all, 'FFCCFFFF');
                $ws->getStyle($all)->getFont()->setBold(true);
            } elseif ($kind === 'title') {
                $setFill($ws, $all, 'FFFFFFCC');
                $ws->getStyle($all)->getFont()->setBold(true);
            } elseif ($kind === 'toolHeader') {
                $setFill($ws, $all, 'FFFFFFFF');
            } elseif ($kind === 'data') {
                $setFill($ws, $all, 'FFFFFFFF');
                $setFill($ws, $b[0] . $row, 'FFB7F0C8');
            } elseif ($kind === 'ttl') {
                $setFill($ws, $all, 'FFFFFFFF');
            }
        }
    };

    $partsOrder = ['IR-BASE','X-CARRIER','Y-CARRIER','Z-CARRIER','Z-STOPPER'];
    $partsToRender = [];
    foreach ($partsOrder as $part) {
        $ttl = (int)($partTotals[$part]['ttl'] ?? 0);
        if ($ttl > 0 || !empty($cavityAgg[$part])) $partsToRender[] = $part;
    }

    $sampleCr = $parent ? $parent->getSheetByName('IR BASE CR 샘플') : null;
    $sampleAvi = null;
    if ($parent) {
        foreach ($parent->getWorksheetIterator() as $wst) {
            if (preg_match('/AVI.*소포장\s*내역/u', (string)$wst->getTitle())) { $sampleAvi = $wst; break; }
        }
    }
    $aviTtlRow = 45;
    if ($sampleAvi) {
        for ($rr = (int)$sampleAvi->getHighestRow(); $rr >= 1; $rr--) {
            if (strtoupper(trim((string)$sampleAvi->getCell('A'.$rr)->getCalculatedValue())) === 'TTL') { $aviTtlRow = $rr; break; }
        }
    }

    $needRows = 0;
    if (!$partsToRender) {
        $needRows = 3;
    } else {
        foreach ($partsToRender as $idx => $part) {
            $toolRows = count($cavityAgg[$part] ?? []);
            $needRows += ($idx === 0 ? 1 : 0) + 3 + $toolRows + 1;
        }
    }
    $curMax = max(3, (int)$ws->getHighestRow());
    if ($needRows > $curMax) $ws->insertNewRowBefore($curMax + 1, $needRows - $curMax);

    $clearEnd = max($needRows + 5, (int)$ws->getHighestRow());
    for ($rr = 1; $rr <= $clearEnd; $rr++) {
        foreach (range('A','M') as $c) $ws->setCellValue($c.$rr, null);
    }

    $paintMemRow = static function(string $kind, int $dstRow) use ($ws, $sampleCr, $sampleAvi, $aviTtlRow, $copyCellsStyle, $applyFallbackMemRowStyle, $normalizeMemRowFill): void {
        $left = ['A','B','C','D','E','F'];
        $right = ['H','I','J','K','L','M'];
        try {
            if ($kind === 'head1') { $copyCellsStyle($ws, 1, $ws, $dstRow, range('A','M'), range('A','M')); $normalizeMemRowFill($ws, $dstRow, $kind); return; }
            if ($kind === 'head2') { $copyCellsStyle($ws, 2, $ws, $dstRow, range('A','M'), range('A','M')); $normalizeMemRowFill($ws, $dstRow, $kind); return; }
            if ($kind === 'title') { $copyCellsStyle($ws, 3, $ws, $dstRow, range('A','M'), range('A','M')); $normalizeMemRowFill($ws, $dstRow, $kind); return; }
            if ($kind === 'toolHeader' && $sampleCr) {
                $copyCellsStyle($sampleCr, 3, $ws, $dstRow, $left, $left);
                $copyCellsStyle($sampleCr, 3, $ws, $dstRow, $left, $right);
                $normalizeMemRowFill($ws, $dstRow, $kind);
                return;
            }
            if ($kind === 'data' && $sampleAvi) {
                $copyCellsStyle($sampleAvi, 4, $ws, $dstRow, $left, $left);
                $copyCellsStyle($sampleAvi, 4, $ws, $dstRow, $left, $right);
                $normalizeMemRowFill($ws, $dstRow, $kind);
                return;
            }
            if ($kind === 'ttl' && $sampleAvi) {
                $copyCellsStyle($sampleAvi, (int)$aviTtlRow, $ws, $dstRow, $left, $left);
                $copyCellsStyle($sampleAvi, (int)$aviTtlRow, $ws, $dstRow, $left, $right);
                $normalizeMemRowFill($ws, $dstRow, $kind);
                return;
            }
        } catch (Throwable $e) {
        }
        $applyFallbackMemRowStyle($ws, $dstRow, $kind);
        $normalizeMemRowFill($ws, $dstRow, $kind);
    };

    $sortTools = static function(array $tools): array {
        usort($tools, static function($a, $b) {
            $a = (string)$a; $b = (string)$b;
            if ($a === '-' && $b !== '-') return 1;
            if ($b === '-' && $a !== '-') return -1;
            return strnatcasecmp($a, $b);
        });
        return $tools;
    };

    $layoutInfo = [];
    $r = 1;
    foreach ($partsToRender as $idx => $part) {
        if ($idx === 0) {
            $paintMemRow('head1', $r);
            $ws->setCellValue('A'.$r, $dateLabel);
            $ws->setCellValue('D'.$r, '합계 출하 수량= 일별출하계획참고');
            $ws->setCellValue('H'.$r, $dateLabel);
            $ws->setCellValue('K'.$r, '합계 출하 수량= 일별출하계획참고');
            $r++;
        }

        $head2Row = $r;
        $paintMemRow('head2', $head2Row);
        $ws->setCellValue('B'.$head2Row, $dateLabel);
        $ws->setCellValue('C'.$head2Row, $customerLabel);
        $ws->setCellValue('D'.$head2Row, '출하 수량');
        $ws->setCellValue('E'.$head2Row, '(완료)');
        $ws->setCellValue('I'.$head2Row, $dateLabel);
        $ws->setCellValue('J'.$head2Row, $customerLabel);
        $ws->setCellValue('K'.$head2Row, '2차 출하 수량');
        $ws->setCellValue('L'.$head2Row, '(완료)');
        $r++;

        $titleRow = $r;
        $paintMemRow('title', $titleRow);
        $ws->setCellValue('A'.$titleRow, $part);
        $ws->setCellValue('B'.$titleRow, ' ETA :');
        $ws->setCellValue('C'.$titleRow, $dateLabel);
        $ws->setCellValue('E'.$titleRow, $customerLabel);
        $ws->setCellValue('H'.$titleRow, $part);
        $ws->setCellValue('I'.$titleRow, ' ETA :');
        $ws->setCellValue('J'.$titleRow, $dateLabel);
        $ws->setCellValue('L'.$titleRow, $customerLabel);
        $r++;

        $toolHeaderRow = $r;
        $paintMemRow('toolHeader', $toolHeaderRow);
        foreach ([['A','B','C','D','E','F'], ['H','I','J','K','L','M']] as $cols) {
            $ws->setCellValue($cols[0].$toolHeaderRow, 'Tool');
            $ws->setCellValue($cols[1].$toolHeaderRow, 'Cav 1');
            $ws->setCellValue($cols[2].$toolHeaderRow, 'Cav 2');
            $ws->setCellValue($cols[3].$toolHeaderRow, 'Cav 3');
            $ws->setCellValue($cols[4].$toolHeaderRow, 'Cav 4');
            $ws->setCellValue($cols[5].$toolHeaderRow, 'TTL');
        }
        $r++;

        $tools = $sortTools(array_keys($cavityAgg[$part] ?? []));
        $dataStart = $r;
        foreach ($tools as $tool) {
            $paintMemRow('data', $r);
            $vals = $cavityAgg[$part][$tool] ?? [1=>0,2=>0,3=>0,4=>0];
            $rowTtl = (int)($vals[1] ?? 0) + (int)($vals[2] ?? 0) + (int)($vals[3] ?? 0) + (int)($vals[4] ?? 0);
            foreach ([['A','B','C','D','E','F'], ['H','I','J','K','L','M']] as $cols) {
                $ws->setCellValue($cols[0].$r, (string)$tool);
                $ws->setCellValue($cols[1].$r, (int)($vals[1] ?? 0));
                $ws->setCellValue($cols[2].$r, (int)($vals[2] ?? 0));
                $ws->setCellValue($cols[3].$r, (int)($vals[3] ?? 0));
                $ws->setCellValue($cols[4].$r, (int)($vals[4] ?? 0));
                $ws->setCellValue($cols[5].$r, $rowTtl);
            }
            $r++;
        }
        $dataEnd = $r - 1;

        $ttlRow = $r;
        $paintMemRow('ttl', $ttlRow);
        $t = $partTotals[$part] ?? [1=>0,2=>0,3=>0,4=>0,'ttl'=>0];
        foreach ([['A','B','C','D','E','F'], ['H','I','J','K','L','M']] as $cols) {
            $ws->setCellValue($cols[0].$ttlRow, 'TTL');
            $ws->setCellValue($cols[1].$ttlRow, (int)($t[1] ?? 0));
            $ws->setCellValue($cols[2].$ttlRow, (int)($t[2] ?? 0));
            $ws->setCellValue($cols[3].$ttlRow, (int)($t[3] ?? 0));
            $ws->setCellValue($cols[4].$ttlRow, (int)($t[4] ?? 0));
            $ws->setCellValue($cols[5].$ttlRow, (int)($t['ttl'] ?? 0));
        }
        $r++;

        $layoutInfo[$part] = ['head2'=>$head2Row, 'title'=>$titleRow, 'toolHeader'=>$toolHeaderRow, 'dataStart'=>$dataStart, 'dataEnd'=>$dataEnd, 'ttl'=>$ttlRow];
    }

    for ($rr = $r; $rr <= max($r + 5, (int)$ws->getHighestRow()); $rr++) {
        foreach (range('A','M') as $c) $ws->setCellValue($c.$rr, null);
    }

    return $layoutInfo;
};

// ========= 템플릿 시트 탐색 =========
$wsSummary = $spreadsheet->getSheetByName('출하 요약');
$wsMem = $spreadsheet->getSheetByName('MEM 소포장 내역');
if (!$wsMem) {
    foreach ($spreadsheet->getWorksheetIterator() as $wsTmp) {
        if (preg_match('/소포장\s*내역/u', (string)$wsTmp->getTitle())) { $wsMem = $wsTmp; break; }
    }
}
if (!$wsSummary || !$wsMem) {
    sp_export_json_error('템플릿 시트(출하 요약 / 소포장 내역)를 찾지 못했습니다.');
}
$desiredMemName = sp_export_mem_sheet_name($projectCodeReq);
try { $wsMem->setTitle($desiredMemName); } catch (Throwable $e) { /* ignore duplicate title etc */ }

// 출하 요약 / 소포장 내역 채움
$fillMemMatrix($wsMem, $cavityAgg, $partTotals, $dateLabel, $customerLabel);
$fillSummaryCompact($wsSummary, $summaryRenderGroups, $projectCodeReq);
$modelCountForTitle = 0;
foreach ($PART_ORDER as $pp) if (((int)($partQtyTotal[$pp] ?? 0)) > 0) $modelCountForTitle++;
$wsSummary->setCellValue('D1', $dateLabel);
$wsSummary->setCellValue('E1', $modelCountForTitle . '종');

// ========= 날짜 시트 (QA raw) =========
$rangeSheetTitle = sp_export_range_sheet_title($fromDate, $toDate);
$wsDateRaw = null;
foreach ($spreadsheet->getWorksheetIterator() as $wsTmp) {
    $t = (string)$wsTmp->getTitle();
    if ($t === '사진') continue;
    if (preg_match('/^\d{4}-\d{2}-\d{2}(\s*~\s*\d{4}-\d{2}-\d{2})?$/u', $t)) { $wsDateRaw = $wsTmp; break; }
}
if (!$wsDateRaw) {
    foreach ($spreadsheet->getWorksheetIterator() as $wsTmp) {
        $h1 = trim((string)($wsTmp->getCell('A1')->getCalculatedValue() ?? ''));
        $h3 = trim((string)($wsTmp->getCell('C1')->getCalculatedValue() ?? ''));
        if ($h1 === 'No' && (str_contains($h3, '출하') || $h3 === '출하 일자')) { $wsDateRaw = $wsTmp; break; }
    }
}
if ($wsDateRaw) {
    try { $wsDateRaw->setTitle($rangeSheetTitle); } catch (Throwable $e) { /* ignore */ }
    // visible 유지
    $lastRow = max(200, (int)$wsDateRaw->getHighestRow());
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($wsDateRaw->getHighestColumn());
    $clearColEnd = max($lastCol, 12);
    for ($r = 2; $r <= $lastRow; $r++) {
        for ($c = 1; $c <= $clearColEnd; $c++) sp_export_set_cell_value_by_col_row($wsDateRaw, $c, $r, null);
    }
    $r = 2;
    $seq = 1;
    foreach ($rawRows as $x) {
        $partNameRaw = (string)sp_export_pick($x, ['part_name','part','model_name'], '');
        $partShort = sp_export_norm_part_name($partNameRaw);
        $partNoCustomer = sp_export_row_customer_part_no($x);
        if ($partNoCustomer === '' && $partShort !== null) $partNoCustomer = (string)($partNoByPart[$partShort] ?? '');

        $shipDate = (string)sp_export_pick($x, ['ship_date','ship_datetime','created_at'], '');
        if ($shipDate !== '') {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $shipDate, $m)) $shipDate = $m[1];
        } else {
            $shipDate = $toDate;
        }

        $packNo = (string)sp_export_pick($x, ['small_pack_no','pack_no','pack_number'], '');
        $internalPartNo = (string)sp_export_pick($x, ['part_no','partno','item_no'], '');
        if ($internalPartNo === '') $internalPartNo = $partNameRaw;
        $rev = strtoupper(trim((string)sp_export_pick($x, ['revision'], '')));
        $partDisplay = trim($partNameRaw . ($rev !== '' ? (' ' . $rev . '차') : ''));
        $qty = (int)sp_export_pick($x, ['qty','qty_ea','quantity','ship_qty'], 0);
        $slipNo = (string)sp_export_pick($x, ['transaction_no','transfer_no','stock_no','supply_no','trace_no'], '');
        $shipToVal = (string)sp_export_pick($x, ['ship_to'], '');

        // 템플릿 헤더 기준 12열 (수불번호가 없는 템플릿도 있어도 값 주입만)
        $wsDateRaw->setCellValue('A'.$r, $seq);
        $wsDateRaw->setCellValue('B'.$r, null);
        $wsDateRaw->setCellValue('C'.$r, $shipDate);
        $wsDateRaw->setCellValue('D'.$r, (string)sp_export_pick($x, ['ship_order_no','instruction_no','ship_request_no'], ''));
        $wsDateRaw->setCellValue('E'.$r, $packNo);
        $wsDateRaw->setCellValue('F'.$r, $internalPartNo);
        $wsDateRaw->setCellValue('G'.$r, $partDisplay);
        $wsDateRaw->setCellValue('H'.$r, (string)sp_export_pick($x, ['model'], ''));
        $wsDateRaw->setCellValue('I'.$r, $partNoCustomer);
        $wsDateRaw->setCellValue('J'.$r, $shipToVal);
        $wsDateRaw->setCellValue('K'.$r, $qty);
        if ($clearColEnd >= 12) $wsDateRaw->setCellValue('L'.$r, $slipNo);
        $r++; $seq++;
    }
}

// ========= RMAZC(F)출하 숨김 raw 시트 (존재 시 같이 주입) =========
$wsRawHelper = $spreadsheet->getSheetByName('RMAZC(F)출하');
if ($wsRawHelper) {
    $lastRow = max(200, (int)$wsRawHelper->getHighestRow());
    $clearColEnd = max(11, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($wsRawHelper->getHighestColumn()));
    for ($r = 2; $r <= $lastRow; $r++) {
        for ($c = 1; $c <= $clearColEnd; $c++) sp_export_set_cell_value_by_col_row($wsRawHelper, $c, $r, null);
    }
    $r = 2;
    $seq = 1;
    foreach ($rawRows as $x) {
        $partNameRaw = (string)sp_export_pick($x, ['part_name','part','model_name'], '');
        $partShort = sp_export_norm_part_name($partNameRaw);
        $partNoCustomer = sp_export_row_customer_part_no($x);
        if ($partNoCustomer === '' && $partShort !== null) $partNoCustomer = (string)($partNoByPart[$partShort] ?? '');
        $shipDate = (string)sp_export_pick($x, ['ship_date','ship_datetime','created_at'], '');
        if ($shipDate !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $shipDate, $m)) $shipDate = $m[1];
        $rev = strtoupper(trim((string)sp_export_pick($x, ['revision'], '')));
        $packNo = (string)sp_export_pick($x, ['small_pack_no','pack_no','pack_number'], '');
        $internalPartNo = (string)sp_export_pick($x, ['part_no','partno','item_no'], '');
        if ($internalPartNo === '') $internalPartNo = $partNameRaw;
        $partDisplay = trim($partNameRaw . ($rev !== '' ? (' ' . $rev . '차') : ''));
        $qty = (int)sp_export_pick($x, ['qty','qty_ea','quantity','ship_qty'], 0);
        $shipToVal = (string)sp_export_pick($x, ['ship_to'], '');

        $wsRawHelper->setCellValue('A'.$r, $seq);
        $wsRawHelper->setCellValue('B'.$r, null);
        $wsRawHelper->setCellValue('C'.$r, ($shipDate !== '' ? $shipDate : $toDate));
        $wsRawHelper->setCellValue('D'.$r, (string)sp_export_pick($x, ['ship_order_no','instruction_no','ship_request_no'], ''));
        $wsRawHelper->setCellValue('E'.$r, $packNo);
        $wsRawHelper->setCellValue('F'.$r, $internalPartNo);
        $wsRawHelper->setCellValue('G'.$r, $partDisplay);
        $wsRawHelper->setCellValue('H'.$r, (string)sp_export_pick($x, ['model'], ''));
        $wsRawHelper->setCellValue('I'.$r, $partNoCustomer);
        if ($clearColEnd >= 10) $wsRawHelper->setCellValue('J'.$r, $shipToVal);
        if ($clearColEnd >= 11) $wsRawHelper->setCellValue('K'.$r, $qty);
        $r++; $seq++;
    }
    $wsRawHelper->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
}

// ========= 특이사항 시트 =========
$wsNote = $spreadsheet->getSheetByName('특이사항');
if ($wsNote) {
    $copyNoteRowStyle = static function(Worksheet $ws, int $srcRow, int $dstRow): void {
        foreach (range('A', 'I') as $col) {
            $ws->duplicateStyle($ws->getStyle($col.$srcRow), $col.$dstRow);
        }
        $srcH = $ws->getRowDimension($srcRow)->getRowHeight();
        if ($srcH !== null && $srcH > 0) $ws->getRowDimension($dstRow)->setRowHeight($srcH);
    };
    $applyNoteRowStyle = static function(Worksheet $ws, int $row): void {
        $medium = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM;
        $center = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
        $vcenter = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;
        $ws->getStyle("A{$row}:I{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF000000']],
            'alignment' => ['horizontal' => $center, 'vertical' => $vcenter],
            'borders' => ['allBorders' => ['borderStyle' => $medium, 'color' => ['argb' => 'FF000000']]],
        ]);
        $ws->getStyle("H{$row}")->getNumberFormat()->setFormatCode('#,##0_);[RED]\(#,##0\)');
        $ws->getStyle("A{$row}:F{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
        $ws->getStyle("G{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC000');
        $ws->getStyle("H{$row}:I{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9D9D9');
        $ws->getStyle("I{$row}")->getFont()->getColor()->setARGB('FFFF0000');
        $ws->getStyle("I{$row}")->getAlignment()->setWrapText(true);
        $ws->getRowDimension($row)->setRowHeight(22);
    };

    $lastNoteRow = max(200, (int)$wsNote->getHighestRow());
    for ($rr=2; $rr<=$lastNoteRow; $rr++) {
        foreach (range('A','I') as $cc) $wsNote->setCellValue($cc.$rr, null);
    }
    $nr = 2;
    foreach ($PART_ORDER as $part) {
        $qty = (int)($partQtyTotal[$part] ?? 0);
        if ($qty <= 0) continue;
        $meta = $partMetaForNote[$part] ?? [];
        $depNo = trim((string)($meta['departure_no'] ?? ''));
        $wsNote->setCellValue('A'.$nr, 'DPAMS');
        $wsNote->setCellValue('B'.$nr, 'MP');
        $wsNote->setCellValue('C'.$nr, '도입');
        $wsNote->setCellValue('D'.$nr, $importDateKorean);
        $wsNote->setCellValue('E'.$nr, (string)($partNoByPart[$part] ?? (string)($meta['part_code'] ?? '')));
        $wsNote->setCellValue('F'.$nr, sp_export_model_display_for_note($part));
        $wsNote->setCellValue('G'.$nr, $depNo);
        $wsNote->setCellValue('H'.$nr, $qty);
        $wsNote->setCellValue('I'.$nr, '도착 예정 시간 09:00AM');
        $nr++;
    }

    $noteDataEnd = $nr - 1;
    if ($noteDataEnd >= 2) {
        if ($noteDataEnd > $lastNoteRow) {
            $wsNote->insertNewRowBefore($lastNoteRow + 1, $noteDataEnd - $lastNoteRow);
            $lastNoteRow = $noteDataEnd;
        }
        for ($rr = 2; $rr <= $noteDataEnd; $rr++) {
            if ($rr > 2) $copyNoteRowStyle($wsNote, 2, $rr);
            $applyNoteRowStyle($wsNote, $rr);
        }
    }
}

// ========= 저장 전 시트 잠금/틀고정 해제 (엑셀에서 스크롤/선택 불가 방지) =========
try {
    foreach ($spreadsheet->getWorksheetIterator() as $wsUnlock) {
        try {
            // 틀고정/분할 해제 (버전별 호환 고려)
            if (method_exists($wsUnlock, 'freezePane')) {
                try { $wsUnlock->freezePane(null); } catch (Throwable $e) {
                    try { $wsUnlock->freezePane('A1'); } catch (Throwable $e2) { /* ignore */ }
                }
            }
            if (method_exists($wsUnlock, 'unfreezePane')) {
                try { $wsUnlock->unfreezePane(); } catch (Throwable $e) { /* ignore */ }
            }
            if (method_exists($wsUnlock, 'setSelectedCell')) {
                try { $wsUnlock->setSelectedCell('A1'); } catch (Throwable $e) { /* ignore */ }
            }
            if (method_exists($wsUnlock, 'setSelectedCells')) {
                try { $wsUnlock->setSelectedCells('A1'); } catch (Throwable $e) { /* ignore */ }
            }
            if (method_exists($wsUnlock, 'getSheetView')) {
                $sv = $wsUnlock->getSheetView();
                if ($sv) {
                    if (method_exists($sv, 'setTopLeftCell')) {
                        try { $sv->setTopLeftCell('A1'); } catch (Throwable $e) { /* ignore */ }
                    }
                    if (method_exists($sv, 'setZoomScaleNormal')) {
                        try { $sv->setZoomScaleNormal(100); } catch (Throwable $e) { /* ignore */ }
                    }
                }
            }

            // 시트 보호 해제
            if (method_exists($wsUnlock, 'getProtection')) {
                $prot = $wsUnlock->getProtection();
                if ($prot) {
                    if (method_exists($prot, 'setSheet')) {
                        try { $prot->setSheet(false); } catch (Throwable $e) { /* ignore */ }
                    }
                    foreach ([
                        'setObjects', 'setScenarios', 'setFormatCells', 'setFormatColumns', 'setFormatRows',
                        'setInsertColumns', 'setInsertRows', 'setInsertHyperlinks', 'setDeleteColumns',
                        'setDeleteRows', 'setSelectLockedCells', 'setSort', 'setAutoFilter', 'setPivotTables',
                        'setSelectUnlockedCells'
                    ] as $mth) {
                        if (method_exists($prot, $mth)) {
                            try { $prot->{$mth}(true); } catch (Throwable $e) { /* ignore */ }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // 개별 시트 실패는 무시하고 다음 시트 진행
        }
    }

    // 워크북 구조/창 보호 해제
    if (method_exists($spreadsheet, 'getSecurity')) {
        $sec = $spreadsheet->getSecurity();
        if ($sec) {
            foreach (['setLockWindows', 'setLockStructure', 'setLockRevision'] as $mth) {
                if (method_exists($sec, $mth)) {
                    try { $sec->{$mth}(false); } catch (Throwable $e) { /* ignore */ }
                }
            }
        }
    }

    try { $spreadsheet->setActiveSheetIndex(0); } catch (Throwable $e) { /* ignore */ }
} catch (Throwable $e) {
    // 저장 직전 정리 실패는 export 자체를 막지 않음
}

// ========= 파일명 =========
$modelCount = 0;
foreach ($PART_ORDER as $partKey) {
    if (((int)($partQtyTotal[$partKey] ?? 0)) > 0) $modelCount++;
}
$yy = date('ymd', strtotime($toDate)); // 기간이어도 요청 형식 YYMMDD_PROJECT_X종_출하.xls 유지
$fnameBase = $yy . '_' . $projectCodeReq . '_' . max(1, $modelCount) . '종_출하.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . rawurlencode($fnameBase) . '"; filename*=UTF-8\'\'' . rawurlencode($fnameBase));
header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: public');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
$writer->save('php://output');
exit;
