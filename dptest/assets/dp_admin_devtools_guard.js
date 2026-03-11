// assets/dp_admin_devtools_guard.js
// ⚠️ 초보 사용자 필터(억제)용입니다. 브라우저로 내려간 소스 자체를 완전히 숨길 수는 없습니다.
(function () {
  if (window.__DP_ADMIN_DEVTOOLS_GUARD__) return;
  window.__DP_ADMIN_DEVTOOLS_GUARD__ = true;

  const cfg = window.DP_DEVTOOLS_GUARD || {};
  const redirectUrl = cfg.redirect || 'warn_devtools.html';

  function hardRedirect() {
    if (window.__dpDevtoolsKicked) return;
    window.__dpDevtoolsKicked = true;
    try { alert('경고: 개발자 도구(DevTools)가 감지되었습니다.'); } catch (_) {}
    try { window.location.href = redirectUrl; } catch (_) {}
  }

  function stop(e) {
    try { e.preventDefault(); } catch (_) {}
    try { e.stopPropagation(); } catch (_) {}
    try { e.stopImmediatePropagation && e.stopImmediatePropagation(); } catch (_) {}
    try { e.returnValue = false; } catch (_) {}
    return false;
  }

  // 우클릭 차단
  // 단, IPQC 그래프 빌더(Quick Graph) 모달에서는 우클릭(컨텍스트 메뉴)이 필요하므로 예외 처리.
  function allowContextMenuHere(e){
    try{
      // If Quick Graph overlay is open, always allow context menu (needed for JMP-like menu on SVG)
      const ov = document.getElementById('qgOverlay');
      if (ov && ov.getAttribute && ov.getAttribute('aria-hidden') === 'false') return true;
    }catch(_){ }

    try{
      // 그래프 빌더가 열릴 때 html/body에 qg-open 클래스가 붙습니다.
      if (document && document.documentElement && document.documentElement.classList && document.documentElement.classList.contains('qg-open')) return true;
    }catch(_){ }

    try{
      // Robust DOM walk (works even when SVGElement.closest is missing/blocked)
      let t = e && e.target;
      if (t && !t.closest && t.ownerSVGElement) t = t.ownerSVGElement;
      while (t){
        if (t.id === 'qgOverlay') return true;
        t = t.parentNode;
      }
    }catch(_){ }

    try{
      const t = e && e.target;
      // Fallback: selector check when available
      if (t && t.closest && t.closest('#qgOverlay')) return true;
    }catch(_){ }

    return false;
  }

  document.addEventListener('contextmenu', function(e){
    if (allowContextMenuHere(e)) return true;
    return stop(e);
  }, true);

  // 단축키 차단 (F12 / Ctrl+Shift+I/J/C / Ctrl+U / Ctrl+S)
  document.addEventListener('keydown', function (e) {
    const kc = e.keyCode || 0;
    const isMac = /Mac/i.test(navigator.platform || '');
    const ctrl = isMac ? !!e.metaKey : !!e.ctrlKey;

    // F12
    if (kc === 123) return stop(e);

    // DevTools
    if (ctrl && e.shiftKey && (kc === 73 || kc === 74 || kc === 67)) return stop(e);

    // View Source / Save
    if (ctrl && (kc === 85 || kc === 83)) return stop(e);

    return true;
  }, true);

  // DevTools 감지(여러 방식 혼합) - 오탐 가능성은 존재
  const ALLOW_MS = 120;
  const EDGE = 160;

  function detectOnce() {
    const t0 = (performance && performance.now) ? performance.now() : +new Date();
    // devtools open 시 debugger가 일시정지되며 시간이 크게 증가
    debugger; // eslint-disable-line no-debugger
    const t1 = (performance && performance.now) ? performance.now() : +new Date();
    if ((t1 - t0) > ALLOW_MS) return true;

    // 창 크기 차이(도킹 DevTools)
    const w = window;
    const dx = Math.abs((w.outerWidth || 0) - (w.innerWidth || 0));
    const dy = Math.abs((w.outerHeight || 0) - (w.innerHeight || 0));
    if (dx > EDGE || dy > EDGE) return true;

    return false;
  }

  let scheduled = false;
  function scheduleCheck() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(function () {
      scheduled = false;
      try {
        if (detectOnce()) hardRedirect();
      } catch (_) {}
    }, 150);
  }

  ['load', 'resize', 'mousemove', 'focus', 'blur'].forEach(function (ev) {
    window.addEventListener(ev, scheduleCheck, true);
  });

  // 주기 체크
  setInterval(function () {
    try {
      if (detectOnce()) hardRedirect();
    } catch (_) {}
  }, 2500);
})();
