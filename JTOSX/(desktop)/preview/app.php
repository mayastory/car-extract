<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('Preview');
?>
<div class="app-root">
  <div class="toolbar">
    <div class="title" id="title">Preview</div>
    <div class="spacer"></div>
    <button class="btn" id="btn-open">Open…</button>
  </div>
  <div class="content" style="padding:0;">
    <iframe id="frame" style="width:100%;height:100%;border:0;background:rgba(255,255,255,.8);"></iframe>
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
  const titleEl = document.getElementById('title');
  const frame = document.getElementById('frame');
  const btn = document.getElementById('btn-open');

  function setFile(fp){
    if(!fp) return;
    const name = fp.split('/').pop();
    titleEl.textContent = 'Preview — ' + name;
    window.OSX_APP?.setTitle('Preview — ' + name);
    frame.src = url('/' + fp.replace(/^\/+/, ''));
  }

  btn.addEventListener('click', ()=>{
    const fp = prompt('Enter file path (e.g. documents/xxx.pdf):','documents/');
    if(fp) setFile(fp);
  });

  window.addEventListener('osx:navigate', (e)=>{
    const d = e.detail||{};
    if(d.filePath) setFile(d.filePath);
  });
})();
</script>
<?php osx_app_footer(); ?>
