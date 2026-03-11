<?php
session_start();
@ini_set('memory_limit', '2048M');
$ROOT = __DIR__;
// JMP Assist (AOI / OMM JMP / CMM JMP)
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
  $spc = trim((string)$spc);
  $keyName = trim($keyName);
  $base = is_numeric_leading($keyName) ? ('FAI ' . $keyName) : $keyName;
  return $spc !== '' ? ($base . ' / SPC ' . $spc) : $base;
}

// OMM 컬럼 정렬: 숫자로 시작하는 FAI류 먼저, 그 다음 자연정렬
function ipqc_cmp_omm_cols(string $aKey, string $bKey): int {
  $aParts = explode('|', $aKey, 2);
  $bParts = explode('|', $bKey, 2);
  $aName = $aParts[0];
  $bName = $bParts[0];

  $ag = is_numeric_leading($aName) ? 0 : 1;
  $bg = is_numeric_leading($bName) ? 0 : 1;
  if ($ag !== $bg) return $ag <=> $bg;

  $aLabel = omm_col_label($aName, $aParts[1] ?? '');
  $bLabel = omm_col_label($bName, $bParts[1] ?? '');
  $c = strnatcasecmp($aLabel, $bLabel);
  if ($c !== 0) return $c;

  return strnatcasecmp($aKey, $bKey);
}

function ipqc_cmp_nat(string $a, string $b): int {
  $c = strnatcasecmp($a, $b);
  return $c !== 0 ? $c : strcmp($a, $b);
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

$TYPE_MAP = [
  'AOI' => ['label' => 'AOI',     'header' => 'ipqc_aoi_header', 'meas' => 'ipqc_aoi_measurements', 'res' => 'ipqc_aoi_result', 'key_col' => 'fai',      'has_spc' => true,  'data_cols' => 16],
  'OMM' => ['label' => 'OMM JMP', 'header' => 'ipqc_omm_header', 'meas' => 'ipqc_omm_measurements', 'res' => 'ipqc_omm_result', 'key_col' => 'fai',      'has_spc' => true,  'data_cols' => 3],
  'CMM' => ['label' => 'CMM JMP', 'header' => 'ipqc_cmm_header', 'meas' => 'ipqc_cmm_measurements', 'res' => 'ipqc_cmm_result', 'key_col' => 'point_no', 'has_spc' => false, 'data_cols' => 3],
];

$type = strtoupper($_GET['type'] ?? 'OMM');
if (!isset($TYPE_MAP[$type])) $type = 'OMM';

$model = trim($_GET['model'] ?? '');

// Build order index for current model/type (if mapping exists)
$orderIndex = [];
$__mk = ipqc_model_to_mapkey($model);
if ($__mk !== '' && isset($IPQC_ORDER_MAP[$__mk]) && is_array($IPQC_ORDER_MAP[$__mk])) {
  $__list = $IPQC_ORDER_MAP[$__mk][$type] ?? [];
  if (is_array($__list)) {
    $i = 0;
    foreach ($__list as $__lab) {
      $__lab = (string)$__lab;
      if ($__lab !== '' && !isset($orderIndex[$__lab])) $orderIndex[$__lab] = $i;
      $i++;
    }
  }
}

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

// 월: 다중 선택(체크박스)
$months = $_GET['months'] ?? [];
if (!is_array($months)) $months = [$months];
$months = array_values(array_unique(array_filter(array_map('intval', $months), function($m){
  return $m >= 1 && $m <= 12;
})));

// 하위호환: month= (단일)
$monthLegacy = (int)($_GET['month'] ?? 0);
if (!$months && $monthLegacy >= 1 && $monthLegacy <= 12) $months = [$monthLegacy];

// 아무것도 체크 안 했으면 기본: 현재 월 1개
if (!$months) $months = [(int)date('n')];


// Paging (브라우저 속도용: 날짜 단위 페이지)
// - page_date=YYYY-MM-DD 형태로 날짜를 선택 (UI에서 선택)
// - 하위호환: page=숫자(1부터)면 해당 순번 날짜로 선택
$pageDate = trim((string)($_GET['page_date'] ?? ''));

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
$models = [];
try {
  $sql = "SELECT DISTINCT part_name FROM {$headerTable} WHERE meas_date IS NOT NULL ORDER BY part_name";
  $models = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (Throwable $e) {
  $models = [];
}

// options: tool list (dynamic, + '전체')
$tools = [];
try {
  $where = "meas_date IS NOT NULL";
  $params = [];
  if ($model !== '') { $where .= " AND part_name = :p"; $params[':p'] = $model; }
  $stmt = $pdo->prepare("SELECT DISTINCT tool FROM {$headerTable} WHERE {$where} ORDER BY tool");
  $stmt->execute($params);
  $tools = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (Throwable $e) {
  $tools = [];
}

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


$pageDates = [];
$doQuery = (isset($_GET['run']) && $_GET['run'] === '1' && $model !== '');

if ($doQuery) {
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

// 날짜 목록(pageDates) 먼저 조회 (header만 조회 -> 메모리/속도 절약)
  try {
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
    $stmtD = $pdo->prepare($sqlDates);
    $stmtD->execute($paramsDates);
    $pageDates = $stmtD->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!is_array($pageDates)) $pageDates = [];
  } catch (Throwable $e) {
    $pageDates = [];
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

  // 날짜(하루) 필터 (ALL이 아니면 쿼리 자체를 하루로 제한)
  $dayParamsPos = [];
  $daySql = '';
  if (!$pageAll && $pageDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageDate)) {
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
        WHERE {$baseWhere}
        GROUP BY h.part_name, DATE(h.meas_date), h.tool, h.cavity, spc, m.{$keyCol}
    ) t";
    $stmt = $pdo->prepare($sqlCntAll);
    $stmt->execute(array_merge($dateParamsPos, [$model], $toolParamsPos));
    $totalRowsAll = (int)($stmt->fetchColumn() ?: 0);

    $sqlCntView = "SELECT COUNT(*) FROM (
        SELECT
          h.part_name, DATE(h.meas_date) AS d, h.tool, h.cavity,
          {$spcExpr} AS spc,
          m.{$keyCol} AS key_name
        FROM {$headerTable} h
        JOIN {$measTable} m ON m.header_id = h.id
        WHERE {$baseWhere} {$daySql}
        GROUP BY h.part_name, DATE(h.meas_date), h.tool, h.cavity, spc, m.{$keyCol}
    ) t";
    $stmt = $pdo->prepare($sqlCntView);
    $stmt->execute(array_merge($dateParamsPos, [$model], $toolParamsPos, $dayParamsPos));
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
    ORDER BY h.meas_date, h.tool, h.cavity, key_name, m.row_index
  ";

  try {
    $params = array_merge($dateParamsPos, $dayParamsPos, [$model], $toolParamsPos);

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
      usort($results, function($a, $b) {
        $c = ipqc_cmp_nat((string)$a['tool'], (string)$b['tool']);
        if ($c !== 0) return $c;
        $c = ipqc_cmp_nat((string)$a['date'], (string)$b['date']);
        if ($c !== 0) return $c;
        $c = ipqc_cmp_nat((string)$a['cavity'], (string)$b['cavity']);
        if ($c !== 0) return $c;
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
          $keyName = (string)($r['key_name'] ?? '');
          $spc = (string)($r['spc'] ?? '');
          $colKey = $keyName . '|' . $spc;

          if (!isset($colMeta[$colKey])) {
            $colMeta[$colKey] = [
              'label' => omm_col_label($keyName, $spc),
              'usl'   => $r['usl'],
              'lsl'   => $r['lsl'],
];
          }
        } else { // CMM
          $keyName = (string)($r['key_name'] ?? ''); // point_no
          $colKey = $keyName;

          if (!isset($colMeta[$colKey])) {
            $colMeta[$colKey] = [
              'label' => cmm_map_fai_name($model, $keyName),
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
</head>
<body>
<!-- IPQC_VIEW PATCH v10.15 -->

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

            <?php if($EMBED): ?><input type="hidden" name="embed" value="1"/><?php endif; ?>
            <input type="hidden" name="run" value="1"/>
            <input type="hidden" name="page_date" id="pageDateHidden" value="<?= h($pageDate) ?>"/>

            <div class="f">
              <label>측정 타입</label>
              <select name="type" id="type" onchange="this.form.submit()">
                <?php foreach($TYPE_MAP as $k=>$v): ?>
                  <option value="<?= h($k) ?>" <?= $k===$type?'selected':'' ?>><?= h($v['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="f">
              <label>모델</label>
              <select name="model" id="model">
                <option value="">(선택)</option>
                <?php foreach($models as $m): ?>
                  <option value="<?= h($m) ?>" <?= $m===$model?'selected':'' ?>><?= h($m) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="f">
  <label>Tool (복수 선택)</label>
  <div class="ms" id="ms-tools" data-group="tools">
    <button type="button" class="ms-toggle" onclick="toggleMs('ms-tools')">
      <span class="ms-summary" id="ms-tools-summary"></span>
      <span class="ms-caret">▾</span>
    </button>
    <div class="ms-panel">
      <div class="ms-actions">
        <button type="button" class="mini" onclick="checkAllIn('ms-tools', true); syncMs('ms-tools');">전체</button>
        <button type="button" class="mini" onclick="checkAllIn('ms-tools', false); syncMs('ms-tools');">해제</button>
      </div>
      <div class="ms-list ms-grid-tools">
        <?php foreach($tools as $t): ?>
          <label class="ms-item">
            <input type="checkbox" name="tools[]" value="<?= h($t) ?>"
              <?= (empty($toolsSel) || in_array($t, $toolsSel, true)) ? 'checked' : '' ?>
              onchange="syncMs('ms-tools')">
            <span><?= h($t) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

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
                      <label class="ms-item">
                        <input type="checkbox" name="years[]" value="<?= $yy ?>"
                          <?= in_array($yy, $yearsSel, true) ? 'checked' : '' ?>
                          onchange="syncMs('ms-years')">
                        <span><?= $yy ?></span>
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
          <label class="ms-item">
            <input type="checkbox" name="months[]" value="<?= $m ?>"
              <?= in_array($m, $months, true) ? 'checked' : '' ?>
              onchange="syncMs('ms-months')">
            <span><?= $m ?>월</span>
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
          $ui_tools_label  = (!empty($toolsSel)) ? implode(', ', $toolsSel) : '전체';
          $ui_years_label  = (!empty($yearsSel)) ? implode(',', $yearsSel) : '전체';
          $ui_months_label = (!empty($monthsSel)) ? implode(',', $monthsSel) : '전체';
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
<div class="mid-right">
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
                        <button type="button" class="ms-datebtn <?= (!$pageAll && $d===$pageDate)?'active':'' ?>" onclick="setPageDate('<?= h($d) ?>')"><?php if ($y !== ''): ?><span class="ms-datebtn-y"><?= h($y) ?></span><?php endif; ?><span class="ms-datebtn-md"><?= h($md) ?></span></button>
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
                      <th><?= h($pc['label']) ?></th>
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
    }

    function syncMs(id){
      const el = document.getElementById(id);
      if(!el) return;
      const group = el.getAttribute('data-group');
      const checked = Array.from(el.querySelectorAll('input[name="'+group+'[]"]:checked')).map(x=>x.value);
      const allCount = el.querySelectorAll('input[name="'+group+'[]"]').length;
      let label = '';
      if(group === 'months'){
        const nums = checked.map(x=>parseInt(x,10)).filter(n=>!isNaN(n)).sort((a,b)=>a-b);
        if(nums.length === 0){
          label = '(선택 없음)';
        }else if(nums.length === 12){
          label = '전체';
        }else{
          label = nums.map(n=>String(n)+'월').join(', ');
        }
      }else if(group === 'years'){
        const nums = checked.map(x=>parseInt(x,10)).filter(n=>!isNaN(n)).sort((a,b)=>a-b);
        if(nums.length === 0){
          label = '(선택 없음)';
        }else if(nums.length === allCount){
          label = '전체';
        }else{
          label = nums.join(', ');
        }
      }else{
        if(checked.length === 0){
          label = '전체';
        }else if(checked.length === allCount){
          label = '전체';
        }else{
          label = checked.join(', ');
        }
      }
      const s = document.getElementById(id+'-summary');
      if(s) s.textContent = label;
    }

    // check/uncheck all checkboxes inside a multi-select
    function checkAllIn(msId, on){
      const root = document.getElementById(msId);
      if(!root) return;
      root.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.checked = !!on;
      });
    }

    // close dropdown when clicking outside
    document.addEventListener('click', function(e){
      const open = document.querySelector('.ms.open');
      if(!open) return;
      if(open.contains(e.target)) return;
      open.classList.remove('open');
    });

    // init
    window.addEventListener('load', function(){
      syncMs('ms-tools');
      syncMs('ms-years');
      syncMs('ms-months');
    });

    // 날짜 페이지 선택 (page_date)
    const PAGE_DATES = <?= json_encode($pageDates, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const CUR_PAGE_DATE = <?= json_encode($pageDate, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

    function setPageDate(d){
      var f=document.getElementById('filterForm');
      if(!f) return;
      var h=document.getElementById('pageDateHidden');
      if(!h){ h=document.createElement('input'); h.type='hidden'; h.name='page_date'; h.id='pageDateHidden'; f.appendChild(h); }
      h.value = String(d||'');
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

      const qs = new URLSearchParams();
      qs.set('mode','all');
      qs.set('type', type);
      qs.set('model', model);

      // legacy + multi
      qs.set('year', years[0]);
      years.forEach(y => qs.append('years[]', y));
      months.forEach(m => qs.append('months[]', m));
      if(tools.length > 0){ tools.forEach(t => qs.append('tools[]', t)); }

      qs.set('format','csv');
            // Safety: do not accidentally export only one page
      qs.delete('page_date');
      qs.delete('page');
      qs.delete('per_page');
      const url = 'lib/ipqc_excel_export.php?' + qs.toString();

      showBusy('내보내는 중...', '파일 생성 중…');
      triggerDownload(url);
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

      const colsMap = {'AOI':16,'OMM':3,'CMM':3};
      const cols = colsMap[(type||'').toUpperCase()] || 16;

      const qs = new URLSearchParams();
      qs.set('mode','page_date');
      qs.set('type', type);
      qs.set('model', model);
      qs.set('page_date', pageDate);
      qs.set('cols', String(cols));

      // legacy + multi
      if (years.length > 0) qs.set('year', years[0]);
      years.forEach(y => qs.append('years[]', y));
      months.forEach(m => qs.append('months[]', m));
      if(tools.length > 0){ tools.forEach(t => qs.append('tools[]', t)); }

      qs.set('format','csv');
            // Safety: keep only the selected page date
      qs.delete('page');
      qs.delete('per_page');
      const url = 'lib/ipqc_excel_export.php?' + qs.toString();

      showBusy('내보내는 중...', '파일 생성 중…');
      triggerDownload(url);
      // Close the modal shortly after starting the download (best-effort)
      setTimeout(hideBusy, 2000);
      }
  </script>

  <div id="busyModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999; align-items:center; justify-content:center;">
    <div style="width:min(420px, 92vw); background:rgba(25,25,25,.95); border:1px solid rgba(255,255,255,.12); border-radius:14px; padding:16px 16px 14px; box-shadow:0 18px 60px rgba(0,0,0,.6);">
      <div id="busyTitle" style="font-weight:700; margin-bottom:10px;">조회 중...</div>
      <div style="height:10px; border-radius:999px; background:rgba(255,255,255,.12); overflow:hidden;">
        <div class="busyBar" style="height:100%; width:35%; border-radius:999px; background:rgba(80,220,120,.9); animation:busyMove 1.1s ease-in-out infinite;"></div>
      </div>
      <div id="busySub" style="opacity:.85; margin-top:10px; font-size:12px;">잠시만 기다려주세요.</div>
      <div style="display:flex; justify-content:flex-end; margin-top:14px;">
        <button type="button" class="btn" onclick="hideBusy()" style="padding:6px 10px; font-size:12px;">닫기</button>
      </div>
    </div>
  </div>
  <div id="msgModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:10000; align-items:center; justify-content:center;">
    <div style="width:min(420px, 92vw); background:rgba(25,25,25,.95); border:1px solid rgba(255,255,255,.12); border-radius:14px; padding:16px 16px 14px; box-shadow:0 18px 60px rgba(0,0,0,.6);">
      <div id="msgTitle" style="font-weight:700; margin-bottom:10px;">알림</div>
      <div id="msgBody" style="opacity:.88; font-size:13px; line-height:1.45; margin-bottom:14px;"></div>
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" class="btn" id="msgOk" onclick="hideMsg()" style="padding:6px 10px; font-size:12px;">확인</button>
      </div>
    </div>
  </div>

  <script>
    // close msg modal on backdrop click / ESC
    (function(){
      var m=document.getElementById('msgModal');
      if(!m) return;
      m.addEventListener('click', function(e){
        if(e.target === m) hideMsg();
      });
      document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){
          if(m.style.display === 'flex') hideMsg();
        }
      });
    })();
  </script>

<style>
    @keyframes busyMove { 0%{ transform:translateX(-10%);} 50%{ transform:translateX(170%);} 100%{ transform:translateX(-10%);} }
  </style>
</body>

</html>