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

$rangeDays = (int)($_GET['days'] ?? 120);
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
    ORDER BY h.part_name, h.tool_cavity, {$dateExpr}, h.excel_col
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':from_date' => $fromDate]);
$headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ngByHeader = [];
$headerIds = array_map(static fn($r) => (int)$r['id'], $headers);
$headerIds = array_values(array_unique(array_filter($headerIds)));
if ($headerIds && $resultCols) {
    foreach (array_chunk($headerIds, 700) as $chunk) {
        $in = oqc_status_in_placeholders(count($chunk));
        $pointFilter = isset($resultCols['point_no']) ? "AND (point_no IS NULL OR point_no NOT LIKE '%(DC)%')" : '';
        $ngSql = "
            SELECT header_id, COUNT(*) AS ng_count
            FROM oqc_result_header
            WHERE header_id IN ($in)
              AND result_ok = 0
              {$pointFilter}
            GROUP BY header_id
        ";
        try {
            $ngStmt = $pdo->prepare($ngSql);
            $ngStmt->execute($chunk);
            foreach ($ngStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ngByHeader[(int)$r['header_id']] = (int)$r['ng_count'];
            }
        } catch (Throwable $e) {}
    }
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
    $ng = $ngByHeader[$hid] ?? 0;
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
            'slot' => $slotText,
            'headers' => 0,
            'kind' => [],
        ];
    }
    $cell =& $data[$modelKey]['tools'][$tool]['cavs'][$cav][$dateKey];
    $cell['ng'] += $ng;
    $cell['headers']++;
    if (!empty($r['kind'])) $cell['kind'][(string)$r['kind']] = true;
    unset($cell);

    $data[$modelKey]['tools'][$tool]['ng'] += $ng;
    $data[$modelKey]['tools'][$tool]['total']++;
    $data[$modelKey]['total']++;
    $data[$modelKey]['ng'] += $ng;
    if ($ng <= 0) $data[$modelKey]['usable']++;
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
                return strcmp((string)$a['date'], (string)$b['date']);
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
.model-tabs{display:flex; gap:6px; border-bottom:1px solid var(--border); margin-bottom:12px; overflow-x:auto;}
.model-tab{appearance:none; border:1px solid var(--border); border-bottom:0; background:#24272d; color:#cbd5e1; padding:9px 14px; border-radius:12px 12px 0 0; cursor:pointer; font-weight:800; white-space:nowrap;}
.model-tab.active{background:#303134; color:#fff; box-shadow:inset 0 2px 0 var(--accent);}
.model-panel{display:none;}
.model-panel.active{display:block;}
.summary-row{display:flex; gap:10px; flex-wrap:wrap; margin:0 0 12px;}
.summary-card{background:#25282e; border:1px solid var(--border); border-radius:14px; padding:10px 12px; min-width:130px; box-shadow:0 8px 18px rgba(0,0,0,.22);}
.summary-card .label{color:var(--muted); font-size:11.5px; margin-bottom:4px;}
.summary-card .value{font-size:18px; font-weight:900;}
.excel-scroll{overflow:auto; border:1px solid var(--border); border-radius:14px; background:#f7f7f7; max-height:calc(100vh - 210px);}
.oqc-grid{border-collapse:collapse; color:#101418; background:#fff; width:max-content; min-width:100%; font-size:12px;}
.oqc-grid th,.oqc-grid td{border:1px solid #d7dce2; min-width:82px; height:24px; padding:3px 6px; text-align:center; white-space:nowrap;}
.oqc-grid thead th{background:#f2f4f7; color:#111827; font-weight:900; position:sticky; top:0; z-index:2;}
.oqc-grid thead tr:nth-child(2) th{top:25px; z-index:2; font-weight:800;}
.oqc-grid .tool-head{background:#ffffff; font-size:13px;}
.oqc-grid .date-cell{background:#fff; color:#111827;}
.oqc-grid .date-cell.ng{color:#d3352f; font-weight:900;}
.oqc-grid .ng-note{color:#c5352c; font-weight:900; background:#fff7f5; text-align:left;}
.oqc-grid .blank{background:#fff;}
.empty-state{padding:50px 20px; text-align:center; color:#6b7280; background:#fff; border-radius:14px;}
.badge{display:inline-flex; align-items:center; gap:4px; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:800;}
.badge.ok{background:rgba(29,185,84,.16); color:#8ff5b4;}
.badge.ng{background:rgba(232,93,93,.16); color:#ffb4b4;}
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
        <div class="sub">모델별 Tool / Cavity 사용 가능 예상 데이터 현황 · <?=h($customerLabel)?> 기준 · 최근 <?=h((string)$rangeDays)?>일</div>
      </div>
      <form class="status-controls" method="get">
        <?php if ($EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>납품처
          <select name="customer">
            <option value="LG" <?= $customer === 'LG' ? 'selected' : '' ?>>엘지이노텍(주)</option>
            <option value="JH" <?= $customer === 'JH' ? 'selected' : '' ?>>자화전자(주)</option>
          </select>
        </label>
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
                  <?php foreach ($modelData['tools'] as $toolData): ?>
                    <th>1CAV</th><th>2CAV</th><th>3CAV</th><th>4CAV</th>
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
                          <?php $title = '기준: ' . ($cell['slot'] ?: '-') . ' / headers: ' . $cell['headers'] . ' / kind: ' . implode(',', array_keys($cell['kind'])); ?>
                          <td class="date-cell <?= $cell['ng'] > 0 ? 'ng' : '' ?>" title="<?=h($title)?>"><?=h($cell['date'])?></td>
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
<script>
(function(){
  const tabs = document.querySelectorAll('.model-tab');
  const panels = document.querySelectorAll('.model-panel');
  tabs.forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.model;
      tabs.forEach(b => b.classList.toggle('active', b === btn));
      panels.forEach(p => p.classList.toggle('active', p.dataset.modelPanel === key));
      try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])model=[^&]*/,'').replace(/[?&]$/,'') + (location.search ? '&' : '?') + 'model=' + encodeURIComponent(key)); } catch(e) {}
    });
  });
})();
</script>
</body>
</html>
