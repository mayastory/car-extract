# -*- coding: utf-8 -*-
"""Extract one or more MSOP FAI pages from a PDF.

Args:
  1: source pdf path
  2: fai label or JSON array of labels, e.g. "FAI 1 / SPC A" or ["FAI 1", "FAI 2"]
  3: output pdf path

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


def _find_page_from_texts(texts: List[str], fai_label: str) -> Tuple[Optional[int], str]:
    num = _extract_fai_no(fai_label)
    if not num:
        return None, "FAI 번호를 추출하지 못했습니다."

    header_patterns, generic_patterns = _compile_patterns(num)

    for idx, u in enumerate(texts):
        if any(p.search(u) for p in header_patterns):
            return idx, ""

    for idx, u in enumerate(texts):
        if any(p.search(u) for p in generic_patterns):
            return idx, ""

    return None, f"FAI {num} 페이지를 찾지 못했습니다."


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
        for label in labels:
            page_idx, err = _find_page_from_texts(texts, label)
            if page_idx is None:
                missing.append({"label": label, "error": err or "FAI page not found"})
                continue
            if page_idx not in page_indices:
                page_indices.append(page_idx)
            found.append({"label": label, "page": page_idx + 1})

        if not page_indices:
            msg = missing[0]["error"] if missing else "FAI page not found"
            _emit(ok=False, error=msg, missing=missing)
            return 1

        out_dir = os.path.dirname(os.path.abspath(out_path))
        os.makedirs(out_dir, exist_ok=True)

        writer = PdfWriter()
        for page_idx in page_indices:
            writer.add_page(reader.pages[page_idx])
        with open(out_path, "wb") as f:
            writer.write(f)

        _emit(ok=True, page=page_indices[0] + 1, pages=[i + 1 for i in page_indices], found=found, missing=missing, out=out_path)
        return 0
    except Exception as e:
        _emit(ok=False, error=str(e))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
