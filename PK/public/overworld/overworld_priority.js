export function isTallGrassBehavior(b) {
  return b === 0x02 || b === 0xD1 || b === 0x49 || b === 0x4A || b === 0x48;
}

function getMapFlag(map, key, x, y) {
  const W = map?.width | 0;
  const H = map?.height | 0;
  const arr = map?.[key];
  if (!Array.isArray(arr) || arr.length !== W * H) return null;
  if (x < 0 || y < 0 || x >= W || y >= H) return 0;
  return arr[y * W + x] ? 1 : 0;
}

export function getFrontCoverAt(map, x, y) {
  return getMapFlag(map, "front_cover", x, y);
}

export function getFrontOccluderAt(map, x, y) {
  const direct = getMapFlag(map, "front_occluder", x, y);
  if (direct !== null) return direct;
  const south = getFrontCoverAt(map, x, y + 1);
  return south !== null ? south : 0;
}

export function getGrassCoverAt(map, x, y, behaviorAt = null) {
  const direct = getMapFlag(map, "grass_cover", x, y);
  if (direct !== null) return direct;
  const b = (typeof behaviorAt === "function") ? (behaviorAt(x, y) | 0) : 0;
  return isTallGrassBehavior(b) ? 1 : 0;
}

function stableTileCoord(v) {
  const n = Number.isFinite(v) ? v : 0;
  return Math.floor(n + 0.0001);
}

export function getActorPriorityState({
  map,
  renderX,
  renderY,
  dir = 0,
  moving = false,
  behaviorAt = null,
  grassCoverAt = null,
  frontOccluderAt = null,
} = {}) {
  const footX = stableTileCoord(renderX);
  const footY = stableTileCoord(renderY);
  const inGrass = (typeof grassCoverAt === "function")
    ? !!grassCoverAt(footX, footY)
    : !!getGrassCoverAt(map, footX, footY, behaviorAt);
  const hasFrontOccluder = (typeof frontOccluderAt === "function")
    ? !!frontOccluderAt(footX, footY)
    : !!getFrontOccluderAt(map, footX, footY);

  return {
    footX,
    footY,
    sortRow: footY,
    grassCover: inGrass ? { x: footX, y: footY } : null,
    southOccluder: (!inGrass && hasFrontOccluder) ? { x: footX, y: footY } : null,
  };
}
