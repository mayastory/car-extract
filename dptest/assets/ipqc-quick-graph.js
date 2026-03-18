/* IPQC Quick Graph (Table-based)
 * - Uses the CURRENT rendered pivot table in ipqc_view.php (no CSV fetch)
 * - Y series: mean of Data 1/2/3 per (Tool, Cavity, Date)
 * - Shows min/max whiskers + 3 replicate dots (center aligned)
 * - Shows USL/LSL lines (read from pivot column <th data-usl/data-lsl>)
 * - USL/LSL input applies immediately + auto-saves to DB (debounced)
 *
 * Multi-select rule:
 *   - Windows: Ctrl
 *   - macOS: Command (⌘)
 *   - Without Ctrl/⌘: single-select
 */

(function(){
  'use strict';

  // Fixed JMP-like series palette (order must be stable):
  // 1) Blue  2) Red  3) Green  4) Purple
  const QG_SERIES_COLORS = [
    '#3d6fe3', // 1 blue
    '#d84b57', // 2 red
    '#4db556', // 3 green
    '#9b43df', // 4 purple
    '#cf7d28', // 5 orange
    '#34b7aa', // 6 teal
    '#d43ad1', // 7 magenta
    '#c6bc31', // 8 olive yellow
    '#35b7c8', // 9 cyan
    '#d9419f', // 10 pink
    '#98b52c', // 11 yellow green
    '#2fa3d2', // 12 sky blue
    '#71c0a0', // 13 mint
    '#1f4f9b', // 14 navy
    '#2442b5', // 15 deep blue
    '#b44bd5'  // 16 violet fallback
  ];

  // Tool color palette (stable): re-use QG_SERIES_COLORS order
  function qgGetToolColorByIndex(i){
    try{
      const n = QG_SERIES_COLORS.length || 1;
      const ii = Math.max(0, Number(i)||0);
      return QG_SERIES_COLORS[(ii % n + n) % n];
    }catch(e){
      return '#2b5bd7';
    }
  }

  // Cavity color palette (stable): re-use QG_SERIES_COLORS order
  function qgGetCavityColorByIndex(i){
    return qgGetToolColorByIndex(i);
  }

  // Stable marker palette for nominal channels (JMP-like symbol differentiation)
  const QG_MARKER_SHAPES = ['circle', 'square', 'diamond', 'triangle', 'plus', 'cross'];

  function qgStableIndexInList(list, value){
    try{
      const arr = Array.isArray(list) ? list : [];
      const vv = String(value == null ? '' : value);
      const idx = arr.findIndex(it => String(it == null ? '' : it) === vv);
      return idx >= 0 ? idx : 0;
    }catch(e){
      return 0;
    }
  }

  function qgGetToolColorByValue(tool){
    return qgGetToolColorByIndex(qgStableIndexInList(QG.tools || [], tool));
  }

  function qgGetCavityColorByValue(cavity){
    return qgGetCavityColorByIndex(qgStableIndexInList(QG.cavities || [], cavity));
  }

  function qgGetShapeByIndex(i){
    try{
      const n = QG_MARKER_SHAPES.length || 1;
      const ii = Math.max(0, Number(i)||0);
      return QG_MARKER_SHAPES[(ii % n + n) % n] || 'circle';
    }catch(e){
      return 'circle';
    }
  }

  function qgGetToolShapeByValue(tool){
    return qgGetShapeByIndex(qgStableIndexInList(QG.tools || [], tool));
  }

  function qgGetCavityShapeByValue(cavity){
    return qgGetShapeByIndex(qgStableIndexInList(QG.cavities || [], cavity));
  }

  function qgNormalizeDockVar(v){
    const s = String(v || '').trim().toLowerCase();
    return (s === 'tool' || s === 'cavity') ? s : null;
  }

  function qgGetColorForVarValue(varKey, value){
    const vk = qgNormalizeDockVar(varKey);
    if (vk === 'tool') return qgGetToolColorByValue(value);
    if (vk === 'cavity') return qgGetCavityColorByValue(value);
    return '#444444';
  }

  function qgGetShapeForVarValue(varKey, value){
    const vk = qgNormalizeDockVar(varKey);
    if (vk === 'tool') return qgGetToolShapeByValue(value);
    if (vk === 'cavity') return qgGetCavityShapeByValue(value);
    return 'circle';
  }

  function qgResolveVisualChannels(){
    qgEnsureDockState();
    const dock = (QG && QG.dockVars) ? QG.dockVars : {};
    const overlayVar = qgNormalizeDockVar(dock.overlay);
    const colorVar = qgNormalizeDockVar(dock.color);
    let shapeVar = qgNormalizeDockVar(dock.shape);

    if (shapeVar && shapeVar === colorVar) shapeVar = null;

    // Auto-promote the remaining nominal variable to marker shape only when color is already in use.
    if (!shapeVar && colorVar && overlayVar && overlayVar !== colorVar){
      shapeVar = overlayVar;
    }

    return { overlayVar, colorVar, shapeVar };
  }



  const QG = {
    built: false,
    isPivot: false,
    table: null,
    cols: [], // [{idx, key, label, usl, lsl, th}]
    data: null, // raw grouped: tool->cav->date->{__rows:[{label,tds}]}
    seriesByCol: null, // {colKey: series}
    series: null, // computed for primary colKey: tool->cav->date->{vals,min,max,mean}
    tools: [],
    cavities: [],
    sel: {
      colKeys: new Set(),
      primaryColKey: '',
      tools: new Set(),
      cavities: new Set(),
    },
    editingLimits: false,
    editingAxis: false,
    limitSaveTimer: null,
    lastLimitPayload: '',
    varLines: {}, // {colKey:[{id,value}]}
    varLineSeq: 1,
    axisByCol: {}, // {colKey:{yMin:null|number,yMax:null|number}}
    gridHidden: true, // grid hide toggle (default: hidden)
    oocSpecByCol: {}, // {colKey: pct} per-FAI OOC SPEC percentage (85 keeps current base/input USL/LSL)
    oocLineVisibleByCol: {}, // {colKey:boolean} per-FAI OOC reference line visibility
    limitBaseByCol: {}, // {colKey:{baseUsl,baseLsl}} per-FAI base limits before OOC scaling
    editingOocSpec: false,
    plotElems: { points:true, line:true, box:true }, // toolbar elements (Shift multi-select)
    captionByColKey: {}, // {colKey:{enabled,stats:[...],xPos,yPos}} (display only)
    displayNameByColKey: {}, // {colKey:display} (legend/user-define/graph label only)
    query: {
      options: null,
      state: null,
      timer: null,
      reqSeq: 0,
      busy: false,
      forceAutoFill: false,
    },
  };

  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.from((root||document).querySelectorAll(sel)); }

  function isMac(){
    try{ return /Mac|iPhone|iPad|iPod/.test(navigator.platform) || /Mac OS X/.test(navigator.userAgent); }
    catch(e){ return false; }
  }
  function multiKey(ev){
    try{ if (typeof window.msMultiKey === 'function') return !!window.msMultiKey(ev); }catch(e){}
    if (!ev) return false;
    return isMac() ? !!ev.metaKey : !!ev.ctrlKey;
  }

  function num(v){
    if (v === null || v === undefined) return null;
    const s = String(v).replace(/,/g,'').trim();
    if (s === '') return null;
    const x = parseFloat(s);
    return isFinite(x) ? x : null;
  }

  function qgClamp01(v){
    const x = Number(v);
    if (!isFinite(x)) return 1;
    return Math.max(0, Math.min(1, x));
  }

  function qgAppendMarkerShape(svg, shape, x, y, size, opt){
    if (!svg) return null;
    const ns = 'http://www.w3.org/2000/svg';
    const s = Math.max(1, Number(size) || 2);
    const fill = (opt && opt.fill !== undefined && opt.fill !== null) ? String(opt.fill) : '#2b5bd7';
    const stroke = (opt && opt.stroke !== undefined && opt.stroke !== null) ? String(opt.stroke) : fill;
    const opacity = qgClamp01(opt && opt.opacity !== undefined && opt.opacity !== null ? opt.opacity : 1);
    const strokeW = Math.max(0.8, Number(opt && opt.strokeWidth) || 1.2);
    const marker = String(shape || 'circle').toLowerCase();

    const g = document.createElementNS(ns, 'g');
    const applyClip = (node)=>{
      try{ if (opt && typeof opt.clip === 'function') opt.clip(node); }catch(e){}
    };
    const applyTip = ()=>{
      try{
        if (opt && opt.tip) g.setAttribute('data-qg-tip', String(opt.tip));
      }catch(e){}
    };

    const append = (node, lineOnly)=>{
      if (!node) return;
      if (lineOnly){
        node.setAttribute('fill', 'none');
        node.setAttribute('stroke', stroke);
        node.setAttribute('stroke-width', String(strokeW));
        node.setAttribute('stroke-opacity', String(opacity));
        node.setAttribute('stroke-linecap', 'round');
        node.setAttribute('stroke-linejoin', 'round');
      }else{
        node.setAttribute('fill', fill);
        node.setAttribute('fill-opacity', String(opacity));
        node.setAttribute('stroke', stroke);
        node.setAttribute('stroke-width', String(Math.max(0.8, strokeW * 0.7)));
        node.setAttribute('stroke-opacity', String(opacity));
        node.setAttribute('stroke-linejoin', 'round');
      }
      g.appendChild(node);
    };

    if (marker === 'square'){
      const side = s * 1.9;
      const rect = document.createElementNS(ns, 'rect');
      rect.setAttribute('x', String(x - side/2));
      rect.setAttribute('y', String(y - side/2));
      rect.setAttribute('width', String(side));
      rect.setAttribute('height', String(side));
      append(rect, false);
    }else if (marker === 'diamond'){
      const r = s * 1.25;
      const poly = document.createElementNS(ns, 'polygon');
      poly.setAttribute('points', [
        [x, y-r].join(','),
        [x+r, y].join(','),
        [x, y+r].join(','),
        [x-r, y].join(',')
      ].join(' '));
      append(poly, false);
    }else if (marker === 'triangle'){
      const r = s * 1.35;
      const poly = document.createElementNS(ns, 'polygon');
      poly.setAttribute('points', [
        [x, y-r].join(','),
        [x+r, y+r*0.85].join(','),
        [x-r, y+r*0.85].join(',')
      ].join(' '));
      append(poly, false);
    }else if (marker === 'plus'){
      const r = s * 1.35;
      const ln1 = document.createElementNS(ns, 'line');
      ln1.setAttribute('x1', String(x-r));
      ln1.setAttribute('y1', String(y));
      ln1.setAttribute('x2', String(x+r));
      ln1.setAttribute('y2', String(y));
      append(ln1, true);
      const ln2 = document.createElementNS(ns, 'line');
      ln2.setAttribute('x1', String(x));
      ln2.setAttribute('y1', String(y-r));
      ln2.setAttribute('x2', String(x));
      ln2.setAttribute('y2', String(y+r));
      append(ln2, true);
    }else if (marker === 'cross'){
      const r = s * 1.25;
      const ln1 = document.createElementNS(ns, 'line');
      ln1.setAttribute('x1', String(x-r));
      ln1.setAttribute('y1', String(y-r));
      ln1.setAttribute('x2', String(x+r));
      ln1.setAttribute('y2', String(y+r));
      append(ln1, true);
      const ln2 = document.createElementNS(ns, 'line');
      ln2.setAttribute('x1', String(x-r));
      ln2.setAttribute('y1', String(y+r));
      ln2.setAttribute('x2', String(x+r));
      ln2.setAttribute('y2', String(y-r));
      append(ln2, true);
    }else{
      const c = document.createElementNS(ns, 'circle');
      c.setAttribute('cx', String(x));
      c.setAttribute('cy', String(y));
      c.setAttribute('r', String(s));
      append(c, false);
    }

    applyTip();
    applyClip(g);
    svg.appendChild(g);
    return g;
  }

  function qgLegendMarkerSvgMarkup(shape, color, opacity){
    const c = escapeHtml(String(color || '#444444'));
    const op = String(qgClamp01(opacity));
    const sh = String(shape || 'circle').toLowerCase();
    if (sh === 'square'){
      return `<svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg"><rect x="4.2" y="3.2" width="7.6" height="7.6" fill="${c}" fill-opacity="${op}" stroke="${c}" stroke-opacity="${op}" stroke-width="1"/></svg>`;
    }
    if (sh === 'diamond'){
      return `<svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg"><polygon points="8,2.4 12.6,7 8,11.6 3.4,7" fill="${c}" fill-opacity="${op}" stroke="${c}" stroke-opacity="${op}" stroke-width="1"/></svg>`;
    }
    if (sh === 'triangle'){
      return `<svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg"><polygon points="8,2.1 12.8,11 3.2,11" fill="${c}" fill-opacity="${op}" stroke="${c}" stroke-opacity="${op}" stroke-width="1" stroke-linejoin="round"/></svg>`;
    }
    if (sh === 'plus'){
      return `<svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg"><line x1="3" y1="7" x2="13" y2="7" stroke="${c}" stroke-opacity="${op}" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="2" x2="8" y2="12" stroke="${c}" stroke-opacity="${op}" stroke-width="2" stroke-linecap="round"/></svg>`;
    }
    if (sh === 'cross'){
      return `<svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg"><line x1="4" y1="3" x2="12" y2="11" stroke="${c}" stroke-opacity="${op}" stroke-width="2" stroke-linecap="round"/><line x1="4" y1="11" x2="12" y2="3" stroke="${c}" stroke-opacity="${op}" stroke-width="2" stroke-linecap="round"/></svg>`;
    }
    return `<svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="7" r="3.2" fill="${c}" fill-opacity="${op}" stroke="${c}" stroke-opacity="${op}" stroke-width="1"/></svg>`;
  }

  // Display alias for a series (legend/user-define/graph row label only).
  // Does NOT change the left "대상 FAI" list or data keys.
  function qgGetDisplayLabel(colKey){
    const k = String(colKey || '');
    if (!k) return '';
    try{
      const m = QG.displayNameByColKey || null;
      const a = (m && m[k] !== undefined && m[k] !== null) ? String(m[k]).trim() : '';
      if (a) return a;
    }catch(e){}
    const col = (QG.cols || []).find(c => String(c.key) === k) || null;
    return col ? String(col.label || k) : k;
  }

  // Plot element toggles (JMP-like toolbar: click=single, Shift=multi)
  function qgEnsurePlotElems(){
    // Default like JMP user's expectation here: raw data points + mean line.
    // Min/max box remains available, but starts OFF unless explicitly enabled.
    if (!QG.plotElems) QG.plotElems = { points:true, line:true, box:false };
    const st = QG.plotElems;
    if (st.points === undefined) st.points = true;
    if (st.line === undefined) st.line = true;
    if (st.box === undefined) st.box = false;
    if (!st.points && !st.line && !st.box) st.points = true;
    return st;
  }


// Plot elements can be overridden per FAI (colKey) when Ctrl/⌘ is held on the toolbar.
// Without Ctrl/⌘ the toolbar applies to all (global), matching JMP's default behavior.
function qgEnsurePlotElemOverrides(){
  if (!QG.plotElemByCol || typeof QG.plotElemByCol !== 'object') QG.plotElemByCol = {};
  return QG.plotElemByCol;
}
function qgGetPlotElemsForColKey(colKey){
  const base = qgEnsurePlotElems();
  const out = { points: !!base.points, line: !!base.line, box: !!base.box };
  try{
    const map = QG.plotElemByCol;
    if (map && colKey && map[colKey]){
      const o = map[colKey];
      if (o.points !== undefined) out.points = !!o.points;
      if (o.line   !== undefined) out.line   = !!o.line;
      if (o.box    !== undefined) out.box    = !!o.box;
    }
  }catch(e){}
  if (!out.points && !out.line && !out.box) out.points = true;
  return out;
}
function qgSetPlotElemForColKey(colKey, elemKey, val){
  if (!colKey) return;
  const map = qgEnsurePlotElemOverrides();
  if (!map[colKey]) map[colKey] = {};
  map[colKey][elemKey] = !!val;
  // Ensure at least one element is ON for this FAI
  try{
    const st = qgGetPlotElemsForColKey(colKey);
    if (!st.points && !st.line && !st.box){
      map[colKey].points = true;
    }
  }catch(e){}
}
function qgClearPlotElemOverrides(){
  QG.plotElemByCol = {};
}



  // Caption box (JMP-like) per FAI (display only; does not affect keys/filters/DB)
  const QG_CAPTION_STATS = [
    {v:'none', t:'없음'},
    {v:'n', t:'N'},
    {v:'missing', t:'결측값 수'},

    {v:'mean', t:'평균'},
    {v:'stddev', t:'표준편차'},
    {v:'stderr', t:'표준 오차'},
    {v:'mean_ci_low', t:'평균의 신뢰 하한'},
    {v:'mean_ci_high', t:'평균의 신뢰 상한'},

    {v:'median', t:'중앙값'},
    {v:'mode', t:'최빈값'},
    {v:'geomean', t:'기하평균'},

    {v:'min', t:'최소값'},
    {v:'max', t:'최대값'},
    {v:'range', t:'범위'},

    {v:'sum', t:'합'},
    {v:'cumsum', t:'누적합'},
    {v:'cumPct', t:'누적 백분율'},

    {v:'pctTotal', t:'% 총계'},
    {v:'pctFactor', t:'% 요인'},
    {v:'pctGrand', t:'% 총 합계'},

    {v:'variance', t:'분산'},
    {v:'skewness', t:'왜도'},
    {v:'kurtosis', t:'첨도'},
    {v:'cv', t:'CV'},

    {v:'zeroCount', t:'0 개수'},
    {v:'uniqCount', t:'고유 값 수'},
    {v:'uncorrSS', t:'비수정 SS'},
    {v:'corrSS', t:'수정 SS'},
    {v:'autocorr', t:'자기상관'},

    {v:'iqr', t:'사분위수 범위'},
    {v:'mad', t:'중앙 절대 편차'},
    {v:'q1', t:'1사분위수'},
    {v:'q3', t:'3사분위수'},
    {v:'five', t:'5가지 숫자 요약'},

    {v:'pctZero', t:'0인 비율'},
    {v:'pctNonZero', t:'0이 아닌 비율'},
    {v:'sd3', t:'3*표준편차'},
    {v:'mean_plus_3sd', t:'3*표준편차와 평균의 합'},
    {v:'mean_minus_3sd', t:'3*표준편차와 평균의 차'},
  ];
  const QG_CAPTION_XPOS = [
    {v:'left', t:'왼쪽'},
    {v:'center', t:'가운데'},
    {v:'right', t:'오른쪽'},
  ];
  const QG_CAPTION_YPOS = [
    {v:'top', t:'위쪽'},
    {v:'middle', t:'가운데'},
    {v:'bottom', t:'아래쪽'},
  ];
  // Factor mode adds in-box placement options for value labels (JMP-like)
  const QG_CAPTION_YPOS_FACTOR = [
    {v:'top', t:'위쪽'},
    {v:'middle', t:'가운데'},
    {v:'bottom', t:'아래쪽'},
    // NOTE: "box_*" keys kept for backward compatibility (stored UI state)
    // but the label/behavior matches JMP's "Data Box" positioning.
    {v:'box_top', t:'데이터박스 위'},
    {v:'box_bottom', t:'데이터박스 아래'},
  ];

  const QG_CAPTION_POSMODE = [
    {v:'graph', t:'그래프'},
    {v:'factor', t:'요인별 그래프'},
    {v:'axis_table', t:'축 테이블'},
    {v:'axis_refline', t:'축 참조선'},
  ];

  // Number format for caption statistics (display only)
  const QG_CAPTION_NUMFMT = [
    {v:'auto', t:'자동'},
    {v:'0', t:'0'},
    {v:'1', t:'0.0'},
    {v:'2', t:'0.00'},
    {v:'3', t:'0.000'},
    {v:'4', t:'0.0000'},
    {v:'6', t:'0.000000'},
    {v:'sci', t:'지수'},
  ];

  function qgCaptionLabel(kind){
    const it = QG_CAPTION_STATS.find(o => o.v === kind);
    return it ? it.t : String(kind || '');
  }

  // Resolve a stable primary FAI key even when the user hasn't clicked the plot yet.
  // (JMP-like: toolbar actions should apply to the currently selected/primary FAI immediately.)
  function qgResolvePrimaryColKey(){
    try{
      if (!QG || !QG.sel) return '';
      const cols = QG.cols || [];
      const colSet = new Set(cols.map(c => c.key));

      let k = String(QG.sel.primaryColKey || '');
      if (k && (!colSet.size || colSet.has(k))) return k;

      // Prefer the first selected key in the current column order.
      let picked = '';
      if (QG.sel.colKeys && QG.sel.colKeys.size){
        for (const c of cols){
          if (QG.sel.colKeys.has(c.key)){ picked = c.key; break; }
        }
        if (!picked){
          picked = Array.from(QG.sel.colKeys)[0] || '';
        }
      }
      if (!picked && cols.length) picked = cols[0].key;

      if (picked){
        QG.sel.primaryColKey = picked;
        if (!QG.sel.colKeys || !(QG.sel.colKeys instanceof Set)) QG.sel.colKeys = new Set();
        if (!QG.sel.colKeys.has(picked)) QG.sel.colKeys.add(picked);

        // If the caption icon was toggled before a primary key was available,
        // apply the pending global state to the first resolvable primary key.
        try{
          if (QG.captionGlobalEnabled){
            const cap = qgEnsureCaptionState(picked);
            cap.enabled = true;
            if (!cap.stat) cap.stat = 'mean';
            if (!cap.xPos) cap.xPos = 'right';
            if (!cap.yPos) cap.yPos = 'top';
            QG.captionGlobalEnabled = false;
          }
        }catch(e){}
      }
      return String(picked || '');
    }catch(e){
      return '';
    }
  }

  
function qgNormalizeCaptionStatsList(list){
    try{
      let a = (list || []).map(v => {
        const t = String(v === undefined || v === null ? '' : v).trim();
        return t ? t : 'none';
      });
      while (a.length && a[a.length - 1] === 'none') a.pop();
      a = a.filter(v => v !== 'none');
      if (!a.length) return ['none'];
      // JMP: 최대 5개
      if (a.length >= 5) return a.slice(0, 5);
      a.push('none');
      return a;
    }catch(e){
      return ['mean','none'];
    }
  }

function qgEnsureCaptionState(colKey){
    const k = String(colKey || '');
    if (!QG.captionByColKey) QG.captionByColKey = {};
	  // showValues: factor graph에서 (요약통계량 기반) 값 표시 여부
	  if (!k) return { enabled:false, stats:['mean','none'], posMode:'graph', xPos:'right', yPos:'top', numFmt:'auto', showValues:true };

    let s = QG.captionByColKey[k];
	  if (!s){
	    s = { enabled:false, stats:['mean','none'], posMode:'graph', xPos:'right', yPos:'top', numFmt:'auto', showValues:true };
      QG.captionByColKey[k] = s;
    }

    if (s.enabled === undefined) s.enabled = false;

    // Backward-compat: migrate legacy single-stat field
    if (!Array.isArray(s.stats)){
      const legacy = (s.stat !== undefined && s.stat !== null) ? String(s.stat||'').trim() : '';
      s.stats = [ (legacy || 'mean'), 'none' ];
    }

    // Normalize stats list: keep exactly one trailing 'none' (JMP: up to 5)
    s.stats = qgNormalizeCaptionStatsList(s.stats);

    if (!s.xPos) s.xPos = 'right';
    if (!s.yPos) s.yPos = 'top';
    if (!s.posMode) s.posMode = 'graph';
    if (!s.numFmt) s.numFmt = 'auto';
	  if (s.showValues === undefined) s.showValues = true;

    // Normalize to known enums (keep backward compatible defaults)
    try{
      const pm = String(s.posMode||'').trim();
      if (!pm || !QG_CAPTION_POSMODE.some(o=>o.v===pm)) s.posMode = 'graph';
      const nf = String(s.numFmt||'').trim();
      if (!nf || !QG_CAPTION_NUMFMT.some(o=>o.v===nf)) s.numFmt = 'auto';
    }catch(e){}
    return s;
  }


  function qgSortedNums(values){
    const a = (values || []).map(Number).filter(v => isFinite(v));
    a.sort((a,b)=>a-b);
    return a;
  }
  function qgQuantileSorted(a, q){
    if (!a || !a.length) return null;
    const pos = (a.length - 1) * q;
    const lo = Math.floor(pos), hi = Math.ceil(pos);
    if (lo === hi) return a[lo];
    const w = pos - lo;
    return a[lo] * (1 - w) + a[hi] * w;
  }
  function qgModeRounded(a){
    if (!a || !a.length) return null;
    const map = new Map();
    for (const v of a){
      const k = (Math.round(v * 1e6) / 1e6).toFixed(6);
      map.set(k, (map.get(k) || 0) + 1);
    }
    let bestK = null, bestN = -1;
    for (const [k,n] of map.entries()){
      if (n > bestN){ bestN = n; bestK = k; }
    }
    const x = bestK ? Number(bestK) : NaN;
    return isFinite(x) ? x : null;
  }

  function qgCaptionStatValue(values, kind, ctx){
    const ord = Array.isArray(values) ? values.filter(v => isFinite(v)).map(Number) : [];
    const a = qgSortedNums(ord);
    const n = a.length;
    const totalSlots = (ctx && isFinite(ctx.totalSlots)) ? Number(ctx.totalSlots) : NaN;

    if (!n){
      if (kind === 'missing' && isFinite(totalSlots)) return Math.max(0, Math.round(totalSlots));
      return null;
    }

    const sum = a.reduce((p,c)=>p+c,0);
    const mean = sum / n;

    // Precompute sums-of-squares around mean (corrected SS) and raw SS
    let corrSS = 0;
    let uncorrSS = 0;
    let m3 = 0;
    let m4 = 0;
    for (const x of a){
      const d = x - mean;
      const d2 = d*d;
      corrSS += d2;
      uncorrSS += x*x;
      m3 += d2*d;
      m4 += d2*d2;
    }

    const varS = (n > 1) ? (corrSS / (n - 1)) : 0;
    const sd = Math.sqrt(varS);
    const stderr = (n > 0) ? (sd / Math.sqrt(n)) : 0;

    if (kind === 'none') return null;
    if (kind === 'n') return n;
    if (kind === 'missing'){
      if (!isFinite(totalSlots)) return null;
      return Math.max(0, Math.round(totalSlots - n));
    }

    if (kind === 'mean') return mean;
    if (kind === 'median') return qgQuantileSorted(a, 0.5);
    if (kind === 'mode') return qgModeRounded(a);

    if (kind === 'geomean'){
      const hasNeg = a.some(v => v < 0);
      if (hasNeg) return null;
      const hasZero = a.some(v => v === 0);
      if (hasZero) return 0;
      const pos = a.filter(v => v > 0);
      if (!pos.length) return null;
      const lg = pos.reduce((p,c)=>p+Math.log(c),0) / pos.length;
      const gm = Math.exp(lg);
      return isFinite(gm) ? gm : null;
    }

    if (kind === 'min') return a[0];
    if (kind === 'max') return a[n-1];
    if (kind === 'range') return a[n-1] - a[0];

    if (kind === 'sum') return sum;
    if (kind === 'cumsum') return sum;

    if (kind === 'variance') return varS;
    if (kind === 'stddev') return sd;
    if (kind === 'stderr') return stderr;

    if (kind === 'cv'){
      if (!isFinite(mean) || Math.abs(mean) < 1e-12) return null;
      return (sd / Math.abs(mean)) * 100;
    }

    // 95% confidence limits for the mean (JMP-like)
    if (kind === 'mean_ci_low' || kind === 'mean_ci_high'){
      if (n < 2) return null;
      const df = n - 1;
      const tcrit = qgTcrit975(df);
      if (!isFinite(tcrit)) return null;
      const delta = tcrit * stderr;
      return (kind === 'mean_ci_low') ? (mean - delta) : (mean + delta);
    }

    if (kind === 'q1') return qgQuantileSorted(a, 0.25);
    if (kind === 'q3') return qgQuantileSorted(a, 0.75);
    if (kind === 'iqr'){
      const q1 = qgQuantileSorted(a, 0.25);
      const q3 = qgQuantileSorted(a, 0.75);
      if (!isFinite(q1) || !isFinite(q3)) return null;
      return q3 - q1;
    }
    if (kind === 'mad'){
      const med = qgQuantileSorted(a, 0.5);
      if (!isFinite(med)) return null;
      const dev = a.map(v => Math.abs(v - med));
      const d2 = qgSortedNums(dev);
      return qgQuantileSorted(d2, 0.5);
    }
    if (kind === 'five'){
      const q1 = qgQuantileSorted(a, 0.25);
      const med = qgQuantileSorted(a, 0.5);
      const q3 = qgQuantileSorted(a, 0.75);
      return { five: [a[0], q1, med, q3, a[n-1]] };
    }

    // Skewness / Kurtosis (adjusted Fisher-Pearson)
    if (kind === 'skewness'){
      if (n < 3) return null;
      const m2 = corrSS / n;
      if (!isFinite(m2) || m2 <= 0) return null;
      const g1 = (m3 / n) / Math.pow(m2, 1.5);
      const G1 = Math.sqrt(n*(n-1)) / (n-2) * g1;
      return isFinite(G1) ? G1 : null;
    }
    if (kind === 'kurtosis'){
      if (n < 4) return null;
      const m2 = corrSS / n;
      if (!isFinite(m2) || m2 <= 0) return null;
      const g2 = (m4 / n) / (m2*m2) - 3;
      const G2 = ((n-1)/((n-2)*(n-3))) * ((n+1)*g2 + 6);
      return isFinite(G2) ? G2 : null;
    }

    if (kind === 'zeroCount') return ord.reduce((p,c)=>p + (c === 0 ? 1 : 0), 0);
    if (kind === 'uniqCount'){
      const s = new Set(ord.map(v => (Math.round(v*1e12)/1e12).toString()));
      return s.size;
    }
    if (kind === 'uncorrSS') return uncorrSS;
    if (kind === 'corrSS') return corrSS;

    // First-order autocorrelation (lag-1)
    if (kind === 'autocorr'){
      if (ord.length < 2) return null;
      const mu = ord.reduce((p,c)=>p+c,0) / ord.length;
      let num = 0;
      let den = 0;
      for (let i=0; i<ord.length; i++){
        const d = ord[i] - mu;
        den += d*d;
        if (i > 0){
          num += (ord[i] - mu) * (ord[i-1] - mu);
        }
      }
      if (!isFinite(den) || den === 0) return null;
      const r1 = num / den;
      return isFinite(r1) ? r1 : null;
    }

    // 0 ratios
    if (kind === 'pctZero'){
      const z = ord.reduce((p,c)=>p + (c === 0 ? 1 : 0), 0);
      return (z / ord.length) * 100;
    }
    if (kind === 'pctNonZero'){
      const z = ord.reduce((p,c)=>p + (c === 0 ? 1 : 0), 0);
      return ((ord.length - z) / ord.length) * 100;
    }

    if (kind === 'sd3') return sd * 3;
    if (kind === 'mean_plus_3sd') return mean + sd * 3;
    if (kind === 'mean_minus_3sd') return mean - sd * 3;

    // Percent-type stats (% total / % factor / % grand / cumulative %) (context-based)
    if (kind === 'pctTotal' || kind === 'pctGrand' || kind === 'cumPct' || kind === 'pctFactor'){
      const overall = ctx && isFinite(ctx.overallSum) ? Number(ctx.overallSum) : NaN;
      const toolSum = (ctx && ctx.toolSum && ctx.tool && isFinite(ctx.toolSum[ctx.tool])) ? Number(ctx.toolSum[ctx.tool]) : NaN;
      const denom = (kind === 'pctFactor' && isFinite(toolSum) && toolSum !== 0) ? toolSum : overall;
      if (!isFinite(denom) || denom === 0) return null;
      return (sum / denom) * 100;
    }

    return mean;
  }

  // 0.975 two-sided t critical values (fallback to ~1.96 for large df)
  function qgTcrit975(df){
    const d = Math.max(1, Math.floor(Number(df)||1));
    // Table for df=1..30 (rounded 3dp)
    const T = [
      12.706, 4.303, 3.182, 2.776, 2.571, 2.447, 2.365, 2.306, 2.262, 2.228,
      2.201, 2.179, 2.160, 2.145, 2.131, 2.120, 2.110, 2.101, 2.093, 2.086,
      2.080, 2.074, 2.069, 2.064, 2.060, 2.056, 2.052, 2.048, 2.045, 2.042
    ];
    if (d <= 30) return T[d-1];
    if (d <= 40) return 2.021;
    if (d <= 60) return 2.000;
    if (d <= 120) return 1.980;
    return 1.960;
  }


  function qgCaptionText(kind, val, numFmt){
    const lab = qgCaptionLabel(kind);
    if (val === null || val === undefined) return '';
    if (kind === 'none') return '';
    if (kind === 'n' || kind === 'missing' || kind === 'zeroCount' || kind === 'uniqCount') return lab + ': ' + String(Math.round(Number(val)));
    if (kind === 'five' && typeof val === 'object' && val && Array.isArray(val.five)){
      const s = val.five.map(x => qgFmtCaptionNum(x, numFmt)).join(', ');
      return lab + ': ' + s;
    }
    if (kind === 'cv' || kind === 'pctTotal' || kind === 'pctGrand' || kind === 'pctFactor' || kind === 'cumPct'){
      return lab + ': ' + qgFmtCaptionNum(val, numFmt) + '%';
    }
    return lab + ': ' + qgFmtCaptionNum(val, numFmt);
  }


  // Set CSS vars used by the top header band so it aligns with the SVG plot area.
  function qgSetPadCssVars(padL, padR, W){
    try{
      const ov = document.getElementById('qgOverlay');
      if (!ov) return;
      const w = (W && isFinite(W)) ? Number(W) : 1200;
      const l = (padL && isFinite(padL)) ? Number(padL) : 56;
      const r = (padR && isFinite(padR)) ? Number(padR) : 18;
      ov.style.setProperty('--qg-padL-pct', (l / w * 100).toFixed(3) + '%');
      ov.style.setProperty('--qg-padR-pct', (r / w * 100).toFixed(3) + '%');
      ov.style.setProperty('--qg-padL-px', l.toFixed(2) + 'px');
      ov.style.setProperty('--qg-padR-px', r.toFixed(2) + 'px');
    }catch(e){}
  }

  // Ensure background elements are always behind all plot content.
  function qgEnsureBgBehind(svg, bg, plot, band){
    try{
      if (!svg) return;
      if (band) svg.insertBefore(band, svg.firstChild);
      if (plot) svg.insertBefore(plot, svg.firstChild);
      if (bg) svg.insertBefore(bg, svg.firstChild);
    }catch(e){}
  }


function fmt(v){
    if (v === null || v === undefined) return '';
    const x = Number(v);
    if (!isFinite(x)) return '';
    // trim noisy floats (general display)
    const s = x.toFixed(6);
    return s.replace(/0+$/,'').replace(/\.$/,'');
  }

  // Caption number formatting (display only)
  function qgFmtCaptionNum(v, numFmt){
    if (v === null || v === undefined) return '';
    const x = Number(v);
    if (!isFinite(x)) return '';
    const k = String(numFmt || 'auto');
    if (!k || k === 'auto') return fmt(x);
    if (k === 'sci'){
      try{ return x.toExponential(3); }catch(e){ return fmt(x); }
    }
    const d = parseInt(k, 10);
    if (isFinite(d) && d >= 0 && d <= 12){
      try{ return x.toFixed(d); }catch(e){ return fmt(x); }
    }
    return fmt(x);
  }

  // Axis/limit label formatting: 0.001 (천분의 일)
  function fmtTick(v){
    if (v === null || v === undefined) return '';
    let x = Number(v);
    if (!isFinite(x)) return '';
    if (Math.abs(x) < 0.0005) x = 0;
    return x.toFixed(3);
  }

  function qgFmtPointValue(v){
    if (v === null || v === undefined) return '';
    let x = Number(v);
    if (!isFinite(x)) return '';
    if (Math.abs(x) < 0.0000005) x = 0;
    try{
      let s = x.toFixed(4);
      s = s.replace(/(?:\.0+|(?:(\.\d*?[1-9]))0+)$/, '$1');
      return s;
    }catch(e){
      return String(x);
    }
  }

function normalizeDateKey(raw){
  let s = (raw === null || raw === undefined) ? '' : String(raw);
  s = s.trim();
  if (!s) return { key:'', label:'', ts: NaN };

  // If a timestamp is included, prefer the leading date part.
  const head = s.split(/\s+/)[0];

  // YYYY-MM-DD / YYYY/MM/DD / YYYY.MM.DD
  let m = /^(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})/.exec(head);
  if (m){
    const y = parseInt(m[1],10);
    const mo = parseInt(m[2],10);
    const d = parseInt(m[3],10);
    const key = String(y).padStart(4,'0') + '-' + String(mo).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    const ts = Date.UTC(y, mo-1, d);
    return { key, label:key, ts };
  }

  // YYYYMMDD / YYMMDD (digits only)
  const digits = head.replace(/\D/g,'');
  if (/^(\d{8})$/.test(digits)){
    const y = parseInt(digits.slice(0,4),10);
    const mo = parseInt(digits.slice(4,6),10);
    const d = parseInt(digits.slice(6,8),10);
    const key = String(y).padStart(4,'0') + '-' + String(mo).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    const ts = Date.UTC(y, mo-1, d);
    return { key, label:key, ts };
  }
  if (/^(\d{6})$/.test(digits)){
    const yy = parseInt(digits.slice(0,2),10);
    const y = (yy >= 70 ? 1900+yy : 2000+yy);
    const mo = parseInt(digits.slice(2,4),10);
    const d = parseInt(digits.slice(4,6),10);
    const key = String(y).padStart(4,'0') + '-' + String(mo).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    const ts = Date.UTC(y, mo-1, d);
    return { key, label:key, ts };
  }

  const t = Date.parse(s);
  return { key:s, label:s, ts: (isFinite(t) ? t : NaN) };
}


function qgPickDatePoint(s, d){
  if (!s) return null;

  const dk = (d && d.key !== undefined && d.key !== null) ? d.key : d;
  if (dk !== undefined && dk !== null){
    const k = String(dk);
    if (s[k]) return s[k];
  }

  const alts = (d && (d._alts || d.alts)) ? (d._alts || d.alts) : null;
  if (alts && Array.isArray(alts)){
    for (const ak of alts){
      if (ak === undefined || ak === null) continue;
      const k2 = String(ak);
      if (s[k2]) return s[k2];
    }
  }

  // Fallback: try non-zero-padded YYYY-M-D if the normalized key used YYYY-MM-DD.
  try{
    if (dk !== undefined && dk !== null){
      const k = String(dk);
      const m = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(k);
      if (m){
        const k3 = String(parseInt(m[1],10)) + '-' + String(parseInt(m[2],10)) + '-' + String(parseInt(m[3],10));
        if (s[k3]) return s[k3];
      }
    }
  }catch(e){}

  return null;
}


// Normalize variable-line label prefix (default: OOC/OOS; migrate legacy 'V')
function qgNormVarPrefix(x){
  const s = String((x===undefined||x===null) ? '' : x).trim();
  return (!s || s === 'V') ? 'OOC/OOS' : s;
}

function sortDateInfo(a,b){
  const ta = a && a.ts;
  const tb = b && b.ts;
  const aOk = isFinite(ta);
  const bOk = isFinite(tb);
  if (aOk && bOk && ta !== tb) return ta - tb;
  if (aOk && !bOk) return -1;
  if (!aOk && bOk) return 1;
  const ak = a && a.key ? a.key : '';
  const bk = b && b.key ? b.key : '';
  return String(ak).localeCompare(String(bk), undefined, {numeric:true, sensitivity:'base'});
}


  function normCavity(v){
    let s = (v === null || v === undefined) ? '' : String(v);
    s = s.trim();
    if (s === '') return '';
    // Normalize 1 / 2 -> 1CAV / 2CAV for display/keys
    if (/^\d+$/.test(s)) return s + 'CAV';
    s = s.replace(/\s+/g,'').toUpperCase();
    if (/^\d+CAV$/.test(s)) return s;
    return s;
  }

  function sortCavity(a,b){
    const ma = /(\d+)/.exec(String(a));
    const mb = /(\d+)/.exec(String(b));
    const na = ma ? parseInt(ma[1],10) : 9999;
    const nb = mb ? parseInt(mb[1],10) : 9999;
    if (na !== nb) return na - nb;
    return String(a).localeCompare(String(b), undefined, {numeric:true, sensitivity:'base'});
  }

  function showMsg(txt, holdMs){
    const box = qs('#qgMsg');
    if (!box) return;
    if (!txt){ box.style.display='none'; box.textContent=''; return; }
    box.style.display='block';
    box.textContent = String(txt);
    if (holdMs){
      setTimeout(()=>{ if (box.textContent === txt) showMsg(''); }, holdMs);
    }
  }

  // Resolve the *actual* plot SVG under the pointer (robust against overlay layers).
  // NOTE: This must be top-level because it is used by capture-phase contextmenu traps.
  function qgSvgFromPoint(x, y){
    try{
      if (!document.elementsFromPoint) return null;
      const grid = qs('#qgGrid');
      const els = document.elementsFromPoint(x, y) || [];
      for (const el of els){
        if (!el) continue;
        let s = null;
        const tg = (el.tagName ? String(el.tagName).toLowerCase() : '');
        if (tg === 'svg') s = el;
        else if (el.closest) s = el.closest('svg.qg-svg');
        if (!s) continue;
        try{ if (s.classList && !s.classList.contains('qg-svg')) continue; }catch(e){}
        if (grid && !grid.contains(s)) continue;
        const r = s.getBoundingClientRect();
        if (x < r.left || x > r.right || y < r.top || y > r.bottom) continue;
        return s;
      }

      // Fallback: if overlay layers block hit-testing, locate the SVG by geometry.
      if (grid){
        const svgs = qsa('svg.qg-svg', grid);
        for (const s of svgs){
          if (!s) continue;
          const r = s.getBoundingClientRect();
          if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return s;
        }
      }
    }catch(e){}
    return null;
  }




  // --- Custom right-click menu + in-app dialog (no browser prompt) ---
  function qgEnsureCtxDismiss(){
    if (QG._ctxDismissInstalled) return;
    QG._ctxDismissInstalled = true;

    function overlayOpen(){
      const ov = qs('#qgOverlay');
      if (!ov) return false;
      const ah = ov.getAttribute('aria-hidden');
      if (ah === 'false') return true;
      if (ah === 'true') return false;
      const ds = (ov.style && ov.style.display) ? ov.style.display : '';
      return ds !== 'none';
    }
    function menuOpen(){
      const m = qs('#qgCtxMenu');
      return !!(m && m.getAttribute('aria-hidden') === 'false');
    }
    function menuContains(t){
      const m = qs('#qgCtxMenu');
      return !!(m && t && m.contains(t));
    }

    document.addEventListener('keydown', (ev)=>{
      try{
        if (!overlayOpen() || !menuOpen()) return;
        if (ev.key === 'Escape') qgHideCtxMenu();
      }catch(e){}
    }, true);

    document.addEventListener('pointerdown', (ev)=>{
      try{
        if (!overlayOpen() || !menuOpen()) return;
        if (menuContains(ev.target)) return;
        // Don't close on right-button pointerdown (used to open the menu)
        if (ev && ev.button === 2) return;
      }catch(e){}
      qgHideCtxMenu();
    }, true);

    document.addEventListener('click', (ev)=>{
      try{
        if (!overlayOpen() || !menuOpen()) return;
        if (menuContains(ev.target)) return;
        if (QG._ctxJustOpened && (Date.now()-QG._ctxJustOpened) < 180) return;
      }catch(e){}
      qgHideCtxMenu();
    }, true);

    document.addEventListener('wheel', (ev)=>{
      try{
        if (!overlayOpen() || !menuOpen()) return;
        if (menuContains(ev.target)) return;
      }catch(e){}
      qgHideCtxMenu();
    }, { capture:true, passive:true });

    document.addEventListener('scroll', ()=>{
      try{ if (overlayOpen() && menuOpen()) qgHideCtxMenu(); }catch(e){}
    }, true);
  }


  function qgHideCtxMenu(){
    const m = qs('#qgCtxMenu');
    if (!m) return;
    m.setAttribute('aria-hidden','true');
    const items = qs('#qgCtxItems');
    if (items) items.innerHTML = '';
  }

  function qgShowCtxMenu(x, y, items){
    const m = qs('#qgCtxMenu');
    const box = qs('#qgCtxItems');
    try{ qgEnsureCtxDismiss(); }catch(e){}
    if (!m || !box) return;
    box.innerHTML = '';
    (items||[]).forEach(it=>{
      if (it === 'sep'){
        const sep = document.createElement('div');
        sep.className = 'qg-ctxsep';
        box.appendChild(sep);
        return;
      }

      const d = document.createElement('div');
      d.className = 'qg-ctxitem' + (it.disabled ? ' is-disabled' : '');
      d.textContent = it.label || '';
      d.addEventListener('pointerdown', (ev)=>{
        try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
        if (d._qgActed) return;
        d._qgActed = true;
        setTimeout(()=>{ try{ d._qgActed = false; }catch(e){} }, 0);
        if (it.disabled) return;
        qgHideCtxMenu();
        try{ it.onClick && it.onClick(); }catch(e){}
      }, { passive:false });
      d.addEventListener('click', (ev)=>{
        try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
        if (d._qgActed) return;
        d._qgActed = true;
        setTimeout(()=>{ try{ d._qgActed = false; }catch(e){} }, 0);
        if (it.disabled) return;
        qgHideCtxMenu();
        try{ it.onClick && it.onClick(); }catch(e){}
      });
      box.appendChild(d);
    });

    // Position
    m.style.left = '0px';
    m.style.top  = '0px';
    m.setAttribute('aria-hidden','false');

    const r = m.getBoundingClientRect();
    const vw = Math.max(1, window.innerWidth);
    const vh = Math.max(1, window.innerHeight);
    let lx = x, ly = y;
    if (lx + r.width + 8 > vw) lx = Math.max(8, vw - r.width - 8);
    if (ly + r.height + 8 > vh) ly = Math.max(8, vh - r.height - 8);
    m.style.left = lx + 'px';
    m.style.top  = ly + 'px';
    try{ QG._ctxJustOpened = Date.now(); }catch(e){}
  }

  // --- Global right-click trap (capture) ---
  // Some shells block or swallow 'contextmenu' before it reaches SVG.
  // We install a window-level capture handler that forwards right-clicks to the nearest plot SVG handler.
  function qgInstallCtxTrap(){
  if (QG._ctxTrapInstalled) return;
  QG._ctxTrapInstalled = true;

  function overlayOpen(){
    const ov = qs('#qgOverlay');
    // be tolerant: aria-hidden may be missing; fall back to display
    if (!ov) return false;
    const ah = ov.getAttribute('aria-hidden');
    if (ah === 'false') return true;
    if (ah === 'true') return false;
    // fallback
    const ds = (ov.style && ov.style.display) ? ov.style.display : '';
    return ds !== 'none';
  }
  function inOverlay(t){
    const ov = qs('#qgOverlay');
    return !!(ov && t && ov.contains(t));
  }

  // graph area only (not the left settings panel)
  function inGraphArea(t){
    if (!t || !t.closest) return false;
    if (t.closest('#qgSide')) return false;
    // Prefer the grid container; it only wraps charts
    if (t.closest('#qgGrid')) return true;
    // fallback: any of our SVG plots
    if (t.closest('svg.qg-svg')) return true;
    return false;
  }

  function findSvg(t){
    let n = t;
    while (n){
      if (n._qgCtxHandler) return n;
      if (n.ownerSVGElement && n.ownerSVGElement._qgCtxHandler) return n.ownerSVGElement;
      n = n.parentNode;
    }
    return null;
  }

  // Some overlays/layers may sit above the SVG. In that case, walk to the nearest chart row
  // and pick the SVG inside that row.
  function findPlotSvg(t){
    let svg = findSvg(t);
    if (svg && typeof svg._qgCtxHandler === 'function') return svg;

    try{
      const row = t && t.closest ? t.closest('.qg-fai-row') : null;
      if (row){
        const cand = row.querySelector('svg.qg-svg');
        if (cand && typeof cand._qgCtxHandler === 'function') return cand;
      }
    }catch(e){}

    try{
      const one = t && t.closest ? t.closest('.qg-fai-one') : null;
      if (one){
        const cand = one.querySelector('svg.qg-svg');
        if (cand && typeof cand._qgCtxHandler === 'function') return cand;
      }
    }catch(e){}

    return null;
  }

  function isRightClick(ev){
    const isCtx = (ev.type === 'contextmenu');
    const btn = (ev.button !== undefined) ? ev.button : null;
    const which = (ev.which !== undefined) ? ev.which : null;
    return isCtx || btn === 2 || which === 3;
  }

    // Fallback if we can't map the target to a specific SVG: open menu for primary FAI
  function openFallbackMenu(ev, forcedColKey){
    try{
      const colKey = (forcedColKey || (QG && QG.sel ? (QG.sel.primaryColKey || '') : '')).toString();
      if (!colKey) return;

      const col = (QG.cols || []).find(c => c.key === colKey) || null;
      const lbl = col ? (col.label || colKey) : colKey;

      const items = [];
      const arr = (QG.varLines && QG.varLines[colKey]) ? QG.varLines[colKey] : [];

      items.push({ label:'기준선 추가...', onClick: ()=>{ try{
        try{ setPrimaryColKey(colKey); try{ renderFaiList(); }catch(e2){} }catch(e2){}
        qgOpenVarDlg({
          title:'기준선 추가' + (lbl ? (' [' + lbl + ']') : ''),
          value:'',
          placeholder:'값 입력',
          showDelete:false,
          onOk:(v)=>{
            const vv = num(v);
            if (vv === null){ try{ showMsg('숫자를 입력하세요', 1200); }catch(e){} return; }
            if (!QG.varLines) QG.varLines = {};
            if (!Array.isArray(QG.varLines[colKey])) QG.varLines[colKey] = [];
            QG.varLines[colKey].push({ id:'vl'+(QG.varLineSeq++), value:vv });
            try{ QG._udVarSel = { colKey: colKey, idx: QG.varLines[colKey].length - 1 }; }catch(e){}
            renderLegend(); renderGrid();
          }
        });
      }catch(e){} } });

      if (arr && arr.length){
        items.push({ label:'기준선 삭제', onClick: ()=>{ try{ setPrimaryColKey(colKey); try{ renderFaiList(); }catch(e2){} QG.varLines[colKey] = []; renderLegend(); renderGrid(); }catch(e){} } });
      }

      items.push('sep');
      items.push({ label:'사용자 정의...', onClick: ()=>{ try{ setPrimaryColKey(colKey); try{ renderFaiList(); }catch(e2){} qgOpenUserDefineDlg(); }catch(e){} } });

      qgShowCtxMenu(ev.clientX, ev.clientY, items);
    }catch(e){}
  }

  function trap(ev){
    try{
      if (!overlayOpen()) return;
      if (!inOverlay(ev.target)) return;
      if (!isRightClick(ev)) return;

      // allow native menu for inputs/selects/textareas in the overlay
      const tag = (ev.target && ev.target.tagName) ? String(ev.target.tagName).toLowerCase() : '';
      if (tag === 'input' || tag === 'textarea' || tag === 'select' || tag === 'option') return;

      // Only hijack inside the graph area
      if (!inGraphArea(ev.target)) return;

      // ALWAYS block the browser context menu inside charts
      try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}

	      let svg = findPlotSvg(ev.target);
	      if (!svg || typeof svg._qgCtxHandler !== 'function'){
	        svg = qgSvgFromPoint(ev.clientX, ev.clientY);
	      }
      if (svg && typeof svg._qgCtxHandler === 'function'){
        try{ svg._qgSkipNextCtx = true; }catch(e){}
        try{ svg._qgCtxHandler(ev); }catch(e){}
        return;
      }

      // If we couldn't bind to a specific SVG handler, still bind the menu to the right-clicked FAI row.
      let hitKey = '';
      try{
        if (svg){
          hitKey = (svg.dataset && svg.dataset.colKey) ? String(svg.dataset.colKey) : '';
          if (!hitKey) hitKey = String(svg.getAttribute('data-col-key')||'');
        }
        if (!hitKey && document.elementsFromPoint){
          const els = document.elementsFromPoint(ev.clientX, ev.clientY) || [];
          for (const el of els){
            const row = (el && el.closest) ? (el.closest('.qg-fai-row') || el.closest('.qg-fai-one')) : null;
            const k = row && row.dataset ? row.dataset.colKey : '';
            if (k){ hitKey = String(k); break; }
          }
        }
      }catch(e){}
      if (hitKey){
        openFallbackMenu(ev, hitKey);
        return;
      }

      // last resort: open menu anyway (bound to current primary)
      openFallbackMenu(ev);

    }catch(e){}
  }

  window.addEventListener('contextmenu', trap, true);
  window.addEventListener('pointerdown', trap, true);
  window.addEventListener('mousedown', trap, true);
  window.addEventListener('auxclick', trap, true);
}



function qgBindHoverTip(){
  if (QG._hoverTipInstalled) return;
  QG._hoverTipInstalled = true;
  const ov = qs('#qgOverlay');
  const tip = qs('#qgHoverTip');
  if (!ov || !tip) return;

  function hide(){
    try{ tip.classList.remove('is-show'); tip.style.display='none'; tip.setAttribute('aria-hidden','true'); }catch(e){}
    QG._hoverLast = null;
  }
  function show(text, x, y){
    if (!text) { hide(); return; }
    try{
      tip.textContent = String(text);
      tip.style.display='block';
      tip.classList.add('is-show');
      tip.setAttribute('aria-hidden','false');
      // position within viewport
      const pad = 12;
      const r = tip.getBoundingClientRect();
      let nx = x + 14;
      let ny = y + 14;
      const vw = window.innerWidth || document.documentElement.clientWidth || 0;
      const vh = window.innerHeight || document.documentElement.clientHeight || 0;
      if (nx + r.width + pad > vw) nx = Math.max(pad, x - r.width - 14);
      if (ny + r.height + pad > vh) ny = Math.max(pad, y - r.height - 14);
      tip.style.left = nx + 'px';
      tip.style.top  = ny + 'px';
    }catch(e){}
  }

  ov.addEventListener('pointermove', (ev)=>{
    try{
      const t = ev.target && ev.target.closest ? ev.target.closest('[data-qg-tip]') : null;
      if (!t){
        hide();
        return;
      }
      const txt = t.getAttribute('data-qg-tip') || '';
      const key = txt + '|' + ev.clientX + '|' + ev.clientY;
      // keep responsive without excessive DOM thrash
      if (QG._hoverLast && QG._hoverLast.txt === txt){
        show(txt, ev.clientX, ev.clientY);
      }else{
        QG._hoverLast = { txt };
        show(txt, ev.clientX, ev.clientY);
      }
    }catch(e){
      hide();
    }
  }, { passive:true });

  ov.addEventListener('pointerleave', hide, { passive:true });
  ov.addEventListener('pointerdown', hide, { passive:true });
}

  function qgCloseVarDlg(){
    const bd = qs('#qgVarDlgBackdrop');
    if (!bd) return;
    bd.setAttribute('aria-hidden','true');
    const inp = qs('#qgVarDlgInput');
    if (inp) inp.value = '';
    bd._qg = null;
  }

  function qgOpenVarDlg(opts){
    const bd = qs('#qgVarDlgBackdrop');
    const ttl = qs('#qgVarDlgTitle');
    const inp = qs('#qgVarDlgInput');
    const ok  = qs('#qgVarDlgOk');
    const ca  = qs('#qgVarDlgCancel');
    const del = qs('#qgVarDlgDelete');
    if (!bd || !ttl || !inp || !ok || !ca || !del) return;

    try{ qgCloseUserDefineDlg(); }catch(e){}
    bd._qg = opts || {};
    ttl.textContent = (opts && opts.title) ? String(opts.title) : '기준선 설정';
    inp.value = (opts && opts.value !== undefined && opts.value !== null) ? String(opts.value) : '';
    inp.placeholder = (opts && opts.placeholder) ? String(opts.placeholder) : '';

    // Delete button
    if (opts && opts.showDelete){
      del.style.display = '';
    }else{
      del.style.display = 'none';
    }

    if (!ok._qg){
      ok._qg = true;
      ok.addEventListener('click', ()=>{
        const o = bd._qg || {};
        const v = String(inp.value||'').trim();
        try{ o.onOk && o.onOk(v); }catch(e){}
        qgCloseVarDlg();
      });
    }
    if (!ca._qg){
      ca._qg = true;
      ca.addEventListener('click', ()=>{ qgCloseVarDlg(); });
    }
    if (!del._qg){
      del._qg = true;
      del.addEventListener('click', ()=>{
        const o = bd._qg || {};
        try{ o.onDelete && o.onDelete(); }catch(e){}
        qgCloseVarDlg();
      });
    }
    if (!bd._qg_bind){
      bd._qg_bind = true;
      bd.addEventListener('click', (ev)=>{
        if (ev.target === bd) qgCloseVarDlg();
      });
      document.addEventListener('keydown', (ev)=>{
        if (ev.key === 'Escape'){
          qgHideCtxMenu();
          qgCloseVarDlg();
        }
      });
      document.addEventListener('pointerdown', (ev)=>{
        try{
          const m = qs('#qgCtxMenu');
          if (m && m.getAttribute('aria-hidden') === 'false' && m.contains(ev.target)) return;
          // Don't close on right-button pointerdown (used to open the menu)
          if (ev && ev.button === 2) return;
        }catch(e){}
        qgHideCtxMenu();
      }, true);
      document.addEventListener('click', (ev)=>{
        try{
          const m = qs('#qgCtxMenu');
          if (m && m.getAttribute('aria-hidden') === 'false' && m.contains(ev.target)) return;
          if (QG._ctxJustOpened && (Date.now()-QG._ctxJustOpened)<180) return;
        }catch(e){}
        qgHideCtxMenu();
      }, true);
      document.addEventListener('scroll', ()=>{ qgHideCtxMenu(); }, true);
      document.addEventListener('contextmenu', (ev)=>{
        // If our menu is open and you right-click elsewhere inside overlay, close it
        try{
          const ov = qs('#qgOverlay');
          if (ov && ov.getAttribute('aria-hidden') === 'false'){
            const m = qs('#qgCtxMenu');
            if (m && m.getAttribute('aria-hidden') === 'false'){
              // do nothing; per-plot handler will reopen if needed
            }
          }
        }catch(e){}
      }, true);
    }

    bd.setAttribute('aria-hidden','false');
    setTimeout(()=>{ try{ inp.focus(); inp.select(); }catch(e){} }, 10);
  }
  
  
  // --- Rename dialog (FAI label alias) ---
  function qgCloseRenameDlg(){
    const bd = qs('#qgRenameDlgBackdrop');
    if (!bd) return;
    bd.setAttribute('aria-hidden','true');
    try{ const o = qs('#qgRenameOrig'); if (o) o.value = ''; }catch(e){}
    try{ const d = qs('#qgRenameDisp'); if (d) d.value = ''; }catch(e){}
    bd._qg = null;
  }

  function qgApplyDisplayAlias(colKey, disp){
    const k = String(colKey || '');
    if (!k) return;
    const col0 = (QG.cols || []).find(c => String(c.key) === k) || null;
    const base = col0 ? String(col0.label || k) : k;
    const nv = String(disp || '').trim();

    try{ if (!QG.displayNameByColKey) QG.displayNameByColKey = {}; }catch(e){}
    try{
      if (!nv || nv === base) delete QG.displayNameByColKey[k];
      else QG.displayNameByColKey[k] = nv;
    }catch(e){}

    // Update the row label(s) in-place
    try{
      const rows = qsa('.qg-fai-row');
      for (const row of rows){
        if (String(row.dataset.colKey || '') !== k) continue;
        const vtxt = row.querySelector ? row.querySelector('.qg-row-label .vtxt') : null;
        if (vtxt) vtxt.textContent = qgGetDisplayLabel(k);
      }
    }catch(e){}

    try{ renderLegend && renderLegend(); }catch(e){}

    // Update User Define title (only when open and matching primary FAI)
    try{
      const bd = qs('#qgUDDlgBackdrop');
      const isOpen = bd && bd.getAttribute('aria-hidden') === 'false';
      if (isOpen){
        const t = qs('#qgUDTitleSub');
        const pk = String(QG.sel.primaryColKey || '');
        if (t && pk === k) t.textContent = qgGetDisplayLabel(pk);
      }
    }catch(e){}
  }

  function qgOpenRenameDlg(opts){
    const bd = qs('#qgRenameDlgBackdrop');
    const o  = qs('#qgRenameOrig');
    const d  = qs('#qgRenameDisp');
    const ok = qs('#qgRenameOk');
    const ca = qs('#qgRenameCancel');
    const rs = qs('#qgRenameReset');
    if (!bd || !o || !d || !ok || !ca || !rs) return;

    try{ qgHideCtxMenu && qgHideCtxMenu(); }catch(e){}
    try{ qgCloseVarDlg && qgCloseVarDlg(); }catch(e){}
    try{ qgCloseUserDefineDlg && qgCloseUserDefineDlg(); }catch(e){}

    bd._qg = opts || {};
    const base = (opts && opts.base !== undefined) ? String(opts.base) : '';
    const cur  = (opts && opts.cur  !== undefined) ? String(opts.cur)  : base;

    o.value = base;
    d.value = cur;

    if (!bd._qg_bind){
      bd._qg_bind = true;

      ok.addEventListener('click', ()=>{
        const x = bd._qg || {};
        qgApplyDisplayAlias(x.colKey, d.value);
        qgCloseRenameDlg();
      });

      ca.addEventListener('click', ()=>{ qgCloseRenameDlg(); });

      rs.addEventListener('click', ()=>{
        const x = bd._qg || {};
        qgApplyDisplayAlias(x.colKey, x.base);
        qgCloseRenameDlg();
      });

      bd.addEventListener('click', (ev)=>{
        if (ev.target === bd) qgCloseRenameDlg();
      });

      d.addEventListener('keydown', (ev)=>{
        if (ev.key === 'Enter'){
          try{ ev.preventDefault(); }catch(e){}
          ok.click();
        }else if (ev.key === 'Escape'){
          try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
          qgCloseRenameDlg();
        }
      });
    }

    bd.setAttribute('aria-hidden','false');
    setTimeout(()=>{ try{ d.focus(); d.select(); }catch(e){} }, 10);
  }

// --- User-defined style dialog (JMP-like "사용자 정의...") ---
  function qgCloseUserDefineDlg(){
    const bd = qs('#qgUDDlgBackdrop');
    if (!bd) return;
    bd.setAttribute('aria-hidden','true');
  }

  function qgEnsureUserStyle(){
    if (!QG.userStyle) QG.userStyle = {};
    if (!QG.userStyle.varline) QG.userStyle.varline = {};
    if (!QG.userStyle.box) QG.userStyle.box = {};
    const v = QG.userStyle.varline;
    if (!v.color) v.color = '#000000';
    if (v.width === undefined || v.width === null || !isFinite(Number(v.width))) v.width = 1.5;
    // IMPORTANT: allow empty string ('') to represent solid line.
    // Only default when dash is truly unset.
    if (v.dash === undefined || v.dash === null) v.dash = '10 3 2 3';
    if (v.showLabel === undefined) v.showLabel = true;
    if (!v.labelPrefix) v.labelPrefix = 'OOC/OOS';
    try{
      const b = QG.userStyle.box;
      const s = Number(b.widthScale);
      // Default (JMP-like): 20% thickness
      if (!isFinite(s) || s <= 0 || s > 1) b.widthScale = 0.2;
      const sw = Number(b.strokeWidth);
      if (!isFinite(sw) || sw <= 0) b.strokeWidth = 1;
      else b.strokeWidth = Math.max(0.5, Math.min(6, sw));
    }catch(e){}

    return QG.userStyle;
  }

  function qgVarDashFromStyleKey(k){
    const key = String(k||'').toLowerCase();
    if (key === 'solid') return '';
    if (key === 'dash') return '10 4';
    if (key === 'dot') return '2 4';
    if (key === 'dashdot') return '10 3 2 3';
    return '10 3 2 3';
  }

    function qgOpenUserDefineDlg(){
    const bd = qs('#qgUDDlgBackdrop');
    const nav = qs('#qgUDNav');
    const tabs = qsa('#qgUDDlgBackdrop .qg-ud-tab');
    const ok = qs('#qgUD_Ok');
    const apply = qs('#qgUD_Apply');
    const close = qs('#qgUD_Close');

    if (!bd || !nav || !tabs.length || !ok || !apply || !close) return;
    try{ qgCloseVarDlg(); }catch(e){}
    try{ qgHideCtxMenu && qgHideCtxMenu(); }catch(e){}

    const st = qgEnsureUserStyle();
    const v = st.varline;

    const vColor = qs('#qgUD_var_color');
    const vWidth = qs('#qgUD_var_width');
    const vStyle = qs('#qgUD_var_style');
    const vLbl   = qs('#qgUD_var_label');
    const vPref  = qs('#qgUD_var_prefix');

    // box style (icon is visual only)
    const bColor = qs('#qgUD_box_color');
    const bFill  = qs('#qgUD_box_fill');
    const bFillColor = qs('#qgUD_box_fill_color');
    const bIcon  = qs('#qgUD_box_icon');

    const bTh   = qs('#qgUD_box_thickness');
    const bThLb = qs('#qgUD_box_thickness_lbl');

    const bSW   = qs('#qgUD_box_stroke_width');

    // mean line (per-FAI) + mean dots (per-FAI)
    const mColor = qs('#qgUD_mean_color');
    const mWidth = qs('#qgUD_mean_width');
    const mStyle = qs('#qgUD_mean_style');
    const mIcon  = qs('#qgUD_mean_icon');
    const mdColor = qs('#qgUD_mean_dot_color');
    const mdIcon  = qs('#qgUD_mean_dot_icon');

    const mdSize = qs('#qgUD_mean_dot_size');
    const mdSizeLb = qs('#qgUD_mean_dot_size_lbl');
	    const mdHide = qs('#qgUD_mean_dot_hide');

    // data dots (Data 1/2/3)
    const ddIcon = qs('#qgUD_data_dot_icon');
    const ddColor = qs('#qgUD_data_dot_color');
    const ddSize = qs('#qgUD_data_dot_size');
    const ddSizeLb = qs('#qgUD_data_dot_size_lbl');
    const ddOp = qs('#qgUD_data_dot_opacity');
    const ddOpLb = qs('#qgUD_data_dot_opacity_lbl');
	    const ddHide = qs('#qgUD_data_dot_hide');

    // background (per-FAI)
    const bgColor = qs('#qgUD_bg_color');
    const bgIcon  = qs('#qgUD_bg_icon');
    const bgStart = qs('#qgUD_bg_start');
    const bgEnd   = qs('#qgUD_bg_end');

    // opacity controls (per active FAI)
    const bgOp   = qs('#qgUD_bg_opacity');
    const bgOpLb = qs('#qgUD_bg_opacity_lbl');
    const mOp    = qs('#qgUD_mean_opacity');
    const mOpLb  = qs('#qgUD_mean_opacity_lbl');
    const mdOp   = qs('#qgUD_mean_dot_opacity');
    const mdOpLb = qs('#qgUD_mean_dot_opacity_lbl');
    const bOp    = qs('#qgUD_box_opacity');
    const bOpLb  = qs('#qgUD_box_opacity_lbl');
    const bfOp   = qs('#qgUD_box_fill_opacity');
    const bfOpLb = qs('#qgUD_box_fill_opacity_lbl');
    const vOp    = qs('#qgUD_var_opacity');
    const vOpLb  = qs('#qgUD_var_opacity_lbl');




    const titleSub = qs('#qgUDTitleSub');

    const vList = qs('#qgUD_var_list');
    const vAdd  = qs('#qgUD_var_add');
    const vDel  = qs('#qgUD_var_del');
    const vVal  = qs('#qgUD_var_value');

    // init style inputs
    if (vColor) vColor.value = v.color || '#000000';
    if (vWidth) vWidth.value = String(v.width ?? 1.5);
    if (vStyle){
      // Preserve empty-string dash (solid). Only fallback when unset.
      const dash = ((v.dash === undefined || v.dash === null) ? '10 3 2 3' : String(v.dash)).trim();
      let key = 'dashdot';
      if (!dash) key = 'solid';
      else if (dash === '10 4') key = 'dash';
      else if (dash === '2 4') key = 'dot';
      else key = 'dashdot';
      vStyle.value = key;
    }
    if (vLbl) vLbl.checked = !!v.showLabel;
    if (vPref) vPref.value = qgNormVarPrefix(v.labelPrefix);

    // init mean line style inputs (per active FAI)
    function ensureMeanStyle(k){
      const kk = String(k||'');
      if (!kk) return null;
      if (!QG.meanStyleByCol) QG.meanStyleByCol = {};
      if (!QG.meanStyleByCol[kk]) QG.meanStyleByCol[kk] = {};
      const ms = QG.meanStyleByCol[kk];
      if (!ms.color) ms.color = seriesColorForKey(kk);
      const w = Number(ms.width);
      if (!isFinite(w) || w <= 0) ms.width = 2;
      if (ms.dash === undefined || ms.dash === null) ms.dash = '';
      if (!ms.dotColor) ms.dotColor = String(ms.color || seriesColorForKey(kk));
      if (ms.opacity === undefined || ms.opacity === null) ms.opacity = 1;
      if (ms.dotOpacity === undefined || ms.dotOpacity === null) ms.dotOpacity = 1;
      const ds = Number(ms.dotSize);
      if (!isFinite(ds) || ds <= 0) ms.dotSize = 3;
      ms.dotSize = Math.max(1, Math.min(8, Number(ms.dotSize)));
      // data dot style (Data 1/2/3)
      if (ms.dataDotOpacity === undefined || ms.dataDotOpacity === null) ms.dataDotOpacity = 1;
      const dds = Number(ms.dataDotSize);
      if (!isFinite(dds) || dds <= 0) ms.dataDotSize = 2;
      ms.dataDotSize = Math.max(1, Math.min(8, Number(ms.dataDotSize)));
      if (ms.dataDotColor === undefined || ms.dataDotColor === null || String(ms.dataDotColor).trim() === '') ms.dataDotColor = String(seriesColorForKey(kk));
	      // Default: hide mean dots on first open (JMP-like)
	      if (ms.hideMeanDots === undefined || ms.hideMeanDots === null) ms.hideMeanDots = true;
	      if (ms.hideDataDots === undefined || ms.hideDataDots === null) ms.hideDataDots = false;
      return ms;
    }


    function ensureBgStyle(k){
      const kk = String(k||'');
      if (!kk) return null;
      if (!QG.bgStyleByCol) QG.bgStyleByCol = {};
      if (!QG.bgStyleByCol[kk]) QG.bgStyleByCol[kk] = {};
      const bs = QG.bgStyleByCol[kk];
      if (!bs.color) bs.color = '#ffffff';
      if (bs.opacity === undefined || bs.opacity === null) bs.opacity = 1;
      if (bs.start === undefined) bs.start = null;
      if (bs.end === undefined) bs.end = null;
      return bs;
    }
    function syncBoxFillEnableUi(){
      const on = !!(bFill && bFill.checked);
      try{ if (bFillColor) bFillColor.disabled = !on; }catch(e){}
      try{ if (bfOp) bfOp.disabled = !on; }catch(e){}
    }

    function updateBgIcon(){
      const k = curColKey();
      if (!k) return;
      const bs = ensureBgStyle(k) || {};
      const c = bs.color ? String(bs.color) : '#ffffff';
      if (bgIcon){
        try{ bgIcon.style.color = c; }catch(e){}
      }
    }

    function refreshBgUi(){
      const k = curColKey();
      if (!k) return;
      const bs = ensureBgStyle(k) || {};
      if (bgColor) bgColor.value = String(bs.color || '#ffffff');
      try{
        if (bgStart) bgStart.value = (bs.start !== undefined && bs.start !== null && isFinite(Number(bs.start))) ? fmt(bs.start) : '';
        if (bgEnd)   bgEnd.value   = (bs.end   !== undefined && bs.end   !== null && isFinite(Number(bs.end)))   ? fmt(bs.end)   : '';
      }catch(e){}
      updateBgIcon();
      try{ if (bgOp) qgOpSet(bgOp, bgOpLb, (bs.opacity !== undefined ? bs.opacity : 1), true); }catch(e){}
      try{ qgBindOp(bgOp, bgOpLb); }catch(e){}
    }


    function qgOpSet(rangeEl, labelEl, op01, asCurrentLabel){
      if (!rangeEl) return;
      const v = Math.round(qgClamp01(op01) * 100);
      try{ rangeEl.value = String(v); }catch(e){}
      if (!labelEl) return;
      try{
        const isInp = (labelEl.tagName && String(labelEl.tagName).toLowerCase() === 'input');
        if (isInp) labelEl.value = String(v);
        else labelEl.textContent = v + '%';
      }catch(e){}
    }
	    function qgParseOpInput(s){
	      const t = String(s||'').replace(/,/g,'').trim();
	      if (!t) return null;
	      const hasPct = /%\s*$/.test(t);
	      const raw = hasPct ? t.replace(/%\s*$/,'').trim() : t;
	      const v = parseFloat(raw);
	      if (!isFinite(v)) return null;
	      if (hasPct) return qgClamp01(v/100);
	      // allow 0~1 or 0~100
	      if (v > 1.000001) return qgClamp01(v/100);
	      return qgClamp01(v);
	    }
    function qgOpRead(rangeEl){
      if (!rangeEl) return null;
      const v = Number(String(rangeEl.value||'').trim());
      if (!isFinite(v)) return null;
      return qgClamp01(v / 100);
    }
    function qgBindOp(rangeEl, labelEl){
      if (!rangeEl || rangeEl._qg_bind_op) return;
      rangeEl._qg_bind_op = true;
      rangeEl.addEventListener('input', ()=>{
        const op = qgOpRead(rangeEl);
        if (labelEl && op !== null){
	          try{
	            const isInp = (labelEl.tagName && String(labelEl.tagName).toLowerCase() === 'input');
	            if (isInp) labelEl.value = String(Math.round(op*100));
	            else labelEl.textContent = Math.round(op*100) + '%';
	          }catch(e){}
        }
        try{ liveApply(true); }catch(e){}
      });
      rangeEl.addEventListener('change', ()=>{
        const op = qgOpRead(rangeEl);
        if (labelEl && op !== null){
	          try{
	            const isInp = (labelEl.tagName && String(labelEl.tagName).toLowerCase() === 'input');
	            if (isInp) labelEl.value = String(Math.round(op*100));
	            else labelEl.textContent = Math.round(op*100) + '%';
	          }catch(e){}
        }
        try{ liveApply(true); }catch(e){}
      });

	      // numeric input support (optional)
	      if (labelEl && labelEl.tagName && String(labelEl.tagName).toLowerCase() === 'input' && !labelEl._qg_bind_op_inp){
	        labelEl._qg_bind_op_inp = true;
	        const apply = ()=>{
	          const op01 = qgParseOpInput(labelEl.value);
	          if (op01 === null) return;
	          try{ rangeEl.value = String(Math.round(qgClamp01(op01) * 100)); }catch(e){}
	          try{ labelEl.value = String(Math.round(qgClamp01(op01) * 100)); }catch(e){}
	          try{ liveApply(true); }catch(e){}
	        };
	        labelEl.addEventListener('change', apply);
	        labelEl.addEventListener('blur', apply);
	        labelEl.addEventListener('keydown', (ev)=>{ if (ev.key === 'Enter'){ try{ ev.preventDefault(); }catch(e){} apply(); }});
	      }
    }

    // Dot size helpers (range: 1..8)
    function qgSizeSet(rangeEl, labelEl, size, asCurrentLabel){
      if (!rangeEl) return;
      let v = Number(size);
      if (!isFinite(v) || v <= 0) v = 3;
      v = Math.max(1, Math.min(8, v));
      try{ rangeEl.value = String(v); }catch(e){}
      if (labelEl){
        try{
          const isInp = (labelEl.tagName && String(labelEl.tagName).toLowerCase() === 'input');
          const set = (s)=>{ if (isInp) labelEl.value = s; else labelEl.textContent = s; };
          const s = (Math.abs(v - Math.round(v)) < 1e-9) ? String(Math.round(v)) : String(v);
          set(s);
        }catch(e){}
      }
    }
	    function qgParseSizeInput(s){
	      const t = String(s||'').replace(/,/g,'').trim();
	      if (!t) return null;
	      const v = parseFloat(t);
	      if (!isFinite(v)) return null;
	      return Math.max(1, Math.min(8, v));
	    }
    function qgSizeRead(rangeEl){
      if (!rangeEl) return null;
      const v = Number(String(rangeEl.value||'').trim());
      if (!isFinite(v)) return null;
      return Math.max(1, Math.min(8, v));
    }
    function qgBindSize(rangeEl, labelEl){
      if (!rangeEl || rangeEl._qg_bind_size) return;
      rangeEl._qg_bind_size = true;
      const upd = ()=>{
        const v = qgSizeRead(rangeEl);
        if (labelEl && v !== null){
          try{
            const s = (Math.abs(v - Math.round(v)) < 1e-9) ? String(Math.round(v)) : String(v);
	            const isInp = (labelEl.tagName && String(labelEl.tagName).toLowerCase() === 'input');
	            if (isInp) labelEl.value = s;
	            else labelEl.textContent = s;
          }catch(e){}
        }
        try{ liveApply(true); }catch(e){}
      };
      rangeEl.addEventListener('input', upd);
      rangeEl.addEventListener('change', upd);

	      if (labelEl && labelEl.tagName && String(labelEl.tagName).toLowerCase() === 'input' && !labelEl._qg_bind_size_inp){
	        labelEl._qg_bind_size_inp = true;
	        const apply = ()=>{
	          const v = qgParseSizeInput(labelEl.value);
	          if (v === null) return;
	          try{ rangeEl.value = String(v); }catch(e){}
	          try{ labelEl.value = (Math.abs(v - Math.round(v)) < 1e-9) ? String(Math.round(v)) : String(v); }catch(e){}
	          try{ liveApply(true); }catch(e){}
	        };
	        labelEl.addEventListener('change', apply);
	        labelEl.addEventListener('blur', apply);
	        labelEl.addEventListener('keydown', (ev)=>{ if (ev.key === 'Enter'){ try{ ev.preventDefault(); }catch(e){} apply(); }});
	      }
    }


    function styleKeyFromDash(dash){
      const d = String(dash||'').trim();
      if (!d) return 'solid';
      if (d === '10 4') return 'dash';
      if (d === '2 4') return 'dot';
      if (d === '10 3 2 3') return 'dashdot';
      return 'dashdot';
    }

    function updateMeanIcons(){
      const k = curColKey();
      if (!k) return;
      const ms = ensureMeanStyle(k) || {};
      const lc = ms.color ? String(ms.color) : seriesColorForKey(k);
      const dc = ms.dotColor ? String(ms.dotColor) : lc;
      const w = (isFinite(Number(ms.width)) && Number(ms.width) > 0) ? Number(ms.width) : 2;
      const dash = (ms.dash !== undefined && ms.dash !== null) ? String(ms.dash).trim() : '';

      // mean line icon (line + dot)
      if (mIcon){
        try{ mIcon.style.color = lc; }catch(e){}
        const ln = mIcon.querySelector ? mIcon.querySelector('line') : null;
        if (ln){
          try{
            ln.setAttribute('stroke-width', String(Math.max(1, Math.min(6, w))));
            if (dash) ln.setAttribute('stroke-dasharray', dash);
            else ln.removeAttribute('stroke-dasharray');
            ln.setAttribute('stroke-linecap', (dash === '2 4') ? 'round' : 'butt');
          }catch(e){}
        }
        const cc = mIcon.querySelector ? mIcon.querySelector('circle') : null;
        if (cc){
          try{ cc.setAttribute('fill', dc); }catch(e){}
        }
      }

      // dot-only icon
      if (mdIcon){
        try{ mdIcon.style.color = dc; }catch(e){}
      }
    }

    function refreshMeanUi(){
      const k = curColKey();
      if (!k) return;
      const ms = ensureMeanStyle(k) || {};
      if (mColor) mColor.value = String(ms.color || seriesColorForKey(k));
      if (mWidth) mWidth.value = String((ms.width !== undefined && ms.width !== null) ? ms.width : 2);
      if (mStyle) mStyle.value = styleKeyFromDash(ms.dash);
      if (mdColor) mdColor.value = String(ms.dotColor || (ms.color || seriesColorForKey(k)));
      updateMeanIcons();
      try{ if (mdSize) qgSizeSet(mdSize, mdSizeLb, (ms.dotSize !== undefined ? ms.dotSize : 3), true); }catch(e){}
      try{ qgBindSize(mdSize, mdSizeLb); }catch(e){}
	      try{ if (mdHide) mdHide.checked = !!ms.hideMeanDots; }catch(e){}

      try{ if (mOp) qgOpSet(mOp, mOpLb, (ms.opacity !== undefined ? ms.opacity : 1), true); }catch(e){}
      try{ if (mdOp) qgOpSet(mdOp, mdOpLb, (ms.dotOpacity !== undefined ? ms.dotOpacity : 1), true); }catch(e){}
      try{ qgBindOp(mOp, mOpLb); }catch(e){}
      try{ qgBindOp(mdOp, mdOpLb); }catch(e){}
    }


    function updateDataDotIcon(){
      const k = curColKey();
      if (!k) return;
      if (ddIcon){
        try{
          const ms = ensureMeanStyle(k) || {};
          const c = (ms && ms.dataDotColor) ? String(ms.dataDotColor) : seriesColorForKey(k);
          ddIcon.style.color = c;
        }catch(e){}
      }
    }

    function refreshDataDotUi(){
      const k = curColKey();
      if (!k) return;
      const ms = ensureMeanStyle(k) || {};
      updateDataDotIcon();
      try{ if (ddColor) ddColor.value = String(ms.dataDotColor || seriesColorForKey(k)); }catch(e){}
      try{ if (ddSize) qgSizeSet(ddSize, ddSizeLb, (ms.dataDotSize !== undefined ? ms.dataDotSize : 2), true); }catch(e){}
      try{ qgBindSize(ddSize, ddSizeLb); }catch(e){}
      try{ if (ddOp) qgOpSet(ddOp, ddOpLb, (ms.dataDotOpacity !== undefined ? ms.dataDotOpacity : 1), true); }catch(e){}
      try{ qgBindOp(ddOp, ddOpLb); }catch(e){}
	      try{ if (ddHide) ddHide.checked = !!ms.hideDataDots; }catch(e){}
    }


    try{ refreshMeanUi(); }catch(e){}
    try{ refreshDataDotUi(); }catch(e){}
    try{ refreshBgUi(); }catch(e){}


    // Visual style picker (preview line updates with width/color)
    (function(){
      const pick = qs('#qgUD_var_stylepick');
      const btn  = qs('#qgUD_var_style_btn');
      const list = qs('#qgUD_var_style_list');
      if (!pick || !btn || !list || !vStyle) return;
      if (pick._qg_ready) return;
      pick._qg_ready = true;

      function curWidth(){
        const w = vWidth ? Number(String(vWidth.value||'').trim()) : NaN;
        return (isFinite(w) && w > 0) ? w : 1.5;
      }
      function curColor(){
        return vColor ? String(vColor.value || '#000000') : '#000000';
      }
      function svgLine(){
        const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('width','74');
        svg.setAttribute('height','10');
        svg.setAttribute('viewBox','0 0 74 10');
        const ln = document.createElementNS('http://www.w3.org/2000/svg','line');
        ln.setAttribute('x1','2'); ln.setAttribute('y1','5');
        ln.setAttribute('x2','72'); ln.setAttribute('y2','5');
        ln.setAttribute('fill','none');
        svg.appendChild(ln);
        return svg;
      }
      function applySvg(svg, key){
        const ln = svg && svg.querySelector ? svg.querySelector('line') : null;
        if (!ln) return;
        const dash = qgVarDashFromStyleKey(key);
        const w = curWidth();
        ln.setAttribute('stroke', curColor());
        ln.setAttribute('stroke-width', String(w));
        if (dash) ln.setAttribute('stroke-dasharray', dash);
        else ln.removeAttribute('stroke-dasharray');
        ln.setAttribute('stroke-linecap', (String(key||'') === 'dot') ? 'round' : 'butt');
      }

      function optLabel(val){
        const o = vStyle.querySelector('option[value="'+String(val)+'"]');
        return o ? String(o.textContent||'').trim() : String(val||'');
      }

      function closeList(){
        try{
          list.hidden = true;
          btn.setAttribute('aria-expanded','false');
        }catch(e){}
      }
      function openList(){
        try{
          list.hidden = false;
          btn.setAttribute('aria-expanded','true');
        }catch(e){}
      }
      function toggleList(){
        if (list.hidden) openList(); else closeList();
      }

      function setValue(val){
        const v = String(val||'');
        vStyle.value = v;
        // keep in sync
        updateButton();
        updateListActive();
        try{
          // trigger existing binding
          vStyle.dispatchEvent(new Event('change', {bubbles:true}));
        }catch(e){
          try{ liveApply(true); }catch(e2){}
        }
      }

      // Build list items once
      function buildList(){
        list.innerHTML = '';
        const values = Array.from(vStyle.querySelectorAll('option')).map(o=>String(o.value));
        values.forEach(val=>{
          const row = document.createElement('div');
          row.className = 'qg-ud-styleopt';
          row.setAttribute('role','option');
          row.dataset.value = val;

          const prev = document.createElement('span');
          prev.className = 'prev';
          const svg = svgLine();
          prev.appendChild(svg);

          const txt = document.createElement('span');
          txt.className = 'txt';
          txt.textContent = optLabel(val);

          row.appendChild(prev);
          row.appendChild(txt);

          row.addEventListener('click', (ev)=>{
            ev.preventDefault(); ev.stopPropagation();
            setValue(val);
            closeList();
          });

          list.appendChild(row);
          applySvg(svg, val);
        });
        updateListActive();
      }

      function updateListActive(){
        const cur = String(vStyle.value||'');
        Array.from(list.querySelectorAll('.qg-ud-styleopt')).forEach(el=>{
          el.classList.toggle('is-active', String(el.dataset.value||'') === cur);
        });
      }

      function updateButton(){
        // text
        const txt = btn.querySelector('.qg-ud-styletxt');
        if (txt) txt.textContent = optLabel(vStyle.value);

        // preview
        let holder = btn.querySelector('.qg-ud-styleprev');
        if (!holder) return;
        if (!holder._qg_svg){
          holder.innerHTML = '';
          holder._qg_svg = svgLine();
          holder.appendChild(holder._qg_svg);
        }
        applySvg(holder._qg_svg, vStyle.value);
      }

      function updateAllPreviews(){
        updateButton();
        Array.from(list.querySelectorAll('svg')).forEach(svg=>{
          const row = svg.closest ? svg.closest('.qg-ud-styleopt') : null;
          const val = row && row.dataset ? row.dataset.value : (vStyle.value||'');
          applySvg(svg, val);
        });
      }

      // Initial
      buildList();
      updateButton();

      // Toggle handler
      btn.addEventListener('click', (ev)=>{
        ev.preventDefault(); ev.stopPropagation();
        toggleList();
      });
      // Don't close user define when clicking in list
      list.addEventListener('click', (ev)=>{ ev.stopPropagation(); });

      // Close on outside click (within modal)
      document.addEventListener('click', (ev)=>{
        try{
          if (list.hidden) return;
          const t = ev.target;
          if (pick.contains(t)) return;
          closeList();
        }catch(e){}
      }, true);

      // Update previews on width/color changes
      if (vWidth && !vWidth._qg_styleprev){
        vWidth._qg_styleprev = true;
        vWidth.addEventListener('input', ()=>{ updateAllPreviews(); });
        vWidth.addEventListener('change', ()=>{ updateAllPreviews(); });
      }
      if (vColor && !vColor._qg_styleprev){
        vColor._qg_styleprev = true;
        vColor.addEventListener('input', ()=>{ updateAllPreviews(); });
        vColor.addEventListener('change', ()=>{ updateAllPreviews(); });
      }
      if (!vStyle._qg_styleprev){
        vStyle._qg_styleprev = true;
        vStyle.addEventListener('change', ()=>{ updateAllPreviews(); updateListActive(); });
      }

    })();



    // Mean line style picker (visual dropdown like JMP)
    (function(){
      const pick = qs('#qgUD_mean_stylepick');
      const btn  = qs('#qgUD_mean_style_btn');
      const list = qs('#qgUD_mean_style_list');
      if (!pick || !btn || !list || !mStyle) return;
      if (pick._qg_ready) return;
      pick._qg_ready = true;

      function curWidth(){
        const w = mWidth ? Number(String(mWidth.value||'').trim()) : NaN;
        return (isFinite(w) && w > 0) ? w : 2;
      }
      function curColor(){
        return mColor ? String(mColor.value || '#2b5bd7') : '#2b5bd7';
      }
      function svgLine(){
        const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('width','74');
        svg.setAttribute('height','10');
        svg.setAttribute('viewBox','0 0 74 10');
        const ln = document.createElementNS('http://www.w3.org/2000/svg','line');
        ln.setAttribute('x1','2'); ln.setAttribute('y1','5');
        ln.setAttribute('x2','72'); ln.setAttribute('y2','5');
        ln.setAttribute('fill','none');
        svg.appendChild(ln);
        return svg;
      }
      function applySvg(svg, key){
        const ln = svg && svg.querySelector ? svg.querySelector('line') : null;
        if (!ln) return;
        const dash = qgVarDashFromStyleKey(key);
        const w = curWidth();
        ln.setAttribute('stroke', curColor());
        ln.setAttribute('stroke-width', String(Math.max(1, Math.min(6, w))));
        if (dash) ln.setAttribute('stroke-dasharray', dash);
        else ln.removeAttribute('stroke-dasharray');
        ln.setAttribute('stroke-linecap', (String(key||'') === 'dot') ? 'round' : 'butt');
      }

      function optLabel(val){
        const o = mStyle.querySelector('option[value="'+String(val)+'"]');
        return o ? String(o.textContent||'').trim() : String(val||'');
      }

      function closeList(){
        try{
          list.hidden = true;
          btn.setAttribute('aria-expanded','false');
        }catch(e){}
      }
      function openList(){
        try{
          list.hidden = false;
          btn.setAttribute('aria-expanded','true');
        }catch(e){}
      }
      function toggleList(){
        if (list.hidden) openList(); else closeList();
      }

      function setValue(val){
        const v = String(val||'');
        mStyle.value = v;
        updateButton();
        updateListActive();
        try{
          mStyle.dispatchEvent(new Event('change', {bubbles:true}));
        }catch(e){
          try{ liveApply(true); }catch(e2){}
        }
      }

      function buildList(){
        list.innerHTML = '';
        const values = Array.from(mStyle.querySelectorAll('option')).map(o=>String(o.value));
        values.forEach(val=>{
          const row = document.createElement('div');
          row.className = 'qg-ud-styleopt';
          row.setAttribute('role','option');
          row.dataset.value = val;

          const prev = document.createElement('span');
          prev.className = 'prev';
          const svg = svgLine();
          prev.appendChild(svg);

          const txt = document.createElement('span');
          txt.className = 'txt';
          txt.textContent = optLabel(val);

          row.appendChild(prev);
          row.appendChild(txt);

          row.addEventListener('click', (ev)=>{
            ev.preventDefault(); ev.stopPropagation();
            setValue(val);
            closeList();
          });

          list.appendChild(row);
          applySvg(svg, val);
        });
        updateListActive();
      }

      function updateListActive(){
        const cur = String(mStyle.value||'');
        Array.from(list.querySelectorAll('.qg-ud-styleopt')).forEach(el=>{
          el.classList.toggle('is-active', String(el.dataset.value||'') === cur);
        });
      }

      function updateButton(){
        const txt = btn.querySelector('.qg-ud-styletxt');
        if (txt) txt.textContent = optLabel(mStyle.value);

        let holder = btn.querySelector('.qg-ud-styleprev');
        if (!holder) return;
        if (!holder._qg_svg){
          holder.innerHTML = '';
          holder._qg_svg = svgLine();
          holder.appendChild(holder._qg_svg);
        }
        applySvg(holder._qg_svg, mStyle.value);
      }

      function updateAllPreviews(){
        updateButton();
        Array.from(list.querySelectorAll('svg')).forEach(svg=>{
          const row = svg.closest ? svg.closest('.qg-ud-styleopt') : null;
          const val = row && row.dataset ? row.dataset.value : (mStyle.value||'');
          applySvg(svg, val);
        });
      }

      buildList();
      updateButton();

      btn.addEventListener('click', (ev)=>{
        ev.preventDefault(); ev.stopPropagation();
        toggleList();
      });
      list.addEventListener('click', (ev)=>{ ev.stopPropagation(); });

      document.addEventListener('click', (ev)=>{
        try{
          if (list.hidden) return;
          const t = ev.target;
          if (pick.contains(t)) return;
          closeList();
        }catch(e){}
      }, true);

      if (mWidth && !mWidth._qg_styleprev){
        mWidth._qg_styleprev = true;
        mWidth.addEventListener('input', ()=>{ updateAllPreviews(); });
        mWidth.addEventListener('change', ()=>{ updateAllPreviews(); });
      }
      if (mColor && !mColor._qg_styleprev){
        mColor._qg_styleprev = true;
        mColor.addEventListener('input', ()=>{ updateAllPreviews(); });
        mColor.addEventListener('change', ()=>{ updateAllPreviews(); });
      }
      if (!mStyle._qg_styleprev){
        mStyle._qg_styleprev = true;
        mStyle.addEventListener('change', ()=>{ updateAllPreviews(); updateListActive(); });
      }
    })();



    function curCol(){
      const k = (QG && QG.sel) ? (QG.sel.primaryColKey || '') : '';
      if (!k) return null;
      return (QG.cols || []).find(c => c.key === k) || null;
    }
    function curColKey(){
      const c = curCol();
      return c ? String(c.key||'') : '';
    }

    function seriesColorForKey(k){
      const kk = String(k||'');
      const meta = (typeof selectedSeriesMeta === 'function') ? selectedSeriesMeta() : [];
      for (let i=0; i<meta.length; i++){
        if (meta[i] && String(meta[i].key||'') === kk) return String(meta[i].color||'#2b5bd7');
      }
      return '#2b5bd7';
    }
    function ensureBoxStyle(k){
      const kk = String(k||'');
      if (!kk) return null;
      if (!QG.boxStyleByCol) QG.boxStyleByCol = {};
      if (!QG.boxStyleByCol[kk]) QG.boxStyleByCol[kk] = {};
      const bs = QG.boxStyleByCol[kk];
      if (bs.fill === undefined) bs.fill = false;
      if (bs.opacity === undefined || bs.opacity === null) bs.opacity = 1;
      if (bs.fillOpacity === undefined || bs.fillOpacity === null) bs.fillOpacity = 0.18;
      return bs;
    }
    function updateBoxPreview(){
      if (!bIcon) return;
      const k = curColKey();
      const bs = ensureBoxStyle(k) || {};
      const effStroke = (bColor && bColor.value) ? String(bColor.value) : (bs.color ? String(bs.color) : seriesColorForKey(k));
      const effFill = (bFillColor && bFillColor.value) ? String(bFillColor.value) : (bs.fillColor ? String(bs.fillColor) : effStroke);
      let sw = 2;
      try{
        const stx = qgEnsureUserStyle();
        const sw0 = (bSW ? num(bSW.value) : null);
        if (sw0 !== null && isFinite(sw0) && sw0 > 0) sw = sw0;
        else if (stx && stx.box && isFinite(Number(stx.box.strokeWidth)) && Number(stx.box.strokeWidth) > 0) sw = Number(stx.box.strokeWidth);
      }catch(e){}
      sw = Math.max(0.5, Math.min(6, Number(sw)));
      try{ bIcon.style.color = effStroke; }catch(e){}
      const r = bIcon.querySelector ? bIcon.querySelector('rect') : null;
      if (r){
        try{
          r.setAttribute('stroke-width', String(sw));
          if (bFill && bFill.checked){
            r.setAttribute('fill', effFill);
            const _fop = (qgOpRead(bfOp) !== null) ? qgOpRead(bfOp) : (bs.fillOpacity !== undefined ? bs.fillOpacity : 0.18);
            r.setAttribute('fill-opacity', String(qgClamp01(_fop)));
          }else{
            r.setAttribute('fill','none');
            r.removeAttribute('fill-opacity');
          }
        }catch(e){}
      }
      syncBoxFillEnableUi();
    }
    function refreshBoxUi(){
      const k = curColKey();
      if (!k) return;
      const bs = ensureBoxStyle(k) || {};
      const eff = bs.color ? String(bs.color) : seriesColorForKey(k);
      if (bColor) bColor.value = eff;
      if (bFill) bFill.checked = !!bs.fill;
      const effFill = bs.fillColor ? String(bs.fillColor) : eff;
      if (bFillColor) bFillColor.value = effFill;
      updateBoxPreview();
      try{ if (bOp) qgOpSet(bOp, bOpLb, (bs.opacity !== undefined ? bs.opacity : 1), true); }catch(e){}
      try{ if (bfOp) qgOpSet(bfOp, bfOpLb, (bs.fillOpacity !== undefined ? bs.fillOpacity : 0.18), true); }catch(e){}
      try{ qgBindOp(bOp, bOpLb); }catch(e){}
      try{ qgBindOp(bfOp, bfOpLb); }catch(e){}
      try{
        const stx = qgEnsureUserStyle();
        if (!stx.box) stx.box = {};
        let s = Number(stx.box.widthScale);
        if (!isFinite(s) || s <= 0 || s > 1) s = 0.2;
        if (bTh) bTh.value = String(Math.round(qgClamp01(s) * 100));
	        if (bThLb && bTh){
	          const txt = String(bTh.value || '100');
	          try{
	            const isInp = (bThLb.tagName && String(bThLb.tagName).toLowerCase() === 'input');
	            if (isInp) bThLb.value = txt; else bThLb.textContent = txt;
	          }catch(e){}
	        }
        const sw0 = Number(stx.box.strokeWidth);
        if (bSW) bSW.value = String((isFinite(sw0) && sw0 > 0) ? sw0 : 1);
        if (bSW && !bSW._qg_bind_bsw){
          bSW._qg_bind_bsw = true;
          const upd2 = ()=>{ try{ liveApply(true); }catch(e){} };
          bSW.addEventListener('input', upd2);
          bSW.addEventListener('change', upd2);
        }
        if (bTh && !bTh._qg_bind_boxth){
          bTh._qg_bind_boxth = true;
          const upd = ()=>{ 
	            try{
	              if (bThLb){
	                const txt = String(bTh.value || '');
	                const isInp = (bThLb.tagName && String(bThLb.tagName).toLowerCase() === 'input');
	                if (isInp) bThLb.value = txt; else bThLb.textContent = txt;
	              }
	            }catch(e){}
            try{ liveApply(true); }catch(e){}
          };
          bTh.addEventListener('input', upd);
          bTh.addEventListener('change', upd);
        }

	        if (bThLb && bThLb.tagName && String(bThLb.tagName).toLowerCase() === 'input' && !bThLb._qg_bind_boxth_inp){
	          bThLb._qg_bind_boxth_inp = true;
	          const apply = ()=>{
	            const op01 = qgParseOpInput(bThLb.value);
	            if (op01 === null) return;
	            const pct = Math.round(qgClamp01(op01) * 100);
	            const pctClamped = Math.max(20, Math.min(100, pct));
	            try{ bTh.value = String(pctClamped); }catch(e){}
	            try{ bThLb.value = String(pctClamped); }catch(e){}
	            try{ liveApply(true); }catch(e){}
	          };
	          bThLb.addEventListener('change', apply);
	          bThLb.addEventListener('blur', apply);
	          bThLb.addEventListener('keydown', (ev)=>{ if (ev.key === 'Enter'){ try{ ev.preventDefault(); }catch(e){} apply(); }});
	        }
      }catch(e){}
    }
    function applyBoxFromInputs(){
      const k = curColKey();
      if (!k) return;
      const bs = ensureBoxStyle(k);
      if (!bs) return;
      try{
        if (bColor) bs.color = String(bColor.value || '');
        if (bFill) bs.fill = !!bFill.checked;
        if (bFillColor) bs.fillColor = String(bFillColor.value || '');
        const _op = qgOpRead(bOp);
        if (_op !== null) bs.opacity = _op;
        const _fop = qgOpRead(bfOp);
        if (_fop !== null) bs.fillOpacity = _fop;
      }catch(e){}
      updateBoxPreview();
    }

    function ensureVarStyle(k){
      const kk = String(k||'');
      if (!kk) return null;
      if (!QG.varStyleByCol) QG.varStyleByCol = {};
      if (!QG.varStyleByCol[kk]) QG.varStyleByCol[kk] = {};
      const vs = QG.varStyleByCol[kk];
      if (vs.opacity === undefined || vs.opacity === null) vs.opacity = 1;
      return vs;
    }

    function ensureVarArr(k){
      const kk = String(k||'');
      if (!kk) return [];
      if (!QG.varLines) QG.varLines = {};
      if (!Array.isArray(QG.varLines[kk])) QG.varLines[kk] = [];
      return QG.varLines[kk];
    }
    function getSelIdx(k, arrLen){
      try{
        if (QG._udVarSel && QG._udVarSel.colKey === k){
          const n = Number(QG._udVarSel.idx||0);
          if (isFinite(n)) return Math.max(0, Math.min(arrLen-1, n));
        }
      }catch(e){}
      return (arrLen > 0) ? (arrLen - 1) : -1;
    }
    function setSelIdx(k, idx){
      try{ QG._udVarSel = { colKey: k, idx: idx }; }catch(e){}
    }

    function qgFmtVarVal(v){
      const n = Number(v);
      if (!isFinite(n)) return '';
      // integer?
      if (Math.abs(n - Math.round(n)) < 1e-9) return String(Math.round(n));
      let s = n.toFixed(6);
      s = s.replace(/0+$/,'').replace(/\.$/,'');
      return s;
    }

    function refreshVarUi(preserveValue){
      const col = curCol();
      const k = col ? String(col.key||'') : '';
      const lbl = k ? qgGetDisplayLabel(k) : '';

      if (titleSub){
        titleSub.textContent = lbl ? lbl : '';
      }

      const arr = ensureVarArr(k);

      if (vList){
        vList.innerHTML = '';
        if (!arr.length){
          const o = document.createElement('option');
          o.value = '-1';
          o.textContent = '(기준선 없음)';
          vList.appendChild(o);
        }else{
          for (let i=0;i<arr.length;i++){
            const o = document.createElement('option');
            o.value = String(i);
            const vs = qgFmtVarVal(arr[i] ? arr[i].value : null);
            o.textContent = vs ? ('기준선 ' + String(i+1) + ' (' + vs + ')') : ('기준선 ' + String(i+1));
            vList.appendChild(o);
          }
        }

        const idx = getSelIdx(k, arr.length);
        vList.value = (idx >= 0) ? String(idx) : '-1';
        setSelIdx(k, idx);

        if (vDel) vDel.disabled = !(arr.length && idx >= 0);
      }

      if (vVal && !preserveValue){
        const idx = getSelIdx(k, arr.length);
        const it = (idx >= 0) ? arr[idx] : null;
        const vv = it ? it.value : null;
        vVal.value = (vv !== undefined && vv !== null && isFinite(Number(vv))) ? String(vv) : '';
      }
      // varline opacity (per active FAI)
      try{
        const vs2 = ensureVarStyle(k) || {};
        if (vOp) qgOpSet(vOp, vOpLb, (vs2.opacity !== undefined ? vs2.opacity : 1), true);
        qgBindOp(vOp, vOpLb);
      }catch(e){}

    }
    function applyStyleFromInputs(){
      const st2 = qgEnsureUserStyle();
      const vv = st2.varline;
      try{
        if (vColor) vv.color = String(vColor.value || '#000000');
        if (vWidth){
          const w = Number(String(vWidth.value||'').trim());
          vv.width = (isFinite(w) && w > 0) ? w : 1.5;
        }
        if (vStyle) vv.dash = qgVarDashFromStyleKey(vStyle.value);
        if (vLbl) vv.showLabel = !!vLbl.checked;
        if (vPref) vv.labelPrefix = qgNormVarPrefix(vPref.value);
        try{
          if (bTh){
            const p = Number(String(bTh.value||'').trim());
            if (isFinite(p)){
              if (!st2.box) st2.box = {};
              st2.box.widthScale = qgClamp01(p/100);
            }
          }
        }catch(e){}
        try{
          if (bSW){
            const sw = Number(String(bSW.value||'').trim());
            if (isFinite(sw) && sw > 0){
              if (!st2.box) st2.box = {};
              st2.box.strokeWidth = Math.max(0.5, Math.min(6, sw));
            }
          }
        }catch(e){}
        try{
          const k0 = curColKey();
          if (k0){
            const vs2 = ensureVarStyle(k0);
            const _vop = qgOpRead(vOp);
            if (vs2 && _vop !== null) vs2.opacity = _vop;
            if (!QG.varStyleByCol) QG.varStyleByCol = {};
            if (vs2) QG.varStyleByCol[k0] = vs2;
          }
        }catch(e){}
      }catch(e){}
      // mean line + mean dots (per active FAI)
      try{
        const k = curColKey();
        if (k){
          const ms = ensureMeanStyle(k);
          if (ms){
            if (mColor) ms.color = String(mColor.value || seriesColorForKey(k));
            if (mWidth){
              const w2 = Number(String(mWidth.value||'').trim());
              ms.width = (isFinite(w2) && w2 > 0) ? w2 : 2;
            }
            if (mStyle) ms.dash = qgVarDashFromStyleKey(mStyle.value);
            if (mdColor) ms.dotColor = String(mdColor.value || ms.color || seriesColorForKey(k));
            if (ddColor) ms.dataDotColor = String(ddColor.value || seriesColorForKey(k));
            const _op = qgOpRead(mOp);
            if (_op !== null) ms.opacity = _op;
            const _dop = qgOpRead(mdOp);
            if (_dop !== null) ms.dotOpacity = _dop;
            const _ds = qgSizeRead(mdSize);
            if (_ds !== null) ms.dotSize = _ds;
            const _dds = qgSizeRead(ddSize);
            if (_dds !== null) ms.dataDotSize = _dds;
            const _ddop = qgOpRead(ddOp);
            if (_ddop !== null) ms.dataDotOpacity = _ddop;
	            try{ if (mdHide) ms.hideMeanDots = !!mdHide.checked; }catch(e){}
	            try{ if (ddHide) ms.hideDataDots = !!ddHide.checked; }catch(e){}
            if (!QG.meanStyleByCol) QG.meanStyleByCol = {};
            QG.meanStyleByCol[k] = ms;
          }
        }
      }catch(e){}
      try{ updateMeanIcons(); }catch(e){}
      try{ updateDataDotIcon(); }catch(e){}

      // background (per active FAI)
      try{
        const k = curColKey();
        if (k){
          const bs = ensureBgStyle(k);
          if (bs){
            if (bgColor) bs.color = String(bgColor.value || '#ffffff');
            const _bop = qgOpRead(bgOp);
            if (_bop !== null) bs.opacity = _bop;
            const s0 = bgStart ? num(bgStart.value) : null;
            const e0 = bgEnd ? num(bgEnd.value) : null;
            if (s0 !== null && e0 !== null){
              bs.start = s0;
              bs.end = e0;
            }else{
              bs.start = null;
              bs.end = null;
            }
            if (!QG.bgStyleByCol) QG.bgStyleByCol = {};
            QG.bgStyleByCol[k] = bs;
          }
        }
      }catch(e){}
      try{ updateBgIcon(); }catch(e){}

      try{ applyBoxFromInputs(); }catch(e){}
    }

    function applyVarValueFromInput(){
      const col = curCol();
      const k = col ? String(col.key||'') : '';
      if (!k) return;
      const arr = ensureVarArr(k);
      const idx = getSelIdx(k, arr.length);
      if (idx < 0 || !arr[idx]) return;

      const raw = (vVal ? String(vVal.value||'').trim() : '');
      if (raw === ''){
        arr[idx].value = null;
      }else{
        const vv = num(raw);
        if (vv === null){
          // don't spam messages while typing; ignore until valid
          return;
        }
        arr[idx].value = vv;
      }
      if (!QG.varLines) QG.varLines = {};
      QG.varLines[k] = arr;
    }

    function liveApply(preserveValue){
      try{ applyStyleFromInputs(); }catch(e){}
      try{ applyVarValueFromInput(); }catch(e){}
      try{ renderLegend(); renderGrid(); }catch(e){}
      try{ refreshVarUi(!!preserveValue); }catch(e){}
    }

    function setTab(tabKey){
      const k = String(tabKey||'varline');
      qsa('#qgUDNav .qg-ud-navitem').forEach(el=>{
        el.classList.toggle('is-active', el.dataset && el.dataset.tab === k);
      });
      tabs.forEach(el=>{
        const show = (el.dataset && el.dataset.tab === k);
        try{
          if (show) el.style.setProperty('display','block','important');
          else el.style.setProperty('display','none','important');
        }catch(e){
          el.style.display = show ? 'block' : 'none';
        }
      });
      if (k === 'box') { try{ updateBoxPreview(); }catch(e){} }
    }

    if (!bd._qg_bind){
      bd._qg_bind = true;

      nav.addEventListener('click', (ev)=>{
        const item = ev.target && ev.target.closest ? ev.target.closest('.qg-ud-navitem') : null;
        if (!item) return;
        ev.preventDefault();
        ev.stopPropagation();
        setTab(item.dataset ? item.dataset.tab : 'varline');
      });

      bd.addEventListener('click', (ev)=>{
        if (ev.target === bd) qgCloseUserDefineDlg();
      });

      // draggable
      const dlg = qs('#qgUDDlgBackdrop .qg-ud');
      const bar = qs('#qgUDDlgBackdrop .qg-ud-titlebar');
      if (dlg && bar && !bar._qg_drag){
        bar._qg_drag = true;
        const state = { down:false, sx:0, sy:0, ox:0, oy:0 };
        function curPos(){
          const p = (QG && QG._udPos) ? QG._udPos : {x:0,y:0};
          return {x:Number(p.x||0), y:Number(p.y||0)};
        }
        function applyPos(x,y){
          if (!dlg) return;
          dlg.style.transform = `translate(${x}px, ${y}px)`;
          QG._udPos = {x, y};
        }
        try{ const p = curPos(); applyPos(p.x, p.y); }catch(e){}
        bar.addEventListener('pointerdown', (ev)=>{
          try{
            if (ev.button !== undefined && ev.button !== 0) return;
            ev.preventDefault();
            ev.stopPropagation();
            state.down = true;
            state.sx = ev.clientX;
            state.sy = ev.clientY;
            const p = curPos();
            state.ox = p.x; state.oy = p.y;
            bar.setPointerCapture && bar.setPointerCapture(ev.pointerId);
          }catch(e){}
        });
        bar.addEventListener('pointermove', (ev)=>{
          if (!state.down) return;
          try{
            applyPos(state.ox + (ev.clientX - state.sx), state.oy + (ev.clientY - state.sy));
          }catch(e){}
        });
        function end(ev){
          if (!state.down) return;
          try{
            state.down = false;
            bar.releasePointerCapture && ev && bar.releasePointerCapture(ev.pointerId);
          }catch(e){}
        }
        bar.addEventListener('pointerup', end);
        bar.addEventListener('pointercancel', end);
      }

      // action buttons
      apply.addEventListener('click', (ev)=>{ ev.preventDefault(); ev.stopPropagation(); liveApply(true); });
      ok.addEventListener('click', (ev)=>{ ev.preventDefault(); ev.stopPropagation(); liveApply(true); qgCloseUserDefineDlg(); });
      close.addEventListener('click', (ev)=>{ ev.preventDefault(); ev.stopPropagation(); qgCloseUserDefineDlg(); });

      // live bindings (one-time)
      function bindLive(el, evNames){
        if (!el) return;
        const names = (evNames||['input','change']);
        names.forEach(n=>{
          el.addEventListener(n, ()=>{ liveApply(true); });
        });
      }
      bindLive(vColor);
      bindLive(vWidth);
      bindLive(vStyle, ['change']);
      bindLive(vLbl, ['change']);
      bindLive(vPref);
      bindLive(bColor);
      bindLive(bFill, ['change']);
      bindLive(bFillColor);

      // mean line / dot live update (per active FAI)
      bindLive(mColor);
      bindLive(mWidth);
      bindLive(mStyle, ['change']);
      bindLive(mdColor);
      bindLive(ddColor);
	      bindLive(mdHide, ['change']);
      bindLive(bgColor);
	      bindLive(ddHide, ['change']);
      bindLive(bgStart);
      bindLive(bgEnd);



      if (vList && !vList._qg_bind){
        vList._qg_bind = true;
        vList.addEventListener('change', ()=>{
          const k = curColKey();
          const arr = ensureVarArr(k);
          const idx = Number(vList.value||'-1');
          if (isFinite(idx) && idx >= 0 && idx < arr.length){
            setSelIdx(k, idx);
          }
          refreshVarUi(false);
        });
      }

      if (vVal && !vVal._qg_bind){
        vVal._qg_bind = true;
        vVal.addEventListener('input', ()=>{ liveApply(true); });
        vVal.addEventListener('change', ()=>{ liveApply(true); });
      }

      if (vAdd && !vAdd._qg_bind){
        vAdd._qg_bind = true;
        vAdd.addEventListener('click', (ev)=>{
          ev.preventDefault(); ev.stopPropagation();
          const col = curCol();
          const k = col ? String(col.key||'') : '';
          if (!k) return;
          const arr = ensureVarArr(k);
          // default value = mid of current axis if possible
          let defV = 0;
          try{
            const ax = (typeof getAxisState === 'function') ? getAxisState(k) : null;
            if (ax && isFinite(Number(ax.yMin)) && isFinite(Number(ax.yMax))){
              defV = (Number(ax.yMin) + Number(ax.yMax)) / 2;
            }
          }catch(e){}
          arr.push({ id:'vl'+(QG.varLineSeq++), value:defV });
          QG.varLines[k] = arr;
          setSelIdx(k, arr.length - 1);
          refreshVarUi(false);
          liveApply(true);
          try{ if (vVal) { vVal.focus(); vVal.select(); } }catch(e){}
        });
      }

      if (vDel && !vDel._qg_bind){
        vDel._qg_bind = true;
        vDel.addEventListener('click', (ev)=>{
          ev.preventDefault(); ev.stopPropagation();
          const col = curCol();
          const k = col ? String(col.key||'') : '';
          if (!k) return;
          const arr = ensureVarArr(k);
          const idx = getSelIdx(k, arr.length);
          if (idx < 0 || idx >= arr.length) return;
          arr.splice(idx, 1);
          QG.varLines[k] = arr;
          setSelIdx(k, Math.max(0, Math.min(arr.length-1, idx-1)));
          refreshVarUi(false);
          liveApply(true);
        });
      }
    }

    // default tab: varline
    setTab('varline');
    // Defensive visibility (prevents tab/body/buttons going blank due to external CSS collisions)
    try{
      const _udBody = qs('#qgUDDlgBackdrop .qg-ud-body');
      if (_udBody) _udBody.style.setProperty('display','grid','important');
      const _udMain = qs('#qgUDDlgBackdrop .qg-ud-main');
      if (_udMain) _udMain.style.setProperty('display','block','important');
      const _udBtns = qs('#qgUDDlgBackdrop .qg-ud-btncol');
      if (_udBtns) _udBtns.style.setProperty('display','flex','important');
      if (nav) nav.style.setProperty('display','block','important');
    }catch(e){}
    bd.setAttribute('aria-hidden','false');

    // restore last position if any
    try{
      const dlg = qs('#qgUDDlgBackdrop .qg-ud');
      if (dlg && QG && QG._udPos){
        dlg.style.transform = `translate(${Number(QG._udPos.x||0)}px, ${Number(QG._udPos.y||0)}px)`;
      }
    }catch(e){}

    refreshVarUi(false);
    try{ refreshBoxUi(); }catch(e){}
    try{ refreshMeanUi(); }catch(e){}
    try{ refreshDataDotUi(); }catch(e){}
  }


function getBase(){
    const ov = qs('#qgOverlay');
    const b = ov && ov.dataset ? (ov.dataset.base || '') : '';
    return (b || '').toString();
  }

  // Prevent the underlying page from scrolling while the full-screen overlay is open.
  function lockScroll(){
    if (QG._scrollLocked) return;
    const html = document.documentElement;
    const body = document.body;
    QG._prevOverflow = {
      html: html ? html.style.overflow : '',
      body: body ? body.style.overflow : '',
    };
    if (html) html.style.overflow = 'hidden';
    if (body) body.style.overflow = 'hidden';
    QG._scrollLocked = true;
  }

  function unlockScroll(){
    if (!QG._scrollLocked) return;
    const html = document.documentElement;
    const body = document.body;
    const prev = QG._prevOverflow || { html:'', body:'' };
    if (html) html.style.overflow = prev.html || '';
    if (body) body.style.overflow = prev.body || '';
    QG._scrollLocked = false;
  }

  function open(){
    const ov = qs('#qgOverlay');
    if (!ov) return;

    // If running under DP_SHELL (iframe), promote the iframe to a top-level fullscreen layer
    // so the modal covers the shell sidebar/userbar too.
    try{
      const topw = window.top;
      if (topw && topw !== window && topw.DP_SHELL_MODAL && typeof topw.DP_SHELL_MODAL.open === 'function'){
        topw.DP_SHELL_MODAL.open();
      }
    }catch(e){}

    lockScroll();
    try{ qgInstallCtxTrap(); }catch(e){}
    try{ qgBindHoverTip(); }catch(e){}
    try{ document.documentElement.classList.add('qg-open'); }catch(e){}
    try{ document.body.classList.add('qg-open'); }catch(e){}
    ov.setAttribute('aria-hidden','false');
    ov.style.display='block';
    if (!QG.built) build();
    else refresh();
  }

  function close(){
    const ov = qs('#qgOverlay');
    if (!ov) return;
    ov.setAttribute('aria-hidden','true');
    ov.style.display='none';
    try{ document.documentElement.classList.remove('qg-open'); }catch(e){}
    try{ document.body.classList.remove('qg-open'); }catch(e){}
    unlockScroll();

    // Restore SHELL layout if we promoted the iframe
    try{
      const topw = window.top;
      if (topw && topw !== window && topw.DP_SHELL_MODAL && typeof topw.DP_SHELL_MODAL.close === 'function'){
        topw.DP_SHELL_MODAL.close();
      }
    }catch(e){}
  }

  function findMainTable(root){
    const base = root || document;

    // Prefer the pivot result table inside .table-wrap
    const candidates = qsa('.table-wrap table', base);
    for (const t of candidates){
      if (qs('thead th.ipqc-pivot-col', t)) return t;
    }
    if (candidates.length) return candidates[0];

    // Fallback: any table that has the required headers
    const all = qsa('table', base);
    for (const t of all){
      const ths = qsa('thead th', t);
      if (!ths.length) continue;
      const texts = ths.map(th => (th.textContent||'').trim());
      if (texts.includes('라벨') && texts.includes('Tool') && texts.includes('Cavity') && texts.includes('Date')) return t;
    }
    return null;
  }

  function detectPivot(ths){
    const texts = ths.map(th => (th.textContent||'').trim());
    return texts.includes('라벨');
  }

  function buildColsFromPivot(ths){
    const texts = ths.map(th => (th.textContent||'').trim());
    const idxLabel = texts.indexOf('라벨');
    const cols = [];
    for (let i = idxLabel + 1; i < ths.length; i++){
      const th = ths[i];
      const label = (th.textContent||'').trim();
      const key = (th.dataset && th.dataset.colkey) ? String(th.dataset.colkey) : label;
      const rawUsl = (th.dataset && th.dataset.usl !== undefined) ? num(th.dataset.usl) : null;
      const rawLsl = (th.dataset && th.dataset.lsl !== undefined) ? num(th.dataset.lsl) : null;
      const savedBase = (QG.limitBaseByCol && QG.limitBaseByCol[key]) ? QG.limitBaseByCol[key] : null;
      const baseUsl = (savedBase && savedBase.baseUsl !== undefined) ? savedBase.baseUsl : rawUsl;
      const baseLsl = (savedBase && savedBase.baseLsl !== undefined) ? savedBase.baseLsl : rawLsl;
      const savedPct = (QG.oocSpecByCol && QG.oocSpecByCol[key] !== undefined) ? QG.oocSpecByCol[key] : 85;
      cols.push({
        idx:i,
        key,
        label,
        usl: baseUsl,
        lsl: baseLsl,
        baseUsl,
        baseLsl,
        oocSpecPct: qgClampOocSpecPct(savedPct),
        th,
      });
    }
    return cols;
  }

  function buildDataFromPivotRows(tbodyRows){
    const ths = qsa('thead th', QG.table);
    const texts = ths.map(th => (th.textContent||'').trim());

    const idxTool   = texts.indexOf('Tool');
    const idxCavity = texts.indexOf('Cavity');
    const idxDate   = texts.indexOf('Date');
    const idxLbl    = texts.indexOf('라벨');
    if (idxTool < 0 || idxCavity < 0 || idxDate < 0 || idxLbl < 0) return null;

    // Accept: Data 1 / Data1 / DATA 2 etc
    const reData = /^\s*Data\s*([1-3])\s*$/i;

    const group = Object.create(null);
    const ensure = (o,k)=> (o[k]||(o[k]=Object.create(null)));

    for (const tr of tbodyRows){
      const tds = qsa('td', tr);
      if (!tds.length) continue;

      const tool = ((tds[idxTool]?.textContent || '').trim()).toUpperCase();
      const cav  = normCavity((tds[idxCavity]?.textContent || '').trim());
      const dateRaw = (tds[idxDate]?.textContent || '').trim();
      const di = normalizeDateKey(dateRaw);
      const date = di.key;
      if (!date) continue;
      const lb   = (tds[idxLbl]?.textContent || '').trim();

      const m = reData.exec(lb);
      if (!m) continue;

      const toolO = ensure(group, tool);
      const cavO  = ensure(toolO, cav);
      if (!cavO[date]) cavO[date] = { __rows: [] };

      cavO[date].__rows.push({ label: lb, tds });
    }
    return group;
  }

  
  function qgDocById(root, id){
    try{
      if (root && typeof root.getElementById === 'function') return root.getElementById(id);
    }catch(e){}
    try{ return qs('#' + String(id || ''), root || document); }catch(e){ return null; }
  }

  function qgUniqueStrings(arr){
    const out = [];
    const seen = new Set();
    for (const it of Array.isArray(arr) ? arr : []){
      const v = String(it == null ? '' : it).trim();
      if (!v || seen.has(v)) continue;
      seen.add(v);
      out.push(v);
    }
    return out;
  }

  function qgReadCheckedValues(root, name){
    return qgUniqueStrings(qsa('input[name="' + String(name || '') + '[]"]:checked', root).map(el => String(el.value || '')));
  }

  function qgReadHiddenValues(root, selector){
    return qgUniqueStrings(qsa(selector, root).map(el => String(el.value || '')));
  }

  function qgExtractPageDate(btn){
    if (!btn) return '';
    try{
      const dv = String((btn.dataset && (btn.dataset.date || btn.dataset.value)) || '').trim();
      if (dv) return dv;
    }catch(e){}
    try{
      const oc = String(btn.getAttribute('onclick') || '');
      const m = oc.match(/setPageDate\('([^']+)'/);
      if (m && m[1]) return String(m[1]).trim();
    }catch(e){}
    try{
      const y = (qs('.ms-datebtn-y', btn)?.textContent || '').trim();
      const md = (qs('.ms-datebtn-md', btn)?.textContent || '').trim();
      if (/^\d{4}$/.test(y) && /^\d{2}-\d{2}$/.test(md)) return y + '-' + md;
    }catch(e){}
    return '';
  }

  function qgColsFromAnyTable(table){
    try{
      if (!table) return [];
      const ths = qsa('thead th', table);
      if (!detectPivot(ths)) return [];
      return buildColsFromPivot(ths).map(c => ({ key: String(c.key || c.label || ''), label: String(c.label || c.key || '') }));
    }catch(e){
      return [];
    }
  }

  function qgModelToMapKey(model){
    try{
      if (typeof ipqcModelToMapKeyJs === 'function') return String(ipqcModelToMapKeyJs(model) || '');
    }catch(e){}
    const m = String(model || '').toLowerCase();
    if (/(^|\s)z\s*[-_ ]?\s*stopper/.test(m)) return 'ZSTOPPER';
    if (/(^|\s)z\s*[-_ ]?\s*carrier/.test(m)) return 'ZCARRIER';
    if (/(^|\s)y\s*[-_ ]?\s*carrier/.test(m)) return 'YCARRIER';
    if (/(^|\s)x\s*[-_ ]?\s*carrier/.test(m)) return 'XCARRIER';
    if (/(^|\s)ir\s*[-_ ]?\s*base/.test(m)) return 'IRBASE';
    return '';
  }

  function qgMappedFaiOptions(typeValue, modelValue){
    const type = String(typeValue || '').trim().toUpperCase();
    const model = String(modelValue || '');
    const mk = qgModelToMapKey(model);
    let items = [];
    try{
      if (type === 'AOI'){
        if (typeof IPQC_AOI_FAI_MODEL_MAP !== 'undefined' && model && Array.isArray(IPQC_AOI_FAI_MODEL_MAP[model])){
          items = IPQC_AOI_FAI_MODEL_MAP[model];
        }else if (typeof IPQC_AOI_FAI_MAP !== 'undefined' && mk && Array.isArray(IPQC_AOI_FAI_MAP[mk])){
          items = IPQC_AOI_FAI_MAP[mk];
        }
      }else if (type === 'OQC'){
        if (typeof IPQC_OQC_POINT_MODEL_MAP !== 'undefined' && model && Array.isArray(IPQC_OQC_POINT_MODEL_MAP[model])){
          items = IPQC_OQC_POINT_MODEL_MAP[model];
        }
        if ((!items || !items.length) && typeof IPQC_FAI_MAP !== 'undefined' && mk && IPQC_FAI_MAP[mk] && Array.isArray(IPQC_FAI_MAP[mk][type])){
          items = IPQC_FAI_MAP[mk][type];
        }
      }else if (type === 'OMM' || type === 'CMM'){
        if (typeof IPQC_FAI_MAP !== 'undefined' && mk && IPQC_FAI_MAP[mk] && Array.isArray(IPQC_FAI_MAP[mk][type])){
          items = IPQC_FAI_MAP[mk][type];
        }
      }
    }catch(e){}
    return qgUniqueStrings(items || []);
  }

  function qgPatchQueryOptionsForState(options, state, extraFai, fallbackTable, useTableFallback){
    const base = Object.assign({}, options || {});
    const st = state || {};
    const typeValue = String(st.type || '').trim().toUpperCase();
    const modelValue = String(st.model || '');
    const hiddenFai = qgUniqueStrings((Array.isArray(extraFai) ? extraFai : []).filter(v => String(v || '') !== '__ALL__'));
    const mappedFai = qgMappedFaiOptions(typeValue, modelValue);
    const cols = (useTableFallback === false) ? [] : qgColsFromAnyTable(fallbackTable || QG.table || findMainTable(document));
    const colFai = cols.map(c => String(c.label || c.key || ''));
    base.faiOptions = qgUniqueStrings([].concat(mappedFai, hiddenFai, colFai));
    base.allowAllFai = ['OMM','CMM','OQC'].includes(typeValue);
    return base;
  }

  function qgCollectQueryOptionsFromDoc(doc, table){
    const typeOptions = qsa('#ms-type .ms-singlebtn', doc).map(btn => ({
      value: String(btn.getAttribute('data-value') || ''),
      label: String(btn.getAttribute('data-label') || btn.textContent || '').trim(),
    })).filter(it => it.value);

    const modelOptions = qsa('#ms-model .ms-singlebtn', doc).map(btn => ({
      value: String(btn.getAttribute('data-value') || ''),
      label: String(btn.getAttribute('data-label') || btn.textContent || '').trim(),
    })).filter(it => it.value);

    const toolOptions = qgUniqueStrings(qsa('#ms-tools input[name="tools[]"]', doc).map(el => String(el.value || '')));
    const yearOptions = qgUniqueStrings(qsa('#ms-years input[name="years[]"]', doc).map(el => String(el.value || '')));
    const monthOptions = qgUniqueStrings(qsa('#ms-months input[name="months[]"]', doc).map(el => String(el.value || '')));

    const pageDates = [];
    qsa('#ms-page .ms-datebtn', doc).forEach(btn => {
      if (btn.classList && btn.classList.contains('ms-datebtn-all')) return;
      const v = qgExtractPageDate(btn);
      if (v) pageDates.push(v);
    });

    const stateLike = {
      type: String(qgDocById(doc, 'type')?.value || ''),
      model: String(qgDocById(doc, 'model')?.value || ''),
    };
    const hiddenFai = qgReadHiddenValues(doc, '#ms-fai-hidden input[name="fai[]"]');
    const patched = qgPatchQueryOptionsForState({
      typeOptions,
      modelOptions,
      toolOptions,
      yearOptions,
      monthOptions,
      pageDates: qgUniqueStrings(pageDates),
      faiOptions: [],
      allowAllFai: false,
    }, stateLike, hiddenFai, table || findMainTable(doc), true);

    return patched;
  }

  function qgEnsureQueryStateValid(options, state){
    const opt = options || {};
    const next = Object.assign({
      type: '', model: '', tools: [], years: [], months: [], fai: [],
      pageAll: false, pageDate: '', pageDates: [], faiSearch: '',
    }, state || {});

    const pickSingle = (value, arr) => {
      const vals = Array.isArray(arr) ? arr : [];
      const v = String(value || '');
      return vals.includes(v) ? v : (vals[0] || '');
    };
    const pickMany = (list, arr) => {
      const vals = Array.isArray(arr) ? arr : [];
      const set = new Set(vals.map(v => String(v || '')));
      const out = [];
      for (const it of qgUniqueStrings(list || [])) if (set.has(it)) out.push(it);
      return out;
    };

    next.type = pickSingle(next.type, (opt.typeOptions || []).map(it => it.value));
    next.model = pickSingle(next.model, (opt.modelOptions || []).map(it => it.value));
    next.tools = pickMany(next.tools, opt.toolOptions || []);
    next.years = pickMany(next.years, opt.yearOptions || []);
    next.months = pickMany(next.months, opt.monthOptions || []);
    const faiOpts = opt.faiOptions || [];
    const rawFai = qgUniqueStrings(next.fai || []);
    if (opt.allowAllFai){
      if (rawFai.includes('__ALL__')) next.fai = ['__ALL__'];
      else {
        next.fai = rawFai.filter(v => faiOpts.includes(v));
        if (!next.fai.length){
          if (faiOpts.length) next.fai = [faiOpts[0]];
          else next.fai = ['__ALL__'];
        }
      }
    }else{
      next.fai = rawFai.filter(v => faiOpts.includes(v));
      if (!next.fai.length && faiOpts.length) next.fai = [faiOpts[0]];
    }

    next.pageDates = pickMany(next.pageDates, opt.pageDates || []);
    next.pageDate = pickSingle(next.pageDate, opt.pageDates || []);
    next.pageAll = !!next.pageAll;
    if (next.pageAll){
      next.pageDate = '';
      next.pageDates = [];
    }else if (next.pageDates.length){
      next.pageDate = '';
    }else if (!next.pageDate && Array.isArray(opt.pageDates) && opt.pageDates.length){
      next.pageDate = opt.pageDates[0];
    }

    next.faiSearch = String(next.faiSearch || '');
    return next;
  }

  function qgCollectQueryStateFromDoc(doc, options){
    const st = {
      type: String(qgDocById(doc, 'type')?.value || ''),
      model: String(qgDocById(doc, 'model')?.value || ''),
      tools: qgReadCheckedValues(doc, 'tools'),
      years: qgReadCheckedValues(doc, 'years'),
      months: qgReadCheckedValues(doc, 'months'),
      fai: qgReadHiddenValues(doc, '#ms-fai-hidden input[name="fai[]"]'),
      pageAll: String(qgDocById(doc, 'pageAllHidden')?.value || '') === '1',
      pageDate: String(qgDocById(doc, 'pageDateHidden')?.value || ''),
      pageDates: qgUniqueStrings(String(qgDocById(doc, 'pageDatesHidden')?.value || '').split(',')),
      faiSearch: String((QG.query && QG.query.state && QG.query.state.faiSearch) || ''),
    };
    return qgEnsureQueryStateValid(options, st);
  }

  function qgQuerySummary(list, allList, kind){
    const vals = qgUniqueStrings(list || []);
    const all = Array.isArray(allList) ? allList : [];
    const fmt = (v) => {
      if (kind === 'month') return String(v) + '월';
      return String(v);
    };

    if (kind === 'page'){
      if (!vals.length) return '(선택 없음)';
      if (all.length && vals.length === all.length) return 'ALL';
      if (vals.length === 1) return vals[0];
      return vals[0] + ' 외 ' + (vals.length - 1) + '개';
    }

    if (!vals.length) return '(선택 없음)';
    if (all.length && vals.length === all.length) return '전체';
    if (vals.length === 1) return fmt(vals[0]);
    return fmt(vals[0]) + ' 외 ' + (vals.length - 1) + '개';
  }

  function qgCollectOpenQueryMs(){
    return qsa('#qgQueryControls .ms.open').map(el => el.id || '').filter(Boolean);
  }

  function qgApplyInlineFaiFilter(){
    const host = qs('#qgq-ms-fai-list');
    if (!host) return;
    const kw = String((QG.query && QG.query.state && QG.query.state.faiSearch) || '').trim().toLowerCase();
    qsa('.fai-btn', host).forEach(btn => {
      const v = String(btn.getAttribute('data-value') || '').toLowerCase();
      btn.style.display = (!kw || v.includes(kw)) ? '' : 'none';
    });
  }

  function qgSameStrArray(a, b){
    const aa = Array.isArray(a) ? a.map(v => String(v == null ? '' : v)) : [];
    const bb = Array.isArray(b) ? b.map(v => String(v == null ? '' : v)) : [];
    if (aa.length !== bb.length) return false;
    for (let i=0;i<aa.length;i++){ if (aa[i] !== bb[i]) return false; }
    return true;
  }

  function qgSameOptionArray(a, b){
    const aa = Array.isArray(a) ? a : [];
    const bb = Array.isArray(b) ? b : [];
    if (aa.length !== bb.length) return false;
    for (let i=0;i<aa.length;i++){
      const av = aa[i] || {};
      const bv = bb[i] || {};
      if (String(av.value == null ? '' : av.value) !== String(bv.value == null ? '' : bv.value)) return false;
      if (String(av.label == null ? '' : av.label) !== String(bv.label == null ? '' : bv.label)) return false;
    }
    return true;
  }

  function qgCanPatchQueryControls(prevOpt, nextOpt){
    const a = prevOpt || {};
    const b = nextOpt || {};
    if (!!a.allowAllFai !== !!b.allowAllFai) return false;
    if (!qgSameOptionArray(a.typeOptions, b.typeOptions)) return false;
    if (!qgSameOptionArray(a.modelOptions, b.modelOptions)) return false;
    if (!qgSameStrArray(a.toolOptions, b.toolOptions)) return false;
    if (!qgSameStrArray(a.faiOptions, b.faiOptions)) return false;
    if (!qgSameStrArray(a.yearOptions, b.yearOptions)) return false;
    if (!qgSameStrArray(a.monthOptions, b.monthOptions)) return false;
    if (!qgSameStrArray(a.pageDates, b.pageDates)) return false;
    return true;
  }

  function qgPatchInlineQueryControls(){
    const host = qs('#qgQueryControls');
    if (!host) return false;
    const opt = (QG.query && QG.query.options) ? QG.query.options : null;
    const st = (QG.query && QG.query.state) ? QG.query.state : null;
    if (!opt || !st) return false;

    const typeSummary = ((opt.typeOptions || []).find(it => it.value === st.type) || {}).label || st.type || '(선택 없음)';
    const modelSummary = ((opt.modelOptions || []).find(it => it.value === st.model) || {}).label || st.model || '(선택 없음)';
    const toolSummary = qgQuerySummary(st.tools, opt.toolOptions, 'tool');
    const faiSummary = (st.fai.includes('__ALL__') ? 'ALL' : qgQuerySummary(st.fai, opt.faiOptions, 'fai'));
    const yearSummary = qgQuerySummary(st.years, opt.yearOptions, 'year');
    const monthSummary = qgQuerySummary(st.months, opt.monthOptions, 'month');
    let pageSummary = 'ALL';
    if (!st.pageAll){
      pageSummary = st.pageDates.length ? qgQuerySummary(st.pageDates, opt.pageDates, 'page') : (st.pageDate || '(선택 없음)');
    }

    const setText = (sel, txt)=>{ const el = qs(sel, host); if (el) el.textContent = String(txt || ''); };
    setText('#qgq-ms-type-summary', typeSummary);
    setText('#qgq-ms-model-summary', modelSummary);
    setText('#qgq-ms-tools-summary', toolSummary);
    setText('#qgq-ms-fai-summary', faiSummary);
    setText('#qgq-ms-years-summary', yearSummary);
    setText('#qgq-ms-months-summary', monthSummary);
    setText('#qgq-ms-page-summary', pageSummary);

    qsa('.ms-singlebtn[data-qgq-single="type"]', host).forEach(btn => {
      btn.classList.toggle('active', String(btn.getAttribute('data-value') || '') === String(st.type || ''));
    });
    qsa('.ms-singlebtn[data-qgq-single="model"]', host).forEach(btn => {
      btn.classList.toggle('active', String(btn.getAttribute('data-value') || '') === String(st.model || ''));
    });

    qsa('[data-qgq-multi="tools"]', host).forEach(inp => {
      const on = Array.isArray(st.tools) && st.tools.includes(String(inp.value || ''));
      inp.checked = !!on;
      const wrap = inp.closest('label, .ms-item, .ms-opt');
      if (wrap) wrap.classList.toggle('active', !!on);
    });
    qsa('[data-qgq-multi="years"]', host).forEach(inp => {
      const on = Array.isArray(st.years) && st.years.includes(String(inp.value || ''));
      inp.checked = !!on;
      const wrap = inp.closest('label, .ms-item, .ms-opt');
      if (wrap) wrap.classList.toggle('active', !!on);
    });
    qsa('[data-qgq-multi="months"]', host).forEach(inp => {
      const on = Array.isArray(st.months) && st.months.includes(String(inp.value || ''));
      inp.checked = !!on;
      const wrap = inp.closest('label, .ms-item, .ms-opt');
      if (wrap) wrap.classList.toggle('active', !!on);
    });

    qsa('.fai-btn[data-qgq-fai]', host).forEach(btn => {
      const on = Array.isArray(st.fai) && st.fai.includes(String(btn.getAttribute('data-qgq-fai') || ''));
      btn.classList.toggle('active', !!on);
    });

    const pageAllBtn = qs('[data-qgq-page-all]', host);
    if (pageAllBtn) pageAllBtn.classList.toggle('active', !!st.pageAll);
    qsa('[data-qgq-page-date]', host).forEach(btn => {
      const v = String(btn.getAttribute('data-qgq-page-date') || '');
      const on = !st.pageAll && (String(st.pageDate || '') === v || (Array.isArray(st.pageDates) && st.pageDates.includes(v)));
      btn.classList.toggle('active', !!on);
    });

    const faiSearch = qs('#qgq-ms-fai-search', host);
    if (faiSearch && String(faiSearch.value || '') !== String(st.faiSearch || '')) faiSearch.value = String(st.faiSearch || '');
    qgApplyInlineFaiFilter();
    setTimeout(qgRefreshInlineMsPositions, 0);
    return true;
  }

  function qgRenderQueryControls(openIds){
    const host = qs('#qgQueryControls');
    if (!host) return;
    const opt = (QG.query && QG.query.options) ? QG.query.options : null;
    const st = (QG.query && QG.query.state) ? QG.query.state : null;
    if (!opt || !st){ host.innerHTML = ''; return; }

    const typeSummary = ((opt.typeOptions || []).find(it => it.value === st.type) || {}).label || st.type || '(선택 없음)';
    const modelSummary = ((opt.modelOptions || []).find(it => it.value === st.model) || {}).label || st.model || '(선택 없음)';
    const toolSummary = qgQuerySummary(st.tools, opt.toolOptions, 'tool');
    const faiSummary = (st.fai.includes('__ALL__') ? 'ALL' : qgQuerySummary(st.fai, opt.faiOptions, 'fai'));
    const yearSummary = qgQuerySummary(st.years, opt.yearOptions, 'year');
    const monthSummary = qgQuerySummary(st.months, opt.monthOptions, 'month');
    let pageSummary = 'ALL';
    if (!st.pageAll){
      pageSummary = st.pageDates.length ? qgQuerySummary(st.pageDates, opt.pageDates, 'page') : (st.pageDate || '(선택 없음)');
    }

    const typeBtns = (opt.typeOptions || []).map(it => `
      <button type="button" class="ms-datebtn ms-singlebtn ${it.value === st.type ? 'active' : ''}" data-qgq-single="type" data-value="${escapeHtml(it.value)}" data-label="${escapeHtml(it.label)}">
        <span class="ms-datebtn-md">${escapeHtml(it.label)}</span>
      </button>`).join('');

    const modelBtns = (opt.modelOptions || []).map(it => `
      <button type="button" class="ms-datebtn ms-singlebtn ${it.value === st.model ? 'active' : ''}" data-qgq-single="model" data-value="${escapeHtml(it.value)}" data-label="${escapeHtml(it.label)}">
        <span class="ms-datebtn-md">${escapeHtml(it.label)}</span>
      </button>`).join('');

    const toolItems = (opt.toolOptions || []).map(v => `
      <label class="ms-item ${st.tools.includes(v) ? 'active' : ''}">
        <input type="checkbox" data-qgq-multi="tools" value="${escapeHtml(v)}" ${st.tools.includes(v) ? 'checked' : ''}>
        <span>${escapeHtml(v)}</span>
      </label>`).join('');

    const yearItems = (opt.yearOptions || []).map(v => `
      <label class="ms-datebtn ms-opt ${st.years.includes(v) ? 'active' : ''}">
        <input type="checkbox" data-qgq-multi="years" value="${escapeHtml(v)}" ${st.years.includes(v) ? 'checked' : ''}>
        <span class="ms-datebtn-md">${escapeHtml(v)}</span>
      </label>`).join('');

    const monthItems = (opt.monthOptions || []).map(v => `
      <label class="ms-datebtn ms-opt ${st.months.includes(v) ? 'active' : ''}">
        <input type="checkbox" data-qgq-multi="months" value="${escapeHtml(v)}" ${st.months.includes(v) ? 'checked' : ''}>
        <span class="ms-datebtn-md">${escapeHtml(v)}월</span>
      </label>`).join('');

    const faiItems = [];
    if (opt.allowAllFai){
      faiItems.push(`
        <button type="button" class="ms-datebtn fai-btn ${st.fai.includes('__ALL__') ? 'active' : ''}" data-qgq-fai="__ALL__">
          <span class="ms-datebtn-md">ALL</span>
        </button>`);
    }
    (opt.faiOptions || []).forEach(v => {
      faiItems.push(`
        <button type="button" class="ms-datebtn fai-btn ${st.fai.includes(v) ? 'active' : ''}" data-qgq-fai="${escapeHtml(v)}" data-value="${escapeHtml(v)}" title="${escapeHtml(v)}">
          <span class="ms-datebtn-md">${escapeHtml(v)}</span>
        </button>`);
    });

    const pageItems = [];
    pageItems.push(`
      <button type="button" class="ms-datebtn ms-datebtn-all ${st.pageAll ? 'active' : ''}" data-qgq-page-all="1"><span class="ms-datebtn-md" style="display:flex; align-items:center; justify-content:center; width:100%;">ALL</span></button>`);
    (opt.pageDates || []).forEach(v => {
      const active = (!st.pageAll && (st.pageDate === v || st.pageDates.includes(v)));
      const y = /^\d{4}-\d{2}-\d{2}$/.test(v) ? v.slice(0,4) : '';
      const md = /^\d{4}-\d{2}-\d{2}$/.test(v) ? v.slice(5) : v;
      pageItems.push(`
        <button type="button" class="ms-datebtn ${active ? 'active' : ''}" data-qgq-page-date="${escapeHtml(v)}">${y ? `<span class="ms-datebtn-y">${escapeHtml(y)}</span>` : ''}<span class="ms-datebtn-md">${escapeHtml(md)}</span></button>`);
    });

    host.innerHTML = `
      <div class="f">
        <label>측정 타입</label>
        <div class="ms ms-type ms-single" id="qgq-ms-type" data-group="type_single">
          <button type="button" class="ms-toggle"><span class="ms-summary" id="qgq-ms-type-summary">${escapeHtml(typeSummary)}</span><span class="ms-caret">▾</span></button>
          <div class="ms-panel"><div class="ms-list ms-grid-type">${typeBtns}</div></div>
        </div>
      </div>
      <div class="f">
        <label>모델</label>
        <div class="ms ms-model ms-single" id="qgq-ms-model" data-group="model_single">
          <button type="button" class="ms-toggle"><span class="ms-summary" id="qgq-ms-model-summary">${escapeHtml(modelSummary)}</span><span class="ms-caret">▾</span></button>
          <div class="ms-panel"><div class="ms-list ms-grid-model">${modelBtns}</div></div>
        </div>
      </div>
      <div class="f">
        <label>Tool (복수 선택)</label>
        <div class="ms" id="qgq-ms-tools" data-group="tools">
          <button type="button" class="ms-toggle"><span class="ms-summary" id="qgq-ms-tools-summary">${escapeHtml(toolSummary)}</span><span class="ms-caret">▾</span></button>
          <div class="ms-panel">
            <div class="ms-actions"><button type="button" class="mini" data-qgq-action="tools-all">전체</button><button type="button" class="mini" data-qgq-action="tools-none">해제</button></div>
            <div class="ms-list ms-grid-tools">${toolItems || '<div class="ms-empty" style="grid-column:1/-1; padding:10px; opacity:0.85;">항목 없음</div>'}</div>
          </div>
        </div>
      </div>
      <div class="f">
        <label>${['CMM','OQC'].includes(String(st.type || '').toUpperCase()) ? 'Point (복수 선택)' : 'FAI (복수 선택)'}</label>
        <div class="ms ms-fai" id="qgq-ms-fai" data-group="fai">
          <button type="button" class="ms-toggle"><span class="ms-summary" id="qgq-ms-fai-summary">${escapeHtml(faiSummary)}</span><span class="ms-caret">▾</span></button>
          <div class="ms-panel">
            <div class="ms-actions ms-fai-head">
              <div class="ms-fai-toolbar">
                <input type="text" id="qgq-ms-fai-search" class="ms-fai-search" placeholder="검색" autocomplete="off" value="${escapeHtml(st.faiSearch || '')}">
                <button type="button" class="mini" data-qgq-action="fai-clear">해제</button>
              </div>
            </div>
            <div class="ms-list ms-grid-fai" id="qgq-ms-fai-list">${faiItems.join('') || '<div class="ms-empty" style="grid-column:1/-1; padding:10px; opacity:0.85;">항목 없음</div>'}</div>
          </div>
        </div>
      </div>
      <div class="f">
        <label>년도 (복수 선택)</label>
        <div class="ms ms-years" id="qgq-ms-years" data-group="years">
          <button type="button" class="ms-toggle"><span class="ms-summary" id="qgq-ms-years-summary">${escapeHtml(yearSummary)}</span><span class="ms-caret">▾</span></button>
          <div class="ms-panel">
            <div class="ms-actions"><button type="button" class="mini" data-qgq-action="years-all">전체</button><button type="button" class="mini" data-qgq-action="years-none">해제</button></div>
            <div class="ms-list ms-grid-years">${yearItems || '<div class="ms-empty" style="grid-column:1/-1; padding:10px; opacity:0.85;">항목 없음</div>'}</div>
          </div>
        </div>
      </div>
      <div class="f">
        <label>월 (복수 선택)</label>
        <div class="ms" id="qgq-ms-months" data-group="months">
          <button type="button" class="ms-toggle"><span class="ms-summary" id="qgq-ms-months-summary">${escapeHtml(monthSummary)}</span><span class="ms-caret">▾</span></button>
          <div class="ms-panel">
            <div class="ms-actions"><button type="button" class="mini" data-qgq-action="months-all">전체</button><button type="button" class="mini" data-qgq-action="months-none">해제</button></div>
            <div class="ms-list ms-grid-months">${monthItems || '<div class="ms-empty" style="grid-column:1/-1; padding:10px; opacity:0.85;">항목 없음</div>'}</div>
          </div>
        </div>
      </div>
      <div class="f">
        <label>날짜 페이지</label>
        <div class="ms ms-page" id="qgq-ms-page" data-group="page_date">
          <button type="button" class="ms-toggle"><span class="ms-summary" id="qgq-ms-page-summary">${escapeHtml(pageSummary)}</span><span class="ms-caret">▾</span></button>
          <div class="ms-panel">
            <div class="ms-actions" style="display:flex; justify-content:space-between; gap:10px;">
              <button type="button" class="mini" data-qgq-action="page-prev">이전</button>
              <button type="button" class="mini" data-qgq-action="page-next">다음</button>
            </div>
            <div class="ms-list ms-grid-dates">${pageItems.join('')}</div>
          </div>
        </div>
      </div>`;

    (Array.isArray(openIds) ? openIds : []).forEach(id => {
      const el = qgDocById(host, id);
      if (el) el.classList.add('open');
    });
    qgApplyInlineFaiFilter();
    setTimeout(qgRefreshInlineMsPositions, 0);
  }

  function qgResetInlineMsPanel(msEl){
    const panel = msEl ? qs('.ms-panel', msEl) : null;
    if (!panel) return;
    panel.style.position = '';
    panel.style.left = '';
    panel.style.top = '';
    panel.style.right = '';
    panel.style.bottom = '';
    panel.style.width = '';
    panel.style.maxWidth = '';
    panel.style.maxHeight = '';
    panel.style.zIndex = '';
  }

  function qgPositionInlineMsPanel(msEl){
    if (!msEl || !msEl.classList || !msEl.classList.contains('open')) return;
    const panel = qs('.ms-panel', msEl);
    const toggle = qs('.ms-toggle', msEl);
    const overlay = qs('#qgOverlay');
    if (!panel || !toggle || !overlay) return;

    qgResetInlineMsPanel(msEl);
    const ov = overlay.getBoundingClientRect();
    const tr = toggle.getBoundingClientRect();
    if (!tr.width || !tr.height) return;

    const pad = 10;
    const belowGap = 4;
    const vwLeft = Math.max(ov.left + pad, 0 + pad);
    const vwTop = Math.max(ov.top + pad, 0 + pad);
    const vwRight = Math.min(ov.right - pad, window.innerWidth - pad);
    const vwBottom = Math.min(ov.bottom - pad, window.innerHeight - pad);

    panel.style.position = 'fixed';
    panel.style.left = Math.round(tr.left) + 'px';
    panel.style.top = Math.round(tr.bottom + belowGap) + 'px';
    panel.style.width = Math.max(Math.round(tr.width), Math.round(panel.getBoundingClientRect().width || tr.width)) + 'px';
    panel.style.maxWidth = Math.max(180, Math.round(vwRight - vwLeft)) + 'px';
    panel.style.maxHeight = Math.max(140, Math.round(vwBottom - vwTop - 20)) + 'px';
    panel.style.zIndex = '2147483647';

    // measure after fixed sizing
    let pr = panel.getBoundingClientRect();
    let width = Math.max(Math.round(pr.width || tr.width), Math.round(tr.width));
    let height = Math.round(pr.height || 0);

    let left = tr.left;
    if (left + width > vwRight) left = vwRight - width;
    if (left < vwLeft) left = vwLeft;

    let top = tr.bottom + belowGap;
    const roomBelow = vwBottom - top;
    const roomAbove = (tr.top - belowGap) - vwTop;
    if (height > roomBelow && roomAbove > roomBelow){
      top = Math.max(vwTop, tr.top - belowGap - height);
    }
    if (top + height > vwBottom) top = Math.max(vwTop, vwBottom - height);
    if (top < vwTop) top = vwTop;

    panel.style.left = Math.round(left) + 'px';
    panel.style.top = Math.round(top) + 'px';
  }

  function qgSetInlineMsHostOpen(msEl, on){
    try{
      const host = msEl && msEl.closest ? msEl.closest('.f') : null;
      if (host && host.classList) host.classList.toggle('qg-ms-host-open', !!on);
    }catch(e){}
  }

  function qgRefreshInlineMsPositions(){
    qsa('#qgQueryControls .ms.open').forEach(msEl => {
      try{
        qgSetInlineMsHostOpen(msEl, true);
        qgPositionInlineMsPanel(msEl);
      }catch(e){}
    });
  }

  function qgCloseInlineMs(except){
    qsa('#qgQueryControls .ms.open').forEach(el => {
      if (except && el === except) return;
      el.classList.remove('open');
      qgSetInlineMsHostOpen(el, false);
      qgResetInlineMsPanel(el);
    });
    if (except) qgSetInlineMsHostOpen(except, true);
  }

  function qgToggleInlineMs(msEl){
    if (!msEl) return;
    const willOpen = !msEl.classList.contains('open');
    qgCloseInlineMs(msEl);
    msEl.classList.toggle('open', willOpen);
    qgSetInlineMsHostOpen(msEl, willOpen);
    if (willOpen) qgPositionInlineMsPanel(msEl);
    else qgResetInlineMsPanel(msEl);
  }

  function qgSetQueryBusy(on, msg){
    try{ QG.query.busy = !!on; }catch(e){}
    const el = qs('#qgQueryStatus');
    if (!el) return;
    el.textContent = String(msg || '조회 중...');
    el.classList.toggle('is-on', !!on);
  }

  function qgBuildFetchUrl(state){
    const st = qgEnsureQueryStateValid(QG.query.options || {}, state || {});
    const url = new URL(window.location.href);
    const sp = url.searchParams;
    const delNames = ['type','model','tool','page_date','page_dates','page_all','run','months_present','ajax'];
    delNames.forEach(k => sp.delete(k));
    ['tools[]','years[]','months[]','fai[]'].forEach(k => sp.delete(k));

    sp.set('run', '1');
    sp.set('months_present', '1');
    if (st.type) sp.set('type', st.type);
    if (st.model) sp.set('model', st.model);
    (st.tools || []).forEach(v => sp.append('tools[]', v));
    (st.years || []).forEach(v => sp.append('years[]', v));
    (st.months || []).forEach(v => sp.append('months[]', v));
    (st.fai || []).forEach(v => sp.append('fai[]', v));
    sp.set('page_all', st.pageAll ? '1' : '0');
    if (st.pageAll){
      sp.delete('page_date');
      sp.delete('page_dates');
    }else if (st.pageDates && st.pageDates.length){
      sp.delete('page_date');
      sp.set('page_dates', st.pageDates.join(','));
    }else if (st.pageDate){
      sp.delete('page_dates');
      sp.set('page_date', st.pageDate);
    }else{
      sp.delete('page_date');
      sp.delete('page_dates');
    }
    return url.toString();
  }

  function qgPopulateFromTable(table){
    if (!table) return false;
    QG.table = table;
    const ths = qsa('thead th', QG.table);
    QG.isPivot = detectPivot(ths);
    if (!QG.isPivot) return false;

    const prevColKeys = selectedColKeysInOrder();
    const prevPrimary = String(QG.sel.primaryColKey || '');
    QG.cols = buildColsFromPivot(ths);
    if (!QG.cols.length) return false;

    try{
      const fs = qs('#qgFaiSummary');
      if (fs) fs.textContent = `대상 FAI (${QG.cols.length})`;
    }catch(e){}

    const rows = qsa('tbody tr', QG.table);
    const grouped = buildDataFromPivotRows(rows);
    if (!grouped) return false;
    QG.data = grouped;

    const validKeys = new Set(QG.cols.map(c => String(c.key || '')));
    const keep = prevColKeys.filter(k => validKeys.has(String(k || '')));
    if (!keep.length) keep.push(String(QG.cols[0].key || ''));
    QG.sel.colKeys = new Set(keep.filter(Boolean));
    QG.sel.primaryColKey = validKeys.has(prevPrimary) ? prevPrimary : (keep[0] || '');
    if (!QG.sel.primaryColKey && keep[0]) QG.sel.primaryColKey = keep[0];
    QG.sel.tools = new Set();
    QG.sel.cavities = new Set();
    QG.editingLimits = false;
    QG.editingAxis = false;
    return true;
  }

  function qgScheduleInlineQuery(immediate, forceAutoFill){
    try{ clearTimeout(QG.query.timer); }catch(e){}
    QG.query.forceAutoFill = !!forceAutoFill;
    const delay = immediate ? 20 : 180;
    QG.query.timer = setTimeout(() => {
      qgApplyInlineQuery(!!QG.query.forceAutoFill);
      QG.query.forceAutoFill = false;
    }, delay);
  }

  async function qgApplyInlineQuery(forceAutoFill){
    if (!QG.query || !QG.query.state) return;
    const reqId = ++QG.query.reqSeq;
    qgSetQueryBusy(true, '조회 중...');
    try{
      const url = qgBuildFetchUrl(QG.query.state);
      const res = await fetch(url, { credentials:'same-origin' });
      const txt = await res.text();
      if (reqId !== QG.query.reqSeq) return;

      const parser = new DOMParser();
      const doc = parser.parseFromString(txt, 'text/html');
      const table = findMainTable(doc);
      const options = qgCollectQueryOptionsFromDoc(doc, table);
      let nextState = qgCollectQueryStateFromDoc(doc, options);
      const prevOptions = QG.query.options || {};
      const openIds = qgCollectOpenQueryMs();
      nextState.faiSearch = String((QG.query.state && QG.query.state.faiSearch) || '');
      QG.query.options = qgPatchQueryOptionsForState(options, nextState, nextState.fai || [], table, true);
      QG.query.state = qgEnsureQueryStateValid(QG.query.options, nextState);
      if (qgCanPatchQueryControls(prevOptions, QG.query.options) && qs('#qgQueryControls')){
        qgPatchInlineQueryControls();
      }else{
        qgRenderQueryControls(openIds);
      }
      qgEnsureInlineFaiOptionsLoaded(QG.query.state, false);

      if (table && qgPopulateFromTable(table)){
        refresh();
      }else{
        showMsg('조회 결과 없음', 1200);
      }
    }catch(e){
      showMsg('조회 실패: ' + (e && e.message ? e.message : String(e)), 1500);
    }finally{
      if (reqId === QG.query.reqSeq) qgSetQueryBusy(false);
    }
  }

  function qgInitInlineQuery(){
    const options = qgCollectQueryOptionsFromDoc(document, QG.table);
    const state = qgCollectQueryStateFromDoc(document, options);
    QG.query.options = options;
    QG.query.state = state;
    qgRenderQueryControls();
    qgEnsureInlineFaiOptionsLoaded(state, false);
  }

  function qgEnsureInlineFaiOptionsLoaded(state, triggerQuery){
    const st = state || ((QG.query && QG.query.state) ? QG.query.state : null);
    if (!st) return;
    const typeValue = String(st.type || '').trim().toUpperCase();
    const modelValue = String(st.model || '');
    if (typeValue !== 'OQC' || !modelValue) return;
    if (qgMappedFaiOptions(typeValue, modelValue).length) return;
    if (typeof faiFetchOqcPoints !== 'function') return;

    const loadKey = typeValue + '|' + modelValue;
    if (QG.query && QG.query.faiLoadKey === loadKey) return;
    if (QG.query) QG.query.faiLoadKey = loadKey;

    try{ qgSetQueryBusy(true, 'Point 목록 불러오는 중...'); }catch(e){}
    Promise.resolve(faiFetchOqcPoints(modelValue)).then(function(){
      const cur = (QG.query && QG.query.state) ? QG.query.state : null;
      if (!cur) return;
      const curType = String(cur.type || '').trim().toUpperCase();
      const curModel = String(cur.model || '');
      if (curType !== typeValue || curModel !== modelValue) return;

      const prevOpts = (QG.query && QG.query.options) ? QG.query.options : {};
      const nextOpts = qgPatchQueryOptionsForState(prevOpts, cur, cur.fai || [], null, false);
      let nextState = Object.assign({}, cur);
      if (QG.query && QG.query.resetFaiOnModelTypeChange) nextState.fai = [];
      nextState = qgEnsureQueryStateValid(nextOpts, nextState);
      if (QG.query){
        QG.query.options = nextOpts;
        QG.query.state = nextState;
        QG.query.faiLoadKey = '';
        QG.query.resetFaiOnModelTypeChange = false;
      }
      qgRenderQueryControls(qgCollectOpenQueryMs());
      if (triggerQuery) qgScheduleInlineQuery(true, false);
    }).catch(function(){}).finally(function(){
      try{ qgSetQueryBusy(false); }catch(e){}
      if (QG.query && QG.query.faiLoadKey === loadKey) QG.query.faiLoadKey = '';
    });
  }


  function selectedColKeysInOrder(){
    const keys = [];
    for (const c of QG.cols){
      if (QG.sel.colKeys && QG.sel.colKeys.has(c.key)) keys.push(c.key);
    }
    return keys;
  }

  function setPrimaryColKey(k){
    const key = (k || '').toString();
    if (!key) return;
    if (!QG.sel.colKeys || !(QG.sel.colKeys instanceof Set)) QG.sel.colKeys = new Set();
    if (!QG.sel.colKeys.has(key)) QG.sel.colKeys.add(key);
    if (QG.sel.primaryColKey === key) return;
    QG.sel.primaryColKey = key;
    QG.editingLimits = false;
    QG.editingAxis = false;
    syncLimitInputs(true);
    syncAxisInputs(true);
    syncOocSpecInputs(true);
    syncOocLineInputs(true);
  
    try{ qgSyncCaptionUi(); }catch(e){}
  }

  function qgSeriesHasAnyData(series){
    if (!series || typeof series !== 'object') return false;
    for (const t of Object.keys(series)){
      const cavMap = series[t] || {};
      for (const c of Object.keys(cavMap)){
        const dateMap = cavMap[c] || {};
        for (const d of Object.keys(dateMap)){
          const p = dateMap[d] || null;
          if (!p) continue;
          if (Array.isArray(p.vals) && p.vals.length) return true;
          if (isFinite(p.mean) || isFinite(p.min) || isFinite(p.max)) return true;
        }
      }
    }
    return false;
  }

  function qgEnsureSelectedColsHaveData(map){
    const cols = Array.isArray(QG.cols) ? QG.cols : [];
    if (!QG.sel.colKeys || !(QG.sel.colKeys instanceof Set)) QG.sel.colKeys = new Set();

    const orderedSelected = [];
    for (const c of cols){
      const k = String(c && c.key != null ? c.key : '');
      if (k && QG.sel.colKeys.has(k)) orderedSelected.push(k);
    }

    const keep = orderedSelected.filter(k => qgSeriesHasAnyData(map && map[k]));
    if (keep.length){
      QG.sel.colKeys = new Set(keep);
      if (!keep.includes(String(QG.sel.primaryColKey || ''))) QG.sel.primaryColKey = keep[0] || '';
      if (QG.sel.primaryColKey && !QG.sel.colKeys.has(QG.sel.primaryColKey)) QG.sel.colKeys.add(QG.sel.primaryColKey);
      return;
    }

    let fallback = '';
    for (const c of cols){
      const k = String(c && c.key != null ? c.key : '');
      if (!k) continue;
      if (!map[k]) map[k] = computeSeriesForColKey(k);
      if (qgSeriesHasAnyData(map[k])){ fallback = k; break; }
    }

    QG.sel.colKeys = fallback ? new Set([fallback]) : new Set();
    QG.sel.primaryColKey = fallback;
  }

  function computeSeriesForSelectedCols(){
    const keys = selectedColKeysInOrder();
  try{ QG._visibleColKeys = Array.isArray(keys) ? keys.slice() : []; }catch(e){}
  try{ qgUpdateToolbarSplitOverlays(); }catch(e){}
    const map = Object.create(null);

    for (const k of keys){
      const s = computeSeriesForColKey(k);
      if (s) map[k] = s;
    }

    qgEnsureSelectedColsHaveData(map);

    QG.seriesByCol = map;
    QG.series = map[QG.sel.primaryColKey] || null;
  }

  function computeSeriesForColKey(colKey){
    const col = QG.cols.find(c => c.key === colKey) || null;
    if (!col) return null;

    const out = Object.create(null);
    const ensure = (o,k)=> (o[k]||(o[k]=Object.create(null)));

    for (const tool of Object.keys(QG.data||{})){
      const toolO = ensure(out, tool);
      for (const cav of Object.keys(QG.data[tool]||{})){
        const cavO = ensure(toolO, cav);
        for (const date of Object.keys(QG.data[tool][cav]||{})){
          const bucket = QG.data[tool][cav][date];
          const rows = bucket.__rows || [];
          const vals = [];
          for (const r of rows){
            const td = r.tds[col.idx];
            const v = num(td ? td.textContent : '');
            if (v !== null) vals.push(v);
          }
          if (!vals.length) continue;
          const mn = Math.min.apply(null, vals);
          const mx = Math.max.apply(null, vals);
          const mean = vals.reduce((a,b)=>a+b,0)/vals.length;
          cavO[date] = { vals, min: mn, max: mx, mean };
        }
      }
    }
    return out;
  }

  function rebuildFacets(){
    const tools = new Set();
    const cavs = new Set();

    const seriesMap = (QG.seriesByCol && typeof QG.seriesByCol === 'object') ? QG.seriesByCol : null;
    const selectedKeys = selectedColKeysInOrder();
    const maps = [];
    if (seriesMap){
      for (const k of selectedKeys){
        const s = seriesMap[k];
        if (s && typeof s === 'object') maps.push(s);
      }
    }
    if (!maps.length && QG.series && typeof QG.series === 'object') maps.push(QG.series);

    for (const s of maps){
      for (const t of Object.keys(s||{})){
        tools.add(t);
        for (const c of Object.keys((s && s[t])||{})) cavs.add(c);
      }
    }

    QG.tools = Array.from(tools).sort((a,b)=>a.localeCompare(b, undefined, {numeric:true, sensitivity:'base'}));
    QG.cavities = Array.from(cavs).sort(sortCavity);

    // Update group titles with counts (Tool (15), Cavity (4) style)
    const ts = qs('#qgToolSummary');
    if (ts) ts.textContent = `Tool (${QG.tools.length})`;
    // Tool group drag handle -> right-side dropdock (중첩/색상/크기/구간)
    if (ts) { try{ qgBindVarDockDragHandle(ts, 'tool', 'Tool'); }catch(e){} }
    const cs = qs('#qgCavitySummary');
    if (cs) cs.textContent = `Cavity (${QG.cavities.length})`;
    // Cavity group drag handle -> right-side dropdock (중첩/색상/크기/구간)
    if (cs) { try{ qgBindVarDockDragHandle(cs, 'cavity', 'Cavity'); }catch(e){} }

    const prevTools = new Set(QG.sel.tools || []);
    const prevCavs = new Set(QG.sel.cavities || []);
    QG.sel.tools = new Set(Array.from(prevTools).filter(t => tools.has(t)));
    QG.sel.cavities = new Set(Array.from(prevCavs).filter(c => cavs.has(c)));

    if (QG.sel.tools.size === 0) QG.tools.forEach(t=>QG.sel.tools.add(t));
    if (QG.sel.cavities.size === 0) QG.cavities.forEach(c=>QG.sel.cavities.add(c));
  }

  function build(){
    const table = findMainTable(document);
    if (!table){
      showMsg('테이블을 찾지 못했습니다. 먼저 조회를 눌러주세요.');
      QG.built = true;
      return;
    }
    if (!qgPopulateFromTable(table)){
      showMsg('현재 화면은 Pivot 테이블이 아닙니다. (그래프 빌더는 OMM/CMM/AOI Pivot에서 동작)');
      QG.built = true;
      return;
    }

    bindUi();
    qgInitInlineQuery();

    QG.built = true;
    refresh();
  }

  

  function qgRenderPlotElemPanel(){
    const host = qs('#qgPlotElemPanel');
    if (!host) return;

    // Only show when the caption tool is enabled (JMP-like).
    if (!QG.captionGlobalEnabled){
      host.innerHTML = '';
      return;
    }

    const cols = QG.cols || [];
    const sel = (QG.sel && QG.sel.colKeys && QG.sel.colKeys instanceof Set) ? QG.sel.colKeys : null;

    // Render caption groups for each selected FAI (graph) in the current column order.
    const keys = [];
    const seen = new Set();
    if (sel && sel.size){
      for (const c of cols){
        if (sel.has(c.key) && !seen.has(c.key)){
          keys.push(c.key);
          seen.add(c.key);
        }
      }
      for (const k of sel){
        const kk = String(k||'');
        if (kk && !seen.has(kk)){
          keys.push(kk);
          seen.add(kk);
        }
      }
    }else{
      for (const c of cols){
        if (!seen.has(c.key)){
          keys.push(c.key);
          seen.add(c.key);
        }
      }
    }

    if (!keys.length){
      host.innerHTML = '';
      QG.captionGlobalEnabled = false;
      return;
    }

    // Only render caption groups for enabled FAI (avoid "one enabled -> show all groups" bug)
    const enabledKeys = keys.filter(k=>{
      try{ return !!qgEnsureCaptionState(k).enabled; }catch(e){ return false; }
    });

    if (!enabledKeys.length){
      host.innerHTML = '';
      QG.captionGlobalEnabled = false;
      return;
    }

    const mkOpts = (arr, cur)=> (arr||[]).map(o=>{
      const v = String(o.v);
      const t = String(o.t);
      return `<option value="${escapeHtml(v)}"${(String(cur)===v)?' selected':''}>${escapeHtml(t)}</option>`;
    }).join('');

    const htmlParts = [];
    for (const k of enabledKeys){
      const cap = qgEnsureCaptionState(k);
      const title = qgGetDisplayLabel(k);
      const stats = qgNormalizeCaptionStatsList(cap.stats);
      cap.stats = stats;

	      const posMode0 = String(cap.posMode||'');
      const hideXY = (posMode0 === 'axis_table');
      const yPosOpts = (posMode0 === 'factor') ? QG_CAPTION_YPOS_FACTOR : QG_CAPTION_YPOS;
      const xyRowsHtml = hideXY ? '' : `
            <div class="qg-row" style="margin-bottom:8px;">
              <div style="width:92px; flex:0 0 auto;">X 위치</div>
              <select class="qg-select qgCapXSel" data-colkey="${escapeHtml(String(k))}">${mkOpts(QG_CAPTION_XPOS, cap.xPos)}</select>
            </div>

            <div class="qg-row">
              <div style="width:92px; flex:0 0 auto;">Y 위치</div>
              <select class="qg-select qgCapYSel" data-colkey="${escapeHtml(String(k))}">${mkOpts(yPosOpts, cap.yPos)}</select>
            </div>
      `;
const statRows = stats.map((sv, i)=>{
        return `
          <div class="qg-row" style="margin-bottom:8px;">
            <div style="width:92px; flex:0 0 auto;">요약 통계량</div>
            <select class="qg-select qgCapStatSel" data-colkey="${escapeHtml(String(k))}" data-idx="${i}">${mkOpts(QG_CAPTION_STATS, sv)}</select>
          </div>
        `;
      }).join('');

      htmlParts.push(`
        <details class="qg-box" data-colkey="${escapeHtml(String(k))}"${(cap.uiOpen ? ' open' : '')} style="margin:0 0 10px 0;">
          <summary>캡션 상자[${escapeHtml(title)}]</summary>
          <div class="qg-box-body">
            ${statRows}

            <div class="qg-row" style="margin-bottom:8px;">
              <div style="width:92px; flex:0 0 auto;">숫자 형식</div>
              <select class="qg-select qgCapNumFmtSel" data-colkey="${escapeHtml(String(k))}">${mkOpts(QG_CAPTION_NUMFMT, cap.numFmt)}</select>
            </div>

            <div class="qg-row" style="margin-bottom:8px;">
              <div style="width:92px; flex:0 0 auto;">위치</div>
              <select class="qg-select qgCapPosSel" data-colkey="${escapeHtml(String(k))}">${mkOpts(QG_CAPTION_POSMODE, cap.posMode)}</select>
            </div>

            ${xyRowsHtml}
          </div>
        </details>
      `);
    }

    host.innerHTML = htmlParts.join('');

    // Delegate changes (re-render to support JMP-like "keep adding" behavior)
    if (!host._qgCapDelegated){
      host._qgCapDelegated = true;

      // Remember open/close state per FAI (details default: closed)
      host.addEventListener('toggle', (ev)=>{
        const t = ev && ev.target;
        try{
          if (!t || String(t.tagName||'').toUpperCase() !== 'DETAILS') return;
          const colKey = (t.dataset && t.dataset.colkey) ? String(t.dataset.colkey) : '';
          if (!colKey) return;
          const cap = qgEnsureCaptionState(colKey);
          cap.uiOpen = !!t.open;
        }catch(e){}
      }, true);

      host.addEventListener('change', (ev)=>{
        const t = ev.target;
        if (!t || !t.classList) return;
        // Summary statistics (multi)
        if (t.classList.contains('qgCapStatSel')){
          const colKey = (t.dataset && t.dataset.colkey) ? String(t.dataset.colkey) : '';
          const idx = (t.dataset && t.dataset.idx) ? parseInt(String(t.dataset.idx), 10) : NaN;
          if (!colKey || !isFinite(idx)) return;
          const cap = qgEnsureCaptionState(colKey);
          let a = Array.isArray(cap.stats) ? cap.stats.slice() : ['mean','none'];
          a[idx] = String(t.value || 'none');
          cap.stats = qgNormalizeCaptionStatsList(a);


          // If the user set the last 'none' to something, we need to show a new empty row
          qgRenderPlotElemPanel();
          renderGrid();
          return;
        }

        // X/Y positions
        if (t.classList.contains('qgCapXSel') || t.classList.contains('qgCapYSel')){
          const colKey = (t.dataset && t.dataset.colkey) ? String(t.dataset.colkey) : '';
          if (!colKey) return;
          const cap = qgEnsureCaptionState(colKey);
          if (t.classList.contains('qgCapXSel')) cap.xPos = String(t.value || 'right');
          if (t.classList.contains('qgCapYSel')) cap.yPos = String(t.value || 'top');
          renderGrid();
          return;
        }

        // Location mode
        if (t.classList.contains('qgCapPosSel')){
          const colKey = (t.dataset && t.dataset.colkey) ? String(t.dataset.colkey) : '';
          if (!colKey) return;
          const cap = qgEnsureCaptionState(colKey);
          const _newMode = String(t.value || 'graph');
          cap.posMode = _newMode;
          // If leaving factor mode, drop factor-only in-box Y options to a safe default
          if (_newMode !== 'factor'){
            const yp = String(cap.yPos || '');
            if (yp === 'box_top' || yp === 'box_bottom') cap.yPos = 'top';
          }
          qgRenderPlotElemPanel();
          renderGrid();
          return;
        }

        // Number format
        if (t.classList.contains('qgCapNumFmtSel')){
          const colKey = (t.dataset && t.dataset.colkey) ? String(t.dataset.colkey) : '';
          if (!colKey) return;
          const cap = qgEnsureCaptionState(colKey);
          cap.numFmt = String(t.value || 'auto');
          renderGrid();
          return;
        }
      }, { passive:true });
    }
  }

  function qgSyncCaptionUi(){
    try{ qgUpdateToolbarSplitOverlays(); }catch(e){}
    qgRenderPlotElemPanel();
  }

  // Fallback: resolve the "current" FAI key from the left list DOM.
  function qgResolvePrimaryColKeyFromDom(){
    try{
      const root = qs('#qgFaiList');
      if (!root) return '';
      const act = root.querySelector('.qg-item.active');
      const k = act && act.dataset ? String(act.dataset.value || '') : '';
      return k || '';
    }catch(e){
      return '';
    }
  }


// Toolbar split overlay + Ctrl/⌘ partial apply (JMP-like)
function qgVisibleColKeysForToolbar(maxN){
  const lim = (maxN === undefined || maxN === null) ? Infinity : Math.max(1, Number(maxN) || 1);
  const a = Array.isArray(QG._visibleColKeys) ? QG._visibleColKeys : [];
  const out = [];
  const seen = new Set();
  for (const k of a){
    const kk = String(k || '');
    if (!kk || seen.has(kk)) continue;
    out.push(kk); seen.add(kk);
    if (out.length >= lim) break;
  }
  if (!out.length){
    let k = '';
    try{ k = qgResolvePrimaryColKey(); }catch(e){}
    if (!k) try{ k = qgResolvePrimaryColKeyFromDom(); }catch(e){}
    if (k) out.push(String(k));
  }
  return out;
}

function qgPickColKeyFromToolBtnEvent(ev, btn){
  const keys = qgVisibleColKeysForToolbar();
  if (keys.length <= 1) return (keys[0] || '');
  try{
    const r = btn.getBoundingClientRect();
    const y = (ev && (ev.clientY !== undefined)) ? (ev.clientY - r.top) : 0;
    const h = Math.max(1, r.height || 1);
    let idx = Math.floor((y / h) * keys.length);
    if (idx < 0) idx = 0;
    if (idx >= keys.length) idx = keys.length - 1;
    return keys[idx] || keys[0] || '';
  }catch(e){
    return keys[0] || '';
  }
}

function qgEnsureToolBtnSplitOverlay(btn){
  if (!btn) return null;
  try{ btn.classList.add('qg-has-split'); }catch(e){}
  let ov = null;
  try{ ov = btn.querySelector('.qg-splitOverlay'); }catch(e){}
  if (!ov){
    try{
      ov = document.createElement('div');
      ov.className = 'qg-splitOverlay';
      btn.appendChild(ov);
    }catch(e){ ov = null; }
  }
  return ov;
}

function qgUpdateToolbarSplitOverlays(){
  const keys = qgVisibleColKeysForToolbar();
  const n = Math.max(1, keys.length);

  const btns = qsa('.qg-toolbar .qg-toolbtn[data-qg-elem]');
  for (const b of btns){
    const elem = (b.dataset && b.dataset.qgElem) ? String(b.dataset.qgElem) : '';
    if (!elem) continue;
    const ov = qgEnsureToolBtnSplitOverlay(b);
    if (!ov) continue;
    ov.innerHTML = '';
    let any = false;
    for (let i=0;i<n;i++){
      const seg = document.createElement('div');
      seg.className = 'qg-splitSeg';
      seg.style.top = (i*100/n) + '%';
      seg.style.height = (100/n) + '%';
      const st = qgGetPlotElemsForColKey(keys[i]);
      const on = !!st[elem];
      if (on){ any = true; seg.classList.add('is-on'); }
      ov.appendChild(seg);
    }
    try{ ov.style.display = (keys.length > 1 || any) ? 'block' : 'none'; }catch(e){}
    try{ b.classList.toggle('is-active', any); }catch(e){}
  }

  try{
    const capBtn = qs('.qg-toolbar .qg-toolbtn[data-qg-aux="caption"]');
    if (capBtn){
      const ov = qgEnsureToolBtnSplitOverlay(capBtn);
      if (ov){
        ov.innerHTML = '';
        let any = false;
        for (let i=0;i<n;i++){
          const seg = document.createElement('div');
          seg.className = 'qg-splitSeg';
          seg.style.top = (i*100/n) + '%';
          seg.style.height = (100/n) + '%';
          const cap = qgEnsureCaptionState(keys[i]);
          const on = !!cap.enabled;
          if (on){ any = true; seg.classList.add('is-on'); }
          ov.appendChild(seg);
        }
        try{ ov.style.display = (keys.length > 1 || any) ? 'block' : 'none'; }catch(e){}
        try{ capBtn.classList.toggle('is-active', any); }catch(e){}
      }
    }
  }catch(e){}
}

// Drag-apply preview (JMP-like scope highlight while dragging toolbar items)
// - Uses pointer-based dragging (no HTML5 dragstart), so it works reliably across browsers/shells.
// - Shows a blue translucent preview per Tool×Cavity panel (JMP-like "cells").
// - Drop on header => apply to ALL FAI (global). Drop on a specific FAI row => apply to that FAI only.
function qgEnsureDropPreviewEl(){
  let el = null;
  try{ el = qs('#qgDropPreview'); }catch(e){ el = null; }
  if (!el){
    try{
      const ov = qs('#qgOverlay');
      if (!ov) return null;
      el = document.createElement('div');
      el.id = 'qgDropPreview';
      el.setAttribute('aria-hidden','true');
      ov.appendChild(el);
    }catch(e){ el = null; }
  }
  return el;
}

function qgEnsureDropPreviewCells(n){
  const root = qgEnsureDropPreviewEl();
  if (!root) return [];
  if (!root._qgCells) root._qgCells = [];
  const cells = root._qgCells;

  // Grow
  while (cells.length < n){
    const d = document.createElement('div');
    d.className = 'qgDropPreviewCell';
    root.appendChild(d);
    cells.push(d);
  }
  // Hide extras (kept for reuse)
  for (let i=n; i<cells.length; i++){
    try{ cells[i].style.display = 'none'; }catch(e){}
  }
  return cells;
}

function qgShowDropPreviewRects(rects, flash){
  const root = qgEnsureDropPreviewEl();
  if (!root) return;

  const list = Array.isArray(rects) ? rects.filter(Boolean) : [];
  const cells = qgEnsureDropPreviewCells(list.length);

  if (!list.length){
    qgHideDropPreview();
    return;
  }

  for (let i=0; i<list.length; i++){
    const r = list[i];
    const w = Math.max(0, (r.right - r.left));
    const h = Math.max(0, (r.bottom - r.top));
    const c = cells[i];
    if (!c || w < 2 || h < 2) continue;

    try{
      c.style.display = 'block';
      c.style.left = r.left + 'px';
      c.style.top = r.top + 'px';
      c.style.width = w + 'px';
      c.style.height = h + 'px';
    }catch(e){}
  }

  try{
    root.classList.add('is-on');
    if (flash) root.classList.add('is-flash');
    root.style.display = 'block';
    root.setAttribute('aria-hidden','false');
  }catch(e){}

  if (flash){
    const t = root._qgFlashTimer;
    if (t) try{ clearTimeout(t); }catch(e){}
    root._qgFlashTimer = setTimeout(()=>{
      try{ root.classList.remove('is-flash'); }catch(e){}
    }, 220);
  }
}

function qgHideDropPreview(){
  const root = qgEnsureDropPreviewEl();
  if (!root) return;
  try{
    root.classList.remove('is-on','is-flash');
    root.style.display = 'none';
    root.setAttribute('aria-hidden','true');
  }catch(e){}
  try{
    const cells = root._qgCells || [];
    for (const c of cells){
      try{ c.style.display = 'none'; }catch(e){}
    }
  }catch(e){}
}

function qgGetPanelCountForSvg(svg){
  if (!svg) return 0;
  let nP = 0;
  try{
    const group = svg.closest ? svg.closest('.qg-tool-group') : null;
    if (!group) return 0;
    const cavs = group.querySelectorAll ? group.querySelectorAll('.qg-tophead-cav') : null;
    nP = cavs ? cavs.length : 0;
  }catch(e){ nP = 0; }
  return nP;
}

function qgPanelRectsFromSvg(svg){
  if (!svg || !svg.getBoundingClientRect) return [];
  const nP = qgGetPanelCountForSvg(svg);
  if (!nP) return [];

  const r0 = svg.getBoundingClientRect();
  const W = 1200; // fixed viewBox width (drawMatrixSvg)
  const padL = 62, padR = 14; // must match drawMatrixSvg()
  const innerW = W - padL - padR;
  const panelW = innerW / nP;

  // Inset so each cavity gets its own rounded box (avoid overlap with rounded corners)
  const inset = 4;

  // Determine viewBox height (rowSvgH) to exclude date-label area on the last row
  let H = 320;
  try{
    if (svg.viewBox && svg.viewBox.baseVal && svg.viewBox.baseVal.height){
      H = svg.viewBox.baseVal.height;
    }else{
      const vb = String(svg.getAttribute('viewBox')||'').trim().split(/\s+/);
      if (vb.length === 4){
        const hh = parseFloat(vb[3]);
        if (isFinite(hh) && hh > 0) H = hh;
      }
    }
  }catch(e){}

  // Cache whether this svg includes rotated date labels (rotate(-45 ...)), which means padB=56 exists.
  let hasXLabels = false;
  try{
    if (svg._qgHasXLabels !== undefined){
      hasXLabels = !!svg._qgHasXLabels;
    }else{
      const t = svg.querySelector && svg.querySelector('text[transform^="rotate(-45"]');
      hasXLabels = !!t;
      svg._qgHasXLabels = hasXLabels;
    }
  }catch(e){ hasXLabels = false; }

  const padB = hasXLabels ? 56 : 0;

  const sx = (r0.width / W);
  const sy = (r0.height / H);

  const left0 = r0.left + padL * sx;
  const top0 = r0.top;

  // Exclude the bottom date-label band (padB) from the preview scope
  const bottomPlot = Math.min(r0.bottom, (r0.top + (H - padB) * sy));

  const rects = [];
  for (let i=0; i<nP; i++){
    const x1 = left0 + (i * panelW) * sx;
    const x2 = left0 + ((i+1) * panelW) * sx;
    rects.push({
      left: Math.round(x1 + inset),
      top: Math.round(top0 + inset),
      right: Math.round(x2 - inset),
      bottom: Math.round(bottomPlot - inset)
    });
  }
  return rects;
}

function qgPickDropScopeAt(x, y){
  let el = null;
  try{ el = document.elementFromPoint(x, y); }catch(e){ el = null; }
  if (!el) return null;
  if (!el.closest || !el.closest('#qgGrid')) return null;

  // Header scope: any top header area means apply to ALL (global)
  const head = el.closest('.qg-tophead');
  if (head){
    const svgs = qsa('#qgGrid svg.qg-svg');
    const rects = [];
    for (const s of svgs){
      try{ rects.push(...qgPanelRectsFromSvg(s)); }catch(e){}
    }
    if (rects.length) return { type:'all', rects };
  }

  // Row scope: FAI row (apply to that FAI only)
  const row = el.closest('.qg-fai-row');
  if (row){
    const colKey = (row.dataset && row.dataset.colKey) ? String(row.dataset.colKey) : '';
    const svg = (row.querySelector && row.querySelector('svg.qg-svg')) ? row.querySelector('svg.qg-svg') : null;
    const rects = svg ? qgPanelRectsFromSvg(svg) : [];
    if (colKey && rects.length) return { type:'row', colKey, rects };
  }

  return null;
}

function qgApplyDragAction(scope){
  const act = QG._dragApplyAct;
  if (!act || !scope) return;

  // Plot element drag apply
  if (act.kind === 'plotElem'){
    const k = String(act.elem || '');
    if (!k) return;

    if (scope.type === 'row' && scope.colKey){
      // Row apply behaves like Ctrl/⌘ segment click (toggle for that FAI only)
      const cur = qgGetPlotElemsForColKey(scope.colKey)[k];
      qgSetPlotElemForColKey(scope.colKey, k, !cur);
    }else{
      // Header/global apply: toggle the element globally (do NOT turn others off), then clear overrides
      const st = qgEnsurePlotElems();
      st[k] = !st[k];
      if (!st.points && !st.line && !st.box) st.points = true;
      qgClearPlotElemOverrides();
    }
    try{ qgUpdateToolbarSplitOverlays(); }catch(e){}
    try{ renderGrid(); }catch(e){}
    return;
  }

  // Caption drag apply
  if (act.kind === 'caption'){
    const dispKeys = selectedColKeysInOrder();
    if (!dispKeys || !dispKeys.length) return;

    if (scope.type === 'row' && scope.colKey){
      const cap = qgEnsureCaptionState(scope.colKey);
      cap.enabled = !cap.enabled;
      if (cap.enabled){
        if (!Array.isArray(cap.stats) || !cap.stats.length) cap.stats = ['mean','none'];
        if (!cap.xPos) cap.xPos = 'right';
        if (!cap.yPos) cap.yPos = 'top';
      }
      let any = false;
      for (const kk of dispKeys){ if (qgEnsureCaptionState(kk).enabled){ any = true; break; } }
      QG.captionGlobalEnabled = any;
    }else{
      let all = true;
      for (const kk of dispKeys){ if (!qgEnsureCaptionState(kk).enabled){ all = false; break; } }
      const next = !all;
      QG.captionGlobalEnabled = next;
      for (const kk of dispKeys){
        const cap = qgEnsureCaptionState(kk);
        cap.enabled = next;
        if (next){
          if (!Array.isArray(cap.stats) || !cap.stats.length) cap.stats = ['mean','none'];
          if (!cap.xPos) cap.xPos = 'right';
          if (!cap.yPos) cap.yPos = 'top';
        }
      }
    }
    try{ qgSyncCaptionUi(); }catch(e){}
    try{ renderGrid(); }catch(e){}
    return;
  }
}

function qgBindDragApply(){
  if (QG._dragApplyBound) return;
  QG._dragApplyBound = true;

  const btns = qsa('.qg-toolbar .qg-toolbtn[data-qg-elem], .qg-toolbar .qg-toolbtn[data-qg-aux=\"caption\"]');
  for (const b of btns){
    if (b._qgDragApplyPtr) continue;
    b._qgDragApplyPtr = true;

    b.addEventListener('pointerdown', (ev)=>{
      try{
        if (!ev || (ev.button !== undefined && ev.button !== 0)) return;
        if (b.disabled) return;
      }catch(e){}

      const elem = (b.dataset && b.dataset.qgElem) ? String(b.dataset.qgElem) : '';
      const aux  = (b.dataset && b.dataset.qgAux) ? String(b.dataset.qgAux) : '';
      let act = null;
      if (elem) act = { kind:'plotElem', elem };
      else if (aux === 'caption') act = { kind:'caption' };
      if (!act) return;

      // Track pointer drag
      const pid = ev.pointerId;
      QG._dragApplyAct = act;
      QG._dragApplyPtr = {
        pid,
        btn: b,
        startX: ev.clientX,
        startY: ev.clientY,
        dragging: false,
        scope: null
      };

      try{ b.setPointerCapture && b.setPointerCapture(pid); }catch(e){}

      const onMove = (mv)=>{
        const st = QG._dragApplyPtr;
        if (!st || st.pid !== mv.pointerId) return;

        const dx = mv.clientX - st.startX;
        const dy = mv.clientY - st.startY;
        const dist = Math.sqrt(dx*dx + dy*dy);

        if (!st.dragging){
          if (dist < 6) return;
          st.dragging = true;
          // While dragging, prevent accidental clicks/selection
          try{ mv.preventDefault(); mv.stopPropagation(); }catch(e){}
        }

        const scope = qgPickDropScopeAt(mv.clientX, mv.clientY);
        st.scope = scope;
        if (scope && scope.rects && scope.rects.length){
          qgShowDropPreviewRects(scope.rects, false);
        }else{
          qgHideDropPreview();
        }
      };

      const onUp = (up)=>{
        const st = QG._dragApplyPtr;
        if (!st || st.pid !== up.pointerId) return;

        // Clean listeners
        try{ document.removeEventListener('pointermove', onMove, true); }catch(e){}
        try{ document.removeEventListener('pointerup', onUp, true); }catch(e){}
        try{ document.removeEventListener('pointercancel', onUp, true); }catch(e){}

        const didDrag = !!st.dragging;
        const scope = st.scope;

        QG._dragApplyPtr = null;

        if (didDrag){
          try{ up.preventDefault(); up.stopPropagation(); }catch(e){}
          if (scope && scope.rects && scope.rects.length){
            qgShowDropPreviewRects(scope.rects, true);
            qgApplyDragAction(scope);
          }
          setTimeout(()=>{ qgHideDropPreview(); }, 260);

          // Suppress the following click that browsers often fire after a drag.
          QG._dragApplySuppressClickUntil = Date.now() + 300;
        }else{
          qgHideDropPreview();
        }
      };

      // Attach listeners (capture) so we still get events when leaving the button
      document.addEventListener('pointermove', onMove, true);
      document.addEventListener('pointerup', onUp, true);
      document.addEventListener('pointercancel', onUp, true);
    }, { passive:false });
  }

  // Global click suppressor (only when a drag-apply happened)
  if (!QG._dragApplyClickGuard){
    QG._dragApplyClickGuard = true;
    document.addEventListener('click', (ev)=>{
      try{
        const until = QG._dragApplySuppressClickUntil || 0;
        if (until && Date.now() < until){
          // Only suppress clicks coming from toolbar buttons
          const t = ev.target;
          const btn = t && t.closest ? t.closest('.qg-toolbar .qg-toolbtn') : null;
          if (btn){
            ev.preventDefault();
            ev.stopPropagation();
            ev.stopImmediatePropagation && ev.stopImmediatePropagation();
          }
        }
      }catch(e){}
    }, true);
  }
}

/* =========================================================
 * Variable Drag -> Dropdock (Overlay/Color/Size/Interval)
 * - Drag from Tool facet list items (left)
 * - Drop into right-side dropdock boxes (중첩/색상/크기/구간)
 * - For now: stores assignment + shows 'Tool' inside the dropdock box
 * ========================================================= */
function qgEnsureDockState(){
  if (!QG.dockVars) QG.dockVars = { overlay:null, color:null, shape:null, size:null, interval:null };
  if (QG.dockVars.shape === undefined) QG.dockVars.shape = null;
}

function qgDockVarLabel(varKey){
  const v = (varKey == null) ? '' : String(varKey);
  return (v === 'tool') ? 'Tool' : ((v === 'cavity') ? 'Cavity' : v);
}

function qgDockItemLabel(dockKey, varKey){
  const dk = (dockKey == null) ? '' : String(dockKey);
  const base = (dk === 'overlay') ? '중첩' : ((dk === 'color') ? '색상' : ((dk === 'size') ? '크기' : ((dk === 'interval') ? '구간' : dk)));
  const vv = (varKey == null) ? '' : String(varKey);
  return vv ? (base + ' : ' + qgDockVarLabel(vv)) : base;
}

function qgRenderDockVars(){
  qgEnsureDockState();
  const items = qsa('.qg-dropdock-item[data-dock]');
  for (const el of items){
    const k = (el.dataset && el.dataset.dock) ? String(el.dataset.dock) : '';
    const v = (k && QG.dockVars) ? (QG.dockVars[k] || null) : null;
    const span = el.querySelector('.qg-dropdock-var');
    if (v){
      el.classList.add('has-var');
      if (span) span.textContent = ' : ' + qgDockVarLabel(v);
      try{ el.title = qgDockItemLabel(k, v); }catch(e){}
      try{ el.style.cursor = 'grab'; }catch(e){}
    }else{
      el.classList.remove('has-var');
      if (span) span.textContent = '';
      try{ el.title = qgDockItemLabel(k, null); }catch(e){}
      try{ el.style.cursor = 'default'; }catch(e){}
    }
  }
  try{ qgBindDockItemInteractions(); }catch(e){}
}

function qgClearDockHover(){
  qsa('.qg-dropdock-item.hover').forEach(el=>el.classList.remove('hover'));
}

function qgPickDockItemAt(x, y){
  const items = qsa('.qg-dropdock-item[data-dock]');
  for (const el of items){
    const r = el.getBoundingClientRect();
    if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom){
      return el;
    }
  }
  return null;
}

function qgSetDockVar(dockKey, varKey){
  qgEnsureDockState();
  const dk = (dockKey || '').toString();
  const vk = (varKey || '').toString();
  if (!dk || !vk) return;

  // Toggle off if same dock already has it
  if (QG.dockVars[dk] === vk){
    QG.dockVars[dk] = null;
  }else{
    // Ensure the variable is not assigned to multiple docks simultaneously
    for (const k of Object.keys(QG.dockVars)){
      if (QG.dockVars[k] === vk) QG.dockVars[k] = null;
    }
    QG.dockVars[dk] = vk;
  }

  qgRenderDockVars();
  try{ renderLegend(); }catch(e){}
  try{ renderGrid(); }catch(e){}
}

function qgClearDockVar(dockKey){
  qgEnsureDockState();
  const dk = (dockKey || '').toString();
  if (!dk) return;
  if (!Object.prototype.hasOwnProperty.call(QG.dockVars || {}, dk)) return;
  if (!QG.dockVars[dk]) return;
  QG.dockVars[dk] = null;
  qgRenderDockVars();
  try{ renderLegend(); }catch(e){}
  try{ renderGrid(); }catch(e){}
}

function qgMoveDockVar(fromDockKey, toDockKey){
  qgEnsureDockState();
  const from = (fromDockKey || '').toString();
  const to = (toDockKey || '').toString();
  if (!from || !to || from === to) return;
  const fv = QG.dockVars ? (QG.dockVars[from] || null) : null;
  const tv = QG.dockVars ? (QG.dockVars[to] || null) : null;
  if (!fv) return;
  QG.dockVars[to] = fv;
  QG.dockVars[from] = tv || null;
  qgRenderDockVars();
  try{ renderLegend(); }catch(e){}
  try{ renderGrid(); }catch(e){}
}

function qgBindDockItemInteractions(){
  const items = qsa('.qg-dropdock-item[data-dock]');
  for (const el of items){
    if (!el || el._qgDockInteractiveBound) continue;
    el._qgDockInteractiveBound = true;

    el.addEventListener('contextmenu', (ev)=>{
      const dockKey = (el.dataset && el.dataset.dock) ? String(el.dataset.dock) : '';
      const curVar = dockKey && QG.dockVars ? (QG.dockVars[dockKey] || null) : null;
      try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
      if (!dockKey || !curVar){
        try{ qgHideCtxMenu(); }catch(e){}
        return false;
      }
      try{
        qgShowCtxMenu(ev.clientX, ev.clientY, [
          { label:'제거', onClick:()=>{ try{ qgClearDockVar(dockKey); }catch(e){} } }
        ]);
      }catch(e){}
      return false;
    }, true);

    el.addEventListener('pointerdown', (ev)=>{
      try{
        if (!ev || (ev.button !== undefined && ev.button !== 0)) return;
      }catch(e){}
      const dockKey = (el.dataset && el.dataset.dock) ? String(el.dataset.dock) : '';
      const curVar = dockKey && QG.dockVars ? (QG.dockVars[dockKey] || null) : null;
      if (!dockKey || !curVar) return;

      const pid = ev.pointerId;
      const st = {
        pid,
        src: el,
        dockKey,
        varKey: String(curVar),
        label: qgDockItemLabel(dockKey, curVar),
        startX: ev.clientX,
        startY: ev.clientY,
        dragging: false,
        overDockKey: dockKey
      };
      QG._dockReorderPtr = st;

      try{ el.setPointerCapture && el.setPointerCapture(pid); }catch(e){}
      const ghost = qgEnsureVarGhost();

      const onMove = (mv)=>{
        const s = QG._dockReorderPtr;
        if (!s || s.pid !== mv.pointerId) return;

        const dx = mv.clientX - s.startX;
        const dy = mv.clientY - s.startY;
        const dist = Math.sqrt(dx*dx + dy*dy);

        if (!s.dragging){
          if (dist < 6) return;
          s.dragging = true;
          ghost.textContent = s.label;
          ghost.style.display = 'block';
          try{ mv.preventDefault(); mv.stopPropagation(); }catch(e){}
        }

        ghost.style.left = (mv.clientX + 12) + 'px';
        ghost.style.top  = (mv.clientY + 10) + 'px';

        const dockEl = qgPickDockItemAt(mv.clientX, mv.clientY);
        qgClearDockHover();
        if (dockEl){
          dockEl.classList.add('hover');
          s.overDockKey = (dockEl.dataset && dockEl.dataset.dock) ? String(dockEl.dataset.dock) : null;
        }else{
          s.overDockKey = null;
        }
      };

      const onUp = (up)=>{
        const s = QG._dockReorderPtr;
        if (!s || s.pid !== up.pointerId) return;

        try{ document.removeEventListener('pointermove', onMove, true); }catch(e){}
        try{ document.removeEventListener('pointerup', onUp, true); }catch(e){}
        try{ document.removeEventListener('pointercancel', onUp, true); }catch(e){}

        const didDrag = !!s.dragging;
        const toDockKey = s.overDockKey;

        QG._dockReorderPtr = null;
        qgClearDockHover();
        ghost.style.display = 'none';

        if (didDrag){
          try{ up.preventDefault(); up.stopPropagation(); }catch(e){}
          if (toDockKey) qgMoveDockVar(s.dockKey, toDockKey);
          QG._dragVarSuppressClickUntil = Date.now() + 300;
        }
      };

      document.addEventListener('pointermove', onMove, true);
      document.addEventListener('pointerup', onUp, true);
      document.addEventListener('pointercancel', onUp, true);
    }, true);
  }
}

function qgEnsureVarGhost(){
  let g = qs('#qgVarGhost');
  if (!g){
    g = document.createElement('div');
    g.id = 'qgVarGhost';
    g.className = 'qg-var-ghost';
    g.style.display = 'none';
    const overlay = qs('#qgOverlay') || document.body;
    overlay.appendChild(g);
  }
  return g;
}

function qgBindVarDockDragHandle(el, varKey, label){
  if (!el || el._qgVarDockDragBound) return;
  el._qgVarDockDragBound = true;

  el.addEventListener('pointerdown', (ev)=>{
    try{
      if (!ev || (ev.button !== undefined && ev.button !== 0)) return;
    }catch(e){}
    // Only for Tool/Cavity group variables
    const vk = (varKey || '').toString();
    if (vk !== 'tool' && vk !== 'cavity') return;

    const pid = ev.pointerId;
    const st = {
      pid,
      src: el,
      varKey: vk,
      label: (label || (vk==='cavity'?'Cavity':'Tool')).toString(),
      startX: ev.clientX,
      startY: ev.clientY,
      dragging: false,
      overDockKey: null
    };
    QG._varDockPtr = st;

    try{ el.setPointerCapture && el.setPointerCapture(pid); }catch(e){}

    const ghost = qgEnsureVarGhost();

    const onMove = (mv)=>{
      const s = QG._varDockPtr;
      if (!s || s.pid !== mv.pointerId) return;

      const dx = mv.clientX - s.startX;
      const dy = mv.clientY - s.startY;
      const dist = Math.sqrt(dx*dx + dy*dy);

      if (!s.dragging){
        if (dist < 6) return;
        s.dragging = true;
        // show ghost
        ghost.textContent = s.label;
        ghost.style.display = 'block';
        try{ mv.preventDefault(); mv.stopPropagation(); }catch(e){}
      }

      // move ghost
      ghost.style.left = (mv.clientX + 12) + 'px';
      ghost.style.top  = (mv.clientY + 10) + 'px';

      const dockEl = qgPickDockItemAt(mv.clientX, mv.clientY);
      qgClearDockHover();
      if (dockEl){
        dockEl.classList.add('hover');
        s.overDockKey = (dockEl.dataset && dockEl.dataset.dock) ? String(dockEl.dataset.dock) : null;
      }else{
        s.overDockKey = null;
      }
    };

    const onUp = (up)=>{
      const s = QG._varDockPtr;
      if (!s || s.pid !== up.pointerId) return;

      try{ document.removeEventListener('pointermove', onMove, true); }catch(e){}
      try{ document.removeEventListener('pointerup', onUp, true); }catch(e){}
      try{ document.removeEventListener('pointercancel', onUp, true); }catch(e){}

      const didDrag = !!s.dragging;
      const dockKey = s.overDockKey;

      QG._varDockPtr = null;
      qgClearDockHover();
      ghost.style.display = 'none';

      if (didDrag){
        try{ up.preventDefault(); up.stopPropagation(); }catch(e){}
        if (dockKey){
          qgSetDockVar(dockKey, s.varKey);
        }
        // suppress click that might follow a drag
        QG._dragVarSuppressClickUntil = Date.now() + 300;
      }
    };

    document.addEventListener('pointermove', onMove, true);
    document.addEventListener('pointerup', onUp, true);
    document.addEventListener('pointercancel', onUp, true);
  }, true);
}





// Disable native HTML5 image dragging for toolbar icons.
// Without this, browsers start a ghost-drag on <img> and our pointer-drag (JMP-like) becomes unresponsive.
function qgDisableNativeToolbarImgDrag(){
  try{
    const tb = qs('.qg-toolbar');
    if (tb && !tb._qgNoNativeImgDrag){
      tb._qgNoNativeImgDrag = true;

      // Capture dragstart from toolbar images and cancel it.
      tb.addEventListener('dragstart', (ev)=>{
        try{
          const t = ev && ev.target;
          if (t && t.tagName === 'IMG'){
            ev.preventDefault();
            ev.stopPropagation();
          }
        }catch(e){}
      }, { capture:true, passive:false });
    }

    const imgs = qsa('.qg-toolbar img');
    for (const im of imgs){
      try{
        im.setAttribute('draggable','false');
        im.draggable = false;
        // WebKit/Safari extra guard
        im.style.webkitUserDrag = 'none';
        im.style.userSelect = 'none';
      }catch(e){}
      if (!im._qgNoDrag){
        im._qgNoDrag = true;
        im.addEventListener('dragstart', (ev)=>{
          try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
        }, { passive:false });
      }
    }
  }catch(e){}
}

function bindUi(){
    const btnClose = qs('#qgClose');
    if (btnClose && !btnClose._qg){ btnClose._qg = true; btnClose.addEventListener('click', close); }


    // Prevent native <img> drag on toolbar icons so pointer-drag apply works (JMP-like)
    try{ qgDisableNativeToolbarImgDrag(); }catch(e){}

// Track Shift key reliably (some browsers/shells lose modifier flags on click events)
try{
  if (!QG._shiftTrack){
    QG._shiftTrack = true;
    QG._shiftDown = false;
    document.addEventListener('keydown', (ev)=>{ try{ if (ev && ev.key === 'Shift') QG._shiftDown = true; }catch(e){} }, true);
    document.addEventListener('keyup',   (ev)=>{ try{ if (ev && ev.key === 'Shift') QG._shiftDown = false; }catch(e){} }, true);
    window.addEventListener('blur', ()=>{ try{ QG._shiftDown = false; }catch(e){} }, true);
  }
}catch(e){}



    // Plot element toolbar: Points / Line / Box (click=single, Shift=multi)
    try{
      const btns = qsa('.qg-toolbar .qg-toolbtn[data-qg-elem]');
      if (btns.length){
        const sync = ()=>{
          try{ qgUpdateToolbarSplitOverlays(); }catch(e){}
        };
        QG._plotElemSync = sync;
        sync();

        for (const b of btns){
          if (b._qgPlotElem) continue;
          b._qgPlotElem = true;
          b.addEventListener('click', (ev)=>{
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
            if (b.disabled) return;
            const k = (b.dataset && b.dataset.qgElem) ? String(b.dataset.qgElem) : '';
            if (!k) return;

            const isCtrl  = !!(ev && (ev.ctrlKey || ev.metaKey));
            const isShift = !!(ev && (ev.shiftKey || QG._shiftDown));

            if (isCtrl){
              // Ctrl/⌘ + click on a split segment applies only to that FAI (JMP-like)
              let colKey = '';
              try{ colKey = qgPickColKeyFromToolBtnEvent(ev, b); }catch(e){}
              if (!colKey) colKey = qgResolvePrimaryColKeyFromDom() || qgResolvePrimaryColKey() || '';
              if (!colKey) return;

              const cur = qgGetPlotElemsForColKey(colKey)[k];
              qgSetPlotElemForColKey(colKey, k, !cur);
            }else{
              // Without Ctrl/⌘: apply globally to all displayed FAI (existing behavior)
              const st = qgEnsurePlotElems();

              if (!isShift){
                st.points = (k === 'points');
                st.line   = (k === 'line');
                st.box    = (k === 'box');
              }else{
                st[k] = !st[k];
                if (!st.points && !st.line && !st.box) st[k] = true;
              }

              // Global apply resets per-FAI overrides
              qgClearPlotElemOverrides();
            }

            sync();
            renderGrid();
}, { passive:false });
        }
      }
    }catch(e){}





    // Caption toolbar (icon_15): JMP-like Caption Box toggle.
// - Click: toggle for ALL displayed FAI (global)
// - Ctrl/⌘ + click (on a split segment): toggle ONLY that FAI
// NOTE: Use a single down-event listener to avoid double toggles (pointerdown/mousedown/click).
try{
  const capBtn = qs('.qg-toolbar .qg-toolbtn[data-qg-aux="caption"]');
  if (capBtn && !capBtn._qgCapBtn){
    capBtn._qgCapBtn = true;

    const doToggle = (ev)=>{
      try{ ev && ev.preventDefault && ev.preventDefault(); ev && ev.stopPropagation && ev.stopPropagation(); }catch(e){}
      if (capBtn.disabled) return;

      // Left button only (avoid right click / context menu)
      try{ if (ev && ev.button !== undefined && ev.button !== 0) return; }catch(e){}

      // De-duplicate rapid repeats (some shells fire multiple down events)
      const now = Date.now();
      const last = capBtn._qgCapLastTs || 0;
      if (last && (now - last) < 180) return;
      capBtn._qgCapLastTs = now;

      const isCtrl = !!(ev && (ev.ctrlKey || ev.metaKey));

      const dispKeys = selectedColKeysInOrder();
      if (!dispKeys || !dispKeys.length) return;

      if (isCtrl){
        // Segment-only toggle
        let colKey = '';
        try{ colKey = qgPickColKeyFromToolBtnEvent(ev, capBtn); }catch(e){}
        if (!colKey) colKey = qgResolvePrimaryColKeyFromDom() || qgResolvePrimaryColKey() || '';
        if (!colKey) return;

        const cap = qgEnsureCaptionState(colKey);
        cap.enabled = !cap.enabled;
        if (cap.enabled){
          if (!Array.isArray(cap.stats) || !cap.stats.length) cap.stats = ['mean','none'];
          if (!cap.xPos) cap.xPos = 'right';
          if (!cap.yPos) cap.yPos = 'top';
        }

        // Global flag becomes "any enabled" (for UI compatibility)
        let any = false;
        for (const k of dispKeys){
          if (qgEnsureCaptionState(k).enabled){ any = true; break; }
        }
        QG.captionGlobalEnabled = any;
      }else{
        // Global toggle (all displayed FAI)
        let all = true;
        for (const k of dispKeys){
          if (!qgEnsureCaptionState(k).enabled){ all = false; break; }
        }
        const next = !all;
        QG.captionGlobalEnabled = next;
        for (const k of dispKeys){
          const cap = qgEnsureCaptionState(k);
          cap.enabled = next;
          if (next){
            if (!Array.isArray(cap.stats) || !cap.stats.length) cap.stats = ['mean','none'];
            if (!cap.xPos) cap.xPos = 'right';
            if (!cap.yPos) cap.yPos = 'top';
          }
        }
      }

      qgSyncCaptionUi();
      renderGrid();
    };

    // Use click (not pointerdown) so drag-to-apply from the toolbar doesn't pre-toggle and immediately revert.
    // Drag-to-apply will suppress the follow-up click automatically.
    capBtn.addEventListener('click', doToggle, { passive:false });
  }
}catch(e){}

// Initial sync for caption UI/button
    try{ qgSyncCaptionUi(); }catch(e){}

    const ov = qs('#qgOverlay');
    if (ov && !ov._qgCtx){
      ov._qgCtx = true;
      // Some environments swallow the SVG's native `contextmenu` listeners.
      // Intercept at overlay capture phase and forward to the nearest plot SVG handler.
	      function _qgForwardCtx(ev){
        try{
          const t = ev.target;
          if (!t) return false;

          // Only handle right-clicks inside the plot grid.
          const grid = qs('#qgGrid');
          if (grid && !grid.contains(t)) return false;

          const tag = (t.tagName ? String(t.tagName).toLowerCase() : '');
          // Don't hijack native context menu for form controls
          if (tag === 'input' || tag === 'textarea' || tag === 'select' || tag === 'option') return false;

	          // Find the most relevant plot SVG.
	          let svg = null;
	          if (tag === 'svg') svg = t;
	          else if (t.closest) svg = t.closest('svg.qg-svg');

	          // If the target isn't inside the SVG (or handler isn't ready), try the nearest row's SVG.
	          if ((!svg || typeof svg._qgCtxHandler !== 'function') && t.closest){
	            const row = t.closest('.qg-fai-row') || t.closest('.qg-fai-one') || null;
	            if (row && row.querySelector){
	              const cand = row.querySelector('svg.qg-svg');
	              if (cand) svg = cand;
	            }
	          }

	          // Final resolution: use coordinates to locate the SVG under the pointer.
	          if (!svg || typeof svg._qgCtxHandler !== 'function'){
	            svg = qgSvgFromPoint(ev.clientX, ev.clientY);
	          }

          // If no handler is ready, do NOT swallow the event (prevents "dead" right-click).
          if (!svg || typeof svg._qgCtxHandler !== 'function') return false;

          try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
          try{ ev.stopImmediatePropagation && ev.stopImmediatePropagation(); }catch(e){}
          try{ svg._qgSkipNextCtx = true; }catch(e){}
          try{ svg._qgCtxHandler(ev); }catch(e){}
          return true;
        }catch(e){}
        return false;
      }

      // Prevent the browser context menu over the plot (our handler will show a custom menu)
      ov.addEventListener('contextmenu', function(ev){
        _qgForwardCtx(ev);
      }, true);

      // Robust right-button trigger: some shells block `contextmenu` but still deliver pointer/mouse down.
      ov.addEventListener('pointerdown', function(ev){
        try{ if (ev.button !== 2) return; }catch(e){ return; }
        _qgForwardCtx(ev);
      }, true);
      ov.addEventListener('mousedown', function(ev){
        try{ if (ev.button !== 2) return; }catch(e){ return; }
        _qgForwardCtx(ev);
      }, true);
    }

    const yMin = qs('#qgYMin');
    const yMax = qs('#qgYMax');
    const onAxisInput = ()=>{
      applyAxisInputsToState();
      renderGrid();
    };
    const onAxisFocus = ()=>{ QG.editingAxis = true; };
    const onAxisBlur  = ()=>{ QG.editingAxis = false; applyAxisInputsToState(); renderGrid(); };
    if (yMin && !yMin._qg){ yMin._qg=true; yMin.addEventListener('input', onAxisInput); yMin.addEventListener('focus', onAxisFocus); yMin.addEventListener('blur', onAxisBlur); }
    if (yMax && !yMax._qg){ yMax._qg=true; yMax.addEventListener('input', onAxisInput); yMax.addEventListener('focus', onAxisFocus); yMax.addEventListener('blur', onAxisBlur); }

    const uslEl = qs('#qgUSL');
    const lslEl = qs('#qgLSL');
    // NOTE: USL/LSL changes in Quick Graph are **virtual only**.
    // They must NOT be persisted to DB from this UI.
    const onLimitInput = ()=>{
      applyLimitInputsToState();
      renderLegend();
      renderGrid();
    };
    const onLimitFocus = ()=>{ QG.editingLimits = true; };
    const onLimitBlur = ()=>{
      QG.editingLimits = false;
      applyLimitInputsToState();
      renderLegend();
      renderGrid();
    };

    if (uslEl && !uslEl._qg){ uslEl._qg=true; uslEl.addEventListener('input', onLimitInput); uslEl.addEventListener('focus', onLimitFocus); uslEl.addEventListener('blur', onLimitBlur); }
    if (lslEl && !lslEl._qg){ lslEl._qg=true; lslEl.addEventListener('input', onLimitInput); lslEl.addEventListener('focus', onLimitFocus); lslEl.addEventListener('blur', onLimitBlur); }

    // Grid hide toggle (default hidden) - applies to all panels
    try{
      const hg = qs('#qgHideGrid');
      if (hg && !hg._qg){
        hg._qg = true;
        hg.checked = !!QG.gridHidden;
        hg.addEventListener('change', ()=>{
          QG.gridHidden = !!hg.checked;
          renderGrid();
        });
      }
    }catch(e){}

    // OOC SPEC value control (1~100%, 85 = current base/input USL/LSL)
    try{
      const specRange = qs('#qgOocSpecRange');
      const specPct = qs('#qgOocSpecPct');
      const onSpecInput = (src)=>{
        applyOocSpecInputsToState(src);
        syncLimitInputs(true);
        renderLegend();
        renderGrid();
      };
      const onSpecFocus = ()=>{ QG.editingOocSpec = true; };
      const onSpecBlur = (src)=>{
        QG.editingOocSpec = false;
        applyOocSpecInputsToState(src);
        syncOocSpecInputs(true);
        syncLimitInputs(true);
        renderLegend();
        renderGrid();
      };
      if (specRange && !specRange._qg){
        specRange._qg = true;
        specRange.addEventListener('input', ()=>onSpecInput('range'));
        specRange.addEventListener('focus', onSpecFocus);
        specRange.addEventListener('blur', ()=>onSpecBlur('range'));
      }
      if (specPct && !specPct._qg){
        specPct._qg = true;
        specPct.addEventListener('input', ()=>onSpecInput('input'));
        specPct.addEventListener('focus', onSpecFocus);
        specPct.addEventListener('blur', ()=>onSpecBlur('input'));
      }
      syncOocSpecInputs(true);
    }catch(e){}

    // OOC SPEC line visibility toggle (per-FAI)
    try{
      const oocLineEl = qs('#qgShowOocSpecLine');
      if (oocLineEl && !oocLineEl._qg){
        oocLineEl._qg = true;
        oocLineEl.addEventListener('change', ()=>{
          applyOocLineInputsToState();
          renderLegend();
          renderGrid();
        });
      }
      syncOocLineInputs(true);
    }catch(e){}

    // USL/LSL label (right-side text) hide toggle - hide only the value labels, keep dashed lines
    try{
      const hll = qs('#qgHideUSLLSLLabel');
      if (hll && !hll._qg){
        hll._qg = true;
        hll.checked = !!QG.hideUslLslLabel;
        hll.addEventListener('change', ()=>{
          QG.hideUslLslLabel = !!hll.checked;
          renderGrid();
        });
      }
    }catch(e){}

    // ESC: do not close the Graph Builder (keep JMP-like behavior)
    if (!document._qgEsc){
      document._qgEsc = true;
      document.addEventListener('keydown', (ev)=>{
        if (ev.key === 'Escape'){
          try{ qgHideCtxMenu && qgHideCtxMenu(); }catch(e){}
          try{ qgCloseVarDlg && qgCloseVarDlg(); }catch(e){}
          try{ qgCloseRenameDlg && qgCloseRenameDlg(); }catch(e){}
          // NOTE: intentionally do NOT close the overlay.
        }
      }, true);
    }


    // Inline query controls inside Graph Builder (same dropdown style / immediate apply)
    try{
      const host = qs('#qgQueryControls');
      if (host && !host._qgqBound){
        host._qgqBound = true;

        host.addEventListener('input', function(ev){
          const t = ev && ev.target ? ev.target : null;
          if (!t) return;
          if (t.id === 'qgq-ms-fai-search'){
            if (!QG.query.state) return;
            QG.query.state.faiSearch = String(t.value || '');
            qgApplyInlineFaiFilter();
          }
        }, true);

        host.addEventListener('click', function(ev){
          const t = ev && ev.target ? ev.target : null;
          if (!t) return;

          const openIds = qgCollectOpenQueryMs();
          const msToggle = t.closest ? t.closest('.ms-toggle') : null;
          if (msToggle && host.contains(msToggle)){
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
            const ms = msToggle.closest('.ms');
            qgToggleInlineMs(ms);
            return;
          }

          const singleBtn = t.closest ? t.closest('.ms-singlebtn[data-qgq-single]') : null;
          if (singleBtn && host.contains(singleBtn)){
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
            const kind = String(singleBtn.getAttribute('data-qgq-single') || '');
            const value = String(singleBtn.getAttribute('data-value') || '');
            if (!QG.query.state) return;
            if (kind === 'type' || kind === 'model'){
              QG.query.state[kind] = value;
              QG.query.state.pageAll = true;
              QG.query.state.pageDate = '';
              QG.query.state.pageDates = [];
              QG.query.state.faiSearch = '';
              QG.query.state.fai = [];
              QG.query.options = qgPatchQueryOptionsForState(QG.query.options || {}, QG.query.state, [], null, false);
              QG.query.state = qgEnsureQueryStateValid(QG.query.options || {}, QG.query.state);
              if (QG.query) QG.query.resetFaiOnModelTypeChange = true;
              qgRenderQueryControls(openIds);
              qgEnsureInlineFaiOptionsLoaded(QG.query.state, true);
              qgScheduleInlineQuery(true, false);
            }
            return;
          }

          const actionBtn = t.closest ? t.closest('[data-qgq-action]') : null;
          if (actionBtn && host.contains(actionBtn)){
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
            const act = String(actionBtn.getAttribute('data-qgq-action') || '');
            const opt = QG.query.options || {};
            const st = QG.query.state || {};
            if (act === 'tools-all') st.tools = (opt.toolOptions || []).slice();
            else if (act === 'tools-none') st.tools = [];
            else if (act === 'years-all') st.years = (opt.yearOptions || []).slice();
            else if (act === 'years-none') st.years = [];
            else if (act === 'months-all') st.months = (opt.monthOptions || []).slice();
            else if (act === 'months-none') st.months = [];
            else if (act === 'fai-clear') st.fai = [];
            else if (act === 'page-prev' || act === 'page-next'){
              const all = opt.pageDates || [];
              const base = (!st.pageAll && st.pageDate) ? st.pageDate : ((st.pageDates && st.pageDates.length) ? st.pageDates[0] : (all[0] || ''));
              const idx = all.indexOf(base);
              if (idx >= 0){
                const nextIdx = act === 'page-prev' ? idx - 1 : idx + 1;
                if (nextIdx >= 0 && nextIdx < all.length){
                  st.pageAll = false;
                  st.pageDates = [];
                  st.pageDate = all[nextIdx];
                }
              }
            }
            QG.query.state = qgEnsureQueryStateValid(opt, st);
            qgRenderQueryControls(openIds);
            qgScheduleInlineQuery(true, false);
            return;
          }

          const multiInput = t.closest ? t.closest('[data-qgq-multi]') : null;
          if (multiInput && host.contains(multiInput)){
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
            const kind = String(multiInput.getAttribute('data-qgq-multi') || '');
            const value = String(multiInput.value || '');
            const st = QG.query.state || {};
            const map = { tools:'toolOptions', years:'yearOptions', months:'monthOptions' };
            const allList = ((QG.query.options || {})[map[kind]] || []).slice();
            const cur = new Set(Array.isArray(st[kind]) ? st[kind] : []);
            if (multiKey(ev)){
              if (cur.has(value)){
                if (cur.size > 1) cur.delete(value);
              }else cur.add(value);
            }else{
              cur.clear();
              cur.add(value);
            }
            st[kind] = Array.from(cur).filter(v => allList.includes(v));
            QG.query.state = qgEnsureQueryStateValid(QG.query.options || {}, st);
            qgRenderQueryControls(openIds);
            qgScheduleInlineQuery(false, false);
            return;
          }

          const faiBtn = t.closest ? t.closest('.fai-btn[data-qgq-fai]') : null;
          if (faiBtn && host.contains(faiBtn)){
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
            const value = String(faiBtn.getAttribute('data-qgq-fai') || '');
            const st = QG.query.state || {};
            const allowAll = !!((QG.query.options || {}).allowAllFai);
            let cur = Array.isArray(st.fai) ? st.fai.slice() : [];
            if (!multiKey(ev)){
              cur = [value];
            }else if (value === '__ALL__'){
              cur = ['__ALL__'];
            }else{
              cur = cur.filter(v => v !== '__ALL__');
              const idx = cur.indexOf(value);
              if (idx >= 0){ if (cur.length > 1) cur.splice(idx,1); }
              else cur.push(value);
            }
            if (allowAll && cur.includes('__ALL__')) cur = ['__ALL__'];
            st.fai = cur;
            QG.query.state = qgEnsureQueryStateValid(QG.query.options || {}, st);
            qgPatchInlineQueryControls();
            qgScheduleInlineQuery(false, false);
            return;
          }

          const pageAllBtn = t.closest ? t.closest('[data-qgq-page-all]') : null;
          if (pageAllBtn && host.contains(pageAllBtn)){
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
            const st = QG.query.state || {};
            st.pageAll = true; st.pageDate = ''; st.pageDates = [];
            QG.query.state = qgEnsureQueryStateValid(QG.query.options || {}, st);
            qgRenderQueryControls(openIds);
            qgScheduleInlineQuery(true, false);
            return;
          }

          const pageBtn = t.closest ? t.closest('[data-qgq-page-date]') : null;
          if (pageBtn && host.contains(pageBtn)){
            try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
            const value = String(pageBtn.getAttribute('data-qgq-page-date') || '');
            const st = QG.query.state || {};
            st.pageAll = false;
            if (multiKey(ev)){
              const cur = new Set(Array.isArray(st.pageDates) ? st.pageDates : []);
              if (cur.has(value)) cur.delete(value); else cur.add(value);
              st.pageDates = Array.from(cur);
              st.pageDate = '';
            }else{
              st.pageDates = [];
              st.pageDate = value;
            }
            QG.query.state = qgEnsureQueryStateValid(QG.query.options || {}, st);
            qgRenderQueryControls(openIds);
            qgScheduleInlineQuery(true, false);
            return;
          }
        }, true);

        document.addEventListener('click', function(ev){
          const box = qs('#qgQueryControls');
          if (!box || !QG.built) return;
          if (box.contains(ev.target)) return;
          qgCloseInlineMs();
        }, true);

        const qgInlineReposition = function(){
          if (!QG || !QG.built) return;
          qgRefreshInlineMsPositions();
        };
        window.addEventListener('resize', qgInlineReposition, true);
        document.addEventListener('scroll', qgInlineReposition, true);
      }
    }catch(e){}

    // Drag-to-apply scope preview (JMP-like blue translucent box)
    try{ qgBindDragApply(); }catch(e){}
  }

  
  function renderFaiList(){
    const root = qs('#qgFaiList');
    if (!root) return;
    root.innerHTML = '';

    const fs = qs('#qgFaiSummary');
    if (fs){
      const selN = QG.sel.colKeys ? QG.sel.colKeys.size : 0;
      fs.textContent = selN ? `대상 FAI (${selN})` : '대상 FAI';
    }

    const cols = QG.cols || [];
    const keysInOrder = cols.map(c=>c.key);
    const counts = cols.map(c => estimateCountForColKey(c.key));
    const maxCount = Math.max(1, ...counts);

    for (let i=0; i<cols.length; i++){
      const c = cols[i];
      const key = c.key;
      const div = document.createElement('div');
      const active = QG.sel.colKeys && QG.sel.colKeys.has(key);
      div.className = 'qg-item' + (active ? ' active' : '');
      div.dataset.value = key;
      div.innerHTML = `<span class="fill"></span><span class="txt"><span class="k">${escapeHtml(c.label)}</span><span class="n"></span></span>`;

      div.addEventListener('click', (ev)=>{
        if (QG._dragVarSuppressClickUntil && Date.now() < QG._dragVarSuppressClickUntil) return;
        const multi = multiKey(ev);
        if (!QG.sel.colKeys || !(QG.sel.colKeys instanceof Set)) QG.sel.colKeys = new Set();

        if (!multi){
          QG.sel.colKeys.clear();
          QG.sel.colKeys.add(key);
          QG.sel.primaryColKey = key;
        } else {
          if (QG.sel.colKeys.has(key)){
            QG.sel.colKeys.delete(key);
            if (QG.sel.primaryColKey === key){
              const next = keysInOrder.find(k => QG.sel.colKeys.has(k)) || '';
              QG.sel.primaryColKey = next;
            }
          } else {
            QG.sel.colKeys.add(key);
            QG.sel.primaryColKey = key;
          }
        }

        if (!QG.sel.primaryColKey){
          QG.sel.primaryColKey = key;
          QG.sel.colKeys.add(key);
        }
        if (!QG.sel.colKeys.has(QG.sel.primaryColKey)) QG.sel.colKeys.add(QG.sel.primaryColKey);

        // limits inputs follow primary selection
        QG.editingLimits = false;
        syncLimitInputs(true);
        QG.editingAxis = false;
        syncAxisInputs(true);
        QG.editingOocSpec = false;
        syncOocSpecInputs(true);
        syncOocLineInputs(true);

        refresh();
      });

      const count = counts[i] || 0;
      div.querySelector('.n').textContent = String(count);
      const pct = Math.max(0, Math.min(100, Math.round((count/maxCount) * 100)));
      div.querySelector('.fill').style.width = pct + '%';

      root.appendChild(div);
    }
  }

  function estimateCountForColKey(colKey){
    const s = (QG.seriesByCol||{})[colKey];
    if (!s) return 0;
    let n = 0;
    for (const t of Object.keys(s)) {
      for (const c of Object.keys(s[t]||{})) {
        for (const d of Object.keys(s[t][c]||{})) {
          const p = (s[t][c]||{})[d];
          const vals = p && p.vals ? p.vals : null;
          n += (vals ? vals.length : 0);
        }
      }
    }
    return n;
  }

function renderFacetList(rootId, items, selSet){
    const root = qs('#'+rootId);
    if (!root) return;
    root.innerHTML = '';

    const counts = items.map(it => estimateCountForFacet(rootId, it));
    const maxCount = Math.max(1, ...counts);
    for (let i=0; i<items.length; i++){
      const it = items[i];
      const div = document.createElement('div');
      div.className = 'qg-item' + (selSet.has(it) ? ' active' : '');
      div.dataset.value = it;
      div.innerHTML = `<span class="fill"></span><span class="txt"><span class="k">${escapeHtml(it)}</span><span class="n"></span></span>`;


      div.addEventListener('click', (ev)=>{
        if (QG._dragVarSuppressClickUntil && Date.now() < QG._dragVarSuppressClickUntil) return;
        const multi = multiKey(ev);
        if (!multi){
          selSet.clear();
          selSet.add(it);
        }else{
          if (selSet.has(it)) selSet.delete(it);
          else selSet.add(it);
        }
        qsa('.qg-item', root).forEach(el=>{
          el.classList.toggle('active', selSet.has(el.dataset.value||''));
        });
        renderLegend();
        renderGrid();
      });

      const count = counts[i] || 0;
      div.querySelector('.n').textContent = String(count);
      const pct = Math.max(0, Math.min(100, Math.round((count/maxCount) * 100)));
      div.querySelector('.fill').style.width = pct + '%';

      root.appendChild(div);
    }
  }

  function estimateCountForFacet(rootId, value){
    let n = 0;
    if (!QG.series) return 0;
    if (rootId === 'qgToolList'){
      const t = value;
      for (const cav of Object.keys(QG.series[t]||{})) {
        for (const d of Object.keys((QG.series[t]||{})[cav]||{})) {
          const p = ((QG.series[t]||{})[cav]||{})[d];
          const vals = p && p.vals ? p.vals : null;
          n += (vals ? vals.length : 0);
        }
      }
    }else{
      const cav = value;
      for (const t of Object.keys(QG.series||{})) {
        for (const d of Object.keys((QG.series[t]||{})[cav]||{})) {
          const p = ((QG.series[t]||{})[cav]||{})[d];
          const vals = p && p.vals ? p.vals : null;
          n += (vals ? vals.length : 0);
        }
      }
    }
    return n;
  }

  function qgClampOocSpecPct(v){
    const n = Math.round(Number(v));
    if (!isFinite(n)) return 85;
    return Math.max(1, Math.min(100, n));
  }

  function qgGetColByKey(colKey){
    const k = String(colKey || '');
    if (!k) return null;
    return (QG.cols || []).find(c => String(c && c.key || '') === k) || null;
  }

  function qgEnsureColSpecState(col){
    if (!col) return null;
    if (!QG.oocSpecByCol) QG.oocSpecByCol = {};
    if (!QG.oocLineVisibleByCol) QG.oocLineVisibleByCol = {};
    if (!QG.limitBaseByCol) QG.limitBaseByCol = {};
    const key = String(col.key || '');
    const savedPct = (QG.oocSpecByCol[key] !== undefined) ? QG.oocSpecByCol[key] : ((col.oocSpecPct !== undefined) ? col.oocSpecPct : 85);
    const savedLineVisible = (QG.oocLineVisibleByCol[key] !== undefined) ? !!QG.oocLineVisibleByCol[key] : ((col.oocLineVisible !== undefined) ? !!col.oocLineVisible : false);
    col.oocSpecPct = qgClampOocSpecPct(savedPct);
    col.oocLineVisible = savedLineVisible;
    const savedBase = QG.limitBaseByCol[key] || null;
    if (savedBase){
      col.baseUsl = (savedBase.baseUsl !== undefined) ? savedBase.baseUsl : (col.baseUsl !== undefined ? col.baseUsl : col.usl);
      col.baseLsl = (savedBase.baseLsl !== undefined) ? savedBase.baseLsl : (col.baseLsl !== undefined ? col.baseLsl : col.lsl);
    }else{
      if (col.baseUsl === undefined) col.baseUsl = col.usl;
      if (col.baseLsl === undefined) col.baseLsl = col.lsl;
      QG.limitBaseByCol[key] = { baseUsl: col.baseUsl, baseLsl: col.baseLsl };
    }
    QG.oocSpecByCol[key] = col.oocSpecPct;
    QG.oocLineVisibleByCol[key] = !!col.oocLineVisible;
    return col;
  }

  function qgGetColOocSpecPct(col){
    const st = qgEnsureColSpecState(col);
    return st ? qgClampOocSpecPct(st.oocSpecPct) : 85;
  }

  function qgGetBaseLimitValueForCol(col, kind){
    const st = qgEnsureColSpecState(col);
    if (!st) return null;
    const isLsl = String(kind || '').toLowerCase() === 'lsl';
    const raw = isLsl ? st.baseLsl : st.baseUsl;
    const v = Number(raw);
    if (!isFinite(v)) return null;
    if (isLsl && Math.abs(v) <= 1e-12) return null;
    return v;
  }

  function qgGetColOocLineVisible(col){
    const st = qgEnsureColSpecState(col);
    return st ? !!st.oocLineVisible : false;
  }

  function qgGetScaledOocLimitsForCol(col){
    const st = qgEnsureColSpecState(col);
    if (!st) return { usl: null, lsl: null };

    const baseU = qgGetBaseLimitValueForCol(st, 'usl');
    const baseL = qgGetBaseLimitValueForCol(st, 'lsl');
    if (baseU === null && baseL === null) return { usl: null, lsl: null };

    const ratio = qgGetColOocSpecPct(st) / 85;
    if (baseU !== null && baseL !== null){
      const center = (baseU + baseL) / 2;
      const halfRange = Math.abs(baseU - baseL) / 2;
      return {
        usl: center + (halfRange * ratio),
        lsl: center - (halfRange * ratio),
      };
    }

    return {
      usl: (baseU !== null) ? (baseU * ratio) : null,
      lsl: (baseL !== null) ? (baseL * ratio) : null,
    };
  }

  function qgScaleSpecValueForCol(col, val){
    const st = qgEnsureColSpecState(col);
    const v = Number(val);
    if (!isFinite(v)) return val;
    const pct = qgGetColOocSpecPct(st);
    const ratio = pct / 85;

    const baseU = Number(st && st.baseUsl);
    const baseL = Number(st && st.baseLsl);
    const hasU = isFinite(baseU);
    const hasLRaw = isFinite(baseL);
    const eps0 = 1e-12;

    // In this graph builder, LSL=0 is treated as NULL/hidden.
    if (hasLRaw && Math.abs(baseL) <= eps0 && Math.abs(v - baseL) <= eps0) return null;

    const hasL = hasLRaw && Math.abs(baseL) > eps0;
    if (hasU && hasL){
      const center = (baseU + baseL) / 2;
      const halfRange = Math.abs(baseU - baseL) / 2;
      const eps = Math.max(1e-12, Math.abs(baseU - baseL) * 1e-12);
      if (Math.abs(v - baseU) <= eps) return center + (halfRange * ratio);
      if (Math.abs(v - baseL) <= eps) return center - (halfRange * ratio);
    }

    return v * ratio;
  }

  function syncOocSpecInputs(force){
    if (!force && QG.editingOocSpec) return;
    const col = qgEnsureColSpecState(qgGetColByKey(QG.sel.primaryColKey));
    const pct = qgGetColOocSpecPct(col);
    const rangeEl = qs('#qgOocSpecRange');
    const inputEl = qs('#qgOocSpecPct');
    if (rangeEl) rangeEl.value = String(pct);
    if (inputEl) inputEl.value = String(pct);
  }

  function syncOocLineInputs(force){
    const chkEl = qs('#qgShowOocSpecLine');
    if (!chkEl) return;
    const col = qgEnsureColSpecState(qgGetColByKey(QG.sel.primaryColKey));
    chkEl.checked = qgGetColOocLineVisible(col);
  }

  function applyOocLineInputsToState(){
    const chkEl = qs('#qgShowOocSpecLine');
    const col = qgEnsureColSpecState(qgGetColByKey(QG.sel.primaryColKey));
    if (!chkEl || !col) return;
    const on = !!chkEl.checked;
    col.oocLineVisible = on;
    if (!QG.oocLineVisibleByCol) QG.oocLineVisibleByCol = {};
    QG.oocLineVisibleByCol[String(col.key || '')] = on;
  }

  function applyOocSpecInputsToState(src){
    const col = qgEnsureColSpecState(qgGetColByKey(QG.sel.primaryColKey));
    if (!col) return;
    const rangeEl = qs('#qgOocSpecRange');
    const inputEl = qs('#qgOocSpecPct');
    const raw = (src === 'input')
      ? ((inputEl && inputEl.value !== undefined) ? inputEl.value : '')
      : ((rangeEl && rangeEl.value !== undefined) ? rangeEl.value : '');
    const pct = qgClampOocSpecPct(raw);
    col.oocSpecPct = pct;
    if (!QG.oocSpecByCol) QG.oocSpecByCol = {};
    QG.oocSpecByCol[String(col.key || '')] = pct;
    if (rangeEl && src !== 'range') rangeEl.value = String(pct);
    if (inputEl && src !== 'input') inputEl.value = String(pct);
  }

  function syncLimitInputs(force){
    if (!force && QG.editingLimits) return;
    const col = qgEnsureColSpecState(qgGetColByKey(QG.sel.primaryColKey));
    const uslEl = qs('#qgUSL');
    const lslEl = qs('#qgLSL');
    const uslV = col ? col.baseUsl : null;
    const lslV = col ? col.baseLsl : null;
    if (uslEl) uslEl.value = (uslV !== null && uslV !== undefined) ? fmt(uslV) : '';
    if (lslEl) lslEl.value = (lslV !== null && lslV !== undefined) ? fmt(lslV) : '';
  }

  function getAxisState(colKey){
    const k = (colKey || '').toString();
    if (!k) return { yMin: null, yMax: null };
    if (!QG.axisByCol) QG.axisByCol = {};
    if (!QG.axisByCol[k]) QG.axisByCol[k] = { yMin: null, yMax: null };
    return QG.axisByCol[k];
  }

  function syncAxisInputs(force){
    if (!force && QG.editingAxis) return;
    const k = QG.sel.primaryColKey || '';
    const st = getAxisState(k);
    const yMinEl = qs('#qgYMin');
    const yMaxEl = qs('#qgYMax');
    if (yMinEl) yMinEl.value = (st.yMin !== null && st.yMin !== undefined) ? fmt(st.yMin) : '';
    if (yMaxEl) yMaxEl.value = (st.yMax !== null && st.yMax !== undefined) ? fmt(st.yMax) : '';
  }

  function applyAxisInputsToState(){
    const k = QG.sel.primaryColKey || '';
    if (!k) return;
    const st = getAxisState(k);
    const yMinRaw = (qs('#qgYMin')?.value || '').toString();
    const yMaxRaw = (qs('#qgYMax')?.value || '').toString();
    st.yMin = num(yMinRaw);
    st.yMax = num(yMaxRaw);
  }

  function applyLimitInputsToState(){
    const col = qgEnsureColSpecState(qgGetColByKey(QG.sel.primaryColKey));
    if (!col) return;
    const uslRaw = (qs('#qgUSL')?.value || '').toString();
    const lslRaw = (qs('#qgLSL')?.value || '').toString();
    const dispUsl = num(uslRaw);
    const dispLsl = num(lslRaw);
    col.baseUsl = (dispUsl === null) ? null : dispUsl;
    col.baseLsl = (dispLsl === null) ? null : dispLsl;
    col.usl = col.baseUsl;
    col.lsl = col.baseLsl;
    if (!QG.limitBaseByCol) QG.limitBaseByCol = {};
    QG.limitBaseByCol[String(col.key || '')] = { baseUsl: col.baseUsl, baseLsl: col.baseLsl };
    try{
      if (col.th && col.th.dataset){
        col.th.dataset.usl = (col.baseUsl === null ? '' : String(col.baseUsl));
        col.th.dataset.lsl = (col.baseLsl === null ? '' : String(col.baseLsl));
      }
    }catch(e){}
  }

  function refresh(){
    try{ if (QG._plotElemSync) QG._plotElemSync(); }catch(e){}
    computeSeriesForSelectedCols();
    rebuildFacets();

    renderFaiList();

    renderLegend();

    try{ qgRenderDockVars(); }catch(e){}

    renderFacetList('qgToolList', QG.tools, QG.sel.tools);
    renderFacetList('qgCavityList', QG.cavities, QG.sel.cavities);

    syncLimitInputs(false);
    syncAxisInputs(false);
    syncOocSpecInputs(false);
    syncOocLineInputs(false);
    renderGrid();
  }

  function selectedSeriesMeta(){
    const keys = selectedColKeysInOrder();
  try{ QG._visibleColKeys = Array.isArray(keys) ? keys.slice() : []; }catch(e){}
  try{ qgUpdateToolbarSplitOverlays(); }catch(e){}
    const out = [];
    for (let i=0; i<keys.length; i++){
      const k = keys[i];
      const col = QG.cols.find(c => c.key === k) || null;
      out.push({
        key: k,
        label: qgGetDisplayLabel(k),
        color: QG_SERIES_COLORS[i % QG_SERIES_COLORS.length],
      });
    }
    return out;
  }

  function renderLegend(){
    const root = qs('#qgLegendItems');
    if (!root) return;
    root.innerHTML = '';

    const meta = selectedSeriesMeta();
    if (!meta.length) return;

    const dock = (QG && QG.dockVars) ? QG.dockVars : null;
    const vis = qgResolveVisualChannels();
    const overlayVar = vis.overlayVar;
    const colorVar   = vis.colorVar;
    const shapeVar   = vis.shapeVar;
    const sizeVar    = (dock && dock.size) ? String(dock.size) : null;
    const intervalVar= (dock && dock.interval) ? String(dock.interval) : null;

// 1) Series markers (Data 1~3) -> JMP-like boxplot symbol in legend
    //    (box + center mean line + vertical dashed whiskers + solid caps)
    for (const m of meta){
      const row = document.createElement('div');
      row.className = 'qg-legend-item';
      let _lgBoxC = m.color; let _lgBoxFillC = m.color; let _lgBoxFill = false; let _lgBoxOp = 1; let _lgBoxFillOp = 0.18;
      try{
        const _k = String(m.key||'');
        const _bs = (_k && QG.boxStyleByCol && QG.boxStyleByCol[_k]) ? QG.boxStyleByCol[_k] : null;
        if (_bs && _bs.color) _lgBoxC = String(_bs.color);
        if (_bs && _bs.fillColor) _lgBoxFillC = String(_bs.fillColor);
        if (_bs && _bs.fill) _lgBoxFill = true;
        if (_bs && _bs.opacity !== undefined && _bs.opacity !== null) _lgBoxOp = qgClamp01(_bs.opacity);
        if (_bs && _bs.fillOpacity !== undefined && _bs.fillOpacity !== null) _lgBoxFillOp = qgClamp01(_bs.fillOpacity);
      }catch(e){}
      row.style.setProperty('--qg-c', _lgBoxC);
      row.innerHTML = `
        <span class="qg-lg-boxplot" aria-hidden="true">
          <svg class="qg-lg-svg" viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
  <g fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="butt" stroke-linejoin="miter">
    <!-- JMP legend marker: box + centered vertical whiskers (no caps, no inner line) -->
    <line x1="8.5" y1="2.5" x2="8.5" y2="4.5" />
    <rect x="4.5" y="4.5" width="8" height="6" />
    <line x1="8.5" y1="10.5" x2="8.5" y2="12.5" />
  </g>
</svg>
        </span>
        <span class="qg-lg-label">${escapeHtml(m.label)}</span>
      `;
      root.appendChild(row);
      try{
        const g = row.querySelector ? row.querySelector('g') : null;
        if (g) g.setAttribute('stroke-opacity', String(_lgBoxOp));
        const r = row.querySelector ? row.querySelector('rect') : null;
        if (r) r.setAttribute('stroke-opacity', String(_lgBoxOp));
      }catch(e){}
      try{
        if (_lgBoxFill){
          const _r = row.querySelector ? row.querySelector('rect') : null;
          if (_r){
            _r.setAttribute('fill', _lgBoxFillC);
            _r.setAttribute('fill-opacity', String(_lgBoxFillOp));
          }
        }
      }catch(e){}
    }

    
// 2) Mean line per series (style is per FAI/series key)
    for (const m of meta){
      const row = document.createElement('div');
      row.className = 'qg-legend-item';

      // per-FAI mean line style (line + dot)
      let _mColor = String(m.color || '#2b5bd7');
      let _mWidth = 2;
      let _mDash  = '';
      let _mDot   = _mColor;
      let _mOp = 1;
      let _mDotOp = 1;
      try{
        const ms = (QG.meanStyleByCol && QG.meanStyleByCol[m.key]) ? QG.meanStyleByCol[m.key] : null;
        if (ms){
          if (ms.color) _mColor = String(ms.color);
          const w = Number(ms.width);
          if (isFinite(w) && w > 0) _mWidth = w;
          if (ms.dash !== undefined && ms.dash !== null) _mDash = String(ms.dash).trim();
          if (ms.dotColor) _mDot = String(ms.dotColor);
          else _mDot = _mColor;
          if (ms.opacity !== undefined && ms.opacity !== null) _mOp = qgClamp01(ms.opacity);
          if (ms.dotOpacity !== undefined && ms.dotOpacity !== null) _mDotOp = qgClamp01(ms.dotOpacity);
        }
      }catch(e){}
      const _wIcon = Math.max(1, Math.min(6, Number(_mWidth) || 2));
      const _dashIcon = (_mDash && _mDash.trim() !== '') ? _mDash.trim() : '';
      const _cap = (_dashIcon === '2 4') ? 'round' : 'butt';

      // Keep legend label text black; colorize icon only via CSS variable.
      try{ row.style.setProperty('--qg-c', _mColor); }catch(e){}
      row.innerHTML = `
        <span class="qg-lg-mean" aria-hidden="true">
          <svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
            <line x1="1" y1="7" x2="15" y2="7" stroke="currentColor" stroke-opacity="${_mOp}" stroke-width="${_wIcon}" ${_dashIcon ? (`stroke-dasharray="${_dashIcon}"`) : ""} stroke-linecap="${_cap}" />
            <circle cx="8" cy="7" r="2.2" fill="${escapeHtml(_mDot)}" fill-opacity="${_mDotOp}" />
          </svg>
        </span>
        <span class="qg-lg-label">${escapeHtml('평균(' + m.label + ')')}</span>
      `;
      root.appendChild(row);
    }

    // Overlay / Color variable legend sections (JMP-like: show variable levels)
    const _addHeader = (txt)=>{
      const row = document.createElement('div');
      row.className = 'qg-legend-item';
      row.innerHTML = `<span class="qg-lg-label">${escapeHtml(String(txt))}</span>`;
      root.appendChild(row);
    };
    const _addLevelPair = (lbl, color)=>{
      // marker (boxplot-like)
      let row = document.createElement('div');
      row.className = 'qg-legend-item';
      try{ row.style.setProperty('--qg-c', String(color||'')); }catch(e){}
      row.innerHTML = `
        <span class="qg-lg-boxplot" aria-hidden="true">
          <svg class="qg-lg-svg" viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
            <g fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="butt" stroke-linejoin="miter">
              <line x1="8.5" y1="2.5" x2="8.5" y2="4.5" />
              <rect x="4.5" y="4.5" width="8" height="6" />
              <line x1="8.5" y1="10.5" x2="8.5" y2="12.5" />
            </g>
          </svg>
        </span>
        <span class="qg-lg-label">${escapeHtml(String(lbl))}</span>
      `;
      root.appendChild(row);

      // mean line (line + dot)
      row = document.createElement('div');
      row.className = 'qg-legend-item';
      try{ row.style.setProperty('--qg-c', String(color||'')); }catch(e){}
      row.innerHTML = `
        <span class="qg-lg-mean" aria-hidden="true">
          <svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
            <line x1="1" y1="7" x2="15" y2="7" stroke="currentColor" stroke-opacity="1" stroke-width="2" stroke-linecap="butt" />
            <circle cx="8" cy="7" r="2.2" fill="currentColor" fill-opacity="1" />
          </svg>
        </span>
        <span class="qg-lg-label">${escapeHtml(String(lbl))}</span>
      `;
      root.appendChild(row);
    };

    const _getSelTools = ()=>{
      const all = (QG && QG.tools && Array.isArray(QG.tools)) ? QG.tools : [];
      const ss  = (QG && QG.sel && QG.sel.tools) ? QG.sel.tools : null;
      return ss ? all.filter(t => ss.has(t)) : all.slice();
    };
    const _getSelCavs = ()=>{
      const all = (QG && QG.cavities && Array.isArray(QG.cavities)) ? QG.cavities : [];
      const ss  = (QG && QG.sel && QG.sel.cavities) ? QG.sel.cavities : null;
      return ss ? all.filter(c => ss.has(c)) : all.slice();
    };

    const _addShapeLevel = (lbl, shape, color)=>{
      const row = document.createElement('div');
      row.className = 'qg-legend-item';
      row.innerHTML = `
        <span class="qg-lg-mean" aria-hidden="true">
          ${qgLegendMarkerSvgMarkup(shape, color, 1)}
        </span>
        <span class="qg-lg-label">${escapeHtml(String(lbl))}</span>
      `;
      root.appendChild(row);
    };

    const _added = new Set();
    const _addVarSection = (vk)=>{
      if (!vk) return;
      const k = String(vk);
      if (_added.has(k)) return;

      const isColorEncoded = (k === colorVar) || (!colorVar && k === overlayVar);
      const isShapeEncoded = (k === shapeVar);
      if (!isColorEncoded && !isShapeEncoded) return;

      let levels = [];
      let head = '';
      if (k === 'tool'){
        levels = _getSelTools();
        head = 'Tool';
      }else if (k === 'cavity'){
        levels = _getSelCavs();
        head = 'Cavity';
      }else{
        return;
      }

      _added.add(k);
      _addHeader(head);
      for (const lv of levels){
        const entryColor = isColorEncoded ? qgGetColorForVarValue(k, lv) : '#444444';
        if (isShapeEncoded){
          _addShapeLevel(lv, qgGetShapeForVarValue(k, lv), entryColor);
        }else{
          _addLevelPair(lv, entryColor);
        }
      }
    };

    _addVarSection(overlayVar);
    _addVarSection(colorVar);
    _addVarSection(shapeVar);

// 3) USL/LSL dashed per selected FAI (base limits stay fixed; OOC SPEC is a separate overlay line)
    for (const m of meta){
      const _col = (QG.cols && Array.isArray(QG.cols)) ? (QG.cols.find(c => c && c.key === m.key) || null) : null;
      const _cap = (QG.captionByColKey && QG.captionByColKey[m.key]) ? QG.captionByColKey[m.key] : null;
      const _numFmt = (_cap && _cap.numFmt) ? _cap.numFmt : 'auto';
      const _srcLab = String(m.label||'');
      const _shortLab = (function(s){
        try{ s = (s||'').toString(); }catch(e){ s=''; }
        const max = 14;
        if (s.length <= max) return s;
        return s.slice(0, Math.max(0, max-3)) + '...';
      })(_srcLab);

      const _baseParts = [];
      try{
        const _u = _col ? qgGetBaseLimitValueForCol(_col, 'usl') : null;
        if (_u !== null){
          const _uf = qgFmtCaptionNum(_u, _numFmt);
          _baseParts.push(_uf ? ('USL(' + _uf + ')') : 'USL');
        }
      }catch(e){}
      try{
        const _l = _col ? qgGetBaseLimitValueForCol(_col, 'lsl') : null;
        if (_l !== null){
          const _lf = qgFmtCaptionNum(_l, _numFmt);
          _baseParts.push(_lf ? ('LSL(' + _lf + ')') : 'LSL');
        }
      }catch(e){}

      if (_baseParts.length){
        const lim = document.createElement('div');
        lim.className = 'qg-legend-item';
        try{ lim.style.setProperty('--qg-c', String(m.color||'#2b5bd7')); }catch(e){}
        const _baseTxt = _baseParts.join(' / ');
        const _labFull = _baseTxt + (_srcLab ? (' (' + _srcLab + ')') : '');
        const _lab = _baseTxt + (_shortLab ? (' (' + _shortLab + ')') : '');
        lim.innerHTML = `
          <span class="qg-lg-mean qg-lg-limit" aria-hidden="true">
            <svg viewBox="0 0 14 12" xmlns="http://www.w3.org/2000/svg">
              <line x1="1" y1="6" x2="13" y2="6" stroke="currentColor" stroke-width="2" stroke-dasharray="4 3" stroke-linecap="butt" />
            </svg>
          </span>
          <span class="qg-lg-label" title="${escapeHtml(_labFull)}">${escapeHtml(_lab)}</span>
        `;
        root.appendChild(lim);
      }

      if (_col && qgGetColOocLineVisible(_col)){
        const _ooc = qgGetScaledOocLimitsForCol(_col);
        const _oocParts = [];
        try{
          if (_ooc && _ooc.usl !== null && _ooc.usl !== undefined){
            const _uf = qgFmtCaptionNum(_ooc.usl, _numFmt);
            _oocParts.push(_uf ? ('OOC USL(' + _uf + ')') : 'OOC USL');
          }
        }catch(e){}
        try{
          if (_ooc && _ooc.lsl !== null && _ooc.lsl !== undefined){
            const _lf = qgFmtCaptionNum(_ooc.lsl, _numFmt);
            _oocParts.push(_lf ? ('OOC LSL(' + _lf + ')') : 'OOC LSL');
          }
        }catch(e){}

        if (_oocParts.length){
          const lim = document.createElement('div');
          lim.className = 'qg-legend-item';
          try{ lim.style.setProperty('--qg-c', String(m.color||'#2b5bd7')); }catch(e){}
          const _oocTxt = _oocParts.join(' / ');
          const _labFull = _oocTxt + (_srcLab ? (' (' + _srcLab + ')') : '');
          const _lab = _oocTxt + (_shortLab ? (' (' + _shortLab + ')') : '');
          lim.innerHTML = `
            <span class="qg-lg-mean qg-lg-limit" aria-hidden="true">
              <svg viewBox="0 0 18 12" xmlns="http://www.w3.org/2000/svg">
                <line x1="1" y1="6" x2="17" y2="6" stroke="currentColor" stroke-width="2" stroke-dasharray="8 3 2 3" stroke-linecap="butt" />
              </svg>
            </span>
            <span class="qg-lg-label" title="${escapeHtml(_labFull)}">${escapeHtml(_lab)}</span>
          `;
          root.appendChild(lim);
        }
      }
    }

    // 4) Variable lines (가변선) per selected FAI (added by right-click on plot)
    const vItems = [];
    for (const m of meta){
      const arr = (QG.varLines && QG.varLines[m.key]) ? QG.varLines[m.key] : [];
      for (let _i=0; _i<arr.length; _i++){
        const it = arr[_i];
        if (!it) continue;
        const v = (it.value !== undefined && it.value !== null) ? Number(it.value) : NaN;
        if (!isFinite(v)) continue;
        vItems.push({ seriesKey: m.key, seriesLabel: m.label, value: v, idx: _i });
      }
    }
    if (vItems.length){
      const _st = (typeof qgEnsureUserStyle === 'function') ? qgEnsureUserStyle() : (QG.userStyle||{});
      const _vs = (_st && _st.varline) ? _st.varline : {};
      const _vColor = (_vs.color || '#000000');
      const _vDash  = (_vs.dash !== undefined && _vs.dash !== null) ? String(_vs.dash) : '10 3 2 3';
      const _vPref  = qgNormVarPrefix(_vs.labelPrefix);
      const _vDashIcon = (_vDash && _vDash.trim() !== '') ? _vDash.trim() : '';
      const _vWIcon = (isFinite(Number(_vs.width)) && Number(_vs.width) > 0) ? Math.max(1, Math.min(6, Number(_vs.width))) : 2;
      const multi = meta.length > 1;
      for (const it of vItems){
        const row = document.createElement('div');
        row.className = 'qg-legend-item';
        let _op = 1;
        try{
          const vs = ensureVarStyle(it.seriesKey);
          if (vs && vs.opacity !== undefined && vs.opacity !== null) _op = qgClamp01(vs.opacity);
        }catch(e){}
        // Keep legend label text black; colorize icon only via CSS variable.
        try{ row.style.setProperty('--qg-c', _vColor); }catch(e){}
        row.innerHTML = `
          <span class="qg-lg-vline" aria-hidden="true">
            <svg viewBox="0 0 16 14" xmlns="http://www.w3.org/2000/svg">
              <line x1="1" y1="7" x2="15" y2="7" stroke="currentColor" stroke-opacity="${_op}" stroke-width="${_vWIcon}" ${_vDashIcon ? (`stroke-dasharray="${_vDashIcon}"`) : ""} />
            </svg>
          </span>
          <span class="qg-lg-label">${escapeHtml(multi ? (_vPref + String((it.idx||0)+1) + '(' + it.seriesLabel + ') ' + fmtTick(it.value)) : (_vPref + String((it.idx||0)+1) + ' ' + fmtTick(it.value)))}</span>
        `;
        root.appendChild(row);
      }
    }
}

function renderGrid(){
  const grid = qs('#qgGrid');
  if (!grid) return;
  grid.innerHTML = '';

  const keys = selectedColKeysInOrder();
  try{ QG._visibleColKeys = Array.isArray(keys) ? keys.slice() : []; }catch(e){}
  try{ qgUpdateToolbarSplitOverlays(); }catch(e){}
  if (!keys.length || !QG.seriesByCol){
    const e = document.createElement('div');
    e.className = 'qg-empty';
    e.textContent = '표시할 데이터가 없습니다.';
    grid.appendChild(e);
    return;
  }

  // Y axis range is per selected FAI (JMP-like). The sidebar inputs always edit the active(=primary) FAI.

  const selTools = QG.tools.filter(t => QG.sel.tools.has(t));
  const selCavs  = QG.cavities.filter(c => QG.sel.cavities.has(c));

  if (!selTools.length || !selCavs.length){
    if (!selTools.length) QG.tools.forEach(t => QG.sel.tools.add(t));
    if (!selCavs.length) QG.cavities.forEach(c => QG.sel.cavities.add(c));
  }
  const selTools2 = QG.tools.filter(t => QG.sel.tools.has(t));
  const selCavs2  = QG.cavities.filter(c => QG.sel.cavities.has(c));

  if (!selTools2.length || !selCavs2.length){
    const e = document.createElement('div');
    e.className = 'qg-empty';
    e.textContent = '표시할 데이터가 없습니다.';
    grid.appendChild(e);
    return;
  }

  // Layout rule (JMP-like):
  // - Columns: Tool (wrapping to next row when too many to fit)
  // - Sub-columns: Cavity (1CAV~4CAV) under each Tool
  // - Rows: selected FAI (no hard limit; distribute within the same graph box)
  const main = qs('.qg-main');
  const mainH = main ? main.clientHeight : 800;
  // Tighten vertical spacing between FAI rows (JMP-like “connected” panels)
  // NOTE: CSS also removes margins so panels can touch with no dark gaps.
  const gapY = 0;
  const rowsN = Math.max(1, keys.length);
  const mainPad = (()=>{
    try{
      if (!main) return 16;
      const cs = getComputedStyle(main);
      const pt = parseFloat(cs.paddingTop||'0')||0;
      const pb = parseFloat(cs.paddingBottom||'0')||0;
      return pt + pb;
    }catch(e){ return 16; }
  })();
  const slack = 10;

  // Decide how many Tool columns can fit in one row (then wrap)
  const gridW = (grid && grid.clientWidth) ? grid.clientWidth : (main ? main.clientWidth : 1200);
  const plotW = Math.max(520, gridW - 44); // subtract label/gap roughly
  const minToolW = Math.max(220, (selCavs2.length * 70) + 40); // tuned to resemble JMP packing
  let toolsPerRow = Math.max(1, Math.floor(plotW / minToolW));
  // Prevent unnecessary wrapping for small Tool counts (JMP-like)
  if (selTools2.length <= 3) toolsPerRow = selTools2.length;
  toolsPerRow = Math.min(selTools2.length, toolsPerRow);
  if (toolsPerRow < 1) toolsPerRow = 1;

  const toolRows = [];
  for (let i=0; i<selTools2.length; i+=toolsPerRow){
    toolRows.push(selTools2.slice(i, i+toolsPerRow));
  }


  // Prevent tiny overflows that cause a pointless scrollbar:
  // Distribute available height across Tool row-groups (no "1px scroll" just because of date labels).
  const groupCount = Math.max(1, toolRows.length);
  const gridGapY = 0;    // matches CSS: #qgGrid gap
  const groupMB = 0;     // matches CSS: .qg-tool-group margin-bottom
  const outer = (groupCount - 1) * gridGapY + groupCount * groupMB;
  const groupCapH = Math.max(220, Math.floor((mainH - mainPad - outer - 16) / groupCount));

  let anyAdded = 0;

  for (const toolsRow of toolRows){
    const group = document.createElement('div');
    group.className = 'qg-tool-group';

    // Header (once per Tool-row block)
    const head = document.createElement('div');
    head.className = 'qg-tophead';

    const toolCols = toolsRow.length;
    const cavCols = toolsRow.length * selCavs2.length;

    const toolCells = toolsRow.map((t, i)=>{
      const cls = 'qg-tophead-tool' + (i===0 ? ' first' : '');
      return `<div class="${cls}">${escapeHtml(t)}</div>`;
    }).join('');

    const cavCells = [];
    for (let ti=0; ti<toolsRow.length; ti++){
      for (let ci=0; ci<selCavs2.length; ci++){
        const c = selCavs2[ci];
        const lab = (String(c).toUpperCase().includes('CAV') ? String(c) : (String(c) + 'CAV'));
        const cls = 'qg-tophead-cav' + (ci===0 ? ' tool-start' : '');
        cavCells.push(`<div class="${cls}">${escapeHtml(lab)}</div>`);
      }
    }

    head.innerHTML = `
      <div class="qg-tophead-stub"></div>
      <div class="qg-tophead-body">
        <div class="qg-tophead-band">
          <div class="qg-tophead-row">Tool</div>
          <div class="qg-tophead-tools" style="grid-template-columns: repeat(${toolCols}, 1fr);">
            ${toolCells}
          </div>
          <div class="qg-tophead-row">Cavity</div>
          <div class="qg-tophead-cavs" style="grid-template-columns: repeat(${cavCols}, 1fr);">
            ${cavCells.join('')}
          </div>
        </div>
      </div>
    `;

    group.appendChild(head);

    // Attach early so we can measure header height for a sensible row height (prevents unwanted scrollbars)
    grid.appendChild(group);
    const headerH = Math.ceil(head.getBoundingClientRect().height || 0);

    const footerH = 56; // reserve date-label space once for the whole tool-row block
    const availPlot = Math.max(120, (groupCapH - headerH - 16 - footerH) - (rowsN-1)*gapY);
    const rowPlotH = Math.max(42, Math.floor(availPlot / rowsN));

    let groupAdded = 0;

    for (let ki=0; ki<keys.length; ki++){
      const colKey = keys[ki];
      const series = QG.seriesByCol[colKey];
      if (!series) continue;

      const col = QG.cols.find(c => c.key === colKey) || null;
      const usl = col ? qgGetBaseLimitValueForCol(col, 'usl') : null;
      const lsl = col ? qgGetBaseLimitValueForCol(col, 'lsl') : null;
      const oocLim = (col && qgGetColOocLineVisible(col)) ? qgGetScaledOocLimitsForCol(col) : { usl: null, lsl: null };

      // Union dates across the entire (Tool x Cavity) matrix in this tool-row block
      const dateMap = new Map();
      for (const tool of toolsRow){
        for (const cav of selCavs2){
          const s = (((series||{}))[tool]||{})[cav]||{};
          for (const d of Object.keys(s)){
            const di = normalizeDateKey(d);
            if (!di.key) continue;
            const ex = dateMap.get(di.key);
            if (!ex){
              di._alts = [d];
              dateMap.set(di.key, di);
            }else{
              if (!ex._alts) ex._alts = [];
              if (ex._alts.indexOf(d) === -1) ex._alts.push(d);
            }
          }
        }
      }
      const dates = Array.from(dateMap.values()).sort(sortDateInfo);
      if (!dates.length) continue;

      const row = document.createElement('div');
      row.className = 'qg-fai-row';
      try{ row.dataset.colKey = String(colKey); }catch(e){}
      row.innerHTML = `
        <div class="qg-row-label"><div class="vtxt">${escapeHtml(qgGetDisplayLabel(colKey))}</div></div>
        <div class="qg-fai-one"></div>
      `;
      const wrap = qs('.qg-fai-one', row);

      // Double-click row label to rename display label (legend/user-define/graph label only)
      const lblEl = qs('.qg-row-label', row);
      if (lblEl && !lblEl._qgRename){
        lblEl._qgRename = true;
        try{ lblEl.style.cursor = 'text'; }catch(e){}
        lblEl.addEventListener('dblclick', (ev)=>{
          try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
          const k = String(colKey || '');
          const col0 = (QG.cols || []).find(c => String(c.key) === k) || null;
          const base = col0 ? String(col0.label || k) : k;
          const cur = qgGetDisplayLabel(k);
          qgOpenRenameDlg({ colKey: k, base: base, cur: cur });
        }, { passive:false });
      }

      // Lock row wrapper/label height to the SVG height so many stacked FAIs keep a clean seam.
      try{
        row.style.margin = '0';
        row.style.padding = '0';
        row.style.minHeight = '0';
        row.style.overflow = 'hidden';
      }catch(e){}
      try{
        wrap.style.margin = '0';
        wrap.style.padding = '0';
        wrap.style.minHeight = '0';
        wrap.style.overflow = 'hidden';
        wrap.style.lineHeight = '0';
      }catch(e){}
      try{
        if (lblEl){
          lblEl.style.minHeight = '0';
          lblEl.style.overflow = 'hidden';
          lblEl.style.borderRadius = '0';
        }
      }catch(e){}

      const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
      svg.classList.add('qg-svg');
      try{ svg.dataset.colKey = String(colKey); svg.setAttribute('data-col-key', String(colKey)); }catch(e){}
      svg.setAttribute('viewBox', '0 0 1200 320');
      svg.setAttribute('preserveAspectRatio','none');
      svg.style.height = '320px';
      svg.style.pointerEvents = 'all';

      // Clicking a chart should switch the axis/limit editor to that FAI (primary selection)
      if (!svg._qgPick){
        svg._qgPick = true;
        svg.addEventListener('pointerdown', (ev)=>{
          try{
            if (ev.button !== undefined && ev.button !== 0) return;
          }catch(e){}
          setPrimaryColKey(colKey);
          // keep the UI in sync without rebuilding everything
          renderFaiList();
        }, { passive:true });
      }

      // Dates only once per tool-row block (on the last FAI row)
      const showXLabels = (ki === keys.length - 1);
      const rowSvgH = rowPlotH + (showXLabels ? footerH : 0);
      svg.setAttribute('viewBox', `0 0 1200 ${rowSvgH}`);
      svg.style.height = rowSvgH + 'px';
      svg.style.display = 'block';
      try{
        row.style.height = rowSvgH + 'px';
        wrap.style.height = rowSvgH + 'px';
        if (lblEl) lblEl.style.height = rowSvgH + 'px';
      }catch(e){}

      const prevSeries = QG.series;
      QG.series = series;
      const seriesColor = QG_SERIES_COLORS[ki % QG_SERIES_COLORS.length];
      const ax = getAxisState(colKey);
      drawMatrixSvg(svg, toolsRow, selCavs2, dates, { usl, lsl, oocUsl: oocLim.usl, oocLsl: oocLim.lsl, yMinO: ax.yMin, yMaxO: ax.yMax, showXLabels, h: rowSvgH, color: seriesColor, colKey: colKey, label: qgGetDisplayLabel(colKey), rowIndex: ki, rowCount: rowsN });
      QG.series = prevSeries;

      wrap.appendChild(svg);
      group.appendChild(row);
      groupAdded += 1;
      anyAdded += 1;
    }

    if (!groupAdded){
      try{ group.remove(); }catch(e){}
    }
  }

  if (!anyAdded){
    const e = document.createElement('div');
    e.className = 'qg-empty';
    e.textContent = '표시할 데이터가 없습니다.';
    grid.appendChild(e);
  }
}

function drawMatrixSvg(svg, tools, cavs, dates, opt){
  if (!svg) return;

  const seriesColor = (opt && opt.color) ? String(opt.color) : '#2b5bd7';
  const vis = qgResolveVisualChannels();
  const overlayVar = vis.overlayVar;
  const colorVar   = vis.colorVar;
  const shapeVar   = vis.shapeVar;

  const _colKey = (opt && opt.colKey) ? String(opt.colKey) : '';
  const _label  = (opt && opt.label) ? String(opt.label) : _colKey;

  const _pe = qgGetPlotElemsForColKey(_colKey);
  const _showPts  = !!_pe.points;
  const _showLine = !!_pe.line;
  const _showBox  = !!_pe.box;


  const W = 1200, H = (opt && opt.h ? opt.h : 320);
  const rowIndex = Math.max(0, Number(opt && opt.rowIndex) || 0);
  const rowCount = Math.max(1, Number(opt && opt.rowCount) || 1);
  // Use wider dynamic side pads when many FAIs are stacked so Y labels / USL labels never get clipped.
  const _fmtCandidates = [];
  try{
    if (opt && opt.usl !== null && opt.usl !== undefined && isFinite(Number(opt.usl))) _fmtCandidates.push(fmtTick(Number(opt.usl)));
    if (opt && opt.lsl !== null && opt.lsl !== undefined && isFinite(Number(opt.lsl))) _fmtCandidates.push(fmtTick(Number(opt.lsl)));
    if (opt && opt.oocUsl !== null && opt.oocUsl !== undefined && isFinite(Number(opt.oocUsl))) _fmtCandidates.push(fmtTick(Number(opt.oocUsl)));
    if (opt && opt.oocLsl !== null && opt.oocLsl !== undefined && isFinite(Number(opt.oocLsl))) _fmtCandidates.push(fmtTick(Number(opt.oocLsl)));
  }catch(e){}
  const _maxNumChars = _fmtCandidates.reduce((m, s)=> Math.max(m, String(s||'').length), 0);
  const padL = Math.max(62, 28 + (_maxNumChars * 7));
  const padR = Math.max(46, 22 + (_maxNumChars * 7));
  const padT = 0, padB = (opt && opt.showXLabels===false) ? 0 : 56;
  const innerW = W - padL - padR;
  const innerH = H - padT - padB;

  qgSetPadCssVars(padL, padR, W);

  const nT = Math.max(1, tools.length);
  const nC = Math.max(1, cavs.length);
  const nP = nT * nC;
  const gap = 0;
  const panelW = (innerW - gap*(nP-1)) / nP;

  // y range across all panels (tool x cavity x date)
  let yMin = Infinity, yMax = -Infinity;
  for (const tool of tools){
    for (const cav of cavs){
      const s = ((QG.series||{})[tool]||{})[cav]||{};
      for (const d of dates){
        const dk = (d && d.key) ? d.key : d;
        const p = qgPickDatePoint(s, d);
        if (!p) continue;
        yMin = Math.min(yMin, p.min, p.mean);
        yMax = Math.max(yMax, p.max, p.mean);
      }
    }
  }
  if (opt && opt.lsl !== null) yMin = Math.min(yMin, opt.lsl);
  if (opt && opt.usl !== null) yMax = Math.max(yMax, opt.usl);
  if (opt && opt.oocLsl !== null && opt.oocLsl !== undefined) yMin = Math.min(yMin, opt.oocLsl);
  if (opt && opt.oocUsl !== null && opt.oocUsl !== undefined) yMax = Math.max(yMax, opt.oocUsl);

  const hasYMinO = !!(opt && opt.yMinO !== null && opt.yMinO !== undefined && isFinite(opt.yMinO));
  const hasYMaxO = !!(opt && opt.yMaxO !== null && opt.yMaxO !== undefined && isFinite(opt.yMaxO));

  if (hasYMinO) yMin = opt.yMinO;
  if (hasYMaxO) yMax = opt.yMaxO;

  if (!isFinite(yMin) || !isFinite(yMax)) {
    yMin = -0.001;
    yMax =  0.001;
  }

  if (yMax - yMin < 1e-12){
    const center = (Number(yMin) + Number(yMax)) / 2;
    let delta = Math.max(Math.abs(center) * 0.05, 1e-4);
    if (!isFinite(delta) || delta <= 0) delta = 1e-4;
    yMin = center - delta;
    yMax = center + delta;
  } else {
    if (!hasYMinO || !hasYMaxO){
      const pad = (yMax - yMin) * 0.08;
      if (!hasYMaxO) yMax += pad;
      if (!hasYMinO) yMin -= pad;
    }
  }

  // snap to 0.001 steps for cleaner axis labels (when not user-fixed)
  const stepY = 0.001;
  if (!hasYMinO){
    yMin = Math.floor(yMin / stepY) * stepY;
  }
  if (!hasYMaxO){
    yMax = Math.ceil(yMax / stepY) * stepY;
  }
  if (yMax - yMin < stepY){
    const c = (yMin + yMax) / 2;
    yMin = c - stepY;
    yMax = c + stepY;
  }

  const yAt = (v)=> padT + (1 - ((v - yMin)/(yMax - yMin))) * innerH;

  // x scale in each panel (same dates index)
  const nD = Math.max(1, dates.length);
  const xPad = Math.min(18, Math.max(10, panelW * 0.18));
  const xInPanel = (idx)=> (nD === 1 ? (xPad + (panelW-2*xPad)/2) : (xPad + (idx/(nD-1))*(panelW-2*xPad)));

  const ns = 'http://www.w3.org/2000/svg';
  while (svg.firstChild) svg.removeChild(svg.firstChild);

  // plot background (per-FAI)
  let _bgColor = '#ffffff';
  let _bgOpacity = 1;
  let _bgStart = null;
  let _bgEnd = null;
  try{
    const bs = (_colKey && QG.bgStyleByCol && QG.bgStyleByCol[_colKey]) ? QG.bgStyleByCol[_colKey] : null;
    if (bs){
      if (bs.color) _bgColor = String(bs.color);
      if (bs.opacity !== undefined && bs.opacity !== null) _bgOpacity = qgClamp01(bs.opacity);
      const s0 = (bs.start !== undefined && bs.start !== null) ? Number(bs.start) : NaN;
      const e0 = (bs.end !== undefined && bs.end !== null) ? Number(bs.end) : NaN;
      if (isFinite(s0) && isFinite(e0)){
        _bgStart = s0;
        _bgEnd = e0;
      }
    }
  }catch(e){}

  // background
  const bg = document.createElementNS(ns,'rect');
  bg.setAttribute('x','0'); bg.setAttribute('y','0');
  bg.setAttribute('width', String(W)); bg.setAttribute('height', String(H));
  // Paper/background outside plot area should stay neutral; only the plot area is user-colored.
  bg.setAttribute('fill', '#ffffff');
  try{ bg.setAttribute('pointer-events','none'); }catch(e){}
  svg.appendChild(bg);

  const plot = document.createElementNS(ns,'rect');
  plot.setAttribute('x', String(padL));
  plot.setAttribute('y', String(padT));
  plot.setAttribute('width', String(innerW));
  plot.setAttribute('height', String(innerH));
  const _bgRangeOn = (_bgStart !== null && _bgEnd !== null);
  if (_bgRangeOn){
    // base plot stays neutral; apply the colored band only within [start,end]
    plot.setAttribute('fill', '#ffffff');
    plot.setAttribute('fill-opacity', '1');
  }else{
    // legacy behavior: color the whole plot area
    plot.setAttribute('fill', _bgColor);
    plot.setAttribute('fill-opacity', String(_bgOpacity));
  }
  // Repeating a full rect stroke for every stacked FAI row creates visible double seams.
  plot.setAttribute('stroke','none');
  plot.setAttribute('stroke-width','0');
  try{ plot.setAttribute('pointer-events','none'); }catch(e){}
  svg.appendChild(plot);

  // background band within value range (per-FAI)
  let band = null;
  if (_bgRangeOn){
    try{
      const y1 = yAt(_bgStart);
      const y2 = yAt(_bgEnd);
      let yTop = Math.min(y1, y2);
      let yBot = Math.max(y1, y2);
      // clamp to plot area
      yTop = Math.max(padT, Math.min(padT + innerH, yTop));
      yBot = Math.max(padT, Math.min(padT + innerH, yBot));
      const h = Math.max(0, yBot - yTop);
      if (h > 0.5){
        band = document.createElementNS(ns,'rect');
        band.setAttribute('x', String(padL));
        band.setAttribute('y', String(yTop));
        band.setAttribute('width', String(innerW));
        band.setAttribute('height', String(h));
        band.setAttribute('fill', _bgColor);
        band.setAttribute('fill-opacity', String(_bgOpacity));
        band.setAttribute('stroke', 'none');
        try{ band.setAttribute('pointer-events','none'); }catch(e){}
        svg.appendChild(band);
      }
    }catch(e){}
  }

  // clip (keep plot contents inside the plot area)
  const clipId = 'qgClip_' + Math.floor(Math.random() * 1e9);
  const defs = document.createElementNS(ns,'defs');
  const cp = document.createElementNS(ns,'clipPath');
  cp.setAttribute('id', clipId);
  const cpr = document.createElementNS(ns,'rect');
  cpr.setAttribute('x', String(padL));
  cpr.setAttribute('y', String(padT));
  cpr.setAttribute('width', String(innerW));
  cpr.setAttribute('height', String(innerH));
  cp.appendChild(cpr);
  defs.appendChild(cp);
  svg.appendChild(defs);
  const clipUrl = 'url(#' + clipId + ')';
  const clip = (el)=>{ try{ el.setAttribute('clip-path', clipUrl); }catch(e){} };

  // y-axis ticks + grid (JMP-like)
  function niceStep(range, targetTicks){
    const t = Math.max(1, targetTicks || 6);
    const r = Number(range);
    if (!isFinite(r) || r <= 0) return 1;
    const rough = r / t;
    const p = Math.pow(10, Math.floor(Math.log10(rough)));
    const x = rough / p;
    let m = 10;
    if (x <= 1) m = 1;
    else if (x <= 2) m = 2;
    else if (x <= 5) m = 5;
    else m = 10;
    return m * p;
  }

  const yRange = (yMax - yMin);
  // When many FAIs are stacked, reduce tick density to keep the rows visually connected like JMP.
  const denseRows = rowCount >= 6;
  const targetMajorTicks = Math.max(2, Math.min(denseRows ? 5 : 7, Math.floor(innerH / (denseRows ? 24 : 18))));
  const major = niceStep(yRange, targetMajorTicks);
  const minor = major / 5;
  const yAxisX = padL;
  const yTickFontPx = Math.max(8, Math.min(11, Math.floor(innerH / (denseRows ? 6 : 5))));

  // minor tick marks (no labels)
  const minorStart = Math.ceil(yMin / minor) * minor;
  for (let v = minorStart; v <= yMax + minor * 0.5; v += minor){
    const y = yAt(v);
    const ln = document.createElementNS(ns,'line');
    ln.setAttribute('x1', String(yAxisX - 3));
    ln.setAttribute('x2', String(yAxisX));
    ln.setAttribute('y1', String(y));
    ln.setAttribute('y2', String(y));
    ln.setAttribute('stroke','rgba(0,0,0,0.18)');
    ln.setAttribute('stroke-width','1');
    svg.appendChild(ln);
  }

  // major grid lines + labels
  const tickVals = [];
  const yStart = Math.ceil(yMin / major) * major;
  const yEnd   = Math.floor(yMax / major) * major;
  for (let v = yStart; v <= yEnd + major * 0.5; v += major){
    tickVals.push(v);
  }
  if (!tickVals.length){
    tickVals.push(yMin, yMax);
  }else{
    if (Math.abs(tickVals[0] - yMin) > major * 0.25) tickVals.unshift(yMin);
    if (Math.abs(tickVals[tickVals.length - 1] - yMax) > major * 0.25) tickVals.push(yMax);
  }

  for (let _ti=0; _ti<tickVals.length; _ti++){
    const v = Number(tickVals[_ti]);
    const y = yAt(v);

    if (!QG.gridHidden){
    const gl = document.createElementNS(ns,'line');
    gl.setAttribute('x1', String(padL));
    gl.setAttribute('x2', String(W - padR));
    gl.setAttribute('y1', String(y));
    gl.setAttribute('y2', String(y));
    gl.setAttribute('stroke','rgba(0,0,0,0.10)');
    gl.setAttribute('stroke-width','1');
    clip(gl);
    svg.appendChild(gl);
    }
    const tk = document.createElementNS(ns,'line');
    tk.setAttribute('x1', String(yAxisX - 6));
    tk.setAttribute('x2', String(yAxisX));
    tk.setAttribute('y1', String(y));
    tk.setAttribute('y2', String(y));
    tk.setAttribute('stroke','rgba(0,0,0,0.35)');
    tk.setAttribute('stroke-width','1');
    svg.appendChild(tk);

    const skipTopLabel = (rowIndex > 0 && _ti === tickVals.length - 1);
    const skipBottomLabel = (rowIndex < rowCount - 1 && _ti === 0);
    if (skipTopLabel || skipBottomLabel) continue;

    const tx = document.createElementNS(ns,'text');
    const yLbl = Math.max(padT + yTickFontPx * 0.85, Math.min(padT + innerH - yTickFontPx * 0.35, y));
    tx.setAttribute('x', String(yAxisX - 8));
    tx.setAttribute('y', String(yLbl));
    tx.setAttribute('font-size', String(yTickFontPx));
    tx.setAttribute('fill','rgba(0,0,0,0.70)');
    tx.setAttribute('text-anchor','end');
    tx.setAttribute('dominant-baseline','middle');
    tx.textContent = fmtTick(v);
    svg.appendChild(tx);
  }

  // 0 line (if inside range)
  if (0 >= yMin && 0 <= yMax){
    const y0 = yAt(0);
    const z = document.createElementNS(ns,'line');
    z.setAttribute('x1', String(padL)); z.setAttribute('x2', String(W - padR));
    z.setAttribute('y1', String(y0)); z.setAttribute('y2', String(y0));
    z.setAttribute('stroke','rgba(0,0,0,0.15)');
    z.setAttribute('stroke-width','1');
    clip(z);
    svg.appendChild(z);
  }

  // vertical separators (between cavities and between tools)
  for (let i=1; i<nP; i++){
    const x = padL + i*panelW;
    const isToolBoundary = (i % nC) === 0;
    const ln = document.createElementNS(ns,'line');
    ln.setAttribute('x1', String(x)); ln.setAttribute('x2', String(x));
    ln.setAttribute('y1', String(padT)); ln.setAttribute('y2', String(padT + innerH));
    ln.setAttribute('stroke', isToolBoundary ? '#bdbdbd' : '#d4d4d4');
    ln.setAttribute('stroke-width', isToolBoundary ? '2' : '1');
    clip(ln);
    svg.appendChild(ln);
  }

  // Draw only the outer box edges that should remain visible when panels are stacked.
  const outerStroke = '#cfcfcf';
  if (rowIndex === 0){
    const topEdge = document.createElementNS(ns,'line');
    topEdge.setAttribute('x1', String(padL));
    topEdge.setAttribute('x2', String(W - padR));
    topEdge.setAttribute('y1', String(padT));
    topEdge.setAttribute('y2', String(padT));
    topEdge.setAttribute('stroke', outerStroke);
    topEdge.setAttribute('stroke-width', '1');
    svg.appendChild(topEdge);
  }
  if (rowIndex === rowCount - 1){
    const botEdge = document.createElementNS(ns,'line');
    botEdge.setAttribute('x1', String(padL));
    botEdge.setAttribute('x2', String(W - padR));
    botEdge.setAttribute('y1', String(padT + innerH));
    botEdge.setAttribute('y2', String(padT + innerH));
    botEdge.setAttribute('stroke', outerStroke);
    botEdge.setAttribute('stroke-width', '1');
    svg.appendChild(botEdge);
  }
  const leftEdge = document.createElementNS(ns,'line');
  leftEdge.setAttribute('x1', String(padL));
  leftEdge.setAttribute('x2', String(padL));
  leftEdge.setAttribute('y1', String(padT));
  leftEdge.setAttribute('y2', String(padT + innerH));
  leftEdge.setAttribute('stroke', outerStroke);
  leftEdge.setAttribute('stroke-width', '1');
  svg.appendChild(leftEdge);
  const rightEdge = document.createElementNS(ns,'line');
  rightEdge.setAttribute('x1', String(W - padR));
  rightEdge.setAttribute('x2', String(W - padR));
  rightEdge.setAttribute('y1', String(padT));
  rightEdge.setAttribute('y2', String(padT + innerH));
  rightEdge.setAttribute('stroke', outerStroke);
  rightEdge.setAttribute('stroke-width', '1');
  svg.appendChild(rightEdge);

  // panels: tool x cavity
  for (let pi=0; pi<nP; pi++){
    const ti = Math.floor(pi / nC);
    const tool = tools[ti];
    const cav  = cavs[pi % nC];
    let panelColor = seriesColor;
    if (colorVar === 'tool') panelColor = qgGetToolColorByValue(tool);
    else if (colorVar === 'cavity') panelColor = qgGetCavityColorByValue(cav);
    else if (!colorVar && overlayVar === 'tool') panelColor = qgGetToolColorByValue(tool);
    else if (!colorVar && overlayVar === 'cavity') panelColor = qgGetCavityColorByValue(cav);

    let panelShape = 'circle';
    if (shapeVar === 'tool') panelShape = qgGetToolShapeByValue(tool);
    else if (shapeVar === 'cavity') panelShape = qgGetCavityShapeByValue(cav);

    const left = padL + pi*(panelW + gap);
    const right = left + panelW;

    // USL/LSL per panel (base limits stay fixed); OOC SPEC uses a separate overlay line
    function hLine(val, label, cfg){
      const scaledVal = Number(val);
      if (!isFinite(scaledVal)) return;
      const st = cfg || {};
      const y = yAt(scaledVal);
      const ln = document.createElementNS(ns,'line');
      ln.setAttribute('x1', String(left)); ln.setAttribute('x2', String(right));
      ln.setAttribute('y1', String(y)); ln.setAttribute('y2', String(y));
      ln.setAttribute('stroke', st.stroke ? String(st.stroke) : seriesColor);
      ln.setAttribute('stroke-width', String(st.width || 1));
      if (st.opacity !== undefined && st.opacity !== null) ln.setAttribute('stroke-opacity', String(st.opacity));
      if (st.dash) ln.setAttribute('stroke-dasharray', String(st.dash));
      clip(ln);
      svg.appendChild(ln);

      if (pi !== nP - 1) return;
      if (st.showLabel === false) return;
      const tx = document.createElementNS(ns,'text');
      const _fontPx = 10;
      const _specX = W - Math.max(6, Math.round(padR * 0.12));
      const _specY = Math.max(_fontPx, Math.min(H - 4, y));
      tx.setAttribute('x', String(_specX));
      tx.setAttribute('y', String(_specY));
      tx.setAttribute('text-anchor', 'end');
      tx.setAttribute('dominant-baseline', 'middle');
      tx.setAttribute('font-size', String(_fontPx));
      tx.setAttribute('fill','rgba(0,0,0,0.70)');
      if (st.labelOpacity !== undefined && st.labelOpacity !== null){
        try{ tx.setAttribute('opacity', String(st.labelOpacity)); }catch(e){}
      }
      tx.textContent = label + ' ' + fmtTick(scaledVal);
      svg.appendChild(tx);
    }
    if (opt && opt.usl !== null) hLine(opt.usl, 'USL', { dash:'4 3', showLabel: !QG.hideUslLslLabel });
    if (opt && opt.lsl !== null) hLine(opt.lsl, 'LSL', { dash:'4 3', showLabel: !QG.hideUslLslLabel });
    if (opt && opt.oocUsl !== null && opt.oocUsl !== undefined) hLine(opt.oocUsl, 'OOC USL', { dash:'8 3 2 3', width:1.2, showLabel:true });
    if (opt && opt.oocLsl !== null && opt.oocLsl !== undefined) hLine(opt.oocLsl, 'OOC LSL', { dash:'8 3 2 3', width:1.2, showLabel:true });

    // Variable lines (가변선) - per FAI row (colKey)
    const _vArr = (_colKey && QG.varLines && QG.varLines[_colKey]) ? QG.varLines[_colKey] : [];
    if (_vArr && _vArr.length){
      const _st = (typeof qgEnsureUserStyle === 'function') ? qgEnsureUserStyle() : (QG.userStyle||{});
      const _vs = (_st && _st.varline) ? _st.varline : {};
      const _vColor = (_vs.color || '#000000');
      const _vWidth = (isFinite(Number(_vs.width)) && Number(_vs.width) > 0) ? Number(_vs.width) : 1.5;
      const _vDash  = (_vs.dash !== undefined && _vs.dash !== null) ? String(_vs.dash) : '10 3 2 3';
      const _vPref  = qgNormVarPrefix(_vs.labelPrefix);
      let _vOpacity = 1;
      try{
        const _vps = ensureVarStyle(_colKey);
        if (_vps && _vps.opacity !== undefined && _vps.opacity !== null) _vOpacity = qgClamp01(_vps.opacity);
      }catch(e){}
      const _vLblOn = (_vs.showLabel !== false);

      for (let _vi=0; _vi<_vArr.length; _vi++){
        const it = _vArr[_vi];
        if (!it) continue;
        const v = (it.value !== undefined && it.value !== null) ? Number(it.value) : NaN;
        if (!isFinite(v)) continue;
        const y = yAt(v);
        const ln = document.createElementNS(ns,'line');
        ln.setAttribute('x1', String(left)); ln.setAttribute('x2', String(right));
        ln.setAttribute('y1', String(y)); ln.setAttribute('y2', String(y));
        ln.setAttribute('stroke', _vColor);
        ln.setAttribute('stroke-width', String(_vWidth));
        try{ ln.setAttribute('stroke-opacity', String(_vOpacity)); }catch(e){}
        if (_vDash && _vDash.trim() !== '') ln.setAttribute('stroke-dasharray', _vDash);
        try{ ln.setAttribute('data-qg-tip', _vPref + String(_vi + 1) + ': ' + fmtTick(v)); }catch(e){}
        clip(ln);
        svg.appendChild(ln);

        // Label at the line end (like USL/LSL)
        if (_vLblOn && pi === nP - 1){
          const tx = document.createElementNS(ns,'text');
          const _fontPx = 10;
          const _lblX = W - Math.max(6, Math.round(padR * 0.12));
          const _lblY = Math.max(_fontPx, Math.min(H - 4, y));
          tx.setAttribute('x', String(_lblX));
          tx.setAttribute('y', String(_lblY));
          tx.setAttribute('text-anchor','end');
          tx.setAttribute('dominant-baseline','middle');
          tx.setAttribute('font-size', String(_fontPx));
          tx.setAttribute('fill','rgba(0,0,0,0.70)');
          try{ tx.setAttribute('opacity', String(_vOpacity)); }catch(e){}
          tx.textContent = _vPref + String(_vi + 1) + ' ' + fmtTick(v);
          try{ tx.setAttribute('data-qg-tip', _vPref + String(_vi + 1) + ': ' + fmtTick(v)); }catch(e){}
          svg.appendChild(tx);
        }
      }
    }


    const s = ((QG.series||{})[tool]||{})[cav]||{};

    const meanPts = [];
    const baseBoxW = Math.max(8, Math.min(12, panelW * 0.20));
    let boxW = baseBoxW;
    try{
      const s0 = (QG.userStyle && QG.userStyle.box) ? Number(QG.userStyle.box.widthScale) : 0.2;
      if (isFinite(s0)) boxW = baseBoxW * Math.max(0.1, Math.min(1, s0));
    }catch(e){}
    boxW = Math.max(2, boxW);


    // per-FAI box style (stroke/fill) - dots are NOT affected
    let _boxColor = panelColor;
    let _boxFillColor = panelColor;
    let _boxFill = false;
    let _boxOpacity = 1;
    let _boxFillOpacity = 0.18;
    try{
      if (!QG.boxStyleByCol) QG.boxStyleByCol = {};
      const bs = (_colKey && QG.boxStyleByCol[_colKey]) ? QG.boxStyleByCol[_colKey] : null;
      if (bs && bs.color) _boxColor = String(bs.color);
      if (bs && bs.fillColor) _boxFillColor = String(bs.fillColor);
      if (bs && bs.fill) _boxFill = true;
      if (bs && bs.opacity !== undefined && bs.opacity !== null) _boxOpacity = qgClamp01(bs.opacity);
      if (bs && bs.fillOpacity !== undefined && bs.fillOpacity !== null) _boxFillOpacity = qgClamp01(bs.fillOpacity);
    }catch(e){}

    // box stroke width (global)
    let _boxStrokeW = 1;
    try{
      const sw0 = (QG.userStyle && QG.userStyle.box) ? Number(QG.userStyle.box.strokeWidth) : 1;
      if (isFinite(sw0) && sw0 > 0) _boxStrokeW = Math.max(0.5, Math.min(6, sw0));
    }catch(e){}


// per-FAI mean line style (line + dot)
    let _meanColor = panelColor;
    let _meanWidth = 2;
    let _meanDash  = '';
    let _meanDotColor = panelColor;
    let _meanOpacity = 1;
    let _meanDotOpacity = 1;
    let _meanDotSize = 3.0;
    let _dataDotOpacity = 1;
    let _dataDotSize = 2.0;
    let _dataDotColor = panelColor;
	    // Default: hide mean dots when no explicit style is set
	    let _hideMeanDots = true;
	    let _hideDataDots = false;
    try{
      if (!QG.meanStyleByCol) QG.meanStyleByCol = {};
      const ms = (_colKey && QG.meanStyleByCol[_colKey]) ? QG.meanStyleByCol[_colKey] : null;
      if (ms){
        if (ms.color) _meanColor = String(ms.color);
        const w = Number(ms.width);
        if (isFinite(w) && w > 0) _meanWidth = w;
        if (ms.dash !== undefined && ms.dash !== null) _meanDash = String(ms.dash).trim();
        if (ms.dotColor) _meanDotColor = String(ms.dotColor);
        else _meanDotColor = _meanColor;
        if (ms.opacity !== undefined && ms.opacity !== null) _meanOpacity = qgClamp01(ms.opacity);
        if (ms.dotOpacity !== undefined && ms.dotOpacity !== null) _meanDotOpacity = qgClamp01(ms.dotOpacity);
        const ds2 = Number(ms.dotSize);
        if (isFinite(ds2) && ds2 > 0) _meanDotSize = Math.max(1, Math.min(8, ds2));
        if (ms.dataDotOpacity !== undefined && ms.dataDotOpacity !== null) _dataDotOpacity = qgClamp01(ms.dataDotOpacity);
        const dds2 = Number(ms.dataDotSize);
        if (isFinite(dds2) && dds2 > 0) _dataDotSize = Math.max(1, Math.min(8, dds2));
        if (ms.dataDotColor) _dataDotColor = String(ms.dataDotColor);
	        if (ms.hideMeanDots === undefined || ms.hideMeanDots === null) _hideMeanDots = true;
	        else _hideMeanDots = !!ms.hideMeanDots;
	        _hideDataDots = !!ms.hideDataDots;
      }else{
        // default dot follows line
        _meanDotColor = _meanColor;
      }
    }catch(e){}


    for (let di=0; di<dates.length; di++){
      const d = dates[di];
      const dk = (d && d.key) ? d.key : d;
      const p = qgPickDatePoint(s, d);
      if (!p) continue;

      const x = left + xInPanel(di);
      const yMinV = yAt(p.min);
      const yMaxV = yAt(p.max);

      const top = Math.min(yMinV, yMaxV);
      const bot = Math.max(yMinV, yMaxV);
      const h = Math.max(1, bot - top);

      if (_showBox){
      const rect = document.createElementNS(ns,'rect');
      rect.setAttribute('x', String(x - boxW/2));
      rect.setAttribute('y', String(top));
      rect.setAttribute('width', String(boxW));
      rect.setAttribute('height', String(h));
      if (_boxFill){ rect.setAttribute('fill', _boxFillColor); rect.setAttribute('fill-opacity', String(_boxFillOpacity)); } else { rect.setAttribute('fill','none'); rect.removeAttribute('fill-opacity'); }
      rect.setAttribute('stroke', _boxColor);
      try{ rect.setAttribute('stroke-opacity', String(_boxOpacity)); }catch(e){}
      rect.setAttribute('stroke-width', String(_boxStrokeW));
      try{
        const _n = Array.isArray(p.vals) ? p.vals.length : 0;
        rect.setAttribute('data-qg-tip', '최소값: ' + qgFmtPointValue(p.min) + '\n최대값: ' + qgFmtPointValue(p.max) + '\nDate: ' + (d && (d.label||d.key) ? String(d.label||d.key) : String(dk)) + '\nN: ' + String(_n));
      }catch(e){}
      clip(rect);
      svg.appendChild(rect);
      }

	      if (_showPts && !_hideDataDots){
        const vals = (p.vals||[]).slice(0,3);
        for (let vi=0; vi<vals.length; vi++){
          const v = vals[vi];
          const cy = yAt(v);
          qgAppendMarkerShape(svg, panelShape, x, cy, _dataDotSize, {
            fill: _dataDotColor,
            stroke: _dataDotColor,
            opacity: _dataDotOpacity,
            clip,
            tip: 'Data ' + String(vi+1) + ': ' + qgFmtPointValue(v) + '\nDate: ' + (d && (d.label||d.key) ? String(d.label||d.key) : String(dk))
          });
        }
      }

      if (_showLine) meanPts.push({ x, y: yAt(p.mean), d, v: p.mean });
    }

    // mean line path
    if (_showLine && meanPts.length){
      let dPath = '';
      for (let i=0;i<meanPts.length;i++){
        dPath += (i===0 ? 'M' : 'L') + meanPts[i].x + ' ' + meanPts[i].y + ' ';
      }
      const path = document.createElementNS(ns,'path');
      path.setAttribute('d', dPath.trim());
      path.setAttribute('fill','none');
      path.setAttribute('stroke', _meanColor);
      path.setAttribute('stroke-width', String(_meanWidth));
      try{ path.setAttribute('stroke-opacity', String(_meanOpacity)); }catch(e){}
      const _md = (_meanDash && _meanDash.trim() !== '') ? _meanDash.trim() : '';
      if (_md) path.setAttribute('stroke-dasharray', _md);
      else path.removeAttribute('stroke-dasharray');
      path.setAttribute('stroke-linecap', (_md === '2 4') ? 'round' : 'butt');
      clip(path);
      svg.appendChild(path);
      try{ path.setAttribute('data-qg-tip', '평균선: ' + String(_label||_colKey||'') ); }catch(e){}

	      if (!_hideMeanDots){
	        for (const mp of meanPts){
	          const _d = (mp && mp.d) ? mp.d : null;
	          qgAppendMarkerShape(svg, panelShape, mp.x, mp.y, _meanDotSize, {
	            fill: _meanDotColor,
	            stroke: _meanDotColor,
	            opacity: _meanDotOpacity,
	            clip,
	            tip: 'Mean: ' + fmtTick(mp && mp.v !== undefined ? mp.v : NaN) + '\nDate: ' + (_d && (_d.label||_d.key) ? String(_d.label||_d.key) : '')
	          });
	        }
	      }
    }

}

  // x labels (per panel) - only on last FAI row of the tool-row block
  if (!(opt && opt.showXLabels===false)) {
    const yAxis = padT + innerH;
    const yLbl = yAxis + 28;

    // Reduce label density when we have many panels (Tool x Cavity)
    const ticksPerPanel = Math.max(2, Math.min(10, Math.floor(36 / Math.max(1, nP))));
    const step = Math.max(1, Math.ceil(dates.length / ticksPerPanel));

    for (let pi=0; pi<nP; pi++){
      const left = padL + pi*(panelW + gap);

      const axis = document.createElementNS(ns,'line');
      axis.setAttribute('x1', String(left));
      axis.setAttribute('x2', String(left + panelW));
      axis.setAttribute('y1', String(yAxis));
      axis.setAttribute('y2', String(yAxis));
      axis.setAttribute('stroke','rgba(0,0,0,0.18)');
      axis.setAttribute('stroke-width','1');
      svg.appendChild(axis);

      for (let di=0; di<dates.length; di+=step){
        const xx = left + xInPanel(di);
        const tk = document.createElementNS(ns,'line');
        tk.setAttribute('x1', String(xx));
        tk.setAttribute('x2', String(xx));
        tk.setAttribute('y1', String(yAxis));
        tk.setAttribute('y2', String(yAxis + 4));
        tk.setAttribute('stroke','rgba(0,0,0,0.22)');
        tk.setAttribute('stroke-width','1');
        svg.appendChild(tk);

        const tx = document.createElementNS(ns,'text');
        tx.setAttribute('x', String(xx));
        tx.setAttribute('y', String(yLbl));
        tx.setAttribute('font-size','10');
        tx.setAttribute('fill','rgba(0,0,0,0.60)');
        tx.setAttribute('text-anchor','middle');
        tx.setAttribute('transform', `rotate(-45 ${xx} ${yLbl})`);
        const dd = dates[di];
        tx.textContent = (dd && (dd.label || dd.key)) ? (dd.label || dd.key) : '';
        svg.appendChild(tx);
      }
    }
  }

  // Keep background layers behind all plot content (prevents solid bg from covering marks)
  try{ qgEnsureBgBehind(svg, bg, plot, band); }catch(e){}

  // Caption box rendering (per-panel summary)
  try{
    if (_colKey){
      const cap = qgEnsureCaptionState(_colKey);
      if (QG.captionGlobalEnabled && cap && cap.enabled){
        const kinds = Array.isArray(cap.stats) ? cap.stats.map(s=>String(s||'').trim()).filter(s=>s && s !== 'none') : [];
        if (kinds.length){
          // Context for percent-type stats
          const toolSum = Object.create(null);
          let overallSum = 0;
          try{
            for (const t0 of tools){
              let ts = 0;
              for (const c0 of cavs){
                const s0 = ((QG.series||{})[t0]||{})[c0]||{};
                for (const d0 of dates){
                  const dk0 = (d0 && d0.key) ? d0.key : d0;
                  const p0 = qgPickDatePoint(s0, d0);
                  if (!p0 || !isFinite(p0.mean)) continue;
                  overallSum += Number(p0.mean);
                  ts += Number(p0.mean);
                }
              }
              toolSum[t0] = ts;
            }
          }catch(e2){}

          const lineH = 12;
          const mode = String(cap.posMode || 'graph');

          // Per-panel clip helpers (to avoid overlap across Tool×Cavity cells)
          const _capClipCache = Object.create(null);
          const capClipPanelPlot = (pi)=>{
            const key = 'p' + String(pi);
            if (_capClipCache[key]) return _capClipCache[key];
            const id = clipId + '_capP_' + String(pi);
            const left = padL + pi*(panelW + gap);
            const cp2 = document.createElementNS(ns,'clipPath');
            cp2.setAttribute('id', id);
            const r2 = document.createElementNS(ns,'rect');
            r2.setAttribute('x', String(left));
            r2.setAttribute('y', String(padT));
            r2.setAttribute('width', String(panelW));
            r2.setAttribute('height', String(innerH));
            cp2.appendChild(r2);
            defs.appendChild(cp2);
            const url = 'url(#' + id + ')';
            _capClipCache[key] = url;
            return url;
          };
          const capClipPanelFull = (pi)=>{
            const key = 'f' + String(pi);
            if (_capClipCache[key]) return _capClipCache[key];
            const id = clipId + '_capF_' + String(pi);
            const left = padL + pi*(panelW + gap);
            const cp2 = document.createElementNS(ns,'clipPath');
            cp2.setAttribute('id', id);
            const r2 = document.createElementNS(ns,'rect');
            r2.setAttribute('x', String(left));
            r2.setAttribute('y', '0');
            r2.setAttribute('width', String(panelW));
            r2.setAttribute('height', String(H));
            cp2.appendChild(r2);
            defs.appendChild(cp2);
            const url = 'url(#' + id + ')';
            _capClipCache[key] = url;
            return url;
          };

          // Mode: Factor graph / Axis table (per-date statistics labels, JMP-like)
          if (mode === 'factor' || mode === 'axis_table'){
            const yAxis = padT + innerH;

            // Value label density control
            // - JMP shows values per category/date; skipping makes it look "missing".
            // - Keep all labels for typical ranges; only thin out when dates are very many.
            const maxLabels = Math.max(2, Math.floor(panelW / 64));
            const stepAuto = Math.max(1, Math.ceil(dates.length / maxLabels));
            const step = (dates.length <= 24) ? 1 : stepAuto;

            const useKinds = kinds.slice(0, 5);
            if (useKinds.length){
              const lineStep = 10;

              const yp0 = String(cap.yPos || 'top').trim();
              const topY = padT + 2;
              // Bottom anchor inside plot (avoid x-axis/date label region)
              const botY = padT + innerH - 2;
              const midY = padT + innerH/2 - 6;

              // Factor-only: "데이터박스 위/아래" (stored as box_top/box_bottom)
              // Position *values* relative to the data box (min/max range bar) instead of the plot edge.
              // Mean should stay in the usual fixed caption area (as users expect in JMP).
              // Stat-name labels stay in the usual caption area.
              const isDataBoxY = (mode === 'factor' && (yp0 === 'box_top' || yp0 === 'box_bottom'));

              // In data-box Y mode (factor), statistic *names* stay in the usual caption area,
              // while statistic *values* (including mean) are anchored to the data box endpoints.
              const valueKinds = useKinds;

              const yForConstBlock = (nLines)=>{
                // yStart is the first line's baseline ("hanging"); subsequent lines add dy=lineStep
                const n = Math.max(1, Number(nLines||1));
                const blockDy = Math.max(0, (n - 1) * lineStep);

                if (mode === 'axis_table'){
                  // Axis table does not expose X/Y position controls; keep it fixed.
                  return yAxis + 8;
                }
                if (yp0 === 'middle') return midY - blockDy/2;
                if (yp0 === 'bottom') return botY - blockDy;
                return topY; // default: top
              };

              const yConst = yForConstBlock(useKinds.length);

              for (let pi=0; pi<nP; pi++){
                const tool = tools[Math.floor(pi / nC)];
                const cav  = cavs[pi % nC];
                const s = ((QG.series||{})[tool]||{})[cav]||{};
                const left = padL + pi*(panelW + gap);

                const emitValueBlock = (di, kindList, forceConst, yConstOverride)=>{
                  if (!kindList || !kindList.length) return null;
                  const d = dates[di];
                  const dk = (d && d.key) ? d.key : d;
                  const p0 = qgPickDatePoint(s, d);
                  if (!p0) return;

                  const raw = [];
                  if (Array.isArray(p0.vals) && p0.vals.length){
                    for (const vv of p0.vals){ if (isFinite(vv)) raw.push(Number(vv)); }
                  }else if (isFinite(p0.mean)){
                    raw.push(Number(p0.mean));
                  }
                  // Some datasets may carry an array with non-finite placeholders;
                  // ensure we can still render labels when mean exists.
                  if (!raw.length && isFinite(p0.mean)){
                    raw.push(Number(p0.mean));
                  }

                  const blockDy = Math.max(0, (kindList.length - 1) * lineStep);
                  const fontPx = 9; // must match tx font-size below
                  const totalBlockH = fontPx + blockDy;

                  // Default: fixed caption area
                  let yStart = (isFinite(yConstOverride) ? Number(yConstOverride) : yConst);

                  // Data-box anchored mode (values move; names stay fixed)
                  let _dataBoxMode = '';
                  if (!forceConst && isDataBoxY && mode === 'factor'){
                    // Anchor to the *data box* endpoints (min/max range bar).
                    // If min/max is missing, fall back to mean.
                    const yTop = isFinite(p0.max) ? yAt(Number(p0.max)) : (isFinite(p0.mean) ? yAt(Number(p0.mean)) : (padT + innerH/2));
                    const yBot = isFinite(p0.min) ? yAt(Number(p0.min)) : (isFinite(p0.mean) ? yAt(Number(p0.mean)) : (padT + innerH/2));
                    const off = 0; // keep tight to the data box

                    if (yp0 === 'box_top'){
                      // 데이터박스 위: place the whole value block right above the data-box top (max)
                      _dataBoxMode = 'top';
                      const gapY = 0;
                      // With dominant-baseline=hanging, y is the top of the first line box.
                      // Keep the bottom of the block close to yTop.
                      yStart = yTop - gapY - totalBlockH;
                      const minY = padT;
                      const maxY = (padT + innerH) - totalBlockH;
                      if (yStart < minY) yStart = minY;
                      if (yStart > maxY) yStart = maxY;
                    }else{
                      // 데이터박스 아래: place the whole value block right below the data-box bottom (min)
                      _dataBoxMode = 'bottom';
                      const gapY = 0;
                      yStart = yBot + gapY;
                      const minY = padT;
                      const maxY = (padT + innerH) - totalBlockH;
                      if (yStart < minY) yStart = minY;
                      if (yStart > maxY) yStart = maxY;
                    }
                  }

                  const xx = left + xInPanel(di);
                  const tx = document.createElementNS(ns,'text');
                  tx.setAttribute('x', String(xx));
                  tx.setAttribute('y', String(yStart));
                  tx.setAttribute('fill','rgba(0,0,0,0.62)');
                  tx.setAttribute('font-size','9');
                  // JMP-like: keep value labels centered on the data box/date slot
                  tx.setAttribute('text-anchor', 'middle');
                  // Value labels: use a stable baseline; vertical placement is handled by yStart.
                  tx.setAttribute('dominant-baseline', 'hanging');
                  try{ tx.setAttribute('pointer-events','none'); }catch(e3){}

                  let wrote = 0;
                  for (let si=0; si<kindList.length; si++){
                    const kind = kindList[si];
                    const v0 = qgCaptionStatValue(raw, kind, { overallSum, toolSum, tool, totalSlots: (Array.isArray(dates)?dates.length:NaN) });
                    if (!isFinite(v0)) continue;
                    const sp = document.createElementNS(ns,'tspan');
                    sp.setAttribute('x', String(xx));
                    if (wrote > 0){
                      sp.setAttribute('dy', String(lineStep));
                    }
                    sp.textContent = qgFmtCaptionNum(v0, cap.numFmt);
                    tx.appendChild(sp);
                    wrote++;
                  }
                  if (!wrote) return;

                  try{
                    const url = (mode === 'axis_table') ? capClipPanelFull(pi) : capClipPanelPlot(pi);
                    tx.setAttribute('clip-path', url);
                  }catch(e4){
                    if (mode !== 'axis_table') clip(tx);
                  }
                  svg.appendChild(tx);
                  return yStart;
                };

                const emitNameBlock = (kindList, yStart)=>{
                  try{
                    if (!kindList || !kindList.length || !isFinite(yStart)) return;
                    const names = kindList.map(k=>qgCaptionLabel(k)).filter(Boolean);
                    if (!names.length) return;

                    let xN = left + 6;
                    let aN = 'start';
                    if (mode === 'axis_table'){
                      xN = left + panelW - 6;
                      aN = 'end';
                    }else{
                      const xpN = String(cap.xPos || 'right');
                      if (xpN === 'center'){ xN = left + panelW/2; aN = 'middle'; }
                      else if (xpN === 'right'){ xN = left + panelW - 6; aN = 'end'; }
                    }

                    const tn = document.createElementNS(ns,'text');
                    tn.setAttribute('x', String(xN));
                    tn.setAttribute('y', String(yStart));
                    tn.setAttribute('fill','rgba(0,0,0,0.62)');
                    tn.setAttribute('font-size','9');
                    tn.setAttribute('text-anchor', aN);
                    tn.setAttribute('dominant-baseline', 'hanging');
                    try{ tn.setAttribute('pointer-events','none'); }catch(e3){}

                    let wroteN = 0;
                    for (let i=0;i<names.length;i++){
                      const sp = document.createElementNS(ns,'tspan');
                      sp.setAttribute('x', String(xN));
                      if (wroteN > 0) sp.setAttribute('dy', String(lineStep));
                      sp.textContent = String(names[i]);
                      tn.appendChild(sp);
                      wroteN++;
                    }
                    if (wroteN){
                      try{
                        const url = (mode === 'axis_table') ? capClipPanelFull(pi) : capClipPanelPlot(pi);
                        tn.setAttribute('clip-path', url);
                      }catch(e4){
                        if (mode !== 'axis_table') clip(tn);
                      }
                      svg.appendChild(tn);
                    }
                  }catch(eN){}
                };

                // per-date value labels with density control
                let lastDi = -1;
                let nameY = null;

                // In data-box Y mode:
                // - Stat names stay in the usual caption area.
                // - Stat values are anchored to the data-box top/bottom endpoint.
                if (isDataBoxY && mode === 'factor'){
                  nameY = yForConstBlock(useKinds.length);
                  emitNameBlock(useKinds, nameY);
                }

                // Emit values per date (do NOT look "missing")
                for (let di=0; di<dates.length; di+=step){
                  lastDi = di;

                  // Value labels (data-box anchored in box_top/box_bottom mode)
                  const yy = emitValueBlock(di, valueKinds, false);
                  if (!isDataBoxY && nameY === null && isFinite(yy)) nameY = yy;
                }
                // Ensure the last date label is shown when possible (JMP-like)
                if (dates.length > 1 && lastDi !== dates.length - 1){
                  const di = dates.length - 1;

                  const yy = emitValueBlock(di, valueKinds, false);
                  if (!isDataBoxY && nameY === null && isFinite(yy)) nameY = yy;
                }

                // Trailing statistic-name labels (align with the value block when not in data-box mode)
                if (!isDataBoxY && nameY !== null && useKinds.length) emitNameBlock(useKinds, nameY);
              }
            }
          }


// Mode: Axis reference line (draw statistic as data-based reference line)
          if (mode === 'axis_refline'){
            for (let pi=0; pi<nP; pi++){
              const tool = tools[Math.floor(pi / nC)];
              const cav  = cavs[pi % nC];
              const s = ((QG.series||{})[tool]||{})[cav]||{};

              const rawVals = [];
              for (const d of dates){
                const dk = (d && d.key) ? d.key : d;
                const p = qgPickDatePoint(s, d);
                if (!p) continue;
                if (Array.isArray(p.vals) && p.vals.length){
                  for (const vv of p.vals){ if (isFinite(vv)) rawVals.push(Number(vv)); }
                }else if (isFinite(p.mean)){
                  rawVals.push(Number(p.mean));
                }
              }

              const left = padL + pi*(panelW + gap);
              const right = left + panelW;
              let liIdx = 0;
              for (const kind of kinds){
                const v = qgCaptionStatValue(rawVals, kind, { overallSum, toolSum, tool, totalSlots: (Array.isArray(dates)?dates.length:NaN) });
                if (!isFinite(v)) continue;
                const y = yAt(Number(v));
                const ln = document.createElementNS(ns,'line');
                ln.setAttribute('x1', String(left + 1));
                ln.setAttribute('x2', String(right - 1));
                ln.setAttribute('y1', String(y));
                ln.setAttribute('y2', String(y));
                ln.setAttribute('stroke','rgba(0,0,0,0.30)');
                ln.setAttribute('stroke-width','1');
                ln.setAttribute('stroke-dasharray','3 3');
                clip(ln);
                svg.appendChild(ln);

                // Label only for the first visible stat (avoid clutter)
                if (liIdx === 0){
                  const tx = document.createElementNS(ns,'text');
                  tx.setAttribute('x', String(right - 4));
                  tx.setAttribute('y', String(y - 2));
                  tx.setAttribute('fill','rgba(0,0,0,0.55)');
                  tx.setAttribute('font-size','9');
                  tx.setAttribute('text-anchor','end');
                  tx.setAttribute('dominant-baseline','auto');
                  try{ tx.setAttribute('pointer-events','none'); }catch(e3){}
                  tx.textContent = qgCaptionLabel(kind) + ': ' + qgFmtCaptionNum(v, cap.numFmt);
                  clip(tx);
                  svg.appendChild(tx);
                }
                liIdx++;
              }
            }
          }

          // Mode: Graph (per-panel captions inside plot)
          if (mode === 'graph'){
            const yAxis = padT + innerH;
            for (let pi=0; pi<nP; pi++){
              const tool = tools[Math.floor(pi / nC)];
              const cav  = cavs[pi % nC];
              const s = ((QG.series||{})[tool]||{})[cav]||{};

              const rawVals = [];
              for (const d of dates){
                const dk = (d && d.key) ? d.key : d;
                const p = qgPickDatePoint(s, d);
                if (!p) continue;
                if (Array.isArray(p.vals) && p.vals.length){
                  for (const vv of p.vals){ if (isFinite(vv)) rawVals.push(Number(vv)); }
                }else if (isFinite(p.mean)){
                  rawVals.push(Number(p.mean));
                }
              }

              const lines = [];
              for (const kind of kinds){
                const v = qgCaptionStatValue(rawVals, kind, { overallSum, toolSum, tool, totalSlots: (Array.isArray(dates)?dates.length:NaN) });
                const t = qgCaptionText(kind, v, cap.numFmt);
                if (t) lines.push(t);
              }
              if (!lines.length) continue;

              const left = padL + pi*(panelW + gap);
              let x = left + 6;
              let anchor = 'start';
              const xp = String(cap.xPos || 'right');
              if (xp === 'center'){ x = left + panelW/2; anchor = 'middle'; }
              else if (xp === 'right'){ x = left + panelW - 6; anchor = 'end'; }

              let y = padT + 4;
              const yp = String(cap.yPos || 'top').trim();

              if (mode === 'axis_table'){
                // Below axis (same as factor graph, but under the axis)
                const topY = yAxis + 10;
                const botY = (H - 6) - (lineH * (lines.length - 1));
                const midY = (topY + botY) / 2;
                if (yp === 'middle') y = midY;
                else if (yp === 'bottom') y = botY;
                else y = topY;
              }else{
                // Inside plot
                if (yp === 'middle') y = padT + innerH/2 - (lineH * (lines.length - 1))/2;
                else if (yp === 'bottom' || yp === 'box_bottom') y = padT + innerH - 4 - (lineH * (lines.length - 1));
              }

              const tx = document.createElementNS(ns,'text');
              tx.setAttribute('x', String(x));
              tx.setAttribute('y', String(y));
              tx.setAttribute('fill','rgba(0,0,0,0.78)');
              tx.setAttribute('font-size','10');
              tx.setAttribute('text-anchor', anchor);
              tx.setAttribute('dominant-baseline', 'hanging');
              try{ tx.setAttribute('pointer-events','none'); }catch(e3){}

              for (let li=0; li<lines.length; li++){
                const sp = document.createElementNS(ns,'tspan');
                sp.setAttribute('x', String(x));
                if (li > 0) sp.setAttribute('dy', String(lineH));
                sp.textContent = lines[li];
                tx.appendChild(sp);
              }

              // Clip to each Tool×Cavity cell
              try{
                const url = (mode === 'axis_table') ? capClipPanelFull(pi) : capClipPanelPlot(pi);
                tx.setAttribute('clip-path', url);
              }catch(e4){
                if (mode !== 'axis_table') clip(tx);
              }
              svg.appendChild(tx);
            }
          }
        }
      }
    }
  }catch(e){}


}






  function drawFacetedSvg(svg, tool, cavs, dates, opt){
    if (!svg) return;

    const seriesColor = (opt && opt.color) ? String(opt.color) : '#2b5bd7';

    const _colKey = (opt && opt.colKey) ? String(opt.colKey) : '';
    let _bgColor = '#ffffff';
    try{
      const bs = (_colKey && QG.bgStyleByCol && QG.bgStyleByCol[_colKey]) ? QG.bgStyleByCol[_colKey] : null;
      if (bs && bs.color) _bgColor = String(bs.color);
    }catch(e){}

    const W = 1200, H = (opt && opt.h ? opt.h : 320);
    // Keep x-axis labels tight to the plot (JMP-like), while still preventing clipping.
    const padL = 62, padR = 14, padT = 14, padB = (opt && opt.showXLabels===false) ? 26 : 70;
    const innerW = W - padL - padR;
    const innerH = H - padT - padB;

    qgSetPadCssVars(padL, padR, W);

    const nC = Math.max(1, cavs.length);
    const gap = 0;
    const panelW = (innerW - gap*(nC-1)) / nC;

    // y range across all selected cavs/dates
    let yMin = Infinity, yMax = -Infinity;
    for (const cav of cavs){
      const s = ((QG.series||{})[tool]||{})[cav]||{};
      for (const d of dates){
        const dk = (d && d.key) ? d.key : d;
        const p = qgPickDatePoint(s, d);
        if (!p) continue;
        yMin = Math.min(yMin, p.min, p.mean);
        yMax = Math.max(yMax, p.max, p.mean);
      }
    }
    if (opt && opt.lsl !== null) yMin = Math.min(yMin, opt.lsl);
    if (opt && opt.usl !== null) yMax = Math.max(yMax, opt.usl);
    if (opt && opt.oocLsl !== null && opt.oocLsl !== undefined) yMin = Math.min(yMin, opt.oocLsl);
    if (opt && opt.oocUsl !== null && opt.oocUsl !== undefined) yMax = Math.max(yMax, opt.oocUsl);

    const hasYMinO = !!(opt && opt.yMinO !== null && opt.yMinO !== undefined && isFinite(opt.yMinO));
    const hasYMaxO = !!(opt && opt.yMaxO !== null && opt.yMaxO !== undefined && isFinite(opt.yMaxO));

    if (hasYMinO) yMin = opt.yMinO;
    if (hasYMaxO) yMax = opt.yMaxO;

    if (!isFinite(yMin) || !isFinite(yMax)) {
      // No data range computed (should be rare). Use a sane tiny range around 0.
      yMin = -0.001;
      yMax =  0.001;
    }

    // If range collapses, expand around center (avoid -1~1 blow-up)
    if (yMax - yMin < 1e-12){
      const center = (Number(yMin) + Number(yMax)) / 2;
      let delta = Math.max(Math.abs(center) * 0.05, 1e-4); // at least 0.0001
      if (!isFinite(delta) || delta <= 0) delta = 1e-4;
      yMin = center - delta;
      yMax = center + delta;
    } else {
      // auto padding only for the side(s) not fixed by user input
      if (!hasYMinO || !hasYMaxO){
        const pad = (yMax - yMin) * 0.08;
        if (!hasYMaxO) yMax += pad;
        if (!hasYMinO) yMin -= pad;
      }
    }

    // snap to 0.001 steps for cleaner axis labels (when not user-fixed)
    const stepY = 0.001;
    if (!hasYMinO){
      yMin = Math.floor(yMin / stepY) * stepY;
    }
    if (!hasYMaxO){
      yMax = Math.ceil(yMax / stepY) * stepY;
    }
    if (yMax - yMin < stepY){
      const c = (yMin + yMax) / 2;
      yMin = c - stepY;
      yMax = c + stepY;
    }

    const yAt = (v)=> padT + (1 - ((v - yMin)/(yMax - yMin))) * innerH;

    // x scale is shared across panels (same dates index)
    const nD = Math.max(1, dates.length);
    const xPad = 18;
    const xInPanel = (idx)=> (nD === 1 ? (xPad + (panelW-2*xPad)/2) : (xPad + (idx/(nD-1))*(panelW-2*xPad)));

    const ns = 'http://www.w3.org/2000/svg';
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    // background
    const bg = document.createElementNS(ns,'rect');
    bg.setAttribute('x','0'); bg.setAttribute('y','0');
    bg.setAttribute('width', String(W)); bg.setAttribute('height', String(H));
    // Paper/background outside plot area should stay neutral; only the plot area is user-colored.
    bg.setAttribute('fill', '#ffffff');
    try{ bg.setAttribute('pointer-events','none'); }catch(e){}
    svg.appendChild(bg);

    const plot = document.createElementNS(ns,'rect');
    plot.setAttribute('x', String(padL));
    plot.setAttribute('y', String(padT));
    plot.setAttribute('width', String(innerW));
    plot.setAttribute('height', String(innerH));
    // NOTE: Temporarily lock the plot background to white (user-configurable color will be added later)
    plot.setAttribute('fill', _bgColor);
  try{
    const bs2 = (_colKey && QG.bgStyleByCol && QG.bgStyleByCol[_colKey]) ? QG.bgStyleByCol[_colKey] : null;
    const op = (bs2 && bs2.opacity !== undefined && bs2.opacity !== null) ? qgClamp01(bs2.opacity) : 1;
    plot.setAttribute('fill-opacity', String(op));
  }catch(e){ try{ plot.setAttribute('fill-opacity','1'); }catch(e2){} }
    plot.setAttribute('stroke','#cfcfcf');
    plot.setAttribute('stroke-width','1');
    try{ plot.setAttribute('pointer-events','none'); }catch(e){}
    svg.appendChild(plot);

// clip (keep plot contents inside the plot area)
const clipId = 'qgClip_' + Math.floor(Math.random() * 1e9);
const defs = document.createElementNS(ns,'defs');
const cp = document.createElementNS(ns,'clipPath');
cp.setAttribute('id', clipId);
const cpr = document.createElementNS(ns,'rect');
cpr.setAttribute('x', String(padL));
cpr.setAttribute('y', String(padT));
cpr.setAttribute('width', String(innerW));
cpr.setAttribute('height', String(innerH));
cp.appendChild(cpr);
defs.appendChild(cp);
svg.appendChild(defs);
const clipUrl = 'url(#' + clipId + ')';
const clip = (el)=>{ try{ el.setAttribute('clip-path', clipUrl); }catch(e){} };
    // y-axis ticks + grid (JMP-like)
    function niceStep(range, targetTicks){
      const t = Math.max(1, targetTicks || 6);
      const r = Number(range);
      if (!isFinite(r) || r <= 0) return 1;
      const rough = r / t;
      const p = Math.pow(10, Math.floor(Math.log10(rough)));
      const x = rough / p;
      let m = 10;
      if (x <= 1) m = 1;
      else if (x <= 2) m = 2;
      else if (x <= 5) m = 5;
      else m = 10;
      return m * p;
    }

    const yRange = (yMax - yMin);
    const major = niceStep(yRange, 7);
    const minor = major / 5;
    const yAxisX = padL;

    // minor tick marks (no labels)
    const minorStart = Math.ceil(yMin / minor) * minor;
    for (let v = minorStart; v <= yMax + minor * 0.5; v += minor){
      const y = yAt(v);
      const ln = document.createElementNS(ns,'line');
      ln.setAttribute('x1', String(yAxisX - 3));
      ln.setAttribute('x2', String(yAxisX));
      ln.setAttribute('y1', String(y));
      ln.setAttribute('y2', String(y));
      ln.setAttribute('stroke','rgba(0,0,0,0.18)');
      ln.setAttribute('stroke-width','1');
      svg.appendChild(ln);
    }

    // major grid lines + labels
    const tickVals = [];
    const yStart = Math.ceil(yMin / major) * major;
    const yEnd   = Math.floor(yMax / major) * major;
    for (let v = yStart; v <= yEnd + major * 0.5; v += major){
      tickVals.push(v);
    }
    // ensure bounds are shown
    if (!tickVals.length){
      tickVals.push(yMin, yMax);
    }else{
      if (Math.abs(tickVals[0] - yMin) > major * 0.25) tickVals.unshift(yMin);
      if (Math.abs(tickVals[tickVals.length - 1] - yMax) > major * 0.25) tickVals.push(yMax);
    }

    for (const v0 of tickVals){
      const v = Number(v0);
      const y = yAt(v);

      if (!QG.gridHidden){
      const gl = document.createElementNS(ns,'line');
      gl.setAttribute('x1', String(padL));
      gl.setAttribute('x2', String(W - padR));
      gl.setAttribute('y1', String(y));
      gl.setAttribute('y2', String(y));
      gl.setAttribute('stroke','rgba(0,0,0,0.10)');
      gl.setAttribute('stroke-width','1');
      clip(gl);
      svg.appendChild(gl);
      }

      const tk = document.createElementNS(ns,'line');
      tk.setAttribute('x1', String(yAxisX - 6));
      tk.setAttribute('x2', String(yAxisX));
      tk.setAttribute('y1', String(y));
      tk.setAttribute('y2', String(y));
      tk.setAttribute('stroke','rgba(0,0,0,0.35)');
      tk.setAttribute('stroke-width','1');
      svg.appendChild(tk);

      const tx = document.createElementNS(ns,'text');
      tx.setAttribute('x', String(yAxisX - 8));
      tx.setAttribute('y', String(y + 4));
      tx.setAttribute('font-size','11');
      tx.setAttribute('fill','rgba(0,0,0,0.70)');
          try{ tx.setAttribute('opacity', String(_vOpacity)); }catch(e){}
      tx.setAttribute('text-anchor','end');
      tx.textContent = fmtTick(v);
      svg.appendChild(tx);
    }

    // 0 line (if inside range)
    if (0 >= yMin && 0 <= yMax){
      const y0 = yAt(0);
      const z = document.createElementNS(ns,'line');
      z.setAttribute('x1', String(padL)); z.setAttribute('x2', String(W - padR));
      z.setAttribute('y1', String(y0)); z.setAttribute('y2', String(y0));
      z.setAttribute('stroke','rgba(0,0,0,0.15)');
      z.setAttribute('stroke-width','1');
      clip(z);
      svg.appendChild(z);
    }

    // panels
    // vertical separators (JMP-like)
    for (let i=1; i<nC; i++){
      const x = padL + i*panelW;
      const ln = document.createElementNS(ns,'line');
      ln.setAttribute('x1', String(x)); ln.setAttribute('x2', String(x));
      ln.setAttribute('y1', String(padT)); ln.setAttribute('y2', String(padT + innerH));
      ln.setAttribute('stroke','#d4d4d4');
      ln.setAttribute('stroke-width','1');
      clip(ln);
      svg.appendChild(ln);
    }


    for (let ci=0; ci<cavs.length; ci++){
      const cav = cavs[ci];
      const left = padL + ci*(panelW + gap);
      const right = left + panelW;

      // panel rect
      const pr = document.createElementNS(ns,'rect');
      pr.setAttribute('x', String(left));
      pr.setAttribute('y', String(padT));
      pr.setAttribute('width', String(panelW));
      pr.setAttribute('height', String(innerH));
      pr.setAttribute('fill','none');
      pr.setAttribute('stroke','none');
      pr.setAttribute('stroke-width','0');
      svg.appendChild(pr);

      // usl/lsl lines per panel (base limits stay fixed); OOC SPEC is drawn as a separate overlay
      // NOTE: USL/LSL is identical across cavities for the same FAI,
      // so draw the dashed line in every cavity panel but draw the label only once (rightmost panel)
      function hLine(val, label, cfg){
        const scaledVal = Number(val);
        if (!isFinite(scaledVal)) return;
        const st = cfg || {};
        const y = yAt(scaledVal);
        const ln = document.createElementNS(ns,'line');
        ln.setAttribute('x1', String(left)); ln.setAttribute('x2', String(right));
        ln.setAttribute('y1', String(y)); ln.setAttribute('y2', String(y));
        ln.setAttribute('stroke', st.stroke ? String(st.stroke) : '#2b5bd7');
        ln.setAttribute('stroke-width', String(st.width || 1));
        if (st.opacity !== undefined && st.opacity !== null) ln.setAttribute('stroke-opacity', String(st.opacity));
        if (st.dash) ln.setAttribute('stroke-dasharray', String(st.dash));
        clip(ln);
        svg.appendChild(ln);
        // Label only on the last visible cavity panel (JMP-like: one label at the far right)
        if (ci !== cavs.length - 1) return;
        if (st.showLabel === false) return;

        const tx = document.createElementNS(ns,'text');
        const _specX = right - 4;
        tx.setAttribute('x', String(_specX));
        tx.setAttribute('y', String(y - 2));
        tx.setAttribute('text-anchor', 'end');
        tx.setAttribute('font-size','10');
        tx.setAttribute('fill','rgba(0,0,0,0.70)');
        if (st.labelOpacity !== undefined && st.labelOpacity !== null){
          try{ tx.setAttribute('opacity', String(st.labelOpacity)); }catch(e){}
        }
        tx.textContent = label + ' ' + fmtTick(scaledVal);
        clip(tx);
        svg.appendChild(tx);
      }
      if (opt && opt.usl !== null) hLine(opt.usl, 'USL', { dash:'4 3', showLabel: !QG.hideUslLslLabel });
      if (opt && opt.lsl !== null) hLine(opt.lsl, 'LSL', { dash:'4 3', showLabel: !QG.hideUslLslLabel });
      if (opt && opt.oocUsl !== null && opt.oocUsl !== undefined) hLine(opt.oocUsl, 'OOC USL', { dash:'8 3 2 3', width:1.2, showLabel:true });
      if (opt && opt.oocLsl !== null && opt.oocLsl !== undefined) hLine(opt.oocLsl, 'OOC LSL', { dash:'8 3 2 3', width:1.2, showLabel:true });

      // data
      const s = ((QG.series||{})[tool]||{})[cav]||{};

      // whiskers + replicate dots + collect mean points
      const meanPts = [];
      for (let di=0; di<dates.length; di++){
        const d = dates[di];
        const dk = (d && d.key) ? d.key : d;
        const p = qgPickDatePoint(s, d);
        if (!p) continue;

        const x = left + xInPanel(di);
        const yMinV = yAt(p.min);
        const yMaxV = yAt(p.max);

        // Data1~3 range box (min~max rectangle) + replicate dots
        const boxW = 12;
        const top = Math.min(yMinV, yMaxV);
        const bot = Math.max(yMinV, yMaxV);
        const h = Math.max(1, bot - top);
        const rect = document.createElementNS(ns,'rect');
        rect.setAttribute('x', String(x - boxW/2));
        rect.setAttribute('y', String(top));
        rect.setAttribute('width', String(boxW));
        rect.setAttribute('height', String(h));
        rect.setAttribute('fill','none');
        rect.setAttribute('stroke', seriesColor);
        rect.setAttribute('stroke-width','1');
        clip(rect);
        svg.appendChild(rect);

        // IMPORTANT: keep Data1~3 dots centered (no time-based drift / no random jitter)
        // If values overlap, they intentionally overlap on the center line.
        const vals = (p.vals||[]).slice(0,3);
        for (let vi=0; vi<vals.length; vi++){
          const v = vals[vi];
          const cy = yAt(v);
          const c = document.createElementNS(ns,'circle');
          c.setAttribute('cx', String(x));
          c.setAttribute('cy', String(cy));
          c.setAttribute('r','2.0');
          c.setAttribute('fill', _dataDotColor);
          clip(c);
          svg.appendChild(c);
        }

        meanPts.push({ x, y: yAt(p.mean), d, v: p.mean });
      }

      // mean line path
      if (meanPts.length){
        let dPath = '';
        for (let i=0;i<meanPts.length;i++){
          dPath += (i===0 ? 'M' : 'L') + meanPts[i].x + ' ' + meanPts[i].y + ' ';
        }
        const path = document.createElementNS(ns,'path');
        path.setAttribute('d', dPath.trim());
        path.setAttribute('fill','none');
        path.setAttribute('stroke', seriesColor);
        path.setAttribute('stroke-width','2');
        clip(path);
        svg.appendChild(path);

        // mean points
        for (const mp of meanPts){
          const c = document.createElementNS(ns,'circle');
          c.setAttribute('cx', String(mp.x));
          c.setAttribute('cy', String(mp.y));
          c.setAttribute('r','3.0');
          c.setAttribute('fill', _dataDotColor);
          clip(c);
          svg.appendChild(c);
        }
      }

    // x labels (per cavity panel) - only on last FAI row
    if (ci === cavs.length - 1 && !(opt && opt.showXLabels===false)) {
      const step = Math.max(1, Math.round(dates.length / 12));
      const yAxis = padT + innerH;
      // Put rotated date labels high enough so they never get clipped by the SVG viewport.
      const yLbl = yAxis + 28;
      for (let ci=0; ci<cavs.length; ci++){
        const left = padL + ci*(panelW + gap);
        // axis baseline per cavity panel
        const axis = document.createElementNS(ns,'line');
        axis.setAttribute('x1', String(left));
        axis.setAttribute('x2', String(left + panelW));
        axis.setAttribute('y1', String(yAxis));
        axis.setAttribute('y2', String(yAxis));
        axis.setAttribute('stroke','rgba(0,0,0,0.18)');
        axis.setAttribute('stroke-width','1');
        svg.appendChild(axis);
        for (let di=0; di<dates.length; di+=step){
          const xx = left + xInPanel(di);
          const tk = document.createElementNS(ns,'line');
          tk.setAttribute('x1', String(xx));
          tk.setAttribute('x2', String(xx));
          tk.setAttribute('y1', String(yAxis));
          tk.setAttribute('y2', String(yAxis + 4));
          tk.setAttribute('stroke','rgba(0,0,0,0.22)');
          tk.setAttribute('stroke-width','1');
          svg.appendChild(tk);
          const tx = document.createElementNS(ns,'text');
          tx.setAttribute('x', String(xx));
          tx.setAttribute('y', String(yLbl));
          tx.setAttribute('font-size','10');
          tx.setAttribute('fill','rgba(0,0,0,0.60)');
          tx.setAttribute('text-anchor','middle');
          tx.setAttribute('transform', `rotate(-45 ${xx} ${yLbl})`);
          const dd = dates[di];
          tx.textContent = (dd && (dd.label || dd.key)) ? (dd.label || dd.key) : '';
          svg.appendChild(tx);
        }
      }
    }

    }

  // Right-click on plot: add/edit a variable line (가변선)
  try{
    function _qgCtx(ev){
      try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
      if (!_colKey) return;

      // Right-click should also activate this FAI (so the axis/limit editor switches)
      try{ setPrimaryColKey(_colKey); }catch(e){}
      try{ renderFaiList && renderFaiList(); }catch(e){}
      try{ QG.editingLimits = false; syncLimitInputs && syncLimitInputs(true); }catch(e){}
      try{ QG.editingAxis = false; syncAxisInputs && syncAxisInputs(true); }catch(e){}
      try{ QG.editingOocSpec = false; syncOocSpecInputs && syncOocSpecInputs(true); }catch(e){}

      // Close browser menu + show our own JMP-like menu
      try{ qgHideCtxMenu(); }catch(e){}

      const r = svg.getBoundingClientRect();
      const Wv = 1200;
      const Hv = (opt && opt.h ? opt.h : 320);

      const x = (ev.clientX - r.left) / Math.max(1, r.width) * Wv;
      const y = (ev.clientY - r.top) / Math.max(1, r.height) * Hv;

      const insidePlot = !(x < padL || x > (Wv - padR) || y < padT || y > (padT + innerH));
      const vClick = insidePlot ? (yMin + (1 - ((y - padT) / Math.max(1, innerH))) * (yMax - yMin)) : null;

      const arr = (QG.varLines && QG.varLines[_colKey]) ? QG.varLines[_colKey] : [];
      let nearIdx = -1;
      let nearDist = 1e9;
      if (insidePlot){
        for (let i=0; i<arr.length; i++){
          const it = arr[i];
          if (!it) continue;
          const v = (it.value !== undefined && it.value !== null) ? Number(it.value) : NaN;
          if (!isFinite(v)) continue;
          const dy = Math.abs(yAt(v) - y);
          if (dy < nearDist){ nearDist = dy; nearIdx = i; }
        }
      }

      const hasNearest = (insidePlot && arr && arr.length && nearIdx >= 0);

      function deleteNearest(){
        if (!hasNearest) return;
        try{
          arr.splice(nearIdx, 1);
          if (!QG.varLines) QG.varLines = {};
          QG.varLines[_colKey] = arr;
          showMsg('기준선 삭제됨', 900);
          renderLegend(); renderGrid();
        }catch(e){}
      }

      function addVar(raw){
        const s = String(raw||'').trim();
        if (s === '') return;
        const vNew = num(s);
        if (vNew === null){
          try{ showMsg('숫자를 입력하세요', 1200); }catch(e){}
          return;
        }
        try{
          if (!QG.varLines) QG.varLines = {};
          if (!Array.isArray(QG.varLines[_colKey])) QG.varLines[_colKey] = [];
          QG.varLines[_colKey].push({ id:'vl' + (QG.varLineSeq++), value: vNew });
          try{ QG._udVarSel = { colKey: _colKey, idx: QG.varLines[_colKey].length - 1 }; }catch(e){}
          showMsg('기준선 추가됨', 900);
          renderLegend(); renderGrid();
        }catch(e){}
      }

      function openAddDlg(){
        const defVal = (insidePlot && vClick !== null && isFinite(Number(vClick))) ? fmtTick(vClick) : '';
        qgOpenVarDlg({
          title: '기준선 추가' + (_label ? (' [' + _label + ']') : ''),
          value: defVal,
          placeholder: '값 입력',
          showDelete: false,
          onOk: (v)=>{ addVar(v); }
        });
      }

      const items = [];
      if (!insidePlot){
        items.push({ label:'기준선', disabled:true });
      }else{
        items.push({ label:'기준선 추가...', onClick: openAddDlg });
        items.push({ label:'기준선 삭제', onClick: deleteNearest, disabled: !hasNearest });
      }

      items.push('sep');
      items.push({ label:'사용자 정의...', onClick: ()=>{ try{ setPrimaryColKey(_colKey); try{ renderFaiList(); }catch(e2){} qgOpenUserDefineDlg(); }catch(e){} } });

      qgShowCtxMenu(ev.clientX, ev.clientY, items);
    }


    // expose ctx handler for global capture trap
    try{ svg._qgCtxHandler = _qgCtx; }catch(e){}

    // Some shells block the native 'contextmenu' event before it reaches SVG.
    // Use right-button pointerdown as a robust trigger.
    if (!svg._qgCtxPD){
      svg._qgCtxPD = true;
      svg.addEventListener('pointerdown', function(ev){
        try{
          if (ev.button !== undefined && ev.button !== 2) return;
        }catch(e){ return; }
        svg._qgSkipNextCtx = true;
        _qgCtx(ev);
      }, { passive:false });
    }

    svg.addEventListener('contextmenu', function(ev){
      if (svg._qgSkipNextCtx){
        svg._qgSkipNextCtx = false;
        return;
      }
      _qgCtx(ev);
    }, { passive:false });
  }catch(e){}


  // Keep background layers behind all plot content (prevents solid bg from covering marks)
  try{ qgEnsureBgBehind(svg, bg, plot, null); }catch(e){}

}

  function scheduleLimitSave(flush){
    clearTimeout(QG.limitSaveTimer);
    const delay = flush ? 0 : 650;
    QG.limitSaveTimer = setTimeout(()=>{ saveLimitsAuto(); }, delay);
  }

  async function saveLimitsAuto(){
    const col = QG.cols.find(c => c.key === QG.sel.primaryColKey) || null;
    if (!col) return;

    const type = (qs('#type')?.value || '').toString().trim();
    const part = (qs('#model')?.value || '').toString();
    if (!type || !part) return;

    const uslV = (qs('#qgUSL')?.value || '').toString();
    const lslV = (qs('#qgLSL')?.value || '').toString();

    const tools = Array.from(QG.sel.tools);

    const body = new URLSearchParams();
    body.set('type', type);
    body.set('part_name', part);
    body.set('key', col.key);
    body.set('usl', uslV);
    body.set('lsl', lslV);
    if (tools.length) body.set('tools', tools.join(','));

    const payload = body.toString();
    if (payload === QG.lastLimitPayload) return; // avoid duplicate spam
    QG.lastLimitPayload = payload;

    try{
      const base = getBase();
      const url = (base ? base : '') + '/ipqc_limit_api.php';
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload,
      });
      const js = await res.json().catch(()=>null);
      if (!res.ok || !js || !js.ok){
        const msg = (js && js.message) ? js.message : ('저장 실패: ' + res.status);
        showMsg(msg, 1400);
        return;
      }
      showMsg('USL/LSL 적용됨', 900);
    }catch(e){
      showMsg('저장 실패: ' + (e && e.message ? e.message : String(e)), 1600);
    }
  }

  function escapeHtml(s){
    return String(s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  // expose
  window.openQuickGraphModal = open;
  window.closeQuickGraphModal = close;

})();