import { Overworld } from "./overworld.js";

const proto = Overworld && Overworld.prototype;

if (proto && !proto.__owMapStatePatch20260408b) {
  proto.__owMapStatePatch20260408b = true;

  const origLoadPret = typeof proto.loadPret === "function" ? proto.loadPret : null;
  const origWarpTo = typeof proto._warpTo === "function" ? proto._warpTo : null;
  const origGroundAt = typeof proto._groundAt === "function" ? proto._groundAt : null;
  const origResolveMapContextAt = typeof proto._resolveMapContextAt === "function" ? proto._resolveMapContextAt : null;
  const origEnsureNeighbor = typeof proto._ensureNeighbor === "function" ? proto._ensureNeighbor : null;
  const origResetMapTransientState = typeof proto._resetMapTransientState === "function" ? proto._resetMapTransientState : null;
  const origCommitLoadedMap = typeof proto._commitLoadedMap === "function" ? proto._commitLoadedMap : null;
  const origBuildMapAssets = typeof proto._buildMapAssets === "function" ? proto._buildMapAssets : null;
  const origEmitMapChange = typeof proto._emitMapChange === "function" ? proto._emitMapChange : null;
  const origSnapCameraToPlayer = typeof proto._snapCameraToPlayer === "function" ? proto._snapCameraToPlayer : null;
  const origPrefetchNeighbors = typeof proto._prefetchNeighbors === "function" ? proto._prefetchNeighbors : null;

  function num(v, d = 0) {
    return Number.isFinite(v) ? (v | 0) : d;
  }

  function inBounds(map, x, y) {
    if (!map) return false;
    const w = num(map.width, 0);
    const h = num(map.height, 0);
    return x >= 0 && y >= 0 && x < w && y < h;
  }

  function groundFromMap(map, x, y) {
    if (!map || !inBounds(map, x, y)) return 0;
    const w = num(map.width, 0);
    const layer = Array.isArray(map.layers) ? map.layers[0] : null;
    const data = Array.isArray(layer?.data) ? layer.data : [];
    const idx = (y | 0) * w + (x | 0);
    return num(data[idx], 0);
  }

  function currentAssets(ow) {
    return {
      tilesetImgs: Array.isArray(ow?.tilesetImgs) ? ow.tilesetImgs : [],
      tilesetImg: ow?.tilesetImg || null,
      tilesetUpperImgs: Array.isArray(ow?.tilesetUpperImgs) ? ow.tilesetUpperImgs : [],
      tilesetUpperImg: ow?.tilesetUpperImg || null,
      tilesetCols: num(ow?.tilesetCols, 16),
      tileSize: num(ow?.tileSize, 16),
      tileAnimFps: Number.isFinite(ow?._tileAnimFps) ? ow._tileAnimFps : 7.5,
    };
  }

  function normalizeDir(dir) {
    const d = String(dir || "").trim().toLowerCase();
    if (d === "left" || d === "west") return "left";
    if (d === "right" || d === "east") return "right";
    if (d === "up" || d === "north") return "up";
    if (d === "down" || d === "south") return "down";
    return "";
  }

  function mapSideForOob(map, x, y) {
    if (!map) return "";
    const w = num(map.width, 0);
    const h = num(map.height, 0);
    if (x < 0) return "left";
    if (x >= w) return "right";
    if (y < 0) return "up";
    if (y >= h) return "down";
    return "";
  }

  function getConnection(map, side) {
    const want = normalizeDir(side);
    if (!want || !Array.isArray(map?.connections)) return null;
    for (const c of map.connections) {
      if (normalizeDir(c?.direction) === want && String(c?.map_id || "").trim()) {
        return c;
      }
    }
    return null;
  }

  function isOutdoorLike(map) {
    return Array.isArray(map?.connections) && map.connections.some((c) => String(c?.map_id || "").trim());
  }

  function borderFromMap(map, x, y) {
    const bw = num(map?.border?.w, 0);
    const bh = num(map?.border?.h, 0);
    const data = Array.isArray(map?.border?.data) ? map.border.data : [];
    if (bw <= 0 || bh <= 0 || data.length <= 0) return 0;
    const bx = ((x % bw) + bw) % bw;
    const by = ((y % bh) + bh) % bh;
    return num(data[by * bw + bx], 0);
  }

  function maybeKickNeighborLoad(ow, mapId) {
    if (!mapId || !ow || typeof ow._ensureNeighbor !== "function") return;
    if (ow._neighborCache?.has?.(mapId) || ow._neighborPromises?.has?.(mapId)) return;
    try {
      Promise.resolve(ow._ensureNeighbor(mapId)).catch(() => {});
    } catch (_e) {}
  }

  function connectionContextFromCache(ow, conn, x, y) {
    const mapId = String(conn?.map_id || "").trim();
    if (!mapId) return null;
    const entry = ow?._neighborCache?.get?.(mapId);
    if (!entry?.map) {
      maybeKickNeighborLoad(ow, mapId);
      return null;
    }
    const cur = ow?.map;
    if (!cur) return null;

    const nMap = entry.map;
    const dir = normalizeDir(conn.direction);
    const off = num(conn.offset, 0);
    let nx = x | 0;
    let ny = y | 0;

    if (dir === "left") {
      nx = num(nMap.width, 0) + (x | 0);
      ny = (y | 0) - off;
    } else if (dir === "right") {
      nx = (x | 0) - num(cur.width, 0);
      ny = (y | 0) - off;
    } else if (dir === "up") {
      nx = (x | 0) - off;
      ny = num(nMap.height, 0) + (y | 0);
    } else if (dir === "down") {
      nx = (x | 0) - off;
      ny = (y | 0) - num(cur.height, 0);
    }

    if (!inBounds(nMap, nx, ny)) return null;
    return {
      map: nMap,
      x: nx,
      y: ny,
      assets: {
        tilesetImgs: Array.isArray(entry.tilesetImgs) ? entry.tilesetImgs : [],
        tilesetImg: entry.tilesetImg || null,
        tilesetUpperImgs: Array.isArray(entry.tilesetUpperImgs) ? entry.tilesetUpperImgs : [],
        tilesetUpperImg: entry.tilesetUpperImg || null,
        tilesetCols: num(entry.tilesetCols, 16),
        tileSize: num(entry.tileSize, 16),
        tileAnimFps: Number.isFinite(entry.tileAnimFps) ? entry.tileAnimFps : 7.5,
      },
    };
  }

  async function fetchPretEnvelope(ow, mapId) {
    const url = new URL(`${ow.apiBase}/pret/map.php`, window.location.href);
    url.searchParams.set("map", mapId);
    url.searchParams.set("_ow_refresh", `${Date.now()}`);
    const res = await fetch(url.toString(), { cache: "no-store", credentials: "same-origin" });
    if (!res.ok) throw new Error(`pret envelope load fail: ${mapId} (${res.status})`);
    const env = await res.json();
    if (!env?.ok) throw new Error(env?.err || `pret envelope load fail: ${mapId}`);
    return env;
  }

  async function fetchFreshMapJson(ow, env) {
    const rawUrl = String(env?.mapUrl || "").trim();
    if (!rawUrl) throw new Error(`mapUrl missing for ${env?.map || "?"}`);
    const url = new URL(rawUrl, window.location.href);
    url.searchParams.set("_ow_refresh", `${Date.now()}`);
    const res = await fetch(url.toString(), { cache: "no-store", credentials: "same-origin" });
    if (!res.ok) throw new Error(`map json load fail: ${env?.map || "?"} (${res.status})`);
    return await res.json();
  }

  function snapshotPlayer(ow) {
    const p = ow?.player || {};
    return {
      x: Number.isFinite(p.x) ? (p.x | 0) : null,
      y: Number.isFinite(p.y) ? (p.y | 0) : null,
      dir: Number.isFinite(p.dir) ? (p.dir | 0) : 0,
    };
  }

  function restorePlayer(ow, nextMap, snap) {
    if (!ow?.player) ow.player = { x: 0, y: 0, dir: 0, px: 0, py: 0, moving: false };
    const spawnX = Number.isFinite(nextMap?.spawn?.x) ? (nextMap.spawn.x | 0) : 0;
    const spawnY = Number.isFinite(nextMap?.spawn?.y) ? (nextMap.spawn.y | 0) : 0;
    ow.player.x = Number.isFinite(snap?.x) ? snap.x : spawnX;
    ow.player.y = Number.isFinite(snap?.y) ? snap.y : spawnY;
    ow.player.dir = Number.isFinite(snap?.dir) ? snap.dir : (Number.isFinite(nextMap?.spawn?.dir) ? (nextMap.spawn.dir | 0) : 0);
    ow.player.px = ow.player.x;
    ow.player.py = ow.player.y;
    ow.player.moving = false;
    ow._moveFrames = 0;
    ow._moveFramesTotal = 0;
    ow._movePx = 0;
    ow._moveDistPx = 0;
    ow._moveDirLocked = null;
    ow._queuedDir = null;
    ow._moveSecondsNow = null;
    ow._jumping = false;
  }

  async function forceRefreshCurrentMap(ow, reason) {
    const mapId = String(ow?.map?.map_id || "").trim();
    if (!mapId || !origBuildMapAssets || !origCommitLoadedMap) return false;

    const snap = snapshotPlayer(ow);
    const env = await fetchPretEnvelope(ow, mapId);
    const nextMap = await fetchFreshMapJson(ow, env);
    const assets = await origBuildMapAssets.call(ow, nextMap);

    if (typeof origResetMapTransientState === "function") {
      try {
        origResetMapTransientState.call(ow, { clearNeighbors: true });
      } catch (_e) {}
    }

    try { ow._neighborCache?.clear?.(); } catch (_e) {}
    try { ow._neighborPromises?.clear?.(); } catch (_e) {}
    try { ow._grassFx?.clear?.(); } catch (_e) {}
    ow._edgePending = null;
    ow._warpPending = false;
    ow._fishFx = null;

    origCommitLoadedMap.call(ow, nextMap, assets, { mapLabel: env?.label || nextMap?.label || nextMap?.meta?.label || null });
    restorePlayer(ow, nextMap, snap);

    if (typeof origSnapCameraToPlayer === "function") {
      try {
        origSnapCameraToPlayer.call(ow);
      } catch (_e) {}
    }

    if (typeof origPrefetchNeighbors === "function") {
      try {
        origPrefetchNeighbors.call(ow);
      } catch (_e) {}
    }

    if (typeof origEmitMapChange === "function") {
      try {
        origEmitMapChange.call(ow, reason || "full-refresh");
      } catch (_e) {}
    }

    try { if (typeof ow._fetchMobs === "function") await ow._fetchMobs(); } catch (_e) {}
    try { if (typeof ow._fetchItems === "function") await ow._fetchItems(); } catch (_e) {}
    try { if (typeof ow._fetchNpcs === "function") await ow._fetchNpcs(); } catch (_e) {}
    return true;
  }

  if (origEnsureNeighbor) {
    proto._ensureNeighbor = async function patchedEnsureNeighbor(mapId) {
      const id = String(mapId || "").trim();
      if (!id) return null;
      if (this._neighborCache?.has?.(id)) return this._neighborCache.get(id);
      if (this._neighborPromises?.has?.(id)) return this._neighborPromises.get(id);

      const pending = (async () => {
        const env = await fetchPretEnvelope(this, id);
        const nextMap = await fetchFreshMapJson(this, env);
        const assets = await origBuildMapAssets.call(this, nextMap);
        const entry = {
          map: nextMap,
          tileSize: num(assets?.tileSize, 16),
          tilesetCols: num(assets?.tilesetCols, 16),
          tileAnimFps: Number.isFinite(assets?.tileAnimFps) ? assets.tileAnimFps : 7.5,
          tilesetImgs: Array.isArray(assets?.tilesetImgs) ? assets.tilesetImgs : [],
          tilesetImg: assets?.tilesetImg || null,
          tilesetUpperImgs: Array.isArray(assets?.tilesetUpperImgs) ? assets.tilesetUpperImgs : [],
          tilesetUpperImg: assets?.tilesetUpperImg || null,
        };
        this._neighborCache.set(id, entry);
        return entry;
      })();

      this._neighborPromises.set(id, pending);
      try {
        return await pending;
      } finally {
        try { this._neighborPromises.delete(id); } catch (_e) {}
      }
    };
  }

  if (origResolveMapContextAt) {
    proto._resolveMapContextAt = function patchedResolveMapContextAt(mx, my) {
      const map = this?.map;
      if (!map) return null;

      if (inBounds(map, mx | 0, my | 0)) {
        return { map, x: mx | 0, y: my | 0, assets: currentAssets(this) };
      }

      try {
        const orig = origResolveMapContextAt.call(this, mx, my);
        if (orig?.map) return orig;
      } catch (_e) {}

      const side = mapSideForOob(map, mx | 0, my | 0);
      const conn = getConnection(map, side);
      if (!conn) return null;
      return connectionContextFromCache(this, conn, mx | 0, my | 0);
    };
  }

  if (origGroundAt) {
    proto._groundAt = function patchedGroundAt(mx, my) {
      const x = mx | 0;
      const y = my | 0;
      const map = this?.map;
      if (!map) return 0;

      try {
        const ctx = typeof this._resolveMapContextAt === "function" ? this._resolveMapContextAt(x, y) : null;
        if (ctx?.map && inBounds(ctx.map, ctx.x | 0, ctx.y | 0)) {
          return groundFromMap(ctx.map, ctx.x | 0, ctx.y | 0);
        }

        if (inBounds(map, x, y)) {
          return groundFromMap(map, x, y);
        }

        const side = mapSideForOob(map, x, y);
        const conn = getConnection(map, side);
        if (conn) {
          const edgeX = Math.max(0, Math.min(num(map.width, 1) - 1, x));
          const edgeY = Math.max(0, Math.min(num(map.height, 1) - 1, y));
          return groundFromMap(map, edgeX, edgeY);
        }

        if (isOutdoorLike(map)) {
          return borderFromMap(map, x, y);
        }
      } catch (_e) {}

      return origGroundAt.call(this, x, y);
    };
  }

  if (origLoadPret) {
    proto.loadPret = async function patchedLoadPret(mapId, opts = {}) {
      const result = await origLoadPret.call(this, mapId, opts);
      const reason = String(opts?.mapChangeReason || "loadPret").trim().toLowerCase();
      if (reason !== "warp") {
        try {
          await forceRefreshCurrentMap(this, reason ? `full:${reason}` : "full:loadPret");
        } catch (e) {
          try { this.status?.(`맵 전체 재로딩 실패: ${e?.message || String(e)}`); } catch (_e) {}
          try { this._log?.(`맵 전체 재로딩 실패: ${e?.message || String(e)}`); } catch (_e) {}
        }
      }
      return result;
    };
  }

  if (origWarpTo) {
    proto._warpTo = async function patchedWarpTo(w) {
      const result = await origWarpTo.call(this, w);
      try {
        await forceRefreshCurrentMap(this, "warp-full-refresh");
      } catch (e) {
        try { this.status?.(`워프 후 전체 재로딩 실패: ${e?.message || String(e)}`); } catch (_e) {}
        try { this._log?.(`워프 후 전체 재로딩 실패: ${e?.message || String(e)}`); } catch (_e) {}
      }
      return result;
    };
  }
}
