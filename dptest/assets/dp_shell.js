/* dp_shell.js (v19)
   - iframe 내부 페이지에 dp_theme_unified.css 주입 + data-dp-unified=1
   - 메뉴 이동(A: 메뉴 따라 주소 변경) 유지
   - 사이드바 '바깥 클릭 닫힘'은 backdrop이 항상 iframe 위에 오도록 CSS(z-index)로 해결
   - dp_shell 쪽에서 불필요한 outside-click 캡쳐 제거(중복/충돌 방지)
*/

(function(){
  'use strict';

  // ---------- Base URL (/DPTest 같은 루트) ----------
  function detectBase(){
    if (window.DP_BASE_URL) return window.DP_BASE_URL;
    var p = location.pathname;
    // "/DPTest/..." 형태면 DPTest를 base로
    var m = p.match(/^(\/[A-Za-z0-9_-]+)(?:\/|$)/);
    if (m) return m[1];
    return '';
  }
  var BASE = detectBase();

  // ---------- Routes (pretty url -> 실제 페이지) ----------
  // NOTE: SHELL(app.php)에서 iframe으로 실제 페이지를 띄우는 매핑
  //       (메뉴 이동 시 전체 페이지 리로드를 막아 매트릭스 BG가 초기화되지 않게 함)
  var ROUTES = {
    '/jtgpt': 'jtgpt_view.php',
    '/shipinglist': 'shipinglist_list.php',
    '/rma': 'RMAlist_list.php',
    '/oqc': 'oqc_view.php',
    '/ipqc': 'ipqc_view.php'
  };

  function qsParse(qs){
    var o = {};
    (qs || '').replace(/^\?/, '').split('&').forEach(function(p){
      if (!p) return;
      var s = p.split('=');
      var k = decodeURIComponent(s[0] || '').trim();
      var v = decodeURIComponent(s[1] || '');
      if (!k) return;
      o[k] = v;
    });
    return o;
  }

  function buildEmbedUrl(targetPath){
    // targetPath: "/shipinglist?..." or "/oqc?..."
    var u = targetPath || '/jtgpt';
    if (u.indexOf('/') !== 0) u = '/' + u;

    // split path/query
    var parts = u.split('?');
    var path = parts[0];
    var query = parts[1] ? ('?' + parts[1]) : '';

    var page = ROUTES[path] || ROUTES['/jtgpt'];

    // embed=1 유지
    var q = qsParse(query);
    q.embed = '1';

    var qstr = Object.keys(q).map(function(k){
      return encodeURIComponent(k) + '=' + encodeURIComponent(q[k]);
    }).join('&');

    return BASE + '/' + page + (qstr ? ('?' + qstr) : '');
  }

  // ---------- iframe 찾기 ----------
  function getFrame(){
    return document.getElementById('dpFrame') || document.getElementById('dpShellFrame') || document.querySelector('iframe[data-dp-frame]');
  }

  // ---------- Unified theme 주입 ----------
  function ensureUnifiedTheme(doc){
    if (!doc || !doc.documentElement) return;
    try{
      doc.documentElement.setAttribute('data-dp-unified','1');
    }catch(e){}

    try{
      var head = doc.head || doc.getElementsByTagName('head')[0];
      if (!head) return;
      if (head.querySelector('link[data-dp-unified-theme]')) return;
      var link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.href = BASE + '/assets/dp_theme_unified.css?v=22';
      link.setAttribute('data-dp-unified-theme','1');
      head.appendChild(link);
    }catch(e){}
  }

  function closeSidebar(){
    var bd = document.getElementById('dpSideBackdrop');
    var panel = document.getElementById('dpSidePanel');
    if (!bd || !panel) return;

    bd.classList.remove('show');
    panel.classList.remove('show');

    // dp_sidebar.css transition(250ms) 감안
    window.setTimeout(function(){
      try{ bd.hidden = true; }catch(e){}
      try{ panel.hidden = true; }catch(e){}
    }, 260);
  }

  function setActiveMenu(targetPath){
    // .dp-side-item a, [data-dp-nav] 모두 대응
    var items = document.querySelectorAll('.dp-side-item, [data-dp-nav]');
    items.forEach(function(el){
      el.classList.remove('active');
      el.removeAttribute('aria-current');
    });

    var selector = '[data-dp-nav="' + targetPath + '"]';
    var hit = document.querySelector(selector);
    if (!hit){
      // sidebar a[href$="/shipinglist"] 같은 형태
      var links = document.querySelectorAll('.dp-side a[href]');
      links.forEach(function(a){
        var href = a.getAttribute('href') || '';
        if (!href) return;
        // base 포함/미포함 모두 처리
        if (href === (BASE + targetPath) || href === targetPath || href === (targetPath + '/') ){
          hit = a.closest('.dp-side-item') || a;
        }
      });
    }

    if (hit){
      hit.classList.add('active');
      hit.setAttribute('aria-current','page');
    }
  }

  function navigate(targetPath, opts){
    opts = opts || {};
    var frame = getFrame();
    if (!frame) return;

    var url = buildEmbedUrl(targetPath);
    if (frame.getAttribute('src') !== url) frame.setAttribute('src', url);

    // 주소창(A): 메뉴 따라 변경
    if (!opts.noPush){
      try{ history.pushState({dpPath: targetPath}, '', BASE + targetPath); }catch(e){}
    }

    setActiveMenu(targetPath);

    // 메뉴 이동 후 닫기
    closeSidebar();
  }

  function currentPrettyPath(){
    var p = location.pathname || '';
    // BASE 제거
    if (BASE && p.indexOf(BASE) === 0) p = p.slice(BASE.length);
    if (!p) p = '/jtgpt';
    // /shipinglist/ -> /shipinglist
    if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
    // 알 수 없으면 기본
    if (!ROUTES[p]) return '/jtgpt';
    return p;
  }

  function bindNavClicks(){
    document.addEventListener('click', function(e){
      var nav = e.target.closest('[data-dp-nav]');
      if (!nav) return;

      var target = nav.getAttribute('data-dp-nav');
      if (!target) return;

      e.preventDefault();
      e.stopPropagation();

      navigate(target);
    }, true);
  }

  function bindPopState(){
    window.addEventListener('popstate', function(){
      var p = currentPrettyPath();
      navigate(p, {noPush:true});
    });
  }

  function bindFrameLoadTheme(){
    var frame = getFrame();
    if (!frame) return;

    frame.addEventListener('load', function(){
      try{
        ensureUnifiedTheme(frame.contentDocument);
      }catch(e){}

      // 내부 페이지에서 스크롤/클릭 등으로 주소가 바뀌는 건 막지 않음
    });
  }

  function init(){
    bindNavClicks();
    bindPopState();
    bindFrameLoadTheme();

    var p = currentPrettyPath();
    setActiveMenu(p);

    // 첫 로드 시 iframe src 세팅
    var frame = getFrame();
    if (frame){
      var url = buildEmbedUrl(p);
      if (!frame.getAttribute('src')) frame.setAttribute('src', url);
    }

    // 현재 문서에도 unified 적용(쉘 카드/요소)
    ensureUnifiedTheme(document);
  }

  
  // ---------- Fullscreen modal helper (for iframe modals) ----------
  // NOTE:
  // - DO NOT move/reparent the iframe node. Moving an iframe can destroy its browsing context,
  //   which reloads the embedded page and resets the user's current state.
  // - Instead, keep the iframe in place and temporarily style it as a fixed, top-level layer.
  (function(){
    var prev = null;
    var scrollPrev = null;

    function lockScroll(){
      try{
        var html = document.documentElement;
        var body = document.body;
        scrollPrev = {
          html: html ? html.style.overflow : '',
          body: body ? body.style.overflow : ''
        };
        if (html) html.style.overflow = 'hidden';
        if (body) body.style.overflow = 'hidden';
      }catch(e){}
    }
    function unlockScroll(){
      try{
        var html = document.documentElement;
        var body = document.body;
        var p = scrollPrev || {html:'', body:''};
        if (html) html.style.overflow = p.html || '';
        if (body) body.style.overflow = p.body || '';
      }catch(e){}
      scrollPrev = null;
    }

    function openShellModal(){
      var frame = getFrame();
      if (!frame) return;
      if (prev) return; // already open

      prev = {
        style: {
          position: frame.style.position,
          inset: frame.style.inset,
          top: frame.style.top,
          left: frame.style.left,
          right: frame.style.right,
          bottom: frame.style.bottom,
          width: frame.style.width,
          height: frame.style.height,
          zIndex: frame.style.zIndex,
          border: frame.style.border,
          display: frame.style.display,
          background: frame.style.background
        }
      };

      lockScroll();

      // Promote iframe to topmost fullscreen layer (no DOM move)
      // Also hide shell UI (rail/userbar) and remove reserved padding so the iframe truly covers the whole viewport
      try{
        var wrap = document.querySelector('.dp-shell-wrap');
        var body = document.querySelector('.dp-shell-body');
        var rail = document.querySelector('.dp-rail');
        var ub   = document.querySelector('.dp-ub');
        prev.shellUI = {
          wrap:{ padLeft: wrap?wrap.style.paddingLeft:'', z: wrap?wrap.style.zIndex:'', pos: wrap?wrap.style.position:'' },
          body:{ z: body?body.style.zIndex:'', pos: body?body.style.position:'' },
          rail:{ disp: rail?rail.style.display:'', vis: rail?rail.style.visibility:'' },
          ub:{ disp: ub?ub.style.display:'', vis: ub?ub.style.visibility:'' }
        };
        if (wrap){ wrap.style.paddingLeft = '0'; wrap.style.zIndex = '2147483646'; wrap.style.position = 'relative'; }
        if (body){ body.style.zIndex = '2147483646'; body.style.position = 'relative'; }
        if (rail){ rail.style.display = 'none'; rail.style.visibility = 'hidden'; }
        if (ub){ ub.style.display = 'none'; ub.style.visibility = 'hidden'; }
      }catch(e){}

      frame.setAttribute('data-dp-shell-modal', '1');
      frame.style.position = 'fixed';
      frame.style.inset = '0';
      frame.style.top = '0';
      frame.style.left = '0';
      frame.style.right = '0';
      frame.style.bottom = '0';
      frame.style.width = '100vw';
      frame.style.height = '100vh';
      frame.style.border = '0';
      frame.style.display = 'block';
      frame.style.background = 'transparent';
      frame.style.zIndex = '2147483647';
    }

    function closeShellModal(){
      var frame = getFrame();
      if (!prev || !frame) { prev = null; unlockScroll(); return; }

      var s = prev.style || {};
      frame.style.position = s.position || '';
      frame.style.inset = s.inset || '';
      frame.style.top = s.top || '';
      frame.style.left = s.left || '';
      frame.style.right = s.right || '';
      frame.style.bottom = s.bottom || '';
      frame.style.width = s.width || '';
      frame.style.height = s.height || '';
      frame.style.zIndex = s.zIndex || '';
      frame.style.border = s.border || '';
      frame.style.display = s.display || '';
      frame.style.background = s.background || '';

      try{ frame.removeAttribute('data-dp-shell-modal'); }catch(e){}

      // Restore shell UI
      try{
        var wrap = document.querySelector('.dp-shell-wrap');
        var body = document.querySelector('.dp-shell-body');
        var rail = document.querySelector('.dp-rail');
        var ub   = document.querySelector('.dp-ub');
        var ui = prev && prev.shellUI ? prev.shellUI : null;
        if (wrap && ui && ui.wrap){ wrap.style.paddingLeft = ui.wrap.padLeft || ''; wrap.style.zIndex = ui.wrap.z || ''; wrap.style.position = ui.wrap.pos || ''; }
        if (body && ui && ui.body){ body.style.zIndex = ui.body.z || ''; body.style.position = ui.body.pos || ''; }
        if (rail && ui && ui.rail){ rail.style.display = ui.rail.disp || ''; rail.style.visibility = ui.rail.vis || ''; }
        if (ub && ui && ui.ub){ ub.style.display = ui.ub.disp || ''; ub.style.visibility = ui.ub.vis || ''; }
      }catch(e){}

      prev = null;
      unlockScroll();
    }

    // Expose to iframes (same-origin)
    window.DP_SHELL_MODAL = {
      open: openShellModal,
      close: closeShellModal,
      isOpen: function(){ return !!prev; }
    };
  })();


  // ---------- JTGPT shell action bridge ----------
  function frameReadyTry(fn, maxTry, delay){
    var tries = 0;
    function tick(){
      tries += 1;
      var frame = getFrame();
      if (!frame) return;
      try{
        if (fn(frame) === true) return;
      }catch(e){}
      if (tries < (maxTry || 40)) window.setTimeout(tick, delay || 250);
    }
    window.setTimeout(tick, delay || 250);
  }

  function openIpqcQuickGraph(spec){
    try{ sessionStorage.setItem('jtgpt.pendingGraphSpec', JSON.stringify(spec || {})); }catch(e){}
    navigate('/ipqc');
    frameReadyTry(function(frame){
      var win = null;
      try{ win = frame.contentWindow; }catch(e){}
      if (!win) return false;
      try{ win.__JTGPT_PENDING_GRAPH_SPEC = spec || {}; }catch(e){}
      try{ win.dispatchEvent(new CustomEvent('jtgpt:graph-spec', { detail: spec || {} })); }catch(e){}
      try{
        if (typeof win.__ipqcOpenQuickGraphSafe === 'function') {
          win.__ipqcOpenQuickGraphSafe();
          return true;
        }
      }catch(e){}
      return false;
    }, 50, 250);
  }

  function openIpqcProcessCapability(spec){
    try{ sessionStorage.setItem('jtgpt.pendingGraphSpec', JSON.stringify(spec || {})); }catch(e){}
    navigate('/ipqc');
    frameReadyTry(function(frame){
      var win = null;
      try{ win = frame.contentWindow; }catch(e){}
      if (!win) return false;
      try{ win.__JTGPT_PENDING_GRAPH_SPEC = spec || {}; }catch(e){}
      try{ win.dispatchEvent(new CustomEvent('jtgpt:graph-spec', { detail: spec || {} })); }catch(e){}
      try{
        if (typeof win.__ipqcOpenProcessCapabilitySafe === 'function') {
          win.__ipqcOpenProcessCapabilitySafe();
          return true;
        }
      }catch(e){}
      return false;
    }, 50, 250);
  }

  function runShellAction(action){
    var a = action || {};
    var kind = String(a.kind || a.type || '').toLowerCase();
    if (!kind && a.route) kind = 'navigate';
    if (kind === 'navigate') {
      navigate(String(a.route || '/jtgpt'));
      return true;
    }
    if (kind === 'open_ipqc_quick_graph') {
      openIpqcQuickGraph(a.graph_spec || {});
      return true;
    }
    if (kind === 'open_ipqc_process_capability') {
      openIpqcProcessCapability(a.graph_spec || {});
      return true;
    }
    if (a.href) {
      try{ window.location.href = a.href; return true; }catch(e){}
    }
    return false;
  }

  window.DP_SHELL_ACTIONS = {
    navigate: navigate,
    run: runShellAction,
    openQuickGraph: openIpqcQuickGraph,
    openProcessCapability: openIpqcProcessCapability
  };

  window.addEventListener('message', function(ev){
    var data = ev && ev.data ? ev.data : null;
    if (!data || data.type !== 'dp-shell-action') return;
    runShellAction(data.action || data);
  });


  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  }else{
    init();
  }

})();
