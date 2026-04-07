# -*- coding: utf-8 -*-
"""Poket cleanup GUI

- Shows common generated/cache/temp files.
- Lets you click to move selected files to tools/_trash (default) or permanently delete.

Safe-by-default: nothing is deleted until you press a button.
"""

import os
import sys
import fnmatch
import shutil
import time
from dataclasses import dataclass
from typing import List, Tuple, Dict

try:
    import tkinter as tk
    from tkinter import ttk, messagebox
except Exception as e:
    print('ERROR: tkinter not available:', e)
    print('Run with a Python that includes tkinter, or install it.')
    sys.exit(1)


def abspath_norm(p: str) -> str:
    return os.path.normpath(os.path.abspath(p))


SCRIPT_DIR = abspath_norm(os.path.dirname(__file__))
PROJECT_ROOT = abspath_norm(os.path.join(SCRIPT_DIR, '..'))
TRASH_DIR = abspath_norm(os.path.join(SCRIPT_DIR, '_trash'))


@dataclass
class Rule:
    key: str
    title: str
    desc: str
    # relative patterns from PROJECT_ROOT; supports ** via manual walk
    patterns: List[str]
    default_checked: bool = False


DEFAULT_RULES: List[Rule] = [
    Rule(
        key='pret_maps',
        title='PRET: public/pret/maps/*.json (generated map cache)',
        desc='Map JSON cache generated from Packege/pret. Safe to purge (will be regenerated).',
        patterns=['public/pret/maps/*.json'],
        default_checked=False,
    ),
    Rule(
        key='pret_tilesets',
        title='PRET: public/pret/tilesets/*.png (generated tileset cache)',
        desc='Tileset PNG cache generated from Packege/pret. Safe to purge (will be regenerated).',
        patterns=['public/pret/tilesets/*.png'],
        default_checked=False,
    ),
    Rule(
        key='pret_lo_up',
        title='PRET: *__lo.png / *__up.png (occlusion split tilesets)',
        desc='If you want to re-generate the split upper/lower tilesets again.',
        patterns=['public/pret/tilesets/*__lo.png', 'public/pret/tilesets/*__up.png'],
        default_checked=False,
    ),
    Rule(
        key='pycache',
        title='Python __pycache__ (safe)',
        desc='Removes compiled python cache folders.',
        patterns=['**/__pycache__/**'],
        default_checked=True,
    ),
    Rule(
        key='temp_files',
        title='Temp/lock files (~$, *.tmp, *.bak, *.swp, Thumbs.db, .DS_Store)',
        desc='Editor/OS temporary files.',
        patterns=[
            '**/~$*', '**/*.tmp', '**/*.bak', '**/*.swp', '**/*.swo',
            '**/Thumbs.db', '**/.DS_Store'
        ],
        default_checked=True,
    ),
    Rule(
        key='npc_cache',
        title='cache/npc/*.json (generated index)',
        desc='NPC cache index. Safe to purge if you rebuild NPC cache.',
        patterns=['cache/npc/*.json'],
        default_checked=False,
    ),
    Rule(
        key='db_generated',
        title='db/generated/*.csv (generated reference tables)',
        desc='Only purge if you can regenerate these CSVs.',
        patterns=['db/generated/*.csv'],
        default_checked=False,
    ),
    Rule(
        key='battle_html',
        title='public/battle/battle.html (optional)',
        desc='If you do NOT want EmulatorJS battle iframe at all, you can remove this file.',
        patterns=['public/battle/battle.html'],
        default_checked=False,
    ),
]


def iter_all_paths(root: str) -> List[str]:
    out = []
    for base, dirs, files in os.walk(root):
        for d in dirs:
            out.append(os.path.join(base, d))
        for f in files:
            out.append(os.path.join(base, f))
    return out


def match_patterns(root: str, patterns: List[str]) -> List[str]:
    """Return matching paths (files or dirs), absolute paths."""
    root = abspath_norm(root)
    all_paths = iter_all_paths(root)

    matched = set()
    for pat in patterns:
        pat = pat.replace('\\', '/')
        # Build absolute-ish pattern by joining and then normalizing separators for fnmatch.
        # We'll compare using forward-slash.
        if pat.startswith('/'):
            pat_rel = pat[1:]
        else:
            pat_rel = pat
        pat_abs = abspath_norm(os.path.join(root, pat_rel))
        pat_abs_fs = pat_abs.replace('\\', '/')

        for p in all_paths:
            p_abs = abspath_norm(p)
            p_fs = p_abs.replace('\\', '/')
            if fnmatch.fnmatchcase(p_fs, pat_abs_fs):
                matched.add(p_abs)

    # For "**/__pycache__/**"-style, the pattern may match files/dirs beneath.
    # Also collapse: if a directory is matched, we don't need every child listed.
    matched_list = sorted(matched)

    # Collapse children under matched dirs
    dirs = [p for p in matched_list if os.path.isdir(p)]
    collapsed = []
    for p in matched_list:
        skip = False
        for d in dirs:
            if p != d and p.startswith(d + os.sep):
                skip = True
                break
        if not skip:
            collapsed.append(p)
    return collapsed


def path_size_bytes(p: str) -> int:
    try:
        if os.path.isfile(p):
            return os.path.getsize(p)
        total = 0
        for base, dirs, files in os.walk(p):
            for f in files:
                fp = os.path.join(base, f)
                try:
                    total += os.path.getsize(fp)
                except OSError:
                    pass
        return total
    except OSError:
        return 0


def fmt_size(n: int) -> str:
    # human readable
    units = ['B', 'KB', 'MB', 'GB']
    v = float(n)
    for u in units:
        if v < 1024.0 or u == units[-1]:
            if u == 'B':
                return f"{int(v)} {u}"
            return f"{v:.1f} {u}"
        v /= 1024.0
    return f"{int(n)} B"


def ensure_dir(p: str) -> None:
    os.makedirs(p, exist_ok=True)


def rel_to_root(p: str) -> str:
    try:
        return os.path.relpath(p, PROJECT_ROOT)
    except Exception:
        return p


def open_in_explorer(path: str) -> None:
    path = abspath_norm(path)
    try:
        if sys.platform.startswith('win'):
            os.startfile(path)  # type: ignore
        elif sys.platform == 'darwin':
            import subprocess
            subprocess.Popen(['open', path])
        else:
            import subprocess
            subprocess.Popen(['xdg-open', path])
    except Exception:
        pass


class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title('Poket Cleaner (click-to-delete)')
        self.geometry('980x720')
        self.minsize(920, 640)

        self.rules = DEFAULT_RULES
        self.rule_vars: Dict[str, tk.BooleanVar] = {}
        self.rule_stats: Dict[str, Tuple[int, int]] = {}  # key -> (count, bytes)
        self.rule_matches: Dict[str, List[str]] = {}

        self._build_ui()
        self.refresh_scan()

    def _build_ui(self):
        top = ttk.Frame(self, padding=10)
        top.pack(side='top', fill='x')

        lbl = ttk.Label(top, text=f'Project root: {PROJECT_ROOT}')
        lbl.pack(side='left')

        btn_open = ttk.Button(top, text='Open project folder', command=lambda: open_in_explorer(PROJECT_ROOT))
        btn_open.pack(side='right')

        main = ttk.Frame(self, padding=(10, 0, 10, 10))
        main.pack(side='top', fill='both', expand=True)

        left = ttk.Frame(main)
        left.pack(side='left', fill='y')

        right = ttk.Frame(main)
        right.pack(side='right', fill='both', expand=True)

        # Rules list
        ttk.Label(left, text='Cleanup groups').pack(anchor='w')

        self.rules_box = ttk.Frame(left)
        self.rules_box.pack(fill='y', expand=False)

        for r in self.rules:
            var = tk.BooleanVar(value=r.default_checked)
            self.rule_vars[r.key] = var
            row = ttk.Frame(self.rules_box)
            row.pack(fill='x', pady=2)
            cb = ttk.Checkbutton(row, variable=var, command=self._update_preview)
            cb.pack(side='left')
            title = ttk.Label(row, text=r.title)
            title.pack(side='left', padx=6)

        # Buttons
        btns = ttk.Frame(left, padding=(0, 10, 0, 0))
        btns.pack(fill='x')

        ttk.Button(btns, text='Refresh scan', command=self.refresh_scan).pack(fill='x', pady=2)
        ttk.Button(btns, text='Move selected to tools/_trash (recommended)', command=self.move_to_trash).pack(fill='x', pady=2)
        ttk.Button(btns, text='PERMANENT DELETE selected (danger)', command=self.permanent_delete).pack(fill='x', pady=2)

        ttk.Separator(left, orient='horizontal').pack(fill='x', pady=10)

        self.status_var = tk.StringVar(value='Ready')
        ttk.Label(left, textvariable=self.status_var, foreground='#444').pack(anchor='w')

        # Preview list
        ttk.Label(right, text='Preview (selected groups)').pack(anchor='w')
        self.preview = tk.Text(right, wrap='none')
        self.preview.pack(fill='both', expand=True)

        # Scrollbars
        yscroll = ttk.Scrollbar(right, orient='vertical', command=self.preview.yview)
        yscroll.pack(side='right', fill='y')
        self.preview.configure(yscrollcommand=yscroll.set)

        xscroll = ttk.Scrollbar(self, orient='horizontal', command=self.preview.xview)
        xscroll.pack(side='bottom', fill='x')
        self.preview.configure(xscrollcommand=xscroll.set)

    def refresh_scan(self):
        self.status_var.set('Scanning...')
        self.update_idletasks()

        self.rule_matches.clear()
        self.rule_stats.clear()

        for r in self.rules:
            matches = match_patterns(PROJECT_ROOT, r.patterns)
            # filter: don't include this cleaner itself or trash dir
            safe_matches = []
            for m in matches:
                m_norm = abspath_norm(m)
                if m_norm.startswith(TRASH_DIR + os.sep):
                    continue
                # never delete packege_sync.py automatically
                if abspath_norm(os.path.join(SCRIPT_DIR, 'packege_sync.py')) == m_norm:
                    continue
                safe_matches.append(m_norm)

            self.rule_matches[r.key] = safe_matches
            total_bytes = sum(path_size_bytes(p) for p in safe_matches)
            self.rule_stats[r.key] = (len(safe_matches), total_bytes)

        self._update_rule_titles_with_stats()
        self._update_preview()
        self.status_var.set('Scan complete')

    def _update_rule_titles_with_stats(self):
        # rebuild the rule list with counts/sizes
        for w in list(self.rules_box.winfo_children()):
            w.destroy()

        for r in self.rules:
            var = self.rule_vars[r.key]
            cnt, b = self.rule_stats.get(r.key, (0, 0))
            row = ttk.Frame(self.rules_box)
            row.pack(fill='x', pady=2)
            cb = ttk.Checkbutton(row, variable=var, command=self._update_preview)
            cb.pack(side='left')
            title = f"{r.title}   [{cnt} items, {fmt_size(b)}]"
            lbl = ttk.Label(row, text=title)
            lbl.pack(side='left', padx=6)

    def _update_preview(self):
        lines = []
        total_cnt = 0
        total_b = 0

        for r in self.rules:
            if not self.rule_vars[r.key].get():
                continue
            matches = self.rule_matches.get(r.key, [])
            cnt, b = self.rule_stats.get(r.key, (0, 0))
            total_cnt += cnt
            total_b += b

            lines.append('=' * 90)
            lines.append(r.title)
            lines.append(f"{r.desc}")
            lines.append(f"Items: {cnt}   Size: {fmt_size(b)}")
            for p in matches[:5000]:
                lines.append(' - ' + rel_to_root(p))
            if len(matches) > 5000:
                lines.append(f"... ({len(matches) - 5000} more)")

        lines.append('=' * 90)
        lines.append(f"TOTAL selected: {total_cnt} items, {fmt_size(total_b)}")
        lines.append('')
        lines.append('Tip: default action is MOVE to tools/_trash (safe). You can restore manually.')

        self.preview.delete('1.0', 'end')
        self.preview.insert('1.0', '\n'.join(lines))

    def _collect_selected_paths(self) -> List[str]:
        selected = []
        for r in self.rules:
            if self.rule_vars[r.key].get():
                selected.extend(self.rule_matches.get(r.key, []))
        # de-duplicate while keeping order
        seen = set()
        out = []
        for p in selected:
            p = abspath_norm(p)
            if p in seen:
                continue
            seen.add(p)
            out.append(p)
        # collapse children under selected dirs
        dirs = [p for p in out if os.path.isdir(p)]
        collapsed = []
        for p in out:
            skip = False
            for d in dirs:
                if p != d and p.startswith(d + os.sep):
                    skip = True
                    break
            if not skip:
                collapsed.append(p)
        return collapsed

    def move_to_trash(self):
        paths = self._collect_selected_paths()
        if not paths:
            messagebox.showinfo('Nothing selected', 'No files matched / selected.')
            return

        ensure_dir(TRASH_DIR)
        stamp = time.strftime('%Y%m%d_%H%M%S')
        dest_root = abspath_norm(os.path.join(TRASH_DIR, stamp))
        ensure_dir(dest_root)

        msg = f"Move {len(paths)} item(s) to tools/_trash/{stamp}?\n\n" \
              f"(Safe: files are NOT permanently deleted.)"
        if not messagebox.askyesno('Confirm move to trash', msg):
            return

        moved = 0
        for p in paths:
            try:
                rel = rel_to_root(p)
                dest = abspath_norm(os.path.join(dest_root, rel))
                ensure_dir(os.path.dirname(dest))
                # move file/dir
                shutil.move(p, dest)
                moved += 1
            except Exception:
                # best-effort
                pass

        messagebox.showinfo('Done', f'Moved {moved} item(s) to:\n{dest_root}')
        self.refresh_scan()

    def permanent_delete(self):
        paths = self._collect_selected_paths()
        if not paths:
            messagebox.showinfo('Nothing selected', 'No files matched / selected.')
            return

        msg = f"PERMANENTLY DELETE {len(paths)} item(s)?\n\nThis cannot be undone." \
              f"\n\nRecommended: use Move to _trash instead."
        if not messagebox.askyesno('Confirm permanent delete', msg):
            return

        deleted = 0
        for p in paths:
            try:
                if os.path.isdir(p):
                    shutil.rmtree(p)
                else:
                    os.remove(p)
                deleted += 1
            except Exception:
                pass

        messagebox.showinfo('Done', f'Deleted {deleted} item(s).')
        self.refresh_scan()


def main():
    # quick CLI
    if '--scan' in sys.argv:
        for r in DEFAULT_RULES:
            m = match_patterns(PROJECT_ROOT, r.patterns)
            b = sum(path_size_bytes(p) for p in m)
            print(f"{r.key}: {len(m)} items, {fmt_size(b)}")
        return

    app = App()
    app.mainloop()


if __name__ == '__main__':
    main()
