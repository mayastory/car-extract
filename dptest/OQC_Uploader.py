#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""OQC DB AUTO UPLOADER (NO CLI OPTIONS) + CMM(IR/ZC) FAST XML

- OQC (OMM) xlsx/xlsm: 기존처럼 ZIP/XML 스트리밍 파싱 후 DB 업로드
- CMM (IR BASE / Z CARRIER) xlsm: 동일하게 ZIP/XML 파싱 (openpyxl 사용 안함)
  * IR BASE: sheet='OQC Raw data', data starts at E2
  * Z CARRIER: sheet='OQC RawData',  data starts at G4
  * CMM 값은 숫자만 저장, 그 외/공백은 NULL
  * OQC 파일이 있는 날짜만 업로드 (CMM만 있고 OQC 없으면 스킵)
  * 상태파일로 재실행해도 다시 업로드하지 않음 (OQC와 동일)

※ 이 스크립트는 CLI 옵션을 받지 않습니다.
"""

from __future__ import annotations

import json
import os
import re
import sys
import time
import zipfile
import hashlib
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple

try:
    import pymysql
except Exception:
    pymysql = None

import xml.etree.ElementTree as ET


# ----------------------------
# Exceptions
# ----------------------------


class EmptyOQCData(RuntimeError):
    """OQC 시트에 수식은 있으나 실제 측정 데이터가 비어있는 케이스.

    기준: 첫 데이터행(11행)의 AK/AL/AM(FAI 3칸)이 모두 비어있으면
    해당 파일은 '데이터 없음'으로 간주하여 OQC/CMM 모두 업로드하지 않는다.
    """


# ==========================================================
# USER SETTINGS (여기만 수정)
# ==========================================================

DB_HOST = "dpams.ddns.net"      # 예: "192.168.1.136"
DB_PORT = 3306
DB_USER = "maya"
DB_PASS = "##Gmlakd2323"      # 비번
DB_NAME = "dp"
DB_CHARSET = "utf8mb4"

# ✅ 스캔 시작 루트(여기 아래를 전부 훑어서 OQC 파일 찾음)
BASE_DIRS = [
    r"X:\\3.측정실\\Memphis(25)\\2.0\\12. OQC",
    r"\\\\192.168.1.136\\3.측정실\\Memphis(25)\\2.0\\12. OQC",
    r"\\\\192.168.1.136\\품질\\3.측정실\\Memphis(25)\\2.0\\12. OQC",
]

SCAN_YEARS = [2025, 2026]

EXTENSIONS = {".xlsx", ".xlsm"}
EXCLUDE_PREFIXES = ("~$",)  # 엑셀 임시파일은 무조건 제외 (로그에도 안 보이게)
EXCLUDE_NAMES = {".DS_Store"}

WATCH_INTERVAL_SEC = 120

ALLOW_MTIME_DATE_FALLBACK = False
DELETE_DUPLICATES = True
BULK_CHUNK_ROWS = 600

_THIS_DIR = Path(__file__).resolve().parent
STATE_FILE = str(_THIS_DIR / "oqc_db_uploader_state.json")
LOG_FILE = str(_THIS_DIR / "oqc_db_uploader.log")

# CMM에 실제 숫자 데이터가 없는 파일(CMM EMPTY) 처리
# - True: CMM EMPTY 로그를 숨김(추천)
SUPPRESS_CMM_EMPTY_LOG = True
# - True: CMM EMPTY도 상태파일에 기록하여 WATCH에서 반복 재시도하지 않음(추천)
MARK_CMM_EMPTY_IN_STATE = True

# ==========================================================


# ----------------------------
# Utilities
# ----------------------------

def now_str() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


LOG_TAG = "OQC"

def log(msg: str) -> None:
    line = f"[{now_str()}]	[{LOG_TAG}]	{msg}"
    print(line)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass


def ensure_text(v: Any) -> str:
    if v is None:
        return ""
    if isinstance(v, bytes):
        return v.decode("utf-8", errors="replace")
    return str(v)


def load_state(path: Path) -> Dict[str, Any]:
    if path.exists():
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except Exception as e:
            # 깨진 json이면 백업해 두고 새로 시작
            try:
                bak = path.with_suffix(path.suffix + ".bad")
                bak.write_text(path.read_text(encoding="utf-8", errors="ignore"), encoding="utf-8")
            except Exception:
                pass
            try:
                log(f"[STATE] invalid json -> reset ({e})")
            except Exception:
                pass
            return {}
    return {}


def save_state(path: Path, state: Dict[str, Any]) -> None:
    try:
        tmp = path.with_suffix(path.suffix + ".tmp")
        tmp.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
        tmp.replace(path)
    except Exception as e:
        try:
            log(f"[STATE] save failed: {e} (path={path})")
        except Exception:
            pass


def file_signature(p: Path) -> str:
    st = p.stat()
    return f"{st.st_size}:{int(st.st_mtime)}"


def should_exclude_file(name: str) -> bool:
    if name in EXCLUDE_NAMES:
        return True
    for pfx in EXCLUDE_PREFIXES:
        if name.startswith(pfx):
            return True
    return False


# ----------------------------
# Filename parsing
# ----------------------------

def detect_part_name_from_text(text: str) -> str:
    lower = text.lower()
    if "ir base" in lower or "ir_base" in lower or "irbase" in lower or "ir base" in lower:
        return "MEM-IR-BASE"
    if "x carrier" in lower or "x_carrier" in lower or "xcarrier" in lower:
        return "MEM-X-CARRIER"
    if "y carrier" in lower or "y_carrier" in lower or "ycarrier" in lower:
        return "MEM-Y-CARRIER"
    if "z carrier" in lower or "z_carrier" in lower or "zcarrier" in lower:
        return "MEM-Z-CARRIER"
    if "z stopper" in lower or "z_stopper" in lower or "zstopper" in lower:
        return "MEM-Z-STOPPER"
    return text


def detect_part_for_key(p: Path) -> str:
    """State/dedupe용 part 판정.

    - 파일명 기준으로 먼저 판정(경로가 X:/UNC로 달라도 동일)
    - 그래도 못 찾으면 전체 경로로 한번 더 시도
    - 최종 실패 시 'UNKNOWN'으로 고정 (경로 문자열을 그대로 part로 쓰지 않음)
    """
    # 1) filename first (stable across X:/UNC)
    v = detect_part_name_from_text(p.name)
    if v != p.name:
        return v

    # 2) path fallback (some 폴더명에만 모델명이 있는 경우)
    vp = detect_part_name_from_text(str(p))
    if vp != str(p):
        return vp

    return "UNKNOWN"


def detect_lot_date_from_filename(filename: str) -> Optional[str]:
    base = Path(filename).stem

    m8 = re.findall(r"(?:^|_)(\d{8})(?=_|$)", base)
    if m8:
        cand = m8[-1]
        yyyy, mm, dd = int(cand[0:4]), int(cand[4:6]), int(cand[6:8])
        return f"{yyyy:04d}-{mm:02d}-{dd:02d}"

    m6 = re.findall(r"(?:^|_)(\d{6})(?=_|$)", base)
    if m6:
        cand = m6[-1]
        yy, mm, dd = int(cand[0:2]), int(cand[2:4]), int(cand[4:6])
        return f"20{yy:02d}-{mm:02d}-{dd:02d}"

    return None


# ✅ OQC 허용 파일명: ..._YYMMDD.xlsx 또는 ..._YYMMDD_1.xlsx (8자리도 동일)
_allowed_oqc_name_re = re.compile(r"^(?P<stem>.+?)_(?P<d6>\d{6}|\d{8})(?P<sfx>_1)?$", re.I)

def is_allowed_oqc_name(p: Path) -> bool:
    stem = p.stem
    # 끝이 '_' 로 끝나면 (예: _250527_) 바로 제외
    if stem.endswith("_"):
        return False
    m = _allowed_oqc_name_re.match(stem)
    if not m:
        return False
    sfx = m.group("sfx")
    # _1만 허용, _2 등은 match 자체가 안 됨
    return True

# ✅ CMM 허용 파일명: ..._YYMMDD_OQC.xlsm 또는 ..._YYMMDD_1_OQC.xlsm (8자리도 동일)
_allowed_cmm_name_re = re.compile(r"^(?P<stem>.+?)_(?P<d6>\d{6}|\d{8})(?P<sfx>_1)?_OQC$", re.I)

def is_allowed_cmm_name(p: Path) -> bool:
    stem = p.stem
    if stem.endswith('_'):
        return False
    return _allowed_cmm_name_re.match(stem) is not None



# ----------------------------
# Excel XML parsing
# ----------------------------

NS_MAIN = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
NS_REL = "http://schemas.openxmlformats.org/package/2006/relationships"
NS_R = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"

_cell_ref_re = re.compile(r"^([A-Z]+)(\d+)$", re.I)


def col_letters_to_index(letters: str) -> int:
    letters = letters.upper()
    n = 0
    for ch in letters:
        if not ("A" <= ch <= "Z"):
            return 0
        n = n * 26 + (ord(ch) - 64)
    return n


def idx_to_letters(idx: int) -> str:
    s = ""
    while idx > 0:
        m = (idx - 1) % 26
        s = chr(65 + m) + s
        idx = (idx - 1) // 26
    return s


def cell_ref_to_col_row(ref: str) -> Tuple[int, int]:
    m = _cell_ref_re.match(ref or "")
    if not m:
        return 0, 0
    col = col_letters_to_index(m.group(1))
    row = int(m.group(2))
    return col, row


def excel_serial_to_date(serial: float) -> datetime:
    base = datetime(1899, 12, 30)
    days = int(serial)
    return base + timedelta(days=days)


def parse_kor_mmdd_to_date(s: str, year: int) -> Optional[str]:
    s = s.strip()
    if not s:
        return None

    m = re.match(r"^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$", s)
    if m:
        y, mo, d = int(m.group(1)), int(m.group(2)), int(m.group(3))
        return f"{y:04d}-{mo:02d}-{d:02d}"

    m = re.match(r"^\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일\s*$", s)
    if m:
        mo, d = int(m.group(1)), int(m.group(2))
        return f"{year:04d}-{mo:02d}-{d:02d}"

    return None


def parse_meas_date_value(raw: Any, year: int) -> Optional[str]:
    if raw is None:
        return None

    if isinstance(raw, datetime):
        return raw.strftime("%Y-%m-%d")

    if isinstance(raw, (int, float)):
        return excel_serial_to_date(float(raw)).strftime("%Y-%m-%d")

    s = ensure_text(raw).strip()
    if not s:
        return None

    if re.fullmatch(r"\d+(?:\.\d+)?", s):
        try:
            return excel_serial_to_date(float(s)).strftime("%Y-%m-%d")
        except Exception:
            return None

    return parse_kor_mmdd_to_date(s, year)


def to_float_or_none(v: Any) -> Optional[float]:
    if v is None:
        return None
    if isinstance(v, (int, float)):
        return float(v)
    s = ensure_text(v).strip().replace(",", "").replace(" ", "")
    if not s:
        return None
    try:
        return float(s)
    except Exception:
        return None


def normalize_point_no_string(s: str) -> str:
    s = s.strip()
    if not s:
        return ""
    if not re.fullmatch(r"-?\d+(?:\.\d+)?", s):
        return s
    if "." in s:
        frac = s.split(".", 1)[1]
        if len(frac) > 6:
            try:
                f = float(s)
                return f"{f:.10f}".rstrip("0").rstrip(".")
            except Exception:
                return s
    return s


def _local(tag: str) -> str:
    return tag.split("}", 1)[1] if "}" in tag else tag


def load_shared_strings(zf: zipfile.ZipFile) -> List[str]:
    try:
        with zf.open("xl/sharedStrings.xml") as f:
            strings: List[str] = []
            buf: List[str] = []
            in_si = False
            for event, elem in ET.iterparse(f, events=("start", "end")):
                name = _local(elem.tag)
                if event == "start" and name == "si":
                    in_si = True
                    buf = []
                elif event == "end" and name == "t" and in_si:
                    if elem.text:
                        buf.append(elem.text)
                elif event == "end" and name == "si":
                    strings.append("".join(buf))
                    in_si = False
                    elem.clear()
            return strings
    except KeyError:
        return []


def find_sheet_entry_by_name(zf: zipfile.ZipFile, sheet_name: str) -> str:
    wb_xml = zf.read("xl/workbook.xml")
    rels_xml = zf.read("xl/_rels/workbook.xml.rels")

    wb = ET.fromstring(wb_xml)

    def _norm(n: str) -> str:
        n = (n or "").lower()
        n = re.sub(r"[^a-z0-9]+", "", n)
        return n

    desired = sheet_name
    desired_norm = _norm(desired)

    sheets: list[tuple[str, str]] = []  # (name, rid)
    rid = None

    for sheet in wb.findall(f".//{{{NS_MAIN}}}sheet"):
        nm = sheet.attrib.get("name") or ""
        r = sheet.attrib.get(f"{{{NS_R}}}id")
        if not r:
            continue
        sheets.append((nm, r))
        if nm == desired:
            rid = r
            break

    if not rid:
        # case-insensitive exact
        for nm, r in sheets:
            if nm.lower() == desired.lower():
                rid = r
                break

    if not rid:
        # normalized match (spaces/underscores differences)
        for nm, r in sheets:
            if _norm(nm) == desired_norm:
                rid = r
                break

    if not rid:
        # heuristic: if desired contains 'raw', pick a sheet that contains 'oqc' and 'raw'
        if "raw" in desired_norm:
            cand = []
            for nm, r in sheets:
                nn = _norm(nm)
                if "oqc" in nn and "raw" in nn:
                    cand.append((nm, r))
            if not cand:
                for nm, r in sheets:
                    nn = _norm(nm)
                    if "raw" in nn:
                        cand.append((nm, r))
            if cand:
                rid = cand[0][1]

    if not rid:
        avail = ", ".join([nm for nm, _ in sheets])
        raise RuntimeError(f'"{sheet_name}" 시트를 workbook.xml에서 찾을 수 없습니다. (available: {avail})')

    rels = ET.fromstring(rels_xml)
    target = None
    for rel in rels.findall(f".//{{{NS_REL}}}Relationship"):
        if rel.attrib.get("Id") == rid:
            target = rel.attrib.get("Target")
            break
    if not target:
        raise RuntimeError(f"rels에서 {rid} 타겟을 찾을 수 없습니다.")

    entry = "xl/" + target.lstrip("/")
    if entry not in zf.namelist():
        raise RuntimeError(f"시트 파일을 ZIP에서 찾을 수 없습니다: {entry}")
    return entry


def find_oqc_sheet_entry(zf: zipfile.ZipFile) -> str:
    return find_sheet_entry_by_name(zf, "OQC")


def read_cell_value(c_elem: ET.Element, shared: List[str]) -> Tuple[Any, bool, bool]:
    t = c_elem.attrib.get("t", "")

    f_elem = None
    v_elem = None
    inline_is = None

    for ch in c_elem:
        name = _local(ch.tag)
        if name == "f":
            f_elem = ch
        elif name == "v":
            v_elem = ch
        elif name == "is":
            inline_is = ch

    is_formula = f_elem is not None
    v_text = v_elem.text if v_elem is not None else None

    if t == "s":
        idx = int(v_text) if (v_text is not None and re.fullmatch(r"-?\d+", v_text.strip())) else -1
        return (shared[idx] if 0 <= idx < len(shared) else ""), is_formula, False

    if t == "inlineStr":
        parts: List[str] = []
        if inline_is is not None:
            for tnode in inline_is.findall(f".//{{{NS_MAIN}}}t"):
                if tnode.text:
                    parts.append(tnode.text)
        return "".join(parts), is_formula, False

    if t == "b":
        return (1 if (v_text or "").strip() == "1" else 0), is_formula, False

    if is_formula:
        if v_text is None or v_text.strip() == "":
            return None, True, True
        return v_text, True, False

    return v_text, False, False


@dataclass
class MetaScan:
    row10: Dict[int, str]
    row9: Dict[int, str]
    date4: Dict[int, Any]   # jmeas_date (AK4)
    date5: Dict[int, Any]   # meas_date2 (AK5)
    date6: Dict[int, Any]   # meas_date  (AK6)
    result_start_col: int
    usl_col: Optional[int]
    lsl_col: Optional[int]
    missing_cache: int


def meta_scan_sheet(zf: zipfile.ZipFile, sheet_entry: str, shared: List[str]) -> MetaScan:
    row10: Dict[int, str] = {}
    row9: Dict[int, str] = {}
    date4: Dict[int, Any] = {}
    date5: Dict[int, Any] = {}
    date6: Dict[int, Any] = {}
    missing_cache = 0

    with zf.open(sheet_entry) as f:
        for event, elem in ET.iterparse(f, events=("end",)):
            if _local(elem.tag) != "c":
                continue

            ref = elem.attrib.get("r", "")
            col, row = cell_ref_to_col_row(ref)
            if row in (4, 5, 6, 9, 10):
                val, _is_formula, miss = read_cell_value(elem, shared)
                if miss:
                    missing_cache += 1
                if row == 10:
                    row10[col] = ensure_text(val).strip()
                elif row == 9:
                    row9[col] = ensure_text(val).strip()
                elif row == 6:
                    date6[col] = val
                elif row == 5:
                    date5[col] = val
                elif row == 4:
                    date4[col] = val

            elem.clear()

    # result_start_col: row10에서 'FAI'가 처음 나오는 컬럼 (측정영역 끝+1)
    result_start_col = 0
    for c, s in row10.items():
        if s.strip().upper() == "FAI":
            result_start_col = int(c)
            break
    if not result_start_col:
        raise RuntimeError("row10에서 'FAI' 컬럼을 찾지 못함(템플릿 확인 필요)")

    usl_col: Optional[int] = None
    lsl_col: Optional[int] = None

    for rowmap in (row10, row9):
        if usl_col is None:
            for c, s in rowmap.items():
                u = s.strip().upper()
                if u == "USL" or "USL" in u:
                    usl_col = int(c)
                    break
        if lsl_col is None:
            for c, s in rowmap.items():
                u = s.strip().upper()
                if u == "LSL" or "LSL" in u:
                    lsl_col = int(c)
                    break

    return MetaScan(
        row10=row10,
        row9=row9,
        date4=date4,
        date5=date5,
        date6=date6,
        result_start_col=result_start_col,
        usl_col=usl_col,
        lsl_col=lsl_col,
        missing_cache=missing_cache,
    )


@dataclass
class ParsedFile:
    part_name: str
    ship_date: str
    lot_date: str
    source_file: str
    cols_scanned: int
    cols_considered: int
    total_rows_used: int
    missing_cache: int
    columns: List[Dict[str, Any]]
    point_no_by_row: Dict[int, str]
    spc_by_row: Dict[int, str]
    usl_by_row: Dict[int, float]
    lsl_by_row: Dict[int, float]


def parse_oqc_xlsx(file_path: Path) -> ParsedFile:
    source_file = file_path.name

    part_name = detect_part_name_from_text(str(file_path))
    if part_name == str(file_path):
        part_name = detect_part_name_from_text(source_file)

    lot_date = detect_lot_date_from_filename(source_file)
    if not lot_date:
        if ALLOW_MTIME_DATE_FALLBACK:
            dt = datetime.fromtimestamp(file_path.stat().st_mtime)
            lot_date = dt.strftime("%Y-%m-%d")
        else:
            raise RuntimeError(f"파일명에서 날짜(YYMMDD/ YYYYMMDD)를 찾지 못함: {source_file}")

    ship_date = lot_date
    year = int(lot_date[:4])

    with zipfile.ZipFile(file_path, "r") as zf:
        sheet_entry = find_oqc_sheet_entry(zf)
        shared = load_shared_strings(zf)

        meta = meta_scan_sheet(zf, sheet_entry, shared)

        first_data_row = 11
        first_data_col = col_letters_to_index("AK")
        measure_end_col = int(meta.result_start_col) - 1
        if measure_end_col < first_data_col:
            raise RuntimeError("측정 컬럼 범위가 비정상입니다(AK ~ FAI-1).")

        point_no_by_row: Dict[int, str] = {}
        spc_by_row: Dict[int, str] = {}
        usl_by_row: Dict[int, float] = {}
        lsl_by_row: Dict[int, float] = {}

        meas_by_col: Dict[int, List[Tuple[int, float]]] = {}
        filled_by_col: Dict[int, int] = {}
        last_data_row_used = 0
        last_point_row_seen = 0

        # ✅ 데이터 존재 여부 판정(요구사항)
        #    - AK11/AL11/AM11(FAI 3칸) 중 "하나라도" 비어있으면:
        #      아직 측정 데이터가 다 안 들어온 상태로 보고 OQC/CMM 둘 다 업로드하지 않는다.
        sentinel_cols = {first_data_col, first_data_col + 1, first_data_col + 2}
        sentinel_row = first_data_row
        sentinel_ok = {c: False for c in sentinel_cols}

        missing_cache = meta.missing_cache

        with zf.open(sheet_entry) as f:
            for event, elem in ET.iterparse(f, events=("end",)):
                if _local(elem.tag) != "c":
                    continue

                ref = elem.attrib.get("r", "")
                col, row = cell_ref_to_col_row(ref)

                if row < 11 or row > 2000:
                    elem.clear()
                    continue

                val, _is_formula, miss = read_cell_value(elem, shared)
                if miss:
                    missing_cache += 1

                if col == 2:  # B point
                    s = normalize_point_no_string(ensure_text(val))
                    if s:
                        point_no_by_row[row] = s
                        if row > last_point_row_seen:
                            last_point_row_seen = row
                    elem.clear()
                    continue

                if col == 3:  # C spc_code
                    s = ensure_text(val).strip()
                    if s:
                        spc_by_row[row] = s
                    elem.clear()
                    continue

                if meta.usl_col and col == meta.usl_col:
                    fval = to_float_or_none(val)
                    if fval is not None:
                        usl_by_row[row] = fval
                    elem.clear()
                    continue

                if meta.lsl_col and col == meta.lsl_col:
                    fval = to_float_or_none(val)
                    if fval is not None:
                        lsl_by_row[row] = fval
                    elem.clear()
                    continue

                if first_data_col <= col <= measure_end_col:
                    fval = to_float_or_none(val)

                    # ✅ 첫 데이터행(11행) FAI 3칸(AK/AL/AM)이 모두 채워졌는지 체크
                    if row == sentinel_row and col in sentinel_cols and fval is not None:
                        sentinel_ok[col] = True

                    if fval is not None:
                        meas_by_col.setdefault(col, []).append((row, fval))
                        filled_by_col[col] = filled_by_col.get(col, 0) + 1
                        if row > last_data_row_used:
                            last_data_row_used = row

                elem.clear()

        # ✅ 상단 첫 데이터행(11행) FAI 3칸이 하나라도 비어있으면: 미완성으로 스킵
        if not all(sentinel_ok.values()):
            missing_cols = [idx_to_letters(c) + str(sentinel_row) for c in sorted(sentinel_ok) if not sentinel_ok[c]]
            raise EmptyOQCData(f"FAI 3칸({', '.join(missing_cols)}) 중 비어있음(미완성)")


        last_point_row = last_data_row_used if last_data_row_used > 0 else last_point_row_seen
        if last_point_row < first_data_row:
            last_point_row = first_data_row
        total_rows = max(1, last_point_row - first_data_row + 1)

        columns: List[Dict[str, Any]] = []
        cols_scanned = max(0, measure_end_col - first_data_col + 1)

        for col_idx, pairs in meas_by_col.items():
            filled = filled_by_col.get(col_idx, 0)
            if filled <= 0:
                continue

            tool_cavity = ensure_text(meta.row10.get(col_idx, "")).strip() or "(UNKNOWN)"

            # ✅ 날짜는 분리 저장
            meas_date = parse_meas_date_value(meta.date6.get(col_idx), year)      # AK6
            meas_date2 = parse_meas_date_value(meta.date5.get(col_idx), year)    # AK5
            jmeas_date = parse_meas_date_value(meta.date4.get(col_idx), year)    # AK4

            slot_idx = int(col_idx) - int(first_data_col)  # 0=AK
            kind = "FAI" if 0 <= slot_idx < 3 else "SPC"
            excel_col = idx_to_letters(int(col_idx))

            columns.append(
                {
                    "col_idx": int(col_idx),
                    "tool_cavity": tool_cavity,
                    "meas_date": meas_date,
                    "meas_date2": meas_date2,
                    "jmeas_date": jmeas_date,
                    "kind": kind,
                    "excel_col": excel_col,
                    "pairs": pairs,
                    "filled": filled,
                    "ratio": (filled / total_rows) if total_rows else 0.0,
                }
            )

        # ✅ tool column order 고정
        columns.sort(key=lambda d: int(d.get("col_idx", 0)))

        return ParsedFile(
            part_name=part_name,
            ship_date=ship_date,
            lot_date=lot_date,
            source_file=source_file,
            cols_scanned=cols_scanned,
            cols_considered=len(meas_by_col),
            total_rows_used=total_rows,
            missing_cache=missing_cache,
            columns=columns,
            point_no_by_row=point_no_by_row,
            spc_by_row=spc_by_row,
            usl_by_row=usl_by_row,
            lsl_by_row=lsl_by_row,
        )


# ----------------------------
# DB
# ----------------------------

def db_connect():
    if pymysql is None:
        raise RuntimeError("pymysql이 없습니다. 먼저: pip install pymysql")

    # NOTE:
    # - WATCH 모드로 오래 켜두면(수시간~) MySQL이 idle connection을 끊어서
    #   "MySQL server has gone away"(2006/2013/0) 가 날 수 있음.
    # - connect/read/write timeout을 주고, 실제 업로드 직전에 ping+reconnect를 수행한다.
    return pymysql.connect(
        host=DB_HOST,
        port=int(DB_PORT),
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset=DB_CHARSET,
        autocommit=False,
        cursorclass=pymysql.cursors.Cursor,
        connect_timeout=8,
        read_timeout=120,
        write_timeout=120,
    )


def _db_err_code(e: Exception) -> int:
    try:
        if hasattr(e, "args") and e.args:
            return int(e.args[0])
    except Exception:
        pass
    return -1


def is_db_conn_lost(e: Exception) -> bool:
    """True if error looks like dropped mysql connection."""
    code = _db_err_code(e)
    if code in (0, 2006, 2013, 2055):
        return True
    s = str(e).lower()
    if "server has gone away" in s:
        return True
    if "lost connection" in s:
        return True
    if "connectionreseterror" in s:
        return True
    if "forcibly closed" in s:
        return True
    if "강제로 끊겼" in s:
        return True
    return False


def ensure_conn_alive(conn):
    """ping(reconnect=True) 로 연결 살아있는지 보장. 필요 시 재연결."""
    if conn is None:
        return db_connect(), True
    try:
        # pymysql은 ping(reconnect=True) 지원
        conn.ping(reconnect=True)
        return conn, False
    except Exception:
        try:
            conn.close()
        except Exception:
            pass
        return db_connect(), True




# ----------------------------
# CMM helpers (missing in older builds)
# ----------------------------

def ensure_cmm_row_table(conn) -> None:
    """Create cmm_row table if it does not exist. No-op if already exists."""
    try:
        with conn.cursor() as cur:
            cur.execute("SHOW TABLES LIKE 'cmm_row'")
            r = cur.fetchone()
            exists = bool(r)
            if exists:
                return
            # Minimal, flexible schema (supports both 'date' and legacy 'meas_date' columns)
            cur.execute("""
CREATE TABLE IF NOT EXISTS cmm_row (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    header_id BIGINT NOT NULL,
    model_name VARCHAR(64) NULL,
    part_name VARCHAR(64) NULL,
    date DATE NULL,
    meas_date DATE NULL,
    tool_index INT NOT NULL,
    row_index INT NOT NULL,
    val_num DOUBLE NULL,
    tool_cavity VARCHAR(64) NULL,
    src_file VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_header_id (header_id),
    KEY idx_model_date (model_name, date),
    KEY idx_part_date (part_name, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """.strip())
    except Exception:
        # If schema creation fails (permissions, etc.), let the caller handle downstream errors.
        return


def detect_cmm_schema(conn) -> Tuple[str, str]:
    """Return (name_col, date_col) for cmm_row based on existing columns."""
    cols = table_columns(conn, "cmm_row")
    # name column
    if cols.get("model_name"):
        name_col = "model_name"
    elif cols.get("part_name"):
        name_col = "part_name"
    elif cols.get("name"):
        name_col = "name"
    else:
        # fallback to model_name (may fail if truly absent)
        name_col = "model_name"
    # date column
    if cols.get("date"):
        date_col = "date"
    elif cols.get("meas_date"):
        date_col = "meas_date"
    elif cols.get("ship_date"):
        date_col = "ship_date"
    else:
        date_col = "date"
    return name_col, date_col


def bulk_insert(cur, sql_prefix: str, rows: List[Tuple[Any, ...]], cols_per_row: int, chunk_rows: int = 500) -> int:
    """Insert many rows using multi-values INSERT in chunks.

    Returns the number of rows attempted to insert (len(rows)).
    """
    if not rows:
        return 0
    ph_row = "(" + ",".join(["%s"] * cols_per_row) + ")"
    total = 0
    for i in range(0, len(rows), max(1, int(chunk_rows))):
        chunk = rows[i:i + max(1, int(chunk_rows))]
        sql = sql_prefix + ",".join([ph_row] * len(chunk))
        vals: List[Any] = []
        for r in chunk:
            vals.extend(list(r))
        cur.execute(sql, vals)
        total += len(chunk)
    return total
def _headers_sig(headers_tool_order: List[Tuple[int, str, str]]) -> str:
    """Stable signature for header_id list to detect header remap even if file unchanged."""
    hids = [int(hid) for (hid, _excel, _tc) in headers_tool_order]
    s = ",".join(map(str, hids)).encode("utf-8")
    return hashlib.md5(s).hexdigest()


def _parse_cmm_state(v: Optional[str]) -> Tuple[Optional[str], Optional[str]]:
    """Return (file_sig, header_sig). Backward compatible with older state values."""
    if not v or not isinstance(v, str):
        return None, None
    if "||H=" in v:
        a, b = v.split("||H=", 1)
        return a, b
    return v, None


def _format_cmm_state(file_sig: str, header_sig: str) -> str:
    return f"{file_sig}||H={header_sig}"


def _cmm_rows_exist(conn, header_ids: List[int]) -> bool:
    """Cheap existence check to avoid stale state when DB rows were deleted."""
    if not header_ids:
        return False
    try:
        cols = table_columns(conn, "cmm_row")
        if not cols:
            return False
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(header_ids))
            cur.execute(f"SELECT 1 FROM cmm_row WHERE header_id IN ({ph}) LIMIT 1", header_ids)
            return cur.fetchone() is not None
    except Exception:
        return False


def get_headers_for_oqc_file(conn, part_name: str, ship_date: str, source_file: str) -> List[Tuple[int, str, str]]:
    """
    Fetch OQC header rows for a given (part_name, date, source_file) and return
    [(header_id, excel_col, tool_cavity)] sorted by excel_col order.
    Fallback: if exact source_file match returns nothing, pick the most common source_file for that date.
    """
    cols = table_columns(conn, "oqc_header")
    name_col = "part_name" if cols.get("part_name") else ("model_name" if cols.get("model_name") else "part_name")
    excel_col_col = "excel_col" if cols.get("excel_col") else "excel_col"
    tool_col = "tool_cavity" if cols.get("tool_cavity") else ("tool_cavity_list" if cols.get("tool_cavity_list") else "tool_cavity")
    src_col = "source_file" if cols.get("source_file") else None
    ship_col = "ship_date" if cols.get("ship_date") else None
    lot_col = "lot_date" if cols.get("lot_date") else None

    def _fetch_for_src(sf: Optional[str]) -> List[Tuple[int, str, str]]:
        where = [f"{name_col}=%s"]
        params: List[Any] = [part_name]
        if sf and src_col:
            where.append(f"{src_col}=%s")
            params.append(sf)
        # date filter
        if ship_col and lot_col:
            where.append(f"({ship_col}=%s OR {lot_col}=%s)")
            params.extend([ship_date, ship_date])
        elif ship_col:
            where.append(f"{ship_col}=%s")
            params.append(ship_date)
        elif lot_col:
            where.append(f"{lot_col}=%s")
            params.append(ship_date)
        else:
            # no date column - last resort: use source_file only
            pass

        sql = f"SELECT id, {excel_col_col}, {tool_col} FROM oqc_header WHERE " + " AND ".join(where)
        with conn.cursor() as cur:
            cur.execute(sql, params)
            rows = cur.fetchall() or []
        out: List[Tuple[int, str, str]] = []
        for r in rows:
            if isinstance(r, dict):
                hid = r.get("id")
                ec = r.get(excel_col_col)
                tc = r.get(tool_col)
            else:
                hid, ec, tc = r[0], r[1], r[2]
            if hid is None or ec is None:
                continue
            out.append((int(hid), str(ec), str(tc) if tc is not None else ""))
        # sort by excel column order
        out.sort(key=lambda t: col_letters_to_index(str(t[1]) or "A"))
        return out

    # primary exact match
    out = _fetch_for_src(source_file if src_col else None)
    if out:
        return out

    # fallback: pick best source_file for date
    if src_col:
        try:
            where = [f"{name_col}=%s"]
            params: List[Any] = [part_name]
            if ship_col and lot_col:
                where.append(f"({ship_col}=%s OR {lot_col}=%s)")
                params.extend([ship_date, ship_date])
            elif ship_col:
                where.append(f"{ship_col}=%s"); params.append(ship_date)
            elif lot_col:
                where.append(f"{lot_col}=%s"); params.append(ship_date)

            sql = f"SELECT {src_col}, COUNT(*) AS cnt FROM oqc_header WHERE " + " AND ".join(where) + f" GROUP BY {src_col} ORDER BY cnt DESC, {src_col} DESC LIMIT 1"
            with conn.cursor() as cur:
                cur.execute(sql, params)
                r = cur.fetchone()
            best_sf = None
            if isinstance(r, dict):
                best_sf = r.get(src_col)
            elif r:
                best_sf = r[0]
            if best_sf:
                out2 = _fetch_for_src(str(best_sf))
                if out2:
                    return out2
        except Exception:
            pass

    return []
def table_columns(conn, table: str) -> Dict[str, bool]:
    """Return existing column names for table as {name: True} mapping."""
    cols: Dict[str, bool] = {}
    with conn.cursor() as cur:
        cur.execute(f"SHOW COLUMNS FROM {table}")
        rows = cur.fetchall()
        for r in rows:
            name = None
            if isinstance(r, dict):
                name = r.get('Field') or r.get('COLUMN_NAME')
                if name is None and r:
                    name = next(iter(r.values()))
            else:
                if r:
                    name = r[0]
            if name:
                cols[str(name)] = True
    return cols


def upsert_one_file(conn, header_cols: Dict[str, bool], parsed: ParsedFile) -> Dict[str, Any]:
    info = {
        "part_name": parsed.part_name,
        "ship_date": parsed.ship_date,
        "lot_date": parsed.lot_date,
        "source_file": parsed.source_file,
        "header": 0,
        "meas": 0,
        "res": 0,
        "missing_cache": parsed.missing_cache,
        "cols_scanned": parsed.cols_scanned,
        "cols_considered": parsed.cols_considered,
        "total_rows_used": parsed.total_rows_used,
        "header_ids": [],
    }
    with conn.cursor() as cur:
        preserve_dates_by_col: Dict[str, Dict[str, Any]] = {}

        if DELETE_DUPLICATES:
            # 기존 DB에 저장된 '발행/예약 플래그(측정일 컬럼)'는 엑셀 재저장/재업로드로 NULL로 덮이지 않게 보존
            sel_cols = ["id", "excel_col"]
            for c in ("meas_date", "meas_date2", "jmeas_date", "jmeas_date2"):
                if header_cols.get(c):
                    sel_cols.append(c)

            cur.execute(
                f"""
                SELECT {",".join(sel_cols)}
                FROM oqc_header
                WHERE part_name = %s
                  AND source_file = %s
                  AND (ship_date = %s OR lot_date = %s)
                """,
                (parsed.part_name, parsed.source_file, parsed.ship_date, parsed.lot_date),
            )

            rows = cur.fetchall()
            old_ids = [int(r[0]) for r in rows]

            # excel_col 기준으로 컬럼값을 보존 (중복이 있으면 non-null 우선, 그 다음 최신값 우선)
            for r in rows:
                excol = str(r[1])
                d = preserve_dates_by_col.setdefault(excol, {})
                idx = 2
                for c in ("meas_date", "meas_date2", "jmeas_date", "jmeas_date2"):
                    if header_cols.get(c):
                        v = r[idx]
                        idx += 1
                        if isinstance(v, str) and v.strip() == "":
                            v = None
                        if c not in d or d[c] is None:
                            d[c] = v
                        else:
                            # 둘 다 값이 있으면 더 '큰' 값(대부분 날짜 최신)을 우선
                            try:
                                if v is not None and v > d[c]:
                                    d[c] = v
                            except Exception:
                                pass

            if old_ids:
                ph = ",".join(["%s"] * len(old_ids))
                cur.execute(f"DELETE FROM oqc_measurements WHERE header_id IN ({ph})", old_ids)
                cur.execute(f"DELETE FROM oqc_result_header WHERE header_id IN ({ph})", old_ids)
                # CMM이 붙어있으면 같이 지워야 참조 깨짐 방지
                try:
                    cur.execute(f"DELETE FROM cmm_row WHERE header_id IN ({ph})", old_ids)
                except Exception:
                    pass
                cur.execute(f"DELETE FROM oqc_header WHERE id IN ({ph})", old_ids)


        fields = ["part_name", "tool_cavity", "kind", "source_file", "excel_col"]
        if header_cols.get("ship_date"):
            fields.append("ship_date")
        if header_cols.get("lot_date"):
            fields.append("lot_date")
        # ✅ 분리 날짜들
        if header_cols.get("meas_date"):
            fields.append("meas_date")
        if header_cols.get("meas_date2"):
            fields.append("meas_date2")
        if header_cols.get("jmeas_date"):
            fields.append("jmeas_date")
        if header_cols.get("jmeas_date2"):
            fields.append("jmeas_date2")

        cols_sql = ",".join(fields)
        ph_sql = ",".join(["%s"] * len(fields))
        header_sql = f"INSERT INTO oqc_header ({cols_sql}) VALUES ({ph_sql})"

        for col in parsed.columns:
            row_values: List[Any] = [
                parsed.part_name,
                col["tool_cavity"],
                col["kind"],
                parsed.source_file,
                col["excel_col"],
            ]
            keep = preserve_dates_by_col.get(str(col["excel_col"]))
            if header_cols.get("ship_date"):
                row_values.append(parsed.ship_date)
            if header_cols.get("lot_date"):
                row_values.append(parsed.lot_date)
            if header_cols.get("meas_date"):
                if keep is not None and "meas_date" in keep:
                    row_values.append(keep["meas_date"])
                else:
                    row_values.append(col.get("meas_date"))
            if header_cols.get("meas_date2"):
                if keep is not None and "meas_date2" in keep:
                    row_values.append(keep["meas_date2"])
                else:
                    row_values.append(col.get("meas_date2"))
            if header_cols.get("jmeas_date"):
                if keep is not None and "jmeas_date" in keep:
                    row_values.append(keep["jmeas_date"])
                else:
                    row_values.append(col.get("jmeas_date"))
            if header_cols.get("jmeas_date2"):
                if keep is not None and "jmeas_date2" in keep:
                    row_values.append(keep["jmeas_date2"])
                else:
                    row_values.append(col.get("jmeas_date2"))

            cur.execute(header_sql, row_values)
            header_id = int(cur.lastrowid)
            info["header"] += 1
            info["header_ids"].append(header_id)

            meas_rows: List[Tuple[Any, ...]] = []
            res_rows: List[Tuple[Any, ...]] = []

            for (r, val) in col["pairs"]:
                point_no = parsed.point_no_by_row.get(r) or str(r)
                spc_code = parsed.spc_by_row.get(r)
                meas_rows.append((header_id, point_no, spc_code, float(val), int(r)))

                usl = parsed.usl_by_row.get(r)
                lsl = parsed.lsl_by_row.get(r)

                ok = None
                if usl is not None or lsl is not None:
                    ok = 1
                    if usl is not None and float(val) > float(usl):
                        ok = 0
                    if lsl is not None and float(val) < float(lsl):
                        ok = 0

                res_rows.append((header_id, point_no, float(val), float(val), float(val), usl, lsl, ok))

            info["meas"] += bulk_insert(
                cur,
                "INSERT INTO oqc_measurements (header_id, point_no, spc_code, value, row_index) VALUES ",
                meas_rows,
                cols_per_row=5,
                chunk_rows=BULK_CHUNK_ROWS,
            )

            info["res"] += bulk_insert(
                cur,
                "INSERT INTO oqc_result_header (header_id, point_no, max_val, min_val, mean_val, usl, lsl, result_ok) VALUES ",
                res_rows,
                cols_per_row=8,
                chunk_rows=min(BULK_CHUNK_ROWS, 500),
            )

    return info


# ----------------------------
# CMM parsing/upsert
# ----------------------------

_CMM_SPECS = {
    "MEM-IR-BASE": {
        "sheet": "OQC Raw data",
        "start_cell": "E2",
    },
    "MEM-Z-CARRIER": {
        "sheet": "OQC RawData",
        "start_cell": "G4",
    },
}


def _parse_cell_ref(cell: str) -> Tuple[int, int]:
    col, row = cell_ref_to_col_row(cell)
    if not col or not row:
        raise RuntimeError(f"Invalid start_cell: {cell}")
    return col, row


def parse_cmm_numeric_matrix(file_path: Path, sheet_name: str, start_cell: str, num_cols: int, max_rows_scan: int = 10000) -> Tuple[int, Dict[Tuple[int, int], float]]:
    """Return (N, values) where values[(row_index, tool_index)] = float.

    - row_index/tool_index are 1-based relative indices from start_cell.
    - Only numeric cells are stored in values.
    - N is last row_index that had any numeric value within the [1..num_cols] range.
    """
    start_col, start_row = _parse_cell_ref(start_cell)
    last_abs_row_with_val = 0
    values: Dict[Tuple[int, int], float] = {}

    with zipfile.ZipFile(file_path, "r") as zf:
        shared = load_shared_strings(zf)
        sheet_entry = find_sheet_entry_by_name(zf, sheet_name)

        with zf.open(sheet_entry) as f:
            for event, elem in ET.iterparse(f, events=("end",)):
                if _local(elem.tag) != "c":
                    continue

                ref = elem.attrib.get("r", "")
                col, row = cell_ref_to_col_row(ref)

                if row < start_row or row > start_row + max_rows_scan:
                    elem.clear()
                    continue

                if col < start_col or col >= start_col + num_cols:
                    elem.clear()
                    continue

                val, _is_formula, _miss = read_cell_value(elem, shared)
                fval = to_float_or_none(val)
                if fval is not None:
                    tool_index = (col - start_col) + 1
                    row_index = (row - start_row) + 1
                    values[(row_index, tool_index)] = float(fval)
                    if row > last_abs_row_with_val:
                        last_abs_row_with_val = row

                elem.clear()

    if last_abs_row_with_val <= 0:
        return 0, {}

    N = (last_abs_row_with_val - start_row) + 1
    if N < 0:
        N = 0
    return N, values


def upsert_cmm_day(
    conn,
    part_name: str,
    ship_date: str,
    headers_tool_order: List[Tuple[int, str, str]],
    cmm_file: Path,
    sheet_name: str,
    start_cell: str,
) -> Dict[str, Any]:
    """Upload one CMM file for a day, mapped to OQC header ids in tool order."""
    ensure_cmm_row_table(conn)

    M = len(headers_tool_order)
    if M <= 0:
        raise RuntimeError("OQC header rows not found for mapping")

    N, values = parse_cmm_numeric_matrix(cmm_file, sheet_name, start_cell, num_cols=M)

    info = {
        "model_name": part_name,
        "ship_date": ship_date,
        "src_file": cmm_file.name,
        "tools": M,
        "rows": N,
        "cells": M * N if N > 0 else 0,
        "non_null": len(values),
    }

    if N <= 0:
        return info

    header_ids = [hid for (hid, _excel, _tc) in headers_tool_order]

    with conn.cursor() as cur:
        # 안전하게 한번 지우고 재삽입(상태파일로 보통 1회만 실행됨)
        ph = ",".join(["%s"] * len(header_ids))
        cur.execute(f"DELETE FROM cmm_row WHERE header_id IN ({ph})", header_ids)

        rows: List[Tuple[Any, ...]] = []
        name_col, date_col = detect_cmm_schema(conn)

        for tool_idx in range(1, M + 1):
            header_id, excel_col, tool_cavity = headers_tool_order[tool_idx - 1]
            for row_idx in range(1, N + 1):
                v = values.get((row_idx, tool_idx))
                rows.append(
                    (
                        int(header_id),
                        part_name,
                        ship_date,
                        int(tool_idx),
                        int(row_idx),
                        (float(v) if v is not None else None),
                        (tool_cavity or None),
                        cmm_file.name,
                    )
                )

        bulk_insert(
            cur,
            f"INSERT INTO cmm_row (header_id, {name_col}, {date_col}, tool_index, row_index, val_num, tool_cavity, src_file) VALUES ",
            rows,
            cols_per_row=8,
            chunk_rows=min(BULK_CHUNK_ROWS, 800),
        )

    return info


# ----------------------------
# Scanning
# ----------------------------

def iter_candidate_oqc_files() -> Iterable[Path]:
    """OQC (OMM) 후보만 수집"""
    for base in BASE_DIRS:
        b = Path(base)
        if not b.exists():
            continue

        for omm in b.rglob("*OMM"):
            # 모델별로 OMM 폴더명이 "OMM" 또는 "1.OMM" 처럼 접두사가 붙는 경우가 있어 끝이 OMM인 폴더를 모두 허용
            if not omm.is_dir():
                continue
            nm = omm.name.strip().upper()
            if not nm.endswith("OMM"):  # safety
                continue
            # (선택) 혹시라도 OMM 비슷한 다른 폴더가 섞이면 여기서 걸러냄
            if "CMM" in nm:
                continue

            # 아래 로직은 기존과 동일

            for year in SCAN_YEARS:
                ydir = omm / str(year)
                if not ydir.exists() or not ydir.is_dir():
                    continue

                for p in ydir.rglob("*"):
                    if not p.is_file():
                        continue
                    if should_exclude_file(p.name):
                        continue
                    if p.suffix.lower() not in EXTENSIONS:
                        continue
                    if not is_allowed_oqc_name(p):
                        continue
                    yield p


def build_cmm_index() -> Dict[Tuple[str, str], Path]:
    """Build (part_name, ship_date) -> cmm_file path for IR/ZC only."""
    index: Dict[Tuple[str, str], Path] = {}

    def consider(part: str, f: Path):
        d = detect_lot_date_from_filename(f.name)
        if not d:
            return
        key = (part, d)
        # 여러 경로 중복 시 최신 mtime 우선
        if key not in index:
            index[key] = f
            return
        try:
            if f.stat().st_mtime > index[key].stat().st_mtime:
                index[key] = f
        except Exception:
            pass

    for base in BASE_DIRS:
        b = Path(base)
        if not b.exists():
            continue

        # IR BASE: ...\IR BASE\2.CMM\YYYY\*.xlsm
        for y in SCAN_YEARS:
            d_ir = b / "IR BASE" / "2.CMM" / str(y)
            if d_ir.exists() and d_ir.is_dir():
                for f in d_ir.rglob("*.xlsm"):
                    if should_exclude_file(f.name):
                        continue
                    if "_oqc" not in f.stem.lower():
                        continue
                    if not is_allowed_cmm_name(f):
                        continue
                    consider("MEM-IR-BASE", f)

        # Z_CARRIER: ...\Z_CARRIER\CMM\YYYY\*.xlsm
        for y in SCAN_YEARS:
            d_zc = b / "Z_CARRIER" / "CMM" / str(y)
            if d_zc.exists() and d_zc.is_dir():
                for f in d_zc.rglob("*.xlsm"):
                    if should_exclude_file(f.name):
                        continue
                    if "_oqc" not in f.stem.lower():
                        continue
                    if not is_allowed_cmm_name(f):
                        continue
                    consider("MEM-Z-CARRIER", f)

    return index


# ----------------------------
# Main loop
# ----------------------------

def _state_key_oqc(p: Path) -> str:
    part = detect_part_for_key(p)
    return f"OQC::{part}::{p.name}"


def _state_key_cmm(p: Path, part_name: str = "") -> str:
    part = part_name or detect_part_for_key(p)
    return f"CMM::{part}::{p.name}"


def _legacy_keys(prefix: str, p: Path) -> list[str]:
    # 과거 버전 호환(경로 기반 키)
    keys = [f"{prefix}::{str(p)}"]
    try:
        keys.append(f"{prefix}::{str(p.resolve())}")
    except Exception:
        pass
    try:
        keys.append(f"{prefix}::{p.as_posix()}")
    except Exception:
        pass
    return keys


def _state_get(state: dict, key: str, legacy_keys: list[str]):
    v = state.get(key)
    if v is not None:
        return v
    for lk in legacy_keys:
        if lk in state:
            v = state.get(lk)
            # 마이그레이션: 새 키에도 복사
            state[key] = v
            return v
    return None


def process_files_once(conn, header_cols: Dict[str, bool], state: Dict[str, Any], state_path: Path, cmm_index: Dict[Tuple[str, str], Path]) -> tuple[int, Any]:
    processed = 0

    # WATCH 모드로 오래 켜두면 idle로 conn이 끊기는 경우가 있어서(특히 수시간 후)
    # 매 사이클 시작 시 ping(reconnect=True) 로 연결을 보장.
    conn, _ = ensure_conn_alive(conn)

    files = list(iter_candidate_oqc_files())

    # 같은 파일이 X: / UNC 등 서로 다른 경로로 중복 수집될 수 있어서,
    # '파일명 기준(part+name+size)'으로 1개만 남기고, 여러 경로면 최신 mtime을 우선 채택
    uniq: Dict[Tuple[str, str, int], Path] = {}
    for fp in files:
        try:
            part = detect_part_for_key(fp)
            st = fp.stat()
            k = (part, fp.name.lower(), int(st.st_size))
            if k not in uniq:
                uniq[k] = fp
            else:
                try:
                    if st.st_mtime > uniq[k].stat().st_mtime:
                        uniq[k] = fp
                except Exception:
                    pass
        except Exception:
            # stat 실패 등은 fallback 키로 일단 보관
            uniq[("UNKNOWN", fp.name.lower(), -1)] = fp
    files = list(uniq.values())

    # 날짜 기준 정렬(월/일 순차)
    def sort_key(p: Path):
        d = detect_lot_date_from_filename(p.name) or "9999-99-99"
        part = detect_part_for_key(p)
        return (part, d, p.name)

    files.sort(key=sort_key)

    for p in files:
        did_upload_oqc = False
        key = _state_key_oqc(p)
        sig = file_signature(p)

        prev = _state_get(state, key, _legacy_keys("OQC", p))
        # 구버전 state 키가 part를 '경로 문자열'로 만들어서 (X:/UNC)마다 다른 키가 생긴 경우가 있음.
        # 같은 filename + 같은 sig(또는 EMPTY::sig)를 가진 과거 키를 찾아서 새 키로 마이그레이션.
        if prev is None:
            suffix = f"::{p.name}"
            target1 = sig
            target2 = f"EMPTY::{sig}"
            try:
                for k2, v2 in state.items():
                    if isinstance(k2, str) and k2.startswith("OQC::") and k2.endswith(suffix):
                        if v2 == target1 or v2 == target2:
                            prev = v2
                            state[key] = v2
                            break
            except Exception:
                pass

        oqc_is_empty = (prev == f"EMPTY::{sig}")
        oqc_done = (prev == sig) or oqc_is_empty

        # OQC 업로드
        if not oqc_done:
            try:
                t0 = time.time()
                parsed = parse_oqc_xlsx(p)

                # DB 연결이 idle로 끊기는 경우(2006/2013/0) 1회 재연결 후 재시도
                info = None
                for _try in range(2):
                    try:
                        conn, _ = ensure_conn_alive(conn)
                        conn.begin()
                        info = upsert_one_file(conn, header_cols, parsed)
                        conn.commit()
                        break
                    except Exception as db_e:
                        try:
                            conn.rollback()
                        except Exception:
                            pass
                        if _try == 0 and is_db_conn_lost(db_e):
                            log(f"[DB] reconnect (OQC) after: {db_e}")
                            conn, _ = ensure_conn_alive(None)
                            continue
                        raise

                dt = time.time() - t0
                processed += 1
                did_upload_oqc = True
                state[key] = sig
                save_state(state_path, state)

                log(
                    f"OK  {p.name} / {info['part_name']} / {info['ship_date']} "
                    f"(header {info['header']}, meas {info['meas']}, res {info['res']}) "
                    f"missing_cache={info['missing_cache']} / "
                    f"cols(scanned={info['cols_scanned']}, considered={info['cols_considered']}), rows_used={info['total_rows_used']} / "
                    f"{dt:.2f}s"
                )

            except EmptyOQCData as e:
                # ✅ 수식만 있고 측정 데이터가 없으면 OQC/CMM 모두 스킵(단, 상태는 기록)
                state[key] = f"EMPTY::{sig}"
                save_state(state_path, state)
                log(f"SKIP empty OQC  {p.name} : {e}")
                continue

            except Exception as e:
                try:
                    conn.rollback()
                except Exception:
                    pass
                log(f"ERR {p.name} : {e}")
                continue

        # ✅ 이 파일은 '데이터 없음'으로 판정된 경우 CMM도 스킵
        if oqc_is_empty:
            continue

        # CMM 업로드(IR/ZC만)
        # - OQC는 있다고 가정(방금 올렸거나, 과거에 올라가 있음)
        try:
            part_name = detect_part_name_from_text(str(p))
            ship_date = detect_lot_date_from_filename(p.name)
            if not ship_date:
                continue

            if part_name not in _CMM_SPECS:
                continue

            cmm_file = cmm_index.get((part_name, ship_date))
            if not cmm_file or not cmm_file.exists():
                # 너무 스팸 안 찍고 조용히 넘어감
                continue

            cmm_key = _state_key_cmm(cmm_file, part_name)
            cmm_sig = file_signature(cmm_file)
            prev_cmm_raw = _state_get(state, cmm_key, _legacy_keys("CMM", cmm_file))

            conn, _ = ensure_conn_alive(conn)
            headers = get_headers_for_oqc_file(conn, part_name, ship_date, p.name)
            headers_sig = _headers_sig(headers)
            prev_file_sig, prev_headers_sig = _parse_cmm_state(prev_cmm_raw)
            prev_is_empty = (prev_file_sig == f"EMPTY::{cmm_sig}")
            same_file_sig = (prev_file_sig == cmm_sig) or prev_is_empty
            same_sig = same_file_sig and ((prev_headers_sig == headers_sig) or (prev_headers_sig is None and same_file_sig))
            if same_sig and (not did_upload_oqc):
                # EMPTY로 마킹된 파일은 WATCH에서 계속 재시도하지 않음(파일이 수정되면 시그니처가 바뀌어 재시도됨)
                if prev_is_empty:
                    continue
                # state는 같은데 DB에서 rows가 없으면(중복정리/재업로드로 삭제됨) 다시 업로드
                if _cmm_rows_exist(conn, [hid for (hid, _ec, _tc) in headers]):
                    continue

            if not headers:
                # OQC는 있는데 header가 조회 안 되면(예: source_file 달라서) -> 스킵
                continue

            spec = _CMM_SPECS[part_name]

            t0 = time.time()
            info2 = None
            for _try in range(2):
                try:
                    conn, _ = ensure_conn_alive(conn)
                    conn.begin()
                    info2 = upsert_cmm_day(
                        conn,
                        part_name=part_name,
                        ship_date=ship_date,
                        headers_tool_order=headers,
                        cmm_file=cmm_file,
                        sheet_name=spec["sheet"],
                        start_cell=spec["start_cell"],
                    )
                    conn.commit()
                    break
                except Exception as db_e:
                    try:
                        conn.rollback()
                    except Exception:
                        pass
                    if _try == 0 and is_db_conn_lost(db_e):
                        log(f"[DB] CMM 재연결 후 재시도: {db_e}")
                        conn, _ = ensure_conn_alive(None)
                        continue
                    raise
            dt = time.time() - t0

            if info2 and (int(info2.get("rows", 0)) <= 0 or int(info2.get("non_null", 0)) <= 0):
                # ✅ CMM 파일이 실제 숫자 데이터가 없는 경우(템플릿만 있고 측정 없음, 또는 수식 캐시 없음)
                # 사용자가 확인했듯 "정상적으로 데이터가 없는 파일"일 수 있으므로 WATCH에서 무한 재시도/로그스팸을 막는다.
                if MARK_CMM_EMPTY_IN_STATE:
                    state[cmm_key] = _format_cmm_state(f"EMPTY::{cmm_sig}", headers_sig)
                    save_state(state_path, state)
                if not SUPPRESS_CMM_EMPTY_LOG:
                    log(
                        f"CMM EMPTY  {cmm_file.name} / {part_name} / {ship_date} "
                        f"(tools={info2['tools']}, rows={info2['rows']}, cells={info2['cells']}, non_null={info2['non_null']}) / "
                        f"{dt:.2f}s  (no numeric data)"
                    )
            else:
                state[cmm_key] = _format_cmm_state(cmm_sig, headers_sig)
                save_state(state_path, state)

                log(
                    f"CMM OK  {cmm_file.name} / {part_name} / {ship_date} "
                    f"(tools={info2['tools']}, rows={info2['rows']}, cells={info2['cells']}, non_null={info2['non_null']}) / "
                    f"{dt:.2f}s"
                )

        except Exception as e:
            try:
                conn.rollback()
            except Exception:
                pass
            log(f"CMM ERR {cmm_file.name if 'cmm_file' in locals() else '(unknown)'} : {e}")

    return processed, conn


def main() -> int:
    if DB_PASS == "CHANGE_ME":
        log("DB_PASS가 CHANGE_ME 입니다. 파일 상단 USER SETTINGS에서 비번 넣어주세요.")
        return 2

    ok_base = any(Path(x).exists() for x in BASE_DIRS)
    if not ok_base:
        log("BASE_DIRS 경로가 하나도 존재하지 않습니다. 파일 상단 USER SETTINGS에서 경로 수정하세요.")
        return 2

    if pymysql is None:
        log("pymysql이 없습니다. 먼저: pip install pymysql")
        return 2

    state_path = Path(STATE_FILE)
    state = load_state(state_path)
    # 상태파일이 없으면 빈 json이라도 생성(위치 확인용)
    if not state_path.exists():
        save_state(state_path, state)


    conn = None
    try:
        conn = db_connect()
        header_cols = table_columns(conn, "oqc_header")

        log("=== START ===")
        log(f"DB={DB_HOST}:{DB_PORT}/{DB_NAME} user={DB_USER}")
        log(f"SCAN_YEARS={SCAN_YEARS} interval={WATCH_INTERVAL_SEC}s")
        log(f"STATE_FILE={state_path.resolve()}")
        log(f"LOG_FILE={Path(LOG_FILE).resolve()}")


        # CMM 인덱스는 사이클마다 1번만 구축
        cmm_index = build_cmm_index()

        log("[MODE] BACKFILL (1회 전체 스캔)")
        n, conn = process_files_once(conn, header_cols, state, state_path, cmm_index)
        log(f"BACKFILL done. processed={n}")

        log("[MODE] WATCH (계속 감시)")
        while True:
            time.sleep(WATCH_INTERVAL_SEC)
            # watch 중에는 새 파일이 생길 수 있으니 인덱스도 갱신
            cmm_index = build_cmm_index()
            n2, conn = process_files_once(conn, header_cols, state, state_path, cmm_index)
            if n2:
                log(f"WATCH cycle processed={n2}")

    except KeyboardInterrupt:
        log("STOP by user (Ctrl+C)")
        return 0
    except Exception as e:
        log(f"FATAL: {e}")
        return 1
    finally:
        try:
            if conn:
                conn.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())