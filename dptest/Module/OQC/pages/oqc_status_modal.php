<?php
// OQC 현황표 : 모델별 Tool/Cavity 잔량 현황
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }

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
    function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

function oqc_status_table_columns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower((string)$r['Field'])] = true;
        }
    } catch (Throwable $e) {}
    return $cols;
}

function oqc_status_model_key(string $part): string
{
    $s = strtoupper($part);
    $s = str_replace(['_', '-', ' '], '', $s);
    if (strpos($s, 'IRBASE') !== false || $s === 'IR') return 'MEM-IR-BASE';
    if (strpos($s, 'XCARRIER') !== false || $s === 'XC') return 'MEM-X-CARRIER';
    if (strpos($s, 'YCARRIER') !== false || $s === 'YC') return 'MEM-Y-CARRIER';
    if (strpos($s, 'ZCARRIER') !== false || $s === 'ZC') return 'MEM-Z-CARRIER';
    if (strpos($s, 'ZSTOPPER') !== false || $s === 'ZS') return 'MEM-Z-STOPPER';
    return $part;
}

function oqc_status_parse_tc(string $tc): array
{
    $raw = strtoupper(trim($tc));
    $tool = '';
    $cavity = '';

    if (preg_match('/([A-Z]+)\s*(?:#|\-|_|\/|\s)?\s*([1-4])\b/u', $raw, $m)) {
        $tool = $m[1];
        $cavity = $m[2];
    } elseif (preg_match('/([A-Z]+).*?([1-4])CAV/u', $raw, $m)) {
        $tool = $m[1];
        $cavity = $m[2];
    } elseif (preg_match('/TOOL\s*([A-Z]+).*?CAV(?:ITY)?\s*([1-4])/u', $raw, $m)) {
        $tool = $m[1];
        $cavity = $m[2];
    }

    return [$tool !== '' ? $tool : $raw, $cavity !== '' ? $cavity : '1'];
}

function oqc_status_is_empty_date($v): bool
{
    $s = trim((string)$v);
    return $s === '' || $s === '0000-00-00';
}

function oqc_status_in_placeholders(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

function oqc_status_fmt_value($v): string
{
    if ($v === null || $v === '') return '';
    $s = trim((string)$v);
    if ($s === '') return '';
    if (is_numeric($s)) return number_format((float)$s, 4, '.', '');
    return $s;
}

function oqc_status_ng_direction($value, $usl, $lsl): string
{
    $v = trim((string)$value);
    if ($v !== '' && is_numeric($v)) {
        $fv = (float)$v;
        if ($usl !== null && $usl !== '' && is_numeric((string)$usl) && $fv > (float)$usl) return 'USL 초과';
        if ($lsl !== null && $lsl !== '' && is_numeric((string)$lsl) && $fv < (float)$lsl) return 'LSL 미달';
    }
    return 'NG';
}


function oqc_status_ng_ignore_point_norm(string $point): string
{
    $s = strtoupper(trim($point));
    $s = str_replace(['–', '—', '－', '‑'], '-', $s);
    $s = preg_replace('/\s+/u', '', $s);
    return $s ?? '';
}

function oqc_status_is_ng_ignored_point(string $modelKey, string $point): bool
{
    if ($modelKey !== 'MEM-Z-CARRIER') return false;

    static $ignore = null;
    if ($ignore === null) {
        $ignore = array_fill_keys([
            '1-1', '1-2', '1-3', '1-4',
            '99-V1', '100-V1', '101-V1', '102-V1',
            '99-V2', '100-V2', '101-V2', '102-V2',
            '105-V3', '106-V3', '107-V3', '108-V3',
            '105-V4', '106-V4', '107-V4', '108-V4',
        ], true);
    }

    return isset($ignore[oqc_status_ng_ignore_point_norm($point)]);
}

function oqc_status_tool_cavity_order(array $tools): array
{
    $ordered = [];
    foreach ($tools as $tool) {
        $key = strtoupper(trim((string)$tool));
        if ($key === '') continue;
        if (!isset($ordered[$key])) $ordered[$key] = (string)$tool;
    }

    $result = array_values($ordered);
    usort($result, static function($a, $b) {
        return strnatcasecmp((string)$a, (string)$b);
    });

    return $result;
}

try {
    $pdo = dp_get_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="background:#202124;color:#ffd1d1;font-family:sans-serif;padding:20px">DB 접속 실패: ' . h($e->getMessage()) . '</body>';
    exit;
}

$customer = $_GET['customer'] ?? 'LG';
if ($customer !== 'LG' && $customer !== 'JH') $customer = 'LG';
$customerLabel = ($customer === 'JH') ? '자화전자(주)' : '엘지이노텍(주)';
$measKey1 = ($customer === 'JH') ? 'jmeas_date' : 'meas_date';

$kindFilter = strtoupper(trim((string)($_GET['kind'] ?? 'ALL')));
if (!in_array($kindFilter, ['ALL','FAI','SPC'], true)) $kindFilter = 'ALL';
$kindLabelMap = ['ALL' => 'ALL', 'FAI' => 'FAI', 'SPC' => 'SPC'];
$kindSql = '';
if ($kindFilter === 'FAI') {
    $kindSql = "AND UPPER(TRIM(COALESCE(h.kind,''))) = 'FAI'";
} elseif ($kindFilter === 'SPC') {
    $kindSql = "AND UPPER(TRIM(COALESCE(h.kind,''))) = 'SPC'";
}

$rangeDays = (int)($_GET['days'] ?? 7);
if ($rangeDays < 7) $rangeDays = 7;
if ($rangeDays > 730) $rangeDays = 730;
$fromDate = date('Y-m-d', strtotime('-' . $rangeDays . ' days'));

$models = [
    'MEM-IR-BASE' => ['label' => 'MEM-IR-BASE', 'aliases' => ['IR BASE','IR-BASE','IRBASE','MEM-IR-BASE']],
    'MEM-X-CARRIER' => ['label' => 'MEM-X-CARRIER', 'aliases' => ['X CARRIER','X-CARRIER','XCARRIER','MEM-X-CARRIER']],
    'MEM-Y-CARRIER' => ['label' => 'MEM-Y-CARRIER', 'aliases' => ['Y CARRIER','Y-CARRIER','YCARRIER','MEM-Y-CARRIER']],
    'MEM-Z-CARRIER' => ['label' => 'MEM-Z-CARRIER', 'aliases' => ['Z CARRIER','Z-CARRIER','ZCARRIER','MEM-Z-CARRIER']],
    'MEM-Z-STOPPER' => ['label' => 'MEM-Z-STOPPER', 'aliases' => ['Z STOPPER','Z-STOPPER','ZSTOPPER','MEM-Z-STOPPER']],
];

$headerCols = oqc_status_table_columns($pdo, 'oqc_header');
$resultCols = oqc_status_table_columns($pdo, 'oqc_result_header');
$measurementCols = oqc_status_table_columns($pdo, 'oqc_measurements');
$hasShipDate = isset($headerCols['ship_date']);
$dateExpr = $hasShipDate ? "COALESCE(NULLIF(h.ship_date,''), h.lot_date)" : "h.lot_date";
$selectMeas1 = isset($headerCols[$measKey1]) ? ", h.`{$measKey1}` AS status_date1" : ", NULL AS status_date1";
$slotSql = isset($headerCols[$measKey1])
    ? "(h.`{$measKey1}` IS NULL OR h.`{$measKey1}` = '' OR h.`{$measKey1}` = '0000-00-00')"
    : '1=0';

$sql = "
    SELECT
        h.id,
        h.part_name,
        h.tool_cavity,
        h.kind,
        h.source_file,
        h.excel_col,
        {$dateExpr} AS data_date
        {$selectMeas1}
    FROM oqc_header h
    WHERE {$dateExpr} IS NOT NULL
      AND {$dateExpr} <> ''
      AND {$dateExpr} >= :from_date
      AND {$slotSql}
      {$kindSql}
    ORDER BY h.part_name, h.tool_cavity, {$dateExpr}, h.excel_col
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':from_date' => $fromDate]);
$headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ngByHeader = [];
$headerIds = array_map(static fn($r) => (int)$r['id'], $headers);
$headerIds = array_values(array_unique(array_filter($headerIds)));

$headerModelById = [];
foreach ($headers as $headerRowForModel) {
    $headerModelById[(int)$headerRowForModel['id']] = oqc_status_model_key((string)$headerRowForModel['part_name']);
}

// 상세 모달용 NG 정보만 조회한다.
// 전체 oqc_measurements를 미리 fetchAll 하면 데이터가 많은 경우 메모리가 터지므로 금지.
if ($headerIds && $resultCols) {
    $resultValueCol = null;
    foreach (['value','measured_value','measure_value','meas_value','result_value','actual_value'] as $candidateCol) {
        if (isset($resultCols[$candidateCol])) { $resultValueCol = $candidateCol; break; }
    }

    foreach (array_chunk($headerIds, 700) as $chunk) {
        $in = oqc_status_in_placeholders(count($chunk));
        try {
            if (isset($resultCols['point_no'])) {
                $selectParts = ['header_id', 'point_no'];
                foreach (['spc_code','usl','lsl','row_index'] as $colName) {
                    if (isset($resultCols[$colName])) $selectParts[] = $colName;
                }
                if ($resultValueCol) $selectParts[] = $resultValueCol . ' AS result_value';
                $selectSql = '`' . implode('`,`', $selectParts) . '`';
                $selectSql = str_replace('`' . $resultValueCol . ' AS result_value`', '`' . $resultValueCol . '` AS result_value', $selectSql);

                $ngSql = "
                    SELECT {$selectSql}
                    FROM oqc_result_header
                    WHERE header_id IN ($in)
                      AND result_ok = 0
                      AND (point_no IS NULL OR point_no NOT LIKE '%(DC)%')
                    ORDER BY header_id, point_no
                ";
                $ngStmt = $pdo->prepare($ngSql);
                $ngStmt->execute($chunk);
                while (($r = $ngStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $hid = (int)$r['header_id'];
                    $point = trim((string)($r['point_no'] ?? ''));
                    if ($point === '') $point = '포인트 정보 없음';
                    if (oqc_status_is_ng_ignored_point((string)($headerModelById[$hid] ?? ''), $point)) continue;
                    $spc = trim((string)($r['spc_code'] ?? ''));
                    $usl = $r['usl'] ?? null;
                    $lsl = $r['lsl'] ?? null;
                    $value = $r['result_value'] ?? null;
                    $direction = oqc_status_ng_direction($value, $usl, $lsl);

                    if (!isset($ngByHeader[$hid])) $ngByHeader[$hid] = ['count' => 0, 'points' => [], 'details' => []];
                    $ngByHeader[$hid]['points'][$point] = true;
                    $detailKey = $point . '|' . $spc . '|' . oqc_status_fmt_value($usl) . '|' . oqc_status_fmt_value($lsl) . '|' . oqc_status_fmt_value($value) . '|' . $direction;
                    $ngByHeader[$hid]['details'][$detailKey] = [
                        'point_no' => $point,
                        'spc_code' => $spc,
                        'usl' => oqc_status_fmt_value($usl),
                        'lsl' => oqc_status_fmt_value($lsl),
                        'value' => oqc_status_fmt_value($value),
                        'direction' => $direction,
                    ];
                }
            } else {
                $ngSql = "
                    SELECT header_id, COUNT(*) AS ng_count
                    FROM oqc_result_header
                    WHERE header_id IN ($in)
                      AND result_ok = 0
                    GROUP BY header_id
                ";
                $ngStmt = $pdo->prepare($ngSql);
                $ngStmt->execute($chunk);
                while (($r = $ngStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $hid = (int)$r['header_id'];
                    $ngByHeader[$hid] = ['count' => (int)$r['ng_count'], 'points' => [], 'details' => []];
                }
            }
        } catch (Throwable $e) {}
    }
    // result_header에 측정값 컬럼이 없는 경우에만 NG 포인트의 값만 보강한다.
    // header 전체 측정값을 읽지 않고, NG로 판정된 point_no만 제한 조회한다.
    if ($ngByHeader && (!$resultValueCol) && $measurementCols && isset($measurementCols['point_no']) && isset($measurementCols['value'])) {
        foreach (array_chunk(array_keys($ngByHeader), 250) as $hidChunk) {
            $points = [];
            foreach ($hidChunk as $hidForPoint) {
                foreach (($ngByHeader[$hidForPoint]['details'] ?? []) as $detailRow) {
                    $pointForQuery = trim((string)($detailRow['point_no'] ?? ''));
                    if ($pointForQuery !== '' && $pointForQuery !== '포인트 정보 없음') $points[$pointForQuery] = true;
                }
            }
            $points = array_keys($points);
            if (!$points) continue;

            foreach (array_chunk($points, 250) as $pointChunk) {
                $hidIn = oqc_status_in_placeholders(count($hidChunk));
                $pointIn = oqc_status_in_placeholders(count($pointChunk));
                $measSelect = ['header_id', 'point_no', 'value'];
                if (isset($measurementCols['spc_code'])) $measSelect[] = 'spc_code';
                $measSql = "SELECT `" . implode('`,`', $measSelect) . "` FROM oqc_measurements WHERE header_id IN ($hidIn) AND point_no IN ($pointIn)";
                try {
                    $measStmt = $pdo->prepare($measSql);
                    $measStmt->execute(array_merge($hidChunk, $pointChunk));
                    while (($mr = $measStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $mh = (int)($mr['header_id'] ?? 0);
                        $mp = trim((string)($mr['point_no'] ?? ''));
                        if (!$mh || $mp === '' || empty($ngByHeader[$mh]['details'])) continue;

                        foreach ($ngByHeader[$mh]['details'] as &$detailRef) {
                            if (trim((string)($detailRef['point_no'] ?? '')) !== $mp) continue;
                            if (($detailRef['value'] ?? '') === '') {
                                $detailRef['value'] = oqc_status_fmt_value($mr['value'] ?? null);
                            }
                            if (($detailRef['spc_code'] ?? '') === '' && isset($mr['spc_code'])) {
                                $detailRef['spc_code'] = trim((string)$mr['spc_code']);
                            }
                            $detailRef['direction'] = oqc_status_ng_direction($detailRef['value'] ?? null, $detailRef['usl'] ?? null, $detailRef['lsl'] ?? null);
                        }
                        unset($detailRef);
                    }
                } catch (Throwable $e) {}
            }
        }
    }

    foreach ($ngByHeader as $hid => &$ngInfo) {
        if (isset($ngInfo['points']) && is_array($ngInfo['points']) && $ngInfo['points']) {
            $ngInfo['count'] = count($ngInfo['points']);
            $ngInfo['points'] = array_keys($ngInfo['points']);
        } else {
            $ngInfo['points'] = [];
            $ngInfo['count'] = (int)($ngInfo['count'] ?? 0);
        }
        if (isset($ngInfo['details']) && is_array($ngInfo['details'])) {
            $ngInfo['details'] = array_values($ngInfo['details']);
        } else {
            $ngInfo['details'] = [];
        }
    }
    unset($ngInfo);
}

$data = [];
foreach ($models as $mk => $meta) {
    $data[$mk] = ['tools' => [], 'total' => 0, 'ng' => 0, 'usable' => 0];
}

foreach ($headers as $r) {
    $modelKey = oqc_status_model_key((string)$r['part_name']);
    if (!isset($data[$modelKey])) continue;

    [$tool, $cav] = oqc_status_parse_tc((string)$r['tool_cavity']);
    if ($tool === '') continue;
    if (!in_array($cav, ['1','2','3','4'], true)) $cav = '1';

    $date = substr((string)$r['data_date'], 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

    $hid = (int)$r['id'];
    $ngInfo = $ngByHeader[$hid] ?? ['count' => 0, 'points' => []];
    $ngPoints = (int)($ngInfo['count'] ?? 0);
    $ngPointList = is_array($ngInfo['points'] ?? null) ? $ngInfo['points'] : [];
    $ngDetailList = is_array($ngInfo['details'] ?? null) ? $ngInfo['details'] : [];
    $ngFlag = $ngPoints > 0 ? 1 : 0;
    $slotText = $measKey1;

    if (!isset($data[$modelKey]['tools'][$tool])) {
        $data[$modelKey]['tools'][$tool] = [
            'cavs' => ['1'=>[], '2'=>[], '3'=>[], '4'=>[]],
            'ng' => 0,
            'total' => 0,
        ];
    }

    $dateKey = $date;
    if (!isset($data[$modelKey]['tools'][$tool]['cavs'][$cav][$dateKey])) {
        $data[$modelKey]['tools'][$tool]['cavs'][$cav][$dateKey] = [
            'date' => $date,
            'ng' => 0,
            'ng_headers' => 0,
            'ng_points' => 0,
            'ng_point_list' => [],
            'ng_detail_list' => [],
            'slot' => $slotText,
            'headers' => 0,
            'kind' => [],
        ];
    }
    $cell =& $data[$modelKey]['tools'][$tool]['cavs'][$cav][$dateKey];
    $cell['ng'] = max((int)$cell['ng'], $ngFlag);
    $cell['ng_headers'] += $ngFlag;
    $cell['ng_points'] += $ngPoints;
    if ($ngPointList) {
        foreach ($ngPointList as $pointName) {
            $pointName = trim((string)$pointName);
            if ($pointName !== '') $cell['ng_point_list'][$pointName] = true;
        }
    }
    if ($ngDetailList) {
        foreach ($ngDetailList as $detailRow) {
            if (is_array($detailRow)) $cell['ng_detail_list'][] = $detailRow;
        }
    }
    $cell['headers']++;
    if (!empty($r['kind'])) $cell['kind'][(string)$r['kind']] = true;
    unset($cell);

    $data[$modelKey]['tools'][$tool]['ng'] += $ngFlag;
    $data[$modelKey]['tools'][$tool]['total']++;
    $data[$modelKey]['total']++;
    $data[$modelKey]['ng'] += $ngFlag;
    if ($ngFlag <= 0) $data[$modelKey]['usable']++;
}

foreach ($data as $mk => &$modelData) {
    uksort($modelData['tools'], static function($a, $b) {
        $la = strlen((string)$a); $lb = strlen((string)$b);
        if ($la === $lb) return strnatcasecmp((string)$a, (string)$b);
        return $la <=> $lb;
    });
    foreach ($modelData['tools'] as &$toolData) {
        foreach ($toolData['cavs'] as &$cells) {
            uasort($cells, static function($a, $b) {
                $cmp = strcmp((string)$a['date'], (string)$b['date']);
                if ($cmp !== 0) return $cmp;
                return ((int)($b['ng'] ?? 0)) <=> ((int)($a['ng'] ?? 0));
            });
        }
    }
}
unset($modelData, $toolData, $cells);

$initialModel = $_GET['model'] ?? 'MEM-IR-BASE';
if (!isset($models[$initialModel])) $initialModel = 'MEM-IR-BASE';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>OQC 현황표</title>
<style>
:root{
  --bg:#202124; --card:#2b2b2b; --fg:#e8eaed; --muted:#9aa0a6;
  --border:rgba(255,255,255,.14); --accent:#1db954; --danger:#e85d5d;
}
*{box-sizing:border-box;}
html,body{margin:0; min-height:100%; background:var(--bg); color:var(--fg); font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; font-size:13px;}
body{padding:<?= $EMBED ? '0' : '18px' ?>;}
.dp-page{<?= $EMBED ? '' : 'padding-left:72px;' ?> min-height:100vh;}
.status-wrap{width:100%; min-height:100vh; padding:18px;}
.status-head{display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:12px;}
.status-title h1{font-size:22px; margin:0 0 6px;}
.status-title .sub{color:var(--muted); font-size:12px;}
.status-controls{display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:flex-end;}
.status-controls select,.status-controls input{height:34px; border-radius:10px; border:1px solid var(--border); background:#17191d; color:var(--fg); padding:0 10px;}
.status-controls button{height:34px; border-radius:10px; border:1px solid rgba(29,185,84,.55); background:rgba(29,185,84,.18); color:var(--fg); padding:0 12px; font-weight:700; cursor:pointer;}
.kind-filter{display:inline-flex; align-items:center; gap:6px; height:34px; border:1px solid var(--border); border-radius:10px; background:#17191d; padding:0 8px;}
.kind-filter span{font-weight:700; color:#dbe3ee; margin-right:2px;}
.kind-filter label{display:inline-flex; align-items:center; gap:4px; height:24px; padding:0 6px; border-radius:8px; cursor:pointer; color:#cbd5e1; font-weight:800;}
.kind-filter label.is-checked{background:rgba(29,185,84,.20); color:#fff;}
.kind-filter input{width:13px; height:13px; margin:0; accent-color:var(--accent);}
.model-tabs{display:flex; gap:6px; border-bottom:1px solid var(--border); margin-bottom:12px; overflow-x:auto;}
.model-tab{appearance:none; border:1px solid var(--border); border-bottom:0; background:#24272d; color:#cbd5e1; padding:9px 14px; border-radius:12px 12px 0 0; cursor:pointer; font-weight:800; white-space:nowrap;}
.model-tab.active{background:#303134; color:#fff; box-shadow:inset 0 2px 0 var(--accent);}
.model-panel{display:none;}
.model-panel.active{display:block;}
.summary-row{display:flex; gap:10px; flex-wrap:wrap; margin:0 0 12px;}
.summary-card{background:#25282e; border:1px solid var(--border); border-radius:14px; padding:10px 12px; min-width:130px; box-shadow:0 8px 18px rgba(0,0,0,.22);}
.summary-card .label{color:var(--muted); font-size:11.5px; margin-bottom:4px;}
.summary-card .value{font-size:18px; font-weight:900;}
.toolcav-card{background:#25282e; border:1px solid var(--border); border-radius:14px; padding:10px 12px; margin:0 0 12px; box-shadow:0 8px 18px rgba(0,0,0,.20);}
.toolcav-card-title{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; color:#dbe3ee; font-weight:900;}
.toolcav-card-title small{color:var(--muted); font-weight:700;}
.toolcav-list{display:flex; gap:6px; flex-wrap:wrap;}
.toolcav-chip{display:inline-flex; align-items:center; gap:6px; min-height:28px; border:1px solid rgba(255,255,255,.13); border-radius:9px; background:#1f2227; color:#edf2f7; padding:4px 8px; cursor:default;}
.toolcav-chip strong{font-size:12px; font-weight:900;}
.toolcav-chip em{font-style:normal; color:#a7f3d0; font-weight:900;}
.toolcav-chip.is-zero em{color:#ffb4b4;}
.excel-scroll{overflow:auto; border:1px solid var(--border); border-radius:14px; background:#f7f7f7; max-height:calc(100vh - 270px);}
.oqc-grid{border-collapse:collapse; color:#101418; background:#fff; width:max-content; min-width:100%; font-size:12px;}
.oqc-grid th,.oqc-grid td{border:1px solid #d7dce2; min-width:82px; height:24px; padding:3px 6px; text-align:center; white-space:nowrap;}
.oqc-grid thead th{background:#f2f4f7; color:#111827; font-weight:900; position:sticky; top:0; z-index:2;}
.oqc-grid thead tr:nth-child(2) th{top:25px; z-index:2; font-weight:800;}
.oqc-grid .tool-head{background:#ffffff; font-size:13px;}
.oqc-grid .date-cell{background:#fff; color:#111827;}
.oqc-grid .date-cell.ng{color:#d3352f; font-weight:900;}
.status-tooltip{position:fixed; z-index:99999; max-width:360px; pointer-events:none; background:#111827; color:#f9fafb; border:1px solid rgba(255,255,255,.22); border-radius:10px; padding:9px 11px; font-size:12px; line-height:1.45; box-shadow:0 14px 32px rgba(0,0,0,.35); white-space:pre-line; display:none;}
.status-tooltip strong{display:block; margin-bottom:4px; color:#ffb4b4;}
.oqc-grid .ng-note{color:#c5352c; font-weight:900; background:#fff7f5; text-align:left;}
.oqc-grid .blank{background:#fff;}
.empty-state{padding:50px 20px; text-align:center; color:#6b7280; background:#fff; border-radius:14px;}
.badge{display:inline-flex; align-items:center; gap:4px; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:800;}
.badge.ok{background:rgba(29,185,84,.16); color:#8ff5b4;}
.badge.ng{background:rgba(232,93,93,.16); color:#ffb4b4;}
.ng-detail-backdrop{position:fixed; inset:0; z-index:100000; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.58); padding:22px;}
.ng-detail-backdrop.open{display:flex;}
.ng-detail-modal{width:min(960px,96vw); max-height:86vh; display:flex; flex-direction:column; background:#23262b; border:1px solid rgba(255,255,255,.18); border-radius:16px; box-shadow:0 24px 70px rgba(0,0,0,.55); overflow:hidden;}
.ng-detail-head{display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 16px; border-bottom:1px solid rgba(255,255,255,.12); background:#2b2f36;}
.ng-detail-title{font-size:16px; font-weight:900;}
.ng-detail-sub{color:#aeb7c4; font-size:12px; margin-top:3px;}
.ng-detail-close{width:32px; height:32px; border-radius:10px; border:1px solid rgba(255,255,255,.18); background:#3a3f48; color:#fff; font-size:20px; cursor:pointer;}
.ng-detail-body{padding:14px; overflow:auto;}
.ng-detail-table{width:100%; border-collapse:collapse; min-width:720px; font-size:12px; background:#1f2227; color:#f4f6f8;}
.ng-detail-table th,.ng-detail-table td{border:1px solid rgba(255,255,255,.12); padding:8px 10px; text-align:center; white-space:nowrap;}
.ng-detail-table th{background:#30343c; color:#e9edf3; font-weight:900; position:sticky; top:0;}
.ng-detail-table td:first-child{text-align:center; font-weight:800;}
.ng-detail-table .judgement{color:#ff9b9b; font-weight:900;}
.ng-detail-empty{padding:28px; text-align:center; color:#aeb7c4;}
.date-cell.ng{cursor:pointer; text-decoration:underline; text-underline-offset:2px;}
@media (max-width:800px){.status-head{flex-direction:column}.status-controls{justify-content:flex-start}.excel-scroll{max-height:calc(100vh - 260px)}}
</style>
</head>
<body>
<?php if (empty($EMBED)):
echo dp_sidebar_render('oqc');
echo dp_render_userbar(['admin_badge_mode'=>'modal','admin_iframe_src'=>'admin_settings','logout_action'=>'logout']);
endif; ?>
<div class="dp-page">
  <div class="status-wrap">
    <div class="status-head">
      <div class="status-title">
        <h1>OQC 현황표</h1>
        <div class="sub">모델별 Tool / Cavity 사용 가능 예상 데이터 현황 · <?=h($customerLabel)?> 기준 · 종류 <?=h($kindLabelMap[$kindFilter] ?? $kindFilter)?> · 최근 <?=h((string)$rangeDays)?>일</div>
      </div>
      <form class="status-controls" method="get">
        <?php if ($EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>납품처
          <select name="customer">
            <option value="LG" <?= $customer === 'LG' ? 'selected' : '' ?>>엘지이노텍(주)</option>
            <option value="JH" <?= $customer === 'JH' ? 'selected' : '' ?>>자화전자(주)</option>
          </select>
        </label>
        <div class="kind-filter" aria-label="종류">
          <span>종류</span>
          <?php foreach (['ALL','FAI','SPC'] as $k): ?>
            <label class="<?= $kindFilter === $k ? 'is-checked' : '' ?>"><input type="checkbox" name="kind" value="<?=h($k)?>" <?= $kindFilter === $k ? 'checked' : '' ?>><?=h($k)?></label>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="model" value="<?=h($initialModel)?>" id="oqcStatusModelInput">
        <label>기간
          <select name="days">
            <?php foreach ([7,30,60,90,120,180,365,730] as $d): ?>
              <option value="<?=$d?>" <?= $rangeDays === $d ? 'selected' : '' ?>>최근 <?=$d?>일</option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit">새로고침</button>
      </form>
    </div>

    <div class="model-tabs" role="tablist">
      <?php foreach ($models as $mk => $meta): ?>
        <button type="button" class="model-tab <?= $mk === $initialModel ? 'active' : '' ?>" data-model="<?=h($mk)?>"><?=h($meta['label'])?></button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($models as $mk => $meta): ?>
      <?php $modelData = $data[$mk]; ?>
      <section class="model-panel <?= $mk === $initialModel ? 'active' : '' ?>" data-model-panel="<?=h($mk)?>">
        <div class="summary-row">
          <div class="summary-card"><div class="label">사용 가능 예상</div><div class="value"><?=number_format((int)$modelData['usable'])?></div></div>
          <div class="summary-card"><div class="label">전체 후보</div><div class="value"><?=number_format((int)$modelData['total'])?></div></div>
          <div class="summary-card"><div class="label">사용 불가 예상(NG)</div><div class="value" style="color:#ffb4b4"><?=number_format((int)$modelData['ng'])?></div></div>
          <div class="summary-card"><div class="label">Tool 수</div><div class="value"><?=number_format(count($modelData['tools']))?></div></div>
        </div>

        <?php if ($modelData['tools']): ?>
          <?php
            $orderedCardTools = oqc_status_tool_cavity_order(array_keys($modelData['tools']));
            $toolCavSummary = [];
            foreach (['1','2','3','4'] as $cardCav) {
                foreach ($orderedCardTools as $cardTool) {
                    if (!isset($modelData['tools'][$cardTool])) continue;
                    $cardCells = $modelData['tools'][$cardTool]['cavs'][$cardCav] ?? [];
                    $cardTotal = 0;
                    $cardNg = 0;
                    foreach ($cardCells as $cardCell) {
                        $cardTotal += (int)($cardCell['headers'] ?? 0);
                        $cardNg += (int)($cardCell['ng_headers'] ?? 0);
                    }
                    $toolCavSummary[] = [
                        'label' => (string)$cardTool . '#' . $cardCav,
                        'tool' => (string)$cardTool,
                        'cav' => (string)$cardCav,
                        'usable' => max(0, $cardTotal - $cardNg),
                        'ng' => $cardNg,
                        'total' => $cardTotal,
                    ];
                }
            }
            usort($toolCavSummary, static function($a, $b) {
                $ua = (int)($a['usable'] ?? 0);
                $ub = (int)($b['usable'] ?? 0);
                if ($ua !== $ub) return $ua <=> $ub;

                $ta = strtoupper((string)($a['tool'] ?? ''));
                $tb = strtoupper((string)($b['tool'] ?? ''));
                $toolCmp = strnatcasecmp($ta, $tb);
                if ($toolCmp !== 0) return $toolCmp;

                $ca = (int)($a['cav'] ?? 0);
                $cb = (int)($b['cav'] ?? 0);
                if ($ca !== $cb) return $ca <=> $cb;

                return strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
            });
          ?>
          <?php if ($toolCavSummary): ?>
            <div class="toolcav-card">
              <div class="toolcav-card-title">
                <span>Tool#Cavity별 사용 가능 예상</span>
                <small>NG 제외 · 적은 순 · 같은 건수 A-Z / 1-4</small>
              </div>
              <div class="toolcav-list">
                <?php foreach ($toolCavSummary as $tcRow): ?>
                  <?php
                    $tcTip = $tcRow['label'] . "\n"
                        . '사용 가능 예상(NG 제외): ' . number_format((int)$tcRow['usable']) . '건' . "\n"
                        . '사용 불가 예상(NG): ' . number_format((int)$tcRow['ng']) . '건' . "\n"
                        . '전체 후보: ' . number_format((int)$tcRow['total']) . '건';
                  ?>
                  <span class="toolcav-chip <?= (int)$tcRow['usable'] <= 0 ? 'is-zero' : '' ?>" data-tooltip="<?=h($tcTip)?>"><strong><?=h($tcRow['label'])?></strong><em><?=number_format((int)$tcRow['usable'])?>건</em></span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (!$modelData['tools']): ?>
          <div class="empty-state">표시할 OQC 잔량 데이터가 없습니다.</div>
        <?php else: ?>
          <?php
            $maxRows = 0;
            foreach ($modelData['tools'] as $toolData) {
                foreach ($toolData['cavs'] as $cells) $maxRows = max($maxRows, count($cells));
            }
            $maxRows = max($maxRows, 1);
          ?>
          <div class="excel-scroll">
            <table class="oqc-grid">
              <thead>
                <tr>
                  <?php foreach ($modelData['tools'] as $tool => $toolData): ?>
                    <th colspan="4" class="tool-head"><?=h($tool)?>차</th>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <?php foreach ($modelData['tools'] as $tool => $toolData): ?>
                    <?php foreach (['1','2','3','4'] as $cavHead): ?>
                      <?php
                        $usableCavCount = 0;
                        foreach (($toolData['cavs'][$cavHead] ?? []) as $cavCell) {
                            $usableCavCount += max(0, (int)($cavCell['headers'] ?? 0) - (int)($cavCell['ng_headers'] ?? 0));
                        }
                        $cavTip = $tool . '차 ' . $cavHead . 'CAV' . "\n" . '사용 가능 예상(NG 제외): ' . number_format($usableCavCount) . '건';
                      ?>
                      <th data-tooltip="<?=h($cavTip)?>"><?=h($cavHead)?>CAV</th>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php for ($i=0; $i<$maxRows; $i++): ?>
                  <tr>
                    <?php foreach ($modelData['tools'] as $toolData): ?>
                      <?php foreach (['1','2','3','4'] as $cav): ?>
                        <?php $cell = array_values($toolData['cavs'][$cav])[$i] ?? null; ?>
                        <?php if ($cell): ?>
                          <?php
                            $kindText = implode(',', array_keys($cell['kind']));
                            if ($kindText === '') $kindText = '-';
                            $cellHeaders = (int)($cell['headers'] ?? 0);
                            $cellNgHeaders = (int)($cell['ng_headers'] ?? 0);
                            $cellUsable = max(0, $cellHeaders - $cellNgHeaders);
                            $dateLabel = (string)$cell['date'];
                            if ($cellHeaders > 1) $dateLabel .= ' ×' . $cellHeaders;
                            $pointList = array_keys($cell['ng_point_list'] ?? []);
                            natcasesort($pointList);
                            $pointList = array_values($pointList);
                            if ((int)($cell['ng'] ?? 0) > 0) {
                                $pointText = $pointList ? implode("\n", array_map(static fn($p) => '- ' . $p, $pointList)) : '- 포인트 정보 없음';
                                $tip = "NG 포인트\n" . $pointText . "\n사용 가능 예상(NG 제외): " . number_format($cellUsable) . "건\n사용 불가 예상(NG): " . number_format($cellNgHeaders) . "건\n기준: " . ($cell['slot'] ?: '-') . "\nkind: " . $kindText;
                            } else {
                                $tip = "사용 가능 예상: " . number_format($cellUsable) . "건\n기준: " . ($cell['slot'] ?: '-') . "\nkind: " . $kindText;
                            }
                          ?>
                          <?php
                            $detailPayload = [
                                'date' => $dateLabel,
                                'tool' => (string)$tool,
                                'cavity' => (string)$cav . 'CAV',
                                'kind' => $kindText,
                                'slot' => (string)($cell['slot'] ?: '-'),
                                'rows' => array_values($cell['ng_detail_list'] ?? []),
                            ];
                            $detailJson = ((int)($cell['ng'] ?? 0) > 0) ? json_encode($detailPayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '';
                          ?>
                          <td class="date-cell <?= $cell['ng'] > 0 ? 'ng' : '' ?>" data-tooltip="<?=h($tip)?>" <?= $detailJson !== '' ? 'data-ng-detail="'.h($detailJson).'"' : '' ?>><?=h($dateLabel)?></td>
                        <?php else: ?>
                          <td class="blank"></td>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tr>
                <?php endfor; ?>
                <tr>
                  <?php foreach ($modelData['tools'] as $toolData): ?>
                    <td colspan="4" class="ng-note">사용 불가 예상(NG): <?=number_format((int)$toolData['ng'])?>건</td>
                  <?php endforeach; ?>
                </tr>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
<div class="status-tooltip" id="oqcStatusTooltip"></div>
<div class="ng-detail-backdrop" id="oqcNgDetailBackdrop" aria-hidden="true">
  <div class="ng-detail-modal" role="dialog" aria-modal="true" aria-labelledby="oqcNgDetailTitle">
    <div class="ng-detail-head">
      <div>
        <div class="ng-detail-title" id="oqcNgDetailTitle">NG 상세</div>
        <div class="ng-detail-sub" id="oqcNgDetailSub"></div>
      </div>
      <button type="button" class="ng-detail-close" id="oqcNgDetailClose">×</button>
    </div>
    <div class="ng-detail-body" id="oqcNgDetailBody"></div>
  </div>
</div>
<script>
(function(){
  const tabs = document.querySelectorAll('.model-tab');
  const panels = document.querySelectorAll('.model-panel');
  const modelInput = document.getElementById('oqcStatusModelInput');
  const kindChecks = document.querySelectorAll('input[name="kind"]');
  const refreshKindLabels = () => {
    kindChecks.forEach(item => item.closest('label')?.classList.toggle('is-checked', item.checked));
  };
  kindChecks.forEach(chk => {
    chk.addEventListener('change', () => {
      if (chk.checked) {
        kindChecks.forEach(other => { if (other !== chk) other.checked = false; });
      } else if (![...kindChecks].some(item => item.checked)) {
        chk.checked = true;
      }
      refreshKindLabels();
    });
  });
  refreshKindLabels();

  const tooltip = document.getElementById('oqcStatusTooltip');
  const moveTooltip = (ev) => {
    if (!tooltip || tooltip.style.display === 'none') return;
    const pad = 14;
    let left = ev.clientX + pad;
    let top = ev.clientY + pad;
    const rect = tooltip.getBoundingClientRect();
    if (left + rect.width + 10 > window.innerWidth) left = ev.clientX - rect.width - pad;
    if (top + rect.height + 10 > window.innerHeight) top = ev.clientY - rect.height - pad;
    tooltip.style.left = Math.max(8, left) + 'px';
    tooltip.style.top = Math.max(8, top) + 'px';
  };
  document.querySelectorAll('[data-tooltip]').forEach(cell => {
    cell.addEventListener('mouseenter', (ev) => {
      if (!tooltip) return;
      const text = cell.getAttribute('data-tooltip') || '';
      tooltip.textContent = text;
      tooltip.style.display = 'block';
      moveTooltip(ev);
    });
    cell.addEventListener('mousemove', moveTooltip);
    cell.addEventListener('mouseleave', () => {
      if (tooltip) tooltip.style.display = 'none';
    });
  });

  const ngBackdrop = document.getElementById('oqcNgDetailBackdrop');
  const ngClose = document.getElementById('oqcNgDetailClose');
  const ngSub = document.getElementById('oqcNgDetailSub');
  const ngBody = document.getElementById('oqcNgDetailBody');
  const escHtml = (value) => String(value ?? '').replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));
  const openNgDetail = (payload) => {
    if (!ngBackdrop || !ngSub || !ngBody) return;
    const rows = Array.isArray(payload.rows) ? payload.rows : [];
    ngSub.textContent = `${payload.date || '-'} · ${payload.tool || '-'}차 · ${payload.cavity || '-'} · kind ${payload.kind || '-'} · 기준 ${payload.slot || '-'}`;
    if (!rows.length) {
      ngBody.innerHTML = '<div class="ng-detail-empty">표시할 NG 상세가 없습니다.</div>';
    } else {
      const bodyRows = rows.map(row => `
        <tr>
          <td>${escHtml(row.point_no || '-')}</td>
          <td>${escHtml(row.spc_code || '-')}</td>
          <td>${escHtml(row.usl || '-')}</td>
          <td>${escHtml(row.lsl || '-')}</td>
          <td>${escHtml(row.value || '-')}</td>
          <td class="judgement">${escHtml(row.direction || 'NG')}</td>
        </tr>`).join('');
      ngBody.innerHTML = `
        <table class="ng-detail-table">
          <thead><tr><th>FAI / 포인트</th><th>SPC</th><th>USL</th><th>LSL</th><th>측정값</th><th>판정</th></tr></thead>
          <tbody>${bodyRows}</tbody>
        </table>`;
    }
    ngBackdrop.classList.add('open');
    ngBackdrop.setAttribute('aria-hidden','false');
  };
  const closeNgDetail = () => {
    if (!ngBackdrop) return;
    ngBackdrop.classList.remove('open');
    ngBackdrop.setAttribute('aria-hidden','true');
  };
  document.querySelectorAll('[data-ng-detail]').forEach(cell => {
    cell.addEventListener('click', () => {
      const raw = cell.getAttribute('data-ng-detail') || '';
      if (!raw) return;
      try { openNgDetail(JSON.parse(raw)); } catch(e) {}
    });
  });
  if (ngClose) ngClose.addEventListener('click', closeNgDetail);
  if (ngBackdrop) ngBackdrop.addEventListener('click', e => { if (e.target === ngBackdrop) closeNgDetail(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ngBackdrop && ngBackdrop.classList.contains('open')) closeNgDetail(); });

  tabs.forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.model;
      tabs.forEach(b => b.classList.toggle('active', b === btn));
      panels.forEach(p => p.classList.toggle('active', p.dataset.modelPanel === key));
      if (modelInput) modelInput.value = key;
      try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])model=[^&]*/,'').replace(/[?&]$/,'') + (location.search ? '&' : '?') + 'model=' + encodeURIComponent(key)); } catch(e) {}
    });
  });
})();
</script>
</body>
</html>
