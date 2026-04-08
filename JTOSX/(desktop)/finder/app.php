<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('Finder');
?>
<div class="app-root" id="app">
  <div class="toolbar">
    <div class="title">Finder</div>
    <div class="spacer"></div>
    <button class="btn" id="btn-refresh">Refresh</button>
  </div>

  <div class="split">
    <div class="sidebar">
      <div class="list">
        <div class="list-item active" data-src="documents">Documents</div>
        <div class="list-item" data-src="uploads">Uploads</div>
      </div>
    </div>
    <div class="content">
      <div class="list" id="files"></div>
    </div>
  </div>
</div>

<script>
(function(){

  const BASE = (window.OSX_APP?.base || window.OSX_BASE || '').replace(/\/$/, '');
  const url = (p) => {
    if (!p) return BASE + '/';
    if (p.startsWith('http://') || p.startsWith('https://')) return p;
    if (!p.startsWith('/')) p = '/' + p;
    return BASE + p;
  };
  const filesEl = document.getElementById('files');
  const btn = document.getElementById('btn-refresh');
  let current = 'documents';

  function setActive(){
    document.querySelectorAll('.sidebar .list-item').forEach(el=>{
      el.classList.toggle('active', el.getAttribute('data-src')===current);
    });
  }

  function render(items){
    filesEl.innerHTML='';
    if (!items.length){
      filesEl.innerHTML = '<div class="small" style="padding:10px;">No files.</div>';
      return;
    }
    for (const it of items){
      const row = document.createElement('div');
      row.className='list-item';
      row.innerHTML = `<div style="min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${it.name}</div>`+
                      `<div class="small" style="margin-left:auto;">${(it.ext||'').toUpperCase()}</div>`;
      row.addEventListener('dblclick', ()=>{
        window.OSX_APP?.openFile(it.file);
      });
      row.addEventListener('click', ()=>{
        window.OSX_APP?.setTitle('Finder — ' + it.name);
      });
      filesEl.appendChild(row);
    }
  }

  async function load(){
    setActive();
    filesEl.innerHTML = '<div class="small" style="padding:10px;">Loading…</div>';
    const res = await fetch(url('/api/files.php?src=') + encodeURIComponent(current), {cache:'no-store'});
    const data = await res.json();
    render(Array.isArray(data.items)?data.items:[]);
  }

  document.querySelectorAll('.sidebar .list-item').forEach(el=>{
    el.addEventListener('click', ()=>{ current = el.getAttribute('data-src'); load(); });
  });
  btn.addEventListener('click', load);

  load();
})();
</script>
<?php osx_app_footer(); ?>
