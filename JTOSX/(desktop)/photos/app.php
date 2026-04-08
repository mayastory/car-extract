<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('Photos');
?>
<div class="app-root">
  <div class="toolbar">
    <div class="title">Photos</div>
    <div class="spacer"></div>
    <input type="file" id="file" accept="image/*" style="display:none" />
    <button class="btn" id="btn-upload">Upload</button>
    <button class="btn" id="btn-refresh">Refresh</button>
  </div>
  <div class="content">
    <div class="grid" id="grid"></div>
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
  const grid = document.getElementById('grid');
  const input = document.getElementById('file');
  const btnU = document.getElementById('btn-upload');
  const btnR = document.getElementById('btn-refresh');

  async function load(){
    grid.innerHTML = '<div class="small" style="padding:12px;">Loading…</div>';
    const res = await fetch(url('/api/photos.php'), {cache:'no-store'});
    const data = await res.json();
    const items = Array.isArray(data.items)?data.items:[];
    grid.innerHTML='';
    if(!items.length){ grid.innerHTML = '<div class="small" style="padding:12px;">No photos yet.</div>'; return; }
    for(const it of items){
      const card = document.createElement('div');
      card.className='card';
      const img = document.createElement('img');
      img.src = it.url;
      img.loading = 'lazy';
      const body = document.createElement('div');
      body.className='card-body';
      body.innerHTML = `<div style="font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${it.name}</div>`;
      card.appendChild(img);
      card.appendChild(body);
      card.addEventListener('dblclick', ()=> window.OSX_APP?.openFile(it.file));
      grid.appendChild(card);
    }
  }

  btnR.addEventListener('click', load);
  btnU.addEventListener('click', ()=> input.click());
  input.addEventListener('change', async ()=>{
    const f = input.files && input.files[0];
    if(!f) return;
    const fd = new FormData();
    fd.append('file', f);
    await fetch(url('/api/photos.php'), {method:'POST', body: fd});
    input.value='';
    load();
  });

  load();
})();
</script>
<?php osx_app_footer(); ?>
