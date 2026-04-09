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
      this.flow = config.flow;
      this.mapsConfig = config.maps;
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
      this.progress = {
        starter: null,
        rivalStarter: null,
        flags: {
          starterChosen: false,
          firstBattleDone: false
        }
      };
      this.battle = null;
      this.pendingBattle = null;
      const startMapId = this.mapsConfig.startMapId;
      const startMap = this.mapsConfig.maps[startMapId];
      this.world = {
        mapId: startMapId,
        x: startMap.start.x,
        y: startMap.start.y,
        dir: startMap.start.dir || 'down',
        lastWarp: null
      };
      this.fieldDialogue = null;
      this.continueSnapshot = this.readLocalSnapshot();
      this.updateStatus();
      this.emitSaveState();
    }

    currentScene() {
      return this.flow.introScenes[this.introIndex];
    }

    currentMap() {
      return this.mapsConfig.maps[this.world.mapId];
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
      const current = this.state === 'intro'
        ? this.currentScene().id
        : this.state === 'field'
          ? this.world.mapId
          : this.state;
      if (this.hooks.onSceneChange) this.hooks.onSceneChange(current);
      if (this.hooks.onPhaseChange) this.hooks.onPhaseChange(this.state);
    }

    emitSaveState() {
      if (!this.hooks.onSaveStateChange) return;
      if (this.state === 'field') {
        const starter = this.progress && this.progress.starter ? ` / ${this.progress.starter}` : '';
        const battle = this.getFlag('firstBattleDone') ? ' / battle1' : '';
        const desc = `${this.profile.playerName || 'PLAYER'} / ${this.world.mapId} / ${this.world.x},${this.world.y}${starter}${battle}`;
        this.hooks.onSaveStateChange(desc);
        return;
      }
      this.hooks.onSaveStateChange(this.continueSnapshot ? 'continue 가능' : 'local only');
    }

    setState(nextState) {
      this.state = nextState;
      this.sceneStart = performance.now();
      this.updateStatus();
      this.emitSaveState();
    }

    advanceIntro() {
      if (this.introIndex < this.flow.introScenes.length - 1) {
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
          if (this.oakLine < this.flow.oakIntro.length - 1) {
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
          if (this.postGenderLine < this.flow.postGender.length - 1) {
            this.postGenderLine += 1;
          } else {
            this.nameMode = 'player';
            this.setState('name-entry');
          }
          break;
        case 'name-entry':
          this.confirmNameEntry();
          break;
        case 'field':
          if (this.consumeFieldDialogue()) break;
          if (this.tryInteract()) break;
          this.saveLocalSnapshot();
          this.setFieldDialogue([this.flow.saveMessage || 'LOCAL 저장 완료.']);
          break;
        case 'battle':
          this.handleBattleAction();
          break;
      }
    }

    handleMenuConfirm() {
      if (this.menuIndex === 0) {
        this.oakLine = 0;
        this.setState('oak-intro');
        return;
      }
      if (this.menuIndex === 1 && this.continueSnapshot) {
        this.applySnapshot(this.continueSnapshot);
        this.setState('field');
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
        this.setState('field');
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
        case 'field':
          if (this.hasFieldDialogue()) return;
          this.movePlayer(dx, dy);
          break;
        case 'battle':
          if (this.battle && this.battle.phase === 'command') {
            this.battle.selectedMove = clamp(this.battle.selectedMove + (dy || dx), 0, 1);
          }
          break;
      }
    }

    movePlayer(dx, dy) {
      if (dx === 0 && dy === 0) return;
      const map = this.currentMap();
      const nextX = this.world.x + dx;
      const nextY = this.world.y + dy;
      if (dx < 0) this.world.dir = 'left';
      if (dx > 0) this.world.dir = 'right';
      if (dy < 0) this.world.dir = 'up';
      if (dy > 0) this.world.dir = 'down';
      if (nextX < 0 || nextX >= map.width || nextY < 0 || nextY >= map.height) {
        this.emitSaveState();
        return;
      }
      if (this.handleFieldMoveGate(map, nextX, nextY)) {
        this.emitSaveState();
        return;
      }
      if (this.isBlocked(map, nextX, nextY)) {
        this.emitSaveState();
        return;
      }
      this.world.x = nextX;
      this.world.y = nextY;
      this.tryWarp();
      this.clearFieldDialogue();
      this.emitSaveState();
    }

    isBlocked(map, x, y) {
      return Array.isArray(map.blocked) && map.blocked.includes(`${x},${y}`);
    }

    tryWarp() {
      const map = this.currentMap();
      const warp = (map.warpEvents || []).find((entry) => entry.x === this.world.x && entry.y === this.world.y);
      if (!warp) return;
      const destMapId = normalizeMapId(warp.dest_map);
      const destMap = this.mapsConfig.maps[destMapId];
      if (!destMap) return;
      const destWarpIndex = Number(warp.dest_warp_id) || 0;
      const targetWarp = (destMap.warpEvents || [])[destWarpIndex] || null;
      this.world.mapId = destMapId;
      if (targetWarp) {
        this.world.x = targetWarp.x;
        this.world.y = targetWarp.y;
      } else if (destMap.start) {
        this.world.x = destMap.start.x;
        this.world.y = destMap.start.y;
      }
      this.world.dir = chooseWarpFacing(warp, targetWarp);
      this.world.lastWarp = {
        from: map.id,
        to: destMapId,
        destWarpId: destWarpIndex
      };
    }

    saveLocalSnapshot() {
      const payload = {
        profile: this.profile,
        progress: this.progress,
        world: this.world,
        state: 'field'
      };
      try {
        localStorage.setItem('frlg_local_profile', JSON.stringify(payload));
        this.continueSnapshot = payload;
      } catch (err) {
        // ignore local storage failures in starter mode
      }
      this.emitSaveState();
    }

    readLocalSnapshot() {
      try {
        const raw = localStorage.getItem('frlg_local_profile');
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || !parsed.profile || !parsed.world) return null;
        return parsed;
      } catch (err) {
        return null;
      }
    }

    applySnapshot(snapshot) {
      this.profile = {
        gender: snapshot.profile.gender || 'M',
        playerName: snapshot.profile.playerName || '',
        rivalName: snapshot.profile.rivalName || ''
      };
      this.progress = {
        starter: (snapshot.progress && snapshot.progress.starter) || null,
        rivalStarter: (snapshot.progress && snapshot.progress.rivalStarter) || null,
        flags: {
          starterChosen: !!(snapshot.progress && snapshot.progress.flags && snapshot.progress.flags.starterChosen),
          firstBattleDone: !!(snapshot.progress && snapshot.progress.flags && snapshot.progress.flags.firstBattleDone)
        }
      };
      this.battle = null;
      this.pendingBattle = null;
      const fallbackMap = this.mapsConfig.startMapId;
      this.world = {
        mapId: this.mapsConfig.maps[snapshot.world.mapId] ? snapshot.world.mapId : fallbackMap,
        x: Number.isFinite(snapshot.world.x) ? snapshot.world.x : this.mapsConfig.maps[fallbackMap].start.x,
        y: Number.isFinite(snapshot.world.y) ? snapshot.world.y : this.mapsConfig.maps[fallbackMap].start.y,
        dir: snapshot.world.dir || 'down',
        lastWarp: snapshot.world.lastWarp || null
      };
    }


    getFlag(name) {
      return !!(this.progress && this.progress.flags && this.progress.flags[name]);
    }

    setFlag(name, value = true) {
      if (!this.progress) {
        this.progress = { starter: null, rivalStarter: null, flags: {} };
      }
      if (!this.progress.flags) this.progress.flags = {};
      this.progress.flags[name] = !!value;
    }

    getStarterLabel(id) {
      const labels = {
        BULBASAUR: '이상해씨',
        CHARMANDER: '파이리',
        SQUIRTLE: '꼬부기'
      };
      return labels[id] || id || '포켓몬';
    }

    getStarterAccent(id) {
      const accents = {
        BULBASAUR: '#7dd27d',
        CHARMANDER: '#ff8b57',
        SQUIRTLE: '#6bbcff'
      };
      return accents[id] || '#ffffff';
    }

    getRivalStarter(playerStarter) {
      const table = {
        BULBASAUR: 'CHARMANDER',
        CHARMANDER: 'SQUIRTLE',
        SQUIRTLE: 'BULBASAUR'
      };
      return table[playerStarter] || 'CHARMANDER';
    }

    queueBattleStart(kind) {
      this.pendingBattle = { kind };
    }

    startPendingBattleIfNeeded() {
      if (!this.pendingBattle) return;
      const pending = this.pendingBattle;
      this.pendingBattle = null;
      if (pending.kind === 'starter-rival') {
        this.startStarterBattle();
      }
    }

    startStarterBattle() {
      const playerStarter = this.progress.starter || 'BULBASAUR';
      const rivalStarter = this.getRivalStarter(playerStarter);
      this.progress.rivalStarter = rivalStarter;
      this.battle = {
        type: 'starter-rival',
        phase: 'intro',
        lineIndex: 0,
        selectedMove: 0,
        playerHp: 20,
        enemyHp: 20,
        round: 0,
        playerStarter,
        rivalStarter,
        lines: [
          `${this.profile.rivalName || 'RIVAL'}: 그럼 내가 이 녀석으로 간다!`,
          `${this.profile.rivalName || 'RIVAL'}의 ${this.getStarterLabel(rivalStarter)}가 승부를 걸어왔다!`,
          `${this.profile.playerName || 'PLAYER'}! 가라! ${this.getStarterLabel(playerStarter)}!`
        ]
      };
      this.setState('battle');
    }

    executeBattleTurn() {
      if (!this.battle) return;
      const move = this.battle.selectedMove === 0 ? '몸통박치기' : '울음소리';
      const playerStarter = this.battle.playerStarter;
      const rivalStarter = this.battle.rivalStarter;
      const playerLabel = this.getStarterLabel(playerStarter);
      const rivalLabel = this.getStarterLabel(rivalStarter);
      const playerDamage = this.battle.selectedMove === 0 ? 11 : 4;
      const rivalDamage = this.battle.round >= 1 ? 3 : 6;
      this.battle.enemyHp = Math.max(0, this.battle.enemyHp - playerDamage);
      const turnLines = [
        `${playerLabel}의 ${move}!`,
        `${rivalLabel}에게 ${playerDamage}의 placeholder 데미지!`
      ];
      if (this.battle.enemyHp <= 0 || this.battle.round >= 1) {
        this.battle.phase = 'result';
        this.battle.lineIndex = 0;
        this.battle.lines = turnLines.concat([
          `${rivalLabel}은(는) 더는 싸울 수 없다!`,
          `${this.profile.rivalName || 'RIVAL'}와의 첫 배틀에서 이겼다!`
        ]);
        return;
      }
      this.battle.playerHp = Math.max(0, this.battle.playerHp - rivalDamage);
      this.battle.round += 1;
      this.battle.phase = 'turn';
      this.battle.lineIndex = 0;
      this.battle.lines = turnLines.concat([
        `${this.profile.rivalName || 'RIVAL'}의 ${rivalLabel}의 반격!`,
        `${playerLabel}이(가) ${rivalDamage}의 placeholder 데미지를 받았다!`
      ]);
    }

    finishBattle() {
      if (!this.battle) return;
      this.setFlag('firstBattleDone', true);
      this.progress.rivalStarter = this.battle.rivalStarter;
      this.battle = null;
      this.saveLocalSnapshot();
      this.setState('field');
      this.setFieldDialogue((this.flow.events && this.flow.events.rivalBattleWin) || [
        '첫 배틀 skeleton 완료.',
        '다음엔 라이벌 퇴장 / 오박사 후속 대사 / 실제 전투 규칙을 붙이면 된다.'
      ]);
    }

    isObjectHidden(entry) {
      if (!entry) return false;
      if (entry.hiddenWhen && this.getFlag(entry.hiddenWhen)) return true;
      if (entry.hiddenByFlag && this.getFlag(entry.hiddenByFlag)) return true;
      return false;
    }

    handleFieldMoveGate(map, nextX, nextY) {
      if (!map || map.id !== 'PalletTown') return false;
      if (this.getFlag('starterChosen')) return false;
      const north = map.structures && map.structures.northBlock;
      if (!north) return false;
      if (!isInsideRect(nextX, nextY, north)) return false;
      return this.setFieldDialogue((this.flow.events && this.flow.events.oakStopsYou) || ['오박사: 아직 혼자 밖으로 나가면 위험하단다.']);
    }

    hasFieldDialogue() {
      return !!(this.fieldDialogue && this.fieldDialogue.lines && this.fieldDialogue.lines.length);
    }

    getActiveFieldText() {
      if (this.hasFieldDialogue()) {
        return this.fieldDialogue.lines[this.fieldDialogue.index] || this.flow.roomMessage;
      }
      const hint = this.peekInteractionHint();
      return hint || this.flow.roomMessage;
    }

    setFieldDialogue(lines) {
      if (!Array.isArray(lines) || !lines.length) return false;
      this.fieldDialogue = { lines, index: 0 };
      return true;
    }

    clearFieldDialogue() {
      this.fieldDialogue = null;
    }

    consumeFieldDialogue() {
      if (!this.hasFieldDialogue()) return false;
      if (this.fieldDialogue.index < this.fieldDialogue.lines.length - 1) {
        this.fieldDialogue.index += 1;
      } else {
        this.clearFieldDialogue();
        this.startPendingBattleIfNeeded();
      }
      return true;
    }

    getFacingPos() {
      const offsets = {
        up: { x: 0, y: -1 },
        down: { x: 0, y: 1 },
        left: { x: -1, y: 0 },
        right: { x: 1, y: 0 }
      };
      const delta = offsets[this.world.dir] || offsets.down;
      return { x: this.world.x + delta.x, y: this.world.y + delta.y };
    }

    isFacingTile(x, y) {
      const pos = this.getFacingPos();
      return pos.x === x && pos.y === y;
    }

    peekInteractionHint() {
      const target = this.findInteractionTarget();
      if (!target) return null;
      if (target.kind === 'object') return 'Enter로 대화하기';
      if (target.kind === 'sign') return 'Enter로 조사하기';
      return null;
    }

    tryInteract() {
      const target = this.findInteractionTarget();
      if (!target) return false;
      const lines = this.resolveInteractionLines(target);
      if (!lines || !lines.length) return false;
      return this.setFieldDialogue(lines);
    }

    handleBattleAction() {
      if (!this.battle) return;
      if (this.battle.phase === 'intro' || this.battle.phase === 'turn' || this.battle.phase === 'result') {
        if (this.battle.lineIndex < this.battle.lines.length - 1) {
          this.battle.lineIndex += 1;
          return;
        }
        if (this.battle.phase === 'intro') {
          this.battle.phase = 'command';
          this.battle.lineIndex = 0;
          return;
        }
        if (this.battle.phase === 'turn') {
          this.battle.phase = 'command';
          this.battle.lineIndex = 0;
          return;
        }
        if (this.battle.phase === 'result') {
          this.finishBattle();
        }
        return;
      }
      if (this.battle.phase === 'command') {
        this.executeBattleTurn();
      }
    }

    findInteractionTarget() {
      const map = this.currentMap();
      const pos = this.getFacingPos();
      const object = (map.objectEvents || []).find((entry) => entry.x === pos.x && entry.y === pos.y && !this.isObjectHidden(entry));
      if (object) return { kind: 'object', data: object };
      const sign = (map.bgEvents || []).find((entry) => entry.x === pos.x && entry.y === pos.y);
      if (sign) return { kind: 'sign', data: sign };
      return null;
    }

    resolveSpecialObjectInteraction(data) {
      const script = data.script || '';
      if (script === 'PalletTown_EventScript_OakStopsYou') {
        return (this.flow.events && this.flow.events.oakStopsYou) || ['오박사: 연구소로 오렴.'];
      }
      if (script === 'PalletTown_ProfessorOaksLab_EventScript_Rival') {
        if (!this.getFlag('starterChosen')) {
          return (this.flow.npcLines && this.flow.npcLines[script]) || ['라이벌: 먼저 골라 보라고.'];
        }
        if (!this.getFlag('firstBattleDone')) {
          this.queueBattleStart('starter-rival');
          return (this.flow.events && this.flow.events.rivalBattleChallenge) || [
            `${this.profile.rivalName || 'RIVAL'}: 좋아! 바로 한판 해 보자!`
          ];
        }
        return (this.flow.events && this.flow.events.rivalAfterBattle) || ['라이벌: 흥! 다음엔 안 질 거야!'];
      }
      const starterMap = {
        PalletTown_ProfessorOaksLab_EventScript_BulbasaurBall: 'BULBASAUR',
        PalletTown_ProfessorOaksLab_EventScript_CharmanderBall: 'CHARMANDER',
        PalletTown_ProfessorOaksLab_EventScript_SquirtleBall: 'SQUIRTLE'
      };
      const starterId = starterMap[script] || null;
      if (starterId) {
        if (this.getFlag('starterChosen')) {
          return (this.flow.events && this.flow.events.starterAlreadyChosen) || ['이미 스타터를 골랐다.'];
        }
        this.progress.starter = starterId;
        this.progress.rivalStarter = this.getRivalStarter(starterId);
        this.setFlag('starterChosen', true);
        this.saveLocalSnapshot();
        if (this.hooks.onSceneChange) this.hooks.onSceneChange(this.world.mapId);
        return (this.flow.events && this.flow.events.starterPick && this.flow.events.starterPick[starterId]) || [`${starterId} 선택 완료.`];
      }
      if (script === 'PalletTown_ProfessorOaksLab_EventScript_ProfOak') {
        if (this.getFlag('firstBattleDone')) {
          return (this.flow.events && this.flow.events.oakAfterBattle) || ['오박사: 좋아. 이제 진짜 모험의 시작이란다.'];
        }
        if (this.getFlag('starterChosen')) {
          return (this.flow.events && this.flow.events.oakAfterStarter) || ['오박사: 이제 시작이구나.'];
        }
      }
      return null;
    }

    resolveInteractionLines(target) {
      const data = target.data || {};
      if (target.kind === 'sign') {
        const text = (this.flow.signs && this.flow.signs[data.script]) || `${data.script || 'sign'} 를 조사했다.`;
        return [text];
      }
      const special = this.resolveSpecialObjectInteraction(data);
      if (special && special.length) return special;
      const lines = (this.flow.npcLines && this.flow.npcLines[data.script]) || null;
      if (Array.isArray(lines) && lines.length) return lines;
      if (data.script) return [`${data.script} placeholder 대화`];
      return ['아직 연결되지 않은 오브젝트다.'];
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
      const canContinue = !!this.continueSnapshot;
      const items = ['NEW GAME', canContinue ? 'CONTINUE (LOCAL)' : 'CONTINUE (없음)'];
      items.forEach((item, idx) => {
        const y = 150 + idx * 40;
        drawWindow(this.ctx, 132, y - 20, 216, 30, idx === this.menuIndex);
        const alpha = idx === 1 && !canContinue ? 0.42 : 1;
        centerText(this.ctx, item, 240, y, 16, alpha, '#ffffff');
      });
      centerText(this.ctx, '방향키 선택 · Enter 확인', 240, 286, 11, 0.8, '#9ccdd7');
    }

    drawOakIntro() {
      this.drawBg('#e8f1f6', '#b7d1db');
      drawOak(this.ctx, 135, 160);
      drawPokemonBuddy(this.ctx, 355, 188);
      this.drawDialogue(this.flow.oakIntro[this.oakLine]);
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
      this.drawDialogue(this.flow.postGender[this.postGenderLine]);
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
        this.ctx.save();
        this.ctx.font = '24px Arial';
        const width = this.ctx.measureText(targetValue || '').width;
        this.ctx.globalAlpha = blink;
        this.ctx.fillStyle = '#d9f9ff';
        this.ctx.fillRect(240 + width / 2 + 6, 136, 10, 22);
        this.ctx.restore();
      }
      centerText(this.ctx, '영문/숫자 입력 · Backspace 지우기 · Enter 확정', 240, 224, 12, 0.86, '#20313d');
      this.drawDialogue(this.nameMode === 'player' ? '너의 이름을 입력해라.' : '이제 라이벌의 이름을 입력해라.');
    }

    drawField() {
      const map = this.currentMap();
      if (map.mapType === 'MAP_TYPE_TOWN') {
        this.drawTownMap(map);
      } else {
        this.drawIndoorMap(map);
      }
      const starterTag = this.progress && this.progress.starter ? ` · ${this.progress.starter}` : '';
      const mapNote = `${this.profile.playerName || 'PLAYER'}${starterTag} · ${map.id} · ${this.world.x},${this.world.y}`;
      centerText(this.ctx, mapNote, 240, 28, 12, 0.92, '#f6fbff');
      const activeText = this.getActiveFieldText();
      this.drawDialogue(activeText);
    }

    drawIndoorMap(map) {
      const ctx = this.ctx;
      this.drawBg('#7aa6cc', '#29445e');
      const tile = fitTileSize(map.width, map.height, 300, 196, 16, 24);
      const origin = centeredOrigin(this.canvas.width, this.canvas.height, map.width, map.height, tile, 32);
      drawWindow(ctx, origin.x - 12, origin.y - 12, map.width * tile + 24, map.height * tile + 24, false, '#a27551', '#623f26');
      for (let y = 0; y < map.height; y++) {
        for (let x = 0; x < map.width; x++) {
          const px = origin.x + x * tile;
          const py = origin.y + y * tile;
          ctx.fillStyle = (x + y) % 2 === 0 ? '#d8c89f' : '#c8b78a';
          ctx.fillRect(px, py, tile, tile);
          if (this.isBlocked(map, x, y)) {
            ctx.fillStyle = 'rgba(80, 58, 38, 0.22)';
            ctx.fillRect(px, py, tile, tile);
          }
        }
      }
      if (map.structures) {
        if (map.structures.bed) drawRectTiles(ctx, origin, tile, map.structures.bed, '#6f4b30', '#d05050');
        if (map.structures.pc) drawRectTiles(ctx, origin, tile, map.structures.pc, '#8fcbc5', '#4a5f7c');
        if (map.structures.shelf) drawRectTiles(ctx, origin, tile, map.structures.shelf, '#8d6b44', '#8d6b44');
        if (map.structures.kitchen) drawRectTiles(ctx, origin, tile, map.structures.kitchen, '#7f5f41', '#b7dbe2');
        if (map.structures.table) drawRectTiles(ctx, origin, tile, map.structures.table, '#6b4d33', '#d9d1af');
        if (map.structures.tv) drawRectTiles(ctx, origin, tile, map.structures.tv, '#3d4755', '#7f95aa');
        if (map.structures.entryDoor) drawDoor(ctx, origin, tile, map.structures.entryDoor);
        if (map.structures.mapStand) drawRectTiles(ctx, origin, tile, map.structures.mapStand, '#cdbb76', '#8e7341');
        if (map.structures.desk) drawRectTiles(ctx, origin, tile, map.structures.desk, '#6f5138', '#d3d0ba');
        if (map.structures.sofa) drawRectTiles(ctx, origin, tile, map.structures.sofa, '#658fc2', '#c8d5ec');
        if (map.structures.leftBench) drawRectTiles(ctx, origin, tile, map.structures.leftBench, '#6b5137', '#a0c2d8');
        if (map.structures.rightBench) drawRectTiles(ctx, origin, tile, map.structures.rightBench, '#6b5137', '#a0c2d8');
        if (map.structures.starterTable) drawRectTiles(ctx, origin, tile, map.structures.starterTable, '#6f5138', '#d8ccb2');
        if (map.structures.leftShelves) drawRectTiles(ctx, origin, tile, map.structures.leftShelves, '#8d6b44', '#8d6b44');
        if (map.structures.rightShelves) drawRectTiles(ctx, origin, tile, map.structures.rightShelves, '#8d6b44', '#8d6b44');
        if (map.structures.stairs) drawStairs(ctx, origin, tile, map.structures.stairs);
        if (map.structures.door) drawDoor(ctx, origin, tile, map.structures.door);
      }
      this.drawMapEvents(map, origin, tile);
    }

    drawTownMap(map) {
      const ctx = this.ctx;
      this.drawBg('#7db3ec', '#4374a0');
      const tile = fitTileSize(map.width, map.height, 360, 232, 12, 20);
      const origin = centeredOrigin(this.canvas.width, this.canvas.height, map.width, map.height, tile, 30);
      for (let y = 0; y < map.height; y++) {
        for (let x = 0; x < map.width; x++) {
          const px = origin.x + x * tile;
          const py = origin.y + y * tile;
          ctx.fillStyle = '#5dbb63';
          ctx.fillRect(px, py, tile, tile);
        }
      }
      const pathRects = (map.structures && map.structures.path) || [];
      pathRects.forEach((rect) => drawRectTiles(ctx, origin, tile, rect, '#d1bd7a', '#d1bd7a'));
      if (map.structures) {
        if (map.structures.playerHouse) drawHouse(ctx, origin, tile, map.structures.playerHouse, '#f1efe8', '#cd5850');
        if (map.structures.rivalHouse) drawHouse(ctx, origin, tile, map.structures.rivalHouse, '#ececf1', '#6692d0');
        if (map.structures.oaksLab) drawHouse(ctx, origin, tile, map.structures.oaksLab, '#ede4d0', '#b67c44');
        if (map.structures.northBlock) drawRectTiles(ctx, origin, tile, map.structures.northBlock, '#4c8f4f', '#4c8f4f');
      }
      this.drawMapEvents(map, origin, tile);
    }

    drawMapEvents(map, origin, tile) {
      const ctx = this.ctx;
      (map.bgEvents || []).forEach((event) => {
        drawMarker(ctx, origin, tile, event.x, event.y, '#ffe46f', '?');
      });
      (map.warpEvents || []).forEach((event) => {
        drawMarker(ctx, origin, tile, event.x, event.y, '#6fd7ff', 'W');
      });
      (map.objectEvents || []).forEach((event) => {
        if (event.hiddenByFlag) return;
        const isInteract = this.isFacingTile(event.x, event.y);
        drawNpc(ctx, origin, tile, event.x, event.y, objectColor(event), isInteract);
      });
      drawTrainerMini(ctx, origin.x + this.world.x * tile, origin.y + this.world.y * tile, PLAYER_COLORS[this.profile.gender], this.world.dir, tile);
    }

    drawDialogue(text) {
      drawWindow(this.ctx, 24, 246, 432, 60, false, '#102030', '#365d79');
      wrapText(this.ctx, text, 44, 270, 392, 20, '#ffffff', 14);
    }

    drawBattle() {
      const ctx = this.ctx;
      const battle = this.battle;
      if (!battle) return;
      this.drawBg('#dcecc9', '#6f9664');
      drawBattleBackdrop(ctx);
      drawBattleStatus(ctx, 42, 42, `${this.profile.rivalName || 'RIVAL'} · ${this.getStarterLabel(battle.rivalStarter)}`, battle.enemyHp, false);
      drawBattleStatus(ctx, 248, 188, `${this.profile.playerName || 'PLAYER'} · ${this.getStarterLabel(battle.playerStarter)}`, battle.playerHp, true);
      drawBattleMon(ctx, 342, 126, this.getStarterAccent(battle.rivalStarter), 1.0, false);
      drawBattleMon(ctx, 122, 216, this.getStarterAccent(battle.playerStarter), 1.1, true);

      if (battle.phase === 'command') {
        drawWindow(ctx, 250, 236, 188, 62, false, '#3b3f55', '#21263a');
        centerText(ctx, '무엇을 할까?', 88, 258, 14, 1, '#1c2430');
        const moves = ['몸통박치기', '울음소리'];
        moves.forEach((move, idx) => {
          const y = 258 + idx * 20;
          ctx.fillStyle = idx === battle.selectedMove ? '#fff1a4' : '#ffffff';
          ctx.font = '14px Arial';
          ctx.fillText(`${idx === battle.selectedMove ? '▶' : ' '} ${move}`, 270, y);
        });
      } else {
        const line = battle.lines[battle.lineIndex] || '배틀 skeleton';
        this.drawDialogue(line);
      }
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
      } else if (this.state === 'field') {
        this.drawField();
      } else if (this.state === 'battle') {
        this.drawBattle();
      }

      requestAnimationFrame(this.boundLoop);
    }
  }

  function drawBattleBackdrop(ctx) {
    ctx.save();
    ctx.fillStyle = '#a6c98d';
    ctx.beginPath();
    ctx.ellipse(114, 225, 84, 28, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#94b377';
    ctx.beginPath();
    ctx.ellipse(350, 138, 66, 22, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }

  function drawBattleStatus(ctx, x, y, label, hp, playerSide) {
    drawWindow(ctx, x, y, 188, 54, false, playerSide ? '#d8e8ef' : '#eef2d8', '#334250');
    ctx.fillStyle = '#13202a';
    ctx.font = '13px Arial';
    ctx.fillText(label, x + 12, y + 18);
    ctx.fillText(`HP ${Math.max(0, hp)}/20`, x + 12, y + 38);
    ctx.fillStyle = '#202e34';
    ctx.fillRect(x + 86, y + 26, 82, 10);
    ctx.fillStyle = hp > 10 ? '#61d86f' : (hp > 5 ? '#f6cf62' : '#ef6969');
    ctx.fillRect(x + 86, y + 26, Math.max(0, Math.min(82, Math.round((hp / 20) * 82))), 10);
  }

  function drawBattleMon(ctx, x, y, color, scale, back) {
    ctx.save();
    ctx.translate(x, y);
    if (back) ctx.scale(-1, 1);
    ctx.scale(scale, scale);
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(0, -12, 26, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillRect(-34, 10, 68, 18);
    ctx.fillRect(-26, 24, 14, 18);
    ctx.fillRect(12, 24, 14, 18);
    ctx.fillStyle = 'rgba(255,255,255,0.92)';
    ctx.fillRect(-10, -18, 10, 10);
    ctx.fillStyle = '#142129';
    ctx.fillRect(-6, -14, 4, 4);
    ctx.restore();
  }

  function normalizeMapId(value) {
    return String(value || '').replace(/^MAP_/, '').replace(/_RIVALS_HOUSE$/, '_RivalsHouse').replace(/_PROFESSOR_OAKS_LAB$/, '_ProfessorOaksLab');
  }

  function chooseWarpFacing(sourceWarp, destWarp) {
    if (!sourceWarp || !destWarp) return 'down';
    if (destWarp.y > sourceWarp.y) return 'up';
    if (destWarp.y < sourceWarp.y) return 'down';
    return 'down';
  }

  function fitTileSize(width, height, maxWidth, maxHeight, minTile, maxTile) {
    const byW = Math.floor(maxWidth / width);
    const byH = Math.floor(maxHeight / height);
    return clamp(Math.min(byW, byH), minTile, maxTile);
  }

  function centeredOrigin(canvasWidth, canvasHeight, width, height, tile, topMargin) {
    const mapWidth = width * tile;
    const mapHeight = height * tile;
    return {
      x: Math.floor((canvasWidth - mapWidth) / 2),
      y: Math.floor((canvasHeight - mapHeight - topMargin) / 2) + topMargin
    };
  }

  function drawRectTiles(ctx, origin, tile, rect, fill, accent) {
    ctx.fillStyle = fill;
    ctx.fillRect(origin.x + rect.x * tile, origin.y + rect.y * tile, rect.w * tile, rect.h * tile);
    if (accent && accent !== fill) {
      ctx.fillStyle = accent;
      ctx.fillRect(origin.x + rect.x * tile, origin.y + rect.y * tile, rect.w * tile, Math.max(3, Math.floor(tile * 0.34)));
    }
  }

  function drawStairs(ctx, origin, tile, rect) {
    const x = origin.x + rect.x * tile;
    const y = origin.y + rect.y * tile;
    ctx.fillStyle = '#8d6b44';
    ctx.fillRect(x, y, rect.w * tile, rect.h * tile);
    ctx.fillStyle = '#49515a';
    for (let i = 0; i < 3; i++) {
      ctx.fillRect(x + i * Math.max(3, Math.floor(tile / 4)), y + i * Math.max(3, Math.floor(tile / 5)), rect.w * tile - i * Math.max(4, Math.floor(tile / 3)), 3);
    }
  }

  function drawDoor(ctx, origin, tile, rect) {
    const x = origin.x + rect.x * tile;
    const y = origin.y + rect.y * tile;
    ctx.fillStyle = '#754e31';
    ctx.fillRect(x, y, rect.w * tile, rect.h * tile);
    ctx.fillStyle = '#d5bf7a';
    ctx.fillRect(x + 2, y + 2, rect.w * tile - 4, Math.max(4, Math.floor(tile / 3)));
  }

  function drawHouse(ctx, origin, tile, rect, wallColor, roofColor) {
    const x = origin.x + rect.x * tile;
    const y = origin.y + rect.y * tile;
    ctx.fillStyle = wallColor;
    ctx.fillRect(x, y + tile, rect.w * tile, rect.h * tile - tile);
    ctx.fillStyle = roofColor;
    ctx.fillRect(x, y, rect.w * tile, tile + 3);
    ctx.fillStyle = '#7f5b3f';
    ctx.fillRect(x + tile, y + rect.h * tile - tile, tile, tile);
  }

  function drawMarker(ctx, origin, tile, x, y, color, text) {
    const px = origin.x + x * tile + tile / 2;
    const py = origin.y + y * tile + tile / 2;
    ctx.save();
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(px, py, Math.max(4, tile * 0.22), 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#173043';
    ctx.textAlign = 'center';
    ctx.font = `${Math.max(8, Math.floor(tile * 0.48))}px Arial`;
    ctx.fillText(text, px, py + Math.max(3, tile * 0.14));
    ctx.restore();
  }

  function drawNpc(ctx, origin, tile, x, y, bodyColor, active = false) {
    const px = origin.x + x * tile;
    const py = origin.y + y * tile;
    ctx.save();
    ctx.fillStyle = '#f1d1bf';
    ctx.fillRect(px + tile * 0.34, py + tile * 0.10, tile * 0.32, tile * 0.28);
    ctx.fillStyle = bodyColor;
    ctx.fillRect(px + tile * 0.25, py + tile * 0.38, tile * 0.50, tile * 0.40);
    ctx.fillStyle = '#2e3440';
    ctx.fillRect(px + tile * 0.28, py + tile * 0.78, tile * 0.16, tile * 0.22);
    ctx.fillRect(px + tile * 0.56, py + tile * 0.78, tile * 0.16, tile * 0.22);
    if (active) {
      ctx.strokeStyle = '#fff6a2';
      ctx.lineWidth = Math.max(2, tile * 0.10);
      ctx.strokeRect(px + tile * 0.12, py + tile * 0.06, tile * 0.76, tile * 0.88);
    }
    ctx.restore();
  }

  function objectColor(event) {
    const gid = String(event.graphics_id || '');
    const lid = String(event.local_id || '');
    if (lid.includes('MOM')) return '#f58ea8';
    if (lid.includes('DAISY')) return '#f5d35f';
    if (gid.includes('PROF_OAK')) return '#dedede';
    if (gid.includes('BLUE')) return '#6ea2ff';
    if (gid.includes('ITEM_BALL')) return '#ff6464';
    if (gid.includes('POKEDEX')) return '#e264ff';
    if (gid.includes('TOWN_MAP')) return '#8ad674';
    if (gid.includes('WORKER_F')) return '#f0b7a8';
    return '#f4f4f4';
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

  function drawTrainerMini(ctx, x, y, color, dir, tile = 24) {
    ctx.save();
    ctx.translate(x + tile / 2, y + tile / 2);
    ctx.fillStyle = '#f1d1bf';
    ctx.fillRect(-tile * 0.18, -tile * 0.44, tile * 0.36, tile * 0.34);
    ctx.fillStyle = color;
    ctx.fillRect(-tile * 0.25, -tile * 0.10, tile * 0.50, tile * 0.44);
    ctx.fillStyle = '#2e3440';
    ctx.fillRect(-tile * 0.22, tile * 0.34, tile * 0.16, tile * 0.30);
    ctx.fillRect(tile * 0.06, tile * 0.34, tile * 0.16, tile * 0.30);
    ctx.fillStyle = '#ffffff';
    if (dir === 'left') ctx.fillRect(-tile * 0.36, -tile * 0.06, tile * 0.14, tile * 0.24);
    if (dir === 'right') ctx.fillRect(tile * 0.22, -tile * 0.06, tile * 0.14, tile * 0.24);
    if (dir === 'up') ctx.fillRect(-tile * 0.07, -tile * 0.58, tile * 0.14, tile * 0.14);
    if (dir === 'down') ctx.fillRect(-tile * 0.07, tile * 0.02, tile * 0.14, tile * 0.14);
    ctx.restore();
  }

  function isInsideRect(x, y, rect) {
    if (!rect) return false;
    return x >= rect.x && y >= rect.y && x < rect.x + rect.w && y < rect.y + rect.h;
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
