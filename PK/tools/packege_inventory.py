#!/usr/bin/env python3
"""PK/tools/packege_inventory.py

Inventory current Packege dependencies before removing the Packege folder.
This script does not modify the project; it only scans the repository and emits
an actionable JSON report so the runtime can be migrated safely.

Typical usage:
  python tools/packege_inventory.py
  python tools/packege_inventory.py --json-out tools/packege_inventory_report.json
  python tools/packege_inventory.py --strict
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, Iterable, List, Sequence

TEXT_EXTENSIONS = {
    ".py", ".php", ".js", ".mjs", ".cjs", ".ts", ".tsx", ".jsx",
    ".html", ".htm", ".css", ".md", ".txt", ".json", ".inc",
    ".asm", ".h", ".hpp", ".c", ".cpp", ".sql", ".ini", ".yml", ".yaml",
}

IGNORE_DIR_NAMES = {
    ".git", "node_modules", "vendor", "__pycache__", ".idea", ".vscode"
}

CRITICAL_EXPORTS = {
    "public/pret/maps": "exported map json",
    "public/pret/tilesets": "exported map tileset png",
    "public/pret/sprites/player": "player sprite sheets",
    "public/pret/sprites/npc": "npc sprite sheets",
    "script/npc": "runtime npc/event script cache",
    "sql": "db bootstrap and seed files",
}

REPO_PATTERN_GROUPS = {
    "packege_path_refs": [
        re.compile(r"(?<![A-Za-z0-9_])Packege/", re.IGNORECASE),
        re.compile(r"(?<![A-Za-z0-9_])Packege\\", re.IGNORECASE),
        re.compile(r"\bpackege_root\s*\(", re.IGNORECASE),
    ],
    "pret_runtime_refs": [
        re.compile(r"public/pret", re.IGNORECASE),
        re.compile(r"\.\/pret\/", re.IGNORECASE),
        re.compile(r"/api/pret/", re.IGNORECASE),
        re.compile(r"api/pret/", re.IGNORECASE),
    ],
    "maps_info_seed_refs": [
        re.compile(r"maps_info", re.IGNORECASE),
        re.compile(r"gen_maps_info_sql", re.IGNORECASE),
    ],
    "sprite_pipeline_refs": [
        re.compile(r"object_events", re.IGNORECASE),
        re.compile(r"sprites?/player", re.IGNORECASE),
        re.compile(r"sprites?/npc", re.IGNORECASE),
    ],
    "battle_runtime_refs": [
        re.compile(r"battle/", re.IGNORECASE),
        re.compile(r"ref_move", re.IGNORECASE),
        re.compile(r"ref_species", re.IGNORECASE),
        re.compile(r"ref_item", re.IGNORECASE),
    ],
}


@dataclass
class MatchEntry:
    path: str
    line: int
    snippet: str


@dataclass
class DirSnapshot:
    exists: bool
    file_count: int
    sample: List[str]


@dataclass
class CriticalCheck:
    key: str
    label: str
    exists: bool
    file_count: int
    severity: str
    note: str


def project_root() -> Path:
    return Path(__file__).resolve().parent.parent


def iter_text_files(root: Path) -> Iterable[Path]:
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        if any(part in IGNORE_DIR_NAMES for part in path.parts):
            continue
        if path.suffix.lower() in TEXT_EXTENSIONS:
            yield path


def read_text(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        return path.read_text(encoding="utf-8", errors="ignore")


def short_snippet(text: str, limit: int = 160) -> str:
    text = " ".join(text.strip().split())
    if len(text) <= limit:
        return text
    return text[: limit - 3] + "..."


def find_matches(root: Path) -> Dict[str, List[MatchEntry]]:
    results: Dict[str, List[MatchEntry]] = {k: [] for k in REPO_PATTERN_GROUPS}
    for path in iter_text_files(root):
        rel = path.relative_to(root).as_posix()
        text = read_text(path)
        lines = text.splitlines()
        for line_no, line in enumerate(lines, start=1):
            for group, patterns in REPO_PATTERN_GROUPS.items():
                if any(p.search(line) for p in patterns):
                    results[group].append(MatchEntry(rel, line_no, short_snippet(line)))
    return results


def snapshot_dir(path: Path, limit: int = 8) -> DirSnapshot:
    if not path.exists() or not path.is_dir():
        return DirSnapshot(False, 0, [])
    files = sorted(p.relative_to(path).as_posix() for p in path.rglob("*") if p.is_file())
    return DirSnapshot(True, len(files), files[:limit])


def count_rows_in_sql(sql_path: Path, table_name: str) -> int:
    if not sql_path.exists():
        return 0
    text = read_text(sql_path)
    needle = f"INSERT INTO `{table_name}`"
    count = 0
    for line in text.splitlines():
        if needle in line:
            count += 1
    return count


def build_critical_checks(root: Path) -> List[CriticalCheck]:
    checks: List[CriticalCheck] = []
    for rel, label in CRITICAL_EXPORTS.items():
        snap = snapshot_dir(root / rel)
        severity = "critical" if rel.startswith("public/pret") or rel == "script/npc" else "important"
        note = "ready" if snap.exists and snap.file_count > 0 else "missing or empty"
        checks.append(CriticalCheck(rel, label, snap.exists, snap.file_count, severity, note))

    sql_file = root / "sql" / "pokemon_full_reset.sql"
    maps_info_seed_count = count_rows_in_sql(sql_file, "maps_info")
    checks.append(
        CriticalCheck(
            "sql/pokemon_full_reset.sql#maps_info",
            "maps_info seed rows in bootstrap sql",
            sql_file.exists(),
            maps_info_seed_count,
            "critical",
            "ready" if maps_info_seed_count > 0 else "maps_info INSERT not detected",
        )
    )
    return checks


def migration_order(root: Path) -> List[Dict[str, object]]:
    checks = {c.key: c for c in build_critical_checks(root)}
    pret_index_exists = (root / "public" / "pret" / "index.json").exists()
    return [
        {
            "step": 1,
            "title": "Freeze current export contract",
            "done": pret_index_exists,
            "details": [
                "public/pret/index.json presence should be confirmed before Packege removal",
                "map json + tilesets + sprite sheets must stay readable without Packege runtime access",
            ],
        },
        {
            "step": 2,
            "title": "Keep generated runtime assets complete",
            "done": all(checks[k].file_count > 0 for k in [
                "public/pret/maps", "public/pret/tilesets", "public/pret/sprites/player", "public/pret/sprites/npc", "script/npc"
            ] if k in checks),
            "details": [
                "public/pret/maps",
                "public/pret/tilesets",
                "public/pret/sprites/player",
                "public/pret/sprites/npc",
                "script/npc",
            ],
        },
        {
            "step": 3,
            "title": "Preserve DB bootstrap inputs",
            "done": checks.get("sql/pokemon_full_reset.sql#maps_info", CriticalCheck("", "", False, 0, "", "")).file_count > 0,
            "details": [
                "sql/pokemon_full_reset.sql must continue to seed maps_info or replacement sql must exist",
                "battle/reference tables must stay synchronized with DB bootstrap",
            ],
        },
        {
            "step": 4,
            "title": "Remove direct Packege path dependencies",
            "done": False,
            "details": [
                "all runtime code should read exports or DB only",
                "tools can keep Packege support temporarily, but public/api runtime should not require it",
            ],
        },
    ]


def summarize(matches: Dict[str, List[MatchEntry]]) -> Dict[str, Dict[str, object]]:
    out: Dict[str, Dict[str, object]] = {}
    for key, items in matches.items():
        out[key] = {
            "count": len(items),
            "sample": [entry.__dict__ for entry in items[:10]],
        }
    return out


def build_report(root: Path) -> Dict[str, object]:
    matches = find_matches(root)
    critical_checks = build_critical_checks(root)
    dir_snapshots = {
        rel: snapshot_dir(root / rel).__dict__
        for rel in [
            "Packege",
            "public/pret",
            "public/pret/maps",
            "public/pret/tilesets",
            "public/pret/sprites/player",
            "public/pret/sprites/npc",
            "script/npc",
            "sql",
            "tools",
        ]
    }

    warnings: List[str] = []
    for check in critical_checks:
        if check.severity == "critical" and check.file_count == 0:
            warnings.append(f"missing critical export: {check.key}")

    if summarize(matches)["packege_path_refs"]["count"] == 0:
        warnings.append("no direct Packege text references found; verify runtime dependencies manually")

    return {
        "project_root": root.as_posix(),
        "report_name": "packege_inventory",
        "summary": {
            "direct_packege_reference_files": len({m.path for m in matches["packege_path_refs"]}),
            "pret_runtime_reference_files": len({m.path for m in matches["pret_runtime_refs"]}),
            "critical_missing_count": sum(1 for c in critical_checks if c.severity == "critical" and c.file_count == 0),
            "warnings": warnings,
        },
        "directory_snapshots": dir_snapshots,
        "critical_checks": [c.__dict__ for c in critical_checks],
        "reference_matches": summarize(matches),
        "migration_order": migration_order(root),
    }


def parse_args(argv: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Inventory Packege dependencies before migration/removal.")
    parser.add_argument(
        "--root",
        type=Path,
        default=None,
        help="PK project root. Defaults to the parent of this script.",
    )
    parser.add_argument(
        "--json-out",
        type=Path,
        default=None,
        help="Optional path to write the JSON report.",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="Exit with code 1 when critical exports are missing.",
    )
    return parser.parse_args(argv)


def main(argv: Sequence[str]) -> int:
    args = parse_args(argv)
    root = args.root.resolve() if args.root else project_root()
    report = build_report(root)
    text = json.dumps(report, ensure_ascii=False, indent=2)

    if args.json_out:
        out_path = args.json_out if args.json_out.is_absolute() else root / args.json_out
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(text + "\n", encoding="utf-8")
        print(f"[ok] wrote report: {out_path}")
    else:
        print(text)

    if args.strict and report["summary"]["critical_missing_count"] > 0:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
