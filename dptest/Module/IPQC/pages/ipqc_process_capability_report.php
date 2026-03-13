<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>공정 능력 결과</title>
  <style>
    :root{
      --qpc-bg: #0a0e0c;
      --qpc-bg-2: rgba(255,255,255,0.04);
      --qpc-panel: rgba(255,255,255,0.03);
      --qpc-line: rgba(255,255,255,0.12);
      --qpc-line-2: rgba(255,255,255,0.08);
      --qpc-text: rgba(236,247,240,0.96);
      --qpc-muted: rgba(236,247,240,0.68);
    }
    html, body{ margin:0; padding:0; background:var(--qpc-bg); color:var(--qpc-text); }
    body{
      font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple SD Gothic Neo", "Noto Sans KR", sans-serif;
      min-width:1200px;
    }
    .qpc-popup-page{ padding:14px 16px 20px; box-sizing:border-box; }
    .qpc-popup-head{
      display:flex; align-items:center; justify-content:space-between; gap:12px;
      padding:0 0 12px; margin-bottom:10px; border-bottom:1px solid var(--qpc-line-2);
    }
    .qpc-popup-title{ font-size:18px; font-weight:800; letter-spacing:.2px; }
    .qpc-popup-sub{ font-size:12px; color:var(--qpc-muted); }
    .qpc-popup-empty{
      padding:18px 16px; border:1px solid var(--qpc-line); background:var(--qpc-panel);
      border-radius:12px; font-size:13px; color:var(--qpc-muted);
    }

    .qpc-report-shell{ width:100%; box-sizing:border-box; }
    .qpc-report-tree,
    .qpc-report-group,
    .qpc-report-sub{
      border:1px solid rgba(92,164,118,.24);
      background:transparent;
      border-radius:0;
      overflow:hidden;
      margin:0 0 4px;
      box-shadow:none;
    }
    .qpc-report-tree > summary,
    .qpc-report-group > summary,
    .qpc-report-sub > summary{
      list-style:none;
      cursor:pointer;
      padding:2px 8px;
      font-size:12px;
      line-height:1.35;
      font-weight:700;
      color:var(--qpc-text);
      background:linear-gradient(180deg, rgba(34,78,53,.86), rgba(13,28,19,.98));
    }
    .qpc-report-sub-top.qpc-report-sub-hist > summary{ padding:1px 6px; }
    .qpc-report-tree > summary::-webkit-details-marker,
    .qpc-report-group > summary::-webkit-details-marker,
    .qpc-report-sub > summary::-webkit-details-marker{ display:none; }
    .qpc-report-tree > summary:before,
    .qpc-report-group > summary:before,
    .qpc-report-sub > summary:before{ content:'▸ '; color:rgba(236,247,240,.78); }
    .qpc-report-tree[open] > summary:before,
    .qpc-report-group[open] > summary:before,
    .qpc-report-sub[open] > summary:before{ content:'▾ '; }
    .qpc-report-body,
    .qpc-report-group-body,
    .qpc-report-sub-body{ padding:5px 8px 8px; border-top:1px solid rgba(92,164,118,.18); }
    .qpc-report-note{ padding:16px 12px; color:var(--qpc-muted); font-size:12px; }

    .qpc-report-hist-grid{ display:grid; grid-template-columns:minmax(0, 1fr) 176px; gap:8px; align-items:start; }
    .qpc-report-top-grid{ width:100%; grid-template-columns:minmax(0, 1fr) 300px; gap:10px; align-items:start; }
    .qpc-hist-wrap,
    .qpc-summary-box,
    .qpc-stat-box,
    .qpc-reject-box{ border:1px solid rgba(255,255,255,.12); background:transparent; border-radius:0; }
    .qpc-hist-wrap{ padding:7px 7px 5px; }
    .qpc-summary-box{ padding:6px 8px; }
    .qpc-report-sub-top{ margin-bottom:0; }
    .qpc-report-sub-top > summary{ background:linear-gradient(180deg, rgba(44,92,62,.88), rgba(14,29,20,.98)); }
    .qpc-report-sub-hist > .qpc-report-sub-body{ padding:3px 4px 4px; }
    .qpc-hist-wrap--top{ padding:3px 3px 2px; }
    .qpc-svgbox svg{ width:100%; height:auto; display:block; }
    .qpc-svgbox--hist{ width:100%; max-width:none; }

    .qpc-report-top-summary{ margin:0; border:0; background:transparent; overflow:visible; align-self:start; }
    .qpc-report-top-summary > summary{
      list-style:none; cursor:pointer; padding:1px 6px; font-size:12px; line-height:1.3; font-weight:700;
      color:var(--qpc-text); background:linear-gradient(180deg, rgba(44,92,62,.88), rgba(14,29,20,.98));
      border:1px solid rgba(92,164,118,.24);
    }
    .qpc-report-top-summary > summary::-webkit-details-marker{ display:none; }
    .qpc-report-top-summary > summary:before{ content:'▸ '; color:rgba(236,247,240,.78); }
    .qpc-report-top-summary[open] > summary:before{ content:'▾ '; }
    .qpc-report-top-summary-body{ padding:4px 0 0; }
    .qpc-report-top-summary .qpc-summary-box{ max-width:156px; border:none; padding:0; background:transparent; }
    .qpc-report-top-summary .qpc-summary-grid{ gap:2px 6px; }
    .qpc-report-top-summary .qpc-summary-sep{ margin:4px 0; }

    .qpc-summary-title{ font-size:12px; font-weight:700; margin-bottom:6px; }
    .qpc-summary-grid{ display:grid; grid-template-columns:1fr auto; gap:2px 8px; font-size:12px; }
    .qpc-summary-grid .k{ color:var(--qpc-text); }
    .qpc-summary-grid .v{ color:var(--qpc-text); text-align:right; font-variant-numeric:tabular-nums; }
    .qpc-summary-sep{ height:1px; background:rgba(255,255,255,.10); margin:6px 0; }

    .qpc-report-two{ display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; }
    .qpc-report-two-tight{ max-width:706px; gap:8px; }
    .qpc-stat-box,
    .qpc-reject-box{ padding:6px 8px; }
    .qpc-reject-box{ padding:4px 6px 5px; }
    .qpc-report-sub-reject{ width:max-content; max-width:100%; }
    .qpc-report-sub-reject > .qpc-report-sub-body{ display:inline-block; padding:4px 6px 5px; }
    .qpc-report-sub-reject .qpc-reject-box{ display:inline-block; width:auto; }

    .qpc-reject-table th,
    .qpc-reject-table td{ padding:1px 4px; }
    .qpc-reject-table thead th{ background:rgba(255,255,255,.035); }
    .qpc-stat-table{ width:100%; border-collapse:collapse; font-size:12px; }
    .qpc-stat-table th,
    .qpc-stat-table td{
      border:1px solid rgba(255,255,255,.10);
      padding:2px 5px;
      text-align:right;
      font-variant-numeric:tabular-nums;
      white-space:nowrap;
    }
    .qpc-stat-table th:first-child,
    .qpc-stat-table td:first-child{ text-align:left; }
    .qpc-stat-table thead th{ background:rgba(255,255,255,.04); font-weight:700; }
    .qpc-stat-table tbody th{ background:rgba(255,255,255,.02); font-weight:600; }

    .qpc-report-placeholder{ padding:8px 10px; color:var(--qpc-muted); font-size:11px; }
    .qpc-report-sub-summary > summary{ padding:2px 8px; }
    .qpc-report-sub-summary > .qpc-report-sub-body{ padding:4px 6px 6px; }
    .qpc-summary-report-wrap{ overflow:auto; border:1px solid rgba(255,255,255,.10); background:rgba(255,255,255,.015); }
    .qpc-summary-report-table{ width:max-content; min-width:100%; border-collapse:collapse; font-size:11.5px; line-height:1.24; }
    .qpc-summary-report-table th,
    .qpc-summary-report-table td{ border:1px solid rgba(255,255,255,.10); padding:1px 5px; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .qpc-summary-report-table thead th{ background:rgba(255,255,255,.055); font-weight:700; text-align:center; color:rgba(236,247,240,.97); }
    .qpc-summary-report-table tbody th{ background:rgba(255,255,255,.028); font-weight:700; text-align:left; color:rgba(236,247,240,.97); }
    .qpc-summary-report-table tbody td{ text-align:right; background:transparent; }
    .qpc-summary-report-table td.kind-stability{ min-width:62px; }
    .qpc-summary-report-table td.kind-capability{ min-width:48px; }
    .qpc-summary-report-table td.is-good{ background:rgba(198,229,191,.96); color:#10210f; }
    .qpc-summary-report-table td.is-warn{ background:rgba(245,229,176,.96); color:#3a2a06; }
    .qpc-summary-report-table td.is-bad{ background:rgba(240,188,188,.96); color:#341111; }
    .qpc-report-empty{ text-align:center !important; color:var(--qpc-muted); }

    .qpc-target-grid{ display:grid; grid-template-columns:minmax(0, 1fr) 128px; gap:8px; align-items:start; }
    .qpc-target-main,
    .qpc-target-side{ border:1px solid rgba(255,255,255,.12); background:transparent; border-radius:0; }
    .qpc-target-main{ position:relative; padding:6px 6px 4px; overflow:visible; }
    .qpc-target-side{ padding:6px 8px; }
    .qpc-target-side-head{ display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:8px; }
    .qpc-target-legend-link{ padding:0; border:0; background:transparent; color:var(--qpc-text); font-size:11px; font-weight:700; }
    .qpc-target-side-preview{ min-height:42px; margin-bottom:10px; }
    .qpc-target-side-item{ display:flex; align-items:center; gap:6px; font-size:11px; margin:4px 0; padding:0; border:0; background:transparent; color:var(--qpc-text); text-align:left; width:100%; }
    .qpc-target-side-empty{ font-size:11px; color:var(--qpc-muted); }
    .qpc-target-marker{ width:9px; height:9px; display:inline-block; box-sizing:border-box; flex:0 0 9px; }
    .qpc-target-marker--overall{ border:1px solid rgba(255,255,255,.88); background:transparent; }
    .qpc-target-marker--within{ border:1px solid rgba(255,255,255,.88); background:#7c7c7c; }
    .qpc-target-ppk-label{ font-size:11px; font-weight:700; margin-bottom:4px; }
    .qpc-target-ppk-line{ display:flex; align-items:center; gap:6px; margin-bottom:6px; }
    .qpc-target-ppk-badge{ min-width:32px; height:18px; padding:0 6px; display:inline-flex; align-items:center; justify-content:center; border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.04); font-size:11px; font-weight:700; }
    .qpc-target-ppk-input,
    .qpc-index-ppk-input{
      width:44px; height:20px; border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.03); color:var(--qpc-text);
      padding:0 4px; font-size:11px; text-align:center; box-sizing:border-box;
    }
    .qpc-target-range,
    .qpc-index-range{ width:100%; accent-color:#b9d8ff; }
    .qpc-target-hover-tip{ display:none !important; }

    .qpc-index-grid{ display:grid; grid-template-columns:minmax(0,292px) 106px; gap:12px; align-items:start; max-width:410px; }
    .qpc-index-main,
    .qpc-index-side{ border:1px solid rgba(255,255,255,.12); background:transparent; border-radius:0; }
    .qpc-index-main{ padding:6px 6px 4px; }
    .qpc-index-side{ padding:6px 8px; }

    @media print{
      body{ background:#fff; color:#000; }
      .qpc-popup-page{ padding:0; }
    }
  </style>
</head>
<body>
  <div class="qpc-popup-page">
    <div class="qpc-popup-head">
      <div>
        <div class="qpc-popup-title">공정 능력 결과</div>
        <div class="qpc-popup-sub">1, 2단계는 모달 유지 / 3단계 결과만 새창</div>
      </div>
    </div>

    <div class="qpc-popup-empty" id="qpcPopupEmpty" hidden>표시할 공정 능력 결과가 없습니다. 공정 능력 모달에서 2단계 확인을 다시 눌러 주세요.</div>

    <div class="qpc-report-shell" id="qpcResultShell" hidden>
      <details class="qpc-report-tree" open>
        <summary>개별 상세 정보 보고서</summary>
        <div class="qpc-report-body" id="qpcResultBody"></div>
      </details>
    </div>
  </div>

  <script>
  (function(){
    var params = new URLSearchParams(window.location.search || '');
    var key = String(params.get('key') || '');
    var storageKey = key ? ('__qpc_report_payload__:' + key) : '';
    var raw = storageKey ? window.localStorage.getItem(storageKey) : '';
    var payload = null;
    try { payload = raw ? JSON.parse(raw) : null; } catch (e) { payload = null; }
    var body = document.getElementById('qpcResultBody');
    var shell = document.getElementById('qpcResultShell');
    var empty = document.getElementById('qpcPopupEmpty');
    if (!payload || !payload.html){
      if (empty) empty.hidden = false;
      if (shell) shell.hidden = true;
      return;
    }
    if (body) body.innerHTML = String(payload.html || '');
    if (shell) shell.hidden = false;
    if (empty) empty.hidden = true;
    if (payload.title) document.title = String(payload.title);
  })();
  </script>
</body>
</html>
