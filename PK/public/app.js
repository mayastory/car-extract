import { Overworld } from "./overworld/overworld.js?v=20260213_overworld_sync_v1";

// Simple auth gate: require play_token (from character select)
const PLAY_TOKEN = sessionStorage.getItem('play_token');
if (!PLAY_TOKEN) {
  window.location.href = './login.html';
}

const statusEl = document.getElementById("status");
const pretStatusEl = document.getElementById("pretStatus");
const overworldPane = document.getElementById("overworldPane");
const battlePane = document.getElementById("battlePane");
const btnOverworld = document.getElementById("btnOverworld");
const btnBattle = document.getElementById("btnBattle");
const btnZoomIn = document.getElementById("btnZoomIn");
const btnZoomOut = document.getElementById("btnZoomOut");
const btnZoomReset = document.getElementById("btnZoomReset");
const btnMapReload = document.getElementById("btnMapReload");
const mapSelect = document.getElementById("mapSelect");

// Map name pop-up (FRLG-style)
const mapNameToast = document.getElementById("mapNameToast");
const mapNameToastText = document.getElementById("mapNameToastText");
let _mapToastTimerA = null;
let _mapToastTimerB = null;
function showMapNameToast(label){
  if(!mapNameToast || !mapNameToastText) return;
  const text = (label && String(label).trim()) ? String(label).trim() : "";
  if(!text) return;
  mapNameToastText.textContent = text;
  mapNameToast.classList.remove("hidden");
  mapNameToast.classList.add("show");
  mapNameToast.setAttribute("aria-hidden","false");
  if(_mapToastTimerA) clearTimeout(_mapToastTimerA);
  if(_mapToastTimerB) clearTimeout(_mapToastTimerB);
  _mapToastTimerA = setTimeout(()=>{
    mapNameToast.classList.remove("show");
    mapNameToast.setAttribute("aria-hidden","true");
  }, 1800);
  _mapToastTimerB = setTimeout(()=>{
    if(!mapNameToast.classList.contains("show")) mapNameToast.classList.add("hidden");
  }, 2400);
}

const debugPanel = document.getElementById("debugPanel");
const debugLog = document.getElementById("debugLog");
const btnDbgToggle = document.getElementById("btnDbgToggle");
const btnDbgHide = document.getElementById("btnDbgHide");
const btnDbgClear = document.getElementById("btnDbgClear");


// PokéMMO-style HUD (bottom)
const pokeHud = document.getElementById("pokeHud");
const pokePanel = document.getElementById("pokePanel");
const pokePanelTitle = document.getElementById("pokePanelTitle");
const pokePanelBody = document.getElementById("pokePanelBody");
const pokePanelClose = document.getElementById("pokePanelClose");

// Party HUD (right sidebar) - UI only
const partyHud = document.getElementById("partyHud");
const partySlots = document.getElementById("partySlots");
const partyCollapseBtn = document.getElementById("partyCollapse");
let _partyCollapsed = false;

function _partyIconUrl(species){
  if(!species) return null;
  const s = String(species).trim();
  if(!s) return null;
  // convention: /public/assets/pokemon/<SpeciesName>/icon.png
  // (kept as best-effort; server will later send resolved sprite paths)
  return `./assets/pokemon/${encodeURIComponent(s)}/icon.png`;
}

function renderPartyHud(party){
  if(!partySlots) return;
  const list = Array.isArray(party) ? party : [];
  partySlots.innerHTML = "";

  const maxSlots = 6;
  for(let i=0;i<maxSlots;i++){
    const p = list[i] || null;
    const slot = document.createElement("div");
    slot.className = "party-slot";
    slot.dataset.slot = String(i+1);

    const ico = document.createElement("div");
    ico.className = "pico";
    if(p && (p.icon || p.species)){
      const picon = document.createElement("div");
      picon.className = "picon";
      const url = p.icon ? String(p.icon) : _partyIconUrl(p.species);
      if(url) picon.style.backgroundImage = `url("${url}")`;;
      ico.appendChild(picon);
    }else{
      ico.textContent = "?";
    }

    const meta = document.createElement("div");
    meta.className = "pmeta";

    const nameRow = document.createElement("div");
    nameRow.className = "pname";
    const nm = document.createElement("span");
    nm.textContent = p?.nickname || p?.species || `Slot ${i+1}`;
    const lv = document.createElement("span");
    lv.className = "plv";
    lv.textContent = p?.level ? `Lv.${p.level}` : `#${i+1}`;
    nameRow.appendChild(nm);
    nameRow.appendChild(lv);

    const hpBar = document.createElement("div");
    hpBar.className = "php";
    const hpFill = document.createElement("i");
    const hp = Number(p?.hp ?? 0);
    const hpMax = Math.max(1, Number(p?.hpMax ?? 0) || 1);
    const pct = p ? Math.max(0, Math.min(1, hp / hpMax)) : 0;
    hpFill.style.width = `${Math.round(pct*100)}%`;
    hpBar.appendChild(hpFill);

    const hpText = document.createElement("div");
    hpText.className = "phptext";
    hpText.textContent = p ? `${hp}/${hpMax}` : "";

    meta.appendChild(nameRow);
    meta.appendChild(hpBar);
    meta.appendChild(hpText);

    slot.appendChild(ico);
    slot.appendChild(meta);
    partySlots.appendChild(slot);
  }
}

function setPartyHudCollapsed(v){
  _partyCollapsed = !!v;
  if(!partyHud) return;
  partyHud.classList.toggle("is-collapsed", _partyCollapsed);
}

partyCollapseBtn?.addEventListener("click", ()=>{
  setPartyHudCollapsed(!_partyCollapsed);
});

// Expose a debug helper (server will replace this later)
window.__setPartyHud = renderPartyHud;

// Initial layout preview (will be replaced by server party state)
renderPartyHud([
  { species: "pikachu", nickname: "Pikachu", level: 5, hp: 20, hpMax: 20 },
]);
setPartyHudCollapsed(false);

function closePokePanel(){
  if(!pokePanel) return;
  pokePanel.classList.add("hidden");
  pokePanel.setAttribute("aria-hidden","true");
}
function openPokePanel(key){
  if(!pokePanel || !pokePanelTitle || !pokePanelBody) return;
  const titleMap = {
    bag: "Bag",
    trainer: "Trainer",
    community: "Community",
    pvp: "PvP",
    pokedex: "POKéDEX",
    trade: "Trade",
    gift: "Gift Shop",
    menu: "Menu",
  };
  pokePanelTitle.textContent = titleMap[key] || "Menu";
  pokePanelBody.textContent = "준비중... (아이콘/기능은 나중에 연결)";
  pokePanel.classList.remove("hidden");
  pokePanel.setAttribute("aria-hidden","false");
}
pokePanelClose?.addEventListener("click", closePokePanel);
window.addEventListener("keydown", (e)=>{
  if(e.key === "Escape") closePokePanel();
});
pokeHud?.addEventListener("click", (e)=>{
  const btn = e.target?.closest?.(".pokebtn");
  if(!btn) return;
  const pane = btn.getAttribute("data-pane") || "menu";
  openPokePanel(pane);
});

function logLine(msg){
  if(!debugLog) return;
  const t = `[${new Date().toLocaleTimeString()}] ${String(msg)}`;
  debugLog.textContent += (debugLog.textContent ? "\n" : "") + t;
  debugLog.scrollTop = debugLog.scrollHeight;
}
function setDebugVisible(v){
  if(!debugPanel) return;
  debugPanel.classList.toggle("hidden", !v);
}
btnDbgToggle?.addEventListener("click", ()=> setDebugVisible(debugPanel?.classList.contains("hidden")));
btnDbgHide?.addEventListener("click", ()=> setDebugVisible(false));
btnDbgClear?.addEventListener("click", ()=> { if(debugLog) debugLog.textContent=""; });

window.addEventListener("keydown",(e)=>{
  if(e.key==="F2"){
    e.preventDefault();
    setDebugVisible(debugPanel?.classList.contains("hidden"));
  }
});


function showOverworld(){
  battlePane.classList.add("hidden");
  overworldPane.classList.remove("hidden");
  pokeHud?.classList.remove("hidden");
  partyHud?.classList.remove("hidden");
}
function showBattle(){
  overworldPane.classList.add("hidden");
  battlePane.classList.remove("hidden");
  closePokePanel();
  pokeHud?.classList.add("hidden");
  partyHud?.classList.add("hidden");
}

btnOverworld.addEventListener("click", showOverworld);
btnBattle.addEventListener("click", showBattle);

// API lives at ../api relative to /public/
const API_BASE = "../api";
window.__uiModalOpen = false;
window.__registeredItem = { item_id: 0, const_name: "", name: "", name_ko: "" };



// =========================
// GBA Key Mapping (fixed)
// A_BUTTON  : keyboard 'A'
// B_BUTTON  : keyboard 'S'
// START     : keyboard 'Z'
// SELECT    : keyboard 'X'
// Movement  : Arrow keys only (WASD disabled to avoid conflicts)
// =========================
let _gbaMenuOpen = false;
let _gbaBagOpen = false;
let _gbaFocus = "menu"; // "menu" | "bag"
let _gbaSel = 0;
let _bagSel = 0;
let _bagItems = [];
let _regItemId = 0;

const _menuItems = [
  { key:"POKEDEX", label:"POKEDEX", icon:"./assets/ui/menu/pokedex.png" },
  { key:"POKEMON", label:"POKEMON", icon:"./assets/ui/menu/community.png" },
  { key:"BAG", label:"BAG", icon:"./assets/ui/menu/bag.png" },
  { key:"TRAINER", label:"TRAINER", icon:"./assets/ui/menu/trainer.png" },
  { key:"SAVE", label:"SAVE", icon:"./assets/ui/menu/gift.png" },
  { key:"OPTION", label:"OPTION", icon:"./assets/ui/menu/menu.png" },
  { key:"EXIT", label:"EXIT", icon:"./assets/ui/menu/menu.png" },
];

let gbaMenuEl = null;
let gbaBagEl = null;

function _ensureGbaUi(){
  if(gbaMenuEl) return;
  gbaMenuEl = document.createElement("div");
  gbaMenuEl.className = "gba-startmenu";
  gbaMenuEl.innerHTML = `
    <div class="hdr">START MENU</div>
    <div class="lst"></div>
    <div class="foot" id="gbaMenuFoot"></div>
  `;
  document.body.appendChild(gbaMenuEl);

  gbaBagEl = document.createElement("div");
  gbaBagEl.className = "gba-bag";
  gbaBagEl.innerHTML = `
    <div class="hdr">
      <div class="title">BAG</div>
      <div class="reg" id="gbaRegTag">REG: -</div>
    </div>
    <div class="lst"></div>
  `;
  document.body.appendChild(gbaBagEl);
}

function _setUiModalOpen(v){
  window.__uiModalOpen = !!v;
}

function _openGbaMenu(){
  _ensureGbaUi();
  _gbaMenuOpen = true;
  _gbaFocus = "menu";
  _gbaSel = Math.max(0, Math.min(_menuItems.length-1, _gbaSel|0));
  gbaMenuEl.classList.add("open");
  gbaBagEl.classList.remove("open");
  _gbaBagOpen = false;
  _setUiModalOpen(true);
  _renderGbaMenu();
}

function _closeGbaMenu(){
  if(!gbaMenuEl) return;
  _gbaMenuOpen = false;
  _gbaBagOpen = false;
  gbaMenuEl.classList.remove("open");
  gbaBagEl.classList.remove("open");
  _setUiModalOpen(false);
}

function _openBag(){
  _ensureGbaUi();
  _gbaBagOpen = true;
  _gbaFocus = "bag";
  gbaBagEl.classList.add("open");
  _renderBag();
}

function _closeBag(){
  _gbaBagOpen = false;
  _gbaFocus = "menu";
  if(gbaBagEl) gbaBagEl.classList.remove("open");
  _renderGbaMenu();
}

async function _refreshBagAndReg(){
  try{
    const r = await fetch(`${API_BASE}/game/player_items.php?limit=5000`, { credentials:"include", cache:"no-store" });
    const j = await r.json().catch(()=>null);
    if(j && j.ok){
      _bagItems = Array.isArray(j.items) ? j.items : [];
      _regItemId = +j.reg_item_id || 0;
      window.__registeredItem = { item_id: _regItemId };
    }
  }catch(_e){}
}

function _renderGbaMenu(){
  if(!gbaMenuEl) return;
  const lst = gbaMenuEl.querySelector(".lst");
  lst.innerHTML = "";
  for(let i=0;i<_menuItems.length;i++){
    const it = _menuItems[i];
    const row = document.createElement("div");
    row.className = "row" + (i===_gbaSel ? " sel" : "");
    row.innerHTML = `
      <div class="ico"><img src="${it.icon}" alt=""></div>
      <div class="lbl">${it.label}</div>
    `;
    lst.appendChild(row);
  }
  const foot = gbaMenuEl.querySelector("#gbaMenuFoot");
  const reg = _bagItems.find(x => (+x.item_id||0) === (_regItemId|0));
  const regName = reg ? (reg.name_ko || reg.name || reg.const_name || "-") : "-";
  foot.textContent = `SELECT: use registered item  |  REG: ${regName}`;
  const tag = gbaBagEl.querySelector("#gbaRegTag");
  if(tag) tag.textContent = `REG: ${regName}`;
}

function _renderBag(){
  if(!gbaBagEl) return;
  const lst = gbaBagEl.querySelector(".lst");
  lst.innerHTML = "";
  const items = Array.isArray(_bagItems) ? _bagItems : [];
  if(_bagSel >= items.length) _bagSel = Math.max(0, items.length-1);
  for(let i=0;i<items.length;i++){
    const it = items[i];
    const name = it.name_ko || it.name || it.const_name || `ITEM_${it.item_id}`;
    const row = document.createElement("div");
    row.className = "row" + (i===_bagSel ? " sel" : "");
    const reg = (+it.item_id||0) === (_regItemId|0);
    const regTag = reg ? `<span class="tag">REG</span>` : "";
    row.innerHTML = `<div class="lbl">${name}</div><div class="qty">x${it.qty||0}</div>${regTag}`;
    lst.appendChild(row);
  }
  _renderGbaMenu();
}

async function _registerSelectedItem(){
  const items = Array.isArray(_bagItems) ? _bagItems : [];
  const it = items[_bagSel];
  if(!it) return;
  const itemId = +it.item_id || 0;
  try{
    const r = await fetch(`${API_BASE}/game/register_item.php`, {
      method:"POST",
      headers:{ "Content-Type":"application/json" },
      credentials:"include",
      body: JSON.stringify({ item_id: itemId })
    });
    const j = await r.json().catch(()=>null);
    if(j && j.ok){
      _regItemId = +j.reg_item_id || 0;
      window.__registeredItem = { item_id: _regItemId, const_name: j.const_name || "", name: j.name || "", name_ko: j.name_ko || "" };
      _renderBag();
    }
  }catch(_e){}
}

// UI key handler for overworld to call
// - When menu is CLOSED: START opens menu, SELECT uses registered item
// - When menu is OPEN  : A confirms, B cancels/back, START closes
const __KEYMAP = { A: 'a', B: 's', START: 'z', SELECT: 'x' };

window.__useRegisteredItem = async function(){
  // keep REG item fresh
  await _refreshBagAndReg();
  try{
    window.dispatchEvent(new CustomEvent('use-registered-item', { detail: window.__registeredItem || {} }));
  }catch(_e){}
};

window.__uiHandleKey = function(e){
  const key = String(e.key || '').toLowerCase();
  const isA = (key === __KEYMAP.A);
  const isB = (key === __KEYMAP.B);
  const isSTART = (key === __KEYMAP.START);
  const isSELECT = (key === __KEYMAP.SELECT);

  // Menu closed: only START/SELECT are handled here
  if(!_gbaMenuOpen){
    if(isSTART){
      e.preventDefault();
      window.__openStartMenu?.();
      return true;
    }
    if(isSELECT){
      e.preventDefault();
      window.__useRegisteredItem?.();
      return true;
    }
    return false;
  }

  // Menu open
  if(isB){
    e.preventDefault();
    if(_gbaFocus === 'bag') _closeBag();
    else _closeGbaMenu();
    return true;
  }

  if(isSTART){
    e.preventDefault();
    _closeGbaMenu();
    return true;
  }

  if(key === 'arrowup' || key === 'arrowdown'){
    e.preventDefault();
    const dir = (key === 'arrowup') ? -1 : 1;
    if(_gbaFocus === 'menu'){
      _gbaSel = (_gbaSel + dir + _menuItems.length) % _menuItems.length;
      _renderGbaMenu();
    }else{
      const n = Math.max(1, _bagItems.length);
      _bagSel = (_bagSel + dir + n) % n;
      _renderBag();
    }
    return true;
  }

  if(isA){
    e.preventDefault();
    if(_gbaFocus === 'menu'){
      const pick = _menuItems[_gbaSel];
      if(pick && pick.key === 'BAG'){
        _openBag();
      }else if(pick && pick.key === 'EXIT'){
        _closeGbaMenu();
      }
      // Others are stubs for now (layout-first)
    }else{
      _registerSelectedItem();
    }
    return true;
  }

  return false;
};

// Overworld will call this to open menu (START)
window.__openStartMenu = async function(){
  // refresh items so REG is always correct
  await _refreshBagAndReg();
  _openGbaMenu();
};


const ow = new Overworld({
  canvas: document.getElementById("game"),
  status: (t)=>{
    if(String(t).startsWith("서버")) statusEl.textContent = t;
    else statusEl.textContent = "서버 OK";
    logLine(t);
  },
  apiBase: "../api",
  playToken: PLAY_TOKEN,
  fixedZoom: 3.0,
  lockZoom: true,
  onMapLabel: (info)=>{
    // info: {mapId,label,width,height,via}
    if(!info) return;
    showMapNameToast(info.label || info.mapId);
  },
});

function updateZoomLabel(){
  // defaultZoom=3.0 => 300% (GBA 느낌 고정)
  btnZoomReset.textContent = `${Math.round(ow.zoom*100)}%`;
  btnZoomReset.title = `줌 리셋 (기본 ${Math.round(ow.defaultZoom*100)}%)`;
}
updateZoomLabel();

// Zoom is locked by design (avoid accidental scale changes)
if(ow.lockZoom){
  [btnZoomIn, btnZoomOut, btnZoomReset].forEach(b=>{ if(!b) return; b.disabled=true; b.style.opacity='0.55'; b.title='줌 고정(300%)'; });
}

async function loadPretList(){
  const apiBase = "../api/pret";
  const listRes = await fetch(`${apiBase}/list.php`, {cache:"no-store"});
  if(!listRes.ok) throw new Error(`pret list fail: HTTP ${listRes.status}`);
  const list = await listRes.json();
  if(!list.ok) throw new Error(`pret list err: ${list.err||"unknown"}`);
  return list.maps || [];
}

function fillMapSelect(maps, prefer){
  mapSelect.innerHTML = "";
  const ids = [];
  for(const m of maps){
    const id = (typeof m === 'string') ? m : m.id;
    const label = (typeof m === 'string') ? m : (m.label || m.id);
    const opt = document.createElement("option");
    opt.value = id;
    opt.textContent = label;
    mapSelect.appendChild(opt);
    ids.push(id);
  }
  const first = (prefer && ids.includes(prefer)) ? prefer : (ids[0] || "");
  mapSelect.value = first;
  return first;
}

async function loadPretMap(mapId){
  pretStatusEl.textContent = `PRET: ${mapId} 생성/로드중...`;
  pretStatusEl.className = "pretstatus";

  await ow.loadPret(mapId);
  updateZoomLabel();

  pretStatusEl.textContent = `PRET: OK (${mapId})`;
  pretStatusEl.classList.add("ok");
  pretStatusEl.classList.remove("err");
}

async function initPret(preferMap="PalletTown"){
  const maps = await loadPretList();
  if(!maps.length) throw new Error("pret maps empty");
  const first = fillMapSelect(maps, preferMap);

  mapSelect.onchange = async ()=>{
    try{
      await loadPretMap(mapSelect.value);
    }catch(e){
      console.error("[PRET MAP FAIL]", e);
      pretStatusEl.textContent = "PRET: 연결 실패 (F12 Console 확인)";
      pretStatusEl.classList.add("err");
    }
  };

  btnMapReload?.addEventListener("click", async ()=>{
    try{
      pretStatusEl.textContent = "PRET: 목록 새로고침...";
      const cur = mapSelect.value;
      const maps2 = await loadPretList();
      const pick = fillMapSelect(maps2, cur);
      await loadPretMap(pick);
    }catch(e){
      console.error("[PRET RELOAD FAIL]", e);
      pretStatusEl.textContent = "PRET: 새로고침 실패";
      pretStatusEl.classList.add("err");
    }
  });

  await loadPretMap(first);
}

async function tryLoadFromIndexJsonFallback(){
  // Fallback: if PHP/GD is not available, try static pret export.
  const idxUrl = "./pret/index.json";
  const idxRes = await fetch(idxUrl, {cache:"no-store"});
  if(!idxRes.ok) throw new Error(`HTTP ${idxRes.status} - ${idxUrl}`);
  const idx = await idxRes.json();
  const first = (idx.maps && idx.maps.length) ? idx.maps[0] : null;
  if(!first) throw new Error("pret/index.json has no maps");
  pretStatusEl.textContent = `PRET: (정적) ${first} 로드중...`;
  await ow.load(`./pret/maps/${first}.json`);
  updateZoomLabel();
  pretStatusEl.textContent = `PRET: OK (${first})`;
  pretStatusEl.classList.add("ok");
  return first;
}

(async ()=>{
  let loaded = false;

  // Prefer spawning at the server-side saved position
  let preferMap = "PalletTown";
  try {
    const stRes = await fetch(`${API_BASE}/rt/get.php`, {
      method: "GET",
      cache: "no-store",
      headers: { "Authorization": `Bearer ${PLAY_TOKEN}` }
    });
    if (stRes.ok) {
      const st = await stRes.json();
      if (st && st.ok && st.state && st.state.map_id) {
        preferMap = st.state.map_id;
      }
    }
  } catch(e) {}

  try{
    // ✅ ALWAYS prefer API so we can include connections/border/collision and correct palette rendering.
    await initPret(preferMap);
    loaded = true;
  }catch(eApi){
    console.error("[PRET API LOAD FAIL]", eApi);
    try{
      await tryLoadFromIndexJsonFallback();
      loaded = true;
    }catch(eIdx){
      console.error("[PRET INDEX LOAD FAIL]", eIdx);
      pretStatusEl.textContent = "PRET: 연결 실패 (F12 Console 확인)";
      pretStatusEl.classList.add("err");
    }
  }

  if(!loaded){
    // Fallback demo map
    await ow.load("./overworld/maps/overworld_demo.json");
    updateZoomLabel();
  }

  ow.start();
})();

// Zoom controls: step-based (픽셀아트는 정수/고정 스텝 줌이 깔끔함)
btnZoomIn.addEventListener("click", ()=>{ ow.setZoom(ow.zoom + ow.zoomStep); updateZoomLabel(); });
btnZoomOut.addEventListener("click", ()=>{ ow.setZoom(ow.zoom - ow.zoomStep); updateZoomLabel(); });
btnZoomReset.addEventListener("click", ()=>{ ow.resetZoom(); updateZoomLabel(); });

window.addEventListener("keydown", (e)=>{
  if (e.key === "b" || e.key === "B") showBattle();
  if (e.key === "+" || e.key === "=") { ow.setZoom(ow.zoom + ow.zoomStep); updateZoomLabel(); }
  if (e.key === "-") { ow.setZoom(ow.zoom - ow.zoomStep); updateZoomLabel(); }
  if (e.key === "0") { ow.resetZoom(); updateZoomLabel(); }
});
