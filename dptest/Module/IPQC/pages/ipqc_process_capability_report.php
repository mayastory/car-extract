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
    --qpc-bg:#08110c;
    --qpc-panel:#0b1710;
    --qpc-border:rgba(92,164,118,.24);
    --qpc-border-soft:rgba(92,164,118,.18);
    --qpc-text:rgba(236,247,240,.96);
    --qpc-muted:rgba(210,225,215,.72);
    --qpc-box-border:rgba(255,255,255,.12);
    --qpc-box-border-soft:rgba(255,255,255,.10);
  }
  html,body{margin:0;padding:0;background:var(--qpc-bg);color:var(--qpc-text);font-family:Segoe UI, Arial, "Malgun Gothic", sans-serif;}
  body{min-width:980px;}
  .qpc-page{padding:8px 10px 14px; box-sizing:border-box;}
  .qpc-page-head{font-size:16px;font-weight:700;line-height:1.15;margin:1px 0 6px;}
  .qpc-report-shell{min-height:100%;}
  .qpc-report-tree,
  .qpc-report-group,
  .qpc-report-sub{display:inline-block;width:auto;max-width:100%;border:1px solid var(--qpc-border);background:transparent;border-radius:0;overflow:hidden;margin:0 0 4px;box-shadow:none;}
  .qpc-report-tree > summary,
  .qpc-report-group > summary,
  .qpc-report-sub > summary{list-style:none;cursor:pointer;padding:2px 8px;font-size:12px;line-height:1.35;font-weight:700;color:var(--qpc-text);background:linear-gradient(180deg, rgba(34,78,53,.86), rgba(13,28,19,.98));}
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
  .qpc-report-sub-body{padding:4px 6px 6px;border-top:1px solid var(--qpc-border-soft);}
  .qpc-report-body,
  .qpc-report-group-body{display:flex;flex-direction:column;align-items:flex-start;gap:4px;}
  .qpc-report-note{padding:14px 10px;color:var(--qpc-muted);font-size:12px;}
  .qpc-report-hist-grid{display:grid;grid-template-columns:minmax(0,660px) 156px;gap:8px;align-items:start;justify-content:start;width:max-content;max-width:100%;}
  .qpc-hist-wrap,.qpc-summary-box,.qpc-stat-box,.qpc-reject-box{border:1px solid var(--qpc-box-border);background:transparent;border-radius:0;}
  .qpc-hist-wrap{padding:4px 4px 3px;}
  .qpc-summary-box{padding:4px 6px;}
  .qpc-report-top-grid{max-width:100%;width:max-content;grid-template-columns:minmax(0,660px) 156px;gap:8px;align-items:start;justify-content:start;}
  .qpc-report-sub-top{margin-bottom:0;}
  .qpc-report-sub-top > summary{background:linear-gradient(180deg, rgba(44,92,62,.88), rgba(14,29,20,.98));}
  .qpc-report-sub-top.qpc-report-sub-hist{width:676px;max-width:100%;}
  .qpc-report-sub-hist > .qpc-report-sub-body{padding:2px 3px 3px;}
  .qpc-hist-wrap--top{padding:2px 2px 1px;}
  .qpc-svgbox{width:100%;overflow:auto;}
  .qpc-svgbox svg{width:100%;height:auto;display:block;}
  .qpc-svgbox--hist{width:660px;max-width:100%;}
  .qpc-svgbox--hist svg{width:660px;max-width:100%;height:auto;display:block;}
  .qpc-report-top-summary{margin:0;width:156px;max-width:156px;border:0;background:transparent;overflow:visible;align-self:start;}
  .qpc-report-top-summary > summary{list-style:none;cursor:pointer;padding:1px 6px;font-size:12px;line-height:1.3;font-weight:700;color:var(--qpc-text);background:linear-gradient(180deg, rgba(44,92,62,.88), rgba(14,29,20,.98));border:1px solid var(--qpc-border);}
  .qpc-report-top-summary-body{padding:4px 0 0;}
  .qpc-report-top-summary .qpc-summary-box{width:156px;max-width:156px;border:none;padding:0;background:transparent;}
  .qpc-report-top-summary .qpc-summary-grid{gap:2px 6px;}
  .qpc-report-top-summary .qpc-summary-sep{margin:4px 0;}
  .qpc-summary-title{font-size:12px;font-weight:700;margin-bottom:6px;}
  .qpc-summary-grid{display:grid;grid-template-columns:1fr auto;gap:2px 8px;font-size:12px;}
  .qpc-summary-grid .k{color:var(--qpc-text);}
  .qpc-summary-grid .v{color:var(--qpc-text);text-align:right;font-variant-numeric:tabular-nums;}
  .qpc-summary-sep{height:1px;background:rgba(255,255,255,.10);margin:6px 0;}
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
  .qpc-summary-report-wrap{overflow:auto;border:1px solid var(--qpc-box-border-soft);background:rgba(255,255,255,.015);}
  .qpc-summary-report-table{width:max-content;min-width:100%;border-collapse:collapse;font-size:11.5px;line-height:1.24;}
  .qpc-summary-report-table th,.qpc-summary-report-table td{border:1px solid var(--qpc-box-border-soft);padding:1px 5px;white-space:nowrap;font-variant-numeric:tabular-nums;}
  .qpc-summary-report-table thead th{background:rgba(255,255,255,.055);font-weight:700;text-align:center;color:rgba(236,247,240,.97);}
  .qpc-summary-report-table tbody th{background:rgba(255,255,255,.028);font-weight:700;text-align:left;color:rgba(236,247,240,.97);}
  .qpc-summary-report-table tbody td{text-align:right;background:transparent;}
  .qpc-summary-report-table td.kind-stability{min-width:62px;}
  .qpc-summary-report-table td.kind-capability{min-width:48px;}
  .qpc-summary-report-table td.is-good{background:rgba(198,229,191,.96);color:#10210f;}
  .qpc-summary-report-table td.is-warn{background:rgba(245,229,176,.96);color:#3a2a06;}
  .qpc-summary-report-table td.is-bad{background:rgba(240,188,188,.96);color:#341111;}
  .qpc-report-empty{text-align:center !important;color:var(--qpc-muted);}
  .qpc-target-grid{display:grid;grid-template-columns:minmax(0, 1fr) 128px;gap:8px;align-items:start;}
  .qpc-target-main,.qpc-target-side{border:1px solid var(--qpc-box-border);background:transparent;border-radius:0;}
  .qpc-target-main{position:relative;padding:6px 6px 4px;overflow:visible;}
  .qpc-target-side{padding:6px 8px;}
  .qpc-target-side-title{font-size:11px;font-weight:700;margin-bottom:8px;}
  .qpc-target-check{display:flex;align-items:center;gap:6px;font-size:11px;margin:0 0 10px;}
  .qpc-target-check input{margin:0;}
  .qpc-target-ppk-label{font-size:11px;font-weight:700;margin-bottom:4px;}
  .qpc-target-ppk-line{display:flex;align-items:center;gap:6px;margin-bottom:6px;}
  .qpc-target-ppk-badge{min-width:32px;height:18px;padding:0 6px;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.04);font-size:11px;font-weight:700;}
  .qpc-target-ppk-input{width:44px;height:20px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.03);color:var(--qpc-text);padding:0 4px;font-size:11px;text-align:center;box-sizing:border-box;}
  .qpc-target-range{width:100%;accent-color:#b9d8ff;}
  .qpc-target-side-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px;}
  .qpc-target-legend-link{padding:0;border:0;background:transparent;color:var(--qpc-text);font-size:11px;font-weight:700;cursor:pointer;user-select:none;}
  .qpc-target-side-preview{min-height:42px;margin-bottom:10px;}
  .qpc-target-side-item{display:flex;align-items:center;gap:6px;font-size:11px;margin:4px 0;padding:0;border:0;background:transparent;color:var(--qpc-text);cursor:pointer;text-align:left;width:100%;user-select:none;}
  .qpc-target-side-empty{font-size:11px;color:var(--qpc-muted);}
  .qpc-target-marker{width:9px;height:9px;display:inline-block;box-sizing:border-box;flex:0 0 9px;}
  .qpc-target-marker--overall{border:1px solid rgba(255,255,255,.88);background:transparent;}
  .qpc-target-marker--within{border:1px solid rgba(255,255,255,.88);background:#7c7c7c;}
  .qpc-target-hover-tip{position:absolute;z-index:12;min-width:270px;max-width:290px;pointer-events:none;background:#ececec;color:#111;border:1px solid #a6a6a6;border-radius:6px;box-shadow:0 6px 14px rgba(0,0,0,.35);padding:8px 10px 10px;}
  .qpc-target-tip-card{font-family:Arial, "Malgun Gothic", "Noto Sans KR", sans-serif;}
  .qpc-target-tip-meta{font-size:12px;line-height:1.35;margin-bottom:2px;color:#333;}
  .qpc-target-tip-svg{margin-top:6px;}
  .qpc-target-tip-svg svg{width:250px;height:auto;display:block;}
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

  document.title = payload.title ? String(payload.title) : titleBase;
  if (root) root.innerHTML = String(payload.html || '');
})();
</script>
</body>
</html>
