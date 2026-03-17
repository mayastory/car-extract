<?php
if (!defined('JTMES_ROOT')) {
 define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

session_start();

$ROOT = JTMES_ROOT;
require_once $ROOT . '/config/dp_config.php';
require_once $ROOT . '/lib/auth_guard.php';

if (function_exists('dp_auth_guard')) {
 dp_auth_guard();
} elseif (function_exists('require_login')) {
 require_login();
}

$key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>공정 능력 결과</title>
<style>
:root{
 --qpc-bg:#f8f8f8;
 --qpc-panel:#f8f8f8;
 --qpc-border:rgba(0,0,0,.18);
 --qpc-border-soft:rgba(0,0,0,.10);
 --qpc-text:#111;
 --qpc-muted:#666;
 --qpc-box-border:rgba(0,0,0,.16);
 --qpc-box-border-soft:rgba(0,0,0,.10);
}
html,body{margin:0;padding:0;background:var(--qpc-bg);color:var(--qpc-text);font-family:Segoe UI, Arial, "Malgun Gothic", sans-serif;}
body{min-width:980px;}
.qpc-page{padding:8px 10px 14px; box-sizing:border-box;}
.qpc-page-head{font-size:16px;font-weight:700;line-height:1.15;margin:1px 0 6px;}
.qpc-report-shell{min-height:100%;}
.qpc-report-tree,
.qpc-report-group,
.qpc-report-sub{display:inline-block;width:auto;max-width:100%;border:0;background:transparent;border-radius:0;overflow:visible;margin:0 0 4px;box-shadow:none;}
.qpc-report-tree > summary,
.qpc-report-group > summary,
.qpc-report-sub > summary{list-style:none;cursor:pointer;padding:2px 8px;font-size:12px;line-height:1.35;font-weight:700;color:rgba(236,247,240,.96);background:linear-gradient(180deg, rgba(34,78,53,.86), rgba(13,28,19,.98));}
.qpc-report-sub-top.qpc-report-sub-hist > summary{padding:1px 6px;}
.qpc-report-tree > summary::-webkit-details-marker,
.qpc-report-group > summary::-webkit-details-marker,
.qpc-report-sub > summary::-webkit-details-marker,
.qpc-report-top-summary > summary::-webkit-details-marker{display:none;}
.qpc-report-tree > summary:before,
.qpc-report-group > summary:before,
.qpc-report-sub > summary:before,
.qpc-report-top-summary > summary:before{content:'▸ ';color:rgba(236,247,240,.78);}
.qpc-report-tree[open] > summary:before,
.qpc-report-group[open] > summary:before,
.qpc-report-sub[open] > summary:before,
.qpc-report-top-summary[open] > summary:before{content:'▾ ';}
.qpc-report-body,
.qpc-report-group-body,
.qpc-report-sub-body{padding:4px 6px 6px;border-top:0;background:transparent;}
.qpc-report-body,
.qpc-report-group-body{display:flex;flex-direction:column;align-items:flex-start;gap:4px;}
.qpc-report-note{padding:14px 10px;color:var(--qpc-muted);font-size:12px;}
.qpc-report-hist-grid{display:grid;grid-template-columns:minmax(0,428px) 156px;gap:12px;align-items:start;justify-content:start;width:max-content;max-width:100%;}
.qpc-hist-wrap,.qpc-summary-box,.qpc-stat-box,.qpc-reject-box{border:1px solid var(--qpc-box-border);background:#fff;border-radius:0;}
.qpc-hist-wrap{padding:0;border:none;background:#fff;}
.qpc-summary-box{padding:4px 6px;}
.qpc-report-top-grid{max-width:100%;width:max-content;grid-template-columns:minmax(0,428px) 156px;gap:12px;align-items:start;justify-content:start;}
.qpc-report-sub-top{margin-bottom:0;}
.qpc-report-sub-top > summary{background:linear-gradient(180deg, rgba(44,92,62,.88), rgba(14,29,20,.98));}
.qpc-report-sub-top.qpc-report-sub-hist{width:444px;max-width:100%;}
.qpc-report-sub-hist > .qpc-report-sub-body{padding:2px 3px 3px;background:#fff;}
.qpc-hist-wrap--top{padding:0;position:relative;background:#fff;}
.qpc-svgbox{width:100%;overflow:visible;}
.qpc-svgbox svg{width:100%;height:auto;display:block;}
.qpc-svgbox--hist{width:428px;max-width:100%;position:relative;display:block;background:transparent;overflow:visible;}
.qpc-svgbox--hist svg{width:428px;max-width:100%;height:auto;display:block;}
.qpc-hist-card{display:flex;flex-direction:column;gap:0;width:428px;max-width:100%;background:#fff;}
.qpc-hist-topline{display:flex;align-items:flex-end;min-height:15px;width:344px;padding:0;background:#fff;}
.qpc-hist-toplabels{position:relative;height:15px;flex:0 0 auto;width:344px;}
.qpc-hist-limit{position:absolute;top:1px;transform:translateX(-50%);font-size:12px;line-height:1;color:#111;font-weight:400;white-space:nowrap;}
.qpc-hist-main{display:grid;grid-template-columns:344px max-content;column-gap:12px;align-items:start;justify-content:start;width:max-content;max-width:100%;background:#fff;}
.qpc-hist-plot{display:block;background:transparent;}
.qpc-hist-plot svg{width:344px;max-width:100%;height:auto;display:block;background:transparent;}
.qpc-hist-side{display:flex;align-items:flex-start;justify-content:flex-start;padding-top:6px;background:#fff;}
.qpc-hist-legend{display:flex;flex-direction:column;gap:6px;color:#111;font-size:12px;line-height:1.2;background:#fff;}
.qpc-hist-legend-title{font-weight:700;}
.qpc-hist-legend-item{display:flex;align-items:center;gap:6px;white-space:nowrap;}
.qpc-hist-legend-line{display:inline-block;width:16px;height:0;border-top:1.15px solid rgba(0,0,0,.78);box-sizing:border-box;}
.qpc-hist-legend-line--overall{border-top-style:dashed;}
.qpc-hist-legend-line--within{border-top:1.65px solid #2d74ff;}
.qpc-hist-foot{display:flex;justify-content:flex-start;width:344px;padding-top:1px;background:#fff;}
.qpc-hist-caption{width:344px;text-align:center;color:#111;font-size:13px;line-height:1;}
.qpc-hist-wrap--top .qpc-svgbox--hist::after{display:none;}
.qpc-report-top-summary{margin:0;width:156px;max-width:156px;border:0;background:transparent;overflow:visible;align-self:start;}
.qpc-report-top-summary > summary{list-style:none;cursor:pointer;padding:1px 6px;font-size:12px;line-height:1.3;font-weight:700;color:var(--qpc-text);background:linear-gradient(180deg, #f2f2f2, #dfdfdf);border:1px solid var(--qpc-border);}
.qpc-report-top-summary-body{padding:4px 0 0;}
.qpc-report-top-summary .qpc-summary-box{width:156px;max-width:156px;border:none;padding:0;background:#fff;}
.qpc-report-top-summary .qpc-summary-grid{gap:2px 6px;}
.qpc-report-top-summary .qpc-summary-sep{margin:4px 0;}
.qpc-summary-title{font-size:12px;font-weight:700;margin-bottom:6px;}
.qpc-summary-grid{display:grid;grid-template-columns:1fr auto;gap:2px 8px;font-size:12px;}
.qpc-summary-grid .k{color:var(--qpc-text);} 
.qpc-summary-grid .v{color:var(--qpc-text);text-align:right;font-variant-numeric:tabular-nums;}
.qpc-summary-sep{height:1px;background:rgba(0,0,0,.10);margin:6px 0;}
.qpc-report-two{display:grid;grid-template-columns:repeat(2, minmax(0, max-content));gap:8px;align-items:start;justify-content:start;width:max-content;max-width:100%;}
.qpc-report-two-tight{max-width:706px;gap:8px;}
.qpc-stat-box,.qpc-reject-box{padding:6px 8px;}
.qpc-reject-box{padding:4px 6px 5px;}
.qpc-report-sub-reject{width:max-content;max-width:100%;}
.qpc-report-sub-reject > .qpc-report-sub-body{display:inline-block;padding:4px 6px 5px;}
.qpc-report-sub-reject .qpc-reject-box{display:inline-block;width:auto;}
.qpc-reject-table th,.qpc-reject-table td{padding:1px 4px;}
.qpc-reject-table thead th{background:rgba(255,255,255,.035);}
.qpc-stat-table{width:100%;border-collapse:collapse;font-size:12px;}
.qpc-stat-table th,.qpc-stat-table td{border:1px solid var(--qpc-box-border-soft);padding:2px 5px;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.qpc-stat-table th:first-child,.qpc-stat-table td:first-child{text-align:left;}
.qpc-stat-table thead th{background:rgba(255,255,255,.04);font-weight:700;}
.qpc-stat-table tbody th{background:rgba(255,255,255,.02);font-weight:600;}
.qpc-report-placeholder{padding:8px 10px;color:var(--qpc-muted);font-size:11px;}
.qpc-report-sub-summary > summary{padding:2px 8px;}
.qpc-report-sub-summary > .qpc-report-sub-body{padding:4px 6px 6px;}
.qpc-summary-report-wrap{overflow:auto;border:1px solid var(--qpc-box-border-soft);background:#fff;}
.qpc-summary-report-table{width:max-content;min-width:100%;border-collapse:collapse;font-size:11.5px;line-height:1.24;}
.qpc-summary-report-table th,.qpc-summary-report-table td{border:1px solid var(--qpc-box-border-soft);padding:1px 5px;white-space:nowrap;font-variant-numeric:tabular-nums;}
.qpc-summary-report-table thead th{background:rgba(0,0,0,.04);font-weight:700;text-align:center;color:#111;}
.qpc-summary-report-table tbody th{background:rgba(0,0,0,.025);font-weight:700;text-align:left;color:#111;} 
.qpc-summary-report-table tbody td{text-align:right;background:transparent;}
.qpc-summary-report-table td.kind-stability{min-width:62px;}
.qpc-summary-report-table td.kind-capability{min-width:48px;}
.qpc-summary-report-table td.is-good{background:rgba(198,229,191,.96);color:#10210f;}
.qpc-summary-report-table td.is-warn{background:rgba(245,229,176,.96);color:#3a2a06;}
.qpc-summary-report-table td.is-bad{background:rgba(240,188,188,.96);color:#341111;}
.qpc-report-empty{text-align:center !important;color:var(--qpc-muted);}
.qpc-target-grid{display:grid;grid-template-columns:minmax(0, 620px) 240px;gap:12px;align-items:start;max-width:872px;}
.qpc-target-main,.qpc-target-side{border:0;background:transparent;border-radius:0;}
.qpc-target-main{position:relative;padding:6px 6px 4px;overflow:visible;max-width:620px;}
.qpc-target-side{padding:8px 14px;min-width:240px;width:auto;box-sizing:border-box;}
.qpc-target-side-title{font-size:11px;font-weight:700;margin-bottom:8px;}
.qpc-target-check{display:flex;align-items:center;gap:6px;font-size:11px;margin:0 0 12px;white-space:nowrap;}
.qpc-target-check input{margin:0;}
.qpc-target-ppk-label{font-size:11px;font-weight:700;margin-bottom:4px;}
.qpc-target-ppk-line{display:grid;grid-template-columns:auto 48px minmax(120px,1fr);align-items:center;column-gap:8px;margin-bottom:6px;}
.qpc-target-ppk-badge{min-width:36px;height:18px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(0,0,0,.18);background:#f7f7f7;font-size:11px;font-weight:700;}
.qpc-target-ppk-input{width:48px;height:20px;border:1px solid rgba(0,0,0,.18);background:#f7f7f7;color:var(--qpc-text);padding:0 4px;font-size:11px;text-align:center;box-sizing:border-box;}
.qpc-target-range{display:block;width:100%;min-width:120px;accent-color:#b9d8ff;}
.qpc-target-side-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px;}
.qpc-target-legend-link{padding:0;border:0;background:transparent;color:var(--qpc-text);font-size:11px;font-weight:700;cursor:pointer;user-select:none;}
.qpc-target-side-preview{min-height:48px;margin-bottom:10px;}
.qpc-target-side-item{display:flex;align-items:center;gap:6px;font-size:11px;margin:4px 0;padding:0;border:0;background:transparent;color:var(--qpc-text);cursor:pointer;text-align:left;width:100%;user-select:none;}
.qpc-target-side-empty{font-size:11px;color:var(--qpc-muted);}
.qpc-target-marker{width:9px;height:9px;display:inline-block;box-sizing:border-box;flex:0 0 9px;}
.qpc-target-marker--overall{border:1px solid rgba(0,0,0,.82);background:transparent;}
.qpc-target-marker--within{border:1px solid rgba(0,0,0,.82);background:#7c7c7c;}
.qpc-target-hover-tip{position:absolute;z-index:12;min-width:270px;max-width:290px;pointer-events:none;background:#ececec;color:#111;border:1px solid #a6a6a6;border-radius:6px;box-shadow:0 6px 14px rgba(0,0,0,.35);padding:8px 10px 10px;}
.qpc-target-tip-card{font-family:Arial, "Malgun Gothic", "Noto Sans KR", sans-serif;}
.qpc-target-tip-meta{font-size:12px;line-height:1.35;margin-bottom:2px;color:#333;}
.qpc-target-tip-svg{margin-top:6px;}
.qpc-target-tip-svg svg{width:250px;height:auto;display:block;}
.qpc-index-main,.qpc-index-side{border:0;background:transparent;border-radius:0;}
@media print{
 body{background:#fff;color:#000;}
 .qpc-page{padding:0;}
}
</style>
</head>
<body>
<div class="qpc-page">
 <div class="qpc-page-head">공정 능력 결과</div>
 <div class="qpc-report-shell">
 <details class="qpc-report-tree" open>
 <summary>개별 상세 정보 보고서</summary>
 <div class="qpc-report-body" id="qpcReportBody">
 <div class="qpc-report-note">결과를 불러오는 중...</div>
 </div>
 </details>
 </div>
</div>
<script>
(function(){
 var key = <?php echo json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
 var root = document.getElementById('qpcReportBody');
 var titleBase = '공정 능력 결과';
 function setMessage(msg){
  if (!root) return;
  root.innerHTML = '<div class="qpc-report-note">' + msg + '</div>';
 }
 if (!key){
  setMessage('잘못된 접근입니다. 결과 키가 없습니다.');
  return;
 }
 var storageKey = '__qpc_report_payload__:' + key;
 var payload = null;
 try {
  payload = JSON.parse(localStorage.getItem(storageKey) || 'null');
 } catch (err) {
  payload = null;
 }
 if (!payload || !payload.html){
  setMessage('결과 데이터를 찾지 못했습니다. 이전 창에서 다시 확인을 눌러 주세요.');
  return;
 }
 function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]; }); }
 function parseNum(v){
  var raw = String(v == null ? '' : v).replace(/,/g, '').trim();
  if (!raw) return NaN;
  var cleaned = raw.replace(/[^0-9eE+\-.]/g, '');
  var n = parseFloat(cleaned);
  return isFinite(n) ? n : NaN;
 }
 function clampNum(v, min, max){
  var n = Number(v);
  if (!isFinite(n)) n = min;
  if (n < min) n = min;
  if (n > max) n = max;
  return n;
 }
 function fixedTrim(n, d){
  var v = Number(n);
  if (!isFinite(v)) return '';
  var mul = Math.pow(10, d);
  v = Math.round((v + Number.EPSILON) * mul) / mul;
  return v.toFixed(d).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
 }

 function fmtTargetTick(v){
  if (!isFinite(v)) return '';
  if (Math.abs(v) < 1e-9) return '0';
  return fixedTrim(v, 1);
 }
 function targetStdTypeLabel(useOverall){
  return useOverall ? '전체' : '군내';
 }
 function targetPlotTooltipSvg(entry, useOverall){
  var values = Array.isArray(entry && entry.values) ? entry.values.filter(function(v){ return isFinite(v); }) : [];
  var width = 250, height = 150;
  var left = 46, right = 12, top = 18, bottom = 26;
  var plotW = width - left - right;
  var plotH = height - top - bottom;
  if (!values.length){
   return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true"><rect x="0.5" y="0.5" width="' + (width - 1) + '" height="' + (height - 1) + '" fill="#ffffff" stroke="#9d9d9d"/><text x="' + fixedTrim(width / 2, 2) + '" y="' + fixedTrim(height / 2, 2) + '" fill="#333" text-anchor="middle" font-size="12">데이터 없음</text></svg>';
  }
  var min = Math.min.apply(null, values);
  var max = Math.max.apply(null, values);
  if (isFinite(entry.lsl)) min = Math.min(min, entry.lsl);
  if (isFinite(entry.usl)) max = Math.max(max, entry.usl);
  var avg = isFinite(entry.avg) ? entry.avg : (values.reduce(function(a, b){ return a + b; }, 0) / Math.max(1, values.length));
  if (isFinite(avg)){ min = Math.min(min, avg); max = Math.max(max, avg); }
  var range = max - min;
  if (!(range > 0)) range = Math.max(Math.abs(max || 1), 1) * 0.1;
  min -= range * 0.08;
  max += range * 0.08;
  function x(i){ return left + (i / Math.max(1, values.length - 1)) * plotW; }
  function y(v){ return top + plotH - ((v - min) / Math.max(1e-9, max - min)) * plotH; }
  var tickIdx = [0];
  var step = values.length > 1 ? Math.max(1, Math.round((values.length - 1) / 4)) : 1;
  for (var i = step; i < values.length - 1; i += step) tickIdx.push(i);
  if (values.length > 1) tickIdx.push(values.length - 1);
  var uniq = Array.from(new Set(tickIdx));
  var xTicks = uniq.map(function(i){
   var xx = x(i);
   var label = i === 0 ? '0' : String(i + 1);
   return '<line x1="' + fixedTrim(xx,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(xx,2) + '" y2="' + fixedTrim(top + plotH + 3,2) + '" stroke="#7e7e7e"/><text x="' + fixedTrim(xx,2) + '" y="' + fixedTrim(top + plotH + 16,2) + '" fill="#111" font-size="10" text-anchor="middle">' + esc(label) + '</text>';
  }).join('');
  var path = values.map(function(v, i){ return (i ? 'L' : 'M') + fixedTrim(x(i),2) + ' ' + fixedTrim(y(v),2); }).join(' ');
  var line = '<path d="' + path + '" fill="none" stroke="#b8b8b8" stroke-width="1.2"/>';
  var dots = values.map(function(v, i){ return '<circle cx="' + fixedTrim(x(i),2) + '" cy="' + fixedTrim(y(v),2) + '" r="1.35" fill="#b8b8b8" stroke="#9a9a9a" stroke-width="0.4"/>'; }).join('');
  var avgLine = isFinite(avg) ? '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(avg),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(avg),2) + '" stroke="#22a52f" stroke-width="1.1"/>' : '';
  var lslLine = isFinite(entry.lsl) ? '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(entry.lsl),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(entry.lsl),2) + '" stroke="#e53935" stroke-width="1"/>' : '';
  var uslLine = isFinite(entry.usl) ? '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(entry.usl),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(entry.usl),2) + '" stroke="#e53935" stroke-width="1"/>' : '';
  var lslLabel = isFinite(entry.lsl) ? '<text x="12" y="' + fixedTrim(y(entry.lsl) + 4,2) + '" fill="#255cff" font-size="11">LSL</text>' : '';
  var uslLabel = isFinite(entry.usl) ? '<text x="12" y="' + fixedTrim(y(entry.usl) + 4,2) + '" fill="#255cff" font-size="11">USL</text>' : '';
  var title = esc((entry.label || entry.proc || '공정') + '의 개별 차트');
  var yLabel = esc(entry.label || entry.proc || '공정');
  return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
   '<rect x="0.5" y="0.5" width="' + (width - 1) + '" height="' + (height - 1) + '" fill="#ffffff" stroke="#9d9d9d"/>' +
   '<text x="' + fixedTrim(width / 2,2) + '" y="14" fill="#111" font-size="11" font-weight="700" text-anchor="middle">' + title + '</text>' +
   '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="#7e7e7e"/>' +
   '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="#7e7e7e"/>' +
   lslLine + avgLine + uslLine + line + dots + xTicks + lslLabel + uslLabel +
   '<text x="' + fixedTrim(width / 2,2) + '" y="' + fixedTrim(height - 4,2) + '" fill="#111" font-size="11" text-anchor="middle">부분군</text>' +
   '<text x="15" y="' + fixedTrim(top + plotH/2,2) + '" fill="#111" font-size="11" text-anchor="middle" transform="rotate(-90 15 ' + fixedTrim(top + plotH/2,2) + ')">' + yLabel + '</text>' +
   '</svg>';
 }
 function targetPlotTooltipHtml(entry, useOverall){
  return '<div class="qpc-target-tip-card">' +
   '<div class="qpc-target-tip-meta">공정: ' + esc(entry.label || entry.proc || '-') + '</div>' +
   '<div class="qpc-target-tip-meta">표준편차 유형: ' + esc(targetStdTypeLabel(useOverall)) + '</div>' +
   '<div class="qpc-target-tip-svg">' + targetPlotTooltipSvg(entry, useOverall) + '</div>' +
   '</div>';
 }
 function targetPlotUseOverallBox(box){
  var raw = box ? String(box.getAttribute('data-use-overall') || '').trim() : '';
  if (raw === '1') return true;
  if (raw === '0') return false;
  var preview = box ? box.querySelector('[data-role="legend-side-preview"]') : null;
  if (preview){
   if (preview.querySelector('.qpc-target-marker--within') && !preview.querySelector('.qpc-target-marker--overall')) return false;
   if (preview.querySelector('.qpc-target-marker--overall') && !preview.querySelector('.qpc-target-marker--within')) return true;
  }
  var txt = preview ? String(preview.textContent || '') : '';
  if (txt.indexOf('군내') >= 0 && txt.indexOf('전체') < 0) return false;
  return true;
 }
 function targetPlotSvg(entry, opts){
  var width = 620, height = 470;
  var left = 44, right = 18, top = 10, bottom = 38;
  var plotW = width - left - right;
  var plotH = height - top - bottom;
  var xMin = -0.6, xMax = 0.6;
  var yMin = 0, yMax = 0.30;
  var useOverall = !opts || opts.useOverall !== false;
  var sigma = useOverall ? entry.sigmaOverall : entry.sigmaWithin;
  var hasSpecs = isFinite(entry.lsl) && isFinite(entry.usl) && entry.usl > entry.lsl;
  var specWidth = hasSpecs ? (entry.usl - entry.lsl) : NaN;
  var targetRaw = Number(entry && entry.target);
  var targetInsideSpecs = hasSpecs && isFinite(targetRaw) && targetRaw > entry.lsl && targetRaw < entry.usl;
  var center = targetInsideSpecs ? targetRaw : (hasSpecs ? ((entry.lsl + entry.usl) / 2) : (isFinite(targetRaw) ? targetRaw : entry.avg));
  var normX = (hasSpecs && isFinite(entry.avg) && isFinite(center) && isFinite(specWidth) && specWidth > 0) ? ((entry.avg - center) / specWidth) : NaN;
  var normY = (hasSpecs && isFinite(sigma) && isFinite(specWidth) && specWidth > 0) ? (sigma / specWidth) : NaN;
  var ppkVal = clampNum(isFinite(parseNum(opts && opts.ppk)) ? parseNum(opts && opts.ppk) : 1, 0.20, 2.50);
  var apexY = 1 / (6 * ppkVal);
  function x(v){ return left + ((v - xMin) / (xMax - xMin)) * plotW; }
  function y(v){ return top + plotH - ((v - yMin) / (yMax - yMin)) * plotH; }
  var xTicks = [-0.6,-0.4,-0.2,0,0.2,0.4,0.6];
  var yTicks = [0,0.05,0.10,0.15,0.20,0.25,0.30];
  var hGrid = yTicks.map(function(v){
   var yy = y(v);
   return '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="rgba(0,0,0,.08)"/>';
  }).join('');
  var xAxisTicks = xTicks.map(function(v){ return '<line x1="' + fixedTrim(x(v),2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(x(v),2) + '" y2="' + fixedTrim(top + plotH + 4,2) + '" stroke="rgba(0,0,0,.45)"/><text x="' + fixedTrim(x(v),2) + '" y="' + fixedTrim(top + plotH + 16,2) + '" fill="rgba(17,17,17,.92)" font-size="10" text-anchor="middle">' + esc(fmtTargetTick(v)) + '</text>'; }).join('');
  var yAxisTicks = yTicks.map(function(v){ return '<line x1="' + fixedTrim(left - 4,2) + '" y1="' + fixedTrim(y(v),2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(y(v),2) + '" stroke="rgba(0,0,0,.45)"/><text x="' + fixedTrim(left - 7,2) + '" y="' + fixedTrim(y(v) + 3,2) + '" fill="rgba(17,17,17,.92)" font-size="10" text-anchor="end">' + esc(v === 0 ? '0' : fixedTrim(v,2)) + '</text>'; }).join('');
  var tri = '<path d="M' + fixedTrim(x(-0.5),2) + ' ' + fixedTrim(y(0),2) + ' L' + fixedTrim(x(0),2) + ' ' + fixedTrim(y(apexY),2) + ' L' + fixedTrim(x(0.5),2) + ' ' + fixedTrim(y(0),2) + '" fill="none" stroke="#ff6672" stroke-width="1.2"/>';
  var marker = '';
  if (isFinite(normX) && isFinite(normY)){
   var px = x(clampNum(normX, xMin, xMax));
   var py = y(clampNum(normY, yMin, yMax));
   var hitType = useOverall ? 'overall' : 'within';
   marker = '<rect x="' + fixedTrim(px - 2.5,2) + '" y="' + fixedTrim(py - 2.5,2) + '" width="5" height="5" fill="transparent" stroke="rgba(17,17,17,.95)" stroke-width="1.1"/>' +
    '<rect x="' + fixedTrim(px - 8,2) + '" y="' + fixedTrim(py - 8,2) + '" width="16" height="16" fill="transparent" stroke="transparent" data-role="target-marker-hit" data-hit-type="' + hitType + '"/>';
  }
  var empty = (!hasSpecs || !isFinite(normX) || !isFinite(normY)) ? '<text x="' + fixedTrim(left + plotW/2,2) + '" y="' + fixedTrim(top + plotH/2,2) + '" fill="rgba(0,0,0,.45)" text-anchor="middle" font-size="11">규격 한계와 데이터가 있어야 표시됩니다.</text>' : '';
  return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
   '<rect x="0.5" y="0.5" width="' + (width - 1) + '" height="' + (height - 1) + '" fill="#f8f8f8" stroke="#b7b7b7"/>' +
   hGrid +
   '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(0,0,0,.55)"/>' +
   '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(0,0,0,.55)"/>' +
   tri + marker + xAxisTicks + yAxisTicks + empty +
   '<text x="' + fixedTrim(left + plotW/2,2) + '" y="' + (height - 6) + '" fill="rgba(17,17,17,.96)" font-size="11" text-anchor="middle">규격으로 표준화된 평균</text>' +
   '<text x="14" y="' + fixedTrim(top + plotH/2,2) + '" fill="rgba(17,17,17,.96)" font-size="11" text-anchor="middle" transform="rotate(-90 14 ' + fixedTrim(top + plotH/2,2) + ')">규격으로 표준화된 표준편차</text>' +
   '</svg>';
 }
 function renderTargetPlotBox(box){
  if (!box || !payload || !payload.entries) return;
  var idx = parseInt(box.getAttribute('data-entry-index') || '-1', 10);
  if (!(idx >= 0) || !payload.entries[idx]) return;
  var entry = payload.entries[idx];
  var useOverall = targetPlotUseOverallBox(box);
  box.setAttribute('data-use-overall', useOverall ? '1' : '0');
  var textEl = box.querySelector('[data-role="ppk-text"]');
  var rangeEl = box.querySelector('[data-role="ppk-range"]');
  var ppk = parseNum(textEl ? textEl.value : '1');
  if (!isFinite(ppk)) ppk = parseNum(rangeEl ? rangeEl.value : '1');
  ppk = clampNum(isFinite(ppk) ? ppk : 1, 0.20, 2.50);
  if (textEl) textEl.value = fixedTrim(ppk, 2);
  if (rangeEl) rangeEl.value = String(ppk);
  var host = box.querySelector('[data-role="target-svg"]');
  if (host) host.innerHTML = targetPlotSvg(entry, { useOverall: useOverall, ppk: ppk });
  var tip = box.querySelector('[data-role="target-hover-tip"]');
  if (tip){ tip.hidden = true; tip.innerHTML = ''; }
 }
 function hideAllTargetHoverTips(){
  Array.prototype.forEach.call(document.querySelectorAll('[data-role="target-hover-tip"]'), function(el){ el.hidden = true; el.innerHTML = ''; });
 }
 function showTargetHoverTip(hitEl, clientX, clientY){
  var box = hitEl && hitEl.closest ? hitEl.closest('.qpc-target-grid') : null;
  if (!box || !payload || !payload.entries) return;
  var idx = parseInt(box.getAttribute('data-entry-index') || '-1', 10);
  if (!(idx >= 0) || !payload.entries[idx]) return;
  var entry = payload.entries[idx];
  var tip = box.querySelector('[data-role="target-hover-tip"]');
  var main = hitEl.closest('.qpc-target-main');
  if (!tip || !main) return;
  var useOverall = (hitEl.getAttribute('data-hit-type') || '') !== 'within';
  tip.innerHTML = targetPlotTooltipHtml(entry, useOverall);
  tip.hidden = false;
  var mainRect = main.getBoundingClientRect();
  var tipRect = tip.getBoundingClientRect();
  var left = (clientX - mainRect.left) + 14;
  var top = (clientY - mainRect.top) - 14;
  if (left + tipRect.width > mainRect.width - 6) left = Math.max(6, mainRect.width - tipRect.width - 6);
  if (top + tipRect.height > mainRect.height - 6) top = Math.max(6, mainRect.height - tipRect.height - 6);
  if (top < 6) top = 6;
  tip.style.left = fixedTrim(left,2) + 'px';
  tip.style.top = fixedTrim(top,2) + 'px';
 }
 function capabilityIndexPlotSvg(entry, refPpk){
  var width = 442, height = 586;
  var left = 42, right = 18, top = 10, bottom = 150;
  var plotW = width - left - right;
  var plotH = height - top - bottom;
  var refVal = clampNum(parseNum(refPpk), 0.20, 2.50);
  var rawPpk = Number(entry && entry.ppk);
  var yMaxBase = Math.ceil((Math.max(refVal, isFinite(rawPpk) ? rawPpk : 0) + 0.25) * 2) / 2;
  var yMax = Math.max(2.2, yMaxBase);
  var xMid = left + plotW / 2;
  function y(v){ return top + plotH - ((v - 0) / (yMax - 0)) * plotH; }
  var yTicks = [];
  for (var v = 0; v <= yMax + 1e-9; v += 0.5) yTicks.push(Number(v.toFixed(1)));
  var hGrid = yTicks.map(function(v){
   var yy = y(v);
   var stroke = Math.abs(v - Math.round(v)) < 1e-9 ? 'rgba(255,255,255,.08)' : 'rgba(255,255,255,.05)';
   return '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="' + stroke + '"/>';
  }).join('');
  var yAxisTicks = yTicks.map(function(v){
   var yy = y(v);
   var label = Math.abs(v - Math.round(v)) < 1e-9 ? String(Math.round(v)) : '';
   return '<line x1="' + fixedTrim(left - 4,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="rgba(0,0,0,.45)"/>' + (label ? '<text x="' + fixedTrim(left - 8,2) + '" y="' + fixedTrim(yy + 3,2) + '" fill="rgba(17,17,17,.92)" font-size="10" text-anchor="end">' + esc(label) + '</text>' : '');
  }).join('');
  var xAxis = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(0,0,0,.45)"/>';
  var yAxis = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(0,0,0,.45)"/>';
  var refLine = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(refVal),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(refVal),2) + '" stroke="#ff6672" stroke-width="1.15"/>';
  var marker = '';
  if (isFinite(rawPpk)){
   var py = y(clampNum(rawPpk, 0, yMax));
   marker = '<rect x="' + fixedTrim(xMid - 3,2) + '" y="' + fixedTrim(py - 3,2) + '" width="6" height="6" fill="transparent" stroke="rgba(17,17,17,.95)" stroke-width="1.1"/>';
  }
  var labelText = esc(entry && (entry.label || entry.proc) || '-');
  return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
   '<rect x="' + fixedTrim(left,2) + '" y="' + fixedTrim(top,2) + '" width="' + fixedTrim(plotW,2) + '" height="' + fixedTrim(plotH,2) + '" fill="transparent" stroke="#b7b7b7"/>' +
   hGrid + xAxis + yAxis + refLine + marker + yAxisTicks +
   '<text x="' + fixedTrim(xMid,2) + '" y="' + fixedTrim(top + plotH + 50,2) + '" fill="rgba(17,17,17,.92)" font-size="10" text-anchor="middle" transform="rotate(-90 ' + fixedTrim(xMid,2) + ' ' + fixedTrim(top + plotH + 50,2) + ')">' + labelText + '</text>' +
   '<text x="' + fixedTrim(xMid,2) + '" y="' + (height - 18) + '" fill="rgba(17,17,17,.92)" font-size="11" text-anchor="middle">공정</text>' +
   '<text x="14" y="' + fixedTrim(top + plotH/2,2) + '" fill="rgba(17,17,17,.96)" font-size="11" text-anchor="middle" transform="rotate(-90 14 ' + fixedTrim(top + plotH/2,2) + ')">Ppk</text>' +
   '</svg>';
 }
 function normalizeCapabilityIndexBox(box){
  if (!box) return;
  box.style.display = 'grid';
  box.style.gridTemplateColumns = 'minmax(0,442px) 128px';
  box.style.gap = '12px';
  box.style.alignItems = 'start';
  box.style.maxWidth = '582px';
  var main = box.querySelector('.qpc-index-main');
  if (main){
   main.style.width = '100%';
   main.style.maxWidth = '442px';
  }
  var host = box.querySelector('[data-role="index-svg"]');
  if (host){
   host.style.width = '100%';
   host.style.maxWidth = '442px';
  }
  var side = box.querySelector('.qpc-index-side');
  if (!side) return;
  side.style.width = '128px';
  var textEl = side.querySelector('[data-role="index-ppk-text"]');
  var line = textEl && textEl.parentElement ? textEl.parentElement : null;
  if (line){
   line.style.marginBottom = '6px';
   var label = line.previousElementSibling;
   if (label && label.tagName === 'DIV' && String(label.textContent || '').trim() === 'Ppk') label.remove();
  }
 }
 function renderCapabilityIndexPlotBox(box){
  if (!box || !payload || !payload.entries) return;
  var idx = parseInt(box.getAttribute('data-entry-index') || '-1', 10);
  if (!(idx >= 0) || !payload.entries[idx]) return;
  var entry = payload.entries[idx];
  var textEl = box.querySelector('[data-role="index-ppk-text"]');
  var rangeEl = box.querySelector('[data-role="index-ppk-range"]');
  var ref = parseNum(textEl ? textEl.value : '1');
  if (!isFinite(ref)) ref = parseNum(rangeEl ? rangeEl.value : '1');
  ref = clampNum(isFinite(ref) ? ref : 1, 0.20, 2.50);
  if (textEl) textEl.value = fixedTrim(ref, 2);
  if (rangeEl) rangeEl.value = String(ref);
  var host = box.querySelector('[data-role="index-svg"]');
  if (host) host.innerHTML = capabilityIndexPlotSvg(entry, ref);
 }
 document.title = payload.title ? String(payload.title) : titleBase;
 if (root) root.innerHTML = String(payload.html || '');
 Array.prototype.forEach.call(document.querySelectorAll('.qpc-index-grid'), function(box){ normalizeCapabilityIndexBox(box); });
 Array.prototype.forEach.call(document.querySelectorAll('.qpc-target-grid'), function(box){ renderTargetPlotBox(box); });
 Array.prototype.forEach.call(document.querySelectorAll('.qpc-index-grid'), function(box){ renderCapabilityIndexPlotBox(box); });
 document.addEventListener('mousemove', function(ev){
  var hit = ev.target && ev.target.closest ? ev.target.closest('[data-role="target-marker-hit"]') : null;
  if (hit) showTargetHoverTip(hit, ev.clientX, ev.clientY);
  else hideAllTargetHoverTips();
 });
 document.addEventListener('input', function(ev){
  if (ev.target && ev.target.matches && ev.target.matches('.qpc-target-ppk-input')){
   var tbox = ev.target.closest('.qpc-target-grid');
   var trange = tbox ? tbox.querySelector('[data-role="ppk-range"]') : null;
   var tv = clampNum(isFinite(parseNum(ev.target.value)) ? parseNum(ev.target.value) : 1, 0.20, 2.50);
   if (trange) trange.value = String(tv);
   renderTargetPlotBox(tbox);
  }
  if (ev.target && ev.target.matches && ev.target.matches('.qpc-target-range')){
   var tbox2 = ev.target.closest('.qpc-target-grid');
   var ttext = tbox2 ? tbox2.querySelector('[data-role="ppk-text"]') : null;
   if (ttext) ttext.value = fixedTrim(parseNum(ev.target.value), 2);
   renderTargetPlotBox(tbox2);
  }
  if (ev.target && ev.target.matches && ev.target.matches('.qpc-index-ppk-input')){
   var box = ev.target.closest('.qpc-index-grid');
   var range = box ? box.querySelector('[data-role="index-ppk-range"]') : null;
   var v = clampNum(isFinite(parseNum(ev.target.value)) ? parseNum(ev.target.value) : 1, 0.20, 2.50);
   if (range) range.value = String(v);
   renderCapabilityIndexPlotBox(box);
  }
  if (ev.target && ev.target.matches && ev.target.matches('.qpc-index-range')){
   var box2 = ev.target.closest('.qpc-index-grid');
   var text2 = box2 ? box2.querySelector('[data-role="index-ppk-text"]') : null;
   if (text2) text2.value = fixedTrim(parseNum(ev.target.value), 2);
   renderCapabilityIndexPlotBox(box2);
  }
 });
})();
</script>
</body>
</html>
