<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('System Settings');
?>
<style>
  /* Settings (alanagoyal port) - desktop layout */
  :root{
    --s-bg: rgba(255,255,255,.88);
    --s-muted: rgba(0,0,0,.05);
    --s-muted2: rgba(0,0,0,.08);
    --s-border: rgba(0,0,0,.10);
    --s-text: rgba(0,0,0,.90);
    --s-sub: rgba(0,0,0,.60);

    /* surfaces */
    --s-sidebar-bg: rgba(245,245,245,.75);
    --s-main-bg: rgba(255,255,255,.80);
    --s-nav-bg: rgba(245,245,245,.55);
    --s-input-bg: #E8E8E7;
    --s-card-bg: rgba(255,255,255,.65);
    --s-card2-bg: rgba(0,0,0,.03);
    --s-hover: rgba(0,0,0,.04);
    --s-hover2: rgba(0,0,0,.10);

    --s-blue: #0a84ff;
    --s-green: #34c759;
    --s-red: #ff3b30;
    --s-orange: #ff9500;
    --s-yellow: #ffcc00;
    --s-cyan: #32ade6;
  }
  html.dark{
    --s-bg: rgba(24,24,27,.92);
    --s-muted: rgba(255,255,255,.06);
    --s-muted2: rgba(255,255,255,.10);
    --s-border: rgba(255,255,255,.12);
    --s-text: rgba(255,255,255,.92);
    --s-sub: rgba(255,255,255,.62);

    --s-sidebar-bg: rgba(32,32,35,.78);
    --s-main-bg: rgba(24,24,27,.82);
    --s-nav-bg: rgba(32,32,35,.55);
    --s-input-bg: rgba(255,255,255,.08);
    --s-card-bg: rgba(32,32,35,.55);
    --s-card2-bg: rgba(255,255,255,.06);
    --s-hover: rgba(255,255,255,.06);
    --s-hover2: rgba(255,255,255,.10);
  }

  body{ background: transparent; }

  .settings-root{ height:100%; display:flex; overflow:hidden; }
  .settings-sidebar{ width: 320px; background: var(--s-sidebar-bg); border-right:1px solid var(--s-border);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    display:flex; flex-direction:column; }
  .settings-main{ flex:1; min-width:0; display:flex; flex-direction:column; background: var(--s-main-bg); }

  .sb-top{ padding: 10px 10px 6px; }
  .sb-search{ position:relative; }
  .sb-search input{ width:100%; height: 28px; border-radius: 10px; border: 1px solid var(--s-border);
    padding: 0 28px 0 28px; outline:none; background: var(--s-input-bg); font-size: 12px; color: var(--s-text); }
  .sb-search .ico{ position:absolute; left: 8px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--s-sub); }
  .sb-search .clear{ position:absolute; right: 6px; top: 50%; transform: translateY(-50%);
    width: 18px; height: 18px; border-radius: 9px; border: 0; background: var(--s-hover2); color: var(--s-text); cursor:pointer; display:none; }
  .sb-search.has-query .clear{ display:block; }

  .sb-scroll{ flex:1; overflow:auto; padding: 6px 8px 10px; }

  .sb-account{ width:100%; display:flex; align-items:center; gap:10px; padding: 8px; border-radius: 12px; border:0;
    background: transparent; cursor:pointer; }
  .sb-account:hover{ background: rgba(255,255,255,.55); }
  html.dark .sb-account:hover{ background: var(--s-hover); }
  .sb-account.active{ background: var(--s-hover2); }
  .sb-account img{ width: 48px; height: 48px; border-radius: 999px; display:block; }
  .sb-account .nm{ font-size: 12px; font-weight: 600; }
  .sb-account .sub{ font-size: 10px; color: var(--s-sub); }

  .sb-cats{ margin-top: 10px; display:flex; flex-direction:column; gap: 4px; }
  .sb-cat{ width:100%; display:flex; align-items:center; gap:10px; padding: 8px 10px; border-radius: 12px; border:0;
    cursor:pointer; background: transparent; text-align:left; font-size: 12px; }
  .sb-cat:hover{ background: rgba(255,255,255,.55); }
  html.dark .sb-cat:hover{ background: var(--s-hover); }
  .sb-cat.active{ background: var(--s-hover2); }
  .sb-ic{ width: 28px; height: 28px; border-radius: 8px; display:flex; align-items:center; justify-content:center; color:white; }
  .sb-ic svg{ width: 16px; height: 16px; display:block; }
  .ic-blue{ background:#3b82f6; }
  .ic-gray{ background:#6b7280; }

  .nav{ height: 40px; display:flex; align-items:center; gap: 6px; padding: 6px 10px; border-bottom: 1px solid var(--s-border);
    background: var(--s-nav-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
  }
  .nav button{ width: 28px; height: 28px; border-radius: 8px; border: 1px solid var(--s-border);
    background: rgba(255,255,255,.70); cursor:pointer; font-size: 14px; color: var(--s-text); }
  html.dark .nav button{ background: rgba(255,255,255,.08); }
  .nav button:disabled{ opacity: .35; cursor: not-allowed; }
  .nav .title{ margin-left: 6px; font-size: 12px; font-weight: 600; color: var(--s-text); }

  .content{ flex:1; min-height:0; overflow:auto; }

  .cat-header{ display:flex; flex-direction:column; align-items:center; padding: 26px 18px 18px; border-bottom: 1px solid var(--s-border); }
  .cat-header .big-ic{ width: 64px; height: 64px; border-radius: 18px; background: rgba(0,0,0,.06); display:flex; align-items:center; justify-content:center; }
  .cat-header .big-ic svg{ width: 32px; height: 32px; display:block; color: rgba(0,0,0,.55); }
  html.dark .cat-header .big-ic svg{ color: rgba(255,255,255,.70); }
  .cat-header h1{ font-size: 14px; margin: 10px 0 3px; }
  .cat-header p{ font-size: 12px; color: var(--s-sub); margin: 0; max-width: 520px; text-align:center; line-height: 1.35; }

  .panel{ padding: 14px; }

  .card{ border:1px solid var(--s-border); border-radius: 16px; background: var(--s-card-bg); overflow:hidden; }
  .rowbtn{ width:100%; display:flex; align-items:center; justify-content:space-between; gap: 10px; padding: 10px 12px; border:0; background: transparent; cursor:pointer; }
  .rowbtn:hover{ background: var(--s-hover); }
  .rowbtn .l{ display:flex; align-items:center; gap: 10px; }
  .rowbtn .chip{ width: 28px; height: 28px; border-radius: 10px; display:flex; align-items:center; justify-content:center; color:#fff; font-size: 14px; }
  .rowbtn .txt{ font-size: 12px; }
  .rowbtn .chev{ color: var(--s-sub); }

  .about-wrap{ max-width: 720px; margin: 0 auto; padding: 18px 14px; }
  .about-top{ display:flex; flex-direction:column; align-items:center; margin-bottom: 18px; }
  .about-top h2{ margin: 6px 0 2px; font-size: 20px; }
  .about-top .sub{ font-size: 12px; color: var(--s-sub); }
  .table{ border-radius: 16px; overflow:hidden; border:1px solid var(--s-border); background: var(--s-card2-bg); }
  .table .tr{ display:flex; justify-content:space-between; padding: 10px 12px; border-bottom: 1px solid var(--s-border); }
  .table .tr:last-child{ border-bottom:0; }
  .table .k{ font-size: 12px; color: var(--s-sub); }
  .table .v{ font-size: 12px; }

  .section-title{ font-size: 12px; font-weight: 600; margin: 16px 0 10px; }

  .osbtn{ width:100%; display:flex; align-items:center; justify-content:space-between; padding: 10px 12px; border:0; background: transparent; cursor:pointer; }
  .osbtn:hover{ background: rgba(0,0,0,.04); }
  .osbtn .l{ display:flex; align-items:center; gap: 10px; }
  .osbtn .thumb{ width: 40px; height: 40px; border-radius: 999px; overflow:hidden; background: rgba(0,0,0,.06); }
  .osbtn .thumb img{ width:100%; height:100%; object-fit: cover; display:block; }
  .osbtn .meta{ display:flex; align-items:center; gap: 8px; }
  .osbtn .meta .ver{ font-size: 12px; color: var(--s-sub); }

  .appearance-card{ border:1px solid var(--s-border); border-radius: 16px; padding: 12px; background: var(--s-card2-bg); }
  .theme-row{ display:flex; align-items:center; justify-content:space-between; }
  .theme-row .label{ font-size: 12px; }
  .theme-cards{ display:flex; gap: 10px; }
  .theme-btn{ border: 2px solid transparent; border-radius: 14px; padding: 8px; background: rgba(255,255,255,.70); cursor:pointer; width: 86px;
    display:flex; flex-direction:column; align-items:center; gap: 6px; }
  html.dark .theme-btn{ background: rgba(255,255,255,.08); }
  .theme-btn.active{ border-color: var(--s-blue); }
  .theme-btn .mini{ width: 64px; height: 44px; border-radius: 10px; overflow:hidden; border:1px solid var(--s-border); background: #fff; }
  html.dark .theme-btn .mini{ background: rgba(24,24,27,.92); }
  .theme-btn .t{ font-size: 11px; }

  .osver-card{ border:1px solid var(--s-border); border-radius: 16px; padding: 12px; background: var(--s-card2-bg); }
  .osver-grid{ display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
  .osver-btn{ position:relative; border:0; background: rgba(255,255,255,.65); border-radius: 14px; padding: 10px 8px; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap: 6px;
    outline: none; }
  .osver-btn:hover{ background: rgba(255,255,255,.85); }
  html.dark .osver-btn{ background: rgba(255,255,255,.08); }
  html.dark .osver-btn:hover{ background: rgba(255,255,255,.12); }
  .osver-btn.active{ box-shadow: 0 0 0 2px var(--s-blue) inset; background: rgba(10,132,255,.10); }
  .osver-btn .check{ position:absolute; top: 8px; right: 8px; width: 18px; height: 18px; border-radius: 9px; background: var(--s-blue); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size: 12px; }
  .osver-btn .av{ width: 56px; height: 56px; border-radius: 999px; overflow:hidden; background: rgba(0,0,0,.06); }
  .osver-btn .av img{ width:100%; height:100%; object-fit: cover; display:block; }
  .osver-btn .nm{ font-size: 12px; font-weight: 600; }
  .osver-btn .ver{ font-size: 11px; color: var(--s-sub); }

  .toggle{ width: 42px; height: 24px; border-radius: 999px; border:0; background: #d1d5db; position:relative; cursor:pointer; }
  .toggle.on{ background: var(--s-blue); }
  .toggle .knob{ position:absolute; top: 2px; left: 2px; width: 20px; height: 20px; border-radius: 999px; background:#fff; box-shadow: 0 1px 2px rgba(0,0,0,.20); transition: transform .15s ease; }
  .toggle.on .knob{ transform: translateX(18px); }

  .wifi-wrap,.bt-wrap{ max-width: 720px; padding: 18px 14px; }
  .headrow{ display:flex; align-items:flex-start; gap: 12px; padding-bottom: 12px; border-bottom: 1px solid rgba(0,0,0,.10); }
  .headrow .ic{ width: 48px; height: 48px; border-radius: 14px; display:flex; align-items:center; justify-content:center; color:#fff; background:#3b82f6; flex: none; }
  .headrow .ic svg{ width: 24px; height: 24px; display:block; }
  .headrow .h{ flex:1; min-width:0; }
  .headrow .h .t{ display:flex; align-items:center; justify-content:space-between; gap: 10px; }
  .headrow .h .t span{ font-size: 12px; font-weight: 600; }
  .headrow .h p{ margin: 6px 0 0; font-size: 12px; color: var(--s-sub); line-height: 1.35; }

  .subsec{ padding: 12px 0; border-bottom: 1px solid rgba(0,0,0,.10); }
  .subsec:last-child{ border-bottom: 0; }
  .subsec h3{ font-size: 12px; color: var(--s-sub); margin: 0 0 8px; font-weight: 600; }

  .netrow{ display:flex; align-items:center; justify-content:space-between; padding: 8px 8px; border-radius: 12px; cursor:pointer; }
  .netrow:hover{ background: var(--s-hover); }
  .netrow .nm{ font-size: 12px; }
  .netrow .meta{ display:flex; align-items:center; gap: 8px; color: var(--s-sub); font-size: 12px; }

  .storage-wrap{ max-width: 720px; margin: 0 auto; padding: 18px 14px; }
  .storage-top{ display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px; }
  .storage-top .l{ font-size: 13px; font-weight: 600; }
  .storage-top .r{ font-size: 13px; color: var(--s-sub); }
  .storage-bar{ position:relative; height: 20px; border-radius: 10px; overflow:hidden; background: #3f3f46; }
  .storage-used{ position:absolute; inset:0 auto 0 0; display:flex; height:100%; }
  .storage-avail{ position:absolute; right: 8px; top: 0; bottom:0; display:flex; align-items:center; font-size: 12px; color: #d4d4d8; }
  .legend{ display:flex; flex-wrap:wrap; gap: 10px 16px; margin-top: 10px; }
  .legend .it{ display:flex; align-items:center; gap: 6px; font-size: 12px; color: var(--s-sub); }
  .dot{ width: 10px; height: 10px; border-radius: 999px; }
</style>

<div class="settings-root" id="app">
  <aside class="settings-sidebar">
    <div class="sb-top">
      <div class="sb-search" id="sb-search">
        <span class="ico">⌕</span>
        <input id="q" type="text" placeholder="Search" />
        <button class="clear" id="qclear" title="Clear">×</button>
      </div>
    </div>
    <div class="sb-scroll">
      <button class="sb-account" id="account">
        <img src="<?php echo htmlspecialchars(osx_public_url('/headshot.jpg')); ?>" alt="Alana Goyal" />
        <div>
          <div class="nm">Alana Goyal</div>
          <div class="sub">Apple Account</div>
        </div>
      </button>
      <div class="sb-cats" id="cats"></div>
    </div>
  </aside>

  <main class="settings-main">
    <div class="nav">
      <button id="back" title="Back">←</button>
      <button id="fwd" title="Forward">→</button>
      <div class="title" id="navTitle"></div>
    </div>
    <div class="content" id="content"></div>
  </main>
</div>

<script>
(function(){
  const BASE = (window.OSX_APP?.base || window.OSX_BASE || '').replace(/\/$/, '');
  const url = (p) => {
    if (!p) return BASE + '/';
    if (p.startsWith('http://') || p.startsWith('https://')) return p;
    if (!p.startsWith('/')) p = '/' + p;
    return BASE + p;
  };

  // --- Persistence (alanagoyal/lib/sidebar-persistence.ts) ---
  const SETTINGS_STATE_KEY = 'settings-state'; // sessionStorage

  // --- System settings (alanagoyal/lib/system-settings-context.tsx) ---
  const WIFI_KEY = 'settings-wifi-enabled';
  const BLUETOOTH_KEY = 'settings-bluetooth-enabled';
  const OS_VERSION_KEY = 'system-os-version';
  const OS_VERSION_KEY_LEGACY = 'osx_os_version';
  const THEME_KEY = 'theme'; // next-themes

  const ICON = (name, size=16) => {
    try {
      const set = (window.top && window.top.OSX_ICONS) ? window.top.OSX_ICONS : (window.OSX_ICONS || null);
      if (set && typeof set[name] === 'function') return set[name](size);
    } catch (e) {}
    return '';
  };

  const categories = [
    { id:'wifi', name:'Wi‑Fi', iconKey:'wifi', iconBg:'ic-blue', keywords:['wifi','wi-fi','wireless','network','internet','connect'], desktopOnly:true },
    { id:'bluetooth', name:'Bluetooth', iconKey:'bluetooth', iconBg:'ic-blue', keywords:['bluetooth','wireless','devices','airpods','keyboard','trackpad'], desktopOnly:true },
    { id:'general', name:'General', iconKey:'settings', iconBg:'ic-gray', keywords:['about','macbook','software update','storage','chip','memory','serial','macos','sonoma'] },
    { id:'appearance', name:'Appearance', iconKey:'slidersHorizontal', iconBg:'ic-blue', keywords:['light','dark','auto','theme','mode'] },
  ];
  const appleAccountKeywords = ['alana','goyal','apple','account','personal','information','name','birthday'];

  const knownNetworks = [{ name:'basecase', connected:true }];
  const personalHotspots = [{ name:"alana's iphone" }];
  const otherNetworks = [
    { name:'DIRECT-7A-HP OfficeJet Pro 9730e' },
    { name:'Xfinity Wifi' },
    { name:'Xfinity Mobile' },
  ];

  const myDevicesDesktop = [
    { name:"Alana's Magic Keyboard", connected:true, battery:91, type:'keyboard' },
    { name:"Alana's Magic Trackpad", connected:true, battery:20, type:'trackpad' },
    { name:"Nothing Headphones", connected:false, type:'headphones' },
    { name:"Alana's AirPods Max", connected:false, type:'airpods-max' },
    { name:"Alana's AirPods Pro", connected:false, type:'airpods' },
    { name:"Flipper Reg0l1", connected:false, type:'headphones' },
  ];

  const storageCategories = [
    { id:'documents', label:'Documents', color:'#ef4444', size:150.00 },
    { id:'messages', label:'Messages', color:'#f97316', size:80.00 },
    { id:'files', label:'Files', color:'#eab308', size:120.00 },
    { id:'notes', label:'Notes', color:'#22c55e', size:45.00 },
    { id:'applications', label:'Applications', color:'#06b6d4', size:90.00 },
    { id:'system', label:'System Data', color:'#71717a', size:128.71 },
  ];
  const totalSize = 994.66;
  const usedSize = 613.71;
  const availableSize = totalSize - usedSize;

  function safeJsonParse(s, fallback){ try { return JSON.parse(s); } catch { return fallback; } }

  function loadSettingsState(){
    const def = { category:'general', panel:null };
    try {
      const saved = sessionStorage.getItem(SETTINGS_STATE_KEY);
      if (!saved) return def;
      const parsed = safeJsonParse(saved, null);
      if (!parsed) return def;
      const catOk = ['general','appearance','wifi','bluetooth'].includes(parsed.category) ? parsed.category : 'general';
      const panelOk = (parsed.panel === null || ['about','personal-info','storage'].includes(parsed.panel)) ? parsed.panel : null;
      return { category: catOk, panel: panelOk };
    } catch { return def; }
  }

  function saveSettingsState(category, panel){
    try { sessionStorage.setItem(SETTINGS_STATE_KEY, JSON.stringify({ category, panel })); } catch (e) {}
  }

  function getWifiEnabled(){
    try {
      const v = localStorage.getItem(WIFI_KEY);
      return v === null ? true : (v === 'true');
    } catch { return true; }
  }
  function setWifiEnabled(v){
    try { localStorage.setItem(WIFI_KEY, String(!!v)); } catch (e) {}
  }
  function getBluetoothEnabled(){
    try {
      const v = localStorage.getItem(BLUETOOTH_KEY);
      return v === null ? true : (v === 'true');
    } catch { return true; }
  }
  function setBluetoothEnabled(v){
    try { localStorage.setItem(BLUETOOTH_KEY, String(!!v)); } catch (e) {}
  }

  function getTheme(){
    try {
      const t = localStorage.getItem(THEME_KEY) || 'system';
      return (t === 'light' || t === 'dark' || t === 'system') ? t : 'system';
    } catch { return 'system'; }
  }
  function setTheme(t){
    const theme = (t === 'light' || t === 'dark' || t === 'system') ? t : 'system';
    try { localStorage.setItem(THEME_KEY, theme); } catch (e) {}
    // Apply to iframe immediately
    try {
      const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      const isDark = (theme === 'dark') || (theme === 'system' && prefersDark);
      document.documentElement.classList.toggle('dark', !!isDark);
    } catch (e) {}
    // Apply to shell
    window.OSX_APP?.setTheme?.(theme);
  }

  function getOsVersionId(){
    try {
      return localStorage.getItem(OS_VERSION_KEY)
        || sessionStorage.getItem(OS_VERSION_KEY)
        || localStorage.getItem(OS_VERSION_KEY_LEGACY)
        || sessionStorage.getItem(OS_VERSION_KEY_LEGACY)
        || 'sierra';
    } catch { return 'sierra'; }
  }
  function setOsVersionId(id){
    try {
      localStorage.setItem(OS_VERSION_KEY, id);
      sessionStorage.setItem(OS_VERSION_KEY, id);
      // legacy
      localStorage.setItem(OS_VERSION_KEY_LEGACY, id);
      sessionStorage.setItem(OS_VERSION_KEY_LEGACY, id);
    } catch (e) {}
    window.OSX_APP?.setOsVersion?.(id);
  }

  function getDaysUntilExpiration(){
    // (alanagoyal) AppleCare+ expiration: January 4, 2027
    const expirationDate = new Date(2027, 0, 4);
    const today = new Date();
    const diffTime = expirationDate.getTime() - today.getTime();
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return Math.max(0, diffDays);
  }

  // --- app state ---
  let versions = []; // OS versions from /api/os_versions.php

  let history = [];
  let historyIndex = 0;
  let scrollToOSVersion = false;

  function setInitialFromOpts(opts){
    if (!opts || typeof opts !== 'object') return false;
    const initCat = (opts.initialCategory && ['general','appearance','wifi','bluetooth'].includes(opts.initialCategory)) ? opts.initialCategory : null;
    const initPanel = (opts.initialPanel === null || ['about','personal-info','storage'].includes(opts.initialPanel)) ? opts.initialPanel : undefined;
    if (initCat || initPanel !== undefined) {
      const cat = initCat || 'general';
      const panel = (initPanel !== undefined) ? initPanel : null;
      history = [{ category: cat, panel: panel }];
      historyIndex = 0;
      return true;
    }
    return false;
  }

  function bootState(){
    // if opened with opts, those win over persisted
    if (!setInitialFromOpts(window.OSX_APP?.opts)) {
      const saved = loadSettingsState();
      history = [{ category: saved.category, panel: saved.panel }];
      historyIndex = 0;
    }
  }

  function current(){ return history[historyIndex]; }

  function navigate(category, panel){
    const newHistory = history.slice(0, historyIndex + 1);
    newHistory.push({ category, panel });
    history = newHistory;
    historyIndex = newHistory.length - 1;
    saveSettingsState(category, panel);
    render();
  }

  function goBack(){
    if (historyIndex > 0) {
      historyIndex--;
      const st = current();
      saveSettingsState(st.category, st.panel);
      render();
    }
  }
  function goForward(){
    if (historyIndex < history.length - 1) {
      historyIndex++;
      const st = current();
      saveSettingsState(st.category, st.panel);
      render();
    }
  }

  // --- render sidebar ---
  const elCats = document.getElementById('cats');
  const elAccount = document.getElementById('account');
  const elQ = document.getElementById('q');
  const elQClear = document.getElementById('qclear');
  const elSearchWrap = document.getElementById('sb-search');

  let query = '';
  function setQuery(q){
    query = (q || '').toLowerCase();
    elQ.value = q || '';
    elSearchWrap.classList.toggle('has-query', !!q);
    renderSidebar();
  }

  elQ.addEventListener('input', () => setQuery(elQ.value));
  elQClear.addEventListener('click', () => setQuery(''));

  function renderSidebar(){
    const st = current();
    const filtered = categories.filter(c => {
      const nameOk = c.name.toLowerCase().includes(query);
      const kwOk = c.keywords.some(k => k.includes(query));
      return nameOk || kwOk;
    });

    const showAccount = (query === '') || appleAccountKeywords.some(k => k.includes(query));
    elAccount.style.display = showAccount ? '' : 'none';
    elAccount.classList.toggle('active', st.panel === 'personal-info');

    elCats.innerHTML = '';
    for (const c of filtered){
      const btn = document.createElement('button');
      btn.className = 'sb-cat' + ((st.category === c.id && st.panel !== 'personal-info' && st.panel === null) ? ' active' : '');
      btn.innerHTML = `<span class="sb-ic ${c.iconBg}">${ICON(c.iconKey, 16)}</span><span>${c.name}</span>`;
      btn.addEventListener('click', () => navigate(c.id, null));
      elCats.appendChild(btn);
    }
  }

  elAccount.addEventListener('click', () => {
    const st = current();
    navigate(st.category, 'personal-info');
  });

  // --- nav ---
  const elBack = document.getElementById('back');
  const elFwd = document.getElementById('fwd');
  const elNavTitle = document.getElementById('navTitle');

  elBack.addEventListener('click', goBack);
  elFwd.addEventListener('click', goForward);

  function getNavTitle(st){
    if (st.panel === 'about') return 'About';
    if (st.panel === 'personal-info') return 'Personal Information';
    if (st.panel === 'storage') return 'Storage';
    if (st.category === 'general') return 'General';
    if (st.category === 'appearance') return 'Appearance';
    if (st.category === 'wifi') return 'Wi-Fi';
    if (st.category === 'bluetooth') return 'Bluetooth';
    return '';
  }

  // --- content rendering helpers ---
  const elContent = document.getElementById('content');

  function headerHtml(cat){
    const info = {
      general: {
        iconKey:'settings',
        title:'General',
        desc:'Manage your overall setup and preferences for iPhone, such as software updates, device language, CarPlay, AirDrop, and more.',
      },
      appearance: {
        iconKey:'slidersHorizontal',
        title:'Appearance',
        desc:'Customize the look and feel of your Mac.',
      },
      wifi: {
        iconKey:'wifi',
        title:'Wi‑Fi',
        desc:'Set up Wi-Fi to wirelessly connect your Mac to the internet.',
      },
      bluetooth: {
        iconKey:'bluetooth',
        title:'Bluetooth',
        desc:'Connect to accessories you can use for activities such as streaming music, making phone calls, and gaming.',
      },
    }[cat];

    if (!info) return '';
    return `
      <div class="cat-header">
        <div class="big-ic">${ICON(info.iconKey, 32)}</div>
        <h1>${info.title}</h1>
        <p>${info.desc}</p>
      </div>
    `;
  }

  function renderGeneralPanel(){
    return `
      <div class="panel">
        <div class="card">
          <button class="rowbtn" data-act="about">
            <div class="l"><span class="chip" style="background:#3b82f6;">ℹ️</span><span class="txt">About</span></div>
            <span class="chev">›</span>
          </button>
          <button class="rowbtn" data-act="software-update">
            <div class="l"><span class="chip" style="background:#6b7280;">⬇️</span><span class="txt">Software Update</span></div>
            <span class="chev">›</span>
          </button>
          <button class="rowbtn" data-act="storage">
            <div class="l"><span class="chip" style="background:#6b7280;">💾</span><span class="txt">Storage</span></div>
            <span class="chev">›</span>
          </button>
        </div>
      </div>
    `;
  }

  function renderAboutPanel(){
    const daysLeft = getDaysUntilExpiration();
    const osId = getOsVersionId();
    const os = versions.find(v => v.id === osId) || versions.find(v => v.id === 'sierra') || versions[0] || { name:'Sierra', version:'10.12', wallpaperFile:'sierra-wallpaper.jpg' };
    const thumb = getThumbnailPath(os);

    return `
      <div class="about-wrap">
        <div class="about-top">
          <div style="width:128px; height:96px; margin-bottom:10px;">
            ${macbookSvg()}
          </div>
          <h2>MacBook Air</h2>
          <div class="sub">M2, 2022</div>
        </div>

        <div class="table" style="margin-bottom:16px;">
          <div class="tr"><div class="k">Name</div><div class="v">Alana's MacBook Air</div></div>
          <div class="tr"><div class="k">Chip</div><div class="v">Apple M2</div></div>
          <div class="tr"><div class="k">Memory</div><div class="v">24 GB</div></div>
          <div class="tr"><div class="k">Serial number</div><div class="v">L76NXH926Q</div></div>
        </div>

        <div class="section-title">macOS</div>
        <div class="table" style="padding:0;">
          <button class="osbtn" data-act="goto-os">
            <div class="l">
              <div class="thumb"><img src="${url(thumb)}" alt="macOS ${escapeHtml(os.name)}" loading="lazy" /></div>
              <div style="font-size:12px;">macOS ${escapeHtml(os.name)}</div>
            </div>
            <div class="meta">
              <div class="ver">Version ${escapeHtml(os.version)}</div>
              <div style="color:rgba(0,0,0,.60);">›</div>
            </div>
          </button>
        </div>

        <div style="height:10px"></div>
        <div class="section-title">AppleCare+</div>
        <div class="table">
          <div class="tr"><div class="k">AppleCare+</div><div class="v">Expires 1/4/27</div></div>
          <div class="tr"><div class="k"></div><div class="v" style="color: var(--s-blue);">Upgrade Coverage</div></div>
        </div>
        <div style="font-size:12px;color:var(--s-sub); padding: 8px 2px;">There are ${daysLeft} days left to add coverage for accidental damage.</div>
      </div>
    `;
  }

  function renderPersonalInfoPanel(){
    // Ported from personal-info.tsx (desktop)
    const devices = [
      { name:"Alana's MacBook Air", model:'This MacBook Air', type:'macbook' },
      { name:"Alana's iPhone 16 Pro", model:'iPhone 16 Pro', type:'iphone' },
      { name:"Alana's iPad", model:'iPad Air', type:'ipad' },
      { name:'Family Room', model:'Apple TV', type:'apple-tv' },
      { name:'Entertainment Room', model:'Apple TV', type:'apple-tv' },
      { name:'Bedroom', model:'Apple TV', type:'apple-tv' },
    ];

    return `
      <div class="panel" style="max-width: 820px; margin: 0 auto;">
        <div class="card" style="background: rgba(0,0,0,.03);">
          <a class="rowbtn" href="https://x.com/alanaagoyal" target="_blank" rel="noopener noreferrer" style="text-decoration:none; color: inherit;">
            <div class="l"><span class="txt">Name</span></div>
            <div class="chev" style="display:flex; gap:6px; align-items:center;"><span style="font-size:12px; color: var(--s-sub);">Alana Goyal</span><span>›</span></div>
          </a>
          <div class="rowbtn" style="cursor:default;">
            <div class="l"><span class="txt">Birthday</span></div>
            <div class="chev" style="font-size:12px; color: var(--s-sub);">1/12/1996</div>
          </div>
        </div>

        <div style="height:14px"></div>
        <div class="section-title" style="padding-left:2px;">Devices</div>
        <div class="card" style="background: rgba(0,0,0,.03);">
          ${devices.map((d,i)=>{
            const b = (i === devices.length - 1) ? '' : 'border-bottom:1px solid rgba(0,0,0,.08);';
            return `
              <div style="display:flex; gap: 12px; padding: 10px 12px; align-items:center; ${b}">
                <div style="width:48px;height:48px;">${deviceSvg(d.type)}</div>
                <div style="flex:1; min-width:0;">
                  <div style="font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(d.name)}</div>
                  <div style="font-size:12px; color: var(--s-sub);">${escapeHtml(d.model)}</div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    `;
  }

  function renderStoragePanel(){
    const usedPercent = (usedSize / totalSize) * 100;
    // segments are percent of USED within used bar
    return `
      <div class="storage-wrap">
        <div class="storage-top">
          <div class="l">Macintosh HD</div>
          <div class="r">${usedSize.toFixed(2)} GB of ${totalSize.toFixed(2)} GB used</div>
        </div>
        <div class="storage-bar">
          <div class="storage-used" style="width:${usedPercent}%;">
            ${storageCategories.map((c,idx)=>{
              const pct = (c.size / usedSize) * 100;
              const rL = (idx===0) ? 'border-top-left-radius:10px;border-bottom-left-radius:10px;' : '';
              return `<div style="width:${pct}%; background:${c.color}; ${rL}"></div>`;
            }).join('')}
          </div>
          <div class="storage-avail">${availableSize.toFixed(2)} GB</div>
        </div>
        <div class="legend">
          ${storageCategories.map(c=>`
            <div class="it"><span class="dot" style="background:${c.color}"></span><span>${escapeHtml(c.label)}</span></div>
          `).join('')}
        </div>
      </div>
    `;
  }

  function renderAppearancePanel(){
    const theme = getTheme();
    const osId = getOsVersionId();

    return `
      <div class="panel" style="max-width: 820px; margin: 0 auto;">
        <div class="appearance-card" style="margin-bottom: 12px;">
          <div class="theme-row">
            <div class="label">Appearance</div>
            <div class="theme-cards">
              ${themeButton('system','Auto',theme)}
              ${themeButton('light','Light',theme)}
              ${themeButton('dark','Dark',theme)}
            </div>
          </div>
        </div>

        <div class="osver-card" id="osversion-section">
          <div style="font-size:12px; font-weight:600; margin-bottom: 10px;">macOS Version</div>
          <div class="osver-grid">
            ${versions.map(v=>osVersionButton(v, osId)).join('')}
          </div>
        </div>
      </div>
    `;
  }

  function renderWifiPanel(){
    const wifiEnabled = getWifiEnabled();
    return `
      <div class="wifi-wrap">
        <div class="headrow">
          <div class="ic">${ICON('wifi', 24)}</div>
          <div class="h">
            <div class="t">
              <span>Wi‑Fi</span>
              <button class="toggle ${wifiEnabled ? 'on' : ''}" data-act="wifi-toggle" aria-label="Wi-Fi toggle"><span class="knob"></span></button>
            </div>
            <p>Set up Wi-Fi to wirelessly connect your Mac to the internet. Turn on Wi-Fi, then choose a network to join. <span style="color:var(--s-blue); cursor:pointer;">Learn More...</span></p>
          </div>
        </div>

        ${wifiEnabled ? `
          <div class="subsec">
            <div style="display:flex; justify-content:space-between; align-items:center; gap: 10px;">
              <div style="font-size:12px;font-weight:600;">basecase</div>
              <div style="display:flex; align-items:center; gap: 8px; color: var(--s-sub); font-size:12px;">
                <span style="display:inline-flex; align-items:center; gap:6px; color:#16a34a;"><span style="width:8px;height:8px;border-radius:999px;background:#22c55e;display:inline-block;"></span>Connected</span>
                <span title="Lock" style="display:inline-flex;align-items:center;">${ICON('lock', 16)}</span>
                <span title="Signal" style="display:inline-flex;align-items:center;">${ICON('wifi', 16)}</span>
                <button class="btn" style="height:26px; padding:0 10px; border-radius:8px;">Details...</button>
              </div>
            </div>
          </div>

          <div class="subsec">
            <h3>Personal Hotspots</h3>
            ${personalHotspots.map(h=>`
              <div class="netrow"><div class="nm">${escapeHtml(h.name)}</div><div class="meta"><span style="display:inline-flex;align-items:center;">${ICON('lock', 16)}</span><span style="display:inline-flex;align-items:center;">${ICON('smartphone', 16)}</span></div></div>
            `).join('')}
          </div>

          <div class="subsec">
            <h3>Known Network</h3>
            ${knownNetworks.map(n=>`
              <div class="netrow">
                <div class="nm"><span style="margin-right:6px;">${n.connected?'✓':''}</span>${escapeHtml(n.name)}</div>
                <div class="meta"><span style="display:inline-flex;align-items:center;">${ICON('lock', 16)}</span><span style="display:inline-flex;align-items:center;">${ICON('wifi', 16)}</span><span title="More">⋯</span></div>
              </div>
            `).join('')}
          </div>

          <div class="subsec">
            <h3>Other Networks</h3>
            ${otherNetworks.map(n=>`
              <div class="netrow">
                <div class="nm">${escapeHtml(n.name)}</div>
                <div class="meta"><span style="display:inline-flex;align-items:center;">${ICON('lock', 16)}</span><span style="display:inline-flex;align-items:center;">${ICON('wifi', 16)}</span><span title="More">⋯</span></div>
              </div>
            `).join('')}
          </div>
        ` : ''}
      </div>
    `;
  }

  function renderBluetoothPanel(){
    const btEnabled = getBluetoothEnabled();
    return `
      <div class="bt-wrap">
        <div class="headrow">
          <div class="ic">${ICON('bluetooth', 24)}</div>
          <div class="h">
            <div class="t">
              <span>Bluetooth</span>
              <button class="toggle ${btEnabled ? 'on' : ''}" data-act="bt-toggle" aria-label="Bluetooth toggle"><span class="knob"></span></button>
            </div>
            <p>Connect to accessories you can use for activities such as streaming music, typing, and gaming. <span style="color:var(--s-blue); cursor:pointer;">Learn more...</span></p>
          </div>
        </div>

        ${btEnabled ? `
          <div class="subsec" style="padding-top: 12px;">
            <div style="font-size:12px;color:var(--s-sub);">This Mac is discoverable as &quot;Alana's MacBook Air&quot; while Bluetooth Settings is open.</div>
          </div>

          <div class="subsec" style="border-bottom:0;">
            <h3>My Devices</h3>
            ${myDevicesDesktop.map(d=>`
              <div class="netrow" style="align-items:flex-start;">
                <div style="display:flex; gap: 10px; align-items:center;">
                  <div style="width:34px; height:34px; color: var(--s-sub);">${btDeviceSvg(d.type)}</div>
                  <div>
                    <div class="nm">${escapeHtml(d.name)}</div>
                    <div style="font-size:11px; color:${d.connected ? 'var(--s-text)' : 'var(--s-sub)'}; margin-top:2px;">
                      ${d.connected ? `Connected${(d.battery!==undefined)?` <span style="color:var(--s-sub);">-</span> ${batteryHtml(d.battery)}`:''}` : 'Not Connected'}
                    </div>
                  </div>
                </div>
                <div class="meta">
                  <button class="btn" style="width:28px;height:28px;border-radius:999px;padding:0;display:flex;align-items:center;justify-content:center;">i</button>
                </div>
              </div>
            `).join('')}
          </div>
        ` : ''}
      </div>
    `;
  }

  // --- small helpers (markup building) ---
  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"]/g, (ch)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[ch]));
  }

  function getThumbnailPath(os){
    // alanagoyal/lib/os-versions.ts getThumbnailPath
    const name = String(os.wallpaperFile || '').replace(/\.[^.]+$/, '');
    return `/desktop/versions/thumbnails/${name}-thumb.jpg`;
  }

  function macbookSvg(){
    // Copied from alanagoyal AboutPanel desktop SVG
    return `
      <svg viewBox="0 0 120 90" class="w-full h-full" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="10" y="5" width="100" height="65" rx="4" fill="#27272a" />
        <rect x="14" y="9" width="92" height="55" rx="2" fill="#38bdf8" />
        <rect x="52" y="5" width="16" height="4" rx="2" fill="#18181b" />
        <path d="M5 70h110l-5 8H10l-5-8z" fill="#d4d4d8" />
        <rect x="40" y="71" width="40" height="3" rx="1.5" fill="#a1a1aa" />
      </svg>
    `;
  }

  function deviceSvg(type){
    // Copied from personal-info.tsx (simplified: linear gradients omitted in inline to keep file small)
    if (type === 'macbook') {
      return `
        <svg class="w-12 h-12" viewBox="0 0 48 48" fill="none">
          <rect x="7" y="8" width="34" height="22" rx="1.5" fill="#1f2937" />
          <rect x="9" y="10" width="30" height="18" rx="1" fill="#38bdf8" />
          <path d="M4 30h40l-3 5H7l-3-5z" fill="#d1d5db" />
          <rect x="18" y="29.5" width="12" height="1" rx="0.5" fill="#d1d5db" />
        </svg>
      `;
    }
    if (type === 'iphone') {
      return `
        <svg class="w-12 h-12" viewBox="0 0 48 48" fill="none">
          <rect x="14" y="4" width="20" height="40" rx="4" fill="#1f2937" />
          <rect x="16" y="7" width="16" height="34" rx="2" fill="#a855f7" />
        </svg>
      `;
    }
    if (type === 'ipad') {
      return `
        <svg class="w-12 h-12" viewBox="0 0 48 48" fill="none">
          <rect x="12" y="4" width="24" height="40" rx="3" fill="#1f2937" />
          <rect x="14" y="7" width="20" height="34" rx="1.5" fill="#8b5cf6" />
        </svg>
      `;
    }
    return `
      <svg class="w-12 h-12" viewBox="0 0 48 48" fill="none">
        <rect x="8" y="8" width="32" height="32" rx="7" fill="#000" />
        <text x="24" y="26" text-anchor="middle" font-size="8" fill="#6b7280" font-family="system-ui">tv</text>
      </svg>
    `;
  }

  function themeButton(val, label, cur){
    const active = (val === cur);
    return `
      <button class="theme-btn ${active ? 'active' : ''}" data-act="theme" data-theme="${val}">
        <div class="mini"></div>
        <div class="t">${label}</div>
      </button>
    `;
  }

  function osVersionButton(v, selectedId){
    const active = (v.id === selectedId);
    const thumb = getThumbnailPath(v);
    return `
      <button class="osver-btn ${active ? 'active' : ''}" data-act="osver" data-os="${escapeHtml(v.id)}">
        ${active ? '<div class="check">✓</div>' : ''}
        <div class="av"><img src="${url(thumb)}" alt="macOS ${escapeHtml(v.name)}" loading="lazy" /></div>
        <div class="nm">${escapeHtml(v.name)}</div>
        <div class="ver">${escapeHtml(v.version)}</div>
      </button>
    `;
  }

  function btDeviceSvg(type){
    // Directly ported from bluetooth.tsx icons (simplified to inline currentColor)
    if (type === 'keyboard') {
      return `
        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="4" y="10" width="24" height="12" rx="2" stroke="currentColor" stroke-width="1.5" />
          <rect x="7" y="13" width="3" height="2" rx="0.5" fill="currentColor" />
          <rect x="11" y="13" width="3" height="2" rx="0.5" fill="currentColor" />
          <rect x="15" y="13" width="3" height="2" rx="0.5" fill="currentColor" />
          <rect x="19" y="13" width="3" height="2" rx="0.5" fill="currentColor" />
          <rect x="23" y="13" width="2" height="2" rx="0.5" fill="currentColor" />
          <rect x="7" y="17" width="2" height="2" rx="0.5" fill="currentColor" />
          <rect x="10" y="17" width="12" height="2" rx="0.5" fill="currentColor" />
          <rect x="23" y="17" width="2" height="2" rx="0.5" fill="currentColor" />
        </svg>
      `;
    }
    if (type === 'trackpad') {
      return `
        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="6" y="8" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.5" />
          <line x1="6" y1="20" x2="26" y2="20" stroke="currentColor" stroke-width="1" />
        </svg>
      `;
    }
    if (type === 'airpods') {
      return `
        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M10 10C10 8 11 6 13 6C15 6 16 8 16 10V18C16 20 15 22 13 22C11 22 10 20 10 18V10Z" stroke="currentColor" stroke-width="1.5" />
          <path d="M22 10C22 8 21 6 19 6C17 6 16 8 16 10V18C16 20 17 22 19 22C21 22 22 20 22 18V10Z" stroke="currentColor" stroke-width="1.5" />
          <line x1="13" y1="22" x2="13" y2="26" stroke="currentColor" stroke-width="1.5" />
          <line x1="19" y1="22" x2="19" y2="26" stroke="currentColor" stroke-width="1.5" />
        </svg>
      `;
    }
    if (type === 'airpods-max') {
      return `
        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M8 14C8 10 11 6 16 6C21 6 24 10 24 14" stroke="currentColor" stroke-width="2" />
          <rect x="6" y="14" width="6" height="10" rx="3" stroke="currentColor" stroke-width="1.5" />
          <rect x="20" y="14" width="6" height="10" rx="3" stroke="currentColor" stroke-width="1.5" />
        </svg>
      `;
    }
    return `
      <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M8 16C8 11 11 6 16 6C21 6 24 11 24 16" stroke="currentColor" stroke-width="2" />
        <rect x="6" y="16" width="5" height="8" rx="2" stroke="currentColor" stroke-width="1.5" />
        <rect x="21" y="16" width="5" height="8" rx="2" stroke="currentColor" stroke-width="1.5" />
      </svg>
    `;
  }

  function batteryHtml(level){
    const fill = Math.max(0, level - 10);
    const color = (level > 20) ? 'currentColor' : '#ef4444';
    return `
      <span style="display:inline-flex; align-items:center; gap:6px;">
        <span style="display:inline-block; position:relative; width:24px; height:12px; border:1px solid currentColor; border-radius:3px;">
          <span style="position:absolute; left:2px; top:2px; bottom:2px; width:${fill}%; background:${color}; border-radius:2px;"></span>
        </span>
        <span style="font-size:11px;">${level}%</span>
      </span>
    `;
  }

  // --- render loop ---
  function render(){
    const st = current();

    // Sidebar
    renderSidebar();

    // Nav
    elBack.disabled = !(historyIndex > 0);
    elFwd.disabled = !(historyIndex < history.length - 1);
    elNavTitle.textContent = getNavTitle(st);

    // Content
    if (st.panel === 'about') {
      elContent.innerHTML = renderAboutPanel();
    } else if (st.panel === 'personal-info') {
      elContent.innerHTML = renderPersonalInfoPanel();
    } else if (st.panel === 'storage') {
      elContent.innerHTML = renderStoragePanel();
    } else {
      if (st.category === 'wifi') {
        elContent.innerHTML = renderWifiPanel();
      } else if (st.category === 'bluetooth') {
        elContent.innerHTML = renderBluetoothPanel();
      } else if (st.category === 'general') {
        elContent.innerHTML = headerHtml('general') + renderGeneralPanel();
      } else if (st.category === 'appearance') {
        elContent.innerHTML = headerHtml('appearance') + renderAppearancePanel();
      } else {
        elContent.innerHTML = '';
      }
    }

    // Wire actions within content
    bindContentActions();

    // Scroll behavior (Software Update / About macOS)
    if (st.category === 'appearance' && st.panel === null && scrollToOSVersion) {
      scrollToOSVersion = false;
      setTimeout(() => {
        const el = document.getElementById('osversion-section');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 100);
    }
  }

  function bindContentActions(){
    // General panel actions
    elContent.querySelectorAll('[data-act="about"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const st = current();
        navigate(st.category, 'about');
      });
    });
    elContent.querySelectorAll('[data-act="storage"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const st = current();
        navigate(st.category, 'storage');
      });
    });
    elContent.querySelectorAll('[data-act="software-update"]').forEach(btn => {
      btn.addEventListener('click', () => {
        scrollToOSVersion = true;
        navigate('appearance', null);
      });
    });

    // About -> go to appearance and scroll
    elContent.querySelectorAll('[data-act="goto-os"]').forEach(btn => {
      btn.addEventListener('click', () => {
        scrollToOSVersion = true;
        navigate('appearance', null);
      });
    });

    // Appearance -> theme
    elContent.querySelectorAll('[data-act="theme"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const t = btn.getAttribute('data-theme') || 'system';
        setTheme(t);
        render();
      });
    });

    // Appearance -> os version
    elContent.querySelectorAll('[data-act="osver"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const osId = btn.getAttribute('data-os');
        if (!osId) return;
        setOsVersionId(osId);
        render();
      });
    });

    // Wi-Fi toggle
    const wbtn = elContent.querySelector('[data-act="wifi-toggle"]');
    if (wbtn) {
      wbtn.addEventListener('click', () => {
        setWifiEnabled(!getWifiEnabled());
        render();
      });
    }

    // Bluetooth toggle
    const bbtn = elContent.querySelector('[data-act="bt-toggle"]');
    if (bbtn) {
      bbtn.addEventListener('click', () => {
        setBluetoothEnabled(!getBluetoothEnabled());
        render();
      });
    }
  }

  // Handle re-navigation from shell
  window.addEventListener('osx:navigate', (ev) => {
    const d = ev.detail || {};
    if (d && typeof d === 'object') {
      if (setInitialFromOpts(d.opts)) {
        const st = current();
        saveSettingsState(st.category, st.panel);
        render();
      }
    }
  });

  // Fetch OS versions then render
  function loadOsVersions(){
    return fetch(url('/api/os_versions.php'), { cache:'no-store' })
      .then(r => r.json())
      .then(data => {
        versions = Array.isArray(data.versions) ? data.versions : [];
      })
      .catch(() => { versions = []; });
  }

  bootState();
  loadOsVersions().finally(() => {
    // Ensure theme is applied on boot
    setTheme(getTheme());
    render();
  });
})();
</script>

<?php osx_app_footer(); ?>
