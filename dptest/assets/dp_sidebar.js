// assets/dp_sidebar.js
// ✅ 슬라이드 열기/닫기 + ESC + 바깥 클릭
// FIX: openPanel/closePanel scope (ReferenceError 방지)

(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }

  var bd = null;
  var panel = null;

  function openPanel(){
    if (!bd || !panel) return;
    bd.hidden = false;
    panel.hidden = false;
    requestAnimationFrame(function(){
      bd.classList.add('show');
      panel.classList.add('show');
      document.documentElement.classList.add('dp-side-open');
    });
  }

  function closePanel(){
    if (!bd || !panel) return;
    bd.classList.remove('show');
    panel.classList.remove('show');
    document.documentElement.classList.remove('dp-side-open');
    window.setTimeout(function(){
      bd.hidden = true;
      panel.hidden = true;
    }, 250);
  }

  function init(){
    bd = qs('#dpSideBackdrop');
    panel = qs('#dpSidePanel');
    if (!bd || !panel) return;

    document.addEventListener('click', function(e){
      var t = e.target;
      if (!t) return;

      if (t.closest('[data-dp-open]')) { openPanel(); return; }
      if (t.closest('[data-dp-close]')) { closePanel(); return; }
      if (t === bd) { closePanel(); return; }
    });

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') closePanel();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // 외부에서 제어할 수 있게 노출
  window.DP_SIDEBAR = {
    open: openPanel,
    close: closePanel,
    isOpen: function(){ return document.documentElement.classList.contains('dp-side-open'); }
  };
})();
