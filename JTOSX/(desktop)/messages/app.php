<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('Messages');
?>
<div class="app-root">
  <div class="toolbar">
    <div class="title">Messages</div>
    <div class="spacer"></div>
    <button class="btn" id="btn-clear">Clear</button>
  </div>
  <div class="msg-wrap" id="msgs"></div>
  <div class="msg-input">
    <textarea id="text" placeholder="iMessage (local demo)…"></textarea>
    <button class="btn" id="send">Send</button>
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
  const wrap = document.getElementById('msgs');
  const text = document.getElementById('text');
  const send = document.getElementById('send');
  const clear = document.getElementById('btn-clear');

  function row(m){
    const r = document.createElement('div');
    r.className = 'msg-row ' + (m.from==='me'?'me':'them');
    const b = document.createElement('div');
    b.className = 'msg-bubble';
    b.textContent = m.text;
    r.appendChild(b);
    return r;
  }

  async function load(){
    const res = await fetch(url('/api/messages.php'), {cache:'no-store'});
    const data = await res.json();
    const msgs = Array.isArray(data.messages)?data.messages:[];
    wrap.innerHTML='';
    for (const m of msgs) wrap.appendChild(row(m));
    wrap.scrollTop = wrap.scrollHeight;
  }

  async function post(){
    const t = text.value.trim();
    if (!t) return;
    text.value='';
    await fetch(url('/api/messages.php'), {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({from:'me', text:t})});
    // simple bot echo
    await fetch(url('/api/messages.php'), {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({from:'them', text:'(demo) '+t})});
    load();
  }

  send.addEventListener('click', post);
  text.addEventListener('keydown', (e)=>{ if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); post(); }});
  clear.addEventListener('click', async ()=>{
    await fetch(url('/api/messages.php'), {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({clear:true})});
    load();
  });

  load();
})();
</script>
<?php osx_app_footer(); ?>
