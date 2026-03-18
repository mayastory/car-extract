<?php
// IPQC Quick Graph Modal (Table-based)
// - Uses the CURRENT rendered pivot table in ipqc_view.php (no CSV fetch)
// - Y: average of Data 1~3 per (Tool, Cavity, Date)
// - Shows Data1~3 dots + min/max whisker + mean line
// - Reads USL/LSL from pivot <th data-usl/data-lsl>, allows inline edit (auto-save)

// Base root (assets must work under /public/legacy/* and pretty routes)
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
$__QG_BASE = ipqc_base_root();

// NOTE: helper h() exists in ipqc_view.php, but guard just in case
if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>

<style>
  /* Quick Graph modal (full screen) */
  /* NOTE: This overlay can live inside an iframe when running under DP_SHELL.
     dp_shell.js may promote the iframe to a full-viewport layer.
     Keep z-index extremely high to win against any fixed sidebars/widgets. */
  #qgOverlay{ position:fixed; inset:0; width:100vw; height:100vh; background:#0a0e0c; z-index:2147483647; display:none; overflow:hidden; isolation:isolate; }
  #qgOverlay[aria-hidden="false"]{ display:block; }
  /* Full-viewport modal (no outer margin) */
  #qgOverlay .qg-modal{ position:absolute; inset:0; background:#0a0e0c; border:0; border-radius:0; overflow:hidden; box-shadow:none; display:flex; flex-direction:column; }
  /* When Graph Builder is open, hide the page sidebar/userbar so the modal is truly full-screen (JMP-like). */
  body.qg-open .dp-rail,
  body.qg-open .dp-ub,
  body.qg-open #dpSidePanel,
  body.qg-open #dpSideBackdrop{
    display:none !important;
  }
  body.qg-open .page-wrap{ padding-left:0 !important; }
  /* Top bar: keep toolbar position stable (doesn't shift with title/FAI labels) */
  #qgOverlay .qg-top{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:10px; padding:8px 10px;
    overflow:hidden; border-bottom:1px solid rgba(255,255,255,0.08); background:rgba(0,0,0,0.25); }
  #qgOverlay .qg-top-left{ display:flex; align-items:center; gap:12px; min-width:0; }
  #qgOverlay .qg-top-mid{ display:flex; align-items:center; justify-content:center; min-width:0; }
  #qgOverlay .qg-title{ font-weight:700; letter-spacing:0.2px; white-space:nowrap; }

  /* Header toolbar (JMP-like icons) */
  #qgOverlay .qg-toolbar{ display:flex; align-items:center; gap:2px; }
  #qgOverlay .qg-toolbtn{ width:30px; height:30px; padding:2px; border-radius:3px; border:1px solid rgba(255,255,255,0.12); background:rgba(255,255,255,0.03); display:flex; align-items:center; justify-content:center; cursor:pointer; position:relative; overflow:hidden; }
  #qgOverlay .qg-toolbtn img{ width:24px; height:24px; display:block; position:relative; z-index:1; -webkit-user-drag:none; user-drag:none; -webkit-user-select:none; user-select:none; }
  #qgOverlay .qg-toolbtn:hover{ background:rgba(255,255,255,0.06); }
  #qgOverlay .qg-toolbtn.is-active{ border-color: rgba(70,140,255,0.95); background: rgba(70,140,255,0.10); }
  /* JMP-like active overlay (blue translucent square that sits "over" the icon) */
  #qgOverlay .qg-toolbtn.is-active::after{
    content:"";
    position:absolute;
    left:0; top:2px; right:2px; bottom:2px;
    border-radius:2px;
    background: rgba(70,140,255,0.26);
    border: 1px solid rgba(110,170,255,0.92);
    box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06);
    z-index:2;
    pointer-events:none;
  }

  /* Mixed (some FAI ON, some OFF): keep single overlay but make it visibly different (no stripes) */
  #qgOverlay .qg-toolbtn.is-active.is-mixed::after{
    background: rgba(70,140,255,0.14);
    border-style: dashed;
  }

  /* Drag-apply preview (JMP-like blue translucent scope box, per panel cells) */
  #qgDropPreview{
    position:fixed;
    left:0; top:0; right:0; bottom:0;
    display:none;
    pointer-events:none;
    z-index:2147483647;
    opacity: 0;
    transition: opacity 120ms ease;
  }
  #qgDropPreview.is-on{ display:block; opacity: 1; }
  #qgDropPreview.is-flash{ opacity: 1; }
  #qgDropPreview .qgDropPreviewCell{
    position:absolute;
    pointer-events:none;
    background: rgba(70,140,255,0.14);
    border: 1px solid rgba(110,170,255,0.55);
    box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06);
    border-radius: 16px;
  }
  #qgDropPreview.is-flash .qgDropPreviewCell{
    background: rgba(70,140,255,0.20);
  }

/* Split overlay (JMP-like per-FAI segments on toolbar icons) */
#qgOverlay .qg-toolbtn.qg-has-split::after{ display:none; }
#qgOverlay .qg-toolbtn .qg-splitOverlay{
  position:absolute;
  left:0; top:2px; right:2px; bottom:2px;
  z-index:2;
  pointer-events:none;
  border-radius:2px;
  overflow:hidden;
}

#qgOverlay .qg-toolbtn.qg-has-split.qg-splitAny .qg-splitOverlay{
  border: 1px solid rgba(110,170,255,0.92);
  box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06);
}
#qgOverlay .qg-toolbtn .qg-splitSeg{
  position:absolute;
  left:0; right:0;
  box-sizing:border-box;
  background:transparent;
  border-top:1px solid rgba(255,255,255,0.10);
}
#qgOverlay .qg-toolbtn .qg-splitOverlay .qg-splitSeg:first-child{ border-top:none; }
#qgOverlay .qg-toolbtn .qg-splitSeg.is-on{
  background: rgba(70,140,255,0.26);
}

#qgOverlay .qg-toolbtn:disabled{ opacity:0.35; cursor:default; }
  #qgOverlay .qg-toolbtn:disabled:hover{ background:rgba(255,255,255,0.03); }
  #qgOverlay .qg-actions{ display:flex; align-items:center; gap:8px; }
  #qgOverlay .qg-close{ padding:6px 10px; border-radius:10px; border:1px solid rgba(255,255,255,0.14); background:rgba(0,0,0,0.25); color:#fff; cursor:pointer; }
  /* Give more room to the plot area: shrink right legend dock a bit (JMP-like tighter layout) */
  #qgOverlay .qg-body{ display:grid; grid-template-columns: 260px 1fr 200px; flex:1 1 auto; min-height:0;   position:relative; }


  /* Guard: keep outer UI dark-mode (do NOT let plot/legend colors paint the whole modal) */
  #qgOverlay .qg-modal{ background:#0a0e0c !important; }
  #qgOverlay .qg-body{ background:transparent !important; }
  #qgOverlay .qg-main{ background:transparent !important; }
  #qgOverlay .qg-legend{ padding:6px 6px 6px 0; border-left:1px solid rgba(255,255,255,0.08); overflow-y:auto; overflow-x:hidden; display:flex; flex-direction:column; align-items:stretch; justify-content:flex-start; gap:10px; }


  #qgOverlay .qg-side{ padding:8px; border-right:1px solid rgba(255,255,255,0.08); overflow-y:auto; overflow-x:visible; position:relative; z-index:20; }
  #qgOverlay #qgQueryBox{ position:relative; overflow:visible; z-index:80; }
  #qgOverlay #qgQueryBox[open]{ z-index:120; }
  #qgOverlay #qgQueryBox > .qg-box-body,
  #qgOverlay .qg-query-wrap,
  #qgOverlay #qgQueryControls{ position:relative; overflow:visible; }
  #qgOverlay .qg-query-wrap .f{ margin-bottom:10px; position:relative; }
  #qgOverlay .qg-query-wrap .f.qg-ms-host-open{ z-index:500; }
  #qgOverlay .qg-query-wrap .f:last-child{ margin-bottom:0; }
  #qgOverlay .qg-query-wrap label{ display:block; font-size:12px; font-weight:700; margin-bottom:6px; }
  #qgOverlay .qg-query-wrap .ms{ position:relative; width:100%; min-width:0; }
  #qgOverlay .qg-query-wrap .ms.ms-years{ min-width:120px; }
  #qgOverlay .qg-query-wrap .ms.ms-page{ min-width:150px; }
  #qgOverlay .qg-query-wrap .ms.ms-type{ min-width:150px; }
  #qgOverlay .qg-query-wrap .ms.ms-model{ min-width:200px; }
  #qgOverlay .qg-query-wrap .ms.ms-fai{ min-width:200px; }
  #qgOverlay .qg-query-wrap .ms.open{ z-index:260; }
  #qgOverlay .qg-query-wrap .ms .ms-toggle{ width:100%; }
  #qgOverlay .qg-query-wrap .ms .ms-panel{ left:0; right:auto; width:360px; max-width:min(360px, calc(100vw - 60px)); z-index:261; }
  #qgOverlay .qg-query-wrap .ms.ms-years .ms-panel{ width:240px; max-width:min(240px, calc(100vw - 60px)); }
  #qgOverlay .qg-query-wrap .ms.ms-page .ms-panel{ left:auto; right:0; width:320px; max-width:min(320px, calc(100vw - 60px)); }
  #qgOverlay .qg-query-wrap .ms.ms-type .ms-panel{ width:260px; max-width:min(260px, calc(100vw - 60px)); }
  #qgOverlay .qg-query-wrap .ms.ms-model .ms-panel{ width:280px; max-width:min(280px, calc(100vw - 60px)); max-height:240px; overflow:hidden; }
  #qgOverlay .qg-query-wrap .ms.ms-fai .ms-panel{ width:340px; max-width:min(340px, calc(100vw - 60px)); max-height:340px; overflow:hidden; }
  #qgOverlay .qg-query-wrap .ms-grid-tools{ grid-template-columns:repeat(6, minmax(0, 1fr)); }
  #qgOverlay .qg-query-wrap .ms-grid-fai{ grid-template-columns:repeat(4, minmax(0, 1fr)); }
  #qgOverlay .qg-query-wrap .ms-grid-dates{ grid-template-columns:repeat(5, minmax(0, 1fr)); }
  #qgOverlay .qg-query-status{ display:none; margin-bottom:8px; font-size:12px; color:rgba(255,255,255,0.72); }
  #qgOverlay .qg-query-status.is-on{ display:block; }
  #qgOverlay .qg-main,
  #qgOverlay .qg-legend{ position:relative; z-index:1; }
  /* Reduce inner padding so the plot starts closer to the left and gains width */
  #qgOverlay .qg-main{ padding:4px; overflow:auto; position:relative; }


  /* Legend dock (outside plot) */
  #qgOverlay .qg-legend{ padding:6px 6px 6px 0; border-left:1px solid rgba(255,255,255,0.08); overflow-y:auto; overflow-x:hidden; display:flex; align-items:center; justify-content:center; position:relative; }
  #qgOverlay .qg-legend-card{
    width:100%;
    max-width:190px;
    border-radius:12px;
    /* JMP-like legend card (fixed light gray like JMP) */
    border:1px solid #cfcfcf;
    background:#f8f8f8;
    color:#000;
    padding:8px 10px;
    overflow:hidden;
  }

  /* Right-side drop zones (JMP-like): Overlay/Color/Size/Interval */
  #qgOverlay .qg-dropdock{
    overflow:hidden;
    box-sizing:border-box;
    width:128px;
    display:flex;
    flex-direction:column;
    gap:4px;              /* slight separation between boxes */
    padding:4px;
    border:1px solid #b9b9b9;
    background:#fff;
    box-shadow:0 1px 0 rgba(0,0,0,0.15);
  }
  #qgOverlay .qg-dropdock-item{
    box-sizing:border-box;
    width:100%;
    height:34px;
    border:1px solid #b9b9b9;
    background:#ffffff;
    color:#222;
    font-weight:700;
    font-size:12px;
    line-height:1;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 8px;
    user-select:none;
    cursor:default;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    position:relative;
  }
  #qgOverlay .qg-dropdock-item:last-child{ }

  /* Dropdock hover (drag-over): JMP-like translucent blue preview */
  #qgOverlay .qg-dropdock-item.hover{
    /* keep base background; draw preview using a pseudo layer to avoid dark fill / size changes */
    background:#ffffff;
  }
  #qgOverlay .qg-dropdock-item.hover::before{
    content:"";
    position:absolute;
    inset:2px;
    border-radius:6px;
    background:rgba(120,170,255,0.18);
    border:2px solid rgba(70,120,255,0.95);
    pointer-events:none;
  }

  /* Assigned var label rendered inline as "XX : Tool" */
  #qgOverlay .qg-dropdock-var{
    position:static;
    margin-left:4px;
    font-size:12px;
    font-weight:700;
    color:#333;
    font-style:italic;
    pointer-events:none;
    max-width:100%;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }
  #qgOverlay .qg-dropdock-item:not(.has-var) .qg-dropdock-var{ display:none; }


  /* Variable drag ghost */
  #qgOverlay .qg-var-ghost{
    position:fixed;
    z-index:99999;
    pointer-events:none;
    background:#f8f8f8;
    border:1px solid #c0c0c0;
    border-radius:8px;
    padding:4px 8px;
    font-weight:800;
    font-size:12px;
    color:#111;
    box-shadow:0 2px 6px rgba(0,0,0,0.25);
    white-space:nowrap;
  }


  /* Floating placement: mimic JMP right-side drop zones without pushing the legend */
  #qgOverlay .qg-dropdock-float{
    position:absolute;
    left:0;
    top:120px;
    z-index:60;
    margin:0;
    pointer-events:auto;
  }

  #qgOverlay .qg-legend-card{ align-self:center; }
  /* Legend list is dynamic (built by JS) */
  #qgOverlay .qg-legend-item{ display:flex; align-items:center; gap:6px; margin:2px 0; font-size:11px; opacity:0.92; color:#000;  min-width:0; }
  #qgOverlay .qg-legend-item .qg-lg-label{
    color:#000;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display:block;
    flex:1 1 auto;
    min-width:0;
  }
  #qgOverlay .qg-lg-sq{
    width:10px; height:10px;
    border-radius:0;
    border:1px solid var(--qg-c, #2b5bd7);
    background:transparent;
    display:inline-block;
    box-sizing:border-box;
    position:relative;
    overflow:visible;
  }
  /* JMP-like whisker caps for legend data marker */
  #qgOverlay .qg-lg-sq::before,
  #qgOverlay .qg-lg-sq::after{
    content:"";
    position:absolute;
    left:50%;
    width:18px;
    transform:translateX(-50%);
    border-top:1px dashed var(--qg-c, #2b5bd7);
    opacity:0.95;
    pointer-events:none;
  }
  #qgOverlay .qg-lg-sq::before{ top:-2px; }
  #qgOverlay .qg-lg-sq::after{ bottom:-2px; }

  /* JMP-like boxplot marker in legend (box + mean line + dashed caps) */
  #qgOverlay .qg-lg-boxplot{
    width:14px; height:12px;
    display:inline-block;
    color: var(--qg-c, #2b5bd7);
  }
  #qgOverlay .qg-lg-boxplot .qg-lg-svg{ width:14px; height:12px; display:block; }
/* JMP-like thin line sample */
  #qgOverlay .qg-lg-line{ width:14px; height:0; border-top:2px solid var(--qg-c, #2b5bd7); display:inline-block; }
  #qgOverlay .qg-lg-mean{ width:14px; height:12px; display:inline-block; color: var(--qg-c, #2b5bd7); }
  .qg-lg-mean svg{ width:14px; height:12px; display:block; }
  #qgOverlay .qg-lg-dash{ width:14px; height:0; border-top:2px dashed #2b5bd7; display:inline-block; }

  #qgOverlay .qg-lg-vline{ width:14px; height:12px; display:inline-block; color: var(--qg-c, #000); }
  #qgOverlay .qg-lg-vline svg{ width:14px; height:12px; display:block; }


  
  /* USL/LSL combined sample: two dashed lines (top & bottom) */
  #qgOverlay .qg-lg-usllsl{
    width:22px; height:12px; display:inline-block; position:relative;
  }
  #qgOverlay .qg-lg-usllsl:before,
  #qgOverlay .qg-lg-usllsl:after{
    content:'';
    position:absolute;
    left:0; right:0;
    border-top:2px dashed #2b5bd7;
    opacity:0.95;
  }
  #qgOverlay .qg-lg-usllsl:before{ top:3px; }
  #qgOverlay .qg-lg-usllsl:after{ bottom:3px; }
/* Groups */
  #qgOverlay details.qg-box{ border:1px solid rgba(255,255,255,0.10); border-radius:12px; background:rgba(0,0,0,0.18); margin-bottom:10px; overflow:hidden; transition:border-color .14s ease, box-shadow .14s ease, background .14s ease; }
  #qgOverlay details.qg-box:hover{ border-color:rgba(29,185,84,0.32); box-shadow:0 0 0 1px rgba(29,185,84,0.10), inset 0 1px 0 rgba(255,255,255,0.03); }
  #qgOverlay details.qg-box[open]{ border-color:rgba(29,185,84,0.24); }
  #qgOverlay details.qg-box > summary{
    list-style:none;
    cursor:pointer;
    padding:10px;
    font-size:13px;
    font-weight:800;
    opacity:0.92;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    user-select:none;
    position:relative;
    transition:background .14s ease, box-shadow .14s ease, border-color .14s ease, color .14s ease;
  }
  #qgOverlay details.qg-box > summary:hover,
  #qgOverlay details.qg-box > summary:focus-visible{
    background:rgba(29,185,84,0.12);
    box-shadow:inset 0 0 0 1px rgba(29,185,84,0.24);
    color:#f2fff6;
    outline:none;
  }
  #qgOverlay details.qg-box[open] > summary{
    border-bottom:1px solid rgba(255,255,255,0.08);
    background:linear-gradient(180deg, rgba(29,185,84,0.12) 0%, rgba(29,185,84,0.05) 100%);
    box-shadow:inset 0 -1px 0 rgba(255,255,255,0.02);
  }
  #qgOverlay details.qg-box[open] > summary:hover,
  #qgOverlay details.qg-box[open] > summary:focus-visible{
    background:linear-gradient(180deg, rgba(29,185,84,0.18) 0%, rgba(29,185,84,0.08) 100%);
    box-shadow:inset 0 0 0 1px rgba(29,185,84,0.28), inset 0 -1px 0 rgba(255,255,255,0.02);
  }
  #qgOverlay details.qg-box > summary::-webkit-details-marker{ display:none; }
  #qgOverlay details.qg-box > summary:after{ content:'▾'; opacity:0.70; font-weight:900; transition:opacity .14s ease, color .14s ease, transform .14s ease; }
  #qgOverlay details.qg-box > summary:hover:after,
  #qgOverlay details.qg-box > summary:focus-visible:after{ opacity:1; color:#c9ffd9; }
  #qgOverlay details.qg-box:not([open]) > summary:after{ content:'▸'; }
  #qgOverlay .qg-box-body{ padding:10px; }

  #qgOverlay .qg-pc-entry{
    width:100%;
    display:flex; align-items:center; justify-content:center;
    padding:12px 10px;
    margin:0 0 10px 0;
    border-radius:12px;
    border:1px solid rgba(29,185,84,0.26);
    background:linear-gradient(180deg, rgba(29,185,84,0.16) 0%, rgba(29,185,84,0.06) 100%);
    color:#f2fff6; font-weight:900; letter-spacing:0.2px;
    cursor:pointer;
    transition:background .14s ease, box-shadow .14s ease, border-color .14s ease, transform .08s ease;
  }
  #qgOverlay .qg-pc-entry:hover{
    background:linear-gradient(180deg, rgba(29,185,84,0.22) 0%, rgba(29,185,84,0.09) 100%);
    border-color:rgba(29,185,84,0.38);
    box-shadow:0 0 0 1px rgba(29,185,84,0.14), inset 0 1px 0 rgba(255,255,255,0.03);
  }
  #qgOverlay .qg-pc-entry:active{ transform:translateY(1px); }

  #qgOverlay .qg-hint{ font-size:12px; opacity:0.75; line-height:1.25; }
  #qgOverlay .qg-row{ display:flex; gap:10px; align-items:center; }
  #qgOverlay .qg-select,
  #qgOverlay .qg-input{
    width:100%;
    padding:10px 10px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(0,0,0,0.25);
    color:#fff;
    box-sizing:border-box;
    outline:none;
  }
  
  #qgOverlay .qg-select:disabled,
  #qgOverlay .qg-input:disabled{
    opacity:0.45;
    cursor:not-allowed;
    background:rgba(0,0,0,0.18);
  }
#qgOverlay .qg-input::placeholder{ color:rgba(255,255,255,0.55); }

  #qgOverlay .qg-chkline{ display:flex; align-items:center; gap:8px; font-size:13px; opacity:0.90; user-select:none; }
  #qgOverlay .qg-chk{ width:14px; height:14px; accent-color: rgba(70,140,255,0.95); }


/* Select list (Tool/Cavity/FAI) */
.qg-list{ display:flex; flex-direction:column; gap:8px; }

/* JMP-like gradient bars:
   - inactive: gray gradient
   - active: green gradient (only selected items)
   Fill bar indicates relative count and follows the same color family. */
.qg-item{
  position:relative;
  display:flex;
  align-items:center;
  padding:7px 10px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,0.10);
  background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(0,0,0,0.35));
  cursor:pointer;
  user-select:none;
  overflow:hidden;
}
.qg-item:hover{ border-color: rgba(255,255,255,0.18); }

.qg-item .fill{
  position:absolute;
  left:0; top:0; bottom:0;
  width:0%;
  background: linear-gradient(90deg, rgba(200,200,200,0.32), rgba(120,120,120,0.18));
  pointer-events:none;
}

.qg-item .txt{
  position:relative;
  z-index:1;
  width:100%;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.qg-item .k{ font-weight:900; letter-spacing:0.2px; font-size:13px; }
.qg-item .n{ width:52px; text-align:right; font-variant-numeric:tabular-nums; opacity:0.92; font-size:12px; }

.qg-item.active{
  border-color: rgba(29,185,84,0.95);
  background: linear-gradient(180deg, rgba(44,190,95,0.72), rgba(18,120,56,0.72));
  box-shadow: inset 0 0 0 1px rgba(0,0,0,0.18), inset 0 12px 24px rgba(255,255,255,0.06);
}
.qg-item.active .fill{
  background: linear-gradient(90deg, rgba(29,185,84,0.70), rgba(29,185,84,0.28));
}

#qgOverlay #qgMsg{ display:none; margin-bottom:10px; padding:8px 10px;
    overflow:hidden; border-radius:12px; border:1px solid rgba(255,255,255,0.10); background:rgba(0,0,0,0.25); opacity:0.92; }

  
    /* Tool header band (JMP-like) */
  /* Tighter vertical rhythm so stacked panels visually connect (JMP-like). */
  #qgOverlay .qg-tool-group{ position:relative; margin-bottom:0; }

  /* Align header with FAI rows: [label 34px] + [gap 6px] + [svg width] */
  #qgOverlay .qg-tophead{
    position:sticky; top:0; z-index:6;
    display:grid;
    grid-template-columns: 34px 1fr;
    gap:6px;
    background:transparent;
    border:none;
    margin-bottom:0;
  }
  #qgOverlay .qg-tophead-stub{
    /* keep the left label column untouched */
    background:transparent;
  }
  #qgOverlay .qg-tophead-body{
    /* add backdrop only behind the actual header body */
    background:transparent;
    min-width:0;
    overflow:hidden;
    line-height:0;
  }
  #qgOverlay .qg-tophead-svg{
    display:block;
    width:100%;
    height:82px;
    background:#ffffff;
  }

  /* The visible band starts at the plot area (skip the y-axis zone inside SVG).
     SVG viewBox is 1200 wide; padL=62 (~5.2%), padR=14 (~1.2%). */
  #qgOverlay .qg-tophead-band{
    /* px vars are set by JS for pixel-perfect alignment; pct vars remain as fallback */
    margin-left: var(--qg-padL-px, var(--qg-padL-pct, 5.2%));
    margin-right: var(--qg-padR-px, var(--qg-padR-pct, 1.2%));
    border:1px solid #cfcfcf;
    border-bottom:none;
    border-radius:0;
    overflow:hidden;
    background:#ffffff;
    box-sizing:border-box;
  }

  /* Remove “double borders” between stacked SVG panels (JMP-like continuous page) */
  #qgOverlay .qg-tool-group .qg-tophead + .qg-fai-row .qg-svg{ margin-top:-1px; }
  #qgOverlay .qg-tool-group .qg-fai-row + .qg-fai-row .qg-svg{ margin-top:-1px; }

  #qgOverlay .qg-tophead-row{
    text-align:center;
    padding:3px 6px;
    font-weight:700;
    font-size:11px;
    color:#000;
    background:#dedbcf;
    border-bottom:1px solid #cfcfcf;
  }

  /* Tool value row */
  #qgOverlay .qg-tophead-tools{
    display:grid;
    background:#f8f8f8;
    border-bottom:1px solid #cfcfcf;
    box-sizing:border-box;
  }
  #qgOverlay .qg-tophead-tool{
    text-align:center;
    padding:4px 0;
    font-weight:700;
    font-size:11px;
    color:#000;
    border-left:1px solid #cfcfcf;
    box-sizing:border-box;
    min-width:0;
  }
  #qgOverlay .qg-tophead-tool.first{ border-left:none; }

  /* Cavity row */
  #qgOverlay .qg-tophead-cavs{ display:grid; background:#dedbcf; box-sizing:border-box; }
  #qgOverlay .qg-tophead-cav{
    text-align:center;
    padding:4px 0;
    font-weight:700;
    font-size:11px;
    color:#000;
    border-left:1px solid #cfcfcf;
    box-sizing:border-box;
    min-width:0;
  }
  #qgOverlay .qg-tophead-cav.tool-start{ border-left:1px solid #cfcfcf; }
  #qgOverlay .qg-tophead-cav:first-child{ border-left:none; }



  /* Row content wrapper */
  #qgOverlay .qg-fai-one{ width:100%; }
/* Grid */
  /* Remove inter-group gaps so the header/FAI panels look like one continuous page (JMP-like) */
  #qgOverlay #qgGrid{ display:grid; gap:0; }
  .qg-card{ border:1px solid rgba(255,255,255,0.10); border-radius:12px; background:rgba(0,0,0,0.18); overflow:hidden; }
  .qg-card .hd{ padding:8px 10px;
    overflow:hidden; border-bottom:1px solid rgba(255,255,255,0.08); font-size:12px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .qg-card .hd .t{ font-weight:900; }
  .qg-card .hd .s{ opacity:0.80; }
  .qg-card .bd{ padding:8px; }

  /* Multi-FAI rows */
  #qgOverlay .qg-fai-row{ margin-bottom:0; }
  #qgOverlay .qg-fai-hd{ padding:6px 2px 10px; font-weight:900; opacity:0.92; }
  #qgOverlay .qg-fai-tools{ display:flex; flex-wrap:wrap; gap:10px; }
  #qgOverlay .qg-card-compact{ flex: 1 1 420px; min-width:420px; }

  /* Make selection text sit nicely inside the pills */
  #qgOverlay .qg-item .k{ max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .qg-svg{ width:100%; height:auto; display:block; }
  .qg-empty{ padding:18px 10px; opacity:0.75; text-align:center; }

  @media (max-width: 1050px){
    #qgOverlay .qg-body{ grid-template-columns: 1fr; }
    #qgOverlay .qg-side{ border-right:none; border-bottom:1px solid rgba(255,255,255,0.08); }
    #qgOverlay .qg-legend{ border-left:none; border-top:1px solid rgba(255,255,255,0.08); justify-content:flex-start; }
    #qgOverlay .qg-legend-card{ max-width:none; }

  }

  /* FAI rows (override) */
  /* Slightly tighter gap between label and plot to gain width */
  #qgOverlay .qg-fai-row{ display:grid; grid-template-columns: 34px 1fr; gap:6px; margin-bottom:0; align-items:stretch; min-height:0; overflow:hidden; }
  #qgOverlay .qg-fai-one{ min-height:0; overflow:hidden; line-height:0; }
  #qgOverlay .qg-row-label{ display:flex; align-items:center; justify-content:center; align-self:start; border:1px solid rgba(255,255,255,0.08); border-radius:0; background:rgba(0,0,0,0.18); min-height:0; overflow:hidden; }
  #qgOverlay .qg-row-label .vtxt{ writing-mode: vertical-rl; transform: rotate(180deg); font-weight:900; font-size:12px; letter-spacing:0.2px; opacity:0.92; padding:0; }

  /* Custom context menu (right-click) */
  #qgOverlay .qg-ctxmenu{
    position:fixed; z-index:2147483647; display:none;
    min-width:180px; padding:6px;
    background:rgba(12,16,14,0.98);
    border:1px solid rgba(255,255,255,0.12);
    border-radius:12px;
    box-shadow:0 12px 40px rgba(0,0,0,0.55);
    backdrop-filter: blur(6px);
  }
  #qgOverlay .qg-ctxmenu[aria-hidden="false"]{ display:block; }
  #qgOverlay .qg-ctxitem{
    display:flex; align-items:center; gap:10px;
    padding:8px 10px;
    overflow:hidden; border-radius:10px;
    color:#fff; font-size:13px; cursor:pointer;
    user-select:none;
  }
  #qgOverlay .qg-ctxitem:hover{ background:rgba(255,255,255,0.07); }
  #qgOverlay .qg-ctxitem.is-disabled{ opacity:0.4; cursor:default; }
  #qgOverlay .qg-ctxitem.is-disabled:hover{ background:transparent; }
  #qgOverlay .qg-ctxsep{ height:1px; background:rgba(255,255,255,0.10); margin:6px 2px; }

  /* Hover tooltip (JMP-like speech bubble) */
  #qgOverlay .qg-hover-tip{
    position:fixed;
    left:0; top:0;
    z-index:2147483647;
    display:none;
    pointer-events:none;
    padding:6px 8px;
    background:#ffffff;
    color:#000;
    border:1px solid rgba(0,0,0,0.32);
    border-radius:10px;
    box-shadow:0 10px 28px rgba(0,0,0,0.18);
    font-size:12px;
    line-height:1.15;
    white-space:nowrap;
  }
  #qgOverlay .qg-hover-tip.is-show{ display:block; }
  #qgOverlay .qg-hover-tip:before{
    content:'';
    position:absolute;
    left:10px;
    top:-7px;
    border:7px solid transparent;
    border-bottom-color: rgba(0,0,0,0.32);
  }
  #qgOverlay .qg-hover-tip:after{
    content:'';
    position:absolute;
    left:10px;
    top:-6px;
    border:6px solid transparent;
    border-bottom-color:#ffffff;
  }

  /* In-app dialog (no browser prompt) */
  #qgOverlay .qg-dlg-backdrop{
    position:fixed; inset:0; z-index:2147483647; display:none;
    align-items:center; justify-content:center;
    background:rgba(0,0,0,0.35);
  }
  #qgOverlay .qg-dlg-backdrop[aria-hidden="false"]{ display:flex; }
  #qgOverlay .qg-dlg{
    width:440px; max-width:calc(100vw - 40px);
    background:rgba(12,16,14,0.98);
    border:1px solid rgba(255,255,255,0.12);
    border-radius:18px;
    padding:14px 14px 12px;
    box-shadow:0 18px 60px rgba(0,0,0,0.60);
  }
  #qgOverlay .qg-dlg-title{ font-weight:800; margin-bottom:10px; }
  #qgOverlay .qg-dlg-input{
    width:100%;
    box-sizing:border-box;
    padding:12px 12px;
    border-radius:14px;
    border:1px solid rgba(120,255,160,0.55);
    background:rgba(0,0,0,0.20);
    color:#fff;
    outline:none;
  }
  #qgOverlay .qg-dlg-actions{
    display:flex; justify-content:flex-end; gap:10px;
    margin-top:12px;
  }
  #qgOverlay .qg-dlg-row{
    display:flex;
    align-items:center;
    gap:10px;
    margin:8px 0;
  }
  #qgOverlay .qg-dlg-row label{
    min-width:72px;
    font-size:12px;
    opacity:0.85;
  }
  #qgOverlay .qg-dlg-ro{
    width:100%;
    box-sizing:border-box;
    padding:12px 12px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.04);
    color:rgba(255,255,255,0.90);
    outline:none;
  }


  /* User-defined (Graph User Def) dialog */
  #qgOverlay .qg-ud-backdrop{
    position:fixed; inset:0; z-index:2147483647; display:none;
    align-items:center; justify-content:center;
    background:rgba(0,0,0,0.25);
  
    will-change: transform;}
  #qgOverlay .qg-ud-backdrop[aria-hidden="false"]{ display:flex; }

  #qgOverlay .qg-ud{
    width:820px; max-width:calc(100vw - 40px);
    height:460px; max-height:calc(100vh - 40px);
    background:#efefef;
    border:1px solid rgba(0,0,0,0.25);
    border-radius:6px;
    box-shadow:0 18px 60px rgba(0,0,0,0.45);
    overflow:hidden;
    color:#111;
    font-family:system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  }
  #qgOverlay .qg-ud-titlebar{
    background:#d8ff3a;
    padding:6px 10px;
    font-weight:700;
    display:flex; align-items:center; justify-content:space-between;
    border-bottom:1px solid rgba(0,0,0,0.22);
  
    cursor:grab;
    user-select:none;
    -webkit-user-select:none;
    touch-action:none;}
  #qgOverlay .qg-ud-body{
    display:grid;
    grid-template-columns: 180px 1fr 110px;
    height:calc(100% - 34px);
  }
  #qgOverlay .qg-ud-nav{
    border-right:1px solid rgba(0,0,0,0.18);
    background:#f7f7f7;
    padding:8px;
    overflow:auto;
  }
  #qgOverlay .qg-ud-navitem{
    padding:6px 8px;
    border:1px solid transparent;
    border-radius:3px;
    cursor:pointer;
    font-size:13px;
    user-select:none;
  }
  #qgOverlay .qg-ud-navitem:hover{ background:#e9e9e9; }
  #qgOverlay .qg-ud-navitem.is-active{
    background:#dfeeff;
    border-color:#5a8cff;
  }
  #qgOverlay .qg-ud-main{
    padding:8px 10px;
    overflow:hidden;
    overflow:auto;
    background:#ffffff;
  }
  #qgOverlay .qg-ud-group{
    border:1px solid rgba(0,0,0,0.20);
    border-radius:3px;
    padding:10px;
    margin-bottom:10px;
  }
  #qgOverlay .qg-ud-group > .h{
    font-weight:700;
    margin:-2px 0 8px 0;
  }
  #qgOverlay .qg-ud-row{
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    margin-bottom:8px;
    font-size:13px;
  }
  #qgOverlay .qg-ud-row label{ min-width:64px; }

  /* dot rows: keep hide checkbox aligned */
  #qgOverlay .qg-ud-row-dothead{ flex-wrap:nowrap; }
  #qgOverlay .qg-ud-hidewrap{ margin-left:auto; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
  #qgOverlay .qg-ud-hidelabel{ min-width:64px; text-align:right; }
  #qgOverlay .qg-ud-dotpad{ width:42px; height:28px; display:inline-block; }

  #qgOverlay .qg-ud-visicon{
    width:34px; height:28px;
    display:flex; align-items:center; justify-content:center;
    border:1px solid rgba(0,0,0,0.25);
    border-radius:3px;
    background:#fff;
    pointer-events:none;
    user-select:none;
    -webkit-user-select:none;
  }
  #qgOverlay .qg-ud-visicon svg{ width:18px; height:18px; display:block; }
  #qgOverlay .qg-ud-color{ background:#fff; }
  #qgOverlay .qg-ud-chk{ width:16px; height:16px; display:inline-block; vertical-align:middle; appearance:auto; -webkit-appearance:auto; }

  #qgOverlay .qg-ud-inp, #qgOverlay .qg-ud-sel{
    height:28px;
    border:1px solid rgba(0,0,0,0.25);
    border-radius:3px;
    padding:0 8px;
    font-size:13px;
    background:#fff;
    color:#111;
  }
  #qgOverlay .qg-ud-inp{ width:90px; }
  #qgOverlay .qg-ud-inp.wide{ width:160px; }
  #qgOverlay .qg-ud-stylepick{ position:relative; display:inline-block; }
  #qgOverlay .qg-ud-stylebtn{
    height:28px;
    border:1px solid rgba(0,0,0,0.25);
    border-radius:3px;
    padding:0 8px;
    font-size:13px;
    background:#fff;
    color:#111;
    display:flex; align-items:center; gap:8px;
    cursor:pointer;
    min-width:190px;
  }
  #qgOverlay .qg-ud-stylebtn:focus{ outline:2px solid rgba(90,140,255,0.35); outline-offset:2px; }
  #qgOverlay .qg-ud-styleprev{ width:74px; height:10px; display:inline-block; }
  #qgOverlay .qg-ud-caret{ margin-left:auto; opacity:0.7; }
  #qgOverlay .qg-ud-stylelist{
    position:absolute;
    left:0; top:calc(100% + 2px);
    z-index:2147483647;
    background:#fff;
    border:1px solid rgba(0,0,0,0.25);
    border-radius:3px;
    box-shadow:0 10px 28px rgba(0,0,0,0.22);
    padding:4px;
    min-width:260px;
  }
  #qgOverlay .qg-ud-styleopt{
    display:flex; align-items:center; gap:10px;
    padding:6px 8px;
    border-radius:3px;
    cursor:pointer;
    user-select:none;
  }
  #qgOverlay .qg-ud-styleopt:hover{ background:rgba(0,0,0,0.06); }
  #qgOverlay .qg-ud-styleopt.is-active{ background:rgba(90,140,255,0.18); }
  #qgOverlay .qg-ud-styleopt .prev{ width:74px; height:10px; }
  #qgOverlay .qg-ud-styleopt .txt{ flex:1; white-space:nowrap; }

  #qgOverlay .qg-ud-btncol{
    border-left:1px solid rgba(0,0,0,0.18);
    background:#f7f7f7;
    padding:10px;
    display:flex;
    flex-direction:column;
    gap:10px;
    align-items:stretch;
  }
  #qgOverlay .qg-ud-btn{
    height:32px;
    border-radius:3px;
    border:1px solid rgba(0,0,0,0.25);
    background:#ffffff;
    font-size:13px;
    cursor:pointer;
  }
  #qgOverlay .qg-ud-mini{
    padding:6px 10px;
    border:1px solid rgba(0,0,0,0.28);
    background:#f4f4f4;
    border-radius:4px;
    cursor:pointer;
    font-size:12px;
  }
  #qgOverlay .qg-ud-mini:disabled{ opacity:0.45; cursor:default; }
  #qgOverlay .qg-ud-btn:hover{ background:#f0f0f0; }

  #qgOverlay .qg-btn{
    padding:10px 14px; border-radius:14px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.06);
    color:#fff; cursor:pointer;
  }
  #qgOverlay .qg-btn:hover{ background:rgba(255,255,255,0.10); }
  #qgOverlay .qg-btn-ghost{ background:transparent; }
  #qgOverlay .qg-btn-ghost:hover{ background:rgba(255,255,255,0.06); }
  #qgOverlay .qg-btn-primary{
    border-color:rgba(120,255,160,0.45);
    background:rgba(120,255,160,0.18);
  }
  #qgOverlay .qg-btn-primary:hover{ background:rgba(120,255,160,0.24); }
  #qgOverlay .qg-btn-danger{
    border-color:rgba(255,120,120,0.45);
    background:rgba(255,120,120,0.14);
  }
  #qgOverlay .qg-btn-danger:hover{ background:rgba(255,120,120,0.22); }

  #qgOverlay .qg-ud-titlebar.is-dragging{ cursor:grabbing; }
</style>

<div id="qgOverlay" aria-hidden="true" data-base="<?= h($__QG_BASE) ?>">
  <div class="qg-modal" role="dialog" aria-modal="true" aria-label="그래프 빌더">
	    <div class="qg-top">
	      <div class="qg-top-left">
	        <div class="qg-title">그래프 빌더</div>
	      </div>

	      <div class="qg-top-mid">
	        <!-- JMP-like toolbar (icons only for now; 3 implemented buttons are enabled) -->
	        <div class="qg-toolbar" role="toolbar" aria-label="그래프 빌더 도구">
          <?php
            $icons = [
              'icon_01.png','icon_02.png','icon_03.png','icon_04.png','icon_05.png',
              'icon_06.png','icon_07.png','icon_08.png','icon_09.png','icon_10.png',
              'icon_11.png','icon_12.png','icon_13.png','icon_14.png','icon_15.png'
            ];
            // Only these are wired/implemented for now (others are shown as disabled placeholders)
            $enabled = ['icon_01.png', 'icon_06.png', 'icon_09.png', 'icon_15.png'];
            $defaultActive = ['icon_01.png', 'icon_06.png', 'icon_09.png'];
            $auxMap = [
              'icon_15.png' => 'caption',
            ];
            $elemMap = [
              'icon_01.png' => 'points',
              'icon_06.png' => 'line',
              'icon_09.png' => 'box',
            ];
          ?>
          <?php foreach ($icons as $fn):
            $isEnabled = in_array($fn, $enabled, true);
            $isActive  = in_array($fn, $defaultActive, true);
            $elem = $elemMap[$fn] ?? '';
            $aux  = $auxMap[$fn] ?? '';
          ?>
            <button type="button" class="qg-toolbtn<?= $isActive ? ' is-active' : '' ?>"<?= $isEnabled ? '' : ' disabled' ?> aria-label="<?= h($fn) ?>"<?= ($isEnabled && $elem) ? ' data-qg-elem="' . h($elem) . '"': (($isEnabled && $aux) ? ' data-qg-aux="' . h($aux) . '"': '') ?>>
              <img src="<?= h($__QG_BASE) ?>/ipqc_img.php?f=<?= h($fn) ?>" alt="" draggable="false">
            </button>
          <?php endforeach; ?>
	        </div>
	      </div>

	      <div class="qg-actions">
        <button type="button" class="qg-close" id="qgClose">닫기</button>
      </div>
    </div>

    <div class="qg-body">
      <div class="qg-side">

        <details class="qg-box" id="qgQueryBox" open>
          <summary id="qgQuerySummary">조회 설정</summary>
          <div class="qg-box-body qg-query-wrap">
            <div id="qgQueryStatus" class="qg-query-status">조회 중...</div>
            <div id="qgQueryControls"></div>
          </div>
        </details>

        <!-- 대상 FAI (Ctrl/⌘ 멀티, 최대 9) -->
        <details class="qg-box" open>
          <summary id="qgFaiSummary">대상 FAI</summary>
          <div class="qg-box-body">
            <div id="qgFaiList" class="qg-list"></div>
          </div>
        </details>

        <details class="qg-box" open>
          <summary id="qgToolSummary">Tool</summary>
          <div class="qg-box-body">
            <div class="qg-hint" style="margin-bottom:8px;">· Ctrl/⌘ 멀티</div>
            <div id="qgToolList" class="qg-list"></div>
          </div>
        </details>

        <details class="qg-box" open>
          <summary id="qgCavitySummary">Cavity</summary>
          <div class="qg-box-body">
            <div class="qg-hint" style="margin-bottom:8px;">· Ctrl/⌘ 멀티</div>
            <div id="qgCavityList" class="qg-list"></div>
          </div>
        </details>

        <!-- 처음 열 때는 닫힌 상태(사용자가 필요할 때만 펼치도록) -->
        <details class="qg-box">
          <summary id="qgAxisSummary">축/기준선</summary>
          <div class="qg-box-body">
            <div class="qg-hint" style="margin-bottom:8px;">· 비우면 자동 범위</div>
            <div class="qg-row" style="margin-bottom:8px;">
              <input id="qgYMin" class="qg-input" placeholder="Y Min (예: -0.005)">
            </div>
            <div class="qg-row" style="margin-bottom:10px;">
              <input id="qgYMax" class="qg-input" placeholder="Y Max (예: 0.015)">
            </div>
            <div class="qg-row" style="margin-top:10px; margin-bottom:14px;">
              <label class="qg-chkline">
                <input id="qgHideGrid" type="checkbox" class="qg-chk" checked>
                <span>그리드 숨기기</span>
              </label>
            </div>

            <div class="qg-hint" style="margin-top:14px; margin-bottom:8px;">· USL/LSL</div>
            <div class="qg-row" style="margin-bottom:8px;">
              <input id="qgUSL" class="qg-input" placeholder="USL (빈칸=NULL)">
            </div>
            <div class="qg-row">
              <input id="qgLSL" class="qg-input" placeholder="LSL (빈칸=NULL)">
            </div>

            <div class="qg-hint" style="margin-top:10px; margin-bottom:8px;">· OOC SPEC</div>
            <div class="qg-row" style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
              <input id="qgOocSpecRange" type="range" min="1" max="100" step="1" value="85" style="flex:1; min-width:0;">
              <input id="qgOocSpecPct" type="number" min="1" max="100" step="1" value="85" class="qg-input" style="width:74px; text-align:right;">
              <span style="min-width:16px; text-align:left;">%</span>
            </div>
            <div class="qg-row" style="margin-top:-2px; margin-bottom:10px;">
              <label class="qg-chkline">
                <input id="qgShowOocSpecLine" type="checkbox" class="qg-chk">
                <span>OOC SPEC 선 표시</span>
              </label>
            </div>

            <div class="qg-row" style="margin-top:10px;">
              <label class="qg-chkline">
                <input id="qgHideUSLLSLLabel" type="checkbox" class="qg-chk">
                <span>USL/LSL 숨기기</span>
              </label>
            </div>


          </div>
        </details>

        <!-- Placeholder panel (will be filled later) -->
        <details class="qg-box">
          <summary id="qgPlotElemSummary">그래프 요소 패널</summary>
          <div class="qg-box-body">
            <div id="qgPlotElemPanel" class="qg-hint"></div>
          </div>
        </details>
</div>

      <div class="qg-main">
        <div id="qgMsg"></div>
        <div id="qgGrid"></div>
      </div>

      <div class="qg-legend" aria-label="범례">
        <div class="qg-dropdock qg-dropdock-float" aria-label="드롭 존">
          <div class="qg-dropdock-item" data-dock="overlay">중첩<span class="qg-dropdock-var"></span></div>
          <div class="qg-dropdock-item" data-dock="color">색상<span class="qg-dropdock-var"></span></div>
          <div class="qg-dropdock-item" data-dock="size">크기<span class="qg-dropdock-var"></span></div>
          <div class="qg-dropdock-item" data-dock="interval">구간<span class="qg-dropdock-var"></span></div>
        </div>
        <div class="qg-legend-card">
          <div id="qgLegendItems"></div>
        </div>
</div>

      <!-- Custom right-click context menu (JMP-like) -->
      <div id="qgCtxMenu" class="qg-ctxmenu" aria-hidden="true">
        <div id="qgCtxItems"></div>
      </div>

      <!-- Hover tooltip -->
      <div id="qgHoverTip" class="qg-hover-tip" aria-hidden="true"></div>

      <!-- Drag-apply preview overlay (scope highlight) -->
      <div id="qgDropPreview" aria-hidden="true"></div>

      <!-- Baseline dialog (in-app, no browser prompt) -->
      <div id="qgVarDlgBackdrop" class="qg-dlg-backdrop" aria-hidden="true">
        <div class="qg-dlg" role="dialog" aria-modal="true" aria-label="기준선 설정">
          <div class="qg-dlg-title" id="qgVarDlgTitle">기준선 설정</div>
          <input id="qgVarDlgInput" class="qg-dlg-input" inputmode="decimal" autocomplete="off">
          <div class="qg-dlg-actions">
            <button type="button" class="qg-btn qg-btn-ghost" id="qgVarDlgCancel">취소</button>
            <button type="button" class="qg-btn qg-btn-danger" id="qgVarDlgDelete" style="display:none;">삭제</button>
            <button type="button" class="qg-btn qg-btn-primary" id="qgVarDlgOk">적용</button>
          </div>
        </div>
      

      </div>

      
      <!-- Rename dialog (alias: legend / user-define title / graph label only) -->
      <div id="qgRenameDlgBackdrop" class="qg-dlg-backdrop" aria-hidden="true">
        <div class="qg-dlg" role="dialog" aria-modal="true" aria-label="리네임">
          <div class="qg-dlg-title">리네임</div>

          <div class="qg-dlg-row">
            <label>원본명</label>
            <input id="qgRenameOrig" class="qg-dlg-ro" readonly>
          </div>

          <div class="qg-dlg-row">
            <label>표시명</label>
            <input id="qgRenameDisp" class="qg-dlg-input" autocomplete="off" spellcheck="false">
          </div>

          <div class="qg-dlg-actions">
            <button type="button" class="qg-btn qg-btn-ghost" id="qgRenameCancel">취소</button>
            <button type="button" class="qg-btn" id="qgRenameReset">초기화</button>
            <button type="button" class="qg-btn qg-btn-primary" id="qgRenameOk">적용</button>
          </div>
        </div>
      </div>

<!-- Graph user define dialog (JMP-like) -->
            <div id="qgUDDlgBackdrop" class="qg-ud-backdrop" aria-hidden="true">
        <div class="qg-ud" role="dialog" aria-modal="true" aria-label="그래프 사용자 정의">
          <div class="qg-ud-titlebar">
            <div>그래프 사용자 정의</div>
            <div id="qgUDTitleSub" style="opacity:.75; font-weight:600;"></div>
          </div>

          <div class="qg-ud-body">
            <div class="qg-ud-nav" id="qgUDNav">
              <div class="qg-ud-navitem" data-tab="bg">배경</div>
              <div class="qg-ud-navitem" data-tab="line">선</div>
              <div class="qg-ud-navitem" data-tab="point">점</div>
              <div class="qg-ud-navitem" data-tab="box">박스</div>
              <div class="qg-ud-navitem is-active" data-tab="varline">기준선</div>
            </div>

            <div class="qg-ud-main">

              <!-- 배경 -->
              <div class="qg-ud-tab" data-tab="bg" style="display:none;">
                <div class="qg-ud-group">
                  <div class="h">특성</div>

                  <div class="qg-ud-row">
                    <label>배경:</label>
                    <span class="qg-ud-visicon" id="qgUD_bg_icon" aria-hidden="true">
                      <svg class="qg-ud-icon-svg" viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
                        <rect x="2" y="2" width="12" height="10" fill="currentColor" stroke="rgba(0,0,0,0.35)" stroke-width="1"/>
                      </svg>
                    </span>
                    <input id="qgUD_bg_color" type="color" class="qg-ud-inp qg-ud-color" style="width:42px; padding:0;">
                  </div>

                  <div class="qg-ud-row">
                    <label>투명도:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_bg_opacity" type="range" min="0" max="100" step="5" class="qg-ud-oprange">
                      <span class="qg-ud-numwrap" style="display:inline-flex; align-items:center; gap:4px;"><input id="qgUD_bg_opacity_lbl" class="qg-ud-oplbl" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" value="100" spellcheck="false"><span class="qg-ud-suf">%</span></span>
                    </div>
                  </div>

                  <div class="qg-ud-row">
                    <label>시작값:</label>
                    <input id="qgUD_bg_start" class="qg-ud-inp" inputmode="decimal" placeholder="">
                    <label style="min-width:64px;">종료값:</label>
                    <input id="qgUD_bg_end" class="qg-ud-inp" inputmode="decimal" placeholder="">
                  </div>
                </div>
              </div>

              <!-- 선 -->
              <div class="qg-ud-tab" data-tab="line" style="display:none;">
                <div class="qg-ud-group">
                  <div class="h">특성</div>

                  <div class="qg-ud-row">
                    <label>평균선:</label>
                    <span class="qg-ud-visicon" id="qgUD_mean_icon" aria-hidden="true">
                      <svg class="qg-ud-icon-svg" viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
                        <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="butt" stroke-linejoin="miter">
                          <line x1="1" y1="7" x2="15" y2="7" />
                        </g>
                        <circle cx="8" cy="7" r="2.2" fill="currentColor" />
                      </svg>
                    </span>

                    <input id="qgUD_mean_color" type="color" class="qg-ud-inp qg-ud-color" style="width:42px; padding:0;">

                    <label style="min-width:54px;">스타일:</label>
                    <div class="qg-ud-stylepick" id="qgUD_mean_stylepick">
                      <button type="button" class="qg-ud-stylebtn" id="qgUD_mean_style_btn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="qg-ud-styleprev" aria-hidden="true"></span>
                        <span class="qg-ud-styletxt">실선</span>
                        <span class="qg-ud-caret" aria-hidden="true">▾</span>
                      </button>
                      <div class="qg-ud-stylelist" id="qgUD_mean_style_list" role="listbox" tabindex="-1" hidden></div>
                      <select id="qgUD_mean_style" class="qg-ud-sel" style="display:none;">
                        <option value="solid">실선</option>
                        <option value="dash">점선(대시)</option>
                        <option value="dot">점선(도트)</option>
                        <option value="dashdot">점선(대시-점)</option>
                      </select>
                    </div>
                  </div>

                  <div class="qg-ud-row">
                    <label>너비:</label>
                    <input id="qgUD_mean_width" class="qg-ud-inp" inputmode="decimal" placeholder="2">
                  </div>

                  <div class="qg-ud-row">
                    <label>투명도:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_mean_opacity" type="range" min="0" max="100" step="5" class="qg-ud-oprange">
                      <span class="qg-ud-numwrap" style="display:inline-flex; align-items:center; gap:4px;"><input id="qgUD_mean_opacity_lbl" class="qg-ud-oplbl" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" value="100" spellcheck="false"><span class="qg-ud-suf">%</span></span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- 점 -->
              <div class="qg-ud-tab" data-tab="point" style="display:none;">
                <div class="qg-ud-group">
                  <div class="h">특성</div>

                  <div class="qg-ud-row qg-ud-row-dothead">
                    <label>평균선 점:</label>
                    <span class="qg-ud-visicon" id="qgUD_mean_dot_icon" aria-hidden="true">
                      <svg class="qg-ud-icon-svg" viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="8" cy="7" r="3" fill="currentColor" />
                      </svg>
                    </span>
                    <input id="qgUD_mean_dot_color" type="color" class="qg-ud-inp qg-ud-color" style="width:42px; padding:0;">
                    <span class="qg-ud-hidewrap">
                      <span class="qg-ud-hidelabel">점 숨김:</span>
                      <input id="qgUD_mean_dot_hide" type="checkbox" class="qg-ud-chk">
                    </span>
                  </div>

                  
                  <div class="qg-ud-row">
                    <label>크기:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_mean_dot_size" type="range" min="1" max="8" step="0.5" class="qg-ud-oprange">
                      <input id="qgUD_mean_dot_size_lbl" class="qg-ud-oplbl" value="" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" placeholder="">
                    </div>
                  </div>

	                  <div class="qg-ud-row">
	                    <label>투명도:</label>
	                    <div class="qg-ud-opwrap">
	                      <input id="qgUD_mean_dot_opacity" type="range" min="0" max="100" step="5" class="qg-ud-oprange">
	                      <span class="qg-ud-numwrap" style="display:inline-flex; align-items:center; gap:4px;"><input id="qgUD_mean_dot_opacity_lbl" class="qg-ud-oplbl" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" value="100" spellcheck="false"><span class="qg-ud-suf">%</span></span>
</div>
	                  </div>

	                  <div class="qg-ud-row qg-ud-row-dothead" style="margin-top:12px;">
	                    <label>데이터 점:</label>
	                    <span class="qg-ud-visicon" id="qgUD_data_dot_icon" aria-hidden="true">
	                      <svg class="qg-ud-icon-svg" viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
	                        <circle cx="8" cy="7" r="2.5" fill="currentColor" />
	                      </svg>
	                    </span>
	                    <input id="qgUD_data_dot_color" type="color" class="qg-ud-inp qg-ud-color" style="width:42px; padding:0;">
<span class="qg-ud-hidewrap">
	                      <span class="qg-ud-hidelabel">점 숨김:</span>
	                      <input id="qgUD_data_dot_hide" type="checkbox" class="qg-ud-chk">
	                    </span>
	                  </div>

                  <div class="qg-ud-row">
                    <label>크기:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_data_dot_size" type="range" min="1" max="8" step="0.5" class="qg-ud-oprange">
                      <input id="qgUD_data_dot_size_lbl" class="qg-ud-oplbl" value="" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" placeholder="">
                    </div>
                  </div>

                  <div class="qg-ud-row">
                    <label>투명도:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_data_dot_opacity" type="range" min="0" max="100" step="5" class="qg-ud-oprange">
                      <span class="qg-ud-numwrap" style="display:inline-flex; align-items:center; gap:4px;"><input id="qgUD_data_dot_opacity_lbl" class="qg-ud-oplbl" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" value="100" spellcheck="false"><span class="qg-ud-suf">%</span></span>
                    </div>
                  </div>

	                </div>
              </div>

              <!-- 박스 -->
              <div class="qg-ud-tab" data-tab="box" style="display:none;">
                <div class="qg-ud-group">
                  <div class="h">특성</div>

                  <div class="qg-ud-row">
                    <label>박스:</label>
                    <span class="qg-ud-visicon" id="qgUD_box_icon" aria-hidden="true">
                      <svg class="qg-ud-icon-svg" viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="3" width="10" height="8" fill="none" stroke="currentColor" stroke-width="2"/>
                      </svg>
                    </span>

                    <input id="qgUD_box_color" type="color" class="qg-ud-inp qg-ud-color" style="width:42px; padding:0;">
                    <label style="min-width:46px;">채움:</label>
                    <input id="qgUD_box_fill" type="checkbox" class="qg-ud-chk" style="margin:0 6px 0 0;">
                    <input id="qgUD_box_fill_color" type="color" class="qg-ud-inp qg-ud-color" style="width:42px; padding:0;">
                  </div>

                  <div class="qg-ud-row">
                    <label>박스 두께:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_box_thickness" type="range" min="20" max="100" step="5" class="qg-ud-oprange">
                      <span class="qg-ud-numwrap" style="display:inline-flex; align-items:center; gap:4px;"><input id="qgUD_box_thickness_lbl" class="qg-ud-oplbl" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" value="100" spellcheck="false"><span class="qg-ud-suf">%</span></span>
                    </div>
                  </div>

                  <div class="qg-ud-row">
                    <label>선 두께:</label>
                    <input id="qgUD_box_stroke_width" class="qg-ud-inp" inputmode="decimal" style="width:76px;">
                  </div>

                  <div class="qg-ud-row">
                    <label>투명도:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_box_opacity" type="range" min="0" max="100" step="5" class="qg-ud-oprange">
                      <span class="qg-ud-numwrap" style="display:inline-flex; align-items:center; gap:4px;"><input id="qgUD_box_opacity_lbl" class="qg-ud-oplbl" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" value="100" spellcheck="false"><span class="qg-ud-suf">%</span></span>
                    </div>
                  </div>

                  <div class="qg-ud-row">
                    <label>채움 투명도:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_box_fill_opacity" type="range" min="0" max="100" step="5" class="qg-ud-oprange">
                      <span class="qg-ud-numwrap" style="display:inline-flex; align-items:center; gap:4px;"><input id="qgUD_box_fill_opacity_lbl" class="qg-ud-oplbl" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" value="100" spellcheck="false"><span class="qg-ud-suf">%</span></span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- 기준선 -->
              <div class="qg-ud-tab" data-tab="varline" style="display:none;">
                <div class="qg-ud-group">
                  <div class="h">특성</div>

                  <div class="qg-ud-row">
                    <label>기준선:</label>
                    <select id="qgUD_var_list" class="qg-ud-sel" style="min-width:220px;"></select>
                    <button type="button" class="qg-ud-mini" id="qgUD_var_add">추가</button>
                    <button type="button" class="qg-ud-mini" id="qgUD_var_del">삭제</button>
                  </div>

                  <div class="qg-ud-row">
                    <label>값:</label>
                    <input id="qgUD_var_value" class="qg-ud-inp wide" inputmode="decimal" placeholder="예: 0.0123">
                  </div>

                  <div class="qg-ud-row">
                    <label>선 색상:</label>
                    <input id="qgUD_var_color" type="color" class="qg-ud-inp" style="width:42px; padding:0;">
                    <label style="min-width:54px;">스타일:</label>
                    <div class="qg-ud-stylepick" id="qgUD_var_stylepick">
                      <button type="button" class="qg-ud-stylebtn" id="qgUD_var_style_btn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="qg-ud-styleprev" aria-hidden="true"></span>
                        <span class="qg-ud-styletxt">실선</span>
                        <span class="qg-ud-caret" aria-hidden="true">▾</span>
                      </button>
                      <div class="qg-ud-stylelist" id="qgUD_var_style_list" role="listbox" tabindex="-1" hidden></div>
                      <select id="qgUD_var_style" class="qg-ud-sel" style="display:none;">
                        <option value="dashdot">점선(대시-점)</option>
                        <option value="dash">점선(대시)</option>
                        <option value="dot">점선(도트)</option>
                        <option value="solid">실선</option>
                      </select>
                    </div>
                  </div>

                  <div class="qg-ud-row">
                    <label>너비:</label>
                    <input id="qgUD_var_width" class="qg-ud-inp" inputmode="decimal" placeholder="1.5">
                  </div>

                  <div class="qg-ud-row">
                    <label>투명도:</label>
                    <div class="qg-ud-opwrap">
                      <input id="qgUD_var_opacity" type="range" min="0" max="100" step="5" class="qg-ud-oprange">
                      <span class="qg-ud-numwrap" style="display:inline-flex; align-items:center; gap:4px;"><input id="qgUD_var_opacity_lbl" class="qg-ud-oplbl" inputmode="decimal" style="width:68px; text-align:right; border:1px solid #bdbdbd; border-radius:4px; padding:2px 6px;" value="100" spellcheck="false"><span class="qg-ud-suf">%</span></span>
                    </div>
                  </div>

                  <div class="qg-ud-row">
                    <label>라벨:</label>
                    <input id="qgUD_var_label" type="checkbox" class="qg-ud-chk">
                    <label style="min-width:76px;">텍스트:</label>
                    <input id="qgUD_var_prefix" class="qg-ud-inp wide" placeholder="OOC/OOS">
                  </div>
                </div>
              </div>

            </div>

            <div class="qg-ud-btncol">
              <button type="button" class="qg-ud-btn" id="qgUD_Ok">확인</button>
              <button type="button" class="qg-ud-btn" id="qgUD_Apply">적용</button>
              <button type="button" class="qg-ud-btn" id="qgUD_Close">닫기</button>
              <button type="button" class="qg-ud-btn" id="qgUD_Help" disabled>도움말</button>
            </div>
          </div>
        </div>
      </div>

</div>

    </div>
  </div>
</div>

<?php
  // Cache-busting for iterative UI work: update automatically when the JS file changes.
  $___qg_js_path = dirname(__DIR__, 3) . '/assets/ipqc-quick-graph.js';
  $___qg_js_ver  = @filemtime($___qg_js_path);
  if (!$___qg_js_ver) $___qg_js_ver = 1;
?>
<script src="<?= h($__QG_BASE) ?>/assets/ipqc-quick-graph.js?v=<?= h($___qg_js_ver) ?>"></script>