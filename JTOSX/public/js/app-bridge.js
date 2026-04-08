(() => {
  'use strict';

  const state = {
    base: '',
    appId: '',
    winId: '',
    opts: {},
  };

  function post(type, payload = {}) {
    if (!window.parent) return;
    window.parent.postMessage({ type, ...payload }, '*');
  }

  window.OSX_APP = {
    get base() { return state.base; },
    get appId() { return state.appId; },
    get winId() { return state.winId; },

    setTitle(title) {
      post('app:setTitle', { winId: state.winId, title });
    },

    openApp(appId, opts) {
      post('shell:openApp', { appId, opts: opts || {} });
    },

    openFile(file) {
      post('shell:openFile', { file });
    },

    setOsVersion(osId) {
      post('shell:setOsVersion', { osId });
    },

    // alanagoyal (next-themes) uses localStorage key 'theme' with values: 'system' | 'light' | 'dark'
    setTheme(theme) {
      post('shell:setTheme', { theme });
    },

    lock() {
      post('shell:lock', {});
    },
  };

  window.addEventListener('message', (ev) => {
    const msg = ev.data || {};
    if (!msg || typeof msg !== 'object') return;
    if (msg.type === 'shell:init') {
      state.base = msg.base || '';
      state.appId = msg.appId || '';
      state.winId = msg.winId || '';
      state.opts = msg.opts || {};
    }
    if (msg.type === 'shell:navigate') {
      // bubble as event
      window.dispatchEvent(new CustomEvent('osx:navigate', { detail: msg }));
    }
  });

})();
