import { Overworld } from "./overworld.js";

const _origGroundAt = Overworld.prototype._groundAt;

function mod(n, m) {
  if (!m) return 0;
  return ((n % m) + m) % m;
}

function sampleBorderTile(map, x, y) {
  const border = map && map.border;
  const bw = border && Number.isFinite(border.w) ? (border.w | 0) : 0;
  const bh = border && Number.isFinite(border.h) ? (border.h | 0) : 0;
  const data = border && Array.isArray(border.data) ? border.data : null;
  const W = map && Number.isFinite(map.width) ? (map.width | 0) : 0;
  const H = map && Number.isFinite(map.height) ? (map.height | 0) : 0;

  if (!data || bw <= 0 || bh <= 0 || data.length < (bw * bh)) {
    return 0;
  }

  const rx = x < 0 ? x : (x >= W ? x - W : x);
  const ry = y < 0 ? y : (y >= H ? y - H : y);
  const bx = mod(rx, bw);
  const by = mod(ry, bh);
  return (data[(by * bw) + bx] ?? 0) | 0;
}

Overworld.prototype._groundAt = function patchedGroundAt(x, y) {
  const ix = x | 0;
  const iy = y | 0;

  if (!this.map) {
    return 0;
  }

  if (typeof this._resolveMapContextAt === "function") {
    const ctx = this._resolveMapContextAt(ix, iy);
    if (ctx && ctx.map) {
      const W = ctx.map.width | 0;
      const idx = ((ctx.y | 0) * W) + (ctx.x | 0);
      return (ctx.map.layers?.[0]?.data?.[idx] ?? 0) | 0;
    }
  }

  const fallback = sampleBorderTile(this.map, ix, iy);

  if (typeof _origGroundAt === "function") {
    try {
      const v = _origGroundAt.call(this, ix, iy);
      if (Number.isFinite(v) && (v | 0) !== 0) {
        return v | 0;
      }
    } catch (_err) {
      // Keep the border fallback; do not rethrow from a visual patch.
    }
  }

  return fallback;
};
