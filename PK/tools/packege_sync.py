#!/usr/bin/env python3
# tools/packege_sync.py
# Packege -> runtime sync (single-file tool)
#
# 기본(권장):
#   python tools/packege_sync.py
#   -> script/npc/common/{npc,event}/*.npc + public/pret/sprites/npc/*.png 생성/갱신
#
# 옵션:
#   python tools/packege_sync.py --ver fr
#   python tools/packege_sync.py npc --ver lg --no-clean

from __future__ import annotations
import argparse
import json
import re
import shutil
from pathlib import Path
from typing import Dict, List, Tuple, Optional, Set

# ---------- Paths ----------

def project_root() -> Path:
    # tools/packege_sync.py -> project root
    return Path(__file__).resolve().parent.parent

def packege_root(root: Path) -> Path:
    return root / "Packege"

def maps_root(packege: Path) -> Path:
    return packege / "data" / "maps"

def norm_ver(ver: str) -> str:
    ver = (ver or "common").strip().lower()
    if ver == "all":
        return "all"
    if not re.match(r"^[a-z0-9_]+$", ver):
        return "common"
    return ver or "common"

def out_script_dir(root: Path, ver: str, section: str) -> Path:
    ver = norm_ver(ver)
    section = (section or "npc").strip().lower()
    if section not in ("npc", "event"):
        section = "npc"
    return root / "script" / "npc" / ver / section

def out_sprite_dir(root: Path) -> Path:
    return root / "public" / "pret" / "sprites" / "npc"

def packege_sprite_dirs(packege: Path) -> List[Path]:
    base = packege / "graphics" / "object_events" / "pics"
    return [base / "people", base / "misc", base / "pokemon"]

def packege_text_dirs(packege: Path) -> List[Path]:
    return [packege / "data" / "text"]

# ---------- Text parsing (.inc) ----------

_LABEL_RE = re.compile(r"^\s*([A-Za-z0-9_]+)::\s*$")
_STRING_RE = re.compile(r'^\s*\.string\s+\"(.*)\"\s*$')

def _decode_asm_string(raw: str) -> str:
    # FRLG text escapes:
    #  \n newline, \l newline, \" quote, \\ backslash
    #  backslash-p is treated as page-break marker (we convert to \f)
    out: List[str] = []
    i = 0
    while i < len(raw):
        ch = raw[i]
        if ch == "\\" and i + 1 < len(raw):
            nx = raw[i + 1]
            if nx == "n":
                out.append("\n"); i += 2; continue
            if nx == "l":
                out.append("\n"); i += 2; continue
            if nx == "p":
                out.append("\f"); i += 2; continue
            if nx == "\\":
                out.append("\\"); i += 2; continue
            if nx == '"':
                out.append('"'); i += 2; continue
            out.append(nx); i += 2; continue
        out.append(ch)
        i += 1
    return "".join(out)

def parse_text_inc(path: Path) -> Dict[str, List[str]]:
    '''Return label -> pages (pages split by page-break marker).'''
    if not path.exists():
        return {}
    label: Optional[str] = None
    buf: List[str] = []
    out: Dict[str, List[str]] = {}

    for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        m = _LABEL_RE.match(line)
        if m:
            if label is not None:
                joined = "".join(buf)
                pages = [p for p in joined.split("\f") if p != ""]
                out[label] = pages if pages else [joined]
            label = m.group(1)
            buf = []
            continue

        m2 = _STRING_RE.match(line)
        if m2 and label is not None:
            s = _decode_asm_string(m2.group(1))
            if "$" in s:
                s = s.split("$", 1)[0]
            buf.append(s)

    if label is not None:
        joined = "".join(buf)
        pages = [p for p in joined.split("\f") if p != ""]
        out[label] = pages if pages else [joined]

    return out

def parse_global_text(packege: Path) -> Dict[str, List[str]]:
    out: Dict[str, List[str]] = {}
    for d in packege_text_dirs(packege):
        if not d.exists():
            continue
        for p in sorted(d.glob("*.inc")):
            for k, v in parse_text_inc(p).items():
                out.setdefault(k, v)
    return out

# ---------- Script parsing (scripts.inc) ----------

_BLOCK_START_RE = re.compile(r"^\s*([A-Za-z0-9_]+)::\s*$")
_MSGBOX_RE = re.compile(r"\bmsgbox\s+([A-Za-z0-9_]+)\b")

def parse_scripts_inc_for_msgboxes(path: Path) -> Dict[str, List[str]]:
    '''Return script_label -> list of msgbox text labels (appearance order).'''
    if not path.exists():
        return {}
    out: Dict[str, List[str]] = {}
    cur_label: Optional[str] = None
    for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        m = _BLOCK_START_RE.match(line)
        if m:
            cur_label = m.group(1)
            out.setdefault(cur_label, [])
            continue
        if not cur_label:
            continue
        for m2 in _MSGBOX_RE.finditer(line):
            out[cur_label].append(m2.group(1))
    return out


def map_has_frlg_conditional(scripts_inc: Path) -> bool:
    """Detect FR/LG compile conditionals inside scripts.inc.
    We only treat FIRERED/LEAFGREEN macros as version split signals.
    """
    try:
        txt = scripts_inc.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        return False
    return re.search(r"(?m)^\s*\.ifn?def\s+(FIRERED|LEAFGREEN)\b", txt) is not None

# ---------- NPC generation ----------

def _safe_name(name: str) -> str:
    name = name.strip()
    name = re.sub(r"[^A-Za-z0-9_]", "_", name)
    name = re.sub(r"_+", "_", name).strip("_")
    return name or "NPC"

def _sprite_key_from_gfx(gfx: str) -> str:
    gfx = (gfx or "").strip()
    if gfx.startswith("OBJ_EVENT_GFX_"):
        return gfx[len("OBJ_EVENT_GFX_"):].lower()
    return gfx.lower()

def _copy_sprite_if_exists(packege: Path, sprite_key: str, dst_dir: Path) -> bool:
    if not sprite_key:
        return False
    dst_dir.mkdir(parents=True, exist_ok=True)
    dst = dst_dir / f"{sprite_key}.png"
    if dst.exists():
        return True
    for sdir in packege_sprite_dirs(packege):
        src = sdir / f"{sprite_key}.png"
        if src.exists():
            shutil.copy2(src, dst)
            return True
    return False

def _emit_mes_block(lines: List[str], indent: str = "  ") -> str:
    out = []
    for ln in lines:
        ln = ln.replace('"', "'").rstrip()
        if ln:
            out.append(f'{indent}mes "{ln}";')
    return "\n".join(out)

def _pages_to_lines(pages: List[str]) -> List[List[str]]:
    out: List[List[str]] = []
    for p in pages:
        p = p.replace("\r\n", "\n").replace("\r", "\n")
        out.append([ln.rstrip() for ln in p.split("\n")])
    return out

def generate_map_npc_files(map_name: str, map_dir: Path, packege: Path, global_text: Dict[str, List[str]]) -> Tuple[str, str, Set[str]]:
    '''Generate (npc_content, event_content, used_sprite_keys) for one map.'''
    map_json = map_dir / "map.json"
    if not map_json.exists():
        return "", "", set()

    m = json.loads(map_json.read_text(encoding="utf-8", errors="ignore"))
    scripts_inc = map_dir / "scripts.inc"
    text_inc = map_dir / "text.inc"

    text_map: Dict[str, List[str]] = dict(global_text)
    text_map.update(parse_text_inc(text_inc))

    script_msg = parse_scripts_inc_for_msgboxes(scripts_inc)

    used_sprites: Set[str] = set()
    header = [
        "// GENERATED by tools/packege_sync.py",
        f"// source: Packege/data/maps/{map_name}/map.json (+ scripts.inc + text.inc + data/text/*.inc)",
        "",
    ]

    npc_lines: List[str] = list(header)
    event_lines: List[str] = list(header)

    # --- Object NPCs (visible) ---
    for idx, ev in enumerate(m.get("object_events", []) or []):
        script_label = str(ev.get("script", "")).strip()
        if script_label in ("", "0x0", "0"):
            continue

        local_id = str(ev.get("local_id", f"OBJ_{idx}"))
        npc_name = _safe_name(local_id.replace("LOCALID_", "").replace(f"{map_name}_", ""))
        x = int(ev.get("x", 0))
        y = int(ev.get("y", 0))

        gfx = str(ev.get("graphics_id", "")).strip()
        sprite_key = _sprite_key_from_gfx(gfx) or "npc_placeholder"
        used_sprites.add(sprite_key)

        npc_lines.append(f"{map_name},{x},{y},0\t{sprite_key}\tscript\t{npc_name}\t0,{{")
        npc_lines.append(f"  // packege: object_events[{idx}] local_id={local_id} gfx={gfx}")
        npc_lines.append(f"  // gba_script: {script_label}")

        msg_labels = script_msg.get(script_label, [])
        # 복잡 스크립트는 msgbox 섞여서 이상해지므로 1개만 사용
        if len(msg_labels) > 1:
            msg_labels = msg_labels[:1]

        pages_collected: List[List[str]] = []
        for tlabel in msg_labels:
            pages = text_map.get(tlabel)
            if pages:
                pages_collected.extend(_pages_to_lines(pages))

        if not pages_collected:
            npc_lines.append("  // UNSUPPORTED: msgbox/text not resolved")
            npc_lines.append('  mes "(아직 미지원 스크립트)";')
            npc_lines.append("  close;")
            npc_lines.append("}")
            npc_lines.append("")
            continue

        for pi, page_lines in enumerate(pages_collected):
            npc_lines.append(_emit_mes_block(page_lines, indent="  "))
            if pi != len(pages_collected) - 1:
                npc_lines.append("  next;")
        npc_lines.append("  close;")
        npc_lines.append("}")
        npc_lines.append("")

    # --- BG events (signposts) -> event ---
    for idx, ev in enumerate(m.get("bg_events", []) or []):
        et = str(ev.get("type", "")).strip().lower()
        if et not in ("sign", "bg_event_type_sign", "0"):
            continue
        script_label = str(ev.get("script", "")).strip()
        if script_label in ("", "0x0", "0"):
            continue
        x = int(ev.get("x", 0))
        y = int(ev.get("y", 0))
        evt_name = f"SIGN_{idx+1}"

        event_lines.append(f"{map_name},{x},{y},0 script {evt_name} 0,{{")
        event_lines.append(f"  // packege: bg_events[{idx}] type={et}")
        event_lines.append(f"  // gba_script: {script_label}")

        msg_labels = script_msg.get(script_label, [])
        if len(msg_labels) > 1:
            msg_labels = msg_labels[:1]

        pages_collected: List[List[str]] = []
        for tlabel in msg_labels:
            pages = text_map.get(tlabel)
            if pages:
                pages_collected.extend(_pages_to_lines(pages))

        if not pages_collected:
            event_lines.append("  // UNSUPPORTED: msgbox/text not resolved")
            event_lines.append('  mes "(아직 미지원 스크립트)";')
            event_lines.append("  close;")
            event_lines.append("}")
            event_lines.append("")
            continue

        for pi, page_lines in enumerate(pages_collected):
            event_lines.append(_emit_mes_block(page_lines, indent="  "))
            if pi != len(pages_collected) - 1:
                event_lines.append("  next;")
        event_lines.append("  close;")
        event_lines.append("}")
        event_lines.append("")

    npc_content = "\n".join(npc_lines).rstrip() + "\n" if len(npc_lines) > len(header) else ""
    event_content = "\n".join(event_lines).rstrip() + "\n" if len(event_lines) > len(header) else ""
    return npc_content, event_content, used_sprites

def _is_generated_file(p: Path) -> bool:
    try:
        head = p.read_text(encoding="utf-8", errors="ignore").splitlines()[:5]
    except Exception:
        return False
    return any("GENERATED by tools/packege_sync.py" in ln for ln in head)

def _clean_generated_dir(d: Path) -> int:
    if not d.exists():
        return 0
    removed = 0
    for f in d.glob("*.npc"):
        if f.is_file() and _is_generated_file(f):
            try:
                f.unlink()
                removed += 1
            except Exception:
                pass
    return removed

def sync_npcs(ver: str = "common", clean: bool = True) -> int:
    root = project_root()
    packege = packege_root(root)
    maps = maps_root(packege)

    if not packege.exists():
        print(f"[ERR] Packege not found: {packege}")
        return 2
    if not maps.exists():
        print(f"[ERR] Packege maps not found: {maps}")
        return 2

    ver = norm_ver(ver)
    if ver == "all":
        rc = 0
        for v in ("common", "fr", "lg"):
            rc = rc or sync_npcs(ver=v, clean=clean)
        return rc

    # Output dirs (common + optional FR/LG overrides)
    out_dirs: Dict[str, Tuple[Path, Path]] = {}
    base_vers: List[str] = [ver]
    if ver == "common":
        base_vers += ["fr", "lg"]
    # de-dup while preserving order
    seen: Set[str] = set()
    for v in base_vers:
        if v in seen:
            continue
        seen.add(v)
        nd = out_script_dir(root, v, "npc")
        ed = out_script_dir(root, v, "event")
        nd.mkdir(parents=True, exist_ok=True)
        ed.mkdir(parents=True, exist_ok=True)
        out_dirs[v] = (nd, ed)

    if clean:
        for (nd, ed) in out_dirs.values():
            _clean_generated_dir(nd)
            _clean_generated_dir(ed)


    spr_dir = out_sprite_dir(root)
    spr_dir.mkdir(parents=True, exist_ok=True)

    # UI text-window frames (FR/LG) for in-game message boxes
    ui_dir = root / "public" / "assets" / "gba_ui"
    ui_dir.mkdir(parents=True, exist_ok=True)
    tw_fr = packege / "graphics" / "text_window" / "type3.png"
    tw_lg = packege / "graphics" / "text_window" / "type4.png"
    if tw_fr.exists():
        shutil.copy2(tw_fr, ui_dir / "text_window_fr.png")
    if tw_lg.exists():
        shutil.copy2(tw_lg, ui_dir / "text_window_lg.png")


    global_text = parse_global_text(packege)

    total_maps = 0
    npc_written = 0
    evt_written = 0
    used_sprites_all: Set[str] = set()

    for map_dir in sorted(maps.iterdir()):
        if not map_dir.is_dir():
            continue
        map_name = map_dir.name
        npc_content, evt_content, used_sprites = generate_map_npc_files(map_name, map_dir, packege, global_text)
        total_maps += 1
        used_sprites_all |= used_sprites

        scripts_inc = map_dir / "scripts.inc"
        split_frlg = map_has_frlg_conditional(scripts_inc)
        dest_vers: List[str] = [ver]
        # If Packege script uses FIRERED/LEAFGREEN conditionals, emit version-specific scripts only
        if ver == "common" and split_frlg:
            dest_vers = ["fr", "lg"]

        for dv in dest_vers:
            nd, ed = out_dirs.get(dv, (out_script_dir(root, dv, "npc"), out_script_dir(root, dv, "event")))
            if dv not in out_dirs:
                nd.mkdir(parents=True, exist_ok=True)
                ed.mkdir(parents=True, exist_ok=True)
                out_dirs[dv] = (nd, ed)

            if npc_content.strip():
                (nd / f"{map_name}.npc").write_text(npc_content, encoding="utf-8")
                npc_written += 1
            if evt_content.strip():
                (ed / f"{map_name}.npc").write_text(evt_content, encoding="utf-8")
                evt_written += 1


    copied = 0
    missing = 0
    for sk in sorted(used_sprites_all):
        if _copy_sprite_if_exists(packege, sk, spr_dir):
            copied += 1
        else:
            missing += 1

    print(f"[OK] ver={ver} maps_scanned={total_maps} npc_files={npc_written} event_files={evt_written}")
    print(f"[OK] sprites_needed={len(used_sprites_all)} sprites_copied={copied} sprites_missing={missing}")
    return 0

def main(argv: Optional[List[str]] = None) -> int:
    ap = argparse.ArgumentParser(add_help=True)
    ap.add_argument("--ver", default="common", help="script/npc/<ver>/... (default: common). 'all' = common+fr+lg")
    ap.add_argument("--no-clean", action="store_true", help="Do not delete previously generated files (generated-only)")

    sub = ap.add_subparsers(dest="cmd")
    sub.add_parser("npc", help="Generate rAthena-style NPC+EVENT scripts from Packege/data/maps/*")

    args = ap.parse_args(argv)

    # default: run npc sync
    if args.cmd in (None, "npc"):
        return sync_npcs(ver=getattr(args, "ver", "common"), clean=(not getattr(args, "no_clean", False)))

    ap.print_help()
    return 1

if __name__ == "__main__":
    raise SystemExit(main())
