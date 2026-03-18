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
.qpc-performance-grid{display:grid;grid-template-columns:minmax(0, 620px) 186px;gap:12px;align-items:start;max-width:818px;}
.qpc-performance-main{padding:6px 6px 2px;max-width:620px;}
.qpc-performance-side{padding:8px 10px 0;min-width:186px;width:auto;box-sizing:border-box;}
.qpc-performance-side-title{font-size:11px;font-weight:700;margin-bottom:8px;}
.qpc-performance-legend{display:flex;flex-direction:column;gap:4px;font-size:11px;line-height:1.22;margin-bottom:14px;}
.qpc-performance-legend-item{display:flex;align-items:center;gap:6px;white-space:nowrap;}
.qpc-performance-swatch{width:16px;height:8px;display:inline-block;flex:0 0 16px;border:1px solid rgba(0,0,0,.06);}
.qpc-performance-line{width:16px;height:0;display:inline-block;border-top:1.6px solid #ff6672;box-sizing:border-box;flex:0 0 16px;}
.qpc-performance-ppk-label,.qpc-performance-stability-label{font-size:11px;font-weight:700;margin-bottom:4px;}
.qpc-performance-ppk-line,.qpc-performance-stability-line{display:grid;grid-template-columns:auto 48px minmax(104px,1fr);align-items:center;column-gap:8px;margin-bottom:10px;}
.qpc-performance-ppk-badge,.qpc-performance-stability-badge{min-width:52px;height:18px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(0,0,0,.18);background:#f7f7f7;font-size:11px;font-weight:700;}
.qpc-performance-ppk-input,.qpc-performance-stability-input{width:48px;height:20px;border:1px solid rgba(0,0,0,.18);background:#f7f7f7;color:var(--qpc-text);padding:0 4px;font-size:11px;text-align:center;box-sizing:border-box;}
.qpc-performance-ppk-range,.qpc-performance-stability-range{display:block;width:100%;min-width:104px;accent-color:#b9d8ff;}
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
  var width = 470, height = 620;
  var left = 48, right = 72, top = 10, bottom = 134;
  var plotW = width - left - right;
  var plotH = height - top - bottom;
  var plotBottom = top + plotH;
  var refVal = clampNum(parseNum(refPpk), 0.20, 2.50);
  var rawPpk = Number(entry && entry.ppk);
  var yMaxBase = Math.ceil((Math.max(refVal, isFinite(rawPpk) ? rawPpk : 0) + 0.25) * 2) / 2;
  var yMax = Math.max(2.2, yMaxBase);
  var xMid = left + plotW / 2;
  var categoryX = left + (plotW * 0.335);
  var tickBottomY = plotBottom + 7;
  var labelY = plotBottom + 40;
  var axisTitleX = xMid;
  var axisTitleY = plotBottom + 84;
  function y(v){ return top + plotH - ((v - 0) / (yMax - 0)) * plotH; }
  var yTicks = [];
  for (var v = 0; v <= yMax + 1e-9; v += 0.5) yTicks.push(Number(v.toFixed(1)));
  var hGrid = yTicks.map(function(v){
   var yy = y(v);
   var stroke = Math.abs(v - Math.round(v)) < 1e-9 ? 'rgba(0,0,0,.08)' : 'rgba(0,0,0,.05)';
   return '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="' + stroke + '"/>';
  }).join('');
  var yAxisTicks = yTicks.map(function(v){
   var yy = y(v);
   var label = Math.abs(v) < 1e-9 ? '0' : Number(v).toFixed(1);
   return '<line x1="' + fixedTrim(left - 4,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="rgba(0,0,0,.45)"/>' + '<text x="' + fixedTrim(left - 8,2) + '" y="' + fixedTrim(yy + 3,2) + '" fill="rgba(17,17,17,.92)" font-size="10" text-anchor="end">' + esc(label) + '</text>';
  }).join('');
  var xAxis = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(plotBottom,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(plotBottom,2) + '" stroke="rgba(0,0,0,.45)"/>';
  var xAxisMidTick = '<line x1="' + fixedTrim(categoryX,2) + '" y1="' + fixedTrim(plotBottom,2) + '" x2="' + fixedTrim(categoryX,2) + '" y2="' + fixedTrim(tickBottomY,2) + '" stroke="rgba(0,0,0,.45)"/>';
  var yAxis = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(plotBottom,2) + '" stroke="rgba(0,0,0,.45)"/>';
  var frame = '<rect x="' + fixedTrim(left,2) + '" y="' + fixedTrim(top,2) + '" width="' + fixedTrim(plotW,2) + '" height="' + fixedTrim(plotH,2) + '" fill="#f8f8f8" stroke="#b7b7b7"/>';
  var refLine = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(refVal),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(refVal),2) + '" stroke="#ff6672" stroke-width="1.15"/>';
  var marker = '';
  if (isFinite(rawPpk)){
   var py = y(clampNum(rawPpk, 0, yMax));
   var markerCx = categoryX;
   marker = '<rect x="' + fixedTrim(markerCx - 2.5,2) + '" y="' + fixedTrim(py - 2.5,2) + '" width="5" height="5" fill="transparent" stroke="rgba(17,17,17,.95)" stroke-width="1.05"/>';
  }
  var labelText = esc(entry && (entry.label || entry.proc) || '-');
  return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
   frame + hGrid + xAxis + xAxisMidTick + yAxis + refLine + marker + yAxisTicks +
   '<text x="' + fixedTrim(categoryX,2) + '" y="' + fixedTrim(labelY,2) + '" fill="rgba(17,17,17,.92)" font-size="10" text-anchor="middle" transform="rotate(-90 ' + fixedTrim(categoryX,2) + ' ' + fixedTrim(labelY,2) + ')">' + labelText + '</text>' +
   '<text x="' + fixedTrim(axisTitleX,2) + '" y="' + fixedTrim(axisTitleY,2) + '" fill="rgba(17,17,17,.92)" font-size="11" text-anchor="middle">공정</text>' +
   '<text x="14" y="' + fixedTrim(top + plotH/2,2) + '" fill="rgba(17,17,17,.96)" font-size="11" text-anchor="middle" transform="rotate(-90 14 ' + fixedTrim(top + plotH/2,2) + ')">Ppk</text>' +
   '</svg>';
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
 function processPerformanceLegendSideHtml(refPpk, refStability){
  return '<div class="qpc-performance-side-title">범례</div>' +
   '<div class="qpc-performance-legend">' +
    '<div class="qpc-performance-legend-item"><span class="qpc-performance-swatch" style="background:#b6ddb2"></span><span>공정 능력이 있고 안정적</span></div>' +
    '<div class="qpc-performance-legend-item"><span class="qpc-performance-swatch" style="background:#e4e1a8"></span><span>공정 능력이 있지만 불안정</span></div>' +
    '<div class="qpc-performance-legend-item"><span class="qpc-performance-swatch" style="background:#e7ccb1"></span><span>공정 능력이 없지만 안정적</span></div>' +
    '<div class="qpc-performance-legend-item"><span class="qpc-performance-swatch" style="background:#efc0c8"></span><span>공정 능력이 없고 불안정</span></div>' +
    '<div class="qpc-performance-legend-item"><span class="qpc-performance-line"></span><span>군내 Cpk=1</span></div>' +
   '</div>' +
   '<div class="qpc-performance-ppk-label">전체 Ppk</div>' +
   '<div class="qpc-performance-ppk-line"><span class="qpc-performance-ppk-badge">전체 Ppk</span><input class="qpc-performance-ppk-input" type="text" value="' + esc(fixedTrim(refPpk, 2)) + '" data-role="performance-ppk-text"><input class="qpc-performance-ppk-range" type="range" min="0.20" max="2.50" step="0.05" value="' + esc(String(refPpk)) + '" data-role="performance-ppk-range"></div>' +
   '<div class="qpc-performance-stability-label">안정성 지수</div>' +
   '<div class="qpc-performance-stability-line"><span class="qpc-performance-stability-badge">안정성</span><input class="qpc-performance-stability-input" type="text" value="' + esc(fixedTrim(refStability, 2)) + '" data-role="performance-stability-text"><input class="qpc-performance-stability-range" type="range" min="0.50" max="3.00" step="0.05" value="' + esc(String(refStability)) + '" data-role="performance-stability-range"></div>';
 }
 function processPerformancePlotSvg(entry, opts){
  var width = 620, height = 470;
  var left = 58, right = 18, top = 10, bottom = 42;
  var plotW = width - left - right;
  var plotH = height - top - bottom;
  var refPpk = clampNum(parseNum(opts && opts.refPpk), 0.20, 2.50);
  var refStability = clampNum(parseNum(opts && opts.refStability), 0.50, 3.00);
  var xMax = Math.max(2.15, Math.ceil(Math.max(refStability, Number(entry && entry.stability) || 0, 2.0) * 20) / 20 + 0.05);
  var yMin = -0.10;
  var yMax = Math.max(3.10, Math.ceil(Math.max(refPpk, Number(entry && entry.ppk) || 0, 3.0) * 10) / 10 + 0.10);
  function x(v){ return left + ((v - 0) / Math.max(1e-9, xMax)) * plotW; }
  function y(v){ return top + plotH - ((v - yMin) / Math.max(1e-9, yMax - yMin)) * plotH; }
  var bg = '' +
   '<rect x="' + fixedTrim(left,2) + '" y="' + fixedTrim(top,2) + '" width="' + fixedTrim(Math.max(0, x(refStability) - left),2) + '" height="' + fixedTrim(Math.max(0, y(refPpk) - top),2) + '" fill="#b6ddb2"/>' +
   '<rect x="' + fixedTrim(x(refStability),2) + '" y="' + fixedTrim(top,2) + '" width="' + fixedTrim(Math.max(0, left + plotW - x(refStability)),2) + '" height="' + fixedTrim(Math.max(0, y(refPpk) - top),2) + '" fill="#e4e1a8"/>' +
   '<rect x="' + fixedTrim(left,2) + '" y="' + fixedTrim(y(refPpk),2) + '" width="' + fixedTrim(Math.max(0, x(refStability) - left),2) + '" height="' + fixedTrim(Math.max(0, top + plotH - y(refPpk)),2) + '" fill="#e7ccb1"/>' +
   '<rect x="' + fixedTrim(x(refStability),2) + '" y="' + fixedTrim(y(refPpk),2) + '" width="' + fixedTrim(Math.max(0, left + plotW - x(refStability)),2) + '" height="' + fixedTrim(Math.max(0, top + plotH - y(refPpk)),2) + '" fill="#efc0c8"/>';
  var xTicks = [];
  for (var xv = 0; xv <= xMax + 1e-9; xv += 0.25) xTicks.push(Number(xv.toFixed(2)));
  var yTicks = [];
  for (var yv = -1; yv <= yMax + 1e-9; yv += 0.5) if (yv >= yMin - 1e-9) yTicks.push(Number(yv.toFixed(1)));
  var xAxisTicks = xTicks.map(function(v){
   var xx = x(v);
   var major = Math.abs((v * 100) % 50) < 1e-9;
   var label = major ? fixedTrim(v, v % 1 === 0 ? 0 : 1) : '';
   return '<line x1="' + fixedTrim(xx,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(xx,2) + '" y2="' + fixedTrim(top + plotH + (major ? 4 : 2),2) + '" stroke="rgba(0,0,0,.45)"/>' +
    (label ? '<text x="' + fixedTrim(xx,2) + '" y="' + fixedTrim(top + plotH + 18,2) + '" fill="rgba(17,17,17,.92)" font-size="10" text-anchor="middle">' + esc(label) + '</text>' : '');
  }).join('');
  var yAxisTicks = yTicks.map(function(v){
   var yy = y(v);
   var label = Math.abs((v * 10) % 10) < 1e-9 ? fixedTrim(v, 0) : '';
   return '<line x1="' + fixedTrim(left - 4,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="rgba(0,0,0,.45)"/>' +
    (label ? '<text x="' + fixedTrim(left - 8,2) + '" y="' + fixedTrim(yy + 3,2) + '" fill="rgba(17,17,17,.92)" font-size="10" text-anchor="end">' + esc(label) + '</text>' : '');
  }).join('');
  var curveParts = [];
  for (var cx = 1.0; cx <= xMax + 1e-9; cx += 0.02){
   var cy = 1 / cx;
   if (cy < yMin || cy > yMax) continue;
   curveParts.push((curveParts.length ? 'L' : 'M') + fixedTrim(x(cx),2) + ' ' + fixedTrim(y(cy),2));
  }
  var curve = curveParts.length ? '<path d="' + curveParts.join(' ') + '" fill="none" stroke="#ff6672" stroke-width="2.1"/>' : '';
  var point = '';
  var rawStability = Number(entry && entry.stability);
  var rawPpk = Number(entry && entry.ppk);
  if (isFinite(rawStability) && isFinite(rawPpk)){
   var px = x(clampNum(rawStability, 0, xMax));
   var py = y(clampNum(rawPpk, yMin, yMax));
   point = '<rect x="' + fixedTrim(px - 3,2) + '" y="' + fixedTrim(py - 3,2) + '" width="6" height="6" fill="transparent" stroke="rgba(17,17,17,.95)" stroke-width="1.1"/>';
  }
  return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
   bg +
   '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(0,0,0,.55)"/>' +
   '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(0,0,0,.55)"/>' +
   curve + point + xAxisTicks + yAxisTicks +
   '<text x="' + fixedTrim(left + plotW/2,2) + '" y="' + (height - 6) + '" fill="rgba(17,17,17,.96)" font-size="11" text-anchor="middle">안정성 지수</text>' +
   '<text x="14" y="' + fixedTrim(top + plotH/2,2) + '" fill="rgba(17,17,17,.96)" font-size="11" text-anchor="middle" transform="rotate(-90 14 ' + fixedTrim(top + plotH/2,2) + ')">공정 능력 전체 Ppk</text>' +
   '</svg>';
 }
 function processPerformancePlotHtml(entry, idx){
  var refPpk = 1;
  var refStability = 1.25;
  return '<div class="qpc-performance-grid" data-entry-index="' + idx + '">' +
   '<div class="qpc-performance-main"><div class="qpc-svgbox" data-role="performance-svg">' + processPerformancePlotSvg(entry, { refPpk: refPpk, refStability: refStability }) + '</div></div>' +
   '<div class="qpc-performance-side">' + processPerformanceLegendSideHtml(refPpk, refStability) + '</div>' +
   '</div>';
 }

 function standardizedBySigmaValues(entry, isWithin){
  if (!entry || !Array.isArray(entry.values)) return [];
  var sigma = isWithin ? Number(entry.sigmaWithin) : Number(entry.sigmaOverall);
  var avg = Number(entry.avg);
  if (!(isFinite(avg) && isFinite(sigma) && sigma > 0)) return [];
  return entry.values.filter(function(v){ return isFinite(v); }).map(function(v){ return (v - avg) / sigma; });
 }
 function normalizedSigmaBoxPlotSvg(entry, isWithin){
  var values = standardizedBySigmaValues(entry, isWithin);
  var sigma = isWithin ? Number(entry.sigmaWithin) : Number(entry.sigmaOverall);
  var avg = Number(entry.avg);
  var width = 700, height = 122;
  var left = 30, right = 96, top = 8;
  var plotW = width - left - right;
  var plotH = 64;
  var plotBottom = top + plotH;
  var tickLineBottom = plotBottom + 4;
  var tickTextY = plotBottom + 16;
  var titleY = height - 2;
  var yMid = top + (plotH / 2);
  var boxH = 16;
  var caption = isWithin ? '평균 및 군내 표준편차를 사용하여 표준화됨' : '평균 및 전체 표준편차를 사용하여 표준화됨';
  if (!(isFinite(avg) && isFinite(sigma) && sigma > 0) || !values.length){
   return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true"><rect x="' + fixedTrim(left,2) + '" y="' + fixedTrim(top,2) + '" width="' + fixedTrim(plotW,2) + '" height="' + fixedTrim(plotH,2) + '" fill="#f8f8f8" stroke="#b7b7b7" stroke-width="1"/><text x="' + fixedTrim(left + (plotW / 2), 2) + '" y="' + fixedTrim(top + (plotH / 2) + 4, 2) + '" fill="#444" text-anchor="middle" font-size="11">표준화에 필요한 값이 없습니다.</text></svg>';
  }
  var sorted = values.slice().sort(function(a,b){ return a - b; });
  function q(arr, p){
   if (!arr.length) return NaN;
   var pos = (arr.length - 1) * p;
   var lo = Math.floor(pos), hi = Math.ceil(pos);
   if (lo === hi) return arr[lo];
   return arr[lo] + (arr[hi] - arr[lo]) * (pos - lo);
  }
  function niceStep(raw, maxTicks){
   var n = Math.abs(Number(raw));
   var ticks = Math.max(2, Number(maxTicks) || 6);
   if (!(n > 0) || !isFinite(n)) return 1;
   var target = n / Math.max(1, ticks - 1);
   var exp = Math.floor(Math.log(target) / Math.LN10);
   var frac = target / Math.pow(10, exp);
   var niceFrac;
   if (frac <= 1) niceFrac = 1;
   else if (frac <= 2) niceFrac = 2;
   else if (frac <= 5) niceFrac = 5;
   else niceFrac = 10;
   return niceFrac * Math.pow(10, exp);
  }
  var q1 = q(sorted, 0.25);
  var med = q(sorted, 0.50);
  var q3 = q(sorted, 0.75);
  var iqr = Math.max(0, q3 - q1);
  var lowFence = q1 - (1.5 * iqr);
  var highFence = q3 + (1.5 * iqr);
  var nonOutliers = sorted.filter(function(v){ return v >= lowFence && v <= highFence; });
  var whiskerLow = nonOutliers.length ? nonOutliers[0] : sorted[0];
  var whiskerHigh = nonOutliers.length ? nonOutliers[nonOutliers.length - 1] : sorted[sorted.length - 1];
  var specDefs = [];
  if (isFinite(entry.lsl)) specDefs.push({ v:(Number(entry.lsl) - avg) / sigma, color:'#67d46f', width:'1.05' });
  specDefs.push({ v:0, color:'#67d46f', width:'1.05' });
  if (isFinite(entry.usl)) specDefs.push({ v:(Number(entry.usl) - avg) / sigma, color:'#67d46f', width:'1.05' });
  var refDefs = [
   { v:-0.5, color:'rgba(120,120,120,.92)', width:'1', dash:'3 3' },
   { v:0.5, color:'rgba(120,120,120,.92)', width:'1', dash:'3 3' }
  ];
  var domainVals = [whiskerLow, whiskerHigh, q1, med, q3, 0];
  specDefs.forEach(function(line){ if (isFinite(line.v)) domainVals.push(line.v); });
  var finiteDomainVals = domainVals.filter(function(v){ return isFinite(v); });
  var rawMin = Math.min.apply(null, finiteDomainVals);
  var rawMax = Math.max.apply(null, finiteDomainVals);
  if (!(isFinite(rawMin) && isFinite(rawMax))){ rawMin = -2; rawMax = 2; }
  if (!(rawMax > rawMin)){
   rawMin -= 1;
   rawMax += 1;
  }
  var rawRange = rawMax - rawMin;
  var pad = Math.max(rawRange * 0.015, 0.12);
  var majorStep = niceStep(rawRange + (pad * 2), 6);
  if (!(majorStep > 0)) majorStep = 1;
  var xMin = Math.floor((rawMin - pad) / majorStep) * majorStep;
  var xMax = Math.ceil((rawMax + pad) / majorStep) * majorStep;
  if (!(xMax > xMin)){
   xMin = rawMin - 1;
   xMax = rawMax + 1;
  }
  var majorCount = Math.round((xMax - xMin) / majorStep);
  if (majorCount > 8) {
   majorStep = niceStep(xMax - xMin, 5);
   xMin = Math.floor((rawMin - pad) / majorStep) * majorStep;
   xMax = Math.ceil((rawMax + pad) / majorStep) * majorStep;
  }
  var minorStep = majorStep / 2;
  if (!isFinite(minorStep) || minorStep <= 0) minorStep = majorStep;
  function x(v){ return left + ((v - xMin) / Math.max(1e-9, xMax - xMin)) * plotW; }
  var refLines = refDefs.filter(function(line){ return line.v >= xMin && line.v <= xMax; }).map(function(line){
   var xx = x(line.v);
   return '<line x1="' + fixedTrim(xx,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(xx,2) + '" y2="' + fixedTrim(plotBottom,2) + '" stroke="' + line.color + '" stroke-width="' + line.width + '" stroke-dasharray="' + line.dash + '"/>';
  }).join('');
  var specLines = specDefs.filter(function(line){ return isFinite(line.v) && line.v >= xMin && line.v <= xMax; }).map(function(line){
   var xx = x(line.v);
   return '<line x1="' + fixedTrim(xx,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(xx,2) + '" y2="' + fixedTrim(plotBottom,2) + '" stroke="' + line.color + '" stroke-width="' + line.width + '"/>';
  }).join('');
  var whiskerColor = 'rgba(0,0,0,.72)';
  var whisker = '<line x1="' + fixedTrim(x(whiskerLow), 2) + '" y1="' + fixedTrim(yMid, 2) + '" x2="' + fixedTrim(x(whiskerHigh), 2) + '" y2="' + fixedTrim(yMid, 2) + '" stroke="' + whiskerColor + '" stroke-width="1.05"/>' +
   '<line x1="' + fixedTrim(x(whiskerLow), 2) + '" y1="' + fixedTrim(yMid - 6, 2) + '" x2="' + fixedTrim(x(whiskerLow), 2) + '" y2="' + fixedTrim(yMid + 6, 2) + '" stroke="' + whiskerColor + '" stroke-width="1.05"/>' +
   '<line x1="' + fixedTrim(x(whiskerHigh), 2) + '" y1="' + fixedTrim(yMid - 6, 2) + '" x2="' + fixedTrim(x(whiskerHigh), 2) + '" y2="' + fixedTrim(yMid + 6, 2) + '" stroke="' + whiskerColor + '" stroke-width="1.05"/>';
  var boxTop = yMid - (boxH / 2);
  var box = '<rect x="' + fixedTrim(x(q1), 2) + '" y="' + fixedTrim(boxTop, 2) + '" width="' + fixedTrim(Math.max(1, x(q3) - x(q1)), 2) + '" height="' + fixedTrim(boxH, 2) + '" fill="none" stroke="rgba(0,0,0,.68)" stroke-width="1"/>' +
   '<line x1="' + fixedTrim(x(med), 2) + '" y1="' + fixedTrim(boxTop, 2) + '" x2="' + fixedTrim(x(med), 2) + '" y2="' + fixedTrim(boxTop + boxH, 2) + '" stroke="rgba(0,0,0,.72)" stroke-width="1"/>';
  var ticks = [];
  var minorStart = Math.ceil(xMin / minorStep) * minorStep;
  for (var mv = minorStart; mv <= xMax + (minorStep * 0.25); mv += minorStep){
   var mm = Math.round(mv * 1000000) / 1000000;
   var xMinor = x(mm);
   ticks.push('<line x1="' + fixedTrim(xMinor, 2) + '" y1="' + fixedTrim(plotBottom, 2) + '" x2="' + fixedTrim(xMinor, 2) + '" y2="' + fixedTrim(plotBottom + 3, 2) + '" stroke="rgba(0,0,0,.35)"/>');
  }
  var majorStart = Math.ceil(xMin / majorStep) * majorStep;
  for (var tv = majorStart; tv <= xMax + (majorStep * 0.25); tv += majorStep){
   var major = Math.round(tv * 1000000) / 1000000;
   var xx = x(major);
   var safeMajor = Math.abs(major) < Math.max(1e-9, majorStep / 1000) ? 0 : major;
   var label = Math.abs(safeMajor - Math.round(safeMajor)) < 1e-9 ? String(Math.round(safeMajor)) : fixedTrim(safeMajor, safeMajor % 1 === 0 ? 0 : 1);
   ticks.push('<line x1="' + fixedTrim(xx, 2) + '" y1="' + fixedTrim(plotBottom, 2) + '" x2="' + fixedTrim(xx, 2) + '" y2="' + fixedTrim(tickLineBottom, 2) + '" stroke="rgba(0,0,0,.45)"/><text x="' + fixedTrim(xx, 2) + '" y="' + fixedTrim(tickTextY, 2) + '" fill="rgba(17,17,17,.92)" font-size="11" text-anchor="middle">' + esc(label) + '</text>');
  }
  return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
   '<rect x="' + fixedTrim(left, 2) + '" y="' + fixedTrim(top, 2) + '" width="' + fixedTrim(plotW, 2) + '" height="' + fixedTrim(plotH, 2) + '" fill="#f8f8f8" stroke="#b7b7b7"/>' +
   '<line x1="' + fixedTrim(left, 2) + '" y1="' + fixedTrim(plotBottom, 2) + '" x2="' + fixedTrim(left + plotW, 2) + '" y2="' + fixedTrim(plotBottom, 2) + '" stroke="rgba(0,0,0,.55)"/>' +
   refLines + specLines + whisker + box + ticks.join('') +
   '<text x="' + fixedTrim(left + (plotW / 2), 2) + '" y="' + fixedTrim(titleY, 2) + '" fill="rgba(17,17,17,.92)" font-size="11.5" text-anchor="middle">' + caption + '</text>' +
   '<text x="' + fixedTrim(left + plotW + 12, 2) + '" y="' + fixedTrim(yMid + 4, 2) + '" fill="rgba(17,17,17,.92)" font-size="12">' + esc(entry.label || entry.proc || '') + '</text>' +
   '</svg>';
 }
 function normalizedSigmaBoxPlotHtml(entry, isWithin){
  return '<div class="qpc-svgbox" style="width:700px;max-width:100%;">' + normalizedSigmaBoxPlotSvg(entry, isWithin) + '</div>';
 }
 function renderProcessPerformancePlotBox(box){
  if (!box || !payload || !payload.entries) return;
  var idx = parseInt(box.getAttribute('data-entry-index') || '-1', 10);
  if (!(idx >= 0) || !payload.entries[idx]) return;
  var entry = payload.entries[idx];
  var ppkText = box.querySelector('[data-role="performance-ppk-text"]');
  var ppkRange = box.querySelector('[data-role="performance-ppk-range"]');
  var stabilityText = box.querySelector('[data-role="performance-stability-text"]');
  var stabilityRange = box.querySelector('[data-role="performance-stability-range"]');
  var refPpk = parseNum(ppkText ? ppkText.value : '1');
  if (!isFinite(refPpk)) refPpk = parseNum(ppkRange ? ppkRange.value : '1');
  refPpk = clampNum(isFinite(refPpk) ? refPpk : 1, 0.20, 2.50);
  var refStability = parseNum(stabilityText ? stabilityText.value : '1.25');
  if (!isFinite(refStability)) refStability = parseNum(stabilityRange ? stabilityRange.value : '1.25');
  refStability = clampNum(isFinite(refStability) ? refStability : 1.25, 0.50, 3.00);
  if (ppkText) ppkText.value = fixedTrim(refPpk, 2);
  if (ppkRange) ppkRange.value = String(refPpk);
  if (stabilityText) stabilityText.value = fixedTrim(refStability, 2);
  if (stabilityRange) stabilityRange.value = String(refStability);
  var host = box.querySelector('[data-role="performance-svg"]');
  if (host) host.innerHTML = processPerformancePlotSvg(entry, { refPpk: refPpk, refStability: refStability });
 }
 function injectProcessPerformanceSections(){
  var summaries = document.querySelectorAll('summary');
  var perfIdx = 0;
  Array.prototype.forEach.call(summaries, function(summary){
   if (String(summary.textContent || '').trim() !== '공정 성능 그림') return;
   var details = summary.parentElement;
   var body = summary.nextElementSibling;
   if (!body) return;
   var entry = payload && payload.entries && payload.entries[perfIdx];
   if (!entry) return;
   if (details && !details.hasAttribute('open')) details.setAttribute('open', 'open');
   body.className = 'qpc-report-sub-body';
   body.innerHTML = processPerformancePlotHtml(entry, perfIdx);
   perfIdx += 1;
  });
 }
 function injectNormalizedSigmaSections(){
  var summaries = document.querySelectorAll('summary');
  var normIdx = 0;
  Array.prototype.forEach.call(summaries, function(summary){
   if (String(summary.textContent || '').trim() !== '공정 성능 그림') return;
   var perfDetails = summary.parentElement;
   var groupBody = perfDetails ? perfDetails.parentElement : null;
   var entry = payload && payload.entries && payload.entries[normIdx];
   if (!groupBody || !entry) return;
   if (groupBody.querySelector('[data-role="within-normalized-box"]') || groupBody.querySelector('[data-role="overall-normalized-box"]')){
    normIdx += 1;
    return;
   }
   var withinDetails = document.createElement('details');
   withinDetails.className = 'qpc-report-sub';
   withinDetails.setAttribute('open', 'open');
   withinDetails.setAttribute('data-role', 'within-normalized-box');
   withinDetails.innerHTML = '<summary>군내 표준편차 정규화된 상자 그림</summary><div class="qpc-report-sub-body">' + normalizedSigmaBoxPlotHtml(entry, true) + '</div>';
   var overallDetails = document.createElement('details');
   overallDetails.className = 'qpc-report-sub';
   overallDetails.setAttribute('open', 'open');
   overallDetails.setAttribute('data-role', 'overall-normalized-box');
   overallDetails.innerHTML = '<summary>전체 표준편차 정규화된 상자 그림</summary><div class="qpc-report-sub-body">' + normalizedSigmaBoxPlotHtml(entry, false) + '</div>';
   if (perfDetails.nextSibling) groupBody.insertBefore(withinDetails, perfDetails.nextSibling);
   else groupBody.appendChild(withinDetails);
   if (withinDetails.nextSibling) groupBody.insertBefore(overallDetails, withinDetails.nextSibling);
   else groupBody.appendChild(overallDetails);
   normIdx += 1;
  });
 }
 document.title = payload.title ? String(payload.title) : titleBase;
 if (root) root.innerHTML = String(payload.html || '');
 injectProcessPerformanceSections();
 injectNormalizedSigmaSections();
 Array.prototype.forEach.call(document.querySelectorAll('.qpc-target-grid'), function(box){ renderTargetPlotBox(box); });
 Array.prototype.forEach.call(document.querySelectorAll('.qpc-index-grid'), function(box){ renderCapabilityIndexPlotBox(box); });
 Array.prototype.forEach.call(document.querySelectorAll('.qpc-performance-grid'), function(box){ renderProcessPerformancePlotBox(box); });
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
  if (ev.target && ev.target.matches && ev.target.matches('.qpc-performance-ppk-input')){
   var pbox = ev.target.closest('.qpc-performance-grid');
   var prange = pbox ? pbox.querySelector('[data-role="performance-ppk-range"]') : null;
   var pv = clampNum(isFinite(parseNum(ev.target.value)) ? parseNum(ev.target.value) : 1, 0.20, 2.50);
   if (prange) prange.value = String(pv);
   renderProcessPerformancePlotBox(pbox);
  }
  if (ev.target && ev.target.matches && ev.target.matches('.qpc-performance-ppk-range')){
   var pbox2 = ev.target.closest('.qpc-performance-grid');
   var ptext = pbox2 ? pbox2.querySelector('[data-role="performance-ppk-text"]') : null;
   if (ptext) ptext.value = fixedTrim(parseNum(ev.target.value), 2);
   renderProcessPerformancePlotBox(pbox2);
  }
  if (ev.target && ev.target.matches && ev.target.matches('.qpc-performance-stability-input')){
   var sbox = ev.target.closest('.qpc-performance-grid');
   var srange = sbox ? sbox.querySelector('[data-role="performance-stability-range"]') : null;
   var sv = clampNum(isFinite(parseNum(ev.target.value)) ? parseNum(ev.target.value) : 1.25, 0.50, 3.00);
   if (srange) srange.value = String(sv);
   renderProcessPerformancePlotBox(sbox);
  }
  if (ev.target && ev.target.matches && ev.target.matches('.qpc-performance-stability-range')){
   var sbox2 = ev.target.closest('.qpc-performance-grid');
   var stext = sbox2 ? sbox2.querySelector('[data-role="performance-stability-text"]') : null;
   if (stext) stext.value = fixedTrim(parseNum(ev.target.value), 2);
   renderProcessPerformancePlotBox(sbox2);
  }
 });
})();
</script>
</body>
</html>
