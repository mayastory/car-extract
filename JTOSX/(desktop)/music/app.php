<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('Music');
?>
<div class="app-root">
  <div class="toolbar">
    <div class="title">Music</div>
    <div class="spacer"></div>
    <input type="file" id="file" accept="audio/*" style="display:none" />
    <button class="btn" id="btn-upload">Upload</button>
    <button class="btn" id="btn-refresh">Refresh</button>
  </div>
  <div class="split">
    <div class="sidebar"><div class="list" id="list"></div></div>
    <div class="content" style="padding:12px;">
      <div style="font-weight:700;" id="now">Nothing playing</div>
      <div style="height:10px"></div>
      <audio id="player" controls style="width:100%;"></audio>
      <div class="small" style="margin-top:10px;">Local demo library (uploads/music)</div>
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
  const list = document.getElementById('list');
  const player = document.getElementById('player');
  const now = document.getElementById('now');
  const input = document.getElementById('file');
  document.getElementById('btn-refresh').addEventListener('click', load);
  document.getElementById('btn-upload').addEventListener('click', ()=>input.click());

  input.addEventListener('change', async ()=>{
    const f = input.files && input.files[0];
    if(!f) return;
    const fd = new FormData();
    fd.append('file', f);
    await fetch(url('/api/music.php'), {method:'POST', body: fd});
    input.value='';
    load();
  });

  async function load(){
    const res = await fetch(url('/api/music.php'), {cache:'no-store'});
    const data = await res.json();
    const items = Array.isArray(data.items)?data.items:[];
    list.innerHTML='';
    if(!items.length){ list.innerHTML='<div class="small" style="padding:10px;">No music.</div>'; return; }
    for(const it of items){
      const row = document.createElement('div');
      row.className='list-item';
      row.textContent = it.name;
      row.addEventListener('click', ()=>{
        player.src = it.url;
        player.play();
        now.textContent = it.name;
        window.OSX_APP?.setTitle('Music — ' + it.name);
      });
      list.appendChild(row);
    }
  }

  load();
})();
</script>
<?php osx_app_footer(); ?>
