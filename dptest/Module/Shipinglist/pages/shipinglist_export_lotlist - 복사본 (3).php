<?php
// shipinglist_export_lotlist_v7.25.3_CleanLog_Blocklist_AutoFlush.php
// Shipping Lot List 성적서 ZIP 생성 + (추가) OQC 템플릿(출하된 품번만) 생성
declare(strict_types=1);
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }



// 한국시간 강제
@date_default_timezone_set('Asia/Seoul');
// v7.25: debug=1 일 때만 상세 로그/SQL 출력
$DEBUG = ((int)($_GET['debug'] ?? 0) === 1);
$GLOBALS['OQC_DEBUG'] = $DEBUG ? 1 : 0;

// Some helper functions expect $debug as int debug level.
$debug = (int)$GLOBALS['OQC_DEBUG'];


// ─────────────────────────────────────────────────────────────
// REPORT(Fai/Spc) 시작열 설정 (납품처 템플릿별로 "처음부터" 이 열에 바로 입력)
//  - LGIT(기준): FAI = L~N(start L), SPC = AM~BR(start AM)
//  - JAWHA(자화): 템플릿 기준으로 조정 (기본: FAI H, SPC AI)
//  - 모델별 예외는 by_model에만 추가 (예: MEM-Z-STOPPER LGIT=J/AK)
// ─────────────────────────────────────────────────────────────
$GLOBALS['REPORT_FAI_SPC_COLCFG'] = $GLOBALS['REPORT_FAI_SPC_COLCFG'] ?? [
    // 🔴 false면 기존 동작(기존 열) 그대로
    'enabled' => true,

    // ✅ dst = 납품처(템플릿)별 "실제 템플릿이 참조하는" 시작 열
    //    - FAI 는 3칸(연속), SPC 는 32칸(연속)
    'dst' => [
        'LGIT'  => ['FAI' => 'L',  'SPC' => 'AM'],
        'JAWHA' => ['FAI' => 'H',  'SPC' => 'AI'],
    ],

    // ✅ 모델(part_name)별 예외 (필요한 모델만 추가)
    'by_model' => [
        // Z-STOPPER: LGIT 템플릿은 기본과 열이 다름
        'MEM-Z-STOPPER' => [
            'dst' => [
                'LGIT'  => ['FAI' => 'J',  'SPC' => 'AK'],
                'JAWHA' => ['FAI' => 'H',  'SPC' => 'AI'],
            ],
        ],
    ],
];


// ✅ 긴급 재사용(30~60일) 범위: 필요 시 GET 파라미터로 조절 가능 (?emg_from=60&emg_to=30)
$GLOBALS['OQC_EMG_FROM_DAYS'] = max(1, (int)($_GET['emg_from'] ?? 60));
$GLOBALS['OQC_EMG_TO_DAYS']   = max(0, (int)($_GET['emg_to'] ?? 30));
if ($GLOBALS['OQC_EMG_TO_DAYS'] >= $GLOBALS['OQC_EMG_FROM_DAYS']) {
    // 잘못된 값이면 기본값 유지
    $GLOBALS['OQC_EMG_FROM_DAYS'] = 60;
    $GLOBALS['OQC_EMG_TO_DAYS']   = 30;
}

$GLOBALS['DEBUG'] = $DEBUG;

// ─────────────────────────────
// PDF 변환(발행 시 xlsx→pdf) 설정
//  - LibreOffice headless 변환 사용 (Docker/OnlyOffice/COM 불필요)
//  - 변환 성공 시 xlsx는 삭제(요청사항)
// ─────────────────────────────
$GLOBALS['PDF_EXPORT_ENABLED'] = true;
$GLOBALS['PDF_EXPORT_DELETE_XLSX'] = true;
$GLOBALS['PDF_EXPORT_TIMEOUT_SEC'] = 120; // 파일 1개당 타임아웃(초)
$GLOBALS['SOFFICE_PATH'] = $GLOBALS['SOFFICE_PATH'] ?? 'C:\\Program Files\\LibreOffice\\program\\soffice.com';

// v39.1: PDF_EXPORT_* 를 상수로도 사용할 수 있게 정의(Undefined constant 방지)
if (!defined('PDF_EXPORT_ENABLED')) { define('PDF_EXPORT_ENABLED', (bool)($GLOBALS['PDF_EXPORT_ENABLED'] ?? false)); }
if (!defined('PDF_EXPORT_DELETE_XLSX')) { define('PDF_EXPORT_DELETE_XLSX', (bool)($GLOBALS['PDF_EXPORT_DELETE_XLSX'] ?? false)); }


// v7.25.2: result_ok로 NG 판단하지 않음 (USL/LSL만 사용)
$USE_RESULT_OK_FOR_NG = false;
$GLOBALS['USE_RESULT_OK_FOR_NG'] = $USE_RESULT_OK_FOR_NG;

@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ini_set('memory_limit', '2048M'); // 1GB로 터졌으면 2GB 권장(서버 여건에 맞게)
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'Off');

// v7.25.3: 실시간 로그가 멈춘 것처럼 보이는 현상 방지 (버퍼 강제 플러시)
if (function_exists('ob_get_level')) {
    while (@ob_get_level() > 0) { @ob_end_flush(); }
}
@ob_implicit_flush(true);


if (function_exists('gc_enable')) gc_enable();
ignore_user_abort(true);

session_start();

require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();
// ✅ 긴 작업(build) 중에도 다른 요청(취소 등)이 막히지 않도록 세션 락 해제
if (function_exists('session_write_close')) { @session_write_close(); }
require_once JTMES_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\CachedObjectStorageFactory;

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// 날짜 문자열을 YYYY-MM-DD 형태로 정규화 (예: '202512-12-12' 같은 깨진 값도 복구)
if (!function_exists('normalize_date_ymd')) {
    function normalize_date_ymd(?string $s): string {
        $s = trim((string)($s ?? ''));
        if ($s === '') return '';
        // 정상: 2025-12-12
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

        // (버그 대응) '2025-12-12'에서 첫 '-'만 제거된 형태: 202512-12-12
        if (preg_match('/^\d{6}-\d{2}-\d{2}$/', $s)) {
            $yyyy = substr($s, 0, 4);
            $mm   = substr($s, 4, 2);
            $dd   = substr($s, -2);
            return $yyyy . '-' . $mm . '-' . $dd;
        }

        // 20251212
        if (preg_match('/^\d{8}$/', $s)) {
            return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
        }

        return '';
    }
}


// ─────────────────────────────
// PhpSpreadsheet 캐시(메모리 절감)
// ─────────────────────────────
try {
    if (class_exists(CachedObjectStorageFactory::class) && method_exists(Settings::class, 'setCacheStorageMethod')) {
        // PHP temp로 셀 캐싱(디스크/메모리 혼합)
        Settings::setCacheStorageMethod(
            CachedObjectStorageFactory::cache_to_phpTemp,
            ['memoryCacheSize' => '256MB']
        );
    }
} catch (Throwable $e) {
    // 캐시 설정 실패해도 진행
}

// ─────────────────────────────
// DB 접속
// ─────────────────────────────
try {
    $pdo = dp_get_pdo();
} catch (PDOException $e) {
    die('DB 접속 실패: ' . h($e->getMessage()));
}

// ─────────────────────────────
// 설정
// ─────────────────────────────
$INCLUDE_CHARTS = false; // 템플릿 레이아웃 안정성 우선 (필요하면 true로)   // OQC 템플릿에 차트 있으면 true
$STYLE_CHUNK          = 1000;   // 스타일 복제 chunk
$CLEAR_SAMPLE_ROWS    = 200;    // 템플릿 샘플 데이터 지우는 범위(작게!)
// $WRITE_CHUNK_ROWS  = 2000;   // (현재 ShippingLotList 쪽에서 고정 2000 사용)

// 품번명 → (Shipping Lot List) 템플릿 파일 + APN + Inner package prefix
// ─────────────────────────────────────────────────────────────
// Templates root auto-detect (사용자가 템플릿 구조를 바꿔도 경로 자동 대응)
// 우선순위: JTMES_ROOT/Templates  → JTMES_ROOT/templates → JTMES_ROOT (기존 구조 호환)
// ─────────────────────────────────────────────────────────────
$TEMPLATES_ROOT = null;
$__tpl_base = JTMES_ROOT;
$__tpl_candidates = [
    $__tpl_base . DIRECTORY_SEPARATOR . 'Templates',
    $__tpl_base . DIRECTORY_SEPARATOR . 'templates',
    $__tpl_base,
];
foreach ($__tpl_candidates as $__cand) {
    if (
        is_dir($__cand . DIRECTORY_SEPARATOR . 'OQC templates') ||
        is_dir($__cand . DIRECTORY_SEPARATOR . 'oqc templates') ||
        is_dir($__cand . DIRECTORY_SEPARATOR . 'CMM templates') ||
        is_dir($__cand . DIRECTORY_SEPARATOR . 'cmm templates') ||
        is_dir($__cand . DIRECTORY_SEPARATOR . 'Report Templates') ||
        is_dir($__cand . DIRECTORY_SEPARATOR . 'report templates')
    ) {
        $TEMPLATES_ROOT = $__cand;
        break;
    }
}
if ($TEMPLATES_ROOT === null) {
    // 최후의 기본값(대부분 새 구조)
    $TEMPLATES_ROOT = $__tpl_base . DIRECTORY_SEPARATOR . 'Templates';
}
unset($__tpl_base, $__tpl_candidates, $__cand);

// 신규 구조에서는 $TEMPLATES_ROOT 안에 아래 3개가 있다고 가정:
//   - OQC templates
//   - CMM templates
//   - Report Templates


$PART_MAP = [
    // NOTE: Shipping Lot List의 Inner package(D열)는 customer_lot_id를 그대로 사용한다.
    //       (과거 inner_prefix 하드코딩 보강 로직은 사용하지 않음)
    'MEM-IR-BASE'   => ['template' => 'OQC_Report_Memphis_MP_IR Base_.xlsx',   'apn' => '817-11868'],
    'MEM-X-CARRIER' => ['template' => 'OQC_Report_Memphis_MP_X_Carrier_.xlsx', 'apn' => '817-11233'],
    'MEM-Y-CARRIER' => ['template' => 'OQC_Report_Memphis_MP_Y_Carrier_.xlsx', 'apn' => '817-11234'],
    'MEM-Z-CARRIER' => ['template' => 'OQC_Report_Memphis_MP_Z Carrier_.xlsx', 'apn' => '817-11238'],
    'MEM-Z-STOPPER' => ['template' => 'OQC_Report_Memphis_MP_Z Stopper_.xlsx', 'apn' => '817-10714'],
];

// OQC 템플릿(네 xlsx 5종) 폴더/맵
$OQC_TEMPLATE_DIR = $TEMPLATES_ROOT . DIRECTORY_SEPARATOR . 'OQC templates';
if (!is_dir($OQC_TEMPLATE_DIR)) { $OQC_TEMPLATE_DIR = $TEMPLATES_ROOT . DIRECTORY_SEPARATOR . 'oqc templates'; }
if (!is_dir($OQC_TEMPLATE_DIR)) { $OQC_TEMPLATE_DIR = JTMES_ROOT . DIRECTORY_SEPARATOR . 'oqc templates'; }
if (!is_dir($OQC_TEMPLATE_DIR)) { $OQC_TEMPLATE_DIR = JTMES_ROOT . DIRECTORY_SEPARATOR . 'OQC templates'; }
$OQC_PART_TPL = [
    'MEM-IR-BASE'   => 'OQC_Memphis_IR Base.xlsx',
    'MEM-X-CARRIER' => 'OQC_Memphis_X Carrier.xlsx',
    'MEM-Y-CARRIER' => 'OQC_Memphis_Y Carrier.xlsx',
    'MEM-Z-CARRIER' => 'OQC_Memphis_Z Carrier.xlsx',
    'MEM-Z-STOPPER' => 'OQC_Memphis_Z Stopper.xlsx',
];

// OQC 템플릿 절대경로 맵(기존 코드에서 사용하던 변수명 보정)
$OQC_TEMPLATES = [];
foreach ($OQC_PART_TPL as $k => $fn) {
    $OQC_TEMPLATES[$k] = $OQC_TEMPLATE_DIR . DIRECTORY_SEPARATOR . $fn;
}


// Shipping Lot List 템플릿 베이스 디렉터리
$TEMPLATE_BASE_DIR = $TEMPLATES_ROOT . DIRECTORY_SEPARATOR . 'Report Templates' . DIRECTORY_SEPARATOR . 'Memphis templates';
if (!is_dir($TEMPLATE_BASE_DIR)) { $TEMPLATE_BASE_DIR = $TEMPLATES_ROOT . DIRECTORY_SEPARATOR . 'report templates' . DIRECTORY_SEPARATOR . 'Memphis templates'; }
if (!is_dir($TEMPLATE_BASE_DIR)) { $TEMPLATE_BASE_DIR = JTMES_ROOT . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'Memphis templates'; }
if (!is_dir($TEMPLATE_BASE_DIR)) { $TEMPLATE_BASE_DIR = JTMES_ROOT . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'Report Templates' . DIRECTORY_SEPARATOR . 'Memphis templates'; }

// ✅ 납품처(ship_to) → 폴더명 매핑 (여기만 계속 추가하면 됨)
$SHIP_TO_TEMPLATE_SUBDIR = [
    '엘지이노텍(주)' => 'LGIT',
    '자화전자(주)'       => 'JAWHA',
    // 필요하면 계속 추가
];

// ship_to (build 화면은 GET, 취소/재시도는 POST일 수 있음)
$shipTo = trim((string)($_GET['ship_to'] ?? ($_POST['ship_to'] ?? '')));

// 기본은 베이스 폴더
$TEMPLATE_DIR = $TEMPLATE_BASE_DIR;

// 매핑된 폴더가 있으면 그쪽으로 (완전일치 우선 + 부분일치 보강)
$sub = null;
if ($shipTo !== '') {
    if (isset($SHIP_TO_TEMPLATE_SUBDIR[$shipTo])) {
        $sub = $SHIP_TO_TEMPLATE_SUBDIR[$shipTo];
    } else {
        // 괄호/㈜/공백 등 표기차이 대응: 키워드 포함이면 매핑
        $norm = preg_replace('/\s+/u', '', $shipTo);
        if (strpos($norm, '엘지이노텍') !== false) $sub = 'LGIT';
        if (strpos($norm, '자화전자') !== false)   $sub = 'JAWHA';
    }
}
if ($sub) {
    $cand = $TEMPLATE_BASE_DIR . DIRECTORY_SEPARATOR . $sub;
    if (is_dir($cand)) {
        $TEMPLATE_DIR = $cand;
    }
}

// (주의) 어떤 action에서도 (특히 fetch/redirect) 헤더 출력 전에 echo/logline이 실행되면 안 됨.
// 템플릿 폴더 로그는 build 진행 로그 화면에서만 표시한다.

// ─────────────────────────────
// 공통 헬퍼
// ─────────────────────────────
function flush_now(): void {
    echo str_repeat(' ', 1024);
    @ob_flush();
    @flush();
}

function detect_table_column(PDO $pdo, string $table, array $candidates): ?string {
    try {
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[strtolower((string)$r['Field'])] = (string)$r['Field'];
        }
        foreach ($candidates as $c) {
            $k = strtolower($c);
            if (isset($cols[$k])) return $cols[$k];
        }
    } catch (Throwable $e) {}
    return null;
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function get_columns_map(PDO $pdo, string $table): array {
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $field = (string)$r['Field'];
            $cols[strtolower($field)] = $field;
        }
    } catch (Throwable $e) {}
    return $cols;
}

// ─────────────────────────────
// CMM 템플릿 생성(자화 성적서 발행용)
//  - ZIP 안에 CMM/ 폴더로 포함
//  - OQC의 Tool#Cavity(=AK10 순서) 그대로 CMM 템플릿 헤더에 주입
//  - IR BASE: 헤더 E2부터 (시트: OQC Raw data)
//  - Z CARRIER: 헤더 G2부터 (시트: OQC RawData)
//  - 여기서는 CMM 값을 채우지 않음(빈 칸 유지)
// ─────────────────────────────

function cmm_find_template_path(string $modelName): ?string {
    $candidates = [];
    $baseDir = JTMES_ROOT;

    // 템플릿 루트(구조 변경 대응): $TEMPLATES_ROOT(전역) 우선, 없으면 탐색
    $tplRoot = null;
    if (isset($GLOBALS['TEMPLATES_ROOT']) && is_string($GLOBALS['TEMPLATES_ROOT']) && $GLOBALS['TEMPLATES_ROOT'] !== '') {
        $tplRoot = $GLOBALS['TEMPLATES_ROOT'];
    }
    if (!$tplRoot) {
        $tplRoot = $baseDir . DIRECTORY_SEPARATOR . 'Templates';
        if (!is_dir($tplRoot)) { $tplRoot = $baseDir . DIRECTORY_SEPARATOR . 'templates'; }
        if (!is_dir($tplRoot)) { $tplRoot = $baseDir; }
    }

    // CMM templates 폴더 후보
    $cmmDir = $tplRoot . DIRECTORY_SEPARATOR . 'CMM templates';
    if (!is_dir($cmmDir)) { $cmmDir = $tplRoot . DIRECTORY_SEPARATOR . 'cmm templates'; }
    if (!is_dir($cmmDir)) { $cmmDir = $baseDir . DIRECTORY_SEPARATOR . 'CMM templates'; } // 기존 구조 호환

    if (is_dir($cmmDir)) {
        if ($modelName === 'MEM-IR-BASE') {
            $candidates[] = $cmmDir . DIRECTORY_SEPARATOR . 'OQC_Raceway_Memphis_MP_IR_Base_.xlsx';
            $candidates[] = $cmmDir . DIRECTORY_SEPARATOR . 'OQC_Raceway_Memphis_MP_IR_Base.xlsx';
            $candidates[] = $cmmDir . DIRECTORY_SEPARATOR . 'Memphis_IR_Base_A2.0_Raceway_Plot_template_.xlsx';
        } elseif ($modelName === 'MEM-Z-CARRIER') {
            $candidates[] = $cmmDir . DIRECTORY_SEPARATOR . 'OQC_Raceway_Memphis_MP_Z_carrier_.xlsx';
            $candidates[] = $cmmDir . DIRECTORY_SEPARATOR . 'OQC_Raceway_Memphis_MP_Z_carrier.xlsx';
            $candidates[] = $cmmDir . DIRECTORY_SEPARATOR . 'Memphis_Z_carrier_A2.0_raceway_Plot_template_.xlsx';
        }
    }

    // 혹시 폴더명이 달라진 경우를 대비(느슨하게)
    foreach (glob($baseDir . DIRECTORY_SEPARATOR . 'CMM*template*', GLOB_ONLYDIR) as $dir) {
        if ($modelName === 'MEM-IR-BASE') {
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'OQC_Raceway_Memphis_MP_IR_Base_.xlsx';
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'OQC_Raceway_Memphis_MP_IR_Base.xlsx';
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'Memphis_IR_Base_A2.0_Raceway_Plot_template_.xlsx';
        } elseif ($modelName === 'MEM-Z-CARRIER') {
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'OQC_Raceway_Memphis_MP_Z_carrier_.xlsx';
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'OQC_Raceway_Memphis_MP_Z_carrier.xlsx';
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'Memphis_Z_carrier_A2.0_raceway_Plot_template_.xlsx';
        }
    }

    foreach ($candidates as $p) {
        if (is_file($p)) return $p;
    }
    return null;
}

function cmm_excel_col_to_num(string $col): int {
    $col = strtoupper(trim($col));
    $n = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $ch = ord($col[$i]);
        if ($ch < 65 || $ch > 90) continue;
        $n = $n * 26 + ($ch - 64);
    }
    return $n;
}

function cmm_excel_num_to_col(int $n): string {
    $s = '';
    while ($n > 0) {
        $r = ($n - 1) % 26;
        $s = chr(65 + $r) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

// ✅ Excel 호환성 보강
// - row 안의 <c> (cell) 노드는 반드시 "열 순서"(A,B,...,Z,AA,AB...)대로 정렬되어야 함.
//   appendChild로 누적되면 Excel이 파일을 "복구"하면서 일부 셀을 제거(=데이터 중간 공백)할 수 있음.
// - 또한 row.@spans 가 실제 셀 범위를 커버하지 않으면 Excel이 복구할 수 있음(예: spans="1:20"인데 AJ열까지 존재).
// 아래 유틸은 (1) 셀 정렬 (2) spans 갱신 (3) dimension 범위 계산에 사용.

function cmm_cellref_to_colrow(string $ref): array {
    $ref = strtoupper(trim($ref));
    if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) return [0, 0];
    $col = cmm_excel_col_to_num($m[1]);
    $row = (int)$m[2];
    return [$col, $row];
}

function cmm_parse_dimension_ref(string $ref): array {
    $ref = strtoupper(trim($ref));
    if ($ref === '') return [1, 1, 1, 1];
    if (strpos($ref, ':') === false) {
        [$c, $r] = cmm_cellref_to_colrow($ref);
        return [$c, $r, $c, $r];
    }
    [$a, $b] = explode(':', $ref, 2);
    [$c1, $r1] = cmm_cellref_to_colrow($a);
    [$c2, $r2] = cmm_cellref_to_colrow($b);
    if ($c1 <= 0 || $r1 <= 0 || $c2 <= 0 || $r2 <= 0) return [1, 1, 1, 1];
    $minC = min($c1, $c2);
    $minR = min($r1, $r2);
    $maxC = max($c1, $c2);
    $maxR = max($r1, $r2);
    return [$minC, $minR, $maxC, $maxR];
}

function cmm_set_dimension_ref(DOMDocument $doc, int $minC, int $minR, int $maxC, int $maxR): void {
    $minC = max(1, $minC);
    $minR = max(1, $minR);
    $maxC = max($minC, $maxC);
    $maxR = max($minR, $maxR);
    $a = cmm_excel_num_to_col($minC) . $minR;
    $b = cmm_excel_num_to_col($maxC) . $maxR;
    $dim = $doc->getElementsByTagName('dimension')->item(0);
    if (!$dim) {
        $ws = $doc->getElementsByTagName('worksheet')->item(0);
        if ($ws) {
            $dim = $doc->createElement('dimension');
            $dim->setAttribute('ref', $a . ':' . $b);
            $ws->insertBefore($dim, $ws->firstChild);
        }
        return;
    }
    $dim->setAttribute('ref', $a . ':' . $b);
}

function cmm_sort_row_cells_and_fix_spans(DOMElement $rowEl): array {
    $cells = [];
    $others = [];

    // childNodes는 live list라서 먼저 스냅샷으로 뽑음
    $snapshot = [];
    foreach ($rowEl->childNodes as $ch) { $snapshot[] = $ch; }

    foreach ($snapshot as $ch) {
        if ($ch instanceof DOMElement && $ch->localName === 'c') {
            $r = $ch->getAttribute('r');
            [$colNum, $rowNum] = cmm_cellref_to_colrow($r);
            $cells[] = ['col' => $colNum, 'node' => $ch];
        } else {
            $others[] = $ch;
        }
    }

    usort($cells, function($a, $b) {
        return $a['col'] <=> $b['col'];
    });

    // clear
    while ($rowEl->firstChild) { $rowEl->removeChild($rowEl->firstChild); }

    $minC = 999999;
    $maxC = 0;
    foreach ($cells as $it) {
        $rowEl->appendChild($it['node']);
        $minC = min($minC, (int)$it['col']);
        $maxC = max($maxC, (int)$it['col']);
    }
    foreach ($others as $it) { $rowEl->appendChild($it); }

    if ($maxC > 0) {
        // 기존 min span은 가급적 유지(예: 2:10 같은 템플릿)
        $sp = $rowEl->getAttribute('spans');
        $minSpan = $minC;
        if ($sp && preg_match('/^(\d+):(\d+)$/', $sp, $m)) {
            $minSpan = (int)$m[1];
        }
        $rowEl->setAttribute('spans', $minSpan . ':' . $maxC);
    }

    return [$minC === 999999 ? 0 : $minC, $maxC];
}

function cmm_zip_find_sheet_xml_path(ZipArchive $zip, string $sheetName): ?string {
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml === false || $relsXml === false) return null;

    $wb = new DOMDocument();
    $wb->loadXML($wbXml);
    $rels = new DOMDocument();
    $rels->loadXML($relsXml);

    $targetRid = null;
    foreach ($wb->getElementsByTagName('sheet') as $sheet) {
        $name = $sheet->getAttribute('name');
        if ($name === $sheetName) {
            // r:id 는 namespace로 붙어있을 수 있으니 attribute 전부 확인
            foreach ($sheet->attributes as $attr) {
                if ($attr->localName === 'id') {
                    $targetRid = $attr->value;
                    break;
                }
            }
            break;
        }
    }
    if (!$targetRid) return null;

    $target = null;
    foreach ($rels->getElementsByTagName('Relationship') as $rel) {
        if ($rel->getAttribute('Id') === $targetRid) {
            $target = $rel->getAttribute('Target');
            break;
        }
    }
    if (!$target) return null;

    $target = ltrim($target, '/');
    if (strpos($target, 'xl/') === 0) {
        return $target;
    }
    return 'xl/' . $target;
}

function cmm_xlsx_set_inline_strings(string $xlsxPath, string $sheetName, array $cellToText): void {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        throw new RuntimeException("CMM 템플릿을 열 수 없습니다: {$xlsxPath}");
    }
    $sheetXmlPath = cmm_zip_find_sheet_xml_path($zip, $sheetName);
    if (!$sheetXmlPath) {
        $zip->close();
        throw new RuntimeException("CMM 시트를 찾을 수 없습니다: {$sheetName}");
    }
    $sheetXml = $zip->getFromName($sheetXmlPath);
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException("CMM 시트 XML을 읽을 수 없습니다: {$sheetXmlPath}");
    }

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = false;
    $doc->loadXML($sheetXml);

    $sheetDataList = $doc->getElementsByTagName('sheetData');
    if ($sheetDataList->length === 0) {
        $zip->close();
        throw new RuntimeException('CMM sheetData가 없습니다');
    }
    $sheetData = $sheetDataList->item(0);

    // row 캐시
    $rowMap = [];
    foreach ($sheetData->getElementsByTagName('row') as $row) {
        $r = $row->getAttribute('r');
        if ($r !== '') $rowMap[(int)$r] = $row;
    }

    // dimension 초기값(템플릿 범위 기준으로 시작)
    $dimEl = $doc->getElementsByTagName('dimension')->item(0);
    [$minC, $minR, $maxC, $maxR] = [1, 1, 1, 1];
    if ($dimEl) {
        [$minC, $minR, $maxC, $maxR] = cmm_parse_dimension_ref((string)$dimEl->getAttribute('ref'));
    }
    $touchedRows = [];

    foreach ($cellToText as $cellRef => $text) {
        $cellRef = strtoupper(trim($cellRef));
        if ($cellRef === '') continue;

        if (!preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $m)) continue;
        $rowNum = (int)$m[2];
        [$colNum, $_rn] = cmm_cellref_to_colrow($cellRef);
        if ($colNum > 0) {
            $minC = min($minC, $colNum);
            $maxC = max($maxC, $colNum);
        }
        if ($rowNum > 0) {
            $minR = min($minR, $rowNum);
            $maxR = max($maxR, $rowNum);
            $touchedRows[$rowNum] = true;
        }

        if (!isset($rowMap[$rowNum])) {
            // 템플릿에 보통 row가 있지만, 없으면 생성 (✅ Excel 호환: row r 오름차순 유지)
            $newRow = $doc->createElement('row');
            $newRow->setAttribute('r', (string)$rowNum);

            // spans는 기존 첫 row의 값을 따라가고, 없으면 1:36
            $spans = '1:36';
            foreach ($sheetData->childNodes as $ch) {
                if ($ch instanceof DOMElement && $ch->localName === 'row') {
                    $sv = $ch->getAttribute('spans');
                    if ($sv) $spans = $sv;
                    break;
                }
            }
            if ($spans) $newRow->setAttribute('spans', $spans);

            // ✅ row는 r 오름차순이어야 Excel이 깨지지 않음 (기존은 끝에 append되어 오류 발생)
            $insertBefore = null;
            foreach ($sheetData->childNodes as $ch) {
                if (!($ch instanceof DOMElement)) continue;
                if ($ch->localName !== 'row') continue;
                $r = (int)$ch->getAttribute('r');
                if ($r > $rowNum) { $insertBefore = $ch; break; }
            }
            if ($insertBefore) $sheetData->insertBefore($newRow, $insertBefore);
            else $sheetData->appendChild($newRow);

            $rowMap[$rowNum] = $newRow;

            // dimension(ref) 최소 row 보정 (예: A2:AJ774 -> A1:AJ774)
            $dim = $doc->getElementsByTagName('dimension')->item(0);
            if ($dim) {
                $ref = $dim->getAttribute('ref');
                if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $ref, $m2)) {
                    $c1 = $m2[1]; $r1 = (int)$m2[2]; $c2 = $m2[3]; $r2 = (int)$m2[4];
                    if ($r1 > $rowNum) {
                        $dim->setAttribute('ref', $c1 . $rowNum . ':' . $c2 . $r2);
                    }
                }
            }
        }
        $rowEl = $rowMap[$rowNum];

        // 기존 cell 찾기
        $found = null;
        foreach ($rowEl->getElementsByTagName('c') as $c) {
            if (strtoupper($c->getAttribute('r')) === $cellRef) {
                $found = $c;
                break;
            }
        }
        if (!$found) {
            $found = $doc->createElement('c');
            $found->setAttribute('r', $cellRef);
            $rowEl->appendChild($found);
        }

        // 내용 교체: inlineStr
        // 기존 자식 제거
        while ($found->firstChild) {
            $found->removeChild($found->firstChild);
        }
        $found->setAttribute('t', 'inlineStr');

        $is = $doc->createElement('is');
        $t = $doc->createElement('t');
        // Excel은 leading/trailing space를 xml:space="preserve"로 처리 필요
        if (preg_match('/^\s|\s$/', (string)$text)) {
            $t->setAttribute('xml:space', 'preserve');
        }
        $t->appendChild($doc->createTextNode((string)$text));
        $is->appendChild($t);
        $found->appendChild($is);
    }

    // ✅ (중요) cell 정렬 + spans 보정 (Excel 복구 경고/데이터 누락 방지)
    foreach (array_keys($touchedRows) as $rn) {
        if (!isset($rowMap[$rn])) continue;
        [$rMinC, $rMaxC] = cmm_sort_row_cells_and_fix_spans($rowMap[$rn]);
        if ($rMinC > 0) $minC = min($minC, $rMinC);
        if ($rMaxC > 0) $maxC = max($maxC, $rMaxC);
    }

    // dimension(ref) 최종 보정
    cmm_set_dimension_ref($doc, $minC, $minR, $maxC, $maxR);

    $newXml = $doc->saveXML();
    $zip->addFromString($sheetXmlPath, $newXml);
    $zip->close();
}


function cmm_xlsx_set_numbers(string $xlsxPath, string $sheetName, array $cellToNumber): void {
    // 값은 숫자만(문자/공백은 호출부에서 제외)
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        throw new RuntimeException("CMM 템플릿을 열 수 없습니다: {$xlsxPath}");
    }
    $sheetXmlPath = cmm_zip_find_sheet_xml_path($zip, $sheetName);
    if (!$sheetXmlPath) {
        $zip->close();
        throw new RuntimeException("CMM 시트를 찾을 수 없습니다: {$sheetName}");
    }
    $sheetXml = $zip->getFromName($sheetXmlPath);
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException("CMM 시트 XML을 읽을 수 없습니다: {$sheetXmlPath}");
    }

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = false;
    $doc->loadXML($sheetXml);

    $sheetDataList = $doc->getElementsByTagName('sheetData');
    if ($sheetDataList->length === 0) {
        $zip->close();
        throw new RuntimeException('CMM sheetData가 없습니다');
    }
    $sheetData = $sheetDataList->item(0);

    // row 캐시
    $rowMap = [];
    foreach ($sheetData->getElementsByTagName('row') as $row) {
        $r = $row->getAttribute('r');
        if ($r !== '') $rowMap[(int)$r] = $row;
    }

    // dimension 초기값(템플릿 범위 기준으로 시작)
    $dimEl = $doc->getElementsByTagName('dimension')->item(0);
    [$minC, $minR, $maxC, $maxR] = [1, 1, 1, 1];
    if ($dimEl) {
        [$minC, $minR, $maxC, $maxR] = cmm_parse_dimension_ref((string)$dimEl->getAttribute('ref'));
    }
    $touchedRows = [];

    foreach ($cellToNumber as $cellRef => $num) {
        $cellRef = strtoupper(trim((string)$cellRef));
        if ($cellRef === '') continue;
        if ($num === null) continue;
        $numStr = trim((string)$num);
        if ($numStr === '') continue;

        if (!preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $m)) continue;
        $rowNum = (int)$m[2];
        [$colNum, $_rn] = cmm_cellref_to_colrow($cellRef);
        if ($colNum > 0) {
            $minC = min($minC, $colNum);
            $maxC = max($maxC, $colNum);
        }
        if ($rowNum > 0) {
            $minR = min($minR, $rowNum);
            $maxR = max($maxR, $rowNum);
            $touchedRows[$rowNum] = true;
        }

        if (!isset($rowMap[$rowNum])) {
            // row 생성 (r 오름차순 유지)
            $newRow = $doc->createElement('row');
            $newRow->setAttribute('r', (string)$rowNum);

            $spans = '1:36';
            foreach ($sheetData->childNodes as $ch) {
                if ($ch instanceof DOMElement && $ch->localName === 'row') {
                    $sv = $ch->getAttribute('spans');
                    if ($sv) $spans = $sv;
                    break;
                }
            }
            if ($spans) $newRow->setAttribute('spans', $spans);

            $insertBefore = null;
            foreach ($sheetData->childNodes as $ch) {
                if (!($ch instanceof DOMElement)) continue;
                if ($ch->localName !== 'row') continue;
                $r = (int)$ch->getAttribute('r');
                if ($r > $rowNum) { $insertBefore = $ch; break; }
            }
            if ($insertBefore) $sheetData->insertBefore($newRow, $insertBefore);
            else $sheetData->appendChild($newRow);

            $rowMap[$rowNum] = $newRow;

            // dimension 최소 row 보정
            $dim = $doc->getElementsByTagName('dimension')->item(0);
            if ($dim) {
                $ref = $dim->getAttribute('ref');
                if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $ref, $m2)) {
                    $c1 = $m2[1]; $r1 = (int)$m2[2]; $c2 = $m2[3]; $r2 = (int)$m2[4];
                    if ($r1 > $rowNum) {
                        $dim->setAttribute('ref', $c1 . $rowNum . ':' . $c2 . $r2);
                    }
                }
            }
        }

        $rowEl = $rowMap[$rowNum];

        // cell 찾기
        $found = null;
        foreach ($rowEl->getElementsByTagName('c') as $c) {
            if (strtoupper($c->getAttribute('r')) === $cellRef) { $found = $c; break; }
        }
        if (!$found) {
            $found = $doc->createElement('c');
            $found->setAttribute('r', $cellRef);
            $rowEl->appendChild($found);
        }

        // 기존 자식 제거
        while ($found->firstChild) {
            $found->removeChild($found->firstChild);
        }
        // 숫자 타입: t 제거
        if ($found->hasAttribute('t')) $found->removeAttribute('t');

        $v = $doc->createElement('v');
        $v->appendChild($doc->createTextNode($numStr));
        $found->appendChild($v);
    }

    // ✅ (중요) cell 정렬 + spans 보정 (Excel 복구 경고/데이터 누락 방지)
    foreach (array_keys($touchedRows) as $rn) {
        if (!isset($rowMap[$rn])) continue;
        [$rMinC, $rMaxC] = cmm_sort_row_cells_and_fix_spans($rowMap[$rn]);
        if ($rMinC > 0) $minC = min($minC, $rMinC);
        if ($rMaxC > 0) $maxC = max($maxC, $rMaxC);
    }

    // dimension(ref) 최종 보정
    cmm_set_dimension_ref($doc, $minC, $minR, $maxC, $maxR);

    $newXml = $doc->saveXML();
    $zip->addFromString($sheetXmlPath, $newXml);
    $zip->close();
}

function cmm_make_template_xlsx(PDO $pdo, string $modelName, array $toolCavityList, array $headerIdList, string $shippingDateStr, string $cmmDir): ?string {
    if ($modelName !== 'MEM-IR-BASE' && $modelName !== 'MEM-Z-CARRIER') return null;
    $tpl = cmm_find_template_path($modelName);
    if (!$tpl) return null;

    if (!is_dir($cmmDir)) {
        @mkdir($cmmDir, 0777, true);
    }

    $ymd = @date('Ymd', strtotime($shippingDateStr));
    if (!$ymd) $ymd = @date('Ymd');

    
// ✅ 출력 파일명 규칙: 템플릿 파일명 끝의 "_.xlsx" 를 "_YYYYMMDD.xlsx" 로 치환
$tplBaseName = basename($tpl);
if (preg_match('/_\.xlsx$/i', $tplBaseName)) {
    $outName = preg_replace('/_\.xlsx$/i', '_' . $ymd . '.xlsx', $tplBaseName);
} else {
    $outName = preg_replace('/\.xlsx$/i', '_' . $ymd . '.xlsx', $tplBaseName);
}

if ($modelName === 'MEM-IR-BASE') {
    $sheetName = 'OQC Raw data';
    $startCol = 'E';
    // IR BASE: 헤더는 E2부터, 값은 E3부터 채움
    $headerRow = 2;   // E2
    $dataRow   = 3;   // E3
} else {
    $sheetName = 'OQC RawData';
    $startCol = 'G';
    $headerRow = 2;   // G2
    $dataRow   = 3;   // G3 (필요시 템플릿 유지)
}

    $outPath = $cmmDir . DIRECTORY_SEPARATOR . $outName;
    if (!@copy($tpl, $outPath)) {
        return null;
    }

    // ✅ 열 조립 규칙:
    //  - 성적서 생성 시 사용한 OQC의 AK10 Tool#Cavity 순서(toolCavityList)를 그대로 열 순서로 사용
    //  - 값은 headerIdList(같은 슬롯 인덱스)로 매핑해서 cmm_row에서 조회 후 주입
    //  - header_id가 중복되면(32칸 채우기 등) 동일 데이터를 여러 열에 복제

    // 1) 컬럼(열) 리스트 구성 (toolCavityList 순서 유지)
    $cols = [];
    foreach ($toolCavityList as $i => $tc) {
        $tc = trim((string)$tc);
        if ($tc === '') continue;
        $hid = $headerIdList[$i] ?? null;
        $hid = is_numeric($hid) ? (int)$hid : 0;
        $cols[] = ['tc' => $tc, 'hid' => $hid];
    }

    // 헤더에 Tool#Cavity 주입
    $startNum = cmm_excel_col_to_num($startCol);
    $cells = [];
    foreach ($cols as $j => $c) {
        $col = cmm_excel_num_to_col($startNum + $j);
        $cells[$col . $headerRow] = (string)$c['tc'];
    }
    if (!empty($cells)) {
        cmm_xlsx_set_inline_strings($outPath, $sheetName, $cells);
    }

    // 2) header_id → 엑셀 열 번호(복수 가능) 매핑
    $hidToColNums = [];
    foreach ($cols as $j => $c) {
        $hid = (int)($c['hid'] ?? 0);
        if ($hid <= 0) continue;
        $hidToColNums[$hid][] = $startNum + $j;
    }
    if (empty($hidToColNums)) {
        // header_id가 없으면 헤더만 만든다(빈칸 유지)
        return $outPath;
    }

    $uniqHids = array_keys($hidToColNums);

    // 3) DB에서 CMM 값 조회 (header_id 기준으로만 조회: 날짜/소스 섞임 방지)
    $in = implode(',', array_fill(0, count($uniqHids), '?'));
    $sql = "SELECT header_id, row_index, val_num FROM cmm_row WHERE model_name=? AND header_id IN ($in) ORDER BY header_id, row_index";
    $stmt = $pdo->prepare($sql);
    $bind = array_merge([$modelName], $uniqHids);
    $stmt->execute($bind);

    $cellToNum = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hid = (int)($r['header_id'] ?? 0);
        if ($hid <= 0) continue;
        $rowIndex = (int)($r['row_index'] ?? 0);
        if ($rowIndex <= 0) continue;
        // 값은 숫자만 저장되어 있다고 가정(문자/공백은 NULL)
        if (!array_key_exists('val_num', $r)) continue;
        if ($r['val_num'] === null) continue; // NULL은 빈칸 유지
        $numStr = trim((string)$r['val_num']);
        // '0' 도 유효
        if ($numStr === '' && $numStr !== '0') continue;

        $excelRow = $dataRow + ($rowIndex - 1);
        $colNums = $hidToColNums[$hid] ?? [];
        if (!$colNums) continue;
        foreach ($colNums as $colNum) {
            $colLetter = cmm_excel_num_to_col((int)$colNum);
            $cellToNum[$colLetter . $excelRow] = $numStr;
        }
    }

    if (!empty($cellToNum)) {
        cmm_xlsx_set_numbers($outPath, $sheetName, $cellToNum);
    }

    return $outPath;
}

function pick_col(array $colsMap, array $candidates): ?string {
    foreach ($candidates as $c) {
        $k = strtolower((string)$c);
        if (isset($colsMap[$k])) return $colsMap[$k];
    }
    return null;
}


// ─────────────────────────────
// (추가) report artifacts 삭제(폴더/파일)
//  - exports/reports/rf_{report_id}/ 를 취소 시 완전 삭제
//  - Windows(XAMPP)에서도 안전하게 동작하도록 baseDir 아래만 삭제
// ─────────────────────────────
if (!function_exists('dp_path_is_under')) {
    function dp_path_is_under(string $path, string $baseDir): bool {
        $rp = @realpath($path);
        $rb = @realpath($baseDir);
        if (!$rb) return false;
        // 경로가 존재하지 않으면 parent 기준으로라도 체크
        if (!$rp) {
            $rp = @realpath(dirname($path));
            if (!$rp) return false;
        }
        $rb = rtrim(str_replace('\\', '/', $rb), '/') . '/';
        $rp = rtrim(str_replace('\\', '/', $rp), '/') . '/';
        return (strpos($rp, $rb) === 0);
    }
}

if (!function_exists('dp_rm_rf')) {
    function dp_rm_rf(string $path, string $baseDir): bool {
        if ($path === '') return true;
        if (!file_exists($path)) return true;

        if (!dp_path_is_under($path, $baseDir)) {
            throw new Exception('Refuse delete outside baseDir: ' . $path);
        }

        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }

        // dir
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        return @rmdir($path);
    }
}

if (!function_exists('dp_delete_report_artifacts')) {
    function dp_delete_report_artifacts(int $reportId, bool $dry = false): array {
        $base = JTMES_ROOT . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'reports';
        $dir  = $base . DIRECTORY_SEPARATOR . 'rf_' . (int)$reportId;
        $out = ['ok' => true, 'dry' => $dry, 'base' => $base, 'dir' => $dir];

        if (!is_dir($base)) {
            // 저장폴더 자체가 없으면 삭제할 것도 없음
            return $out;
        }

        if (!file_exists($dir)) {
            return $out;
        }

        if ($dry) {
            $out['ok'] = true;
            $out['skipped'] = 'dry-run';
            return $out;
        }

        try {
            $ok = dp_rm_rf($dir, $base);
            if (!$ok) {
                $out['ok'] = false;
                $e = error_get_last();
                $out['error'] = $e['message'] ?? 'delete failed';
            }
        } catch (Throwable $e) {
            $out['ok'] = false;
            $out['error'] = $e->getMessage();
        }
        return $out;
    }
}


function safe_col(PDO $pdo, string $table, ?string $col): ?string {
    // DB 스키마가 환경마다 달라서(컬럼명 차이, 구버전 테이블 등) meta가 잘못 잡히는 경우가 있다.
    // ✅ 실제 존재하는 컬럼만 사용하도록 테이블별 컬럼맵을 캐시해서 재검증한다.
    if ($table === '' || $col === null || $col === '') return null;
    static $cache = [];
    $tk = strtolower($table);
    if (!isset($cache[$tk])) {
        $cache[$tk] = get_columns_map($pdo, $table);
    }
    $ck = strtolower($col);
    return $cache[$tk][$ck] ?? null;
}

function extract_tool_letter(string $s): ?string {
    // "A", "Tool A", "MoldType: B" 등에서 첫 글자(A~Z)만 추출
    if (preg_match('/([A-Z])/i', $s, $m)) return strtoupper($m[1]);
    return null;
}

function parse_tool_cavity_pair(string $toolCavity): ?array {
    // 허용 포맷 예:
    //  - "A#1", "A # 1"
    //  - "A-1", "A / 1", "A_1", "A 1", "A1"
    //  - 뒤에 "/Cav" 같은 꼬리글이 붙어도 제거해서 파싱 시도
    $s = trim($toolCavity);
    if ($s === '') return null;

    // 꼬리 제거
    $s = preg_replace('/\s*\/\s*CAV.*$/i', '', $s);
    $s = preg_replace('/\s*CAV.*$/i', '', $s);
    $s = trim($s);

    // A#1 / A-1 / A 1 / A_1 / A/1
    if (preg_match('/^\s*([A-Z])\s*(?:#|\/|\-|_|\s)+\s*(\d+)\s*$/i', $s, $m)) {
        return [strtoupper($m[1]), (int)$m[2]];
    }
    // A1
    if (preg_match('/^\s*([A-Z])\s*(\d+)\s*$/i', $s, $m)) {
        return [strtoupper($m[1]), (int)$m[2]];
    }
    return null;
}

function normalize_tool_cavity_key(string $toolCavity): ?string {
    $p = parse_tool_cavity_pair($toolCavity);
    if (!$p) return null;
    return $p[0] . '#' . $p[1];
}

function sort_tool_cavity_pairs(array $pairs): array {
    usort($pairs, function($a, $b) {
        $pa = parse_tool_cavity_pair((string)$a);
        $pb = parse_tool_cavity_pair((string)$b);
        if (!$pa && !$pb) return strcmp((string)$a, (string)$b);
        if (!$pa) return 1;
        if (!$pb) return -1;
        [$ta, $ca] = $pa;
        [$tb, $cb] = $pb;
        if ($ta === $tb) return $ca <=> $cb;
        return strcmp($ta, $tb);
    });
    return $pairs;
}

function disconnect_book($wb): void {
    try {
        if ($wb && method_exists($wb, 'disconnectWorksheets')) $wb->disconnectWorksheets();
    } catch (Throwable $e) {}
    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
}




// ─────────────────────────────────────────────────────────────
// 모듈 로드 (로직 무변경: 기존 함수 정의 블록을 lib/* 로 분리)
// ─────────────────────────────────────────────────────────────
require_once JTMES_ROOT . '/lib/xlsx_zip_patch.php';
require_once JTMES_ROOT . '/lib/oqc_rowdata.php';
require_once JTMES_ROOT . '/lib/oqc_report.php';

// ─────────────────────────────────────────────────────────────
// 납품처별 측정일/예약 컬럼 선택 (LGIT vs JAWHA)
//  - LGIT(엘지이노텍): meas_date / meas_date2
//  - JAWHA(자화전자) : jmeas_date / jmeas_date2
// ※ 생성(예약/긴급)뿐 아니라 "취소/롤백(삭제)"에서도 동일 규칙을 적용해야 함.
// ─────────────────────────────────────────────────────────────

function ship_to_norm_code(string $shipTo): string {
    $s = trim($shipTo);
    if ($s === '') return '';
    // 코드로 넘어오는 경우도 허용
    if (strcasecmp($s, 'LGIT') === 0) return 'LGIT';
    if (strcasecmp($s, 'JAWHA') === 0) return 'JAWHA';
    if (mb_strpos($s, '엘지이노텍') !== false) return 'LGIT';
    if (mb_strpos($s, '자화') !== false) return 'JAWHA';
    return '';
}

function pick_mark_cols_by_ship_to(string $shipTo): array {
    $code = ship_to_norm_code($shipTo);
    if ($code === 'JAWHA') {
        return ['col1' => 'jmeas_date', 'col2' => 'jmeas_date2', 'code' => 'JAWHA'];
    }
    // default: LGIT(or unknown)
    return ['col1' => 'meas_date', 'col2' => 'meas_date2', 'code' => ($code === 'LGIT' ? 'LGIT' : '')];
}

/**
 * report_finish.parts_json 파싱
 * - 과거: ["PART1","PART2",...]
 * - 현재: {"parts":[{"part":"P","ship_qty":..},...],"total_ship_qty":..}
 */
function report_finish_parts_list(string $partsJson): array {
    $partsJson = trim($partsJson);
    if ($partsJson === '') return [];
    $tmp = json_decode($partsJson, true);
    if (!is_array($tmp)) return [];

    $parts = [];
    // (1) object 형태
    if (isset($tmp['parts']) && is_array($tmp['parts'])) {
        foreach ($tmp['parts'] as $it) {
            if (is_array($it)) {
                $p = trim((string)($it['part'] ?? ''));
                if ($p !== '') $parts[] = $p;
            } else {
                $p = trim((string)$it);
                if ($p !== '') $parts[] = $p;
            }
        }
    }

    // (2) 과거 list 형태
    if (empty($parts)) {
        foreach ($tmp as $it) {
            $p = trim((string)$it);
            if ($p !== '') $parts[] = $p;
        }
    }
    $parts = array_values(array_unique($parts));
    return $parts;
}


/**
 * (추가) 발행(build) 시 실제로 마킹한 header_id/컬럼/날짜를 누적 기록해서
 * report_finish.parts_json에 함께 저장 → 취소(롤백) 시 날짜가 달라도/경로가 달라도 정확히 되돌림.
 *
 * - 전제: 마킹은 (대부분) NULL → YYYY-MM-DD 로만 이루어지므로 prev는 NULL이지만,
 *         롤백은 안전하게 "현재값의 날짜(LEFT 10) == 당시 마킹날짜" 조건을 걸어
 *         이후 다른 발행에서 재사용된 header를 실수로 지우지 않게 한다.
 */
function oqc_marklog_reset(): void {
    $GLOBALS['OQC_MARK_LOG'] = [];
}

function oqc_marklog_add(int $headerId, string $col, string $dateYmd, array $ctx = []): void {
    if ($headerId <= 0) return;
    $col = trim($col);
    $dateYmd = trim($dateYmd);
    if ($col === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) return;
    if (!isset($GLOBALS['OQC_MARK_LOG']) || !is_array($GLOBALS['OQC_MARK_LOG'])) $GLOBALS['OQC_MARK_LOG'] = [];
    $GLOBALS['OQC_MARK_LOG'][] = [
        'hid' => $headerId,
        'col' => $col,
        'd'   => $dateYmd,
        'ctx' => $ctx,
    ];
}

function oqc_marklog_add_many(array $headerIds, string $col, string $dateYmd, array $ctx = []): void {
    if (empty($headerIds)) return;
    $uniq = [];
    foreach ($headerIds as $v) {
        $id = (int)$v;
        if ($id > 0) $uniq[$id] = true;
    }
    if (empty($uniq)) return;
    foreach (array_keys($uniq) as $id) {
        oqc_marklog_add((int)$id, $col, $dateYmd, $ctx);
    }
}

/**
 * report_finish.parts_json 에 저장할 compact 포맷
 *  - g: [{col:'meas_date', d:'2025-12-12', ids:[1,2,3]}, ...]
 */
function oqc_marklog_export_grouped(): array {
    $log = $GLOBALS['OQC_MARK_LOG'] ?? [];
    if (!is_array($log) || empty($log)) return ['v' => 1, 'g' => []];

    $grp = []; // col => date => set(ids)
    foreach ($log as $it) {
        if (!is_array($it)) continue;
        $hid = (int)($it['hid'] ?? 0);
        $col = trim((string)($it['col'] ?? ''));
        $d   = trim((string)($it['d'] ?? ''));
        if ($hid <= 0 || $col === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $grp[$col][$d][$hid] = true;
    }

    $out = ['v' => 1, 'g' => []];
    foreach ($grp as $col => $dates) {
        foreach ($dates as $d => $set) {
            $ids = array_map('intval', array_keys($set));
            sort($ids);
            $out['g'][] = ['col' => $col, 'd' => $d, 'ids' => $ids];
        }
    }
    return $out;
}

function report_finish_marklog_get(string $partsJson): ?array {
    $partsJson = trim($partsJson);
    if ($partsJson === '') return null;
    $tmp = json_decode($partsJson, true);
    if (!is_array($tmp)) return null;
    $ml = $tmp['mark_log'] ?? null;
    if (!is_array($ml)) return null;
    if (!isset($ml['g']) || !is_array($ml['g'])) return null;
    return $ml;
}

/**
 * mark_log 기반 롤백 (header_id + col + date guard)
 */
function oqc_rollback_marks_by_marklog(PDO $pdo, array $meta, array $markLog, bool $dry = false): array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    $hId = (string)($h['id'] ?? 'id');

    if ($tHeader === '' || !is_array($h)) {
        return ['ok' => false, 'error' => 'no meta(t_header)'];
    }

    // 컬럼 안전화
    if (function_exists('safe_col')) {
        $hIdSafe = safe_col($pdo, $tHeader, $hId) ?: 'id';
    } else {
        $hIdSafe = preg_match('/^[A-Za-z0-9_]+$/', $hId) ? $hId : 'id';
    }

    $groups = $markLog['g'] ?? [];
    if (!is_array($groups) || empty($groups)) {
        return ['ok' => true, 'target_rows' => 0, 'updated_rows' => 0, 'groups' => 0, 'cols' => []];
    }

    $target = 0;
    $updated = 0;
    $groupCnt = 0;
    $skipped = [];
    $colsUsed = [];

    foreach ($groups as $g) {
        if (!is_array($g)) continue;
        $col = trim((string)($g['col'] ?? ''));
        $d   = trim((string)($g['d'] ?? ''));
        $ids = $g['ids'] ?? [];
        if ($col === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !is_array($ids) || empty($ids)) continue;

        $ids2 = [];
        foreach ($ids as $v) {
            $id = (int)$v;
            if ($id > 0) $ids2[$id] = true;
        }
        $ids2 = array_keys($ids2);
        if (empty($ids2)) continue;

        if (function_exists('safe_col')) {
            $colSafe = safe_col($pdo, $tHeader, $col);
        } else {
            $colSafe = preg_match('/^[A-Za-z0-9_]+$/', $col) ? $col : null;
        }
        if (!$colSafe) {
            $skipped[] = $col;
            continue;
        }

        $groupCnt++;
        $colsUsed[$colSafe] = true;
        $target += count($ids2);
        if ($dry) continue;

        $chunkSize = 500;
        for ($off = 0; $off < count($ids2); $off += $chunkSize) {
            $chunk = array_slice($ids2, $off, $chunkSize);
            if (empty($chunk)) continue;
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            // ✅ date guard: 당시 찍힌 날짜와 동일할 때만 NULL 처리
            $sql = "UPDATE `{$tHeader}` SET `{$colSafe}` = NULL WHERE `{$hIdSafe}` IN ({$ph}) AND LEFT(`{$colSafe}`,10) = ?";
            $params = array_merge($chunk, [$d]);
            try {
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $updated += (int)$st->rowCount();
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'marklog rollback failed: ' . $e->getMessage(), 'col' => $colSafe];
            }
        }
    }

    return [
        'ok' => true,
        'target_rows' => $target,
        'updated_rows' => $updated,
        'groups' => $groupCnt,
        'cols' => array_values(array_keys($colsUsed)),
        'skipped_cols' => array_values(array_unique($skipped)),
        'dry' => $dry,
    ];
}
/**
 * 날짜(YYYY-MM-DD) 기준으로 oqc_header의 mark 컬럼을 NULL로 롤백
 * - ship_to에 따라 meas/jmeas 컬럼을 선택
 * - parts(품번) 목록이 있으면 해당 품번만 롤백(다른 납품처/다른 품번 영향 최소화)
 */
function oqc_rollback_marks_shipto(PDO $pdo, string $dateYmd, array $parts, bool $dry, string $shipTo): array {
    $cols = pick_mark_cols_by_ship_to($shipTo);
    $c1 = $cols['col1'];
    $c2 = $cols['col2'];

    // 품번 컬럼 탐색(프로젝트마다 컬럼명이 다를 수 있어 안전하게)
    $partCol = null;
    foreach (['model_name','part_name','model','part'] as $cand) {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM `oqc_header` LIKE " . $pdo->quote($cand));
            $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if ($r && isset($r['Field'])) { $partCol = $cand; break; }
        } catch (Throwable $e) {}
    }

    $where = "((`{$c1}` IS NOT NULL AND LEFT(`{$c1}`,10)=?) OR (`{$c2}` IS NOT NULL AND LEFT(`{$c2}`,10)=?))";
    $params = [$dateYmd, $dateYmd];

    if ($partCol && !empty($parts)) {
        $in = implode(',', array_fill(0, count($parts), '?'));
        $where .= " AND `{$partCol}` IN ({$in})";
        foreach ($parts as $p) $params[] = $p;
    }

    // 대상 수
    $cnt = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM `oqc_header` WHERE {$where}");
        $st->execute($params);
        $cnt = (int)($st->fetchColumn() ?? 0);
    } catch (Throwable $e) {}

    $affected = 0;
    if (!$dry) {
        $sql = "UPDATE `oqc_header`
                SET `{$c1}` = CASE WHEN (`{$c1}` IS NOT NULL AND LEFT(`{$c1}`,10)=?) THEN NULL ELSE `{$c1}` END,
                    `{$c2}` = CASE WHEN (`{$c2}` IS NOT NULL AND LEFT(`{$c2}`,10)=?) THEN NULL ELSE `{$c2}` END
                WHERE {$where}";
        $uParams = array_merge([$dateYmd, $dateYmd], $params);
        try {
            $st = $pdo->prepare($sql);
            $st->execute($uParams);
            $affected = (int)$st->rowCount();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'rollback update failed: ' . $e->getMessage(), 'ship_to' => $shipTo, 'col1' => $c1, 'col2' => $c2];
        }
    }

    return [
        'ok' => true,
        'ship_to' => $shipTo,
        'col1' => $c1,
        'col2' => $c2,
        'date' => $dateYmd,
        'part_col' => $partCol,
        'parts' => $parts,
        'target_rows' => $cnt,
        'updated_rows' => $affected,
        'dry' => $dry,
    ];
}



// ─────────────────────────────────────────────────────────────
// (추가) report_id 기반 header_id 역추적 + header_id IN 롤백(가드)
//  - mark_log가 없는 과거 발행내역/누락 케이스(PASS3 등) 보강용
//  - header_id IN (...) 이라서 날짜/품번 조건보다 훨씬 좁고, guardDates로 재사용 헤더 오염을 최소화
// ─────────────────────────────────────────────────────────────

function oqc_trace_header_ids_by_report_id(PDO $pdo, int $reportId, string $tbl = 'oqc_result_header'): array {
    if ($reportId <= 0) return [];
    if (!function_exists('table_exists') || !table_exists($pdo, $tbl)) return [];

    $cols = function_exists('get_columns_map') ? get_columns_map($pdo, $tbl) : [];
    if (!is_array($cols) || empty($cols)) return [];

    $linkCol = function_exists('pick_col') ? pick_col($cols, [
        'report_finish_id','reportFinish_id','report_finish_id',
        'report_id','reportId',
        'finish_id','finishId',
        'report_idx','report_index',
        'rf_id','rfid'
    ]) : null;

    $hidCol = function_exists('pick_col') ? pick_col($cols, [
        'header_id','oqc_header_id','oqcHeader_id','hdr_id','headerid'
    ]) : null;

    if (!$linkCol || !$hidCol) return [];

    $set = [];
    try {
        $sql = "SELECT DISTINCT `{$hidCol}` AS hid FROM `{$tbl}` WHERE `{$linkCol}` = ?";
        $st = $pdo->prepare($sql);
        $st->execute([$reportId]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $hid = (int)($r['hid'] ?? 0);
            if ($hid > 0) $set[$hid] = true;
        }
    } catch (Throwable $e) {
        return [];
    }

    $ids = array_map('intval', array_keys($set));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * header_id(oqc_header.id) 목록 + guardDates(YYYY-MM-DD list)로만 롤백
 *  - guardDates가 비어있으면 안전을 위해 업데이트하지 않음(0 반환)
 *  - ship_to 우선 컬럼(meas/jmeas)부터 롤백하되, 과거 혼재 대비로 존재하는 meas/jmeas 모두를 guardDates로만 롤백
 */
function oqc_rollback_marks_by_header_ids_guarded(PDO $pdo, array $headerIds, array $guardDates, bool $dry, string $shipTo): array {
    $ids = [];
    foreach ($headerIds as $v) {
        $id = (int)$v;
        if ($id > 0) $ids[$id] = true;
    }
    $ids = array_map('intval', array_keys($ids));
    sort($ids, SORT_NUMERIC);
    if (empty($ids)) {
        return ['ok'=>true,'mode'=>'header_ids_guarded','target_rows'=>0,'updated_rows'=>0,'cols'=>[],'dates'=>[],'dry'=>$dry];
    }

    $dates = [];
    foreach ($guardDates as $d) {
        $d = trim((string)$d);
        if ($d !== '' && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $d)) $dates[$d] = true;
    }
    $datesMap = $dates;
    $dates = array_keys($datesMap);
    sort($dates);
    // guardDates가 비어도 아래에서 header_id 범위(=report_id로 역추적된 최소 범위) 내 실제 날짜를 수집해 가드로 보강 가능


    // 컬럼 존재 여부 체크(Unknown column 방지)
    $want = ['meas_date','meas_date2','jmeas_date','jmeas_date2'];
    $have = [];
    foreach ($want as $c) {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM `oqc_header` LIKE " . $pdo->quote($c));
            $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if ($r && isset($r['Field'])) $have[$c] = true;
        } catch (Throwable $e) {}
    }

    $cols = function_exists('pick_mark_cols_by_ship_to') ? pick_mark_cols_by_ship_to($shipTo) : ['col1'=>'meas_date','col2'=>'meas_date2'];
    $ordered = [];
    foreach ([$cols['col1'] ?? null, $cols['col2'] ?? null] as $c) {
        if ($c && isset($have[$c])) $ordered[] = $c;
    }
    foreach ($want as $c) {
        if (isset($have[$c]) && !in_array($c, $ordered, true)) $ordered[] = $c;
    }

    if (empty($ordered)) {
        return ['ok'=>false,'mode'=>'header_ids_guarded','error'=>'no mark columns on oqc_header','target_rows'=>0,'updated_rows'=>0,'cols'=>[],'dates'=>$dates];
    }

    // report_id로 역추적된 header_id 기준: 실제 찍혀있는 날짜를 가드로 보강
    //  - to_date/저장필드가 꼬여도, header_id 범위가 이미 최소라 안전하게 취소 가능
    $foundDates = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $selCols = [];
        foreach ($ordered as $c) { $selCols[] = '`' . $c . '`'; }
        $sel = implode(', ', $selCols);
        $st = $pdo->prepare("SELECT {$sel} FROM `oqc_header` WHERE `id` IN ({$in})");
        $st->execute($ids);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach ($ordered as $c) {
                $v = (string)($r[$c] ?? '');
                if ($v === '') continue;
                $ymd = substr($v, 0, 10);
                if ($ymd !== '' && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $ymd)) $foundDates[$ymd] = true;
            }
            if (count($foundDates) >= 90) break;
        }
    } catch (Throwable $e) {}

    if (!empty($foundDates)) {
        foreach (array_keys($foundDates) as $d) { $datesMap[$d] = true; }
    }
    $dates = array_keys($datesMap);
    sort($dates);

    if (empty($dates)) {
        return ['ok'=>true,'mode'=>'header_ids_guarded','target_rows'=>0,'updated_rows'=>0,'cols'=>$ordered,'dates'=>[],'dry'=>$dry,'note'=>'no guardDates'];
    }

    // 대상 수(존재 헤더 수)
    $cnt = 0;
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM `oqc_header` WHERE `id` IN ({$in})");
        $st->execute($ids);
        $cnt = (int)($st->fetchColumn() ?? 0);
    } catch (Throwable $e) {}

    $affected = 0;
    if (!$dry) {
        $chunkSize = 400;
        for ($off = 0; $off < count($ids); $off += $chunkSize) {
            $chunk = array_slice($ids, $off, $chunkSize);
            if (empty($chunk)) continue;

            $inIds = implode(',', array_fill(0, count($chunk), '?'));
            $inD   = implode(',', array_fill(0, count($dates), '?'));

            $setParts = [];
            $params = [];
            foreach ($ordered as $c) {
                $setParts[] = "`{$c}` = CASE WHEN (`{$c}` IS NOT NULL AND LEFT(`{$c}`,10) IN ({$inD})) THEN NULL ELSE `{$c}` END";
                // 날짜 파라미터는 컬럼마다 1세트씩 필요
                foreach ($dates as $d) $params[] = $d;
            }
            $setSql = implode(', ', $setParts);

            $sql = "UPDATE `oqc_header` SET {$setSql} WHERE `id` IN ({$inIds})";
            // 마지막에 id 파라미터
            foreach ($chunk as $id) $params[] = (int)$id;

            try {
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $affected += (int)$st->rowCount();
            } catch (Throwable $e) {
                return ['ok'=>false,'mode'=>'header_ids_guarded','error'=>$e->getMessage(),'target_rows'=>$cnt,'updated_rows'=>$affected,'cols'=>$ordered,'dates'=>$dates];
            }
        }
    }

    return ['ok'=>true,'mode'=>'header_ids_guarded','target_rows'=>$cnt,'updated_rows'=>$affected,'cols'=>$ordered,'dates'=>$dates,'dry'=>$dry];
}

// ─────────────────────────────────────────────────────────────
// (추가) 템플릿/NG 판단용 Point Spec Map (USL/LSL 포함)
//  - oqc_model_point에 usl/lsl 컬럼이 없거나 스키마가 다르면 기존 db_build_point_set 결과(true set)로 폴백
//  - oqc_norm_key()는 lib/oqc_rowdata.php에 존재한다고 가정
// ─────────────────────────────────────────────────────────────
function db_build_point_spec_map(PDO $pdo, string $modelName, string $tbl = 'oqc_model_point', bool $onlyEnabled = true): array {
    $out = [];
    // 1) usl/lsl을 같이 가져오는 시도
    $sql = "SELECT point_no, lsl, usl" . ($onlyEnabled ? ", enabled" : "") . " FROM `{$tbl}` WHERE model_name = ?";
    if ($onlyEnabled) $sql .= " AND (enabled = 1 OR enabled IS NULL)";
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$modelName]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $k = oqc_norm_key((string)($r['point_no'] ?? ''));
            if ($k === '') continue;
            $lsl = (isset($r['lsl']) && is_numeric($r['lsl'])) ? (float)$r['lsl'] : null;
            $usl = (isset($r['usl']) && is_numeric($r['usl'])) ? (float)$r['usl'] : null;
            $out[$k] = ['lsl' => $lsl, 'usl' => $usl];
        }
        if (!empty($out)) return $out;
    } catch (Throwable $e) {
        // fallthrough
    }

    // 2) 폴백: 기존 point set(true) 방식
    try {
        $set = db_build_point_set($pdo, $modelName, $tbl, $onlyEnabled);
        foreach ($set as $k => $v) {
            $vars = function_exists('fai_key_variants') ? oqc_key_variants((string)$k) : [oqc_norm_key((string)$k)];
            foreach ($vars as $kk) { if ($kk === '') continue; $out[$kk] = true; }
        }
    } catch (Throwable $e) {}
    return $out;
}

// ─────────────────────────────
// 액션
// ─────────────────────────────


// ✅ build(성적서 발행) 중단 요청용 플래그 파일(세션 락과 무관)
function build_cancel_flag_path(string $token): string {
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);
    if ($token === '') $token = 'x';
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dptest_report_cancel';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir . DIRECTORY_SEPARATOR . 'cancel_' . $token . '.flag';
}
function build_cancel_is_requested(string $token): bool {
    if ($token === '') return false;
    return is_file(build_cancel_flag_path($token));
}
function build_cancel_request_write(string $token): bool {
    if ($token === '') return false;
    $p = build_cancel_flag_path($token);
    @file_put_contents($p, date('c'));
    return is_file($p);
}
function build_cancel_request_clear(string $token): void {
    if ($token === '') return;
    $p = build_cancel_flag_path($token);
    if (is_file($p)) @unlink($p);
}

$action = $_REQUEST['action'] ?? '';


// (AJAX) build 취소 요청
//  - POST action=build_cancel&build_token=...
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'build_cancel') {
    header('Content-Type: application/json; charset=utf-8');
    $token = trim((string)($_POST['build_token'] ?? ''));
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);
    if ($token === '') {
        echo json_encode(['ok' => false, 'msg' => 'no token'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ok = build_cancel_request_write($token);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}


// ─────────────────────────────
// (AJAX) 날짜 범위로 납품처 목록 가져오기
//  - GET action=ship_to_list&from_date=YYYY-MM-DD&to_date=YYYY-MM-DD
//  - JS에서 날짜 선택 시 납품처 셀렉트를 자동 갱신하기 위함
// ─────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $action === 'ship_to_list') {
    header('Content-Type: application/json; charset=utf-8');

    $from_date = trim((string)($_GET['from_date'] ?? ''));
    $to_date   = trim((string)($_GET['to_date'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
        echo json_encode(['ok' => false, 'msg' => 'invalid date'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // cutoff 계산 (shipinglist_list / 본 화면과 동일 로직)
    $cutoffTime = '08:30:00';
    $fromTs = strtotime($from_date . ' ' . $cutoffTime . ' -1 day');
    $toTs   = strtotime($to_date   . ' ' . $cutoffTime);
    $fromDt = date('Y-m-d H:i:s', $fromTs);
    $toDt   = date('Y-m-d H:i:s', $toTs);

    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT ship_to
            FROM ShipingList
            WHERE ship_datetime >= :from_dt
              AND ship_datetime <  :to_dt
              AND ship_to <> ''
            ORDER BY ship_to
        ");
        $stmt->execute([':from_dt' => $fromDt, ':to_dt' => $toDt]);
        $shipToList = $stmt->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            'ok' => true,
            'ship_to_list' => $shipToList,
            'from_dt' => $fromDt,
            'to_dt' => $toDt,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'db error'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}


// ─────────────────────────────
// (추가) 발행 내역 취소(롤백)
//  - POST action=cancel_report
// ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'cancel_report')) {
    $id = (int)($_POST['id'] ?? 0);
    $histMonth = (string)($_POST['hist_month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $histMonth)) $histMonth = date('Y-m');

    $byUser = (string)($_SESSION['ship_user_id'] ?? ($_SESSION['dp_admin_id'] ?? 'web'));
	if ($id <= 0) {
	    $res = ['ok' => false, 'msg' => '잘못된 ID'];
	} else {
	    // report_finish 기반으로 납품처/품번을 알아내서, 해당 납품처에 맞는 컬럼(meas/jmeas)만 롤백
	    $row = function_exists('report_finish_get') ? report_finish_get($pdo, $id) : null;
	    if (!$row) {
	        $res = ['ok' => false, 'msg' => '발행 내역을 찾을 수 없습니다.'];
	    } else {
	        $shipTo = (string)($row['ship_to'] ?? '');
	        // ✅ 롤백 기준 날짜는 "발행 완료 시각(finished_at)"이 아니라,
	        //    실제 성적서가 생성된 조회기간(from_date~to_date = 출하일 필터) 기준으로 처리해야 함.
	        //    (과거 출하분을 지금 발행/취소하면 finished_at 기준으로는 절대 지워지지 않음)
	        $fromDate = trim((string)($_POST['from_date'] ?? ''));
	        $toDate   = trim((string)($_POST['to_date'] ?? ''));
	
	        $mkYmd = function($s) {
	            $s = trim((string)$s);
	            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
	            return null;
	        };
	
	        $start = $mkYmd($fromDate);
	        $end   = $mkYmd($toDate);
	
	        // report_finish row 안에 날짜 범위가 저장돼있으면(혹은 이름이 다르면) 그것도 시도
	        if (!$start || !$end) {
	            $candPairs = [
	                ['from_date', 'to_date'],
	                ['date_from', 'date_to'],
	                ['ship_date_from', 'ship_date_to'],
	                ['ship_from', 'ship_to'],
	                ['ship_from', 'ship_to_date'],
	            ];
	            foreach ($candPairs as $p) {
	                $s = $mkYmd($row[$p[0]] ?? null);
	                $e = $mkYmd($row[$p[1]] ?? null);
	                if ($s && $e) { $start = $s; $end = $e; break; }
	            }
	            if (!$start && !$end) {
	                $one = $mkYmd($row['ship_date'] ?? null);
	                if ($one) { $start = $one; $end = $one; }
	            }
	        }
	
	        $dateList = [];
	        $spanText = '';
	        if ($start && $end) {
	            if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }
	            $spanText = ($start === $end) ? $start : ($start . '~' . $end);
	            try {
	                $d0 = new DateTime($start);
	                $d1 = new DateTime($end);
	                $i = 0;
	                $maxDays = 400; // 안전장치
	                while ($d0 <= $d1 && $i < $maxDays) {
	                    $dateList[] = $d0->format('Y-m-d');
	                    $d0->modify('+1 day');
	                    $i++;
	                }
	            } catch (Throwable $e) {
	                $dateList = [];
	            }
	        }
	
	        // fallback: 예전 방식(최후 수단)
	        if (!$dateList) {
	            $fallback = substr((string)($row['finished_at'] ?? ''), 0, 10);
	            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) $fallback = date('Y-m-d');
	            $dateList = [$fallback];
	            $spanText = $fallback;
	        }
	
	        $parts = report_finish_parts_list((string)($row['parts_json'] ?? ''));

        // ✅ build 시 저장된 mark_log가 있으면, header_id/col/날짜(guard) 기준으로 정확히 롤백
        $markLog = function_exists('report_finish_marklog_get') ? report_finish_marklog_get((string)($row['parts_json'] ?? '')) : null;
        $metaOqc = function_exists('init_oqc_db_meta') ? init_oqc_db_meta($pdo) : ['t_header' => 'oqc_header', 'h' => ['id' => 'id']];

        // 날짜 범위(또는 단일 날짜) 전체를 롤백
        $rbAgg = ['ok' => true, 'dates' => $dateList, 'target_rows' => 0, 'updated_rows' => 0, 'col1' => null, 'col2' => null, 'marklog' => null, 'trace' => null, 'trace_error' => null];

        // 1) mark_log 기반(가장 정확)
        if (is_array($markLog)) {
            $rbML = oqc_rollback_marks_by_marklog($pdo, $metaOqc, $markLog, false);
            if (!($rbML['ok'] ?? false)) {
                $rbAgg = ['ok' => false, 'error' => $rbML['error'] ?? 'marklog rollback failed'];
            } else {
                $rbAgg['marklog'] = $rbML;
                $rbAgg['target_rows'] += (int)($rbML['target_rows'] ?? 0);
                $rbAgg['updated_rows'] += (int)($rbML['updated_rows'] ?? 0);
                $colsUsed = $rbML['cols'] ?? [];
                if (is_array($colsUsed) && !empty($colsUsed)) {
                    if ($rbAgg['col1'] === null) $rbAgg['col1'] = $colsUsed[0] ?? null;
                    if ($rbAgg['col2'] === null) $rbAgg['col2'] = $colsUsed[1] ?? null;
                }
            }
        }

        // 1.5) report_id 기반 역추적(header_id IN) 롤백(최소 범위, mark_log 누락/부분누락 보강)
        if ($rbAgg['ok']) {
            $traceIds = oqc_trace_header_ids_by_report_id($pdo, $id);
            if (!empty($traceIds)) {
                $rbTR = oqc_rollback_marks_by_header_ids_guarded($pdo, $traceIds, $dateList, false, $shipTo);
                $rbAgg['trace'] = $rbTR;
                if (($rbTR['ok'] ?? false)) {
                    $rbAgg['target_rows'] += (int)($rbTR['target_rows'] ?? 0);
                    $rbAgg['updated_rows'] += (int)($rbTR['updated_rows'] ?? 0);
                    $colsUsed = $rbTR['cols'] ?? [];
                    if (is_array($colsUsed) && !empty($colsUsed)) {
                        if ($rbAgg['col1'] === null) $rbAgg['col1'] = $colsUsed[0] ?? null;
                        if ($rbAgg['col2'] === null) $rbAgg['col2'] = $colsUsed[1] ?? null;
                    }
                } else {
                    $rbAgg['trace_error'] = $rbTR['error'] ?? 'trace rollback failed';
                }
            }
        }

        // 2) safety net: 날짜 기반 롤백(구버전/누락 대비)
        if ($rbAgg['ok']) {
            foreach ($dateList as $dateYmd) {
                $rb = oqc_rollback_marks_shipto($pdo, $dateYmd, $parts, false, $shipTo);
                if (!($rb['ok'] ?? false)) {
                    $rbAgg = ['ok' => false, 'error' => $rb['error'] ?? 'unknown', 'failed_date' => $dateYmd];
                    break;
                }
                $rbAgg['target_rows'] += (int)($rb['target_rows'] ?? 0);
                $rbAgg['updated_rows'] += (int)($rb['updated_rows'] ?? 0);
                if ($rbAgg['col1'] === null) $rbAgg['col1'] = $rb['col1'] ?? null;
                if ($rbAgg['col2'] === null) $rbAgg['col2'] = $rb['col2'] ?? null;
            }
        }

        $rb = $rbAgg;
	        if (!($rb['ok'] ?? false)) {
	            $res = ['ok' => false, 'msg' => '롤백 실패: ' . ($rb['error'] ?? 'unknown') . (isset($rb['failed_date']) ? (' @' . $rb['failed_date']) : '')];
	        } else {
	            $reason = sprintf('취소/롤백(%s) (%s/%s) by %s', $spanText ?: 'unknown', $rb['col1'] ?? 'col1', $rb['col2'] ?? 'col2', $byUser);
	            try {
	                if (function_exists('report_finish_mark_canceled')) {
	                    report_finish_mark_canceled($pdo, $id, $byUser, $reason);
	                }
	                $del = dp_delete_report_artifacts($id, false);
					$msg = '취소/롤백 완료';
					if (!($del['ok'] ?? true)) {
						$msg .= ' (파일삭제 실패: ' . ($del['error'] ?? 'unknown') . ')';
					}
					$res = ['ok' => true, 'msg' => $msg, 'rollback' => $rb, 'delete' => $del];
	            } catch (Throwable $e) {
	                $res = ['ok' => false, 'msg' => '취소 마킹 실패: ' . $e->getMessage(), 'rollback' => $rb];
	            }
	        }
	    }
	}
    // 결과를 GET 파라미터로 보여주기(간단)
    $from_date = trim((string)($_POST['from_date'] ?? ''));
    $to_date   = trim((string)($_POST['to_date'] ?? ''));
    $ship_to   = trim((string)($_POST['ship_to'] ?? ''));

    $qs = [
        'hist_month' => $histMonth,
        'cancel_ok'  => $res['ok'] ? 1 : 0,
        'cancel_msg' => $res['msg'] ?? '',
    ];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) $qs['from_date'] = $from_date;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date))   $qs['to_date']   = $to_date;
    if ($ship_to !== '') $qs['ship_to'] = $ship_to;

    $q = http_build_query($qs);

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . $q);
    exit;
}



// ─────────────────────────────
// report_finish / rollback 액션 (UI 없어도 호출 가능)
// ─────────────────────────────

// ─────────────────────────────
if ($action === 'rollback_today' || $action === 'rollback_date') {
    $dry = (string)($_REQUEST['dry'] ?? '') === '1';
    $dateYmd = ($action === 'rollback_today') ? date('Y-m-d') : trim((string)($_REQUEST['date'] ?? ''));
    if ($dateYmd === '') $dateYmd = date('Y-m-d');
	    $shipTo = trim((string)($_REQUEST['ship_to'] ?? ''));

    $parts = [];
    if (isset($_REQUEST['part_name']) && trim((string)$_REQUEST['part_name']) !== '') {
        $parts = [trim((string)$_REQUEST['part_name'])];
    } elseif (isset($_REQUEST['parts']) && trim((string)$_REQUEST['parts']) !== '') {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string)$_REQUEST['parts']))));
    }

	    // 납품처 미지정이면 기본(meas_date/meas_date2)로 롤백
	    $result = oqc_rollback_marks_shipto($pdo, $dateYmd, $parts, $dry, $shipTo);
    header('Content-Type: application/json; charset=UTF-8');
	    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'report_cancel') {
    $id = (int)($_REQUEST['id'] ?? 0);
    if ($id <= 0) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'invalid id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = report_finish_get($pdo, $id);
    if (!$row) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ✅ 롤백 기준 날짜는 "발행 완료 시각"이 아니라, 실제 조회기간(from_date~to_date) 우선
    $mkYmd = function($s) {
        $s = trim((string)$s);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
        return null;
    };

    $start = $mkYmd($row['from_date'] ?? null);
    $end   = $mkYmd($row['to_date'] ?? null);

    if (!$start || !$end) {
        $dt = (string)($row['finished_at'] ?? ($row['created_at'] ?? ''));
        $dateYmd = (strlen($dt) >= 10 && preg_match('/^\d{4}-\d{2}-\d{2}$/', substr($dt,0,10))) ? substr($dt, 0, 10) : date('Y-m-d');
        $start = $start ?: $dateYmd;
        $end   = $end   ?: $dateYmd;
    }

    if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }
    $dateList = [];
    try {
        $d0 = new DateTime($start);
        $d1 = new DateTime($end);
        $i = 0;
        $maxDays = 400;
        while ($d0 <= $d1 && $i < $maxDays) {
            $dateList[] = $d0->format('Y-m-d');
            $d0->modify('+1 day');
            $i++;
        }
    } catch (Throwable $e) {
        $dateList = [$start];
    }

    // parts_json 있으면 part 필터로 롤백 범위를 줄임
    $parts = report_finish_parts_list((string)($row['parts_json'] ?? ''));

    $dry = (string)($_REQUEST['dry'] ?? '') === '1';
    $shipTo = (string)($row['ship_to'] ?? '');

    // 1) mark_log 기반(정확) + 2) 날짜 기반(safety net)
    $markLog = function_exists('report_finish_marklog_get') ? report_finish_marklog_get((string)($row['parts_json'] ?? '')) : null;
    $metaOqc = function_exists('init_oqc_db_meta') ? init_oqc_db_meta($pdo) : ['t_header' => 'oqc_header', 'h' => ['id' => 'id']];

    $resultAgg = ['ok' => true, 'dates' => $dateList, 'target_rows' => 0, 'updated_rows' => 0, 'col1' => null, 'col2' => null, 'marklog' => null, 'trace' => null, 'trace_error' => null];

    if (is_array($markLog)) {
        $rbML = oqc_rollback_marks_by_marklog($pdo, $metaOqc, $markLog, $dry);
        if (!($rbML['ok'] ?? false)) {
            $resultAgg = ['ok' => false, 'error' => $rbML['error'] ?? 'marklog rollback failed'];
        } else {
            $resultAgg['marklog'] = $rbML;
            $resultAgg['target_rows'] += (int)($rbML['target_rows'] ?? 0);
            $resultAgg['updated_rows'] += (int)($rbML['updated_rows'] ?? 0);
            $colsUsed = $rbML['cols'] ?? [];
            if (is_array($colsUsed) && !empty($colsUsed)) {
                if ($resultAgg['col1'] === null) $resultAgg['col1'] = $colsUsed[0] ?? null;
                if ($resultAgg['col2'] === null) $resultAgg['col2'] = $colsUsed[1] ?? null;
            }
        }
    }



    // 1.5) report_id 기반 역추적(header_id IN) 롤백(최소 범위, mark_log 누락/부분누락 보강)
    if ($resultAgg['ok']) {
        $traceIds = oqc_trace_header_ids_by_report_id($pdo, $id);
        if (!empty($traceIds)) {
            $rbTR = oqc_rollback_marks_by_header_ids_guarded($pdo, $traceIds, $dateList, $dry, $shipTo);
            $resultAgg['trace'] = $rbTR;
            if (($rbTR['ok'] ?? false)) {
                $resultAgg['target_rows'] += (int)($rbTR['target_rows'] ?? 0);
                $resultAgg['updated_rows'] += (int)($rbTR['updated_rows'] ?? 0);
                $colsUsed = $rbTR['cols'] ?? [];
                if (is_array($colsUsed) && !empty($colsUsed)) {
                    if ($resultAgg['col1'] === null) $resultAgg['col1'] = $colsUsed[0] ?? null;
                    if ($resultAgg['col2'] === null) $resultAgg['col2'] = $colsUsed[1] ?? null;
                }
            } else {
                $resultAgg['trace_error'] = $rbTR['error'] ?? 'trace rollback failed';
            }
        }
    }
    if ($resultAgg['ok']) {
        foreach ($dateList as $dateYmd) {
            $rb = oqc_rollback_marks_shipto($pdo, $dateYmd, $parts, $dry, $shipTo);
            if (!($rb['ok'] ?? false)) {
                $resultAgg = ['ok' => false, 'error' => $rb['error'] ?? 'unknown', 'failed_date' => $dateYmd];
                break;
            }
            $resultAgg['target_rows'] += (int)($rb['target_rows'] ?? 0);
            $resultAgg['updated_rows'] += (int)($rb['updated_rows'] ?? 0);
            if ($resultAgg['col1'] === null) $resultAgg['col1'] = $rb['col1'] ?? null;
            if ($resultAgg['col2'] === null) $resultAgg['col2'] = $rb['col2'] ?? null;
        }
    }

    $result = $resultAgg;

    if (!$dry) {
        $by = (string)($_SESSION['ship_user_id'] ?? ($_SESSION['dp_admin_id'] ?? ''));
        report_finish_mark_canceled($pdo, $id, $by ?: null, '취소(롤백): ' . ($end ?? ''));
    // ✅ 서버 저장 폴더(exports/reports/rf_{id}/)도 함께 삭제
    $del = dp_delete_report_artifacts($id, $dry);

    }

    $isAjax = ((string)($_REQUEST['ajax'] ?? '') === '1')
              || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'id' => $id, 'result' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 일반 폼 제출이면 화면으로 복귀
    $ret = (string)($_REQUEST['return'] ?? '');
    if ($ret === '') {
        // 기존 GET 파라미터 유지(가능한 범위)
        $qs = [];
        foreach (['from_date','to_date','ship_to','month'] as $k) {
            if (isset($_REQUEST[$k]) && (string)$_REQUEST[$k] !== '') $qs[] = $k . '=' . rawurlencode((string)$_REQUEST[$k]);
        }
        $ret = basename($_SERVER['PHP_SELF']) . ($qs ? ('?' . implode('&', $qs)) : '');
    }
    header('Location: ' . $ret);
    exit;
}


// ─────────────────────────────
// 0) ZIP 토큰 다운로드(fetch)
// ─────────────────────────────
if ($action === 'fetch') {
    $token = (string)($_GET['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) die('잘못된 토큰');

    

    // consume=1(기본): 다운로드 후 파일 정리(삭제)
    // consume=0: 자동 다운로드 시도용(파일 유지, 수동 링크로 재시도 가능)
    $consume = ((string)($_GET['consume'] ?? '1') !== '0');
$metaPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "oqc_export_meta_{$token}.json";
    if (!is_file($metaPath)) die('만료되었거나 없는 파일입니다.');

    $meta = json_decode((string)file_get_contents($metaPath), true);
    if (!is_array($meta) || empty($meta['zip_path']) || empty($meta['zip_name'])) die('메타 손상');

    $zipPath = (string)$meta['zip_path'];
    $zipName = (string)$meta['zip_name'];
    $workDir = (string)($meta['work_dir'] ?? '');

    if (!is_file($zipPath)) die('ZIP 파일이 없습니다.');

    while (ob_get_level() > 0) @ob_end_clean();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);

    if (!$consume) { exit; }

    // 정리
    @unlink($zipPath);
    @unlink($metaPath);
    if ($workDir && is_dir($workDir)) {
        // workDir 내부 파일 정리
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) @rmdir($f->getRealPath());
            else @unlink($f->getRealPath());
        }
        @rmdir($workDir);
    }
    exit;
}

// ─────────────────────────────
// 1) 화면: 기간 + 납품처 선택
// ─────────────────────────────
if ($action !== 'build') {

    $today = date('Y-m-d');

$fromDate = normalize_date_ymd($_GET['from_date'] ?? $today);
$toDate   = normalize_date_ymd($_GET['to_date']   ?? $today);

// normalize_date_ymd()가 빈 문자열을 반환하는 경우(이상한 값이 넘어온 경우) 화면에 빈값 대신 오늘 날짜로 표시
if ($fromDate === '') $fromDate = $today;
if ($toDate   === '') $toDate   = $today;

// cutoff 계산 (shipinglist_list 와 동일 로직)
    $cutoffTime = '08:30:00';
    $fromTs = strtotime($fromDate . ' ' . $cutoffTime . ' -1 day');
    $toTs   = strtotime($toDate   . ' ' . $cutoffTime);
    $fromDt = date('Y-m-d H:i:s', $fromTs);
    $toDt   = date('Y-m-d H:i:s', $toTs);

    // 해당 기간의 납품처 목록
    $stmt = $pdo->prepare("
        SELECT DISTINCT ship_to
        FROM ShipingList
        WHERE ship_datetime >= :from_dt
          AND ship_datetime <  :to_dt
          AND ship_to <> ''
        ORDER BY ship_to
    ");
    $stmt->execute([':from_dt' => $fromDt, ':to_dt' => $toDt]);
    $shipToList = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $selectedShipTo = $_GET['ship_to'] ?? ($shipToList[0] ?? '');

    // ✅ 납품처가 없는 기간이면 select가 비어버려서 build 시 필수 파라미터 누락이 발생함.
    //    UI에서 '(해당 기간 납품처 없음)'을 보여주고, 생성 버튼은 안내 메시지로 막는다.
    if (empty($shipToList)) {
        $shipToList = [''];
        $selectedShipTo = '';
    }


    // ─────────────────────────────
    // (추가) 발행 내역 조회 (월 단위)
    // ─────────────────────────────
    ensure_report_finish_table($pdo);

    $histMonth = trim($_GET['hist_month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $histMonth)) $histMonth = date('Y-m');

    $histRows  = report_finish_list_month($pdo, $histMonth);

    $cancelOk  = ((int)($_GET['cancel_ok'] ?? 0) === 1);
    $cancelMsg = trim((string)($_GET['cancel_msg'] ?? ''));

?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>Shipping Lot List 성적서 만들기</title>
<style>
:root{
  --bg:#202124; --card:#2b2b2b; --fg:#e8eaed;
  --muted:#9aa0a6; --border:#5f6368;
  --accent:#4f8cff; --accent2:#8ab4f8;
  --danger:#e85d5d;
  --radius:14px;
  --ctl-h:34px;
}
body{margin:0;padding:18px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,'Apple Color Emoji','Segoe UI Emoji';background:var(--bg);color:var(--fg);font-size:13px;}
.wrap{max-width:860px;margin:0 auto;}
h1{font-size:20px;margin:0 0 12px;}
.card{background:var(--card);border-radius:var(--radius);padding:16px 18px 14px;box-shadow:0 8px 20px rgba(0,0,0,0.45);margin-bottom:18px;}
.card.small{padding:14px 18px;}
label{font-size:13px;display:block;margin-bottom:4px;color:#d7dbe0;}
.row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.col{display:flex;flex-direction:column;margin-bottom:8px;}
.ctl, input[type="date"], input[type="month"], select{
  height:var(--ctl-h);
  padding:0 10px;
  border-radius:10px;
  border:1px solid var(--border);
  background:var(--bg);
  color:var(--fg);
  font-size:13px;
}
input[type="date"]{padding:0 8px;}
.ctl:focus, input[type="date"]:focus, input[type="month"]:focus, select:focus{
  outline:none;border-color:var(--accent2);box-shadow:0 0 0 1px var(--accent2);
}
.btn{
  height:var(--ctl-h);
  padding:0 12px;
  border-radius:12px;
  border:1px solid transparent;
  font-size:12.5px;
  font-weight:650;
  letter-spacing:0;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  gap:6px;
  user-select:none;
  box-shadow:0 6px 14px rgba(0,0,0,0.26);
}
.btn-primary{
  background:linear-gradient(135deg, rgba(79,140,255,1), rgba(106,168,255,0.9));
  color:#fff;
}
.btn-primary:hover{filter:brightness(1.05);}
.btn-secondary{
  background:rgba(255,255,255,0.06);
  border-color:rgba(255,255,255,0.16);
  color:var(--fg);
}
.btn-secondary:hover{
  border-color:rgba(138,180,248,0.55);
  background:rgba(138,180,248,0.10);
}
.btn-sm{height:30px;padding:0 10px;border-radius:12px;font-size:12px;box-shadow:0 5px 12px rgba(0,0,0,0.22);}
.btn-danger{
  background:linear-gradient(135deg, rgba(232,93,93,0.92), rgba(232,93,93,0.65));
  color:#fff;
  border-color:rgba(232,93,93,0.0);
}
.btn-danger:hover{filter:brightness(1.06);}
.btn-danger:active{transform:translateY(1px);}
.desc{font-size:12px;color:var(--muted);margin-top:8px;line-height:1.5;}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;}
.card-title{font-size:14px;font-weight:800;margin:0;}
.alert{
  margin:0 0 12px;
  padding:10px 12px;
  border-radius:12px;
  background:rgba(138,180,248,0.12);
  border:1px solid rgba(138,180,248,0.22);
  color:#cfe3ff;
  font-size:12.5px;
}
.alert.bad{background:rgba(232,93,93,0.12);border-color:rgba(232,93,93,0.22);color:#ffd1d1;}
.table-wrap{overflow:auto;border-radius:12px;border:1px solid rgba(255,255,255,0.10);background:rgba(0,0,0,0.08);}
table{width:100%;border-collapse:separate;border-spacing:0;min-width:560px;}
thead th{
  position:sticky;top:0;z-index:1;
  background:rgba(255,255,255,0.06);
  color:#cbd5e1;
  font-size:12px;
  text-align:left;
  padding:10px 10px;
  border-bottom:1px solid rgba(255,255,255,0.10);
}
tbody td{
  padding:10px 10px;
  font-size:12.5px;
  color:#e8eaed;
  border-bottom:1px solid rgba(255,255,255,0.06);
  white-space:nowrap;
}
tbody tr:hover td{background:rgba(255,255,255,0.03);}
.badge{
  display:inline-flex;align-items:center;
  padding:2px 8px;border-radius:999px;
  font-size:11px;font-weight:800;
  border:1px solid rgba(138,180,248,0.25);
  background:rgba(138,180,248,0.12);
  color:var(--accent2);
}
.badge.pub{}
.badge.cancel{
  border-color:rgba(232,93,93,0.25);
  background:rgba(232,93,93,0.12);
  color:#ffb4b4;
}
.mini{font-size:11px;color:var(--muted);}
.lines{display:flex;flex-direction:column;gap:2px;}
.lines.right{align-items:flex-end;}
.mono{font-variant-numeric:tabular-nums;}
.sumline{margin-top:4px;padding-top:4px;border-top:1px solid rgba(255,255,255,0.10);color:var(--muted);font-size:11px;}

/* 발행내역 테이블: 가운데정렬(가로) */
.history-table th,
.history-table td{ text-align:center; }
.history-table td{ vertical-align:top; }

/* 발행일시/조회기간/납품처/상태: 세로 가운데정렬 */
.history-table td:nth-child(1),
.history-table td:nth-child(2),
.history-table td:nth-child(5),
.history-table td:nth-child(6){
  vertical-align:middle;
}

</style>
</head>
<body>
<div class="wrap">
  <h1>성적서 제작</h1>
  <div id="topAlert" class="alert <?=($cancelOk ? '' : 'bad')?>" style="<?= empty($cancelMsg) ? 'display:none;' : '' ?>"><?=h($cancelMsg)?></div>
  <div class="card">
    <form method="get" id="buildForm">
      <input type="hidden" name="action" value="build">
      <div class="row">
        <div class="col">
          <label>조회기간 (출하일자)</label>
          <div style="display:flex; gap:6px; align-items:center;">
            <input type="date" name="from_date"
                   class="filter-input-date"
                   value="<?= h($fromDate) ?>"
                   min="2000-01-01" max="9999-12-31">
            <span style="font-size:11px; color:#9aa0a6;">~</span>
            <input type="date" name="to_date"
                   class="filter-input-date"
                   value="<?= h($toDate) ?>"
                   min="2000-01-01" max="9999-12-31">
          </div>
        </div>
        <div class="col">
          <label>납품처</label>
          <select name="ship_to">
            <?php foreach ($shipToList as $st): ?>
              <?php $stLabel = ($st === '' ? '(해당 기간 출하내역 없음)' : $st); ?>
              <option value="<?=h($st)?>" <?=($st === $selectedShipTo ? 'selected' : '')?>><?=h($stLabel)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col">
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-primary">성적서 생성</button>
        </div>
      </div>

    </form>
<script>
(function(){
  const from = document.querySelector('input[name="from_date"]');
  const to   = document.querySelector('input[name="to_date"]');
  const sel  = document.querySelector('select[name="ship_to"]');
  if (!from || !to || !sel) return;

  const form = document.getElementById('buildForm') || sel.closest('form');
  const topAlert = document.getElementById('topAlert');

  function showTopInfo(msg){
    if (!topAlert) {
      alert(msg);
      return;
    }
    topAlert.textContent = msg;
    topAlert.classList.remove('bad');
    topAlert.style.display = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  if (form) {
    form.addEventListener('submit', function(e){
      // ship_to가 없으면 build 호출 자체를 막고 안내만 표시
      if (!sel.value) {
        e.preventDefault();
        showTopInfo('해당 조회기간에는 출하내역이 존재하지않아 성적서를 생성할 수 없습니다.');
      }
    });
  }

  let lastKey = '';
  let inflight = null;

  async function refreshShipTo(){
    const fd = (from.value || '').trim();
    const td = (to.value || '').trim();
    if (!fd || !td) return;

    const key = fd + '|' + td;
    if (key === lastKey && !inflight) return;
    lastKey = key;

    const prevValue = sel.value;
    sel.disabled = true;

    try{
      const url = new URL(location.href);
      url.searchParams.set('action', 'ship_to_list');
      url.searchParams.set('from_date', fd);
      url.searchParams.set('to_date', td);

      inflight = fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
      const res = await inflight;
      inflight = null;

      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      const list = Array.isArray(data.ship_to_list) ? data.ship_to_list : [];

      sel.innerHTML = '';
      if (list.length === 0) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = '(해당 기간 출하내역 없음)';
        sel.appendChild(opt);
        sel.value = '';
      } else {
        for (const st of list) {
          const opt = document.createElement('option');
          opt.value = st;
          opt.textContent = st;
          sel.appendChild(opt);
        }
        if (list.includes(prevValue)) sel.value = prevValue;
        else sel.value = list[0];
      }
    } catch (e) {
      console.error('[ship_to_list] failed', e);
    } finally {
      inflight = null;
      sel.disabled = false;
    }
  }

  from.addEventListener('change', refreshShipTo);
  to.addEventListener('change', refreshShipTo);

  // 페이지 로드시 날짜가 이미 선택되어 있으면 납품처 목록을 새로 로딩
  if (from.value && to.value) refreshShipTo();
})();
</script>


  </div>

  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">출하성적서 발행 내역</div>
      </div>
      <form method="get" style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="hidden" name="from_date" value="<?=h($fromDate)?>">
        <input type="hidden" name="to_date" value="<?=h($toDate)?>">
        <input type="hidden" name="ship_to" value="<?=h($selectedShipTo)?>">
        <input class="ctl" type="month" name="hist_month" value="<?=h($histMonth)?>">
        <button type="submit" class="btn btn-secondary btn-sm">조회</button>
      </form>
    </div>

    <div class="table-wrap">
      <table class="history-table">
        <thead>
          <tr>
            <th style="min-width:90px;">발행일시</th>
            <th style="min-width:90px;">조회기간</th>
            <th style="min-width:100px;">품번</th>
            <th style="min-width:120px;">출하수량</th>
            <th style="min-width:130px;">납품처</th>
            <th style="min-width:160px;">상태</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($histRows)): ?>
            <tr><td colspan="6" style="color:var(--muted);">내역이 없습니다.</td></tr>
          <?php else: ?>
            <?php foreach ($histRows as $r): ?>
              <?php
                $isCanceled = ((int)($r['is_canceled'] ?? 0) === 1);

                // parts_json에서 (품번 목록 / 출하수량) 표시용 파싱
                $partsLines = [];
                $qtyLines = [];
                $totalShipQty = null;

                $pjText = $r['parts_json'] ?? '';
                if (is_string($pjText) && $pjText !== '') {
                    $tmp = json_decode($pjText, true);
                    if (is_array($tmp)) {
                        // 옛날 포맷: ["MEM-IR-BASE","MEM-X-CARRIER",...]
                        if (array_keys($tmp) === range(0, count($tmp) - 1)) {
                            foreach ($tmp as $v) {
                                if (is_string($v) && $v !== '') $partsLines[] = $v;
                            }
                        } else {
                            // 최신 포맷: {"parts":[{"part":"...","ship_qty":...},...], "total_ship_qty":...}
                            if (isset($tmp['parts']) && is_array($tmp['parts'])) {
                                foreach ($tmp['parts'] as $it) {
                                    if (is_array($it) && isset($it['part'])) {
                                        $partsLines[] = (string)$it['part'];
                                        $qtyLines[]   = number_format((int)($it['ship_qty'] ?? $it['qty'] ?? 0));
                                    } elseif (is_string($it) && $it !== '') {
                                        $partsLines[] = $it;
                                    }
                                }
                            }
                            if (isset($tmp['total_ship_qty'])) $totalShipQty = (int)$tmp['total_ship_qty'];
                        }
                    }
                }
                if ($partsLines && !$qtyLines) {
                    $qtyLines = array_fill(0, count($partsLines), '-');
                }

                // 발행일시 표시용 분리 (YYYY-MM-DD / HH:MM:SS)
                $faStr  = trim((string)($r['finished_at'] ?? ''));
                $faDate = $faStr;
                $faTime = '';
                if ($faStr !== '') {
                    if (strpos($faStr, ' ') !== false) {
                        $p = explode(' ', $faStr, 2);
                        $faDate = $p[0] ?? $faStr;
                        $faTime = $p[1] ?? '';
                    } else {
                        $ts = strtotime($faStr);
                        if ($ts !== false) {
                            $faDate = date('Y-m-d', $ts);
                            $faTime = date('H:i:s', $ts);
                        }
                    }
                }
              ?>
              <tr>
                <td class="mono"><div class="lines center"><div><?=h((string)$faDate)?></div><?php if ($faTime !== ''): ?><div><?=h((string)$faTime)?></div><?php endif; ?></div></td>
                <td class="mono"><div class="lines center"><div><?=h((string)($r['from_date'] ?? ''))?></div><div>~</div><div><?=h((string)($r['to_date'] ?? ''))?></div></div></td>
                <td>
                  <?php if (!empty($partsLines)): ?>
                    <div class="lines">
                      <?php foreach ($partsLines as $pl): ?>
                        <div><?=h((string)$pl)?></div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="mini">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($qtyLines)): ?>
                    <div class="lines center mono">
                      <?php foreach ($qtyLines as $ql): ?>
                        <div><?=h((string)$ql)?></div>
                      <?php endforeach; ?>
                      <?php if ($totalShipQty !== null): ?>
                        <div class="sumline">합계 <?=h(number_format((int)$totalShipQty))?></div>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="mini">-</span>
                  <?php endif; ?>
                </td>
                <td><?=h((string)($r['ship_to'] ?? ''))?></td>
                <td>
                  <?php if ($isCanceled): ?>
                    <span class="badge cancel">취소완료</span>
                    <?php if (!empty($r['canceled_at'])): ?>
                      <div class="mini">취소: <?=h((string)($r['canceled_at'] ?? ''))?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['canceled_by'])): ?>
                      <div class="mini"><?=h((string)($r['canceled_by'] ?? ''))?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge">발행</span>
                    <?php $viewOk = is_dir(JTMES_ROOT . '/exports/reports/rf_' . (int)($r['id'] ?? 0)); ?>
                  <?php if ($viewOk): ?>
                    <button type="button" class="btn btn-secondary btn-sm" style="margin-left:6px;" onclick="openReportView(<?= (int)($r['id'] ?? 0) ?>)">View</button>
                  <?php endif; ?>

                    <form method="post" style="display:inline;margin-left:6px;"
                      onsubmit="return confirm('이 발행 건을 취소(롤백)할까요?\n\n※ 발행일(=meas_date / meas_date2) 마킹을 되돌리고, 서버에 저장된 발행 파일도 삭제합니다.');">
                      <input type="hidden" name="action" value="cancel_report">
                      <input type="hidden" name="id" value="<?=h((string)($r['id'] ?? '0'))?>">
                      <input type="hidden" name="hist_month" value="<?=h($histMonth)?>">
                      <input type="hidden" name="from_date" value="<?=h($fromDate)?>">
                      <input type="hidden" name="to_date" value="<?=h($toDate)?>">
                      <input type="hidden" name="ship_to" value="<?=h($selectedShipTo)?>">
                      <button type="submit" class="btn btn-danger btn-sm">취소</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>



<!-- Report View Modal -->
<div id="reportViewModal" class="rv-modal" style="display:none;">
  <div class="rv-backdrop" onclick="closeReportView()"></div>
  <div class="rv-panel" role="dialog" aria-modal="true">
    <div class="rv-head">
      <div class="rv-title">발행 결과 보기</div>
      <button class="rv-close" type="button" onclick="closeReportView()">닫기</button>
    </div>
    <iframe id="reportViewFrame" class="rv-iframe" src="about:blank"></iframe>
  </div>
</div>

<style>
  .rv-modal{position:fixed;inset:0;z-index:9999;}
  .rv-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55);}
  .rv-panel{position:relative;margin:4vh auto;width:96vw;max-width:1400px;height:90vh;background:var(--card);border:1px solid rgba(255,255,255,.12);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.6);overflow:hidden;display:flex;flex-direction:column;}
  .rv-head{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.10);}
  .rv-title{font-weight:700;color:var(--text);}
  .rv-close{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:var(--text);padding:6px 10px;border-radius:10px;cursor:pointer;}
  .rv-close:hover{background:rgba(255,255,255,.12);}
  .rv-iframe{flex:1;border:0;width:100%;background:#111;}
</style>

<script>
  function openReportView(id){
    var m = document.getElementById('reportViewModal');
    var f = document.getElementById('reportViewFrame');
    f.src = 'report_view.php?id=' + encodeURIComponent(id);
    m.style.display = 'block';
  }
  function closeReportView(){
    var m = document.getElementById('reportViewModal');
    var f = document.getElementById('reportViewFrame');
    f.src = 'about:blank';
    m.style.display = 'none';
  }
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeReportView();
  });
</script>


</body>
</html>
    <?php
    exit;
}

// ─────────────────────────────
// 2) build: 진행 로그 화면 + ZIP 생성 후 fetch로 넘김
// ─────────────────────────────

// 진행 로그 화면 시작(여기서부터는 HTML로 출력하면서 flush)
while (ob_get_level() > 0) @ob_end_flush();
@ob_implicit_flush(true);

$fromDate = trim($_GET['from_date'] ?? '');
$toDate   = trim($_GET['to_date']   ?? '');
$fromDate = normalize_date_ymd($fromDate);
$toDate   = normalize_date_ymd($toDate);
$shipTo   = trim($_GET['ship_to']   ?? '');


$buildToken = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)($_GET['build_token'] ?? '')));
if ($buildToken !== '') {
    // 이전 실행에서 남은 취소 플래그는 제거
    build_cancel_request_clear($buildToken);
    $GLOBALS['BUILD_CANCEL_TOKEN'] = $buildToken;
    $GLOBALS['PDO_REF'] = $pdo;
}

if ($fromDate === '' || $toDate === '' || $shipTo === '') {
    // ✅ 납품처가 없는 상태에서 생성 버튼을 누르면 에러(die) 대신 안내 메시지로 되돌린다.
    $msg = ($shipTo === '')
        ? '해당 조회기간에는 납품처가 없어 성적서를 생성할 수 없습니다.'
        : '필수 파라미터 누락 (from_date / to_date / ship_to)';

    $params = [
        'from_date'  => ($fromDate !== '' ? $fromDate : date('Y-m-d')),
        'to_date'    => ($toDate   !== '' ? $toDate   : date('Y-m-d')),
        'ship_to'    => $shipTo,
        'cancel_ok'  => 1,
        'cancel_msg' => $msg,
    ];
    if (isset($_GET['hist_month'])) $params['hist_month'] = (string)$_GET['hist_month'];
    if (isset($_GET['embed']))     $params['embed']     = (string)$_GET['embed'];
    if (isset($_GET['debug']))     $params['debug']     = (string)$_GET['debug'];
    if (isset($_GET['emg_from']))  $params['emg_from']  = (string)$_GET['emg_from'];
    if (isset($_GET['emg_to']))    $params['emg_to']    = (string)$_GET['emg_to'];

    $self = $_SERVER['PHP_SELF'] ?? 'shipinglist_export_lotlist.php';
    header('Location: ' . $self . '?' . http_build_query($params));
    exit;
}

$IS_JAWHA = ($shipTo === '자화전자(주)');

// ✅ build 마킹 로그 초기화 (취소 시 header_id 기반 롤백용)
if (function_exists('oqc_marklog_reset')) oqc_marklog_reset();

$shippingDateStr = $toDate;
$cutoffTime = '08:30:00';
$fromTs = strtotime($fromDate . ' ' . $cutoffTime . ' -1 day');
$toTs   = strtotime($toDate   . ' ' . $cutoffTime);
$fromDt = date('Y-m-d H:i:s', $fromTs);
$toDt   = date('Y-m-d H:i:s', $toTs);

// 생산일 컬럼 자동 감지
$prodCol = detect_table_column($pdo, 'ShipingList', [
    'lot_date','prod_date','production_date','mfg_date','make_date','inj_date','injection_date'
]);

// 금형/툴(TOOL) 컬럼 자동 감지
//  - 네가 말하는 "Tool=차수"는 DB에선 보통 tool 컬럼이거나 revision 컬럼(차수/리비전)에 들어가있다.
//  - shipinglist.sql 기본 구조에는 tool 컬럼이 없고 revision 이 차수 역할을 한다.
$toolCol = detect_table_column($pdo, 'ShipingList', [
    'tool','tool_name',
    'revision','rev',
    'moldtype','mold_type','mold','moldtype_name','moldtypeid','mold_type_id'
]);

?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>성적서 발행 중...</title>
<style>
body{margin:0;padding:20px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#202124;color:#e8eaed;}
.wrap{max-width:900px;margin:0 auto;}
.card{background:#2b2b2b;border-radius:14px;padding:16px 18px;box-shadow:0 8px 20px rgba(0,0,0,0.45);margin-bottom:18px;}
.log{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
     font-size:12px; line-height:1.55; white-space:pre-wrap; color:#d7dce3;}
.small{color:#9aa0a6;font-size:12px;}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#3c4043;color:#e8eaed;font-size:12px;margin-left:8px;}
</style>
</head>
<body>
<script>
(function(){
  try{
    if(window.parent && window.parent !== window){
      window.parent.postMessage({type:'report_build',status:'started',build_token:'<?=h($buildToken ?? '') ?>'},'*');
    }
  }catch(e){}
})();
</script>
<div class="wrap">
  <h2 style="margin:0 0 12px;">성적서 발행 중... <span class="badge">진행 로그</span></h2>
  <div class="card">
    <div class="small">기간: <?=h($fromDate)?> ~ <?=h($toDate)?> / 납품처: <?=h($shipTo)?></div>
    <div id="log" class="log"><?php



function build_cancel_handle_if_needed(): void {
    if (!empty($GLOBALS['_BUILD_CANCEL_HANDLED'])) return;
    $token = (string)($GLOBALS['BUILD_CANCEL_TOKEN'] ?? '');
    if ($token === '') return;
    if (!build_cancel_is_requested($token)) return;

    $GLOBALS['_BUILD_CANCEL_HANDLED'] = 1;

    echo "
[취소] 사용자가 발행을 취소했습니다. 롤백 중...
";
    @ob_flush(); @flush();

    $pdo = $GLOBALS['PDO_REF'] ?? null;
    if ($pdo instanceof PDO) {
        try {
            $meta = init_oqc_db_meta($pdo);
            $ml   = oqc_marklog_export_grouped();
            oqc_rollback_marks_by_marklog($pdo, $meta, $ml, false);
            echo "[취소] 롤백 완료
";
        } catch (Throwable $e) {
            echo "[취소] 롤백 중 오류: " . $e->getMessage() . "
";
        }
    }

    build_cancel_request_clear($token);

    echo "[취소] 중단 완료. 창을 닫아도 됩니다.
";

    // 남은 HTML 닫고 parent에 통지
    $t = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    echo "</div></div></div>";
    echo "<script>(function(){try{if(window.parent&&window.parent!==window){window.parent.postMessage({type:'report_build',status:'canceled',build_token:'".$t."'},'*');}}catch(e){}})();</script>";
    echo "</body></html>";
    exit;
}
function logline(string $s, string $level = 'INFO'): void {
    build_cancel_handle_if_needed();
    $debug = !empty($GLOBALS['DEBUG']);
    $lv = strtoupper(trim($level));

    // ✅ 기본(비-debug) 모드에서는 "진행 로그"는 최대한 살리고,
    //    SQL/내부 디버그/주석 형태 메시지만 숨긴다. (메시지 커스텀 보존)
    if (!$debug) {
        if ($lv === 'DEBUG') return;

        $t = ltrim($s);
        if ($t !== '') {
            // 주석처럼 시작하는 라인 숨김
            if (preg_match('/^(\/\/|#)/u', $t)) return;

            // 디버그 표기 숨김
            if (stripos($t, 'DEBUG') !== false || stripos($t, '[DEBUG]') !== false) return;

            // SQL/쿼리 문자열 숨김
            if (preg_match('/^\s*(SQL|SELECT|WITH|INSERT|UPDATE|DELETE)\b/i', $t)) return;
            if (stripos($t, 'SQL:') !== false) return;
            if (strpos($t, '쿼리') !== false) return;
            // OQC 성공/상세 로그 숨김 (사용자 화면 불필요)
            if (strpos($t, 'OQC 템플릿 기본폴더:') !== false) return;
            if (strpos($t, 'OQC 템플릿 OK:') !== false) return;
            if (strpos($t, '[FAI MAP]') !== false) return;
            if (strpos($t, 'OQC 생성:') !== false && strpos($t, '실패') === false && stripos($t, 'error') === false) return;
            // 사용자 요청: 마킹/긴급소진/FAI예약 로그는 기본 모드에서 숨김
            if (strpos($t, '[FAI 예약]') !== false) return;
            if (strpos($t, '신규 사용 meas_date 마킹') !== false) return;
            if (strpos($t, '긴급 재사용(meas_date2) 소진') !== false) return;

        }
    }

    echo '[' . date('H:i:s') . '] ' . h($s) . "\n";
    flush_now();
}



function dlog(string $s): void { logline($s, 'DEBUG'); }
function wlog(string $s): void { logline($s, 'WARN'); }

// ─────────────────────────────
// LibreOffice headless: xlsx → pdf 변환
// ─────────────────────────────
function win_cmd_quote(string $s): string {
    // Windows cmd.exe 인자용 안전 따옴표
    $s = str_replace('"', '""', $s);
    return '"' . $s . '"';
}

function lo_profile_url(string $absPath): string {
    // file:///C:/path 형태 (공백만 %20 처리)
    $p = str_replace('\\', '/', $absPath);
    $p = str_replace(' ', '%20', $p);
    return 'file:///' . $p;
}

function rrmdir_safe(string $dir): void {
    if ($dir === '' || !is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname());
    }
    @rmdir($dir);
}


function lo_kill_process_tree(int $pid): void {
    $pid = (int)$pid;
    if ($pid <= 0) return;

    $isWin = false;
    if (defined('PHP_OS_FAMILY')) {
        $isWin = (PHP_OS_FAMILY === 'Windows');
    } else {
        $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    }

    if ($isWin) {
        // /T: 자식 프로세스까지, /F: 강제
        @exec("taskkill /F /T /PID {$pid} 2>NUL");
        return;
    }

    // POSIX 계열 best-effort
    if (function_exists('posix_kill')) {
        @posix_kill($pid, 15);
        usleep(200000);
        @posix_kill($pid, 9);
    } else {
        @exec("kill -TERM {$pid} 2>/dev/null");
        usleep(200000);
        @exec("kill -KILL {$pid} 2>/dev/null");
    }
}




// ─────────────────────────────
// LibreOffice 변환 동시 실행 제어(프로세스 와르르 방지)
//  - 여러 요청이 동시에 PDF 변환을 돌리면 soffice가 누적/폭주할 수 있어 전역 lock으로 직렬화
// ─────────────────────────────
function lo_global_lock_acquire(int $waitSec = 300) {
    if (isset($GLOBALS['__LO_LOCK_FP']) && is_resource($GLOBALS['__LO_LOCK_FP'])) {
        return $GLOBALS['__LO_LOCK_FP'];
    }
    $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dptest_lo_convert.lock';
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) return null;

    $start = time();
    while (!@flock($fp, LOCK_EX | LOCK_NB)) {
        if ((time() - $start) >= $waitSec) {
            @fclose($fp);
            return null;
        }
        usleep(200000);
    }

    $GLOBALS['__LO_LOCK_FP'] = $fp;
    return $fp;
}

function lo_global_lock_release(): void {
    if (isset($GLOBALS['__LO_LOCK_FP']) && is_resource($GLOBALS['__LO_LOCK_FP'])) {
        @flock($GLOBALS['__LO_LOCK_FP'], LOCK_UN);
        @fclose($GLOBALS['__LO_LOCK_FP']);
    }
    unset($GLOBALS['__LO_LOCK_FP']);
}

function lo_pick_soffice_path(string $sofficePath): string {
    // Windows에서 soffice.exe가 자식(soffice.bin)로 위임 후 빠르게 종료하는 케이스가 있어
    // 콘솔용 soffice.com이 있으면 그걸 우선 사용하면 대기/종료가 더 안정적인 경우가 많음.
    $p = $sofficePath;
    $lower = strtolower($p);
    if (substr($lower, -10) === 'soffice.exe') {
        $com = substr($p, 0, -3) . 'com';
        if (@is_file($com)) return $com;
    }
    return $p;
}

// ─────────────────────────────
// XLSX print settings: "한 시트 = 한 페이지(Fit to 1x1)" 강제
//  - LibreOffice PDF 변환(calc_pdf_Export) 시 시트가 조각조각 여러 페이지로 쪼개지는 현상 방지
//  - 템플릿/패치 방식(XLSX ZIP XML) 모두에 적용 가능
// ─────────────────────────────
function xlsx_patch_fit_one_page_xml(string $xml): string {
    // ✅ DOM 기반으로 안전하게 패치(정규식 패치로 sheetPr가 self-closing인 템플릿에서 무효/깨짐 방지)
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    if (!@$dom->loadXML($xml)) return $xml;

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $ws = $dom->documentElement;
    if (!($ws instanceof DOMElement)) return $xml;

    // 0) 수동 페이지 나눔 제거(rowBreaks/colBreaks)
    foreach (['rowBreaks','colBreaks'] as $tag) {
        $nodes = $xp->query('./x:' . $tag, $ws);
        if ($nodes && $nodes->length > 0) {
            // NodeList는 live라 뒤에서부터 삭제
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $n = $nodes->item($i);
                if ($n && $n->parentNode) $n->parentNode->removeChild($n);
            }
        }
    }

    // 1) sheetPr/pageSetUpPr fitToPage="1" 보장
    $sheetPr = $xp->query('./x:sheetPr', $ws)->item(0);
    if (!($sheetPr instanceof DOMElement)) {
        $sheetPr = $dom->createElementNS($ws->namespaceURI, 'sheetPr');
        // worksheet 최상단 쪽에 삽입(첫 element 앞)
        $firstEl = null;
        foreach ($ws->childNodes as $ch) { if ($ch instanceof DOMElement) { $firstEl = $ch; break; } }
        if ($firstEl) $ws->insertBefore($sheetPr, $firstEl); else $ws->appendChild($sheetPr);
    }

    $pageSetUpPr = $xp->query('./x:pageSetUpPr', $sheetPr)->item(0);
    if (!($pageSetUpPr instanceof DOMElement)) {
        $pageSetUpPr = $dom->createElementNS($ws->namespaceURI, 'pageSetUpPr');
        $sheetPr->appendChild($pageSetUpPr);
    }
    $pageSetUpPr->setAttribute('fitToPage', '1');

    // 2) pageSetup fitToWidth/fitToHeight = 1 (scale 제거)
    $pageSetup = $xp->query('./x:pageSetup', $ws)->item(0);
    if (!($pageSetup instanceof DOMElement)) {
        $pageSetup = $dom->createElementNS($ws->namespaceURI, 'pageSetup');
        // pageMargins 뒤에 넣는게 일반적(없으면 끝에)
        $pageMargins = $xp->query('./x:pageMargins', $ws)->item(0);
        if ($pageMargins instanceof DOMElement) {
            if ($pageMargins->nextSibling) $ws->insertBefore($pageSetup, $pageMargins->nextSibling);
            else $ws->appendChild($pageSetup);
        } else {
            $ws->appendChild($pageSetup);
        }
    }

    if ($pageSetup->hasAttribute('scale')) $pageSetup->removeAttribute('scale');
    $pageSetup->setAttribute('fitToWidth', '1');
    $pageSetup->setAttribute('fitToHeight', '1');

    return $dom->saveXML();
}


function xlsx_force_fit_one_page_zip(string $xlsxPath): array {
    if (!is_file($xlsxPath)) return ['ok'=>false, 'err'=>'xlsx not found', 'path'=>$xlsxPath];

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        return ['ok'=>false, 'err'=>'zip open failed', 'path'=>$xlsxPath];
    }

    $deletedPrinter = 0;
    $patchedRels    = 0;
    $patchedSheets  = 0;

    // (추가) Print_Area/Print_Titles 정의가 남아있으면 LibreOffice가 그 범위대로 "여러 페이지"로 고정 출력하는 케이스가 있음 → 제거
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if ($wbXml !== false && $wbXml !== '') {
        $fixed = $wbXml;
        // Print_Area / Print_Titles definedName만 제거(다른 named range는 유지)
        $fixed = preg_replace('/<definedName\b[^>]*\bname="_xlnm\.Print_(Area|Titles)"[^>]*>.*?<\/definedName>/s', '', $fixed);
        // definedNames가 비어버리면 컨테이너 제거
        $fixed = preg_replace('/<definedNames\b[^>]*>\s*<\/definedNames>/s', '', $fixed);
        if ($fixed !== null && $fixed !== $wbXml) {
            $zip->addFromString('xl/workbook.xml', $fixed);
        }
    }

    // 0) printerSettings 제거(특정 프린터 설정이 남아있으면 LibreOffice가 스케일/페이지 설정을 무시하는 케이스 방지)
    $toDelete = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!$name) continue;
        if (preg_match('#^xl/printerSettings/[^/]+\.bin$#i', $name)) {
            $toDelete[] = $name;
        }
    }
    foreach ($toDelete as $name) {
        if ($zip->deleteName($name)) $deletedPrinter++;
    }

    // printerSettings relationship 제거(시트 rels / workbook rels)
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!$name) continue;
        if (!preg_match('#^(xl/_rels/workbook\.xml\.rels|xl/worksheets/_rels/[^/]+\.rels)$#i', $name)) continue;

        $xml = $zip->getFromName($name);
        if ($xml === false) continue;

        $new = $xml;
        // Type 또는 Target에 printerSettings가 포함된 Relationship 제거
        $new = preg_replace('/<Relationship\b[^>]*\bType="[^"]*printerSettings[^"]*"[^>]*\/>/i', '', $new);
        $new = preg_replace('/<Relationship\b[^>]*\bTarget="[^"]*printerSettings[^"]*"[^>]*\/>/i', '', $new);

        if ($new !== $xml) {
            $zip->addFromString($name, $new);
            $patchedRels++;
        }
    }

    // 1) worksheets XML: FitToPage(1x1) + pageBreak 제거
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!$name) continue;

        // worksheets only (템플릿에 따라 sheet1.xml 외 다른 이름도 있을 수 있어 전체 패치)
        if (!preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name)) continue;

        $xml = $zip->getFromName($name);
        if ($xml === false) continue;

        $newXml = xlsx_patch_fit_one_page_xml($xml);
        if ($newXml !== $xml) {
            $zip->addFromString($name, $newXml);
            $patchedSheets++;
        }
    }

    $zip->close();
    return [
        'ok' => true,
        'patched_sheets' => $patchedSheets,
        'patched_rels'   => $patchedRels,
        'deleted_printer_settings' => $deletedPrinter,
        'path' => $xlsxPath
    ];
}


function lo_convert_xlsx_to_pdf(string $xlsxPath, ?string $outDir = null, ?int $timeoutSec = null): array {
    // 전역 lock: 동시에 여러 변환이 돌면서 soffice 프로세스가 우르르 쌓이는 현상 방지
    $lock = lo_global_lock_acquire((int)($GLOBALS['PDF_EXPORT_LOCK_WAIT_SEC'] ?? 300));
    if ($lock === null) {
        return ['ok'=>false,'err'=>'LibreOffice converter busy (lock timeout)','xlsx'=>$xlsxPath];
    }

    $sofficeRaw = (string)($GLOBALS['SOFFICE_PATH'] ?? '');
    $soffice    = lo_pick_soffice_path($sofficeRaw);
    $timeout    = $timeoutSec ?? (int)($GLOBALS['PDF_EXPORT_TIMEOUT_SEC'] ?? 90);
    if ($outDir === null || $outDir === '') $outDir = dirname($xlsxPath);

    if ($soffice === '' || !is_file($soffice)) {
        lo_global_lock_release();
        return ['ok'=>false,'err'=>'LibreOffice soffice not found','soffice'=>$sofficeRaw,'xlsx'=>$xlsxPath];
    }
    if (!is_file($xlsxPath)) {
        lo_global_lock_release();
        return ['ok'=>false,'err'=>'xlsx not found','xlsx'=>$xlsxPath];
    }
    if (!is_dir($outDir)) @mkdir($outDir, 0777, true);

    $base = pathinfo($xlsxPath, PATHINFO_FILENAME);
    $pdfPath = rtrim($outDir, "\/") . DIRECTORY_SEPARATOR . $base . '.pdf';

    // 이미 존재하면 삭제(오래된 pdf가 남아있으면 성공처럼 보임)
    if (is_file($pdfPath)) @unlink($pdfPath);

    // 시도할 convert-to 파라미터(환경 따라 다름)
    $convertToCandidates = [];
    $convertToCandidates[] = 'pdf:calc_pdf_Export'; // Calc 전용(가장 안정적인 편)
    $convertToCandidates[] = 'pdf';                // fallback

    $lastFail = null;

    try {
        foreach ($convertToCandidates as $convertTo) {
        // soffice는 user profile lock 문제가 자주 생기므로 매번 임시 profile로 분리
        $profileDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lo_profile_' . uniqid('', true);
        @mkdir($profileDir, 0777, true);
        $profileUrl = lo_profile_url($profileDir);

        // stdout/stderr를 파일로 리다이렉트(파이프 읽기에서 hang 방지)
        $outLog = $profileDir . DIRECTORY_SEPARATOR . 'stdout.log';
        $errLog = $profileDir . DIRECTORY_SEPARATOR . 'stderr.log';

        $cmd = win_cmd_quote($soffice) .
            ' --headless --nologo --nodefault --nofirststartwizard --norestore --invisible --nocrashreport --nolockcheck ' .
            win_cmd_quote('-env:UserInstallation=' . $profileUrl) .
            ' --convert-to ' . win_cmd_quote($convertTo) .
            ' --outdir ' . win_cmd_quote($outDir) .
            ' ' . win_cmd_quote($xlsxPath);

        $proc = @proc_open($cmd, [
            1 => ['file', $outLog, 'w'],
            2 => ['file', $errLog, 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            rrmdir_safe($profileDir);
            $lastFail = ['ok'=>false,'err'=>'proc_open failed','cmd'=>$cmd,'xlsx'=>$xlsxPath,'pdf'=>$pdfPath,'convertTo'=>$convertTo];
            continue;
        }

        $pid = 0;
        $t0 = microtime(true);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($proc);
            if (isset($status['pid']) && $status['pid']) $pid = (int)$status['pid'];

            if (!$status['running']) break;

            if ((microtime(true) - $t0) > $timeout) {
                $timedOut = true;
                break;
            }
            usleep(200000);
        }

        $exitCode = null;

        if ($timedOut) {
            // 1차: proc_terminate 시도
            @proc_terminate($proc);

            // 2차: 프로세스 트리 강제 종료(Windows soffice가 잘 안 죽는 케이스)
            if ($pid) lo_kill_process_tree($pid);

            // 종료 대기(최대 5초)
            $killWait = microtime(true);
            while ((microtime(true) - $killWait) < 5.0) {
                $st = proc_get_status($proc);
                if (empty($st['running'])) break;
                usleep(200000);
            }

            // 그래도 살아있으면 한 번 더
            $st = proc_get_status($proc);
            if (!empty($st['running']) && $pid) lo_kill_process_tree($pid);

            // timeout 케이스는 proc_close가 무한 블럭되는 환경이 있어서, running이면 close를 건너뜀
            $st2 = proc_get_status($proc);
            if (empty($st2['running'])) {
                $exitCode = @proc_close($proc);
            }
        } else {
            $exitCode = @proc_close($proc);
        }
        // PDF 생성 대기: 변환이 느린 경우(대용량/IO) 10초로는 부족해서 timeout 범위 내에서 충분히 기다림
        $waitStart = microtime(true);
        $waitMax = (float)min(180.0, max(20.0, (float)$timeout + 10.0));
        while (!is_file($pdfPath) && (microtime(true) - $waitStart) < $waitMax) {
            usleep(200000);
        }

        // 로그 읽기(있으면)
        $out = is_file($outLog) ? (string)@file_get_contents($outLog) : '';
        $err = is_file($errLog) ? (string)@file_get_contents($errLog) : '';

        rrmdir_safe($profileDir);

        if (is_file($pdfPath) && filesize($pdfPath) > 100) {
            return ['ok'=>true,'pdf'=>$pdfPath,'cmd'=>$cmd,'exit'=>($exitCode ?? 0),'stdout'=>$out,'stderr'=>$err,'xlsx'=>$xlsxPath,'convertTo'=>$convertTo,'soffice'=>$soffice];
        }

        $lastFail = ['ok'=>false,'err'=>($timedOut ? 'timeout' : 'pdf not created'),'pdf'=>$pdfPath,'cmd'=>$cmd,'exit'=>($timedOut ? -2 : ($exitCode ?? -1)),'stdout'=>$out,'stderr'=>$err,'xlsx'=>$xlsxPath,'convertTo'=>$convertTo,'soffice'=>$soffice];
    }
        return $lastFail ?? ['ok'=>false,'err'=>'pdf not created','pdf'=>$pdfPath,'xlsx'=>$xlsxPath];
    } finally {
        lo_global_lock_release();
    }
}



// (삭제) 템플릿 폴더 로그는 불필요하여 출력하지 않음


// 호환용(예전 코드에서 write_log()를 사용하던 흔적)
if (!function_exists('write_log')) {
    function write_log(string $s): void { logline($s); }
}

//logline('ShipingList 컬럼 감지: prod_date=' . ($GLOBALS['prodCol'] ?? 'NONE') . ' / tool=' . ($GLOBALS['toolCol'] ?? 'NONE') . ' / cavity=cavity');

logline('1) 데이터 카운트 집계 중...');

// part별 행수(작은 쿼리)
$stmtCnt = $pdo->prepare("
    SELECT part_name, COUNT(*) AS cnt, COALESCE(SUM(qty),0) AS qty_sum
    FROM ShipingList
    WHERE ship_datetime >= :from_dt
      AND ship_datetime <  :to_dt
      AND ship_to = :ship_to
    GROUP BY part_name
    ORDER BY part_name
");
$stmtCnt->execute([':from_dt'=>$fromDt, ':to_dt'=>$toDt, ':ship_to'=>$shipTo]);
$counts = [];
$sumQty = [];
while ($r = $stmtCnt->fetch(PDO::FETCH_ASSOC)) {
    $pn = (string)$r['part_name'];
    $counts[$pn] = (int)$r['cnt'];
    $sumQty[$pn] = (int)($r['qty_sum'] ?? 0);
}
if (!$counts) {
    logline('데이터 없음. 종료.');
    echo "</div></div></div></body></html>";
    exit;
}

logline('모델: ' . count($counts) . ' / 총 행: ' . array_sum($counts));

// 작업 디렉터리/토큰
$token = bin2hex(random_bytes(16));
$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "oqc_export_{$token}";
@mkdir($workDir, 0777, true);

// OQC 생성 파일은 workDir/OQC 아래에 저장 (대문자 폴더명 고정)
$oqcDir = $workDir . DIRECTORY_SEPARATOR . 'OQC';
@mkdir($oqcDir, 0777, true);

// CMM(자화용) 템플릿은 workDir/cmm 아래에 저장
$cmmDir = $workDir . DIRECTORY_SEPARATOR . 'cmm';
@mkdir($cmmDir, 0777, true);


$createdFiles = [];      // shipping lot list
$createdReportByPart = []; // part_name => report xlsx path

$reportParts = [];
$totalOqcNew = 0;
$totalOqcEmg = 0;

$createdOqcFiles = [];   // oqc files
$oqcAgg = [];            // [part]['dates'=>set, 'cavs'=>set]

$createdCmmFiles = [];   // cmm template files

// 메인 조회(정렬) - 스트리밍 처리
logline('2) 성적서 발행 시작(스트리밍)...');

$extraSelect = '';
if ($prodCol) $extraSelect .= ", `{$prodCol}` AS prod_date";
if ($toolCol) $extraSelect .= ", `{$toolCol}` AS tool";

$sql = "
SELECT ship_datetime, part_name, cavity, qty, customer_lot_id, pack_no, ann_date {$extraSelect}
FROM ShipingList
WHERE ship_datetime >= :from_dt
  AND ship_datetime <  :to_dt
  AND ship_to = :ship_to
ORDER BY part_name, pack_no, ship_datetime, id
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':from_dt'=>$fromDt, ':to_dt'=>$toDt, ':ship_to'=>$shipTo]);

// 파트별로 엑셀을 “열어두고” 처리
$currentPart = null;
$sheet = null;
$spreadsheet = null;
$reader = null;
$writer = null;

$curRow = 2;
$chunkData = [];
$mergePlans = []; // [start,end,totalQty]
$curPack = null;
$packStartRow = 2;
$packTotalQty = 0;

$partLastRow = 0;

$finalizePart = function() use (
    &$currentPart, &$spreadsheet, &$sheet, &$writer, &$reader,
    &$chunkData, &$curRow, &$mergePlans, &$createdFiles, &$createdReportByPart, &$workDir,
    &$packStartRow, &$packTotalQty, &$curPack, $IS_JAWHA,
    $PART_MAP, $shippingDateStr
) {
    if (!$currentPart || !$spreadsheet || !$sheet) return;

    
    // ✅ 마지막 pack flush (마지막 묶음은 pack 변경 이벤트가 없어서 mergePlans에 안 들어갈 수 있음)
    if ($curPack !== null) {
        $packEnd = $curRow - 1;
        if ($packEnd >= $packStartRow) {
            $mergePlans[] = [$packStartRow, $packEnd, $packTotalQty];
        }
        $curPack = null;
    }

// 남은 chunk flush
    if ($chunkData) {
        $startCell = "A" . ($curRow - count($chunkData));
        $sheet->fromArray($chunkData, null, $startCell, true);
        $chunkData = [];
    }

    // merge + total
    // ✅ 자화(JAWHA)라도 Z-STOPPER는 Shipping Lot List가 기본 포맷(Annealing Date 없음)이므로
    //    pack merge/total 위치도 기본 포맷 기준으로 처리한다.
    $useJawhaLotList = ($IS_JAWHA && $currentPart !== 'MEM-Z-STOPPER');
    foreach ($mergePlans as [$s, $e, $tq]) {
        if ($useJawhaLotList) {
            // JAWHA(일반): PackNo=F, TotalQty=G
            $sheet->setCellValueExplicit("G{$s}", (int)$tq, DataType::TYPE_NUMERIC);
            if ($e > $s) {
                $sheet->mergeCells("F{$s}:F{$e}");
                $sheet->mergeCells("G{$s}:G{$e}");
            }
        } else {
            // LGIT 등 기본: PackNo=E, TotalQty=F
            $sheet->setCellValueExplicit("F{$s}", (int)$tq, DataType::TYPE_NUMERIC);
            if ($e > $s) {
                $sheet->mergeCells("E{$s}:E{$e}");
                $sheet->mergeCells("F{$s}:F{$e}");
            }
        }
    }

    // 저장 (REPORT 파일명은 템플릿 ZIP 규칙에 맞춘다)
    // 예: OQC_Report_Memphis_MP_IR Base_20251212.xlsx
    $ymd = @date('Ymd', strtotime((string)$shippingDateStr));
    if (!$ymd) $ymd = date('Ymd');

    $outName = null;
    if (isset($PART_MAP[(string)$currentPart]['template'])) {
        $tmplName = (string)$PART_MAP[(string)$currentPart]['template'];
        // 템플릿명은 끝이 "_.xlsx" 형태(날짜 자리)라고 가정한다.
        $outName = preg_replace('/_\\.xlsx$/', '_' . $ymd . '.xlsx', $tmplName);
        if (!$outName) $outName = $tmplName;
    }
    if (!$outName) {
        $safePart = str_replace(['/', '\\'], '_', (string)$currentPart);
        $outName = sprintf('OQC_Report_Memphis_MP_%s_%s.xlsx', $safePart, $ymd);
    }
    $tmpPath = $workDir . DIRECTORY_SEPARATOR . $outName;

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->setPreCalculateFormulas(false);
    if (method_exists($writer, 'setUseDiskCaching')) {
        $writer->setUseDiskCaching(true, sys_get_temp_dir());
    }
    $writer->save($tmpPath);

    // definedNames 제거(엑셀 경고 방지)
    $zipFix = new ZipArchive();
    if ($zipFix->open($tmpPath) === true) {
        $xml = $zipFix->getFromName('xl/workbook.xml');
        if ($xml !== false) {
            $fixed = preg_replace('/<definedNames[^>]*>.*?<\\/definedNames>/s', '', $xml);
            if ($fixed !== null) $zipFix->addFromString('xl/workbook.xml', $fixed);
        }
        $zipFix->close();
    }

    // ✅ PDF 변환/뷰어용: 한 시트를 한 페이지로 강제(Fit 1x1)
    // (LibreOffice calc_pdf_Export가 시트를 조각조각 여러 페이지로 나누는 현상 방지)
    xlsx_force_fit_one_page_zip($tmpPath);

    $createdFiles[] = ['path'=>$tmpPath, 'name'=>$outName];
    $createdReportByPart[$currentPart] = $tmpPath;

    // 해제
    disconnect_book($spreadsheet);
    $spreadsheet = null;
    $sheet = null;
    $writer = null;
    $reader = null;

    $mergePlans = [];
    $curPack = null;
    $packTotalQty = 0;
    $packStartRow = 2;
    $curRow = 2;
};

$openPart = function(string $partName, int $rowCount) use (
    $PART_MAP, $TEMPLATE_DIR, $STYLE_CHUNK, $CLEAR_SAMPLE_ROWS, $shippingDateStr, $IS_JAWHA,
    &$spreadsheet, &$sheet, &$reader, &$curRow, &$chunkData, &$mergePlans, &$curPack, &$packStartRow, &$packTotalQty, &$partLastRow
) {
    if (!isset($PART_MAP[$partName])) return [null, null];

    $info = $PART_MAP[$partName];
    $templateFile = $TEMPLATE_DIR . '/' . $info['template'];
    if (!is_file($templateFile)) return [null, null];

    $partLastRow = 1 + $rowCount;

    $reader = IOFactory::createReader('Xlsx');
    if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(false);
    if (method_exists($reader, 'setIncludeCharts')) $reader->setIncludeCharts(true);// ✅ 도형/차트/드로잉 유지
    if (method_exists($reader, 'setReadEmptyCells')) $reader->setReadEmptyCells(true);// ✅ 빈셀 서식(테두리) 보존

    $spreadsheet = $reader->load($templateFile);

    // NamedRange 제거
    foreach ($spreadsheet->getNamedRanges() as $name => $nr) {
        $spreadsheet->removeNamedRange($name);
    }

    $sheet = $spreadsheet->getSheetByName('Shipping Lot List');
    if (!$sheet) return [null, null];

    // ✅ 자화(JAWHA)라도 Z-STOPPER는 Shipping Lot List가 기본 포맷(Annealing Date 없음)
    //    → 샘플 삭제/스타일 복제/숫자 포맷 범위를 기본 포맷 기준으로 처리한다.
    $useJawhaLotList = ($IS_JAWHA && $partName !== 'MEM-Z-STOPPER');
    // merge 해제는 하지 않음 (템플릿 레이아웃/선 유지)
    // 샘플 데이터만 "작게" 지우기
    for ($rr = 2; $rr <= $CLEAR_SAMPLE_ROWS; $rr++) {
        foreach (($useJawhaLotList ? range('A','J') : range('A','I')) as $c) {
            $sheet->setCellValueExplicit($c.$rr, null, DataType::TYPE_NULL);
        }
    }

    // 스타일 복제(필요 행까지만, chunk)
    duplicate_style_chunked($sheet, ($useJawhaLotList ? 'A2:J2' : 'A2:I2'), 2, $partLastRow, 'A', ($useJawhaLotList ? 'J' : 'I'), $STYLE_CHUNK);

    // 숫자 포맷
    $sheet->getStyle(($useJawhaLotList ? "G2:I{$partLastRow}" : "F2:H{$partLastRow}"))->getNumberFormat()->setFormatCode('0');

    // 초기화
    $curRow = 2;
    $chunkData = [];
    $mergePlans = [];
    $curPack = null;
    $packStartRow = 2;
    $packTotalQty = 0;

    return [$spreadsheet, $sheet];
};

// 스트리밍 루프
$processed = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pn = (string)$row['part_name'];

    // OQC 집계(파트별/생산일별 Tool#Cavity set)
    if (!isset($oqcAgg[$pn])) $oqcAgg[$pn] = ['dates'=>[], 'cavs'=>[], 'pairs'=>[], 'by_date'=>[]];

    $cv = (int)($row['cavity'] ?? 0);
    if ($cv > 0) $oqcAgg[$pn]['cavs'][$cv] = true;

    // 생산일 (YYYY-MM-DD) : 출하내역에서 중복제거 리스트 만들기
    $d = '';
    if ($prodCol && !empty($row['prod_date'])) {
        $d = substr((string)$row['prod_date'], 0, 10);
        if ($d !== '') $oqcAgg[$pn]['dates'][$d] = true;
    }

    // Tool#Cavity (출하내역 기준) : 생산일별로 따로 모아둔다 (중복 제거)
    if ($toolCol && $cv > 0) {
        $tRaw = (string)($row['tool'] ?? '');
        $t = $tRaw !== '' ? extract_tool_letter($tRaw) : null;
        if ($t) {
            $k = $t . '#' . $cv;
            $oqcAgg[$pn]['pairs'][$k] = true;

            if ($d !== '') {
                if (!isset($oqcAgg[$pn]['by_date'][$d])) $oqcAgg[$pn]['by_date'][$d] = ['pairs'=>[]];
                $oqcAgg[$pn]['by_date'][$d]['pairs'][$k] = true;
            }
        }
    }

    // 파트 전환
    if ($currentPart === null) {
        $currentPart = $pn;
        if (!isset($counts[$currentPart])) $counts[$currentPart] = 0;

        logline(" - {$currentPart} 모델 시작 {$counts[$currentPart]} 행");
        [$spreadsheet, $sheet] = $openPart($currentPart, $counts[$currentPart]);
        if (!$spreadsheet || !$sheet) {
            // 템플릿 없으면 그냥 스킵
            $currentPart = null;
            continue;
        }
    } elseif ($pn !== $currentPart) {
        // 이전 파트 마감
        $finalizePart();

        $currentPart = $pn;
        logline(" - {$currentPart} 모델 시작 {$counts[$currentPart]} 행");
        [$spreadsheet, $sheet] = $openPart($currentPart, $counts[$currentPart]);
        if (!$spreadsheet || !$sheet) {
            $currentPart = null;
            continue;
        }
    }

    if (!$spreadsheet || !$sheet) continue;

    // pack 처리(merge 계획)
    $packNo = (string)($row['pack_no'] ?: '(NO PACK_NO)');
    if ($curPack === null) {
        $curPack = $packNo;
        $packStartRow = $curRow;
        $packTotalQty = 0;
    } elseif ($packNo !== $curPack) {
        // 이전 pack 종료
        $packEnd = $curRow - 1;
        $mergePlans[] = [$packStartRow, $packEnd, $packTotalQty];

        // 새 pack 시작
        $curPack = $packNo;
        $packStartRow = $curRow;
        $packTotalQty = 0;
    }
    // inner package (Shipping Lot List D열)
    // ✅ customer_lot_id를 그대로 사용한다 (하드코딩 prefix / pack_barcode 보강 없음)
    $info = $PART_MAP[$currentPart];
    $innerPackage = trim((string)($row['customer_lot_id'] ?? ''));

    $qty = (int)($row['qty'] ?? 0);
    $packTotalQty += $qty;

    // row 데이터
    // JAWHA(자화전자(주)) Shipping Lot List: Annealing Date(ann_date)는 D열, Build는 C열(템플릿 변경 반영)
    $annRaw = trim((string)($row['ann_date'] ?? ''));
    $annDateStr = '';
    if ($annRaw !== '') {
        if (preg_match('/^\d{8}$/', $annRaw)) {
            $annDateStr = substr($annRaw, 0, 4) . '-' . substr($annRaw, 4, 2) . '-' . substr($annRaw, 6, 2);
        } else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $annRaw)) {
            $annDateStr = $annRaw;
        } else {
            // 기타 포맷은 그대로(혹시 "2025/12/12" 등)
            $annDateStr = $annRaw;
        }
    }

    // ✅ 자화(JAWHA)라도 Z-STOPPER는 어닐링일자(Annealing Date)가 없으므로
    //    Shipping Lot List는 기본 포맷(Annealing Date 컬럼 없음)으로 출력한다.
    if ($IS_JAWHA && $currentPart !== 'MEM-Z-STOPPER') {
        // (JAWHA 전용 포맷)
        // A:APN, B:ShipDate, C:Build(MP), D:AnnealingDate, E:InnerPkg, F:PackNo, G:TotalQty(merge), H:Cavity, I:ShipQty, J:Unit
        $chunkData[] = [
            (string)$info['apn'],         // A
            (string)$shippingDateStr,     // B
            'MP',                         // C
            (string)$annDateStr,          // D
            (string)$innerPackage,        // E
            (string)$packNo,              // F
            null,                         // G (나중에 packStartRow에만 total)
            (int)$cv,                     // H
            (int)$qty,                    // I
            'PCS',                        // J
        ];
    } else {
        // 기본(LGIT 등): A:APN, B:ShipDate, C:Build(MP), D:InnerPkg, E:PackNo, F:TotalQty(merge), G:Cavity, H:ShipQty, I:Unit
        $chunkData[] = [
            (string)$info['apn'],         // A
            (string)$shippingDateStr,     // B
            'MP',                         // C
            (string)$innerPackage,        // D
            (string)$packNo,              // E
            null,                         // F (나중에 packStartRow에만 total)
            (int)$cv,                     // G
            (int)$qty,                    // H
            'PCS',                        // I
        ];
    }

    $curRow++;
    $processed++;

    // chunk flush
    if (count($chunkData) >= 2000) {
        $startCell = "A" . ($curRow - count($chunkData));
        $sheet->fromArray($chunkData, null, $startCell, true);
        $chunkData = [];
    }

// 로그 갱신
//    if ($processed % 5000 === 0) {
//        logline("   진행: {$processed} rows...");
//    }
}

// 마지막 파트 마감 (마지막 pack도 마감)
if ($currentPart !== null && $spreadsheet && $sheet) {
    // 마지막 pack 종료
    if ($curPack !== null) {
        $packEnd = $curRow - 1;
        $mergePlans[] = [$packStartRow, $packEnd, $packTotalQty];
    }
    $finalizePart();
}

logline('3) OQC 파일 생성 시작...');

$oqcSummaryFirst = true;
// OQC DB 메타(테이블/컬럼) 자동 감지
$OQC_DB_META = init_oqc_db_meta($pdo);

// 납품처별로 마킹 컬럼을 분리해서 사용
// - LGIT(엘지이노텍): meas_date / meas_date2
// - JAWHA(자화전자):  jmeas_date / jmeas_date2
$shipToCodeForMark = $SHIP_TO_TEMPLATE_SUBDIR[$shipTo] ?? '';
if ($shipToCodeForMark === 'JAWHA') {
    // jmeas_* 컬럼이 존재할 때만 meas_* 키를 해당 컬럼으로 대체
    if (!empty($OQC_DB_META['h']['jmeas_date']))  $OQC_DB_META['h']['meas_date']  = $OQC_DB_META['h']['jmeas_date'];
    if (!empty($OQC_DB_META['h']['jmeas_date2'])) $OQC_DB_META['h']['meas_date2'] = $OQC_DB_META['h']['jmeas_date2'];
}
if (empty($OQC_DB_META['t_header']) || empty($OQC_DB_META['t_meas'])) {
    logline('  [경고] OQC DB 테이블(oqc_header/oqc_measurements 또는 oqc_meas)을 찾지 못했습니다. OQC 파일 생성이 스킵될 수 있습니다.');
}


$createdOqcFiles = [];
logline('  OQC 템플릿 기본폴더: ' . (JTMES_ROOT . DIRECTORY_SEPARATOR . 'oqc templates'));
$oqcDiag = [];

$REPORT_PARTS = [];
$REPORT_OQC_NEW_USED = 0;
$REPORT_OQC_EMG_USED = 0;

foreach ($oqcAgg as $part => $agg) {
    $REPORT_PARTS[] = $part;
    // NG(LSL/USL 초과/미달) 예외 처리(=NG 판정만 무시, 데이터 채움은 유지)
    // - DB 테이블 oqc_ng_ignore_point 사용 (없으면 하드코딩 fallback)
    $ngIgnoreSet = db_build_ng_ignore_set($pdo, $part, 'oqc_ng_ignore_point', true);
    if (empty($ngIgnoreSet)) {
        $ngIgnoreSet = [];
        if ($part === 'MEM-IR-BASE') {
            $tmp = ['113-V1','114-V1','115-V1','116-V1','113-V2','114-V2','115-V2','116-V2','119','120','121','122'];
        } elseif ($part === 'MEM-Z-CARRIER') {
            $tmp = ['99-V1','100-V1','101-V1','102-V1','99-V2','100-V2','101-V2','102-V2','105-V3','106-V3','107-V3','108-V3','105-V4','106-V4','107-V4','108-V4'];
        } else {
            $tmp = [];
        }
        foreach ($tmp as $pno) {
            $k = oqc_norm_key($pno);
            if ($k !== '') $ngIgnoreSet[$k] = true;
        }
    }
    $GLOBALS['NG_EXEMPT_TEMPLATE_POINTS'] = $ngIgnoreSet;

    $mapped = $OQC_TEMPLATES[$part] ?? null;
    $tpl = resolve_oqc_template_path($part, $mapped, JTMES_ROOT);
    if (!$tpl || !is_file($tpl)) {
        $msg = "  - OQC 템플릿 못찾음: {$part} / mapped=" . ($mapped ?? '(none)');
        logline($msg);
        $oqcDiag[] = $msg;
        continue;
    }
    $msg = "  - OQC 템플릿 OK: {$part} -> {$tpl}";
    logline($msg);
    $oqcDiag[] = $msg;
    // OQC DB 메타는 파트별로 따로 둘 필요 없이 자동 감지 결과를 사용
    $meta = $OQC_DB_META;

    // [DB point list] 템플릿(OQC 시트 B열) 스캔 대신 DB(oqc_model_point) 기반으로 point_no 목록/행매핑 사용
    $faiMaps = ['full' => [], 'base' => []];
    $tmplPointSet = [];
    try {
        $faiMaps = db_build_fai_row_maps($pdo, $part, 11, 'oqc_model_point', true); // ['full'=>[key=>row], 'base'=>[key=>row]]
        $tmplPointSet = db_build_point_spec_map($pdo, $part, 'oqc_model_point', true);  // [key=>true]
        if (empty($tmplPointSet)) {
            // DB가 비어있으면(초기/오류) 구버전 호환: 템플릿 B열 fallback
            $faiMaps = xlsx_build_fai_row_maps($tpl, 'OQC', 'B');
            $tmplPointSet = [];
            foreach (($faiMaps['full'] ?? []) as $k => $v) { if ($k !== '') $tmplPointSet[$k] = true; }
            logline("  [DB 모델포인트 없음] {$part}: 템플릿 B열 fallback");
        }
    } catch (Throwable $e) {
        // DB/조회 실패 시 구버전 호환: 템플릿 B열 fallback (최대한 중단 없이 진행)
        try {
            $faiMaps = xlsx_build_fai_row_maps($tpl, 'OQC', 'B');
            $tmplPointSet = [];
            foreach (($faiMaps['full'] ?? []) as $k => $v) { if ($k !== '') $tmplPointSet[$k] = true; }
            logline("  [DB 포인트 조회 실패] {$part}: " . $e->getMessage() . " (fallback=template)");
        } catch (Throwable $e2) {
            $faiMaps = ['full' => [], 'base' => []];
            $tmplPointSet = null;
            logline("  [포인트 목록 로드 실패] {$part}: " . $e2->getMessage());
        }

// ✅ (중요) 행 매핑은 템플릿(OQC 시트 B열 point_no) 기준이 정답.
// DB(oqc_model_point)는 스펙/NG 판단용으로만 쓰고, row_index 매핑은 항상 템플릿을 우선한다.
try {
    $tplMaps = xlsx_build_fai_row_maps($tpl, 'OQC', 'B');
    if (!empty($tplMaps) && !empty($tplMaps['full'])) {
        $faiMaps = $tplMaps;

        // underscore(_)->hyphen(-) alias만 추가 (hyphen을 underscore로 "바꿔버리지는" 않음)
        foreach (($faiMaps['full'] ?? []) as $k0 => $r0) {
            if ($k0 !== '' && strpos($k0, '_') !== false) {
                $k1 = str_replace('_', '-', $k0);
                if (!isset($faiMaps['full'][$k1])) $faiMaps['full'][$k1] = $r0;
            }
        }
        foreach (($faiMaps['base'] ?? []) as $k0 => $r0) {
            if ($k0 !== '' && strpos($k0, '_') !== false) {
                $k1 = str_replace('_', '-', $k0);
                if (!isset($faiMaps['base'][$k1])) $faiMaps['base'][$k1] = $r0;
            }
        }

        // 템플릿 기준으로도 point set을 만들 수 있게(백업)
        if (empty($tmplPointSet) || !is_array($tmplPointSet)) {
            $tmplPointSet = [];
            foreach (($faiMaps['full'] ?? []) as $k => $v) { if ($k !== '') $tmplPointSet[$k] = true; }
        }
    }
} catch (Throwable $e) {
    // ignore (템플릿 map 실패 시 기존 DB기반 map 유지)
}


    }


    // (중요) ToolPairs는 템플릿을 "읽어서 추측"하지 않고,
    // 출하내역(생산일별)에서 만들어서 그대로 꽂는다.
    // 또한 AK6:BP6에는 "어느 OQC 날짜(소스파일 YYMMDD)에서 가져왔는지" 출처를 남긴다.

    // ✅ (중요) PASS0~3 전체가 "출하내역에 실제로 존재하는 Tool#Cavity" 기준으로만 돌아야 한다.
    // - oqc_header(측정/원본)에는 같은 prod_date라도 다른 납품처의 데이터가 섞여 있을 수 있음.
    //   이걸 그대로 쓰면 "현재 검색된 출하내역에는 없는 툴"이 끼어들어간다.
    // - 따라서:
    //   1) oqc_header에서는 "source_file 목록"(출처 태그)만 가져오고
    //   2) 실제 Tool#Cavity 조합(pairs)은 무조건 출하내역 집계($agg['by_date'])로 강제한다.

    // 출하내역 기준(생산일별/전체) Tool#Cavity set
    $shipByDate = $agg['by_date'] ?? [];
    $shipPairsAll = $agg['pairs'] ?? [];

    // PASS3 보강 시에도 "출하내역에 존재하는 tc"만 허용
    $allowedTcSet = [];
    foreach (array_keys($shipPairsAll) as $tc0) {
        $tn = normalize_tool_cavity_key($tc0);
        if ($tn !== '') $allowedTcSet[$tn] = true;
    }

    // ✅ byDate는 "출하내역(납품처 필터 적용된 shipByDate)"만 사용한다.
    //    (pairs = prod_date별 Tool#Cavity 집계)
    $byDate = $shipByDate;
    if (empty($byDate)) {
        // fallback: 출하내역 기반 집계가 비었으면 이 파트는 OQC 생성 스킵
        logline("  - 스킵: 생산일별 Tool#Cavity 집계가 없음(출하내역에 prod_date/tool/cavity가 없거나 비어있음): $part");
        continue;
    }

    $dateList = array_keys($byDate);
    sort($dateList);

    // ✅ 핵심(사용자 수동 방식 반영):
    //    납품처 > 품명 > prod_date(생산일자) 순으로 진행하면서,
    //    OQC header의 source_file(파일명)에 들어있는 날짜(YYMMDD)를 기준으로
    //    "source_file 날짜 <= prod_date"인 파일을 누적해서 스캔한다.
    //    ※ 출하내역에 없는 날짜(그날 출하가 없어도 OQC 측정 파일이 있으면)도 누적 대상에 포함해야 한다.
    //    ※ lot_date/ship_date 등 수기 입력 날짜는 절대 사용하지 않는다.
    $tHeader = (string)($meta['t_header'] ?? '');
    $hcols   = $meta['h'] ?? [];
    $colPart = $hcols['part'] ?? ($hcols['part_name'] ?? 'part_name');
    $colSrc  = $hcols['source_file'] ?? null;

    $srcFilesByYmd = []; // ymd => [sf=>true]
    $undatedSfs    = []; // [sf=>true]

    $minProdDate = (string)($dateList[0] ?? '');
    $maxProdDate = (string)($dateList[count($dateList)-1] ?? '');

    // PASS0/1/2는 출하내역(prod_date) 기반 누적 스캔만 사용 (별도 "최근/개월수" 제한 없음)
    $bufferStart = '';

    if ($tHeader && $colPart && $colSrc) {
        $sqlSf = "SELECT DISTINCT h.`{$colSrc}` AS sf FROM `{$tHeader}` h WHERE h.`{$colPart}` = :part";
        $stmtSf = $pdo->prepare($sqlSf);
        $stmtSf->execute([':part' => $part]);
        $rowsSf = $stmtSf->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rowsSf as $r) {
            $sf = trim((string)($r['sf'] ?? ''));
            if ($sf === '') continue;

            $ymd = oqc_extract_ymd_from_source_file($sf);
            if ($ymd) {
                // 미래 파일 제외
                if ($maxProdDate !== '' && $ymd > $maxProdDate) continue;
                // 너무 과거는 PASS3에서 처리
                if ($bufferStart !== '' && $ymd < $bufferStart) continue;

                if (!isset($srcFilesByYmd[$ymd])) $srcFilesByYmd[$ymd] = [];
                $srcFilesByYmd[$ymd][$sf] = true;
            } else {
                // 날짜 추출 실패 파일은 최후순위
                $undatedSfs[$sf] = true;
            }
        }
    }

    // ymd별 source_file 정렬
    $srcYmdList = array_keys($srcFilesByYmd);
    sort($srcYmdList, SORT_STRING);
    $srcYmdToSfs = [];
    foreach ($srcYmdList as $ymd) {
        $sfs = array_keys($srcFilesByYmd[$ymd] ?? []);
        sort($sfs, SORT_STRING);
        $srcYmdToSfs[$ymd] = $sfs;
    }

    // 날짜 없는 source_file은 맨 뒤(최후순위)
    $undatedList = array_keys($undatedSfs);
    sort($undatedList, SORT_STRING);

    // prod_date별 누적 source_file 리스트(가까운 날짜 우선) 구성
    $cumSrcFilesByDate = [];
    $cum = [];
    $seenSf = [];
    $iY = 0;
    $nY = count($srcYmdList);

    foreach ($dateList as $d) {
        while ($iY < $nY && $srcYmdList[$iY] <= $d) {
            $ymd = $srcYmdList[$iY];
            $add = [];
            foreach (($srcYmdToSfs[$ymd] ?? []) as $sf) {
                if ($sf === '' || isset($seenSf[$sf])) continue;
                $seenSf[$sf] = true;
                $add[] = $sf;
            }
            if ($add) {
                // ✅ 파일 날짜 정렬(가까운 날짜를 우선 사용)
                $cum = array_merge($add, $cum);
            }
            $iY++;
        }

        $list = $cum;
        foreach ($undatedList as $sf) {
            if ($sf === '') continue;
            $list[] = $sf;
        }
        $cumSrcFilesByDate[$d] = $list;
    }
$getPick = function(string $prodDate, string $tcNorm) use ($pdo, $meta, $part, &$usedHeaderIds, $tmplPointSet, $cumSrcFilesByDate): ?array {
    $exclude = array_keys($usedHeaderIds);

    // ✅ 핵심: "출하(prod_date) 순서"대로 source_file을 누적 스캔(미래 파일은 사용하지 않음)
    //  - source_file(파일명)에서 날짜를 추출/매칭해서 prod_date별로 누적 리스트를 만들고,
    //  - 그 누적 리스트 안에서 FIELD(source_file, ...) 순서대로 탐색한다.
    //  - lot_date/ship_date 등 수기 입력 날짜는 절대 사용하지 않는다.
    $sfs = $cumSrcFilesByDate[$prodDate] ?? [];
    if (!$sfs) return null;

    return oqc_pick_best_header_by_source_files($pdo, $meta, $part, $sfs, $prodDate, $tcNorm, ['SPC'], $exclude, $tmplPointSet, ['FAI']);
};

$getPickKind = function(string $prodDate, string $tcNorm, string $kindWanted) use ($pdo, $meta, $part, &$usedHeaderIds, $tmplPointSet, $cumSrcFilesByDate): ?array {
    $exclude = array_keys($usedHeaderIds);

    $sfs = $cumSrcFilesByDate[$prodDate] ?? [];
    if (!$sfs) return null;

    return oqc_pick_best_header_kind_by_source_files($pdo, $meta, $part, $sfs, $prodDate, $tcNorm, $kindWanted, $exclude, $tmplPointSet);
};


    // 기간 내 출하분에서 "툴 전체"를 우선 확보 (캐비티는 부분적으로만)
    $toolDateCavs = []; // tool => date => cavSet
    foreach ($dateList as $prodDate) {
        $pairs = array_keys($byDate[$prodDate]['pairs'] ?? []);
        foreach ($pairs as $tcRaw) {
            $tcNorm = normalize_tool_cavity_key($tcRaw);
            if (!preg_match('/^([A-Za-z]+)#(\d+)$/', $tcNorm, $m)) continue;
            $tool = strtoupper($m[1]);
            $cav  = (int)$m[2];
            if (!isset($toolDateCavs[$tool])) $toolDateCavs[$tool] = [];
            if (!isset($toolDateCavs[$tool][$prodDate])) $toolDateCavs[$tool][$prodDate] = [];
            $toolDateCavs[$tool][$prodDate][$cav] = true;
        }
    }

    $toolsAll = array_keys($toolDateCavs);
    usort($toolsAll, function($a, $b) {
        $la = strlen($a); $lb = strlen($b);
        if ($la !== $lb) return $la <=> $lb;
        return strcmp($a, $b);
    });

    
    // (고정) OQC 열 수 (AK~BP = 32칸)
    $maxCols  = 32;

    // pass 카운터 초기화(Notice 방지)
    $pass0Cnt = 0;
    $pass1Cnt = 0;
    $pass2Cnt = 0;
    $pass3Cnt = 0;

    $toolCols = $maxCols;

    // (초기화) Tool#Cavity / source tag / header_id / kind 배열
    $toolPairs  = array_fill(0, $maxCols, '');
    $sourceTags = array_fill(0, $maxCols, '');
    $headerIds  = array_fill(0, $maxCols, 0);
    $kinds      = array_fill(0, $maxCols, '');

// FAI 예약석(기본 3칸: AK/AL/AM). 툴이 너무 많으면 예약석 축소
    $reservedFaiCols = 3;
    if (count($toolsAll) > ($maxCols - $reservedFaiCols)) {
        $reservedFaiCols = max(0, $maxCols - count($toolsAll));
        logline("  - [경고] 툴 수(" . count($toolsAll) . ")가 많아 FAI 예약석을 {$reservedFaiCols}칸으로 축소");
    }
$usedHeaderIds = []; // 이번 실행에서 이미 선택한 OQC header id (중복 선택 방지)
    $colIdx = $reservedFaiCols;

    // PASS-1) "툴"이 최소 1번씩은 나오게 채움 (DB 있는 cavity 우선)
    foreach ($toolsAll as $tool) {
        if ($colIdx >= $maxCols) break;

        $datesForTool = array_keys($toolDateCavs[$tool] ?? []);
        sort($datesForTool);

        $chosen = null;
        $fallback = null;

        foreach ($datesForTool as $prodDate) {
            $cavs = array_keys($toolDateCavs[$tool][$prodDate] ?? []);
            sort($cavs, SORT_NUMERIC);
            if (!$cavs) continue;

            if ($fallback === null) {
                $fallback = [$prodDate, $tool . '#' . $cavs[0]];
            }

            // 같은 툴이라도 cavity 중 DB 있는것을 먼저 채택
            foreach ($cavs as $cv) {
                $tcNorm = $tool . '#' . (int)$cv;
                $pick = $getPick($prodDate, $tcNorm);
                if ($pick) {
                    $chosen = [$prodDate, $tcNorm, $pick];
                    break 2;
                }
            }
        }

        if ($chosen === null && $fallback !== null) {
            $chosen = [$fallback[0], $fallback[1], false];
        }
        if ($chosen === null) continue;

        [$prodDate, $tcNorm, $pick] = $chosen;
            // v51: Tool#Cavity(tc) 중복은 허용. header_id(측정 세션) 중복만 방지한다.
        $toolPairs[$colIdx] = $tcNorm;
        if ($pick) {
            $headerIds[$colIdx]  = (int)$pick['id'];
            $pass1Cnt++;
            $usedHeaderIds[(int)$pick['id']] = true;
                    $kinds[$colIdx] = strtoupper(trim((string)($pick['kind'] ?? '')));
            $sourceTags[$colIdx] = (string)$pick['src_tag'];
        } else {
            // DB 없으면: 툴은 남기되 데이터는 비고, 상단 날짜는 prod_date(YYMMDD)로 표시
            $headerIds[$colIdx]  = 0;
            $sourceTags[$colIdx] = oqc_extract_src_tag('', $prodDate);
            logline("   [경고] OQC DB 없음: {$part} {$prodDate} {$tcNorm}");
        }

        $colIdx++;
    }

    


    // [PASS2] Warning 방지: 클로저 use($tmplPointFullMap)용 (없으면 null)
    $tmplPointFullMap = $tmplPointSet; // PASS2/NG 판단용: spec map(USL/LSL) 기반
// ✅ PASS2 PICK: 생산일(prodDate) "당일 source_file"만 사용 + kind=SPC + meas_date IS NULL(미사용)만 사용
//  - PASS1은 누적 source_file 기반(getPick)으로 "최소 1회"를 먼저 보장
//  - PASS2는 "출하된 Tool#Cavity"를 생산일 순으로 돌면서, 미사용(SPC) 헤더만 채움
$getPickP2 = function(string $prodDate, string $tcNorm) use ($pdo, $meta, $part, &$usedHeaderIds, $tmplPointFullMap, $cumSrcFilesByDate) {
    // PASS2는 "출하(prod_date) 순서"로 누적된 source_file 목록에서 미사용(meas_date IS NULL) SPC만 픽한다.
    // (별도 날짜 윈도우/최근/개월수 제한 없음. 미래 파일은 누적 리스트에서 제외됨)
    $sourceFiles = $cumSrcFilesByDate[$prodDate] ?? [];
    if (empty($sourceFiles)) return null;

    // kind=SPC 고정, disallowKinds=['FAI']
    return oqc_pick_best_header_kind_by_source_files_unused(
        $pdo,
        $meta,
        $part,
        $sourceFiles,
        $prodDate,
        $tcNorm,
        'SPC',
        array_keys($usedHeaderIds),
        $tmplPointFullMap,
        ['FAI']
    );
};


    // [DEBUG] PASS2가 순회하는 prod_date 전체 리스트/후보 source_file 개수 출력
    //  - prodDates(all) 가 누락되면 byDate/dateList 생성 로직부터 의심
    //  - 주의: 이 블록은 $DEBUG(= ?debug=1) 기반으로만 동작해야 함
    if ($DEBUG) {
        // 품명(=part) 기준으로 생산일자 가로 나열 + 날짜별 후보 요약
        $dateCsv = !empty($dateList) ? implode(',', $dateList) : '';
        logline("[DEBUG] {$part} 생산일자 " . ($dateCsv !== '' ? $dateCsv : '(none)'));

        foreach ($dateList as $pd) {
            $sfCnt   = isset($cumSrcFilesByDate[$pd]) ? count($cumSrcFilesByDate[$pd]) : 0;
            $pairCnt = isset($byDate[$pd]['pairs']) ? count($byDate[$pd]['pairs']) : 0;
            logline("  [DEBUG] prodDate={$pd} pairs={$pairCnt} sourceFiles={$sfCnt}");
        }
    }

// PASS-2) 남은 칸은 (생산일 → 툴 → 캐비티) 라운드로빈으로, DB 있는 것만 추가
    for ($round = 0; $round < 6; $round++) {
        $didFill = false;
foreach ($dateList as $prodDate) {
        if ($colIdx >= $maxCols) break;

        $toolCavs = []; // tool => cavSet
        $pairs = array_keys($byDate[$prodDate]['pairs'] ?? []);
        foreach ($pairs as $tcRaw) {
            $tcNorm = normalize_tool_cavity_key($tcRaw);
            if (!preg_match('/^([A-Za-z]+)#(\d+)$/', $tcNorm, $m)) continue;
            $tool = strtoupper($m[1]);
            $cav  = (int)$m[2];
            if (!isset($toolCavs[$tool])) $toolCavs[$tool] = [];
            $toolCavs[$tool][$cav] = true;
        }

        $tools = array_keys($toolCavs);
        usort($tools, function($a, $b) {
            $la = strlen($a); $lb = strlen($b);
            if ($la !== $lb) return $la <=> $lb;
            return strcmp($a, $b);
        });

        // 각 툴의 cavity를 정렬 리스트로 만들고 depth 라운드로빈
        $toolCavLists = [];
        $maxDepth = 0;
        foreach ($tools as $tool) {
            $cavs = array_keys($toolCavs[$tool]);
            sort($cavs, SORT_NUMERIC);
            $toolCavLists[$tool] = $cavs;
            if (count($cavs) > $maxDepth) $maxDepth = count($cavs);
        }

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            foreach ($tools as $tool) {
                if ($colIdx >= $maxCols) break 3;
                if (!isset($toolCavLists[$tool][$depth])) continue;

                $tcNorm = $tool . '#' . (int)$toolCavLists[$tool][$depth];
            // v7.16: 중복 Tool#Cavity 허용 (PASS2/3 보강)
                $pick = $getPickP2($prodDate, $tcNorm);
                if (!$pick) continue; // 추가칸에서는 DB 없는건 스킵(빈열 최소화)

                $toolPairs[$colIdx]  = $tcNorm;
                $headerIds[$colIdx]  = (int)$pick['id'];
                $pass2Cnt++;
            $usedHeaderIds[(int)$pick['id']] = true;
                $kinds[$colIdx] = strtoupper(trim((string)($pick['kind'] ?? '')));
                $sourceTags[$colIdx] = (string)$pick['src_tag'];
                $colIdx++;
            }
        }
    }


        

    // PASS-0) FAI 예약석(AK/AL/AM)을 먼저 채움 (최대 $reservedFaiCols)
    if ($reservedFaiCols > 0) {
        $faiCands = []; // hid => cand

        foreach ($dateList as $prodDate) {
            $pairs = array_keys($byDate[$prodDate]['pairs'] ?? []);
            foreach ($pairs as $tcRaw) {
                $tcNorm = normalize_tool_cavity_key($tcRaw);
                if (!preg_match('/^([A-Za-z]+)#(\d+)$/', $tcNorm)) continue;

                $pickF = $getPickKind($prodDate, $tcNorm, 'FAI');
                if (!$pickF) continue;

                $hid = (int)($pickF['id'] ?? 0);
                if ($hid <= 0) continue;

                if (!isset($faiCands[$hid])) {
                    $faiCands[$hid] = [
                        'id' => $hid,
                        'src_tag' => (string)($pickF['src_tag'] ?? ''),
                        'tcNorm' => $tcNorm,
                        'prodDate' => $prodDate,
                    ];
                }
            }
        }

        $candList = array_values($faiCands);
        usort($candList, function($a, $b) {
            // src_tag(YYYY-MM-DD) desc -> prodDate desc -> id desc
            $sa = (string)($a['src_tag'] ?? '');
            $sb = (string)($b['src_tag'] ?? '');
            if ($sa !== $sb) return strcmp($sb, $sa);
            $da = (string)($a['prodDate'] ?? '');
            $db = (string)($b['prodDate'] ?? '');
            if ($da !== $db) return strcmp($db, $da);
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });

        

        // (PASS0) NG 헤더 사전 제외 (템플릿 point 기준)
        $pass0BadSet = [];
        try {
            $candIds0 = [];
            foreach ($candList as $c0) { $candIds0[] = (int)$c0['id']; }
            if (!empty($candIds0) && function_exists('oqc_build_bad_headers_by_template_ng')) {
                $badMap0 = oqc_build_bad_headers_by_template_ng($pdo, $candIds0, $tmplPointSet, $debug);
                foreach ($badMap0 as $hid0 => $info0) $pass0BadSet[(int)$hid0] = true;
                if ($debug >= 1) logline("  [DEBUG] PASS0 NG-skip: bad=" . count($pass0BadSet) . " / cand=" . count($candIds0));
            }
        } catch (Throwable $e) {
            // ignore
        }
// 이미 선택된 헤더 id
        $usedHids = [];
        for ($i = $reservedFaiCols; $i < $maxCols; $i++) {
            $hid = (int)($headerIds[$i] ?? 0);
            if ($hid > 0) $usedHids[$hid] = true;
        }

        $slot = 0;
        // 1차: 중복 없는 FAI부터
        foreach ($candList as $c) {
            if ($slot >= $reservedFaiCols) break;
            $hid = (int)$c['id'];
            if (isset($pass0BadSet[$hid])) continue;
            if (isset($usedHids[$hid])) continue;

            $toolPairs[$slot]  = (string)$c['tcNorm'];
            $headerIds[$slot]  = $hid;
            $kinds[$slot]      = 'FAI';
            $sourceTags[$slot] = (string)$c['src_tag'];
            $slot++;
        }
        // 2차: 그래도 부족하면(정말 FAI가 적으면) 중복 허용
        if ($slot < $reservedFaiCols) {
            foreach ($candList as $c) {
                if ($slot >= $reservedFaiCols) break;
                $hid = (int)$c['id'];
                if (isset($pass0BadSet[$hid])) continue;
                // 이미 예약석에 들어간 hid면 스킵
                $already = false;
                for ($j = 0; $j < $slot; $j++) {
                    if ((int)($headerIds[$j] ?? 0) === $hid) { $already = true; break; }
                }
                if ($already) continue;

                $toolPairs[$slot]  = (string)$c['tcNorm'];
                $headerIds[$slot]  = $hid;
                $kinds[$slot]      = 'FAI';
                $sourceTags[$slot] = (string)$c['src_tag'];
                $slot++;
            }
        }


        $pass0Cnt = $slot;

        // PASS3: 그래도 FAI 예약석이 비어있으면 파트 전체 FAI 풀에서 추가로 채움
        //        (없으면 SPC 등을 FAI로 강제 대체해서라도 'KIND=FAI' 3칸을 채움)
        if ($slot < $reservedFaiCols) {
            $needBefore = $reservedFaiCols - $slot;

            // 이미 선택된 header id 중복 방지
            // v7.20: FAI 예약석(PASS3)에서는 "예약석 내부" 중복만 막고,
            //        기존 32칸 전체(headerIds) 중복까지 막지는 않는다.
            //        (기존 칸에서 이미 사용된 FAI header를 재사용해야 할 때가 있음)
            $excludeH = [];
            for ($j = 0; $j < $slot; $j++) {
                $hid0 = (int)($headerIds[$j] ?? 0);
                if ($hid0 > 0) $excludeH[$hid0] = true;
            }

            // 3-A) kind=FAI 풀에서 채움
            $more = oqc_pick_any_headers_for_part($pdo, $meta, $part, 'FAI', 200, array_keys($excludeH), true, (string)($dateList[0] ?? ($toDate ?? ($fromDate ?? ''))), $tmplPointSet);
            foreach ($more as $r) {
                if ($slot >= $reservedFaiCols) break;
                $hid = (int)($r['_hid'] ?? 0);
                if ($hid <= 0 || isset($excludeH[$hid])) continue;
                $tc = (string)($r['_tc'] ?? '');
                if ($tc === '') continue;
                if (!empty($allowedTcSet) && !isset($allowedTcSet[$tc])) continue; // ✅ 출하내역 tc만 허용

                $toolPairs[$slot]  = $tc;
                $sourceTags[$slot] = (string)($r['_tag'] ?? '');
                $headerIds[$slot]  = $hid;
                $kinds[$slot]      = 'FAI';

                $excludeH[$hid] = true;
                $slot++;
            }

            // 3-B) 그래도 부족하면: 아무 kind에서 가져와 FAI로 강제 대체(출력 템플릿용)
            //      단, 가능하면 SPC는 먼저 제외(1차)하고 그래도 부족할 때만 마지막에 허용(2차)
            if ($slot < $reservedFaiCols) {
                $more = oqc_pick_any_headers_for_part($pdo, $meta, $part, null, 200, array_keys($excludeH), true, (string)($dateList[0] ?? ($toDate ?? ($fromDate ?? ''))), $tmplPointSet);

                // 1차: SPC 제외
                foreach ($more as $r) {
                    if ($slot >= $reservedFaiCols) break;
                    $hid = (int)($r['_hid'] ?? 0);
                    if ($hid <= 0 || isset($excludeH[$hid])) continue;

                    $kindRaw = strtoupper(trim((string)($r['_kind'] ?? '')));
                    if ($kindRaw === 'SPC') continue;

                    $tc = (string)($r['_tc'] ?? '');
                    if ($tc === '') continue;
                    if (!empty($allowedTcSet) && !isset($allowedTcSet[$tc])) continue; // ✅ 출하내역 tc만 허용

                    $toolPairs[$slot]  = $tc;
                    $sourceTags[$slot] = (string)($r['_tag'] ?? '');
                    $headerIds[$slot]  = $hid;
                    $kinds[$slot]      = 'FAI'; // ✅ 출력 템플릿용: kind는 항상 FAI로

                    $excludeH[$hid] = true;
                    $slot++;
                }
                // 2차: 그래도 부족하면 (SPC 사용하지 않음)
                //      → 이미 확보된 non-SPC(가능하면 FAI)를 "중복" 사용해서 3칸을 완성한다.
                if ($slot < $reservedFaiCols) {
                    // (A) 예약석에 이미 1개라도 들어갔으면, 그 1개를 복제해서 빈칸 채움
                    if ($slot > 0) {
                        $tc0  = (string)($toolPairs[0]  ?? '');
                        $tag0 = (string)($sourceTags[0] ?? '');
                        $hid0 = (int)($headerIds[0]    ?? 0);

                        if ($tc0 !== '' && $hid0 > 0) {
                            while ($slot < $reservedFaiCols) {
                                $toolPairs[$slot]  = $tc0;
                                $sourceTags[$slot] = $tag0;
                                $headerIds[$slot]  = $hid0;   // 동일 header 재사용(중복 허용)
                                $kinds[$slot]      = 'FAI';   // ✅ 출력 템플릿용: kind는 항상 FAI로
                                $slot++;
                            }
                        }
                    }

                    // (B) 예약석이 0개였던 경우: 이미 선택된 32칸 중 non-SPC(가능하면 FAI)를 찾아 복제
                    if ($slot < $reservedFaiCols) {
                        $pickIdx = -1;

                        // 1) FAI 우선
                        for ($ii = 0; $ii < count($headerIds); $ii++) {
                            $kk = strtoupper(trim((string)($kinds[$ii] ?? '')));
                            if ($kk !== 'FAI') continue;
                            $hidX = (int)($headerIds[$ii] ?? 0);
                            $tcX  = (string)($toolPairs[$ii] ?? '');
                            if ($hidX > 0 && $tcX !== '') { $pickIdx = $ii; break; }
                        }

                        // 2) 그래도 없으면 non-SPC 아무거나
                        if ($pickIdx < 0) {
                            for ($ii = 0; $ii < count($headerIds); $ii++) {
                                $kk = strtoupper(trim((string)($kinds[$ii] ?? '')));
                                if ($kk === 'SPC') continue;
                                $hidX = (int)($headerIds[$ii] ?? 0);
                                $tcX  = (string)($toolPairs[$ii] ?? '');
                                if ($hidX > 0 && $tcX !== '') { $pickIdx = $ii; break; }
                            }
                        }

                        if ($pickIdx >= 0) {
                            $tcX  = (string)($toolPairs[$pickIdx]  ?? '');
                            $tagX = (string)($sourceTags[$pickIdx] ?? '');
                            $hidX = (int)($headerIds[$pickIdx]    ?? 0);

                            if ($tcX !== '' && $hidX > 0) {
                                while ($slot < $reservedFaiCols) {
                                    $toolPairs[$slot]  = $tcX;
                                    $sourceTags[$slot] = $tagX;
                                    $headerIds[$slot]  = $hidX; // 동일 header 재사용(중복 허용)
                                    $kinds[$slot]      = 'FAI';
                                    $slot++;
                                }
                            }
                        }
                    }
                }
}

            $needAfter = $reservedFaiCols - $slot;
            if ($needAfter < $needBefore) {
                logline("  - [FAI 보강] {$part}: 예약석 {$needBefore}/{$reservedFaiCols} 비어있음 → 추가 채움(PASS3), 남은 빈칸 {$needAfter}/{$reservedFaiCols}");
            } else {
                logline("  - [FAI 보강] {$part}: 예약석 {$needBefore}/{$reservedFaiCols} 비어있지만 추가 후보 없음(PASS3)");
            }
        }

        $pass3ResCnt = max(0, $slot - $pass0Cnt);

//        if ($slot > 0) {
//            logline("  - [FAI 예약] {$part}: AK/AL/AM 슬롯 {$slot}/{$reservedFaiCols} 채움");
//        }
    }

// AK/AL/AM(앞 3칸)은 FAI 전용 예약석: SPC(또는 kind 미상) 절대 금지
        [$toolPairs, $sourceTags, $headerIds, $kinds, $droppedCols] = oqc_reorder_cols_fai_reserved($toolPairs, $sourceTags, $headerIds, $kinds, $reservedFaiCols);

        
        // ✅ FAI 예약석(AK/AL/AM)은 "SPC/미지정"이 들어오면 안 됨
        //    - kind=FAI 는 그대로 유지
        //    - kind=SPC 또는 kind 비어있음(미상) 은 비우고 PASS3로 다시 채움
        for ($i = 0; $i < $reservedFaiCols; $i++) {
            $hid = (int)($headerIds[$i] ?? 0);
            $kk  = strtoupper(trim((string)($kinds[$i] ?? '')));

            // FAI or other non-SPC kinds are acceptable here; SPC/unknown are not.
            if ($hid > 0 && $kk !== '' && $kk !== 'SPC') {
                continue;
            }

            // clear this reserved slot
            $toolPairs[$i]  = '';
            $sourceTags[$i] = '';
            $headerIds[$i]  = 0;
            $kinds[$i]      = '';
        }

// ─────────────────────────────────────────────
        
        // ─────────────────────────────────────────────
        // (v7.23) FAI 예약석(앞쪽: AK/AL/AM 등)에 이미 들어간 Tool#Cavity(tc)는
        //        뒤쪽(AN~BP)에서 다시 쓰지 않는다.
        //  - 목적: 같은 tc가 FAI/비FAI 슬롯에 중복 배치되는 슬롯 낭비 방지
        //  - 주의: 여기서 막는 것은 "같은 tc" 중복만이며, 같은 Tool의 다른 cavity는 허용
        // ─────────────────────────────────────────────
        $reservedTCs = [];
        for ($i = 0; $i < $reservedFaiCols; $i++) {
            $tc = (string)($toolPairs[$i] ?? '');
            if ($tc === '') continue;
            $reservedTCs[$tc] = true;
        }

        // v51: tc 중복 제거 로직 비활성화(헤더가 다르면 tc 중복도 허용)
        if (false && !empty($reservedTCs) && $reservedFaiCols < 32) {
            // 1) 뒤쪽에서 reserved tc 제거
            for ($i = $reservedFaiCols; $i < 32; $i++) {
                $tc = (string)($toolPairs[$i] ?? '');
                if ($tc === '') continue;
                if (isset($reservedTCs[$tc])) {
                    $toolPairs[$i]  = '';
                    $sourceTags[$i] = '';
                    $headerIds[$i]  = 0;
                    $kinds[$i]      = '';
                }
            }

            // 2) 예약석 이후 구간만 압축(빈 칸 앞으로)
            $write = $reservedFaiCols;
            for ($i = $reservedFaiCols; $i < 32; $i++) {
                if ((string)($toolPairs[$i] ?? '') === '') continue;
                if ($write !== $i) {
                    $toolPairs[$write]  = $toolPairs[$i];
                    $sourceTags[$write] = $sourceTags[$i];
                    $headerIds[$write]  = $headerIds[$i];
                    $kinds[$write]      = $kinds[$i];

                    $toolPairs[$i]  = '';
                    $sourceTags[$i] = '';
                    $headerIds[$i]  = 0;
                    $kinds[$i]      = '';
                }
                $write++;
            }

            // 3) 빈 칸 재채움: reserved tc는 제외 + (선택) 중복 tc는 허용(v7.16 유지)
            $nextEmpty = function(int $start) use (&$toolPairs): int {
                for ($i = $start; $i < 32; $i++) {
                    if ((string)($toolPairs[$i] ?? '') === '') return $i;
                }
                return 32;
            };

            $fillPos = $nextEmpty($reservedFaiCols);

            if ($fillPos < 32) {
                foreach ($dateList as $prodDate) {
                    if ($fillPos >= 32) break;

                    $pairs = array_keys($byDate[$prodDate]['pairs'] ?? []);
                    foreach ($pairs as $tcRaw) {
                        if ($fillPos >= 32) break;

                        $tcNorm = normalize_tool_cavity_key($tcRaw);
                        if (!$tcNorm) continue;
                        if (isset($reservedTCs[$tcNorm])) continue;

                        // SPC 우선, 없으면 ANY(단, FAI는 제외) fallback
                        $pick = $getPickKind($prodDate, $tcNorm, 'SPC');
                        if (!$pick) $pick = $getPickP2($prodDate, $tcNorm);
                        if (!$pick) continue;

                        $toolPairs[$fillPos]  = $tcNorm;
                        $headerIds[$fillPos]  = (int)($pick['header_id'] ?? 0);
                        if ((int)($headerIds[$fillPos] ?? 0) > 0) $pass2Cnt++;
                        $sourceTags[$fillPos] = (string)($pick['src_tag'] ?? oqc_extract_src_tag((string)($pick['source_file'] ?? ''), $prodDate));
                        $kinds[$fillPos]      = (string)($pick['kind'] ?? '');

                        $fillPos = $nextEmpty($fillPos + 1);
                        if ($fillPos >= 32) break;
                    }
                }
            }
        }

        if (!empty($droppedCols)) {
            logline("  - 경고: FAI 예약석 적용으로 컬럼 {$droppedCols}개가 32칸을 초과하여 뒤에서 생략됨");
        }

// 위치 고정(요청): AK6~BP6 = 출처(YY-MM-DD) / AK10~BP10 = Tool#Cavity / AK11~ = Data
    $sourceRow = 6;
    $toolRow = 10;
    $dataStartRow = 11;

    // 템플릿 used range(endRow) 를 ZIP에서 읽기
    $tplPath = $tpl;
    $endRow = 2000;
    $zipTmp = new ZipArchive();
    if ($zipTmp->open($tplPath) === true) {
        $sheetPath = xlsx_get_sheet_path_by_name($zipTmp, 'OQC');
        if ($sheetPath) {
            $xml = $zipTmp->getFromName($sheetPath);
            if ($xml !== false) {
                $er = xlsx_get_dimension_end_row($xml);
                if ($er > 0) $endRow = min(20000, max($dataStartRow, $er));
            }
        }
        $zipTmp->close();
    }

        // DB(oqc_model_point) + TEMPLATE(B열) -> row 매핑 (point_no 매칭용)
    //  - DB row_index 는 누락된 포인트가 있을 수 있으므로, 템플릿 B열을 소스 오브 트루스로 사용
    //  - '-' / '_' 차이는 point_no 자체를 바꾸지 말고, 키 매칭에서만 허용
    $dbMaps = ['full' => [], 'base' => []];
    if (function_exists('db_build_fai_row_maps')) {
        $dbMaps = db_build_fai_row_maps($pdo, $part, $dataStartRow, 'oqc_model_point', true);
        if (!is_array($dbMaps)) $dbMaps = ['full' => [], 'base' => []];
    }

    $tplMaps = ['full' => [], 'base' => []];
    if (function_exists('xlsx_build_fai_row_maps') && is_file($tplPath)) {
        $tplMaps = xlsx_build_fai_row_maps($tplPath, 'OQC', 'B');
        if (!is_array($tplMaps)) $tplMaps = ['full' => [], 'base' => []];
    }

    // 템플릿 맵: 키 변형(55-1 vs 55_1 등)까지 포함해 확장
    $tplFullAug = [];
    if (!empty($tplMaps['full']) && is_array($tplMaps['full'])) {
        foreach ($tplMaps['full'] as $k0 => $r0) {
            $k0n = oqc_norm_key((string)$k0);
            foreach (oqc_key_variants($k0n) as $vk) {
                if (!isset($tplFullAug[$vk])) $tplFullAug[$vk] = (int)$r0;
            }
        }
    }
    $tplBaseAug = [];
    if (!empty($tplMaps['base']) && is_array($tplMaps['base'])) {
        foreach ($tplMaps['base'] as $k0 => $r0) {
            $k0n = oqc_norm_key((string)$k0);
            foreach (oqc_base_key_variants($k0n) as $vk) {
                if (!isset($tplBaseAug[$vk])) $tplBaseAug[$vk] = (int)$r0;
            }
        }
    }
    // base 맵이 비어있으면(full로부터 파생)
    if (empty($tplBaseAug) && !empty($tplMaps['full']) && is_array($tplMaps['full'])) {
        foreach ($tplMaps['full'] as $k0 => $r0) {
            $k0n = oqc_norm_key((string)$k0);
            foreach (oqc_base_key_variants($k0n) as $vk) {
                if (!isset($tplBaseAug[$vk])) $tplBaseAug[$vk] = (int)$r0;
            }
        }
    }

    // merge: DB 우선 + 템플릿으로 누락 보강 (ex. Z-STOPPER 55-1)
    $faiMaps = $dbMaps;
    if (!isset($faiMaps['full']) || !is_array($faiMaps['full'])) $faiMaps['full'] = [];
    if (!isset($faiMaps['base']) || !is_array($faiMaps['base'])) $faiMaps['base'] = [];
    foreach ($tplFullAug as $k => $r) {
        if (!isset($faiMaps['full'][$k])) $faiMaps['full'][$k] = (int)$r;
    }
    foreach ($tplBaseAug as $k => $r) {
        if (!isset($faiMaps['base'][$k])) $faiMaps['base'][$k] = (int)$r;
    }

    logline("   - [FAI MAP] {$part}: full=" . count($faiMaps['full']) . ", base=" . count($faiMaps['base'])
        . " (DB=" . count($dbMaps['full'] ?? []) . "/" . count($dbMaps['base'] ?? [])
        . ", TPL=" . count($tplFullAug) . "/" . count($tplBaseAug) . ")");



    // ─────────────────────────────────────────────
    // v7.13 1단계 마킹: 이번 Export에서 선택된 헤더들은 meas_date = 오늘 로 사용 처리
    // ─────────────────────────────────────────────
    $reservedCnt = oqc_reserve_headers_meas_date_v712($pdo, $meta, $headerIds, $shippingDateStr, $part, 'PASS0-2');
    $REPORT_OQC_NEW_USED += $reservedCnt;
//    if ($reservedCnt > 0) {
//        // logline("  - {$part}: 신규 사용 meas_date 마킹 {$reservedCnt}건");
//    $totalOqcNew += (int)$reservedCnt;
//    }

    // ─────────────────────────────────────────────
    
        if (!$didFill) break;

        $hasEmpty = false;
        for ($i = 0; $i < $toolCols; $i++) {
            if (!$toolPairs[$i] || !$headerIds[$i]) { $hasEmpty = true; break; }
        }
        if (!$hasEmpty) break;
    }

    // ✅ DEBUG: PASS2 이후(긴급 보강 직전) 미채움 슬롯 진단
    if ($DEBUG) {
        $miss = [];
        for ($i = 0; $i < 32; $i++) {
            $tc = $toolPairs[$i] ?? '';
            if ($tc === '') continue;
            if (($headerIds[$i] ?? 0) > 0) continue;
            $miss[] = $tc;
        }
        if (!empty($miss)) {
            $uniq = array_values(array_unique($miss));
            logline("  [DEBUG] PASS2 이후 미채움 슬롯=" . count($miss) . " (unique tc=" . count($uniq) . ")");
            // 미채움 tc를 툴별 cavity 리스트로 요약 (예: F=1,2,3,4/Cav / J=2/Cav)
            $tcByTool = []; // tool => cavSet
            foreach ($uniq as $tcNormTmp) {
                $t = $tcNormTmp;
                $cv = null;
                if (strpos($tcNormTmp, '#') !== false) {
                    $pp = explode('#', $tcNormTmp, 2);
                    $t = trim($pp[0]);
                    $cv = (int)($pp[1] ?? 0);
                }
                if (!isset($tcByTool[$t])) $tcByTool[$t] = [];
                if ($cv !== null && $cv > 0) $tcByTool[$t][$cv] = true;
            }
            // tool 정렬(문자열)
            $toolKeys = array_keys($tcByTool);
            sort($toolKeys, SORT_STRING);
            $tcSummaryParts = [];
            foreach ($toolKeys as $t) {
                $cavs = array_keys($tcByTool[$t]);
                sort($cavs, SORT_NUMERIC);
                $cavText = !empty($cavs) ? implode(',', $cavs) : '';
                $tcSummaryParts[] = $t . '=' . ($cavText !== '' ? $cavText : '-') . '/Cav';
            }
            logline("  [DEBUG] PASS2 미채움 TC 요약: " . implode(' / ', $tcSummaryParts));

            $fromDays = (int)($GLOBALS['OQC_EMG_FROM_DAYS'] ?? 60);
            $toDays   = (int)($GLOBALS['OQC_EMG_TO_DAYS'] ?? 30);
            $prodDatesDbg = array_slice($dateList, 0, 12); // 너무 많아지면 SQL 파라미터 폭발하니 상위 12개만
            $n = 0;
            foreach ($uniq as $tcNormDbg) {
                if ($n++ >= 20) break;
                $diag = oqc_debug_tc_diag($pdo, $meta, $part, $tcNormDbg, $prodDatesDbg, $fromDays, $toDays, $shippingDateStr);
                if (!$diag) {
                    logline("    - {$tcNormDbg}: [diag 실패]");
                    continue;
                }
                $md = ($diag['meas_min'] ?? '') . "~" . ($diag['meas_max'] ?? '');
                $s0 = $diag['samples'][0] ?? null;
                $sText = '';
                if (is_array($s0)) {
                    $sid   = $s0['id'] ?? '';
                    $skind = $s0['kind'] ?? '';
                    $stc   = $s0['tc_raw'] ?? '';
                    $sdate = $s0['date_v'] ?? '';
                    $smeas = $s0['meas_v'] ?? '';
                    $smeas2= $s0['meas2_v'] ?? '';
                    $sText = " | sample(id={$sid}, kind={$skind}, tc={$stc}, date={$sdate}, meas={$smeas}, meas2={$smeas2})";
                }
                logline("    - {$tcNormDbg}: prod(any/SPC/FAI)={$diag['prod_any']}/{$diag['prod_spc']}/{$diag['prod_fai']} | "
                      . "emg_unused(60~30/90~30/120~30)={$diag['emg_u_60_30']}/{$diag['emg_u_90_30']}/{$diag['emg_u_120_30']} | "
                      . "emg_used(60~30)={$diag['emg_used_60_30']} | meas_date(min~max)={$md}{$sText}");
            }
            if (count($uniq) > 20) logline("    ... (생략 " . (count($uniq) - 20) . "개 tc)");
            logline("  [DEBUG] emg window days={$fromDays}~{$toDays} (meas_date BETWEEN refDate-{$fromDays} AND refDate-{$toDays}, meas_date2 IS NULL, refDate={$shippingDateStr})");
        }
    }


    // ✅ DEBUG: PASS3(긴급 보강) 트리거/미채움 상태 확인
    if ($DEBUG) {
        $missTc = [];
        $missEmpty = 0;
        for ($i = 0; $i < 32; $i++) {
            $tcDbg = trim((string)($toolPairs[$i] ?? ''));
            $hidDbg = (int)($headerIds[$i] ?? 0);
            if ($hidDbg > 0) continue;
            if ($tcDbg === '') {
                $missEmpty++;
            } else {
                $missTc[] = $tcDbg;
            }
        }
        $fromDaysDbg = (int)($GLOBALS['OQC_EMG_FROM_DAYS'] ?? 60);
        $toDaysDbg   = (int)($GLOBALS['OQC_EMG_TO_DAYS'] ?? 30);
        $uniqTc = array_values(array_unique($missTc));
        $missTotal = $missEmpty + count($missTc);
        $tmplNgMap = $tmplPointSet; // NG 판단: spec map(USL/USL) 기준으로 통일
        logline("  [DEBUG] PASS3 trigger check: missSlots={$missTotal} (empty={$missEmpty}) uniqTc=" . count($uniqTc)
            . " windowDays={$fromDaysDbg}~{$toDaysDbg} refDate={$shippingDateStr}");
        if (!empty($uniqTc)) {
            logline("  [DEBUG] PASS3 missing tc sample: " . implode(", ", array_slice($uniqTc, 0, 20)) . (count($uniqTc) > 20 ? " ..." : ""));
        }
    }
    // PASS3에서 사용: 템플릿 기반 NG 판단 맵(비디버그에서도 필요)
    $tmplNgMap = $tmplPointSet ?? [];


// v7.13 3단계(긴급 보강): 아직 헤더가 없는 컬럼(header_id=0)은
    // 30~60일 재사용(1회) 대상(meas_date2 IS NULL)을 찾아서 채운다.
    // ─────────────────────────────────────────────
    $emgUsed = 0;
    $emgMiss = [];
    $canEmergency = !empty($meta['h']['meas_date2']) && !empty($meta['h']['meas_date']);
    if ($DEBUG) { logline("  [DEBUG] PASS3 canEmergency=" . ($canEmergency ? "Y" : "N") . " (need meas_date+meas_date2 columns)"); }
    if (!$canEmergency) {
        logline("  - [경고] meas_date2 컬럼이 없어 v7.13 긴급 보강을 스킵합니다.");
    } else {
        // ✅ PASS3가 비어있는 슬롯(tc='')도 채울 수 있도록: 긴급 풀에서 TC 후보를 미리 수집
        $usedTcNorm = [];
        $emptySlots = 0;
        for ($i = 0; $i < 32; $i++) {
            $tc0 = trim((string)($toolPairs[$i] ?? ''));
            $hid0 = (int)($headerIds[$i] ?? 0);
            if ($tc0 !== '') {
                $n0 = normalize_tool_cavity_key($tc0);
                if ($n0 !== '') $usedTcNorm[$n0] = true;
            } else if ($hid0 <= 0) {
                $emptySlots++;
            }
        }

        $emgPoolAnyNoFai = [];
        $emgPoolFai = [];
        $poolIdxAny = 0;
        $poolIdxFai = 0;

        if ($emptySlots > 0 && function_exists('oqc_emg_list_tc_candidates_v48')) {
            // non-reserved: FAI는 제외하고(SPC 우선 포함) 전체 kind에서 후보 수집
            $emgPoolAnyNoFai = oqc_emg_list_tc_candidates_v48($pdo, $meta, $part, null, ['FAI'], $shippingDateStr, 1200);
            // reserved FAI: FAI만 후보 수집
            $emgPoolFai      = oqc_emg_list_tc_candidates_v48($pdo, $meta, $part, 'FAI', null, $shippingDateStr, 1200);
            if ($DEBUG) {
                logline("  [DEBUG] PASS3 tc pool: emptySlots={$emptySlots} poolAnyNoFai=" . count($emgPoolAnyNoFai) . " poolFai=" . count($emgPoolFai) . " usedTc=" . count($usedTcNorm));
            }
        }

        for ($i = 0; $i < 32; $i++) {
            $tc = trim((string)($toolPairs[$i] ?? ''));
            $hid = (int)($headerIds[$i] ?? 0);
            if ($hid > 0) continue;

            // tc가 비어있으면(=아예 슬롯이 비어있으면) 긴급 풀에서 새 Tool#Cavity를 뽑아 넣는다.
            if ($tc === '') {
                $pickedTc = '';
                if ($i < $reservedFaiCols) {
                    while ($poolIdxFai < count($emgPoolFai)) {
                        $cand = (string)$emgPoolFai[$poolIdxFai++];
                        $norm = normalize_tool_cavity_key($cand);
                        if ($norm === '' || isset($usedTcNorm[$norm])) continue;
                        $pickedTc = $cand;
                        $usedTcNorm[$norm] = true;
                        break;
                    }
                } else {
                    while ($poolIdxAny < count($emgPoolAnyNoFai)) {
                        $cand = (string)$emgPoolAnyNoFai[$poolIdxAny++];
                        $norm = normalize_tool_cavity_key($cand);
                        if ($norm === '' || isset($usedTcNorm[$norm])) continue;
                        $pickedTc = $cand;
                        $usedTcNorm[$norm] = true;
                        break;
                    }
                }

                if ($pickedTc !== '') {
                    $tc = $pickedTc;
                    $toolPairs[$i] = $tc;
                } else {
                    $emgMiss[] = '(EMPTY)';
                    continue;
                }
            }

            // 예약석은 FAI만, 그 외는 SPC 우선
            if ($i < $reservedFaiCols) {
                $pickE = oqc_pick_emergency_and_consume_v712($pdo, $meta, $part, $tc, 'FAI', $tmplNgMap, null, $shippingDateStr);
            } else {
                $pickE = oqc_pick_emergency_and_consume_v712($pdo, $meta, $part, $tc, 'SPC', $tmplNgMap, ['FAI'], $shippingDateStr);
                if (!$pickE) $pickE = oqc_pick_emergency_and_consume_v712($pdo, $meta, $part, $tc, null, $tmplNgMap, ['FAI'], $shippingDateStr);
            }

            if ($pickE) {
                $headerIds[$i]  = (int)($pickE['id'] ?? 0);
                $kinds[$i]      = strtoupper(trim((string)($pickE['kind'] ?? '')));
                $sourceTags[$i] = (string)($pickE['src_tag'] ?? '');
                $emgUsed++;
            } else {
                $emgMiss[] = $tc;
            }
        }

        $REPORT_OQC_EMG_USED += $emgUsed;

//        if ($emgUsed > 0) {
//            // logline("  - {$part}: 긴급 재사용(meas_date2) 소진 {$emgUsed}건");
//    $totalOqcEmg += (int)$emgUsed;
//        }
        if (!empty($emgMiss)) {
            logline("  - [경고] {$part}: 보강 실패 Tool#Cavity = " . implode(', ', array_slice($emgMiss, 0, 60)) . (count($emgMiss) > 60 ? ' ...' : ''));
        }
    }

    // ─────────────────────────────────────────────
    // (v7.26.x) 사용자용 PASS 요약 로그(품명별)
    // ─────────────────────────────────────────────
    $pass3EmgCnt = (int)$emgUsed;
    $pass3Cnt = (int)$pass3ResCnt + (int)$pass3EmgCnt;

    $filledCnt = 0;
    for ($i = 0; $i < 32; $i++) {
        if ((int)($headerIds[$i] ?? 0) > 0) $filledCnt++;
    }

    if ($oqcSummaryFirst) {
        logline("──────────────────────────────");
        $oqcSummaryFirst = false;
    }
    logline("[OQC] {$part}");
    if ((int)$pass0Cnt > 0) {
        logline("  - PASS0(FAI): {$pass0Cnt}건");
    }
    if ((int)$pass1Cnt > 0) {
        logline("  - PASS1: {$pass1Cnt}건");
    }
    logline("  - PASS2: {$pass2Cnt}건");
    logline("  - PASS3: {$pass3Cnt}건");
logline("  - 총 채움: {$filledCnt}/32");
    logline("──────────────────────────────");

// 각 컬럼(header_id)마다 oqc_measurements를 row_index 기준으로 가져와서 채움
    $dataByRow = []; // rowNum => [32 values]
    for ($i = 0; $i < 32; $i++) {
        $hid = (int)($headerIds[$i] ?? 0);
        if ($hid <= 0) continue;

        $colVals = fetch_measure_column_values_by_point($pdo, $meta, 'header', $hid, $faiMaps, $dataStartRow, $endRow);
        foreach ($colVals as $r => $v) {
            if (!isset($dataByRow[$r])) {
                $dataByRow[$r] = array_fill(0, 32, '');
            }
            $dataByRow[$r][$i] = $v;
        }
    }

    
    // ✅ ZS 특수 포인트(33,55-1,55-2,56-1,56-2) 빈값 보강 (로우데이터 단계)
    if ($part === 'MEM-Z-STOPPER' && function_exists('oqc_zs_backfill_specific_points')) {
        oqc_zs_backfill_specific_points($dataByRow, $faiMaps, $toolPairs);
    }

$outName = "OQC_{$part}.xlsx";
    $outPath = $oqcDir . DIRECTORY_SEPARATOR . $outName;

    // ✅ 템플릿 ZIP(XML) 패치로 값만 교체 → 서식/테두리/도형 보존
    try {
        $wInfo = patch_oqc_xlsx_preserve_template(
            $tplPath,
            $outPath,
            $sourceTags, // AK6~BP6
            $toolPairs,  // AK10~BP10
            $dataByRow,  // AK11~BP??
            'OQC',
            'AK',
            'BP',
            $sourceRow,
            $toolRow,
            $dataStartRow
        );

        $createdOqcFiles[] = $outPath;

        // ✅ PDF 변환/뷰어용: 한 시트를 한 페이지로 강제(Fit 1x1)
        xlsx_force_fit_one_page_zip($outPath);

        // (REPORT) OQC Rowdata → 성적서(FAI/SPC) 자동 채움
        $OQC_REPORT_POINT_MAP = $GLOBALS['OQC_REPORT_POINT_MAP'] ?? [];
        if (isset($createdReportByPart[$part]) && isset($OQC_REPORT_POINT_MAP[$part]['pairs'])) {
            try {
                $rInfo = patch_oqc_report_from_rowdata(
                    $createdReportByPart[$part],
                    $part,
                    $OQC_REPORT_POINT_MAP[$part]['pairs'],
                    $faiMaps,
                    $toolPairs,
                    $dataByRow,
                    $IS_JAWHA
                );
                if (($rInfo['ok'] ?? false) && ((int)($rInfo['mapped'] ?? 0) > 0)) {
                    logline("  - REPORT 채움: " . basename($createdReportByPart[$part]) . " (rows=" . ($rInfo['mapped'] ?? 0) . ")");
                } else {
                    logline("  - REPORT 채움 스킵/부분: " . basename($createdReportByPart[$part]) . " / " . ($rInfo['reason'] ?? 'unknown'));
                }
            } catch (Throwable $e2) {
                logline("  - REPORT 채움 실패: " . basename($createdReportByPart[$part]) . " / " . $e2->getMessage());
            }
        }

        $dataRows = (int)($wInfo['dataRows'] ?? count($dataByRow));
        logline("  - OQC 생성: $outName (toolCols=32, filled=32, dataRows={$dataRows})");

        // 자화용 CMM 템플릿도 같이 생성(IR BASE / Z CARRIER만)
        // - IR BASE: 시트 'OQC Raw data' / 헤더 E2 (데이터 E3)
        // - Z CARRIER: 시트 'OQC RawData' / 헤더 G2 (데이터는 템플릿 유지)
        // - Tool#Cavity는 OQC의 AK10 순서(toolPairs) 그대로 주입(템플릿의 기존 인덱스/텍스트는 무시)
        if ($IS_JAWHA) {
            $cmmOut = cmm_make_template_xlsx($pdo, $part, $toolPairs, $headerIds, $shippingDateStr, $cmmDir);
            if ($cmmOut) {
                $createdCmmFiles[] = $cmmOut;
                logline("  - CMM 템플릿 생성: " . basename($cmmOut));
            }
        }
    } catch (Throwable $e) {
        logline("  - OQC 생성 실패: $outName / " . $e->getMessage());
    }
}

// OQC 파일이 하나도 생성되지 않으면(템플릿 경로 문제 등) 원인 텍스트를 oqc 폴더에 남김
if (count($createdOqcFiles) === 0) {
    $diagPath = $oqcDir . DIRECTORY_SEPARATOR . '_NO_OQC_FILES.txt';
    $txt  = "OQC 파일이 생성되지 않았습니다.\r\n";
    $txt .= "아래는 템플릿 탐색 결과입니다.\r\n\r\n";
    $txt .= implode("\r\n", $oqcDiag) . "\r\n";
    @file_put_contents($diagPath, $txt);
    $createdOqcFiles[] = $diagPath;
}


// ─────────────────────────────
// (v39) ZIP은 xlsx 그대로 유지, 서버 저장(뷰어)은 PDF로 별도 생성 (ZIP 생성 후 처리)
// ─────────────────────────────

logline('4) ZIP 묶는 중...');

// ZIP 파일명: "YY.MM.DD (LGIT).zip" 형식
$zipDate = date('y.m.d', strtotime($shippingDateStr));
$shipCode = $SHIP_TO_TEMPLATE_SUBDIR[$shipTo] ?? 'SHIP';
$zipBase = sprintf('%s (%s)', $zipDate, $shipCode);
$zipName = $zipBase . '.zip';
// 동일 이름이 이미 있으면 충돌 방지로 시간만 뒤에 붙임
if (file_exists($workDir . DIRECTORY_SEPARATOR . $zipName)) {
    $zipName = $zipBase . '_' . date('His') . '.zip';
}
$zipPath = $workDir . DIRECTORY_SEPARATOR . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    logline('ZIP 생성 실패');
    echo "</div></div></div></body></html>";
    exit;
}

// 루트: Shipping Lot List 파일들
foreach ($createdFiles as $f) {
    $zip->addFile($f['path'], $f['name']);
    if (method_exists($zip, 'setCompressionName')) { $zip->setCompressionName($f['name'], ZipArchive::CM_STORE); }

    if (method_exists($zip, 'setCompressionName')) { $zip->setCompressionName($f['name'], ZipArchive::CM_STORE); }
}

// oqc 폴더: OQC 파일들 (폴더는 항상 생성해서 ZIP 안에 보이게 함)
    $zip->addEmptyDir('OQC');
if (!empty($createdOqcFiles)) {
    foreach ($createdOqcFiles as $outPath) {
        if (!is_string($outPath) || $outPath === '' || !file_exists($outPath)) continue;
        $zip->addFile($outPath, 'OQC/' . basename($outPath));
        if (method_exists($zip, 'setCompressionName')) { $zip->setCompressionName('OQC/' . basename($outPath), ZipArchive::CM_STORE); }
    }
}

// CMM: 자화용 CMM 템플릿들 (ZIP 루트에 파일로 포함, 폴더 생성 안 함)
if (!empty($createdCmmFiles)) {
    foreach ($createdCmmFiles as $outPath) {
        if (!is_string($outPath) || $outPath === '' || !file_exists($outPath)) continue;
        $entry = basename($outPath);
        $zip->addFile($outPath, $entry);
        if (method_exists($zip, 'setCompressionName')) { $zip->setCompressionName($entry, ZipArchive::CM_STORE); }
    }
}
$zip->close();

// ─────────────────────────────
// (v39) 서버 저장(뷰어용) PDF 생성 (ZIP은 xlsx 유지)
//  - ZIP: createdFiles/createdOqcFiles 그대로(.xlsx)
//  - 서버폴더(exports/reports/rf_{id}/): PDF + ZIP (xlsx는 필요 시 삭제)
// ─────────────────────────────
$storeMainFiles = $createdFiles;
$storeOqcFiles  = $createdOqcFiles;

if (PDF_EXPORT_ENABLED) {
    logline('3.6) 서버 저장용 PDF 변환 시작 (ZIP은 xlsx 유지)...');

    // PDF 변환 진행표시용 카운터(초 단위 출력 없음)
    $pdfMainTotal = 0;
    foreach ($createdFiles as $itTmp) {
        $tmp = null;
        if (is_array($itTmp)) {
            $tmp = $itTmp['path'] ?? ($itTmp[0] ?? null);
        } else if (is_string($itTmp)) {
            $tmp = $itTmp;
        }
        if (!$tmp || !is_string($tmp) || !is_file($tmp)) continue;
        if (preg_match('/\.xlsx$/i', $tmp)) $pdfMainTotal++;
    }
    $pdfMainIdx = 0;

    $pdfMain = [];
    foreach ($createdFiles as $it) {
        $src = null;
        if (is_array($it)) {
            $src = $it['path'] ?? ($it[0] ?? null);
        } else if (is_string($it)) {
            $src = $it;
        }
        if (!$src || !is_string($src) || !is_file($src)) continue;

        if (!preg_match('/\.xlsx$/i', $src)) {
            // xlsx가 아니면 그대로 보관
            $pdfMain[] = $it;
            continue;
        }
        $pdfMainIdx++;
        $label = ($pdfMainTotal > 0) ? ($pdfMainIdx . '/' . $pdfMainTotal) : (string)$pdfMainIdx;
        logline("  - PDF 변환(main {$label}): " . basename($src));
        $res = lo_convert_xlsx_to_pdf($src, dirname($src));
        $pdfPath = null;
        if (is_array($res)) $pdfPath = $res['pdf'] ?? null;
        else if (is_string($res)) $pdfPath = $res;

        if (is_string($pdfPath) && $pdfPath !== '' && is_file($pdfPath)) {
            $pdfMain[] = $pdfPath;
            logline("    -> OK");
            if (PDF_EXPORT_DELETE_XLSX) @unlink($src);
        } else {
            logline("    -> FAIL");
            // 실패 시 xlsx라도 보관(뷰어 fallback)
            $pdfMain[] = $it;
        }
    }

    $pdfOqc = [];
    $pdfOqcTotal = 0;
    if (!empty($createdOqcFiles)) {
        foreach ($createdOqcFiles as $srcTmp) {
            if (is_string($srcTmp) && is_file($srcTmp) && preg_match('/\.xlsx$/i', $srcTmp)) $pdfOqcTotal++;
        }
    }
    $pdfOqcIdx = 0;
    foreach ($createdOqcFiles as $src) {
        if (!is_string($src) || !is_file($src)) continue;

        if (!preg_match('/\.xlsx$/i', $src)) {
            $pdfOqc[] = $src;
            continue;
        }

        $pdfOqcIdx++;
        $label = ($pdfOqcTotal > 0) ? ($pdfOqcIdx . '/' . $pdfOqcTotal) : (string)$pdfOqcIdx;
        logline("  - PDF 변환(OQC {$label}): " . basename($src));

        $res = lo_convert_xlsx_to_pdf($src, dirname($src));
        $pdfPath = null;
        if (is_array($res)) $pdfPath = $res['pdf'] ?? null;
        else if (is_string($res)) $pdfPath = $res;

        if (is_string($pdfPath) && $pdfPath !== '' && is_file($pdfPath)) {
            $pdfOqc[] = $pdfPath;
            logline("    -> OK");
            if (PDF_EXPORT_DELETE_XLSX) @unlink($src);
        } else {
            logline("    -> FAIL");
            $pdfOqc[] = $src;
        }
    }

    if (!empty($pdfMain)) $storeMainFiles = $pdfMain;
    if (!empty($pdfOqc))  $storeOqcFiles  = $pdfOqc;

    logline('  - PDF 변환 완료: main=' . count($storeMainFiles) . ' / oqc=' . count($storeOqcFiles));
}




// ─────────────────────────────
// report_finish 기록 (발행 이력 저장)
// ─────────────────────────────
try {
    // parts_json: 품번 + 출하수량(합계 포함)
    $partsUniq = array_keys($counts);
    sort($partsUniq);

    $partsArr = [];
    $totalShipQty = 0;
    foreach ($partsUniq as $p) {
        $q = (int)($sumQty[$p] ?? 0);
        $partsArr[] = ['part' => $p, 'ship_qty' => $q];
        $totalShipQty += $q;
    }
    $partsPayload = ['parts' => $partsArr, 'total_ship_qty' => $totalShipQty];

    // ✅ 발행(build) 시 실제 마킹한 header_id/컬럼/날짜 로그 저장(취소 롤백용)
    if (function_exists('oqc_marklog_export_grouped')) {
        $ml = oqc_marklog_export_grouped();
        if (is_array($ml) && !empty($ml['g'])) $partsPayload['mark_log'] = $ml;
    }


    // PDF 기능 제거됨 (성능 개선): pdf_rel/pdf_name은 저장하지 않음
    $pdfRel = null;
    $pdfName = null;
    report_finish_insert($pdo, [
        'from_date'      => $fromDate,
        'to_date'        => $toDate,
        'ship_to'        => $shipTo,
        'parts_json'     => json_encode($partsPayload, JSON_UNESCAPED_UNICODE),
        'pdf_rel'        => null,
        'pdf_name'       => null,
        'oqc_new_count'  => (int)$totalOqcNew,
        'oqc_emg_count'  => (int)$totalOqcEmg,
        'zip_path'       => $zipPath,
        'zip_name'       => $zipName,
    ]);

    // ✅ 발행내역 View(엑셀 뷰어)용으로 결과 파일을 report_finish.id 단위로 보관
    $rfId = (int)$pdo->lastInsertId();
    if ($rfId > 0) {
        $meta = [
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'ship_to'   => $shipTo,
            'parts'     => $partsPayload,
            'zip_name'  => $zipName,
        ];
        $storeInfo = report_files_store($rfId, $storeMainFiles, $storeOqcFiles, $meta);
        // ZIP도 같이 보관(백업) - 실패해도 무시
        $dstZip = report_files_abs($rfId) . DIRECTORY_SEPARATOR . basename($zipName);
        @copy($zipPath, $dstZip);
    }

} catch (Throwable $e) {
    // 저장 실패해도 export 자체는 계속 진행
    logline('  [경고] report_finish 저장 실패: ' . $e->getMessage());
}

logline('완료. 잠시 후 다운로드가 시작됩니다.');

// meta 저장 후 fetch로 이동
$metaPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "oqc_export_meta_{$token}.json";
file_put_contents($metaPath, json_encode([
    'zip_path' => $zipPath,
    'zip_name' => $zipName,
    'work_dir' => $workDir,
], JSON_UNESCAPED_UNICODE));

?></div>
  </div>

  <div class="card small">
    다운로드가 자동으로 시작되지 않으면 아래 링크를 눌러주세요.<br>
    <a style="color:#8ab4f8;" href="?action=fetch&token=<?=h($token)?>&consume=1">ZIP 다운로드</a>
  </div>
</div>

<script>
(function(){
  try{
    if(window.parent && window.parent !== window){
      window.parent.postMessage({type:'report_build',status:'done',build_token:'<?=h($buildToken ?? '') ?>'},'*');
    }
  }catch(e){}
})();
setTimeout(() => {
  (window.top || window).location.href = '?action=fetch&token=<?=h($token)?>&consume=0';
}, 800);
</script>
</body>
</html>
<?php
exit;
