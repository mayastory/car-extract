(function () {
  const store = {
    kind: '',
    message: '',
    detail: '',
    source: '',
    url: '',
    status: '',
    response: '',
    stack: '',
    time: ''
  };

  function truncate(v, n) {
    const s = String(v == null ? '' : v);
    return s.length > n ? s.slice(0, n) + '…' : s;
  }

  function setStore(patch) {
    Object.assign(store, patch || {});
    store.time = new Date().toLocaleTimeString();
    window.__pkLastFatal = { ...store };
    flushOverlay();
  }

  function explain(url) {
    const u = String(url || '');
    if (!u) return '';
    if (u.includes('/auth/')) return '인증 확인 요청';
    if (u.includes('/rt/get.php')) return '플레이어 상태 조회';
    if (u.includes('/rt/upsert.php')) return '플레이어 위치 저장';
    if (u.includes('/rt/map_mobs.php')) return '맵 몹 조회';
    if (u.includes('/rt/map_items.php')) return '맵 아이템 조회';
    return '';
  }

  function overlayRoot() {
    const nodes = Array.from(document.querySelectorAll('div,section,article,aside'));
    return nodes.find((el) => {
      const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
      return text.includes('오류') && text.includes('새로고침') && text.includes('로그인으로');
    }) || null;
  }

  function ensureDetailEl(root) {
    let el = root.querySelector('#pkFatalDebugDetail');
    if (el) return el;
    el = document.createElement('pre');
    el.id = 'pkFatalDebugDetail';
    el.style.whiteSpace = 'pre-wrap';
    el.style.wordBreak = 'break-word';
    el.style.margin = '12px 0 0 0';
    el.style.padding = '10px 12px';
    el.style.borderRadius = '10px';
    el.style.background = 'rgba(255,255,255,0.04)';
    el.style.border = '1px solid rgba(255,255,255,0.08)';
    el.style.color = '#cfe2ff';
    el.style.fontSize = '12px';
    el.style.lineHeight = '1.45';
    const reloadBtn = Array.from(root.querySelectorAll('button')).find((btn) => (btn.textContent || '').includes('새로고침'));
    const anchor = reloadBtn ? reloadBtn.parentElement : null;
    if (anchor && anchor.parentElement === root) {
      root.insertBefore(el, anchor);
    } else {
      root.appendChild(el);
    }
    return el;
  }

  function setFriendlyMessage(root, text) {
    const candidates = Array.from(root.querySelectorAll('p,div,span,b,strong'));
    const target = candidates.find((el) => (el.textContent || '').includes('오류가 발생했습니다.'));
    if (target) target.textContent = text;
  }

  function renderDetail() {
    const lines = [];
    if (store.message) lines.push('메시지: ' + store.message);
    if (store.source) lines.push('출처: ' + store.source);
    if (store.url) lines.push('요청: ' + store.url);
    if (store.url && explain(store.url)) lines.push('설명: ' + explain(store.url));
    if (store.status) lines.push('상태: ' + store.status);
    if (store.response) lines.push('응답: ' + store.response);
    if (store.stack) lines.push('스택: ' + store.stack);
    if (store.time) lines.push('시각: ' + store.time);
    return lines.join('\n').trim() || '아직 잡힌 원인 정보가 없습니다.';
  }

  function flushOverlay() {
    const root = overlayRoot();
    if (!root) return;
    setFriendlyMessage(root, store.message || '원인 수집 중 오류');
    ensureDetailEl(root).textContent = renderDetail();
  }

  const origFetch = window.fetch ? window.fetch.bind(window) : null;
  if (origFetch) {
    window.fetch = async function(input, init) {
      const url = typeof input === 'string' ? input : (input && input.url) || '';
      const method = String((init && init.method) || (typeof input !== 'string' && input && input.method) || 'GET').toUpperCase();
      try {
        const res = await origFetch(input, init);
        if (res.status >= 400) {
          let body = '';
          try { body = truncate(await res.clone().text(), 600); } catch (_e) {}
          setStore({
            kind: 'fetch',
            message: method + ' ' + (url || '(알 수 없음)') + ' 실패',
            source: 'fetch',
            url,
            status: String(res.status) + (res.statusText ? ' ' + res.statusText : ''),
            response: body
          });
        }
        return res;
      } catch (err) {
        setStore({
          kind: 'fetch',
          message: truncate(err && err.message ? err.message : err, 300),
          source: 'fetch',
          url,
          status: 'NETWORK',
          stack: truncate(err && err.stack ? err.stack : '', 1200)
        });
        throw err;
      }
    };
  }

  window.addEventListener('error', function(e) {
    setStore({
      kind: 'error',
      message: truncate(e && e.message ? e.message : '스크립트 오류', 300),
      source: [e && e.filename ? e.filename : '', (e && e.lineno) ? (':' + e.lineno + ':' + (e.colno || 0)) : ''].join(''),
      stack: truncate(e && e.error && e.error.stack ? e.error.stack : '', 1200)
    });
  });

  window.addEventListener('unhandledrejection', function(e) {
    const r = e ? e.reason : null;
    setStore({
      kind: 'promise',
      message: truncate(typeof r === 'string' ? r : (r && r.message) ? r.message : '처리되지 않은 Promise 오류', 300),
      source: 'unhandledrejection',
      stack: truncate(r && r.stack ? r.stack : '', 1200)
    });
  });

  const mo = new MutationObserver(flushOverlay);
  window.addEventListener('DOMContentLoaded', function() {
    mo.observe(document.documentElement || document.body, { childList: true, subtree: true, attributes: true, characterData: true });
    flushOverlay();
  });
})();
