<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }


session_start();
@ini_set('memory_limit', '2048M');
$ROOT = JTMES_ROOT;
// JMP Assist (AOI / OMM / CMM)
// - JMP Assist UI 흐름(측정타입/모델/툴/년도/월 선택 -> 해당 데이터 모아서 표시) 기반
// - DB 저장/불러오기, Data Check, QA출하정보, 설정 탭은 폐기 (뷰어만)

require_once $ROOT . '/config/dp_config.php';
require_once $ROOT . '/lib/auth_guard.php';

$EMBED = (isset($_GET['embed']) && $_GET['embed'] === '1');

// 로그인 가드: embed여부와 무관하게 항상 체크(세션 기반)
if (function_exists('dp_auth_guard')) {
  dp_auth_guard();
} elseif (function_exists('require_login')) {
  require_login();
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// -------------------- UI option cache helpers --------------------
// 목적: DB 순간 끊김/예외/지연로 인해 Tool/Year 옵션이 빈 배열로 떨어지면서
//       멀티셀렉트가 "빈 목록"으로 보이는 현상을 완화한다.
function ipqc_sess_cache_get(string $key, int $maxAgeSec = 600) {
  if (!isset($_SESSION['__ipqc_cache']) || !is_array($_SESSION['__ipqc_cache'])) return null;
  if (!isset($_SESSION['__ipqc_cache'][$key])) return null;
  $ent = $_SESSION['__ipqc_cache'][$key];
  if (!is_array($ent) || !isset($ent['t']) || !array_key_exists('v', $ent)) return null;
  if ((time() - (int)$ent['t']) > $maxAgeSec) return null;
  return $ent['v'];
}
function ipqc_sess_cache_set(string $key, $val): void {
  if (!isset($_SESSION['__ipqc_cache']) || !is_array($_SESSION['__ipqc_cache'])) $_SESSION['__ipqc_cache'] = [];
  $_SESSION['__ipqc_cache'][$key] = ['t'=>time(), 'v'=>$val];
}
function ipqc_norm_str_list($arr): array {
  if (!is_array($arr)) return [];
  $map = [];
  foreach ($arr as $v) {
    $s = trim((string)$v);
    if ($s === '') continue;
    $map[$s] = 1;
  }
  $out = array_keys($map);
  usort($out, 'ipqc_cmp_nat');
  return $out;
}
function ipqc_default_tools(): array {
  // JMP Assist 기준(현장 Tool set): I/O 없음
  return ['A','B','C','D','E','F','G','H','J','K','L','M','N','P','Q'];
}

function ipqc_fetch_tools_for_type_model(PDO $pdo, string $type, string $model, array $TYPE_MAP, ?array $oqcSchema = null): array {
  $type = strtoupper(trim($type));
  $model = trim($model);
  $tools = ipqc_default_tools();

  if ($type === 'OQC') {
    $schema = (is_array($oqcSchema) && !empty($oqcSchema)) ? $oqcSchema : oqc_detect_schema($pdo);
    if (!is_array($schema) || empty($schema['ok'])) return $tools;

    $hT = $schema['headerTable'];
    $partCol = $schema['partCol'];
    $srcCol = $schema['sourceCol'];
    $cacheKey = 'ipqc_tools|OQC|' . ($model !== '' ? $model : '__ALL__');

    try {
      $where = "{$srcCol} IS NOT NULL AND {$srcCol} <> ''";
      $params = [];
      if ($model !== '') {
        $where .= " AND {$partCol} = ?";
        $params[] = $model;
      }

      $toolsTmp = null;
      if (!empty($schema['toolCol'])) {
        $toolCol = $schema['toolCol'];
        $stmt = $pdo->prepare("SELECT DISTINCT {$toolCol} AS t FROM {$hT} WHERE {$where} ORDER BY t");
        $stmt->execute($params);
        $toolsTmp = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
      } else {
        $tcCol = $schema['tcCol'];
        $stmt = $pdo->prepare("SELECT DISTINCT {$tcCol} AS tc FROM {$hT} WHERE {$where}");
        $stmt->execute($params);
        $tmap = [];
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        foreach ($rows as $tc) {
          [$t, $c] = oqc_parse_tool_cavity_from_tc($tc);
          if ($t !== '') $tmap[$t] = 1;
        }
        $toolsTmp = array_keys($tmap);
      }

      $toolsTmp = ipqc_norm_str_list($toolsTmp);
      if (!empty($toolsTmp)) {
        $tools = $toolsTmp;
        ipqc_sess_cache_set($cacheKey, $tools);
      } else {
        $cached = ipqc_sess_cache_get($cacheKey, 3600);
        if (is_array($cached) && !empty($cached)) $tools = $cached;
      }
    } catch (Throwable $e) {
      $cached = ipqc_sess_cache_get($cacheKey, 3600);
      if (is_array($cached) && !empty($cached)) $tools = $cached;
    }

    return $tools;
  }

  if (!isset($TYPE_MAP[$type]) || !is_array($TYPE_MAP[$type])) return $tools;
  $headerTable = (string)($TYPE_MAP[$type]['header'] ?? '');
  if ($headerTable === '') return $tools;

  $cacheKey = 'ipqc_tools|' . $type . '|' . ($model !== '' ? $model : '__ALL__');
  try {
    $where = "meas_date IS NOT NULL";
    $params = [];
    if ($model !== '') {
      $where .= " AND part_name = :p";
      $params[':p'] = $model;
    }

    $stmt = $pdo->prepare("SELECT DISTINCT tool FROM {$headerTable} WHERE {$where} ORDER BY tool");
    $stmt->execute($params);
    $toolsTmp = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $toolsTmp = ipqc_norm_str_list($toolsTmp);

    if (!empty($toolsTmp)) {
      $tools = $toolsTmp;
      ipqc_sess_cache_set($cacheKey, $tools);
    } else {
      $cached = ipqc_sess_cache_get($cacheKey, 3600);
      if (is_array($cached) && !empty($cached)) $tools = $cached;
    }
  } catch (Throwable $e) {
    $cached = ipqc_sess_cache_get($cacheKey, 3600);
    if (is_array($cached) && !empty($cached)) $tools = $cached;
  }

  return $tools;
}
// ---------------------------------------------------------------


function _num_or_null($v) {
  if ($v === null) return null;
  $v = trim((string)$v);
  if ($v === '') return null;
  // normalize possible commas
  $v = str_replace(',', '', $v);
  return is_numeric($v) ? (float)$v : null;
}

// NG 색상 분리: 상한(USL 초과) / 하한(LSL 미만)
function ng_class($val, $lsl, $usl) {
  $x = _num_or_null($val);
  if ($x === null) return '';
  $lo = _num_or_null($lsl);
  $hi = _num_or_null($usl);
  if ($lo !== null && $x < $lo) return 'ng-lo';
  if ($hi !== null && $x > $hi) return 'ng-hi';
  return '';
}


function is_numeric_leading(string $s): bool {
  return preg_match('/^\s*\d/', $s) === 1;
}

function omm_col_label(string $keyName, ?string $spc): string {
  // OMM: DB에 저장된 fai 라벨을 그대로 사용한다. (SPC는 표시용 부가필드)
  return (string)$keyName;
}


// --- OMM label/key normalization (viewer/export consistency) ---
function ipqc_norm_omm_fai($v): string {
  // OMM: 라벨 가공 금지(DB 그대로)
  return (string)$v;
}
function ipqc_norm_omm_spc($v): string {
  // OMM: SPC는 부가 필드(표시용). 가공/분리/강제 금지.
  return (string)$v;
}

// parse mapping label like "FAI 129-1 / SPC BD" -> ["129-1","BD"]
// tolerant to missing slashes/spaces and inconsistent formatting
function ipqc_parse_omm_map_label(string $lab): array {
  // OMM: 라벨 파싱/분리 금지. (과거 호환용: 호출되더라도 '원문'만 돌려준다)
  return [$lab, ''];
}




// OMM 컬럼 정렬: 숫자로 시작하는 FAI류 먼저, 그 다음 자연정렬
function ipqc_cmp_omm_cols(string $aKey, string $bKey): int {
  // OMM: DB 라벨 그대로 정렬(가공/접두어 보정 없음)
  return ipqc_cmp_nat($aKey, $bKey);
}

function ipqc_cmp_nat(string $a, string $b): int {
  $c = strnatcasecmp($a, $b);
  return $c !== 0 ? $c : strcmp($a, $b);
}



/* =========================
 * OQC (Py JMP Assist spec)
 * - Date: ONLY from source_file (YYYYMMDD / YYMMDD with optional separators)
 * - Row: Part, Tool, Cavity, Date, Label(Data N)
 * - Col: point_no
 * - Value: measurements.value
 * ========================= */

function oqc_excel_col_to_num($col) {
  if ($col === null) return null;
  $s = strtoupper(trim((string)$col));
  if ($s === '') return null;
  // allow numeric already
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
  $base = oqc_excel_col_to_num('AK'); // AK = Data 1
  $n = oqc_excel_col_to_num($excelCol);
  if ($n === null) return null;
  $idx = $n - $base + 1;
  return ($idx >= 1 && $idx <= 500) ? $idx : null;
}

// return "YYYYMMDD" or null
function oqc_parse_ymd_from_source_file($src) {
  $s = (string)$src;
  if ($s === '') return null;

  // 1) YYYYMMDD
  if (preg_match('/(20\d{2})[^\d]?([01]\d)[^\d]?([0-3]\d)/', $s, $m)) {
    return $m[1] . $m[2] . $m[3];
  }
  // 2) YYMMDD -> assume 20YY (project range)
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
  if (preg_match('/^\d+\s*CAV$/', $s)) return str_replace(' ', '', $s);
  // e.g. "1CAV", "2 CAV"
  $s = str_replace(' ', '', $s);
  if (preg_match('/^\d+CAV$/', $s)) return $s;
  return $s;
}

// parse tc like "A#1", "B#2CAV", "ToolA#1", "A-1CAV"
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
  // POSIX ERE (MySQL REGEXP)
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
  $mTable = '';
  foreach ($headerCandidates as $t) {
    if (oqc_table_exists($pdo, $t)) { $hTable = $t; break; }
  }
  if ($hTable === '') return ['ok'=>false, 'error'=>'OQC header table not found (tried: '.implode(',',$headerCandidates).')'];

  $hCols = oqc_table_columns($pdo, $hTable);
  $hColsL = array_map('strtolower', $hCols);

  // id col
  $idCol = in_array('id', $hColsL, true) ? $hCols[array_search('id',$hColsL,true)]
        : (in_array('header_id', $hColsL, true) ? $hCols[array_search('header_id',$hColsL,true)] : $hCols[0]);

  // part col
  $partCol = '';
  foreach (['part_name','model_name','part','model'] as $c) {
    $i = array_search($c, $hColsL, true);
    if ($i !== false) { $partCol = $hCols[$i]; break; }
  }
  if ($partCol === '') return ['ok'=>false,'error'=>"OQC header missing part_name/model column in {$hTable}"];

  // source_file
  $sourceCol = '';
  foreach (['source_file','src_file','source','filename','file_name'] as $c) {
    $i = array_search($c, $hColsL, true);
    if ($i !== false) { $sourceCol = $hCols[$i]; break; }
  }
  if ($sourceCol === '') return ['ok'=>false,'error'=>"OQC header missing source_file column in {$hTable}"];

  // excel_col
  $excelCol = '';
  foreach (['excel_col','excelcol','col','column'] as $c) {
    $i = array_search($c, $hColsL, true);
    if ($i !== false) { $excelCol = $hCols[$i]; break; }
  }
  if ($excelCol === '') return ['ok'=>false,'error'=>"OQC header missing excel_col column in {$hTable}"];

  // tool/cavity/tc
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

  // measurements table detect by required cols
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
// Memphis Z Carrier(CMM) 전용: point_no(숫자) -> 라벨 (JMP Assist.py와 동일)
// - DB에 point_no가 556,557...처럼 숫자로 들어오는 경우, 뷰어에서는 실제 라벨로 보여주기 위해 변환한다.
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
    if ($v2 !== '' && preg_match('/^\d+$/', $v2) && $v1 !== '') return $v1; // fallback
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
// Load order map (Excel/JMP column order) generated from 매핑.xlsx
$IPQC_ORDER_MAP = [];
$__orderMapFile = $ROOT . '/lib/ipqc_order_map.php';
if (!is_file($__orderMapFile)) {
  $__orderMapFile = $ROOT . '/ipqc_order_map.php';
}
if (is_file($__orderMapFile)) {
  $tmp = include $__orderMapFile;
  if (is_array($tmp)) $IPQC_ORDER_MAP = $tmp;
}

// AOI FAI list map for client-side (model select without submit)
$IPQC_AOI_MAP = [];
foreach ($IPQC_ORDER_MAP as $__mk2 => $__mm2) {
  if (is_array($__mm2) && isset($__mm2['AOI']) && is_array($__mm2['AOI'])) {
    $IPQC_AOI_MAP[$__mk2] = array_values($__mm2['AOI']);
  }
}

function ipqc_model_to_mapkey(string $model): string {
  $m = trim($model);
  if ($m === '') return '';
  if (preg_match('/z\s*[-_]?\s*stopper/i', $m)) return 'ZSTOPPER';
  if (preg_match('/z\s*[-_]?\s*carrier/i', $m)) return 'ZCARRIER';
  if (preg_match('/y\s*[-_]?\s*carrier/i', $m)) return 'YCARRIER';
  if (preg_match('/x\s*[-_]?\s*carrier/i', $m)) return 'XCARRIER';
  if (preg_match('/ir\s*[-_]?\s*base/i', $m)) return 'IRBASE';
  return '';
}



$pdo = dp_get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- AJAX: OQC point_no list (for ms-fai in OQC) ----------
// 목적: OQC는 mapping.xlsx가 없는 경우가 많으므로, UI에서 열(=point_no) 선택 목록을 DB에서 즉시 조회한다.
// 절대 규칙: point_no 라벨은 DB 원문 그대로 사용 (trim/정리/파싱 금지)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'oqc_points') {
  header('Content-Type: application/json; charset=utf-8');

  $uiModel = (string)($_GET['model'] ?? '');
  $items = [];
  $err = null;
  $usedModel = null;

  // UI 모델명은 변경하지 않는다. (표시용) / 조회는 DB model_name에 맞춰 alias 후보를 만든다.
  $aliasMap = [
    'Memphis IR BASE'   => ['MEM-IR-BASE'],
    'Memphis X Carrier' => ['MEM-X-CARRIER'],
    'Memphis Y Carrier' => ['MEM-Y-CARRIER'],
    'Memphis Z Carrier' => ['MEM-Z-CARRIER'],
    'Memphis Z Stopper' => ['MEM-Z-STOPPER','MEM-Z-STOPPERPPER'], // sql 오타 케이스 fallback
    // 이미 DB 키가 넘어오는 경우도 허용
    'MEM-IR-BASE'   => ['MEM-IR-BASE'],
    'MEM-X-CARRIER' => ['MEM-X-CARRIER'],
    'MEM-Y-CARRIER' => ['MEM-Y-CARRIER'],
    'MEM-Z-CARRIER' => ['MEM-Z-CARRIER'],
    'MEM-Z-STOPPER' => ['MEM-Z-STOPPER','MEM-Z-STOPPERPPER'],
  ];

  $cands = [];
  if ($uiModel !== '') $cands[] = $uiModel;
  if (isset($aliasMap[$uiModel])) $cands = array_merge($cands, $aliasMap[$uiModel]);

  // heuristic: "Memphis IR BASE" -> "MEM-IR-BASE"
  if (stripos($uiModel, 'Memphis') === 0) {
    $rest = trim(substr($uiModel, strlen('Memphis')));
    if ($rest !== '') {
      $rest = preg_replace('/\s+/', '-', $rest);
      $rest = str_replace('_', '-', $rest);
      $rest = strtoupper($rest);
      $cands[] = 'MEM-' . $rest;
    }
  }

  $cands = array_values(array_unique(array_filter($cands, function($v){ return (string)$v !== ''; })));

  try {
    // 1) 가장 빠른 경로: oqc_model_point 테이블 (모델별 point_no 사전 맵)
    if (!empty($cands)) {
      $st = $pdo->prepare("SELECT point_no FROM oqc_model_point WHERE model_name=? AND active=1 ORDER BY sort_order");
      foreach ($cands as $cand) {
        try {
          $st->execute([$cand]);
          $rows = $st->fetchAll(PDO::FETCH_COLUMN, 0);
          if (is_array($rows) && !empty($rows)) {
            $usedModel = $cand;
            foreach ($rows as $pv) {
              if ($pv === null) continue;
              $sv = (string)$pv; // DO NOT trim
              if ($sv === '') continue;
              $items[] = $sv;
            }
            break;
          }
        } catch (Throwable $e2) {
          // per-candidate ignore; fall through
          $err = $e2->getMessage();
        }
      }
    }
  } catch (Throwable $e) {
    // 테이블이 없거나 권한 문제 등일 수 있음 -> fallback
    $err = $e->getMessage();
  }

  // 2) fallback: 기존 OQC 스키마 detect + 실제 측정 테이블 DISTINCT
  if (empty($items) && $uiModel !== '') {
    try {
      $sch = oqc_detect_schema($pdo);
      if (is_array($sch) && !empty($sch['ok'])) {
        $hT = $sch['headerTable'];
        $mT = $sch['measTable'];
        $idCol = $sch['idCol'];
        $partCol = $sch['partCol'];
        $srcCol = $sch['sourceCol'];
        $fkCol = $sch['measFkCol'];
        $ptCol = $sch['measPointCol'];

        foreach ($cands as $cand) {
          // 2-1) source_file 있는 헤더만(일반 케이스)
          $sql = "SELECT DISTINCT m.{$ptCol} AS pt
                  FROM {$mT} m
                  JOIN {$hT} h ON h.{$idCol} = m.{$fkCol}
                  WHERE h.{$partCol} = ? AND h.{$srcCol} IS NOT NULL AND h.{$srcCol} <> ''";
          $st = $pdo->prepare($sql);
          $st->execute([$cand]);
          $pts = $st->fetchAll(PDO::FETCH_COLUMN, 0);
          if (!is_array($pts)) $pts = [];

          foreach ($pts as $pv) {
            if ($pv === null) continue;
            $sv = (string)$pv; // DO NOT trim
            if ($sv === '') continue;
            $items[] = $sv;
          }

          // 2-2) fallback: source_file 조건 때문에 0건이 되는 경우 대비
          if (empty($items)) {
            $sql2 = "SELECT DISTINCT m.{$ptCol} AS pt
                     FROM {$mT} m
                     JOIN {$hT} h ON h.{$idCol} = m.{$fkCol}
                     WHERE h.{$partCol} = ?";
            $st2 = $pdo->prepare($sql2);
            $st2->execute([$cand]);
            $pts2 = $st2->fetchAll(PDO::FETCH_COLUMN, 0);
            if (is_array($pts2)) {
              foreach ($pts2 as $pv) {
                if ($pv === null) continue;
                $sv = (string)$pv; // DO NOT trim
                if ($sv === '') continue;
                $items[] = $sv;
              }
            }
          }

          if (!empty($items)) {
            $usedModel = $cand;
            $items = array_values(array_unique($items));
            usort($items, 'ipqc_cmp_nat');
            break;
          }
        }
      } else {
        if ($err === null) $err = is_array($sch) ? ($sch['error'] ?? 'OQC schema detect failed') : 'OQC schema detect failed';
      }
    } catch (Throwable $e3) {
      if ($err === null) $err = $e3->getMessage();
    }
  }

  echo json_encode([
    'ok' => true,
    'model' => $uiModel,
    'used_model' => $usedModel,
    'items' => $items,
    'error' => $err,
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$TYPE_MAP = [
  'AOI' => ['label' => 'AOI',     'header' => 'ipqc_aoi_header', 'meas' => 'ipqc_aoi_measurements', 'res' => 'ipqc_aoi_result', 'key_col' => 'fai',      'has_spc' => true,  'data_cols' => 16],
  'OMM' => ['label' => 'OMM', 'header' => 'ipqc_omm_header', 'meas' => 'ipqc_omm_measurements', 'res' => 'ipqc_omm_result', 'key_col' => 'fai',      'has_spc' => false,  'data_cols' => 3],
  'CMM' => ['label' => 'CMM', 'header' => 'ipqc_cmm_header', 'meas' => 'ipqc_cmm_measurements', 'res' => 'ipqc_cmm_result', 'key_col' => 'point_no', 'has_spc' => false, 'data_cols' => 3],
  'OQC' => ['label' => 'OQC', 'header' => '__OQC__', 'meas' => '__OQC__', 'res' => '', 'key_col' => 'point_no', 'has_spc' => false, 'data_cols' => 200],
];


// ---------- AJAX: Tool list (for ms-tools realtime refresh) ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tools') {
  header('Content-Type: application/json; charset=utf-8');

  $uiType = strtoupper(trim((string)($_GET['type'] ?? '')));
  $uiModel = (string)($_GET['model'] ?? '');
  $items = [];
  $err = null;

  try {
    if ($uiType === 'OQC') {
      $schema = oqc_detect_schema($pdo);
      if (!is_array($schema) || empty($schema['ok'])) {
        $err = is_array($schema) ? (string)($schema['error'] ?? 'OQC schema detect failed') : 'OQC schema detect failed';
      } else {
        $items = ipqc_fetch_tools_for_type_model($pdo, $uiType, $uiModel, $TYPE_MAP, $schema);
      }
    } elseif (isset($TYPE_MAP[$uiType])) {
      $items = ipqc_fetch_tools_for_type_model($pdo, $uiType, $uiModel, $TYPE_MAP, null);
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }

  echo json_encode([
    'ok' => true,
    'type' => $uiType,
    'model' => $uiModel,
    'items' => array_values(is_array($items) ? $items : []),
    'error' => $err,
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$type = strtoupper($_GET['type'] ?? 'OMM');
if (!isset($TYPE_MAP[$type])) $type = 'OMM';

$model = trim($_GET['model'] ?? '');

// Build order index for current model/type (if mapping exists)
$orderList = [];
$orderIndex = [];
$__mk = ipqc_model_to_mapkey($model);
if ($__mk !== '' && isset($IPQC_ORDER_MAP[$__mk]) && is_array($IPQC_ORDER_MAP[$__mk])) {
  $__list = $IPQC_ORDER_MAP[$__mk][$type] ?? [];
  if (is_array($__list)) {
    $orderList = $__list;
    $i = 0;
    foreach ($__list as $__lab) {
      if (is_array($__lab)) continue;
      $__lab = (string)$__lab;
      if ($__lab !== '' && !isset($orderIndex[$__lab])) $orderIndex[$__lab] = $i;
      $i++;
    }
  }
}

// CMM도 매핑 순서를 적용 (매핑.xlsx 기반)
// if ($type === 'CMM') { $orderIndex = []; }
// 년도(복수 선택)
$currentYear = (int)date('Y');
$yearsSel = $_GET['years'] ?? [];
if (!is_array($yearsSel)) $yearsSel = [$yearsSel];
$yearsSel = array_values(array_unique(array_filter(array_map('intval', $yearsSel), function($y){
  return $y >= 2000 && $y <= 2100;
})));

// 하위호환: year= (단일)
$yearLegacy = (int)($_GET['year'] ?? 0);
if (!$yearsSel && $yearLegacy >= 2000 && $yearLegacy <= 2100) $yearsSel = [$yearLegacy];

// 기본: 현재 년도 1개
if (!$yearsSel) $yearsSel = [$currentYear];
sort($yearsSel);

// Tool: 다중 선택(체크박스)
$toolsSel = $_GET['tools'] ?? [];
if (!is_array($toolsSel)) $toolsSel = [$toolsSel];
$toolsSel = array_values(array_unique(array_filter(array_map('trim', $toolsSel), function($t){
  return $t !== '' && strlen($t) <= 16;
})));

// 하위호환: tool= (단일)
$toolLegacy = trim($_GET['tool'] ?? '');
if (!$toolsSel && $toolLegacy !== '') $toolsSel = [$toolLegacy];
// FAI(복수선택):
// - AOI: 행 필터(m.fai IN ...)
// - OMM/CMM: 열(컬럼) 필터(표시/엑셀에서 선택된 컬럼만 남김)
// - 라벨은 DB/매핑 문자열 그대로 사용(공백/슬래시 포함). trim/정리/분리 금지.
// - OMM/CMM에는 ALL 옵션(__ALL__)이 있으며, 기본 선택은 ALL이 아님.
$faiSel = $_GET['fai'] ?? [];
if (!is_array($faiSel)) $faiSel = [$faiSel];
$tmp = [];
foreach ($faiSel as $v) {
  $s = (string)$v;
  if ($s === '') continue;
  $tmp[] = $s;
}
$faiSel = array_values(array_unique($tmp));
if (count($faiSel) > 500) $faiSel = array_slice($faiSel, 0, 500);

// OMM/CMM: ALL 토큰은 '필터 없음(전체 컬럼)' 의미 (배타)
if (($type === 'OMM' || $type === 'CMM' || $type === 'OQC') && in_array('__ALL__', $faiSel, true)) {
  $faiSel = ['__ALL__'];
}

// ✅ AOI: FAI는 1개 이상 선택 필수(부하 감소)
if ($type === 'AOI') {
  if (empty($faiSel)) {
    // 매핑(순서) 기반으로 첫 항목을 기본 선택
    if (!empty($orderList) && is_array($orderList)) {
      $first = null;
      foreach ($orderList as $__lab) {
        if (is_array($__lab)) continue;
        $__lab = (string)$__lab; // trim 금지
        if ($__lab !== '') { $first = $__lab; break; }
      }
      if ($first !== null) $faiSel = [$first];
    }
  }
}

// ✅ OMM/CMM: 기본 선택은 ALL이 아님 (선택이 없으면 첫 항목 1개 자동 선택)
if (($type === 'OMM' || $type === 'CMM' || $type === 'OQC')) {
  if (empty($faiSel)) {
    if (!empty($orderList) && is_array($orderList)) {
      $first = null;
      foreach ($orderList as $__lab) {
        if (is_array($__lab)) continue;
        $__lab = (string)$__lab;
        if ($__lab !== '' && $__lab !== '__ALL__') { $first = $__lab; break; }
      }
      if ($first !== null) $faiSel = [$first];
    }
  }
}

// 월: 다중 선택(체크박스)
$months = $_GET['months'] ?? [];
if (!is_array($months)) $months = [$months];
$months = array_values(array_unique(array_filter(array_map('intval', $months), function($m){
  return $m >= 1 && $m <= 12;
})));

// 하위호환: month= (단일)
$monthLegacy = (int)($_GET['month'] ?? 0);
if (!$months && $monthLegacy >= 1 && $monthLegacy <= 12) $months = [$monthLegacy];

// 아무것도 체크 안 했으면 기본: 현재 월 1개 (단, 사용자가 '해제'로 비운 경우 유지)
$monthsPresent = ((string)($_GET['months_present'] ?? '') === '1');
if (!$months && !$monthsPresent) $months = [(int)date('n')];


// Paging (브라우저 속도용: 날짜 단위 페이지)
// - page_date=YYYY-MM-DD 형태로 날짜를 선택 (UI에서 선택)
// - 하위호환: page=숫자(1부터)면 해당 순번 날짜로 선택
$pageDate = trim((string)($_GET['page_date'] ?? ''));

// Ctrl+Click multi-date selection (comma-separated YYYY-MM-DD)
$pageDatesSel = $_GET['page_dates'] ?? [];
if (!is_array($pageDatesSel)) $pageDatesSel = preg_split('/\s*,\s*/', trim((string)$pageDatesSel), -1, PREG_SPLIT_NO_EMPTY);
if (!is_array($pageDatesSel)) $pageDatesSel = [];
$pageDatesSel = array_values(array_unique(array_filter(array_map('trim', $pageDatesSel), function($d){
  return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
})));


$pageAll  = ((string)($_GET['page_all'] ?? '') === '1');
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;


$cfg = $TYPE_MAP[$type];
$headerTable = $cfg['header'];
$measTable   = $cfg['meas'];
$resTable    = $cfg['res'];
$keyCol      = $cfg['key_col'];
$hasSpc      = $cfg['has_spc'];
$dataCols    = (int)$cfg['data_cols'];

// options: model list from selected type
$OQC_SCHEMA = null;
$OQC_SCHEMA_ERR = null;

if ($type === 'OQC') {
  // OQC: date is derived ONLY from source_file (no meas_date usage)
  $models = [];
  $tools = [];
  $yearsAvail = [];

  try {
    $OQC_SCHEMA = oqc_detect_schema($pdo);
  } catch (Throwable $e) {
    $OQC_SCHEMA = ['ok'=>false, 'error'=>$e->getMessage()];
  }

  if (is_array($OQC_SCHEMA) && !empty($OQC_SCHEMA['ok'])) {
    $hT = $OQC_SCHEMA['headerTable'];
    $partCol = $OQC_SCHEMA['partCol'];
    $srcCol  = $OQC_SCHEMA['sourceCol'];

    // models
    try {
      $sql = "SELECT DISTINCT {$partCol} AS p FROM {$hT} WHERE {$srcCol} IS NOT NULL AND {$srcCol} <> '' ORDER BY p";
      $models = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0);
      if (!is_array($models)) $models = [];
    } catch (Throwable $e) {
      $models = [];
    }

    // 모델은 단일 선택이며 항상 1개가 선택되어야 한다. ('전체/빈값' 금지)
    // 전달된 model이 현재 타입의 목록에 없으면 첫 항목으로 강제한다.
    if ($model === '' || (!empty($models) && !in_array($model, $models, true))) {
      if (!empty($models)) $model = (string)$models[0];
    }

    // 선택된 모델 기준으로 매핑(order_map)을 다시 계산 (model 강제 변경 시 정합성 유지)
    $orderList = [];
    $orderIndex = [];
    $__mk = ipqc_model_to_mapkey($model);
    if ($__mk !== '' && isset($IPQC_ORDER_MAP[$__mk]) && is_array($IPQC_ORDER_MAP[$__mk])) {
      $__list = $IPQC_ORDER_MAP[$__mk][$type] ?? [];
      if (is_array($__list)) {
        $orderList = $__list;
        $i = 0;
        foreach ($__list as $__lab) {
          if (is_array($__lab)) continue;
          $__lab = (string)$__lab;
          if ($__lab !== '' && !isset($orderIndex[$__lab])) $orderIndex[$__lab] = $i;
          $i++;
        }
      }
    }

    // tools (Tool only; cavity is displayed separately)
    $tools = ipqc_fetch_tools_for_type_model($pdo, 'OQC', $model, $TYPE_MAP, $OQC_SCHEMA);

    // years available from source_file
    try {
      $where = "{$srcCol} IS NOT NULL AND {$srcCol} <> ''";
      $params = [];
      if ($model !== '') { $where .= " AND {$partCol} = ?"; $params[] = $model; }

      // apply tool filter if selected (best-effort)
      $toolWhere = '';
      $toolParams = [];
      if (!empty($toolsSel)) {
        if (!empty($OQC_SCHEMA['toolCol'])) {
          $toolCol = $OQC_SCHEMA['toolCol'];
          $in = implode(',', array_fill(0, count($toolsSel), '?'));
          $toolWhere = " AND {$toolCol} IN ({$in})";
          $toolParams = array_values($toolsSel);
        } else {
          $tcCol = $OQC_SCHEMA['tcCol'];
          $likes = [];
          foreach (array_values($toolsSel) as $t) { $likes[] = "{$tcCol} LIKE ?"; $toolParams[] = strtoupper(trim($t))."#%"; }
          if (!empty($likes)) $toolWhere = " AND (" . implode(" OR ", $likes) . ")";
        }
      }

      $stmt = $pdo->prepare("SELECT DISTINCT {$srcCol} AS sf FROM {$hT} WHERE {$where} {$toolWhere}");
      $stmt->execute(array_merge($params, $toolParams));
      $srcFiles = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

      $ymap = [];
      foreach ($srcFiles as $sf) {
        $ymd = oqc_parse_ymd_from_source_file($sf);
        if ($ymd) {
          $yy = (int)substr($ymd,0,4);
          if ($yy >= 2000 && $yy <= 2100) $ymap[$yy] = 1;
        }
      }
      $yearsAvail = array_keys($ymap);
      rsort($yearsAvail);

// FAI options list (AOI only): mapping 우선, 없으면 DB에서 DISTINCT (row_index=1)로 가볍게 조회
if ($type === 'AOI') {
  // 1) mapping list (매핑.xlsx 기반)
  $tmp = [];
  if (!empty($orderList) && is_array($orderList)) {
    foreach ($orderList as $lab) {
      if (is_array($lab)) continue;
      $lab = trim((string)$lab);
      if ($lab !== '') $tmp[] = $lab;
    }
  }
  $tmp = array_values(array_unique($tmp));
  if (!empty($tmp)) {
    $faiOptions = $tmp;
  } else {
    // 2) fallback: DB distinct (row_index=1만)
    if ($model !== '' && !empty($toolsSel)) {
      try {
        $yIn = implode(',', array_fill(0, count($yearsSel), '?'));
        $mIn = implode(',', array_fill(0, count($months), '?'));
        $tIn = implode(',', array_fill(0, count($toolsSel), '?'));
        $sqlF = "
          SELECT DISTINCT m.{$keyCol} AS k
          FROM {$headerTable} h
          JOIN {$measTable} m ON m.header_id = h.id
          WHERE h.meas_date IS NOT NULL
            AND YEAR(h.meas_date) IN ($yIn)
            AND MONTH(h.meas_date) IN ($mIn)
            AND h.part_name = ?
            AND h.tool IN ($tIn)
            AND m.row_index = 1
          ORDER BY k
          LIMIT 500
        ";
        $stmtF = $pdo->prepare($sqlF);
        $stmtF->execute(array_merge($yearsSel, $months, [$model], array_values($toolsSel)));
        $rowsF = $stmtF->fetchAll(PDO::FETCH_COLUMN, 0);
        if (is_array($rowsF)) {
          $faiOptions = array_values(array_unique(array_filter(array_map('trim', $rowsF), function($v){
            return is_string($v) && $v !== '';
          })));
          if (count($rowsF) >= 500) $faiOptionsTruncated = true;
        }
      } catch (Throwable $e) {
        // ignore
      }
    }
  }
}
    } catch (Throwable $e) {
      $yearsAvail = [];
    }
  } else {
    $models = [];
    $tools = [];
    $yearsAvail = [];
    $OQC_SCHEMA_ERR = is_array($OQC_SCHEMA) ? ($OQC_SCHEMA['error'] ?? 'OQC schema detect failed') : 'OQC schema detect failed';
  }
} else {
  $models = [];
  try {
    $sql = "SELECT DISTINCT part_name FROM {$headerTable} WHERE meas_date IS NOT NULL ORDER BY part_name";
    $models = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0);
  } catch (Throwable $e) {
    $models = [];
  }

  // 모델은 단일 선택이며 항상 1개가 선택되어야 한다. ('전체/빈값' 금지)
  // 전달된 model이 현재 타입의 목록에 없으면 첫 항목으로 강제한다.
  if ($model === '' || (!empty($models) && !in_array($model, $models, true))) {
    if (!empty($models)) $model = (string)$models[0];
  }

  // 선택된 모델 기준으로 매핑(order_map)을 다시 계산 (model 강제 변경 시 정합성 유지)
  $orderList = [];
  $orderIndex = [];
  $__mk = ipqc_model_to_mapkey($model);
  if ($__mk !== '' && isset($IPQC_ORDER_MAP[$__mk]) && is_array($IPQC_ORDER_MAP[$__mk])) {
    $__list = $IPQC_ORDER_MAP[$__mk][$type] ?? [];
    if (is_array($__list)) {
      $orderList = $__list;
      $i = 0;
      foreach ($__list as $__lab) {
        if (is_array($__lab)) continue;
        $__lab = (string)$__lab;
        if ($__lab !== '' && !isset($orderIndex[$__lab])) $orderIndex[$__lab] = $i;
        $i++;
      }
    }
  }

  // options: tool list (dynamic, + '전체')
  $tools = ipqc_fetch_tools_for_type_model($pdo, $type, $model, $TYPE_MAP, null);

  // options: year list (dynamic)
  $yearsAvail = [];
  try {
    $where = "meas_date IS NOT NULL";
    $params = [];
    if ($model !== '') { $where .= " AND part_name = :p"; $params[':p'] = $model; }
    $stmt = $pdo->prepare("SELECT DISTINCT YEAR(meas_date) AS y FROM {$headerTable} WHERE {$where} ORDER BY y DESC");
    $stmt->execute($params);
    $yearsAvail = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
  } catch (Throwable $e) {
    $yearsAvail = [];
  }
}

// ensure selected years exist in the list

foreach ($yearsSel as $yy) {
  $yy = (int)$yy;
  if ($yy >= 2000 && $yy <= 2100 && !in_array($yy, $yearsAvail, true)) $yearsAvail[] = $yy;
}
rsort($yearsAvail);

$results = [];
$isPivot = false;
$pivotCols = [];
$meta = ['raw_rows' => 0, 'rows' => 0, 'error' => null];



$faiOptions = [];
$faiOptionsTruncated = false;
// FAI 옵션(AOI 전용): mapping 우선, 없으면 DB에서 DISTINCT (row_index=1)로 가볍게 조회
if ($type === 'AOI') {
  // 1) mapping list (매핑.xlsx 기반)
  $tmp = [];
  if (!empty($orderList) && is_array($orderList)) {
    foreach ($orderList as $lab) {
      if (is_array($lab)) continue;
      $lab = trim((string)$lab);
      if ($lab !== '') $tmp[] = $lab;
    }
  }
  $tmp = array_values(array_unique($tmp));
  if (!empty($tmp)) {
    $faiOptions = $tmp;
  } else {
    // 2) fallback: DB distinct (row_index=1만)
    if ($model !== '' && !empty($toolsSel) && !empty($yearsSel) && !empty($months)) {
      try {
        $yIn = implode(',', array_fill(0, count($yearsSel), '?'));
        $mIn = implode(',', array_fill(0, count($months), '?'));
        $tIn = implode(',', array_fill(0, count($toolsSel), '?'));
        $sqlF = "
          SELECT DISTINCT m.{$keyCol} AS k
          FROM {$headerTable} h
          JOIN {$measTable} m ON m.header_id = h.id
          WHERE h.meas_date IS NOT NULL
            AND YEAR(h.meas_date) IN ($yIn)
            AND MONTH(h.meas_date) IN ($mIn)
            AND h.part_name = ?
            AND h.tool IN ($tIn)
            AND m.row_index = 1
          ORDER BY k
          LIMIT 500
        ";
        $stmtF = $pdo->prepare($sqlF);
        $stmtF->execute(array_merge($yearsSel, $months, [$model], array_values($toolsSel)));
        $rowsF = $stmtF->fetchAll(PDO::FETCH_COLUMN, 0);
        if (is_array($rowsF)) {
          $faiOptions = array_values(array_unique(array_filter(array_map('trim', $rowsF), function($v){
            return is_string($v) && $v !== '';
          })));
          if (count($rowsF) >= 500) $faiOptionsTruncated = true;
        }
      } catch (Throwable $e) {
        // ignore
      }
    }
  }
}

$pageDates = [];
$wantRun = (isset($_GET['run']) && $_GET['run'] === '1');
$doQuery = ($wantRun && $model !== '');

// 최소 1개 툴 선택 필수
if ($wantRun && $model !== '' && empty($toolsSel)) {
  $meta['error'] = '툴을 1개 이상 선택해주세요.';
  $doQuery = false;
}

// ✅ AOI: FAI는 1개 이상 선택 필수
if ($wantRun && $type === 'AOI' && $model !== '' && empty($faiSel)) {
  $meta['error'] = 'FAI를 1개 이상 선택해주세요.';
  $doQuery = false;
}

if ($doQuery) {

  if ($type === 'OQC') {
    // ===== OQC (Py spec) =====
    $isPivot = true;
    $pivotCols = [];
    $results = [];
    $displayResults = [];
    $pageOffset = 0;
    $totalRowsAll = 0;
    $totalRowsView = 0;
    $totalRows = 0;
    $totalPages = 1;

    $schema = $OQC_SCHEMA;
    if (!is_array($schema) || empty($schema['ok'])) {
      try { $schema = oqc_detect_schema($pdo); } catch (Throwable $e) { $schema = ['ok'=>false,'error'=>$e->getMessage()]; }
    }
    if (!is_array($schema) || empty($schema['ok'])) {
      $meta['error'] = is_array($schema) ? ($schema['error'] ?? 'OQC schema detect failed') : 'OQC schema detect failed';
      $pageDates = [];
    } else {
      $hT = $schema['headerTable'];
      $mT = $schema['measTable'];
      $idCol = $schema['idCol'];
      $partCol = $schema['partCol'];
      $srcCol = $schema['sourceCol'];
      $excelCol = $schema['excelCol'];

      // build YM regex from selected years/months
      $ymPairs = [];
      foreach ($yearsSel as $yy) {
        foreach ($months as $mm) {
          $yy = (int)$yy; $mm = (int)$mm;
          if ($yy < 2000 || $yy > 2100) continue;
          if ($mm < 1 || $mm > 12) continue;
          $ymPairs[] = [$yy, $mm];
        }
      }
      usort($ymPairs, function($a, $b){
        if ($a[0] === $b[0]) return $a[1] <=> $b[1];
        return $a[0] <=> $b[0];
      });
      $ymRegexParts = [];
      foreach ($ymPairs as $pair) { $ymRegexParts[] = oqc_build_regex_ym((int)$pair[0], (int)$pair[1]); }
      $ymRegex = !empty($ymRegexParts) ? ('(' . implode('|', $ymRegexParts) . ')') : '';

      // base WHERE
      $where = "{$srcCol} IS NOT NULL AND {$srcCol} <> '' AND {$partCol} = ?";
      $params = [$model];

      // tool filter (Tool only)
      if (!empty($toolsSel)) {
        if (!empty($schema['toolCol'])) {
          $toolCol = $schema['toolCol'];
          $in = implode(',', array_fill(0, count($toolsSel), '?'));
          $where .= " AND {$toolCol} IN ({$in})";
          foreach (array_values($toolsSel) as $t) $params[] = $t;
        } else {
          $tcCol = $schema['tcCol'];
          $likes = [];
          foreach (array_values($toolsSel) as $t) { $likes[] = "{$tcCol} LIKE ?"; $params[] = strtoupper(trim($t))."#%"; }
          if (!empty($likes)) $where .= " AND (" . implode(" OR ", $likes) . ")";
        }
      }

      // YM filter from source_file (no meas_date usage)
      if ($ymRegex !== '') {
        $where .= " AND {$srcCol} REGEXP ?";
        $params[] = $ymRegex;
      }

      // date paging: distinct source_file -> parse -> unique YYYY-MM-DD
      $pageDates = [];
      try {
        $stmtD = $pdo->prepare("SELECT DISTINCT {$srcCol} AS sf FROM {$hT} WHERE {$where}");
        $stmtD->execute($params);
        $srcFiles = $stmtD->fetchAll(PDO::FETCH_COLUMN, 0);

        $dmap = [];
        foreach ($srcFiles as $sf) {
          $ymd = oqc_parse_ymd_from_source_file($sf);
          if ($ymd) $dmap[$ymd] = 1;
        }
        $ymds = array_keys($dmap);
        sort($ymds);
        foreach ($ymds as $ymd) $pageDates[] = oqc_fmt_ymd_dash($ymd);
        // guard: remove empty/duplicate labels (prevents blank buttons)
        $pageDates = array_values(array_unique($pageDates));
        $pageDates = array_values(array_filter($pageDates, function($v){
          return is_string($v) && trim($v) !== '';
        }));
      } catch (Throwable $e) {
        $pageDates = [];
      }

            // apply multi-date selection (OQC: page_dates)
      if (!empty($pageDatesSel)) {
        $tmpSel = [];
        foreach ($pageDatesSel as $dSel) {
          if (in_array($dSel, $pageDates, true)) $tmpSel[] = $dSel;
        }
        $pageDatesSel = array_values(array_unique($tmpSel));
        if (!empty($pageDatesSel)) {
          $pageAll = false;
          $page = 1;
          $pageDate = $pageDatesSel[count($pageDatesSel)-1];
        }
      }

// 선택 날짜 결정 (ALL이면 선택 날짜 강제하지 않음)
      $selectedDate = $pageDate;
      if (!$pageAll) {
        if ($selectedDate === '' || !in_array($selectedDate, $pageDates, true)) {
          if (!empty($pageDates)) {
            $idx = $page - 1;
            if ($idx < 0) $idx = 0;
            if ($idx >= count($pageDates)) $idx = count($pageDates) - 1;
            $selectedDate = $pageDates[$idx];
          } else {
            $selectedDate = '';
          }
        }
      } else {
        if ($selectedDate === '' || !in_array($selectedDate, $pageDates, true)) {
          $selectedDate = !empty($pageDates) ? $pageDates[0] : '';
        }
      }
      $pageDate = $selectedDate;

      // day filter via source_file regex (ALL이 아니면 하루/여러날로 제한)
      $dayWhere = '';
      $dayParams = [];
      if (!$pageAll) {
        if (!empty($pageDatesSel)) {
          $ors = [];
          foreach ($pageDatesSel as $dSel) {
            $dre = oqc_build_regex_ymd($dSel);
            if ($dre !== '') { $ors[] = "{$srcCol} REGEXP ?"; $dayParams[] = $dre; }
          }
          if (!empty($ors)) {
            $dayWhere = " AND (" . implode(" OR ", $ors) . ")";
          }
        } elseif ($pageDate !== '') {
          $dre = oqc_build_regex_ymd($pageDate);
          if ($dre !== '') { $dayWhere = " AND {$srcCol} REGEXP ?"; $dayParams[] = $dre; }
        }
      }


      // ALL 모드 보호 (header count)
      $skipHeavy = false;
      if ($pageAll) {
        try {
          $stmtC = $pdo->prepare("SELECT COUNT(*) FROM {$hT} WHERE {$where}");
          $stmtC->execute($params);
          $hc = (int)$stmtC->fetchColumn();
          if ($hc > 1200) {
            $meta['error'] = "ALL 범위가 너무 큽니다 (header={$hc}). 날짜를 선택하거나 EXPORT를 사용하세요.";
            $skipHeavy = true;
          }
        } catch (Throwable $e) {}
      }

      if (!$skipHeavy) {
        // heavy: header + measurements join
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
          JOIN {$mT} m
            ON m.{$fkCol} = h.{$idCol}
          WHERE {$where}
          {$dayWhere}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $dayParams));
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rawCount = is_array($raw) ? count($raw) : 0;

        // pivot build
        $colMetaMap = [];
        $tmpRows = [];

        foreach ($raw as $r) {
          $part = trim((string)($r['part_name'] ?? ''));
          if ($part === '') continue;

          $ymd = oqc_parse_ymd_from_source_file($r['source_file'] ?? '');
          if (!$ymd) continue;
          $d = oqc_fmt_ymd_dash($ymd);
          if ($d === '') continue;

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
          if ($cavity === '') $cavity = ''; // allow blank

          $idx = oqc_data_index_from_excel_col($r['excel_col'] ?? null);
          if ($idx === null) continue;

          $label = 'Data ' . $idx;
          $rowKey = $part . '|||' . $tool . '|||' . $cavity . '|||' . $d . '|||' . $idx;

          $point = (string)($r['point_no'] ?? '');
          if ($point === '') continue;

          if (!isset($colMetaMap[$point])) {
            $colMetaMap[$point] = ['key' => $point, 'label' => $point, 'lsl' => null, 'usl' => null];
          }

          if (!isset($tmpRows[$rowKey])) {
            $tmpRows[$rowKey] = [
              'part' => $part,
              'tool' => $tool,
              'cavity' => $cavity,
              'date' => $d,
              'label' => $label,
              'idx' => $idx,
              'cells' => []
            ];
          }
          $tmpRows[$rowKey]['cells'][$point] = $r['value'];
        }

        $pivotCols = array_values($colMetaMap);
        usort($pivotCols, function($a, $b) use ($orderIndex){
          $la = (string)($a['label'] ?? '');
          $lb = (string)($b['label'] ?? '');
          if (!empty($orderIndex)) {
            $ia = $orderIndex[$la] ?? PHP_INT_MAX;
            $ib = $orderIndex[$lb] ?? PHP_INT_MAX;
            if ($ia !== $ib) return $ia <=> $ib;
          }
          return ipqc_cmp_nat($la, $lb);
        });

        // OQC: 선택된 Point(=fai[]) 컬럼만 남김 (__ALL__이면 전체)
        if (!empty($faiSel) && !in_array('__ALL__', $faiSel, true)) {
          $selSet = array_fill_keys($faiSel, 1);
          $pivotCols = array_values(array_filter($pivotCols, function($c) use ($selSet){
            $k = (string)($c['key'] ?? '');
            return $k !== '' && isset($selSet[$k]);
          }));
        }

// IMPORTANT: keep row shape compatible with existing pivot renderer
        // row['cells'][point_key] is used by the table renderer
        $results = [];
        foreach ($tmpRows as $row) {
          $results[] = [
            'part' => $row['part'],
            'tool' => $row['tool'],
            'cavity' => $row['cavity'],
            'date' => $row['date'],
            'label' => $row['label'],
            'idx' => (int)$row['idx'],
            'cells' => $row['cells'],
          ];
        }

        usort($results, function($a, $b){
          $c = ipqc_cmp_nat((string)($a['part'] ?? ''), (string)($b['part'] ?? ''));
          if ($c !== 0) return $c;
          $c = ipqc_cmp_nat((string)($a['tool'] ?? ''), (string)($b['tool'] ?? ''));
          if ($c !== 0) return $c;
          $c = ipqc_cmp_nat((string)($a['cavity'] ?? ''), (string)($b['cavity'] ?? ''));
          if ($c !== 0) return $c;
          $c = ipqc_cmp_nat((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
          if ($c !== 0) return $c;
          return ((int)($a['idx'] ?? 0)) <=> ((int)($b['idx'] ?? 0));
        });

        $meta['raw_rows'] = $rawCount;
        $meta['rows'] = count($results);

        // ✅ OQC: 페이지(날짜) 컨트롤은 항상 살아있어야 한다.
        // - pageDates가 비어있으면(쿼리/REGEXP/환경 이슈 등) 현재 results에서 날짜를 재구성해 복구
        // - page_date가 비어있으면 page(번호) 기준으로 날짜를 자동 선택
        $datesFromData = [];
        if (!empty($results)) {
          $dmap2 = [];
          foreach ($results as $__r) {
            $dd = (string)($__r['date'] ?? '');
            if ($dd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) $dmap2[$dd] = 1;
          }
          $datesFromData = array_keys($dmap2);
          sort($datesFromData);
        }

        if (!empty($datesFromData)) {
          // merge/unique/sort
          $pageDates = array_values(array_unique(array_merge(is_array($pageDates) ? $pageDates : [], $datesFromData)));
          sort($pageDates);
        } else {
          if (!is_array($pageDates)) $pageDates = [];
        }

        // pick pageDate
        $selectedDate = (string)$pageDate;
        if (!empty($pageDates)) {
          if ($pageAll) {
            if ($selectedDate === '' || !in_array($selectedDate, $pageDates, true)) $selectedDate = $pageDates[0];
          } else {
            if ($selectedDate === '' || !in_array($selectedDate, $pageDates, true)) {
              $idx = $page - 1;
              if ($idx < 0) $idx = 0;
              if ($idx >= count($pageDates)) $idx = count($pageDates) - 1;
              $selectedDate = $pageDates[$idx];
            }
          }
        } else {
          $selectedDate = '';
        }
        $pageDate = $selectedDate;

        // filter display rows when not ALL
        $displayResults = $results;
        if (!$pageAll && $pageDate !== '') {
          $displayResults = array_values(array_filter($results, function($rr) use ($pageDate){
            return ((string)($rr['date'] ?? '')) === $pageDate;
          }));
        }

        $totalRowsAll = count($results);
        $totalRowsView = count($displayResults);
        $totalRows = $totalRowsAll;
        $totalPages = max(1, count($pageDates));
      } else {
        $results = [];
        $displayResults = [];
        $pageOffset = 0;
        $totalRowsAll = 0;
        $totalRowsView = 0;
        $totalRows = 0;
        $totalPages = max(1, count($pageDates));
      }
    }
  } else {
  // 날짜 범위 조건 (INDEX friendly): (meas_date >= start AND < end) OR ...
  $ymPairs = [];
  foreach ($yearsSel as $yy) {
    foreach ($months as $mm) {
      $yy = (int)$yy; $mm = (int)$mm;
      if ($yy < 2000 || $yy > 2100) continue;
      if ($mm < 1 || $mm > 12) continue;
      $ymPairs[] = [$yy, $mm];
    }
  }

  // sort by (year,month)
  usort($ymPairs, function($a, $b){
    if ($a[0] === $b[0]) return $a[1] <=> $b[1];
    return $a[0] <=> $b[0];
  });

  // build and merge contiguous month ranges
  $ranges = [];
  foreach ($ymPairs as $pair) {
    [$yy, $mm] = $pair;
    $start = sprintf('%04d-%02d-01 00:00:00', $yy, $mm);
    // next month
    $dt = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $yy, $mm));
    $end = $dt->modify('+1 month')->format('Y-m-d H:i:s');

    if (empty($ranges)) {
      $ranges[] = [$start, $end];
    } else {
      $lastIdx = count($ranges) - 1;
      if ($ranges[$lastIdx][1] === $start) {
        // contiguous -> merge
        $ranges[$lastIdx][1] = $end;
      } else {
        $ranges[] = [$start, $end];
      }
    }
  }

  $dateParts = [];
$dateParamsPos = [];
foreach ($ranges as $rg) {
  $dateParts[] = "(h.meas_date >= ? AND h.meas_date < ?)";
  $dateParamsPos[] = $rg[0];
  $dateParamsPos[] = $rg[1];
}
$dateRangeSql = !empty($dateParts) ? ('(' . implode(' OR ', $dateParts) . ')') : '1=1';
$skipHeavy = false;


  // Tool 다중 선택: IN (positional placeholders)
$toolParamsPos = [];
$toolInSql = '';
if (!empty($toolsSel)) {
  $toolParamsPos = array_values($toolsSel);
  $toolInSql = implode(',', array_fill(0, count($toolParamsPos), '?'));
}
// common excludes: (disabled - allow NULL source_file / preserve all data)
  $excludeSql = "";


// AOI FAI 필터 (m.fai IN ...)
$faiParamsPos = [];
$faiInSql = '';
$faiSql = '';
if ($type === 'AOI' && !empty($faiSel)) {
  $faiParamsPos = array_values($faiSel);
  $faiInSql = implode(',', array_fill(0, count($faiParamsPos), '?'));
  if ($faiInSql !== '') $faiSql = " AND m.{$keyCol} IN ($faiInSql)";
}
// 날짜 목록(pageDates) 먼저 조회 (AOI+FAI 필터 시 m JOIN 필요)
  try {
    if ($type === 'AOI' && $faiSql !== '') {
      // ⚠️ $faiSql 은 m.* 를 참조하므로 measurements JOIN + row_index=1로 경량화
      $sqlDates = "
        SELECT DISTINCT DATE(h.meas_date) AS d
        FROM {$headerTable} h
        JOIN {$measTable} m ON m.header_id = h.id
        WHERE h.meas_date IS NOT NULL
          AND {$dateRangeSql}
          AND h.part_name = ?
          " . (!empty($toolParamsPos) ? " AND h.tool IN ($toolInSql)" : "") . "
          {$excludeSql}
          {$faiSql}
          AND m.row_index = 1
        ORDER BY d
      ";
      $paramsDates = array_merge($dateParamsPos, [$model], $toolParamsPos, $faiParamsPos);
    } else {
      $sqlDates = "
        SELECT DISTINCT DATE(h.meas_date) AS d
        FROM {$headerTable} h
        WHERE h.meas_date IS NOT NULL
          AND {$dateRangeSql}
          AND h.part_name = ?
          " . (!empty($toolParamsPos) ? " AND h.tool IN ($toolInSql)" : "") . "
          {$excludeSql}
        ORDER BY d
      ";
      $paramsDates = array_merge($dateParamsPos, [$model], $toolParamsPos);
    }

    $stmtD = $pdo->prepare($sqlDates);
    $stmtD->execute($paramsDates);
    $pageDates = $stmtD->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!is_array($pageDates)) $pageDates = [];
  } catch (Throwable $e) {
    $pageDates = [];
  }

  // apply multi-date selection (page_dates): keep only existing dates
  if (!empty($pageDatesSel)) {
    $tmpSel = [];
    foreach ($pageDatesSel as $dSel) {
      if (in_array($dSel, $pageDates, true)) $tmpSel[] = $dSel;
    }
    $pageDatesSel = array_values(array_unique($tmpSel));
    if (!empty($pageDatesSel)) {
      // multi-date mode forces non-ALL and uses the last selected date as the "current" one
      $pageAll = false;
      $page = 1;
      $pageDate = $pageDatesSel[count($pageDatesSel)-1];
    }
  }

  // 선택 날짜 결정 (ALL이면 선택 날짜 강제하지 않음)
  $selectedDate = $pageDate;
  if (!$pageAll) {
    if ($selectedDate === '' || !in_array($selectedDate, $pageDates, true)) {
      if (!empty($pageDates)) {
        // fallback: page=숫자(1부터) -> 해당 순번 날짜
        $idx = $page - 1;
        if ($idx < 0) $idx = 0;
        if ($idx >= count($pageDates)) $idx = count($pageDates) - 1;
        $selectedDate = $pageDates[$idx];
      } else {
        $selectedDate = '';
      }
    }
  } else {
    // ALL 모드에서도 prev/next / 페이지EXCEL 등에 쓸 '기준 날짜'는 하나 확보
    if ($selectedDate === '' || !in_array($selectedDate, $pageDates, true)) {
      $selectedDate = !empty($pageDates) ? $pageDates[0] : '';
    }
  }
  $pageDate = $selectedDate;

  // 날짜(하루) 필터 (ALL이 아니면 쿼리 자체를 날짜 범위로 제한)
// - 기본: 1일(page_date)
// - Ctrl+Click: 여러 날짜(page_dates=YYYY-MM-DD,YYYY-MM-DD...)
  $dayParamsPos = [];
  $daySql = '';

  if (!$pageAll) {
    if (!empty($pageDatesSel)) {
      $or = [];
      foreach ($pageDatesSel as $i => $dSel) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dSel)) continue;
        try {
          $d0 = new DateTimeImmutable($dSel . ' 00:00:00');
          $d1 = $d0->modify('+1 day');
          $or[] = "(h.meas_date >= ? AND h.meas_date < ?)";
          $dayParamsPos[] = $d0->format('Y-m-d H:i:s');
          $dayParamsPos[] = $d1->format('Y-m-d H:i:s');
        } catch (Throwable $e) {}
      }
      if (!empty($or)) {
        $daySql = " AND (" . implode(" OR ", $or) . ")";
      }
    } elseif ($pageDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageDate)) {
      try {
        $d0 = new DateTimeImmutable($pageDate . ' 00:00:00');
        $d1 = $d0->modify('+1 day');
        $daySql = " AND h.meas_date >= ? AND h.meas_date < ?";
        $dayParamsPos[] = $d0->format('Y-m-d H:i:s');
        $dayParamsPos[] = $d1->format('Y-m-d H:i:s');
      } catch (Throwable $e) {
        $dayParamsPos = [];
        $daySql = '';
      }
    }
  }

// ALL 모드 보호: 너무 큰 범위면 화면 표시 대신 export를 유도 (OOM 방지)
  if ($pageAll && empty($pageDate) === false) {
    try {
      $sqlHcnt = "
        SELECT COUNT(*) AS c
        FROM {$headerTable} h
        WHERE h.meas_date IS NOT NULL
          AND {$dateRangeSql}
          AND h.part_name = ?
          " . (!empty($toolParamsPos) ? " AND h.tool IN ($toolInSql)" : "") . "
          {$excludeSql}
      ";
      $stmtC = $pdo->prepare($sqlHcnt);
      $stmtC->execute(array_merge($dateParamsPos, [$model], $toolParamsPos));
      $hc = (int)$stmtC->fetchColumn();
      if ($hc > 1200) {
        $meta['error'] = "ALL 범위가 너무 큽니다 (header={$hc}). 날짜를 선택하거나 EXPORT를 사용하세요.";
        $skipHeavy = true; // heavy query skip
      }
    } catch (Throwable $e) {
      // ignore
    }
  }


  

// ----------------------
// UI counts: shown vs total (total ignores selected date "page")
// - shown: 화면에 실제 렌더링되는 행 수 (PHP에서 count($displayResults)로 계산)
// - total: 선택한 기간(연/월/툴/모델) 전체 행 수 (선택한 날짜(pageDate)와 무관)
// ----------------------
$totalRowsAll = 0;
$totalRowsView = 0;

$baseWhereAoi = '';
try {
  // base WHERE (period + model + tool)
  $baseWhere = "h.meas_date IS NOT NULL AND {$dateRangeSql} AND h.part_name = ?";
  if (!empty($toolParamsPos)) {
    $baseWhere .= " AND h.tool IN ($toolInSql)";
  }
  if (!empty(trim($excludeSql))) {
    // $excludeSql should already contain leading AND ... ; keep as-is
    $baseWhere .= " {$excludeSql}";
  }

  $baseWhereAoi = $baseWhere;
  if ($type === 'AOI' && $faiSql !== '') { $baseWhereAoi .= $faiSql; }
if ($type === 'AOI') {
    // AOI row key (rendered): part|spc|key|tool|cavity|date
    $spcExpr = $hasSpc ? "m.spc" : "''";

    $sqlCntAll = "SELECT COUNT(*) FROM (
        SELECT
          h.part_name, DATE(h.meas_date) AS d, h.tool, h.cavity,
          {$spcExpr} AS spc,
          m.{$keyCol} AS key_name
        FROM {$headerTable} h
        JOIN {$measTable} m ON m.header_id = h.id
        WHERE {$baseWhereAoi}
        GROUP BY h.part_name, DATE(h.meas_date), h.tool, h.cavity, spc, m.{$keyCol}
    ) t";
    $stmt = $pdo->prepare($sqlCntAll);
    $stmt->execute(array_merge($dateParamsPos, [$model], $toolParamsPos, $faiParamsPos));
    $totalRowsAll = (int)($stmt->fetchColumn() ?: 0);

    $sqlCntView = "SELECT COUNT(*) FROM (
        SELECT
          h.part_name, DATE(h.meas_date) AS d, h.tool, h.cavity,
          {$spcExpr} AS spc,
          m.{$keyCol} AS key_name
        FROM {$headerTable} h
        JOIN {$measTable} m ON m.header_id = h.id
        WHERE {$baseWhereAoi} {$daySql}
        GROUP BY h.part_name, DATE(h.meas_date), h.tool, h.cavity, spc, m.{$keyCol}
    ) t";
    $stmt = $pdo->prepare($sqlCntView);
    $stmt->execute(array_merge($dateParamsPos, [$model], $toolParamsPos, $faiParamsPos, $dayParamsPos));
    $totalRowsView = (int)($stmt->fetchColumn() ?: 0);

  } else {
    // OMM/CMM pivot row key (rendered): part|tool|cavity|date|idx(row_index)
    $sqlCntAll = "SELECT COUNT(*) FROM (
        SELECT
          h.part_name, DATE(h.meas_date) AS d, h.tool, h.cavity, m.row_index
        FROM {$headerTable} h
        JOIN {$measTable} m ON m.header_id = h.id
        WHERE {$baseWhere}
          AND m.row_index BETWEEN 1 AND ?
        GROUP BY h.part_name, DATE(h.meas_date), h.tool, h.cavity, m.row_index
    ) t";
    $stmt = $pdo->prepare($sqlCntAll);
    $stmt->execute(array_merge($dateParamsPos, [$model], $toolParamsPos, [$dataCols]));
    $totalRowsAll = (int)($stmt->fetchColumn() ?: 0);

    $sqlCntView = "SELECT COUNT(*) FROM (
        SELECT
          h.part_name, DATE(h.meas_date) AS d, h.tool, h.cavity, m.row_index
        FROM {$headerTable} h
        JOIN {$measTable} m ON m.header_id = h.id
        WHERE {$baseWhere} {$daySql}
          AND m.row_index BETWEEN 1 AND ?
        GROUP BY h.part_name, DATE(h.meas_date), h.tool, h.cavity, m.row_index
    ) t";
    $stmt = $pdo->prepare($sqlCntView);
    $stmt->execute(array_merge($dateParamsPos, [$model], $toolParamsPos, $dayParamsPos, [$dataCols]));
    $totalRowsView = (int)($stmt->fetchColumn() ?: 0);
  }
} catch (Throwable $e) {
  // counts are UI-only; do not break page if something goes wrong
  $totalRowsAll = 0;
  $totalRowsView = 0;
}

if (!$skipHeavy) {
  $spcSelect = $hasSpc ? "m.spc AS spc," : "NULL AS spc,";
  $spcGroupKey = $hasSpc ? "spc" : "";  $sql = "
    SELECT
      h.part_name, h.meas_date, h.tool, h.cavity,
      m.{$keyCol} AS key_name,
      {$spcSelect}
      m.row_index, m.value,
r.usl, r.lsl, r.result_ok
    FROM {$headerTable} h
    JOIN {$measTable} m ON m.header_id = h.id
    LEFT JOIN {$resTable} r
           ON r.header_id = h.id
          AND r.{$keyCol} = m.{$keyCol}
    WHERE h.meas_date IS NOT NULL
      AND $dateRangeSql
      $daySql
      AND h.part_name = ?
      " . (!empty($toolParamsPos) ? " AND h.tool IN ($toolInSql)" : "") . "
      {$excludeSql}
      {$faiSql}
    ORDER BY h.meas_date, h.tool, h.cavity, key_name, m.row_index
  ";

  try {
    $params = array_merge($dateParamsPos, $dayParamsPos, [$model], $toolParamsPos, $faiParamsPos);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rawCount = 0;

    if ($type === 'AOI') {
      $isPivot = false;
      $rows = [];

      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rawCount++;

        $date = substr((string)$r['meas_date'], 0, 10);
        $spc  = $r['spc'] ?? '';
        $keyName = $r['key_name'] ?? '';
        $cav = (string)$r['cavity'];
        $t   = (string)$r['tool'];

        $k = $r['part_name'] . "|" . $spc . "|" . $keyName . "|" . $t . "|" . $cav . "|" . $date;

        if (!isset($rows[$k])) {
          $data = array_fill(1, $dataCols, null);
          $rows[$k] = [
            'part' => $r['part_name'],
            'spc'  => $spc,
            'key'  => $keyName,
            'tool' => $t,
            'cavity' => $cav,
            'date' => $date,
            'usl' => $r['usl'],
            'lsl' => $r['lsl'],
            'ok'  => $r['result_ok'],
            'data' => $data,
          ];
        }

        $idx = (int)$r['row_index'];
        if ($idx >= 1 && $idx <= $dataCols) {
          $rows[$k]['data'][$idx] = $r['value'];
        }
      }

      $results = array_values($rows);

      // JMP Assist 정렬 규칙(_sort_rows_by_date_cavity_label 기반):
      //   Tool -> Date -> Cavity (동일 키 내에서는 FAI/SPC로 자연정렬해 결정적으로 유지)
      usort($results, function($a, $b) use ($orderIndex) {
        $c = ipqc_cmp_nat((string)$a['tool'], (string)$b['tool']);
        if ($c !== 0) return $c;
        $c = ipqc_cmp_nat((string)$a['date'], (string)$b['date']);
        if ($c !== 0) return $c;
        $c = ipqc_cmp_nat((string)$a['cavity'], (string)$b['cavity']);
        if ($c !== 0) return $c;
        // 매핑.xlsx 순서 우선 (AOI)
        if (!empty($orderIndex)) {
          $ka = (string)($a['key'] ?? '');
          $kb = (string)($b['key'] ?? '');
          $ia = $orderIndex[$ka] ?? PHP_INT_MAX;
          $ib = $orderIndex[$kb] ?? PHP_INT_MAX;
          if ($ia !== $ib) return $ia <=> $ib;
        }
        // tie-breakers (PHP usort는 stable sort가 아니라서 추가)
        $c = ipqc_cmp_nat((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
        if ($c !== 0) return $c;
        return ipqc_cmp_nat((string)($a['spc'] ?? ''), (string)($b['spc'] ?? ''));
      });

    } else {
      // OMM/CMM: JMP 시트처럼 Pivot(라벨=Data 1..N, 열=포인트/FAI)
      $isPivot = true;
      $pivotRows = [];
      $colMeta = [];

      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rawCount++;

        $part = (string)$r['part_name'];
        $tool = (string)$r['tool'];
        $cav  = (string)$r['cavity'];
        $date = substr((string)$r['meas_date'], 0, 10);

        $idx = (int)$r['row_index'];
        if ($idx < 1 || $idx > $dataCols) continue;

        $label = 'Data ' . $idx;

        if ($type === 'OMM') {
          // OMM: DB에 저장된 fai 라벨을 그대로 사용한다. (SPC는 표시용 부가필드)
          $keyName = (string)($r['key_name'] ?? '');
          $colKey = $keyName;

          if (!isset($colMeta[$colKey])) {
            $colMeta[$colKey] = [
              'label' => $keyName,
              'usl'   => $r['usl'],
              'lsl'   => $r['lsl'],
            ];
          }
        } else { // CMM
          $keyName = (string)($r['key_name'] ?? ''); // point_no
          $colKey = $keyName;

          if (!isset($colMeta[$colKey])) {
            $mappedLabel = cmm_map_fai_name($model, $keyName);
          $colMeta[$colKey] = [
            'label' => ($mappedLabel !== '' ? $mappedLabel : $keyName),
            'usl'   => $r['usl'],
            'lsl'   => $r['lsl'],
          ];
          }
        }

        // pivot row key: part|tool|cavity|date|idx
        $rk = implode('|', [$part, $tool, $cav, $date, (string)$idx]);

        if (!isset($pivotRows[$rk])) {
          $pivotRows[$rk] = [
            'part'   => $part,
            'tool'   => $tool,
            'cavity' => $cav,
            'date'   => $date,
            'label'  => $label,
            'idx'    => $idx,
            'cells'  => [],
          ];
        }

        // keep smallest ord (insertion order proxy)
        $pivotRows[$rk]['cells'][$colKey] = $r['value'];
      }

      // Build ordered pivot columns
      // OMM: always follow mapping order (all labels), then append any DB-only columns
      if ($type === 'OMM' && !empty($orderList)) {
        // OMM: 매핑.xlsx(order_map)은 '정렬'에만 사용한다.
        // - DB에 없는 라벨로 신규 컬럼을 만들지 않는다.
        // - 라벨/키를 정규화/분리/접두어 보정하지 않는다(DB 그대로).
        $mappedKeys = [];
        $mappedSet  = [];
        foreach ($orderList as $__lab) {
          if (is_array($__lab)) continue;
          $__rawLab = (string)$__lab;
          if ($__rawLab === '') continue;
          if (isset($colMeta[$__rawLab]) && !isset($mappedSet[$__rawLab])) {
            $mappedSet[$__rawLab] = 1;
            $mappedKeys[] = $__rawLab;
          }
        }

        $extraKeys = [];
        foreach (array_keys($colMeta) as $k) {
          if (!isset($mappedSet[$k])) $extraKeys[] = $k;
        }
        usort($extraKeys, function($a, $b) { return ipqc_cmp_omm_cols((string)$a, (string)$b); });

        $colKeys = array_merge($mappedKeys, $extraKeys);
      } else {
        $colKeys = array_keys($colMeta);
        usort($colKeys, function($a, $b) use ($colMeta, $type, $orderIndex) {
          $la = (string)($colMeta[$a]['label'] ?? $a);
          $lb = (string)($colMeta[$b]['label'] ?? $b);

          // 1) If we have an explicit Excel/JMP order map, follow it
          if (!empty($orderIndex)) {
            $ia = $orderIndex[$la] ?? PHP_INT_MAX;
            $ib = $orderIndex[$lb] ?? PHP_INT_MAX;
            if ($ia !== $ib) return $ia <=> $ib;
          }

          // 2) Fallback: keep previous rule
          if ($type === 'OMM') return ipqc_cmp_omm_cols((string)$a, (string)$b);
          return ipqc_cmp_nat($la, $lb);
        });
      }
// OMM/CMM: FAI 선택은 '열(컬럼)' 필터이다. 선택된 컬럼만 남긴다. (__ALL__이면 전체)
      if (($type === 'OMM' || $type === 'CMM') && isset($colKeys) && is_array($colKeys) && !empty($faiSel) && !in_array('__ALL__', $faiSel, true)) {
        $selSet = array_fill_keys($faiSel, 1);
        $colKeys = array_values(array_filter($colKeys, function($k) use ($selSet, $colMeta, $type){
          $k = (string)$k;
          if (isset($selSet[$k])) return true;
          if ($type === 'CMM') {
            $label = (string)($colMeta[$k]['label'] ?? '');
            if ($label !== '' && isset($selSet[$label])) return true;
          }
          return false;
        }));
      }

$pivotCols = [];
      foreach ($colKeys as $ck) {
        $pivotCols[] = ['key' => $ck] + $colMeta[$ck];
      }

      $results = array_values($pivotRows);
      usort($results, function($a, $b) {
        // JMP Assist 정렬 규칙(_sort_rows_by_date_cavity_label):
        //   Tool -> Date -> Cavity -> 라벨(Data N)  (Part는 정렬키에서 제외)
        $c = ipqc_cmp_nat((string)$a['tool'], (string)$b['tool']);
        if ($c !== 0) return $c;
        $c = ipqc_cmp_nat((string)$a['date'], (string)$b['date']);
        if ($c !== 0) return $c;
        $c = ipqc_cmp_nat((string)$a['cavity'], (string)$b['cavity']);
        if ($c !== 0) return $c;
        $c = ipqc_cmp_nat((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        if ($c !== 0) return $c;
        return ((int)$a['idx']) <=> ((int)$b['idx']);
      });
}

    $meta['raw_rows'] = $rawCount;
    $meta['rows'] = count($results);
// date paging - 날짜별로 한 페이지
    // 날짜 목록($pageDates)과 선택 날짜($pageDate)는 header-only 쿼리에서 결정됨.
    // (ALL이 아니면 쿼리 자체가 하루로 제한되므로 여기서 추가 필터링 불필요)
    $displayResults = $results;
    $pageOffset = 0;


    $totalRows = $totalRowsAll;
    $totalPages = max(1, count($pageDates));
} catch (Throwable $e) {
    $meta['error'] = $e->getMessage();
  }
  } else {
    // heavy query skipped (OOM 방지)
    $results = [];
    $displayResults = [];
    $pageOffset = 0;
    $totalRows = 0;
    $totalPages = max(1, count($pageDates));
  }

  }
}


// defaults for paging variables
if (!isset($pageDates)) { $pageDates = []; }
if (!isset($pageDate))  { $pageDate = ''; }
if (!isset($totalPages)) { $totalPages = max(1, count($pageDates)); }
if (!isset($totalRows))  { $totalRows = $meta['rows'] ?? 0; }
if (!isset($pageOffset)) { $pageOffset = 0; }
if (!isset($displayResults)) { $displayResults = $results; }

?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>JMP Assist</title>

  <style>
:root{
      --bg: #06150b;
      --card: #2b2b2b;
      --card2: #202124;
      --line: rgba(255,255,255,0.10);
      --text: rgba(255,255,255,0.92);
      --muted: rgba(255,255,255,0.65);
      --accent: #1db954;
      --warn: #ffcc00;
      --bad: #ff5252;
    }
    html,body{ height:100%; }
    body{
      margin:0;
      color: var(--text);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple SD Gothic Neo", "Noto Sans KR", sans-serif;
      background: var(--bg);
      overflow-x:hidden;
    }
    .dp-wrap{ position:relative; min-height:100%; z-index:5; }
    .dp-container{ position:relative; z-index:5; max-width: 1700px; margin: 0 auto; padding: 20px; }
    
  /* Page head (OQC style 3-tier) */
  .page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;padding:6px 4px 10px}
  .page-title{font-size:22px;font-weight:800;letter-spacing:.2px;color:#d8ffe0;text-shadow:0 2px 12px rgba(0,0,0,.55)}
  .page-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;
    background:rgba(0,0,0,.35);border:1px solid rgba(120,255,160,.25);color:#d9ffe6;font-weight:700;font-size:12px}
  /* mid-card: 드롭다운(페이지 선택) 패널이 하단 데이터 카드 위로 떠야 해서
   mid-card stacking-context를 위로 올리고 overflow를 visible로 둔다. */
  .mid-card{background:rgba(0,0,0,.50); position:relative; z-index:15; overflow:visible}
  .mid-b{display:flex;align-items:center;justify-content:space-between;gap:12px}
  .mid-left{display:flex;align-items:center;gap:12px;min-width:0}
  .mid-summary{font-size:12px;color:#cfd7cf;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .mid-summary .muted{color:#9bb19e}
  .mid-summary .sep{margin:0 8px;color:rgba(120,255,160,.18)}
  .mid-right{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.dp-card{
  background: rgba(0,0,0,0.52);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.35);
  backdrop-filter:none;
  -webkit-backdrop-filter:none;
}

/* 하단(데이터) 카드 영역은 불투명(원래 스타일) 유지: 매트릭스가 비치지 않게 */
.dp-card.data-card{
  background: var(--card);
  backdrop-filter: none;
  -webkit-backdrop-filter: none;
}
    .filter-card{ position:relative; z-index:20; overflow:visible; }
    /* Make the top filter card semi-transparent like oqc_view (matrix visible through) */
    .dp-card.filter-card{ background: rgba(0,0,0,0.52); }

    .data-card{ position:relative; z-index:1; }
    .dp-card-h{ padding: 14px 16px; border-bottom: 1px solid var(--line); display:flex; gap:10px; align-items:center; justify-content:space-between; }
    .dp-card-b{ padding: 14px 16px; }
    .title{ font-size: 16px; font-weight: 700; letter-spacing: .2px; }
    .sub{ font-size: 12px; color: var(--muted); }

    .filters{ display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
    /* Force filter layout even if global CSS overrides .filters */
    .dp-card.filter-card form.filters{ display:flex !important; gap:10px !important; flex-wrap:wrap !important; align-items:flex-end !important; }
    .dp-card.filter-card form.filters .f{ display:flex !important; flex-direction:column !important; gap:6px !important; }
    /* Make native <select> match the rest of JMP Assist controls */
    .dp-card.filter-card form.filters select{
      -webkit-appearance:none; -moz-appearance:none; appearance:none;
      padding-right: 30px;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24'%3E%3Cpath fill='rgba(255,255,255,0.75)' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 10px center;
      background-size: 12px 12px;
    }
    .dp-card.filter-card form.filters select::-ms-expand{ display:none; }

    .f{ display:flex; flex-direction:column; gap:6px; }
    .f label{ font-size: 11px; color: var(--muted); }
    select, input[type="number"]{
      height: 34px;
      padding: 0 10px;
      background: rgba(0,0,0,0.35);
      color: var(--text);
      border: 1px solid var(--line);
      border-radius: 10px;
      outline:none;
    }
    select:focus, input:focus{ border-color: rgba(29,185,84,0.55); }
    .btn{
      height: 34px;
      padding: 0 14px;
      border-radius: 10px;
      border: 1px solid rgba(29,185,84,0.55);
      background: rgba(29,185,84,0.18);
      color: var(--text);
      font-weight: 700;
      cursor:pointer;
    }
    .btn:hover{ background: rgba(29,185,84,0.28); }

    .f.full{ flex: 1 1 100%; }
    .checkgrid{
      display:flex; flex-wrap:wrap; gap:8px;
      padding: 8px 10px;
      background: rgba(0,0,0,0.25);
      border: 1px solid var(--line);
      border-radius: 10px;
      align-items:center;
    }
    .chk{
      display:flex; align-items:center; gap:6px;
      padding: 4px 10px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.10);
      background: rgba(0,0,0,0.20);
      cursor:pointer;
      user-select:none;
    }
    .chk input{ accent-color: var(--accent); }
    .chk:hover{ border-color: rgba(29,185,84,0.35); }
    .mini{
      height: 26px;
      padding: 0 10px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.06);
      color: rgba(255,255,255,0.85);
      font-size: 12px;
      cursor:pointer;
    }
    .mini:hover{ background: rgba(255,255,255,0.10); }
    .chip{
      font-size: 12px;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: rgba(0,0,0,0.25);
      color: var(--muted);
      white-space:nowrap;
    }
    .chip.warn{ border-color: rgba(255,204,0,0.45); color: rgba(255,204,0,0.95); }
    .chip.bad{ border-color: rgba(255,82,82,0.45); color: rgba(255,82,82,0.95); }

    .table-wrap{ position:relative; min-height: 220px; overflow:auto; border-radius: 12px; border: 0;  background: #202124; }
    .no-results-overlay{
      position:absolute;
      left:0; right:0; bottom:0;
      top: 44px; /* leave space for table header */
      display:flex;
      align-items:center;
      justify-content:center;
      pointer-events:none;
      color: var(--muted);
      font-size: 13px;
    }
    .no-results-overlay span{
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.12);
      background: rgba(0,0,0,0.25);
    }
    table{ border-collapse:collapse; width:max-content; min-width:100%; }
    th,td{
      border-bottom: 1px solid rgba(255,255,255,0.08);
      padding: 8px 10px;
      font-size: 12px;
      text-align:center;
      white-space:nowrap;
    }
    td.rownum{ text-align:right; color: rgba(255,255,255,0.75); }
    th{
      position: sticky;
      top: 0;
      background: #303134;
      z-index: 2;
      color: rgba(255,255,255,0.85);
      font-weight: 800;
    }
    tr:hover td{ background: rgba(29,185,84,0.08); }

    /* ✅ 매트릭스 비침 방지: 하단(데이터) 영역은 불투명 유지 */
    .dp-card.data-card{ background:#2b2b2b !important; backdrop-filter:none !important; -webkit-backdrop-filter:none !important; }
    .dp-card.data-card .table-wrap{ background:#202124 !important; backdrop-filter:none !important; -webkit-backdrop-filter:none !important; }
    .dp-card.data-card table{ background:#202124 !important; }
    .dp-card.data-card tbody td{ background:#202124 !important; }
    .dp-card.data-card tbody tr:hover td{ background: rgba(29,185,84,0.08) !important; }

    .ok{ color: rgba(29,185,84,0.95); font-weight: 800; }
    .ng-hi{ color: rgba(255,82,82,0.95); font-weight: 800; }
    .ng-lo{ color: rgba(77,166,255,0.95); font-weight: 800; }
    .muted{ color: var(--muted); }
.hint{ font-size: 11px; color: var(--muted); margin-top: 6px; }

/* compact multi-select dropdown (like select) */
.ms{ position: relative; min-width: 240px; }
.ms.ms-years{ min-width: 120px; width: 120px; }
.ms.ms-years .ms-panel{ width: 240px; }
.ms.ms-page{ min-width: 150px; width: 150px; }
.ms.ms-page .ms-panel{ left:auto; right:0; width: 320px; }

.ms.ms-type{ min-width: 150px; width: 150px; }
.ms.ms-type .ms-panel{ width: 260px; }
.ms.ms-model{ min-width: 200px; width: 200px; }
.ms.ms-model .ms-panel{ width: 280px; max-height: 240px; overflow:hidden; }
#ms-model-list{ max-height: 180px; overflow:auto; padding-right: 2px; }
.ms-grid-model .ms-datebtn{ align-items:flex-start; text-align:left; padding: 6px 8px; }
.ms-grid-type{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
.ms-grid-model{ grid-template-columns: repeat(1, minmax(0, 1fr)); }
.ms-grid-model .ms-datebtn{ align-items:flex-start; text-align:left; padding: 8px 10px; }
.ms-grid-model .ms-datebtn-md{ width:100%; text-align:left; font-weight:700; }

.ms-toggle{
  width: 100%;
  height: 34px;
  padding: 0 10px;
  background: rgba(0,0,0,0.35);
  color: var(--text);
  border: 1px solid var(--line);
  border-radius: 10px;
  outline:none;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  cursor:pointer;
}
.ms-toggle:hover{ border-color: rgba(29,185,84,0.35); }
.ms-summary{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width: calc(100% - 22px); text-align:left; }
.ms-caret{ color: var(--muted); font-size: 12px; }
.ms-panel{
  position:absolute;
  z-index: 9999;
  top: 38px;
  left: 0;
  width: 360px;
  max-width: min(360px, calc(100vw - 60px));
  max-height: 280px;
  overflow:auto;
  background: rgba(0,0,0,0.75);
  border: 1px solid var(--line);
  border-radius: 12px;
  box-shadow: 0 14px 45px rgba(0,0,0,0.35);
  padding: 10px;
  display:none;
  backdrop-filter: blur(10px);
}
.ms.open .ms-panel{ display:block; }
.ms-actions{ display:flex; gap:8px; margin-bottom: 10px; position: sticky; top: 0; background: rgba(0,0,0,0.70); padding-bottom: 8px; }
.ms-list{ display:grid; gap:6px; }
.ms-grid-tools{ grid-template-columns: repeat(6, minmax(0, 1fr)); }
.ms-grid-months{ grid-template-columns: repeat(4, minmax(0, 1fr)); }
.ms-grid-years{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
.ms-grid-dates{ grid-template-columns: repeat(5, minmax(0, 1fr)); }

/* FAI multi-select (AOI): page-date style grid + search */
.ms.ms-fai{ min-width: 200px; }
.ms.ms-fai .ms-panel{ width: 340px; max-height: 340px; overflow:hidden; }
#ms-fai-list{ max-height: 250px; overflow:auto; padding-right: 2px; }
.ms-grid-fai{ grid-template-columns: repeat(4, minmax(0, 1fr)); }
.ms-fai-head{ display:flex; flex-direction:column; align-items:stretch; gap:6px; }
.ms-fai-toolbar{ display:flex; gap:8px; align-items:center; }
.ms-fai-search{
  height: 30px;
  padding: 0 10px;
  background: rgba(0,0,0,0.35);
  color: var(--text);
  border: 1px solid var(--line);
  border-radius: 10px;
  outline:none;
  flex: 1;
}
.ms-fai-search:focus{ border-color: rgba(29,185,84,0.55); }
.ms-fai-hint{ font-size: 11px; color: rgba(255,255,255,0.75); padding: 0 2px; }
.ms-grid-fai .ms-datebtn{ min-width: 0; }
.ms-grid-fai .ms-datebtn-md{ font-size: 12px; font-weight: 700; }


.ms-datebtn{
  appearance:none;
  -webkit-appearance:none;
  border: 1px solid rgba(255,255,255,0.08);
  background: rgba(255,255,255,0.04);
  color: var(--text);
  padding: 6px 6px;
  border-radius: 10px;
  cursor:pointer;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap: 1px;
  user-select:none;
  min-width: 64px;
}
.ms-datebtn-y{ font-size:10px; opacity:0.85; line-height:1.05; }
.ms-datebtn-md{ font-size:12px; font-weight:600; line-height:1.05; }
.ms-datebtn:hover{ border-color: rgba(29,185,84,0.35); background: rgba(29,185,84,0.10); }
.ms-datebtn.active{ border-color: rgba(29,185,84,0.55); background: rgba(29,185,84,0.18); }

.ms-opt input{ display:none; }
.ms-grid-years .ms-datebtn,
.ms-grid-months .ms-datebtn{ min-width: 0; }

.ms-item{
  display:flex; align-items:center; gap:8px;
  padding: 6px 8px;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,0.08);
  background: rgba(255,255,255,0.04);
  cursor:pointer;
  user-select:none;
  font-size: 12px;
}
.ms-item:hover{ border-color: rgba(29,185,84,0.35); background: rgba(29,185,84,0.10); }
.ms-item input{ accent-color: var(--accent); }

/* row number sticky */
.rownum{ text-align:right; color: var(--muted); width: 56px; }
td.sticky-left, th.sticky-left{ position: sticky; left: 0; z-index: 5; }
    th.sticky-left{ background: #303134; }
tbody td.sticky-left{ z-index: 3; background: #202124; }
tr:hover td.sticky-left{ background: rgba(29,185,84,0.10); }

/* tooltip for USL/LSL on data cells */
td.cell{ position: relative; }
td.cell[data-tip]:hover::after{
  content: attr(data-tip);
  position:absolute;
  left: 50%;
  transform: translateX(-50%);
  top: -34px;
  background: rgba(0,0,0,0.90);
  border: 1px solid var(--line);
  padding: 6px 10px;
  border-radius: 10px;
  color: rgba(255,255,255,0.85);
  font-size: 11px;
  white-space: nowrap;
  z-index: 99;
  pointer-events:none;
}
td.cell[data-tip]:hover::before{
  content:'';
  position:absolute;
  left: 50%;
  transform: translateX(-50%);
  top: -10px;
  border: 6px solid transparent;
  border-top-color: rgba(0,0,0,0.90);
  z-index: 99;
  pointer-events:none;
}

  
    .page-select{height:34px; border-radius:10px; padding:0 10px; border:1px solid rgba(255,255,255,.18); background:rgba(0,0,0,.35); color:#d8ffe4;}

/* ===== Matrix background dim overlay (keep matrix visible) ===== */
#matrix-dim{
  position:fixed;
  inset:0;
  pointer-events:none;
  z-index:1; /* above matrix canvas, below content */
  background:
    radial-gradient(1000px 600px at 50% 10%, rgba(0,0,0,0.08), rgba(0,0,0,0.18)),
    rgba(0,0,0,0.06);
}

/* Try to target common matrix canvas ids/classes without touching other canvases */
#matrix-bg, #matrixCanvas, #matrix-canvas, canvas.matrix-bg, canvas.matrixCanvas, canvas#matrix-bg, canvas#matrixCanvas{
  position:fixed !important;
  inset:0 !important;
  z-index:0 !important;
  pointer-events:none !important;
}

/* Ensure app/content stays above dim */
.page-wrap, .container, .content, .main, .app, #app{
  position:relative;
  z-index:2;
}



/* ===== FORCE matrix-visible glass for data area (QA style) ===== */
/* ipqc_view had .dp-card.data-card opaque; override to allow matrix texture to show */
.dp-card.data-card{
  background: rgba(0,0,0,0.34) !important;
  border: 1px solid rgba(255,255,255,0.07) !important;
  backdrop-filter: blur(8px) saturate(120%) !important;
  -webkit-backdrop-filter: blur(8px) saturate(120%) !important;
}
.dp-card.data-card .table-wrap{
  background: rgba(0,0,0,0.18) !important;
  backdrop-filter: blur(5px) saturate(115%) !important;
  -webkit-backdrop-filter: blur(5px) saturate(115%) !important;
}
/* keep header readable */
.dp-card.data-card thead th{
  background: rgba(0,0,0,0.62) !important;
}
/* body cells transparent so matrix shows through */
.dp-card.data-card table,
.dp-card.data-card tbody td{
  background: transparent !important;
}
/* hover still visible */
.dp-card.data-card tbody tr:hover td{
  background: rgba(29,185,84,0.10) !important;
}
/* sticky-left column needs a bit more solid so text doesn't disappear */
.dp-card.data-card th.sticky-left{
  background: rgba(0,0,0,0.72) !important;
}
.dp-card.data-card tbody td.sticky-left{
  background: rgba(0,0,0,0.30) !important;
}



/* ===== FIX: ensure matrix behind is not hidden by opaque page background ===== */
html, body { background: transparent !important; }
:root { --bg: transparent !important; }
.dp-wrap, .dp-container { background: transparent !important; }



/* ===== Tone fix: avoid 'hovered/green wash' look (match QA/oqc feel) ===== */
/* Darken top a bit so the filter card doesn't get green-tinted by matrix */
#matrix-dim{
  background:
    linear-gradient(to bottom, rgba(0,0,0,0.42), rgba(0,0,0,0.24) 45%, rgba(0,0,0,0.18)),
    radial-gradient(1200px 700px at 50% 0%, rgba(0,0,0,0.06), rgba(0,0,0,0.38));
}

/* Reduce matrix brightness/saturation so it reads as texture, not a glow */
#matrix-bg, #matrixCanvas, #matrix-canvas,
canvas.matrix-bg, canvas.matrixCanvas, canvas#matrix-bg, canvas#matrixCanvas{
  filter: brightness(0.78) saturate(0.85);
}

/* Glass blur disabled (match OQC style & avoid double-border halo) */
.dp-card{backdrop-filter:none !important;-webkit-backdrop-filter:none !important;}
/* Keep cards slightly more solid like QA */
.dp-card.filter-card,
.dp-card.data-card{
  background: rgba(0,0,0,0.58) !important;
}



/* ===== FIX: stop card 'hover/flicker' transparency changes ===== */
/* Some CSS adds hover styles/transition that makes cards look like mouse hover. Lock them. */
.dp-card,
.dp-card.filter-card,
.dp-card.data-card{
  transition: none !important;
}

/* Force same background on hover/focus to prevent opacity switching */
.dp-card:hover,
.dp-card:focus-within,
.dp-card.filter-card:hover,
.dp-card.filter-card:focus-within,
.dp-card.data-card:hover,
.dp-card.data-card:focus-within{
  background: rgba(0,0,0,0.58) !important;
  box-shadow: 0 10px 28px rgba(0,0,0,0.45) !important; /* keep stable */
  transform: none !important;
}

/* If there is a generic hover that increases transparency, neutralize it */
.dp-card:hover *{
  transition: none !important;
}



/* ===== FIX: remove outer 'big card' frame (was not in original) ===== */
/* Some wrapper got a border/outline; neutralize it. */
.page-card, .main-card, .app-card, .dp-panel, .dp-shell, .dp-page, .dp-section, .dp-outer, .outer-card{
  border: none !important;
  outline: none !important;
  box-shadow: none !important;
  background: transparent !important;
}
/* Commonly the top-level .dp-card wrapping everything can look like a frame */
.dp-card.page-shell, .dp-card.outer-shell, .dp-card.app-shell{
  border: none !important;
  outline: none !important;
  box-shadow: none !important;
  background: transparent !important;
}

/* v10.9: remove unintended outer frame around the whole page */
.dp-wrap{ background: transparent !important; border:0 !important; outline:0 !important; box-shadow:none !important; }
.dp-wrap > .dp-container{
  background: transparent !important;
  border: 0 !important;
  outline: 0 !important;
  box-shadow: none !important;
  border-radius: 0 !important;
  backdrop-filter: none !important;
}
/* Some layouts wrap the content in a <main> or .content area */
main, .content, .content-area, .main, .main-content{
  background: transparent !important;
  border: 0 !important;
  outline: 0 !important;
  box-shadow: none !important;
}



/* ===== UI polish: remove inner border around the top filter bar (측정타입 영역) ===== */
/* Keep input borders; remove only the container bar/frame border/shadow. */
.filter-bar, .filter-panel, .dp-filter, .filters, .filter-row, .filter-wrap, .filter-container{
  border: none !important;
  outline: none !important;
  box-shadow: none !important;
}
/* Sometimes the bar border is drawn via pseudo elements */
.filter-bar::before, .filter-bar::after,
.filter-panel::before, .filter-panel::after,
.dp-filter::before, .dp-filter::after,
.filters::before, .filters::after,
.filter-row::before, .filter-row::after{
  content: none !important;
  border: none !important;
}


/* Avoid double border: card already has border */
.data-card .table-wrap{border:0 !important; box-shadow:none !important;}


/* ===== v10.15: truly single border + no hover flicker ===== */
.dp-card,
.dp-card:hover,
.dp-card:focus,
.dp-card:active,
.dp-card:focus-within{
  border: 1px solid rgba(255,255,255,0.10) !important;
  outline: 0 !important;
  background-image: none !important;
  box-shadow: 0 10px 30px rgba(0,0,0,0.35) !important;
  transform: none !important;
  filter: none !important;
  opacity: 1 !important;
  transition: none !important;
}
.dp-card.filter-card,
.dp-card.filter-card:hover,
.dp-card.filter-card:focus-within{ background: rgba(0,0,0,0.60) !important; }

.dp-card.mid-card,
.dp-card.mid-card:hover,
.dp-card.mid-card:focus-within{ background: rgba(0,0,0,0.30) !important; }

.dp-card.data-card,
.dp-card.data-card:hover,
.dp-card.data-card:focus-within{ background: rgba(0,0,0,0.55) !important; }

/* kill any pseudo-element borders/glows that can look like a 2nd border */
.dp-card::before, .dp-card::after,
.dp-card:hover::before, .dp-card:hover::after{
  content: none !important;
  display: none !important;
}

/* nothing inside cards should animate on hover */
.dp-card *{ transition: none !important; }



/* === HARD OVERRIDES (prevent "card jiggle" + remove nested-card look) === */
.dp-card{ 
  --card-bg: rgba(0,0,0,.70);
  --card-border: rgba(255,255,255,.08);
  --card-shadow: 0 12px 45px rgba(0,0,0,.45);
  background: var(--card-bg) !important;
  border: 1px solid var(--card-border) !important;
  box-shadow: var(--card-shadow) !important;
  transition: none !important;
  transform: none !important;
  filter: none !important;
  opacity: 1 !important;
}
.dp-card::before,.dp-card::after{transition:none !important;}
.dp-card:hover,.dp-card:focus-within,.dp-card:active{
  background: var(--card-bg) !important;
  border-color: var(--card-border) !important;
  box-shadow: var(--card-shadow) !important;
  transform: none !important;
  filter: none !important;
  opacity: 1 !important;
}

/* Filter card must NOT contain another "inner card" background/border */
.filter-card .filters, .filter-card form.filters{
  background: transparent !important;
  border: 0 !important;
  box-shadow: none !important;
  padding: 0 !important;
}

/* Some global styles add an inset border via backdrop wrappers; kill those inside the filter bar */
.filter-card .dp-card, .filter-card .card, .filter-card .inner-card, .filter-card .glass, .filter-card .glass-card{
  background: transparent !important;
  border: 0 !important;
  box-shadow: none !important;
}
</style>

<!-- v10.16: single-border cards (no inner stroke) + disable card hover background flicker -->
<style id="v10_16_single_border_no_inner_stroke">
  /* Root card: one stroke only (remove the nested-background "second border" look) */
  .dp-card{
    background: rgba(0,0,0,0.58) !important;
    border: 1px solid rgba(255,255,255,0.12) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.55) !important;
    transition: none !important;
    transform: none !important;
    filter: none !important;
    opacity: 1 !important;
  }
  /* dp-card-b used to be darker than dp-card; that contrast created an inner edge */
  .dp-card > .dp-card-b{
    background: transparent !important;
  }
  /* Kill any pseudo-element stroke variants */
  .dp-card::before,
  .dp-card::after{
    content: none !important;
    display: none !important;
  }

  /* No hover/focus visual toggles on the card container (keep EXACTLY same) */
  .dp-card:hover,
  .dp-card:focus-within,
  .dp-card:active{
    background: rgba(0,0,0,0.58) !important;
    border-color: rgba(255,255,255,0.12) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.55) !important;
    transition: none !important;
    transform: none !important;
    filter: none !important;
    opacity: 1 !important;
  }

  /* Safety: if the filter form/container ever gets a border from another rule, drop it */
  form.filters,
  .filters,
  .filter-row,
  .filter-group{
    border: 0 !important;
    outline: 0 !important;
    box-shadow: none !important;
    background: transparent !important;
    transition: none !important;
  }

</style>

<!-- v10.18: filter card should be a single shell (remove inner card) -->
<style id="v10_18_filter_single_shell">
  .dp-card.filter-card > .filter-inner{
    padding: 14px 16px !important;
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
  }
  /* safety if dp-card-b remains */
  .dp-card.filter-card > .dp-card-b{
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
  }
  /* ensure the form itself never paints a bar */
  .dp-card.filter-card form.filters{
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
    border-radius: 0 !important;
  }
</style>


<style>
/* ===== IPQC Graph Modal ===== */
.btn.btn-graph{
  padding:8px 14px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,0.18);
  background: rgba(40,120,90,0.35);
  color:#eafff5;
  font-weight:700;
  cursor:pointer;
}
.btn.btn-graph:hover{ background: rgba(50,150,110,0.45); }

.graph-modal-overlay{
  position:fixed; inset:0;
  background: rgba(0,0,0,0.55);
  display:none;
  z-index: 9999;
}
.graph-modal-overlay.show{ display:flex; align-items:center; justify-content:center; }

.graph-modal{
  width: min(1400px, 96vw);
  height: min(820px, 92vh);
  background: rgba(10,14,12,0.92);
  border:1px solid rgba(255,255,255,0.14);
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.55);
  overflow:hidden;
  display:flex;
  flex-direction:column;
}
.graph-modal .gm-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  padding:14px 16px;
  border-bottom:1px solid rgba(255,255,255,0.10);
  gap:12px;
}
.graph-modal .gm-title{
  font-size:18px; font-weight:800; letter-spacing:0.2px;
}
.graph-modal .gm-sub{
  margin-top:4px;
  font-size:12px;
  opacity:0.78;
}
.graph-modal .gm-actions{
  display:flex; gap:8px; align-items:center; flex-wrap:wrap;
}
.graph-modal .gm-actions .btn{
  padding:7px 12px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,0.16);
  background: rgba(255,255,255,0.06);
  color:#e9f6ef;
  cursor:pointer;
  font-weight:700;
}
.graph-modal .gm-actions .btn:hover{ background: rgba(255,255,255,0.10); }
.graph-modal .gm-actions .btn.primary{
  background: rgba(45,160,110,0.30);
  border-color: rgba(70,220,150,0.35);
}
.graph-modal .gm-body{
  flex:1;
  display:flex;
  min-height:0;
}
.graph-modal .gm-left{
  width: 280px;
  border-right:1px solid rgba(255,255,255,0.10);
  padding:12px;
  display:flex;
  flex-direction:column;
  gap:10px;
}
.graph-modal .gm-left .gm-search{
  display:flex;
  flex-direction:column;
  gap:8px;
}
.graph-modal input.gm-searchbox{
  width:100%;
  box-sizing:border-box;
  padding:10px 12px;
  border-radius: 12px;
  border:1px solid rgba(255,255,255,0.12);
  background: rgba(0,0,0,0.25);
  color:#eaf5ef;
  outline:none;
}
.graph-modal select.gm-cavity{
  width:100%;
  box-sizing:border-box;
  padding:9px 10px;
  border-radius: 12px;
  border:1px solid rgba(255,255,255,0.12);
  background: rgba(0,0,0,0.25);
  color:#eaf5ef;
  outline:none;
}
.graph-modal .gm-left .gm-meta{
  font-size:12px;
  opacity:0.80;
  display:flex;
  justify-content:space-between;
}
.graph-modal .gm-fai-list{
  flex:1;
  min-height:0;
  overflow:auto;
  padding-right:4px;
}
.graph-modal .gm-fai-item{
  padding:8px 8px;
  border-radius: 12px;
  border:1px solid rgba(255,255,255,0.08);
  background: rgba(255,255,255,0.03);
  margin-bottom:8px;
  cursor:pointer;
  user-select:none;
}
.graph-modal .gm-fai-item:hover{ background: rgba(255,255,255,0.06); }
.graph-modal .gm-fai-item label{
  display:flex;
  gap:10px;
  align-items:center;
  cursor:pointer;
}
.graph-modal .gm-fai-item input{
  transform: scale(1.05);
}
.graph-modal .gm-fai-empty{
  padding:10px;
  opacity:0.75;
}

.graph-modal .gm-right{
  flex:1;
  min-width:0;
  padding:12px;
  display:flex;
  flex-direction:column;
  gap:10px;
}
.graph-modal .gm-hint{
  font-size:12px;
  opacity:0.78;
}
.graph-modal .gm-gridwrap{
  flex:1;
  min-height:0;
  overflow:auto;
  border:1px solid rgba(255,255,255,0.10);
  border-radius: 14px;
  background: rgba(0,0,0,0.20);
  padding:10px;
}
.graph-modal .gm-grid{
  display:grid;
  gap:10px;
  align-items:start;
}
.graph-modal .gm-cell{
  border:1px solid rgba(255,255,255,0.10);
  border-radius: 14px;
  background: rgba(255,255,255,0.03);
  padding:10px;
}
.graph-modal .gm-cell.head{
  background: rgba(255,255,255,0.06);
  font-weight:800;
  text-align:center;
}
.graph-modal .gm-cell.label{
  background: rgba(255,255,255,0.02);
  font-weight:700;
  font-size:12px;
  line-height:1.25;
}
.graph-modal .gm-canvas{
  width:100%;
  height:130px;
  display:block;
}
.graph-modal .gm-nodata{
  padding:12px;
  opacity:0.75;
}
</style>

</head>
<body>
<!-- IPQC_VIEW PATCH v10.22 -->

<div class="dp-wrap">
    <?php if(!$EMBED): ?>
      <?php require_once $ROOT . '/inc/sidebar.php'; ?>
      <?php require_once $ROOT . '/inc/dp_userbar.php'; ?>
    <?php endif; ?>

    <div class="dp-container">
      <div class="page-head"><div class="page-title">JMP Assist</div></div>

      <div class="dp-card filter-card">
        <div class="filter-inner">
          <form method="get" class="filters" id="filterForm">
            <input type="hidden" name="page_all" id="pageAllHidden" value="<?= $pageAll ? '1' : '0' ?>">
            <input type="hidden" name="page_date" id="pageDateHidden" value="<?= h($pageDate) ?>">
            <input type="hidden" name="page_dates" id="pageDatesHidden" value="<?= h(implode(',', $pageDatesSel ?? [])) ?>">

            <?php if($EMBED): ?><input type="hidden" name="embed" value="1"/><?php endif; ?>
            <input type="hidden" name="run" value="1"/>
            <input type="hidden" name="months_present" value="1"/>
            <div class="f">
              <label>측정 타입</label>
              <div class="ms ms-type ms-single" id="ms-type" data-group="type_single">
                <input type="hidden" name="type" id="type" value="<?= h($type) ?>">
                <button type="button" class="ms-toggle" onclick="toggleMs('ms-type')">
                  <span class="ms-summary" id="ms-type-summary"><?= h($TYPE_MAP[$type]['label'] ?? $type) ?></span>
                  <span class="ms-caret">▾</span>
                </button>
                <div class="ms-panel">
                  <div class="ms-list ms-grid-type">
                    <?php foreach($TYPE_MAP as $k=>$v): ?>
                      <button type="button"
                        class="ms-datebtn ms-singlebtn <?= $k===$type?'active':'' ?>"
                        data-value="<?= h($k) ?>"
                        data-label="<?= h($v['label']) ?>">
                        <span class="ms-datebtn-md"><?= h($v['label']) ?></span>
                      </button>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="f">
              <label>모델</label>
              <div class="ms ms-model ms-single" id="ms-model" data-group="model_single">
                <input type="hidden" name="model" id="model" value="<?= h($model) ?>">
                <button type="button" class="ms-toggle" onclick="toggleMs('ms-model')">
                  <span class="ms-summary" id="ms-model-summary"><?= h($model) ?></span>
                  <span class="ms-caret">▾</span>
                </button>
                <div class="ms-panel">
                  <div class="ms-list ms-grid-model" id="ms-model-list">
                    <?php foreach($models as $m): ?>
                      <button type="button"
                        class="ms-datebtn ms-singlebtn <?= $m===$model?'active':'' ?>"
                        data-value="<?= h($m) ?>"
                        data-label="<?= h($m) ?>">
                        <span class="ms-datebtn-md"><?= h($m) ?></span>
                      </button>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
<div class="f">
  <label>Tool (복수 선택)</label>
  <div class="ms" id="ms-tools" data-group="tools" data-type="<?= h($type) ?>" data-model="<?= h($model) ?>">
    <button type="button" class="ms-toggle" onclick="toggleMs('ms-tools')">
      <span class="ms-summary" id="ms-tools-summary"></span>
      <span class="ms-caret">▾</span>
    </button>
    <div class="ms-panel">
      <div class="ms-actions">
        <button type="button" class="mini" onclick="checkAllIn('ms-tools', true); syncMs('ms-tools');">전체</button>
        <button type="button" class="mini" onclick="checkAllIn('ms-tools', false); syncMs('ms-tools');">해제</button>
      </div>
      <div class="ms-list ms-grid-tools" id="ms-tools-list" style="<?= empty($tools) ? 'display:none;' : '' ?>">
        <?php if (!empty($tools)): ?>
          <?php foreach($tools as $t): ?>
            <label class="ms-item">
              <input type="checkbox" name="tools[]" value="<?= h($t) ?>"
                <?= (in_array($t, $toolsSel, true)) ? 'checked' : '' ?>
                onchange="syncMs('ms-tools')">
              <span><?= h($t) ?></span>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="ms-empty" id="ms-tools-empty" style="grid-column:1/-1; padding:10px; opacity:0.85; <?= empty($tools) ? '' : 'display:none;' ?>">
        툴 목록이 비어있습니다. (데이터 없음 또는 DB 조회 실패)
        <div style="margin-top:8px;">
          <button type="button" class="mini" onclick="location.reload()">새로고침</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if(in_array($type, ['AOI','OMM','CMM','OQC'], true)): ?>
<div class="f">
  <label><?= ($type==='CMM'||$type==='OQC') ? 'Point (복수 선택)' : 'FAI (복수 선택)' ?></label>
  <div class="ms ms-fai" id="ms-fai" data-group="fai">
    <button type="button" class="ms-toggle" onclick="toggleMs('ms-fai')">
      <span class="ms-summary" id="ms-fai-summary"></span>
      <span class="ms-caret">▾</span>
    </button>
    <div class="ms-panel">
      <div class="ms-actions ms-fai-head">
        <div class="ms-fai-toolbar">
          <input type="text" id="ms-fai-search" class="ms-fai-search" placeholder="검색" oninput="faiApplyFilter(this.value)" autocomplete="off">
          <button type="button" class="mini" onclick="faiClearAll(true)">해제</button>
        </div>
        <div class="ms-fai-hint" id="ms-fai-hint" style="display:none;"></div>
      </div>

      <div class="ms-list ms-grid-fai" id="ms-fai-list"></div>
      <div class="ms-empty" id="ms-fai-empty" style="padding:10px; opacity:0.85; display:none;">
        목록을 만들 수 없습니다. (데이터/매핑이 없을 수 있습니다.)
      </div>
    </div>

    <!-- Hidden inputs (submitted as fai[]) -->
    <div id="ms-fai-hidden" style="display:none;">
      <?php foreach($faiSel as $fv): ?>
        <input type="hidden" name="fai[]" value="<?= h($fv) ?>">
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="f">
              <label>년도 (복수 선택)</label>
              <div class="ms ms-years" id="ms-years" data-group="years">
                <button type="button" class="ms-toggle" onclick="toggleMs('ms-years')">
                  <span class="ms-summary" id="ms-years-summary"></span>
                  <span class="ms-caret">▾</span>
                </button>
                <div class="ms-panel">
                  <div class="ms-actions">
                    <button type="button" class="mini" onclick="checkAllIn('ms-years', true); syncMs('ms-years');">전체</button>
                    <button type="button" class="mini" onclick="checkAllIn('ms-years', false); syncMs('ms-years');">해제</button>
                  </div>
                  <div class="ms-list ms-grid-years">
                    <?php foreach($yearsAvail as $yy): $yy = (int)$yy; ?>
                      <label class="ms-datebtn ms-opt <?= in_array($yy, $yearsSel, true) ? 'active' : '' ?>">
  <input type="checkbox" name="years[]" value="<?= $yy ?>"
    <?= in_array($yy, $yearsSel, true) ? 'checked' : '' ?>
    onchange="syncMs('ms-years')">
  <span class="ms-datebtn-md"><?= $yy ?></span>
</label>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="f">
  <label>월 (복수 선택)</label>
  <div class="ms" id="ms-months" data-group="months">
    <button type="button" class="ms-toggle" onclick="toggleMs('ms-months')">
      <span class="ms-summary" id="ms-months-summary"></span>
      <span class="ms-caret">▾</span>
    </button>
    <div class="ms-panel">
      <div class="ms-actions">
        <button type="button" class="mini" onclick="checkAllIn('ms-months', true); syncMs('ms-months');">전체</button>
        <button type="button" class="mini" onclick="checkAllIn('ms-months', false); syncMs('ms-months');">해제</button>
      </div>
      <div class="ms-list ms-grid-months">
        <?php for($m=1;$m<=12;$m++): ?>
          <label class="ms-datebtn ms-opt <?= in_array($m, $months, true) ? 'active' : '' ?>">
  <input type="checkbox" name="months[]" value="<?= $m ?>"
    <?= in_array($m, $months, true) ? 'checked' : '' ?>
    onchange="syncMs('ms-months')">
  <span class="ms-datebtn-md"><?= $m ?>월</span>
</label>
        <?php endfor; ?>
      </div>
    </div>
  </div>
</div>
            <div class="f">
              <label>&nbsp;</label>
              <div style="display:flex; gap:8px; align-items:center;">
                <button class="btn" id="btnRun" type="submit">조회</button>
              </div>
            </div>
          </form>

          <?php if($doQuery && $meta['error']): ?>
            <div class="chip bad">ERR: <?= h($meta['error']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if($doQuery && !$meta['error']): ?>
        <?php
          $ui_model_label  = ($model !== '') ? $model : '전체';
          $ui_tools_label  = (!empty($toolsSel)) ? implode(', ', $toolsSel) : '(선택 없음)';
          $ui_years_label  = (!empty($yearsSel)) ? implode(',', $yearsSel) : '전체';
          $ui_months_label = (!empty($months)) ? implode(',', $months) : '(선택 없음)';
          $ui_period_label = $ui_years_label . ' / ' . $ui_months_label;
          $ui_shown_rows   = is_array($displayResults) ? count($displayResults) : 0;
          $ui_total_rows   = ($totalRowsAll > 0) ? $totalRowsAll : (($totalRows > 0) ? (int)$totalRows : $ui_shown_rows);
        ?>
        <div style="height:14px"></div>

        <div class="dp-card mid-card">
          <div class="dp-card-b mid-b">
            <div class="mid-left">
              <div class="chip"><?= h($TYPE_MAP[$type]['label']) ?></div>
              <div class="mid-summary">
                <span class="muted">모델:</span> <?= h($ui_model_label) ?>
                <span class="sep">|</span>
                <span class="muted">Tool:</span> <?= h($ui_tools_label) ?>
                <span class="sep">|</span>
                <span class="muted">기간:</span> <?= h($ui_period_label) ?>
                <span class="sep">|</span>
                <span class="muted">표시/전체:</span> <?= number_format($ui_shown_rows) ?>/<?= number_format($ui_total_rows) ?>
              </div>
            </div>
<div class="mid-right">              <button class="btn btn-graph" type="button" id="btnProcessCapabilityOpen" onclick="__ipqcOpenProcessCapabilitySafe()">공정 능력</button>
              <button class="btn btn-graph" type="button" id="btnQuickGraphOpen" onclick="__ipqcOpenQuickGraphSafe()">그래프 빌더</button>
              <button class="btn btn-excel" type="button" onclick="exportExcelAll()">전체EXCEL</button>
              <?php if(!empty($pageDates) && !empty($pageDate)): ?>
              <button class="btn btn-excel" type="button" onclick="exportExcelPage()">페이지EXCEL</button>
              <?php endif; ?>
              <?php if(!empty($pageDates)): ?>
                <div class="ms ms-page" id="ms-page" data-group="page_date" style="display:inline-block; margin-left:8px; vertical-align:middle;">
                  <button type="button" class="ms-toggle" onclick="toggleMs('ms-page')">
                    <span class="ms-summary" id="ms-page-summary"><?= h($pageAll ? 'ALL' : $pageDate) ?></span>
                    <span class="ms-caret">▾</span>
                  </button>
                  <div class="ms-panel">
                    <div class="ms-actions" style="display:flex; justify-content:space-between; gap:10px;">
                      <button type="button" class="mini" onclick="pagePrev()">이전</button>
                      <button type="button" class="mini" onclick="pageNext()">다음</button>
                    </div>
                    <div class="ms-list ms-grid-dates">
                      
                      <button type="button" class="ms-datebtn ms-datebtn-all <?= $pageAll ? 'active' : '' ?>" onclick="setPageAll()"><span class="ms-datebtn-md" style="display:flex; align-items:center; justify-content:center; width:100%;">ALL</span></button>
<?php foreach($pageDates as $d): 
                        $y = '';
                        $md = $d;
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                          $y  = substr($d, 0, 4);
                          $md = substr($d, 5); // MM-DD
                        }
                      ?>
                        <button type="button" class="ms-datebtn <?= (!$pageAll && ($d===$pageDate || (!empty($pageDatesSel) && in_array($d,$pageDatesSel,true))))?'active':'' ?>" onclick="setPageDate('<?= h($d) ?>', event)"><?php if ($y !== ''): ?><span class="ms-datebtn-y"><?= h($y) ?></span><?php endif; ?><span class="ms-datebtn-md"><?= h($md) ?></span></button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

            </div>
          
          </div>
        </div>

        <div style="height:14px"></div>

        <div class="dp-card data-card">
          <div class="dp-card-b">
            <div class="table-wrap">
              <div class="no-results-overlay" id="ipqc-stale-overlay" style="display:none;"><span>필터가 변경되었습니다. 조회를 눌러주세요.</span></div>
              <?php if (empty($displayResults)): ?>
                <div class="no-results-overlay"><span>조회 결과 없음</span></div>
              <?php endif; ?>
              <?php if($isPivot): ?>
              <table>
                <thead>
                  <tr>
                    <th class="rownum sticky-left no-export">#</th>
                    <th>Part</th>
                    <th>Tool</th>
                    <th>Cavity</th>
                    <th>Date</th>
                    <th>라벨</th>
                    <?php foreach($pivotCols as $pc): ?>
                      <th
                        class="ipqc-pivot-col"
                        data-colkey="<?= h($pc['key']) ?>"
                        data-usl="<?= h(($pc['usl'] ?? null) === null ? '' : (string)$pc['usl']) ?>"
                        data-lsl="<?= h(($pc['lsl'] ?? null) === null ? '' : (string)$pc['lsl']) ?>"
                        title="<?= h($pc['label']) ?>"><?= h($pc['label']) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php $rn = $pageOffset; foreach($displayResults as $row): $rn++; ?>
                    <tr>
                      <td class="rownum sticky-left no-export"><?= $rn ?></td>
                      <td><?= h($row['part']) ?></td>
                      <td><?= h($row['tool']) ?></td>
                      <td><?= h($row['cavity']) ?></td>
                      <td><?= h($row['date']) ?></td>
                      <td><?= h($row['label']) ?></td>
                      <?php foreach($pivotCols as $pc): $v = $row['cells'][$pc['key']] ?? ''; $cls = ng_class($v, $pc['lsl'] ?? null, $pc['usl'] ?? null); ?>
                        <td class="cell <?= $cls ?>"><?= h($v) ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th class="rownum sticky-left no-export">#</th>
                    <th>Part</th>
                    <?php if($hasSpc): ?><th>SPC</th><?php endif; ?>
                    <th><?= $type==='CMM' ? 'Point' : 'FAI' ?></th>
                    <th>Tool</th>
                    <th>Cavity</th>
                    <th>Date</th>
                    <?php for($i=1;$i<=$dataCols;$i++): ?>
                      <th>Data <?= $i ?></th>
                    <?php endfor; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php $rn = $pageOffset; foreach($displayResults as $row): $rn++; ?>
                    <tr>
                      <td class="rownum sticky-left no-export"><?= $rn ?></td>
                      <td><?= h($row['part']) ?></td>
                      <?php if($hasSpc): ?><td><?= h($row['spc']) ?></td><?php endif; ?>
                      <td><?= h($row['key']) ?></td>
                      <td><?= h($row['tool']) ?></td>
                      <td><?= h($row['cavity']) ?></td>
                      <td><?= h($row['date']) ?></td>
                      <?php for($i=1;$i<=$dataCols;$i++): $v = $row['data'][$i] ?? ''; $cls = ng_class($v, $row['lsl'], $row['usl']); ?>
                        <td class="cell <?= $cls ?>"><?= h($v) ?></td>
                      <?php endfor; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php if (empty($EMBED)): ?>
<!-- ✅ 매트릭스 배경 외부 연결 (config/matrix_bg.php에서 설정) -->
<?php
$__mb = @include $ROOT . '/config/matrix_bg.php';
if (!is_array($__mb)) {
  $__mb = ['enabled'=>true,'text'=>'01','speed'=>1.15,'size'=>16,'zIndex'=>0,'scanlines'=>true,'vignette'=>true];
}
?>
<script>
  window.MATRIX_BG = <?php echo json_encode($__mb, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;


function setupGraphDnD(){
  const grid = document.getElementById('gmGrid');
  if (!grid || grid._dndBound) return;
  grid._dndBound = true;

  grid.addEventListener('dragover', (ev) => {
    ev.preventDefault();
    ev.dataTransfer.dropEffect = 'copy';
  });
  grid.addEventListener('drop', (ev) => {
    ev.preventDefault();
    const raw = ev.dataTransfer.getData('text/plain') || '';
    const key = _normKey(raw);
    if (!key) return;

    if (!IPQC_GRAPH.selected.includes(key)) {
      if (IPQC_GRAPH.selected.length >= IPQC_GRAPH.maxSel) {
        showMsg(`최대 ${IPQC_GRAPH.maxSel}개까지 선택할 수 있습니다.`);
        return;
      }
      IPQC_GRAPH.selected.push(key);

      // 체크박스 UI도 같이 반영
      const rowWrap = Array.from(document.querySelectorAll('.gm-fai-item')).find(el => (el.dataset.key || '') === key);
      const cb = rowWrap ? rowWrap.querySelector('input[type=checkbox]') : null;
      if (cb) cb.checked = true;
      _updateSelMeta();
      refreshGraph();
    }
  });
}

</script>
<script src="assets/matrix-bg.js"></script>
<?php endif; ?>
<script>
    function checkAll(group, on){
      document.querySelectorAll('input[name="'+group+'[]"]').forEach(function(cb){
        cb.checked = on;
      });
    }

    function toggleMs(id){
      const el = document.getElementById(id);
      if(!el) return;
      // close others
      document.querySelectorAll('.ms.open').forEach(function(o){
        if(o !== el) o.classList.remove('open');
      });
      el.classList.toggle('open');
      // AOI: open FAI dropdown should always reflect current model mapping
      if(id === 'ms-fai' && el.classList.contains('open')){
        try{
          const modelSel = document.getElementById('model');
          faiBuildListForModel(modelSel ? (modelSel.value||'') : '', false);
        }catch(e){}
      }
      if(id === 'ms-tools' && el.classList.contains('open')){
        try{ ipqcRefreshToolsForCurrentFilter(false); }catch(e){}
      }
    }


    // ---- Single-select MS (type/model) ----
    function msSingleSet(msId, value, label, silent){
      const root = document.getElementById(msId);
      if(!root) return;
      const inp = (msId === 'ms-type') ? document.getElementById('type')
               : (msId === 'ms-model') ? document.getElementById('model')
               : root.querySelector('input[type="hidden"]');
      const v = (value === null || value === undefined) ? '' : String(value);
      // 모델(ms-model)은 '빈값/전체'를 허용하지 않는다: 항상 첫 항목을 선택
      if(msId === 'ms-model' && v === ''){
        try{
          const list = document.getElementById('ms-model-list');
          const btn = list ? Array.from(list.querySelectorAll('.ms-singlebtn')).find(b => b.style.display !== 'none') : null;
          if(btn){
            const vv = String(btn.getAttribute('data-value') || '');
            const ll = String(btn.getAttribute('data-label') || btn.textContent || vv);
            if(vv !== ''){
              if(inp) inp.value = vv;
              const sum2 = document.getElementById(msId + '-summary') || root.querySelector('.ms-summary');
              if(sum2) sum2.textContent = ll;
              root.querySelectorAll('.ms-singlebtn').forEach(function(b2){
                const bv = String(b2.getAttribute('data-value') || '');
                b2.classList.toggle('active', bv === vv);
              });
              root.classList.remove('open');
              if(!silent && inp){ try{ inp.dispatchEvent(new Event('change', { bubbles:true })); }catch(e){} }
              return;
            }
          }
        }catch(e){}
      }
      if(inp) inp.value = v;

      const sum = document.getElementById(msId + '-summary') || root.querySelector('.ms-summary');
      if(sum){
        const lab = (label === null || label === undefined) ? v : String(label);
        sum.textContent = lab;
      }

      // active state
      root.querySelectorAll('.ms-singlebtn').forEach(function(btn){
        const bv = String(btn.getAttribute('data-value') || '');
        btn.classList.toggle('active', bv === v);
      });

      // close dropdown
      root.classList.remove('open');

      if(!silent && inp){
        try{ inp.dispatchEvent(new Event('change', { bubbles:true })); }catch(e){}
      }
    }

    function msSingleInit(msId){
      const root = document.getElementById(msId);
      if(!root) return;

      root.addEventListener('click', function(e){
        const btn = (e && e.target && e.target.closest) ? e.target.closest('.ms-singlebtn') : null;
        if(!btn || !root.contains(btn)) return;

        // stop native button behavior
        try{ e.preventDefault(); e.stopPropagation(); }catch(err){}

        const v = btn.getAttribute('data-value');
        const lab = btn.getAttribute('data-label');
        msSingleSet(msId, (v===null? '': v), (lab===null? (v===null? '' : v) : lab), false);
      }, true);
    }


    function msRefreshActive(msId){
      const root = document.getElementById(msId);
      if(!root) return;
      root.querySelectorAll('.ms-opt').forEach(el => {
        const cb = el.querySelector('input[type="checkbox"]');
        if(cb) el.classList.toggle('active', !!cb.checked);
      });
    }

    function syncMs(id){
      const el = document.getElementById(id);
      if(!el) return;
      if(id === 'ms-tools') ipqcRememberToolSelection();
      msRefreshActive(id);
      const group = el.getAttribute('data-group');
      const checked = Array.from(el.querySelectorAll('input[name="'+group+'[]"]:checked')).map(x=>x.value);
      const allCount = el.querySelectorAll('input[name="'+group+'[]"]').length;

      function _sumFirstRest(list){
        if(!list || list.length === 0) return '(선택 없음)';
        if(allCount && list.length === allCount) return '전체';
        if(list.length === 1) return String(list[0]);
        return String(list[0]) + ' 외 ' + (list.length - 1) + '개';
      }

      let label = '';
      if(group === 'months'){
        const nums = checked.map(x=>parseInt(x,10)).filter(n=>!isNaN(n)).sort((a,b)=>a-b);
        label = _sumFirstRest(nums.map(n=>String(n)+'월'));
      }else if(group === 'years'){
        const nums = checked.map(x=>parseInt(x,10)).filter(n=>!isNaN(n)).sort((a,b)=>a-b);
        label = _sumFirstRest(nums.map(n=>String(n)));
      }else{
        label = _sumFirstRest(checked);
      }
      const s = document.getElementById(id+'-summary');
      if(s) s.textContent = label;
    }

    
    // 멀티 선택 보조키:
    // - Windows/Linux: Ctrl
    // - macOS: Command(⌘)
    function msIsMacOS(){
      return /Mac|iPhone|iPad|iPod/.test(navigator.platform) || /Mac OS X/.test(navigator.userAgent);
    }
    function msMultiKey(ev){
      if(!ev) return false;
      return msIsMacOS() ? !!ev.metaKey : !!ev.ctrlKey;
    }

// Ctrl 없이 클릭하면 단일 선택, Ctrl 누르면 토글(복수 선택)
    // label(.ms-opt) 클릭이 checkbox(display:none)를 토글하므로 capture 단계에서 가로채서 직접 제어한다.
    function msRequireCtrlMulti(msId){
      const root = document.getElementById(msId);
      if(!root) return;
      const group = root.getAttribute('data-group');
      if(!group) return;

      const list = root.querySelector('.ms-list');
      if(!list) return;

      list.addEventListener('click', function(e){
        const lab = (e.target && e.target.closest) ? e.target.closest('.ms-opt') : null;
        if(!lab || !list.contains(lab)) return;

        const cb = lab.querySelector('input[type="checkbox"]');
        if(!cb) return;

        // 우리가 직접 상태를 바꾼다 (기본 label 토글 방지)
        e.preventDefault();
        e.stopPropagation();

        const inputs = Array.from(root.querySelectorAll('input[type="checkbox"][name="'+group+'[]"]'));
        if(msMultiKey(e)){
          cb.checked = !cb.checked;
        }else{
          inputs.forEach(x => x.checked = false);
          cb.checked = true;
        }
        syncMs(msId);
      }, true);
    }


    // check/uncheck all checkboxes inside a multi-select
    function checkAllIn(msId, on){
      const root = document.getElementById(msId);
      if(!root) return;
      root.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.checked = !!on;
      });
    msRefreshActive(msId);
    }


    // ---- AOI FAI multi-select (mapping order, page-date style) ----
    <?php
  // AOI: 실제 모델명(드롭다운 value) -> 매핑 리스트 (조회 버튼 없이도 바로 동작)
  $IPQC_AOI_MODEL_MAP = [];
  if (!empty($models) && is_array($models)) {
    foreach ($models as $__m) {
      $__m = (string)$__m;
      $mk = ipqc_model_to_mapkey($__m);
      if ($mk !== '' && isset($IPQC_ORDER_MAP[$mk]['AOI']) && is_array($IPQC_ORDER_MAP[$mk]['AOI'])) {
        $IPQC_AOI_MODEL_MAP[$__m] = array_values($IPQC_ORDER_MAP[$mk]['AOI']);
      }
    }
  }
// FAI list map for client-side (AOI/OMM/CMM)
// - AOI: FAI codes list
// - OMM: full label list (DB raw label)
// - CMM: point_no list
$IPQC_FAI_MAP = [];
foreach ($IPQC_ORDER_MAP as $__mk3 => $__mm3) {
  if (!is_array($__mm3)) continue;
  $IPQC_FAI_MAP[$__mk3] = [];
  foreach (['AOI','OMM','CMM','OQC'] as $__t) {
    if (isset($__mm3[$__t]) && is_array($__mm3[$__t])) {
      $IPQC_FAI_MAP[$__mk3][$__t] = array_values($__mm3[$__t]);
    }
  }
}

// OQC point_no list map for client-side (model -> point_no list)
// - OQC는 mapping.xlsx에 보통 매핑이 없으므로(DB에서 point_no를 직접 뽑아) UI에서 열 선택이 가능하게 한다.
// - point_no 라벨은 DB 원문 그대로 사용 (trim/정리 금지)
$IPQC_OQC_POINT_MODEL_MAP = []; // lazy via ajax=oqc_points

?>
const IPQC_FAI_MAP = <?= json_encode($IPQC_FAI_MAP, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const IPQC_AOI_FAI_MAP = <?= json_encode($IPQC_AOI_MAP, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const IPQC_AOI_FAI_MODEL_MAP = <?= json_encode($IPQC_AOI_MODEL_MAP, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const IPQC_OQC_POINT_MODEL_MAP = <?= json_encode($IPQC_OQC_POINT_MODEL_MAP, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const IPQC_TOOL_MODEL_MAP = <?= json_encode([strtoupper((string)$type) . '|' . (string)$model => array_values($tools)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const IPQC_TOOL_SELECTION_MAP = <?= json_encode([strtoupper((string)$type) . '|' . (string)$model => array_values($toolsSel)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const __TOOLS_LOADED = {};
const __TOOLS_PROMISE = {};
Object.keys(IPQC_TOOL_MODEL_MAP || {}).forEach(function(k){ __TOOLS_LOADED[k] = true; });

    // OQC point_no list: lazy fetch fallback (mapping 없는 모델도 즉시 표시)
    const __OQC_POINTS_LOADED = {};
    const __OQC_POINTS_PROMISE = {};
    function faiFetchOqcPoints(model){
      const m = String(model || '');
      if(!m) return Promise.resolve([]);
      if(__OQC_POINTS_PROMISE[m]) return __OQC_POINTS_PROMISE[m];

      const url = new URL(window.location.href);
      url.searchParams.set('ajax', 'oqc_points');
      url.searchParams.set('model', m);

      __OQC_POINTS_PROMISE[m] = fetch(url.toString(), {credentials:'same-origin'})
        .then(r => r.json())
        .then(j => {
          const arr = (j && j.ok && Array.isArray(j.items)) ? j.items : [];
          __OQC_POINTS_LOADED[m] = true;
          if(Array.isArray(arr)) IPQC_OQC_POINT_MODEL_MAP[m] = arr;
          return arr;
        })
        .catch(() => {
          __OQC_POINTS_LOADED[m] = true;
          return [];
        });

      return __OQC_POINTS_PROMISE[m];
    }


    function ipqcToolKey(type, model){
      return String(type || '').trim().toUpperCase() + '|' + String(model || '');
    }

    function ipqcGetSelectedTools(){
      const root = document.getElementById('ms-tools');
      if(!root) return [];
      return Array.from(root.querySelectorAll('input[name="tools[]"]:checked')).map(function(cb){
        return String(cb.value || '');
      });
    }

    function ipqcRememberToolSelection(){
      const root = document.getElementById('ms-tools');
      if(!root) return;
      const type = String(root.dataset.type || (document.getElementById('type') ? (document.getElementById('type').value || '') : '')).trim().toUpperCase();
      const model = String(root.dataset.model || (document.getElementById('model') ? (document.getElementById('model').value || '') : ''));
      IPQC_TOOL_SELECTION_MAP[ipqcToolKey(type, model)] = ipqcGetSelectedTools();
    }

    function ipqcSetToolsLoading(msg){
      const list = document.getElementById('ms-tools-list');
      const empty = document.getElementById('ms-tools-empty');
      if(list){
        list.innerHTML = '';
        list.style.display = 'none';
      }
      if(empty){
        empty.style.display = 'block';
        empty.innerHTML = String(msg || '툴 목록 불러오는 중...');
      }
      syncMs('ms-tools');
    }

    function ipqcRenderTools(items, selected){
      const root = document.getElementById('ms-tools');
      const list = document.getElementById('ms-tools-list');
      const empty = document.getElementById('ms-tools-empty');
      if(!root || !list || !empty) return;

      const seen = new Set();
      const arr = [];
      (Array.isArray(items) ? items : []).forEach(function(v){
        const s = String(v || '').trim();
        if(!s || seen.has(s)) return;
        seen.add(s);
        arr.push(s);
      });

      const selectedSet = new Set((Array.isArray(selected) ? selected : []).map(function(v){ return String(v || ''); }));
      const hasSelected = arr.some(function(v){ return selectedSet.has(v); });
      if(!hasSelected && arr.length){
        selectedSet.clear();
        selectedSet.add(arr[0]);
      }

      list.innerHTML = '';
      if(!arr.length){
        list.style.display = 'none';
        empty.style.display = 'block';
        empty.innerHTML = '툴 목록이 비어있습니다. (데이터 없음 또는 DB 조회 실패)<div style="margin-top:8px;"><button type="button" class="mini" onclick="location.reload()">새로고침</button></div>';
        IPQC_TOOL_SELECTION_MAP[ipqcToolKey(root.dataset.type || '', root.dataset.model || '')] = [];
        syncMs('ms-tools');
        return;
      }

      arr.forEach(function(t){
        const lab = document.createElement('label');
        lab.className = 'ms-item';

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.name = 'tools[]';
        cb.value = t;
        cb.checked = selectedSet.has(t);
        cb.addEventListener('change', function(){ syncMs('ms-tools'); });

        const sp = document.createElement('span');
        sp.textContent = t;

        lab.appendChild(cb);
        lab.appendChild(sp);
        list.appendChild(lab);
      });

      empty.style.display = 'none';
      empty.innerHTML = '';
      list.style.display = 'grid';
      syncMs('ms-tools');
      ipqcRememberToolSelection();
    }

    function ipqcFetchTools(type, model, opts){
      opts = opts || {};
      const t = String(type || '').trim().toUpperCase();
      const m = String(model || '');
      const key = ipqcToolKey(t, m);

      if(!t || !m){
        IPQC_TOOL_MODEL_MAP[key] = [];
        __TOOLS_LOADED[key] = true;
        return Promise.resolve([]);
      }
      if(!opts.force && Array.isArray(IPQC_TOOL_MODEL_MAP[key])){
        __TOOLS_LOADED[key] = true;
        return Promise.resolve(IPQC_TOOL_MODEL_MAP[key]);
      }
      if(!opts.force && __TOOLS_PROMISE[key]) return __TOOLS_PROMISE[key];

      const url = new URL(window.location.href);
      url.searchParams.set('ajax', 'tools');
      url.searchParams.set('type', t);
      url.searchParams.set('model', m);

      __TOOLS_PROMISE[key] = fetch(url.toString(), {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(j){
          const arr = (j && Array.isArray(j.items)) ? j.items : [];
          IPQC_TOOL_MODEL_MAP[key] = arr;
          __TOOLS_LOADED[key] = true;
          delete __TOOLS_PROMISE[key];
          return arr;
        })
        .catch(function(){
          __TOOLS_LOADED[key] = true;
          delete __TOOLS_PROMISE[key];
          if(!Array.isArray(IPQC_TOOL_MODEL_MAP[key])) IPQC_TOOL_MODEL_MAP[key] = [];
          return IPQC_TOOL_MODEL_MAP[key];
        });

      return __TOOLS_PROMISE[key];
    }

    function ipqcRefreshToolsForCurrentFilter(force){
      const root = document.getElementById('ms-tools');
      const typeEl = document.getElementById('type');
      const modelEl = document.getElementById('model');
      if(!root || !typeEl || !modelEl) return Promise.resolve([]);

      ipqcRememberToolSelection();

      const type = String(typeEl.value || '').trim().toUpperCase();
      const model = String(modelEl.value || '');
      const key = ipqcToolKey(type, model);
      const preferred = Array.isArray(IPQC_TOOL_SELECTION_MAP[key]) ? IPQC_TOOL_SELECTION_MAP[key] : ipqcGetSelectedTools();

      root.dataset.type = type;
      root.dataset.model = model;

      if(Array.isArray(IPQC_TOOL_MODEL_MAP[key]) && !force){
        ipqcRenderTools(IPQC_TOOL_MODEL_MAP[key], preferred);
        return Promise.resolve(IPQC_TOOL_MODEL_MAP[key]);
      }

      ipqcSetToolsLoading('툴 목록 불러오는 중...');
      return ipqcFetchTools(type, model, {force: !!force}).then(function(arr){
        const curType = String(typeEl.value || '').trim().toUpperCase();
        const curModel = String(modelEl.value || '');
        if(curType !== type || curModel !== model) return arr;
        ipqcRenderTools(arr, preferred);
        return arr;
      });
    }

    function ipqcModelToMapKeyJs(model){
      const m = String(model || '').toLowerCase();
      if (/(^|\s)z\s*[-_ ]?\s*stopper/.test(m)) return 'ZSTOPPER';
      if (/(^|\s)z\s*[-_ ]?\s*carrier/.test(m)) return 'ZCARRIER';
      if (/(^|\s)y\s*[-_ ]?\s*carrier/.test(m)) return 'YCARRIER';
      if (/(^|\s)x\s*[-_ ]?\s*carrier/.test(m)) return 'XCARRIER';
      if (/(^|\s)ir\s*[-_ ]?\s*base/.test(m)) return 'IRBASE';
      return '';
    }

    let __FAI_SEL = new Set();

    function faiShowHint(msg){
      const hint = document.getElementById('ms-fai-hint');
      if(!hint) return;
      if(!msg){
        hint.textContent = '';
        hint.style.display = 'none';
        return;
      }
      hint.textContent = msg;
      hint.style.display = 'block';
      hint.style.opacity = '1';
      try{ clearTimeout(hint.__t); }catch(e){}
      hint.__t = setTimeout(function(){
        try{ hint.textContent=''; hint.style.display='none'; }catch(e){}
      }, 1200);
    }

    function faiEnsureAtLeastOne(){
      if(__FAI_SEL && __FAI_SEL.size > 0) return true;
      const list = document.getElementById('ms-fai-list');
      if(!list) return false;
      // 첫 번째(필터 적용 시 보이는) 버튼을 기본 선택 (OMM/CMM은 ALL을 기본으로 선택하지 않음)
      const btns = Array.from(list.querySelectorAll('.ms-datebtn')).filter(b => b.style.display !== 'none');
      const btn = btns.find(b => String(b.getAttribute('data-value')||'') !== '__ALL__') || btns[0] || null;
      const v = btn ? String(btn.getAttribute('data-value')||'') : '';
      if(!v) return false;
      __FAI_SEL.add(v);
      if(btn) btn.classList.add('active');
      faiWriteHiddenByOrder();
      faiSyncSummary();
      return true;
    }


    function faiReadHidden(){
      __FAI_SEL = new Set();
      const box = document.getElementById('ms-fai-hidden');
      if(!box) return;
      box.querySelectorAll('input[name="fai[]"]').forEach(inp => {
        const v = String(inp.value || '');
        if(v) __FAI_SEL.add(v);
      });
    }

    function faiWriteHiddenByOrder(){
      const box = document.getElementById('ms-fai-hidden');
      if(!box) return;

      const model = document.getElementById('model') ? (document.getElementById('model').value || '') : '';
      const type = (document.getElementById('type') ? (document.getElementById('type').value || '') : '').trim().toUpperCase();
      const mk = ipqcModelToMapKeyJs(model);

      // ALL 선택이면 그대로 1개만 유지(필터 없음 의미)
      if(__FAI_SEL && __FAI_SEL.has('__ALL__')){
        box.innerHTML = '';
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'fai[]'; inp.value = '__ALL__';
        box.appendChild(inp);
        return;
      }

      let order = [];
      if(type === 'AOI'){
        order = (model && Array.isArray(IPQC_AOI_FAI_MODEL_MAP[model])) ? IPQC_AOI_FAI_MODEL_MAP[model] : ((mk && Array.isArray(IPQC_AOI_FAI_MAP[mk])) ? IPQC_AOI_FAI_MAP[mk] : []);
      }else if(type === 'OQC'){
        // OQC: point_no list (열 선택). mapping.xlsx가 없을 수 있으므로 DB에서 lazy fetch fallback.
        items = (model && IPQC_OQC_POINT_MODEL_MAP && Array.isArray(IPQC_OQC_POINT_MODEL_MAP[model])) ? IPQC_OQC_POINT_MODEL_MAP[model] : [];

        // 아직 로드된 적 없고(또는 빈 배열), 모델이 있으면 1회만 AJAX로 가져온다.
        if(model && (!__OQC_POINTS_LOADED[model]) && (!items || items.length === 0)){
          if(empty) empty.style.display = 'none';
          list.style.display = 'block';
          list.innerHTML = '<div class="ms-empty" style="grid-column:1/-1; padding:10px; opacity:0.85;">로딩...</div>';
          faiSyncSummary();
          faiFetchOqcPoints(model).then(function(){
            try{ faiBuildListForModel(model, resetSelection); }catch(e){}
          });
          return;
        }

        // mapping fallback (드물게 있을 수 있음)
        if((!items || items.length === 0) && mk && IPQC_FAI_MAP && IPQC_FAI_MAP[mk] && Array.isArray(IPQC_FAI_MAP[mk][type])) {
          items = IPQC_FAI_MAP[mk][type];
        }
      }else if(type === 'OMM' || type === 'CMM'){

        order = (mk && IPQC_FAI_MAP && IPQC_FAI_MAP[mk] && Array.isArray(IPQC_FAI_MAP[mk][type])) ? IPQC_FAI_MAP[mk][type] : [];
      }

// build ordered list of selected values
      const out = [];
      const seen = new Set();
      for(const v of order){
        if(__FAI_SEL.has(v) && !seen.has(v)){ out.push(v); seen.add(v); }
      }
      // any extra (shouldn't happen) appended
      for(const v of __FAI_SEL){
        if(!seen.has(v)){ out.push(v); seen.add(v); }
      }

      box.innerHTML = '';
      for(const v of out){
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'fai[]';
        inp.value = v;
        box.appendChild(inp);
      }
    }

    function faiSelectedOrdered(){
      const model = document.getElementById('model') ? (document.getElementById('model').value || '') : '';
      const mk = ipqcModelToMapKeyJs(model);
      const type = (document.getElementById('type') ? (document.getElementById('type').value || '') : '').trim().toUpperCase();

      let order = [];
      if(type === 'AOI'){
        order = (model && Array.isArray(IPQC_AOI_FAI_MODEL_MAP[model])) ? IPQC_AOI_FAI_MODEL_MAP[model] : ((mk && Array.isArray(IPQC_AOI_FAI_MAP[mk])) ? IPQC_AOI_FAI_MAP[mk] : []);
      }else if(type === 'OQC'){
        order = (model && IPQC_OQC_POINT_MODEL_MAP && Array.isArray(IPQC_OQC_POINT_MODEL_MAP[model])) ? IPQC_OQC_POINT_MODEL_MAP[model]
              : ((mk && IPQC_FAI_MAP && IPQC_FAI_MAP[mk] && Array.isArray(IPQC_FAI_MAP[mk][type])) ? IPQC_FAI_MAP[mk][type] : []);
      }else if(type === 'OMM' || type === 'CMM'){
        order = (mk && IPQC_FAI_MAP && IPQC_FAI_MAP[mk] && Array.isArray(IPQC_FAI_MAP[mk][type])) ? IPQC_FAI_MAP[mk][type] : [];
      }

      const out = [];
      const seen = new Set();
      for(const v of order){
        if(__FAI_SEL.has(v) && !seen.has(v)){ out.push(v); seen.add(v); }
      }
      for(const v of __FAI_SEL){
        if(!seen.has(v)){ out.push(v); seen.add(v); }
      }
      return out;
    }

    function faiSyncSummary(){
      const s = document.getElementById('ms-fai-summary');

      const ordered = faiSelectedOrdered();
      const n = ordered.length;

      let label = '(선택 없음)';
      if(ordered.indexOf('__ALL__') >= 0){
        label = 'ALL';
      }else if(n === 1){
        label = ordered[0];
      }else if(n > 1){
        label = ordered[0] + ' 외 ' + (n - 1) + '개';
      }
      if(s) s.textContent = label;
    }

    // Hard reset FAI UI/selection (clear hidden + summary; do not trim labels)
    function faiHardResetUI(){
      try{ __FAI_SEL = new Set(); }catch(e){}
      try{ const box=document.getElementById('ms-fai-hidden'); if(box) box.innerHTML=''; }catch(e){}
      try{ const s=document.getElementById('ms-fai-summary'); if(s) s.textContent='(선택 없음)'; }catch(e){}
      try{ const list=document.getElementById('ms-fai-list'); if(list) list.innerHTML=''; }catch(e){}
      try{ const empty=document.getElementById('ms-fai-empty'); if(empty) empty.style.display='none'; }catch(e){}
      try{ const search=document.getElementById('ms-fai-search'); if(search) search.value=''; }catch(e){}
    }



    function faiBtnForValue(v){
      try{
        const esc = (window.CSS && CSS.escape) ? CSS.escape(v) : v.replace(/"/g,'\\"');
        return document.querySelector('#ms-fai-list .ms-datebtn[data-value="'+esc+'"]');
      }catch(e){
        return null;
      }
    }

    function faiToggleValue(v, ev){
      v = String(v || '');
      if(!v) return;

      // Ctrl 없이 클릭: 단일 선택(복수 금지). Ctrl 누른 경우에만 토글(복수 선택).
      const __multi = msMultiKey(ev);
      if(!__multi){
        __FAI_SEL = new Set([v]);
        faiWriteHiddenByOrder();
        faiSyncSummary();
        // refresh active states
        document.querySelectorAll('#ms-fai-list .fai-btn').forEach(b=>{
          const vv = String(b.getAttribute('data-value') || '');
          b.classList.toggle('active', __FAI_SEL.has(vv));
              ipqcMarkDirty();
});
        return;
      }


      // OMM/CMM: ALL은 배타(선택하면 나머지 해제). ALL이 선택된 상태에서 다른 값을 선택하면 ALL 해제.
      if(v === '__ALL__'){
        __FAI_SEL = new Set(['__ALL__']);
        faiWriteHiddenByOrder();
        faiSyncSummary();
        document.querySelectorAll('#ms-fai-list .fai-btn').forEach(b=>{
          const vv = String(b.getAttribute('data-value') || '');
          b.classList.toggle('active', __FAI_SEL.has(vv));
        });
        return;
      }
      if(__FAI_SEL.has('__ALL__')){
        __FAI_SEL.delete('__ALL__');
      }

      if(__FAI_SEL.has(v)){
        // ✅ 최소 1개 선택 강제
        if(__FAI_SEL.size <= 1){
          faiShowHint('FAI는 1개 이상 선택해야 합니다.');
          return;
        }
        __FAI_SEL.delete(v);
      }else{
        __FAI_SEL.add(v);
      }

      faiWriteHiddenByOrder();
      faiSyncSummary();

      const btn = faiBtnForValue(v);
      if(btn) btn.classList.toggle('active', __FAI_SEL.has(v));
    }


    function faiClearAll(doSync){
      // ✅ '해제'는 전체 0개가 되지 않도록 기본 1개를 남김
      __FAI_SEL = new Set();
      faiWriteHiddenByOrder();

      // clear all active states in current list
      document.querySelectorAll('#ms-fai-list .ms-datebtn.active').forEach(b => b.classList.remove('active'));

      // 최소 1개 보장 (첫 항목 자동 선택)
      faiEnsureAtLeastOne();

      if(doSync) faiSyncSummary();
          ipqcMarkDirty();
}


    function faiApplyFilter(q){
      const kw = String(q || '').trim().toLowerCase();
      const list = document.getElementById('ms-fai-list');
      if(!list) return;
      const btns = list.querySelectorAll('.ms-datebtn');
      btns.forEach(btn => {
        const v = String(btn.getAttribute('data-value') || '').toLowerCase();
        btn.style.display = (!kw || v.includes(kw)) ? '' : 'none';
      });
    }

    function faiBuildListForModel(model, resetSelection){
      const list = document.getElementById('ms-fai-list');
      const empty = document.getElementById('ms-fai-empty');
      if(empty && !empty.dataset.defaultText) empty.dataset.defaultText = (empty.textContent || '');
      const search = document.getElementById('ms-fai-search');

      if(resetSelection){
        // 모델 변경 시에는 기존 선택을 완전 초기화(새 모델 기준으로 1개 자동 선택)
        __FAI_SEL = new Set();
        const box0 = document.getElementById('ms-fai-hidden');
        if(box0) box0.innerHTML = '';
      }
      if(search) { search.value = ''; }

      if(!list) return;

      const mk = ipqcModelToMapKeyJs(model);
      const type = (document.getElementById('type') ? (document.getElementById('type').value || '') : '').trim().toUpperCase();
      let items = [];
      if(type === 'AOI'){
        items = (model && Array.isArray(IPQC_AOI_FAI_MODEL_MAP[model])) ? IPQC_AOI_FAI_MODEL_MAP[model] : ((mk && Array.isArray(IPQC_AOI_FAI_MAP[mk])) ? IPQC_AOI_FAI_MAP[mk] : []);
      }else if(type === 'OQC'){
        if(model && IPQC_OQC_POINT_MODEL_MAP && Array.isArray(IPQC_OQC_POINT_MODEL_MAP[model])){
          items = IPQC_OQC_POINT_MODEL_MAP[model];
        }else{
          // OQC는 mapping.xlsx에 매핑이 없는 경우가 많다 -> 드롭다운을 열 때 DB에서 point_no를 lazy fetch
          if(model && !__OQC_POINTS_LOADED[model]){
            if(empty){
              empty.style.display = 'block';
              empty.textContent = '로딩 중...';
            }
            list.style.display = 'none';
            faiSyncSummary();

            faiFetchOqcPoints(model).then(() => {
              if(empty){
                empty.textContent = (empty.dataset.defaultText || '');
              }
              const curType = (document.getElementById('type') ? (document.getElementById('type').value || '') : '').trim().toUpperCase();
              const curModel = (document.getElementById('model') ? (document.getElementById('model').value || '') : '');
              if(curType === 'OQC' && curModel === model){
                faiBuildListForModel(model, false);
              }
            });
            return;
          }
          items = (mk && IPQC_FAI_MAP && IPQC_FAI_MAP[mk] && Array.isArray(IPQC_FAI_MAP[mk][type])) ? IPQC_FAI_MAP[mk][type] : [];
        }
      }else if(type === 'OMM' || type === 'CMM'){
        items = (mk && IPQC_FAI_MAP && IPQC_FAI_MAP[mk] && Array.isArray(IPQC_FAI_MAP[mk][type])) ? IPQC_FAI_MAP[mk][type] : [];
      }

      list.innerHTML = '';
      if(!items.length){
        if(empty) empty.style.display = 'block';
        list.style.display = 'none';
        faiSyncSummary();
        return;
      }
      if(empty) empty.style.display = 'none';
      list.style.display = 'grid';

      // ensure selection is loaded from hidden (first init)
      if(!__FAI_SEL) __FAI_SEL = new Set();

      // OMM/CMM: ALL(전체 컬럼) 옵션 추가 (기본 선택은 ALL이 아님)
      if(type === 'OMM' || type === 'CMM' || type === 'OQC'){
        const allBtn = document.createElement('button');
        allBtn.type = 'button';
        allBtn.className = 'ms-datebtn fai-btn';
        allBtn.setAttribute('data-value', '__ALL__');
        allBtn.title = 'ALL';

        const spanA = document.createElement('span');
        spanA.className = 'ms-datebtn-md';
        spanA.textContent = 'ALL';
        allBtn.appendChild(spanA);

        if(__FAI_SEL.has('__ALL__')) allBtn.classList.add('active');
        allBtn.addEventListener('click', function(e){
          faiToggleValue('__ALL__', e);
        });
        list.appendChild(allBtn);
      }

for(const v of items){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ms-datebtn fai-btn';
        btn.setAttribute('data-value', v);
        btn.title = v;

        const span = document.createElement('span');
        span.className = 'ms-datebtn-md';
        span.textContent = v;
        btn.appendChild(span);

        if(__FAI_SEL.has(v)) btn.classList.add('active');

        btn.addEventListener('click', function(e){
          faiToggleValue(v, e);
        });

        list.appendChild(btn);
      }

      faiEnsureAtLeastOne();
      faiSyncSummary();
      if(search) faiApplyFilter(search.value);
    }

    function faiInit(){
      faiReadHidden();
      const model = document.getElementById('model') ? (document.getElementById('model').value || '') : '';
      faiBuildListForModel(model, false);
      faiSyncSummary();
    }

    // close dropdown when clicking outside
    document.addEventListener('click', function(e){
      const open = document.querySelector('.ms.open');
      if(!open) return;
      if(open.contains(e.target)) return;

      const wasPage = (open.id === 'ms-page');
      open.classList.remove('open');

      if(wasPage){
        try{
          const f = document.getElementById('filterForm');
          if(!f) return;
          const hs2 = f.querySelector('input[name="page_dates"]');
          const cur = hs2 ? String(hs2.value||'').trim() : '';
          if(cur){
            // 드롭다운이 닫히면(바깥 클릭) 멀티 선택을 적용하기 위해 submit
            const a=document.getElementById('pageAllHidden'); if(a) a.value = '0';
            const ph=document.getElementById('pageHidden'); if(ph) ph.remove();
            f.submit();
          }
        }catch(e){}
      }
    });// init
    
    // Filter dirty marker: when filters changed but query not re-submitted, hide stale table by overlay
    function ipqcMarkDirty(){
      try{
        const ov = document.getElementById('ipqc-stale-overlay');
        if(ov) ov.style.display = 'flex';
      }catch(e){}
    }
    function ipqcClearDirty(){
      try{
        const ov = document.getElementById('ipqc-stale-overlay');
        if(ov) ov.style.display = 'none';
      }catch(e){}
    }

window.addEventListener('load', function(){
      syncMs('ms-tools');
      syncMs('ms-years');
      syncMs('ms-months');
      try{ msRequireCtrlMulti('ms-years'); }catch(e){}
      try{ msRequireCtrlMulti('ms-months'); }catch(e){}
      if(document.getElementById('ms-fai')) faiInit();

      // type/model single-select MS init
      if(document.getElementById('ms-type')) msSingleInit('ms-type');
      if(document.getElementById('ms-model')) msSingleInit('ms-model');

      // 필터 변경 시: 기존 결과는 오래된 값이므로 overlay 표시(조회 누르기 전까지)
      const f = document.getElementById('filterForm');
      const typeSel = document.getElementById('type');
      const modelSel = document.getElementById('model');

      // 기존 멀티 날짜(page_dates) 초기화 + summary 원복 (잔상 제거)
      function __ipqcResetPageDatesMulti(){
        try{
          // clear multi-date + single page date + ALL flag (잔상 완전 제거)
          const hs2 = f ? f.querySelector('input[name="page_dates"]') : null;
          if(hs2) hs2.value = '';
          const pd = f ? f.querySelector('input[name="page_date"]') : null;
          if(pd) pd.value = '';
          const pa = document.getElementById('pageAllHidden');
          if(pa) pa.value = '0';

          // UI: active 하이라이트 전부 제거(ALL 포함)
          document.querySelectorAll('#ms-page .ms-datebtn.active, #ms-page .ms-datebtn-all.active').forEach(function(btn){
            btn.classList.remove('active');
          });

          // UI: 요약 텍스트도 초기화
          const sum = document.getElementById('ms-page-summary');
          if(sum) sum.textContent = '(선택 없음)';
        }catch(e){}
      }

      // 타입/모델 변경 시: FAI UI 재구성(이전 타입 선택이 남는 문제 방지)
      function __ipqcRebuildFaiUI(reset){
        try{
          const ms = document.getElementById('ms-fai');
          if(!ms) return;

          const typeNow = (typeSel ? (typeSel.value||'') : '').trim().toUpperCase();
          const wrap = ms.closest('.f') || ms.parentElement;

          // FAI 필터가 의미있는 타입만 표시(AOI/OMM/CMM). 그 외 타입이면 UI 숨김 + 상태 초기화.
          if(!['AOI','OMM','CMM','OQC'].includes(typeNow)){
            if(wrap) wrap.style.display = 'none';
            faiHardResetUI();
            return;
          }else{
            if(wrap) wrap.style.display = '';
          }

          if(reset){
            // 타입/모델 변경 시: hidden/summary까지 완전히 비우고 새 리스트로 재구성
            faiHardResetUI();
          }

          const m = modelSel ? (modelSel.value||'') : '';
          faiBuildListForModel(m, !!reset);
          faiSyncSummary();
        }catch(e){}
      }

      function __ipqcOnBigFilterChange(){
        // 드롭다운 열려있으면 닫기
        document.querySelectorAll('.ms.open').forEach(function(o){ o.classList.remove('open'); });
        __ipqcResetPageDatesMulti();
        __ipqcRebuildFaiUI(true);
        ipqcRefreshToolsForCurrentFilter(true);
        ipqcMarkDirty();
      }

      if(typeSel){
        typeSel.addEventListener('change', function(){
          // 타입 변경 시: 모델은 항상 단일 1개 선택 (첫 항목으로 강제)
          try{
            const list = document.getElementById('ms-model-list');
            const btn = list ? Array.from(list.querySelectorAll('.ms-singlebtn')).find(b => b.style.display !== 'none') : null;
            if(btn){
              const v = btn.getAttribute('data-value') || '';
              const lab = btn.getAttribute('data-label') || btn.textContent || v;
              msSingleSet('ms-model', v, lab, true);
            }
          }catch(e){}
          __ipqcOnBigFilterChange();
        });
      }
      if(modelSel){
        modelSel.addEventListener('change', function(){ __ipqcOnBigFilterChange(); });
      }

      // 일반 필터 변경도 stale 표시
      if(f){
        f.addEventListener('change', function(ev){
          const t = ev && ev.target ? ev.target : null;
          if(!t) return;
          const n = (t.getAttribute && t.getAttribute('name')) ? t.getAttribute('name') : '';
          // 조회 버튼 클릭으로 submit 되기 전, 변경이면 stale 표시
          if(n && n !== 'page_date' && n !== 'page_dates'){
            ipqcMarkDirty();
          }
        }, true);
      }

      // 초기 로드에서는 stale overlay 숨김 + FAI UI 최초 구성
      ipqcClearDirty();
      __ipqcRebuildFaiUI(false);
      ipqcRefreshToolsForCurrentFilter(false);
});
// 날짜 페이지 선택 (page_date)
    const PAGE_DATES = <?= json_encode($pageDates, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const CUR_PAGE_DATE = <?= json_encode($pageDate, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

    function setPageDate(d, ev){
      var f=document.getElementById('filterForm');
      if(!f) return;
      var v = String(d||'');
      var hs = f.querySelectorAll('input[name="page_date"]');
      if(!hs || hs.length===0){
        var h=document.createElement('input'); h.type='hidden'; h.name='page_date'; h.id='pageDateHidden';
        f.appendChild(h);
        hs = [h];
      }
      for (var i=0;i<hs.length;i++) { try{ hs[i].value = v; }catch(e){} }

      // page_dates (Ctrl/Meta click multi-select)
      var hs2 = f.querySelectorAll('input[name="page_dates"]');
      if(!hs2 || hs2.length===0){
        var h2=document.createElement('input'); h2.type='hidden'; h2.name='page_dates'; h2.id='pageDatesHidden';
        f.appendChild(h2);
        hs2 = [h2];
      }
      var cur = String(hs2[0].value||'').trim();
      var list = cur ? cur.split(',').map(x=>String(x||'').trim()).filter(Boolean) : [];

      var multi = msMultiKey(ev);
      if(multi){
        // ✅ Ctrl/Meta: 토글만 하고, 드롭다운이 닫힐 때(바깥 클릭) submit 된다.
        if(ev){ try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){} }

        var idx = list.indexOf(v);
        if(idx >= 0) list.splice(idx,1);
        else list.push(v);

        // keep order as in PAGE_DATES if possible
        if (Array.isArray(PAGE_DATES) && PAGE_DATES.length){
          list.sort(function(a,b){ return PAGE_DATES.indexOf(a) - PAGE_DATES.indexOf(b); });
        }
        hs2[0].value = list.join(',');

        // UI: active highlight
        try{
          document.querySelectorAll('#ms-page .ms-datebtn').forEach(function(btn){
            var cls = btn.getAttribute('class') || '';
            // skip ALL button
            if(cls.indexOf('ms-datebtn-all') >= 0) return;
            // each button calls setPageDate('YYYY-MM-DD', event); store in data-date? 없으니 onclick에서 추출
            var oc = btn.getAttribute('onclick') || '';
            var m = oc.match(/setPageDate\('([^']+)'\s*,/);
            if(!m) return;
            var dd = m[1];
            var on = (hs2[0].value && hs2[0].value.split(',').indexOf(dd) >= 0);
            if(on) btn.classList.add('active'); else btn.classList.remove('active');
          });
          var sum=document.getElementById('ms-page-summary');
          if(sum){
            if(Array.isArray(PAGE_DATES) && list.length === PAGE_DATES.length){
              sum.textContent = '전체';
            }else if(list.length === 0){
              sum.textContent = v || '(선택 없음)';
            }else if(list.length === 1){
              sum.textContent = list[0];
            }else{
              sum.textContent = list[0] + ' 외 ' + (list.length - 1) + '개';
            }
          }
        }catch(e){}
        return;
      }else{
        // normal click = single date mode (clear multi) and submit immediately
        hs2[0].value = '';
      }

      var a=document.getElementById('pageAllHidden');
      if(a) a.value = '0';
      var ph=document.getElementById('pageHidden');
      if(ph) ph.remove();
      f.submit();
    }

    function setPageAll(){
      var f=document.getElementById('filterForm');
      if(!f) return;
      var a=document.getElementById('pageAllHidden');
      if(!a){ a=document.createElement('input'); a.type='hidden'; a.name='page_all'; a.id='pageAllHidden'; f.appendChild(a); }
      a.value = '1';
       var pd=document.getElementById('pageDatesHidden'); if(pd) pd.value='';
      var ph=document.getElementById('pageHidden');
      if(ph) ph.remove();
      f.submit();
    }

    function pagePrev(){
      if(!PAGE_DATES || PAGE_DATES.length===0) return;
      var i = PAGE_DATES.indexOf(CUR_PAGE_DATE);
      if(i <= 0) return;
      setPageDate(PAGE_DATES[i-1]);
    }

    function pageNext(){
      if(!PAGE_DATES || PAGE_DATES.length===0) return;
      var i = PAGE_DATES.indexOf(CUR_PAGE_DATE);
      if(i < 0 || i >= PAGE_DATES.length-1) return;
      setPageDate(PAGE_DATES[i+1]);
    }

function showBusy(title, sub){
      var m=document.getElementById('busyModal');
      if(!m) return;
      document.getElementById('busyTitle').textContent = title || '조회 중...';
      var s = document.getElementById('busySub');
      if (sub) { s.style.display='block'; s.textContent=sub; }
      else { s.style.display='none'; s.textContent=''; }
      m.style.display='flex';
    }
    function hideBusy(){
      var m=document.getElementById('busyModal');
      if(m) m.style.display='none';
    }
    function showMsg(msg, title){
      var m=document.getElementById('msgModal');
      if(!m){ alert(String(msg||'')); return; }
      var t=document.getElementById('msgTitle');
      var b=document.getElementById('msgBody');
      if(t) t.textContent = title || '알림';
      if(b) b.textContent = String(msg||'');
      m.style.display='flex';
      var ok=document.getElementById('msgOk');
      if(ok) setTimeout(function(){ try{ ok.focus(); }catch(e){} }, 0);
    }
    function hideMsg(){
      var m=document.getElementById('msgModal');
      if(m) m.style.display='none';
    }

// 조회 버튼(사용자 submit) 시 모달 + 모델 필수 체크
    (function(){
      var f=document.getElementById('filterForm');
      if(!f) return;

      f.addEventListener('submit', function(e){
        // NOTE: form.submit()는 대부분의 브라우저에서 submit 이벤트를 트리거하지 않음.
        // 따라서 여기서는 주로 '조회' 버튼 클릭 같은 사용자 submit만 처리한다.
        var submitter = e.submitter || document.activeElement;
        var isRunBtn = !!(submitter && submitter.id === 'btnRun');

        if (isRunBtn) {
          var toolChecked = document.querySelectorAll('#ms-tools input[name="tools[]"]:checked').length;
          if (!toolChecked) {
            e.preventDefault();
            hideBusy();
            showMsg('툴을 1개 이상 선택해주세요.');
            return;
          }

          var model = (document.getElementById('model')?.value || '').trim();
          if (!model) {
            e.preventDefault();
            hideBusy();
            showMsg('모델을 선택해주세요.');
            return;
          }
        }

        showBusy('조회 중...', '잠시만 기다려주세요.');
      });
    })();
    // CSV export (especially AOI with a 1-month range) can take minutes.
    // Removing the iframe too early cancels the request and the download will "do nothing".
    function triggerDownload(url, ttlMs){
      const iframe = document.createElement('iframe');
      iframe.style.display = 'none';
      iframe.src = url;
      iframe.onload = function(){
        // If the response is an error page/text (same-origin), show it.
        try{
          const doc = iframe.contentDocument || iframe.contentWindow?.document;
          const txt = (doc && doc.body) ? (doc.body.innerText || '').trim() : '';
          if(txt && txt.length < 5000){
            const head = txt.slice(0, 120).toLowerCase();
            if(head.includes('필수') || head.includes('invalid') || head.includes('error') || head.includes('err') || head.includes('sqlstate')){
              showMsg(txt);
            }
          }
        }catch(e){}
        setTimeout(()=>{ try{ iframe.remove(); }catch(e){} }, 1500);
      };
      document.body.appendChild(iframe);
      // Default TTL: 2 hours (avoid canceling long exports)
      const ttl = (typeof ttlMs === 'number' && ttlMs > 0) ? ttlMs : (2 * 60 * 60 * 1000);
      setTimeout(()=>{ try{ iframe.remove(); }catch(e){} }, ttl);
    }

    function exportExcelAll(){
      const form = document.getElementById('filterForm');
      if(!form){ showMsg('폼을 찾을 수 없습니다.'); return; }

      const type = (document.getElementById('type')?.value || '').trim();
      const model = (document.getElementById('model')?.value || '').trim();
      if(!model){ showMsg('모델을 선택해주세요.'); return; }

      const years = Array.from(form.querySelectorAll('input[name="years[]"]:checked')).map(cb=>cb.value);
      if(years.length === 0){ showMsg('년도를 최소 1개 선택하세요.'); return; }

      const months = Array.from(form.querySelectorAll('input[name="months[]"]:checked')).map(cb=>cb.value);
      if(months.length === 0){ showMsg('월을 최소 1개 선택하세요.'); return; }

      const tools = Array.from(form.querySelectorAll('input[name="tools[]"]:checked')).map(cb=>cb.value);

      let fais = [];
      if (['AOI','OMM','CMM','OQC'].includes((type||'').toUpperCase())) {
        try { fais = faiSelectedOrdered(); } catch(e) { fais = []; }
      }
const qs = new URLSearchParams();
      qs.set('mode','all');
      qs.set('type', type);
      qs.set('model', model);
      // OQC export expects 'part' (Py JMP Assist spec). Keep 'model' too.
      if ((type||'').toUpperCase() === 'OQC') qs.set('part', model);

      // legacy + multi
      qs.set('year', years[0]);
      years.forEach(y => qs.append('years[]', y));
      months.forEach(m => qs.append('months[]', m));
      if(tools.length > 0){ tools.forEach(t => qs.append('tools[]', t)); }

      if(fais.length > 0 && ['AOI','OMM','CMM','OQC'].includes((type||'').toUpperCase())){ fais.forEach(v => qs.append('fai[]', v)); }
      qs.set('format','csv');
            // Safety: do not accidentally export only one page
      qs.delete('page_date');
      qs.delete('page');
      qs.delete('per_page');
      const url = new URL('lib/ipqc_excel_export.php', window.location.href); url.search = qs.toString();

      showBusy('내보내는 중...', '파일 생성 중…');
      triggerDownload(url.toString());
      // Close the modal shortly after starting the download (best-effort)
      setTimeout(hideBusy, 2000);
      }

    function exportExcelPage(){
  const form = document.getElementById('filterForm');
  if(!form){ showMsg('폼을 찾을 수 없습니다.'); return; }

  const type = (document.getElementById('type')?.value || '').trim();
  const model = (document.getElementById('model')?.value || '').trim();
  if(!model){ showMsg('모델을 선택해주세요.'); return; }

  const pageDate = (typeof CUR_PAGE_DATE !== 'undefined' ? CUR_PAGE_DATE : '') || (document.getElementById('pageDateHidden')?.value || '');
  if(!pageDate){ showMsg('페이지 날짜를 선택하세요.'); return; }

  const years = Array.from(form.querySelectorAll('input[name="years[]"]:checked')).map(cb=>cb.value);
  const months = Array.from(form.querySelectorAll('input[name="months[]"]:checked')).map(cb=>cb.value);
  const tools = Array.from(form.querySelectorAll('input[name="tools[]"]:checked')).map(cb=>cb.value);
  let fais = [];
      if (['AOI','OMM','CMM','OQC'].includes((type||'').toUpperCase())) {
        try { fais = faiSelectedOrdered(); } catch(e) { fais = []; }
      }
const colsMap = {'AOI':16,'OMM':3,'CMM':3};
  const cols = colsMap[(type||'').toUpperCase()] || 16;

  const qs = new URLSearchParams();
  qs.set('mode','page_date');
  qs.set('type', type);
  qs.set('model', model);
  if ((type||'').toUpperCase() === 'OQC') qs.set('part', model);
  qs.set('page_date', pageDate);
  // page_dates (Ctrl 멀티 날짜 선택) - 있으면 이걸 우선 적용한다.
  const pageDatesVal = (document.getElementById('pageDatesHidden')?.value || form.querySelector('input[name="page_dates"]')?.value || '').toString();
  const pageDates = pageDatesVal.split(',').map(x=>String(x||'')).filter(x=>x!=='');
  if(pageDates.length > 0){
    qs.set('page_dates', pageDates.join(','));
    qs.set('page_date', pageDates[0]);
  }else{
    qs.delete('page_dates');
  }
qs.set('cols', String(cols));

  // legacy + multi
  if (years.length > 0) qs.set('year', years[0]);
  years.forEach(y => qs.append('years[]', y));
  months.forEach(m => qs.append('months[]', m));
  if(tools.length > 0){ tools.forEach(t => qs.append('tools[]', t)); }
  if(fais.length > 0 && ['AOI','OMM','CMM','OQC'].includes((type||'').toUpperCase())){ fais.forEach(v => qs.append('fai[]', v)); }

  qs.set('format','csv');

  // Safety: keep only the selected page date
  qs.delete('page');
  qs.delete('per_page');

  const url = new URL('lib/ipqc_excel_export.php', window.location.href); 
  url.search = qs.toString();

  showBusy('내보내는 중...', '파일 생성 중…');
  triggerDownload(url.toString());
  setTimeout(hideBusy, 2000);
}
</script>


<script>
window.__QPC_REPORT_PAGE_URL = <?php echo json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/ipqc_process_capability_report.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>

<script>
/* IPQC Quick Graph open safety wrapper (modal + JS are external):
 * - keeps ipqc_view.php smaller
 * - gives visible feedback when quick graph modal script didn't load
 */
(function(){
  function safeOpen(){
    try{
      if (typeof window.openQuickGraphModal === 'function') return window.openQuickGraphModal();
      var ov = document.getElementById('qgOverlay');
      if (ov){
        ov.style.display = 'block';
        ov.setAttribute('aria-hidden','false');
      }
      alert('그래프 빌더 모달이 로드되지 않았습니다. (openQuickGraphModal 미정의)\n\n1) 서버에 반영된 파일이 맞는지 확인\n2) 개발자도구(F12) Console에서 오류 확인');
    }catch(e){
      alert('그래프 빌더 열기 실패: ' + (e && e.message ? e.message : String(e)));
    }
  }
  function safeOpenProcessCapability(){
    try{
      if (typeof window.openProcessCapabilityModal === 'function') return window.openProcessCapabilityModal();
      var ov = document.getElementById('qpcOverlay');
      if (ov){
        ov.style.display = 'block';
        ov.setAttribute('aria-hidden','false');
      }
      alert('공정 능력 모달이 로드되지 않았습니다. (openProcessCapabilityModal 미정의)\n\n1) 서버에 반영된 파일이 맞는지 확인\n2) 개발자도구(F12) Console에서 오류 확인');
    }catch(e){
      alert('공정 능력 열기 실패: ' + (e && e.message ? e.message : String(e)));
    }
  }

  window.__ipqcOpenQuickGraphSafe = safeOpen;
  window.__ipqcOpenProcessCapabilitySafe = safeOpenProcessCapability;

  document.addEventListener('click', function(ev){
    var btn = ev.target && ev.target.closest ? ev.target.closest('#btnQuickGraphOpen') : null;
    if (!btn) return;
    ev.preventDefault();
    safeOpen();
  });

  document.addEventListener('click', function(ev){
    var btn = ev.target && ev.target.closest ? ev.target.closest('#btnProcessCapabilityOpen') : null;
    if (!btn) return;
    ev.preventDefault();
    safeOpenProcessCapability();
  });
})();
</script>


<?php
// Quick graph modal (simple, average of Data 1~3)
include_once $ROOT . '/Module/IPQC/lib/ipqc_quick_graph_modal.php';
include_once $ROOT . '/Module/IPQC/lib/ipqc_process_capability_modal.php';
?>

</body>
</html>
