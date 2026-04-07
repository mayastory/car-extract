<?php
// Module/IPQC/lib/ipqc_graph_modal.php
// Graph modal UI + JS (JMP-like) for IPQC OMM daily mean/min/max.
// NOTE: This file is included from Module/IPQC/pages/ipqc_view.php and MUST NOT touch the data table.
?>
<style>
/* ===== JMP-like Graph Modal (IPQC) ===== */
#gmOverlay{
  position:fixed; inset:0;
  background:rgba(0,0,0,.65);
  z-index:99999;
  display:none;
}
#gmModal{
  position:absolute;
  inset:12px;
  background:rgba(12,14,12,.96);
  border:1px solid rgba(255,255,255,.12);
  border-radius:14px;
  overflow:hidden;
  box-shadow:0 20px 60px rgba(0,0,0,.6);
  display:flex;
  flex-direction:column;
}
#gmTopbar{
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 12px;
  border-bottom:1px solid rgba(255,255,255,.10);
  background:linear-gradient(to bottom, rgba(20,24,20,.95), rgba(12,14,12,.92));
}
#gmTopbar .title{
  font-weight:800;
  font-size:18px;
  letter-spacing:.2px;
}
#gmTopbar .subtitle{
  opacity:.75;
  font-size:12px;
  margin-left:10px;
}
#gmTopbar .left{
  display:flex; align-items:baseline; gap:10px;
}
#gmTopbar .right{
  display:flex; align-items:center; gap:8px;
}
#gmTopbar button{
  background:rgba(0,0,0,.28);
  border:1px solid rgba(255,255,255,.14);
  color:#eaf7ea;
  padding:6px 10px;
  border-radius:10px;
  cursor:pointer;
}
#gmTopbar button:hover{ background:rgba(0,0,0,.38); }
#gmTopbar .primary{
  border-color: rgba(82, 255, 125, .45);
}
#gmBody{
  flex:1;
  display:grid;
  grid-template-columns: 280px 320px 1fr;
  min-height:0;
}
.gmCol{
  min-height:0;
  border-right:1px solid rgba(255,255,255,.10);
}
.gmCol:last-child{ border-right:0; }

.gmPane{
  padding:10px;
  display:flex;
  flex-direction:column;
  gap:10px;
  height:100%;
  min-height:0;
}
.gmCard{
  background:rgba(0,0,0,.25);
  border:1px solid rgba(255,255,255,.10);
  border-radius:12px;
  overflow:hidden;
}
.gmCard .hdr{
  display:flex; align-items:center; justify-content:space-between;
  padding:8px 10px;
  border-bottom:1px solid rgba(255,255,255,.08);
  background:rgba(255,255,255,.04);
  font-weight:700;
}
.gmCard .hdr small{ font-weight:500; opacity:.75; }
.gmCard .body{
  padding:8px 10px;
  min-height:0;
}
.gmRow{
  display:flex; gap:8px; align-items:center;
}
.gmRow input{
  width:100%;
  padding:7px 10px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(0,0,0,.25);
  color:#eaf7ea;
}
.gmHint{ font-size:12px; opacity:.75; }
.gmList{
  max-height:240px;
  overflow:auto;
  padding:6px;
}
.gmItem{
  position:relative;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  padding:7px 8px;
  border-radius:10px;
  cursor:pointer;
  user-select:none;
}
.gmItem:hover{ background:rgba(255,255,255,.06); }
.gmItem.sel{ outline:1px solid rgba(82,255,125,.45); background:rgba(82,255,125,.10); }
.gmItem .name{ font-weight:700; }
.gmItem .count{ font-variant-numeric: tabular-nums; opacity:.85; font-size:12px; }
.gmItem .bar{
  position:absolute; inset:0;
  border-radius:10px;
  background:linear-gradient(to right, rgba(82,255,125,.22), rgba(82,255,125,0));
  opacity:.0;
  pointer-events:none;
  width:0%;
}
.gmItem.hasbar .bar{ opacity:.55; }
.gmItem .text{ position:relative; z-index:1; display:flex; width:100%; align-items:center; justify-content:space-between; gap:8px; }
.gmBtns{
  display:flex; gap:6px;
}
.gmBtns button{
  padding:6px 8px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(0,0,0,.28);
  color:#eaf7ea;
  cursor:pointer;
  font-size:12px;
}
.gmBtns button:hover{ background:rgba(0,0,0,.38); }

.gmZone{
  border:1px dashed rgba(255,255,255,.22);
  border-radius:12px;
  padding:8px;
  min-height:64px;
  background:rgba(0,0,0,.18);
}
.gmZone.dragover{ border-color: rgba(82,255,125,.55); background:rgba(82,255,125,.06); }
.gmChipWrap{
  display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;
}
.gmChip{
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(0,0,0,.26);
  cursor:grab;
  user-select:none;
}
.gmChip .x{
  width:18px; height:18px; border-radius:6px;
  display:inline-flex; align-items:center; justify-content:center;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.06);
  cursor:pointer;
}
.gmChip:hover{ background:rgba(0,0,0,.34); }
.gmChip.dragging{ opacity:.5; }

#gmCanvas{
  position:relative;
  height:100%;
  overflow:auto;
  background:rgba(0,0,0,.12);
}
#gmGrid{
  display:grid;
  gap:0;
  padding:10px;
  min-width:900px;
}
.gmHead{
  position:sticky;
  top:0;
  z-index:5;
  background:rgba(12,14,12,.92);
  border-bottom:1px solid rgba(255,255,255,.10);
}
.gmCell, .gmColHead, .gmRowHead, .gmCorner{
  border:1px solid rgba(255,255,255,.08);
  background:rgba(255,255,255,.02);
}
.gmCorner{
  position:sticky; left:0; z-index:6;
  background:rgba(12,14,12,.92);
}
.gmRowHead{
  position:sticky; left:0; z-index:4;
  padding:10px 8px;
  font-weight:800;
  background:rgba(12,14,12,.88);
  min-width:140px;
}
.gmColHead{
  padding:8px 8px;
  text-align:center;
  font-weight:800;
  background:rgba(12,14,12,.88);
  min-width:260px;
}
.gmColHead .sub{ font-weight:600; opacity:.75; margin-top:2px; font-size:12px; }
.gmCell{
  min-width:260px;
  min-height:130px;
  padding:6px;
  display:flex;
}
.gmCell .empty{
  opacity:.55; font-size:12px;
  display:flex; align-items:center; justify-content:center;
  width:100%;
}
.gmSvg{
  width:100%;
  height:100%;
}
#gmRightTools{
  position:absolute;
  right:10px; top:10px;
  display:flex; flex-direction:column; gap:8px;
  z-index:10;
}
#gmRightTools button{
  padding:8px 10px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(0,0,0,.28);
  color:#eaf7ea;
  cursor:pointer;
  font-size:12px;
}
#gmRightTools button:hover{ background:rgba(0,0,0,.38); }

.gmToast{
  position:absolute;
  left:50%; bottom:16px;
  transform:translateX(-50%);
  padding:10px 12px;
  border-radius:12px;
  background:rgba(0,0,0,.75);
  border:1px solid rgba(255,255,255,.14);
  font-size:12px;
  opacity:0;
  pointer-events:none;
  transition:opacity .18s ease;
}
.gmToast.show{ opacity:1; }
</style>

<div id="gmOverlay" aria-hidden="true">
  <div id="gmModal" role="dialog" aria-modal="true">
    <div id="gmTopbar">
      <div class="left">
        <div class="title">그래프</div>
        <div class="subtitle" id="gmSubtitle">OMM 데이터 기반 (일자별 평균/Min/Max)</div>
      </div>
      <div class="right">
        <button id="gmMaxBtn" class="primary" title="행(FAI) 최대 선택 수">4개</button>
        <button id="gmClearBtn" title="행(FAI) 선택 초기화">해제</button>
        <button id="gmReloadBtn" class="primary" title="새로고침">새로고침</button>
        <button id="gmCloseBtn" class="primary" title="닫기">닫기</button>
      </div>
    </div>

    <div id="gmBody">
      <!-- Col 1: Local Data Filter -->
      <div class="gmCol">
        <div class="gmPane">
          <div class="gmCard">
            <div class="hdr">로컬 데이터 필터 <small id="gmFacetNote"></small></div>
            <div class="body">
              <div class="gmHint">Ctrl(또는 ⌘) + 클릭으로 다중 선택 / 클릭만 하면 단일 선택</div>
            </div>
          </div>

          <div class="gmCard" style="flex:1; min-height:0;">
            <div class="hdr">
              <span>Tool</span>
              <span class="gmBtns">
                <button type="button" id="gmToolAllBtn">전체</button>
                <button type="button" id="gmToolNoneBtn">해제</button>
              </span>
            </div>
            <div class="body" style="padding:6px;">
              <div class="gmList" id="gmToolList" style="max-height:none; height:100%;"></div>
            </div>
          </div>

          <div class="gmCard" style="flex:1; min-height:0;">
            <div class="hdr">
              <span>Cavity</span>
              <span class="gmBtns">
                <button type="button" id="gmCavAllBtn">전체</button>
                <button type="button" id="gmCavNoneBtn">해제</button>
              </span>
            </div>
            <div class="body" style="padding:6px;">
              <div class="gmList" id="gmCavityList" style="max-height:none; height:100%;"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Col 2: Graph Builder -->
      <div class="gmCol">
        <div class="gmPane">
          <div class="gmCard">
            <div class="hdr">그래프 빌더</div>
            <div class="body">
              <div class="gmRow">
                <input id="gmKeySearch" placeholder="FAI 검색..." />
                <button type="button" id="gmOpenMetaBtn" title="현재 조건의 메타(JSON) 바로 열기">메타</button>
              </div>
              <div class="gmHint" style="margin-top:6px;">왼쪽 FAI를 <b>드래그</b>해서 “행”에 넣으면 그래프가 갱신됩니다. (최대 4개)</div>
            </div>
          </div>

          <div class="gmCard" style="flex:1; min-height:0;">
            <div class="hdr">FAI / SPC 목록 <small id="gmKeyNote"></small></div>
            <div class="body" style="padding:6px;">
              <div class="gmList" id="gmKeyList" style="max-height:none; height:100%;"></div>
            </div>
          </div>

          <div class="gmCard">
            <div class="hdr">행 (선택한 FAI) <small id="gmRowsNote"></small></div>
            <div class="body">
              <div id="gmDropRows" class="gmZone" data-zone="rows">
                <div class="gmHint">여기로 드래그</div>
                <div class="gmChipWrap" id="gmRowsChips"></div>
              </div>
            </div>
          </div>

          <div class="gmCard">
            <div class="hdr">열 (선택한 Tool × Cavity)</div>
            <div class="body">
              <div class="gmHint" id="gmColsHint">Tool/Cavity 선택을 바꾸면 열이 자동으로 바뀝니다.</div>
              <div class="gmChipWrap" id="gmColsChips" style="margin-top:8px;"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Col 3: Chart -->
      <div class="gmCol" style="position:relative;">
        <div id="gmCanvas">
          <div id="gmGrid"></div>
          <div id="gmRightTools">
            <button type="button" id="gmBtnOverlay" title="(옵션) 중첩: 같은 셀에 여러 FAI 겹쳐 그리기 (현재는 비활성)">중첩</button>
            <button type="button" id="gmBtnColor" title="(옵션) 색상 (현재는 비활성)">색상</button>
            <button type="button" id="gmBtnSize" title="(옵션) 크기 (현재는 비활성)">크기</button>
            <button type="button" id="gmBtnBand" title="Min/Max 구간 표시 토글">구간</button>
          </div>
          <div class="gmToast" id="gmToast"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
/* ===== IPQC Graph Modal (JMP-like) ===== */
(function(){
  const GM = {
    inited:false,
    maxRows:4,
    showBand:true,
    meta: null,
    facets: null,
    selectedTools: new Set(),
    selectedCavities: new Set(),
    selectedKeys: [],
    keyLabelByKey: new Map(),
    keyOrder: [],
  };

  const $ = (id)=>document.getElementById(id);

  function toast(msg){
    const el = $('gmToast');
    if(!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(()=>el.classList.remove('show'), 1400);
  }

  function getBaseRoot(){
    // Determine project base like "/JTMES" from current path:
    // "/JTMES/Module/IPQC/..." -> "/JTMES"
    const p = location.pathname || '';
    const i = p.indexOf('/Module/');
    if(i > 0) return p.substring(0, i);
    const j = p.indexOf('/public/');
    if(j > 0) return p.substring(0, j);
    // fallback: one level up
    return p.substring(0, p.lastIndexOf('/')) || '';
  }
  // --- API URL resolution (handles /jtmes/... as well as direct module path) ---
  let __gmApiBaseResolved = null;
  function apiBaseCandidates(){
    const baseRoot = getBaseRoot();
    const cand = [];
    const push = (u)=>{
      u = (u == null) ? '' : String(u);
      if(!u) return;
      if(cand.indexOf(u) >= 0) return;
      cand.push(u);
    };

    // Prefer server-injected URLs if present
    push(window.__IPQC_GRAPH_API_URL__);
    push(window.__GRAPH_API_URL__);

    // Common layouts
    push(baseRoot + '/ipqc_graph_api.php');
    push(baseRoot + '/public/legacy/ipqc_graph_api.php');
    push(baseRoot + '/public/ipqc_graph_api.php');

    // Direct module access (bypasses rewrite wrappers)
    push(baseRoot + '/Module/IPQC/lib/ipqc_graph_api.php');
    push(baseRoot + '/public/legacy/Module/IPQC/lib/ipqc_graph_api.php');

    return cand;
  }

  async function resolveApiBase(){
    if(__gmApiBaseResolved) return __gmApiBaseResolved;
    const cand = apiBaseCandidates();
    // Try candidates with ping=1 (JSON)
    for(const base of cand){
      try{
        const sep = (base.indexOf('?') >= 0) ? '&' : '?';
        const url = base + sep + 'ping=1&_t=' + Date.now();
        const res = await fetch(url, {credentials: 'same-origin'});
        const txt = await res.text();
        let j = null;
        try{ j = JSON.parse(txt); }catch(e){ j = null; }
        if(j && typeof j === 'object' && ('ok' in j)){
          __gmApiBaseResolved = base;
          return __gmApiBaseResolved;
        }
      }catch(e){ /* ignore */ }
    }
    __gmApiBaseResolved = cand[0] || (getBaseRoot() + '/ipqc_graph_api.php');
    return __gmApiBaseResolved;
  }

  function currentFilters(){
    const form = document.getElementById('filterForm');
    const type = (document.getElementById('type')?.value || 'OMM');
    const model = (document.getElementById('model')?.value || '').trim();
    const years = form ? Array.from(form.querySelectorAll('input[name="years[]"]:checked')).map(x=>x.value) : [];
    const months = form ? Array.from(form.querySelectorAll('input[name="months[]"]:checked')).map(x=>x.value) : [];
    const tools = form ? Array.from(form.querySelectorAll('input[name="tools[]"]:checked')).map(x=>x.value) : [];
    return {type, model, years, months, tools};
  }

  async function apiGet(params){
    const qs = new URLSearchParams();
    Object.entries(params || {}).forEach(([k,v])=>{
      if(v === undefined || v === null) return;
      if(Array.isArray(v)){
        v.forEach(x=>qs.append(k+'[]', x));
      }else{
        qs.set(k, String(v));
      }
    });
    const base = await resolveApiBase();
    const url = base + '?' + qs.toString();
    const res = await fetch(url, {credentials:'same-origin'});
    const txt = await res.text();
    let json;
    try{ json = JSON.parse(txt); }
    catch(e){
      throw new Error('API 응답이 JSON이 아닙니다. ('+res.status+') ' + txt.slice(0,180));
    }
    if(!json.ok) throw new Error(json.err || 'API error');
    return json;
  }

  function listSelectedValues(listEl){
    return Array.from(listEl.querySelectorAll('.gmItem.sel')).map(el=>el.dataset.value);
  }

  function applyInitialSelectionsFromPage(){
    const f = currentFilters();
    // init selection set from main filter's tool selection (if any). If empty => none (interpreted as ALL).
    GM.selectedTools = new Set(f.tools || []);
    GM.selectedCavities = new Set(); // default none => ALL
  }

  function buildFacetList(listEl, items, selectedSet){
    listEl.innerHTML = '';
    if(!items || items.length === 0){
      listEl.innerHTML = '<div class="gmHint" style="padding:8px;">데이터 없음</div>';
      return;
    }
    const maxCnt = Math.max(1, ...items.map(x=>Number(x.count||0)));
    items.forEach(it=>{
      const val = String(it.value);
      const cnt = Number(it.count||0);
      const row = document.createElement('div');
      row.className = 'gmItem hasbar' + (selectedSet.has(val) ? ' sel' : '');
      row.dataset.value = val;
      row.dataset.count = String(cnt);

      const bar = document.createElement('div');
      bar.className = 'bar';
      bar.style.width = Math.round((cnt/maxCnt)*100) + '%';

      const text = document.createElement('div');
      text.className = 'text';
      const name = document.createElement('div');
      name.className = 'name';
      name.textContent = val;
      const c = document.createElement('div');
      c.className = 'count';
      c.textContent = cnt ? cnt.toLocaleString() : '0';
      text.appendChild(name);
      text.appendChild(c);

      row.appendChild(bar);
      row.appendChild(text);
      listEl.appendChild(row);
    });
  }

  function setSelectionOnList(listEl, selectedSet){
    Array.from(listEl.querySelectorAll('.gmItem')).forEach(el=>{
      const v = el.dataset.value;
      if(selectedSet.has(v)) el.classList.add('sel'); else el.classList.remove('sel');
    });
  }

  function readSelectionFromList(listEl){
    const set = new Set();
    Array.from(listEl.querySelectorAll('.gmItem.sel')).forEach(el=>set.add(el.dataset.value));
    return set;
  }

  function onFacetClick(listEl, setRefName, ev){
    const item = ev.target.closest('.gmItem');
    if(!item) return;
    const v = item.dataset.value;
    const additive = ev.ctrlKey || ev.metaKey;
    const set = GM[setRefName];

    if(!additive){
      // single selection
      set.clear();
      set.add(v);
    }else{
      if(set.has(v)) set.delete(v); else set.add(v);
    }
    setSelectionOnList(listEl, set);
    updateColsChips();
    refreshGraph().catch(err=>alert(err.message || String(err)));
  }

  function setAll(listEl, setRefName){
    const set = GM[setRefName];
    set.clear();
    Array.from(listEl.querySelectorAll('.gmItem')).forEach(el=>set.add(el.dataset.value));
    setSelectionOnList(listEl, set);
    updateColsChips();
    refreshGraph().catch(err=>alert(err.message || String(err)));
  }
  function setNone(listEl, setRefName){
    const set = GM[setRefName];
    set.clear();
    setSelectionOnList(listEl, set);
    updateColsChips();
    refreshGraph().catch(err=>alert(err.message || String(err)));
  }

  function buildKeyList(items){
    const listEl = $('gmKeyList');
    listEl.innerHTML = '';
    GM.keyLabelByKey.clear();
    GM.keyOrder = [];

    (items || []).forEach(it=>{
      const key = String(it.key);
      const label = String(it.label || it.key);
      GM.keyLabelByKey.set(key, label);
      GM.keyOrder.push(key);

      const row = document.createElement('div');
      row.className = 'gmItem';
      row.dataset.key = key;
      row.draggable = true;

      const text = document.createElement('div');
      text.className = 'text';
      const name = document.createElement('div');
      name.className = 'name';
      name.textContent = label;
      const c = document.createElement('div');
      c.className = 'count';
      c.textContent = it.kind ? String(it.kind) : '';
      text.appendChild(name);
      text.appendChild(c);

      row.appendChild(text);

      row.addEventListener('dragstart', (e)=>{
        e.dataTransfer.setData('text/plain', key);
        e.dataTransfer.effectAllowed = 'copy';
      });
      row.addEventListener('dblclick', ()=>{
        addKeyToRows(key);
      });

      listEl.appendChild(row);
    });

    $('gmKeyNote').textContent = (items && items.length) ? ('총 ' + items.length.toLocaleString() + '개') : '';
    applyKeySearch(); // apply current filter
  }

  function applyKeySearch(){
    const q = ($('gmKeySearch').value || '').trim().toLowerCase();
    const listEl = $('gmKeyList');
    let shown = 0;
    Array.from(listEl.children).forEach(el=>{
      const key = el.dataset.key;
      const label = (GM.keyLabelByKey.get(key) || key).toLowerCase();
      const ok = !q || label.includes(q);
      el.style.display = ok ? '' : 'none';
      if(ok) shown++;
    });
    $('gmKeyNote').textContent = shown ? ('표시 ' + shown.toLocaleString() + ' / 전체 ' + (GM.keyOrder.length||0).toLocaleString()) : '';
  }

  function addKeyToRows(key){
    key = String(key);
    if(GM.selectedKeys.includes(key)) return;
    if(GM.selectedKeys.length >= GM.maxRows){
      toast('행(FAI)은 최대 ' + GM.maxRows + '개');
      return;
    }
    GM.selectedKeys.push(key);
    renderRowsChips();
    refreshGraph().catch(err=>alert(err.message || String(err)));
  }

  function removeKeyFromRows(key){
    const i = GM.selectedKeys.indexOf(String(key));
    if(i>=0){
      GM.selectedKeys.splice(i,1);
      renderRowsChips();
      refreshGraph().catch(err=>alert(err.message || String(err)));
    }
  }

  function renderRowsChips(){
    const wrap = $('gmRowsChips');
    wrap.innerHTML = '';
    GM.selectedKeys.forEach((key, idx)=>{
      const chip = document.createElement('div');
      chip.className = 'gmChip';
      chip.draggable = true;
      chip.dataset.key = key;
      chip.dataset.idx = String(idx);
      chip.textContent = GM.keyLabelByKey.get(key) || key;

      const x = document.createElement('span');
      x.className = 'x';
      x.textContent = '×';
      x.title = '제거';
      x.addEventListener('click', (e)=>{ e.stopPropagation(); removeKeyFromRows(key); });

      chip.appendChild(x);

      chip.addEventListener('dragstart', (e)=>{
        chip.classList.add('dragging');
        e.dataTransfer.setData('text/plain', key);
        e.dataTransfer.setData('application/x-gm-chip', String(idx));
        e.dataTransfer.effectAllowed = 'move';
      });
      chip.addEventListener('dragend', ()=>chip.classList.remove('dragging'));

      wrap.appendChild(chip);
    });
    $('gmRowsNote').textContent = GM.selectedKeys.length ? ('선택: ' + GM.selectedKeys.length + ' / 최대: ' + GM.maxRows) : '선택: 0';
  }

  function setupRowDrop(){
    const zone = $('gmDropRows');

    zone.addEventListener('dragover', (e)=>{
      e.preventDefault();
      zone.classList.add('dragover');
      e.dataTransfer.dropEffect = 'copy';
    });
    zone.addEventListener('dragleave', ()=>zone.classList.remove('dragover'));

    zone.addEventListener('drop', (e)=>{
      e.preventDefault();
      zone.classList.remove('dragover');
      const key = e.dataTransfer.getData('text/plain');
      if(!key) return;
      // If dropped a chip (reorder), ignore here and reorder in chip wrap.
      const chipIdx = e.dataTransfer.getData('application/x-gm-chip');
      if(chipIdx !== ''){
        // reorder: place at end (simple)
        const from = parseInt(chipIdx, 10);
        if(!isNaN(from) && from >= 0 && from < GM.selectedKeys.length){
          const k = GM.selectedKeys.splice(from,1)[0];
          GM.selectedKeys.push(k);
          renderRowsChips();
          refreshGraph().catch(err=>alert(err.message || String(err)));
        }
        return;
      }
      addKeyToRows(key);
    });
  }

  function getEffectiveTools(){
    const all = (GM.facets?.tools || []).map(x=>String(x.value));
    if(GM.selectedTools.size === 0) return all; // none means ALL
    // keep order of facets list
    return all.filter(t=>GM.selectedTools.has(t));
  }
  function getEffectiveCavities(){
    const all = (GM.facets?.cavities || []).map(x=>String(x.value));
    if(GM.selectedCavities.size === 0) return all;
    return all.filter(c=>GM.selectedCavities.has(c));
  }

  function updateColsChips(){
    const wrap = $('gmColsChips');
    wrap.innerHTML = '';
    const tools = getEffectiveTools();
    const cavs = getEffectiveCavities();
    const combos = [];
    tools.forEach(t=>{
      cavs.forEach(c=>{
        combos.push({t,c});
      });
    });
    combos.slice(0, 16).forEach(x=>{
      const chip = document.createElement('div');
      chip.className = 'gmChip';
      chip.style.cursor = 'default';
      chip.textContent = x.t + ' / ' + x.c;
      wrap.appendChild(chip);
    });
    if(combos.length > 16){
      const more = document.createElement('div');
      more.className = 'gmHint';
      more.style.marginTop = '6px';
      more.textContent = '... 외 ' + (combos.length - 16) + '개';
      wrap.appendChild(more);
    }
  }

  function fmtNum(v){
    if(v === null || v === undefined || v === '') return null;
    const n = Number(v);
    if(!isFinite(n)) return null;
    return n;
  }

  function renderGrid(rowsByCell){
    const grid = $('gmGrid');
    const tools = getEffectiveTools();
    const cavs = getEffectiveCavities();
    const cols = [];
    tools.forEach(t=>cavs.forEach(c=>cols.push({tool:t, cavity:c})));

    // grid template
    const colCount = 1 + cols.length; // + row header
    grid.style.gridTemplateColumns = '140px ' + cols.map(()=> 'minmax(260px, 1fr)').join(' ');

    // header row
    grid.innerHTML = '';

    const corner = document.createElement('div');
    corner.className = 'gmCorner gmHead';
    corner.style.padding = '8px';
    corner.innerHTML = '<div style="font-weight:800;">Z Carrier IPQC Data</div>';
    grid.appendChild(corner);

    cols.forEach(c=>{
      const h = document.createElement('div');
      h.className = 'gmColHead gmHead';
      h.innerHTML = '<div>'+c.tool+'</div><div class="sub">'+c.cavity+'</div>';
      grid.appendChild(h);
    });

    // body
    const keys = GM.selectedKeys.slice();
    if(keys.length === 0){
      // show hint
      const r = document.createElement('div');
      r.className = 'gmRowHead';
      r.textContent = '';
      grid.appendChild(r);
      const cell = document.createElement('div');
      cell.className = 'gmCell';
      cell.style.gridColumn = '2 / span ' + cols.length;
      cell.innerHTML = '<div class="empty">FAI를 선택하세요.</div>';
      grid.appendChild(cell);
      return;
    }

    keys.forEach(key=>{
      const rowHead = document.createElement('div');
      rowHead.className = 'gmRowHead';
      rowHead.textContent = GM.keyLabelByKey.get(key) || key;
      grid.appendChild(rowHead);

      cols.forEach(c=>{
        const cell = document.createElement('div');
        cell.className = 'gmCell';
        const mapKey = key + '||' + c.tool + '||' + c.cavity;
        const series = rowsByCell.get(mapKey) || [];
        if(series.length === 0){
          const empty = document.createElement('div');
          empty.className = 'empty';
          empty.textContent = '데이터 없음';
          cell.appendChild(empty);
        }else{
          const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
          svg.setAttribute('class','gmSvg');
          svg.setAttribute('viewBox','0 0 260 120');
          renderSeriesSvg(svg, series, {showBand:GM.showBand});
          cell.appendChild(svg);
        }
        grid.appendChild(cell);
      });
    });
  }

  function renderSeriesSvg(svg, series, opt){
    // series: [{date, mean, min, max}] sorted
    const W = 260, H = 120;
    const padL = 26, padR = 6, padT = 8, padB = 18;
    const innerW = W - padL - padR;
    const innerH = H - padT - padB;

    const xs = series.map((_,i)=>i);
    const ymin = Math.min(...series.map(p=>opt.showBand ? (p.min ?? p.mean) : p.mean));
    const ymax = Math.max(...series.map(p=>opt.showBand ? (p.max ?? p.mean) : p.mean));
    let y0 = ymin, y1 = ymax;
    if(!isFinite(y0) || !isFinite(y1)){ y0 = 0; y1 = 1; }
    if(y0 === y1){ y0 -= 1; y1 += 1; }
    const padY = (y1 - y0) * 0.08;
    y0 -= padY; y1 += padY;

    const xScale = (i)=> padL + (xs.length <= 1 ? innerW/2 : (i/(xs.length-1))*innerW);
    const yScale = (v)=> padT + (1 - ((v - y0)/(y1 - y0))) * innerH;

    // background axis
    const axis = document.createElementNS(svg.namespaceURI,'line');
    axis.setAttribute('x1', padL);
    axis.setAttribute('x2', W-padR);
    axis.setAttribute('y1', padT+innerH);
    axis.setAttribute('y2', padT+innerH);
    axis.setAttribute('stroke','rgba(255,255,255,.18)');
    axis.setAttribute('stroke-width','1');
    svg.appendChild(axis);

    // band (min-max)
    if(opt.showBand){
      const path = document.createElementNS(svg.namespaceURI,'path');
      let d = '';
      series.forEach((p,i)=>{
        const x = xScale(i);
        const y = yScale(p.max ?? p.mean);
        d += (i===0 ? 'M' : 'L') + x.toFixed(2) + ' ' + y.toFixed(2) + ' ';
      });
      for(let i=series.length-1;i>=0;i--){
        const p = series[i];
        const x = xScale(i);
        const y = yScale(p.min ?? p.mean);
        d += 'L' + x.toFixed(2) + ' ' + y.toFixed(2) + ' ';
      }
      d += 'Z';
      path.setAttribute('d', d);
      path.setAttribute('fill','rgba(82,255,125,.10)');
      path.setAttribute('stroke','none');
      svg.appendChild(path);
    }

    // mean line
    const line = document.createElementNS(svg.namespaceURI,'path');
    let d2 = '';
    series.forEach((p,i)=>{
      const x = xScale(i);
      const y = yScale(p.mean);
      d2 += (i===0 ? 'M' : 'L') + x.toFixed(2) + ' ' + y.toFixed(2) + ' ';
    });
    line.setAttribute('d', d2);
    line.setAttribute('fill','none');
    line.setAttribute('stroke','rgba(76, 160, 255, .95)'); // blue-ish
    line.setAttribute('stroke-width','1.6');
    svg.appendChild(line);

    // points
    series.forEach((p,i)=>{
      const c = document.createElementNS(svg.namespaceURI,'circle');
      c.setAttribute('cx', xScale(i));
      c.setAttribute('cy', yScale(p.mean));
      c.setAttribute('r', 1.8);
      c.setAttribute('fill','rgba(76, 160, 255, .95)');
      svg.appendChild(c);
    });

    // y labels (min/max)
    const t1 = document.createElementNS(svg.namespaceURI,'text');
    t1.setAttribute('x','2');
    t1.setAttribute('y', String(padT+10));
    t1.setAttribute('fill','rgba(255,255,255,.55)');
    t1.setAttribute('font-size','9');
    t1.textContent = y1.toFixed(3);
    svg.appendChild(t1);

    const t0 = document.createElementNS(svg.namespaceURI,'text');
    t0.setAttribute('x','2');
    t0.setAttribute('y', String(padT+innerH));
    t0.setAttribute('fill','rgba(255,255,255,.55)');
    t0.setAttribute('font-size','9');
    t0.textContent = y0.toFixed(3);
    svg.appendChild(t0);
  }

  async function loadMetaAndFacets(){
    const f = currentFilters();
    if(!f.model){
      $('gmFacetNote').textContent = '모델을 먼저 선택하세요.';
      return;
    }
    $('gmFacetNote').textContent = '로딩...';

    // meta: key list (FAI/SPC)
    // facets: tool/cavity lists with counts
    const part = f.model;

    // Accept both part and part_name for backward compatibility
    const baseParams = { part: part, part_name: part, years: f.years, months: f.months };

    const [meta, facets] = await Promise.all([
      apiGet(Object.assign({mode:'meta'}, baseParams)),
      apiGet(Object.assign({mode:'facets'}, baseParams)),
    ]);

    GM.meta = meta;
    GM.facets = facets;

    buildKeyList(meta.items || []);
    $('gmFacetNote').textContent = (facets.total_rows ? ('총 ' + Number(facets.total_rows).toLocaleString() + ' rows') : '');

    // Build tool/cavity lists
    buildFacetList($('gmToolList'), facets.tools || [], GM.selectedTools);
    buildFacetList($('gmCavityList'), facets.cavities || [], GM.selectedCavities);

    // Apply initial tools selection if current filters had tools.
    // If main page has selected tools but facets list does not include them (rare), ignore.
    setSelectionOnList($('gmToolList'), GM.selectedTools);
    setSelectionOnList($('gmCavityList'), GM.selectedCavities);

    updateColsChips();
  }

  async function refreshGraph(){
    const f = currentFilters();
    const part = f.model;
    if(!part) return;

    const tools = getEffectiveTools();
    const cavities = getEffectiveCavities();
    const keys = GM.selectedKeys.slice();

    // No key => just render empty grid
    if(keys.length === 0){
      renderGrid(new Map());
      return;
    }

    // Show quick status
    $('gmSubtitle').textContent = `OMM 데이터 기반 (일자별 평균/Min/Max)  |  Tool ${tools.length} × Cavity ${cavities.length} × FAI ${keys.length}`;
    const baseParams = { part: part, part_name: part, years: f.years, months: f.months };

    const json = await apiGet(Object.assign({mode:'series'}, baseParams, {
      keys: keys,
      tools: tools,
      cavities: cavities
    }));

    const rows = json.rows || [];
    const map = new Map();

    rows.forEach(r=>{
      const key = String(r.key);
      const tool = String(r.tool);
      const cavity = String(r.cavity);
      const date = String(r.date);
      const mean = fmtNum(r.mean);
      if(mean === null) return;
      const minv = fmtNum(r.min);
      const maxv = fmtNum(r.max);
      const mk = key + '||' + tool + '||' + cavity;
      if(!map.has(mk)) map.set(mk, []);
      map.get(mk).push({date, mean, min:minv, max:maxv});
    });

    // sort by date inside each cell
    map.forEach(arr=>arr.sort((a,b)=>a.date.localeCompare(b.date)));

    renderGrid(map);
  }

  function initOnce(){
    if(GM.inited) return;
    GM.inited = true;

    // Buttons
    $('gmCloseBtn').addEventListener('click', closeGraphModal);
    $('gmReloadBtn').addEventListener('click', ()=>{ loadMetaAndFacets().then(()=>refreshGraph()).catch(err=>alert(err.message||String(err))); });
    $('gmClearBtn').addEventListener('click', ()=>{ GM.selectedKeys = []; renderRowsChips(); refreshGraph().catch(err=>alert(err.message||String(err))); });
    $('gmMaxBtn').addEventListener('click', ()=>{
      GM.maxRows = (GM.maxRows === 4) ? 8 : 4;
      $('gmMaxBtn').textContent = GM.maxRows + '개';
      while(GM.selectedKeys.length > GM.maxRows) GM.selectedKeys.pop();
      renderRowsChips();
      refreshGraph().catch(err=>alert(err.message||String(err)));
    });

    $('gmBtnBand').addEventListener('click', ()=>{
      GM.showBand = !GM.showBand;
      toast(GM.showBand ? '구간: ON' : '구간: OFF');
      refreshGraph().catch(err=>alert(err.message||String(err)));
    });

    $('gmToolAllBtn').addEventListener('click', ()=>setAll($('gmToolList'),'selectedTools'));
    $('gmToolNoneBtn').addEventListener('click', ()=>setNone($('gmToolList'),'selectedTools'));
    $('gmCavAllBtn').addEventListener('click', ()=>setAll($('gmCavityList'),'selectedCavities'));
    $('gmCavNoneBtn').addEventListener('click', ()=>setNone($('gmCavityList'),'selectedCavities'));

    // Facet clicks
    $('gmToolList').addEventListener('click', (ev)=>onFacetClick($('gmToolList'),'selectedTools', ev));
    $('gmCavityList').addEventListener('click', (ev)=>onFacetClick($('gmCavityList'),'selectedCavities', ev));

    // Key search
    $('gmKeySearch').addEventListener('input', applyKeySearch);

    // Meta open
    $('gmOpenMetaBtn').addEventListener('click', async ()=>{
      const f = currentFilters();
      const part = f.model;
      if(!part){ alert('모델을 먼저 선택하세요.'); return; }
      const qs = new URLSearchParams();
      qs.set('mode','meta');
      qs.set('part', part);
      f.years.forEach(y=>qs.append('years[]', y));
      f.months.forEach(m=>qs.append('months[]', m));
      const base = await resolveApiBase();
      window.open(base + '?' + qs.toString(), '_blank');
    });

    // Row drop zone
    setupRowDrop();

    // ESC to close
    window.addEventListener('keydown', (e)=>{
      if(e.key === 'Escape' && $('gmOverlay').style.display !== 'none') closeGraphModal();
    });

    // Click outside to close
    $('gmOverlay').addEventListener('click', (e)=>{
      if(e.target === $('gmOverlay')) closeGraphModal();
    });
  }

  async function openGraphModal(){
    initOnce();
    applyInitialSelectionsFromPage();
    $('gmOverlay').style.display = 'block';
    $('gmOverlay').setAttribute('aria-hidden', 'false');

    try{
      await loadMetaAndFacets();
      renderRowsChips();
      updateColsChips();
      await refreshGraph();
    }catch(err){
      alert(err.message || String(err));
    }
  }

  function closeGraphModal(){
    $('gmOverlay').style.display = 'none';
    $('gmOverlay').setAttribute('aria-hidden', 'true');
  }

  // expose globals (used by button in ipqc_view.php)
  window.openGraphModal = openGraphModal;
  window.closeGraphModal = closeGraphModal;
})();
</script>
