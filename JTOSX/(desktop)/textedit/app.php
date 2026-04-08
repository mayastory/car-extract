<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('TextEdit');
?>
<div class="app-root">
  <div class="toolbar">
    <div class="title" id="title">TextEdit</div>
    <div class="spacer"></div>
    <button class="btn" id="btn-open">Open…</button>
    <button class="btn" id="btn-save">Save</button>
  </div>
  <div class="content" style="padding:0;">
    <textarea class="editor" id="editor" spellcheck="false"></textarea>
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
  const ed = document.getElementById('editor');
  const btnOpen = document.getElementById('btn-open');
  const btnSave = document.getElementById('btn-save');

  let currentFile = 'untitled.txt';

  async function load(fp){
    currentFile = fp;
    titleEl.textContent = 'TextEdit — ' + fp.split('/').pop();
    window.OSX_APP?.setTitle(titleEl.textContent);

    const res = await fetch(url('/api/textedit.php?file=') + encodeURIComponent(fp), {cache:'no-store'});
    if(!res.ok){
      ed.value = '';
      return;
    }
    const data = await res.json();
    ed.value = data.content || '';
  }

  async function save(){
    const res = await fetch(url('/api/textedit.php'), {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({file: currentFile, content: ed.value})});
    const data = await res.json();
    if(data.ok){
      alert('Saved to ' + (data.saved||''));
    } else {
      alert('Save failed: ' + (data.error||''));
    }
  }

  btnOpen.addEventListener('click', ()=>{
    const fp = prompt('Enter file path (e.g. documents/readme.txt):', currentFile);
    if(fp) load(fp);
  });

  btnSave.addEventListener('click', save);

  window.addEventListener('osx:navigate', (e)=>{
    const d = e.detail||{};
    if(d.filePath) load(d.filePath);
  });

  // default
  load(currentFile);
})();
</script>
<?php osx_app_footer(); ?>
