# -*- coding: utf-8 -*-
import csv
import datetime
import re
import traceback
from collections import OrderedDict, defaultdict

import tkinter as tk
from tkinter import ttk, filedialog, messagebox

import pymysql

try:
    from openpyxl import Workbook
    OPENPYXL_OK = True
except Exception:
    OPENPYXL_OK = False

APP_TITLE = "OMM 역추출기 (Rough GUI)"

# DB 설정은 코드에만 둠
DB_HOSTS = ["dpams.ddns.net", "211.212.182.110"]
DB_PORT = 3306
DB_USER = "maya"
DB_PASS = "##Gmlakd2323"
DB_NAME = "dp"

MODEL_OPTIONS = [
    "ALL",
    "Memphis IR BASE",
    "Memphis X Carrier",
    "Memphis Y Carrier",
    "Memphis Z Carrier",
    "Memphis Z Stopper",
]

BASE_COLUMNS = ["fai", "usl", "lsl"]


def chunked(items, size):
    i = 0
    while i < len(items):
        yield items[i:i + size]
        i += size


class OMMReverseService(object):
    def __init__(self, hosts, port, user, password, database):
        self.hosts = [str(x).strip() for x in hosts if str(x).strip()]
        self.port = int(port)
        self.user = user
        self.password = password
        self.database = database

    def connect(self):
        last_exc = None
        for host in self.hosts:
            try:
                return pymysql.connect(
                    host=host,
                    port=self.port,
                    user=self.user,
                    password=self.password,
                    database=self.database,
                    charset="utf8mb4",
                    autocommit=True,
                    cursorclass=pymysql.cursors.DictCursor,
                    connect_timeout=5,
                    read_timeout=60,
                    write_timeout=60,
                )
            except Exception as exc:
                last_exc = exc
        raise RuntimeError("DB 연결 실패: %s" % last_exc)

    def fetch_headers(self, start_date, end_date, model, selected_tcs):
        tool_set = []
        cav_set = []
        for tc in selected_tcs:
            if "#" not in tc:
                continue
            tool, cav = tc.split("#", 1)
            tool = tool.strip().upper()
            cav = cav.strip()
            if not tool or not cav.isdigit():
                continue
            if tool not in tool_set:
                tool_set.append(tool)
            cav_i = int(cav)
            if cav_i not in cav_set:
                cav_set.append(cav_i)

        if not tool_set or not cav_set:
            return []

        sql = []
        params = []
        sql.append("SELECT id, meas_date, part_name, tool, cavity")
        sql.append("FROM ipqc_omm_header")
        sql.append("WHERE meas_date BETWEEN %s AND %s")
        params.append(start_date)
        params.append(end_date)

        if model != "ALL":
            sql.append("AND part_name = %s")
            params.append(model)

        sql.append("AND tool IN (%s)" % ",".join(["%s"] * len(tool_set)))
        params.extend(tool_set)
        sql.append("AND cavity IN (%s)" % ",".join(["%s"] * len(cav_set)))
        params.extend(cav_set)
        sql.append("ORDER BY meas_date, tool, cavity, id")

        out = []
        conn = self.connect()
        try:
            cur = conn.cursor()
            try:
                cur.execute("\n".join(sql), params)
                fetched = cur.fetchall()
                for row in fetched:
                    out.append({
                        "header_id": int(row.get("id") or 0),
                        "meas_date": str(row.get("meas_date") or ""),
                        "part_name": str(row.get("part_name") or ""),
                        "tool": str(row.get("tool") or "").upper(),
                        "cavity": int(row.get("cavity") or 0),
                    })
            finally:
                cur.close()
        finally:
            conn.close()
        return out

    def fetch_wide_rows(self, headers, selected_tcs):
        if not headers:
            return list(BASE_COLUMNS), []

        # 같은 Tool#Cavity가 여러 건이면 마지막 header를 사용
        tc_to_header = OrderedDict()
        for tc in selected_tcs:
            tc_to_header[tc] = None
        for h in headers:
            tc = "%s#%s" % (h["tool"], h["cavity"])
            if tc in tc_to_header:
                tc_to_header[tc] = h

        chosen_headers = []
        for tc in selected_tcs:
            h = tc_to_header.get(tc)
            if h is not None:
                chosen_headers.append(h)

        if not chosen_headers:
            return list(BASE_COLUMNS), []

        header_ids = [int(h["header_id"]) for h in chosen_headers]
        result_by_key = {}
        meas_by_key = defaultdict(dict)
        fai_order = OrderedDict()

        conn = self.connect()
        try:
            cur = conn.cursor()
            try:
                for chunk in chunked(header_ids, 500):
                    placeholders = ",".join(["%s"] * len(chunk))

                    cur.execute(
                        "SELECT header_id, fai, usl, lsl FROM ipqc_omm_result WHERE header_id IN (%s) ORDER BY header_id, fai" % placeholders,
                        chunk,
                    )
                    for row in cur.fetchall():
                        hid = int(row.get("header_id") or 0)
                        fai = str(row.get("fai") or "")
                        if fai and fai not in fai_order:
                            fai_order[fai] = None
                        result_by_key[(hid, fai)] = {
                            "usl": row.get("usl"),
                            "lsl": row.get("lsl"),
                        }

                    cur.execute(
                        "SELECT header_id, fai, row_index, value FROM ipqc_omm_measurements WHERE header_id IN (%s) ORDER BY header_id, fai, row_index" % placeholders,
                        chunk,
                    )
                    for row in cur.fetchall():
                        hid = int(row.get("header_id") or 0)
                        fai = str(row.get("fai") or "")
                        idx = int(row.get("row_index") or 0)
                        val = row.get("value")
                        if fai and fai not in fai_order:
                            fai_order[fai] = None
                        meas_by_key[(hid, fai)][idx] = val
                        if (hid, fai) not in result_by_key:
                            result_by_key[(hid, fai)] = {"usl": None, "lsl": None}
            finally:
                cur.close()
        finally:
            conn.close()

        rows = []
        for fai in fai_order.keys():
            row = {"fai": fai, "usl": None, "lsl": None}
            for tc in selected_tcs:
                chosen = tc_to_header.get(tc)
                if chosen is None:
                    row[tc + "_1"] = None
                    row[tc + "_2"] = None
                    row[tc + "_3"] = None
                    continue

                hid = int(chosen["header_id"])
                result_info = result_by_key.get((hid, fai), {})
                if row.get("usl") is None and result_info.get("usl") is not None:
                    row["usl"] = result_info.get("usl")
                if row.get("lsl") is None and result_info.get("lsl") is not None:
                    row["lsl"] = result_info.get("lsl")

                idx_vals = meas_by_key.get((hid, fai), {})
                row[tc + "_1"] = idx_vals.get(1)
                row[tc + "_2"] = idx_vals.get(2)
                row[tc + "_3"] = idx_vals.get(3)
            rows.append(row)

        columns = list(BASE_COLUMNS)
        for tc in selected_tcs:
            columns.append(tc + "_1")
            columns.append(tc + "_2")
            columns.append(tc + "_3")
        return columns, rows


class App(tk.Tk):
    def __init__(self):
        tk.Tk.__init__(self)
        self.title(APP_TITLE)
        self.geometry("1600x860")
        self.minsize(1200, 720)

        self.columns = []
        self.rows = []

        today = datetime.date.today().strftime("%Y-%m-%d")
        self.start_date_var = tk.StringVar(value=today)
        self.end_date_var = tk.StringVar(value=today)
        self.model_var = tk.StringVar(value="ALL")
        self.tools_var = tk.StringVar(value="A")
        self.cavities_var = tk.StringVar(value="1-4")
        self.status_var = tk.StringVar(value="대기 중")

        self._build_ui()
        self._fill_defaults()

    def _build_ui(self):
        self.columnconfigure(0, weight=1)
        self.rowconfigure(1, weight=1)

        filter_frame = ttk.LabelFrame(self, text="OMM 조회 조건")
        filter_frame.grid(row=0, column=0, sticky="ew", padx=8, pady=(8, 4))
        for c in range(12):
            filter_frame.columnconfigure(c, weight=1)

        add_labeled_entry(filter_frame, "시작일", self.start_date_var, 0, 0, 12)
        add_labeled_entry(filter_frame, "종료일", self.end_date_var, 0, 2, 12)

        ttk.Label(filter_frame, text="모델").grid(row=0, column=4, sticky="w", padx=(8, 4), pady=6)
        model_cb = ttk.Combobox(filter_frame, textvariable=self.model_var, values=MODEL_OPTIONS, state="readonly", width=24)
        model_cb.grid(row=0, column=5, sticky="ew", padx=(0, 8), pady=6)

        add_labeled_entry(filter_frame, "툴", self.tools_var, 0, 8, 18)
        add_labeled_entry(filter_frame, "캐비티", self.cavities_var, 0, 10, 12)

        tc_frame = ttk.LabelFrame(self, text="Tool#Cavity 설정")
        tc_frame.grid(row=1, column=0, sticky="nsew", padx=8, pady=4)
        tc_frame.columnconfigure(0, weight=0)
        tc_frame.columnconfigure(1, weight=1)
        tc_frame.rowconfigure(1, weight=1)

        helper_frame = ttk.Frame(tc_frame)
        helper_frame.grid(row=0, column=0, columnspan=2, sticky="ew", padx=6, pady=(6, 4))
        helper_frame.columnconfigure(1, weight=1)

        ttk.Label(helper_frame, text="FAI명은 세로 유지 / Tool#Cavity 3칸씩 오른쪽 누적").grid(row=0, column=0, sticky="w")
        ttk.Button(helper_frame, text="툴/캐비티로 목록 생성", command=self.generate_tc).grid(row=0, column=1, sticky="e", padx=4)
        ttk.Button(helper_frame, text="조회", command=self.run_query).grid(row=0, column=2, sticky="e", padx=4)
        ttk.Button(helper_frame, text="CSV 저장", command=self.export_csv).grid(row=0, column=3, sticky="e", padx=4)
        ttk.Button(helper_frame, text="엑셀 저장", command=self.export_xlsx).grid(row=0, column=4, sticky="e", padx=4)

        left_box = ttk.Frame(tc_frame)
        left_box.grid(row=1, column=0, sticky="nsw", padx=(6, 3), pady=(0, 6))
        ttk.Label(left_box, text="Tool#Cavity 목록\n(쉼표 또는 줄바꿈 구분)").pack(anchor="w")
        self.tc_text = tk.Text(left_box, width=30, height=10, wrap="word")
        self.tc_text.pack(fill="y", expand=False, pady=(4, 0))

        preview_frame = ttk.Frame(tc_frame)
        preview_frame.grid(row=1, column=1, sticky="nsew", padx=(3, 6), pady=(0, 6))
        preview_frame.columnconfigure(0, weight=1)
        preview_frame.rowconfigure(0, weight=1)

        self.tree = ttk.Treeview(preview_frame, show="headings")
        self.tree.grid(row=0, column=0, sticky="nsew")
        ysb = ttk.Scrollbar(preview_frame, orient="vertical", command=self.tree.yview)
        xsb = ttk.Scrollbar(preview_frame, orient="horizontal", command=self.tree.xview)
        self.tree.configure(yscrollcommand=ysb.set, xscrollcommand=xsb.set)
        ysb.grid(row=0, column=1, sticky="ns")
        xsb.grid(row=1, column=0, sticky="ew")

        status_bar = ttk.Frame(self)
        status_bar.grid(row=2, column=0, sticky="ew", padx=8, pady=(0, 8))
        status_bar.columnconfigure(0, weight=1)
        ttk.Label(status_bar, textvariable=self.status_var).grid(row=0, column=0, sticky="w")

    def _fill_defaults(self):
        self.tc_text.delete("1.0", "end")
        self.tc_text.insert("1.0", "A#1, A#2, A#3, A#4")

    def make_service(self):
        return OMMReverseService(DB_HOSTS, DB_PORT, DB_USER, DB_PASS, DB_NAME)

    def generate_tc(self):
        try:
            tools = parse_tools(self.tools_var.get())
            cavities = parse_cavities(self.cavities_var.get())
            tc_list = []
            for tool in tools:
                for cav in cavities:
                    tc_list.append("%s#%s" % (tool, cav))
            self.tc_text.delete("1.0", "end")
            self.tc_text.insert("1.0", ", ".join(tc_list))
            self.status_var.set("Tool#Cavity %s개 생성" % len(tc_list))
        except Exception as exc:
            messagebox.showerror(APP_TITLE, str(exc))

    def run_query(self):
        try:
            start_date = normalize_date(self.start_date_var.get().strip())
            end_raw = self.end_date_var.get().strip()
            if not end_raw:
                end_raw = self.start_date_var.get().strip()
            end_date = normalize_date(end_raw)
            selected_tcs = parse_tc_text(self.tc_text.get("1.0", "end"))
            if not selected_tcs:
                raise ValueError("Tool#Cavity 목록이 비어 있음")

            self.status_var.set("조회 중...")
            self.update_idletasks()

            svc = self.make_service()
            headers = svc.fetch_headers(start_date, end_date, self.model_var.get().strip() or "ALL", selected_tcs)
            columns, rows = svc.fetch_wide_rows(headers, selected_tcs)

            self.columns = columns
            self.rows = rows
            self.refresh_tree(columns, rows)

            used_count = 0
            for tc in selected_tcs:
                found = False
                for h in headers:
                    if (h["tool"] + "#" + str(h["cavity"])) == tc:
                        found = True
                        break
                if found:
                    used_count += 1

            self.status_var.set("조회 완료: header %s건 / 사용 Tool#Cavity %s개 / FAI 행 %s건" % (len(headers), used_count, len(rows)))
        except Exception as exc:
            self.status_var.set("오류")
            messagebox.showerror(APP_TITLE, "조회 실패\n\n%s\n\n%s" % (exc, traceback.format_exc()))

    def refresh_tree(self, columns, rows):
        self.tree.delete(*self.tree.get_children())
        self.tree["columns"] = columns

        for col in columns:
            width = 110
            if col == "fai":
                width = 240
            elif col in ("usl", "lsl"):
                width = 90
            self.tree.heading(col, text=col)
            self.tree.column(col, width=width, anchor="center")

        preview_limit = 500
        for row in rows[:preview_limit]:
            values = [format_cell(row.get(col), "") for col in columns]
            self.tree.insert("", "end", values=values)

        if len(rows) > preview_limit:
            self.status_var.set("미리보기는 %s행까지만 표시됨 / 전체 %s행" % (preview_limit, len(rows)))

    def export_csv(self):
        if not self.columns or not self.rows:
            messagebox.showwarning(APP_TITLE, "먼저 조회부터 해줘")
            return

        path = filedialog.asksaveasfilename(
            title="CSV 저장",
            defaultextension=".csv",
            filetypes=[("CSV", "*.csv")],
            initialfile="omm_reverse_export.csv",
        )
        if not path:
            return

        f = open(path, "w", newline="", encoding="utf-8-sig")
        try:
            writer = csv.writer(f)
            writer.writerow(self.columns)
            for row in self.rows:
                writer.writerow([format_cell(row.get(col), "") for col in self.columns])
        finally:
            f.close()

        self.status_var.set("CSV 저장 완료: %s" % path)
        messagebox.showinfo(APP_TITLE, "CSV 저장 완료\n%s" % path)

    def export_xlsx(self):
        if not self.columns or not self.rows:
            messagebox.showwarning(APP_TITLE, "먼저 조회부터 해줘")
            return
        if not OPENPYXL_OK:
            messagebox.showerror(APP_TITLE, "openpyxl이 없어서 xlsx 저장 불가")
            return

        path = filedialog.asksaveasfilename(
            title="엑셀 저장",
            defaultextension=".xlsx",
            filetypes=[("Excel", "*.xlsx")],
            initialfile="omm_reverse_export.xlsx",
        )
        if not path:
            return

        wb = Workbook()
        ws = wb.active
        ws.title = "OMM"
        ws.append(self.columns)
        for row in self.rows:
            ws.append([normalize_excel_cell(row.get(col)) for col in self.columns])

        ws.freeze_panes = "A2"
        for idx in range(1, len(self.columns) + 1):
            col = self.columns[idx - 1]
            width = 15
            if col == "fai":
                width = 28
            elif col in ("usl", "lsl"):
                width = 12
            ws.column_dimensions[get_excel_col(idx)].width = width

        wb.save(path)
        self.status_var.set("엑셀 저장 완료: %s" % path)
        messagebox.showinfo(APP_TITLE, "엑셀 저장 완료\n%s" % path)


def normalize_excel_cell(value):
    if value is None:
        return None
    return value


def add_labeled_entry(parent, label, var, row, col, width):
    ttk.Label(parent, text=label).grid(row=row, column=col, sticky="w", padx=(8, 4), pady=6)
    ent = ttk.Entry(parent, textvariable=var, width=width)
    ent.grid(row=row, column=col + 1, sticky="ew", padx=(0, 8), pady=6)
    return ent


def parse_tools(text):
    raw = re.split(r"[\s,;/]+", text.strip())
    out = []
    for item in raw:
        item = item.strip().upper()
        if not item:
            continue
        out.append(item)
    if not out:
        raise ValueError("툴 입력이 비어 있음")
    return out


def parse_cavities(text):
    text = text.strip()
    if not text:
        raise ValueError("캐비티 입력이 비어 있음")

    out = []
    for part in re.split(r"[\s,;/]+", text):
        part = part.strip()
        if not part:
            continue
        if "-" in part:
            a, b = part.split("-", 1)
            a_i = int(a)
            b_i = int(b)
            if a_i <= b_i:
                out.extend(list(range(a_i, b_i + 1)))
            else:
                out.extend(list(range(a_i, b_i - 1, -1)))
        else:
            out.append(int(part))

    if not out:
        raise ValueError("캐비티 해석 실패")
    return out


def parse_tc_text(text):
    raw = re.split(r"[,\n\r\t;]+", text)
    out = []
    seen = set()
    for item in raw:
        s = item.strip().upper().replace(" ", "")
        if not s:
            continue
        m = re.match(r"^([A-Z0-9]+)#([0-9]+)$", s)
        if not m:
            raise ValueError("Tool#Cavity 형식 오류: %s" % item)
        norm = "%s#%s" % (m.group(1), int(m.group(2)))
        if norm not in seen:
            out.append(norm)
            seen.add(norm)
    return out


def normalize_date(text):
    text = text.strip()
    for fmt in ("%Y-%m-%d", "%Y/%m/%d", "%Y%m%d"):
        try:
            return datetime.datetime.strptime(text, fmt).strftime("%Y-%m-%d")
        except Exception:
            pass
    raise ValueError("날짜 형식 오류: %s" % text)


def format_cell(value, empty=""):
    if value is None:
        return empty
    if isinstance(value, float):
        s = ("%.6f" % value).rstrip("0").rstrip(".")
        return s
    return str(value)


def get_excel_col(n):
    out = ""
    while n > 0:
        n, rem = divmod(n - 1, 26)
        out = chr(65 + rem) + out
    return out


if __name__ == "__main__":
    app = App()
    app.mainloop()
