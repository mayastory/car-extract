<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }


// 한국시간 강제
date_default_timezone_set('Asia/Seoul');

// shipinglist_list.php : ShipingList 출하내역 조회(다크모드 + 필터 + datalist)

session_start();
require_once JTMES_ROOT . '/config/dp_config.php';

// Web app base (/dptest)
$sn = $_SERVER['SCRIPT_NAME'] ?? '';
$seg = explode('/', trim($sn, '/'));
$app = $seg[0] ?? '';
$APP_BASE = $app !== '' ? '/' . $app : '';

// ✅ embed=1 이면(쉘/iframe 내부) 사이드바/유저바/매트릭스 출력 안 함
$EMBED = !empty($_GET['embed']);
if (!$EMBED) {
    require_once JTMES_ROOT . '/inc/sidebar.php';
    require_once JTMES_ROOT . '/inc/dp_userbar.php';
}


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

if (!function_exists('col')) {
    function col(array $row, string $key): string {
            return h($row[$key] ?? '');
        }
}


// 로그인 체크 + 단일세션 검사
require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();
try {
    $pdo = dp_get_pdo();
} catch (PDOException $e) {
    die("DB 접속 실패: " . h($e->getMessage()));
}


// ─────────────────────────────
// 팝업 필터 요청(__popup=1) 처리: 이 파일 안에서 HTML fragment 반환
// ─────────────────────────────
if (($_GET['__popup'] ?? '') === '1') {
    header('Content-Type: text/html; charset=UTF-8');

    $popupCol = trim((string)($_GET['popup_col'] ?? ''));
    $popupVal = trim((string)($_GET['popup_val'] ?? ''));

    $allowedCols = [
        'pack_date' => ['label' => '포장일자',   'type' => 'date'],
        'prod_date' => ['label' => '생산일자',   'type' => 'date'],
        'ann_date'  => ['label' => '어닐링일자', 'type' => 'date'],
        'pack_no'   => ['label' => '포장번호',   'type' => 'text'],
    ];

    if (!isset($allowedCols[$popupCol])) {
        echo '<div class="pf-meta">허용되지 않은 필터입니다.</div>';
        exit;
    }
    if ($popupVal === '') {
        echo '<div class="pf-meta">필터 값이 비어있습니다.</div>';
        exit;
    }

    if ($allowedCols[$popupCol]['type'] === 'date') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $popupVal)) {
            echo '<div class="pf-meta">날짜 형식이 올바르지 않습니다.</div>';
            exit;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $popupVal);
        if (!$dt || $dt->format('Y-m-d') !== $popupVal) {
            echo '<div class="pf-meta">날짜 값이 올바르지 않습니다.</div>';
            exit;
        }
    }

    $sql = "
        SELECT ShipingList.*,
               (
                   SELECT MAX(COALESCE(r.return_datetime, r.return_date))
                   FROM `rmalist` r
                   WHERE r.small_pack_no = ShipingList.small_pack_no
               ) AS last_return_datetime
        FROM ShipingList
        WHERE {$popupCol} = :v
        ORDER BY
        CASE
          WHEN SUBSTRING_INDEX(TRIM(COALESCE(NULLIF(pack_no,''), small_pack_no)), '/', -1) REGEXP '^[0-9]{6,8}-[0-9]+'
            THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(COALESCE(NULLIF(pack_no,''), small_pack_no)), '/', -1), '-', 1), ' ', 1) AS UNSIGNED)
          ELSE 0
        END DESC,
        CASE
          WHEN SUBSTRING_INDEX(TRIM(COALESCE(NULLIF(pack_no,''), small_pack_no)), '/', -1) REGEXP '^[0-9]{6,8}-[0-9]+'
            THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(COALESCE(NULLIF(pack_no,''), small_pack_no)), '/', -1), '-', 2), '-', -1) AS UNSIGNED)
          ELSE 999999999
        END ASC,
        CASE
          WHEN SUBSTRING_INDEX(TRIM(COALESCE(NULLIF(pack_no,''), small_pack_no)), '/', -1) LIKE '%-%-%'
            THEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(COALESCE(NULLIF(pack_no,''), small_pack_no)), '/', -1), '-', -1)
          ELSE ''
        END ASC,
        TRIM(COALESCE(NULLIF(pack_no,''), small_pack_no)) ASC,
        small_pack_no ASC,
        ship_datetime DESC,
        id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':v' => $popupVal]);
    $rowsPopup = $st->fetchAll(PDO::FETCH_ASSOC);
    $cnt = count($rowsPopup);

    $label = $allowedCols[$popupCol]['label'];

    echo '<div class="pf-meta"><b>' . h($label) . '</b> = <span class="pf-val">' . h($popupVal) . '</span> &nbsp; <span class="pf-muted">결과: ' . number_format($cnt) . '건</span></div>';

    if (!$rowsPopup) {
        echo '<div class="pf-empty">데이터가 없습니다.</div>';
        exit;
    }

    echo '<div class="pf-table-wrap"><table class="pf-table"><thead><tr>'
        . '<th>창고</th><th>포장일자</th><th>생산일자</th><th>어닐링일자</th><th>설비</th><th>CAVITY</th><th>품번코드</th><th>차수</th><th>고객사 품번</th><th>품번명</th><th>모델</th><th>프로젝트</th><th>소포장 NO</th><th>Tray NO</th><th>납품처</th><th>출고수량</th><th>출하일자</th><th>고객 LOTID</th><th>포장바코드</th><th>포장번호</th><th>AVI</th><th>RETURNDATE</th><th>RMA</th>'
        . '</tr></thead><tbody>';

    foreach ($rowsPopup as $r) {
        $retDisp = ($r['last_return_datetime'] ?? '') ?: (($r['return_date'] ?? '') ?: ($r['return_datetime'] ?? ''));
        if (is_string($retDisp) && substr($retDisp, 0, 10) === '0000-00-00') $retDisp = '';

        echo '<tr>'
            . '<td>'.h($r['warehouse'] ?? '').'</td>'
            . '<td>'.h($r['pack_date'] ?? '').'</td>'
            . '<td>'.h($r['prod_date'] ?? '').'</td>'
            . '<td>'.h($r['ann_date'] ?? '').'</td>'
            . '<td>'.h($r['facility'] ?? '').'</td>'
            . '<td>'.h($r['cavity'] ?? '').'</td>'
            . '<td>'.h($r['part_code'] ?? '').'</td>'
            . '<td>'.h($r['revision'] ?? '').'</td>'
            . '<td>'.h($r['customer_part_no'] ?? '').'</td>'
            . '<td>'.h($r['part_name'] ?? '').'</td>'
            . '<td>'.h($r['model'] ?? '').'</td>'
            . '<td>'.h($r['project'] ?? '').'</td>'
            . '<td>'.h($r['small_pack_no'] ?? '').'</td>'
            . '<td>'.h($r['tray_no'] ?? '').'</td>'
            . '<td>'.h($r['ship_to'] ?? '').'</td>'
            . '<td class="num-right">'.h($r['qty'] ?? '').'</td>'
            . '<td>'.h($r['ship_datetime'] ?? '').'</td>'
            . '<td>'.h($r['customer_lot_id'] ?? '').'</td>'
            . '<td>'.h($r['pack_barcode'] ?? '').'</td>'
            . '<td>'.h($r['pack_no'] ?? '').'</td>'
            . '<td>'.h($r['avi'] ?? '').'</td>'
            . '<td>'.h($retDisp).'</td>'
            . '<td>'.h($r['rma_visit_cnt'] ?? '').'</td>'
            . '</tr>';
    }
    echo '</tbody></table></div>';
    exit;
}

// ─────────────────────────────
// 필터 값 읽기 (GET)
// ─────────────────────────────
$today = date('Y-m-d');

// 날짜: GET에 없거나 비어있으면 오늘로 강제 설정
$fromDate = trim($_GET['from_date'] ?? '');
$toDate   = trim($_GET['to_date']   ?? '');
$fromDate = normalize_date_ymd($fromDate);
$toDate   = normalize_date_ymd($toDate);

$normYmd = function($v, $fallback) {
    $v = trim((string)$v);
    if ($v === '') return $fallback;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $fallback;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) return $fallback;
    return $v;
};

$fromDate = $normYmd($fromDate, $today);
$toDate   = $normYmd($toDate, $today);


// 나머지 필터값
$filterShipTo  = trim($_GET['ship_to']   ?? '');
$filterPackBc  = trim($_GET['pack_bc']   ?? '');
$filterSmallNo = trim($_GET['small_no']  ?? '');
$filterTrayNo  = trim($_GET['tray_no']   ?? '');
$filterPname   = trim($_GET['part_name'] ?? '');

// ✅ 날짜조건 0건이면 전체기간으로 자동 fallback
$forceFallback = (($_GET['fallback'] ?? '') === '1');
$hasAnyKeyword = (
    $filterShipTo  !== '' ||
    $filterPackBc  !== '' ||
    $filterSmallNo !== '' ||
    $filterTrayNo  !== '' ||
    $filterPname   !== ''
);




$didFallback = false;
// 페이징
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 100;
$allowed = [50, 100, 200, 500];
if (!in_array($perPage, $allowed, true)) {
    $perPage = 100;
}
$offset = ($page - 1) * $perPage;

// ─────────────────────────────
// 날짜 차이 계산 (heavy 쿼리 on/off 판단용)
// ─────────────────────────────
$diffDays = null;
if ($fromDate !== '' && $toDate !== '') {
    $fromTsRaw = strtotime($fromDate);
    $toTsRaw   = strtotime($toDate);
    if ($fromTsRaw !== false && $toTsRaw !== false) {
        // 양 끝 포함 일수 (예: 같은 날 → 1일)
        $diffDays = (int)ceil(($toTsRaw - $fromTsRaw) / 86400) + 1;
    }
}

// 너무 넓은 기간이면 datalist / 합계는 생략해서 속도 확보
// (예: 60일 초과 시 OFF)
$enableHeavy = true;
$heavyThresholdDays = 7; // was 60 (7일 초과면 heavy 쿼리 OFF)
if ($diffDays !== null && $diffDays > $heavyThresholdDays) {
    $enableHeavy = false;
}

// fallback 모드에서는 전체기간 검색이 될 수 있으니 heavy는 강제 OFF
if ($forceFallback) {
    $enableHeavy = false;
    $diffDays = null;
}

// ─────────────────────────────
// WHERE 절 구성 (ship_datetime는 '날짜' 기준으로만 필터)  ※컷오프 제거
// ─────────────────────────────
$whereParts = [];
$params = [];

// 분리: 날짜조건 / 기타 필터 (fallback=1이면 날짜조건 무시)
$dateWhereParts   = [];
$dateParams       = [];
$filterWhereParts = [];
$filterParams     = [];

// 날짜 조건
if (!$forceFallback) {
    if ($fromDate !== '' && $toDate !== '') {
        // to_date 포함: [from 00:00:00, (to+1) 00:00:00)
        $fromDt = $fromDate . ' 00:00:00';
        $toDt   = date('Y-m-d 00:00:00', strtotime($toDate . ' +1 day'));

        $dateWhereParts[]    = "ship_datetime >= :from_dt";
        $dateWhereParts[]    = "ship_datetime < :to_dt";
        $dateParams[':from_dt'] = $fromDt;
        $dateParams[':to_dt']   = $toDt;
    } else {
        if ($fromDate !== '') {
            $fromDt = $fromDate . ' 00:00:00';
            $dateWhereParts[]      = "ship_datetime >= :from_dt";
            $dateParams[':from_dt'] = $fromDt;
        }
        if ($toDate !== '') {
            $toDt = date('Y-m-d 00:00:00', strtotime($toDate . ' +1 day'));
            $dateWhereParts[]    = "ship_datetime < :to_dt";
            $dateParams[':to_dt'] = $toDt;
        }
    }
}

// ✅ 납품처 / 포장바코드 필터
if ($filterShipTo !== '') {
    $filterWhereParts[]       = 'ship_to LIKE :ship_to';
    $filterParams[':ship_to'] = '%' . $filterShipTo . '%';
}
if ($filterPackBc !== '') {
    $filterWhereParts[]       = 'pack_barcode LIKE :pack_bc';
    $filterParams[':pack_bc'] = '%' . $filterPackBc . '%';
}

if ($filterSmallNo !== '') {
    $filterWhereParts[]        = 'small_pack_no LIKE :small_no';
    $filterParams[':small_no'] = '%' . $filterSmallNo . '%';
}
if ($filterTrayNo !== '') {
    $filterWhereParts[]       = 'tray_no LIKE :tray_no';
    $filterParams[':tray_no'] = '%' . $filterTrayNo . '%';
}
if ($filterPname !== '') {
    $filterWhereParts[]         = 'part_name LIKE :part_name';
    $filterParams[':part_name'] = '%' . $filterPname . '%';
}

// merge
$whereParts = array_merge($dateWhereParts, $filterWhereParts);
$params     = array_merge($dateParams, $filterParams);

$whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// ─────────────────────────────
// 총 개수 & 데이터
// ─────────────────────────────
$countSql = "SELECT COUNT(*) FROM ShipingList {$whereSql}";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();


// ✅ 날짜조건(기본: 오늘)으로 0건이면 → 날짜조건을 빼고(전체기간) 한 번 더 검색
if (!$forceFallback && $total === 0 && $hasAnyKeyword) {
    $didFallback = true;

    // 전체기간 fallback이면 heavy 기능은 꺼서 속도 확보
    $enableHeavy = false;

    // fallback은 1페이지부터
    $page = 1;
    $offset = 0;

    // 날짜조건 제외하고 재조회
    $whereParts = $filterWhereParts;
    $params     = $filterParams;
    $whereSql   = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    $countSql = "SELECT COUNT(*) FROM ShipingList {$whereSql}";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
}
$totalPages = max(1, (int)ceil($total / $perPage));

// 리스트 본문: 인덱스(ship_datetime, id) 활용
$listSql = "
    SELECT ShipingList.*,
           (
               SELECT MAX(COALESCE(r.return_datetime, r.return_date))
               FROM `rmalist` r
               WHERE r.small_pack_no = ShipingList.small_pack_no
           ) AS last_return_datetime
    FROM ShipingList
    {$whereSql}
    ORDER BY
        COALESCE(NULLIF(SUBSTRING_INDEX(COALESCE(NULLIF(pack_no,''), small_pack_no), '-', 1), ''), DATE_FORMAT(ship_datetime, '%Y%m%d')) DESC,
        CASE
          WHEN COALESCE(NULLIF(pack_no,''), small_pack_no) REGEXP '^[0-9]{6,8}-[0-9]+' THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(NULLIF(pack_no,''), small_pack_no), '-', 2), '-', -1) AS UNSIGNED)
          ELSE 999999999
        END ASC,
        CASE
          WHEN COALESCE(NULLIF(pack_no,''), small_pack_no) LIKE '%-%-%' THEN SUBSTRING_INDEX(COALESCE(NULLIF(pack_no,''), small_pack_no), '-', -1)
          ELSE ''
        END ASC,
        COALESCE(NULLIF(pack_no,''), small_pack_no) ASC,
        small_pack_no ASC,
        ship_datetime DESC,
        id DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$startItem = $total === 0 ? 0 : ($offset + 1);
$endItem   = min($total, $offset + $perPage);

// ─────────────────────────────
// datalist용 distinct 값(현재 필터 조건 기준)
// ─────────────────────────────
$listShipTo  = [];
$listPackBc  = [];
$listSmallNo = [];
$listTrayNo  = [];
$listPname   = [];

// ★ 기간이 너무 넓으면(>60일) distinct 5개는 생략 (속도용)
if ($total > 0 && $enableHeavy) {
    $distinctLimit = 200;

    $sql = "SELECT DISTINCT ship_to FROM ShipingList {$whereSql} ORDER BY ship_to LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listShipTo = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT DISTINCT pack_barcode FROM ShipingList {$whereSql} ORDER BY pack_barcode LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listPackBc = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT DISTINCT small_pack_no FROM ShipingList {$whereSql} ORDER BY small_pack_no LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listSmallNo = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT DISTINCT tray_no FROM ShipingList {$whereSql} ORDER BY tray_no LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listTrayNo = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT DISTINCT part_name FROM ShipingList {$whereSql} ORDER BY part_name LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listPname = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ─────────────────────────────
// 품번명별 출고수량 합계 (현재 검색조건 기준)
// ─────────────────────────────
$sumRows = [];
if ($total > 0 && $enableHeavy) {
    $sumSql = "
        SELECT part_name, SUM(qty) AS sum_qty
        FROM ShipingList
        {$whereSql}
        GROUP BY part_name
        ORDER BY part_name
    ";
    $stmt = $pdo->prepare($sumSql);
    $stmt->execute($params);
    $sumRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 페이지 번호 범위
$windowSize = 5;
$startPage  = max(1, $page - (int)floor($windowSize / 2));
$endPage    = min($totalPages, $startPage + $windowSize - 1);
if ($endPage - $startPage + 1 < $windowSize) {
    $startPage = max(1, $endPage - $windowSize + 1);
}

// 페이지 링크용 쿼리스트링 유지
function build_query(array $extra = []): string {
    $base = $_GET;
    unset($base['page']);
    $q = array_merge($base, $extra);
    return '?' . http_build_query($q);
}
function pageLink(int $p, int $per): string {
    return build_query(['page' => $p, 'per_page' => $per]);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>ShipingList 출하내역</title>
    <style>
        body {
            margin:0;
            padding:0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:#202124;
            color:#e8eaed;
        }
        .page-wrap {
            width:1550px;
            margin:20px auto 40px;
            padding:0 24px;
            box-sizing:border-box;
            position:relative;
        }
        .page-title {
            font-size:20px;
            font-weight:600;
            margin-bottom:8px;
        }
        .page-sub {
            font-size:12px;
            color:#9aa0a6;
            margin-bottom:10px;
        }

        .card-filter {
            background:#2b2b2b;
            border-radius:14px;
            box-shadow:0 8px 20px rgba(0,0,0,0.45);
            padding:12px 16px 10px;
            margin-bottom:14px;
        }
        .filter-row {
            display:flex;
            flex-wrap:wrap;
            gap:12px 14px;
            align-items:flex-end;
        }
        .filter-group {
            display:flex;
            flex-direction:column;
            font-size:12px;
            color:#e8eaed;
        }
        .filter-group label {
            margin-bottom:4px;
        }
        .filter-input,
        .filter-input-date {
            min-width:150px;
            padding:4px 8px;
            border-radius:6px;
            border:1px solid #5f6368;
            background:#202124;
            color:#e8eaed;
            font-size:12px;
            box-sizing:border-box;
        }
        .filter-input:focus,
        .filter-input-date:focus {
            outline:none;
            border-color:#8ab4f8;
            box-shadow:0 0 0 1px #8ab4f8;
        }
        .filter-input-date {
            padding-right:6px;
        }

        .btn-search {
            padding:7px 18px;
            border-radius:6px;
            border:none;
            background:#4f8cff;
            color:#ffffff;
            font-size:12px;
            font-weight:600;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        .btn-search:hover {
            background:#6ea0ff;
        }

        .card-list {
            background:#2b2b2b;
            border-radius:18px;
            box-shadow:0 12px 30px rgba(0,0,0,0.55);
            padding:14px 16px 0;
        }

        .top-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:6px;
        }
        .btn {
            padding:6px 12px;
            border-radius:6px;
            border:1px solid #5f6368;
            background:#303134;
            color:#e8eaed;
            font-size:12px;
            cursor:pointer;
        }
        .btn:hover {
            background:#3c4043;
        }

        .table-wrap {
            margin-top:6px;
            border-radius:12px 12px 0 0;
            overflow:auto;
            border:1px solid #3c4043;
            background: rgba(0,0,0,0.10);
        }

        table {
            width:100%;
            border-collapse:collapse;
            font-size:12px;
            min-width:1800px;
        }
        thead {
            background: rgba(53,54,58,0.55);
        }
        th, td {
            padding:6px 8px;
            border-bottom:1px solid #3c4043;
            white-space:nowrap;
            text-align:center;
        }
        th {
            font-weight:600;
        }

        .text-muted { color:#9aa0a6; }
        .num-right  { text-align:center; }

        .pager-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:8px 10px;
            font-size:12px;
            background: rgba(41,42,45,0.55);
            border:1px solid #3c4043;
            border-top:none;
            border-radius:0 0 12px 12px;
            margin-bottom:10px;
        }
        .pager-left, .pager-center, .pager-right {
            display:flex;
            align-items:center;
            gap:6px;
        }
        .page-btn {
            border:none;
            background:transparent;
            padding:3px 6px;
            cursor:pointer;
            font-size:12px;
            color:#9aa0a6;
        }
        .page-btn[disabled] {
            opacity:0.35;
            cursor:default;
        }
        .page-num {
            border-radius:6px;
            padding:3px 7px;
            cursor:pointer;
            border:1px solid transparent;
            text-decoration:none;
            color:#e8eaed;
        }
        .page-num.current {
            background:#8ab4f8;
            border-color:#8ab4f8;
            color:#202124;
            font-weight:600;
        }
        .page-num:hover:not(.current) {
            border-color:#5f6368;
            background:#3c4043;
        }
        select {
            font-size:12px;
            padding:3px 6px;
            border-radius:6px;
            border:1px solid #5f6368;
            background:#303134;
            color:#e8eaed;
        }
        select:focus {
            outline:none;
            border-color:#8ab4f8;
        }

        .table-wrap table tbody tr {
            transition: background-color 0.12s ease;
        }
        .table-wrap table tbody tr:hover {
            background:#224b7a;
        }
        .table-wrap table tbody tr:hover td {
            color:#e8eaed;
        }

        /* 품번명별 합계 카드: 폭/스타일만 */
        .sum-card {
            position:absolute;   /* JS에서 fixed로 바꿔줌 */
            top:122px;
            right:-205px;
            width:220px;
            background:#2b2b2b;
            border-radius:14px;
            box-shadow:0 10px 26px rgba(0,0,0,0.65);
            padding:8px 10px 10px;
            box-sizing:border-box;
            font-size:11px;
            z-index:5;
        }
        .sum-title {
            font-size:12px;
            font-weight:600;
            margin-bottom:3px;
        }
        .sum-caption {
            font-size:11px;
            color:#9aa0a6;
            margin-bottom:6px;
        }
        .sum-row {
            display:flex;
            align-items:center;
            margin-bottom:1px;
        }
        .sum-row-name {
            flex:1 1 auto;
            min-width:0;
            max-width:none;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
            padding-right:40px;
        }
        .sum-row-qty {
            min-width:60px;
            text-align:right;
            position:relative;
        }
        .sum-row-qty::after {
            content:"EAEA";
            opacity:0;
            pointer-events:none;
        }

        .modal-backdrop {
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.55);
            display:none;
            z-index:900;
        }
        .modal-backdrop.show {
            display:block;
        }

        /* ✅ 데이터 셀 클릭 → 팝업 필터 */
        .pf-cell{
            cursor:pointer;
            color:#8ab4f8;
            text-decoration:underline;
            text-decoration-color:rgba(138,180,248,0.35);
        }
        .pf-cell:hover{ background:rgba(138,180,248,0.12); }

        .pf-modal{
            position:fixed;
            left:50%;
            top:50%;
            transform:translate(-50%, -50%);
            background:#2b2b2b;
            border-radius:18px;
            box-shadow:0 20px 40px rgba(0,0,0,0.7);
            width:1200px;
            max-width:96%;
            padding:10px 12px 12px;
            display:none;
            z-index:911;
        }
        .pf-modal.show{ display:block; }

        .pf-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:8px 6px 10px;
            border-bottom:1px solid #3b3f45;
            margin-bottom:10px;
        }
        .pf-title{
            font-size:15px;
            font-weight:600;
            color:#f1f3f4;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
            padding-right:12px;
        }
        .pf-close{
            border:none;
            background:transparent;
            color:#f1f3f4;
            font-size:18px;
            cursor:pointer;
        }
        .pf-body{
            max-height:70vh;
            overflow:hidden; /* 스크롤은 pf-table-wrap 하나만 */
            display:flex;
            flex-direction:column;
            min-height:0;
        }
        .pf-meta{
            font-size:13px;
            color:#e8eaed;
            margin:0 0 10px;
        }
        .pf-muted{ color:#9aa0a6; font-size:12px; }
        .pf-val{ color:#ffd54f; }
        .pf-empty{ color:#9aa0a6; padding:10px 2px; }

        .pf-table-wrap{
            width:100%;
            flex:1 1 auto;
            min-height:0;
            overflow:auto;
            border-radius:12px;
            border:1px solid #3a3f47;
        }
        .pf-table{
            width:100%;
            border-collapse:collapse;
            font-size:12px;
            background:#202124;
            color:#e8eaed;
        }
        .pf-table th, .pf-table td{
            padding:7px 8px;
            border-bottom:1px solid #2f3338;
            white-space:nowrap;
        }
        .pf-table th{
            position:sticky;
            top:0;
            background:#2b2f36;
            z-index:1;
            font-weight:600;
        }
        .tool-modal {
            position:fixed;
            left:50%;
            top:50%;
            transform:translate(-50%, -50%);
            background:#2b2b2b;
            border-radius:18px;
            box-shadow:0 20px 40px rgba(0,0,0,0.7);
            width:1100px;
            max-width:95%;
            padding:10px 12px 12px;
            display:none;
            z-index:901;
        }
        .tool-modal.show {
            display:block;
        }
        .tool-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:4px 4px 6px;
            cursor:move;
        }
        .tool-title {
            font-size:15px;
            font-weight:600;
        }
        .tool-close {
            border:none;
            background:transparent;
            color:#f1f3f4;
            font-size:18px;
            cursor:pointer;
        }
        .tool-modal iframe {
            width:100%;
            height:480px;
            border:none;
            border-radius:12px;
            background:#202124;
        }

        /* ✅ 소포장 상세내역 모달 */\n        /* SP modal v12: body scroll lock + iframe cache bust */
        .sp-backdrop{ z-index:915; }
        .sp-modal {
            position:fixed;
            inset:8px;
            left:8px;
            top:8px;
            transform:none;
            background:#2b2b2b;
            border-radius:14px;
            box-shadow:0 20px 40px rgba(0,0,0,0.7);
            width:auto;
            max-width:none;
            height:auto;
            max-height:none;
            padding:8px;
            display:none;
            z-index:916;
            overflow:hidden;
        }
        .sp-modal.show { display:block; }
        .sp-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:8px;
            padding:2px 4px 6px;
            cursor:move;
        }
        .sp-tabs{
            display:flex;
            align-items:flex-end;
            gap:3px;
            min-width:0;
            flex:1 1 auto;
            padding-right:8px;
        }
        .sp-header .sp-tab{
            border:1px solid #4d5560 !important;
            background:linear-gradient(180deg,#2b3036,#1d2126) !important; /* 비활성 탭: 어두운 회색 */
            color:#e6ebef !important;
            font-weight:700;
            font-size:13px;
            line-height:1;
            height:32px;
            padding:0 14px;
            border-radius:10px 10px 0 0;
            cursor:pointer;
            white-space:nowrap;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.03);
            opacity:.95;
            transition:filter .12s ease, opacity .12s ease, border-color .12s ease, background .12s ease, color .12s ease;
        }
        .sp-header .sp-tab:hover{
            filter:brightness(1.06);
            opacity:1;
            color:#ffffff !important;
            border-color:#6a7380 !important;
        }
        .sp-header .sp-tab.active,
        .sp-header .sp-tab[aria-selected="true"]{
            background:linear-gradient(180deg,#1f8f58,#146a42) !important; /* 활성 탭: 초록 */
            color:#ffffff !important;
            border-color:#2fb06f !important;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.18), 0 0 0 1px rgba(14,78,49,.25), 0 1px 0 rgba(0,0,0,.28);
            opacity:1;
            filter:none;
        }
        .sp-header .sp-tab.active:hover,
        .sp-header .sp-tab[aria-selected="true"]:hover{
            filter:none;
            color:#ffffff !important;
            border-color:#37c17a !important;
        }
        .sp-close {
            border:none; background:transparent; color:#f1f3f4; font-size:18px; cursor:pointer;
            flex:0 0 auto;
        }
        html.sp-modal-open, body.sp-modal-open{ overflow:hidden !important; }
        .sp-modal iframe {
            width:100%;
            height:calc(100% - 40px);
            border:none;
            border-radius:10px;
            background:#202124;
            display:block;
        }
    
        

/* ✅ 성적서 제작 모달 */
.report-backdrop{ z-index:920; }
.report-modal{
    position:fixed;
    left:50%;
    top:50%;
    transform:translate(-50%, -50%);
    background:#2b2b2b;
    border-radius:18px;
    box-shadow:0 20px 40px rgba(0,0,0,0.7);
    width:1200px;
    max-width:96%;
    padding:10px 12px 12px;
    display:none;
    z-index:921;
}
.report-modal.show{ display:block; }
.report-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:6px 6px 8px;
    cursor:move;
}
.report-title{
    font-size:15px;
    font-weight:600;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    padding-right:10px;
}
.report-actions{ display:flex; gap:6px; align-items:center; }
.report-mini{
    padding:4px 10px;
    border-radius:8px;
    border:1px solid #5f6368;
    background:#303134;
    color:#e8eaed;
    font-size:12px;
    cursor:pointer;
}
.report-mini:hover{ background:#3c4043; }
.report-close{
    border:none;
    background:transparent;
    color:#f1f3f4;
    font-size:20px;
    cursor:pointer;
    line-height:1;
}
.report-modal iframe{
    width:100%;
    height:72vh;
    border:none;
    border-radius:12px;
    background:#202124;
}

/* ✅ 성적서 발행 중 닫기 확인 모달 */
.report-confirm-backdrop{z-index:930;}
.report-confirm-modal{
  position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);
  width:420px;max-width:92%;
  background:#2b2b2b;border:1px solid rgba(255,255,255,0.10);
  border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,0.75);
  padding:14px 16px 12px;
  display:none;
  z-index:931;
}
.report-confirm-modal.show{display:block;}
.report-confirm-title{font-size:15px;font-weight:800;margin:0 0 10px;color:#e8eaed;}
.report-confirm-msg{font-size:13px;line-height:1.55;margin:0 0 12px;color:#cdd3da;}
.report-confirm-actions{display:flex;gap:8px;justify-content:flex-end;}
.report-confirm-btn{
  padding:8px 12px;border-radius:10px;
  border:1px solid rgba(255,255,255,0.18);
  background:#303134;color:#e8eaed;cursor:pointer;font-size:12.5px;
}
.report-confirm-btn:hover{background:#3c4043;}
.report-confirm-btn.danger{border-color:rgba(232,93,93,0.55);background:rgba(232,93,93,0.20);}
.report-confirm-btn.danger:hover{background:rgba(232,93,93,0.28);}

/* ✅ 좌측 레일(72px) 공간 확보 */
        .dp-page{ padding-left:72px; box-sizing:border-box; }

        .fallback-notice{
            margin:10px 0 14px;
            padding:10px 12px;
            background:#2b2f36;
            border:1px solid #3a3f47;
            border-radius:12px;
            color:#ffd54f;
            font-size:13px;
        }
</style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {

        // 모달 드래그
        function makeDraggable(modal, handle) {
            if (!modal || !handle) return;

            handle.style.cursor = 'move';

            let isDown = false;
            let startX = 0, startY = 0;
            let origX = 0, origY = 0;

            handle.addEventListener('mousedown', function (e) {
                isDown = true;
                startX = e.clientX;
                startY = e.clientY;

                const rect = modal.getBoundingClientRect();
                origX = rect.left;
                origY = rect.top;

                modal.style.position   = 'fixed';
                modal.style.transform  = 'none';
                modal.style.transition = 'none';
                modal.style.left       = rect.left + 'px';
                modal.style.top        = rect.top  + 'px';

                function onMove(ev) {
                    if (!isDown) return;
                    const dx = ev.clientX - startX;
                    const dy = ev.clientY - startY;
                    modal.style.left = (origX + dx) + 'px';
                    modal.style.top  = (origY + dy) + 'px';
                }

                function onUp() {
                    isDown = false;
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                }

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);

                e.preventDefault();
            });
        }

        var toolBackdrop = document.getElementById('toolBackdrop');
        var toolModal    = document.getElementById('toolModal');
        var toolHeader   = document.getElementById('toolHeader');
        var toolCloseBtn = document.getElementById('toolCloseBtn');

        function openToolCavityModal() {
            if (!toolModal || !toolBackdrop) return;

            var frame = document.getElementById('toolFrame');
            if (frame) {
                var qs  = window.location.search || '';
                var url = 'shipinglist_tool_cavity.php' + qs;
                url += (qs ? '&' : '?') + 'ts=' + Date.now();
                frame.src = url;
            }

            toolModal.classList.add('show');
            toolBackdrop.classList.add('show');
        }

        function closeToolCavityModal() {
            if (!toolModal || !toolBackdrop) return;
            toolModal.classList.remove('show');
            toolBackdrop.classList.remove('show');
        }

        window.openToolCavityModal  = openToolCavityModal;
        window.closeToolCavityModal = closeToolCavityModal;

        if (toolBackdrop) toolBackdrop.addEventListener('click', closeToolCavityModal);
        if (toolCloseBtn) toolCloseBtn.addEventListener('click', closeToolCavityModal);

        makeDraggable(toolModal, toolHeader);

        var spBackdrop = document.getElementById('spBackdrop');
        var spModal    = document.getElementById('spModal');
        var spHeader   = document.getElementById('spHeader');
        var spCloseBtn = document.getElementById('spCloseBtn');
        var spTabSummary = document.getElementById('spTabSummary');
        var spTabDetail  = document.getElementById('spTabDetail');
        var spCurrentTab = 'detail';

        function buildSoPojangModalUrl(tabName) {
            var qs = window.location.search || '';
            var page = (tabName === 'summary') ? 'shipinglist_sopojang.php' : 'shipinglist_sopojang_detail.php';
            var url = page + qs;
            url += (qs ? '&' : '?') + 'ts=' + Date.now();
            if (!/(^|[?&])embed=1(&|$)/.test(url)) url += '&embed=1';
            return url;
        }

        function setSoPojangTabActive(tabName) {
            spCurrentTab = (tabName === 'summary') ? 'summary' : 'detail';
            var isSummary = (spCurrentTab === 'summary');
            var isDetail  = !isSummary;
            if (spTabSummary) {
                spTabSummary.classList.toggle('active', isSummary);
                spTabSummary.setAttribute('aria-selected', isSummary ? 'true' : 'false');
            }
            if (spTabDetail)  {
                spTabDetail.classList.toggle('active', isDetail);
                spTabDetail.setAttribute('aria-selected', isDetail ? 'true' : 'false');
            }
        }

        function loadSoPojangTab(tabName) {
            if (!spModal || !spBackdrop) return;
            setSoPojangTabActive(tabName);
            var frame = document.getElementById('spFrame');
            if (frame) frame.src = buildSoPojangModalUrl(spCurrentTab);
        }

        function openSoPojangModal(tabName) {
            if (!spModal || !spBackdrop) return;
            loadSoPojangTab(tabName || 'detail');
            spModal.classList.add('show');
            spBackdrop.classList.add('show');
            document.documentElement.classList.add('sp-modal-open');
            document.body.classList.add('sp-modal-open');
        }

        function openSoPojangDetailModal() { openSoPojangModal('detail'); }
        function openSoPojangSummaryModal() { openSoPojangModal('summary'); }

        function closeSoPojangDetailModal() {
            if (!spModal || !spBackdrop) return;
            spModal.classList.remove('show');
            spBackdrop.classList.remove('show');
            document.documentElement.classList.remove('sp-modal-open');
            document.body.classList.remove('sp-modal-open');

            var frame = document.getElementById('spFrame');
            if (frame) frame.src = 'about:blank';
        }

        window.openSoPojangModal = openSoPojangModal;
        window.openSoPojangDetailModal = openSoPojangDetailModal;
        window.openSoPojangSummaryModal = openSoPojangSummaryModal;
        window.closeSoPojangDetailModal = closeSoPojangDetailModal;

        if (spTabSummary) spTabSummary.addEventListener('click', function(){ loadSoPojangTab('summary'); });
        if (spTabDetail)  spTabDetail.addEventListener('click', function(){ loadSoPojangTab('detail'); });
        if (spBackdrop) spBackdrop.addEventListener('click', closeSoPojangDetailModal);
        if (spCloseBtn) spCloseBtn.addEventListener('click', closeSoPojangDetailModal);

        // makeDraggable(spModal, spHeader); // fullscreen 고정 (v12)

// ─────────────────────────────
// ✅ 성적서 제작 모달 (shipinglist_export_lotlist.php)
//  - 발행중(backdrop/X/ESC) 닫기 시 확인 모달
//  - (best effort) 취소: build_token 기반 취소 요청
// ─────────────────────────────
var reportBackdrop = document.getElementById('reportBackdrop');
var reportModal    = document.getElementById('reportModal');
var reportHeader   = document.getElementById('reportHeader');
var reportTitle    = document.getElementById('reportTitle');
var reportCloseBtn = document.getElementById('reportCloseBtn');
var reportPopBtn   = document.getElementById('reportPopBtn');

// 닫기 확인 모달
var reportConfirmBackdrop = document.getElementById('reportConfirmBackdrop');
var reportConfirmModal    = document.getElementById('reportConfirmModal');
var reportConfirmContinue = document.getElementById('reportConfirmContinue');
var reportConfirmCancel   = document.getElementById('reportConfirmCancel');

var reportBuildToken = null;
var reportRunning = false;
var reportCancelRequested = false;

function makeBuildToken(){
    return String(Date.now()) + '_' + Math.random().toString(16).slice(2);
}

function buildReportUrl(){
    var qs = window.location.search || '';
    // embed=1(있으면 그대로)
    var hasEmbed = /(^|[?&])embed=1(&|$)/.test(qs);
    var url = '<?= $APP_BASE ?>/shipinglist_export_lotlist.php' + qs;
    url += (qs ? '&' : '?') + 'ts=' + Date.now();
    if (!hasEmbed) url += '&embed=1';
    if (reportBuildToken) url += '&build_token=' + encodeURIComponent(reportBuildToken);
    return url;
}

function openReportConfirm(){
    if (!reportConfirmModal || !reportConfirmBackdrop) return;
    reportConfirmBackdrop.style.display = '';
    reportConfirmModal.classList.add('show');
    reportConfirmBackdrop.classList.add('show');
}
function closeReportConfirm(){
    if (!reportConfirmModal || !reportConfirmBackdrop) return;
    reportConfirmModal.classList.remove('show');
    reportConfirmBackdrop.classList.remove('show');
    reportConfirmBackdrop.style.display = 'none';
}

async function requestCancelReportBuild(){
    reportCancelRequested = true;
    closeReportConfirm();

    // 서버에 취소 요청(best effort)
    try {
        if (reportBuildToken) {
            var body = new URLSearchParams();
            body.set('action', 'build_cancel');
            body.set('build_token', reportBuildToken);
            await fetch('shipinglist_export_lotlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString(),
                credentials: 'same-origin'
            });
        }
    } catch (e) {
        // ignore
    }

    closeReportBuildModal();
}

function openReportBuildModal(){
    // 매번 새 토큰
    reportBuildToken = makeBuildToken();
    // ✅ 발행 시작 전(폼 화면)에는 닫기 확인을 띄우지 않음
    //    실제 발행 시작은 iframe에서 postMessage(status='started')로만 true로 전환
    reportRunning = false;
    reportCancelRequested = false;

    if (!reportModal || !reportBackdrop) {
        // fallback: 팝업(차단되면 새탭)
        var u = buildReportUrl();
        var w = window.open(u, 'report_build', 'width=1200,height=800,menubar=no,toolbar=no,location=no,status=no');
        if (!w) location.href = u;
        return;
    }

    var frame = document.getElementById('reportFrame');
    var url = buildReportUrl();
    if (reportTitle) reportTitle.textContent = '성적서 제작';
    if (frame) frame.src = url;

    reportModal.classList.add('show');
    reportBackdrop.classList.add('show');
}

function closeReportBuildModal(){
    if (!reportModal || !reportBackdrop) return;
    reportModal.classList.remove('show');
    reportBackdrop.classList.remove('show');
    closeReportConfirm();

    // 요청 끊기(브라우저에 따라 서버는 계속 처리될 수 있음)
    var frame = document.getElementById('reportFrame');
    if (frame) frame.src = 'about:blank';

    reportRunning = false;
}

function popReportBuildModal(){
    var u = buildReportUrl();
    var w = window.open(u, 'report_build', 'width=1200,height=800,menubar=no,toolbar=no,location=no,status=no');
    if (!w) location.href = u;
}

function attemptCloseReportBuildModal(){
    // 모달이 열려있지 않으면 아무 것도 하지 않음
    if (!reportModal || !reportModal.classList || !reportModal.classList.contains('show')) {
        closeReportConfirm();
        return;
    }
    // 발행중이면 확인 모달
    if (reportRunning && !reportCancelRequested) {
        openReportConfirm();
        return;
    }
    closeReportBuildModal();
}

window.openReportBuildModal  = openReportBuildModal;
window.closeReportBuildModal = closeReportBuildModal;

// iframe → parent 상태 통지
window.addEventListener('message', function(ev){
    var d = ev && ev.data;
    if (!d || typeof d !== 'object') return;
    if (d.type !== 'report_build') return;
    if (reportBuildToken && d.build_token && String(d.build_token) !== String(reportBuildToken)) return;

    var st = String(d.status || '');
    if (st === 'started') reportRunning = true;
    if (st === 'done' || st === 'canceled' || st === 'error') reportRunning = false;
});

if (reportBackdrop) reportBackdrop.addEventListener('click', attemptCloseReportBuildModal);
if (reportCloseBtn) reportCloseBtn.addEventListener('click', attemptCloseReportBuildModal);
if (reportPopBtn) reportPopBtn.addEventListener('click', popReportBuildModal);

if (reportConfirmBackdrop) reportConfirmBackdrop.addEventListener('click', function(){ closeReportConfirm(); });
if (reportConfirmContinue) reportConfirmContinue.addEventListener('click', function(){ closeReportConfirm(); });
if (reportConfirmCancel) reportConfirmCancel.addEventListener('click', function(){ requestCancelReportBuild(); });

makeDraggable(reportModal, reportHeader);

// ESC로 닫기(발행중이면 확인)
document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    // 확인 모달이 떠있으면 먼저 닫기
    if (reportConfirmModal && reportConfirmModal.classList && reportConfirmModal.classList.contains('show')) {
        closeReportConfirm();
        return;
    }
    // 성적서 모달이 열려있을 때만 닫기 시도
    if (reportModal && reportModal.classList && reportModal.classList.contains('show')) {
        attemptCloseReportBuildModal();
        return;
    }
    // 소포장 상세내역 모달 닫기
    if (spModal && spModal.classList && spModal.classList.contains('show')) {
        closeSoPojangDetailModal();
        return;
    }
    // Tool&Cavity 모달 닫기
    if (toolModal && toolModal.classList && toolModal.classList.contains('show')) {
        closeToolCavityModal();
    }
});


        // ─────────────────────────────
        // 품번명별 합계 카드: 스크롤 고정
        // ─────────────────────────────
        var sumCard  = document.getElementById('sumCard');
        var pageWrap = document.querySelector('.page-wrap');

        if (sumCard && pageWrap) {
            function updateSumCardPos() {
                var rect = pageWrap.getBoundingClientRect();
                var offsetX = -15;  // 오른쪽 여백
                var offsetY = 142;  // 화면 상단에서 떨어진 높이

                sumCard.style.position = 'fixed';
                sumCard.style.left = (rect.right + offsetX) + 'px';
                sumCard.style.top  = offsetY + 'px';
            }

            updateSumCardPos();
            window.addEventListener('scroll',  updateSumCardPos);

        // ✅ date input: 마우스휠로 값이 튀는 문제 방지
        document.querySelectorAll('input[type="date"]').forEach(function(inp){
            inp.addEventListener('wheel', function(e){
                e.preventDefault();
                this.blur();
            }, {passive:false});
        });

        // ─────────────────────────────
        // ✅ 데이터 클릭 → 팝업 필터 모달
        // ─────────────────────────────
        var pfBackdrop = document.getElementById('pfBackdrop');
        var pfModal    = document.getElementById('pfModal');
        var pfHeader   = document.getElementById('pfHeader');
        var pfTitle    = document.getElementById('pfTitle');
        var pfContent  = document.getElementById('pfContent');
        var pfCloseBtn = document.getElementById('pfCloseBtn');

        function openPopupFilter(col, val, label){
            if (!pfModal || !pfBackdrop || !pfContent) return;
            if (!val) return;

            pfTitle.textContent = (label ? (label + ' : ' + val) : ('필터 상세 : ' + val));
            pfContent.innerHTML = '<div class="pf-muted">로딩중...</div>';

            var url = window.location.pathname
                    + '?__popup=1'
                    + '&popup_col=' + encodeURIComponent(col)
                    + '&popup_val=' + encodeURIComponent(val)
                    + '&ts=' + Date.now();

            fetch(url, {credentials:'same-origin'})
                .then(function(res){ return res.text(); })
                .then(function(html){ pfContent.innerHTML = html; })
                .catch(function(err){
                    pfContent.innerHTML = '<div class="pf-empty">불러오기에 실패했습니다.</div>';
                    console.error(err);
                });

            pfModal.classList.add('show');
            pfBackdrop.classList.add('show');
        }

        function closePopupFilter(){
            if (!pfModal || !pfBackdrop) return;
            pfModal.classList.remove('show');
            pfBackdrop.classList.remove('show');
        }

        if (pfBackdrop) pfBackdrop.addEventListener('click', closePopupFilter);
        if (pfCloseBtn) pfCloseBtn.addEventListener('click', closePopupFilter);
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') closePopupFilter();
        });

        // 드래그 가능
        makeDraggable(pfModal, pfHeader);

        // 클릭 가능한 셀 바인딩
        document.querySelectorAll('.pf-cell').forEach(function(td){
            td.addEventListener('click', function(){
                openPopupFilter(td.dataset.pfCol, td.dataset.pfVal, td.dataset.pfLabel);
            });
        });
            window.addEventListener('resize', updateSumCardPos);
        }
    });
    </script>

<style id="jtmes-v28-jmp-card-control-sync">
body.jtmes-page .card-filter,
body.jtmes-page .card-table,
body.jtmes-page .summary-card,
body.jtmes-page .sum-box,
body.jtmes-page .sum-wrap{
  position: relative;
  border-radius: 18px !important;
  border: 1px solid rgba(28, 214, 106, .18) !important;
  background: linear-gradient(180deg, rgba(18,22,30,.86), rgba(12,16,22,.92)) !important;
  box-shadow:
    0 14px 32px rgba(0,0,0,.34),
    inset 0 1px 0 rgba(255,255,255,.03),
    0 0 0 1px rgba(3, 20, 10, .35) !important;
  overflow: visible;
}
body.jtmes-page .card-filter::before,
body.jtmes-page .card-table::before,
body.jtmes-page .summary-card::before,
body.jtmes-page .sum-box::before,
body.jtmes-page .sum-wrap::before{
  content: "";
  position: absolute;
  inset: 8px;
  border-radius: 13px;
  border: 1px solid rgba(255,255,255,.05);
  background: linear-gradient(180deg, rgba(8, 16, 19, .28), rgba(3, 9, 12, .16));
  pointer-events: none;
}
body.jtmes-page .card-filter > *,
body.jtmes-page .card-table > *,
body.jtmes-page .summary-card > *,
body.jtmes-page .sum-box > *,
body.jtmes-page .sum-wrap > * { position: relative; z-index: 1; }

body.jtmes-page .filters-grid .field,
body.jtmes-page .filter-grid .field,
body.jtmes-page .filter-row .field{
  background: rgba(0,0,0,.18) !important;
  border: 1px solid rgba(255,255,255,.04) !important;
  border-radius: 14px !important;
  padding: 8px !important;
}
body.jtmes-page .filters-grid input,
body.jtmes-page .filters-grid select,
body.jtmes-page .filter-grid input,
body.jtmes-page .filter-grid select,
body.jtmes-page .card-filter input,
body.jtmes-page .card-filter select{
  height: 38px !important;
  border-radius: 12px !important;
  border: 1px solid rgba(26, 213, 106, .28) !important;
  background: linear-gradient(180deg, rgba(5, 17, 19, .78), rgba(4, 8, 12, .9)) !important;
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.05),
    inset 0 0 0 1px rgba(0,0,0,.22),
    0 0 0 1px rgba(4, 22, 11, .26) !important;
  color: #e6f7ee !important;
  transition: box-shadow .15s ease, border-color .15s ease, background .15s ease !important;
}
body.jtmes-page .filters-grid input::placeholder,
body.jtmes-page .filter-grid input::placeholder,
body.jtmes-page .card-filter input::placeholder{ color: rgba(220,235,228,.48) !important; }
body.jtmes-page .filters-grid input:focus,
body.jtmes-page .filters-grid select:focus,
body.jtmes-page .filter-grid input:focus,
body.jtmes-page .filter-grid select:focus,
body.jtmes-page .card-filter input:focus,
body.jtmes-page .card-filter select:focus{
  border-color: rgba(29, 230, 113, .52) !important;
  box-shadow:
    0 0 0 2px rgba(11, 89, 47, .34),
    0 0 0 1px rgba(29, 230, 113, .20) inset,
    inset 0 1px 0 rgba(255,255,255,.06) !important;
}
/* native datalist/select popup cannot be fully themed like JMP custom dropdown without JS conversion */
body.jtmes-page .filters-grid select,
body.jtmes-page .filter-grid select,
body.jtmes-page .card-filter select{
  -webkit-appearance: none;
  appearance: none;
  background-image:
    linear-gradient(45deg, transparent 50%, rgba(180,255,210,.9) 50%),
    linear-gradient(135deg, rgba(180,255,210,.9) 50%, transparent 50%);
  background-position:
    calc(100% - 16px) calc(50% - 2px),
    calc(100% - 10px) calc(50% - 2px);
  background-size: 6px 6px, 6px 6px;
  background-repeat: no-repeat;
  padding-right: 30px !important;
}
body.jtmes-page .filters-grid input[list]{
  padding-right: 34px !important;
  background-image:
    radial-gradient(circle at center, rgba(180,255,210,.95) 0 1px, transparent 1.5px),
    radial-gradient(circle at center, rgba(180,255,210,.95) 0 1px, transparent 1.5px),
    radial-gradient(circle at center, rgba(180,255,210,.95) 0 1px, transparent 1.5px);
  background-size: 2px 2px, 2px 2px, 2px 2px;
  background-position: calc(100% - 16px) 50%, calc(100% - 12px) 50%, calc(100% - 8px) 50%;
  background-repeat: no-repeat;
}

body.jtmes-page .btn,
body.jtmes-page .action-btn,
body.jtmes-page .top-actions .btn,
body.jtmes-page .tool-btn,
body.jtmes-page .excel-btn{
  border-radius: 12px !important;
  border: 1px solid rgba(29, 230, 113, .38) !important;
  background: linear-gradient(180deg, rgba(9, 34, 24, .78), rgba(7, 23, 18, .86)) !important;
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.06),
    0 0 0 1px rgba(3, 20, 10, .24) !important;
  transform: none !important;
}
body.jtmes-page .btn:hover,
body.jtmes-page .action-btn:hover,
body.jtmes-page .top-actions .btn:hover,
body.jtmes-page .tool-btn:hover,
body.jtmes-page .excel-btn:hover{
  background: linear-gradient(180deg, rgba(12, 44, 30, .86), rgba(8, 28, 20, .92)) !important;
  border-color: rgba(52, 247, 131, .52) !important;
  box-shadow:
    0 0 0 2px rgba(10, 90, 47, .18),
    inset 0 1px 0 rgba(255,255,255,.08) !important;
  transform: none !important;
  translate: none !important;
}
body.jtmes-page .btn:active,
body.jtmes-page .action-btn:active,
body.jtmes-page .top-actions .btn:active,
body.jtmes-page .tool-btn:active,
body.jtmes-page .excel-btn:active{
  transform: none !important;
  translate: none !important;
}
</style>

<!-- v30: JMP Assist visual sync (safe/static CSS only; no JS post-processing) -->
<style id="jtmes-v30-jmp-card-sync">
/* Only result/data card gets dual-layer (JMP-like). Filter card stays single-layer. */
body.jtmes-page .card-filter{
  position:relative !important;
  border-radius:18px !important;
  border:1px solid rgba(26,205,108,.16) !important;
  background:linear-gradient(180deg, rgba(5,12,13,.78), rgba(3,8,10,.84)) !important;
  box-shadow:0 10px 26px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.03) !important;
}
body.jtmes-page .card-filter::before,
body.jtmes-page .card-filter::after{ content:none !important; display:none !important; }

body.jtmes-page .card-list{
  position:relative !important;
  border-radius:20px !important;
  border:1px solid rgba(26,205,108,.14) !important;
  background:linear-gradient(180deg, rgba(23,25,29,.80), rgba(12,15,18,.88)) !important;
  box-shadow:0 18px 42px rgba(0,0,0,.36), inset 0 1px 0 rgba(255,255,255,.02) !important;
}
body.jtmes-page .card-list::before{
  content:"";
  position:absolute;
  inset:10px;
  border-radius:14px;
  border:1px solid rgba(26,205,108,.14);
  background:linear-gradient(180deg, rgba(0,0,0,.14), rgba(0,0,0,.26));
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.018);
  pointer-events:none;
}
body.jtmes-page .card-list > *{ position:relative; z-index:1; }

body.jtmes-page .fixed-summary{
  border-radius:16px !important;
  border:1px solid rgba(26,205,108,.18) !important;
  background:linear-gradient(180deg, rgba(7,14,16,.76), rgba(4,8,10,.86)) !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.03), 0 8px 20px rgba(0,0,0,.22) !important;
}

body.jtmes-page .card-filter .filter-group label{
  color:rgba(238,255,244,.92) !important;
  text-shadow:0 1px 0 rgba(0,0,0,.45) !important;
}
body.jtmes-page .card-filter .filter-input,
body.jtmes-page .card-filter .filter-input-date,
body.jtmes-page .card-filter select.filter-input,
body.jtmes-page .card-filter input[type="text"],
body.jtmes-page .card-filter input[type="date"],
body.jtmes-page .card-filter input[list]{
  border-radius:12px !important;
  border:1px solid rgba(26,205,108,.18) !important;
  background:linear-gradient(180deg, rgba(2,6,8,.86), rgba(4,8,10,.84)) !important;
  color:#effff5 !important;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.02), inset 0 0 0 1px rgba(0,0,0,.18) !important;
}
body.jtmes-page .card-filter .filter-input::placeholder,
body.jtmes-page .card-filter input[type="text"]::placeholder{ color:rgba(234,255,244,.40) !important; }
body.jtmes-page .card-filter .filter-input:focus,
body.jtmes-page .card-filter .filter-input-date:focus,
body.jtmes-page .card-filter select.filter-input:focus,
body.jtmes-page .card-filter input[type="text"]:focus,
body.jtmes-page .card-filter input[type="date"]:focus,
body.jtmes-page .card-filter input[list]:focus{
  border-color:rgba(26,205,108,.42) !important;
  box-shadow:0 0 0 3px rgba(26,205,108,.10), inset 0 1px 0 rgba(255,255,255,.02) !important;
  outline:none !important;
}

body.jtmes-page .btn,
body.jtmes-page button,
body.jtmes-page input[type="button"],
body.jtmes-page input[type="submit"]{
  transition: background-color .14s ease, border-color .14s ease, box-shadow .14s ease, color .14s ease !important;
}
body.jtmes-page .card-filter .btn-search,
body.jtmes-page .card-list .top-bar .btn{
  border-radius:12px !important;
  border:1px solid rgba(26,205,108,.46) !important;
  background:linear-gradient(180deg, rgba(7,33,20,.44), rgba(5,17,12,.62)) !important;
  color:#ebfff2 !important;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.03), 0 4px 14px rgba(0,0,0,.20) !important;
}
body.jtmes-page .card-filter .btn-search:hover,
body.jtmes-page .card-list .top-bar .btn:hover{
  background:linear-gradient(180deg, rgba(9,40,24,.52), rgba(6,20,14,.70)) !important;
  border-color:rgba(34,222,119,.58) !important;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.04), 0 6px 18px rgba(0,0,0,.24) !important;
}
body.jtmes-page .card-filter .btn-search:active,
body.jtmes-page .card-list .top-bar .btn:active,
body.jtmes-page .card-filter .btn-search:hover,
body.jtmes-page .card-list .top-bar .btn:hover{
  transform:none !important;
  translate:none !important;
}

body.jtmes-page .card-list .table-wrap,
body.jtmes-page .card-list .table-scroll{
  background:rgba(4,9,10,.58) !important;
  border-radius:12px !important;
}
body.jtmes-page .card-list table{ background:rgba(0,0,0,.12) !important; }
body.jtmes-page .card-list thead th{
  background:rgba(32,35,39,.70) !important;
  border-bottom:1px solid rgba(255,255,255,.07) !important;
}
body.jtmes-page .card-list tbody td{ border-bottom-color:rgba(255,255,255,.05) !important; }

body.jtmes-page .popup-filter-backdrop,
body.jtmes-page .pf-backdrop,
body.jtmes-page .pf-mask{
  background:rgba(0,0,0,.20) !important;
  backdrop-filter: blur(2px);
}
body.jtmes-page .popup-filter-panel,
body.jtmes-page .pf-panel,
body.jtmes-page .pf-card{
  border-radius:16px !important;
  border:1px solid rgba(26,205,108,.30) !important;
  background:linear-gradient(180deg, rgba(8,15,13,.94), rgba(7,10,12,.95)) !important;
  color:#effff5 !important;
  box-shadow:0 16px 38px rgba(0,0,0,.42), 0 0 0 1px rgba(0,0,0,.22) inset, 0 0 0 1px rgba(26,205,108,.05) !important;
}
body.jtmes-page .popup-filter-head,
body.jtmes-page .pf-head{
  background:rgba(4,10,8,.34) !important;
  border-bottom:1px solid rgba(26,205,108,.14) !important;
}
body.jtmes-page .popup-filter-search input,
body.jtmes-page .pf-search{
  border-radius:12px !important;
  border:1px solid rgba(26,205,108,.24) !important;
  background:rgba(2,7,8,.86) !important;
  color:#effff5 !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.02) !important;
}
body.jtmes-page .popup-filter-search input::placeholder,
body.jtmes-page .pf-search::placeholder{ color:rgba(239,255,245,.40) !important; }
body.jtmes-page .popup-filter-clear,
body.jtmes-page .popup-filter-btn,
body.jtmes-page .pf-btn,
body.jtmes-page .pf-head button{
  border-radius:10px !important;
  border:1px solid rgba(26,205,108,.28) !important;
  background:linear-gradient(180deg, rgba(6,18,12,.58), rgba(4,12,10,.78)) !important;
  color:#effff5 !important;
  transform:none !important;
}
body.jtmes-page .popup-filter-list,
body.jtmes-page .pf-list{ background:transparent !important; }
body.jtmes-page .popup-filter-item,
body.jtmes-page .pf-item,
body.jtmes-page .pf-row{
  border-radius:10px !important;
  border:1px solid rgba(255,255,255,.03) !important;
  background:rgba(255,255,255,.01) !important;
  color:#ecfff3 !important;
}
body.jtmes-page .popup-filter-item:hover,
body.jtmes-page .pf-item:hover,
body.jtmes-page .pf-row:hover{
  background:rgba(26,205,108,.07) !important;
  border-color:rgba(26,205,108,.18) !important;
}
body.jtmes-page .popup-filter-item.selected,
body.jtmes-page .pf-item.selected,
body.jtmes-page .pf-item.is-on,
body.jtmes-page .pf-row.is-on{
  background:linear-gradient(180deg, rgba(8,48,26,.46), rgba(5,23,15,.66)) !important;
  border-color:rgba(26,205,108,.36) !important;
  box-shadow: inset 0 0 0 1px rgba(26,205,108,.08) !important;
}

body.jtmes-page .card-filter *::-webkit-scrollbar,
body.jtmes-page .card-list *::-webkit-scrollbar,
body.jtmes-page .popup-filter-panel *::-webkit-scrollbar,
body.jtmes-page .pf-panel *::-webkit-scrollbar{ width:10px; height:10px; }
body.jtmes-page .card-filter *::-webkit-scrollbar-track,
body.jtmes-page .card-list *::-webkit-scrollbar-track,
body.jtmes-page .popup-filter-panel *::-webkit-scrollbar-track,
body.jtmes-page .pf-panel *::-webkit-scrollbar-track{ background:rgba(255,255,255,.06); border-radius:999px; }
body.jtmes-page .card-filter *::-webkit-scrollbar-thumb,
body.jtmes-page .card-list *::-webkit-scrollbar-thumb,
body.jtmes-page .popup-filter-panel *::-webkit-scrollbar-thumb,
body.jtmes-page .pf-panel *::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.28); border-radius:999px; }
body.jtmes-page .card-filter *::-webkit-scrollbar-thumb:hover,
body.jtmes-page .card-list *::-webkit-scrollbar-thumb:hover,
body.jtmes-page .popup-filter-panel *::-webkit-scrollbar-thumb:hover,
body.jtmes-page .pf-panel *::-webkit-scrollbar-thumb:hover{ background:rgba(255,255,255,.40); }
</style>


<style id="ipqc-ui-shell-sync-safe-v34">
/* v34: IPQC-like UI shell sync (CSS only / no query logic touched) */
.card-filter{ 
  position:relative;
  background: linear-gradient(90deg, rgba(5,18,12,.72), rgba(3,8,15,.82) 45%, rgba(5,18,12,.72)) !important;
  border: 1px solid rgba(0,255,140,.12) !important;
  border-radius: 18px !important;
  box-shadow: inset 0 0 0 1px rgba(0,255,140,.05), 0 10px 22px rgba(0,0,0,.28) !important;
  backdrop-filter: blur(6px);
}
.card-filter::before{ display:none !important; content:none !important; }
.card-filter .field label,
.card-filter .filter-item label,
.card-filter .filter-group label{
  color:#e8f6ec !important;
  font-weight:700 !important;
  text-shadow:0 1px 0 rgba(0,0,0,.35);
}
.card-filter input[type="text"],
.card-filter input[type="date"],
.card-filter select{
  background: linear-gradient(180deg, rgba(2,5,8,.92), rgba(4,10,8,.90)) !important;
  color:#eaf5ee !important;
  border:1px solid rgba(0,255,145,.22) !important;
  border-radius: 12px !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.03), inset 0 0 0 1px rgba(0,0,0,.35) !important;
}
.card-filter input::placeholder{ color: rgba(220,240,228,.45) !important; }
.card-filter input[type="text"]:focus,
.card-filter input[type="date"]:focus,
.card-filter select:focus{
  outline:none !important;
  border-color: rgba(0,255,145,.52) !important;
  box-shadow: 0 0 0 2px rgba(0,255,145,.10), inset 0 1px 0 rgba(255,255,255,.03) !important;
}
.card-filter input[type="date"]::-webkit-calendar-picker-indicator{ filter: invert(89%) sepia(7%) saturate(209%) hue-rotate(82deg) brightness(98%) contrast(88%); opacity:.85; }
.card-filter .btn-search,
.card-filter button[type="submit"]{
  background: linear-gradient(180deg, rgba(8,92,44,.78), rgba(6,58,32,.88)) !important;
  color:#ecfff4 !important;
  border:1px solid rgba(58,255,154,.35) !important;
  border-radius: 12px !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.08), 0 2px 8px rgba(0,0,0,.22) !important;
  transform:none !important;
  transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease, opacity .15s ease !important;
}
.card-filter .btn-search:hover,
.card-filter button[type="submit"]:hover{
  transform:none !important;
  background: linear-gradient(180deg, rgba(10,109,53,.86), rgba(7,70,37,.92)) !important;
  border-color: rgba(74,255,170,.46) !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 4px 12px rgba(0,0,0,.24) !important;
}

/* data card only: double-shell like IPQC */
.card-table{
  position:relative !important;
  background: linear-gradient(180deg, rgba(19,20,25,.76), rgba(17,18,22,.80)) !important;
  border: 1px solid rgba(255,255,255,.05) !important;
  border-radius: 20px !important;
  box-shadow: 0 12px 28px rgba(0,0,0,.34), inset 0 1px 0 rgba(255,255,255,.03) !important;
  overflow: visible !important;
}
.card-table::before{
  content:"";
  position:absolute;
  inset: 10px;
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(4,10,12,.92), rgba(3,7,10,.95));
  border:1px solid rgba(0,255,145,.10);
  box-shadow: inset 0 1px 0 rgba(255,255,255,.03), inset 0 -1px 0 rgba(0,0,0,.28);
  pointer-events:none;
  z-index:0;
}
.card-table > *{ position:relative; z-index:1; }
.card-table .table-actions,
.card-table .actions,
.card-table .toolbar{
  background: transparent !important;
}
.card-table .btn,
.card-table .btn-excel,
.card-table .btn-report,
.card-table a.btn,
.card-table button{
  background: linear-gradient(180deg, rgba(8,80,42,.52), rgba(5,40,24,.68)) !important;
  color:#eafbef !important;
  border:1px solid rgba(44,235,138,.40) !important;
  border-radius: 14px !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.07) !important;
  transform:none !important;
  transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease, opacity .15s ease !important;
}
.card-table .btn:hover,
.card-table .btn-excel:hover,
.card-table .btn-report:hover,
.card-table a.btn:hover,
.card-table button:hover{
  transform:none !important;
  background: linear-gradient(180deg, rgba(10,98,50,.65), rgba(7,56,31,.78)) !important;
  border-color: rgba(75,255,170,.48) !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.10), 0 3px 8px rgba(0,0,0,.20) !important;
}

.card-table .table-wrap,
.card-table .table-scroll,
.card-table .qa-table-wrap,
.card-table .grid-wrap{
  background: transparent !important;
}
.card-table table{
  background: rgba(0,0,0,.30) !important;
  border-radius: 12px !important;
  overflow: hidden;
}
.card-table thead th{
  background: rgba(10,12,16,.94) !important;
  color: #f2f7f4 !important;
  border-bottom: 1px solid rgba(255,255,255,.08) !important;
}
.card-table tbody td{
  border-bottom: 1px solid rgba(255,255,255,.05) !important;
}
.card-table tbody tr:hover td,
.card-table tbody tr:hover th{
  background: rgba(66,126,204,.22) !important; /* IPQC-ish blue hover */
}
.card-table tbody tr.selected td,
.card-table tbody tr.table-active td{
  background: rgba(74,142,230,.30) !important;
}

/* Native datalist popup cannot be fully themed; keep input shell consistent */
input[list]{
  caret-color:#eafbef;
}
</style>


<!-- v36: IPQC-like button hover / color sync (CSS-only, final override) -->
<style id="v36-ipqc-button-hover-sync">
/* action/search buttons: stronger IPQC-like green outline + hover (no vertical movement) */
.card-table .btn,
.card-list .btn,
.table-actions .btn,
.top-actions .btn,
.search-actions .btn,
button.btn,
a.btn,
button[type="submit"].btn,
input[type="submit"].btn {
  background: linear-gradient(180deg, rgba(10, 24, 15, 0.90) 0%, rgba(7, 16, 11, 0.90) 100%) !important;
  color: #e8fff0 !important;
  border: 1px solid rgba(24, 219, 120, 0.52) !important;
  border-radius: 13px !important;
  box-shadow:
    inset 0 1px 0 rgba(190,255,220,0.06),
    0 0 0 1px rgba(0,0,0,0.18) !important;
  transition:
    background .12s ease,
    border-color .12s ease,
    box-shadow .12s ease,
    color .12s ease !important;
  transform: none !important;
  text-decoration: none !important;
}

.card-table .btn:hover,
.card-list .btn:hover,
.table-actions .btn:hover,
.top-actions .btn:hover,
.search-actions .btn:hover,
button.btn:hover,
a.btn:hover,
button[type="submit"].btn:hover,
input[type="submit"].btn:hover {
  background: linear-gradient(180deg, rgba(17, 54, 33, 0.96) 0%, rgba(10, 31, 20, 0.96) 100%) !important;
  color: #ffffff !important;
  border-color: rgba(37, 255, 143, 0.95) !important;
  box-shadow:
    inset 0 1px 0 rgba(220,255,235,0.12),
    0 0 0 1px rgba(18,255,124,0.20),
    0 0 14px rgba(18,255,124,0.12) !important;
  transform: none !important;
}

.card-table .btn:focus,
.card-list .btn:focus,
button.btn:focus,
a.btn:focus {
  outline: none !important;
  border-color: rgba(37, 255, 143, 0.95) !important;
  box-shadow:
    inset 0 1px 0 rgba(220,255,235,0.10),
    0 0 0 2px rgba(18,255,124,0.20),
    0 0 14px rgba(18,255,124,0.10) !important;
}

.card-table .btn:active,
.card-list .btn:active,
button.btn:active,
a.btn:active {
  background: linear-gradient(180deg, rgba(12, 40, 24, 0.98) 0%, rgba(7, 23, 14, 0.98) 100%) !important;
  border-color: rgba(37, 255, 143, 0.88) !important;
  box-shadow:
    inset 0 1px 3px rgba(0,0,0,0.28),
    0 0 0 1px rgba(18,255,124,0.16) !important;
  transform: none !important;
}

/* table row hover color: sync closer to IPQC blue hover tone */
.card-table table tbody tr:hover > td,
.table-wrap table tbody tr:hover > td,
.data-table tbody tr:hover > td,
table tbody tr:hover > td {
  background: rgba(61, 122, 190, 0.28) !important;
}
</style>

</head>
<body class="jtmes-page">
<?php if (empty($EMBED)):
// 좌측 메뉴 + 상단 유저바 (페이지마다 자동 적용)
echo dp_sidebar_render('shipinglist');
echo dp_render_userbar([
'admin_badge_mode' => 'modal',       // ✅ 관리자 버튼 = 모달
    'admin_iframe_src' => 'admin_settings', // 모달 안에서 열 페이지(필요시 변경)
    'logout_action'    => 'logout'
]);
endif; ?>
<?php if (!empty($EMBED)): ?>
<style>
  /* ✅ iframe(embed)에서는 배경/좌측패딩 제거해서 쉘 배경이 보이게 */
  body{background: transparent !important; padding:0 !important;}
  .dp-page{padding-left:0 !important;}


:root{
  --jmp-accent:#7ea2da;
  --jmp-accent-2:#5c86c8;
  --jmp-accent-3:#3b5f96;
  --jmp-border:#2d4465;
  --jmp-surface-1:linear-gradient(180deg, rgba(22,30,43,.98), rgba(13,18,28,.98));
  --jmp-surface-2:linear-gradient(180deg, rgba(18,25,36,.98), rgba(10,14,22,.98));
}

.card-filter{
  background: linear-gradient(180deg, rgba(10,40,18,.55), rgba(6,22,12,.72));
  border-color: rgba(42,120,68,.35);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.02),
    0 10px 28px rgba(0,0,0,.24),
    0 0 0 1px rgba(42,120,68,.10);
}

.card-filter .filter-group label{
  color:#d8f0df;
  font-weight:700;
  letter-spacing:.02em;
}

.card-filter .filter-input,
.card-filter .filter-input-date,
.card-filter select{
  height: 36px;
  min-height: 36px;
  border-radius: 10px;
  border: 1px solid rgba(48,145,86,.32);
  background: linear-gradient(180deg, rgba(6,20,11,.94) 0%, rgba(4,15,8,.97) 100%);
  color:#eef8f1;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.02), 0 0 0 1px rgba(0,0,0,.12);
  transition: border-color .14s ease, box-shadow .14s ease, background .14s ease;
}

.card-filter .filter-input::placeholder,
.card-filter .filter-input-date::placeholder{
  color: rgba(170,199,176,.62);
}

.card-filter .filter-input:focus,
.card-filter .filter-input-date:focus,
.card-filter select:focus{
  outline: none;
  border-color: #39b46e;
  box-shadow:
    0 0 0 2px rgba(57,180,110,.12),
    inset 0 1px 0 rgba(255,255,255,.03);
  background: linear-gradient(180deg, rgba(8,24,14,.96) 0%, rgba(5,17,10,.98) 100%);
}

.card-filter select{
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  padding-right: 34px;
  background-image:
    linear-gradient(45deg, transparent 50%, #cfead7 50%),
    linear-gradient(135deg, #cfead7 50%, transparent 50%);
  background-position:
    calc(100% - 16px) 15px,
    calc(100% - 10px) 15px;
  background-size: 6px 6px, 6px 6px;
  background-repeat: no-repeat;
}

.card-filter .filter-input-date::-webkit-calendar-picker-indicator,
.card-filter input[type="date"]::-webkit-calendar-picker-indicator{
  filter: invert(91%) sepia(6%) saturate(793%) hue-rotate(80deg) brightness(96%) contrast(89%);
  opacity:.8;
  cursor:pointer;
}

.card-filter .date-quick-btn,
.card-filter .quick-btn{
  height: 28px;
  border-radius: 9px;
  border: 1px solid rgba(48,145,86,.26);
  background: linear-gradient(180deg, #14251b, #0d1712);
  color:#d9efde;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.015);
}

.card-filter .date-quick-btn:hover,
.card-filter .quick-btn:hover{
  border-color:#2f8b59;
  color:#eef8f1;
  background: linear-gradient(180deg, #173825, #0d1f14);
}

.card-filter .date-quick-btn.active,
.card-filter .quick-btn.active{
  border-color:#49c07a;
  color:#eef8f1;
  background: linear-gradient(180deg, #1f5c3b, #143726);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.08),
    0 0 0 1px rgba(73,192,122,.14);
}

.btn-search,
.toolbar-actions .btn,
.action-row .btn,
.card-filter + .action-row .btn,
button.btn{
  border-radius: 12px;
  border: 1px solid #2f8b59;
  color:#d9efde;
  background: linear-gradient(180deg, #142f1f 0%, #0d2015 100%);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.05),
    0 6px 16px rgba(0,0,0,.22);
  transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease, background .12s ease;
}

.btn-search{
  border-color:#35aa68;
  background: linear-gradient(180deg, #1b9a52 0%, #158341 52%, #106332 100%);
  color:#eef8f1;
  font-weight:800;
  text-shadow: 0 1px 0 rgba(0,0,0,.35);
}

button.btn:hover,
.btn-search:hover{
  transform: translateY(-1px);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.08),
    0 10px 22px rgba(0,0,0,.28);
}

button.btn:hover{
  border-color:#2f8b59;
  background: linear-gradient(180deg, #173825 0%, #0d1f14 100%);
}

.btn-search:hover{
  border-color:#49c07a;
  background: linear-gradient(180deg, #22ae62 0%, #198d49 52%, #126e39 100%);
}

button.btn:active,
.btn-search:active{
  transform: translateY(0);
  box-shadow: inset 0 2px 5px rgba(0,0,0,.25);
}

/* keep existing widths/layouts; only visual sync */


body.jtmes-page .search-panel,
body.jtmes-page .search-toolbar,
body.jtmes-page .search-row,
body.jtmes-page .filter-card,
body.jtmes-page .toolbar,
body.jtmes-page .toolbar-row,
body.jtmes-page .action-bar,
body.jtmes-page .summary-card,
body.jtmes-page .right-summary {
  background: rgba(7,16,9,.72) !important;
  border-color: rgba(255,255,255,.10) !important;
  box-shadow: 0 10px 28px rgba(0,0,0,.28) !important;
  backdrop-filter: blur(8px) !important;
}

body.jtmes-page input[type="text"],
body.jtmes-page input[type="search"],
body.jtmes-page input[type="date"],
body.jtmes-page input[type="number"],
body.jtmes-page select,
body.jtmes-page textarea {
  background: rgba(8,14,11,.90) !important;
  border: 1px solid rgba(29,185,84,.28) !important;
  color: #eaf7ef !important;
  box-shadow: inset 0 0 0 1px rgba(0,0,0,.25) !important;
}

body.jtmes-page input[type="text"]:focus,
body.jtmes-page input[type="search"]:focus,
body.jtmes-page input[type="date"]:focus,
body.jtmes-page input[type="number"]:focus,
body.jtmes-page select:focus,
body.jtmes-page textarea:focus {
  border-color: rgba(29,185,84,.55) !important;
  box-shadow: 0 0 0 2px rgba(29,185,84,.18), inset 0 0 0 1px rgba(0,0,0,.20) !important;
}

body.jtmes-page .btn,
body.jtmes-page .btn-outline,
body.jtmes-page .btn-primary,
body.jtmes-page .btn-secondary,
body.jtmes-page button,
body.jtmes-page input[type="button"],
body.jtmes-page input[type="submit"] {
  background: rgba(29,185,84,.15) !important;
  border-color: rgba(29,185,84,.55) !important;
  color: #d8ffe8 !important;
  box-shadow: none !important;
  transform: none !important;
  transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease !important;
}

body.jtmes-page .btn:hover,
body.jtmes-page .btn-outline:hover,
body.jtmes-page .btn-primary:hover,
body.jtmes-page .btn-secondary:hover,
body.jtmes-page button:hover,
body.jtmes-page input[type="button"]:hover,
body.jtmes-page input[type="submit"]:hover {
  background: rgba(29,185,84,.26) !important;
  border-color: rgba(29,185,84,.75) !important;
  color: #f2fff7 !important;
  box-shadow: none !important;
  transform: none !important;
}

body.jtmes-page .btn:active,
body.jtmes-page .btn-outline:active,
body.jtmes-page .btn-primary:active,
body.jtmes-page .btn-secondary:active,
body.jtmes-page button:active,
body.jtmes-page input[type="button"]:active,
body.jtmes-page input[type="submit"]:active {
  background: rgba(29,185,84,.20) !important;
  transform: none !important;
}


</style>
<?php endif; ?>
<div class="dp-page">

<div class="page-wrap">
    <div class="page-title">QA 출하내역</div>
    <?php if (!empty($didFallback)): ?>
        <div class="fallback-notice">날짜 조건에서 결과가 없어 <b>전체기간</b>으로 다시 검색했습니다.</div>
    <?php endif; ?>


    <!-- 필터 카드 -->
    <div class="card-filter">
        <form method="get">
        <?php if (!empty($EMBED)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <div class="filter-row">
                <div class="filter-group">
                    <label>조회기간 (출하일자)</label>
                    <div style="display:flex; gap:6px; align-items:center;">
						<input type="date" name="from_date"
							   class="filter-input-date"
							   value="<?= h($fromDate) ?>"
							   min="2000-01-01" max="9999-12-31">
						<span style="font-size:11px; color:#aac7b0;">~</span>
						<input type="date" name="to_date"
							   class="filter-input-date"
							   value="<?= h($toDate) ?>"
							   min="2000-01-01" max="9999-12-31">
                    </div>
                </div>

                <div class="filter-group">
                    <label>납품처</label>
                    <input type="text" name="ship_to"
                           class="filter-input"
                           list="ship_to_list"
                           value="<?= h($filterShipTo) ?>">
                </div>

                <div class="filter-group">
                    <label>포장바코드</label>
                    <input type="text" name="pack_bc"
                           class="filter-input"
                           list="pack_bc_list"
                           value="<?= h($filterPackBc) ?>">
                </div>

                <div class="filter-group">
                    <label>소포장 NO</label>
                    <input type="text" name="small_no"
                           class="filter-input"
                           list="small_no_list"
                           value="<?= h($filterSmallNo) ?>">
                </div>

                <div class="filter-group">
                    <label>Tray NO</label>
                    <input type="text" name="tray_no"
                           class="filter-input"
                           list="tray_no_list"
                           value="<?= h($filterTrayNo) ?>">
                </div>

                <div class="filter-group">
                    <label>품번명</label>
                    <input type="text" name="part_name"
                           class="filter-input"
                           list="part_name_list"
                           value="<?= h($filterPname) ?>">
                </div>

                <div class="filter-group" style="margin-left:auto;">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn-search">조회</button>
                </div>
            </div>

            <input type="hidden" name="page" value="1">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
        </form>

        <datalist id="ship_to_list">
            <?php foreach ($listShipTo as $v): ?>
                <?php if ($v === null || $v === '') continue; ?>
                <option value="<?= h($v) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <datalist id="pack_bc_list">
            <?php foreach ($listPackBc as $v): ?>
                <?php if ($v === null || $v === '') continue; ?>
                <option value="<?= h($v) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <datalist id="small_no_list">
            <?php foreach ($listSmallNo as $v): ?>
                <?php if ($v === null || $v === '') continue; ?>
                <option value="<?= h($v) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <datalist id="tray_no_list">
            <?php foreach ($listTrayNo as $v): ?>
                <?php if ($v === null || $v === '') continue; ?>
                <option value="<?= h($v) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <datalist id="part_name_list">
            <?php foreach ($listPname as $v): ?>
                <?php if ($v === null || $v === '') continue; ?>
                <option value="<?= h($v) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>

    <!-- 목록 카드 -->
    <div class="card-list">
        <div class="top-bar">
            <div class="text-muted" style="font-size:12px;"></div>
            <div>
                <!-- 성적서(Shipping Lot List) 버튼 -->
                <button type="button" class="btn"
                        onclick="openReportBuildModal();">
                    성적서 제작
                </button>

                <!-- 소포장 상세내역 모달 -->
                <button type="button" class="btn"
                        onclick="openSoPojangDetailModal();">
                    소포장상세내역
                </button>

                <!-- 기존 Tool & Cavity 모달 -->
                <button type="button" class="btn"
                        onclick="openToolCavityModal();">
                    Tool&Cavity별 수량
                </button>

                <button type="button" class="btn"
                        onclick="downloadExcel('all');">
                    전체EXCEL
                </button>
                <button type="button" class="btn"
                        onclick="downloadExcel('page');">
                    페이지EXCEL
                </button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>창고</th>
                    <th>포장일자</th>
                    <th>생산일자</th>
                    <th>어닐링일자</th>
                    <th>설비</th>
                    <th>CAVITY</th>
                    <th>품번코드</th>
                    <th>차수</th>
                    <th>고객사 품번</th>
                    <th>품번명</th>
                    <th>모델</th>
                    <th>프로젝트</th>
                    <th>소포장 NO</th>
                    <th>Tray NO</th>
                    <th>납품처</th>
                    <th>출고수량</th>
                    <th>출하일자</th>
                    <th>고객 LOTID</th>
                    <th>포장바코드</th>
                    <th>포장번호</th>
                    <th>AVI</th>
                    <th>RETURNDATE</th>
                    <th>RMA</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="23" class="text-muted" style="text-align:center; padding:12px 0;">
                            데이터가 없습니다.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= col($r,'warehouse') ?></td>
                            <td class="pf-cell" data-pf-col="pack_date" data-pf-label="포장일자" data-pf-val="<?= col($r,'pack_date') ?>"><?= col($r,'pack_date') ?></td>
                            <td class="pf-cell" data-pf-col="prod_date" data-pf-label="생산일자" data-pf-val="<?= col($r,'prod_date') ?>"><?= col($r,'prod_date') ?></td>
                            <td class="pf-cell" data-pf-col="ann_date" data-pf-label="어닐링일자" data-pf-val="<?= col($r,'ann_date') ?>"><?= col($r,'ann_date') ?></td>
                            <td><?= col($r,'facility') ?></td>
                            <td><?= col($r,'cavity') ?></td>
                            <td><?= col($r,'part_code') ?></td>
                            <td><?= col($r,'revision') ?></td>
                            <td><?= col($r,'customer_part_no') ?></td>
                            <td><?= col($r,'part_name') ?></td>
                            <td><?= col($r,'model') ?></td>
                            <td><?= col($r,'project') ?></td>
                            <td><?= col($r,'small_pack_no') ?></td>
                            <td><?= col($r,'tray_no') ?></td>
                            <td><?= col($r,'ship_to') ?></td>
                            <td class="num-right"><?= col($r,'qty') ?></td>
                            <td><?= col($r,'ship_datetime') ?></td>
                            <td><?= col($r,'customer_lot_id') ?></td>
                            <td><?= col($r,'pack_barcode') ?></td>
                            <td class="pf-cell" data-pf-col="pack_no" data-pf-label="포장번호" data-pf-val="<?= col($r,'pack_no') ?>"><?= col($r,'pack_no') ?></td>
                            <td><?= col($r,'avi') ?></td>

                            <?php
                                $retDisp = ($r['last_return_datetime'] ?? '') ?: (($r['return_date'] ?? '') ?: ($r['return_datetime'] ?? ''));
                                if (is_string($retDisp) && substr($retDisp, 0, 10) === '0000-00-00') $retDisp = '';
                            ?>
                            <td><?= h($retDisp) ?></td>
                            <td><?= col($r,'rma_visit_cnt') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pager-bar">
            <div class="pager-left">
                <button class="page-btn" <?= $page <= 1 ? 'disabled' : '' ?>
                        onclick="location.href='<?= pageLink(1, $perPage) ?>'">&laquo;</button>
                <button class="page-btn" <?= $page <= 1 ? 'disabled' : '' ?>
                        onclick="location.href='<?= pageLink(max(1,$page-1), $perPage) ?>'">&lsaquo;</button>
            </div>
            <div class="pager-center">
                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                    <a class="page-num <?= $p === $page ? 'current' : '' ?>"
                       href="<?= pageLink($p, $perPage) ?>"><?= $p ?></a>
                <?php endfor; ?>
                <span class="text-muted">
                    <?= $startItem ?> - <?= $endItem ?> / <?= $total ?> items
                </span>
            </div>
            <div class="pager-right">
                <span class="text-muted">items per page</span>
                <select onchange="location.href='<?= build_query(['page'=>1]) ?>&per_page='+this.value;">
                    <?php foreach ($allowed as $opt): ?>
                        <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>>
                            <?= $opt ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- 품번명별 출고수량 합계 카드 (heavy 모드일 때만) -->
    <?php if (!empty($sumRows)): ?>
    <div class="sum-card" id="sumCard">
        <div class="sum-title">품번명별 출고수량 합계</div>
        <div class="sum-caption">
            현재 검색조건 기준
            <?php if (!$enableHeavy && $diffDays !== null): ?>
                (※ 넓은 기간에서는 성능을 위해 생략)
            <?php endif; ?>
        </div>
        <?php foreach ($sumRows as $sr): ?>
            <div class="sum-row">
                <div class="sum-row-name"><?= h($sr['part_name']) ?></div>
                <div class="sum-row-qty"><?= number_format((int)$sr['sum_qty']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Tool & Cavity별 출하 수량 모달 -->
<div class="modal-backdrop" id="toolBackdrop"></div>
<div class="tool-modal" id="toolModal">
    <div class="tool-header" id="toolHeader">
        <div class="tool-title">Tool &amp; Cavity별 출하 수량</div>
        <button type="button" class="tool-close" id="toolCloseBtn">&times;</button>
    </div>
    <iframe id="toolFrame" src="about:blank"></iframe>
</div>

<!-- 소포장 상세내역 모달 -->
<div class="modal-backdrop sp-backdrop" id="spBackdrop"></div>
<div class="sp-modal" id="spModal">
    <div class="sp-header" id="spHeader">
        <div class="sp-tabs" role="tablist" aria-label="소포장 모달 탭">
            <button type="button" class="sp-tab" id="spTabSummary" role="tab" aria-selected="false">소포장내역</button>
            <button type="button" class="sp-tab active" id="spTabDetail" role="tab" aria-selected="true">소포장 상세내역</button>
        </div>
        <button type="button" class="sp-close" id="spCloseBtn">&times;</button>
    </div>
    <iframe id="spFrame" src="about:blank"></iframe>
</div>

<!-- 성적서 제작 모달 -->
<div class="modal-backdrop report-backdrop" id="reportBackdrop"></div>
<div class="report-modal" id="reportModal">
    <div class="report-header" id="reportHeader">
        <div class="report-title" id="reportTitle">성적서 제작</div>
        <div class="report-actions">
            <button type="button" class="report-close" id="reportCloseBtn">&times;</button>
        </div>
    </div>
    <iframe id="reportFrame" src="about:blank"></iframe>
</div>





<!-- 성적서 발행중 닫기 확인 모달 -->
<div class="modal-backdrop report-backdrop report-confirm-backdrop" id="reportConfirmBackdrop" style="display:none;"></div>
<div class="report-confirm-modal" id="reportConfirmModal" aria-hidden="true">
  <div class="report-confirm-title">성적서 발행을 취소하시겠습니까?</div>
  <div class="report-confirm-msg">발행 중 창을 닫으면 진행 상황을 볼 수 없습니다.<br>
    <span style="color:#9aa0a6;">(취소를 누르면 서버에 취소 요청을 보냅니다.)</span>
  </div>
  <div class="report-confirm-actions">
    <button type="button" class="report-confirm-btn" id="reportConfirmContinue">계속 발행</button>
    <button type="button" class="report-confirm-btn danger" id="reportConfirmCancel">발행 취소</button>
  </div>
</div>

<!-- ✅ 클릭 팝업 필터 모달 -->
<div class="modal-backdrop" id="pfBackdrop" style="z-index:910;"></div>
<div class="pf-modal" id="pfModal">
    <div class="pf-header" id="pfHeader">
        <div class="pf-title" id="pfTitle">필터 상세</div>
        <button type="button" class="pf-close" id="pfCloseBtn">&times;</button>
    </div>
    <div class="pf-body" id="pfContent"></div>
</div>



<!-- ✅ EXCEL 다운로드(전체/현재페이지) -->
<script>
function downloadExcel(mode) {
  try {
    const params = new URLSearchParams(window.location.search || '');
    params.set('src', 'shipinglist');
    const base = (mode === 'all') ? 'lib/excel_export_all.php' : 'lib/excel_export_page.php';
    const qs = params.toString();
    window.location.href = base + (qs ? ('?' + qs) : '');
  } catch (e) {
    alert('EXCEL 다운로드 중 오류가 발생했습니다.');
  }
}
</script>

<?php if (empty($EMBED)): ?>
<!-- ✅ 매트릭스 배경 외부 연결 (config/matrix_bg.php에서 설정) -->
<?php
$__mb = @include JTMES_ROOT . '/config/matrix_bg.php';
if (!is_array($__mb)) {
  $__mb = ['enabled'=>true,'text'=>'01','speed'=>1.15,'size'=>16,'zIndex'=>0,'scanlines'=>true,'vignette'=>true];
}
?>
<script>
  window.MATRIX_BG = <?php echo json_encode($__mb, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/matrix-bg.js"></script>
<?php endif; ?>

</div>

<script>
/* v35: custom dark dropdown for input[list] filters (IPQC-like UI, no query logic changes) */
(function(){
  if (window.__jtmesIpqcSuggestV35Init) return;
  window.__jtmesIpqcSuggestV35Init = true;

  function injectStyle(){
    if (document.getElementById('jtmes-ipqc-suggest-v35-style')) return;
    var st = document.createElement('style');
    st.id = 'jtmes-ipqc-suggest-v35-style';
    st.textContent = [
      '.jtmes-suggest-panel{position:fixed;z-index:999999;display:none;min-width:180px;max-height:320px;overflow:auto;',
      'background:linear-gradient(180deg,rgba(13,18,22,.98),rgba(7,10,12,.98));',
      'border:1px solid rgba(34,193,110,.28);border-radius:12px;',
      'box-shadow:0 18px 42px rgba(0,0,0,.55),0 0 0 1px rgba(34,193,110,.08) inset;',
      'padding:6px;} ',
      '.jtmes-suggest-panel.open{display:block;} ',
      '.jtmes-suggest-item{display:block;width:100%;text-align:left;cursor:pointer;border:0;outline:0;background:transparent;',
      'color:#eaf8ef;font-size:14px;line-height:1.25;padding:9px 12px;border-radius:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;} ',
      '.jtmes-suggest-item:hover,.jtmes-suggest-item.active{background:linear-gradient(180deg,rgba(13,36,24,.92),rgba(9,24,17,.92));',
      'box-shadow:inset 0 0 0 1px rgba(34,193,110,.24);color:#f4fff8;} ',
      '.jtmes-suggest-empty{color:rgba(234,248,239,.65);font-size:13px;padding:10px 12px;} ',
      '.jtmes-suggest-scrollbar::-webkit-scrollbar{width:10px;height:10px;} ',
      '.jtmes-suggest-scrollbar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18);border-radius:999px;border:2px solid rgba(0,0,0,0);} ',
      '.jtmes-suggest-scrollbar::-webkit-scrollbar-track{background:transparent;} ',
      '.card-filter .filter-input.has-jtmes-suggest{padding-right:34px !important;} ',
      '.card-filter .filter-group,.card-filter .filter-col,.card-filter .input-wrap{position:relative;} ',
      '.jtmes-suggest-caret{position:absolute;right:10px;top:50%;transform:translateY(-50%);width:18px;height:18px;pointer-events:none;opacity:.9;} ',
      '.jtmes-suggest-caret:before{content:"";position:absolute;left:4px;top:6px;width:0;height:0;border-left:4px solid transparent;border-right:4px solid transparent;border-top:6px solid #dff5e8;opacity:.9;} ',
      '.jtmes-suggest-open ~ .jtmes-suggest-caret:before{transform:rotate(180deg);transform-origin:center 2px;} '
    ].join('');
    document.head.appendChild(st);
  }

  function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; }); }

  function init(){
    injectStyle();
    var inputs = qsa('.card-filter input.filter-input[list]');
    if (!inputs.length) return;

    var panel = document.createElement('div');
    panel.className = 'jtmes-suggest-panel jtmes-suggest-scrollbar';
    panel.setAttribute('role','listbox');
    document.body.appendChild(panel);

    var state = { input:null, items:[], idx:-1, visible:false };

    function closePanel(){
      if (!state.visible) return;
      panel.classList.remove('open');
      state.visible = false;
      if (state.input) state.input.classList.remove('jtmes-suggest-open');
      state.idx = -1;
    }

    function getListInfo(input){
      if (input.__jtmesListInfo) return input.__jtmesListInfo;
      var id = input.getAttribute('list');
      if (!id) return null;
      var dl = document.getElementById(id);
      if (!dl) return null;
      var opts = qsa('option', dl).map(function(o){ return (o.value||'').trim(); }).filter(Boolean);
      var lower = opts.map(function(v){ return v.toLowerCase(); });
      input.__jtmesListInfo = { id:id, dl:dl, opts:opts, lower:lower };
      return input.__jtmesListInfo;
    }

    function ensureCaret(input){
      if (input.__jtmesCaretAdded) return;
      var host = input.closest('.filter-group') || input.parentElement;
      if (!host) return;
      if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
      var caret = document.createElement('span');
      caret.className = 'jtmes-suggest-caret';
      host.appendChild(caret);
      input.__jtmesCaretAdded = true;
    }

    function rankOptions(info, query){
      var q = (query||'').trim().toLowerCase();
      var results = [];
      var max = q ? 180 : 120;
      if (!q){
        for (var i=0;i<info.opts.length && results.length<max;i++) results.push(info.opts[i]);
        return results;
      }
      // startsWith first
      for (var i=0;i<info.opts.length && results.length<max;i++){
        var v = info.lower[i];
        if (v.indexOf(q) === 0) results.push(info.opts[i]);
      }
      for (var j=0;j<info.opts.length && results.length<max;j++){
        var vv = info.lower[j];
        if (vv.indexOf(q) > 0) results.push(info.opts[j]);
      }
      return results;
    }

    function positionPanel(input){
      var r = input.getBoundingClientRect();
      var vw = window.innerWidth || document.documentElement.clientWidth;
      var vh = window.innerHeight || document.documentElement.clientHeight;
      var width = Math.max(r.width, 220);
      var left = r.left;
      var top = r.bottom + 6;
      panel.style.minWidth = width + 'px';
      panel.style.left = Math.max(6, Math.min(left, vw - width - 6)) + 'px';
      // temporary height for flip calc
      var desiredMax = Math.min(320, Math.max(120, vh - top - 8));
      panel.style.maxHeight = desiredMax + 'px';
      panel.style.top = top + 'px';
      panel.style.bottom = 'auto';
      if (vh - top < 160){
        var above = r.top - 6;
        panel.style.top = 'auto';
        panel.style.bottom = Math.max(6, vh - r.top + 6) + 'px';
        panel.style.maxHeight = Math.min(320, Math.max(120, above - 8)) + 'px';
      }
    }

    function renderPanel(input, forceOpenAll){
      var info = getListInfo(input);
      if (!info) return closePanel();
      var list = rankOptions(info, forceOpenAll ? '' : input.value);
      state.input = input;
      state.items = list;
      state.idx = -1;
      input.classList.add('jtmes-suggest-open');
      positionPanel(input);
      if (!list.length){
        panel.innerHTML = '<div class="jtmes-suggest-empty">항목 없음</div>';
      } else {
        var html = '';
        for (var i=0;i<list.length;i++){
          html += '<button type="button" class="jtmes-suggest-item" data-idx="'+i+'" title="'+escapeHtml(list[i])+'">'+escapeHtml(list[i])+'</button>';
        }
        panel.innerHTML = html;
      }
      panel.classList.add('open');
      state.visible = true;
    }

    function commitSelection(value){
      if (!state.input) return;
      state.input.value = value;
      try {
        state.input.dispatchEvent(new Event('input', {bubbles:true}));
        state.input.dispatchEvent(new Event('change', {bubbles:true}));
      } catch(e) {
        var ev1 = document.createEvent('Event'); ev1.initEvent('input', true, true); state.input.dispatchEvent(ev1);
        var ev2 = document.createEvent('Event'); ev2.initEvent('change', true, true); state.input.dispatchEvent(ev2);
      }
      closePanel();
      state.input.focus();
    }

    function setActive(idx){
      var nodes = qsa('.jtmes-suggest-item', panel);
      if (!nodes.length) return;
      if (idx < 0) idx = nodes.length - 1;
      if (idx >= nodes.length) idx = 0;
      state.idx = idx;
      nodes.forEach(function(n){ n.classList.remove('active'); });
      var el = nodes[idx];
      if (el){
        el.classList.add('active');
        var pRect = panel.getBoundingClientRect();
        var eRect = el.getBoundingClientRect();
        if (eRect.bottom > pRect.bottom) panel.scrollTop += (eRect.bottom - pRect.bottom) + 6;
        if (eRect.top < pRect.top) panel.scrollTop -= (pRect.top - eRect.top) + 6;
      }
    }

    inputs.forEach(function(input){
      var info = getListInfo(input);
      if (!info) return;
      // Disable native datalist popup (cannot be styled) and use custom panel.
      input.removeAttribute('list');
      input.setAttribute('data-jtmes-list', info.id);
      input.setAttribute('autocomplete','off');
      input.classList.add('has-jtmes-suggest');
      ensureCaret(input);

      input.addEventListener('focus', function(){ renderPanel(input, true); });
      input.addEventListener('click', function(){ renderPanel(input, !input.value); });
      input.addEventListener('input', function(){ renderPanel(input, false); });
      input.addEventListener('keydown', function(e){
        if (e.key === 'ArrowDown'){
          if (!state.visible || state.input !== input) { renderPanel(input, false); }
          else setActive(state.idx + 1);
          e.preventDefault();
        } else if (e.key === 'ArrowUp'){
          if (state.visible && state.input === input){ setActive(state.idx - 1); e.preventDefault(); }
        } else if (e.key === 'Enter'){
          if (state.visible && state.input === input && state.idx > -1 && state.items[state.idx] != null){
            commitSelection(state.items[state.idx]);
            e.preventDefault();
          }
        } else if (e.key === 'Escape'){
          closePanel();
        }
      });
      input.addEventListener('blur', function(){
        setTimeout(function(){ if (document.activeElement !== panel) closePanel(); }, 120);
      });
    });

    panel.addEventListener('mousedown', function(e){
      var btn = e.target.closest('.jtmes-suggest-item');
      if (!btn) return;
      e.preventDefault();
      var idx = parseInt(btn.getAttribute('data-idx'), 10);
      if (!isNaN(idx) && state.items[idx] != null) commitSelection(state.items[idx]);
    });
    panel.addEventListener('mousemove', function(e){
      var btn = e.target.closest('.jtmes-suggest-item');
      if (!btn) return;
      var idx = parseInt(btn.getAttribute('data-idx'), 10);
      if (!isNaN(idx)) setActive(idx);
    });

    document.addEventListener('mousedown', function(e){
      if (!state.visible) return;
      if (panel.contains(e.target)) return;
      if (state.input && e.target === state.input) return;
      if (state.input && state.input.closest('.filter-group') && state.input.closest('.filter-group').contains(e.target)) return;
      closePanel();
    }, true);

    window.addEventListener('resize', function(){ if (state.visible && state.input) positionPanel(state.input); }, {passive:true});
    window.addEventListener('scroll', function(){ if (state.visible && state.input) positionPanel(state.input); }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
</script>


<style id="ipqc-btn-hover-v37">
/* v37: make action button hover visibly fill/glow like IPQC (no movement) */
.card-list > .top-bar .btn,
.card-list .top-bar > div:last-child .btn,
.card-list .top-bar button.btn {
  background: linear-gradient(180deg, rgba(8,22,16,.90) 0%, rgba(5,15,11,.96) 100%) !important;
  border: 1px solid rgba(0, 255, 159, .36) !important;
  color: #eafff3 !important;
  box-shadow:
    inset 0 1px 0 rgba(157, 255, 209, .08),
    0 0 0 1px rgba(7, 24, 18, .25) !important;
  transition: background .14s ease, border-color .14s ease, box-shadow .14s ease, color .14s ease, filter .14s ease !important;
  transform: none !important;
}
.card-list > .top-bar .btn:hover,
.card-list .top-bar > div:last-child .btn:hover,
.card-list .top-bar button.btn:hover {
  background: linear-gradient(180deg, rgba(9, 69, 46, .96) 0%, rgba(5, 38, 28, .98) 100%) !important;
  border-color: rgba(79, 255, 180, .82) !important;
  color: #ffffff !important;
  box-shadow:
    inset 0 1px 0 rgba(198,255,225,.24),
    0 0 0 1px rgba(22, 86, 60, .40),
    0 0 14px rgba(0, 255, 153, .18) !important;
  filter: brightness(1.03) saturate(1.02) !important;
  transform: none !important;
}
.card-list > .top-bar .btn:active,
.card-list .top-bar > div:last-child .btn:active,
.card-list .top-bar button.btn:active {
  background: linear-gradient(180deg, rgba(4, 40, 27, .98) 0%, rgba(3, 27, 20, 1) 100%) !important;
  border-color: rgba(79, 255, 180, .70) !important;
  box-shadow:
    inset 0 1px 2px rgba(0,0,0,.45),
    0 0 0 1px rgba(22, 86, 60, .26) !important;
  transform: none !important;
}
.card-list > .top-bar .btn:focus-visible,
.card-list .top-bar > div:last-child .btn:focus-visible,
.card-list .top-bar button.btn:focus-visible {
  outline: none !important;
  border-color: rgba(108, 255, 197, .9) !important;
  box-shadow:
    inset 0 1px 0 rgba(198,255,225,.18),
    0 0 0 1px rgba(23,98,67,.45),
    0 0 0 3px rgba(0,255,153,.16),
    0 0 16px rgba(0,255,153,.15) !important;
}
</style>


<style id="ipqc-query-btn-v38">
/* v38: make filter-card 조회 button use same visible fill/hover as IPQC action buttons */
.card-filter .btn-search,
.card-filter button[type="submit"].btn-search,
.card-filter .filter-actions .btn-search {
  background: linear-gradient(180deg, rgba(8,22,16,.90) 0%, rgba(5,15,11,.96) 100%) !important;
  border: 1px solid rgba(0,255,159,.36) !important;
  color: #eafff3 !important;
  box-shadow:
    inset 0 1px 0 rgba(157,255,209,.08),
    0 0 0 1px rgba(7,24,18,.25) !important;
  transition: background .14s ease, border-color .14s ease, box-shadow .14s ease, color .14s ease, filter .14s ease !important;
  transform: none !important;
}
.card-filter .btn-search:hover,
.card-filter button[type="submit"].btn-search:hover,
.card-filter .filter-actions .btn-search:hover {
  background: linear-gradient(180deg, rgba(9,69,46,.96) 0%, rgba(5,38,28,.98) 100%) !important;
  border-color: rgba(79,255,180,.82) !important;
  color: #ffffff !important;
  box-shadow:
    inset 0 1px 0 rgba(198,255,225,.24),
    0 0 0 1px rgba(22,86,60,.40),
    0 0 14px rgba(0,255,153,.18) !important;
  filter: brightness(1.03) saturate(1.02) !important;
  transform: none !important;
}
.card-filter .btn-search:active,
.card-filter button[type="submit"].btn-search:active,
.card-filter .filter-actions .btn-search:active {
  background: linear-gradient(180deg, rgba(4,40,27,.98) 0%, rgba(3,27,20,1) 100%) !important;
  border-color: rgba(79,255,180,.70) !important;
  box-shadow:
    inset 0 1px 2px rgba(0,0,0,.45),
    0 0 0 1px rgba(22,86,60,.26) !important;
  transform: none !important;
}
.card-filter .btn-search:focus-visible,
.card-filter button[type="submit"].btn-search:focus-visible,
.card-filter .filter-actions .btn-search:focus-visible {
  outline: none !important;
  border-color: rgba(108,255,197,.9) !important;
  box-shadow:
    inset 0 1px 0 rgba(198,255,225,.18),
    0 0 0 1px rgba(23,98,67,.45),
    0 0 0 3px rgba(0,255,153,.16),
    0 0 16px rgba(0,255,153,.15) !important;
}
</style>

</body>
</html>
