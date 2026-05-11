# -*- coding: utf-8 -*-
"""Extract one or more MSOP FAI pages from a PDF.

Args:
  1: source pdf path
  2: fai label or JSON array of labels, e.g. "FAI 1 / SPC A" or ["FAI 1", "FAI 2"]
     Use "__ALL__" to copy the whole document while setting a browser-friendly PDF title.
  3: output pdf path
  4: optional PDF title / shown filename

Prints JSON to stdout.
"""
from __future__ import annotations

import json
import os
import re
import sys
from typing import Iterable, List, Optional, Tuple


def _emit(**payload):
    print(json.dumps(payload, ensure_ascii=False))


def _labels(arg: str) -> List[str]:
    raw = str(arg or "").strip()
    if not raw:
        return []
    try:
        data = json.loads(raw)
        if isinstance(data, list):
            out = []
            for v in data:
                s = str(v or "").strip()
                if s:
                    out.append(s)
            return out
        if isinstance(data, str) and data.strip():
            raw = data.strip()
    except Exception:
        pass

    parts = re.split(r"\s*(?:\|\||[,;\r\n]+)\s*", raw)
    return [p.strip() for p in parts if p.strip()]


def _extract_fai_no(label: str) -> str:
    m = re.search(r"(\d+)", str(label or ""))
    if not m:
        return ""
    return (m.group(1).lstrip("0") or "0")


def _norm_text(txt: str) -> str:
    txt = (txt or "").upper()
    txt = re.sub(r"\s+", " ", txt)
    return txt.strip()


def _compile_patterns(num: str):
    # JMP Assist.py 기준: 1순위는 상단 헤더 "MSOP : FAI X".
    # PDF 추출 텍스트의 공백/기호 흔들림을 고려해 조금 더 넓게 허용한다.
    header_patterns = [
        re.compile(rf"MSOP\s*[:：]\s*FAI\s*(?:NO\.?\s*)?[-_#]?\s*{re.escape(num)}\b", re.I),
        re.compile(rf"MSOP\s*[:：]\s*.*?\bFAI\s*(?:NO\.?\s*)?[-_#]?\s*{re.escape(num)}\b", re.I),
    ]
    generic_patterns = [
        re.compile(rf"\bFAI\s*(?:NO\.?\s*)?[-_#]?\s*{re.escape(num)}\b", re.I),
        re.compile(rf"\bFAI{re.escape(num)}\b", re.I),
    ]
    return header_patterns, generic_patterns


def _compact_text(txt: str) -> str:
    return re.sub(r"[^A-Z0-9]+", "", (txt or "").upper())


def _find_pages_from_texts(texts: List[str], fai_label: str) -> Tuple[List[int], str]:
    """Return every page matching the requested FAI.

    MSOP 문서는 같은 FAI가 2페이지 이상으로 이어질 수 있다.
    기존 방식처럼 첫 번째 매칭 페이지만 반환하면 후속 페이지가 잘리므로,
    1) 상단 헤더(MSOP : FAI n...)로 매칭되는 모든 페이지를 우선 수집하고,
    2) 헤더 매칭이 전혀 없을 때만 일반 FAI n 텍스트 매칭으로 fallback한다.
    """
    num = _extract_fai_no(fai_label)
    if not num:
        return [], "FAI 번호를 추출하지 못했습니다."

    header_patterns, generic_patterns = _compile_patterns(num)
    # Compact fallback은 공백/기호가 사라진 PDF 텍스트용이다.
    # 단순 포함 검색(MSOPFAI8 in MSOPFAI81)을 쓰면 FAI 8 선택 시 81/89까지 따라오므로
    # 숫자 뒤는 반드시 다음 숫자가 아니어야 한다.
    compact_header_patterns = [
        re.compile(rf"MSOPFAI(?:NO)?0*{re.escape(num)}(?!\d)", re.I),
    ]

    header_hits: List[int] = []
    for idx, u in enumerate(texts):
        c = _compact_text(u)
        if any(p.search(u) for p in header_patterns) or any(p.search(c) for p in compact_header_patterns):
            header_hits.append(idx)

    if header_hits:
        return header_hits, ""

    generic_hits: List[int] = []
    compact_generic_patterns = [
        re.compile(rf"FAI(?:NO)?0*{re.escape(num)}(?!\d)", re.I),
    ]
    for idx, u in enumerate(texts):
        c = _compact_text(u)
        if any(p.search(u) for p in generic_patterns) or any(p.search(c) for p in compact_generic_patterns):
            generic_hits.append(idx)

    if generic_hits:
        return generic_hits, ""

    return [], f"FAI {num} 페이지를 찾지 못했습니다."


def main():
    if len(sys.argv) < 4:
        _emit(ok=False, error="arguments missing")
        return 2

    pdf_path = sys.argv[1]
    fai_arg = sys.argv[2]
    out_path = sys.argv[3]

    if not os.path.isfile(pdf_path):
        _emit(ok=False, error="source PDF not found")
        return 1

    labels = _labels(fai_arg)
    if not labels:
        _emit(ok=False, error="FAI label missing")
        return 1

    title = ""
    if len(sys.argv) >= 5:
        title = str(sys.argv[4] or "").strip()

    try:
        from pypdf import PdfReader, PdfWriter
    except Exception as e:
        _emit(ok=False, error=f"pypdf 모듈이 없습니다: {e}")
        return 1

    try:
        reader = PdfReader(pdf_path)
        texts: List[str] = []
        for page in reader.pages:
            try:
                txt = page.extract_text() or ""
            except Exception:
                txt = ""
            texts.append(_norm_text(txt))

        page_indices: List[int] = []
        found = []
        missing = []

        all_requested = any(str(label).strip().upper() in ("ALL", "__ALL__") for label in labels)
        if all_requested:
            page_indices = list(range(len(reader.pages)))
            found.append({"label": "ALL", "pages": [i + 1 for i in page_indices], "added_pages": [i + 1 for i in page_indices]})
        else:
            for label in labels:
                pages, err = _find_pages_from_texts(texts, label)
                if not pages:
                    missing.append({"label": label, "error": err or "FAI page not found"})
                    continue
                added_pages = []
                for page_idx in pages:
                    if page_idx not in page_indices:
                        page_indices.append(page_idx)
                        added_pages.append(page_idx + 1)
                found.append({"label": label, "pages": [i + 1 for i in pages], "added_pages": added_pages})

        if not page_indices:
            msg = missing[0]["error"] if missing else "FAI page not found"
            _emit(ok=False, error=msg, missing=missing)
            return 1

        out_dir = os.path.dirname(os.path.abspath(out_path))
        os.makedirs(out_dir, exist_ok=True)

        writer = PdfWriter()
        for page_idx in page_indices:
            writer.add_page(reader.pages[page_idx])
        if title:
            try:
                writer.add_metadata({"/Title": title})
            except Exception:
                pass
        with open(out_path, "wb") as f:
            writer.write(f)

        _emit(ok=True, page=page_indices[0] + 1, pages=[i + 1 for i in page_indices], found=found, missing=missing, out=out_path)
        return 0
    except Exception as e:
        _emit(ok=False, error=str(e))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
