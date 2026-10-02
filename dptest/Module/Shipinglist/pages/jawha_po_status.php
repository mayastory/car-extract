<?php
declare(strict_types=1);

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
    if (!defined('JTMES_ROOT')) define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

date_default_timezone_set('Asia/Seoul');
session_start();
require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();
require_once __DIR__ . '/jawha_po_lib.php';
require_once __DIR__ . '/jawha_po_status_lib.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('po_fmt_ea')) {
    function po_fmt_ea(int $n): string { return number_format(max(0, $n)) . ' EA'; }
}

try {
    $pdo = dp_get_pdo();
    jawha_po_ensure_tables($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="background:#202124;color:#e8eaed;font-family:sans-serif;padding:16px;">DB 접속 실패</body>';
    exit;
}

$po = jawha_po_get_active($pdo);
$items = [];
$contribMap = [];
$reportRows = [];
$reportCount = 0;
$tupleCount = 0;

if (is_array($po) && (int)($po['id'] ?? 0) > 0) {
    $poId = (int)$po['id'];

    $st = $pdo->prepare("SELECT `part_name`,`prod_date`,`tool`,`cavity`,`tool_cavity`,`seen_count`,`first_seen_at`,`last_seen_at`
        FROM `jawha_po_item`
        WHERE `po_id`=:po
        ORDER BY `part_name`,`tool`,`prod_date`,`cavity`");
    $st->execute([':po' => $poId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $tupleCount = count($items);

    $st = $pdo->prepare("SELECT `part_name`,`prod_date`,`tool`,`cavity`,`report_finish_id`
        FROM `jawha_po_item_report`
        WHERE `po_id`=:po
        ORDER BY `report_finish_id`,`part_name`,`prod_date`,`tool`,`cavity`");
    $st->execute([':po' => $poId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $part = trim((string)($r['part_name'] ?? ''));
        $date = trim((string)($r['prod_date'] ?? ''));
        $tool = strtoupper(trim((string)($r['tool'] ?? '')));
        $rf = (int)($r['report_finish_id'] ?? 0);
        if ($part === '' || $date === '' || $tool === '' || $rf <= 0) continue;
        $contribMap[$part . '|' . $date . '|' . $tool][$rf] = true;
    }

    $st = $pdo->prepare("SELECT rf.`id`, rf.`parts_json`
        FROM `jawha_po_report` pr
        INNER JOIN `report_finish` rf ON rf.`id`=pr.`report_finish_id`
        WHERE pr.`po_id`=:po
        ORDER BY pr.`report_finish_id`");
    $st->execute([':po' => $poId]);
    $reportRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $reportCount = count($reportRows);
}

$partTotals = jawha_po_status_part_totals_from_reports($reportRows);
$groups = jawha_po_status_build_groups($items, $contribMap, $partTotals);
$totalQty = array_sum(array_map('intval', $partTotals));
$summaryParts = [];
foreach ($groups as $g) {
    $summaryParts[] = (string)$g['part_name'] . ' : ' . po_fmt_ea((int)$g['total_qty']);
}
$poActive = is_array($po) && (int)($po['id'] ?? 0) > 0;
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PO 현황</title>
<style>
*{box-sizing:border-box}html,body{height:100%}body{margin:0;background:#202124;color:#e8eaed;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px;overflow:hidden}.wrap{height:100vh;padding:8px 10px 10px;display:flex;flex-direction:column;gap:8px;overflow:hidden;min-width:0}.top-summary{flex:0 0 auto;color:#dfe3e7;white-space:nowrap;overflow-x:auto;overflow-y:hidden;padding:2px 2px 6px;line-height:1.35;border-bottom:1px solid #34383d}.top-summary .row1{color:#cfd8dc}.top-summary .row2{color:#e8eaed}.top-summary .row3{color:#9aa0a6;font-size:11px;margin-top:2px}.po-sn{color:#b9ffd0;font-weight:850;text-shadow:0 0 8px rgba(70,220,130,.35)}.groups{flex:1 1 auto;min-height:0;min-width:0;display:flex;flex-wrap:nowrap;gap:10px;align-items:stretch;overflow-x:auto;overflow-y:hidden;padding:0 0 4px;scrollbar-gutter:stable both-edges}.group-card{flex:0 0 auto;background:#1b1c1f;border:1px solid #34383d;border-radius:12px;min-width:max-content;width:max-content;padding:7px;box-shadow:0 6px 18px rgba(0,0,0,.35);display:flex;flex-direction:column;min-height:0;overflow:hidden}.group-title{flex:0 0 auto;font-size:13px;font-weight:700;color:#f1f3f4;margin:0 0 6px;text-align:center;white-space:nowrap}.group-row{flex:1 1 auto;min-height:0;display:flex;align-items:stretch;gap:8px;min-width:0}.table-shell{flex:0 0 auto;border:1px solid #2e3237;border-radius:8px;overflow-y:auto;overflow-x:hidden;background:#111316;min-height:0;scrollbar-gutter:stable}table.matrix{border-collapse:collapse;width:max-content;min-width:100%;white-space:nowrap;color:#e8eaed;font-size:11px}.matrix th,.matrix td{border-bottom:1px solid #262a2f;border-right:1px solid #262a2f;padding:5px 6px;text-align:center;line-height:1.2}.matrix th:last-child,.matrix td:last-child{border-right:none}.matrix thead th{position:sticky;top:0;z-index:2;background:#1f2329;color:#f1f3f4;font-weight:700}.matrix td.part{text-align:left;min-width:88px}.matrix td.tool{min-width:42px}.matrix td.date{min-width:86px}.matrix td.count{min-width:48px}.matrix td.bool-true,.matrix td.bool-false{min-width:48px}.bool-true{background:#c6efce;color:#006100;font-weight:700}.bool-false{background:#ffc7ce;color:#9c0006;font-weight:700}.muted-row td{color:#9aa0a6}.side{flex:0 0 auto;width:154px;min-width:154px;display:flex;flex-direction:column;gap:6px;min-height:0}.total-box{flex:0 0 auto;border:1px solid #3a3a3a;border-radius:8px;background:#151515;font-weight:700;text-align:center;padding:6px 8px;white-space:nowrap}.uniq-list{flex:1 1 auto;border:1px solid #333;border-radius:8px;background:#121212;padding:6px;overflow-y:auto;overflow-x:hidden;min-height:0;font-family:Consolas,"Courier New",monospace;line-height:1.35;white-space:pre;font-size:11px;scrollbar-gutter:stable}.uniq-line{color:#e8eaed}.uniq-line.tool{color:#d0d7de}.empty{color:#9aa0a6}.help{flex:0 0 auto;color:#9aa0a6;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.no-po{margin:auto;border:1px solid #3a3f45;border-radius:14px;background:#191b1f;padding:18px 24px;color:#cbd2d9;font-weight:700;box-shadow:0 8px 24px rgba(0,0,0,.28)}@media(max-width:1200px){.side{width:140px;min-width:140px}.matrix th,.matrix td{padding:4px 5px;font-size:10px}.uniq-list{font-size:10px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top-summary">
    <div class="row1">납품처: 자화전자(주) | PO: <span class="po-sn"><?=h($poActive ? (string)($po['po_sn'] ?? '') : '진행중인 PO 없음')?></span><?php if ($poActive): ?> | 시작: <?=h((string)($po['started_at'] ?? ''))?><?php endif; ?></div>
    <div class="row2"><?=h(implode(' | ', $summaryParts))?></div>
    <div class="row3">총 출하수량 <?=h(po_fmt_ea((int)$totalQty))?> / 유효 발행 <?=number_format($reportCount)?>건 / PO Tool#Cavity 누적 <?=number_format($tupleCount)?>건</div>
  </div>

  <?php if (!$poActive): ?>
    <div class="no-po">현재 진행 중인 자화 PO가 없습니다. PO시작 후 발행된 성적서부터 이 화면에 누적됩니다.</div>
  <?php else: ?>
  <div class="groups">
    <?php foreach ($groups as $g): ?>
      <?php $pn=(string)$g['part_name'];$cavs=(array)$g['cavs'];$rowsOut=(array)$g['rows'];$sideLines=jawha_po_status_side_lines($rowsOut,$cavs); ?>
      <section class="group-card">
        <div class="group-title"><?=h($pn)?></div>
        <div class="group-row">
          <div class="table-shell">
            <table class="matrix">
              <thead><tr><th>품번명</th><th>Tool</th><th>생산일</th><?php foreach($cavs as $c): ?><th>Cav<?=intval($c)?></th><?php endforeach; ?><th>발행</th></tr></thead>
              <tbody>
              <?php if (!$rowsOut): ?>
                <tr class="muted-row"><td class="part"><?=h($pn)?></td><td class="tool">-</td><td class="date">-</td><?php foreach($cavs as $_): ?><td>-</td><?php endforeach; ?><td class="count">0</td></tr>
              <?php else: foreach($rowsOut as $r): ?>
                <tr><td class="part"><?=h($pn)?></td><td class="tool"><?=h((string)$r['tool'])?></td><td class="date"><?=h((string)$r['date'])?></td><?php foreach($cavs as $c): ?><?php $v=!empty($r['cavs'][(int)$c]); ?><td class="<?=$v?'bool-true':'bool-false'?>"><?=$v?'TRUE':'FALSE'?></td><?php endforeach; ?><td class="count"><?=number_format((int)($r['report_count']??0))?>건</td></tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <div class="side">
            <div class="total-box"><?=h(po_fmt_ea((int)$g['total_qty']))?></div>
            <div class="uniq-list" title="생산일 / Tool별 Cavity 목록">
              <?php if (!$sideLines): ?><div class="empty">-</div><?php else: foreach($sideLines as $line): ?><?php $isTool=(strpos($line,'/Cav')!==false); ?><div class="uniq-line<?=$isTool?' tool':''?>"><?=h($line)?></div><?php endforeach; endif; ?>
            </div>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="help">※ 현재 활성 자화 PO의 실제 누적 데이터 기준입니다. 성적서 취소로 PO 기여분이 제거되면 현황에서도 제외됩니다.</div>
</div>
</body>
</html>
