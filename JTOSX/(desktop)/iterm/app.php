<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('iTerm');
?>
<div class="app-root" style="background: rgba(0,0,0,.86); color: rgba(255,255,255,.92);">
  <div class="toolbar" style="background: rgba(0,0,0,.55); border-bottom-color: rgba(255,255,255,.08);">
    <div class="title" style="color: rgba(255,255,255,.92);">iTerm</div>
    <div class="spacer"></div>
    <button class="btn" id="btn-clear" style="background: rgba(255,255,255,.08); color: rgba(255,255,255,.92); border-color: rgba(255,255,255,.12);">Clear</button>
  </div>
  <div id="out" style="flex:1; overflow:auto; padding:12px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px;"></div>
  <div style="border-top:1px solid rgba(255,255,255,.08); padding:10px; display:flex; gap:8px;">
    <span style="color: rgba(0,212,85,.95);">❯</span>
    <input id="inp" style="flex:1; background: transparent; border:0; outline:none; color: rgba(255,255,255,.92); font: inherit;" placeholder="type a command (demo)" />
  </div>
</div>
<script>
(function(){
  const out = document.getElementById('out');
  const inp = document.getElementById('inp');
  const clear = document.getElementById('btn-clear');

  function line(s){
    const d = document.createElement('div');
    d.textContent = s;
    out.appendChild(d);
    out.scrollTop = out.scrollHeight;
  }

  function run(cmd){
    line('❯ ' + cmd);
    if(cmd==='help'){
      line('demo commands: help, date, echo <text>, open <appId>');
    } else if(cmd==='date'){
      line(new Date().toString());
    } else if(cmd.startsWith('echo ')){
      line(cmd.slice(5));
    } else if(cmd.startsWith('open ')){
      const appId = cmd.slice(5).trim();
      window.OSX_APP?.openApp(appId, {});
      line('(opened ' + appId + ')');
    } else {
      line('command not found: ' + cmd);
    }
  }

  inp.addEventListener('keydown', (e)=>{
    if(e.key==='Enter'){
      const cmd = inp.value.trim();
      inp.value='';
      if(cmd) run(cmd);
    }
  });
  clear.addEventListener('click', ()=>{ out.innerHTML=''; });

  line('iTerm (PHP/JS demo) — type help');
})();
</script>
<?php osx_app_footer(); ?>
