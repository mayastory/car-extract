<?php
// 소포장 상세내역 (가로 매트릭스) - ShipingList 기준
// [modules-refactor] JTMES_ROOT auto-detect for relocated pages / top-level route files
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

function normalize_date_ymd_local(?string $s): string {
    $s = trim((string)($s ?? ''));
    if ($s === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^\d{6}-\d{2}-\d{2}$/', $s)) {
        $yyyy = substr($s, 0, 4);
        $mm   = substr($s, 4, 2);
        $dd   = substr($s, -2);
        return $yyyy . '-' . $mm . '-' . $dd;
    }
    if (preg_match('/^\d{8}$/', $s)) {
        return substr($s,0,4) . '-' . substr($s,4,2) . '-' . substr($s,6,2);
    }
    return '';
}

function ymd_valid_local(string $v): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return $dt && $dt->format('Y-m-d') === $v;
}

function parse_cavity_local($v): ?int {
    if ($v === null) return null;
    if (is_int($v)) return ($v >= 1 && $v <= 8) ? $v : null;
    if (is_float($v)) {
        $iv = (int)$v;
        if (abs($v - $iv) < 1e-9 && $iv >= 1 && $iv <= 8) return $iv;
        return null;
    }
    $s = trim((string)$v);
    if ($s === '') return null;
    if (!preg_match('/(\d+)/', $s, $m)) return null;
    $iv = (int)$m[1];
    return ($iv >= 1 && $iv <= 8) ? $iv : null;
}

function fmt_ea($n): string {
    return number_format((int)$n) . ' EA';
}

function rev_sort_key_local(string $rev): array {
    $rev = trim($rev);
    if ($rev !== '' && preg_match('/^\d+$/', $rev)) return [0, (int)$rev, ''];
    return [1, 0, $rev];
}

$DEFAULT_GROUPS = [
    ['MEM-IR-BASE',   [1,2,3,4]],
    ['MEM-X-CARRIER', [1,2,3,4]],
    ['MEM-Y-CARRIER', [1,2,3,4]],
    ['MEM-Z-CARRIER', [5,6,7,8]],
    ['MEM-Z-STOPPER', [5,6,7,8]],
];
$PART_ALIAS = [
    'MEM-Y-CARRIER DAMPERLESS' => 'MEM-Y-CARRIER',
];

try {
    $pdo = dp_get_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="background:#202124;color:#e8eaed;font-family:sans-serif;padding:16px;">DB 접속 실패: '.h($e->getMessage()).'</body>';
    exit;
}

$today = date('Y-m-d');
$fromDate = normalize_date_ymd_local(trim((string)($_GET['from_date'] ?? '')));
$toDate   = normalize_date_ymd_local(trim((string)($_GET['to_date'] ?? '')));
if (!ymd_valid_local($fromDate)) $fromDate = $today;
if (!ymd_valid_local($toDate))   $toDate   = $today;

$filterShipTo  = trim((string)($_GET['ship_to'] ?? ''));
$filterPackBc  = trim((string)($_GET['pack_bc'] ?? ''));
$filterSmallNo = trim((string)($_GET['small_no'] ?? ''));
$filterTrayNo  = trim((string)($_GET['tray_no'] ?? ''));
$filterPname   = trim((string)($_GET['part_name'] ?? ''));

$where = [];
$params = [];

if ($fromDate !== '' && $toDate !== '') {
    $fromDt = $fromDate . ' 00:00:00';
    $toDt   = date('Y-m-d 00:00:00', strtotime($toDate . ' +1 day'));
    $where[] = 'ship_datetime >= :from_dt';
    $where[] = 'ship_datetime < :to_dt';
    $params[':from_dt'] = $fromDt;
    $params[':to_dt']   = $toDt;
}
if ($filterShipTo !== '') {
    $where[] = 'ship_to LIKE :ship_to';
    $params[':ship_to'] = '%' . $filterShipTo . '%';
}
if ($filterPackBc !== '') {
    $where[] = 'pack_barcode LIKE :pack_bc';
    $params[':pack_bc'] = '%' . $filterPackBc . '%';
}
if ($filterSmallNo !== '') {
    $where[] = 'small_pack_no LIKE :small_no';
    $params[':small_no'] = '%' . $filterSmallNo . '%';
}
if ($filterTrayNo !== '') {
    $where[] = 'tray_no LIKE :tray_no';
    $params[':tray_no'] = '%' . $filterTrayNo . '%';
}
if ($filterPname !== '') {
    $where[] = 'part_name LIKE :part_name';
    $params[':part_name'] = '%' . $filterPname . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        part_name,
        revision,
        DATE(prod_date) AS prod_date,
        cavity,
        SUM(qty) AS sum_qty
    FROM ShipingList
    {$whereSql}
    GROUP BY TRIM(part_name), TRIM(revision), DATE(prod_date), cavity
";

$t0 = microtime(true);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$dbMs = (int)round((microtime(true) - $t0) * 1000);

$agg = [];      // key: pn|rev|date|cav => qty
$cavSeen = [];  // pn => [cav...]
foreach ($rows as $r) {
    $pnRaw = trim((string)($r['part_name'] ?? ''));
    $pn = $pnRaw !== '' ? ($PART_ALIAS[$pnRaw] ?? $pnRaw) : '';
    $rev = trim((string)($r['revision'] ?? ''));
    $d   = trim((string)($r['prod_date'] ?? ''));
    $cav = parse_cavity_local($r['cavity'] ?? null);
    $q   = (int)($r['sum_qty'] ?? 0);

    if ($pn === '' || $rev === '' || $d === '' || $q <= 0 || $cav === null) continue;

    $k = $pn . "\x1F" . $rev . "\x1F" . $d . "\x1F" . $cav;
    if (!isset($agg[$k])) $agg[$k] = 0;
    $agg[$k] += $q;
    $cavSeen[$pn][] = $cav;
}

$totals = [];
foreach ($agg as $k => $q) {
    [$pn] = explode("\x1F", $k, 2);
    if (!isset($totals[$pn])) $totals[$pn] = 0;
    $totals[$pn] += (int)$q;
}

$chooseCavs = function(string $pn, array $defaultCavs) use (&$cavSeen): array {
    $vals = [];
    foreach (($cavSeen[$pn] ?? []) as $c) {
        if (is_int($c) && $c >= 1 && $c <= 8) $vals[] = $c;
    }
    $default4 = function() use ($defaultCavs): array {
        if (!$defaultCavs) return [1,2,3,4];
        $mx = max($defaultCavs); $mn = min($defaultCavs);
        if ($mx <= 4) return [1,2,3,4];
        if ($mn >= 5) return [5,6,7,8];
        return [1,2,3,4];
    };
    if (!$vals) return $default4();
    $c14 = 0; $c58 = 0;
    foreach ($vals as $c) { if ($c <= 4) $c14++; else $c58++; }
    if ($c14 > $c58) return [1,2,3,4];
    if ($c58 > $c14) return [5,6,7,8];
    return $default4();
};

$groups = [];
foreach ($DEFAULT_GROUPS as [$partName, $defaultCavs]) {
    $cavCols = $chooseCavs($partName, $defaultCavs);
    $keyMap = []; // rev|date => ['cavs'=>[1=>bool..], 'qty'=>n]

    foreach ($agg as $k => $q) {
        [$pn, $rev, $d, $cav] = explode("\x1F", $k);
        $cav = (int)$cav;
        if ($pn !== $partName) continue;
        $k2 = $rev . "\x1F" . $d;
        if (!isset($keyMap[$k2])) {
            $cm = [];
            foreach ($cavCols as $cc) $cm[(int)$cc] = false;
            $keyMap[$k2] = ['rev'=>$rev, 'date'=>$d, 'cavs'=>$cm, 'qty'=>0];
        }
        if (array_key_exists($cav, $keyMap[$k2]['cavs'])) {
            $keyMap[$k2]['cavs'][$cav] = true;
        }
        $keyMap[$k2]['qty'] += (int)$q;
    }

    $rowsOut = array_values(array_filter($keyMap, function($v){ return ((int)($v['qty'] ?? 0)) > 0; }));
    usort($rowsOut, function($a, $b){
        $ka = rev_sort_key_local((string)($a['rev'] ?? ''));
        $kb = rev_sort_key_local((string)($b['rev'] ?? ''));
        if ($ka[0] !== $kb[0]) return $ka[0] <=> $kb[0];
        if ($ka[0] === 0 && $ka[1] !== $kb[1]) return $ka[1] <=> $kb[1];
        if ($ka[2] !== $kb[2]) return strcmp($ka[2], $kb[2]);
        return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
    });

    $groups[] = [
        'part_name' => $partName,
        'cavs'      => $cavCols,
        'rows'      => $rowsOut,
        'total_qty' => (int)($totals[$partName] ?? 0),
    ];
}

function uniq_lines_for_group(array $rows, array $cavHeader): array {
    $dates = [];
    $revs = [];
    $revToCavs = [];
    foreach ($rows as $rr) {
        $rv = trim((string)($rr['rev'] ?? ''));
        $dd = trim((string)($rr['date'] ?? ''));
        if ($dd !== '') $dates[$dd] = true;
        if ($rv !== '') {
            $revs[$rv] = true;
            $cmap = (array)($rr['cavs'] ?? []);
            foreach ($cavHeader as $c) {
                $ci = (int)$c;
                if (!empty($cmap[$ci])) $revToCavs[$rv][$ci] = true;
            }
        }
    }
    $dateList = array_keys($dates);
    sort($dateList, SORT_STRING);

    $revList = array_keys($revs);
    usort($revList, function($a, $b){
        $ka = rev_sort_key_local((string)$a);
        $kb = rev_sort_key_local((string)$b);
        if ($ka[0] !== $kb[0]) return $ka[0] <=> $kb[0];
        if ($ka[0] === 0 && $ka[1] !== $kb[1]) return $ka[1] <=> $kb[1];
        return strcmp((string)$ka[2], (string)$kb[2]);
    });

    $out = [];
    foreach ($dateList as $d) $out[] = $d;
    foreach ($revList as $rv) {
        $cavs = array_keys($revToCavs[$rv] ?? []);
        sort($cavs, SORT_NUMERIC);
        $cavStr = $cavs ? implode(',', $cavs) : '-';
        $out[] = sprintf('%-2s %-11s /Cav', $rv, $cavStr);
    }
    return $out;
}

$summaryParts = [];
foreach ($groups as $g) {
    $summaryParts[] = ($g['part_name'] . ' : ' . fmt_ea((int)$g['total_qty']));
}

// 모델별 박스 수 요약 (pack_barcode 우선, 없으면 소포장번호 기준 distinct)
$boxTotalsByPart = [];
try {
    $sqlBoxSummary = "
        SELECT
            part_name,
            COUNT(DISTINCT NULLIF(TRIM(COALESCE(NULLIF(pack_barcode,''), small_pack_no)), '')) AS box_count
        FROM ShipingList
        {$whereSql}
        GROUP BY TRIM(part_name)
    ";
    $stBox = $pdo->prepare($sqlBoxSummary);
    $stBox->execute($params);
    foreach ($stBox->fetchAll(PDO::FETCH_ASSOC) as $br) {
        $pnRaw = trim((string)($br['part_name'] ?? ''));
        $pn = $pnRaw !== '' ? ($PART_ALIAS[$pnRaw] ?? $pnRaw) : '';
        if ($pn === '') continue;
        if (!isset($boxTotalsByPart[$pn])) $boxTotalsByPart[$pn] = 0;
        $boxTotalsByPart[$pn] += (int)($br['box_count'] ?? 0);
    }
} catch (Throwable $e) {
    // 요약만 실패해도 상세 표는 계속 표시
}

$visibleGroups = array_values(array_filter($groups, function($g){
    return (int)($g['total_qty'] ?? 0) > 0;
}));
$hasVisibleGroups = !empty($visibleGroups);

$summaryPartsVisible = [];
$summaryBoxPartsVisible = [];
foreach ($visibleGroups as $g) {
    $pn = (string)($g['part_name'] ?? '');
    $summaryPartsVisible[] = ($pn . ' : ' . fmt_ea((int)$g['total_qty']));
    $summaryBoxPartsVisible[] = ($pn . ' : ' . number_format((int)($boxTotalsByPart[$pn] ?? 0)) . ' 박스');
}
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>소포장 상세내역</title>
<style>
    *{box-sizing:border-box}
    html,body{height:100%;}
    body{
        margin:0;
        background:#202124;
        color:#e8eaed;
        font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        font-size:12px;
        overflow:hidden; /* 페이지 전체 스크롤 막고 내부(groups)에서 전체 가로스크롤 */
    }
    .wrap{
        height:100vh;
        padding:8px 10px 10px;
        display:flex;
        flex-direction:column;
        gap:8px;
        overflow:hidden;
        min-width:0;
    }
    .top-summary{
        flex:0 0 auto;
        color:#dfe3e7;
        white-space:nowrap;
        overflow-x:auto;
        overflow-y:hidden;
        padding:2px 2px 6px;
        line-height:1.32;
        border-bottom:1px solid #34383d;
        margin-bottom:0;
    }
    .top-summary .row1{ color:#cfd8dc; }
    .top-summary .row2{ color:#e8eaed; }
    .top-summary .row3{ color:#b9d9ff; }
    .filters{
        color:#9aa0a6;
        margin-top:2px;
        font-size:11px;
        white-space:nowrap;
    }
    .empty-notice{
        flex:0 0 auto;
        margin:2px 0 0;
        padding:14px 16px;
        border:1px dashed #3a4148;
        border-radius:10px;
        background:#1b1d20;
        color:#eef2f6;
        text-align:center;
        font-size:13px;
        font-weight:600;
    }
    .groups{
        flex:1 1 auto;
        min-height:0;
        min-width:0;
        display:flex;
        flex-wrap:nowrap;
        gap:10px;
        align-items:flex-start;
        overflow-x:auto;  /* 전체 가로 스크롤 */
        overflow-y:auto;  /* 전체 세로 스크롤 */
        padding:0 0 4px;
        scrollbar-gutter:stable both-edges;
    }
    .group-card{
        flex:0 0 auto;
        background:#1b1c1f;
        border:1px solid #34383d;
        border-radius:12px;
        min-width:max-content;   /* 내부 원래 크기 유지 */
        width:max-content;
        padding:7px;
        box-shadow:0 6px 18px rgba(0,0,0,.35);
        display:flex;
        flex-direction:column;
        min-height:0;
        overflow:visible;
    }
    .group-title{
        flex:0 0 auto;
        font-size:13px;
        font-weight:700;
        color:#f1f3f4;
        margin:0 0 6px;
        text-align:center;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }
    .group-row{
        flex:0 0 auto;
        min-height:0;
        display:flex;
        align-items:flex-start;
        gap:8px;
        min-width:0;
    }
    .group-row > *{ min-width:0; min-height:0; }
    .table-shell{
        flex:0 0 auto;
        border:1px solid #2e3237;
        border-radius:8px;
        overflow-y:visible;
        overflow-x:visible;
        background:#111316;
        max-height:none;
        min-height:0;
        scrollbar-gutter:stable;
    }
    table.matrix{
        border-collapse:collapse;
        width:max-content;
        min-width:100%;
        white-space:nowrap;
        color:#e8eaed;
        font-size:11px;
    }
    .matrix th,.matrix td{
        border-bottom:1px solid #262a2f;
        border-right:1px solid #262a2f;
        padding:5px 6px;
        text-align:center;
        line-height:1.2;
    }
    .matrix th:last-child,.matrix td:last-child{ border-right:none; }
    .matrix thead th{
        position:sticky;
        top:0;
        z-index:2;
        background:#1f2329;
        color:#f1f3f4;
        font-weight:700;
    }
    /* 원래 디자인처럼 품번명 컬럼 표시 */
    .matrix td.part{ text-align:left; min-width:88px; }
    .matrix td.rev{ min-width:32px; }
    .matrix td.date{ min-width:86px; }
    .matrix td.qty{ text-align:right; min-width:74px; padding-right:8px; }
    .matrix td.bool-true,.matrix td.bool-false{ min-width:48px; }
    .bool-true{ background:#c6efce; color:#006100; font-weight:700; }
    .bool-false{ background:#ffc7ce; color:#9c0006; font-weight:700; }
    .muted-row td{ color:#9aa0a6; }

    .side{
        flex:0 0 auto;
        width:154px;
        min-width:154px;
        display:flex;
        align-self:flex-start;
        flex-direction:column;
        gap:6px;
        min-height:0;
    }
    .total-box{
        flex:0 0 auto;
        border:1px solid #3a3a3a;
        border-radius:8px;
        background:#151515;
        font-weight:700;
        text-align:center;
        padding:6px 8px;
        white-space:nowrap;
    }
    .uniq-list{
        flex:0 0 auto;
        border:1px solid #333;
        border-radius:8px;
        background:#121212;
        padding:6px;
        overflow-y:visible;
        overflow-x:visible;
        min-height:0;
        font-family:Consolas, "Courier New", monospace;
        line-height:1.35;
        white-space:pre;   /* 원래 줄맞춤 유지 */
        font-size:11px;
        scrollbar-gutter:stable;
    }
    .uniq-line{ color:#e8eaed; }
    .uniq-line.rev{ color:#d0d7de; }
    .empty{ color:#9aa0a6; }
    .help{
        flex:0 0 auto;
        color:#9aa0a6;
        margin-top:0;
        font-size:11px;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }


    /* 단일 전역 스크롤 강제: 내부 카드/리스트 개별 세로스크롤 제거 */
    .groups{ overscroll-behavior:contain; }
    .groups > .group-card{ align-self:flex-start; }
    .table-shell, .uniq-list{ scrollbar-gutter:auto; }
    .table-shell::-webkit-scrollbar,
    .uniq-list::-webkit-scrollbar{ width:0 !important; height:0 !important; }

    /* 아주 좁은 화면에서만 최소 보호 */
    @media (max-width: 1200px){
        .group-card{ min-width:max-content; width:max-content; }
        .side{ width:140px; min-width:140px; }
        .matrix th,.matrix td{ padding:4px 5px; font-size:10px; }
        .uniq-list{ font-size:10px; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="top-summary">
        <div class="row1">기간: <?= h($fromDate) ?> ~ <?= h($toDate) ?></div>
        <div class="row2"><?= h($summaryPartsVisible ? implode(' | ', $summaryPartsVisible) : '-') ?></div>
        <div class="row3"><?= h($summaryBoxPartsVisible ? implode(' | ', $summaryBoxPartsVisible) : '-') ?></div>
    </div>

    <?php if (!$hasVisibleGroups): ?>
        <div class="empty-notice">해당 조건의 출하 데이터가 없습니다. (출하 있는 모델만 표시됩니다)</div>
    <?php else: ?>
    <div class="groups">
        <?php foreach ($visibleGroups as $g): ?>
            <?php
                $pn = (string)$g['part_name'];
                $cavs = array_values(array_map('intval', (array)$g['cavs']));
                $rowsOut = (array)$g['rows'];
                $totalQty = (int)$g['total_qty'];
                $uniqLines = uniq_lines_for_group($rowsOut, $cavs);
            ?>
            <section class="group-card">
                <div class="group-title"><?= h($pn) ?></div>
                <div class="group-row">
                    <div class="table-shell">
                        <table class="matrix">
                            <thead>
                                <tr>
                                    <th>품번명</th>
                                    <th>차수</th>
                                    <th>날짜</th>
                                    <?php foreach ($cavs as $c): ?>
                                        <th>Cav<?= (int)$c ?></th>
                                    <?php endforeach; ?>
                                    <th>수량</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$rowsOut): ?>
                                <tr class="muted-row">
                                    <td class="part"><?= h($pn) ?></td>
                                    <td class="rev">-</td>
                                    <td class="date">-</td>
                                    <?php foreach ($cavs as $c): ?><td>-</td><?php endforeach; ?>
                                    <td class="qty">0 EA</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rowsOut as $r): ?>
                                    <tr>
                                        <td class="part"><?= h($pn) ?></td>
                                        <td class="rev"><?= h((string)$r['rev']) ?></td>
                                        <td class="date"><?= h((string)$r['date']) ?></td>
                                        <?php foreach ($cavs as $c): ?>
                                            <?php $v = !empty($r['cavs'][(int)$c]); ?>
                                            <td class="<?= $v ? 'bool-true' : 'bool-false' ?>"><?= $v ? 'TRUE' : 'FALSE' ?></td>
                                        <?php endforeach; ?>
                                        <td class="qty"><?= h(fmt_ea((int)($r['qty'] ?? 0))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="side">
                        <div class="total-box"><?= h(fmt_ea($totalQty)) ?></div>
                        <div class="uniq-list" title="날짜 / 차수별 Cavity 목록">
                            <?php if (!$uniqLines): ?>
                                <div class="empty">-</div>
                            <?php else: ?>
                                <?php foreach ($uniqLines as $line): ?>
                                    <?php $isRev = (strpos($line, '/Cav') !== false); ?>
                                    <div class="uniq-line<?= $isRev ? ' rev' : '' ?>"><?= h($line) ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
