(function(){
  const canvas = document.getElementById('gameCanvas');
  const engine = new window.FRLGEngine(canvas);
  const statePill = document.getElementById('statePill');
  const scenePill = document.getElementById('scenePill');
  const savePill = document.getElementById('savePill');
  engine.onStateChange = (s)=>{ statePill.textContent = s; scenePill.textContent = s; };
  engine.onSaveLabelChange = (label)=>{ savePill.textContent = label; };

  const repeatTimers = new Map();
  function send(key){ engine.handleInput(key); }
  function startRepeat(key){
    send(key);
    if (repeatTimers.has(key)) return;
    const t = setTimeout(()=>{
      const i = setInterval(()=>send(key), 80);
      repeatTimers.set(key, i);
    }, 240);
    repeatTimers.set(key, t);
  }
  function stopRepeat(key){
    const id = repeatTimers.get(key);
    if (id != null){ clearTimeout(id); clearInterval(id); repeatTimers.delete(key); }
  }

  window.addEventListener('keydown', (e)=>{
    const key = e.key;
    if (['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Enter','Escape','Backspace',' ','r','R'].includes(key)){
      e.preventDefault(); send(key);
    }
  }, {passive:false});

  document.querySelectorAll('[data-key]').forEach(btn=>{
    const key = btn.dataset.key;
    const isDir = key.startsWith('Arrow');
    const down = (e)=>{ e.preventDefault(); isDir ? startRepeat(key) : send(key); };
    const up = (e)=>{ e.preventDefault(); if (isDir) stopRepeat(key); };
    btn.addEventListener('pointerdown', down, {passive:false});
    btn.addEventListener('pointerup', up, {passive:false});
    btn.addEventListener('pointerleave', up, {passive:false});
    btn.addEventListener('pointercancel', up, {passive:false});
    btn.addEventListener('touchstart', down, {passive:false});
    btn.addEventListener('touchend', up, {passive:false});
    btn.addEventListener('touchcancel', up, {passive:false});
  });
})();
