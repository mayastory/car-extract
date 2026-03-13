<?php
if (!function_exists('ipqc_base_root')) {
  function ipqc_base_root(): string {
    $p = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($p === '') return '';
    $i = strpos($p, '/public/legacy/');
    if ($i !== false) return substr($p, 0, $i);
    $j = strpos($p, '/public/');
    if ($j !== false) return substr($p, 0, $j);
    $d = rtrim(dirname($p), '/');
    return $d === '/' ? '' : $d;
  }
}
$__QPC_BASE = ipqc_base_root();
if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>
<style>
  #qpcOverlay{
    --qpc-bg: rgba(10,14,12,0.96);
    --qpc-bg-2: rgba(255,255,255,0.04);
    --qpc-bg-3: rgba(255,255,255,0.06);
    --qpc-panel: rgba(255,255,255,0.03);
    --qpc-line: rgba(255,255,255,0.12);
    --qpc-line-2: rgba(255,255,255,0.08);
    --qpc-text: rgba(236,247,240,0.96);
    --qpc-muted: rgba(236,247,240,0.68);
    --qpc-accent: #1db954;
    --qpc-accent-soft: rgba(29,185,84,0.18);
    --qpc-select: rgba(43,91,215,0.24);
    --qpc-select-line: rgba(78,132,255,0.72);
    --qpc-report-scale: 0.92;
    position:fixed; inset:0; z-index:2147483647; display:none;
    background:rgba(0,0,0,.58); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
  }
  #qpcOverlay[aria-hidden="false"]{ display:block; }
  #qpcOverlay .qpc-window{
    position:absolute; left:50%; top:50%; transform:translate(-50%, -50%);
    width:min(880px, calc(100vw - 28px)); height:min(760px, calc(100vh - 28px));
    background:var(--qpc-bg); color:var(--qpc-text);
    border:1px solid var(--qpc-line); border-radius:18px;
    box-shadow:0 18px 60px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.04);
    font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple SD Gothic Neo", "Noto Sans KR", sans-serif;
    overflow:hidden; display:flex; flex-direction:column;
  }
  #qpcOverlay[data-step="spec"] .qpc-window{ width:min(980px, calc(100vw - 28px)); height:min(580px, calc(100vh - 28px)); }
  #qpcOverlay[data-step="report"] .qpc-window{ width:min(980px, calc(100vw - 24px)); height:min(780px, calc(100vh - 24px)); }
  #qpcOverlay .qpc-titlebar{
    height:52px; display:flex; align-items:center; justify-content:space-between; gap:12px; cursor:move;
    padding:0 16px 0 18px; background:rgba(255,255,255,.03); border-bottom:1px solid var(--qpc-line-2);
  }
  #qpcOverlay .qpc-title{ font-size:17px; font-weight:800; letter-spacing:.2px; }
  #qpcOverlay .qpc-close{
    width:32px; height:32px; border:1px solid var(--qpc-line); background:rgba(255,255,255,.04);
    color:var(--qpc-text); border-radius:10px; cursor:pointer; font-size:18px; line-height:1;
  }
  #qpcOverlay .qpc-close:hover{ background:rgba(255,255,255,.08); }
  #qpcOverlay .qpc-stage{ padding:14px 16px 16px; box-sizing:border-box; flex:1 1 auto; min-height:0; overflow:auto; }
  #qpcOverlay .qpc-desc{ display:none; }
  #qpcOverlay .qpc-setup-grid{ display:grid; grid-template-columns:236px minmax(0, 1fr) 88px; gap:12px; }
  #qpcOverlay .qpc-block-label,
  #qpcOverlay .qpc-role-title{ font-size:12px; color:var(--qpc-muted); margin-bottom:6px; font-weight:700; }
  #qpcOverlay .qpc-panel,
  #qpcOverlay .qpc-vars,
  #qpcOverlay .qpc-dropzone,
  #qpcOverlay .qpc-workcol,
  #qpcOverlay .qpc-tableload,
  #qpcOverlay .qpc-specbox,
  #qpcOverlay .qpc-limitscope,
  #qpcOverlay .qpc-group-body,
  #qpcOverlay .qpc-fieldset,
  #qpcOverlay .qpc-basis-box{
    border:1px solid var(--qpc-line); background:var(--qpc-panel);
  }
  #qpcOverlay .qpc-left .qpc-panel{ padding:10px; border-radius:14px; }
  #qpcOverlay .qpc-input,
  #qpcOverlay .qpc-select,
  #qpcOverlay .qpc-spec-table input[type="text"]{
    height:34px; border:1px solid var(--qpc-line); background:rgba(0,0,0,.24); color:var(--qpc-text);
    padding:6px 10px; font-size:12px; box-sizing:border-box; border-radius:10px; outline:none;
  }
  #qpcOverlay .qpc-input::placeholder{ color:rgba(236,247,240,.42); }
  #qpcOverlay .qpc-input:focus,
  #qpcOverlay .qpc-select:focus,
  #qpcOverlay .qpc-spec-table input[type="text"]:focus{
    border-color:rgba(78,132,255,.65); box-shadow:0 0 0 3px rgba(78,132,255,.14);
  }
  #qpcOverlay .qpc-varcount{ font-size:12px; margin-bottom:6px; color:var(--qpc-muted); text-align:right; }
  #qpcOverlay .qpc-searchrow{ display:flex; gap:6px; align-items:center; margin-bottom:8px; }
  #qpcOverlay .qpc-searchrow .qpc-input{ flex:1 1 auto; }
  #qpcOverlay .qpc-tinybtn,
  #qpcOverlay .qpc-btn,
  #qpcOverlay .qpc-work-btn{
    border:1px solid var(--qpc-line); background:rgba(255,255,255,.05); color:var(--qpc-text);
    cursor:pointer; font-size:12px; min-height:34px; border-radius:10px; font-weight:700;
  }
  #qpcOverlay .qpc-tinybtn:hover,
  #qpcOverlay .qpc-btn:hover,
  #qpcOverlay .qpc-work-btn:hover{ background:rgba(255,255,255,.09); }
  #qpcOverlay .qpc-tinybtn{ width:34px; padding:0; flex:0 0 34px; }
  #qpcOverlay .qpc-btn{ padding:7px 12px; }
  #qpcOverlay .qpc-btn.qpc-btn-y{ background:rgba(29,185,84,.20); border-color:rgba(70,220,150,.32); }
  #qpcOverlay .qpc-btn.qpc-btn-y:hover{ background:rgba(29,185,84,.28); }
  #qpcOverlay .qpc-work-btn{ width:100%; padding:7px 8px; }
  #qpcOverlay .qpc-vars{
    background:rgba(0,0,0,.16); height:510px; overflow:auto; border-radius:12px; padding:4px;
  }
  #qpcOverlay .qpc-var{
    display:flex; align-items:center; gap:8px; padding:8px 10px; font-size:13px; user-select:none;
    cursor:default; border-radius:10px; color:var(--qpc-text);
  }
  #qpcOverlay .qpc-var:hover{ background:rgba(255,255,255,.06); }
  #qpcOverlay .qpc-var.is-selected{ background:var(--qpc-select); color:#fff; box-shadow:inset 0 0 0 1px var(--qpc-select-line); }
  #qpcOverlay .qpc-var .ico{ width:12px; color:#6aa2ff; font-size:11px; display:inline-flex; justify-content:center; }
  #qpcOverlay .qpc-var.is-selected .ico{ color:#fff; }
  #qpcOverlay .qpc-alpha{ margin-top:12px; display:flex; align-items:center; gap:8px; font-size:12px; color:var(--qpc-text); }
  #qpcOverlay .qpc-alpha .qpc-input{ width:62px; }
  #qpcOverlay .qpc-limitscope{ margin-top:12px; padding:10px 12px 8px; border-radius:14px; }
  #qpcOverlay .qpc-limitscope legend,
  #qpcOverlay .qpc-fieldset legend{ padding:0 6px; font-size:12px; color:var(--qpc-muted); }
  #qpcOverlay .qpc-radio{ display:flex; align-items:flex-start; gap:7px; font-size:12px; margin:7px 0; color:var(--qpc-text); line-height:1.35; }
  #qpcOverlay .qpc-main{ min-width:0; }
  #qpcOverlay .qpc-role-header{ display:grid; grid-template-columns:90px minmax(0, 1fr); gap:12px; align-items:start; }
  #qpcOverlay .qpc-dropzone-wrap{ min-width:0; }
  #qpcOverlay .qpc-drophead{ display:flex; justify-content:space-between; font-size:12px; margin-bottom:6px; color:var(--qpc-muted); }
  #qpcOverlay .qpc-dropzone{ height:118px; background:rgba(0,0,0,.18); overflow:auto; position:relative; border-radius:14px; }
  #qpcOverlay .qpc-dropzone.is-dragover{ outline:2px solid rgba(70,220,150,.58); outline-offset:-2px; box-shadow:inset 0 0 0 9999px rgba(29,185,84,.06); }
  #qpcOverlay .qpc-dropzone-empty{ position:absolute; inset:12px; color:rgba(236,247,240,.40); font-size:12px; pointer-events:none; }
  #qpcOverlay .qpc-assigned-item{
    padding:8px 10px; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; user-select:none;
    border-radius:10px; margin:4px;
  }
  #qpcOverlay .qpc-assigned-item:hover{ background:rgba(255,255,255,.06); }
  #qpcOverlay .qpc-assigned-item.is-selected{ background:var(--qpc-select); color:#fff; box-shadow:inset 0 0 0 1px var(--qpc-select-line); }
  #qpcOverlay .qpc-groups{ margin-top:12px; display:grid; gap:8px; }
  #qpcOverlay .qpc-group{ border:1px solid var(--qpc-line); background:var(--qpc-bg-2); border-radius:12px; overflow:hidden; }
  #qpcOverlay .qpc-group > summary{
    list-style:none; padding:9px 12px; cursor:pointer; font-size:12px; font-weight:700; color:var(--qpc-text);
    background:rgba(255,255,255,.03);
  }
  #qpcOverlay .qpc-group > summary:hover{ background:rgba(255,255,255,.06); }
  #qpcOverlay .qpc-group > summary::-webkit-details-marker{ display:none; }
  #qpcOverlay .qpc-group > summary:before{ content:'▸ '; color:var(--qpc-muted); }
  #qpcOverlay .qpc-group[open] > summary:before{ content:'▾ '; }
  #qpcOverlay .qpc-group-body{ padding:10px 12px; background:rgba(0,0,0,.14); border:0; border-top:1px solid var(--qpc-line-2); }
  #qpcOverlay .qpc-row{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12px; margin-bottom:8px; color:var(--qpc-text); }
  #qpcOverlay .qpc-row:last-child{ margin-bottom:0; }
  #qpcOverlay .qpc-fieldset{ padding:8px 10px; background:rgba(255,255,255,.02); border-radius:12px; }
  #qpcOverlay .qpc-fieldset label{ display:flex; align-items:center; gap:6px; margin:6px 0; color:var(--qpc-text); font-size:12px; }
  #qpcOverlay .qpc-subtle{ color:var(--qpc-muted); }
  #qpcOverlay .qpc-basis-box{ min-height:76px; border-radius:12px; background:rgba(0,0,0,.16); }
  #qpcOverlay .qpc-workcol{ padding:10px; display:grid; gap:8px; align-content:start; border-radius:14px; }
  #qpcOverlay .qpc-worktitle{ font-size:12px; font-weight:700; color:var(--qpc-muted); margin-bottom:2px; }
  #qpcOverlay .qpc-status{ margin-top:8px; min-height:16px; font-size:12px; color:#9fe3b8; }
  #qpcOverlay .qpc-status:empty{ display:none; }

  #qpcOverlay .qpc-stage-spec{ display:flex; flex-direction:column; gap:14px; }
  #qpcOverlay .qpc-stage-spec .qpc-tableload{
    width:280px; padding:12px 14px; margin-bottom:14px; border-radius:14px;
  }
  #qpcOverlay .qpc-stage-spec .qpc-tableload .qpc-btn{ margin-top:10px; }
  #qpcOverlay .qpc-specbox{ padding:12px 14px; border-radius:16px; min-height:0; overflow:auto; }
  #qpcOverlay .qpc-spec-table{ width:100%; border-collapse:separate; border-spacing:0; table-layout:fixed; font-size:12px; overflow:hidden; }
  #qpcOverlay .qpc-spec-table th,
  #qpcOverlay .qpc-spec-table td{ border-right:1px solid var(--qpc-line-2); border-bottom:1px solid var(--qpc-line-2); padding:6px 8px; background:rgba(0,0,0,.14); color:var(--qpc-text); box-sizing:border-box; vertical-align:middle; }
  #qpcOverlay .qpc-spec-table th:first-child,
  #qpcOverlay .qpc-spec-table td:first-child{ border-left:1px solid var(--qpc-line-2); }
  #qpcOverlay .qpc-spec-table thead th{ background:rgba(255,255,255,.05); font-weight:800; }
  #qpcOverlay .qpc-spec-table thead tr:first-child th{ border-top:1px solid var(--qpc-line-2); }
  #qpcOverlay .qpc-spec-table td.proc{ background:rgba(255,255,255,.03); font-weight:700; }
  #qpcOverlay .qpc-spec-table td > input[type="text"]{ width:100%; min-width:0; display:block; margin:0; }
  #qpcOverlay .qpc-spec-checkcell{ text-align:center; }
  #qpcOverlay .qpc-spec-actions{ display:flex; align-items:center; gap:8px; margin-top:12px; }
  #qpcOverlay .qpc-spec-actions .qpc-right{ margin-left:auto; display:flex; gap:8px; }
  #qpcOverlay input[type="checkbox"],
  #qpcOverlay input[type="radio"]{ accent-color: var(--qpc-accent); }

  #qpcOverlay .qpc-stage-report{ padding:6px 8px 10px; overflow:auto; }
  #qpcOverlay .qpc-stage-report .qpc-report-shell{ transform:scale(var(--qpc-report-scale)); transform-origin:top left; }
  #qpcOverlay .qpc-stage-report[hidden],
  #qpcOverlay .qpc-stage-spec[hidden],
  #qpcOverlay .qpc-stage-setup[hidden]{ display:none !important; }
  #qpcOverlay .qpc-report-shell{ min-height:100%; }
  #qpcOverlay .qpc-report-tree,
  #qpcOverlay .qpc-report-group,
  #qpcOverlay .qpc-report-sub{ border:1px solid rgba(92,164,118,.24); background:transparent; border-radius:0; overflow:hidden; margin:0 0 4px; box-shadow:none; }
  #qpcOverlay .qpc-report-tree > summary,
  #qpcOverlay .qpc-report-group > summary,
  #qpcOverlay .qpc-report-sub > summary{ list-style:none; cursor:pointer; padding:2px 8px; font-size:12px; line-height:1.35; font-weight:700; color:var(--qpc-text); background:linear-gradient(180deg, rgba(34,78,53,.86), rgba(13,28,19,.98)); }
  #qpcOverlay .qpc-report-tree > summary::-webkit-details-marker,
  #qpcOverlay .qpc-report-group > summary::-webkit-details-marker,
  #qpcOverlay .qpc-report-sub > summary::-webkit-details-marker{ display:none; }
  #qpcOverlay .qpc-report-tree > summary:before,
  #qpcOverlay .qpc-report-group > summary:before,
  #qpcOverlay .qpc-report-sub > summary:before{ content:'▸ '; color:rgba(236,247,240,.78); }
  #qpcOverlay .qpc-report-tree[open] > summary:before,
  #qpcOverlay .qpc-report-group[open] > summary:before,
  #qpcOverlay .qpc-report-sub[open] > summary:before{ content:'▾ '; }
  #qpcOverlay .qpc-report-body,
  #qpcOverlay .qpc-report-group-body,
  #qpcOverlay .qpc-report-sub-body{ padding:5px 8px 8px; border-top:1px solid rgba(92,164,118,.18); }
  #qpcOverlay .qpc-report-note{ padding:16px 12px; color:var(--qpc-muted); font-size:12px; }
  #qpcOverlay .qpc-report-hist-grid{ display:grid; grid-template-columns:minmax(0, 1fr) 176px; gap:8px; align-items:start; }
  #qpcOverlay .qpc-hist-wrap,
  #qpcOverlay .qpc-summary-box,
  #qpcOverlay .qpc-stat-box,
  #qpcOverlay .qpc-reject-box{ border:1px solid rgba(255,255,255,.12); background:transparent; border-radius:0; }
  #qpcOverlay .qpc-hist-wrap{ padding:7px 7px 5px; }
  #qpcOverlay .qpc-summary-box{ padding:6px 8px; }
  #qpcOverlay .qpc-report-top-grid{ max-width:718px; grid-template-columns:minmax(0, 556px) minmax(148px, 156px); gap:6px; align-items:start; }
  #qpcOverlay .qpc-report-sub-top{ margin-bottom:0; }
  #qpcOverlay .qpc-report-sub-top > summary{ background:linear-gradient(180deg, rgba(44,92,62,.88), rgba(14,29,20,.98)); }
  #qpcOverlay .qpc-report-sub-hist > .qpc-report-sub-body{ padding:4px 6px 6px; }
  #qpcOverlay .qpc-hist-wrap--top{ padding:5px 5px 4px; }
  #qpcOverlay .qpc-svgbox--hist{ width:100%; max-width:556px; }
  #qpcOverlay .qpc-report-top-summary{ margin:0; border:0; background:transparent; overflow:visible; align-self:start; }
  #qpcOverlay .qpc-report-top-summary > summary{ list-style:none; cursor:pointer; padding:1px 6px; font-size:12px; line-height:1.3; font-weight:700; color:var(--qpc-text); background:linear-gradient(180deg, rgba(44,92,62,.88), rgba(14,29,20,.98)); border:1px solid rgba(92,164,118,.24); }
  #qpcOverlay .qpc-report-top-summary > summary::-webkit-details-marker{ display:none; }
  #qpcOverlay .qpc-report-top-summary > summary:before{ content:'▸ '; color:rgba(236,247,240,.78); }
  #qpcOverlay .qpc-report-top-summary[open] > summary:before{ content:'▾ '; }
  #qpcOverlay .qpc-report-top-summary-body{ padding:4px 0 0; }
  #qpcOverlay .qpc-report-top-summary .qpc-summary-box{ max-width:156px; border:none; padding:0; background:transparent; }
  #qpcOverlay .qpc-report-top-summary .qpc-summary-grid{ gap:2px 6px; }
  #qpcOverlay .qpc-report-top-summary .qpc-summary-sep{ margin:4px 0; }
  #qpcOverlay .qpc-summary-title{ font-size:12px; font-weight:700; margin-bottom:6px; }
  #qpcOverlay .qpc-summary-grid{ display:grid; grid-template-columns:1fr auto; gap:2px 8px; font-size:12px; }
  #qpcOverlay .qpc-summary-grid .k{ color:var(--qpc-text); }
  #qpcOverlay .qpc-summary-grid .v{ color:var(--qpc-text); text-align:right; font-variant-numeric:tabular-nums; }
  #qpcOverlay .qpc-summary-sep{ height:1px; background:rgba(255,255,255,.10); margin:6px 0; }
  #qpcOverlay .qpc-report-two{ display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; }
  #qpcOverlay .qpc-report-two-tight{ max-width:706px; gap:8px; }
  #qpcOverlay .qpc-stat-box,
  #qpcOverlay .qpc-reject-box{ padding:6px 8px; }
  #qpcOverlay .qpc-reject-box{ padding:4px 6px 5px; }
  #qpcOverlay .qpc-report-sub-reject{ width:max-content; max-width:100%; }
  #qpcOverlay .qpc-report-sub-reject > .qpc-report-sub-body{ display:inline-block; padding:4px 6px 5px; }
  #qpcOverlay .qpc-report-sub-reject .qpc-reject-box{ display:inline-block; width:auto; }
  #qpcOverlay .qpc-reject-table th,
  #qpcOverlay .qpc-reject-table td{ padding:1px 4px; }
  #qpcOverlay .qpc-reject-table thead th{ background:rgba(255,255,255,.035); }
  #qpcOverlay .qpc-stat-table{ width:100%; border-collapse:collapse; font-size:12px; }
  #qpcOverlay .qpc-stat-table th,
  #qpcOverlay .qpc-stat-table td{ border:1px solid rgba(255,255,255,.10); padding:2px 5px; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  #qpcOverlay .qpc-stat-table th:first-child,
  #qpcOverlay .qpc-stat-table td:first-child{ text-align:left; }
  #qpcOverlay .qpc-stat-table thead th{ background:rgba(255,255,255,.04); font-weight:700; }
  #qpcOverlay .qpc-stat-table tbody th{ background:rgba(255,255,255,.02); font-weight:600; }
  #qpcOverlay .qpc-report-placeholder{ padding:8px 10px; color:var(--qpc-muted); font-size:11px; }
  #qpcOverlay .qpc-report-sub-summary > summary{ padding:2px 8px; }
  #qpcOverlay .qpc-report-sub-summary > .qpc-report-sub-body{ padding:4px 6px 6px; }
  #qpcOverlay .qpc-summary-report-wrap{ overflow:auto; border:1px solid rgba(255,255,255,.10); background:rgba(255,255,255,.015); }
  #qpcOverlay .qpc-summary-report-table{ width:max-content; min-width:100%; border-collapse:collapse; font-size:11.5px; line-height:1.24; }
  #qpcOverlay .qpc-summary-report-table th,
  #qpcOverlay .qpc-summary-report-table td{ border:1px solid rgba(255,255,255,.10); padding:1px 5px; white-space:nowrap; font-variant-numeric:tabular-nums; }
  #qpcOverlay .qpc-summary-report-table thead th{ background:rgba(255,255,255,.055); font-weight:700; text-align:center; color:rgba(236,247,240,.97); }
  #qpcOverlay .qpc-summary-report-table tbody th{ background:rgba(255,255,255,.028); font-weight:700; text-align:left; color:rgba(236,247,240,.97); }
  #qpcOverlay .qpc-summary-report-table tbody td{ text-align:right; background:transparent; }
  #qpcOverlay .qpc-summary-report-table td.kind-stability{ min-width:62px; }
  #qpcOverlay .qpc-summary-report-table td.kind-capability{ min-width:48px; }
  #qpcOverlay .qpc-summary-report-table td.is-good{ background:rgba(198,229,191,.96); color:#10210f; }
  #qpcOverlay .qpc-summary-report-table td.is-warn{ background:rgba(245,229,176,.96); color:#3a2a06; }
  #qpcOverlay .qpc-summary-report-table td.is-bad{ background:rgba(240,188,188,.96); color:#341111; }
  #qpcOverlay .qpc-report-empty{ text-align:center !important; color:var(--qpc-muted); }
  #qpcOverlay .qpc-svgbox{ width:100%; overflow:auto; }
  #qpcOverlay .qpc-svgbox svg{ width:100%; height:auto; display:block; }
  #qpcOverlay .qpc-target-grid{ display:grid; grid-template-columns:minmax(0, 1fr) 128px; gap:8px; align-items:start; }
  #qpcOverlay .qpc-target-main,
  #qpcOverlay .qpc-target-side{ border:1px solid rgba(255,255,255,.12); background:transparent; border-radius:0; }
  #qpcOverlay .qpc-target-main{ position:relative; padding:6px 6px 4px; overflow:visible; }
  #qpcOverlay .qpc-target-side{ padding:6px 8px; }
  #qpcOverlay .qpc-target-side-title{ font-size:11px; font-weight:700; margin-bottom:8px; }
  #qpcOverlay .qpc-target-check{ display:flex; align-items:center; gap:6px; font-size:11px; margin:0 0 10px; }
  #qpcOverlay .qpc-target-check input{ margin:0; }
  #qpcOverlay .qpc-target-ppk-label{ font-size:11px; font-weight:700; margin-bottom:4px; }
  #qpcOverlay .qpc-target-ppk-line{ display:flex; align-items:center; gap:6px; margin-bottom:6px; }
  #qpcOverlay .qpc-target-ppk-badge{ min-width:32px; height:18px; padding:0 6px; display:inline-flex; align-items:center; justify-content:center; border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.04); font-size:11px; font-weight:700; }
  #qpcOverlay .qpc-target-ppk-input{ width:44px; height:20px; border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.03); color:var(--qpc-text); padding:0 4px; font-size:11px; text-align:center; box-sizing:border-box; }
  #qpcOverlay .qpc-target-range{ width:100%; accent-color:#b9d8ff; }


  #qpcOverlay .qpc-target-side-head{ display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:8px; }
  #qpcOverlay .qpc-target-legend-link{
    padding:0; border:0; background:transparent; color:var(--qpc-text); font-size:11px; font-weight:700; cursor:pointer; user-select:none;
  }
  #qpcOverlay .qpc-target-side-preview{ min-height:42px; margin-bottom:10px; }
  #qpcOverlay .qpc-target-side-item{
    display:flex; align-items:center; gap:6px; font-size:11px; margin:4px 0; padding:0; border:0; background:transparent; color:var(--qpc-text);
    cursor:pointer; text-align:left; width:100%; user-select:none;
  }
  #qpcOverlay .qpc-target-hover-tip{
    position:absolute; z-index:12; min-width:270px; max-width:290px; pointer-events:none;
    background:#ececec; color:#111; border:1px solid #a6a6a6; border-radius:6px;
    box-shadow:0 6px 14px rgba(0,0,0,.35); padding:8px 10px 10px;
  }
  #qpcOverlay .qpc-target-tip-card{ font-family:Arial, "Malgun Gothic", "Noto Sans KR", sans-serif; }
  #qpcOverlay .qpc-target-tip-meta{ font-size:12px; line-height:1.35; margin-bottom:2px; color:#333; }
  #qpcOverlay .qpc-target-tip-svg{ margin-top:6px; }
  #qpcOverlay .qpc-target-tip-svg svg{ width:250px; height:auto; display:block; }
  #qpcOverlay .qpc-target-side-empty{ font-size:11px; color:var(--qpc-muted); }
  #qpcOverlay .qpc-target-marker{ width:9px; height:9px; display:inline-block; box-sizing:border-box; flex:0 0 9px; }
  #qpcOverlay .qpc-target-marker--overall{ border:1px solid rgba(255,255,255,.88); background:transparent; }
  #qpcOverlay .qpc-target-marker--within{ border:1px solid rgba(255,255,255,.88); background:#7c7c7c; }
  #qpcOverlay .qpc-target-marker--dialog.qpc-target-marker--overall{ border-color:#000; background:transparent; }
  #qpcOverlay .qpc-target-marker--dialog.qpc-target-marker--within{ border-color:#000; background:#6f6f6f; }

  #qpcOverlay .qpc-mini-dialog{
    position:absolute; z-index:20; display:none; min-width:392px; background:#ececec; color:#000; border:1px solid #808080;
    box-shadow:2px 2px 0 rgba(0,0,0,.32); font-family:Tahoma, "MS Sans Serif", "맑은 고딕", sans-serif; font-size:12px;
  }
  #qpcOverlay .qpc-mini-dialog[aria-hidden="false"]{ display:block; }
  #qpcOverlay .qpc-mini-titlebar{
    height:28px; display:flex; align-items:center; justify-content:space-between; gap:8px; padding:0 8px 0 10px;
    background:#ccff00; color:#000; cursor:move; user-select:none; border-bottom:1px solid #9dbd08; font-weight:700;
  }
  #qpcOverlay .qpc-mini-close{
    width:26px; height:22px; border:1px solid #808080; background:#ececec; color:#000; font-size:16px; line-height:1; cursor:pointer;
  }
  #qpcOverlay .qpc-mini-body{ padding:12px 14px 12px; }
  #qpcOverlay .qpc-mini-label{ display:block; margin-bottom:4px; }
  #qpcOverlay .qpc-mini-input,
  #qpcOverlay .qpc-mini-select{
    height:22px; border:1px solid #8f8f8f; background:#fff; color:#000; padding:2px 6px; box-sizing:border-box; font-size:12px;
  }
  #qpcOverlay .qpc-mini-select{ padding-right:24px; }
  #qpcOverlay .qpc-mini-btn{
    min-width:62px; height:24px; border:1px solid #8f8f8f; background:#ececec; color:#000; cursor:pointer; font-size:12px;
    box-shadow:inset 1px 1px 0 #fff;
  }
  #qpcOverlay .qpc-mini-btn:active{ box-shadow:inset -1px -1px 0 #fff; }
  #qpcOverlay .qpc-mini-grid{ display:grid; grid-template-columns:minmax(0,1fr) 118px; gap:12px; }
  #qpcOverlay .qpc-mini-preview-box{ border:1px solid #adadad; background:#f6f6f6; min-height:210px; padding:10px; box-sizing:border-box; }
  #qpcOverlay .qpc-mini-preview-title{ position:relative; top:-2px; display:inline-block; background:#ececec; padding:0 4px; margin-bottom:8px; font-weight:700; }
  #qpcOverlay .qpc-mini-preview-inner{ font-size:12px; }
  #qpcOverlay .qpc-legend-items-wrap{ display:grid; grid-template-columns:minmax(0,1fr) 26px; gap:6px; margin-top:6px; }
  #qpcOverlay .qpc-legend-items{ border:1px solid #8f8f8f; background:#fff; min-height:54px; padding:4px 6px; }
  #qpcOverlay .qpc-legend-item{ display:grid; grid-template-columns:16px 12px 1fr; align-items:center; gap:6px; padding:2px 2px; cursor:pointer; }
  #qpcOverlay .qpc-legend-item.is-selected{ background:#dbe8f8; }
  #qpcOverlay .qpc-legend-order{ display:grid; gap:4px; align-content:start; }
  #qpcOverlay .qpc-legend-order .qpc-mini-btn{ min-width:0; width:26px; padding:0; }
  #qpcOverlay .qpc-mini-form{ margin-top:10px; display:grid; grid-template-columns:82px minmax(0,1fr); align-items:center; gap:6px 8px; }
  #qpcOverlay .qpc-mini-theme-btn{ width:100%; height:18px; border:1px solid #8f8f8f; padding:0; background:#000; cursor:pointer; }
  #qpcOverlay .qpc-mini-status{ min-height:16px; color:#2f5b00; margin-top:8px; }
  #qpcOverlay .qpc-mini-actions{ display:flex; justify-content:flex-end; gap:8px; margin-top:10px; }
  #qpcOverlay .qpc-mini-caption{ font-weight:700; margin-bottom:6px; }
  #qpcOverlay .qpc-theme-head{ display:flex; align-items:center; gap:8px; margin-bottom:10px; }
  #qpcOverlay .qpc-theme-current{ display:flex; align-items:center; gap:8px; font-weight:700; }
  #qpcOverlay .qpc-theme-current-chip{ width:104px; height:16px; border:1px solid #8f8f8f; }
  #qpcOverlay .qpc-theme-grid{ display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:0; border:1px solid #9f9f9f; }
  #qpcOverlay .qpc-theme-col{ border-right:1px solid #9f9f9f; padding:8px 6px 6px; }
  #qpcOverlay .qpc-theme-col:last-child{ border-right:0; }
  #qpcOverlay .qpc-theme-col-title{ font-weight:700; margin-bottom:8px; text-align:center; }
  #qpcOverlay .qpc-theme-option{ display:block; width:100%; border:1px solid transparent; background:#fff; padding:2px; margin-bottom:6px; cursor:pointer; }
  #qpcOverlay .qpc-theme-option.is-selected{ border-color:#3b74d7; box-shadow:0 0 0 1px #3b74d7 inset; }
  #qpcOverlay .qpc-theme-sample{ display:grid; grid-auto-flow:column; grid-auto-columns:1fr; height:14px; }
  #qpcOverlay .qpc-theme-footer{ display:flex; justify-content:space-between; align-items:center; margin-top:10px; }
  #qpcOverlay .qpc-theme-user{ display:flex; align-items:center; gap:8px; }

  
  /* ===== JMP MIRROR LAYOUT (REPORT ONLY) ===== */
  #qpcOverlay[data-step="report"]{
    --qpc-report-scale: 1;
  }
  #qpcOverlay[data-step="report"] .qpc-stage-report{
    background:#f0f0f0;
    color:#000;
    padding:10px 12px 14px;
  }
  #qpcOverlay[data-step="report"] .qpc-report-shell{
    font-family: Arial, "Malgun Gothic", "Noto Sans KR", sans-serif;
  }
  #qpcOverlay[data-step="report"] .qpc-report-tree,
  #qpcOverlay[data-step="report"] .qpc-report-group,
  #qpcOverlay[data-step="report"] .qpc-report-sub{
    border:1px solid #bdbdbd;
    background:#ffffff;
    border-radius:0;
    margin:0 0 6px;
  }
  #qpcOverlay[data-step="report"] .qpc-report-tree > summary,
  #qpcOverlay[data-step="report"] .qpc-report-group > summary,
  #qpcOverlay[data-step="report"] .qpc-report-sub > summary{
    background:#e6e6e6;
    color:#000;
    border-bottom:1px solid #c9c9c9;
    padding:2px 8px;
    font-size:12px;
    font-weight:700;
    line-height:1.35;
  }
  #qpcOverlay[data-step="report"] .qpc-report-tree > summary:before,
  #qpcOverlay[data-step="report"] .qpc-report-group > summary:before,
  #qpcOverlay[data-step="report"] .qpc-report-sub > summary:before{
    color:#333;
  }
  #qpcOverlay[data-step="report"] .qpc-report-body,
  #qpcOverlay[data-step="report"] .qpc-report-group-body,
  #qpcOverlay[data-step="report"] .qpc-report-sub-body{
    border-top:0;
    background:#ffffff;
    padding:8px 10px 10px;
  }

  /* Top row (Histogram + Process Summary) */
  #qpcOverlay[data-step="report"] .qpc-report-hist-grid{
    gap:12px;
    align-items:start;
  }
  #qpcOverlay[data-step="report"] .qpc-report-top-grid{
    max-width:none;
    grid-template-columns:minmax(0, 1fr) 320px;
    gap:12px;
    align-items:start;
  }
  #qpcOverlay[data-step="report"] .qpc-report-sub-top > summary,
  #qpcOverlay[data-step="report"] .qpc-report-sub-hist > summary,
  #qpcOverlay[data-step="report"] .qpc-report-top-summary > summary{
    background:#e6e6e6;
    color:#000;
    border:0;
    border-bottom:1px solid #c9c9c9;
  }
  #qpcOverlay[data-step="report"] .qpc-report-sub-hist > .qpc-report-sub-body{
    padding:6px 8px 8px;
  }
  #qpcOverlay[data-step="report"] .qpc-hist-wrap,
  #qpcOverlay[data-step="report"] .qpc-summary-box,
  #qpcOverlay[data-step="report"] .qpc-stat-box,
  #qpcOverlay[data-step="report"] .qpc-reject-box,
  #qpcOverlay[data-step="report"] .qpc-target-main,
  #qpcOverlay[data-step="report"] .qpc-target-side{
    border:1px solid #bdbdbd;
    background:#ffffff;
  }
  #qpcOverlay[data-step="report"] .qpc-hist-wrap{
    padding:8px 8px 6px;
    background:#f7f7f7;
  }
  #qpcOverlay[data-step="report"] .qpc-svgbox--hist{
    max-width:600px;
  }
  #qpcOverlay[data-step="report"] .qpc-report-top-summary{
    border:1px solid #bdbdbd;
    background:#ffffff;
    overflow:hidden;
  }
  #qpcOverlay[data-step="report"] .qpc-report-top-summary-body{
    padding:6px 8px 8px;
  }
  #qpcOverlay[data-step="report"] .qpc-summary-grid{
    font-size:12px;
    gap:2px 10px;
  }
  #qpcOverlay[data-step="report"] .qpc-summary-grid .k,
  #qpcOverlay[data-step="report"] .qpc-summary-grid .v{
    color:#000;
  }
  #qpcOverlay[data-step="report"] .qpc-summary-sep{
    background:#d0d0d0;
  }

  /* Tables */
  #qpcOverlay[data-step="report"] .qpc-stat-table th,
  #qpcOverlay[data-step="report"] .qpc-stat-table td,
  #qpcOverlay[data-step="report"] .qpc-reject-table th,
  #qpcOverlay[data-step="report"] .qpc-reject-table td{
    border:1px solid #bdbdbd;
    color:#000;
    background:#ffffff;
  }
  #qpcOverlay[data-step="report"] .qpc-stat-table thead th,
  #qpcOverlay[data-step="report"] .qpc-reject-table thead th{
    background:#e6e6e6;
    font-weight:700;
  }
  #qpcOverlay[data-step="report"] .qpc-stat-table tbody th{
    background:#f3f3f3;
    font-weight:700;
  }
  #qpcOverlay[data-step="report"] .qpc-reject-box{
    padding:6px 8px 7px;
  }
  #qpcOverlay[data-step="report"] .qpc-report-sub-reject{
    width:max-content;
  }
  #qpcOverlay[data-step="report"] .qpc-report-sub-reject > .qpc-report-sub-body{
    padding:6px 8px 7px;
  }

  /* Target plot side (keep layout but JMP-like colors) */
  #qpcOverlay[data-step="report"] .qpc-target-side-title,
  #qpcOverlay[data-step="report"] .qpc-target-check,
  #qpcOverlay[data-step="report"] .qpc-target-ppk-label{
    color:#000;
  }
  #qpcOverlay[data-step="report"] .qpc-target-ppk-badge,
  #qpcOverlay[data-step="report"] .qpc-target-ppk-input{
    border:1px solid #bdbdbd;
    background:#ffffff;
    color:#000;
  }


  @media (max-width: 980px){
    #qpcOverlay{ --qpc-report-scale: 0.80; }
    #qpcOverlay .qpc-window{ left:8px; top:8px; transform:none; width:calc(100vw - 16px); height:calc(100vh - 16px); }
    #qpcOverlay .qpc-setup-grid{ grid-template-columns:1fr; }
    #qpcOverlay .qpc-vars{ height:220px; }
    #qpcOverlay .qpc-workcol{ grid-template-columns:repeat(3, minmax(0, 1fr)); }
    #qpcOverlay .qpc-worktitle{ grid-column:1 / -1; }
    #qpcOverlay .qpc-report-hist-grid,
    #qpcOverlay .qpc-report-top-grid,
    #qpcOverlay .qpc-report-two,
    #qpcOverlay .qpc-target-grid{ grid-template-columns:1fr; }
  }
</style>

<div id="qpcOverlay" aria-hidden="true" data-step="setup">
  <div class="qpc-window" id="qpcWindow" role="dialog" aria-modal="true" aria-label="공정 능력">
    <div class="qpc-titlebar">
      <div class="qpc-title" id="qpcTitleText">공정 능력</div>
      <button type="button" class="qpc-close" id="qpcCloseTop" aria-label="닫기">×</button>
    </div>

    <div class="qpc-stage qpc-stage-setup" id="qpcStageSetup">
      <div class="qpc-desc">규격 한계 관점에서 공정 능력을 분석합니다.</div>
      <div class="qpc-setup-grid">
        <div class="qpc-left">
          <div class="qpc-block-label">열 선택</div>
          <div class="qpc-panel">
            <div class="qpc-varcount" id="qpcVarCount">0개 열</div>
            <div class="qpc-searchrow">
              <input type="text" id="qpcVarSearch" class="qpc-input" placeholder="열 이름 입력" autocomplete="off">
              <button type="button" class="qpc-tinybtn" id="qpcVarRefresh" aria-label="새로고침">↻</button>
            </div>
            <div class="qpc-vars" id="qpcVarList"></div>
          </div>

          <div class="qpc-alpha">
            <span>유의 수준 지정</span>
            <input type="text" id="qpcAlpha" class="qpc-input" value="0.05">
          </div>

          <fieldset class="qpc-limitscope">
            <legend>규격 한계 대상자 표시</legend>
            <label class="qpc-radio"><input type="radio" name="qpcLimitScope" value="need" checked> <span>필요한 경우(열에 규격 한계가 없는 경우)</span></label>
            <label class="qpc-radio"><input type="radio" name="qpcLimitScope" value="always"> <span>예</span></label>
            <label class="qpc-radio"><input type="radio" name="qpcLimitScope" value="skip"> <span>아니요(규격 한계가 없는 열 건너뜀)</span></label>
          </fieldset>
        </div>

        <div class="qpc-main">
          <div class="qpc-role-title">선택한 열 역할 지정</div>
          <div class="qpc-role-header">
            <button type="button" id="qpcAssignY" class="qpc-btn qpc-btn-y">Y, 공정</button>
            <div class="qpc-dropzone-wrap">
              <div class="qpc-drophead"><span>필수</span><span class="qpc-subtle">선택적</span></div>
              <div class="qpc-dropzone" id="qpcAssignedList"><div class="qpc-dropzone-empty">열 선택 영역에서 여기로 드래그</div></div>
            </div>
          </div>

          <div class="qpc-groups">
            <details class="qpc-group" id="qpcGroupSubgroup">
              <summary>공정 부분군 지정</summary>
              <div class="qpc-group-body">
                <div class="qpc-row">
                  <button type="button" class="qpc-btn" disabled>부분군 ID 열 내보관</button>
                </div>
                <fieldset class="qpc-fieldset" style="display:inline-block; min-width:220px;">
                  <legend>다음으로 부분군 지정</legend>
                  <label><input type="radio" name="qpcSubgroupMode" value="subgroup_id" checked> <span>부분군 ID 열</span></label>
                  <label><input type="radio" name="qpcSubgroupMode" value="fixed_size"> <span>일정한 부분군 크기</span></label>
                </fieldset>
              </div>
            </details>

            <details class="qpc-group" id="qpcGroupMR">
              <summary>이동 범위 옵션</summary>
              <div class="qpc-group-body">
                <fieldset class="qpc-fieldset" style="display:inline-block; min-width:170px;">
                  <legend>이동 범위 통계량</legend>
                  <label><input type="radio" name="qpcMrStat" value="mean" checked> <span>이동 범위 평균</span></label>
                  <label><input type="radio" name="qpcMrStat" value="median"> <span>이동 범위 중앙값</span></label>
                </fieldset>
              </div>
            </details>

            <details class="qpc-group" id="qpcGroupHistSigma">
              <summary>과거 정보</summary>
              <div class="qpc-group-body">
                <div class="qpc-row"><label><input type="checkbox" id="qpcUseHistoricalSigma"> <span>과거 시그마 사용</span></label></div>
                <div class="qpc-row">
                  <span>과거 시그마 설정</span>
                  <select class="qpc-select" id="qpcHistoricalSigmaSelect" style="width:96px;">
                    <option value=""></option>
                    <option value="approved">승인값</option>
                    <option value="manual">수동</option>
                  </select>
                </div>
              </div>
            </details>

            <details class="qpc-group" id="qpcGroupDist">
              <summary>분포 옵션</summary>
              <div class="qpc-group-body">
                <div class="qpc-row">
                  <span>공정 분포 설정</span>
                  <select class="qpc-select" id="qpcDistribution" style="width:112px;">
                    <option value="normal">정규</option>
                    <option value="nonnormal">비정규</option>
                  </select>
                </div>
                <details class="qpc-group" style="margin-top:6px;">
                  <summary>비정규 분포 옵션</summary>
                  <div class="qpc-group-body">추가 비정규 분포 설정은 다음 단계에서 연결됩니다.</div>
                </details>
              </div>
            </details>

            <details class="qpc-group" id="qpcGroupBasis">
              <summary>기준</summary>
              <div class="qpc-group-body">
                <div class="qpc-row qpc-subtle">선택적</div>
                <div class="qpc-basis-box"></div>
              </div>
            </details>
          </div>

          <div class="qpc-status" id="qpcSetupStatus"></div>
        </div>

        <div class="qpc-workcol">
          <div class="qpc-worktitle">작업</div>
          <button type="button" class="qpc-work-btn" id="qpcSetupOk">확인</button>
          <button type="button" class="qpc-work-btn" id="qpcSetupCancel">취소</button>
          <button type="button" class="qpc-work-btn" id="qpcSetupRemove">제거</button>
          <button type="button" class="qpc-work-btn" id="qpcSetupRecall">재호출</button>
          <button type="button" class="qpc-work-btn" id="qpcSetupHelp">도움말</button>
        </div>
      </div>
    </div>

    <div class="qpc-stage qpc-stage-spec" id="qpcStageSpec" hidden>
      <div class="qpc-tableload">
        <div style="font-size:13px; margin-bottom:4px; font-weight:700;">데이터 테이블에서 규격 한계 적재</div>
        <button type="button" class="qpc-btn" id="qpcLoadTableBtn">데이터 테이블 선택</button>
      </div>

      <div class="qpc-specbox">
        <div style="font-size:13px; margin-bottom:8px; font-weight:700;">각 공정에 대한 규격 한계를 입력하십시오.</div>
        <table class="qpc-spec-table">
          <thead>
            <tr>
              <th style="width:34%;">공정</th>
              <th style="width:12%;">LSL</th>
              <th style="width:12%;">목표값</th>
              <th style="width:12%;">USL</th>
              <th style="width:18%;">공정 중요도</th>
              <th style="width:12%;">한계 표시</th>
            </tr>
          </thead>
          <tbody id="qpcSpecTableBody"></tbody>
        </table>

        <div class="qpc-spec-actions">
          <button type="button" class="qpc-btn" id="qpcSpecCheckAll">한계 표시 모두 선택</button>
          <div class="qpc-right">
            <button type="button" class="qpc-btn" id="qpcSpecOk">확인</button>
            <button type="button" class="qpc-btn" id="qpcSpecCancel">취소</button>
            <button type="button" class="qpc-btn" id="qpcSpecHelp">도움말</button>
          </div>
        </div>
        <div class="qpc-status" id="qpcSpecStatus"></div>
      </div>
    </div>

    <div class="qpc-stage qpc-stage-report" id="qpcStageReport" hidden>
      <div class="qpc-report-shell">
        <details class="qpc-report-tree" open>
          <summary>개별 상세 정보 보고서</summary>
          <div class="qpc-report-body" id="qpcReportBody"></div>
        </details>
      </div>
    </div>
  </div>

  <div class="qpc-mini-dialog" id="qpcLegendDialog" aria-hidden="true">
    <div class="qpc-mini-titlebar" data-dialog="legend"><div>범례 설정</div><button type="button" class="qpc-mini-close" id="qpcLegendClose">×</button></div>
    <div class="qpc-mini-body">
      <div class="qpc-mini-grid">
        <div>
          <label class="qpc-mini-label" for="qpcLegendTitle">제목: <input type="text" id="qpcLegendTitle" class="qpc-mini-input" style="width:154px;"></label>
          <div class="qpc-legend-items-wrap">
            <div class="qpc-legend-items" id="qpcLegendItems"></div>
            <div class="qpc-legend-order">
              <button type="button" class="qpc-mini-btn" id="qpcLegendMoveUp">▲</button>
              <button type="button" class="qpc-mini-btn" id="qpcLegendMoveDown">▼</button>
            </div>
          </div>
          <div class="qpc-mini-form">
            <div>색상 테마</div>
            <button type="button" id="qpcLegendThemeBtn" class="qpc-mini-theme-btn" aria-label="색상 테마"></button>
            <div>제목 위치:</div>
            <select id="qpcLegendTitlePos" class="qpc-mini-select"><option>위쪽</option><option>왼쪽</option><option>없음</option></select>
            <div>항목 방향:</div>
            <select id="qpcLegendItemDir" class="qpc-mini-select"><option>수직</option><option>수평</option></select>
            <div>항목 줄바꿈:</div>
            <select id="qpcLegendWrap" class="qpc-mini-select"><option>자동</option><option>수직</option><option>수평</option></select>
            <div>최대 항목 수</div>
            <input type="text" id="qpcLegendMaxItems" class="qpc-mini-input" value="256" style="width:76px; text-align:right;">
          </div>
                    <div class="qpc-mini-status" id="qpcLegendStatus"></div>
        </div>
        <div>
          <div class="qpc-mini-preview-box">
            <div class="qpc-mini-preview-title">미리보기</div>
            <div class="qpc-mini-preview-inner" id="qpcLegendPreview"></div>
          </div>
        </div>
      </div>
      <div class="qpc-mini-actions">
        <button type="button" class="qpc-mini-btn" id="qpcLegendOk">확인</button>
        <button type="button" class="qpc-mini-btn" id="qpcLegendCancel">취소</button>
        <button type="button" class="qpc-mini-btn" id="qpcLegendHelp">도움말</button>
      </div>
    </div>
  </div>

  <div class="qpc-mini-dialog" id="qpcThemeDialog" aria-hidden="true" style="min-width:560px;">
    <div class="qpc-mini-titlebar" data-dialog="theme"><div>범주형 색상 테마</div><button type="button" class="qpc-mini-close" id="qpcThemeClose">×</button></div>
    <div class="qpc-mini-body">
      <div class="qpc-theme-head">
        <div class="qpc-theme-current">현재 테마 <span class="qpc-theme-current-chip" id="qpcThemeCurrentChip"></span> <span id="qpcThemeCurrentName">JMP 기본 설정</span></div>
      </div>
      <div class="qpc-theme-grid" id="qpcThemeGrid"></div>
      <div class="qpc-theme-footer">
        <div class="qpc-theme-user"><button type="button" class="qpc-mini-btn" id="qpcThemeUserBtn">사용자 색상 테마</button><label>색적 <select id="qpcThemeColorScope" class="qpc-mini-select"><option>전체 색상</option></select></label></div>
        <div class="qpc-mini-actions" style="margin-top:0;">
          <button type="button" class="qpc-mini-btn" id="qpcThemeOk">확인</button>
          <button type="button" class="qpc-mini-btn" id="qpcThemeCancel">취소</button>
          <button type="button" class="qpc-mini-btn" id="qpcThemeHelp">도움말</button>
        </div>
      </div>
    </div>
  </div>

</div>

<?php
  $___qpc_js_path = dirname(__DIR__, 3) . '/assets/ipqc-process-capability.js';
  $___qpc_js_ver  = @filemtime($___qpc_js_path);
  if (!$___qpc_js_ver) $___qpc_js_ver = 1;
?>
<script src="<?= h($__QPC_BASE) ?>/assets/ipqc-process-capability.js?v=<?= h($___qpc_js_ver) ?>"></script>
