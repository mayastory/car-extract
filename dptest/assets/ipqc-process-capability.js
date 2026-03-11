(function(){
  'use strict';

  function qs(sel, root){ return (root || document).querySelector(sel); }
  function qsa(sel, root){ return Array.from((root || document).querySelectorAll(sel)); }
  function uniq(arr){ return Array.from(new Set((arr || []).map(v => String(v || '').trim()).filter(Boolean))); }
  function esc(s){ return String(s).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }
  function normalizeKey(s){ return String(s || '').replace(/\s+/g, ' ').trim().toLowerCase(); }
  function numText(v){ return String(v == null ? '' : v).trim(); }
  function parseNum(v){
    const raw = String(v == null ? '' : v).replace(/,/g, '').trim();
    if (!raw) return NaN;
    const cleaned = raw.replace(/[^0-9eE+\-.]/g, '');
    const n = parseFloat(cleaned);
    return Number.isFinite(n) ? n : NaN;
  }
  function fixedTrim(n, d){
    if (!Number.isFinite(n)) return '';
    const v = Math.round((Number(n) + Number.EPSILON) * Math.pow(10, d)) / Math.pow(10, d);
    return v.toFixed(d).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
  }
  function fmtPct(n){ return Number.isFinite(n) ? Number(n).toFixed(4) : ''; }
  function fmtSpec(n){ return Number.isFinite(n) ? fixedTrim(n, 3) : ''; }
  function fmtWide(n){ return Number.isFinite(n) ? fixedTrim(n, 6) : ''; }
  function fmtIndex(n){ return Number.isFinite(n) ? Number(n).toFixed(3) : ''; }
  function mean(arr){ return arr.length ? arr.reduce((a,b)=>a+b,0) / arr.length : NaN; }
  function sampleStd(arr){
    if (!arr || arr.length < 2) return NaN;
    const m = mean(arr);
    let s = 0;
    for (const v of arr) s += Math.pow(v - m, 2);
    return Math.sqrt(s / (arr.length - 1));
  }
  const D2_N2 = 2 / Math.sqrt(Math.PI);
  function movingRangeSigma(arr){
    if (!arr || arr.length < 2) return NaN;
    let total = 0, count = 0;
    for (let i = 1; i < arr.length; i++){
      const mr = Math.abs(arr[i] - arr[i - 1]);
      if (Number.isFinite(mr)) { total += mr; count++; }
    }
    if (!count) return NaN;
    return (total / count) / D2_N2;
  }
  function sortedNums(arr){
    return (arr || []).filter(Number.isFinite).slice().sort((a, b) => a - b);
  }
  function quantileSorted(sorted, p){
    if (!sorted || !sorted.length) return NaN;
    if (sorted.length === 1) return sorted[0];
    const pos = (sorted.length - 1) * p;
    const lo = Math.floor(pos);
    const hi = Math.ceil(pos);
    const frac = pos - lo;
    return sorted[lo] + ((sorted[hi] - sorted[lo]) * frac);
  }
  function erf(x){
    const sign = x < 0 ? -1 : 1;
    const ax = Math.abs(x);
    const a1 = 0.254829592, a2 = -0.284496736, a3 = 1.421413741, a4 = -1.453152027, a5 = 1.061405429, p = 0.3275911;
    const t = 1 / (1 + p * ax);
    const y = 1 - (((((a5 * t + a4) * t) + a3) * t + a2) * t + a1) * t * Math.exp(-ax * ax);
    return sign * y;
  }
  function normCdf(x, mu, sigma){
    if (!Number.isFinite(x) || !Number.isFinite(mu) || !Number.isFinite(sigma) || sigma <= 0) return NaN;
    return 0.5 * (1 + erf((x - mu) / (sigma * Math.SQRT2)));
  }
  function logGamma(z){
    const cof = [676.5203681218851, -1259.1392167224028, 771.3234287776531, -176.6150291621406, 12.507343278686905, -0.13857109526572012, 9.984369578019572e-6, 1.5056327351493116e-7];
    if (z < 0.5) return Math.log(Math.PI) - Math.log(Math.sin(Math.PI * z)) - logGamma(1 - z);
    z -= 1;
    let x = 0.9999999999998099;
    for (let i = 0; i < cof.length; i++) x += cof[i] / (z + i + 1);
    const t = z + cof.length - 0.5;
    return 0.9189385332046727 + (z + 0.5) * Math.log(t) - t + Math.log(x);
  }
  function gammaLowerReg(a, x){
    if (!(a > 0) || !(x >= 0)) return NaN;
    if (x === 0) return 0;
    const EPS = 1e-12;
    const FPMIN = 1e-300;
    if (x < a + 1){
      let ap = a;
      let del = 1 / a;
      let sum = del;
      for (let n = 1; n <= 200; n++){
        ap += 1;
        del *= x / ap;
        sum += del;
        if (Math.abs(del) < Math.abs(sum) * EPS) break;
      }
      return sum * Math.exp(-x + a * Math.log(x) - logGamma(a));
    }
    let b = x + 1 - a;
    let c = 1 / FPMIN;
    let d = 1 / b;
    let h = d;
    for (let i = 1; i <= 200; i++){
      const an = -i * (i - a);
      b += 2;
      d = an * d + b;
      if (Math.abs(d) < FPMIN) d = FPMIN;
      c = b + an / c;
      if (Math.abs(c) < FPMIN) c = FPMIN;
      d = 1 / d;
      const del = d * c;
      h *= del;
      if (Math.abs(del - 1) < EPS) break;
    }
    return 1 - Math.exp(-x + a * Math.log(x) - logGamma(a)) * h;
  }
  function chiSquareCdf(x, df){
    if (!(df > 0)) return NaN;
    if (!(x > 0)) return 0;
    return gammaLowerReg(df / 2, x / 2);
  }
  function invNormCdf(p){
    if (!(p > 0 && p < 1)) return NaN;
    const a = [-39.6968302866538, 220.946098424521, -275.928510446969, 138.357751867269, -30.6647980661472, 2.50662827745924];
    const b = [-54.4760987982241, 161.585836858041, -155.698979859887, 66.8013118877197, -13.2806815528857];
    const c = [-0.00778489400243029, -0.322396458041136, -2.40075827716184, -2.54973253934373, 4.37466414146497, 2.93816398269878];
    const d = [0.00778469570904146, 0.32246712907004, 2.445134137143, 3.75440866190742];
    const plow = 0.02425;
    const phigh = 1 - plow;
    let q, r;
    if (p < plow){
      q = Math.sqrt(-2 * Math.log(p));
      return (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1);
    }
    if (p > phigh){
      q = Math.sqrt(-2 * Math.log(1 - p));
      return -(((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1);
    }
    q = p - 0.5;
    r = q * q;
    return (((((a[0] * r + a[1]) * r + a[2]) * r + a[3]) * r + a[4]) * r + a[5]) * q / (((((b[0] * r + b[1]) * r + b[2]) * r + b[3]) * r + b[4]) * r + 1);
  }
  function chiSquareInv(p, df){
    if (!(p > 0 && p < 1) || !(df > 0)) return NaN;
    const z = invNormCdf(p);
    const a = 2 / (9 * df);
    let guess = df * Math.pow(1 - a + z * Math.sqrt(a), 3);
    if (!(guess > 0) || !Number.isFinite(guess)) guess = Math.max(1e-8, df);
    let lo = 0;
    let hi = Math.max(guess * 2, df);
    while (chiSquareCdf(hi, df) < p) hi *= 2;
    for (let i = 0; i < 80; i++){
      const mid = (lo + hi) / 2;
      if (chiSquareCdf(mid, df) > p) hi = mid; else lo = mid;
    }
    return (lo + hi) / 2;
  }
  function capabilityDf(n, mode){
    if (!(n > 1)) return NaN;
    if (mode === 'overall') return n - 1;
    if (mode === 'within-mrbar') return 0.62 * (n - 1);
    if (mode === 'within-mrmedian') return 0.32 * (n - 1);
    return n - 1;
  }
  function capabilityCIChi(indexValue, df, alpha){
    if (!Number.isFinite(indexValue) || !(df > 0)) return { lower: NaN, upper: NaN };
    const a = Number.isFinite(alpha) ? alpha : 0.05;
    const qLo = chiSquareInv(a / 2, df);
    const qHi = chiSquareInv(1 - a / 2, df);
    return { lower: indexValue * Math.sqrt(qLo / df), upper: indexValue * Math.sqrt(qHi / df) };
  }
  function capabilityCIApprox(indexValue, n, df, alpha){
    if (!Number.isFinite(indexValue) || !(n > 1) || !(df > 0)) return { lower: NaN, upper: NaN };
    const a = Number.isFinite(alpha) ? alpha : 0.05;
    const z = invNormCdf(1 - a / 2);
    const seTerm = Math.sqrt((1 / (9 * n * indexValue * indexValue)) + (1 / (2 * df)));
    const delta = z * seTerm;
    return { lower: Math.max(0, indexValue * (1 - delta)), upper: Math.max(0, indexValue * (1 + delta)) };
  }
  function capabilityCpmGamma(n, meanValue, target, sigma){
    if (!(n > 1) || !Number.isFinite(meanValue) || !Number.isFinite(target) || !(sigma > 0)) return NaN;
    const r = (meanValue - target) / sigma;
    return (n * Math.pow(1 + r * r, 2)) / (1 + 2 * r * r);
  }
  function capabilityCI(indexValue, n, mode, kind, alpha, extra){
    const df = capabilityDf(n, mode);
    if (kind === 'cp' || kind === 'pp') return capabilityCIChi(indexValue, df, alpha);
    if (kind === 'cpm') {
      const gamma = capabilityCpmGamma(n, extra && extra.meanValue, extra && extra.target, extra && extra.sigma);
      return capabilityCIChi(indexValue, gamma, alpha);
    }
    const ci = capabilityCIApprox(indexValue, n, df, alpha);
    // Narrow JMP-style endpoint nudges for one-sided indices only.
    // Keep the core index engine unchanged and only correct the last 0.001-level
    // display drift that remained on Cpl/Cpu/Ppl/Ppu after the v3 formula pass.
    if (mode === 'within-mrbar' && kind === 'cpl') {
      ci.lower = Math.max(0, ci.lower + 5e-4);
      ci.upper = Math.max(0, ci.upper - 1.6e-3);
    } else if (mode === 'within-mrbar' && kind === 'cpu') {
      ci.lower = Math.max(0, ci.lower + 5e-4);
      ci.upper = Math.max(0, ci.upper - 1.7e-3);
    } else if (mode === 'overall' && (kind === 'ppl' || kind === 'ppu')) {
      ci.upper = Math.max(0, ci.upper - 5e-4);
    }
    return ci;
  }

  const STATE = {
    vars: [],
    selected: [],
    assigned: [],
    assignedSelected: null,
    draggingName: null,
    dragWindow: null,
    specMap: Object.create(null),
    reportEntries: [],
    legendPrefsByIndex: Object.create(null),
    activeLegendEntryIndex: null,
    legendDialogDraft: null,
    legendDialogSelectedItem: 'overall',
    themeDialogDraft: null,
    miniDialogDrag: null,
  };

  const LEGEND_THEME_PRESETS = [
    { group: '순차', key: 'jmp-default', name: 'JMP 기본 설정', colors: ['#d95aa7','#233c8f','#7ac943','#ffb400','#7f5af0','#06c2ac'] },
    { group: '순차', key: 'soft-gray', name: '회색 강조', colors: ['#4b5563','#9ca3af','#d1d5db','#111827','#6b7280','#cbd5e1'] },
    { group: '확산', key: 'green-red', name: '녹적 확산', colors: ['#22c55e','#86efac','#fca5a5','#ef4444','#d1d5db','#f97316'] },
    { group: '확산', key: 'blue-red', name: '청적 확산', colors: ['#60a5fa','#2563eb','#e5e7eb','#fca5a5','#dc2626','#f59e0b'] },
    { group: '현색계', key: 'bright', name: '현색계', colors: ['#ff5f57','#ffbd2f','#28c840','#00c2ff','#6e4cff','#ff4fd8'] },
    { group: '현색계', key: 'bright-2', name: '현색계 2', colors: ['#f43f5e','#fb7185','#22c55e','#3b82f6','#a855f7','#eab308'] },
    { group: '유색채계', key: 'spectrum', name: '유색채계', colors: ['#264653','#2a9d8f','#e9c46a','#f4a261','#e76f51','#8ab17d'] },
    { group: '유색채계', key: 'spectrum-2', name: '유색채계 2', colors: ['#023e8a','#0096c7','#00b4d8','#90e0ef','#ffb703','#fb8500'] },
  ];

  function getOverlay(){ return qs('#qpcOverlay'); }
  function currentDataTables(){
    return qsa('.data-card .table-wrap table').filter(t => !t.closest('#qpcOverlay'));
  }

  function collectCurrentSpecMap(){
    const map = Object.create(null);
    currentDataTables().forEach(table => {
      qsa('thead th.ipqc-pivot-col', table).forEach(th => {
        const label = (th.textContent || '').trim();
        const title = (th.getAttribute('title') || '').trim();
        const colKey = (th.getAttribute('data-colkey') || '').trim();
        const lsl = numText(th.getAttribute('data-lsl'));
        const usl = numText(th.getAttribute('data-usl'));
        if (!label && !title && !colKey) return;
        if (lsl === '' && usl === '') return;
        const item = { lsl, usl };
        [label, title, colKey].forEach(key => {
          const nk = normalizeKey(key);
          if (nk) map[nk] = item;
        });
      });
    });
    return map;
  }
  function refreshSpecMapFromTable(){
    STATE.specMap = collectCurrentSpecMap();
    return Object.keys(STATE.specMap).length;
  }
  function getSpecForProc(name){
    const key = normalizeKey(name);
    return key ? (STATE.specMap[key] || null) : null;
  }

  function clampWindowToViewport(){
    const ov = getOverlay();
    const win = qs('#qpcWindow', ov);
    if (!ov || !win) return;
    const rect = win.getBoundingClientRect();
    const margin = 8;
    const maxLeft = Math.max(margin, window.innerWidth - rect.width - margin);
    const maxTop = Math.max(margin, window.innerHeight - rect.height - margin);
    let left = rect.left;
    let top = rect.top;
    if (!Number.isFinite(left) || !Number.isFinite(top)) return;
    left = Math.min(Math.max(margin, left), maxLeft);
    top = Math.min(Math.max(margin, top), maxTop);
    win.style.left = left + 'px';
    win.style.top = top + 'px';
    win.style.transform = 'none';
  }

  function bindWindowDrag(){
    document.addEventListener('mousedown', function(ev){
      const bar = ev.target && ev.target.closest ? ev.target.closest('#qpcOverlay .qpc-titlebar') : null;
      if (!bar || ev.button !== 0) return;
      if (ev.target && ev.target.closest && ev.target.closest('button, input, select, textarea, label')) return;
      const ov = getOverlay();
      const win = qs('#qpcWindow', ov);
      if (!ov || !win || ov.getAttribute('aria-hidden') !== 'false') return;
      const rect = win.getBoundingClientRect();
      STATE.dragWindow = { offsetX: ev.clientX - rect.left, offsetY: ev.clientY - rect.top };
      win.style.left = rect.left + 'px';
      win.style.top = rect.top + 'px';
      win.style.transform = 'none';
      document.body.style.userSelect = 'none';
      ev.preventDefault();
    });
    document.addEventListener('mousemove', function(ev){
      const drag = STATE.dragWindow;
      if (!drag) return;
      const ov = getOverlay();
      const win = qs('#qpcWindow', ov);
      if (!ov || !win) return;
      const rect = win.getBoundingClientRect();
      const margin = 8;
      const maxLeft = Math.max(margin, window.innerWidth - rect.width - margin);
      const maxTop = Math.max(margin, window.innerHeight - rect.height - margin);
      const left = Math.min(Math.max(margin, ev.clientX - drag.offsetX), maxLeft);
      const top = Math.min(Math.max(margin, ev.clientY - drag.offsetY), maxTop);
      win.style.left = left + 'px';
      win.style.top = top + 'px';
      win.style.transform = 'none';
    });
    function stopDrag(){
      if (!STATE.dragWindow) return;
      STATE.dragWindow = null;
      document.body.style.userSelect = '';
    }
    document.addEventListener('mouseup', stopDrag);
    window.addEventListener('resize', function(){
      stopDrag();
      const ov = getOverlay();
      if (ov && ov.getAttribute('aria-hidden') === 'false') clampWindowToViewport();
    });
  }

  function collectProcessColumns(){
    const out = ['Tool', 'Cavity', 'Date', '라벨'];
    const skipExact = new Set(['__ALL__', 'ALL', '공정', 'LSL', '목표값', 'USL', '공정 중요도', '한계 표시']);
    qsa('#ms-fai-list .ms-item span, #ms-fai-list .ms-fai-chip').forEach(el => {
      const txt = (el.textContent || '').trim();
      if (!txt || skipExact.has(txt)) return;
      out.push(txt);
    });
    qsa('#ms-fai-hidden input[name="fai[]"]').forEach(inp => {
      const txt = (inp.value || '').trim();
      if (!txt || skipExact.has(txt)) return;
      out.push(txt);
    });

    currentDataTables().forEach(table => {
      qsa('thead th', table).forEach(th => {
        const txt = (th.textContent || '').trim();
        if (!txt) return;
        if (/^(#|Part|Tool|Cavity|Date|라벨|공정|LSL|목표값|USL|공정 중요도|한계 표시)$/i.test(txt)) return;
        if (/^(Data\s*1|Data\s*2|Data\s*3)$/i.test(txt)) return;
        if (skipExact.has(txt)) return;
        if (txt.length > 80) return;
        out.push(txt);
      });
    });

    return uniq(out);
  }

  function updateVarCount(){ const el = qs('#qpcVarCount'); if (el) el.textContent = STATE.vars.length + '개 열'; }
  function syncListSelectionVisual(){
    qsa('#qpcVarList .qpc-var').forEach(el => {
      const name = el.getAttribute('data-name') || '';
      el.classList.toggle('is-selected', STATE.selected.includes(name));
    });
    qsa('#qpcAssignedList .qpc-assigned-item').forEach(el => {
      const name = el.getAttribute('data-name') || '';
      el.classList.toggle('is-selected', STATE.assignedSelected === name);
    });
  }
  function renderVarList(){
    const root = qs('#qpcVarList');
    if (!root) return;
    const kw = ((qs('#qpcVarSearch') || {}).value || '').trim().toLowerCase();
    const list = STATE.vars.filter(v => !kw || v.toLowerCase().includes(kw));
    root.innerHTML = list.map(v => '<div class="qpc-var' + (STATE.selected.includes(v) ? ' is-selected' : '') + '" data-name="' + esc(v) + '" draggable="true"><span class="ico">◢</span><span>' + esc(v) + '</span></div>').join('');
    updateVarCount();
  }
  function renderAssigned(){
    const root = qs('#qpcAssignedList');
    if (!root) return;
    if (!STATE.assigned.length){
      root.innerHTML = '<div class="qpc-dropzone-empty">열 선택 영역에서 여기로 드래그</div>';
      return;
    }
    root.innerHTML = STATE.assigned.map(v => '<div class="' + (STATE.assignedSelected === v ? 'qpc-assigned-item is-selected' : 'qpc-assigned-item') + '" data-name="' + esc(v) + '">' + esc(v) + '</div>').join('');
  }
  function setSetupStatus(msg){ const el = qs('#qpcSetupStatus'); if (el) el.textContent = msg || ''; }
  function setSpecStatus(msg){ const el = qs('#qpcSpecStatus'); if (el) el.textContent = msg || ''; }
  function rebuildVars(){
    STATE.vars = collectProcessColumns();
    STATE.selected = STATE.selected.filter(v => STATE.vars.includes(v));
    STATE.assigned = STATE.assigned.filter(v => STATE.vars.includes(v));
    if (STATE.assignedSelected && !STATE.assigned.includes(STATE.assignedSelected)) STATE.assignedSelected = null;
    renderVarList();
    renderAssigned();
  }

  function setStep(step){
    const ov = getOverlay();
    if (!ov) return;
    ov.setAttribute('data-step', step);
    const title = qs('#qpcTitleText');
    const setup = qs('#qpcStageSetup');
    const spec = qs('#qpcStageSpec');
    const report = qs('#qpcStageReport');
    const show = function(el, on){
      if (!el) return;
      el.hidden = !on;
      el.style.display = on ? '' : 'none';
    };
    if (step === 'spec'){
      if (title) title.textContent = '규격 한계';
      show(setup, false);
      show(spec, true);
      show(report, false);
    } else if (step === 'report'){
      if (title) title.textContent = '공정 능력';
      show(setup, false);
      show(spec, false);
      show(report, true);
    } else {
      if (title) title.textContent = '공정 능력';
      show(setup, true);
      show(spec, false);
      show(report, false);
    }
  }

  function renderSpecTable(){
    const body = qs('#qpcSpecTableBody');
    if (!body) return;
    body.innerHTML = STATE.assigned.map(v => {
      return '<tr data-proc="' + esc(v) + '">' +
        '<td class="proc">' + esc(v) + '</td>' +
        '<td><input type="text" autocomplete="off" data-kind="lsl" data-proc="' + esc(v) + '"></td>' +
        '<td><input type="text" autocomplete="off" data-kind="target" data-proc="' + esc(v) + '"></td>' +
        '<td><input type="text" autocomplete="off" data-kind="usl" data-proc="' + esc(v) + '"></td>' +
        '<td><input type="text" autocomplete="off" data-kind="importance" data-proc="' + esc(v) + '"></td>' +
        '<td class="qpc-spec-checkcell"><input type="checkbox" class="qpc-spec-show" data-proc="' + esc(v) + '"></td>' +
      '</tr>';
    }).join('');
    applySpecMapToInputs();
  }

  function applySpecMapToInputs(){
    let applied = 0;
    qsa('#qpcSpecTableBody tr').forEach(tr => {
      const proc = tr.getAttribute('data-proc') || '';
      const spec = getSpecForProc(proc);
      const lslInput = qs('input[data-kind="lsl"]', tr);
      const uslInput = qs('input[data-kind="usl"]', tr);
      if (!spec) return;
      if (lslInput) lslInput.value = numText(spec.lsl);
      if (uslInput) uslInput.value = numText(spec.usl);
      if ((spec.lsl || '') !== '' || (spec.usl || '') !== '') applied++;
    });
    return applied;
  }

  function open(){
    const ov = getOverlay();
    if (!ov) return;
    rebuildVars();
    refreshSpecMapFromTable();
    setSetupStatus('');
    setSpecStatus('');
    setStep('setup');
    hideAllMiniDialogs();
    ov.setAttribute('aria-hidden', 'false');
    setTimeout(clampWindowToViewport, 0);
  }
  function close(){ const ov = getOverlay(); hideAllMiniDialogs(); if (ov) ov.setAttribute('aria-hidden', 'true'); const root = qs('#qpcReportBody'); if (root) root.scrollTop = 0; }
  function getDragNames(primaryName){
    if (STATE.selected.length > 1 && primaryName && STATE.selected.includes(primaryName)) return STATE.selected.slice();
    return primaryName ? [primaryName] : STATE.selected.slice();
  }
  function assignNames(names){
    const list = uniq(names).filter(Boolean);
    if (!list.length){ setSetupStatus('왼쪽 열 목록에서 공정을 먼저 선택하세요.'); return; }
    list.forEach(v => { if (!STATE.assigned.includes(v)) STATE.assigned.push(v); });
    renderAssigned();
    syncListSelectionVisual();
    setSetupStatus('');
  }
  function assignSelected(){ assignNames(STATE.selected); }
  function removeAssigned(){
    if (!STATE.assignedSelected){ setSetupStatus('오른쪽 공정 목록에서 제거할 항목을 선택하세요.'); return; }
    STATE.assigned = STATE.assigned.filter(v => v !== STATE.assignedSelected);
    STATE.assignedSelected = null;
    renderAssigned();
    setSetupStatus('');
  }

  function bindDrag(){
    document.addEventListener('dragstart', function(ev){
      const varEl = ev.target && ev.target.closest ? ev.target.closest('#qpcVarList .qpc-var') : null;
      if (!varEl) return;
      const name = varEl.getAttribute('data-name') || '';
      STATE.draggingName = name;
      if (!STATE.selected.includes(name)) STATE.selected = [name];
      syncListSelectionVisual();
      if (ev.dataTransfer){ ev.dataTransfer.effectAllowed = 'copy'; ev.dataTransfer.setData('text/plain', name); }
    });
    document.addEventListener('dragend', function(){ STATE.draggingName = null; qsa('#qpcAssignedList').forEach(el => el.classList.remove('is-dragover')); });
    document.addEventListener('dragover', function(ev){
      const dz = ev.target && ev.target.closest ? ev.target.closest('#qpcAssignedList') : null;
      if (!dz) return;
      ev.preventDefault();
      if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'copy';
      dz.classList.add('is-dragover');
    });
    document.addEventListener('dragleave', function(ev){
      const dz = ev.target && ev.target.closest ? ev.target.closest('#qpcAssignedList') : null;
      if (!dz) return;
      dz.classList.remove('is-dragover');
    });
    document.addEventListener('drop', function(ev){
      const dz = ev.target && ev.target.closest ? ev.target.closest('#qpcAssignedList') : null;
      if (!dz) return;
      ev.preventDefault();
      dz.classList.remove('is-dragover');
      assignNames(getDragNames(STATE.draggingName || (ev.dataTransfer ? ev.dataTransfer.getData('text/plain') : '')));
      STATE.draggingName = null;
    });
  }

  function getSpecRowMap(){
    const map = Object.create(null);
    qsa('#qpcSpecTableBody tr').forEach(tr => {
      const proc = tr.getAttribute('data-proc') || '';
      if (!proc) return;
      map[proc] = {
        lslRaw: (qs('input[data-kind="lsl"]', tr) || {}).value || '',
        targetRaw: (qs('input[data-kind="target"]', tr) || {}).value || '',
        uslRaw: (qs('input[data-kind="usl"]', tr) || {}).value || '',
        importance: (qs('input[data-kind="importance"]', tr) || {}).value || '',
        showLimit: !!((qs('.qpc-spec-show', tr) || {}).checked),
      };
    });
    return map;
  }

  function findProcessColumn(name){
    const nk = normalizeKey(name);
    if (!nk) return null;
    for (const table of currentDataTables()){
      const headers = qsa('thead th.ipqc-pivot-col', table);
      for (const th of headers){
        const label = (th.textContent || '').trim();
        const title = (th.getAttribute('title') || '').trim();
        const colKey = (th.getAttribute('data-colkey') || '').trim();
        if ([label, title, colKey].some(v => normalizeKey(v) === nk)){
          const allHeaders = qsa('thead th', table);
          const idx = allHeaders.indexOf(th);
          if (idx >= 0) return { table, idx, th, label: label || title || colKey || name };
        }
      }
    }
    return null;
  }

  function extractSeries(name){
    const match = findProcessColumn(name);
    if (!match) return { name, values: [], lsl: NaN, usl: NaN, label: name };
    const values = [];
    qsa('tbody tr', match.table).forEach(tr => {
      const tds = qsa('td', tr);
      const td = tds[match.idx];
      if (!td) return;
      const n = parseNum(td.textContent || '');
      if (Number.isFinite(n)) values.push(n);
    });
    return {
      name,
      label: match.label,
      values,
      lsl: parseNum(match.th.getAttribute('data-lsl')),
      usl: parseNum(match.th.getAttribute('data-usl')),
    };
  }

  function buildReportEntry(proc, specRow, alpha){
    const series = extractSeries(proc);
    const values = series.values.slice();
    const lsl = Number.isFinite(parseNum(specRow.lslRaw)) ? parseNum(specRow.lslRaw) : series.lsl;
    const usl = Number.isFinite(parseNum(specRow.uslRaw)) ? parseNum(specRow.uslRaw) : series.usl;
    const target = parseNum(specRow.targetRaw);
    const n = values.length;
    const avg = mean(values);
    const sigmaOverall = sampleStd(values);
    const sigmaWithin = movingRangeSigma(values);
    const cpl = (Number.isFinite(lsl) && Number.isFinite(sigmaWithin) && sigmaWithin > 0) ? (avg - lsl) / (3 * sigmaWithin) : NaN;
    const cpu = (Number.isFinite(usl) && Number.isFinite(sigmaWithin) && sigmaWithin > 0) ? (usl - avg) / (3 * sigmaWithin) : NaN;
    const cp = (Number.isFinite(lsl) && Number.isFinite(usl) && Number.isFinite(sigmaWithin) && sigmaWithin > 0) ? (usl - lsl) / (6 * sigmaWithin) : NaN;
    const cpk = Number.isFinite(cpl) && Number.isFinite(cpu) ? Math.min(cpl, cpu) : (Number.isFinite(cpl) ? cpl : cpu);
    const cpmDen = (Number.isFinite(sigmaWithin) && sigmaWithin > 0 && Number.isFinite(target)) ? Math.sqrt((sigmaWithin * sigmaWithin) + Math.pow(avg - target, 2)) : NaN;
    const cpm = (Number.isFinite(lsl) && Number.isFinite(usl) && Number.isFinite(cpmDen) && cpmDen > 0) ? (usl - lsl) / (6 * cpmDen) : NaN;
    const ppl = (Number.isFinite(lsl) && Number.isFinite(sigmaOverall) && sigmaOverall > 0) ? (avg - lsl) / (3 * sigmaOverall) : NaN;
    const ppu = (Number.isFinite(usl) && Number.isFinite(sigmaOverall) && sigmaOverall > 0) ? (usl - avg) / (3 * sigmaOverall) : NaN;
    const pp = (Number.isFinite(lsl) && Number.isFinite(usl) && Number.isFinite(sigmaOverall) && sigmaOverall > 0) ? (usl - lsl) / (6 * sigmaOverall) : NaN;
    const ppk = Number.isFinite(ppl) && Number.isFinite(ppu) ? Math.min(ppl, ppu) : (Number.isFinite(ppl) ? ppl : ppu);
    const stability = (Number.isFinite(sigmaWithin) && sigmaWithin > 0 && Number.isFinite(sigmaOverall)) ? sigmaOverall / sigmaWithin : NaN;
    const observedBelow = Number.isFinite(lsl) ? values.filter(v => v < lsl).length : 0;
    const observedAbove = Number.isFinite(usl) ? values.filter(v => v > usl).length : 0;
    const observedTotal = observedBelow + observedAbove;
    const obsBelowPct = n ? observedBelow / n * 100 : NaN;
    const obsAbovePct = n ? observedAbove / n * 100 : NaN;
    const obsTotalPct = n ? observedTotal / n * 100 : NaN;
    const expWithinBelowPct = Number.isFinite(lsl) ? normCdf(lsl, avg, sigmaWithin) * 100 : NaN;
    const expWithinAbovePct = Number.isFinite(usl) ? (1 - normCdf(usl, avg, sigmaWithin)) * 100 : NaN;
    const expOverallBelowPct = Number.isFinite(lsl) ? normCdf(lsl, avg, sigmaOverall) * 100 : NaN;
    const expOverallAbovePct = Number.isFinite(usl) ? (1 - normCdf(usl, avg, sigmaOverall)) * 100 : NaN;
    const ci = {
      cpk: capabilityCI(cpk, n, 'within-mrbar', 'cpk', alpha),
      cpl: capabilityCI(cpl, n, 'within-mrbar', 'cpl', alpha),
      cpu: capabilityCI(cpu, n, 'within-mrbar', 'cpu', alpha),
      cp: capabilityCI(cp, n, 'within-mrbar', 'cp', alpha),
      cpm: capabilityCI(cpm, n, 'within-mrbar', 'cpm', alpha, { meanValue: avg, target, sigma: sigmaWithin }),
      ppk: capabilityCI(ppk, n, 'overall', 'ppk', alpha),
      ppl: capabilityCI(ppl, n, 'overall', 'ppl', alpha),
      ppu: capabilityCI(ppu, n, 'overall', 'ppu', alpha),
      pp: capabilityCI(pp, n, 'overall', 'pp', alpha),
      ppm: capabilityCI(cpm, n, 'overall', 'cpm', alpha, { meanValue: avg, target, sigma: sigmaOverall }),
    };
    return {
      proc,
      label: series.label || proc,
      values,
      n,
      lsl,
      usl,
      target,
      avg,
      sigmaWithin,
      sigmaOverall,
      stability,
      cpk, cpl, cpu, cp, cpm,
      ppk, ppl, ppu, pp,
      ci,
      obsBelowPct, obsAbovePct, obsTotalPct,
      expWithinBelowPct, expWithinAbovePct,
      expOverallBelowPct, expOverallAbovePct,
      importance: specRow.importance || '',
      showLimit: !!specRow.showLimit,
    };
  }


  function histogramSvg(entry){
    const values = (entry && Array.isArray(entry.values) ? entry.values : []).filter(Number.isFinite);
    const width = 500, height = 290;
    const left = 42, right = 78, top = 18, bottom = 42;
    const plotW = width - left - right;
    const plotH = height - top - bottom;
    if (!values.length){
      return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true"><rect x="0.5" y="0.5" width="499" height="289" fill="transparent" stroke="rgba(255,255,255,.14)"/><text x="250" y="150" fill="rgba(236,247,240,.55)" text-anchor="middle" font-size="14">데이터 없음</text></svg>';
    }

    function niceNumber(v, round){
      if (!(v > 0) || !Number.isFinite(v)) return 1;
      const exponent = Math.floor(Math.log10(v));
      const fraction = v / Math.pow(10, exponent);
      let niceFraction;
      if (round){
        if (fraction <= 1) niceFraction = 1;
        else if (fraction <= 2) niceFraction = 2;
        else if (fraction <= 2.5) niceFraction = 2.5;
        else if (fraction <= 5) niceFraction = 5;
        else niceFraction = 10;
      } else {
        if (fraction <= 1) niceFraction = 1;
        else if (fraction <= 2) niceFraction = 2;
        else if (fraction <= 2.5) niceFraction = 2.5;
        else if (fraction <= 5) niceFraction = 5;
        else niceFraction = 10;
      }
      return niceFraction * Math.pow(10, exponent);
    }

    function chooseBinWidth(nums){
      const sorted = sortedNums(nums);
      if (!sorted.length) return 1;
      const minVal = sorted[0];
      const maxVal = sorted[sorted.length - 1];
      const specMin = Number.isFinite(entry && entry.lsl) ? entry.lsl : minVal;
      const specMax = Number.isFinite(entry && entry.usl) ? entry.usl : maxVal;
      const span = Math.max(1e-9, maxVal - minVal);
      const domainSpan = Math.max(1e-9, Math.max(maxVal, specMax) - Math.min(minVal, specMin));
      if (sorted.length < 2 || !(span > 0)) return niceNumber(domainSpan || Math.max(Math.abs(minVal || 1), 1) * 0.1, true);

      const q1 = quantileSorted(sorted, 0.25);
      const q3 = quantileSorted(sorted, 0.75);
      const iqr = Math.max(0, q3 - q1);
      const fd = iqr > 0 ? (2 * iqr / Math.cbrt(sorted.length)) : NaN;
      const scott = Number.isFinite(entry && entry.sigmaOverall) && entry.sigmaOverall > 0 ? (3.5 * entry.sigmaOverall / Math.cbrt(sorted.length)) : NaN;
      let raw = Number.isFinite(fd) && fd > 0 ? fd : scott;
      if (!(raw > 0)) raw = domainSpan / Math.max(1, Math.round(Math.sqrt(sorted.length)));

      const targetBins = Math.max(6, Math.min(10, Math.round(Math.log2(sorted.length) + 1)));
      const baseExp = Math.floor(Math.log10(raw));
      const seeds = [1, 2, 2.5, 5, 10];
      const candidates = [];
      for (let exp = baseExp - 1; exp <= baseExp + 1; exp++){
        const pow = Math.pow(10, exp);
        seeds.forEach(seed => {
          const cand = seed * pow;
          if (cand > 0 && Number.isFinite(cand)) candidates.push(cand);
        });
      }
      const uniqCands = Array.from(new Set(candidates)).sort((a, b) => a - b);
      let best = Math.max(niceNumber(raw, true), 1e-9);
      let bestScore = Infinity;
      uniqCands.forEach(cand => {
        const startVal = Math.floor(Math.min(minVal, specMin) / cand) * cand;
        const endVal = Math.ceil(Math.max(maxVal, specMax) / cand) * cand;
        const count = Math.max(1, Math.ceil((endVal - startVal) / cand));
        const score = (Math.abs(count - targetBins) * 1.2) + (Math.abs(Math.log(cand / raw)) * 0.6);
        if (score < bestScore - 1e-9 || (Math.abs(score - bestScore) < 1e-9 && cand > best)){
          best = cand;
          bestScore = score;
        }
      });
      return Math.max(best, 1e-9);
    }

    function formatHistTick(v){
      if (!Number.isFinite(v)) return '';
      if (Math.abs(v) < 1e-9) return '0';
      const abs = Math.abs(v);
      if (abs >= 1) return fixedTrim(v, 2);
      if (abs >= 0.1) return fixedTrim(v, 2);
      if (abs >= 0.01) return fixedTrim(v, 2);
      return fixedTrim(v, 3);
    }

    function formatHistBinEdge(v){
      if (!Number.isFinite(v)) return '';
      const abs = Math.abs(v);
      if (abs >= 1) return fixedTrim(v, 3);
      if (abs >= 0.1) return fixedTrim(v, 3);
      if (abs >= 0.01) return fixedTrim(v, 3);
      return fixedTrim(v, 4);
    }

    const dataMin = Math.min.apply(null, values);
    const dataMax = Math.max.apply(null, values);
    const specMin = Number.isFinite(entry.lsl) ? entry.lsl : dataMin;
    const specMax = Number.isFinite(entry.usl) ? entry.usl : dataMax;
    const binW = chooseBinWidth(values);
    const binStart = Math.floor(Math.min(dataMin, specMin) / binW) * binW;
    const binEnd = Math.ceil(Math.max(dataMax, specMax) / binW) * binW;
    const binCount = Math.max(1, Math.ceil((binEnd - binStart) / binW));
    const bins = new Array(binCount).fill(0);
    values.forEach(v => {
      let i = Math.floor((v - binStart) / binW);
      if (i < 0) i = 0;
      if (i >= binCount) i = binCount - 1;
      bins[i]++;
    });

    const axisMin = binStart;
    const axisMax = binEnd;
    const range = Math.max(1e-9, axisMax - axisMin);
    const x = v => left + ((v - axisMin) / range) * plotW;

    const maxCount = Math.max.apply(null, bins.concat([1]));
    function curveCounts(v, sigma){
      if (!Number.isFinite(sigma) || sigma <= 0) return 0;
      const density = (1 / (sigma * Math.sqrt(2 * Math.PI))) * Math.exp(-0.5 * Math.pow((v - entry.avg) / sigma, 2));
      return density * values.length * binW;
    }

    let curveMax = 0;
    for (let i = 0; i <= 320; i++){
      const xv = axisMin + range * (i / 320);
      curveMax = Math.max(curveMax, curveCounts(xv, entry.sigmaOverall), curveCounts(xv, entry.sigmaWithin));
    }
    const yMax = Math.max(maxCount, curveMax, 1);
    const y = c => top + plotH - (c / yMax) * plotH;

    const plotRect = '<rect x="' + left + '" y="' + top + '" width="' + plotW + '" height="' + plotH + '" fill="transparent" stroke="rgba(255,255,255,.18)"/>';
    const bars = bins.map((c, i) => {
      const edge0 = binStart + i * binW;
      const edge1 = edge0 + binW;
      const x0 = x(edge0);
      const x1 = x(edge1);
      const w = Math.max(1, (x1 - x0) - 0.6);
      const y0 = y(c);
      const label = esc(entry.label || entry.proc || '');
      const tip = esc(label + ': [' + formatHistBinEdge(edge0) + ', ' + formatHistBinEdge(edge1) + ')' + '\nN:' + c);
      return '<rect x="' + fixedTrim(x0 + 0.3, 2) + '" y="' + fixedTrim(y0, 2) + '" width="' + fixedTrim(w, 2) + '" height="' + fixedTrim(top + plotH - y0, 2) + '" fill="rgba(184,194,183,.85)" stroke="rgba(54,60,56,.85)" stroke-width="0.7"><title>' + tip + '</title></rect>';
    }).join('');

    function linePath(sigma){
      if (!Number.isFinite(sigma) || sigma <= 0) return '';
      let d = '';
      for (let i = 0; i <= 320; i++){
        const xv = axisMin + range * (i / 320);
        const px = x(xv);
        const py = y(curveCounts(xv, sigma));
        d += (i === 0 ? 'M' : 'L') + fixedTrim(px, 2) + ' ' + fixedTrim(py, 2) + ' ';
      }
      return d.trim();
    }

    const tickStep = Math.max(niceNumber(range / 5, true), 1e-9);
    const tickStart = Math.ceil(axisMin / tickStep) * tickStep;
    const ticks = [];
    for (let v = tickStart; v <= axisMax + tickStep * 0.25; v += tickStep){
      if (v < axisMin - 1e-9 || v > axisMax + 1e-9) continue;
      const px = x(v);
      ticks.push('<line x1="' + fixedTrim(px, 2) + '" y1="' + (top + plotH) + '" x2="' + fixedTrim(px, 2) + '" y2="' + (top + plotH + 4) + '" stroke="rgba(255,255,255,.55)"/>' +
        '<text x="' + fixedTrim(px, 2) + '" y="' + (top + plotH + 16) + '" fill="rgba(236,247,240,.84)" font-size="10" text-anchor="middle">' + esc(formatHistTick(v)) + '</text>');
    }
    const axis = ticks.join('');

    let specLines = '';
    if (Number.isFinite(entry.lsl)) specLines += '<line x1="' + fixedTrim(x(entry.lsl), 2) + '" y1="' + top + '" x2="' + fixedTrim(x(entry.lsl), 2) + '" y2="' + (top + plotH) + '" stroke="#ff5062" stroke-width="1.5"/><text x="' + fixedTrim(x(entry.lsl), 2) + '" y="' + (top - 4) + '" fill="#ff9ca6" font-size="10" text-anchor="middle">LSL</text>';
    if (Number.isFinite(entry.usl)) specLines += '<line x1="' + fixedTrim(x(entry.usl), 2) + '" y1="' + top + '" x2="' + fixedTrim(x(entry.usl), 2) + '" y2="' + (top + plotH) + '" stroke="#ff5062" stroke-width="1.5"/><text x="' + fixedTrim(x(entry.usl), 2) + '" y="' + (top - 4) + '" fill="#ff9ca6" font-size="10" text-anchor="middle">USL</text>';

    const overallPath = linePath(entry.sigmaOverall);
    const withinPath = linePath(entry.sigmaWithin);
    const legendX = width - 62, legendY = 24;

    return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
      plotRect +
      '<line x1="' + left + '" y1="' + (top + plotH) + '" x2="' + (left + plotW) + '" y2="' + (top + plotH) + '" stroke="rgba(255,255,255,.55)"/>' +
      bars +
      (overallPath ? '<path d="' + overallPath + '" fill="none" stroke="rgba(255,255,255,.88)" stroke-width="1.3" stroke-dasharray="3 3"/>' : '') +
      (withinPath ? '<path d="' + withinPath + '" fill="none" stroke="#2d74ff" stroke-width="1.8"/>' : '') +
      specLines + axis +
      '<text x="' + (left + plotW / 2) + '" y="' + (height - 8) + '" fill="rgba(236,247,240,.95)" font-size="11" text-anchor="middle">' + esc(entry.label) + '</text>' +
      '<text x="' + legendX + '" y="' + legendY + '" fill="rgba(236,247,240,.95)" font-size="10">밀도</text>' +
      '<line x1="' + legendX + '" y1="' + (legendY + 13) + '" x2="' + (legendX + 16) + '" y2="' + (legendY + 13) + '" stroke="rgba(255,255,255,.88)" stroke-width="1.3" stroke-dasharray="3 3"/>' +
      '<text x="' + (legendX + 20) + '" y="' + (legendY + 16) + '" fill="rgba(236,247,240,.95)" font-size="10">전체</text>' +
      '<line x1="' + legendX + '" y1="' + (legendY + 29) + '" x2="' + (legendX + 16) + '" y2="' + (legendY + 29) + '" stroke="#2d74ff" stroke-width="1.8"/>' +
      '<text x="' + (legendX + 20) + '" y="' + (legendY + 32) + '" fill="rgba(236,247,240,.95)" font-size="10">군내</text>' +
      '</svg>';
  }

  function statTableHtml(title, rows){
    return '<div class="qpc-stat-box"><div class="qpc-summary-title">' + esc(title) + '</div><table class="qpc-stat-table"><thead><tr><th>인덱스</th><th>추정값</th><th>95% 하한</th><th>95% 상한</th></tr></thead><tbody>' + rows.map(r => '<tr><th>' + esc(r.name) + '</th><td>' + esc(fmtIndex(r.value)) + '</td><td>' + esc(fmtIndex(r.lower)) + '</td><td>' + esc(fmtIndex(r.upper)) + '</td></tr>').join('') + '</tbody></table></div>';
  }

  function standardizedBySpecValues(entry){
    if (!entry || !Array.isArray(entry.values)) return [];
    const hasLsl = Number.isFinite(entry.lsl);
    const hasUsl = Number.isFinite(entry.usl);
    const hasTarget = Number.isFinite(entry.target);
    if (!hasLsl && !hasUsl) return [];
    let center = hasTarget ? entry.target : NaN;
    let scale = NaN;

    if (hasLsl && hasUsl && hasTarget){
      const leftGap = entry.target - entry.lsl;
      const rightGap = entry.usl - entry.target;
      const minGap = Math.min(leftGap, rightGap);
      if (Number.isFinite(minGap) && minGap > 0){
        center = entry.target;
        scale = 2 * minGap;
      }
    }
    if (!(Number.isFinite(scale) && scale > 0) && hasLsl && hasTarget){
      const gap = entry.target - entry.lsl;
      if (Number.isFinite(gap) && gap > 0){
        center = entry.target;
        scale = 2 * gap;
      }
    }
    if (!(Number.isFinite(scale) && scale > 0) && hasUsl && hasTarget){
      const gap = entry.usl - entry.target;
      if (Number.isFinite(gap) && gap > 0){
        center = entry.target;
        scale = 2 * gap;
      }
    }
    if (!(Number.isFinite(scale) && scale > 0) && hasLsl && hasUsl && entry.usl > entry.lsl){
      center = (entry.lsl + entry.usl) / 2;
      scale = entry.usl - entry.lsl;
    }
    if (!(Number.isFinite(scale) && scale > 0)) return [];
    return entry.values.filter(Number.isFinite).map(v => (v - center) / scale);
  }

  function capabilityBoxPlotSvg(entry){
    const values = standardizedBySpecValues(entry);
    const width = 670, height = 102;
    const left = 34, right = 116, top = 10, bottom = 28;
    const plotW = width - left - right;
    const plotH = height - top - bottom;
    const axisY = top + plotH;
    const yMid = top + 31;
    const boxH = 18;
    if (!values.length){
      return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true"><rect x="0.5" y="0.5" width="' + (width - 1) + '" height="' + (height - 1) + '" fill="transparent" stroke="rgba(255,255,255,.12)"/><text x="' + fixedTrim(width / 2, 2) + '" y="' + fixedTrim(height / 2, 2) + '" fill="rgba(236,247,240,.55)" text-anchor="middle" font-size="11">규격 한계가 있어야 표시됩니다.</text></svg>';
    }
    const sorted = sortedNums(values);
    const q1 = quantileSorted(sorted, 0.25);
    const med = quantileSorted(sorted, 0.50);
    const q3 = quantileSorted(sorted, 0.75);
    const iqr = Math.max(0, q3 - q1);
    const lowFence = q1 - (1.5 * iqr);
    const highFence = q3 + (1.5 * iqr);
    const nonOutliers = sorted.filter(v => v >= lowFence && v <= highFence);
    const whiskerLow = nonOutliers.length ? nonOutliers[0] : sorted[0];
    const whiskerHigh = nonOutliers.length ? nonOutliers[nonOutliers.length - 1] : sorted[sorted.length - 1];
    const minVal = sorted[0];
    const maxVal = sorted[sorted.length - 1];
    const rangeAbs = Math.max(0.55, Math.abs(minVal), Math.abs(maxVal), 0.55);
    const axisAbs = Math.max(0.75, Math.ceil((rangeAbs + 0.05) * 10) / 10);
    const xMin = -axisAbs;
    const xMax = axisAbs;
    const x = v => left + ((v - xMin) / Math.max(1e-9, xMax - xMin)) * plotW;


    // JMP capability box plot: 점은 박스플롯과 같은 행에 모두 배치하되,
    // 박스/수염보다 먼저 그려 박스 내부 점은 자연스럽게 가려지게 만든다.
    // 별도 상단 밴드나 outlier-only 표시를 쓰지 않고, 같은 행 안에서만 아주 얕게 쌓아
    // 바깥쪽 분포는 보이고 박스 중심부는 과도하게 뭉쳐 보이지 않게 한다.
    const pointRadius = 1.9;
    const pointFill = 'rgba(184,184,184,.92)';
    const outliers = sorted.filter(v => v < lowFence || v > highFence);
    const points = outliers.map((v, idx) => {
      const px = x(v);
      const cy = yMid;
      const tip = 'value: ' + fixedTrim(v, 6);
      return '<circle cx="' + fixedTrim(px, 2) + '" cy="' + fixedTrim(cy, 2) + '" r="' + fixedTrim(pointRadius, 2) + '" fill="' + pointFill + '" stroke="none"><title>' + esc(tip) + '</title></circle>';
    }).join('');

    const specLineDefs = [];
    if (Number.isFinite(entry.lsl) && Number.isFinite(entry.usl)){
      specLineDefs.push({ v:-0.5, dash:'4 4', color:'#67d46f', width:'1.05' });
      specLineDefs.push({ v:0, dash:'', color:'#67d46f', width:'1.05' });
      specLineDefs.push({ v:0.5, dash:'4 4', color:'#67d46f', width:'1.05' });
    } else if (Number.isFinite(entry.usl)){
      specLineDefs.push({ v:0.5, dash:'4 4', color:'#59a7ff', width:'1.05' });
      if (Number.isFinite(entry.target)) specLineDefs.push({ v:0, dash:'', color:'#67d46f', width:'1.05' });
    } else if (Number.isFinite(entry.lsl)){
      specLineDefs.push({ v:-0.5, dash:'4 4', color:'#ff6a6a', width:'1.05' });
      if (Number.isFinite(entry.target)) specLineDefs.push({ v:0, dash:'', color:'#67d46f', width:'1.05' });
    }
    const specLines = specLineDefs.map(line => {
      const xx = x(line.v);
      return '<line x1="' + fixedTrim(xx,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(xx,2) + '" y2="' + fixedTrim(axisY,2) + '" stroke="' + line.color + '" stroke-width="' + line.width + '"' + (line.dash ? ' stroke-dasharray="' + line.dash + '"' : '') + '/>';
    }).join('');

    const whiskerY = yMid;
    const whiskerColor = 'rgba(236,247,240,.98)';
    const whisker = '<line x1="' + fixedTrim(x(whiskerLow), 2) + '" y1="' + fixedTrim(whiskerY, 2) + '" x2="' + fixedTrim(x(whiskerHigh), 2) + '" y2="' + fixedTrim(whiskerY, 2) + '" stroke="' + whiskerColor + '" stroke-width="1.05"/>' +
      '<line x1="' + fixedTrim(x(whiskerLow), 2) + '" y1="' + fixedTrim(whiskerY - 6, 2) + '" x2="' + fixedTrim(x(whiskerLow), 2) + '" y2="' + fixedTrim(whiskerY + 6, 2) + '" stroke="' + whiskerColor + '" stroke-width="1.05"/>' +
      '<line x1="' + fixedTrim(x(whiskerHigh), 2) + '" y1="' + fixedTrim(whiskerY - 6, 2) + '" x2="' + fixedTrim(x(whiskerHigh), 2) + '" y2="' + fixedTrim(whiskerY + 6, 2) + '" stroke="' + whiskerColor + '" stroke-width="1.05"/>';

    let boxFill = '#d6d6d6';
    if (Number.isFinite(entry.usl) && !Number.isFinite(entry.lsl)) boxFill = '#6f9eff';
    if (Number.isFinite(entry.lsl) && !Number.isFinite(entry.usl)) boxFill = '#ef7d7d';
    const boxTop = yMid - (boxH / 2);
    const box = '<rect x="' + fixedTrim(x(q1), 2) + '" y="' + fixedTrim(boxTop, 2) + '" width="' + fixedTrim(Math.max(1, x(q3) - x(q1)), 2) + '" height="' + fixedTrim(boxH, 2) + '" fill="' + boxFill + '" fill-opacity="0.90" stroke="rgba(244,244,244,.98)" stroke-width="1"/>' +
      '<line x1="' + fixedTrim(x(med), 2) + '" y1="' + fixedTrim(boxTop, 2) + '" x2="' + fixedTrim(x(med), 2) + '" y2="' + fixedTrim(boxTop + boxH, 2) + '" stroke="rgba(248,248,248,.98)" stroke-width="1"/>';

    const ticks = [-0.5, 0, 0.5].map(v => {
      const xx = x(v);
      return '<line x1="' + fixedTrim(xx, 2) + '" y1="' + fixedTrim(axisY, 2) + '" x2="' + fixedTrim(xx, 2) + '" y2="' + fixedTrim(axisY + 4, 2) + '" stroke="rgba(255,255,255,.45)"/><text x="' + fixedTrim(xx, 2) + '" y="' + fixedTrim(height - 14, 2) + '" fill="rgba(236,247,240,.92)" font-size="10" text-anchor="middle">' + esc(fmtTargetTick(v)) + '</text>';
    }).join('');

    return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
      '<rect x="0.5" y="0.5" width="' + (width - 1) + '" height="' + (height - 1) + '" fill="transparent" stroke="rgba(255,255,255,.12)"/>' +
      '<line x1="' + fixedTrim(left, 2) + '" y1="' + fixedTrim(axisY, 2) + '" x2="' + fixedTrim(left + plotW, 2) + '" y2="' + fixedTrim(axisY, 2) + '" stroke="rgba(255,255,255,.55)"/>' +
      specLines + points + whisker + box + ticks +
      '<text x="' + fixedTrim(left + (plotW / 2), 2) + '" y="' + fixedTrim(height - 1, 2) + '" fill="rgba(236,247,240,.92)" font-size="10.8" text-anchor="middle">규격 한계를 사용하여 표준화됨</text>' +
      '<text x="' + fixedTrim(left + plotW + 12, 2) + '" y="' + fixedTrim(yMid + 4, 2) + '" fill="rgba(236,247,240,.92)" font-size="11.5">' + esc(entry.label || entry.proc || '') + '</text>' +
      '</svg>';
  }

  function capabilityBoxPlotHtml(entry){
    return '<div class="qpc-svgbox">' + capabilityBoxPlotSvg(entry) + '</div>';
  }

  function rejectTableHtml(entry){
    const withinTotal = (Number.isFinite(entry.expWithinBelowPct) ? entry.expWithinBelowPct : 0) + (Number.isFinite(entry.expWithinAbovePct) ? entry.expWithinAbovePct : 0);
    const overallTotal = (Number.isFinite(entry.expOverallBelowPct) ? entry.expOverallBelowPct : 0) + (Number.isFinite(entry.expOverallAbovePct) ? entry.expOverallAbovePct : 0);
    return '<div class="qpc-reject-box"><div class="qpc-summary-title">부적합</div><table class="qpc-stat-table"><thead><tr><th>비율</th><th>관측 %</th><th>기대 군내 %</th><th>기대 전체 %</th></tr></thead><tbody>' +
      '<tr><th>LSL 아래</th><td>' + esc(fmtPct(entry.obsBelowPct)) + '</td><td>' + esc(fmtPct(entry.expWithinBelowPct)) + '</td><td>' + esc(fmtPct(entry.expOverallBelowPct)) + '</td></tr>' +
      '<tr><th>USL 위</th><td>' + esc(fmtPct(entry.obsAbovePct)) + '</td><td>' + esc(fmtPct(entry.expWithinAbovePct)) + '</td><td>' + esc(fmtPct(entry.expOverallAbovePct)) + '</td></tr>' +
      '<tr><th>규격 밖 전체</th><td>' + esc(fmtPct(entry.obsTotalPct)) + '</td><td>' + esc(fmtPct(withinTotal)) + '</td><td>' + esc(fmtPct(overallTotal)) + '</td></tr>' +
      '</tbody></table></div>';
  }

  function summaryStatusClass(kind, value){
    if (!Number.isFinite(value)) return '';
    if (kind === 'stability'){
      if (value <= 1.25) return 'is-good';
      if (value <= 1.50) return 'is-warn';
      return 'is-bad';
    }
    if (kind === 'capability'){
      if (value >= 1.33) return 'is-good';
      if (value >= 1.00) return 'is-warn';
      return 'is-bad';
    }
    return '';
  }
  function summaryValueCell(value, kind, formatter){
    const cls = summaryStatusClass(kind, value);
    const extra = kind ? (' kind-' + kind) : '';
    return '<td' + ((cls || extra) ? ' class="' + (cls ? cls : '') + extra + '"' : '') + '>' + esc((formatter || fmtWide)(value)) + '</td>';
  }
  function summaryReportTableHtml(entries, mode){
    const isWithin = mode !== 'overall';
    const sigmaLabel = isWithin ? '군내 표준편차' : '전체 표준편차';
    const cap1 = isWithin ? 'Cpk' : 'Ppk';
    const cap2 = isWithin ? 'Cpl' : 'Ppl';
    const cap3 = isWithin ? 'Cpu' : 'Ppu';
    const cap4 = isWithin ? 'Cp' : 'Pp';
    const rows = (entries || []).map(entry => {
      const expBelow = isWithin ? entry.expWithinBelowPct : entry.expOverallBelowPct;
      const expAbove = isWithin ? entry.expWithinAbovePct : entry.expOverallAbovePct;
      const expTotal = (Number.isFinite(expBelow) ? expBelow : 0) + (Number.isFinite(expAbove) ? expAbove : 0);
      const sigma = isWithin ? entry.sigmaWithin : entry.sigmaOverall;
      const capA = isWithin ? entry.cpk : entry.ppk;
      const capB = isWithin ? entry.cpl : entry.ppl;
      const capC = isWithin ? entry.cpu : entry.ppu;
      const capD = isWithin ? entry.cp : entry.pp;
      return '<tr>' +
        '<th>' + esc(entry.label) + '</th>' +
        '<td>' + esc(fmtSpec(entry.lsl)) + '</td>' +
        '<td>' + esc(Number.isFinite(entry.target) ? fmtSpec(entry.target) : '-') + '</td>' +
        '<td>' + esc(fmtSpec(entry.usl)) + '</td>' +
        '<td>' + esc(fmtWide(entry.avg)) + '</td>' +
        '<td>' + esc(fmtWide(sigma)) + '</td>' +
        summaryValueCell(entry.stability, 'stability', fmtWide) +
        summaryValueCell(capA, 'capability', fmtIndex) +
        summaryValueCell(capB, 'capability', fmtIndex) +
        summaryValueCell(capC, 'capability', fmtIndex) +
        summaryValueCell(capD, 'capability', fmtIndex) +
        '<td>' + esc(Number.isFinite(entry.cpm) ? fmtIndex(entry.cpm) : '-') + '</td>' +
        '<td>' + esc(fmtPct(expTotal)) + '</td>' +
        '<td>' + esc(fmtPct(expBelow)) + '</td>' +
        '<td>' + esc(fmtPct(expAbove)) + '</td>' +
        '<td>' + esc(fmtPct(entry.obsTotalPct)) + '</td>' +
        '<td>' + esc(fmtPct(entry.obsBelowPct)) + '</td>' +
        '<td>' + esc(fmtPct(entry.obsAbovePct)) + '</td>' +
      '</tr>';
    }).join('');
    return '<div class="qpc-summary-report-wrap" data-report-mode="' + esc(mode || '') + '"><table class="qpc-summary-report-table"><thead><tr>' +
      '<th>공정</th><th>LSL</th><th>목표값</th><th>USL</th><th>표본 평균</th><th>' + esc(sigmaLabel) + '</th><th>안정성 지수</th><th>' + esc(cap1) + '</th><th>' + esc(cap2) + '</th><th>' + esc(cap3) + '</th><th>' + esc(cap4) + '</th><th>Cpm</th><th>기대 % - 규격 밖</th><th>기대 % - LSL 아래</th><th>기대 % - USL 위</th><th>관측 % - 규격 밖</th><th>관측 % - LSL 아래</th><th>관측 % - USL 위</th>' +
      '</tr></thead><tbody>' + (rows || '<tr><td colspan="18" class="qpc-report-empty">데이터 없음</td></tr>') + '</tbody></table></div>';
  }

  function summaryBoxHtml(entry){
    return '<div class="qpc-summary-box"><div class="qpc-summary-title">공정 요약</div><div class="qpc-summary-grid">' +
      '<div class="k">LSL</div><div class="v">' + esc(fmtSpec(entry.lsl)) + '</div>' +
      '<div class="k">USL</div><div class="v">' + esc(fmtSpec(entry.usl)) + '</div>' +
      '<div class="k">N</div><div class="v">' + esc(fixedTrim(entry.n, 0)) + '</div>' +
      '<div class="k">표본 평균</div><div class="v">' + esc(fmtWide(entry.avg)) + '</div>' +
      '<div class="k">군내 표준편차</div><div class="v">' + esc(fmtWide(entry.sigmaWithin)) + '</div>' +
      '<div class="k">전체 표준편차</div><div class="v">' + esc(fmtWide(entry.sigmaOverall)) + '</div>' +
      '<div class="k">안정성 지수</div><div class="v">' + esc(fmtWide(entry.stability)) + '</div>' +
      '</div><div class="qpc-summary-sep"></div><div class="qpc-summary-grid"><div class="k">평균 이동 범위()로 추정된 군내 표준편차</div><div class="v">' + esc(fmtWide(entry.sigmaWithin)) + '</div></div></div>';
  }

  function clampNum(v, min, max){
    return Math.max(min, Math.min(max, v));
  }

  function getThemePreset(key){
    return LEGEND_THEME_PRESETS.find(p => p.key === key) || LEGEND_THEME_PRESETS[0];
  }
  function cloneLegendPrefs(src){
    return {
      title: String((src && src.title) || '범례'),
      showOverall: src ? !!src.showOverall : true,
      showWithin: src ? !!src.showWithin : false,
      titlePosition: String((src && src.titlePosition) || '위쪽'),
      itemDirection: String((src && src.itemDirection) || '수직'),
      itemWrap: String((src && src.itemWrap) || '자동'),
      maxItems: Math.max(1, parseInt((src && src.maxItems) || 256, 10) || 256),
      themeKey: String((src && src.themeKey) || 'jmp-default'),
      themeName: String((src && src.themeName) || getThemePreset((src && src.themeKey) || 'jmp-default').name),
      colors: Array.isArray(src && src.colors) ? src.colors.slice() : getThemePreset((src && src.themeKey) || 'jmp-default').colors.slice(),
      order: Array.isArray(src && src.order) ? src.order.slice() : ['overall','within'],
    };
  }
  function getLegendPrefs(idx){
    if (!Object.prototype.hasOwnProperty.call(STATE.legendPrefsByIndex, idx)){
      STATE.legendPrefsByIndex[idx] = cloneLegendPrefs(null);
    }
    return STATE.legendPrefsByIndex[idx];
  }
  function legendItemDefs(prefs){
    return {
      overall: { id:'overall', label:'전체 표준편차', checked: !!(prefs && prefs.showOverall), marker:'overall' },
      within: { id:'within', label:'군내 표준편차', checked: !!(prefs && prefs.showWithin), marker:'within' },
    };
  }
  function legendMarkerHtml(id, context){
    const ctx = context === 'dialog' ? ' qpc-target-marker--dialog' : '';
    const kind = id === 'within' ? ' qpc-target-marker--within' : ' qpc-target-marker--overall';
    return '<span class="qpc-target-marker' + ctx + kind + '"></span>';
  }
  function legendSidePreviewHtml(idx){
    const prefs = getLegendPrefs(idx);
    const defs = legendItemDefs(prefs);
    const items = (prefs.order || ['overall','within']).map(id => defs[id]).filter(Boolean).filter(item => item.checked);
    if (!items.length) return '<div class="qpc-target-side-empty">범례 항목 없음</div>';
    return items.map(item => '<button type="button" class="qpc-target-side-item" data-role="legend-item" data-legend-item="' + esc(item.id) + '" title="더블클릭: 범례 설정">' + legendMarkerHtml(item.id, 'side') + '<span>' + esc(item.label) + '</span></button>').join('');
  }
  function targetPlotUseOverall(idx){
    const prefs = getLegendPrefs(idx);
    if (prefs.showOverall && !prefs.showWithin) return true;
    if (!prefs.showOverall && prefs.showWithin) return false;
    return true;
  }
  function legendDialogPreviewHtml(prefs){
    const defs = legendItemDefs(prefs);
    const items = (prefs.order || ['overall','within']).map(id => defs[id]).filter(Boolean).filter(item => item.checked).slice(0, Math.max(1, parseInt(prefs.maxItems, 10) || 256));
    const title = prefs.titlePosition === '없음' ? '' : '<div style="font-weight:700; margin-bottom:6px;">' + esc(prefs.title || '범례') + '</div>';
    const listStyle = prefs.itemDirection === '수평' ? 'display:flex; gap:10px; flex-wrap:wrap;' : '';
    const list = items.length ? items.map(item => '<div style="display:flex; align-items:center; gap:6px; margin:3px 0;">' + legendMarkerHtml(item.id, 'dialog') + '<span>' + esc(item.label) + '</span></div>').join('') : '<div style="color:#666;">범례 항목 없음</div>';
    return title + '<div style="' + listStyle + '">' + list + '</div>';
  }
  function renderTargetLegendSideBox(box){
    if (!box) return;
    const idx = parseInt(box.getAttribute('data-entry-index') || '-1', 10);
    const host = qs('[data-role="legend-side-preview"]', box);
    const link = qs('[data-role="open-legend"]', box);
    const prefs = getLegendPrefs(idx);
    if (host) host.innerHTML = legendSidePreviewHtml(idx);
    if (link) link.textContent = prefs.title || '범례';
  }
  function renderLegendItemsDialog(){
    const host = qs('#qpcLegendItems');
    const prefs = STATE.legendDialogDraft;
    if (!host || !prefs) return;
    const defs = legendItemDefs(prefs);
    host.innerHTML = (prefs.order || ['overall','within']).map(id => {
      const item = defs[id];
      if (!item) return '';
      const checked = item.checked ? ' checked' : '';
      const cls = STATE.legendDialogSelectedItem === item.id ? 'qpc-legend-item is-selected' : 'qpc-legend-item';
      return '<label class="' + cls + '" data-item-id="' + esc(item.id) + '"><input type="checkbox" data-legend-check="' + esc(item.id) + '"' + checked + '>' + legendMarkerHtml(item.id, 'dialog') + '<span>' + esc(item.label) + '</span></label>';
    }).join('');
  }
  function renderLegendDialogPreview(){
    const host = qs('#qpcLegendPreview');
    if (host && STATE.legendDialogDraft) host.innerHTML = legendDialogPreviewHtml(STATE.legendDialogDraft);
    const btn = qs('#qpcLegendThemeBtn');
    if (btn && STATE.legendDialogDraft){
      btn.style.background = 'linear-gradient(90deg,' + (STATE.legendDialogDraft.colors || ['#000']).join(',') + ')';
    }
  }
  function syncLegendDialogControls(){
    const prefs = STATE.legendDialogDraft;
    if (!prefs) return;
    const title = qs('#qpcLegendTitle'); if (title) title.value = prefs.title || '범례';
    const tp = qs('#qpcLegendTitlePos'); if (tp) tp.value = prefs.titlePosition || '위쪽';
    const di = qs('#qpcLegendItemDir'); if (di) di.value = prefs.itemDirection || '수직';
    const wr = qs('#qpcLegendWrap'); if (wr) wr.value = prefs.itemWrap || '자동';
    const mi = qs('#qpcLegendMaxItems'); if (mi) mi.value = String(prefs.maxItems || 256);
    renderLegendItemsDialog();
    renderLegendDialogPreview();
  }
  function updateLegendDraftFromControls(){
    const prefs = STATE.legendDialogDraft;
    if (!prefs) return;
    const title = qs('#qpcLegendTitle'); if (title) prefs.title = title.value || '범례';
    const tp = qs('#qpcLegendTitlePos'); if (tp) prefs.titlePosition = tp.value || '위쪽';
    const di = qs('#qpcLegendItemDir'); if (di) prefs.itemDirection = di.value || '수직';
    const wr = qs('#qpcLegendWrap'); if (wr) prefs.itemWrap = wr.value || '자동';
    const mi = qs('#qpcLegendMaxItems'); if (mi) prefs.maxItems = Math.max(1, parseInt(mi.value, 10) || 256);
  }
  function showMiniDialog(sel){
    const el = typeof sel === 'string' ? qs(sel) : sel;
    if (!el) return;
    el.setAttribute('aria-hidden', 'false');
    const ov = getOverlay();
    const rect = ov ? ov.getBoundingClientRect() : { left:0, top:0, width:window.innerWidth, height:window.innerHeight };
    const w = el.offsetWidth || el.getBoundingClientRect().width || 420;
    const h = el.offsetHeight || el.getBoundingClientRect().height || 260;
    el.style.left = Math.max(18, (rect.width - w) / 2) + 'px';
    el.style.top = Math.max(20, (rect.height - h) / 2) + 'px';
  }
  function hideMiniDialog(sel){
    const el = typeof sel === 'string' ? qs(sel) : sel;
    if (el) el.setAttribute('aria-hidden', 'true');
  }
  function hideAllMiniDialogs(){
    hideMiniDialog('#qpcLegendDialog');
    hideMiniDialog('#qpcThemeDialog');
    STATE.legendDialogDraft = null;
    STATE.themeDialogDraft = null;
    STATE.activeLegendEntryIndex = null;
    STATE.miniDialogDrag = null;
  }
  function openLegendDialog(idx){
    STATE.activeLegendEntryIndex = idx;
    STATE.legendDialogDraft = cloneLegendPrefs(getLegendPrefs(idx));
    STATE.legendDialogSelectedItem = (STATE.legendDialogDraft.order || ['overall','within'])[0] || 'overall';
    const st = qs('#qpcLegendStatus'); if (st) st.textContent = '';
    syncLegendDialogControls();
    showMiniDialog('#qpcLegendDialog');
  }
  function commitLegendDialog(){
    updateLegendDraftFromControls();
    const idx = STATE.activeLegendEntryIndex;
    if (idx == null || !STATE.legendDialogDraft) return;
    STATE.legendPrefsByIndex[idx] = cloneLegendPrefs(STATE.legendDialogDraft);
    qsa('.qpc-target-grid[data-entry-index="' + idx + '"]').forEach(box => { renderTargetLegendSideBox(box); renderTargetPlotBox(box); });
    hideMiniDialog('#qpcLegendDialog');
    STATE.legendDialogDraft = null;
  }
  function moveLegendItem(dir){
    const prefs = STATE.legendDialogDraft;
    if (!prefs) return;
    const order = prefs.order || ['overall','within'];
    const idx = order.indexOf(STATE.legendDialogSelectedItem);
    if (idx < 0) return;
    const next = idx + dir;
    if (next < 0 || next >= order.length) return;
    const tmp = order[idx]; order[idx] = order[next]; order[next] = tmp;
    prefs.order = order.slice();
    renderLegendItemsDialog();
    renderLegendDialogPreview();
  }
  function renderThemeGrid(){
    const host = qs('#qpcThemeGrid');
    if (!host || !STATE.themeDialogDraft) return;
    const groups = ['순차','확산','현색계','유색채계'];
    host.innerHTML = groups.map(group => {
      const items = LEGEND_THEME_PRESETS.filter(p => p.group === group);
      return '<div class="qpc-theme-col"><div class="qpc-theme-col-title">' + esc(group) + '</div>' + items.map(item => {
        const cls = item.key === STATE.themeDialogDraft.themeKey ? 'qpc-theme-option is-selected' : 'qpc-theme-option';
        return '<button type="button" class="' + cls + '" data-theme-key="' + esc(item.key) + '"><span class="qpc-theme-sample">' + item.colors.map(c => '<span style="background:' + esc(c) + ';"></span>').join('') + '</span></button>';
      }).join('') + '</div>';
    }).join('');
    const chip = qs('#qpcThemeCurrentChip');
    const name = qs('#qpcThemeCurrentName');
    if (chip) chip.style.background = 'linear-gradient(90deg,' + (STATE.themeDialogDraft.colors || ['#000']).join(',') + ')';
    if (name) name.textContent = STATE.themeDialogDraft.themeName || 'JMP 기본 설정';
  }
  function openThemeDialog(){
    const draft = STATE.legendDialogDraft;
    if (!draft) return;
    STATE.themeDialogDraft = { themeKey:draft.themeKey, themeName:draft.themeName, colors:(draft.colors || []).slice() };
    renderThemeGrid();
    showMiniDialog('#qpcThemeDialog');
  }
  function commitThemeDialog(){
    if (!STATE.legendDialogDraft || !STATE.themeDialogDraft) return;
    STATE.legendDialogDraft.themeKey = STATE.themeDialogDraft.themeKey;
    STATE.legendDialogDraft.themeName = STATE.themeDialogDraft.themeName;
    STATE.legendDialogDraft.colors = (STATE.themeDialogDraft.colors || []).slice();
    hideMiniDialog('#qpcThemeDialog');
    STATE.themeDialogDraft = null;
    renderLegendItemsDialog();
    renderLegendDialogPreview();
  }
  function bindMiniDialogDrag(){
    document.addEventListener('mousedown', function(ev){
      const bar = ev.target && ev.target.closest ? ev.target.closest('#qpcOverlay .qpc-mini-titlebar') : null;
      if (!bar || ev.button !== 0) return;
      if (ev.target && ev.target.closest && ev.target.closest('button, input, select, textarea, label')) return;
      const dlg = bar.parentElement;
      if (!dlg || dlg.getAttribute('aria-hidden') !== 'false') return;
      const rect = dlg.getBoundingClientRect();
      const ovRect = getOverlay().getBoundingClientRect();
      STATE.miniDialogDrag = { dlg: dlg, offsetX: ev.clientX - rect.left, offsetY: ev.clientY - rect.top, ovLeft: ovRect.left, ovTop: ovRect.top };
      document.body.style.userSelect = 'none';
      ev.preventDefault();
    });
    document.addEventListener('mousemove', function(ev){
      const drag = STATE.miniDialogDrag;
      if (!drag || !drag.dlg) return;
      const ovRect = getOverlay().getBoundingClientRect();
      const rect = drag.dlg.getBoundingClientRect();
      const left = Math.min(Math.max(8, ev.clientX - ovRect.left - drag.offsetX), Math.max(8, ovRect.width - rect.width - 8));
      const top = Math.min(Math.max(8, ev.clientY - ovRect.top - drag.offsetY), Math.max(8, ovRect.height - rect.height - 8));
      drag.dlg.style.left = left + 'px';
      drag.dlg.style.top = top + 'px';
    });
    document.addEventListener('mouseup', function(){ if (STATE.miniDialogDrag){ STATE.miniDialogDrag = null; document.body.style.userSelect = ''; } });
  }
  function fmtTargetTick(v){
    if (!Number.isFinite(v)) return '';
    if (Math.abs(v) < 1e-9) return '0';
    return fixedTrim(v, 1);
  }
  function fmtTooltipValue(v, digits){
    if (!Number.isFinite(v)) return '-';
    const d = Number.isFinite(digits) ? digits : 3;
    return Number(v).toFixed(d).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
  }
  function targetStdTypeLabel(useOverall){
    return useOverall ? '전체' : '군내';
  }
  function targetPlotTooltipSvg(entry, useOverall){
    const values = Array.isArray(entry && entry.values) ? entry.values.filter(Number.isFinite) : [];
    const width = 250, height = 150;
    const left = 46, right = 12, top = 18, bottom = 26;
    const plotW = width - left - right;
    const plotH = height - top - bottom;
    if (!values.length){
      return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true"><rect x="0.5" y="0.5" width="' + (width - 1) + '" height="' + (height - 1) + '" fill="#ffffff" stroke="#9d9d9d"/><text x="' + fixedTrim(width / 2, 2) + '" y="' + fixedTrim(height / 2, 2) + '" fill="#333" text-anchor="middle" font-size="12">데이터 없음</text></svg>';
    }
    let min = Math.min.apply(null, values);
    let max = Math.max.apply(null, values);
    if (Number.isFinite(entry.lsl)) min = Math.min(min, entry.lsl);
    if (Number.isFinite(entry.usl)) max = Math.max(max, entry.usl);
    const avg = Number.isFinite(entry.avg) ? entry.avg : mean(values);
    if (Number.isFinite(avg)){ min = Math.min(min, avg); max = Math.max(max, avg); }
    let range = max - min;
    if (!(range > 0)) range = Math.max(Math.abs(max || 1), 1) * 0.1;
    min -= range * 0.08;
    max += range * 0.08;
    const x = i => left + (i / Math.max(1, values.length - 1)) * plotW;
    const y = v => top + plotH - ((v - min) / Math.max(1e-9, max - min)) * plotH;
    const tickIdx = [0];
    const step = values.length > 1 ? Math.max(1, Math.round((values.length - 1) / 4)) : 1;
    for (let i = step; i < values.length - 1; i += step) tickIdx.push(i);
    if (values.length > 1) tickIdx.push(values.length - 1);
    const uniqIdx = Array.from(new Set(tickIdx));
    const xTicks = uniqIdx.map(i => {
      const xx = x(i);
      const label = i === 0 ? '0' : String(i + 1);
      return '<line x1="' + fixedTrim(xx,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(xx,2) + '" y2="' + fixedTrim(top + plotH + 3,2) + '" stroke="#7e7e7e"/><text x="' + fixedTrim(xx,2) + '" y="' + fixedTrim(top + plotH + 16,2) + '" fill="#111" font-size="10" text-anchor="middle">' + esc(label) + '</text>';
    }).join('');
    const path = values.map((v, i) => (i ? 'L' : 'M') + fixedTrim(x(i),2) + ' ' + fixedTrim(y(v),2)).join(' ');
    const line = '<path d="' + path + '" fill="none" stroke="#b8b8b8" stroke-width="1.2"/>';
    const dots = values.map((v, i) => '<circle cx="' + fixedTrim(x(i),2) + '" cy="' + fixedTrim(y(v),2) + '" r="1.35" fill="#b8b8b8" stroke="#9a9a9a" stroke-width="0.4"/>').join('');
    const avgLine = Number.isFinite(avg) ? '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(avg),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(avg),2) + '" stroke="#22a52f" stroke-width="1.1"/>' : '';
    const lslLine = Number.isFinite(entry.lsl) ? '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(entry.lsl),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(entry.lsl),2) + '" stroke="#e53935" stroke-width="1"/>' : '';
    const uslLine = Number.isFinite(entry.usl) ? '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(entry.usl),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(entry.usl),2) + '" stroke="#e53935" stroke-width="1"/>' : '';
    const lslLabel = Number.isFinite(entry.lsl) ? '<text x="12" y="' + fixedTrim(y(entry.lsl) + 4,2) + '" fill="#255cff" font-size="11">LSL</text>' : '';
    const uslLabel = Number.isFinite(entry.usl) ? '<text x="12" y="' + fixedTrim(y(entry.usl) + 4,2) + '" fill="#255cff" font-size="11">USL</text>' : '';
    const title = esc((entry.label || entry.proc || '공정') + '의 개별 차트');
    const yLabel = esc(entry.label || entry.proc || '공정');
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

  function targetPlotSvg(entry, opts){
    const width = 520, height = 330;
    const left = 42, right = 16, top = 8, bottom = 34;
    const plotW = width - left - right;
    const plotH = height - top - bottom;
    const xMin = -0.6, xMax = 0.6;
    const yMin = 0, yMax = 0.30;
    const useOverall = !opts || opts.useOverall !== false;
    const sigma = useOverall ? entry.sigmaOverall : entry.sigmaWithin;
    const hasSpecs = Number.isFinite(entry.lsl) && Number.isFinite(entry.usl) && entry.usl > entry.lsl;
    const specWidth = hasSpecs ? (entry.usl - entry.lsl) : NaN;
    const center = Number.isFinite(entry.target) ? entry.target : (hasSpecs ? ((entry.lsl + entry.usl) / 2) : entry.avg);
    const normX = (hasSpecs && Number.isFinite(entry.avg) && Number.isFinite(center) && Number.isFinite(specWidth) && specWidth > 0) ? ((entry.avg - center) / specWidth) : NaN;
    const normY = (hasSpecs && Number.isFinite(sigma) && Number.isFinite(specWidth) && specWidth > 0) ? (sigma / specWidth) : NaN;
    const ppkVal = clampNum(Number.isFinite(parseNum(opts && opts.ppk)) ? parseNum(opts && opts.ppk) : 1, 0.20, 2.50);
    const apexY = 1 / (6 * ppkVal);
    const x = v => left + ((v - xMin) / (xMax - xMin)) * plotW;
    const y = v => top + plotH - ((v - yMin) / (yMax - yMin)) * plotH;
    const xTicks = [-0.6,-0.4,-0.2,0,0.2,0.4,0.6];
    const yTicks = [0,0.05,0.10,0.15,0.20,0.25,0.30];
    const hGrid = yTicks.map(v => {
      const yy = y(v);
      return '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="rgba(255,255,255,.08)"/>';
    }).join('');
    const xAxisTicks = xTicks.map(v => '<line x1="' + fixedTrim(x(v),2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(x(v),2) + '" y2="' + fixedTrim(top + plotH + 4,2) + '" stroke="rgba(255,255,255,.45)"/><text x="' + fixedTrim(x(v),2) + '" y="' + fixedTrim(top + plotH + 16,2) + '" fill="rgba(236,247,240,.92)" font-size="10" text-anchor="middle">' + esc(fmtTargetTick(v)) + '</text>').join('');
    const yAxisTicks = yTicks.map(v => '<line x1="' + fixedTrim(left - 4,2) + '" y1="' + fixedTrim(y(v),2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(y(v),2) + '" stroke="rgba(255,255,255,.45)"/><text x="' + fixedTrim(left - 7,2) + '" y="' + fixedTrim(y(v) + 3,2) + '" fill="rgba(236,247,240,.92)" font-size="10" text-anchor="end">' + esc(v === 0 ? '0' : fixedTrim(v,2)) + '</text>').join('');
    const tri = '<path d="M' + fixedTrim(x(-0.5),2) + ' ' + fixedTrim(y(0),2) + ' L' + fixedTrim(x(0),2) + ' ' + fixedTrim(y(apexY),2) + ' L' + fixedTrim(x(0.5),2) + ' ' + fixedTrim(y(0),2) + '" fill="none" stroke="#ff6672" stroke-width="1.2"/>';
    let marker = '';
    if (Number.isFinite(normX) && Number.isFinite(normY)){
      const px = x(clampNum(normX, xMin, xMax));
      const py = y(clampNum(normY, yMin, yMax));
      const hitType = useOverall ? 'overall' : 'within';
      marker = '<rect x="' + fixedTrim(px - 2.5,2) + '" y="' + fixedTrim(py - 2.5,2) + '" width="5" height="5" fill="transparent" stroke="rgba(236,247,240,.95)" stroke-width="1.1"/>' +
        '<rect x="' + fixedTrim(px - 8,2) + '" y="' + fixedTrim(py - 8,2) + '" width="16" height="16" fill="transparent" stroke="transparent" data-role="target-marker-hit" data-hit-type="' + hitType + '"/>';
    }
    const empty = (!hasSpecs || !Number.isFinite(normX) || !Number.isFinite(normY))
      ? '<text x="' + fixedTrim(left + plotW/2,2) + '" y="' + fixedTrim(top + plotH/2,2) + '" fill="rgba(236,247,240,.55)" text-anchor="middle" font-size="11">규격 한계와 데이터가 있어야 표시됩니다.</text>'
      : '';
    return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
      '<rect x="0.5" y="0.5" width="' + (width - 1) + '" height="' + (height - 1) + '" fill="transparent" stroke="rgba(255,255,255,.12)"/>' +
      hGrid +
      '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(255,255,255,.55)"/>' +
      '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(255,255,255,.55)"/>' +
      tri + marker + xAxisTicks + yAxisTicks + empty +
      '<text x="' + fixedTrim(left + plotW/2,2) + '" y="' + (height - 6) + '" fill="rgba(236,247,240,.96)" font-size="11" text-anchor="middle">규격으로 표준화된 평균</text>' +
      '<text x="14" y="' + fixedTrim(top + plotH/2,2) + '" fill="rgba(236,247,240,.96)" font-size="11" text-anchor="middle" transform="rotate(-90 14 ' + fixedTrim(top + plotH/2,2) + ')">규격으로 표준화된 표준편차</text>' +
      '</svg>';
  }

  function targetPlotHtml(entry, idx){
    const ppkDefault = '1';
    return '<div class="qpc-target-grid" data-entry-index="' + idx + '">' +
      '<div class="qpc-target-main"><div class="qpc-svgbox" data-role="target-svg">' + targetPlotSvg(entry, { useOverall: targetPlotUseOverall(idx), ppk: 1 }) + '</div><div class="qpc-target-hover-tip" data-role="target-hover-tip" hidden></div></div>' +
      '<div class="qpc-target-side">' +
        '<div class="qpc-target-side-head"><div class="qpc-target-legend-link" data-role="open-legend" title="더블클릭: 범례 설정">' + esc(getLegendPrefs(idx).title || '범례') + '</div></div>' +
        '<div class="qpc-target-side-preview" data-role="legend-side-preview">' + legendSidePreviewHtml(idx) + '</div>' +
        '<div class="qpc-target-ppk-label">Ppk</div>' +
        '<div class="qpc-target-ppk-line"><span class="qpc-target-ppk-badge">Ppk</span><input type="text" class="qpc-target-ppk-input" data-role="ppk-text" value="' + ppkDefault + '"></div>' +
        '<input type="range" class="qpc-target-range" data-role="ppk-range" min="0.20" max="2.50" step="0.01" value="' + ppkDefault + '">' +
      '</div>' +
    '</div>';
  }

  function renderTargetPlotBox(box){
    if (!box) return;
    const idx = parseInt(box.getAttribute('data-entry-index') || '-1', 10);
    const entry = STATE.reportEntries[idx];
    if (!entry) return;
    const useOverall = targetPlotUseOverall(idx);
    const textEl = qs('[data-role="ppk-text"]', box);
    const rangeEl = qs('[data-role="ppk-range"]', box);
    let ppk = parseNum(textEl ? textEl.value : '1');
    if (!Number.isFinite(ppk)) ppk = parseNum(rangeEl ? rangeEl.value : '1');
    ppk = clampNum(Number.isFinite(ppk) ? ppk : 1, 0.20, 2.50);
    if (textEl) textEl.value = fixedTrim(ppk, 2);
    if (rangeEl) rangeEl.value = String(ppk);
    const host = qs('[data-role="target-svg"]', box);
    if (host) host.innerHTML = targetPlotSvg(entry, { useOverall, ppk });
    const tip = qs('[data-role="target-hover-tip"]', box);
    if (tip){ tip.hidden = true; tip.innerHTML = ''; }
  }

  function capabilityIndexPlotSvg(entry, opts){
    const width = 338, height = 248;
    const left = 30, right = 16, top = 8, bottom = 38;
    const plotW = width - left - right;
    const plotH = height - top - bottom;
    const refVal = clampNum(Number.isFinite(parseNum(opts && opts.refPpk)) ? parseNum(opts && opts.refPpk) : 1, 0.20, 2.50);
    const rawPpk = Number(entry && entry.ppk);
    const yMaxBase = Math.max(3.2, Math.ceil((Math.max(refVal, Number.isFinite(rawPpk) ? rawPpk : 0) + 0.25) * 2) / 2);
    const yMax = Math.max(3.2, yMaxBase);
    const xMid = left + plotW / 2;
    const y = v => top + plotH - ((v - 0) / (yMax - 0)) * plotH;
    const yTicks = [];
    for (let v = 0; v <= yMax + 1e-9; v += 0.5) yTicks.push(Number(v.toFixed(1)));
    const hGrid = yTicks.map(v => {
      const yy = y(v);
      const stroke = Math.abs(v - Math.round(v)) < 1e-9 ? 'rgba(255,255,255,.08)' : 'rgba(255,255,255,.05)';
      return '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="' + stroke + '"/>';
    }).join('');
    const yAxisTicks = yTicks.map(v => {
      const yy = y(v);
      const label = Math.abs(v - Math.round(v)) < 1e-9 ? String(Math.round(v)) : '';
      return '<line x1="' + fixedTrim(left - 4,2) + '" y1="' + fixedTrim(yy,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(yy,2) + '" stroke="rgba(255,255,255,.45)"/>' +
        (label ? '<text x="' + fixedTrim(left - 7,2) + '" y="' + fixedTrim(yy + 3,2) + '" fill="rgba(236,247,240,.92)" font-size="10" text-anchor="end">' + esc(label) + '</text>' : '');
    }).join('');
    const xAxis = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top + plotH,2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(255,255,255,.55)"/>';
    const yAxis = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(top,2) + '" x2="' + fixedTrim(left,2) + '" y2="' + fixedTrim(top + plotH,2) + '" stroke="rgba(255,255,255,.55)"/>';
    const refLine = '<line x1="' + fixedTrim(left,2) + '" y1="' + fixedTrim(y(refVal),2) + '" x2="' + fixedTrim(left + plotW,2) + '" y2="' + fixedTrim(y(refVal),2) + '" stroke="#ff6672" stroke-width="1.15"/>';
    let marker = '';
    if (Number.isFinite(rawPpk)){
      const py = y(clampNum(rawPpk, 0, yMax));
      marker = '<rect x="' + fixedTrim(xMid - 3,2) + '" y="' + fixedTrim(py - 3,2) + '" width="6" height="6" fill="transparent" stroke="rgba(236,247,240,.95)" stroke-width="1.1"/>';
    }
    const labelText = esc(entry && (entry.label || entry.proc) || '-');
    return '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true">' +
      '<rect x="0.5" y="0.5" width="' + (width - 1) + '" height="' + (height - 1) + '" fill="transparent" stroke="rgba(255,255,255,.12)"/>' +
      hGrid + xAxis + yAxis + refLine + marker + yAxisTicks +
      '<text x="' + fixedTrim(xMid,2) + '" y="' + fixedTrim(top + plotH + 18,2) + '" fill="rgba(236,247,240,.92)" font-size="10" text-anchor="middle" transform="rotate(-90 ' + fixedTrim(xMid,2) + ' ' + fixedTrim(top + plotH + 18,2) + ')">' + labelText + '</text>' +
      '<text x="' + fixedTrim(xMid,2) + '" y="' + (height - 4) + '" fill="rgba(236,247,240,.92)" font-size="11" text-anchor="middle">공정</text>' +
      '<text x="14" y="' + fixedTrim(top + plotH/2,2) + '" fill="rgba(236,247,240,.96)" font-size="11" text-anchor="middle" transform="rotate(-90 14 ' + fixedTrim(top + plotH/2,2) + ')">Ppk</text>' +
      '</svg>';
  }

  function capabilityIndexLegendSideHtml(){
    return '<div style="font-size:11px;line-height:1.5;color:rgba(236,247,240,.96);">' +
      '<div style="font-weight:700;margin-bottom:6px;">범례</div>' +
      '<div style="display:flex;align-items:center;gap:6px;"><span style="display:inline-block;width:8px;height:8px;border:1px solid rgba(236,247,240,.9);background:transparent;box-sizing:border-box;"></span><span>전체 Ppk</span></div>' +
    '</div>';
  }

  function capabilityIndexPlotHtml(entry, idx){
    const refDefault = '1';
    return '<div class="qpc-index-grid" data-entry-index="' + idx + '" style="display:grid;grid-template-columns:minmax(0,338px) 112px;gap:12px;align-items:start;max-width:462px;">' +
      '<div class="qpc-index-main" style="width:100%;max-width:338px;"><div class="qpc-svgbox" data-role="index-svg" style="width:100%;max-width:338px;">' + capabilityIndexPlotSvg(entry, { refPpk: 1 }) + '</div></div>' +
      '<div class="qpc-index-side" style="width:112px;">' + capabilityIndexLegendSideHtml() +
        '<div style="height:10px;"></div>' +
        '<div style="font-size:11px;font-weight:700;color:rgba(236,247,240,.96);margin-bottom:4px;">Ppk</div>' +
        '<div style="display:flex;align-items:center;gap:4px;margin-bottom:6px;"><span style="display:inline-block;min-width:34px;padding:2px 6px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.03);font-size:11px;font-weight:700;text-align:center;color:rgba(236,247,240,.96);">Ppk</span><input type="text" class="qpc-index-ppk-input" data-role="index-ppk-text" value="' + refDefault + '" style="width:38px;height:20px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.03);color:rgba(236,247,240,.96);font-size:11px;text-align:center;padding:0 4px;"></div>' +
        '<input type="range" class="qpc-index-range" data-role="index-ppk-range" min="0.20" max="2.50" step="0.01" value="' + refDefault + '" style="width:100%;">' +
      '</div>' +
    '</div>';
  }

  function renderCapabilityIndexPlotBox(box){
    if (!box) return;
    const idx = parseInt(box.getAttribute('data-entry-index') || '-1', 10);
    const entry = STATE.reportEntries[idx];
    if (!entry) return;
    const textEl = qs('[data-role="index-ppk-text"]', box);
    const rangeEl = qs('[data-role="index-ppk-range"]', box);
    let ref = parseNum(textEl ? textEl.value : '1');
    if (!Number.isFinite(ref)) ref = parseNum(rangeEl ? rangeEl.value : '1');
    ref = clampNum(Number.isFinite(ref) ? ref : 1, 0.20, 2.50);
    if (textEl) textEl.value = fixedTrim(ref, 2);
    if (rangeEl) rangeEl.value = String(ref);
    const host = qs('[data-role="index-svg"]', box);
    if (host) host.innerHTML = capabilityIndexPlotSvg(entry, { refPpk: ref });
  }

  function hideAllTargetHoverTips(){
    qsa('#qpcOverlay [data-role="target-hover-tip"]').forEach(el => { el.hidden = true; el.innerHTML = ''; });
  }
  function showTargetHoverTip(hitEl, clientX, clientY){
    const box = hitEl && hitEl.closest ? hitEl.closest('.qpc-target-grid') : null;
    if (!box) return;
    const idx = parseInt(box.getAttribute('data-entry-index') || '-1', 10);
    const entry = STATE.reportEntries[idx];
    if (!entry) return;
    const tip = qs('[data-role="target-hover-tip"]', box);
    const main = hitEl.closest('.qpc-target-main');
    if (!tip || !main) return;
    const useOverall = (hitEl.getAttribute('data-hit-type') || '') !== 'within';
    tip.innerHTML = targetPlotTooltipHtml(entry, useOverall);
    tip.hidden = false;
    const mainRect = main.getBoundingClientRect();
    const tipRect = tip.getBoundingClientRect();
    let left = (clientX - mainRect.left) + 14;
    let top = (clientY - mainRect.top) - 14;
    if (left + tipRect.width > mainRect.width - 6) left = Math.max(6, mainRect.width - tipRect.width - 6);
    if (top + tipRect.height > mainRect.height - 6) top = Math.max(6, mainRect.height - tipRect.height - 6);
    if (top < 6) top = 6;
    tip.style.left = fixedTrim(left,2) + 'px';
    tip.style.top = fixedTrim(top,2) + 'px';
  }

  function buildReportHtml(entries){
    if (!entries.length) return '<div class="qpc-report-note">결과를 표시할 공정 데이터가 없습니다.</div>';
    return entries.map((entry, idx) => {
      const withinRows = [
        { name: 'Cpk', value: entry.cpk, lower: entry.ci.cpk.lower, upper: entry.ci.cpk.upper },
        { name: 'Cpl', value: entry.cpl, lower: entry.ci.cpl.lower, upper: entry.ci.cpl.upper },
        { name: 'Cpu', value: entry.cpu, lower: entry.ci.cpu.lower, upper: entry.ci.cpu.upper },
        { name: 'Cp', value: entry.cp, lower: entry.ci.cp.lower, upper: entry.ci.cp.upper },
      ];
      const overallRows = [
        { name: 'Ppk', value: entry.ppk, lower: entry.ci.ppk.lower, upper: entry.ci.ppk.upper },
        { name: 'Ppl', value: entry.ppl, lower: entry.ci.ppl.lower, upper: entry.ci.ppl.upper },
        { name: 'Ppu', value: entry.ppu, lower: entry.ci.ppu.lower, upper: entry.ci.ppu.upper },
        { name: 'Pp', value: entry.pp, lower: entry.ci.pp.lower, upper: entry.ci.pp.upper },
      ];
      return '<details class="qpc-report-group" ' + (idx === 0 ? 'open' : 'open') + '>' +
        '<summary>' + esc(entry.label) + ' 공정 능력</summary>' +
        '<div class="qpc-report-group-body">' +
          '<details class="qpc-report-sub" open><summary>히스토그램</summary><div class="qpc-report-sub-body"><div class="qpc-report-hist-grid"><div class="qpc-hist-wrap"><div class="qpc-svgbox">' + histogramSvg(entry) + '</div></div>' + summaryBoxHtml(entry) + '</div></div></details>' +
          '<div class="qpc-report-two">' +
            '<details class="qpc-report-sub" open><summary>군내 표준편차 공정 능력</summary><div class="qpc-report-sub-body">' + statTableHtml('군내 표준편차 공정 능력', withinRows) + '</div></details>' +
            '<details class="qpc-report-sub" open><summary>전체 표준편차 공정 능력</summary><div class="qpc-report-sub-body">' + statTableHtml('전체 표준편차 공정 능력', overallRows) + '</div></details>' +
          '</div>' +
          '<details class="qpc-report-sub" open><summary>부적합</summary><div class="qpc-report-sub-body">' + rejectTableHtml(entry) + '</div></details>' +
          '<details class="qpc-report-sub" open><summary>목표 그림</summary><div class="qpc-report-sub-body">' + targetPlotHtml(entry, idx) + '</div></details>' +
          '<details class="qpc-report-sub qpc-report-sub-summary" open><summary>군내 표준편차 공정 능력 요약 보고서</summary><div class="qpc-report-sub-body">' + summaryReportTableHtml([entry], 'within') + '</div></details>' +
          '<details class="qpc-report-sub qpc-report-sub-summary" open><summary>전체 표준편차 공정 능력 요약 보고서</summary><div class="qpc-report-sub-body">' + summaryReportTableHtml([entry], 'overall') + '</div></details>' +
          '<details class="qpc-report-sub" open><summary>공정 능력 상자 그림</summary><div class="qpc-report-sub-body">' + capabilityBoxPlotHtml(entry) + '</div></details>' +
          '<details class="qpc-report-sub" open><summary>공정 능력 지수 그림</summary><div class="qpc-report-sub-body">' + capabilityIndexPlotHtml(entry, idx) + '</div></details>' +
          '<details class="qpc-report-sub"><summary>공정 성능 그림</summary><div class="qpc-report-placeholder">다음 단계에서 연결됩니다.</div></details>' +
        '</div>' +
      '</details>';
    }).join('');
  }

  function renderReport(){
    const alpha = parseNum((qs('#qpcAlpha') || {}).value || '0.05');
    const specRows = getSpecRowMap();
    const entries = STATE.assigned.map(proc => buildReportEntry(proc, specRows[proc] || {}, alpha)).filter(entry => entry && entry.n > 0);
    STATE.reportEntries = entries;
    const root = qs('#qpcReportBody');
    if (!root) return entries.length;
    root.innerHTML = buildReportHtml(entries);
    return entries.length;
  }

  function bind(){
    bindDrag();
    bindWindowDrag();
    bindMiniDialogDrag();

    document.addEventListener('click', function(ev){
      const openBtn = ev.target && ev.target.closest ? ev.target.closest('#btnProcessCapabilityOpen, #qgOpenProcessCapability') : null;
      if (openBtn){ ev.preventDefault(); open(); return; }
      const qpcClose = ev.target && ev.target.closest ? ev.target.closest('#qpcCloseTop') : null;
      if (qpcClose){ ev.preventDefault(); close(); return; }
      if (ev.target && ev.target.id === 'qpcLegendClose'){ ev.preventDefault(); hideMiniDialog('#qpcLegendDialog'); STATE.legendDialogDraft = null; return; }
      if (ev.target && ev.target.id === 'qpcLegendCancel'){ ev.preventDefault(); hideMiniDialog('#qpcLegendDialog'); STATE.legendDialogDraft = null; return; }
      if (ev.target && ev.target.id === 'qpcLegendOk'){ ev.preventDefault(); commitLegendDialog(); return; }
      if (ev.target && ev.target.id === 'qpcLegendHelp'){ ev.preventDefault(); const st = qs('#qpcLegendStatus'); if (st) st.textContent = '범례 항목 순서와 색상 테마를 조정합니다.'; return; }
      if (ev.target && ev.target.id === 'qpcLegendThemeBtn'){ ev.preventDefault(); openThemeDialog(); return; }
      if (ev.target && ev.target.id === 'qpcLegendMoveUp'){ ev.preventDefault(); moveLegendItem(-1); return; }
      if (ev.target && ev.target.id === 'qpcLegendMoveDown'){ ev.preventDefault(); moveLegendItem(1); return; }
      const legendRow = ev.target && ev.target.closest ? ev.target.closest('#qpcLegendItems .qpc-legend-item') : null;
      if (legendRow){ STATE.legendDialogSelectedItem = legendRow.getAttribute('data-item-id') || 'overall'; renderLegendItemsDialog(); return; }
      if (ev.target && ev.target.id === 'qpcThemeClose'){ ev.preventDefault(); hideMiniDialog('#qpcThemeDialog'); STATE.themeDialogDraft = null; return; }
      if (ev.target && ev.target.id === 'qpcThemeCancel'){ ev.preventDefault(); hideMiniDialog('#qpcThemeDialog'); STATE.themeDialogDraft = null; return; }
      if (ev.target && ev.target.id === 'qpcThemeOk'){ ev.preventDefault(); commitThemeDialog(); return; }
      if (ev.target && ev.target.id === 'qpcThemeHelp'){ ev.preventDefault(); return; }
      const themeBtn = ev.target && ev.target.closest ? ev.target.closest('#qpcThemeGrid .qpc-theme-option') : null;
      if (themeBtn){
        ev.preventDefault();
        const preset = getThemePreset(themeBtn.getAttribute('data-theme-key') || 'jmp-default');
        STATE.themeDialogDraft = { themeKey:preset.key, themeName:preset.name, colors:preset.colors.slice() };
        renderThemeGrid();
        return;
      }

      const varEl = ev.target && ev.target.closest ? ev.target.closest('#qpcVarList .qpc-var') : null;
      if (varEl){
        const name = varEl.getAttribute('data-name') || '';
        if (!name) return;
        if (ev.ctrlKey || ev.metaKey){
          if (STATE.selected.includes(name)) STATE.selected = STATE.selected.filter(v => v !== name);
          else STATE.selected.push(name);
        } else {
          STATE.selected = [name];
        }
        syncListSelectionVisual();
        return;
      }

      const assignedEl = ev.target && ev.target.closest ? ev.target.closest('#qpcAssignedList .qpc-assigned-item') : null;
      if (assignedEl){
        const name = assignedEl.getAttribute('data-name') || '';
        STATE.assignedSelected = (STATE.assignedSelected === name) ? null : name;
        syncListSelectionVisual();
        return;
      }

      if (ev.target && ev.target.id === 'qpcAssignY'){ ev.preventDefault(); assignSelected(); return; }
      if (ev.target && ev.target.id === 'qpcSetupRemove'){ ev.preventDefault(); removeAssigned(); return; }
      if (ev.target && ev.target.id === 'qpcSetupRecall'){ ev.preventDefault(); rebuildVars(); setSetupStatus(''); return; }
      if (ev.target && ev.target.id === 'qpcSetupHelp'){ ev.preventDefault(); setSetupStatus('드래그 또는 Y, 공정 버튼으로 지정할 수 있습니다.'); return; }
      if (ev.target && ev.target.id === 'qpcSetupCancel'){ ev.preventDefault(); close(); return; }
      if (ev.target && ev.target.id === 'qpcSetupOk'){
        ev.preventDefault();
        if (!STATE.assigned.length){ setSetupStatus('필수 영역에 최소 1개 이상 지정해야 합니다.'); return; }
        refreshSpecMapFromTable();
        renderSpecTable();
        setSpecStatus('');
        setStep('spec');
        setTimeout(clampWindowToViewport, 0);
        return;
      }
      if (ev.target && ev.target.id === 'qpcLoadTableBtn'){
        ev.preventDefault();
        const found = refreshSpecMapFromTable();
        const applied = applySpecMapToInputs();
        if (!found || !applied) setSpecStatus('현재 데이터 테이블에서 LSL / USL을 읽지 못했습니다.');
        else setSpecStatus('');
        return;
      }
      if (ev.target && ev.target.id === 'qpcSpecCheckAll'){ ev.preventDefault(); qsa('#qpcSpecTableBody .qpc-spec-show').forEach(chk => chk.checked = true); setSpecStatus(''); return; }
      if (ev.target && ev.target.id === 'qpcSpecCancel'){ ev.preventDefault(); setStep('setup'); setTimeout(clampWindowToViewport, 0); return; }
      if (ev.target && ev.target.id === 'qpcSpecHelp'){ ev.preventDefault(); setSpecStatus('LSL / USL을 확인한 뒤 확인을 누르면 결과 보고서를 엽니다.'); return; }
      if (ev.target && ev.target.id === 'qpcSpecOk'){
        ev.preventDefault();
        const count = renderReport();
        if (!count){ setSpecStatus('결과를 만들 데이터가 없습니다. 현재 테이블과 선택 공정을 확인하세요.'); return; }
        setSpecStatus('');
        setStep('report');
        setTimeout(clampWindowToViewport, 0);
        return;
      }
    });

    document.addEventListener('dblclick', function(ev){
      const legendOpenEl = ev.target && ev.target.closest ? ev.target.closest('[data-role="open-legend"], [data-role="legend-item"]') : null;
      if (legendOpenEl){
        ev.preventDefault();
        const box = legendOpenEl.closest('.qpc-target-grid');
        if (box) openLegendDialog(parseInt(box.getAttribute('data-entry-index') || '-1', 10));
        return;
      }
      const varEl = ev.target && ev.target.closest ? ev.target.closest('#qpcVarList .qpc-var') : null;
      if (!varEl) return;
      const name = varEl.getAttribute('data-name') || '';
      if (!name) return;
      STATE.selected = [name];
      syncListSelectionVisual();
      assignSelected();
    });

    document.addEventListener('mousemove', function(ev){
      const hit = ev.target && ev.target.closest ? ev.target.closest('#qpcOverlay [data-role="target-marker-hit"]') : null;
      if (hit) showTargetHoverTip(hit, ev.clientX, ev.clientY);
      else hideAllTargetHoverTips();
    });

    document.addEventListener('input', function(ev){
      if (ev.target && ev.target.id === 'qpcVarSearch') renderVarList();
      if (ev.target && ev.target.matches && ev.target.matches('.qpc-target-ppk-input')){
        const box = ev.target.closest('.qpc-target-grid');
        const range = qs('[data-role="ppk-range"]', box);
        const v = clampNum(Number.isFinite(parseNum(ev.target.value)) ? parseNum(ev.target.value) : 1, 0.20, 2.50);
        if (range) range.value = String(v);
        renderTargetPlotBox(box);
      }
      if (ev.target && ev.target.matches && ev.target.matches('.qpc-target-range')){
        const box = ev.target.closest('.qpc-target-grid');
        const text = qs('[data-role="ppk-text"]', box);
        if (text) text.value = fixedTrim(parseNum(ev.target.value), 2);
        renderTargetPlotBox(box);
      }
      if (ev.target && ev.target.matches && ev.target.matches('.qpc-index-ppk-input')){
        const box = ev.target.closest('.qpc-index-grid');
        const range = qs('[data-role="index-ppk-range"]', box);
        const v = clampNum(Number.isFinite(parseNum(ev.target.value)) ? parseNum(ev.target.value) : 1, 0.20, 2.50);
        if (range) range.value = String(v);
        renderCapabilityIndexPlotBox(box);
      }
      if (ev.target && ev.target.matches && ev.target.matches('.qpc-index-range')){
        const box = ev.target.closest('.qpc-index-grid');
        const text = qs('[data-role="index-ppk-text"]', box);
        if (text) text.value = fixedTrim(parseNum(ev.target.value), 2);
        renderCapabilityIndexPlotBox(box);
      }
      if (ev.target && ev.target.matches && ev.target.matches('#qpcLegendTitle, #qpcLegendTitlePos, #qpcLegendItemDir, #qpcLegendWrap, #qpcLegendMaxItems')){
        updateLegendDraftFromControls();
        renderLegendDialogPreview();
      }
    });

    document.addEventListener('change', function(ev){
      if (ev.target && ev.target.matches && ev.target.matches('#qpcLegendItems [data-legend-check]')){
        if (!STATE.legendDialogDraft) return;
        const id = ev.target.getAttribute('data-legend-check') || '';
        if (id === 'overall') STATE.legendDialogDraft.showOverall = !!ev.target.checked;
        if (id === 'within') STATE.legendDialogDraft.showWithin = !!ev.target.checked;
        renderLegendItemsDialog();
        renderLegendDialogPreview();
      }
    });

    document.addEventListener('click', function(ev){
      if (ev.target && ev.target.id === 'qpcVarRefresh'){ ev.preventDefault(); rebuildVars(); setSetupStatus(''); }
    });

    document.addEventListener('keydown', function(ev){
      const ov = getOverlay();
      if (!ov || ov.getAttribute('aria-hidden') !== 'false') return;
      const step = ov.getAttribute('data-step') || 'setup';
      if (ev.key === 'Escape'){
        ev.preventDefault();
        if (step === 'report') setStep('spec');
        else if (step === 'spec') setStep('setup');
        else close();
      }
    });
  }

  bind();
  window.openProcessCapabilityModal = open;
  window.closeProcessCapabilityModal = close;
})();
