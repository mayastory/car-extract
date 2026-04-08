(() => {
  'use strict';

  const root = document.getElementById('osx-root');
  const BASE = (window.OSX_BASE || '').replace(/\/$/, '');
  const INITIAL = window.OSX_INITIAL || { appId: 'notes', noteSlug: 'about-me', filePath: null };

  const DEFAULT_OS_VERSION_ID = 'sierra';
  // alanagoyal keys (with legacy fallback)
  const OS_VERSION_KEY = 'system-os-version';
  const OS_VERSION_KEY_LEGACY = 'osx_os_version';
  const THEME_KEY = 'theme';

  const $ = (sel, el = document) => el.querySelector(sel);
  const $$ = (sel, el = document) => Array.from(el.querySelectorAll(sel));

  // Created later, but referenced for theme sync to open app iframes (same-origin).
  let wm = null;

  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function url(path) {
    if (!path) return BASE + '/';
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    if (!path.startsWith('/')) path = '/' + path;
    return BASE + path;
  }

  function nowTimeString(d = new Date()) {
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${hh}:${mm}`;
  }

  function nowDateString(d = new Date()) {
    return d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
  }

  function safeJsonParse(s, fallback) {
    try { return JSON.parse(s); } catch { return fallback; }
  }

  // --- UI mount ---
  root.innerHTML = `
    <div class="osx-desktop" id="osx-desktop"></div>
    <div class="osx-brightness-overlay" id="osx-brightness"></div>

    <div class="osx-menubar" id="osx-menubar">
      <div class="mb-left">
        <div class="mb-btn mb-iconbtn" id="mb-apple-btn" title="Apple"></div>
        <div class="mb-btn" id="mb-app-btn">Finder</div>
        <div class="mb-btn reg hidden" id="mb-file-btn">File</div>
      </div>
      <div class="mb-center" id="mb-center"></div>
      <div class="mb-right">
        <div class="mb-btn mb-iconbtn" id="mb-wifi-btn" title="Wi‑Fi"></div>
        <div class="mb-btn mb-iconbtn" id="mb-battery-btn" title="Battery"></div>
        <div class="mb-btn mb-iconbtn" id="mb-cc-btn" title="Control Center"></div>
        <div class="mb-time" id="mb-time"></div>
      </div>

      <div class="osx-menu hidden" id="menu-apple"></div>
      <div class="osx-menu hidden" id="menu-app"></div>
      <div class="osx-menu hidden" id="menu-file"></div>
      <div class="osx-menu hidden" id="menu-wifi"></div>
      <div class="osx-menu hidden" id="menu-battery"></div>
      <div class="osx-menu hidden" id="menu-cc"></div>
    </div>

    <div class="osx-windows" id="osx-windows"></div>

    <div class="osx-dock" id="osx-dock"></div>

    <div class="osx-overlay show" id="overlay-lock">
      <div class="lock-bg" id="lock-bg"></div>
      <div class="lock-dim"></div>
      <div class="lock-content">
        <div class="lock-time" id="lock-time"></div>
        <div class="lock-date" id="lock-date"></div>
        <div class="lock-avatar"><img src="${url('/headshot.jpg')}" alt="" /></div>
        <div class="lock-name">User</div>
        <div class="lock-input">
          <input id="lock-pass" type="password" placeholder="Enter Password" />
          <button id="lock-go">→</button>
        </div>
        <div class="lock-hint">Press Enter to unlock (demo)</div>
      </div>
    </div>

    <div class="osx-overlay" id="overlay-sleep"><div class="simple-overlay">Sleeping…</div></div>
    <div class="osx-overlay" id="overlay-restart"><div class="simple-overlay">Restarting…</div></div>
    <div class="osx-overlay" id="overlay-shutdown"><div class="simple-overlay">Shutting down…</div></div>
  `;

  // Apply theme immediately (alanagoyal next-themes compatible)
  const mqDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function computeIsDark(theme) {
    if (theme === 'dark') return true;
    if (theme === 'light') return false;
    // system
    return !!(mqDark && mqDark.matches);
  }
  function syncThemeToIframes(isDark) {
    if (!wm || !wm.windows) return;
    for (const st of wm.windows.values()) {
      const iframe = st && st.iframe;
      if (!iframe) continue;
      try {
        const doc = iframe.contentDocument;
        if (doc && doc.documentElement) doc.documentElement.classList.toggle('dark', !!isDark);
      } catch (e) {}
    }
  }
  function applyTheme(theme) {
    const isDark = computeIsDark(theme);
    document.documentElement.classList.toggle('dark', !!isDark);
    syncThemeToIframes(isDark);
  }
  let currentTheme = (() => {
    try { return localStorage.getItem(THEME_KEY) || 'system'; } catch { return 'system'; }
  })();
  applyTheme(currentTheme);
  if (mqDark) {
    // Keep in sync when theme is system
    const onMq = () => { if (currentTheme === 'system') applyTheme('system'); };
    try { mqDark.addEventListener('change', onMq); } catch { mqDark.addListener(onMq); }
  }

  const desktopEl = $('#osx-desktop');
  const windowsEl = $('#osx-windows');
  const dockEl = $('#osx-dock');
  const brightnessEl = $('#osx-brightness');

  const mbAppleBtn = $('#mb-apple-btn');
  const mbAppBtn = $('#mb-app-btn');
  const mbFileBtn = $('#mb-file-btn');
  const mbWifiBtn = $('#mb-wifi-btn');
  const mbBatteryBtn = $('#mb-battery-btn');
  const mbCcBtn = $('#mb-cc-btn');
  const mbTime = $('#mb-time');

  const menuApple = $('#menu-apple');
  const menuApp = $('#menu-app');
  const menuFile = $('#menu-file');
  const menuWifi = $('#menu-wifi');
  const menuBattery = $('#menu-battery');
  const menuCc = $('#menu-cc');

  function setWallpaperByOsId(osId) {
    const file = {
      'leopard': 'leopard-server-wallpaper.jpg',
      'snow-leopard': 'snow-leopard-wallpaper.jpg',
      'lion': 'lion-wallpaper.jpg',
      'mountain-lion': 'mountain-lion-wallpaper.jpg',
      'yosemite': 'yosemite-wallpaper.jpg',
      'el-capitan': 'elcapitan-wallpaper.jpg',
      'sierra': 'sierra-wallpaper.jpg',
      'mojave': 'mojave-wallpaper.jpg',
      'sonoma': 'sonoma-wallpaper.jpg',
      'sequoia': 'sequoia-wallpaper.jpg',
      'tahoe': 'tahoe-wallpaper.jpg',
    }[osId] || 'sierra-wallpaper.jpg';
    const src = url('/desktop/versions/' + file);
    desktopEl.style.backgroundImage = `url('${src}')`;
    $('#lock-bg').style.backgroundImage = `url('${src}')`;
  }

  const savedOs = (
    sessionStorage.getItem(OS_VERSION_KEY) || localStorage.getItem(OS_VERSION_KEY) ||
    sessionStorage.getItem(OS_VERSION_KEY_LEGACY) || localStorage.getItem(OS_VERSION_KEY_LEGACY) ||
    DEFAULT_OS_VERSION_ID
  );
  setWallpaperByOsId(savedOs);

  function updateMenubarClock() {
    const d = new Date();
    mbTime.textContent = d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    $('#lock-time').textContent = nowTimeString(d);
    $('#lock-date').textContent = nowDateString(d);
  }
  updateMenubarClock();
  setInterval(updateMenubarClock, 1000 * 10);

  // --- Menu bar (alanagoyal menu-bar.tsx + apple-menu/app-menu/file-menu/status-menus) ---
  const ICON = (name, size=14) => (window.OSX_ICONS && window.OSX_ICONS[name]) ? window.OSX_ICONS[name](size) : '';

  // Render top-level icons
  mbAppleBtn.innerHTML = ICON('apple', 14);
  mbWifiBtn.innerHTML = ICON('wifi', 14);
  mbBatteryBtn.innerHTML = ICON('batteryFull', 14);
  mbCcBtn.innerHTML = ICON('sliders', 14);

  // System settings storage (keys match alanagoyal/lib/system-settings-context.tsx)
  const SYS = {
    BRIGHTNESS_KEY: 'system-brightness',
    VOLUME_KEY: 'system-volume',
    WIFI_KEY: 'settings-wifi-enabled',
    BT_KEY: 'settings-bluetooth-enabled',
    AIRDROP_KEY: 'settings-airdrop-mode',
    FOCUS_KEY: 'system-focus',
  };

  function getLS(key, fallback) {
    try {
      const v = localStorage.getItem(key);
      return v == null ? fallback : v;
    } catch { return fallback; }
  }
  function setLS(key, value) {
    try { localStorage.setItem(key, String(value)); } catch {}
  }
  function getBool(key, fallback=false) {
    const v = getLS(key, null);
    if (v === null) return fallback;
    return v === 'true' || v === '1' || v === 'on';
  }
  function setBool(key, b) { setLS(key, b ? 'true' : 'false'); }
  function getNum(key, fallback) {
    const v = Number(getLS(key, String(fallback)));
    return Number.isFinite(v) ? v : fallback;
  }

  function applyBrightnessFromStorage() {
    const b = clamp(getNum(SYS.BRIGHTNESS_KEY, 100), 20, 100);
    const dim = (100 - b) / 100;
    brightnessEl.style.opacity = String(dim);
  }
  applyBrightnessFromStorage();

  // Menu open state
  let openMenu = null; // 'apple'|'app'|'file'|'wifi'|'battery'|'cc'

  function closeMenus() {
    openMenu = null;
    for (const el of [menuApple, menuApp, menuFile, menuWifi, menuBattery, menuCc]) {
      el.classList.add('hidden');
    }
    for (const btn of [mbAppleBtn, mbAppBtn, mbFileBtn, mbWifiBtn, mbBatteryBtn, mbCcBtn]) {
      btn.classList.remove('active');
    }
  }

  function positionMenuUnderButton(menuEl, btnEl, align='left') {
    const mbRect = $('#osx-menubar').getBoundingClientRect();
    const r = btnEl.getBoundingClientRect();
    // Reset
    menuEl.style.left = '';
    menuEl.style.right = '';
    if (align === 'left') {
      menuEl.style.left = Math.max(8, Math.round(r.left - mbRect.left + 10)) + 'px';
    } else if (align === 'right') {
      menuEl.style.left = Math.max(8, Math.round(r.right - mbRect.left - 220)) + 'px';
    }
  }

  function menuItem(label, { icon=null, shortcut=null, action=null, disabled=false } = {}) {
    const iconHtml = icon ? `<span class="menu-icon">${icon}</span>` : `<span class="menu-icon"></span>`;
    const sc = shortcut ? `<span class="menu-shortcut">${shortcut}</span>` : `<span class="menu-shortcut"></span>`;
    const cls = `menu-item${disabled ? ' disabled' : ''}`;
    return `<div class="${cls}" data-action="${action || ''}"><div class="menu-left">${iconHtml}<span>${label}</span></div>${sc}</div>`;
  }

  function renderAppleMenu() {
    const userName = 'User';
    menuApple.innerHTML = [
      menuItem('About This Mac', { icon: ICON('info', 16), action: 'about' }),
      menuItem('System Settings...', { icon: ICON('monitor', 16), action: 'settings' }),
      '<div class="menu-divider"></div>',
      menuItem('Sleep', { icon: ICON('moon', 16), action: 'sleep' }),
      menuItem('Restart...', { icon: ICON('rotateCcw', 16), action: 'restart' }),
      menuItem('Shut Down...', { icon: ICON('power', 16), action: 'shutdown' }),
      '<div class="menu-divider"></div>',
      menuItem('Lock Screen', { icon: ICON('lock', 16), action: 'lock' }),
      menuItem(`Log Out ${userName}...`, { icon: ICON('logOut', 16), action: 'logout' }),
    ].join('');
  }

  function renderAppMenu(appName) {
    menuApp.innerHTML = [
      menuItem(`About ${appName}`, { icon: ICON('info', 16), action: 'app-about' }),
      '<div class="menu-divider"></div>',
      menuItem(`Quit ${appName}`, { icon: ICON('x', 16), shortcut: '⌘Q', action: 'app-quit' }),
    ].join('');
  }

  function renderFileMenu(appId, appName) {
    const isNotes = appId === 'notes';
    const isMessages = appId === 'messages';
    if (!(isNotes || isMessages)) {
      menuFile.innerHTML = '';
      return;
    }

    const items = [];
    if (isNotes) {
      items.push(menuItem('New Note', { shortcut: '⌘N', action: 'file:new-note' }));
      items.push(menuItem('Pin Note', { shortcut: '⌘⇧P', action: 'file:pin-note' }));
      items.push('<div class="menu-divider"></div>');
      items.push(menuItem('Delete Note', { shortcut: '⌘⌫', action: 'file:delete-note' }));
    }
    if (isMessages) {
      items.push(menuItem('New Message', { shortcut: '⌘N', action: 'file:new-message' }));
    }
    menuFile.innerHTML = items.join('');
  }

  function renderWifiMenu() {
    const wifiEnabled = getBool(SYS.WIFI_KEY, true);
    const toggle = `<button class="toggle ${wifiEnabled ? 'on' : ''}" data-action="wifi:toggle"><span class="knob"></span></button>`;
    const rows = [];
    rows.push(`<div class="menu-head"><span>Wi‑Fi</span>${toggle}</div>`);
    rows.push('<div class="menu-divider"></div>');
    if (wifiEnabled) {
      rows.push(`<div class="menu-item" data-action="wifi:network"><div class="menu-left"><span class="menu-icon">${ICON('smartphone', 16)}</span><span>basecase</span></div><span class="menu-shortcut">Connected</span></div>`);
      rows.push('<div class="menu-divider"></div>');
      rows.push(`<div class="menu-item" data-action="wifi:settings"><div class="menu-left"><span class="menu-icon">${ICON('monitor', 16)}</span><span>Wi‑Fi Settings...</span></div><span></span></div>`);
    } else {
      rows.push(`<div class="menu-item disabled"><div class="menu-left"><span class="menu-icon"></span><span>Wi‑Fi is Off</span></div><span></span></div>`);
    }
    menuWifi.innerHTML = rows.join('');
  }

  function renderBatteryMenu() {
    menuBattery.innerHTML = [
      `<div class="menu-head"><span>Battery</span><span style="font-weight:500; opacity:.8">97%</span></div>`,
      `<div style="padding: 0 10px 8px; font-size:12px; opacity:.7">Power Source: Battery</div>`,
      '<div class="menu-divider"></div>',
      `<div style="padding: 0 10px 6px; font-size:11px; opacity:.65; font-weight:600">Energy Mode</div>`,
      `<div class="menu-item" data-action="battery:lowpower"><div class="menu-left"><span class="menu-icon">${ICON('battery', 16)}</span><span>Low Power</span></div><span></span></div>`,
      '<div class="menu-divider"></div>',
      `<div style="padding: 6px 10px; font-size:12px; opacity:.7">No Apps Using Significant Energy</div>`,
    ].join('');
  }

  function renderControlCenterMenu() {
    const wifiEnabled = getBool(SYS.WIFI_KEY, true);
    const btEnabled = getBool(SYS.BT_KEY, true);
    const airdropMode = getLS(SYS.AIRDROP_KEY, 'contacts');
    const focus = getLS(SYS.FOCUS_KEY, 'none');
    const brightness = clamp(getNum(SYS.BRIGHTNESS_KEY, 100), 20, 100);
    const volume = clamp(getNum(SYS.VOLUME_KEY, 50), 0, 100);

    menuCc.innerHTML = [
      `<div class="menu-head"><span>Control Center</span><span></span></div>`,
      '<div class="menu-divider"></div>',
      `<div class="menu-item" data-action="cc:wifi"><div class="menu-left"><span class="menu-icon">${ICON('wifi', 16)}</span><span>Wi‑Fi</span></div><span class="menu-shortcut">${wifiEnabled ? 'On' : 'Off'}</span></div>`,
      `<div class="menu-item" data-action="cc:bt"><div class="menu-left"><span class="menu-icon">${ICON('bluetooth', 16)}</span><span>Bluetooth</span></div><span class="menu-shortcut">${btEnabled ? 'On' : 'Off'}</span></div>`,
      `<div class="menu-item" data-action="cc:airdrop"><div class="menu-left"><span class="menu-icon">${ICON('chevronRight', 16)}</span><span>AirDrop</span></div><span class="menu-shortcut">${airdropMode}</span></div>`,
      `<div class="menu-item" data-action="cc:focus"><div class="menu-left"><span class="menu-icon">${ICON('bedDouble', 16)}</span><span>Focus</span></div><span class="menu-shortcut">${focus}</span></div>`,
      '<div class="menu-divider"></div>',
      `<div class="slider-row"><span class="menu-icon">${ICON('sun', 16)}</span><input type="range" min="20" max="100" value="${brightness}" data-action="cc:brightness"/></div>`,
      `<div class="slider-row"><span class="menu-icon">${ICON('volume2', 16)}</span><input type="range" min="0" max="100" value="${volume}" data-action="cc:volume"/></div>`,
    ].join('');
  }

  function openMenuByName(name, btn, menuEl, align='left') {
    const isOpen = openMenu === name;
    closeMenus();
    if (isOpen) return;
    openMenu = name;
    btn.classList.add('active');
    menuEl.classList.remove('hidden');
    positionMenuUnderButton(menuEl, btn, align);
  }

  function toggleAppleMenu() { renderAppleMenu(); openMenuByName('apple', mbAppleBtn, menuApple, 'left'); }
  function toggleAppMenu() {
    const st = wm && wm.focusedWinId ? wm.windows.get(wm.focusedWinId) : null;
    const appName = st?.app?.menuBarTitle || st?.app?.name || 'Finder';
    renderAppMenu(appName);
    openMenuByName('app', mbAppBtn, menuApp, 'left');
  }
  function toggleFileMenu() {
    const st = wm && wm.focusedWinId ? wm.windows.get(wm.focusedWinId) : null;
    const appId = st?.appId || 'finder';
    const appName = st?.app?.menuBarTitle || st?.app?.name || 'Finder';
    renderFileMenu(appId, appName);
    openMenuByName('file', mbFileBtn, menuFile, 'left');
  }
  function toggleWifiMenu() { renderWifiMenu(); openMenuByName('wifi', mbWifiBtn, menuWifi, 'right'); }
  function toggleBatteryMenu() { renderBatteryMenu(); openMenuByName('battery', mbBatteryBtn, menuBattery, 'right'); }
  function toggleCcMenu() { renderControlCenterMenu(); openMenuByName('cc', mbCcBtn, menuCc, 'right'); }

  mbAppleBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleAppleMenu(); });
  mbAppBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleAppMenu(); });
  mbFileBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleFileMenu(); });
  mbWifiBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleWifiMenu(); });
  mbBatteryBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleBatteryMenu(); });
  mbCcBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleCcMenu(); });

  document.addEventListener('click', () => closeMenus());
  window.addEventListener('resize', () => closeMenus());

  function dispatchToFocusedApp(payload) {
    const st = (wm && wm.focusedWinId) ? wm.windows.get(wm.focusedWinId) : null;
    const iframe = st && st.iframe;
    if (!iframe) return;
    try { iframe.contentWindow.postMessage(payload, '*'); } catch {}
  }

  function onMenuClick(e) {
    const item = e.target.closest('.menu-item');
    if (!item || item.classList.contains('disabled')) return;
    const act = item.getAttribute('data-action') || '';
    if (!act) return;
    // Many actions close menus immediately.
    closeMenus();

    if (act === 'about') return wm.open('notes', { noteSlug: 'about-me' });
    if (act === 'settings') return wm.open('settings');
    if (act === 'sleep') return overlay.sleep();
    if (act === 'restart') return overlay.restart();
    if (act === 'shutdown') return overlay.shutdown();
    if (act === 'lock') return overlay.lock(true);
    if (act === 'logout') return overlay.lock(true);

    if (act === 'app-about') return wm.open('notes', { noteSlug: 'about-me' });
    if (act === 'app-quit') {
      const st = (wm && wm.focusedWinId) ? wm.windows.get(wm.focusedWinId) : null;
      if (st) wm.close(st.winId);
      return;
    }

    if (act.startsWith('file:')) {
      const map = {
        'file:new-note': 'fileMenu:newNote',
        'file:pin-note': 'fileMenu:pinNote',
        'file:delete-note': 'fileMenu:deleteNote',
        'file:new-message': 'fileMenu:newMessage',
      };
      const t = map[act] || null;
      if (t) dispatchToFocusedApp({ type: t });
      return;
    }

    if (act === 'wifi:toggle') {
      const next = !getBool(SYS.WIFI_KEY, true);
      setBool(SYS.WIFI_KEY, next);
      renderWifiMenu();
      return;
    }
    if (act === 'wifi:settings') return wm.open('settings', { section: 'wifi' });

    if (act === 'cc:wifi') { setBool(SYS.WIFI_KEY, !getBool(SYS.WIFI_KEY, true)); return; }
    if (act === 'cc:bt') { setBool(SYS.BT_KEY, !getBool(SYS.BT_KEY, true)); return; }
    if (act === 'cc:airdrop') {
      const cur = getLS(SYS.AIRDROP_KEY, 'contacts');
      const next = (cur === 'off') ? 'contacts' : (cur === 'contacts') ? 'everyone' : 'off';
      setLS(SYS.AIRDROP_KEY, next);
      return;
    }
    if (act === 'cc:focus') {
      const cur = getLS(SYS.FOCUS_KEY, 'none');
      const next = (cur === 'none') ? 'sleep' : (cur === 'sleep') ? 'do-not-disturb' : 'none';
      setLS(SYS.FOCUS_KEY, next);
      return;
    }
  }

  for (const el of [menuApple, menuApp, menuFile, menuWifi, menuBattery, menuCc]) {
    el.addEventListener('click', onMenuClick);
    el.addEventListener('click', (e) => e.stopPropagation());
  }

  // Sliders in Control Center
  menuCc.addEventListener('input', (e) => {
    const t = e.target;
    if (!(t && t.matches && t.matches('input[type="range"][data-action]'))) return;
    const act = t.getAttribute('data-action');
    const v = Number(t.value);
    if (act === 'cc:brightness') {
      setLS(SYS.BRIGHTNESS_KEY, clamp(v, 20, 100));
      applyBrightnessFromStorage();
    }
    if (act === 'cc:volume') {
      setLS(SYS.VOLUME_KEY, clamp(v, 0, 100));
    }
  });

  // Keyboard shortcuts (⌘Q quit focused app, ⌘N new note/message when file menu supports it)
  document.addEventListener('keydown', (e) => {
    const meta = e.metaKey || e.ctrlKey;
    if (!meta) return;
    const key = String(e.key || '').toLowerCase();
    if (key === 'q') {
      const st = (wm && wm.focusedWinId) ? wm.windows.get(wm.focusedWinId) : null;
      if (st) { e.preventDefault(); wm.close(st.winId); }
    }
    if (key === 'n') {
      const st = (wm && wm.focusedWinId) ? wm.windows.get(wm.focusedWinId) : null;
      if (!st) return;
      if (st.appId === 'notes') { e.preventDefault(); dispatchToFocusedApp({ type: 'fileMenu:newNote' }); }
      if (st.appId === 'messages') { e.preventDefault(); dispatchToFocusedApp({ type: 'fileMenu:newMessage' }); }
    }
  });

  const overlay = {
    lock(show = true) {
      $('#overlay-lock').classList.toggle('show', show);
      if (show) {
        setTimeout(() => $('#lock-pass').focus(), 50);
      }
    },
    sleep() {
      $('#overlay-sleep').classList.add('show');
      setTimeout(() => { $('#overlay-sleep').classList.remove('show'); overlay.lock(true); }, 1200);
    },
    restart() {
      $('#overlay-restart').classList.add('show');
      setTimeout(() => window.location.reload(), 900);
    },
    shutdown() {
      $('#overlay-shutdown').classList.add('show');
      // demo: just lock after a moment
      setTimeout(() => { $('#overlay-shutdown').classList.remove('show'); overlay.lock(true); }, 1100);
    },
  };

  function unlock() {
    overlay.lock(false);
    // open initial app
    wm.open(INITIAL.appId || 'notes', { noteSlug: INITIAL.noteSlug, filePath: INITIAL.filePath });
  }

  $('#lock-go').addEventListener('click', unlock);
  $('#lock-pass').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') unlock();
  });

  // --- App / window management ---

  class WindowManager {
    constructor() {
      this.apps = [];
      this.appMap = new Map();
      this.windows = new Map(); // winId -> state
      this.z = 50;
      this.focusedWinId = null;
      this.dockDesiredScale = 1;
      this._dockMouseX = null;
    }

    async init() {
      const res = await fetch(url('/api/apps.php'), { cache: 'no-store' });
      const data = await res.json();
      this.apps = Array.isArray(data.apps) ? data.apps : [];
      this.appMap = new Map(this.apps.map(a => [a.id, a]));
      this.renderDock();
      // Ensure Finder exists; but visibility is based on (desktop)
      if (!this.appMap.has('finder')) {
        console.warn('Finder app not found in (desktop).');
      }
    }

    getApp(appId) {
      return this.appMap.get(appId) || null;
    }

    _makeWinId(appId) {
      const app = this.getApp(appId);
      if (!app) return null;
      if (!app.multiWindow) return appId;
      // create unique
      let n = 1;
      while (this.windows.has(`${appId}#${n}`)) n++;
      return `${appId}#${n}`;
    }

    open(appId, opts = {}) {
      const app = this.getApp(appId);
      if (!app) return;

      const winId = this._makeWinId(appId);
      if (!winId) return;

      // If single-window app already open, focus/unminimize
      if (!app.multiWindow && this.windows.has(winId)) {
        const st = this.windows.get(winId);
        st.minimized = false;
        this.focus(winId);
        this._syncWindowDom(winId);
        this.renderDock();
        this._sendToApp(winId, { type: 'shell:navigate', ...opts });
        return;
      }

      const pos = { ...app.defaultPosition };
      const size = { ...app.defaultSize };

      // cascade for multiWindow
      if (app.multiWindow) {
        const baseId = appId;
        const openCount = Array.from(this.windows.keys()).filter(k => k.startsWith(baseId + '#')).length;
        pos.x += (openCount * (app.cascadeOffset || 30));
        pos.y += (openCount * (app.cascadeOffset || 30));
      }

      const st = {
        winId,
        appId,
        title: app.name,
        x: pos.x,
        y: pos.y,
        w: size.width,
        h: size.height,
        minimized: false,
        maximized: false,
        z: ++this.z,
        iframe: null,
      };
      this.windows.set(winId, st);
      this._createWindowDom(st, opts);
      this.focus(winId);
      this.renderDock();
    }

    close(winId) {
      const st = this.windows.get(winId);
      if (!st) return;
      const el = document.getElementById(`win-${cssSafe(winId)}`);
      if (el) el.remove();
      this.windows.delete(winId);
      if (this.focusedWinId === winId) {
        this.focusedWinId = null;
        // focus topmost remaining
        let top = null;
        for (const s of this.windows.values()) {
          if (!top || s.z > top.z) top = s;
        }
        if (top) this.focus(top.winId);
        else {
          mbAppBtn.textContent = 'Finder';
          mbFileBtn.classList.add('hidden');
          closeMenus();
        }
      }
      this.renderDock();
    }

    minimize(winId) {
      const st = this.windows.get(winId);
      if (!st) return;
      st.minimized = true;
      this._syncWindowDom(winId);
      this.renderDock();
    }

    toggleMaximize(winId) {
      const st = this.windows.get(winId);
      if (!st) return;
      st.maximized = !st.maximized;
      this._syncWindowDom(winId);
    }

    focus(winId) {
      const st = this.windows.get(winId);
      if (!st) return;
      st.z = ++this.z;
      this.focusedWinId = winId;
      for (const s of this.windows.values()) {
        const el = document.getElementById(`win-${cssSafe(s.winId)}`);
        if (!el) continue;
        el.style.zIndex = String(s.z);
        el.classList.toggle('inactive', s.winId !== winId);
      }
      const app = this.getApp(st.appId);
      const appName = app?.menuBarTitle || app?.name || st.appId;
      mbAppBtn.textContent = appName;
      // File menu only for Notes + Messages (alanagoyal file-menu.tsx)
      if (st.appId === 'notes' || st.appId === 'messages') mbFileBtn.classList.remove('hidden');
      else mbFileBtn.classList.add('hidden');
      closeMenus();
    }

    bringAppToFront(appId) {
      // find top window of app
      let top = null;
      for (const s of this.windows.values()) {
        if (s.appId !== appId) continue;
        if (!top || s.z > top.z) top = s;
      }
      if (top) {
        top.minimized = false;
        this.focus(top.winId);
        this._syncWindowDom(top.winId);
        this.renderDock();
      } else {
        this.open(appId);
      }
    }

    _createWindowDom(st, opts) {
      const app = this.getApp(st.appId);
      const el = document.createElement('div');
      el.className = 'osx-window';
      el.id = `win-${cssSafe(st.winId)}`;
      el.style.left = st.x + 'px';
      el.style.top = st.y + 'px';
      el.style.width = st.w + 'px';
      el.style.height = st.h + 'px';
      el.style.zIndex = String(st.z);

      el.innerHTML = `
        <div class="osx-titlebar" data-role="titlebar">
          <div class="window-controls" data-role="controls">
            <button class="traffic close" title="Close" data-action="close">
              <span class="icon">${svgClose()}</span>
            </button>
            <button class="traffic min" title="Minimize" data-action="min">
              <span class="icon">${svgMin()}</span>
            </button>
            <button class="traffic zoom" title="Zoom" data-action="zoom">
              <span class="icon">${svgZoom()}</span>
            </button>
          </div>
          <div class="osx-title" data-role="title">${escapeHtml(app?.name || st.appId)}</div>
          <div style="width:64px"></div>
        </div>
        <div class="osx-window-body"></div>
        <div class="resize-handle n" data-r="n"></div>
        <div class="resize-handle s" data-r="s"></div>
        <div class="resize-handle e" data-r="e"></div>
        <div class="resize-handle w" data-r="w"></div>
        <div class="resize-handle ne" data-r="ne"></div>
        <div class="resize-handle nw" data-r="nw"></div>
        <div class="resize-handle se" data-r="se"></div>
        <div class="resize-handle sw" data-r="sw"></div>
      `;

      windowsEl.appendChild(el);

      el.addEventListener('mousedown', (e) => {
        if (e.button !== 0) return;
        this.focus(st.winId);
      });

      // controls
      el.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const act = btn.getAttribute('data-action');
        if (act === 'close') this.close(st.winId);
        if (act === 'min') this.minimize(st.winId);
        if (act === 'zoom') this.toggleMaximize(st.winId);
      });

      // drag
      const titlebar = el.querySelector('[data-role="titlebar"]');
      titlebar.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) return;
        if (st.maximized) return;
        const isControl = e.target.closest('[data-role="controls"]');
        if (isControl) return;
        titlebar.setPointerCapture(e.pointerId);
        const startX = e.clientX;
        const startY = e.clientY;
        const baseX = st.x;
        const baseY = st.y;
        const onMove = (ev) => {
          const dx = ev.clientX - startX;
          const dy = ev.clientY - startY;
          st.x = clamp(baseX + dx, -2000, window.innerWidth - 60);
          st.y = clamp(baseY + dy, -2000, window.innerHeight - 60);
          this._syncWindowDom(st.winId);
        };
        const onUp = () => {
          titlebar.removeEventListener('pointermove', onMove);
          titlebar.removeEventListener('pointerup', onUp);
        };
        titlebar.addEventListener('pointermove', onMove);
        titlebar.addEventListener('pointerup', onUp);
      });

      // resize
      $$('.resize-handle', el).forEach((h) => {
        h.addEventListener('pointerdown', (e) => {
          if (e.button !== 0) return;
          if (st.maximized) return;
          e.stopPropagation();
          h.setPointerCapture(e.pointerId);
          const dir = h.getAttribute('data-r');
          const startX = e.clientX;
          const startY = e.clientY;
          const base = { x: st.x, y: st.y, w: st.w, h: st.h };
          const min = app?.minSize || { width: 300, height: 200 };
          const onMove = (ev) => {
            const dx = ev.clientX - startX;
            const dy = ev.clientY - startY;
            let x = base.x, y = base.y, w = base.w, hh = base.h;
            if (dir.includes('e')) w = base.w + dx;
            if (dir.includes('s')) hh = base.h + dy;
            if (dir.includes('w')) { w = base.w - dx; x = base.x + dx; }
            if (dir.includes('n')) { hh = base.h - dy; y = base.y + dy; }
            w = Math.max(min.width, w);
            hh = Math.max(min.height, hh);
            st.x = x; st.y = y; st.w = w; st.h = hh;
            this._syncWindowDom(st.winId);
          };
          const onUp = () => {
            h.removeEventListener('pointermove', onMove);
            h.removeEventListener('pointerup', onUp);
          };
          h.addEventListener('pointermove', onMove);
          h.addEventListener('pointerup', onUp);
        });
      });

      // iframe
      const body = el.querySelector('.osx-window-body');
      const iframe = document.createElement('iframe');
      const src = new URL(app.appUrl, window.location.origin);
      // pass context
      src.searchParams.set('appId', st.appId);
      if (opts.noteSlug) src.searchParams.set('noteSlug', opts.noteSlug);
      if (opts.filePath) src.searchParams.set('file', opts.filePath);
      iframe.src = src.toString();
      iframe.setAttribute('allow', 'clipboard-read; clipboard-write');
      body.appendChild(iframe);
      st.iframe = iframe;

      // keep title in sync via postMessage
      this._sendToApp(st.winId, { type: 'shell:init', base: BASE, appId: st.appId, winId: st.winId, opts });

      // focus gating
      iframe.addEventListener('load', () => {
        // Ensure iframe theme matches current shell theme.
        try {
          const isDark = computeIsDark(currentTheme);
          const doc = iframe.contentDocument;
          if (doc && doc.documentElement) doc.documentElement.classList.toggle('dark', !!isDark);
        } catch (e) {}
        this._sendToApp(st.winId, { type: 'shell:navigate', ...opts });
      });
    }

    _syncWindowDom(winId) {
      const st = this.windows.get(winId);
      if (!st) return;
      const el = document.getElementById(`win-${cssSafe(winId)}`);
      if (!el) return;
      if (st.minimized) {
        el.classList.add('hidden');
        return;
      }
      el.classList.remove('hidden');
      el.classList.toggle('maximized', !!st.maximized);
      if (!st.maximized) {
        el.style.left = st.x + 'px';
        el.style.top = st.y + 'px';
        el.style.width = st.w + 'px';
        el.style.height = st.h + 'px';
      } else {
        el.style.left = '0px';
        el.style.top = '0px';
        el.style.width = '100%';
        el.style.height = '100%';
      }
    }

    _sendToApp(winId, payload) {
      const st = this.windows.get(winId);
      if (!st || !st.iframe || !st.iframe.contentWindow) return;
      st.iframe.contentWindow.postMessage(payload, '*');
    }

    renderDock() {
      // Default dock apps: showOnDockByDefault !== false (ground truth from app-config)
      const visibleApps = this.apps.filter(a => a.showOnDockByDefault !== false);
      // plus open apps that are not default
      const openBaseAppIds = new Set(Array.from(this.windows.values()).map(w => w.appId));
      const extras = this.apps.filter(a => !visibleApps.find(v => v.id === a.id) && openBaseAppIds.has(a.id));

      const ordered = [...visibleApps, ...extras];

      const parts = [];
      // Finder first if present
      const finder = ordered.find(a => a.id === 'finder');
      const rest = ordered.filter(a => a.id !== 'finder');

      const list = [];
      if (finder) list.push(finder);
      list.push(...rest);

      dockEl.innerHTML = '';

      list.forEach((app) => {
        const item = document.createElement('div');
        item.className = 'dock-item';
        item.dataset.appId = app.id;
        item.title = app.name;
        if (openBaseAppIds.has(app.id)) item.classList.add('open');

        item.innerHTML = `
          <img src="${url(app.icon)}" alt="" draggable="false" />
          <div class="dot"></div>
        `;

        item.addEventListener('click', (e) => {
          e.stopPropagation();
          this.bringAppToFront(app.id);
        });

        dockEl.appendChild(item);
      });

      // divider + trash
      const divider = document.createElement('div');
      divider.className = 'dock-divider';
      dockEl.appendChild(divider);

      const trash = document.createElement('div');
      trash.className = 'dock-item';
      trash.dataset.appId = 'trash';
      trash.title = 'Trash';
      trash.innerHTML = `<img src="${url('/trash.png')}" alt="" draggable="false" />`;
      trash.addEventListener('click', () => alert('Trash (demo)'));
      dockEl.appendChild(trash);

      // magnification
      this._attachDockMagnification();
    }

    _attachDockMagnification() {
      dockEl.onmousemove = (e) => {
        const rect = dockEl.getBoundingClientRect();
        const x = e.clientX - rect.left;
        this._dockMouseX = x;
        this._applyDockScale();
      };
      dockEl.onmouseleave = () => {
        this._dockMouseX = null;
        this._applyDockScale(true);
      };
    }

    _applyDockScale(reset = false) {
      const items = $$('.dock-item', dockEl);
      const mouseX = this._dockMouseX;
      items.forEach((it) => {
        const img = $('img', it);
        if (!img) return;
        if (reset || mouseX === null) {
          img.style.transform = 'scale(1) translateY(0px)';
          return;
        }
        const r = it.getBoundingClientRect();
        const cx = r.left + r.width / 2;
        const dx = Math.abs((dockEl.getBoundingClientRect().left + mouseX) - cx);
        const influence = clamp(1 - (dx / 140), 0, 1);
        const scale = 1 + influence * 0.65;
        const lift = influence * 10;
        img.style.transform = `scale(${scale.toFixed(3)}) translateY(${-lift.toFixed(1)}px)`;
      });
    }
  }

  function cssSafe(id) {
    return id.replace(/[^a-zA-Z0-9_-]/g, '_');
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function svgClose() {
    return `<svg viewBox="0 0 10 10"><path d="M2.5 1.5L5 4L7.5 1.5L8.5 2.5L6 5L8.5 7.5L7.5 8.5L5 6L2.5 8.5L1.5 7.5L4 5L1.5 2.5Z" /></svg>`;
  }

  function svgMin() {
    return `<svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2 5h6" /></svg>`;
  }

  function svgZoom() {
    return `<svg viewBox="0 0 10 10"><polygon points="2,2 6,2 2,6" /><polygon points="8,8 4,8 8,4" /></svg>`;
  }

  wm = new WindowManager();

  // Cross-window messaging
  window.addEventListener('message', (ev) => {
    const msg = ev.data || {};
    if (!msg || typeof msg !== 'object') return;

    if (msg.type === 'app:setTitle' && msg.winId) {
      const st = wm.windows.get(msg.winId);
      if (st) {
        st.title = msg.title || st.title;
        const el = document.getElementById(`win-${cssSafe(msg.winId)}`);
        if (el) {
          const t = el.querySelector('[data-role="title"]');
          if (t) t.textContent = st.title;
        }
      }
    }

    if (msg.type === 'shell:openApp') {
      wm.open(msg.appId, msg.opts || {});
    }

    if (msg.type === 'shell:openFile') {
      // choose preview or textedit based on extension
      const fp = msg.file;
      const ext = (fp.split('.').pop() || '').toLowerCase();
      if (['pdf','png','jpg','jpeg','webp','gif'].includes(ext)) {
        wm.open('preview', { filePath: fp });
      } else {
        wm.open('textedit', { filePath: fp });
      }
    }

    if (msg.type === 'shell:setOsVersion') {
      const osId = msg.osId || DEFAULT_OS_VERSION_ID;
      // Save new key + legacy key for backward compatibility
      try {
        localStorage.setItem(OS_VERSION_KEY, osId);
        sessionStorage.setItem(OS_VERSION_KEY, osId);
        localStorage.setItem(OS_VERSION_KEY_LEGACY, osId);
        sessionStorage.setItem(OS_VERSION_KEY_LEGACY, osId);
      } catch (e) {}
      setWallpaperByOsId(osId);
    }

    if (msg.type === 'shell:setTheme') {
      const theme = (msg.theme === 'dark' || msg.theme === 'light' || msg.theme === 'system') ? msg.theme : 'system';
      currentTheme = theme;
      try { localStorage.setItem(THEME_KEY, theme); } catch (e) {}
      applyTheme(theme);
    }

    if (msg.type === 'shell:lock') {
      overlay.lock(true);
    }
  });

  // Init
  wm.init().then(() => {
    // lock screen stays until unlock
  });

})();
