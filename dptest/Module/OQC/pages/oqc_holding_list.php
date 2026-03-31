<?php
if (!defined('JTMES_ROOT')) {
    define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

date_default_timezone_set('Asia/Seoul');
session_start();

require_once JTMES_ROOT . '/config/dp_config.php';

$EMBED = !empty($_GET['embed']);
if (!$EMBED) {
    require_once JTMES_ROOT . '/inc/sidebar.php';
    require_once JTMES_ROOT . '/inc/dp_userbar.php';
}
require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function table_columns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[strtolower((string)$row['Field'])] = true;
        }
    } catch (Throwable $e) {
    }
    return $cols;
}

function normalize_simple(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9가-힣]/u', '', $s) ?? $s;
    return $s;
}

function fmt_display_value($value): string
{
    if ($value === null) return '';
    $text = trim((string)$value);
    if ($text === '') return '';
    return $text;
}

function parse_numeric_or_null($value): ?float
{
    if ($value === null) return null;
    $text = trim((string)$value);
    if ($text === '') return null;
    if (!is_numeric($text)) return null;
    return (float)$text;
}

function parse_tool_cavity(?string $toolCavity): array
{
    $text = trim((string)$toolCavity);
    if ($text === '') return ['', ''];

    $patterns = [
        '/([A-Z])\s*#?\s*(\d+)/i',
        '/TOOL\s*[:#-]?\s*([A-Z0-9]+).*?CAV(?:ITY)?\s*[:#-]?\s*(\d+)/i',
        '/([A-Z])\s*\/\s*(\d+)/i',
        '/([A-Z])(\d)/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return [strtoupper(trim($m[1])), trim($m[2])];
        }
    }
    return ['', ''];
}

function parse_date_text(?string $value): ?string
{
    $text = trim((string)$value);
    if ($text === '') return null;

    $formats = ['Y-m-d', 'Y/m/d', 'Y.m.d', 'y-m-d', 'y/m/d', 'y.m.d'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $text);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    if (preg_match('/\b(20\d{2}|19\d{2})[-_\/.]?(\d{2})[-_\/.]?(\d{2})\b/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/\b(\d{2})[-_\/.]?(\d{2})[-_\/.]?(\d{2})\b/', $text, $m)) {
        $year = (int)$m[1];
        $year += ($year >= 70) ? 1900 : 2000;
        return sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[3]);
    }

    $ts = strtotime($text);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }
    return null;
}

function pick_header_date(array $row, array $priorityCols): ?string
{
    $sourceFile = $row['source_file'] ?? '';
    $fromSource = parse_date_text($sourceFile);
    if ($fromSource !== null) {
        return $fromSource;
    }

    foreach ($priorityCols as $col) {
        if (!array_key_exists($col, $row)) continue;
        $parsed = parse_date_text((string)$row[$col]);
        if ($parsed !== null) return $parsed;
    }
    return null;
}

function lot_label(string $date): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt instanceof DateTime) return $date;
    return $dt->format('m월 d일');
}

function build_model_condition(string $fieldExpr, string $token): array
{
    $patterns = ["%{$token}%"];
    if ($token === 'irbase') {
        $patterns[] = '%ir%base%';
    } elseif ($token === 'xcarrier') {
        $patterns[] = '%xcarrier%';
        $patterns[] = '%x%carrier%';
    } elseif ($token === 'ycarrier') {
        $patterns[] = '%ycarrier%';
        $patterns[] = '%y%carrier%';
    } elseif ($token === 'zcarrier') {
        $patterns[] = '%zcarrier%';
        $patterns[] = '%z%carrier%';
    } elseif ($token === 'zstopper') {
        $patterns[] = '%zstopper%';
        $patterns[] = '%z%stopper%';
    }
    $patterns = array_values(array_unique($patterns));

    $parts = [];
    $params = [];
    foreach ($patterns as $idx => $pattern) {
        $key = ':model_like_' . $idx;
        $parts[] = "{$fieldExpr} LIKE {$key}";
        $params[$key] = $pattern;
    }
    return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
}

function classify_ng_side(array $row): string
{
    $value = parse_numeric_or_null($row['measured_value'] ?? null);
    $usl = parse_numeric_or_null($row['usl'] ?? null);
    $lsl = parse_numeric_or_null($row['lsl'] ?? null);

    if ($value !== null && $usl !== null && $value > $usl) return 'USL';
    if ($value !== null && $lsl !== null && $value < $lsl) return 'LSL';
    if ($usl !== null && $lsl === null) return 'USL';
    if ($lsl !== null && $usl === null) return 'LSL';
    if ($value !== null && $usl !== null && $lsl !== null) {
        return abs($value - $usl) <= abs($value - $lsl) ? 'USL' : 'LSL';
    }
    return 'USL';
}

function is_dc_point(?string $pointNo): bool
{
    $text = trim((string)$pointNo);
    if ($text === '') return false;
    return (bool)preg_match('/\(\s*dc\s*\)/i', $text);
}

function daterange_inclusive(string $start, string $end): array
{
    $out = [];
    $cur = DateTime::createFromFormat('Y-m-d', $start);
    $last = DateTime::createFromFormat('Y-m-d', $end);
    if (!$cur instanceof DateTime || !$last instanceof DateTime) return $out;
    while ($cur <= $last) {
        $out[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    return $out;
}

$holdingTabs = [
    'MEM-IR-BASE' => [
        'label' => 'MEM-IR-BASE',
        'product_name' => 'IR BASE',
        'model_norm' => 'irbase',
    ],
    'MEM-X-CARRIER' => [
        'label' => 'MEM-X-CARRIER',
        'product_name' => 'X CARRIER',
        'model_norm' => 'xcarrier',
    ],
    'MEM-Y-CARRIER' => [
        'label' => 'MEM-Y-CARRIER',
        'product_name' => 'Y CARRIER',
        'model_norm' => 'ycarrier',
    ],
    'MEM-Z-CARRIER' => [
        'label' => 'MEM-Z-CARRIER',
        'product_name' => 'Z CARRIER',
        'model_norm' => 'zcarrier',
    ],
    'MEM-Z-STOPPER' => [
        'label' => 'MEM-Z-STOPPER',
        'product_name' => 'Z STOPPER',
        'model_norm' => 'zstopper',
    ],
];

$activeTab = $_GET['tab'] ?? 'MEM-IR-BASE';
if (!isset($holdingTabs[$activeTab])) {
    $activeTab = 'MEM-IR-BASE';
}
$tab = $holdingTabs[$activeTab];

$state = [
    'rows' => [],
    'year' => null,
    'empty_message' => '',
];

try {
    $pdo = dp_get_pdo();
    $headerCols = table_columns($pdo, 'oqc_header');
    $hasResultHeader = table_exists($pdo, 'oqc_result_header');
    $hasMeasurements = table_exists($pdo, 'oqc_measurements');
    $hasIgnoreTable = table_exists($pdo, 'oqc_ng_ignore_point');

    // 측정일 기준: meas_date / jmeas_date 계열은 날짜가 아니라 납품처 플래그로 쓰일 수 있으므로
    // 홀딩리스트 날짜 축에서는 제외한다. 실제 측정일은 source_file 날짜를 최우선으로 보고,
    // 필요할 때만 일반 날짜성 컬럼으로 느슨하게 fallback 한다.
    $priorityCols = [];
    foreach (['measurement_date', 'measure_date', 'measured_date', 'inspect_date', 'inspection_date', 'date', 'lot_date', 'ship_date'] as $col) {
        if (isset($headerCols[$col])) $priorityCols[] = $col;
    }

    $partNameExpr = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(h.part_name,'-',''),'_',''),' ',''),'.',''))";
    $cond = build_model_condition($partNameExpr, $tab['model_norm']);

    $selectCols = ['h.id', 'h.part_name', 'h.tool_cavity', 'h.source_file'];
    foreach ($priorityCols as $col) {
        $selectCols[] = "h.`{$col}`";
    }

    $headerSql = "SELECT " . implode(', ', $selectCols) . " FROM oqc_header h WHERE {$cond['sql']} ORDER BY h.id ASC";
    $stmtHeaders = $pdo->prepare($headerSql);
    $stmtHeaders->execute($cond['params']);
    $allHeaders = $stmtHeaders->fetchAll(PDO::FETCH_ASSOC);

    $headersById = [];
    $dates = [];
    foreach ($allHeaders as $row) {
        $eventDate = pick_header_date($row, $priorityCols);
        if ($eventDate === null) continue;
        $row['event_date'] = $eventDate;
        $headersById[(int)$row['id']] = $row;
        $dates[] = $eventDate;
    }

    if (!$headersById) {
        $state['empty_message'] = '표시할 OQC 데이터가 없습니다.';
    } else {
        rsort($dates);
        $latestDate = $dates[0];
        $targetYear = substr($latestDate, 0, 4);
        $state['year'] = $targetYear;

        foreach ($headersById as $id => $row) {
            if (substr($row['event_date'], 0, 4) !== $targetYear) {
                unset($headersById[$id]);
            }
        }

        if (!$headersById) {
            $state['empty_message'] = '표시할 OQC 데이터가 없습니다.';
        } else {
            $ignorePoints = [];
            if ($hasIgnoreTable) {
                try {
                    $modelExpr = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(model_name,'-',''),'_',''),' ',''),'.',''))";
                    $sqlIgnore = "SELECT point_no FROM oqc_ng_ignore_point WHERE enabled = 1 AND {$modelExpr} = :model_norm";
                    $stmtIgnore = $pdo->prepare($sqlIgnore);
                    $stmtIgnore->execute([':model_norm' => $tab['model_norm']]);
                    foreach ($stmtIgnore->fetchAll(PDO::FETCH_ASSOC) as $ignoreRow) {
                        $ignorePoints[(string)$ignoreRow['point_no']] = true;
                    }
                } catch (Throwable $e) {
                }
            }

            $headersByDate = [];
            $dateMin = null;
            $dateMax = null;
            foreach ($headersById as $id => $row) {
                $date = $row['event_date'];
                $headersByDate[$date][$id] = $row;
                if ($dateMin === null || $date < $dateMin) $dateMin = $date;
                if ($dateMax === null || $date > $dateMax) $dateMax = $date;
            }

            $ngRowsByDate = [];
            if ($hasResultHeader && $hasMeasurements) {
                $headerIds = array_keys($headersById);
                $chunks = array_chunk($headerIds, 500);
                foreach ($chunks as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $sqlNg = "
                        SELECT
                            r.header_id,
                            r.point_no,
                            r.usl,
                            r.lsl,
                            m.value AS measured_value
                        FROM oqc_result_header r
                        LEFT JOIN oqc_measurements m
                          ON m.header_id = r.header_id
                         AND m.point_no = r.point_no
                        WHERE r.header_id IN ({$ph})
                          AND r.result_ok = 0
                        ORDER BY r.header_id ASC, r.point_no ASC
                    ";
                    $stmtNg = $pdo->prepare($sqlNg);
                    $stmtNg->execute($chunk);
                    foreach ($stmtNg->fetchAll(PDO::FETCH_ASSOC) as $ng) {
                        $pointNo = (string)($ng['point_no'] ?? '');
                        if ($pointNo !== '' && (isset($ignorePoints[$pointNo]) || is_dc_point($pointNo))) {
                            continue;
                        }
                        $headerId = (int)$ng['header_id'];
                        if (!isset($headersById[$headerId])) continue;
                        $header = $headersById[$headerId];
                        $side = classify_ng_side($ng);
                        [$tool, $cav] = parse_tool_cavity((string)($header['tool_cavity'] ?? ''));
                        $date = $header['event_date'];
                        $ngRowsByDate[$date][] = [
                            'state' => 'ng',
                            'product_name' => $tab['product_name'],
                            'lot_label' => lot_label($date),
                            'tool' => $tool,
                            'cav' => $cav,
                            'side' => $side,
                            'fai' => $pointNo,
                            'usl' => fmt_display_value($ng['usl'] ?? ''),
                            'lsl' => fmt_display_value($ng['lsl'] ?? ''),
                            'measured' => fmt_display_value($ng['measured_value'] ?? ''),
                        ];
                    }
                }
            }

            $renderRows = [];
            if ($dateMin !== null && $dateMax !== null) {
                foreach (daterange_inclusive($dateMin, $dateMax) as $date) {
                    if (!empty($ngRowsByDate[$date])) {
                        foreach ($ngRowsByDate[$date] as $row) {
                            $renderRows[] = $row;
                        }
                        continue;
                    }

                    $hasAnyFile = !empty($headersByDate[$date]);
                    $renderRows[] = [
                        'state' => $hasAnyFile ? 'ok' : 'idle',
                        'product_name' => $tab['product_name'],
                        'lot_label' => lot_label($date),
                        'tool' => '',
                        'cav' => '',
                    ];
                }
            }

            $state['rows'] = $renderRows;
            if (!$renderRows) {
                $state['empty_message'] = '표시할 OQC 데이터가 없습니다.';
            }
        }
    }
} catch (PDOException $e) {
    $state['empty_message'] = 'DB 접속 실패: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OQC 홀딩리스트</title>
<style>
:root{
    --bg:#202124;
    --card:#2b2b2b;
    --card-border:#4a4d55;
    --card-shadow:0 14px 28px rgba(0,0,0,.28), 0 10px 10px rgba(0,0,0,.18);
    --title:#f2f4f7;
    --folder:#535d6c;
    --folder-border:#77808e;
    --folder-text:#f4f6fa;
    --folder-active:#3b9857;
    --folder-active-border:#5ebf79;
    --table-bg:#f6f6f6;
    --th-bg:#ece8e2;
    --th-bg-2:#f3eee8;
    --cell-bg:#ffffff;
    --oqc-bg:#ffffff;
    --ng-bg:#FDF2ED;
    --ok-bg:#CFE9CF;
    --idle-bg:#f5f5f5;
    --grid:#2d2d2d;
    --text:#111;
}
*{box-sizing:border-box;}
html,body{margin:0; min-height:100%;}
body{
    font-family:Arial, "Malgun Gothic", "맑은 고딕", sans-serif;
    background:<?= $EMBED ? 'transparent' : 'var(--bg)' ?>;
    color:var(--title);
    <?= $EMBED ? '' : 'padding-left:72px;' ?>
}
.main-shell{
    width:min(980px, calc(100vw - 132px));
    margin:34px auto 44px;
}
.page-title{
    margin:0 0 10px;
    font-size:20px;
    line-height:1.15;
    font-weight:800;
    color:var(--title);
    letter-spacing:-.02em;
}
.folder-tabs{
    display:flex;
    align-items:flex-end;
    gap:0;
    padding-left:8px;
    margin:0 0 -1px;
}
.folder-tab{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:27px;
    padding:0 15px;
    margin-right:2px;
    border:1px solid var(--folder-border);
    border-bottom:none;
    border-radius:6px 6px 0 0;
    background:linear-gradient(to bottom, #5e6877 0%, #4f5866 100%);
    color:var(--folder-text);
    text-decoration:none;
    font-size:11px;
    font-weight:800;
    line-height:1;
    letter-spacing:.01em;
    box-shadow:0 -1px 0 rgba(255,255,255,.08) inset;
}
.folder-tab.active{
    background:linear-gradient(to bottom, var(--folder-active) 0%, #2f8448 100%);
    border-color:var(--folder-active-border);
    color:#fff;
    position:relative;
    z-index:2;
}
.viewer-card{
    background:var(--card);
    border:1px solid var(--card-border);
    border-radius:12px;
    box-shadow:var(--card-shadow);
    padding:12px;
}
.viewer-surface{
    background:#323232;
    border:1px solid rgba(255,255,255,.08);
    border-radius:10px;
    padding:10px;
    overflow:auto;
}
.holding-table{
    width:100%;
    border-collapse:collapse;
    background:var(--table-bg);
    color:var(--text);
    table-layout:fixed;
}
.holding-table col.c-product{width:92px;}
.holding-table col.c-lot{width:102px;}
.holding-table col.c-tool{width:40px;}
.holding-table col.c-cav{width:34px;}
.holding-table col.c-oqc{width:70px;}
.holding-table col.c-qty{width:54px;}
.holding-table th,
.holding-table td{
    border:1px solid var(--grid);
    padding:3px 6px;
    font-size:12px;
    line-height:1.35;
    text-align:center;
    vertical-align:middle;
    background:var(--cell-bg);
}
.holding-table thead th{
    background:var(--th-bg);
    font-weight:700;
}
.holding-table thead tr:first-child th.oqc-span{
    background:var(--th-bg);
}
.holding-table thead tr:last-child th{
    background:var(--th-bg-2);
}
.holding-table td.product,
.holding-table td.lot,
.holding-table td.tool,
.holding-table td.cav,
.holding-table td.qty{
    background:#fff;
}
.holding-table td.ng-cell{
    background:var(--ng-bg);
}
.holding-table td.blank-oqc{
    background:#fff;
}
.holding-table td.state-cell{
    font-weight:400;
    font-size:13px;
}
.holding-table td.state-ok{
    background:var(--ok-bg);
    color:#0a7c2b;
}
.holding-table td.state-idle{
    background:var(--idle-bg);
    color:#111;
}
.empty-box{
    min-height:200px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    color:#d6d9dd;
    font-size:14px;
    padding:24px;
}

@media (max-width:1100px){
    .main-shell{
        width:calc(100vw - 92px);
    }
}
@media (max-width:860px){
    .folder-tabs{overflow-x:auto; padding-bottom:2px;}
    .folder-tab{flex:0 0 auto;}
    .main-shell{width:calc(100vw - 84px);}
}
</style>
</head>
<body>
<?php if (!$EMBED): ?>
<?php echo dp_sidebar_render('oqc_holdinglist'); ?>
<div class="dp-shell-wrap" style="height:auto; min-height:100vh; padding-left:72px; position:relative; z-index:20; display:block;">
  <?php echo dp_render_userbar([
      'admin_badge_mode' => 'modal',
      'admin_iframe_src' => 'admin_settings',
      'logout_action' => 'logout'
  ]); ?>
<?php endif; ?>

<div class="main-shell">
    <h1 class="page-title">OQC 홀딩리스트</h1>

    <div class="folder-tabs" role="tablist" aria-label="OQC 홀딩리스트 모델 탭">
        <?php foreach ($holdingTabs as $key => $cfg): ?>
            <?php
                $query = ['tab' => $key];
                if ($EMBED) $query['embed'] = '1';
                $href = '?' . http_build_query($query);
            ?>
            <a class="folder-tab <?= $key === $activeTab ? 'active' : '' ?>" href="<?= h($href) ?>" role="tab" aria-selected="<?= $key === $activeTab ? 'true' : 'false' ?>">
                <?= h($cfg['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="viewer-card">
        <div class="viewer-surface">
            <?php if ($state['empty_message'] !== ''): ?>
                <div class="empty-box"><?= h($state['empty_message']) ?></div>
            <?php else: ?>
            <table class="holding-table" aria-label="OQC 홀딩리스트 엑셀형 뷰어">
                <colgroup>
                    <col class="c-product">
                    <col class="c-lot">
                    <col class="c-tool">
                    <col class="c-cav">
                    <col class="c-oqc">
                    <col class="c-oqc">
                    <col class="c-oqc">
                    <col class="c-oqc">
                    <col class="c-oqc">
                    <col class="c-oqc">
                    <col class="c-qty">
                </colgroup>
                <thead>
                    <tr>
                        <th rowspan="2">제품명</th>
                        <th rowspan="2">Lot</th>
                        <th rowspan="2">Tool</th>
                        <th rowspan="2">Cav</th>
                        <th colspan="6" class="oqc-span">OQC</th>
                        <th rowspan="2">격리<br>수량</th>
                    </tr>
                    <tr>
                        <th>FAI</th>
                        <th>USL</th>
                        <th>측정값</th>
                        <th>FAI</th>
                        <th>LSL</th>
                        <th>측정값</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($state['rows'] as $row): ?>
                    <tr>
                        <td class="product"><?= h($row['product_name']) ?></td>
                        <td class="lot"><?= h($row['lot_label']) ?></td>
                        <td class="tool"><?= h($row['tool'] ?? '') ?></td>
                        <td class="cav"><?= h($row['cav'] ?? '') ?></td>

                        <?php if (($row['state'] ?? '') === 'ok'): ?>
                            <td colspan="6" class="state-cell state-ok">OK</td>
                        <?php elseif (($row['state'] ?? '') === 'idle'): ?>
                            <td colspan="6" class="state-cell state-idle">비가동</td>
                        <?php else: ?>
                            <?php if (($row['side'] ?? '') === 'USL'): ?>
                                <td class="ng-cell"><?= h($row['fai'] ?? '') ?></td>
                                <td class="ng-cell"><?= h($row['usl'] ?? '') ?></td>
                                <td class="ng-cell"><?= h($row['measured'] ?? '') ?></td>
                                <td class="blank-oqc"></td>
                                <td class="blank-oqc"></td>
                                <td class="blank-oqc"></td>
                            <?php else: ?>
                                <td class="blank-oqc"></td>
                                <td class="blank-oqc"></td>
                                <td class="blank-oqc"></td>
                                <td class="ng-cell"><?= h($row['fai'] ?? '') ?></td>
                                <td class="ng-cell"><?= h($row['lsl'] ?? '') ?></td>
                                <td class="ng-cell"><?= h($row['measured'] ?? '') ?></td>
                            <?php endif; ?>
                        <?php endif; ?>

                        <td class="qty"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$EMBED): ?>
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
</div>
<?php endif; ?>
</body>
</html>
