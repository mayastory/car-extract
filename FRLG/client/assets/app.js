(function() {
  const canvas = document.getElementById('game');
  const sceneName = document.getElementById('sceneName');
  const phaseName = document.getElementById('phaseName');
  const saveState = document.getElementById('saveState');
  const touchRoot = document.getElementById('touchControls');

  const engine = new window.FRLG.IntroEngine(
    canvas,
    {
      flow: window.FRLG_FLOW_CONFIG,
      maps: window.FRLG_MAP_DATA
    },
    {
      onSceneChange(id) {
        sceneName.textContent = id;
      },
      onPhaseChange(phase) {
        phaseName.textContent = phase;
      },
      onSaveStateChange(text) {
        saveState.textContent = text;
      }
    }
  );

  const KEY_CODE_MAP = {
    ArrowUp: 'ArrowUp',
    ArrowDown: 'ArrowDown',
    ArrowLeft: 'ArrowLeft',
    ArrowRight: 'ArrowRight',
    Enter: 'Enter',
    Backspace: 'Backspace',
    Escape: 'Escape',
    r: 'KeyR',
    R: 'KeyR'
  };

  function dispatchKey(key) {
    const code = KEY_CODE_MAP[key] || key;
    engine.handleKeyDown({
      key,
      code,
      preventDefault() {},
      stopPropagation() {}
    });
  }

  function isTouchDevice() {
    return window.matchMedia('(pointer: coarse)').matches || window.innerWidth <= 900;
  }

  function syncTouchVisibility() {
    if (!touchRoot) return;
    touchRoot.classList.toggle('is-visible', isTouchDevice());
  }

  function bindTouchButton(button) {
    const key = button.dataset.key;
    if (!key) return;

    let repeatTimer = null;
    let repeatStarter = null;
    const repeating = key.startsWith('Arrow');

    const press = (event) => {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      button.classList.add('is-pressed');
      dispatchKey(key);

      if (repeating) {
        clearTimeout(repeatStarter);
        clearInterval(repeatTimer);
        repeatStarter = setTimeout(() => {
          repeatTimer = setInterval(() => dispatchKey(key), 95);
        }, 260);
      }
    };

    const release = (event) => {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      button.classList.remove('is-pressed');
      clearTimeout(repeatStarter);
      clearInterval(repeatTimer);
      repeatStarter = null;
      repeatTimer = null;
    };

    button.addEventListener('pointerdown', press, { passive: false });
    button.addEventListener('pointerup', release, { passive: false });
    button.addEventListener('pointercancel', release, { passive: false });
    button.addEventListener('pointerleave', release, { passive: false });
    button.addEventListener('touchstart', press, { passive: false });
    button.addEventListener('touchend', release, { passive: false });
    button.addEventListener('touchcancel', release, { passive: false });
    button.addEventListener('mousedown', press, { passive: false });
    button.addEventListener('mouseup', release, { passive: false });
    button.addEventListener('mouseleave', release, { passive: false });
    button.addEventListener('contextmenu', (event) => event.preventDefault());
  }

  window.addEventListener('keydown', (e) => {
    engine.handleKeyDown(e);
  });

  if (touchRoot) {
    touchRoot.querySelectorAll('[data-key]').forEach(bindTouchButton);
    syncTouchVisibility();
    window.addEventListener('resize', syncTouchVisibility);
    window.addEventListener('orientationchange', syncTouchVisibility);
  }

  engine.start();
})();
