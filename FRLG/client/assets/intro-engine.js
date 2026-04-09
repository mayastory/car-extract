window.FRLG = window.FRLG || {};

(function() {
  const KEY_ENTER = new Set(['Enter', 'Space']);
  const PLAYER_COLORS = {
    M: '#ee4e4e',
    F: '#4ec6ff'
  };

  class IntroEngine {
    constructor(canvas, config, hooks) {
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.config = config;
      this.hooks = hooks || {};
      this.boundLoop = this.loop.bind(this);
      this.running = false;
      this.resetAll();
    }

    resetAll() {
      this.state = 'intro';
      this.introIndex = 0;
      this.sceneStart = performance.now();
      this.menuIndex = 0;
      this.oakLine = 0;
      this.genderIndex = 0;
      this.postGenderLine = 0;
      this.nameMode = 'player';
      this.profile = {
        gender: 'M',
        playerName: '',
        rivalName: ''
      };
      this.room = {
        mapId: 'PalletTown_PlayersHouse_2F',
        x: 6,
        y: 4,
        dir: 'down',
        maxX: 10,
        maxY: 7
      };
      this.updateStatus();
      this.emitSaveState();
    }

    currentScene() {
      return this.config.introScenes[this.introIndex];
    }

    start() {
      this.running = true;
      this.sceneStart = performance.now();
      requestAnimationFrame(this.boundLoop);
    }

    restart() {
      this.resetAll();
      this.sceneStart = performance.now();
    }

    updateStatus() {
      const current = this.state === 'intro' ? this.currentScene().id : this.state;
      if (this.hooks.onSceneChange) this.hooks.onSceneChange(current);
      if (this.hooks.onPhaseChange) this.hooks.onPhaseChange(this.state);
    }

    emitSaveState() {
      if (!this.hooks.onSaveStateChange) return;
      const desc = this.state === 'room-start'
        ? `${this.profile.playerName || 'PLAYER'} / ${this.room.mapId}`
        : 'local only';
      this.hooks.onSaveStateChange(desc);
    }

    setState(nextState) {
      this.state = nextState;
      this.sceneStart = performance.now();
      this.updateStatus();
      this.emitSaveState();
    }

    advanceIntro() {
      if (this.introIndex < this.config.introScenes.length - 1) {
        this.introIndex += 1;
        this.sceneStart = performance.now();
        this.updateStatus();
        return;
      }
      this.setState('title');
    }

    onAction() {
      switch (this.state) {
        case 'intro':
          this.advanceIntro();
          break;
        case 'title':
          this.setState('main-menu');
          break;
        case 'main-menu':
          this.handleMenuConfirm();
          break;
        case 'oak-intro':
          if (this.oakLine < this.config.oakIntro.length - 1) {
            this.oakLine += 1;
          } else {
            this.setState('gender-select');
          }
          break;
        case 'gender-select':
          this.profile.gender = this.genderIndex === 0 ? 'M' : 'F';
          this.setState('post-gender');
          break;
        case 'post-gender':
          if (this.postGenderLine < this.config.postGender.length - 1) {
            this.postGenderLine += 1;
          } else {
            this.nameMode = 'player';
            this.setState('name-entry');
          }
          break;
        case 'name-entry':
          this.confirmNameEntry();
          break;
        case 'room-start':
          this.saveLocalSnapshot();
          break;
      }
    }

    handleMenuConfirm() {
      if (this.menuIndex === 0) {
        this.oakLine = 0;
        this.setState('oak-intro');
      }
    }

    confirmNameEntry() {
      const target = this.nameMode === 'player' ? 'playerName' : 'rivalName';
      const value = this.profile[target].trim();
      if (!value) return;

      if (this.nameMode === 'player') {
        this.nameMode = 'rival';
        this.profile.rivalName = '';
      } else {
        this.saveLocalSnapshot();
        this.setState('room-start');
      }
    }

    appendChar(ch) {
      if (this.state !== 'name-entry') return;
      if (!/^[A-Za-z0-9 ]$/.test(ch)) return;
      const target = this.nameMode === 'player' ? 'playerName' : 'rivalName';
      if (this.profile[target].length >= 10) return;
      this.profile[target] += ch.toUpperCase();
    }

    backspaceName() {
      if (this.state !== 'name-entry') return;
      const target = this.nameMode === 'player' ? 'playerName' : 'rivalName';
      this.profile[target] = this.profile[target].slice(0, -1);
    }

    moveSelection(dx, dy) {
      switch (this.state) {
        case 'main-menu':
          this.menuIndex = clamp(this.menuIndex + dy, 0, 1);
          break;
        case 'gender-select':
          this.genderIndex = clamp(this.genderIndex + (dx || dy), 0, 1);
          break;
        case 'room-start':
          this.movePlayer(dx, dy);
          break;
      }
    }

    movePlayer(dx, dy) {
      if (dx === 0 && dy === 0) return;
      if (dx < 0) this.room.dir = 'left';
      if (dx > 0) this.room.dir = 'right';
      if (dy < 0) this.room.dir = 'up';
      if (dy > 0) this.room.dir = 'down';
      this.room.x = clamp(this.room.x + dx, 2, this.room.maxX);
      this.room.y = clamp(this.room.y + dy, 2, this.room.maxY);
      this.emitSaveState();
    }

    saveLocalSnapshot() {
      try {
        localStorage.setItem('frlg_local_profile', JSON.stringify({
          profile: this.profile,
          room: this.room,
          state: this.state
        }));
        if (this.hooks.onSaveStateChange) {
          this.hooks.onSaveStateChange(`saved · ${this.profile.playerName || 'PLAYER'} / ${this.room.mapId}`);
        }
      } catch (err) {
        // ignore local storage failures in starter mode
      }
    }

    handleKeyDown(e) {
      if (e.key === 'r' || e.key === 'R') {
        e.preventDefault();
        this.restart();
        return;
      }
      if (KEY_ENTER.has(e.code)) {
        e.preventDefault();
        this.onAction();
        return;
      }
      if (e.key === 'Backspace') {
        e.preventDefault();
        this.backspaceName();
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        this.moveSelection(0, -1);
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        this.moveSelection(0, 1);
        return;
      }
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        this.moveSelection(-1, 0);
        return;
      }
      if (e.key === 'ArrowRight') {
        e.preventDefault();
        this.moveSelection(1, 0);
        return;
      }
      if (this.state === 'name-entry' && e.key.length === 1) {
        this.appendChar(e.key);
      }
    }

    drawBg(gradTop = '#020406', gradBottom = '#000000') {
      const g = this.ctx.createLinearGradient(0, 0, 0, this.canvas.height);
      g.addColorStop(0, gradTop);
      g.addColorStop(1, gradBottom);
      this.ctx.fillStyle = g;
      this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
    }

    drawTitle(now) {
      this.drawBg('#1d2312', '#04070a');
      drawLeafBackdrop(this.ctx, now);
      centerText(this.ctx, 'POKéMON', 240, 108, 42, 1, '#ffe65d');
      centerText(this.ctx, 'LEAF GREEN / FIRE RED', 240, 145, 18, 1, '#ffffff');
      const blink = Math.sin(now / 280) > 0 ? 1 : 0.25;
      centerText(this.ctx, 'PRESS ENTER', 240, 240, 18, blink, '#dff8ff');
      centerText(this.ctx, 'starter flow prototype', 240, 274, 11, 0.8, '#9ccdd7');
    }

    drawMenu() {
      this.drawBg('#102233', '#081018');
      centerText(this.ctx, 'MAIN MENU', 240, 92, 22, 1, '#ffffff');
      const items = ['NEW GAME', 'CONTINUE (준비중)'];
      items.forEach((item, idx) => {
        const y = 150 + idx * 40;
        drawWindow(this.ctx, 144, y - 20, 192, 30, idx === this.menuIndex);
        centerText(this.ctx, item, 240, y, 16, idx === 1 ? 0.5 : 1, '#ffffff');
      });
      centerText(this.ctx, '방향키 선택 · Enter 확인', 240, 286, 11, 0.8, '#9ccdd7');
    }

    drawOakIntro() {
      this.drawBg('#e8f1f6', '#b7d1db');
      drawOak(this.ctx, 135, 160);
      drawPokemonBuddy(this.ctx, 355, 188);
      this.drawDialogue(this.config.oakIntro[this.oakLine]);
      centerText(this.ctx, 'OAK INTRO', 240, 30, 14, 0.9, '#22313a');
    }

    drawGenderSelect() {
      this.drawBg('#edf5fa', '#c8dceb');
      centerText(this.ctx, '먼저 네가 어떤 아이인지 알려다오.', 240, 56, 16, 1, '#23323b');
      const options = [
        { label: 'BOY', color: PLAYER_COLORS.M, x: 150 },
        { label: 'GIRL', color: PLAYER_COLORS.F, x: 330 }
      ];
      options.forEach((opt, idx) => {
        drawWindow(this.ctx, opt.x - 70, 118, 140, 120, idx === this.genderIndex);
        drawTrainerBust(this.ctx, opt.x, 162, opt.color, idx === this.genderIndex ? 1.04 : 1);
        centerText(this.ctx, opt.label, opt.x, 222, 18, 1, '#ffffff');
      });
      this.drawDialogue('Are you a boy? Or are you a girl?');
    }

    drawPostGender() {
      this.drawBg('#edf5fa', '#c8dceb');
      drawOak(this.ctx, 135, 160);
      drawTrainerBust(this.ctx, 355, 170, PLAYER_COLORS[this.profile.gender], 1);
      this.drawDialogue(this.config.postGender[this.postGenderLine]);
    }

    drawNameEntry(now) {
      this.drawBg('#ebeff4', '#9fb6c6');
      const targetLabel = this.nameMode === 'player' ? '플레이어 이름' : '라이벌 이름';
      const targetValue = this.nameMode === 'player' ? this.profile.playerName : this.profile.rivalName;
      centerText(this.ctx, targetLabel, 240, 72, 20, 1, '#1b2732');
      drawWindow(this.ctx, 88, 112, 304, 74, true);
      centerText(this.ctx, targetValue || '__________', 240, 156, 24, 1, '#ffffff');
      const blink = Math.sin(now / 250) > 0 ? 1 : 0;
      if (targetValue.length < 10) {
        const width = this.ctx.measureText(targetValue || '').width;
        this.ctx.save();
        this.ctx.globalAlpha = blink;
        this.ctx.fillStyle = '#d9f9ff';
        this.ctx.fillRect(240 + width / 2 + 6, 136, 10, 22);
        this.ctx.restore();
      }
      centerText(this.ctx, '영문/숫자 입력 · Backspace 지우기 · Enter 확정', 240, 224, 12, 0.86, '#20313d');
      this.drawDialogue(this.nameMode === 'player' ? '너의 이름을 입력해라.' : '이제 라이벌의 이름을 입력해라.');
    }

    drawRoomStart() {
      this.drawRoomMap();
      this.drawDialogue(this.config.roomMessage);
      centerText(this.ctx, `${this.profile.playerName || 'PLAYER'} · ${this.room.mapId}`, 240, 28, 12, 0.9, '#f6fbff');
    }

    drawRoomMap() {
      const ctx = this.ctx;
      this.drawBg('#7aa6cc', '#29445e');
      drawWindow(ctx, 84, 40, 312, 206, false, '#a27551', '#623f26');

      for (let y = 0; y < 6; y++) {
        for (let x = 0; x < 9; x++) {
          const px = 114 + x * 28;
          const py = 70 + y * 24;
          ctx.fillStyle = (x + y) % 2 === 0 ? '#d8c89f' : '#c8b78a';
          ctx.fillRect(px, py, 28, 24);
        }
      }

      ctx.fillStyle = '#6f4b30';
      ctx.fillRect(128, 78, 72, 40); // bed
      ctx.fillStyle = '#d05050';
      ctx.fillRect(128, 78, 72, 12);
      ctx.fillStyle = '#8fcbc5';
      ctx.fillRect(250, 82, 40, 34); // pc desk
      ctx.fillStyle = '#4a5f7c';
      ctx.fillRect(296, 82, 18, 22);
      ctx.fillStyle = '#8d6b44';
      ctx.fillRect(320, 160, 42, 54); // stairs area
      ctx.fillStyle = '#49515a';
      ctx.fillRect(322, 184, 38, 8);
      ctx.fillStyle = '#5a3c23';
      ctx.fillRect(208, 208, 56, 28); // rug

      drawTrainerMini(ctx, 114 + this.room.x * 28, 70 + this.room.y * 24, PLAYER_COLORS[this.profile.gender], this.room.dir);
      centerText(ctx, '방 시작 / 로컬 이동 테스트', 240, 264, 12, 0.92, '#e7f0f6');
    }

    drawDialogue(text) {
      drawWindow(this.ctx, 24, 246, 432, 60, false, '#102030', '#365d79');
      wrapText(this.ctx, text, 44, 270, 392, 20, '#ffffff', 14);
    }

    loop(now) {
      if (!this.running) return;
      this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

      if (this.state === 'intro') {
        const scene = this.currentScene();
        const elapsed = now - this.sceneStart;
        const duration = scene.duration || 1000;
        const t = Math.max(0, Math.min(1, elapsed / duration));
        this.drawBg();
        scene.draw(this.ctx, t, this);
        if (elapsed >= duration) {
          this.advanceIntro();
        }
      } else if (this.state === 'title') {
        this.drawTitle(now);
      } else if (this.state === 'main-menu') {
        this.drawMenu();
      } else if (this.state === 'oak-intro') {
        this.drawOakIntro();
      } else if (this.state === 'gender-select') {
        this.drawGenderSelect();
      } else if (this.state === 'post-gender') {
        this.drawPostGender();
      } else if (this.state === 'name-entry') {
        this.drawNameEntry(now);
      } else if (this.state === 'room-start') {
        this.drawRoomStart();
      }

      requestAnimationFrame(this.boundLoop);
    }
  }

  function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
  }

  function centerText(ctx, text, x, y, size, alpha = 1, color = '#fff') {
    ctx.save();
    ctx.globalAlpha = alpha;
    ctx.textAlign = 'center';
    ctx.fillStyle = color;
    ctx.font = `${size}px Arial`;
    ctx.fillText(text, x, y);
    ctx.restore();
  }

  function drawWindow(ctx, x, y, w, h, selected = false, fill = '#0e2132', line = '#4ca7db') {
    ctx.save();
    ctx.fillStyle = fill;
    ctx.fillRect(x, y, w, h);
    ctx.strokeStyle = selected ? '#ffffff' : line;
    ctx.lineWidth = selected ? 3 : 2;
    ctx.strokeRect(x + 1, y + 1, w - 2, h - 2);
    ctx.restore();
  }

  function drawLeafBackdrop(ctx, now) {
    const t = now / 1000;
    for (let i = 0; i < 9; i++) {
      const x = 60 + i * 46 + Math.sin(t + i) * 8;
      const y = 34 + (i % 2) * 24 + Math.cos(t * 1.3 + i) * 10;
      ctx.save();
      ctx.translate(x, y);
      ctx.rotate(Math.sin(t + i) * 0.4);
      ctx.fillStyle = i % 2 ? '#2fbf63' : '#6ed86e';
      ctx.beginPath();
      ctx.moveTo(0, -18);
      ctx.quadraticCurveTo(22, -2, 0, 18);
      ctx.quadraticCurveTo(-22, -2, 0, -18);
      ctx.fill();
      ctx.restore();
    }
  }

  function drawOak(ctx, x, y) {
    ctx.save();
    ctx.translate(x, y);
    ctx.fillStyle = '#f0d6bc';
    ctx.beginPath();
    ctx.arc(0, -46, 28, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(-28, -18, 56, 72);
    ctx.fillStyle = '#d9e3ee';
    ctx.fillRect(-10, -18, 20, 72);
    ctx.fillStyle = '#7f7f85';
    ctx.fillRect(-24, 54, 18, 36);
    ctx.fillRect(6, 54, 18, 36);
    ctx.fillStyle = '#cfd7de';
    ctx.fillRect(-18, -4, 10, 34);
    ctx.fillRect(8, -4, 10, 34);
    ctx.fillStyle = '#f5f5f5';
    ctx.fillRect(-34, -70, 68, 10);
    ctx.restore();
  }

  function drawPokemonBuddy(ctx, x, y) {
    ctx.save();
    ctx.translate(x, y);
    ctx.fillStyle = '#79c15b';
    ctx.beginPath();
    ctx.arc(0, -14, 34, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillRect(-28, 10, 56, 32);
    ctx.fillStyle = '#d94d53';
    ctx.fillRect(-10, -56, 20, 24);
    ctx.restore();
  }

  function drawTrainerBust(ctx, x, y, color, scale) {
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(scale, scale);
    ctx.fillStyle = '#f1d1bf';
    ctx.beginPath();
    ctx.arc(0, -26, 26, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = color;
    ctx.fillRect(-24, 0, 48, 58);
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(-12, 8, 24, 40);
    ctx.restore();
  }

  function drawTrainerMini(ctx, x, y, color, dir) {
    ctx.save();
    ctx.translate(x + 14, y + 12);
    ctx.fillStyle = '#f1d1bf';
    ctx.fillRect(-5, -11, 10, 10);
    ctx.fillStyle = color;
    ctx.fillRect(-7, -1, 14, 12);
    ctx.fillStyle = '#2e3440';
    ctx.fillRect(-6, 11, 5, 9);
    ctx.fillRect(1, 11, 5, 9);
    ctx.fillStyle = '#ffffff';
    if (dir === 'left') ctx.fillRect(-10, 0, 4, 7);
    if (dir === 'right') ctx.fillRect(6, 0, 4, 7);
    if (dir === 'up') ctx.fillRect(-2, -16, 4, 4);
    if (dir === 'down') ctx.fillRect(-2, 2, 4, 4);
    ctx.restore();
  }

  function wrapText(ctx, text, x, y, maxWidth, lineHeight, color, size) {
    ctx.save();
    ctx.fillStyle = color;
    ctx.font = `${size}px Arial`;
    ctx.textAlign = 'left';
    const words = text.split(' ');
    let line = '';
    let row = 0;
    for (let n = 0; n < words.length; n++) {
      const testLine = line + words[n] + ' ';
      const testWidth = ctx.measureText(testLine).width;
      if (testWidth > maxWidth && n > 0) {
        ctx.fillText(line.trim(), x, y + row * lineHeight);
        line = words[n] + ' ';
        row += 1;
      } else {
        line = testLine;
      }
    }
    ctx.fillText(line.trim(), x, y + row * lineHeight);
    ctx.restore();
  }

  window.FRLG.IntroEngine = IntroEngine;
})();
