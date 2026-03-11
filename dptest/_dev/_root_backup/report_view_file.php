<?php
// report_view_file.php (v34_9 ReportView JS RenderFix)
// ✅ report_view.php 전용 단일 파일 뷰어
// - report_finish.id 폴더(rf_{id}) 밖의 파일은 절대 못 읽게 제한
// - JS(Web) 파싱 = 빠름 (CDN 미사용, 로컬 SheetJS)
// - 병합/열너비/행높이 반영(이전보다 엑셀 형태에 가깝게)

declare(strict_types=1);
@date_default_timezone_set('Asia/Seoul');
session_start();

require_once __DIR__ . '/config/dp_config.php';
require_once __DIR__ . '/lib/auth_guard.php';
dp_auth_guard();
require_once __DIR__ . '/lib/oqc_report.php';

// ---- Web app base (/dptest) ----
$sn = $_SERVER['SCRIPT_NAME'] ?? '';
$seg = explode('/', trim($sn, '/'));
$app = $seg[0] ?? '';
$APP_BASE = $app !== '' ? '/' . $app : '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---- Params ----
$id = (int)($_GET['id'] ?? 0);
$f  = (string)($_GET['f'] ?? '');
$f  = str_replace('\\', '/', $f);
$f  = ltrim($f, '/');

if ($id <= 0 || $f === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Missing parameter (id,f)";
  exit;
}

// ---- Resolve safe absolute path (inside rf_{id}) ----
$baseAbs  = report_files_abs($id);
$baseReal = realpath($baseAbs);
if ($baseReal === false || !is_dir($baseReal)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Report folder not found";
  exit;
}

// disallow traversal
if (strpos($f, '..') !== false) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Invalid path";
  exit;
}

$abs = realpath($baseReal . DIRECTORY_SEPARATOR . $f);
if ($abs === false || strpos($abs, $baseReal) !== 0 || !is_file($abs)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "File not found";
  exit;
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$raw = !empty($_GET['raw']);
$download = !empty($_GET['download']);

if ($download || $raw) {
  $fn = basename($abs);

  // IMPORTANT:
  // When serving a PDF via PHP (raw=1), many browsers (and PDF.js)
  // fall back to the URL path (report_view_file.php) as the download name
  // unless Content-Disposition provides a filename.
  // So we must always set Content-Disposition with the real filename.
  $cdName = str_replace('"', '', $fn);
  $cdUtf8 = rawurlencode($fn);
  if ($ext === 'pdf') {
    header('Content-Type: application/pdf');
  } elseif ($ext === 'zip') {
    header('Content-Type: application/zip');
  } elseif (in_array($ext, ['txt','log','json','csv'], true)) {
    header('Content-Type: text/plain; charset=utf-8');
  } else {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  }
  if ($download) {
    header('Content-Disposition: attachment; filename="' . $cdName . '"; filename*=UTF-8\'\'' . $cdUtf8);
  } else {
    // inline preview (raw=1)
    header('Content-Disposition: inline; filename="' . $cdName . '"; filename*=UTF-8\'\'' . $cdUtf8);
  }
  header('Content-Length: ' . filesize($abs));
  readfile($abs);
  exit;
}

$self = $APP_BASE . '/report_view_file.php';

// ✅ 서버/환경에 따라 PATH_INFO(report_view_file.php/filename.pdf)가 404/리라이트로 깨질 수 있어
//    (모달/iframe 안에서는 특히 "다른 페이지가 뜨는" 증상으로 보일 수 있음)
//    그래서 raw/다운로드 URL은 **항상 쿼리스트링 방식**으로 고정한다.
$rawUrl = $self . '?raw=1&id=' . $id . '&f=' . rawurlencode($f);
$dlUrl  = $self . '?download=1&id=' . $id . '&f=' . rawurlencode($f);
if ($ext === 'zip') {
  // ZIP은 미리보기 대신 다운로드 안내
  ?><!doctype html>
  <html lang="ko"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=h(basename($f))?></title>
    <style>
      body{margin:0;font-family:ui-sans-serif,system-ui,Segoe UI,Roboto,"Noto Sans KR","Malgun Gothic",Arial;background:#0b0d10;color:#e7eef8}
      .card{max-width:680px;margin:24px auto;padding:18px;border:1px solid rgba(255,255,255,.14);border-radius:14px;background:rgba(255,255,255,.04)}
      a{color:#60a5fa;text-decoration:none}
      .btn{display:inline-flex;gap:8px;align-items:center;margin-top:12px;padding:8px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06)}
    </style>
  </head><body>
    <div class="card">
      <div style="font-weight:800;margin-bottom:8px;">ZIP 미리보기 불가</div>
      <div style="opacity:.85;font-size:13px;line-height:1.4;">
        이 파일은 ZIP 입니다. 아래 버튼으로 다운로드하세요.<br>
        파일: <b><?=h(basename($f))?></b>
      </div>
      <a class="btn" href="<?=h($dlUrl)?>">⬇️ 다운로드</a>
    </div>
  </body></html><?php
  exit;
}

if ($ext === 'pdf') {
  ?><!doctype html>
  <html lang="ko"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=h(basename($f))?></title>
    <style>
      html,body{height:100%;margin:0;background:#0b0d10;color:#e7eef8;font-family:ui-sans-serif,system-ui,Segoe UI,Roboto,"Apple SD Gothic Neo","Noto Sans KR","Malgun Gothic",Arial;}
      .top{height:44px;display:flex;align-items:center;gap:10px;padding:0 12px;border-bottom:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03)}
      .btn{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);color:#e7eef8;text-decoration:none;font-size:13px}
      .name{font-weight:700;font-size:13px;opacity:.9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .frame{height:calc(100% - 44px);}
      embed{width:100%;height:100%;border:0;background:#111;}
    </style>
  </head><body>
    <div class="top">
      <a class="btn" href="<?=h($dlUrl)?>">⬇️ 다운로드</a>
      <div class="name"><?=h(basename($f))?></div>
    </div>
    <div class="frame"><embed src="<?=h($rawUrl)?>" type="application/pdf"></embed></div>
  </body></html><?php
  exit;
}


?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?=h(basename($f))?></title>
  <style>
    :root{
      --bg:#fff;
      --text:#0f172a;
      --muted:#475569;
      --border:rgba(15,23,42,.14);
      --shadow: 0 10px 24px rgba(2,6,23,.12);
      --radius:14px;
      --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      --sans: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, "Apple SD Gothic Neo", "Noto Sans KR", "Malgun Gothic", Arial, sans-serif;
    }
    *{box-sizing:border-box}
    html,body{height:100%; margin:0; background:var(--bg); color:var(--text); font-family:var(--sans);}
    .top{
      position:sticky; top:0; z-index:999;
      background:linear-gradient(180deg, rgba(255,255,255,.98), rgba(255,255,255,.92));
      border-bottom:1px solid var(--border);
      padding:10px 12px;
    }
    .row{display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;}
    .path{
      font-size:12px; color:var(--muted);
      font-family:var(--mono);
      overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
      max-width: min(980px, 78vw);
    }
    .btns{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
    .btn{
      display:inline-flex; align-items:center; gap:8px;
      padding:7px 10px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
      color:var(--text);
      font-size:12px;
      text-decoration:none;
      box-shadow: 0 6px 16px rgba(2,6,23,.06);
      cursor:pointer;
    }
    .btn:hover{border-color:rgba(59,130,246,.45)}
    .tabs{display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:10px;}
    .tab{
      display:inline-flex; align-items:center;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid var(--border);
      background:#fff;
      color:var(--text);
      font-size:12px;
      text-decoration:none;
      cursor:pointer;
    }
    .tab.on{border-color:rgba(59,130,246,.55); box-shadow: 0 10px 24px rgba(59,130,246,.15);}
    .wrap{height:100%; display:flex; flex-direction:column;}
    #viewerWrap{flex:1; min-height:0; overflow:auto; padding:12px; background:#fff;}
    .loading{
      position:fixed; inset:0;
      display:flex; align-items:center; justify-content:center;
      background:rgba(255,255,255,.78);
      backdrop-filter: blur(6px);
      z-index:2000;
    }
    .card{
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:14px 16px;
      box-shadow: var(--shadow);
      background:#fff;
      max-width: 560px;
    }
    .card b{display:block; margin-bottom:8px}
    .card .small{font-size:12px;color:var(--muted); line-height:1.35}
    .err{
      margin-top:10px;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid rgba(239,68,68,.25);
      background:rgba(239,68,68,.05);
      color:#7f1d1d;
      font-size:12px;
      white-space:pre-wrap;
    }

    table.xl{
      border-collapse:collapse;
      table-layout:fixed;
      background:#fff;
    }
    table.xl td{
      border:1px solid rgba(15,23,42,.12);
      padding:2px 6px;
      font-size:12px;
      line-height:1.2;
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
      vertical-align:middle;
      background:#fff;
    }
    table.xl td.center{text-align:center}
    table.xl td.right{text-align:right}
    table.xl td.left{text-align:left}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="row">
      <div class="path">📄 <?=h($f)?></div>
      <div class="btns">
        <a class="btn" href="<?=h($dlUrl)?>">⬇️ 다운로드</a>
        <button class="btn" id="toggleFormula" type="button">🧮 수식 숨김: ON</button>
        <button class="btn" id="toggleFit" type="button">↔️ 너비맞춤: OFF</button>
      </div>
    </div>
    <div class="tabs" id="tabs"></div>
  </div>

  <div id="viewerWrap">
    <div id="viewer">로딩 중...</div>
  </div>
</div>

<div class="loading" id="loading" style="display:flex;">
  <div class="card">
    <b>엑셀 로딩 중…</b>
    <div class="small">
      - 브라우저에서 직접 XLSX 파싱(빠름)<br>
      - v34_9: report_view.php(± 트리)와 완전 호환 + 병합/열너비/행높이 반영
    </div>
    <div class="err" id="err" style="display:none;"></div>
  </div>
</div>

<!-- Local SheetJS -->
<script src="<?=h($APP_BASE)?>/assets/vendor/xlsx.full.min.js"></script>
<script>
const RAW_URL = <?=json_encode($rawUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;

let WB = null;
let sheetIndex = 0;
let hideFormula = true;
let fitWidth = false;

function setError(msg){
  const e=document.getElementById('err');
  e.style.display='block';
  e.textContent=msg;
}
function showLoading(on){
  document.getElementById('loading').style.display = on ? 'flex' : 'none';
}
function buildTabs(){
  const tabs = document.getElementById('tabs');
  tabs.innerHTML = '';
  WB.SheetNames.forEach((name, i)=>{
    const b=document.createElement('button');
    b.type='button';
    b.className='tab' + (i===sheetIndex ? ' on' : '');
    b.textContent = name;
    b.addEventListener('click', ()=>{
      sheetIndex = i;
      renderCurrent();
      buildTabs();
      document.getElementById('viewerWrap').scrollTop = 0;
    });
    tabs.appendChild(b);
  });
}

function escHtml(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#39;");
}
function cellDisplay(cell){
  if(!cell) return '';
  if(hideFormula && cell.f && (cell.v === undefined || cell.v === null || cell.v === '')){
    return '';
  }
  if(cell.w !== undefined) return cell.w;
  try{
    const s = XLSX.utils.format_cell(cell);
    if(s !== undefined) return s;
  }catch(e){}
  return cell.v ?? '';
}
function isNumberCell(cell){
  if(!cell) return false;
  return cell.t === 'n';
}
function colWidthPx(col){
  if(!col) return 80;
  if(typeof col.wpx === 'number' && col.wpx > 0) return Math.max(20, Math.min(800, col.wpx));
  if(typeof col.wch === 'number' && col.wch > 0) return Math.max(20, Math.min(800, Math.round(col.wch * 7 + 10)));
  if(typeof col.width === 'number' && col.width > 0) return Math.max(20, Math.min(800, Math.round(col.width * 7 + 10)));
  return 80;
}
function rowHeightPx(row){
  if(!row) return null;
  if(typeof row.hpx === 'number' && row.hpx > 0) return Math.max(12, Math.min(600, row.hpx));
  if(typeof row.hpt === 'number' && row.hpt > 0) return Math.max(12, Math.min(600, Math.round(row.hpt * 96/72)));
  return null;
}

function renderSheet(ws){
  const ref = ws['!ref'];
  if(!ref){
    return '<div style="color:#475569;font-size:13px;">(빈 시트)</div>';
  }
  const range = XLSX.utils.decode_range(ref);

  const merges = ws['!merges'] || [];
  const mergeStart = new Map();
  const covered = new Set();

  for(const m of merges){
    const r1=m.s.r, c1=m.s.c, r2=m.e.r, c2=m.e.c;
    mergeStart.set(r1+','+c1, {r1,c1,r2,c2});
    for(let r=r1; r<=r2; r++){
      for(let c=c1; c<=c2; c++){
        if(r===r1 && c===c1) continue;
        covered.add(r+','+c);
      }
    }
  }

  const cols = ws['!cols'] || [];
  const rows = ws['!rows'] || [];

  let colgroup = '<colgroup>';
  for(let c=range.s.c; c<=range.e.c; c++){
    const w = colWidthPx(cols[c]);
    colgroup += `<col style="width:${w}px">`;
  }
  colgroup += '</colgroup>';

  let html = `<table class="xl" style="${fitWidth?'width:100%;':'width:auto;'}">` + colgroup + '<tbody>';

  for(let r=range.s.r; r<=range.e.r; r++){
    const h = rowHeightPx(rows[r]);
    html += `<tr${h?` style="height:${h}px"`:''}>`;
    for(let c=range.s.c; c<=range.e.c; c++){
      const key = r+','+c;
      if(covered.has(key)) continue;

      const ms = mergeStart.get(key);
      let rowspan = 1, colspan = 1;
      if(ms){
        rowspan = (ms.r2 - ms.r1 + 1);
        colspan = (ms.c2 - ms.c1 + 1);
      }

      const addr = XLSX.utils.encode_cell({r,c});
      const cell = ws[addr];
      const text = cellDisplay(cell);

      let cls = isNumberCell(cell) ? 'right' : 'left';
      const center = (ms && String(text).length <= 12) ? ' center' : '';
      cls += center;

      html += `<td class="${cls}"${rowspan>1?` rowspan="${rowspan}"`:''}${colspan>1?` colspan="${colspan}"`:''}>${escHtml(text)}</td>`;
    }
    html += '</tr>';
  }

  html += '</tbody></table>';
  return html;
}

function renderCurrent(){
  const name = WB.SheetNames[sheetIndex];
  const ws = WB.Sheets[name];
  document.getElementById('viewer').innerHTML = renderSheet(ws);
}

async function loadXlsx(){
  if(typeof XLSX === 'undefined'){
    throw new Error('xlsx.full.min.js 로딩 실패 (<?=h($APP_BASE)?>/assets/vendor/xlsx.full.min.js 확인)');
  }
  const res = await fetch(RAW_URL, {cache:'no-store', credentials:'same-origin'});
  if(!res.ok) throw new Error('XLSX fetch 실패: HTTP ' + res.status);
  const buf = await res.arrayBuffer();
  WB = XLSX.read(buf, {type:'array', cellDates:true});
  sheetIndex = 0;
  buildTabs();
  renderCurrent();
}

document.getElementById('toggleFormula').addEventListener('click', ()=>{
  hideFormula = !hideFormula;
  document.getElementById('toggleFormula').textContent = hideFormula ? '🧮 수식 숨김: ON' : '🧮 수식 숨김: OFF';
  try { renderCurrent(); } catch(e){}
});
document.getElementById('toggleFit').addEventListener('click', ()=>{
  fitWidth = !fitWidth;
  document.getElementById('toggleFit').textContent = fitWidth ? '↔️ 너비맞춤: ON' : '↔️ 너비맞춤: OFF';
  try { renderCurrent(); } catch(e){}
});

(async ()=>{
  try{
    await loadXlsx();
    showLoading(false);
  }catch(err){
    setError(String(err && err.message ? err.message : err));
    showLoading(true);
  }
})();
</script>
</body>
</html>