<?php
// excel_viewer.php
// ✅ 엑셀 결과물/템플릿을 서버에서 바로 보는 뷰어
//  - 좌측(숨김 가능) 트리에서 폴더/파일 선택
//  - 우측에 excel_viewer_file.php 를 iframe으로 띄움

declare(strict_types=1);
@date_default_timezone_set('Asia/Seoul');
session_start();

require_once __DIR__ . '/config/dp_config.php';
require_once __DIR__ . '/lib/auth_guard.php';
dp_auth_guard();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/excel_viewer_lib.php';

// 기본 오픈 루트: exports (없으면 첫 번째 루트)
$roots = ev_allowed_roots();
$defaultRootKey = isset($roots['exports']) ? 'exports' : (array_key_first($roots) ?: '');
$defaultRootEnc = $defaultRootKey !== '' ? ev_b64u_enc($defaultRootKey) : '';

?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Excel Viewer</title>
  <style>
    :root{ --bg:#0f1115; --panel:#151923; --panel2:#0b0d12; --line:#2a3242; --txt:#e7e9ee; --muted:#9aa3b2; --accent:#2d6cdf; }
    html,body{height:100%;}
    body{margin:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Apple SD Gothic Neo","Malgun Gothic",sans-serif;}
    .layout{display:flex;height:100%;}
    .side{width:320px;min-width:260px;max-width:50vw;background:var(--panel);border-right:1px solid var(--line);display:flex;flex-direction:column;transition:transform .18s ease;}
    .side.hidden{transform:translateX(-100%);}
    .topbar{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);background:rgba(21,25,35,.85);backdrop-filter: blur(8px);}
    .btn{border:1px solid var(--line);background:#111523;color:var(--txt);padding:8px 10px;border-radius:10px;font-size:12px;cursor:pointer;}
    .btn:hover{filter:brightness(1.08)}
    .title{font-weight:800;}
    .muted{color:var(--muted);font-size:12px;}
    .tree{padding:10px 8px;overflow:auto;}
    .node{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:10px;cursor:pointer;user-select:none;}
    .node:hover{background:rgba(255,255,255,.06)}
    .node .icon{width:18px;text-align:center;opacity:.9}
    .node .name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;}
    .children{margin-left:18px;border-left:1px dashed rgba(255,255,255,.12);padding-left:10px;display:none;}
    .children.open{display:block;}
    .node.file{color:#dbe3ff}
    .node.active{outline:1px solid rgba(45,108,223,.6);background:rgba(45,108,223,.12)}
    .main{flex:1;display:flex;flex-direction:column;min-width:0;}
    .mainbar{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);background:rgba(15,17,21,.85);backdrop-filter: blur(8px);}
    .mainbar .path{font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    iframe{flex:1;width:100%;border:0;background:var(--bg);} 
    .empty{padding:16px;color:var(--muted);}
    @media (max-width: 900px){
      .side{position:fixed;z-index:50;left:0;top:0;bottom:0;box-shadow: 8px 0 30px rgba(0,0,0,.4);} 
    }
  </style>
</head>
<body>

<div class="layout">
  <div id="side" class="side">
    <div class="topbar">
      <div class="title">Excel Viewer</div>
      <div class="muted">(서버 파일)</div>
      <div style="margin-left:auto;display:flex;gap:6px;">
        <button class="btn" id="btnRefresh">새로고침</button>
        <button class="btn" id="btnHide">숨기기</button>
      </div>
    </div>
    <div id="tree" class="tree">
      <div class="muted" style="padding:10px 8px;">루트 폴더 로딩 중...</div>
    </div>
  </div>

  <div class="main">
    <div class="mainbar">
      <button class="btn" id="btnShow">☰</button>
      <div class="path" id="curPath">파일을 선택하면 오른쪽에 표시돼.</div>
    </div>
    <iframe id="viewer" src="about:blank"></iframe>
    <div id="empty" class="empty" style="display:none;">표시할 파일이 없음</div>
  </div>
</div>

<script>
  const side = document.getElementById('side');
  const btnHide = document.getElementById('btnHide');
  const btnShow = document.getElementById('btnShow');
  const btnRefresh = document.getElementById('btnRefresh');
  const tree = document.getElementById('tree');
  const viewer = document.getElementById('viewer');
  const curPath = document.getElementById('curPath');

  let activeNode = null;

  function setActive(el){
    if(activeNode) activeNode.classList.remove('active');
    activeNode = el;
    if(activeNode) activeNode.classList.add('active');
  }

  btnHide.addEventListener('click', ()=> side.classList.add('hidden'));
  btnShow.addEventListener('click', ()=> side.classList.toggle('hidden'));
  btnRefresh.addEventListener('click', ()=> loadRoots(true));

  async function api(url){
    const r = await fetch(url, {credentials:'same-origin'});
    return await r.json();
  }

  function icon(type, open){
    if(type==='dir') return open ? '📂' : '📁';
    return '📄';
  }

  function mkNode(item){
    const row = document.createElement('div');
    row.className = 'node ' + (item.type==='file'?'file':'dir');
    row.dataset.p = item.p;
    row.dataset.type = item.type;
    const ic = document.createElement('div');
    ic.className = 'icon';
    ic.textContent = icon(item.type, false);
    const nm = document.createElement('div');
    nm.className = 'name';
    nm.textContent = item.name;
    row.appendChild(ic);
    row.appendChild(nm);

    if(item.type==='dir'){
      const children = document.createElement('div');
      children.className = 'children';
      row.addEventListener('click', async (e)=>{
        e.stopPropagation();
        const isOpen = children.classList.contains('open');
        if(isOpen){
          children.classList.remove('open');
          ic.textContent = icon('dir', false);
          return;
        }
        // load if empty
        if(children.childElementCount===0){
          row.style.opacity = '0.8';
          const res = await api('excel_viewer_api.php?op=list&p=' + encodeURIComponent(item.p));
          row.style.opacity = '1';
          if(!res.ok){
            alert('폴더 로드 실패: ' + (res.err||''));
            return;
          }
          for(const it of res.items){
            children.appendChild(mkNode(it));
          }
        }
        children.classList.add('open');
        ic.textContent = icon('dir', true);
      });
      const wrap = document.createElement('div');
      wrap.appendChild(row);
      wrap.appendChild(children);
      return wrap;
    } else {
      row.addEventListener('click', (e)=>{
        e.stopPropagation();
        setActive(row);
        curPath.textContent = item.name;
        viewer.src = 'excel_viewer_file.php?p=' + encodeURIComponent(item.p);
      });
      return row;
    }
  }

  async function loadRoots(force){
    tree.innerHTML = '<div class="muted" style="padding:10px 8px;">로딩 중...</div>';
    const res = await api('excel_viewer_api.php?op=roots');
    if(!res.ok){
      tree.innerHTML = '<div class="muted" style="padding:10px 8px;">루트 로드 실패</div>';
      return;
    }
    tree.innerHTML = '';
    for(const r of res.roots){
      // roots는 type이 없으니 dir로 가공
      const item = {type:'dir', name:r.name, p:r.key};
      tree.appendChild(mkNode(item));
    }
    // 기본 루트 자동 오픈
    if('<?= ev_h($defaultRootEnc) ?>'){
      const def = tree.querySelector('[data-p="<?= ev_h($defaultRootEnc) ?>"]');
      if(def) def.click();
    }
  }

  loadRoots(false);
</script>

</body>
</html>
