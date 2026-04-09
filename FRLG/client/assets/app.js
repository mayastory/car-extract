(function() {
  const canvas = document.getElementById('game');
  const sceneName = document.getElementById('sceneName');
  const phaseName = document.getElementById('phaseName');
  const saveState = document.getElementById('saveState');

  const engine = new window.FRLG.IntroEngine(
    canvas,
    {
      flow: window.FRLG_FLOW_CONFIG,
      maps: window.FRLG_MAP_DATA
    },
    {
      onSceneChange(id) {
        if (sceneName) sceneName.textContent = id;
      },
      onPhaseChange(phase) {
        if (phaseName) phaseName.textContent = phase;
      },
      onSaveStateChange(text) {
        if (saveState) saveState.textContent = text;
      }
    }
  );

  window.__frlgEngine = engine;


  window.addEventListener('keydown', (e) => {
    engine.handleKeyDown(e);
  });

  bindTouchControls(engine);
  engine.start();

  function bindTouchControls(engine) {
    const mapFromLabel = (label) => {
      const t = String(label || '').trim().toUpperCase();
      if (t === 'A') return { key: 'Enter', code: 'Enter', repeat: false };
      if (t === 'B') return { key: 'Backspace', code: 'Backspace', repeat: false };
      if (t === 'MENU') return { key: 'Escape', code: 'Escape', repeat: false };
      if (t === 'R') return { key: 'r', code: 'KeyR', repeat: false };
      if (t === '▲' || t === 'UP' || t === 'ARROWUP') return { key: 'ArrowUp', code: 'ArrowUp', repeat: true };
      if (t === '▼' || t === 'DOWN' || t === 'ARROWDOWN') return { key: 'ArrowDown', code: 'ArrowDown', repeat: true };
      if (t === '◀' || t === 'LEFT' || t === 'ARROWLEFT') return { key: 'ArrowLeft', code: 'ArrowLeft', repeat: true };
      if (t === '▶' || t === 'RIGHT' || t === 'ARROWRIGHT') return { key: 'ArrowRight', code: 'ArrowRight', repeat: true };
      return null;
    };

    const resolveControl = (el) => {
      if (!el) return null;
      const ds = el.dataset || {};
      const direct = ds.frlgKey || ds.key || ds.frlgAction || ds.action || ds.frlgDir || ds.dir || ds.control;
      return mapFromLabel(direct || el.textContent);
    };

    let heldTimer = null;
    let heldDelay = null;

    const stopHeld = () => {
      if (heldDelay) clearTimeout(heldDelay);
      if (heldTimer) clearInterval(heldTimer);
      heldDelay = null;
      heldTimer = null;
    };

    const trigger = (control) => {
      if (!control) return;
      engine.handleKeyDown({ key: control.key, code: control.code, preventDefault() {} });
    };

    const startHeld = (control) => {
      trigger(control);
      if (!control.repeat) return;
      stopHeld();
      heldDelay = setTimeout(() => {
        heldTimer = setInterval(() => trigger(control), 85);
      }, 220);
    };

    const controls = Array.from(document.querySelectorAll([
      '[data-frlg-key]','[data-key]','[data-frlg-action]','[data-action]','[data-frlg-dir]','[data-dir]','.touch-btn','.touch-button','.touch-control','button'
    ].join(','))).filter((el) => resolveControl(el));

    controls.forEach((el) => {
      const onDown = (ev) => {
        ev.preventDefault();
        startHeld(resolveControl(el));
      };
      const onUp = (ev) => {
        ev.preventDefault();
        stopHeld();
      };
      el.addEventListener('pointerdown', onDown, { passive: false });
      el.addEventListener('pointerup', onUp, { passive: false });
      el.addEventListener('pointerleave', onUp, { passive: false });
      el.addEventListener('pointercancel', onUp, { passive: false });
      el.addEventListener('touchstart', onDown, { passive: false });
      el.addEventListener('touchend', onUp, { passive: false });
      el.addEventListener('touchcancel', onUp, { passive: false });
    });

    window.addEventListener('pointerup', stopHeld);
    window.addEventListener('touchend', stopHeld);
    window.addEventListener('touchcancel', stopHeld);
  }
})();
