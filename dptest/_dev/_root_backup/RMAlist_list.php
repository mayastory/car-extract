<?php
// 한국시간 강제
date_default_timezone_set('Asia/Seoul');

// rmalist_list.php : rmalist 출하내역 조회(다크모드 + 필터 + datalist)

session_start();
require_once __DIR__ . '/config/dp_config.php';

// ✅ embed=1 이면(쉘/iframe 내부) 사이드바/유저바/매트릭스 출력 안 함
$EMBED = !empty($_GET['embed']);
if (!$EMBED) {
    require_once __DIR__ . '/inc/sidebar.php';
    require_once __DIR__ . '/inc/dp_userbar.php';
}


if (!function_exists('h')) {
    function h(?string $s): string {
            return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
        }
}
if (!function_exists('col')) {
    function col(array $row, string $key): string {
            return h($row[$key] ?? '');
        }
}


// 로그인 체크
if (empty($_SESSION['ship_user_id'])) {
    header('Location: index.php');
    exit;
}

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
        'pack_date'          => ['label' => '포장일자',           'type' => 'date'],
        'prod_date'          => ['label' => '생산일자',           'type' => 'date'],
        'ann_date' => ['label' => '어닐링일자', 'type' => 'date'],
        'pack_no'            => ['label' => '포장번호',           'type' => 'text'],
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
        SELECT rmalist.*,
               (
                   SELECT MAX(COALESCE(r.return_datetime, r.return_date))
                   FROM `rmalist` r
                   WHERE r.small_pack_no = rmalist.small_pack_no
               ) AS last_return_datetime
        FROM rmalist
        WHERE {$popupCol} = :v
        ORDER BY ship_datetime DESC, id DESC
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
$heavyThresholdDays = 60;
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
$countSql = "SELECT COUNT(*) FROM rmalist {$whereSql}";
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

    $countSql = "SELECT COUNT(*) FROM rmalist {$whereSql}";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
}
$totalPages = max(1, (int)ceil($total / $perPage));

// 리스트 본문: 인덱스(ship_datetime, id) 활용
$listSql = "
    SELECT rmalist.*,
           (
               SELECT MAX(COALESCE(r.return_datetime, r.return_date))
               FROM `rmalist` r
               WHERE r.small_pack_no = rmalist.small_pack_no
           ) AS last_return_datetime
    FROM rmalist
    {$whereSql}
    ORDER BY ship_datetime DESC, id DESC
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

    $sql = "SELECT DISTINCT ship_to FROM rmalist {$whereSql} ORDER BY ship_to LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listShipTo = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT DISTINCT pack_barcode FROM rmalist {$whereSql} ORDER BY pack_barcode LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listPackBc = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT DISTINCT small_pack_no FROM rmalist {$whereSql} ORDER BY small_pack_no LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listSmallNo = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT DISTINCT tray_no FROM rmalist {$whereSql} ORDER BY tray_no LIMIT {$distinctLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listTrayNo = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT DISTINCT part_name FROM rmalist {$whereSql} ORDER BY part_name LIMIT {$distinctLimit}";
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
        FROM rmalist
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
    <title>rmalist 출하내역</title>
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
                var url = 'rmalist_tool_cavity.php' + qs;
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
</head>
<body>
<?php if (empty($EMBED)):
// 좌측 메뉴 + 상단 유저바 (페이지마다 자동 적용)
echo dp_sidebar_render('rma');
echo dp_render_userbar([
'admin_badge_mode' => 'modal',       // ✅ 관리자 버튼 = 모달
    'admin_iframe_src' => 'rmalist_main', // 모달 안에서 열 페이지(필요시 변경)
    'logout_action'    => 'logout'
]);
endif; ?>
<?php if (!empty($EMBED)): ?>
<style>
  /* ✅ iframe(embed)에서는 배경/좌측패딩 제거해서 쉘 배경이 보이게 */
  body{background: transparent !important; padding:0 !important;}
  .dp-page{padding-left:0 !important;}
</style>
<?php endif; ?>
<div class="dp-page">

<div class="page-wrap">
    <div class="page-title">RMA 내역</div>
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
                               value="<?= h($fromDate) ?>" min="2000-01-01" max="9999-12-31">
                        <span style="font-size:11px; color:#9aa0a6;">~</span>
                        <input type="date" name="to_date"
                               class="filter-input-date"
                               value="<?= h($toDate) ?>" min="2000-01-01" max="9999-12-31">
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
                            <td class="pf-cell" data-pf-col="ann_date" data-pf-label="어닐링일자" data-pf-val="<?= col($r,'prod_date_nocutoff') ?>"><?= col($r,'prod_date_nocutoff') ?></td>
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
    params.set('src', 'rmalist');
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
$__mb = @include __DIR__ . '/config/matrix_bg.php';
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
</body>
</html>
