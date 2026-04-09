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

  const oakAssets = createOakAssetBundle();
  patchOakScenes(engine, oakAssets);

  window.addEventListener('keydown', (e) => {
    engine.handleKeyDown(e);
  });

  bindTouchControls(engine);
  engine.start();

  function createOakAssetBundle() {
    const base = 'assets/packege/oak_speech';
    return {
      bg: loadImage(base + '/oak_speech_bg.png'),
      platform: loadImage(base + '/platform.png'),
      oak: loadImage(base + '/oak/pic.png'),
      red: loadImage(base + '/red/pic.png'),
      leaf: loadImage(base + '/leaf/pic.png'),
      rival: loadImage(base + '/rival/pic.png')
    };
  }

  function loadImage(src) {
    const img = new Image();
    img.src = src;
    return img;
  }

  function patchOakScenes(engine, assets) {
    engine.drawOakIntro = function() {
      drawOakSpeechBackdrop(this, assets);
      drawOakFigure(this.ctx, assets.oak, 96, 34, 170, 185);
      drawBuddyFigure(this.ctx, assets.rival, 292, 94, 110, 120);
      drawOakSpeechTitle(this.ctx, 'OAK INTRO');
      drawOakMessage(this.ctx, this.flow.oakIntro[this.oakLine]);
    };

    engine.drawGenderSelect = function() {
      drawOakSpeechBackdrop(this, assets);
      drawOakSpeechTitle(this.ctx, 'BOY / GIRL');
      drawSelectCard(this.ctx, 88, 78, 120, 156, this.genderIndex === 0);
      drawSelectCard(this.ctx, 272, 78, 120, 156, this.genderIndex === 1);
      drawTrainerFigure(this.ctx, assets.red, 105, 92, 86, 110);
      drawTrainerFigure(this.ctx, assets.leaf, 289, 92, 86, 110);
      drawChoiceLabel(this.ctx, 'BOY', 148, 214, this.genderIndex === 0);
      drawChoiceLabel(this.ctx, 'GIRL', 332, 214, this.genderIndex === 1);
      drawOakMessage(this.ctx, 'Are you a boy? Or are you a girl?');
    };

    engine.drawPostGender = function() {
      drawOakSpeechBackdrop(this, assets);
      drawOakFigure(this.ctx, assets.oak, 84, 34, 162, 181);
      const sprite = this.profile.gender === 'F' ? assets.leaf : assets.red;
      drawTrainerFigure(this.ctx, sprite, 300, 82, 92, 118);
      drawOakSpeechTitle(this.ctx, 'OAK INTRO');
      drawOakMessage(this.ctx, this.flow.postGender[this.postGenderLine]);
    };
  }

  function drawOakSpeechBackdrop(engine, assets) {
    const ctx = engine.ctx;
    const canvas = engine.canvas;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#dfeef1';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    if (assets.bg.complete && assets.bg.naturalWidth) {
      ctx.drawImage(assets.bg, 0, 0, canvas.width, 228);
    } else {
      ctx.fillStyle = '#bfe2d9';
      ctx.fillRect(0, 0, canvas.width, 228);
    }

    if (assets.platform.complete && assets.platform.naturalWidth) {
      ctx.drawImage(assets.platform, 78, 188, 130, 18);
      ctx.drawImage(assets.platform, 270, 206, 118, 16);
    } else {
      ctx.fillStyle = '#d2e7dd';
      ctx.fillRect(78, 188, 130, 14);
      ctx.fillRect(270, 206, 118, 14);
    }
  }

  function drawOakSpeechTitle(ctx, text) {
    ctx.save();
    ctx.font = '700 14px Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillStyle = '#2b4958';
    ctx.fillText(text, 240, 30);
    ctx.restore();
  }

  function drawOakFigure(ctx, img, x, y, w, h) {
    if (img.complete && img.naturalWidth) {
      ctx.drawImage(img, x, y, w, h);
      return;
    }
    ctx.fillStyle = '#e0d2c3';
    ctx.beginPath();
    ctx.arc(x + w * 0.45, y + 38, 24, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(x + 18, y + 72, 70, 98);
    ctx.fillStyle = '#8e8e9b';
    ctx.fillRect(x + 32, y + 126, 18, 54);
    ctx.fillRect(x + 60, y + 126, 18, 54);
  }

  function drawBuddyFigure(ctx, img, x, y, w, h) {
    if (img.complete && img.naturalWidth) {
      ctx.drawImage(img, x, y, w, h);
      return;
    }
    drawFallbackBuddy(ctx, x, y, w, h);
  }

  function drawTrainerFigure(ctx, img, x, y, w, h) {
    if (img.complete && img.naturalWidth) {
      ctx.drawImage(img, x, y, w, h);
      return;
    }
    ctx.fillStyle = '#f2f6fa';
    ctx.fillRect(x + 14, y + 8, w - 28, h - 16);
    ctx.strokeStyle = '#537286';
    ctx.lineWidth = 2;
    ctx.strokeRect(x + 14, y + 8, w - 28, h - 16);
  }

  function drawFallbackBuddy(ctx, x, y, w, h) {
    const cx = x + w / 2;
    const cy = y + h / 2;
    ctx.save();
    ctx.fillStyle = '#f7f7f7';
    ctx.beginPath();
    ctx.arc(cx, cy + 4, 34, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#cf2d3a';
    ctx.fillRect(cx - 34, cy + 2, 68, 10);
    ctx.strokeStyle = '#25313a';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.arc(cx, cy + 4, 34, 0, Math.PI * 2);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(cx - 34, cy + 7);
    ctx.lineTo(cx + 34, cy + 7);
    ctx.stroke();
    ctx.fillStyle = '#ffffff';
    ctx.beginPath();
    ctx.arc(cx, cy + 7, 9, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = '#25313a';
    ctx.stroke();
    ctx.restore();
  }

  function drawSelectCard(ctx, x, y, w, h, active) {
    ctx.save();
    ctx.fillStyle = active ? '#f4fbff' : 'rgba(245,250,255,0.72)';
    ctx.strokeStyle = active ? '#4d7ea4' : '#7ea0b5';
    ctx.lineWidth = active ? 3 : 2;
    roundRect(ctx, x, y, w, h, 10);
    ctx.fill();
    ctx.stroke();
    ctx.restore();
  }

  function drawChoiceLabel(ctx, label, x, y, active) {
    ctx.save();
    ctx.font = '700 18px Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillStyle = active ? '#24465f' : '#476577';
    ctx.fillText(label, x, y);
    ctx.restore();
  }

  function drawOakMessage(ctx, text) {
    const boxX = 48;
    const boxY = 248;
    const boxW = 384;
    const boxH = 68;

    ctx.save();
    ctx.fillStyle = '#ffffff';
    ctx.strokeStyle = '#46657a';
    ctx.lineWidth = 4;
    ctx.fillRect(boxX, boxY, boxW, boxH);
    ctx.strokeRect(boxX, boxY, boxW, boxH);
    ctx.fillStyle = '#233545';
    ctx.font = '16px Arial, sans-serif';
    ctx.textAlign = 'left';
    wrapText(ctx, String(text || ''), boxX + 18, boxY + 24, boxW - 36, 20);
    ctx.restore();
  }

  function wrapText(ctx, text, x, y, maxWidth, lineHeight) {
    const words = String(text).replace(/\s+/g, ' ').trim().split(' ');
    let line = '';
    let row = 0;
    words.forEach((word) => {
      const test = line ? line + ' ' + word : word;
      if (ctx.measureText(test).width > maxWidth && line) {
        ctx.fillText(line, x, y + row * lineHeight);
        line = word;
        row += 1;
      } else {
        line = test;
      }
    });
    if (line) ctx.fillText(line, x, y + row * lineHeight);
  }

  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

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
