import { Overworld } from "./overworld/overworld.js?v=20260213_overworld_sync_v1";

const byId = (id) => document.getElementById(id);
const firstEl = (...ids) => ids.map((id) => byId(id)).find(Boolean) || null;
const qs = (sel) => document.querySelector(sel);

const ui = {
  status: () => firstEl("status"),
  pretStatus: () => firstEl("pretStatus"),
  overworldPane: () => firstEl("overworldPane"),
  battlePane: () => firstEl("battlePane"),
  battleFrame: () => firstEl("battleFrame"),
  btnOverworld: () => firstEl("btnOverworld"),
  btnBattle: () => firstEl("btnBattle"),
  btnZoomIn: () => firstEl("btnZoomIn"),
  btnZoomOut: () => firstEl("btnZoomOut"),
  btnZoomReset: () => firstEl("btnZoomReset"),
  btnMapReload: () => firstEl("btnMapReload"),
  mapSelect: () => firstEl("mapSelect"),
  mapNameToast: () => firstEl("mapNameToast"),
  mapNameToastText: () => firstEl("mapNameToastText"),
  debugPanel: () => firstEl("debugPanel"),
  debugLog: () => firstEl("debugLog"),
  btnDbgToggle: () => firstEl("btnDbgToggle"),
  btnDbgHide: () => firstEl("btnDbgHide"),
  btnDbgClear: () => firstEl("btnDbgClear"),
  pokeHud: () => firstEl("pokeHud"),
  pokePanel: () => firstEl("pokePanel"),
  pokePanelTitle: () => firstEl("pokePanelTitle"),
  pokePanelBody: () => firstEl("pokePanelBody"),
  pokePanelClose: () => firstEl("pokePanelClose"),
  partyHud: () => firstEl("partyHud"),
  partySlots: () => firstEl("partySlots"),
  partyCollapse: () => firstEl("partyCollapse"),
  fatalOverlay: () => firstEl("fatalOverlay"),
  fatalTitle: () => firstEl("fatalTitle"),
  fatalMessage: () => firstEl("fatalMessage"),
  fatalDetail: () => firstEl("fatalDetail"),
  btnFatalRetry: () => firstEl("btnFatalRetry"),
  btnFatalLogin: () => firstEl("btnFatalLogin"),
};

const state = {
  ow: null,
  booting: false,
  battleLoaded: false,
  partyCollapsed: false,
  mapToastTimerA: null,
  mapToastTimerB: null,
};

function getCanvas() {
  return (
    firstEl("overworldCanvas", "gameCanvas", "owCanvas", "canvas") ||
    qs("#overworldPane canvas") ||
    qs("canvas") ||
    null
  );
}

function ensureCanvas() {
  let canvas = getCanvas();
  if (canvas) return canvas;

  const pane = ui.overworldPane() || document.body;
  canvas = document.createElement("canvas");
  canvas.id = "overworldCanvas";
  canvas.style.width = "100%";
  canvas.style.height = "100%";
  canvas.style.display = "block";
  pane.prepend(canvas);
  return canvas;
}

function logLine(msg) {
  const log = ui.debugLog();
  if (!log) return;
  const line = `[${new Date().toLocaleTimeString()}] ${String(msg ?? "")}`;
  log.textContent += (log.textContent ? "\n" : "") + line;
  log.scrollTop = log.scrollHeight;
}

function setStatus(msg) {
  const el = ui.status();
  if (el) el.textContent = String(msg ?? "");
  if (msg) logLine(msg);
}

function setPretStatus(text, ok = null) {
  const el = ui.pretStatus();
  if (!el) return;
  el.textContent = String(text ?? "");
  if (ok === true) {
    el.classList.add("ok");
    el.classList.remove("bad");
  } else if (ok === false) {
    el.classList.add("bad");
    el.classList.remove("ok");
  }
}

function updateZoomLabel() {
  const btn = ui.btnZoomReset();
  const ow = state.ow;
  if (!btn || !ow) return;
  const zoom = Number.isFinite(ow.zoom) ? ow.zoom : 3;
  btn.textContent = `${Math.round(zoom * 100)}%`;
}

function closeFatalOverlay() {
  const overlay = ui.fatalOverlay();
  if (!overlay) return;
  overlay.classList.add("hidden");
  overlay.setAttribute("aria-hidden", "true");
}

function showFatalOverlay(kind, title, message, detail = "") {
  const overlay = ui.fatalOverlay();
  const titleEl = ui.fatalTitle();
  const msgEl = ui.fatalMessage();
  const detailEl = ui.fatalDetail();
  const btnLogin = ui.btnFatalLogin();
  const btnRetry = ui.btnFatalRetry();
  if (!overlay || !titleEl || !msgEl) return;

  const finalTitle = String(title || "오류");
  const finalMessage = String(message || "오류가 발생했습니다.");
  const finalDetail = String(detail || "").trim();

  titleEl.textContent = finalTitle;
  msgEl.textContent = finalMessage;
  if (detailEl) detailEl.textContent = finalDetail;
  if (btnRetry) btnRetry.textContent = kind === "server" ? "다시 시도" : "새로고침";
  if (btnLogin) btnLogin.classList.toggle("hidden", kind === "server");

  overlay.classList.remove("hidden");
  overlay.setAttribute("aria-hidden", "false");
}

function truncateText(value, maxLen = 1200) {
  const s = String(value ?? "").trim();
  if (!s) return "";
  return s.length > maxLen ? `${s.slice(0, maxLen)}…` : s;
}

function formatErrorDetail(err) {
  if (!err) return "";
  const parts = [];
  if (err.message) parts.push(`메시지: ${truncateText(err.message, 500)}`);
  if (err.fileName) parts.push(`파일: ${err.fileName}`);
  if (Number.isFinite(err.lineNumber)) parts.push(`위치: ${err.lineNumber}:${err.columnNumber || 0}`);
  if (err.stack) parts.push(`스택: ${truncateText(err.stack, 1500)}`);
  return parts.join("\n");
}

async function readResponsePreview(res) {
  try {
    return truncateText(await res.clone().text(), 800);
  } catch (_e) {
    return "";
  }
}

function explainUrl(url) {
  const u = String(url || "");
  if (!u) return "";
  if (u.includes("/auth/")) return "인증 확인 요청";
  if (u.includes("/rt/get.php")) return "플레이어 상태 조회";
  if (u.includes("/rt/upsert.php")) return "플레이어 위치 저장";
  if (u.includes("/rt/map_mobs.php")) return "맵 몹 조회";
  if (u.includes("/rt/map_items.php")) return "맵 아이템 조회";
  return u;
}

function isCriticalUrl(url) {
  const u = String(url || "");
  return (
    u.includes("/auth/whoami.php") ||
    u.includes("/rt/get.php") ||
    u.includes("/rt/upsert.php")
  );
}

const originalFetch = window.fetch.bind(window);
window.fetch = async function wrappedFetch(input, init) {
  const url = typeof input === "string" ? input : input?.url || "";
  const method = String(init?.method || (typeof input !== "string" ? input?.method : "") || "GET").toUpperCase();

  try {
    const res = await originalFetch(input, init);
    if (isCriticalUrl(url) && !res.ok) {
      const preview = await readResponsePreview(res);
      showFatalOverlay(
        res.status === 401 ? "auth" : "server",
        res.status === 401 ? "로그인/인증 오류" : "서버 오류",
        `${explainUrl(url) || "요청"} 실패`,
        [
          `요청: ${method} ${url || "(알 수 없음)"}`,
          `상태: ${res.status} ${res.statusText || ""}`.trim(),
          preview ? `응답: ${preview}` : "",
        ]
          .filter(Boolean)
          .join("\n")
      );
    }
    return res;
  } catch (err) {
    if (isCriticalUrl(url)) {
      showFatalOverlay(
        "server",
        "서버 연결 오류",
        `${explainUrl(url) || "요청"} 중 예외가 발생했습니다.`,
        [
          `요청: ${method} ${url || "(알 수 없음)"}`,
          `메시지: ${truncateText(err?.message || err, 500)}`,
          err?.stack ? `스택: ${truncateText(err.stack, 1500)}` : "",
        ]
          .filter(Boolean)
          .join("\n")
      );
    }
    throw err;
  }
};

window.addEventListener("error", (e) => {
  const message = String(e?.message || "알 수 없는 스크립트 오류");
  const detail = [
    e?.filename ? `파일: ${e.filename}` : "",
    Number.isFinite(e?.lineno) && e.lineno > 0 ? `위치: ${e.lineno}:${e.colno || 0}` : "",
    e?.error?.stack ? `스택: ${truncateText(e.error.stack, 1500)}` : "",
  ]
    .filter(Boolean)
    .join("\n");
  showFatalOverlay("fatal", "스크립트 오류", message, detail);
});

window.addEventListener("unhandledrejection", (e) => {
  const reason = e?.reason;
  const message = typeof reason === "string" ? reason : reason?.message || "처리되지 않은 Promise 오류";
  const detail = reason?.stack ? `스택: ${truncateText(reason.stack, 1500)}` : "";
  showFatalOverlay("fatal", "Promise 오류", String(message), detail);
});

function showMapNameToast(label) {
  const toast = ui.mapNameToast();
  const textEl = ui.mapNameToastText();
  if (!toast || !textEl) return;
  const text = String(label || "").trim();
  if (!text) return;

  textEl.textContent = text;
  toast.classList.remove("hidden");
  toast.classList.add("show");
  toast.setAttribute("aria-hidden", "false");

  if (state.mapToastTimerA) clearTimeout(state.mapToastTimerA);
  if (state.mapToastTimerB) clearTimeout(state.mapToastTimerB);

  state.mapToastTimerA = window.setTimeout(() => {
    toast.classList.remove("show");
    toast.setAttribute("aria-hidden", "true");
  }, 1800);

  state.mapToastTimerB = window.setTimeout(() => {
    if (!toast.classList.contains("show")) toast.classList.add("hidden");
  }, 2400);
}

function setDebugVisible(v) {
  const panel = ui.debugPanel();
  if (!panel) return;
  panel.classList.toggle("hidden", !v);
}

function closePokePanel() {
  const panel = ui.pokePanel();
  if (!panel) return;
  panel.classList.add("hidden");
  panel.setAttribute("aria-hidden", "true");
}

function openPokePanel(key) {
  const panel = ui.pokePanel();
  const title = ui.pokePanelTitle();
  const body = ui.pokePanelBody();
  if (!panel || !title || !body) return;

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

  title.textContent = titleMap[key] || "Menu";
  body.textContent = "준비중... (아이콘/기능은 나중에 연결)";
  panel.classList.remove("hidden");
  panel.setAttribute("aria-hidden", "false");
}

function partyIconUrl(species) {
  const s = String(species || "").trim();
  if (!s) return "";
  return `./assets/pokemon/${encodeURIComponent(s)}/icon.png`;
}

function setPartyHudCollapsed(collapsed) {
  state.partyCollapsed = !!collapsed;
  const hud = ui.partyHud();
  if (!hud) return;
  hud.classList.toggle("collapsed", state.partyCollapsed);
}

function renderPartyHud(party) {
  const slotsEl = ui.partySlots();
  if (!slotsEl) return;
  const list = Array.isArray(party) ? party : [];
  slotsEl.innerHTML = "";

  for (let i = 0; i < 6; i += 1) {
    const mon = list[i] || null;
    const slot = document.createElement("div");
    slot.className = "party-slot";

    const left = document.createElement("div");
    left.className = "party-slot-left";

    const iconWrap = document.createElement("div");
    iconWrap.className = "party-slot-icon";
    if (mon?.species) {
      const img = document.createElement("img");
      img.alt = mon.nickname || mon.species || `Slot ${i + 1}`;
      img.src = partyIconUrl(mon.species);
      img.onerror = () => img.remove();
      iconWrap.appendChild(img);
    }
    left.appendChild(iconWrap);

    const info = document.createElement("div");
    info.className = "party-slot-info";

    const name = document.createElement("div");
    name.className = "party-slot-name";
    name.textContent = mon ? `${mon.nickname || mon.species || `Slot ${i + 1}`} Lv.${mon.level ?? "?"}` : `Slot ${i + 1} #${i + 1}`;

    const hpRow = document.createElement("div");
    hpRow.className = "party-slot-hp";
    const hpBar = document.createElement("div");
    hpBar.className = "party-slot-hp-bar";
    const hpFill = document.createElement("span");
    const hpNow = Number(mon?.hp ?? 0);
    const hpMax = Math.max(1, Number(mon?.hpMax ?? 1));
    hpFill.style.width = `${Math.max(0, Math.min(100, Math.round((hpNow / hpMax) * 100)))}%`;
    hpBar.appendChild(hpFill);
    hpRow.appendChild(hpBar);

    info.appendChild(name);
    info.appendChild(hpRow);
    left.appendChild(info);
    slot.appendChild(left);
    slotsEl.appendChild(slot);
  }
}

function showOverworld() {
  ui.battlePane()?.classList.add("hidden");
  ui.overworldPane()?.classList.remove("hidden");
  ui.pokeHud()?.classList.remove("hidden");
  ui.partyHud()?.classList.remove("hidden");
}

function showBattle() {
  const frame = ui.battleFrame();
  if (frame && !state.battleLoaded) {
    const src = frame.getAttribute("data-src") || "./battle/battle.html";
    if (frame.getAttribute("src") !== src) frame.setAttribute("src", src);
    state.battleLoaded = true;
  }
  ui.overworldPane()?.classList.add("hidden");
  ui.battlePane()?.classList.remove("hidden");
  closePokePanel();
  ui.pokeHud()?.classList.add("hidden");
  ui.partyHud()?.classList.add("hidden");
}

function currentMapId() {
  const sel = ui.mapSelect();
  const value = String(sel?.value || sel?.options?.[sel.selectedIndex]?.value || "").trim();
  return value || "PalletTown";
}

async function loadMap(mapId, opts = {}) {
  if (!state.ow) throw new Error("오버월드가 아직 초기화되지 않았습니다.");
  setPretStatus(`로드중... (${mapId})`, false);
  await state.ow.loadPret(mapId, opts);
  setPretStatus(`OK (${state.ow.map?.map_id || mapId})`, true);
  updateZoomLabel();
  showMapNameToast(state.ow.map?.map_id || mapId);
}

async function boot({ forceReload = false } = {}) {
  if (state.booting) return;
  state.booting = true;
  closeFatalOverlay();

  try {
    const canvas = ensureCanvas();
    const playToken = sessionStorage.getItem("play_token") || "";

    if (!state.ow || forceReload) {
      state.ow = new Overworld({
        canvas,
        status: setStatus,
        playToken,
        apiBase: "../api",
        fixedZoom: 3.0,
        lockZoom: true,
      });
      window.__ow = state.ow;
    }

    await loadMap(currentMapId());
    if (!state.ow._started) state.ow.start();
    renderPartyHud([
      { species: "pikachu", nickname: "Pikachu", level: 5, hp: 20, hpMax: 20 },
    ]);
    setPartyHudCollapsed(false);
    setStatus("오버월드 로드 OK");
  } catch (err) {
    console.error(err);
    showFatalOverlay("fatal", "오버월드 로드 실패", err?.message || String(err), formatErrorDetail(err));
  } finally {
    state.booting = false;
  }
}

function bindUi() {
  ui.btnFatalRetry()?.addEventListener("click", () => window.location.reload());
  ui.btnFatalLogin()?.addEventListener("click", () => {
    try {
      sessionStorage.removeItem("play_token");
    } catch (_e) {
      // ignore
    }
    window.location.href = "./login.html";
  });

  ui.btnDbgToggle()?.addEventListener("click", () => {
    const panel = ui.debugPanel();
    setDebugVisible(panel?.classList.contains("hidden"));
  });
  ui.btnDbgHide()?.addEventListener("click", () => setDebugVisible(false));
  ui.btnDbgClear()?.addEventListener("click", () => {
    const log = ui.debugLog();
    if (log) log.textContent = "";
  });

  ui.btnOverworld()?.addEventListener("click", showOverworld);
  ui.btnBattle()?.addEventListener("click", showBattle);
  ui.btnMapReload()?.addEventListener("click", async () => {
    try {
      await loadMap(currentMapId(), { force: true });
    } catch (err) {
      console.error(err);
      showFatalOverlay("fatal", "맵 다시 불러오기 실패", err?.message || String(err), formatErrorDetail(err));
    }
  });

  ui.mapSelect()?.addEventListener("change", async (e) => {
    const mapId = String(e.target?.value || "").trim();
    if (!mapId) return;
    try {
      await loadMap(mapId);
    } catch (err) {
      console.error(err);
      showFatalOverlay("fatal", "맵 이동 실패", err?.message || String(err), formatErrorDetail(err));
    }
  });

  ui.btnZoomIn()?.addEventListener("click", () => {
    if (!state.ow) return;
    state.ow.setZoom((state.ow.zoom || 3) + (state.ow.zoomStep || 0.5), { user: true });
    updateZoomLabel();
  });
  ui.btnZoomOut()?.addEventListener("click", () => {
    if (!state.ow) return;
    state.ow.setZoom((state.ow.zoom || 3) - (state.ow.zoomStep || 0.5), { user: true });
    updateZoomLabel();
  });
  ui.btnZoomReset()?.addEventListener("click", () => {
    if (!state.ow) return;
    state.ow.resetZoom();
    updateZoomLabel();
  });

  ui.partyCollapse()?.addEventListener("click", () => setPartyHudCollapsed(!state.partyCollapsed));
  ui.pokePanelClose()?.addEventListener("click", closePokePanel);
  ui.pokeHud()?.addEventListener("click", (e) => {
    const btn = e.target?.closest?.(".pokebtn");
    if (!btn) return;
    openPokePanel(btn.getAttribute("data-pane") || "menu");
  });

  window.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closePokePanel();
    if (e.key === "F2") {
      e.preventDefault();
      const panel = ui.debugPanel();
      setDebugVisible(panel?.classList.contains("hidden"));
    }
  });
}

function init() {
  bindUi();
  updateZoomLabel();
  window.__setPartyHud = renderPartyHud;
  window.__showMapNameToast = showMapNameToast;
  boot().catch((err) => {
    console.error(err);
    showFatalOverlay("fatal", "부팅 실패", err?.message || String(err), formatErrorDetail(err));
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init, { once: true });
} else {
  init();
}
