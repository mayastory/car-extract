<?php
// PATCH: jtmes-cmm-rowindex-nokeymap-v3

// DPTest/lib/ipqc_excel_export.php
// IPQC AOI/OMM/CMM "long form" Excel export (Part/SPC/FAI/Tool/Cavity/Date + Data1..N)
// Reuses the streaming XLSX engine in excel_export_common.php (dp_xlsx_*).

declare(strict_types=1);



// Export endpoints should not leak HTML warnings into CSV/XLSX downloads
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('output_buffering', '0');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Login guard (match auth_guard.php behavior, but without redirect)
if (!function_exists('ipqc_auth_is_logged_in')) {
    function ipqc_auth_is_logged_in(): bool {
        $keys = [
            'ship_user_id','dp_admin_id','user_id','userid','user','username','logged_in','is_login','is_logged_in'
        ];
        foreach ($keys as $k) {
            if (!empty($_SESSION[$k])) return true;
        }
        return false;
    }
}
if (!ipqc_auth_is_logged_in()) {
    ipqc_export_fail(401, "로그인이 필요합니다.");
}
require_once __DIR__ . '/excel_export_common.php'; // dp_get_pdo(), dp_xlsx_* helpers

/**
 * Always return errors as a downloadable text file.
 * 이유: iframe/새탭 없이 다운로드를 트리거할 때, 화면에 에러가 안 보이고 '그냥 끝난 것처럼' 보일 수 있음.
 * 그래서 실패 시에도 attachment 로 내려보내서 사용자에게 바로 보이게 한다.
 */
function ipqc_export_fail(int $code, string $msg): void {
    if (!headers_sent()) {
        http_response_code($code);
        $ts = date('Ymd_His');
        $filename = "IPQC_EXPORT_ERROR_{$ts}.txt";
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }
    echo "ERR(" . $code . "): " . $msg;
    exit;
}


function ipqc_norm_type(string $t): string {
    $t = strtolower(trim($t));
    if ($t === 'aoi') return 'aoi';
    if ($t === 'cmm') return 'cmm';
    if ($t === 'oqc') return 'oqc';
    return 'omm';
}

// UI 모델코드 ↔ DB part_name 모두 허용
function ipqc_norm_part_name(string $modelOrName): string {
    $s = trim($modelOrName);
    if ($s === '') return '';
    $map = [
        'MEM-IR-BASE'    => 'Memphis IR BASE',
        'MEM-X-CARRIER'  => 'Memphis X Carrier',
        'MEM-Y-CARRIER'  => 'Memphis Y Carrier',
        'MEM-Z-CARRIER'  => 'Memphis Z Carrier',
        'MEM-Z-STOPPER'  => 'Memphis Z Stopper',
    ];
    $u = strtoupper($s);
    if (isset($map[$u])) return $map[$u];
    // already a DB name?
    return $s;
}

function cmm_map_fai_name(string $modelDisp, $v2, $v1=null): string {
    static $NUM2LABEL_ZCARRIER = [
        "556"=>"20101_V1_FAI1-1_34","557"=>"20102_V2_FAI1-2_34","558"=>"20103_V3_FAI1-3_34","559"=>"20104_V4_FAI1-4_34",
        "560"=>"20201_V1_FAI2-1_34","561"=>"20202_V2_FAI1-2_34","562"=>"20203_V3_FAI2-3_34","563"=>"20204_V4_FAI2-4_34",
        "564"=>"20101_V1_FAI1-1_34A","565"=>"20102_V2_FAI1-2_34A","566"=>"20103_V3_FAI1-3_34A","567"=>"20104_V4_FAI1-4_34A",
        "568"=>"20101_V1_FAI1-1_34AT","569"=>"20102_V2_FAI1-2_34AT","570"=>"20103_V3_FAI1-3_34AT","571"=>"20104_V4_FAI1-4_34AT",
        "572"=>"20101_V1_FAI1-1_34AB","573"=>"20102_V2_FAI1-2_34AB","574"=>"20103_V3_FAI1-3_34AB","575"=>"20104_V4_FAI1-4_34AB",
        "577"=>"FAI98-V1--","578"=>"FAI99-V1","579"=>"FAI100-V1","580"=>"FAI101-V1","581"=>"FAI102-V1","582"=>"FAI103-V1",
        "584"=>"FAI98-V2-","585"=>"FAI99-V2","586"=>"FAI100-V2","587"=>"FAI101-V2","588"=>"FAI102-V2","589"=>"FAI103-V2",
        "591"=>"FAI104-V3-","592"=>"FAI105-V3","593"=>"FAI106-V3","594"=>"FAI107-V3","595"=>"FAI108-V3","596"=>"FAI109-V3",
        "598"=>"FAI104-V4-","599"=>"FAI105-V4","600"=>"FAI106-V4","601"=>"FAI107-V4","602"=>"FAI108-V4","603"=>"FAI109-V4",
    ];

    $mkey = preg_replace('/[^a-z0-9]/', '', strtolower((string)$modelDisp));
    $v2 = trim((string)$v2);
    $v1 = trim((string)$v1);

    if ($mkey === 'memphiszcarrier') {
        if (isset($NUM2LABEL_ZCARRIER[$v2])) return $NUM2LABEL_ZCARRIER[$v2];
        if ($v2 !== '' && preg_match('/^\d+$/', $v2) && $v1 !== '') return $v1;
    }
    return $v2;
}

function cmm_unmap_fai_name(string $modelDisp, $label): string {
    static $LABEL2NUM_ZCARRIER = null;
    static $NUM2LABEL_ZCARRIER = [
        "556"=>"20101_V1_FAI1-1_34","557"=>"20102_V2_FAI1-2_34","558"=>"20103_V3_FAI1-3_34","559"=>"20104_V4_FAI1-4_34",
        "560"=>"20201_V1_FAI2-1_34","561"=>"20202_V2_FAI1-2_34","562"=>"20203_V3_FAI2-3_34","563"=>"20204_V4_FAI2-4_34",
        "564"=>"20101_V1_FAI1-1_34A","565"=>"20102_V2_FAI1-2_34A","566"=>"20103_V3_FAI1-3_34A","567"=>"20104_V4_FAI1-4_34A",
        "568"=>"20101_V1_FAI1-1_34AT","569"=>"20102_V2_FAI1-2_34AT","570"=>"20103_V3_FAI1-3_34AT","571"=>"20104_V4_FAI1-4_34AT",
        "572"=>"20101_V1_FAI1-1_34AB","573"=>"20102_V2_FAI1-2_34AB","574"=>"20103_V3_FAI1-3_34AB","575"=>"20104_V4_FAI1-4_34AB",
        "577"=>"FAI98-V1--","578"=>"FAI99-V1","579"=>"FAI100-V1","580"=>"FAI101-V1","581"=>"FAI102-V1","582"=>"FAI103-V1",
        "584"=>"FAI98-V2-","585"=>"FAI99-V2","586"=>"FAI100-V2","587"=>"FAI101-V2","588"=>"FAI102-V2","589"=>"FAI103-V2",
        "591"=>"FAI104-V3-","592"=>"FAI105-V3","593"=>"FAI106-V3","594"=>"FAI107-V3","595"=>"FAI108-V3","596"=>"FAI109-V3",
        "598"=>"FAI104-V4-","599"=>"FAI105-V4","600"=>"FAI106-V4","601"=>"FAI107-V4","602"=>"FAI108-V4","603"=>"FAI109-V4",
    ];

    if ($LABEL2NUM_ZCARRIER === null) {
        $LABEL2NUM_ZCARRIER = [];
        foreach ($NUM2LABEL_ZCARRIER as $__num => $__lab) {
            $__lab = (string)$__lab;
            if ($__lab !== '' && !isset($LABEL2NUM_ZCARRIER[$__lab])) $LABEL2NUM_ZCARRIER[$__lab] = (string)$__num;
        }
    }

    $mkey = preg_replace('/[^a-z0-9]/', '', strtolower((string)$modelDisp));
    $label = trim((string)$label);
    if ($label === '') return '';

    if ($mkey === 'memphiszcarrier' && isset($LABEL2NUM_ZCARRIER[$label])) {
        return $LABEL2NUM_ZCARRIER[$label];
    }
    return $label;
}

// Build download filename: "<Model>_<AOI|OMM|CMM>_<TOKEN>_JMP_Sheet.<ext>"
// TOKEN rule:
// - page_date: YYYYMMDD
// - else: YYYYMM or YYYYMM-YYYYMM (based on selected years/months)
// - else: ALL
function ipqc_sanitize_filename(string $fn): string {
    // Windows forbidden: \ / : * ? " < > |
    $bad = ["\\", "/", ":", "*", "?", "\"", "<", ">", "|"];
    $fn = str_replace($bad, "_", $fn);
    // control chars
    $tmp = @preg_replace('/[\x00-\x1F\x7F]+/u', '_', $fn);
    if (is_string($tmp)) $fn = $tmp;
    // collapse
    $tmp = @preg_replace('/_+/u', '_', $fn);
    if (is_string($tmp)) $fn = $tmp;
    $fn = trim($fn, "._ ");
    if ($fn === '') $fn = 'export';
    return $fn;
}

function ipqc_send_download_headers(string $filename, string $contentType): void {
    // Clear any buffered output (prevents BOM/headers corruption)
    while (ob_get_level() > 0) { @ob_end_clean(); }

    // RFC 5987 filename*
    $ascii = @preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
    if (!is_string($ascii) || $ascii === '') $ascii = 'export';

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $contentType);
    header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($filename));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function ipqc_build_jmp_sheet_filename(string $partLabel, string $typeUpper, string $mode, string $pageDate, array $years, array $months, int $year, string $ext): string {
    $typeUpper = strtoupper(trim($typeUpper));
    if ($typeUpper === '') $typeUpper = 'OMM';

    $partLabel = trim($partLabel);
    if ($partLabel === '') $partLabel = 'MODEL';

    // TOKEN 1) page_date -> YYYYMMDD
    $token = '';
    $pd = trim((string)$pageDate);
    if ($pd !== '') {
        if (preg_match('/(\d{4})[-_\/\.]?(\d{1,2})[-_\/\.]?(\d{1,2})/', $pd, $m)) {
            $token = sprintf('%04d%02d%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        } elseif (preg_match('/^\d{8}$/', $pd)) {
            $token = $pd;
        }
    }

    // TOKEN 2) years/months -> YYYYMM or YYYYMM-YYYYMM
    if ($token === '') {
        $yList = array_values(array_filter(array_map('intval', $years), fn($x)=>$x>0));
        $mList = array_values(array_filter(array_map('intval', $months), fn($x)=>$x>0 && $x<=12));

        $yMin = !empty($yList) ? min($yList) : ($year > 0 ? $year : 0);
        $yMax = !empty($yList) ? max($yList) : $yMin;

        if ($yMin > 0 && !empty($mList)) {
            $mMin = min($mList);
            $mMax = max($mList);
            $from = sprintf('%04d%02d', $yMin, $mMin);
            $to   = sprintf('%04d%02d', $yMax, $mMax);
            $token = ($from === $to) ? $from : ($from . '-' . $to);
        } elseif ($yMin > 0) {
            $token = (string)$yMin;
        } else {
            $token = 'ALL';
        }
    }

    $base = "{$partLabel}_{$typeUpper}_{$token}_JMP_Sheet.{$ext}";
    return ipqc_sanitize_filename($base);
}

function ipqc_output_csv_from_stmt(PDOStatement $stmt, array $headers, array $keys, string $downloadName): void {
    ipqc_send_download_headers($downloadName, 'text/csv; charset=UTF-8');
    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($out === false) {
        ipqc_export_fail(500, "Failed to open output stream");
}


/* =========================
 * OQC JMP CSV Export (Py JMP Assist spec)
 * - Date: ONLY from source_file
 * - Row: Part, Tool, Cavity, Date, Label(Data N)
 * - Col: point_no
 * ========================= */

function ipqc_cmp_nat_export(string $a, string $b): int {
  $c = strnatcasecmp($a, $b);
  return $c !== 0 ? $c : strcmp($a, $b);
}

function oqc_excel_col_to_num($col) {
  if ($col === null) return null;
  $s = strtoupper(trim((string)$col));
  if ($s === '') return null;
  if (ctype_digit($s)) return (int)$s;
  $n = 0;
  for ($i=0; $i<strlen($s); $i++) {
    $ch = ord($s[$i]);
    if ($ch < 65 || $ch > 90) continue;
    $n = $n * 26 + ($ch - 64);
  }
  return $n > 0 ? $n : null;
}

function oqc_data_index_from_excel_col($excelCol) {
  $base = oqc_excel_col_to_num('AK');
  $n = oqc_excel_col_to_num($excelCol);
  if ($n === null) return null;
  $idx = $n - $base + 1;
  return ($idx >= 1 && $idx <= 500) ? $idx : null;
}

function oqc_parse_ymd_from_source_file($src) {
  $s = (string)$src;
  if ($s === '') return null;
  if (preg_match('/(20\d{2})[^\d]?([01]\d)[^\d]?([0-3]\d)/', $s, $m)) {
    return $m[1] . $m[2] . $m[3];
  }
  if (preg_match('/(^|[^\d])(\d{2})[^\d]?([01]\d)[^\d]?([0-3]\d)/', $s, $m)) {
    $yy = (int)$m[2];
    return sprintf('20%02d%02d%02d', $yy, (int)$m[3], (int)$m[4]);
  }
  return null;
}

function oqc_fmt_ymd_dash($ymd) {
  if (!is_string($ymd) || !preg_match('/^\d{8}$/', $ymd)) return '';
  return substr($ymd,0,4).'-'.substr($ymd,4,2).'-'.substr($ymd,6,2);
}

function oqc_norm_cavity($cav) {
  $s = strtoupper(trim((string)$cav));
  if ($s === '') return '';
  if (preg_match('/^\d+$/', $s)) return $s.'CAV';
  $s = str_replace(' ', '', $s);
  if (preg_match('/^\d+CAV$/', $s)) return $s;
  return $s;
}

function oqc_parse_tool_cavity_from_tc($tc) {
  $s = strtoupper(trim((string)$tc));
  if ($s === '') return ['',''];
  if (preg_match('/\b([A-Z])\s*#\s*(\d)\s*(?:CAV)?\b/', $s, $m)) {
    return [$m[1], $m[2].'CAV'];
  }
  if (preg_match('/\b([A-Z])\s*[-_ ]\s*(\d)\s*CAV\b/', $s, $m)) {
    return [$m[1], $m[2].'CAV'];
  }
  if (preg_match('/\b([A-Z])\s*(\d)\s*CAV\b/', $s, $m)) {
    return [$m[1], $m[2].'CAV'];
  }
  return ['',''];
}

function oqc_build_regex_ym(int $yyyy, int $mm): string {
  $yy2 = sprintf('%02d', $yyyy % 100);
  $yyyy4 = sprintf('%04d', $yyyy);
  $mm2 = sprintf('%02d', $mm);
  return '(^|[^0-9])(' . $yyyy4 . '|' . $yy2 . ')[^0-9]?' . $mm2 . '[^0-9]?[0-3][0-9]([^0-9]|$)';
}

function oqc_build_regex_ymd(string $pageDate): string {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageDate)) return '';
  $yyyy = (int)substr($pageDate,0,4);
  $mm = (int)substr($pageDate,5,2);
  $dd = (int)substr($pageDate,8,2);
  $yy2 = sprintf('%02d', $yyyy % 100);
  $yyyy4 = sprintf('%04d', $yyyy);
  $mm2 = sprintf('%02d', $mm);
  $dd2 = sprintf('%02d', $dd);
  return '(^|[^0-9])(' . $yyyy4 . '|' . $yy2 . ')[^0-9]?' . $mm2 . '[^0-9]?' . $dd2 . '([^0-9]|$)';
}

function oqc_table_exists(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
  $stmt->execute([$table]);
  return (bool)$stmt->fetchColumn();
}

function oqc_table_columns(PDO $pdo, string $table): array {
  $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
  $stmt->execute([$table]);
  $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  return is_array($cols) ? $cols : [];
}

function oqc_detect_schema(PDO $pdo): array {
  $headerCandidates = ['oqc_header','oqc_result_header','oqc_headers'];
  $measCandidates   = ['tbl_measurements','oqc_measurements','oqc_result_measurements','oqc_measurement','oqc_result'];

  $hTable = '';
  foreach ($headerCandidates as $t) {
    if (oqc_table_exists($pdo, $t)) { $hTable = $t; break; }
  }
  if ($hTable === '') return ['ok'=>false, 'error'=>'OQC header table not found (tried: '.implode(',',$headerCandidates).')'];

  $hCols = oqc_table_columns($pdo, $hTable);
  $hColsL = array_map('strtolower', $hCols);

  $idCol = in_array('id', $hColsL, true) ? $hCols[array_search('id',$hColsL,true)]
        : (in_array('header_id', $hColsL, true) ? $hCols[array_search('header_id',$hColsL,true)] : $hCols[0]);

  $partCol = '';
  foreach (['part_name','model_name','part','model'] as $c) {
    $i = array_search($c, $hColsL, true);
    if ($i !== false) { $partCol = $hCols[$i]; break; }
  }
  if ($partCol === '') return ['ok'=>false,'error'=>"OQC header missing part_name/model column in {$hTable}"];

  $sourceCol = '';
  foreach (['source_file','src_file','source','filename','file_name'] as $c) {
    $i = array_search($c, $hColsL, true);
    if ($i !== false) { $sourceCol = $hCols[$i]; break; }
  }
  if ($sourceCol === '') return ['ok'=>false,'error'=>"OQC header missing source_file column in {$hTable}"];

  $excelCol = '';
  foreach (['excel_col','excelcol','col','column'] as $c) {
    $i = array_search($c, $hColsL, true);
    if ($i !== false) { $excelCol = $hCols[$i]; break; }
  }
  if ($excelCol === '') return ['ok'=>false,'error'=>"OQC header missing excel_col column in {$hTable}"];

  $toolCol = '';
  $cavCol  = '';
  $tcCol   = '';

  $i = array_search('tool', $hColsL, true);
  if ($i !== false) $toolCol = $hCols[$i];
  $i = array_search('cavity', $hColsL, true);
  if ($i !== false) $cavCol = $hCols[$i];

  foreach (['tc','tool_cavity','toolcavity','tool_cavity_str'] as $c) {
    $i = array_search($c, $hColsL, true);
    if ($i !== false) { $tcCol = $hCols[$i]; break; }
  }
  if ($toolCol === '' && $tcCol === '') return ['ok'=>false,'error'=>"OQC header missing tool or tc column in {$hTable}"];

  $best = null;
  foreach ($measCandidates as $t) {
    if (!oqc_table_exists($pdo, $t)) continue;
    $cols = oqc_table_columns($pdo, $t);
    $colsL = array_map('strtolower', $cols);

    $fk = '';
    foreach (['header_id','oqc_header_id','result_header_id'] as $c) {
      $j = array_search($c, $colsL, true);
      if ($j !== false) { $fk = $cols[$j]; break; }
    }
    $pt = '';
    foreach (['point_no','point','key_name','fai_name'] as $c) {
      $j = array_search($c, $colsL, true);
      if ($j !== false) { $pt = $cols[$j]; break; }
    }
    $val = '';
    foreach (['value','meas_value','measured_value','val'] as $c) {
      $j = array_search($c, $colsL, true);
      if ($j !== false) { $val = $cols[$j]; break; }
    }
    if ($fk !== '' && $pt !== '' && $val !== '') {
      $best = ['table'=>$t,'fk'=>$fk,'point'=>$pt,'value'=>$val];
      break;
    }
  }
  if (!$best) return ['ok'=>false,'error'=>'OQC measurements table not found (needs header_id + point_no + value).'];

  return [
    'ok'=>true,
    'headerTable'=>$hTable,
    'measTable'=>$best['table'],
    'idCol'=>$idCol,
    'partCol'=>$partCol,
    'sourceCol'=>$sourceCol,
    'excelCol'=>$excelCol,
    'toolCol'=>$toolCol,
    'cavCol'=>$cavCol,
    'tcCol'=>$tcCol,
    'measFkCol'=>$best['fk'],
    'measPointCol'=>$best['point'],
    'measValueCol'=>$best['value'],
  ];
}

function oqc_export_csv(PDO $pdo, string $mode) {
  $type = ipqc_norm_type($_GET['type'] ?? 'omm');
  if ($type !== 'oqc') return;

  $part = trim((string)($_GET['part'] ?? ''));
  if ($part === '') ipqc_export_fail(400, '필수 파라미터 누락: part');

  $tools = $_GET['tools'] ?? [];
  if (!is_array($tools)) $tools = [];
  $tools = array_values(array_filter(array_map('trim', $tools), fn($v)=>$v!==''));

  $years = $_GET['years'] ?? [];
  if (!is_array($years)) $years = [];
  $years = array_values(array_filter(array_map('intval', $years), fn($v)=>$v>0));
  if (empty($years)) $years = [ (int)date('Y') ];

  $months = $_GET['months'] ?? [];
  if (!is_array($months)) $months = [];
  $months = array_values(array_filter(array_map('intval', $months), fn($v)=>$v>=1 && $v<=12));
  if (empty($months)) $months = [ (int)date('n') ];

  // optional column filter (point_no list) from fai[]
  $fais = $_GET['fai'] ?? [];
  if (!is_array($fais)) $fais = [$fais];
  $tmpF = [];
  foreach ($fais as $fv) {
    $s = (string)$fv;
    if ($s === '') continue;
    $tmpF[] = $s;
  }
  $fais = array_values(array_unique($tmpF));
  if (count($fais) > 1000) $fais = array_slice($fais, 0, 1000);
  $faiAll = in_array('__ALL__', $fais, true);
  if ($faiAll) $fais = []; // ALL => no filter



  $pageDate = trim((string)($_GET['page_date'] ?? ''));
    $pageDatesRaw = trim((string)($_GET['page_dates'] ?? '')); // comma-separated YYYY-MM-DD list (optional)
    $pageDates = [];
    if ($pageDatesRaw !== '') {
        foreach (preg_split('/\s*,\s*/', $pageDatesRaw) as $d) {
            $d = trim((string)$d);
            if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $pageDates[] = $d;
        }
        $pageDates = array_values(array_unique($pageDates));
    }
    if (!empty($pageDates) && $pageDate === '') { $pageDate = $pageDates[0]; }


  $schema = oqc_detect_schema($pdo);
  if (empty($schema['ok'])) ipqc_export_fail(500, (string)($schema['error'] ?? 'OQC schema detect failed'));

  $hT = $schema['headerTable'];
  $mT = $schema['measTable'];
  $partCol = $schema['partCol'];
  $srcCol = $schema['sourceCol'];
  $excelCol = $schema['excelCol'];
  $idCol = $schema['idCol'];

  // YM regex
  $ymPairs = [];
  foreach ($years as $yy) foreach ($months as $mm) $ymPairs[] = [(int)$yy,(int)$mm];
  $ymRegexParts = [];
  foreach ($ymPairs as $p) $ymRegexParts[] = oqc_build_regex_ym((int)$p[0], (int)$p[1]);
  $ymRegex = !empty($ymRegexParts) ? ('(' . implode('|', $ymRegexParts) . ')') : '';

  $where = "{$srcCol} IS NOT NULL AND {$srcCol} <> '' AND {$partCol} = ?";
  $params = [$part];

  // tool filter
  if (!empty($tools)) {
    if (!empty($schema['toolCol'])) {
      $toolCol = $schema['toolCol'];
      $in = implode(',', array_fill(0, count($tools), '?'));
      $where .= " AND {$toolCol} IN ({$in})";
      foreach ($tools as $t) $params[] = $t;
    } else {
      $tcCol = $schema['tcCol'];
      $likes = [];
      foreach ($tools as $t) { $likes[] = "{$tcCol} LIKE ?"; $params[] = strtoupper(trim($t))."#%"; }
      if (!empty($likes)) $where .= " AND (" . implode(" OR ", $likes) . ")";
    }
  }

  // month filter unless mode is page_date
  if ($mode !== 'page_date' && $ymRegex !== '') {
    $where .= " AND {$srcCol} REGEXP ?";
    $params[] = $ymRegex;
  }

  // day filter for page_date mode
  $dayWhere = '';
  $dayParams = [];
  if ($mode === 'page_date') {
    if ($pageDate === '') ipqc_export_fail(400, '필수 파라미터 누락: page_date');
    $dre = oqc_build_regex_ymd($pageDate);
    if ($dre === '') ipqc_export_fail(400, 'invalid page_date');
    $dayWhere = " AND {$srcCol} REGEXP ?";
    $dayParams[] = $dre;
  }

  // query join
  $fkCol = $schema['measFkCol'];
  $ptCol = $schema['measPointCol'];
  $valCol = $schema['measValueCol'];

  $selTool = !empty($schema['toolCol']) ? ("h.{$schema['toolCol']} AS tool,") : ("h.{$schema['tcCol']} AS tc,");
  $selCav  = !empty($schema['cavCol'])  ? ("h.{$schema['cavCol']} AS cavity,") : ("'' AS cavity,");

  $sql = "
    SELECT
      h.{$partCol} AS part_name,
      {$selTool}
      {$selCav}
      h.{$srcCol} AS source_file,
      h.{$excelCol} AS excel_col,
      m.{$ptCol} AS point_no,
      m.{$valCol} AS value
    FROM {$hT} h
    JOIN {$mT} m ON m.{$fkCol} = h.{$idCol}
    WHERE {$where}
    {$dayWhere}
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_merge($params, $dayParams));
  $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // pivot
  $colMetaMap = [];
  $tmpRows = [];

  foreach ($raw as $r) {
    $p = trim((string)($r['part_name'] ?? ''));
    if ($p === '') continue;

    $ymd = oqc_parse_ymd_from_source_file($r['source_file'] ?? '');
    if (!$ymd) continue;
    $date = oqc_fmt_ymd_dash($ymd);
    if ($date === '') continue;

    $tool = '';
    $cavity = '';

    if (!empty($schema['toolCol'])) {
      $tool = strtoupper(trim((string)($r['tool'] ?? '')));
      $cavity = !empty($schema['cavCol']) ? oqc_norm_cavity($r['cavity'] ?? '') : '';
    } else {
      [$tool, $cavity] = oqc_parse_tool_cavity_from_tc($r['tc'] ?? '');
      if (!empty($schema['cavCol'])) $cavity = oqc_norm_cavity($r['cavity'] ?? $cavity);
    }
    if ($tool === '') continue;

    $idx = oqc_data_index_from_excel_col($r['excel_col'] ?? null);
    if ($idx === null) continue;

    $label = 'Data ' . $idx;
    $rowKey = $p . '|||' . $tool . '|||' . $cavity . '|||' . $date . '|||' . $idx;

    $point = (string)($r['point_no'] ?? '');
    if ($point === '') continue;

    if (!isset($colMetaMap[$point])) $colMetaMap[$point] = $point;

    if (!isset($tmpRows[$rowKey])) {
      $tmpRows[$rowKey] = ['part'=>$p,'tool'=>$tool,'cavity'=>$cavity,'date'=>$date,'label'=>$label,'idx'=>$idx,'cells'=>[]];
    }
    $tmpRows[$rowKey]['cells'][$point] = $r['value'];
  }


  // Column order:
  // - if fai[] (point_no list) is provided, it MUST be applied as-is (viewer/export sync).
  // - else fall back to natural order from measured points.
  $colKeys = [];
  if (!empty($fais)) {
    $seen = [];
    foreach ($fais as $v) {
      $s = (string)$v;
      if ($s === '' || isset($seen[$s])) continue;
      $seen[$s] = 1;
      $colKeys[] = $s;
    }
  } else {
    $colKeys = array_keys($colMetaMap);
    usort($colKeys, 'ipqc_cmp_nat_export');
  }


  // sort rows
  $rows = array_values($tmpRows);
  usort($rows, function($a, $b){
    $c = ipqc_cmp_nat_export((string)$a['part'], (string)$b['part']); if ($c!==0) return $c;
    $c = ipqc_cmp_nat_export((string)$a['tool'], (string)$b['tool']); if ($c!==0) return $c;
    $c = ipqc_cmp_nat_export((string)$a['cavity'], (string)$b['cavity']); if ($c!==0) return $c;
    $c = ipqc_cmp_nat_export((string)$a['date'], (string)$b['date']); if ($c!==0) return $c;
    return ((int)$a['idx']) <=> ((int)$b['idx']);
  });

  // output CSV
  $fn = "OQC_JMP_" . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $part);
  if ($mode === 'page_date' && $pageDate !== '') $fn .= "_" . $pageDate;
  else if (!empty($years) && !empty($months)) $fn .= "_" . $years[0] . "-" . sprintf('%02d',$months[0]);
  $fn .= ".csv";

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$fn.'"');
  echo "\xEF\xBB\xBF"; // BOM for Excel

  $out = fopen('php://output', 'w');
  $head = array_merge(['Part','Tool','Cavity','Date','Label'], $colKeys);
  fputcsv($out, $head);

  foreach ($rows as $r) {
    $line = [$r['part'], $r['tool'], $r['cavity'], $r['date'], $r['label']];
    foreach ($colKeys as $k) $line[] = $r['cells'][$k] ?? null;
    fputcsv($out, $line);
  }
  fclose($out);
  exit;
}
    fputcsv($out, $headers);
    $i = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $line = [];
        foreach ($keys as $k) {
            $v = $row[$k] ?? '';
            if (is_array($v) || is_object($v)) $v = '';
            $line[] = $v;
        }
        fputcsv($out, $line);
        $i++;
        if (($i % 500) === 0) { @fflush($out); if (function_exists('flush')) @flush(); }
    }
    fclose($out);
    exit;
}



function ipqc_pdo_disable_buffering(PDO $pdo): void {
    // For very large exports: disable buffered queries to avoid memory explosion
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); } catch (Throwable $e) { /* ignore */ }
    }
}


function ipqc_starts_with_ci(string $s, string $prefix): bool {
    return strncasecmp($s, $prefix, strlen($prefix)) === 0;
}
function ipqc_is_numeric_leading2(string $s): bool {
    return preg_match('/^\s*\d/', $s) === 1;
}
/**
 * OMM header label (match JMP Assist view; avoid "FAI FAI 1" / "SPC SPC A")
 */
function ipqc_omm_header_label(string $fai, string $spc): string {
    // OMM: DB에 저장된 fai 라벨을 그대로 사용한다. (SPC는 표시용 부가필드)
    return (string)$fai;
}

// Normalize OMM pivot keys so that mapping.xlsx 라벨 공백/표기 흔들림에도 안정적으로 매칭된다.
function ipqc_norm_omm_fai_key(string $s): string {
    // OMM: 라벨 가공 금지(DB 그대로)
    return (string)$s;
}
function ipqc_norm_omm_spc_key(string $s): string {
    // OMM: SPC는 부가 필드(표시용). 가공/분리/강제 금지.
    return (string)$s;
}


function ipqc_output_pivot_csv_stream(
    PDO $pdo,
    string $type,
    string $hTable,
    string $mTable,
    string $whereSql,
    array $params,
    int $colsN,
    string $downloadName,
    array $colKeys,
    string $mode
): void {
    // long-running download should not lock the session
    if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    @header('X-Accel-Buffering: no');

    ipqc_pdo_disable_buffering($pdo);

    // Build dynamic headers + key -> column index map
    $headers = ['Part','Tool','Cavity','Date','라벨'];
    $keyToIdx = [];

    if ($type === 'omm') {
        foreach ($colKeys as $i => $p) {
            $fai = (string)($p['fai'] ?? '');
            $rawLabel = trim((string)($p['label'] ?? ''));
            if ($rawLabel !== '') {
                $headers[] = $rawLabel; // 매핑.xlsx 라벨은 그대로 표시
            } else {
                $headers[] = $fai; // DB 라벨 그대로
            }
            $keyToIdx[$fai] = (int)$i;
        }
    } else { // cmm
        foreach ($colKeys as $i => $p) {
            $pn = (string)($p['point_no'] ?? '');
            $headers[] = $pn;
            $keyToIdx[$pn] = (int)$i;
        }
    }

    // Page mode: limit by "groups"(=rows of Data n)
    $offsetGroups = 0;
    $limitGroups = PHP_INT_MAX;
    if ($mode === 'page') {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 5000;
        $allowed = [1000, 2000, 5000, 10000, 20000];
        if (!in_array($perPage, $allowed, true)) $perPage = 5000;
        $offsetGroups = ($page - 1) * $perPage;
        $limitGroups = $perPage;
    }

    ipqc_send_download_headers($downloadName, 'text/csv; charset=UTF-8');
    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    if ($out === false) {
        ipqc_export_fail(500, "Failed to open output stream");
}
    fputcsv($out, $headers);

    $whereKeyPrefix = $whereSql ? ($whereSql . " AND ") : "WHERE ";
    $params2 = $params;
    $params2[':cols'] = $colsN;

    if ($type === 'omm') {
        $sql = "
            SELECT
                h.part_name AS part,
                h.tool AS tool,
                h.cavity AS cavity,
                h.meas_date AS meas_date,
                m.row_index AS row_index,
                m.fai AS fai,
                m.spc AS spc,
                m.value AS value
            FROM {$hTable} h
            JOIN {$mTable} m ON m.header_id = h.id
            {$whereKeyPrefix} m.row_index BETWEEN 1 AND :cols
              AND m.fai IS NOT NULL AND m.fai <> ''
            ORDER BY h.meas_date ASC, h.tool ASC, h.cavity ASC, m.row_index ASC, m.spc ASC, m.fai ASC
        ";
    } else { // cmm
        $sql = "
            SELECT
                h.part_name AS part,
                h.tool AS tool,
                h.cavity AS cavity,
                h.meas_date AS meas_date,
                m.row_index AS row_index,
                m.point_no AS point_no,
                m.value AS value
            FROM {$hTable} h
            JOIN {$mTable} m ON m.header_id = h.id
            {$whereKeyPrefix} m.row_index BETWEEN 1 AND :cols
              AND m.point_no IS NOT NULL AND m.point_no <> ''
            ORDER BY h.meas_date ASC, h.tool ASC, h.cavity ASC, m.row_index ASC, m.point_no ASC
        ";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params2 as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    $colCount = count($colKeys);
    $vals = ($colCount > 0) ? array_fill(0, $colCount, '') : [];

    $curGroup = null;
    $curBase = null;
    $groupIndex = -1;
    $written = 0;
    $stoppedEarly = false;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $part = (string)($r['part'] ?? '');
        $tool = (string)($r['tool'] ?? '');
        $cav  = (string)($r['cavity'] ?? '');
        $measDate = (string)($r['meas_date'] ?? '');
        $date = ($measDate !== '') ? substr($measDate, 0, 10) : '';
        $ri   = (int)($r['row_index'] ?? 0);

        $g = $part . "\t" . $tool . "\t" . $cav . "\t" . $date . "\t" . $ri;

        // group boundary: flush previous group
        if ($curGroup !== null && $g !== $curGroup) {
            $groupIndex++;

            if ($groupIndex >= $offsetGroups && $groupIndex < ($offsetGroups + $limitGroups)) {
                fputcsv($out, array_merge($curBase, $vals));
                $written++;
                if (($written % 200) === 0) { @fflush($out); if (function_exists('flush')) @flush(); }
            }

            // reset values for next group
            if ($colCount > 0) $vals = array_fill(0, $colCount, '');

            // stop after last requested group in page mode
            if ($mode === 'page' && $groupIndex >= ($offsetGroups + $limitGroups - 1) && $groupIndex >= $offsetGroups) {
                $stoppedEarly = true;
                break;
            }
        }

        // start new group
        if ($curGroup === null || $g !== $curGroup) {
            $cavLabel = ($type === 'cmm') ? ($cav . 'Cavity') : ($cav . 'CAV');
            $curBase = [$part, $tool, $cavLabel, $date, 'Data ' . $ri];
            $curGroup = $g;
        }

        // fill value
        if ($type === 'omm') {
            $fai = (string)($r['fai'] ?? '');
            // SPC는 키에 관여하지 않음
            if ($fai !== '' && isset($keyToIdx[$fai])) {
                $idx = (int)$keyToIdx[$fai];
                if ($idx >= 0 && $idx < $colCount) $vals[$idx] = $r['value'] ?? '';
            }
        } else {
            $pn = (string)($r['point_no'] ?? '');
            if ($pn !== '' && isset($keyToIdx[$pn])) {
                $idx = (int)$keyToIdx[$pn];
                if ($idx >= 0 && $idx < $colCount) $vals[$idx] = $r['value'] ?? '';
            }
        }
    }

    // flush last group (if not stopped early)
    if (!$stoppedEarly && $curGroup !== null) {
        $groupIndex++;
        if ($groupIndex >= $offsetGroups && $groupIndex < ($offsetGroups + $limitGroups)) {
            fputcsv($out, array_merge($curBase, $vals));
        }
    }

    fclose($out);
    exit;
}







function ipqc_parse_list_param(string $key): array {
    // supports ?key=A&key=B, ?key[]=A&key[]=B, or ?key=A,B
    if (!isset($_GET[$key]) && !isset($_GET[$key.'[]'])) return [];
    $v = $_GET[$key] ?? $_GET[$key.'[]'];
    $out = [];
    if (is_array($v)) {
        foreach ($v as $x) {
            foreach (preg_split('/\s*,\s*/', (string)$x, -1, PREG_SPLIT_NO_EMPTY) as $p) $out[] = $p;
        }
    } else {
        foreach (preg_split('/\s*,\s*/', (string)$v, -1, PREG_SPLIT_NO_EMPTY) as $p) $out[] = $p;
    }
    $out = array_values(array_unique(array_filter(array_map('trim', $out), fn($x)=>$x!=='')));
    return $out;
}

function ipqc_default_cols(string $type): int {
    // JMP Assist long-form table 기준(가장 흔한 형태)
    if ($type === 'aoi') return 16;
    if ($type === 'cmm') return 3;
    return 3;  // omm
}

function ipqc_build_cols(int $n, bool $withSpcFai): array {
    $headers = ['Part'];
    $keys    = ['part'];
    $types   = ['s'];

    if ($withSpcFai) {
        $headers[] = 'SPC'; $keys[] = 'spc'; $types[] = 's';
        $headers[] = 'FAI'; $keys[] = 'fai'; $types[] = 's';
    } else {
        $headers[] = 'POINT'; $keys[] = 'fai'; $types[] = 's'; // CMM: point_no
    }

    $headers[] = 'Tool';   $keys[] = 'tool';   $types[] = 's';
    $headers[] = 'Cavity'; $keys[] = 'cavity'; $types[] = 's';
    $headers[] = 'Date';   $keys[] = 'date';   $types[] = 's';

    for ($i=1; $i<=$n; $i++) {
        $headers[] = "Data{$i}";
        $keys[]    = "data{$i}";
        $types[]   = 'n';
    }
    return ['headers'=>$headers,'keys'=>$keys,'types'=>$types];
}

function ipqc_export_run(string $mode): void {
    if ($mode !== 'all' && $mode !== 'page' && $mode !== 'page_date') {
        ipqc_export_fail(400, "Invalid mode");
}

    $type = ipqc_norm_type((string)($_GET['type'] ?? 'omm'));
    $part = ipqc_norm_part_name((string)($_GET['model'] ?? $_GET['part'] ?? ''));
    $year = (int)($_GET['year'] ?? 0);
    $months = array_map('intval', ipqc_parse_list_param('months'));
    $years = array_map('intval', ipqc_parse_list_param('years'));
    $years = array_values(array_filter($years, fn($x)=>$x>0));
    $pageDate = trim((string)($_GET['page_date'] ?? ''));
    $pageDatesRaw = trim((string)($_GET['page_dates'] ?? '')); // comma-separated YYYY-MM-DD list (optional)
    $pageDates = [];
    if ($pageDatesRaw !== '') {
        foreach (preg_split('/\s*,\s*/', $pageDatesRaw) as $d) {
            $d = trim((string)$d);
            if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $pageDates[] = $d;
        }
        $pageDates = array_values(array_unique($pageDates));
    }
    if (!empty($pageDates) && $pageDate === '') { $pageDate = $pageDates[0]; }

    $tools  = ipqc_parse_list_param('tools');
    $fais = $_GET['fai'] ?? [];
    if (!is_array($fais)) $fais = [$fais];
    $tmpF = [];
    foreach ($fais as $fv) {
        $s = (string)$fv;
        if ($s === '') continue;
        $tmpF[] = $s;
    }
    $fais = array_values(array_unique($tmpF));
    if (count($fais) > 500) $fais = array_slice($fais, 0, 500);
    $faiAll = in_array('__ALL__', $fais, true);
    if ($faiAll) $fais = []; // ALL => no filter


    

    // output format: csv (default) or xlsx (?format=xlsx)
    $format = strtolower((string)($_GET['format'] ?? 'csv'));
    if ($format !== 'xlsx') $format = 'csv';
    $ext = ($format === 'xlsx') ? 'xlsx' : 'csv';
if ($part === '' || (($year <= 0 && empty($years)) && $pageDate === '')) {
        ipqc_export_fail(400, "필수 파라미터 누락: model(or part), year");
}

    $colsN = (int)($_GET['cols'] ?? ipqc_default_cols($type));
    if ($colsN < 1) $colsN = ipqc_default_cols($type);
    if ($colsN > 120) $colsN = 120; // safety cap

    // tables
    $hTable = "ipqc_{$type}_header";
    $mTable = "ipqc_{$type}_measurements";

    $withSpcFai = ($type !== 'cmm');

    // WHERE
    $where = [];
    $params = [];
    $where[] = "h.part_name = :part";
    $params[':part'] = $part;

    $where[] = "h.meas_date IS NOT NULL";

    // Tool filter (viewer/export sync)
    // - __ALL__ (or ALL) sentinel means no filter
    $tools = array_values(array_unique(array_filter(array_map('trim', $tools), fn($x)=>$x!=='')));
    if (in_array('__ALL__', $tools, true) || in_array('ALL', $tools, true)) {
        $tools = [];
    }
    if (!empty($tools)) {
        $inT = [];
        foreach ($tools as $i => $t) {
            $k = ":t{$i}";
            $inT[] = $k;
            $params[$k] = $t;
        }
        if (!empty($inT)) {
            $where[] = "h.tool IN (" . implode(',', $inT) . ")";
        }
    }


    // 날짜 범위 조건 (INDEX friendly): YEAR()/MONTH() 대신 범위 조건으로
    // page_date(하루)가 지정되면 하루 범위가 이미 있으므로 year/month 범위는 생략
    if (!(($pageDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageDate)) || !empty($pageDates))) {
        $yearsEff = !empty($years) ? $years : (($year > 0) ? [$year] : []);
        $monthsEff = $months;

        $pairs = [];
        if (!empty($monthsEff)) {
            foreach ($yearsEff as $yy) {
                foreach ($monthsEff as $mm) {
                    $yy = (int)$yy; $mm = (int)$mm;
                    if ($yy < 2000 || $yy > 2100) continue;
                    if ($mm < 1 || $mm > 12) continue;
                    $pairs[] = [$yy, $mm];
                }
            }
        } else {
            foreach ($yearsEff as $yy) {
                $yy = (int)$yy;
                if ($yy < 2000 || $yy > 2100) continue;
                $pairs[] = [$yy, 0]; // whole year
            }
        }

        usort($pairs, function($a, $b){
            if ($a[0] === $b[0]) return $a[1] <=> $b[1];
            return $a[0] <=> $b[0];
        });

        $ranges = [];
        foreach ($pairs as $pair) {
            [$yy, $mm] = $pair;
            if ($mm === 0) {
                $start = sprintf('%04d-01-01 00:00:00', $yy);
                $dt = new DateTimeImmutable($start);
                $end = $dt->modify('+1 year')->format('Y-m-d H:i:s');
            } else {
                $start = sprintf('%04d-%02d-01 00:00:00', $yy, $mm);
                $dt = new DateTimeImmutable($start);
                $end = $dt->modify('+1 month')->format('Y-m-d H:i:s');
            }

            if (empty($ranges)) {
                $ranges[] = [$start, $end];
            } else {
                $last = count($ranges) - 1;
                if ($ranges[$last][1] === $start) {
                    $ranges[$last][1] = $end; // merge contiguous
                } else {
                    $ranges[] = [$start, $end];
                }
            }
        }

        $parts = [];
        foreach ($ranges as $i => $rg) {
            $ps = ':ds' . $i;
            $pe = ':de' . $i;
            $parts[] = "(h.meas_date >= {$ps} AND h.meas_date < {$pe})";
            $params[$ps] = $rg[0];
            $params[$pe] = $rg[1];
        }
        if (!empty($parts)) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }
    // page_date/page_dates optional filter (used for '페이지EXCEL')
    // - page_dates가 있으면 그 날짜들(멀티)을 OR로 묶는다.
    // - 없으면 page_date(단일)를 사용한다.
    if (!empty($pageDates)) {
        $parts = [];
        foreach ($pageDates as $i => $d) {
            $ps = ':pd' . $i . 's';
            $pe = ':pd' . $i . 'e';
            $params[$ps] = $d . ' 00:00:00';
            $dt = new DateTimeImmutable($d . ' 00:00:00');
            $params[$pe] = $dt->modify('+1 day')->format('Y-m-d H:i:s');
            $parts[] = "(h.meas_date >= {$ps} AND h.meas_date < {$pe})";
        }
        if (!empty($parts)) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    } else if ($pageDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageDate)) {
        try {
            $d0 = new DateTimeImmutable($pageDate . ' 00:00:00');
            $d1 = $d0->modify('+1 day');
            $where[] = "h.meas_date >= :pd0 AND h.meas_date < :pd1";
            $params[':pd0'] = $d0->format('Y-m-d H:i:s');
            $params[':pd1'] = $d1->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            // ignore invalid date
        }
    }

    // AOI only: optional FAI filter (reduce load)
    if ($type === 'aoi' && !empty($fais)) {
        $phF = [];
        foreach ($fais as $i => $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            $k = ":f{$i}";
            $phF[] = $k;
            $params[$k] = $v;
        }
        if (!empty($phF)) {
            $where[] = "m.fai IN (" . implode(',', $phF) . ")";
        }
    }




    // Dedupe: 같은 날짜/Tool/Cavity에 header가 여러개 있으면 (원본+_1 보정 등)
    // Python/JMP 출력과 동일하게 "가장 최신(header id 최대)" 1개만 사용한다.
    // (이 조건이 없으면 web에서는 여러 header의 값이 섞여서 CSV/XLSX가 뷰어/py와 달라질 수 있음)
    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    $dedupeTypes = ['aoi','omm','cmm'];
    if (in_array($type, $dedupeTypes, true)) {
        $dedupeCond = "h.id = (SELECT MAX(h2.id) FROM {$hTable} h2
                               WHERE h2.meas_date IS NOT NULL
                                 AND h2.part_name = h.part_name
                                 AND DATE(h2.meas_date) = DATE(h.meas_date)
                                 AND h2.tool = h.tool
                                 AND h2.cavity = h.cavity)";
        $whereSql .= ($whereSql ? " AND " : "WHERE ") . $dedupeCond;
    }


// AOI export (viewer format): Part, SPC, FAI, Tool, Cavity, Date, Data 1..N
// - AOI는 OMM/CMM처럼 '동적 컬럼 피벗'이 아니다.
// - OMM/CMM용 ipqc_output_pivot_csv_stream()를 타면 500이 날 수 있으므로 AOI는 별도 스트리밍으로 처리한다.
if ($type === 'aoi' && $format === 'csv') {
    $pdo2 = dp_get_pdo();

    // filename (same rule as others)
    $typeLabel = strtoupper($type);
    $downloadName = ipqc_build_jmp_sheet_filename((string)($_GET['model'] ?? $part), $typeLabel, $mode, $pageDate, $years, $months, $year, $ext);

    // order map (매핑.xlsx -> ipqc_order_map.php)
    $orderMap = ipqc2_load_order_map();
    $mk = ipqc2_model_to_mapkey($part);
    $orderAoi = [];
    if ($mk !== '' && isset($orderMap[$mk]['AOI']) && is_array($orderMap[$mk]['AOI'])) $orderAoi = $orderMap[$mk]['AOI'];

    // ORDER BY: Tool/Date/Cavity + mapping 순서(FIELD). 라벨은 1글자도 가공하지 않는다.
    $fieldExpr = '';
    if (!empty($orderAoi)) {
        $vals = [];
        foreach ($orderAoi as $kname) {
            if (is_array($kname)) continue;
            $s = (string)$kname;
            if ($s === '') continue;
            $vals[] = "'" . str_replace("'", "''", $s) . "'";
        }
        if (!empty($vals)) {
            $fld = "FIELD(m.fai, " . implode(",", $vals) . ")";
            $fieldExpr = "CASE WHEN {$fld}=0 THEN 999999 ELSE {$fld} END";
        }
    }

    $whereSql2 = $whereSql;
    $whereSql2 .= ($whereSql2 ? " AND " : "WHERE ") . "m.row_index BETWEEN 1 AND :cols";

    $sql = "
        SELECT
            h.part_name AS part_name,
            h.meas_date AS meas_date,
            h.tool AS tool,
            h.cavity AS cavity,
            m.spc AS spc,
            m.fai AS fai,
            m.row_index AS row_index,
            m.value AS value
        FROM {$hTable} h
        JOIN {$mTable} m ON m.header_id = h.id
        {$whereSql2}
        ORDER BY h.meas_date ASC, h.tool ASC, h.cavity ASC, " . ($fieldExpr !== '' ? "{$fieldExpr}, m.fai ASC" : "m.fai ASC") . ", m.spc ASC, m.row_index ASC
    ";

    $stmt = $pdo2->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':cols', $colsN, PDO::PARAM_INT);
    $stmt->execute();

    ipqc_send_download_headers($downloadName, 'text/csv; charset=UTF-8');
    echo "\xEF\xBB\xBF"; // BOM for Excel
    $out = fopen('php://output','w');

    $headers = ['Part','SPC','FAI','Tool','Cavity','Date'];
    for ($i=1; $i<=$colsN; $i++) $headers[] = "Data {$i}";
    fputcsv($out, $headers);

    $curKey = null;
    $curRow = null;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $date = substr((string)$r['meas_date'], 0, 10);
        $k = (string)$r['part_name']."|".(string)$r['spc']."|".(string)$r['fai']."|".(string)$r['tool']."|".(string)$r['cavity']."|".$date;

        if ($curKey !== $k) {
            if ($curRow !== null) fputcsv($out, $curRow);
            $curKey = $k;
            $curRow = array_fill(0, 6 + $colsN, '');
            $curRow[0] = (string)$r['part_name'];
            $curRow[1] = (string)$r['spc'];
            $curRow[2] = (string)$r['fai'];
            $curRow[3] = (string)$r['tool'];
            $curRow[4] = (string)$r['cavity'];
            $curRow[5] = $date;
        }

        $idx = (int)$r['row_index'];
        if ($idx >= 1 && $idx <= $colsN) {
            $curRow[5 + $idx] = $r['value'];
        }
    }
    if ($curRow !== null) fputcsv($out, $curRow);

    fclose($out);
    exit;
}



// Special: OMM/CMM은 화면처럼(라벨(Data n) 행 + 동적 컬럼) 피벗 형태로 내보냄
// - OMM: (FAI, SPC) 조합이 컬럼
// - CMM: point_no가 컬럼
// (전체EXCEL/페이지EXCEL 모두 동일한 피벗 형태)
if (($type === 'omm' || $type === 'cmm') && ($mode === 'all' || $mode === 'page' || $mode === 'page_date')) {
    $pdo2 = dp_get_pdo();

    // filename (same rule as below)
    $typeLabel = strtoupper($type);
    $downloadName = ipqc_build_jmp_sheet_filename((string)($_GET['model'] ?? $part), $typeLabel, $mode, $pageDate, $years, $months, $year, $ext);

    $whereKeyPrefix = $whereSql ? ($whereSql . " AND ") : "WHERE ";
    $params2 = $params;
    $params2[':cols'] = $colsN;

    if ($type === 'omm') {
        // If user selected fai[] columns, export ONLY those columns (viewer/export sync)
        if (!empty($fais)) {
            $keys = [];
            $seen = [];
            foreach ($fais as $v) {
                $s = (string)$v; // DO NOT trim/normalize (absolute rule)
                if ($s === '' || isset($seen[$s])) continue;
                $seen[$s] = 1;
                $keys[] = ['fai' => $s];
            }
        } else {
        // OMM export: DB에 저장된 fai 라벨을 그대로 사용한다.
        // - SPC는 부가 필드(표시용)일 뿐이며, 컬럼 키/매칭/생성에 관여하지 않는다.
        // - 매핑.xlsx(order_map)은 '정렬'에만 사용(정확히 일치하는 라벨만 적용). 신규 컬럼 생성/라벨 가공 금지.

        // 0) 실제 DB에 존재하는 fai 라벨 목록(그대로)
        $dbKeySet = [];
        $dbKeyList = [];
        $keySql = "
            SELECT DISTINCT m.fai AS fai
            FROM {$mTable} m
            JOIN {$hTable} h ON h.id = m.header_id
            {$whereKeyPrefix} m.row_index BETWEEN 1 AND :cols
              AND m.fai IS NOT NULL AND m.fai <> ''
        ";
        $stmtA = $pdo2->prepare($keySql);
        foreach ($params2 as $k => $v) $stmtA->bindValue($k, $v);
        $stmtA->execute();
        while ($rA = $stmtA->fetch(PDO::FETCH_ASSOC)) {
            $f = (string)($rA['fai'] ?? '');
            if ($f === '') continue;
        if (!empty($filterSet) && !isset($filterSet[$f])) continue;
            if (isset($dbKeySet[$f])) continue;
            $dbKeySet[$f] = 1;
            $dbKeyList[] = $f;
        }

        // 1) Apply order map only when labels match DB labels exactly
        $orderMap = [];
        try { $orderMap = ipqc2_load_order_map(); } catch (Throwable $e) { $orderMap = []; }
        $mk = ipqc2_model_to_mapkey($part);
        $labels = [];
        if ($mk !== '' && isset($orderMap[$mk]['OMM']) && is_array($orderMap[$mk]['OMM'])) {
            $labels = $orderMap[$mk]['OMM'];
        }

        $seen = [];
        foreach ($labels as $lab) {
            if (!is_string($lab)) continue;
            $rawLab = (string)$lab;
            if ($rawLab === '') continue;
            if (isset($dbKeySet[$rawLab]) && !isset($seen[$rawLab])) {
                $seen[$rawLab] = 1;
                $keys[] = ['fai' => $rawLab];
            }
        }

        // 2) Append DB keys not in mapping (do not lose data)
        $extra = [];
        foreach ($dbKeyList as $f) {
            if ($f === '' || isset($seen[$f])) continue;
            $seen[$f] = 1;
            $extra[] = $f;
        }
        if (!empty($extra)) {
            usort($extra, function($a, $b){
                $c = strnatcasecmp((string)$a, (string)$b);
                return $c !== 0 ? $c : strcmp((string)$a, (string)$b);
            });
            foreach ($extra as $f) {
                $keys[] = ['fai' => $f];
            }
        }

        // 3) If mapping is missing and DB keys exist, still export them
        if (empty($keys) && !empty($dbKeyList)) {
            $tmp = $dbKeyList;
            usort($tmp, function($a, $b){
                $c = strnatcasecmp((string)$a, (string)$b);
                return $c !== 0 ? $c : strcmp((string)$a, (string)$b);
            });
            foreach ($tmp as $f) {
                $keys[] = ['fai' => $f];
            }
        }

        }
    } else { // cmm
        // If user selected fai[] columns, export ONLY those columns (viewer/export sync)
        if (!empty($fais)) {
            $keys = [];
            $seen = [];
            $modelForCmm = (string)($_GET['model'] ?? $part);
            foreach ($fais as $v) {
                $disp = (string)$v;
                if ($disp === '') continue;
                $raw = cmm_unmap_fai_name($modelForCmm, $disp);
                if ($raw === '' || isset($seen[$raw])) continue;
                $seen[$raw] = 1;
                $keys[] = ['point_no' => $raw, 'label' => cmm_map_fai_name($modelForCmm, $raw)];
            }
        } else {
        // Build column list from actual measurements in the selected range (correctness first)
        $keySql = "
            SELECT DISTINCT m.point_no AS point_no
            FROM {$mTable} m
            JOIN {$hTable} h ON h.id = m.header_id
            {$whereKeyPrefix} m.row_index BETWEEN 1 AND :cols
              AND m.point_no IS NOT NULL AND m.point_no <> ''
        ";
        $stmtK = $pdo2->prepare($keySql);
        foreach ($params2 as $k => $v) $stmtK->bindValue($k, $v);
        $stmtK->execute();
        $keys = [];
        $modelForCmm = (string)($_GET['model'] ?? $part);
        while ($rK = $stmtK->fetch(PDO::FETCH_ASSOC)) {
            $pn = trim((string)($rK['point_no'] ?? ''));
            if ($pn !== '') $keys[] = ['point_no' => $pn, 'label' => cmm_map_fai_name($modelForCmm, $pn)];
        }
        $orderMap = [];
        try { $orderMap = ipqc2_load_order_map(); } catch (Throwable $e) { $orderMap = []; }
        $mk = ipqc2_model_to_mapkey($modelForCmm);
        $orderIndex = [];
        if ($mk !== '' && isset($orderMap[$mk]['CMM']) && is_array($orderMap[$mk]['CMM'])) {
            $iOrd = 0;
            foreach ($orderMap[$mk]['CMM'] as $lab) {
                $lab = (string)$lab;
                if ($lab !== '' && !isset($orderIndex[$lab])) $orderIndex[$lab] = $iOrd;
                $iOrd++;
            }
        }
        usort($keys, function($a, $b) use ($orderIndex){
            $la = (string)($a['label'] ?? ($a['point_no'] ?? ''));
            $lb = (string)($b['label'] ?? ($b['point_no'] ?? ''));
            if (!empty($orderIndex)) {
                $ia = $orderIndex[$la] ?? PHP_INT_MAX;
                $ib = $orderIndex[$lb] ?? PHP_INT_MAX;
                if ($ia !== $ib) return $ia <=> $ib;
            }
            $pa = (string)($a['point_no'] ?? '');
            $pb = (string)($b['point_no'] ?? '');
            $an = is_numeric($pa);
            $bn = is_numeric($pb);
            if ($an && $bn) return (int)$pa <=> (int)$pb;
            if ($an && !$bn) return -1;
            if (!$an && $bn) return 1;
            return strnatcmp($la !== '' ? $la : $pa, $lb !== '' ? $lb : $pb);
        });
        }
    }

    
    // CSV (대용량): MySQL 동적 PIVOT(MAX(CASE...)) 대신
    // long-row 스트리밍 + PHP에서 피벗 구성하여 500/메모리/쿼리길이 이슈를 피함
    if ($format === 'csv' && ($type === 'omm' || $type === 'cmm')) {
        if ($type === 'cmm') { header('X-DP-Patch: jtmes-cmm-rowindex-nokeymap-v3'); }

        ipqc_output_pivot_csv_stream($pdo2, $type, $hTable, $mTable, $whereSql, $params, $colsN, $downloadName, $keys, $mode);
    }

// 2) Build dynamic pivot SELECT
    $headers = ['Part','Tool','Cavity','Date','라벨'];
    $outKeys = ['part','tool','cavity','date','label'];
    $types   = ['s','s','s','s','s'];

    $select = [
        "h.part_name AS part",
        "h.tool AS tool",
        ($type === 'cmm' ? "CONCAT(h.cavity, 'Cavity') AS cavity" : "CONCAT(h.cavity, 'CAV') AS cavity"),
        "DATE_FORMAT(h.meas_date, '%Y-%m-%d') AS date",
        "CONCAT('Data ', m.row_index) AS label"
    ];

    $i = 0;
    foreach ($keys as $p) {
        $ck = "c{$i}";
        if ($type === 'omm') {
            $fai = (string)($p['fai'] ?? '');
            $headers[] = $fai; // DB 라벨 그대로
            $pf = ":kf{$i}";
            $select[] = "MAX(CASE WHEN m.fai = {$pf} THEN m.value END) AS {$ck}";
            $params2[$pf] = $fai;
} else {
            $pn = (string)($p['point_no'] ?? '');
            $disp = trim((string)($p['label'] ?? ''));
            $headers[] = ($disp !== '' ? $disp : $pn);
            $pp = ":kp{$i}";
            $select[] = "MAX(CASE WHEN m.point_no = {$pp} THEN m.value END) AS {$ck}";
            $params2[$pp] = $pn;
        }
        $outKeys[] = $ck;
        $types[]   = 'n';
        $i++;
    }

    $params2[':cols'] = $colsN;

    $sqlP = "
        SELECT
            " . implode(",\n            ", $select) . "
        FROM {$hTable} h
        JOIN {$mTable} m ON m.header_id = h.id
        {$whereKeyPrefix} m.row_index BETWEEN 1 AND :cols
        GROUP BY h.part_name, h.tool, h.cavity, DATE(h.meas_date), m.row_index
        ORDER BY DATE(h.meas_date) ASC, h.tool ASC, h.cavity ASC, m.row_index ASC
    ";

    // optional page mode limit
    if ($mode === 'page') {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 5000;
        $allowed = [1000, 2000, 5000, 10000, 20000];
        if (!in_array($perPage, $allowed, true)) $perPage = 5000;
        $offset = ($page - 1) * $perPage;
        $sqlP .= " LIMIT {$perPage} OFFSET {$offset}";
    }

    $stmtP = $pdo2->prepare($sqlP);
    foreach ($params2 as $k => $v) $stmtP->bindValue($k, $v);
    $stmtP->execute();

    $col = ['headers' => $headers, 'keys' => $outKeys, 'types' => $types];

    

    if ($format === 'csv') {
        ipqc_output_csv_from_stmt($stmtP, $headers, $outKeys, $downloadName);
    }
$tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dptest_ipqc_xlsx_' . uniqid('', true);
    @mkdir($tmpBase, 0777, true);

    $sheetXml = $tmpBase . DIRECTORY_SEPARATOR . 'sheet1.xml';
    $zipPath  = $tmpBase . DIRECTORY_SEPARATOR . 'export.xlsx';

    dp_xlsx_generate_sheet_xml($sheetXml, $col, (function() use ($stmtP, $col) {
        while ($row = $stmtP->fetch(PDO::FETCH_ASSOC)) {
            foreach ($col['keys'] as $k) {
                if (!array_key_exists($k, $row)) $row[$k] = '';
            }
            yield $row;
        }
    })());
    dp_xlsx_make_zip($sheetXml, $zipPath);

    dp_xlsx_output_file($zipPath, $downloadName);

    @unlink($sheetXml);
    @unlink($zipPath);
    @rmdir($tmpBase);
    exit;
}


// Pivot columns (Data1..N)
    $pivot = [];
    for ($i=1; $i<=$colsN; $i++) {
        $pivot[] = "MAX(CASE WHEN m.row_index = {$i} THEN m.value END) AS data{$i}";
    }
    $pivotSql = implode(",\n               ", $pivot);

    if ($withSpcFai) {
        $selectKey = "m.spc AS spc, m.fai AS fai";
        $groupKey  = "h.part_name, m.spc, m.fai, h.tool, h.cavity, h.meas_date";
        $orderKey  = "h.meas_date ASC, h.tool ASC, h.cavity ASC, m.spc ASC, m.fai ASC";
    } else {
        $selectKey = "NULL AS spc, m.point_no AS fai";
        $groupKey  = "h.part_name, m.point_no, h.tool, h.cavity, h.meas_date";
        $orderKey  = "h.meas_date ASC, h.tool ASC, h.cavity ASC, m.point_no ASC";
    }

    $sql = "
        SELECT
            h.part_name AS part,
            {$selectKey},
            h.tool AS tool,
            CONCAT(h.cavity, 'CAV') AS cavity,
            h.meas_date AS meas_date,
            {$pivotSql}
        FROM {$hTable} h
        JOIN {$mTable} m ON m.header_id = h.id
        {$whereSql}
        GROUP BY {$groupKey}
        ORDER BY {$orderKey}
    ";

    // page/limit
    if ($mode === 'page') {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 5000;
        $allowed = [1000, 2000, 5000, 10000, 20000];
        if (!in_array($perPage, $allowed, true)) $perPage = 5000;
        $offset = ($page - 1) * $perPage;
        $sql .= " LIMIT {$perPage} OFFSET {$offset}";
    }
    $pdo = dp_get_pdo();

    // OQC JMP export (CSV only)
    if ($type === 'oqc') {
        oqc_export_csv($pdo, $mode);
        return;
    }
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    $col = ipqc_build_cols($colsN, $withSpcFai);

    

    if ($format === 'csv') {
        ipqc_output_csv_from_stmt($stmt, $col['headers'], $col['keys'], $downloadName);
    }
$rowsIter = (function() use ($stmt, $col) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // ensure all keys exist
            foreach ($col['keys'] as $k) {
                if (!array_key_exists($k, $row)) $row[$k] = '';
            }
            yield $row;
        }
    })();

    $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dptest_ipqc_xlsx_' . uniqid('', true);
    @mkdir($tmpBase, 0777, true);
    $sheetXml = $tmpBase . DIRECTORY_SEPARATOR . 'sheet1.xml';
    $zipPath  = $tmpBase . DIRECTORY_SEPARATOR . 'export.xlsx';

    dp_xlsx_generate_sheet_xml($sheetXml, $col, $rowsIter);
    dp_xlsx_make_zip($sheetXml, $zipPath);

    $typeLabel = strtoupper($type);
    $downloadName = ipqc_build_jmp_sheet_filename((string)($_GET['model'] ?? $part), $typeLabel, $mode, $pageDate, $years, $months, $year, $ext);

    dp_xlsx_output_file($zipPath, $downloadName);

    @unlink($sheetXml);
    @unlink($zipPath);
    @rmdir($tmpBase);
    exit;
}

/* ===========================
 * IPQC Export FIX (AOI/OMM/CMM)
 * - AOI: 비-PIVOT (행=FAI, 열=Data1..N)
 * - OMM/CMM: PIVOT (행=Data 1..N, 열=FAI/SPC or point_no)
 * - Column order is seeded from lib/ipqc_order_map.php (매핑.xlsx)
 * - No mysqli usage (PDO only)
 * =========================== */

function ipqc2_norm_type(string $t): string {
    $t = strtoupper(trim($t));
    if ($t === 'AOI') return 'AOI';
    if ($t === 'OMM') return 'OMM';
    if ($t === 'CMM') return 'CMM';
    if ($t === 'OQC') return 'OQC';
    return 'OMM';
}

function ipqc2_get_list(string $name): array {
    $v = $_GET[$name] ?? [];
    if (is_array($v)) return array_values(array_filter(array_map('trim', $v), fn($x)=>$x!==''));
    $s = trim((string)$v);
    if ($s === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $s)), fn($x)=>$x!==''));
}

function ipqc2_model_to_mapkey(string $model): string {
    $m = trim($model);
    if ($m === '') return '';
    if (preg_match('/z\s*[-_]?\s*stopper/i', $m)) return 'ZSTOPPER';
    if (preg_match('/z\s*[-_]?\s*carrier/i', $m)) return 'ZCARRIER';
    if (preg_match('/y\s*[-_]?\s*carrier/i', $m)) return 'YCARRIER';
    if (preg_match('/x\s*[-_]?\s*carrier/i', $m)) return 'XCARRIER';
    if (preg_match('/ir\s*[-_]?\s*base/i', $m)) return 'IRBASE';
    return '';
}

function ipqc2_load_order_map(): array {
    $p = __DIR__ . '/ipqc_order_map.php';
    if (!is_file($p)) return [];
    $tmp = @include $p;
    return is_array($tmp) ? $tmp : [];
}

function ipqc2_send_csv(string $filename): void {
    ipqc_send_download_headers($filename, 'text/csv; charset=UTF-8');
    echo "\xEF\xBB\xBF"; // BOM for Excel
}

function ipqc2_build_date_or(array $pairs, array &$params): string {
    // pairs: [ [Y,M], ... ] where M==0 means whole year
    $or = [];
    $i = 0;
    foreach ($pairs as $pm) {
        $yy = (int)$pm[0];
        $mm = (int)$pm[1];
        if ($yy < 2000 || $yy > 2100) continue;
        if ($mm === 0) {
            $s = sprintf('%04d-01-01 00:00:00', $yy);
            $e = sprintf('%04d-01-01 00:00:00', $yy+1);
        } else {
            if ($mm < 1 || $mm > 12) continue;
            $s = sprintf('%04d-%02d-01 00:00:00', $yy, $mm);
            if ($mm === 12) $e = sprintf('%04d-01-01 00:00:00', $yy+1);
            else $e = sprintf('%04d-%02d-01 00:00:00', $yy, $mm+1);
        }
        $ps = ":ds{$i}";
        $pe = ":de{$i}";
        $params[$ps] = $s;
        $params[$pe] = $e;
        $or[] = "(h.meas_date >= {$ps} AND h.meas_date < {$pe})";
        $i++;
    }
    if (empty($or)) return "1=1";
    return "(" . implode(" OR ", $or) . ")";
}

function ipqc2_build_day_range(string $pageDate, array &$params): string {
    // pageDate: YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageDate)) return "1=1";
    $s = $pageDate . " 00:00:00";
    $dt = new DateTime($pageDate);
    $dt->modify("+1 day");
    $e = $dt->format("Y-m-d") . " 00:00:00";
    $params[':ds0'] = $s;
    $params[':de0'] = $e;
    return "(h.meas_date >= :ds0 AND h.meas_date < :de0)";
}

function ipqc2_parse_omm_label(string $label): array {
    // OMM 매핑 라벨은 '그냥 포인트명'일 뿐이고,
    // SPC는 라벨 문자열의 일부일 수도 / 별도 컬럼(m.spc)에만 있을 수도 / 아예 없을 수도 있다.
    // 따라서 export는 SPC 유무로 키를 분리하지 않고,
    // 라벨에서 만든 후보(full/base)로만 DB fai 키와 매칭한다.
    $label = trim((string)$label);
    if ($label === '') return ['', ''];

    // strip trailing underscores (common typo in mapping)
    $label = preg_replace('/_+$/', '', $label);
    $label = trim((string)$label);
    if ($label === '') return ['', ''];

    // normalize spaces
    $s = preg_replace('/\s+/', ' ', $label);
    $s = trim((string)$s);

    // strip leading FAI token
    $sNoFai = preg_replace('/^\s*FAI\s*/i', '', $s);
    $sNoFai = trim((string)$sNoFai);
    if ($sNoFai === '') return ['', ''];

    // normalize "/ SPC X" -> " SPC X" so DB key style("... SPC X")도 매칭 가능
    $fullSrc = preg_replace('/\s*\/\s*SPC\s+/i', ' SPC ', $sNoFai);
    $fullSrc = preg_replace('/\s+/', ' ', (string)$fullSrc);
    $full = trim((string)$fullSrc);

    // base candidate: before SPC token
    $base = '';
    if (preg_match('/^(.*?)(?:\s*\/\s*)?\s*SPC\s+.+$/i', $sNoFai, $m)) {
        $before = rtrim(trim((string)$m[1]), "/ \t");
        $before = preg_replace('/\s+/', ' ', (string)$before);
        $base = trim((string)$before);
    }

    return [$full, $base];
}


// OMM column label: must match JMP Assist viewer (omm_col_label in ipqc_view.php)
function ipqc2_omm_col_label(string $keyName, string $spc): string {
    $keyName = trim((string)$keyName);
    $spc = trim((string)$spc);

    // base label: numeric-leading only (avoid "FAI FAI 1" etc)
    $base = $keyName;
    if ($base !== '' && ipqc_is_numeric_leading2($base) && !ipqc_starts_with_ci($base, 'FAI')) {
        $base = 'FAI ' . $base;
    }

    if ($spc === '') return $base;

    // avoid "/ SPC SPC A" duplication
    if (ipqc_starts_with_ci($spc, 'SPC')) return $base . ' / ' . $spc;

    return $base . ' / SPC ' . $spc;
}

function ipqc2_omm_colkey(string $keyName, string $spc): string {
    return trim((string)$keyName) . '|' . trim((string)$spc);
}

// OMM column ordering: numeric-leading first, then natural by label (same as viewer fallback ipqc_cmp_omm_cols)
function ipqc2_cmp_omm_colkey(string $aKey, string $bKey): int {
    $aParts = explode('|', $aKey, 2);
    $bParts = explode('|', $bKey, 2);
    $aName = (string)($aParts[0] ?? '');
    $bName = (string)($bParts[0] ?? '');

    $ag = ipqc_is_numeric_leading2($aName) ? 0 : 1;
    $bg = ipqc_is_numeric_leading2($bName) ? 0 : 1;
    if ($ag !== $bg) return $ag <=> $bg;

    $aLabel = ipqc2_omm_col_label($aName, (string)($aParts[1] ?? ''));
    $bLabel = ipqc2_omm_col_label($bName, (string)($bParts[1] ?? ''));
    $c = strnatcasecmp($aLabel, $bLabel);
    if ($c !== 0) return $c;

    return strnatcasecmp($aKey, $bKey);
}

// OMM pivot export: column keys derived from actual (fai, spc) pairs in the selected range,
// and ordered to match viewer (orderMap if it matches, otherwise fallback comparator).
function ipqc2_export_omm_pivot_csv(PDO $pdo, string $model, array $tools, string $dateWhere, array $params, int $colsN, array $orderLabels = [], array $faiFilter = []): void {
    // OMM export MUST use DB label (m.fai) AS-IS.
    // - 공백/슬래시/문자/대소문자/접두어 등 어떤 가공도 하지 않는다.
    // - OMM에서 spc는 사용하지 않으며(항상 ''), 키/정렬/매칭에 관여하지 않는다.
    $hTable = "ipqc_omm_header";
    $mTable = "ipqc_omm_measurements";

    // Build base WHERE
    $where = [];
    $where[] = "h.meas_date IS NOT NULL";
    $where[] = $dateWhere;
    $where[] = "h.part_name = :model";
    $params[':model'] = $model;

    if (!empty($tools)) {
        $in = [];
        foreach ($tools as $i => $t) {
            $k = ":t{$i}";
            $in[] = $k;
            $params[$k] = $t;
        }
        $where[] = "h.tool IN (" . implode(',', $in) . ")";
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    // 0) Mapping order (매핑.xlsx -> ipqc_order_map.php):
    //    - 라벨은 문자열 그대로 사용
    //    - 라벨 파싱/분리/trim/정규화 금지
    $mappedKeys = [];
    $mappedSet  = [];
    if (!empty($orderLabels)) {
        foreach ($orderLabels as $lb) {
            if (is_array($lb)) continue;
            $lab = (string)$lb;
            if ($lab === '') continue;
            if (!empty($filterSet) && !isset($filterSet[$lab])) continue;
            if (isset($mappedSet[$lab])) continue;
            $mappedSet[$lab] = 1;
            $mappedKeys[] = $lab;
        }
    }

    
    // Optional column filter (OMM): if faiFilter is provided, keep only those columns.
    $filterSet = [];
    if (!empty($faiFilter)) { foreach ($faiFilter as $x) { $filterSet[(string)$x] = 1; } }
// 1) Distinct labels present in DB for this filter (actual DB)
    $presentKeys = [];
    $presentSet = [];
    $keySql = "
        SELECT DISTINCT m.fai AS fai
        FROM {$mTable} m
        JOIN {$hTable} h ON h.id = m.header_id
        {$whereSql}
          AND m.row_index BETWEEN 1 AND :cols
          AND m.fai IS NOT NULL AND m.fai <> ''
    ";
    $stmtK = $pdo->prepare($keySql);
    foreach ($params as $k => $v) $stmtK->bindValue($k, $v);
    $stmtK->bindValue(':cols', $colsN, PDO::PARAM_INT);
    $stmtK->execute();

    while ($r = $stmtK->fetch(PDO::FETCH_ASSOC)) {
        $f = (string)($r['fai'] ?? '');
        if ($f === '') continue;
        if (isset($presentSet[$f])) continue;
        $presentSet[$f] = 1;
        $presentKeys[] = $f;
    }

    // 2) Column meta: mapping columns first (to keep full mapping backbone),
    //    then append any DB-only columns (if any).
    $colMeta = []; // key => ['label'=>...]
    foreach ($mappedKeys as $k) {
        $colMeta[$k] = ['label' => $k];
    }
    foreach ($presentKeys as $k) {
        if (!isset($colMeta[$k])) $colMeta[$k] = ['label' => $k];
    }

    // 3) Column ordering: mapping order + extras
    if (!empty($mappedKeys)) {
        $extraKeys = [];
        foreach (array_keys($colMeta) as $k) {
            if (!isset($mappedSet[$k])) $extraKeys[] = $k;
        }
        // deterministic ordering for extras (rare)
        usort($extraKeys, 'ipqc_cmp_nat_export');
        $colKeys = array_merge($mappedKeys, $extraKeys);
    } else {
        $colKeys = array_keys($colMeta);
        usort($colKeys, 'ipqc_cmp_nat_export');
    }

    // Headers
    $headers = ['Part','Tool','Cavity','Date','라벨'];
    foreach ($colKeys as $ck) {
        $headers[] = (string)($colMeta[$ck]['label'] ?? $ck);
    }

    ipqc2_send_csv(ipqc_sanitize_filename("{$model}_OMM_JMP_Sheet.csv"));
    $out = fopen('php://output','w');
    fputcsv($out, $headers);

    // colKey -> column index
    $colIndex = [];
    foreach ($colKeys as $i => $ck) $colIndex[$ck] = $i;

    // 4) Stream measurements ordered by row key so we can flush row by row
    $sql = "
        SELECT h.part_name, h.tool, h.cavity, h.meas_date,
               m.row_index, m.value, m.fai
        FROM {$mTable} m
        JOIN {$hTable} h ON h.id = m.header_id
        {$whereSql}
          AND m.row_index BETWEEN 1 AND :cols
          AND m.fai IS NOT NULL AND m.fai <> ''
        ORDER BY h.part_name, h.tool, h.cavity, h.meas_date, m.row_index
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':cols', $colsN, PDO::PARAM_INT);
    $stmt->execute();

    $curKey = null;
    $curRow = null;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $date = (string)($r['meas_date'] ?? '');
        $date = ($date !== '') ? substr($date, 0, 10) : '';

        $idx = (int)($r['row_index'] ?? 0);
        if ($idx < 1) continue;

        $rk = (string)$r['part_name']."|".(string)$r['tool']."|".(string)$r['cavity']."|".$date."|".$idx;

        if ($curKey !== $rk) {
            if ($curRow !== null) fputcsv($out, $curRow);
            $curKey = $rk;
            $curRow = array_fill(0, 5 + count($colKeys), '');
            $curRow[0] = (string)$r['part_name'];
            $curRow[1] = (string)$r['tool'];
            $curRow[2] = (string)$r['cavity'];
            $curRow[3] = $date;
            $curRow[4] = "Data {$idx}";
        }

        $ck = (string)($r['fai'] ?? '');
        if ($ck === '') continue;

        if (isset($colIndex[$ck])) {
            $curRow[5 + $colIndex[$ck]] = $r['value'];
        }
    }
    if ($curRow !== null) fputcsv($out, $curRow);

    fclose($out);
    exit;
}

function ipqc2_export_aoi_csv(PDO $pdo, string $model, array $tools, array $fais, string $dateWhere, array $params, int $colsN, array $orderAoi): void {
    $hTable = "ipqc_aoi_header";
    $mTable = "ipqc_aoi_measurements";

    $where = [];
    $where[] = "h.meas_date IS NOT NULL";
    $where[] = $dateWhere;
    $where[] = "h.part_name = :model";
    $params[':model'] = $model;

    if (!empty($tools)) {
        $in = [];
        foreach ($tools as $i => $t) {
            $k = ":t{$i}";
            $in[] = $k;
            $params[$k] = $t;
        }
        $where[] = "h.tool IN (" . implode(",", $in) . ")";
    }

    

// optional AOI FAI filter: m.fai IN (...)
if (!empty($fais)) {
    $fais = array_values(array_unique(array_filter(array_map('trim', $fais), fn($x)=>$x!=='')));
    if (count($fais) > 200) $fais = array_slice($fais, 0, 200);
    $inF = [];
    foreach ($fais as $i => $fv) {
        $k = ":f{$i}";
        $inF[] = $k;
        $params[$k] = $fv;
    }
    if (!empty($inF)) {
        $where[] = "m.fai IN (" . implode(",", $inF) . ")";
    }
}
$where[] = "m.row_index BETWEEN 1 AND :cols";
    $params[':cols'] = $colsN;

    // ORDER BY: Tool/Date/Cavity + 매핑 순서(가능하면)
    $fieldExpr = '';
    $fieldParams = [];
    if (!empty($orderAoi)) {
        $ph = [];
        foreach ($orderAoi as $i => $kname) {
            $kk = ":k{$i}";
            $ph[] = $kk;
            $fieldParams[$kk] = $kname;
        }
        // Unknown keys last: CASE WHEN FIELD(..)=0 THEN big ELSE FIELD(..)
        $fieldExpr = "CASE WHEN FIELD(m.fai, " . implode(",", $ph) . ")=0 THEN 999999 ELSE FIELD(m.fai, " . implode(",", $ph) . ") END";
    }

    $sql = "
        SELECT
            h.part_name AS part_name,
            h.meas_date AS meas_date,
            h.tool AS tool,
            h.cavity AS cavity,
            m.fai AS fai,
            m.spc AS spc,
            m.row_index AS row_index,
            m.value AS value
        FROM {$hTable} h
        JOIN {$mTable} m ON m.header_id = h.id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY h.meas_date ASC, h.tool ASC, h.cavity ASC, " . ($fieldExpr !== '' ? "{$fieldExpr}, m.fai ASC" : "m.fai ASC") . ", m.spc ASC, m.row_index ASC
    ";

    $stmt = $pdo->prepare($sql);
    foreach (array_merge($params, $fieldParams) as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    $headers = ['Part','SPC','FAI','Tool','Cavity','Date'];
    for ($i=1; $i<=$colsN; $i++) $headers[] = "Data {$i}";

    ipqc2_send_csv(ipqc_sanitize_filename("{$model}_AOI_JMP_Sheet.csv"));
    $out = fopen('php://output','w');
    fputcsv($out, $headers);

    $curKey = null;
    $curRow = null;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $date = substr((string)$r['meas_date'], 0, 10);
        $k = $r['part_name']."|".(string)$r['spc']."|".(string)$r['fai']."|".(string)$r['tool']."|".(string)$r['cavity']."|".$date;

        if ($curKey !== $k) {
            if ($curRow !== null) {
                fputcsv($out, $curRow);
            }
            $curKey = $k;
            $curRow = array_fill(0, 6 + $colsN, '');
            $curRow[0] = (string)$r['part_name'];
            $curRow[1] = (string)$r['spc'];
            $curRow[2] = (string)$r['fai'];
            $curRow[3] = (string)$r['tool'];
            $curRow[4] = (string)$r['cavity'];
            $curRow[5] = $date;
        }

        $idx = (int)$r['row_index'];
        if ($idx >= 1 && $idx <= $colsN) {
            $curRow[5 + $idx] = $r['value'];
        }
    }
    if ($curRow !== null) fputcsv($out, $curRow);
    fclose($out);
    exit;
}

function ipqc2_export_pivot_csv(PDO $pdo, string $type, string $model, array $tools, string $dateWhere, array $params, int $colsN, array $colLabels): void {
    $typeU = strtoupper($type);
    $hTable = "ipqc_" . strtolower($type) . "_header";
    $mTable = "ipqc_" . strtolower($type) . "_measurements";

    $where = [];
    $where[] = "h.meas_date IS NOT NULL";
    $where[] = $dateWhere;
    $where[] = "h.part_name = :model";
    $params[':model'] = $model;

    if (!empty($tools)) {
        $in = [];
        foreach ($tools as $i => $t) {
            $k = ":t{$i}";
            $in[] = $k;
            $params[$k] = $t;
        }
        $where[] = "h.tool IN (" . implode(",", $in) . ")";
    }
    $where[] = "m.row_index BETWEEN 1 AND :cols";
    $params[':cols'] = $colsN;

    if ($typeU === 'OMM') {
        $sql = "
            SELECT
                h.part_name AS part_name,
                h.meas_date AS meas_date,
                h.tool AS tool,
                h.cavity AS cavity,
                m.row_index AS row_index,
                m.fai AS fai,
                m.spc AS spc,
                m.value AS value
            FROM {$hTable} h
            JOIN {$mTable} m ON m.header_id = h.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY h.meas_date ASC, h.tool ASC, h.cavity ASC, m.row_index ASC, m.fai ASC, m.spc ASC
        ";
    } else { // CMM
        $sql = "
            SELECT
                h.part_name AS part_name,
                h.meas_date AS meas_date,
                h.tool AS tool,
                h.cavity AS cavity,
                m.row_index AS row_index,
                m.point_no AS point_no,
                m.value AS value
            FROM {$hTable} h
            JOIN {$mTable} m ON m.header_id = h.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY h.meas_date ASC, h.tool ASC, h.cavity ASC, m.row_index ASC, m.point_no ASC
        ";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    // Headers
    $headers = ['Part','Tool','Cavity','Date','라벨'];
    $colIndex = [];
    if ($typeU === 'CMM') {
        foreach ($colLabels as $i => $lb) {
            if (is_array($lb)) {
                $raw = trim((string)($lb['point_no'] ?? ''));
                $disp = trim((string)($lb['label'] ?? ''));
                if ($raw === '' && $disp !== '') {
                    $raw = cmm_unmap_fai_name($model, $disp);
                }
                if ($disp === '' && $raw !== '') {
                    $disp = cmm_map_fai_name($model, $raw);
                }
            } else {
                $src = trim((string)$lb);
                $raw = cmm_unmap_fai_name($model, $src);
                if ($raw === '') $raw = $src;
                $disp = cmm_map_fai_name($model, $raw, $src);
                if ($disp === '') $disp = $src;
            }
            if ($disp === '') $disp = $raw;
            $headers[] = $disp;
            if ($raw !== '') $colIndex[$raw] = $i;
            if ($disp !== '') $colIndex[$disp] = $i;
        }
    } else {
        foreach ($colLabels as $i => $lb) {
            $headers[] = $lb;
            $colIndex[(string)$lb] = $i;
        }
    }

    ipqc2_send_csv(ipqc_sanitize_filename("{$model}_{$typeU}_JMP_Sheet.csv"));
    $out = fopen('php://output','w');
    fputcsv($out, $headers);

    $curKey = null;
    $curRow = null;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $date = substr((string)$r['meas_date'], 0, 10);
        $idx = (int)$r['row_index'];
        if ($idx < 1 || $idx > $colsN) continue;

        $rk = (string)$r['part_name']."|".(string)$r['tool']."|".(string)$r['cavity']."|".$date."|".$idx;

        if ($curKey !== $rk) {
            if ($curRow !== null) fputcsv($out, $curRow);
            $curKey = $rk;
            $curRow = array_fill(0, 5 + count($colLabels), '');
            $curRow[0] = (string)$r['part_name'];
            $curRow[1] = (string)$r['tool'];
            $curRow[2] = (string)$r['cavity'];
            $curRow[3] = $date;
            $curRow[4] = "Data {$idx}";
        }

        if ($typeU === 'OMM') {
            $lb = ipqc2_omm_col_label((string)$r['fai'], (string)$r['spc']);
            if ($lb !== '' && isset($colIndex[$lb])) {
                $curRow[5 + $colIndex[$lb]] = $r['value'];
            }
        } else {
            $rawPoint = trim((string)$r['point_no']);
            $dispPoint = ($rawPoint !== '') ? cmm_map_fai_name($model, $rawPoint) : '';
            if ($rawPoint !== '' && isset($colIndex[$rawPoint])) {
                $curRow[5 + $colIndex[$rawPoint]] = $r['value'];
            } elseif ($dispPoint !== '' && isset($colIndex[$dispPoint])) {
                $curRow[5 + $colIndex[$dispPoint]] = $r['value'];
            }
        }
    }
    if ($curRow !== null) fputcsv($out, $curRow);

    fclose($out);
    exit;
}

function ipqc2_export_run(string $mode): void {
    $typeU = ipqc2_norm_type((string)($_GET['type'] ?? 'OMM'));
    if ($typeU === 'OQC') {
        ipqc_export_fail(400, "OQC export는 이 엔드포인트에서 지원하지 않습니다.");
    }

    $model = trim((string)($_GET['model'] ?? $_GET['part'] ?? ''));
    if ($model === '') ipqc_export_fail(400, "필수 파라미터 누락: model");

    $tools = ipqc2_get_list('tools');
    $fais  = ipqc2_get_list('fai');
    $years = array_map('intval', ipqc2_get_list('years'));
    $months = array_map('intval', ipqc2_get_list('months'));

    $pageDate = trim((string)($_GET['page_date'] ?? ''));
    $pageDatesRaw = trim((string)($_GET['page_dates'] ?? '')); // comma-separated YYYY-MM-DD list (optional)
    $pageDates = [];
    if ($pageDatesRaw !== '') {
        foreach (preg_split('/\s*,\s*/', $pageDatesRaw) as $d) {
            $d = trim((string)$d);
            if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $pageDates[] = $d;
        }
        $pageDates = array_values(array_unique($pageDates));
    }
    if (!empty($pageDates) && $pageDate === '') { $pageDate = $pageDates[0]; }

    $colsN = (int)($_GET['cols'] ?? 0);
    if ($colsN <= 0) {
        $colsN = ($typeU === 'AOI') ? 16 : 3;
    }
    if ($colsN > 120) $colsN = 120;

    $pdo = dp_get_pdo();

    // date where
    $params = [];
    if ($mode === 'page_date' && $pageDate !== '') {
        $dateWhere = ipqc2_build_day_range($pageDate, $params);
    } else {
        // Build pairs
        $pairs = [];
        $years = array_values(array_filter($years, fn($y)=>$y>0));
        $months = array_values(array_filter($months, fn($m)=>$m>=1 && $m<=12));
        if (!empty($years)) {
            if (!empty($months)) {
                foreach ($years as $yy) foreach ($months as $mm) $pairs[] = [$yy,$mm];
            } else {
                foreach ($years as $yy) $pairs[] = [$yy,0];
            }
        } else {
            // fallback: current month
            $yy = (int)date('Y'); $mm = (int)date('n');
            $pairs[] = [$yy,$mm];
        }
        $dateWhere = ipqc2_build_date_or($pairs, $params);
    }

    // order map
    $orderMap = ipqc2_load_order_map();
    $mk = ipqc2_model_to_mapkey($model);

    if ($typeU === 'AOI') {
        $orderAoi = [];
        if ($mk !== '' && isset($orderMap[$mk]['AOI']) && is_array($orderMap[$mk]['AOI'])) $orderAoi = $orderMap[$mk]['AOI'];
        ipqc2_export_aoi_csv($pdo, $model, $tools, $fais, $dateWhere, $params, $colsN, $orderAoi);
    }

    if ($typeU === 'OMM') {
        $labels = [];
        if ($mk !== '' && isset($orderMap[$mk]['OMM']) && is_array($orderMap[$mk]['OMM'])) $labels = $orderMap[$mk]['OMM'];
        // Do not hard-fail when mapping is missing; export should still match viewer by using actual keys.
        ipqc2_export_omm_pivot_csv($pdo, $model, $tools, $dateWhere, $params, $colsN, $labels, $fais);
    }

    if ($typeU === 'CMM') {
        $labels = [];
        if ($mk !== '' && isset($orderMap[$mk]['CMM']) && is_array($orderMap[$mk]['CMM'])) $labels = $orderMap[$mk]['CMM'];
        if (empty($labels)) ipqc_export_fail(400, "CMM 컬럼 매핑이 없습니다(ipqc_order_map.php).");

        $colMeta = [];
        $seenRaw = [];
        foreach ($labels as $lb) {
            $disp = (string)$lb;
            if ($disp === '') continue;
            $raw = cmm_unmap_fai_name($model, $disp);
            if ($raw === '' || isset($seenRaw[$raw])) continue;
            $seenRaw[$raw] = 1;
            $colMeta[] = ['point_no' => $raw, 'label' => cmm_map_fai_name($model, $raw)];
        }
        if (!empty($fais)) {
            $want = array_fill_keys($fais, 1);
            $colMeta = array_values(array_filter($colMeta, function($m) use ($want){
                $raw = (string)($m['point_no'] ?? '');
                $disp = (string)($m['label'] ?? $raw);
                return isset($want[$raw]) || isset($want[$disp]);
            }));
        }
        ipqc2_export_pivot_csv($pdo, 'cmm', $model, $tools, $dateWhere, $params, $colsN, $colMeta);
    }

    ipqc_export_fail(400, "지원하지 않는 type");
}

// entry
$mode = (string)($_GET['mode'] ?? 'all');
ipqc_export_run($mode);