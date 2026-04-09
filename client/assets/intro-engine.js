window.FRLG = window.FRLG || {};

(function() {
  const KEY_ENTER = new Set(['Enter', 'Space']);
  const PLAYER_COLORS = {
    M: '#ee4e4e',
    F: '#4ec6ff'
  };

  const PACKEGE_ASSET_BASE = 'assets/packege/object_events';
  const PACKEGE_TEXT_WINDOW_BASE = 'assets/packege/text_window';
  const PACKEGE_INTERFACE_BASE = 'assets/packege/interface';
  const PACKEGE_NAMING_BASE = 'assets/packege/naming_screen';
  const PACKEGE_FIELD_ASSETS = createPackegeFieldAssets();
  const PACKEGE_UI_ASSETS = createPackegeUiAssets();
  const PACKEGE_OAK_SPEECH_BASE = 'assets/packege/oak_speech';
  const PACKEGE_OAK_SPEECH_ASSETS = createPackegeOakSpeechAssets();

  const NAME_ENTRY_MAX_CHARS = 7;
  const NAME_ENTRY_PAGE_NEXT = {
    upper: 'lower',
    lower: 'symbols',
    symbols: 'upper'
  };
  const NAME_ENTRY_PAGE_BUTTON_LABEL = {
    upper: 'abc',
    lower: '123',
    symbols: 'ABC'
  };
  const NAME_ENTRY_PAGE_TITLE = {
    upper: 'ABC',
    lower: 'abc',
    symbols: '123'
  };
  const NAME_ENTRY_PAGES = {
    lower: [
      ['a', 'b', 'c', 'd', 'e', 'f', ' ', '.'],
      ['g', 'h', 'i', 'j', 'k', 'l', ' ', ','],
      ['m', 'n', 'o', 'p', 'q', 'r', 's'],
      ['t', 'u', 'v', 'w', 'x', 'y', 'z']
    ],
    upper: [
      ['A', 'B', 'C', 'D', 'E', 'F', ' ', '.'],
      ['G', 'H', 'I', 'J', 'K', 'L', ' ', ','],
      ['M', 'N', 'O', 'P', 'Q', 'R', 'S'],
      ['T', 'U', 'V', 'W', 'X', 'Y', 'Z']
    ],
    symbols: [
      ['0', '1', '2', '3', '4'],
      ['5', '6', '7', '8', '9'],
      ['!', '?', '♂', '♀', '/', '-'],
      ['…', '“', '”', '‘', "'"]
    ]
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
      this.fieldMenuIndex = 0;
      this.oakLine = 0;
      this.genderIndex = 0;
      this.postGenderLine = 0;
      this.nameMode = 'player';
      this.profile = {
        gender: 'M',
        playerName: '',
        rivalName: ''
      };
      this.nameEntry = this.createNameEntryState();
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
      this.oakSpeech = null;
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
        : (this.state === 'field' || this.state === 'field-menu')
          ? this.world.mapId
          : this.state;
      if (this.hooks.onSceneChange) this.hooks.onSceneChange(current);
      if (this.hooks.onPhaseChange) this.hooks.onPhaseChange(this.state);
    }

    emitSaveState() {
      if (!this.hooks.onSaveStateChange) return;
      if (this.state === 'field' || this.state === 'field-menu') {
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
      if (nextState === 'field-menu') this.fieldMenuIndex = 0;
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
        case 'oak-speech':
          if (this.oakSpeech && typeof this.oakSpeech.onAction === 'function') {
            this.oakSpeech.onAction();
          }
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
            this.resetNamingScreen();
            this.setState('name-entry');
          }
          break;
        case 'name-entry':
          this.handleNameEntryAction();
          break;
        case 'field':
          if (this.consumeFieldDialogue()) break;
          if (this.tryInteract()) break;
          this.saveLocalSnapshot();
          this.setFieldDialogue([this.flow.saveMessage || 'LOCAL 저장 완료.']);
          break;
        case 'field-menu':
          this.handleFieldMenuConfirm();
          break;
        case 'battle':
          this.handleBattleAction();
          break;
      }
    }

    handleMenuConfirm() {
      if (this.menuIndex === 0) {
        if (window.FRLG && typeof window.FRLG.OakSpeech === 'function') {
          this.oakSpeech = new window.FRLG.OakSpeech(this);
          this.setState('oak-speech');
        } else {
          this.oakLine = 0;
          this.setState('oak-intro');
        }
        return;
      }
      if (this.menuIndex === 1 && this.continueSnapshot) {
        this.applySnapshot(this.continueSnapshot);
        this.setState('field');
      }
    }

    getFieldMenuItems() {
      const items = [];
      if (this.getFlag('gotPokedex')) items.push({ id: 'pokedex', label: 'POKéDEX' });
      items.push({ id: 'pokemon', label: 'POKéMON' });
      items.push({ id: 'bag', label: 'BAG' });
      items.push({ id: 'player', label: this.profile.playerName || 'PLAYER' });
      items.push({ id: 'save', label: 'SAVE' });
      items.push({ id: 'option', label: 'OPTION' });
      items.push({ id: 'exit', label: 'EXIT' });
      return items;
    }

    openFieldMenu() {
      if (this.state !== 'field') return false;
      if (this.hasFieldDialogue()) return false;
      this.fieldMenuIndex = 0;
      this.setState('field-menu');
      return true;
    }

    closeFieldMenu() {
      if (this.state !== 'field-menu') return false;
      this.setState('field');
      return true;
    }

    handleFieldMenuConfirm() {
      const items = this.getFieldMenuItems();
      const item = items[this.fieldMenuIndex];
      if (!item) return;
      const playerLabel = this.profile.playerName || 'PLAYER';
      const starterLabel = this.progress && this.progress.starter ? this.getStarterLabel(this.progress.starter) : '없음';
      const optionMessages = {
        pokedex: ['도감 skeleton.', '오박사에게 도감을 받은 뒤 실제 목록을 붙이면 된다.'],
        pokemon: [`${playerLabel}의 포켓몬 메뉴 skeleton.`, `현재 스타터: ${starterLabel}`],
        bag: ['가방 skeleton.', '현재는 실제 아이템 목록이 아직 연결되지 않았다.'],
        player: [`${playerLabel}의 프로필 skeleton.`, `성별: ${this.profile.gender === 'M' ? 'BOY' : 'GIRL'} / 스타터: ${starterLabel}`, `위치: ${this.world.mapId} ${this.world.x},${this.world.y}`],
        save: [this.flow.saveMessage || 'LOCAL 저장 완료.'],
        option: ['옵션 skeleton.', '텍스트 속도 / 사운드 / 프레임 등은 나중에 붙이면 된다.'],
        exit: []
      };
      if (item.id === 'save') {
        this.saveLocalSnapshot();
        this.setState('field');
        this.setFieldDialogue(optionMessages.save);
        return;
      }
      if (item.id === 'exit') {
        this.closeFieldMenu();
        return;
      }
      this.setState('field');
      this.setFieldDialogue(optionMessages[item.id] || ['아직 연결되지 않은 메뉴다.']);
    }

    createNameEntryState() {
      return {
        page: 'upper',
        focus: 'grid',
        x: 0,
        y: 0,
        controlIndex: 0
      };
    }

    resetNamingScreen() {
      this.nameEntry = this.createNameEntryState();
    }

    getNameMaxLength() {
      return NAME_ENTRY_MAX_CHARS;
    }

    getCurrentNameRows() {
      return NAME_ENTRY_PAGES[this.nameEntry.page] || NAME_ENTRY_PAGES.upper;
    }

    normalizeNameCursor() {
      const rows = this.getCurrentNameRows();
      const row = rows[this.nameEntry.y] || rows[0] || [];
      this.nameEntry.y = clamp(this.nameEntry.y, 0, rows.length - 1);
      this.nameEntry.x = clamp(this.nameEntry.x, 0, Math.max(0, row.length - 1));
    }

    getNameControlItems() {
      return [
        { kind: 'page', label: NAME_ENTRY_PAGE_BUTTON_LABEL[this.nameEntry.page] || 'abc' },
        { kind: 'del', label: 'DEL' },
        { kind: 'ok', label: 'OK' }
      ];
    }

    cycleNamePage() {
      this.nameEntry.page = NAME_ENTRY_PAGE_NEXT[this.nameEntry.page] || 'upper';
      this.normalizeNameCursor();
    }

    confirmNameEntry() {
      const target = this.nameMode === 'player' ? 'playerName' : 'rivalName';
      const value = this.profile[target].trim();
      if (!value) return;

      if (this.nameMode === 'player') {
        this.nameMode = 'rival';
        this.profile.rivalName = '';
        this.resetNamingScreen();
      } else {
        this.saveLocalSnapshot();
        this.setState('field');
      }
    }

    appendChar(ch) {
      if (this.state !== 'name-entry') return;
      if (typeof ch !== 'string' || ch.length !== 1) return;
      const target = this.nameMode === 'player' ? 'playerName' : 'rivalName';
      if (this.profile[target].length >= this.getNameMaxLength()) return;
      this.profile[target] += ch;
    }

    backspaceName() {
      if (this.state !== 'name-entry') return;
      const target = this.nameMode === 'player' ? 'playerName' : 'rivalName';
      this.profile[target] = this.profile[target].slice(0, -1);
    }

    moveNameSelection(dx, dy) {
      if (this.nameEntry.focus === 'grid') {
        if (dy > 0 && this.nameEntry.y === this.getCurrentNameRows().length - 1) {
          this.nameEntry.focus = 'controls';
          this.nameEntry.controlIndex = clamp(this.nameEntry.controlIndex, 0, 2);
          return;
        }
        this.nameEntry.x += dx;
        this.nameEntry.y += dy;
        this.normalizeNameCursor();
        return;
      }

      if (dy < 0) {
        this.nameEntry.focus = 'grid';
        this.nameEntry.y = this.getCurrentNameRows().length - 1;
        const backMap = [0, 3, 6];
        this.nameEntry.x = backMap[this.nameEntry.controlIndex] || 0;
        this.normalizeNameCursor();
        return;
      }

      this.nameEntry.controlIndex = clamp(this.nameEntry.controlIndex + dx, 0, 2);
    }

    handleNameEntryAction() {
      if (this.nameEntry.focus === 'grid') {
        const rows = this.getCurrentNameRows();
        const row = rows[this.nameEntry.y] || [];
        const ch = row[this.nameEntry.x];
        if (ch !== undefined) this.appendChar(ch);
        return;
      }

      const control = this.getNameControlItems()[this.nameEntry.controlIndex];
      if (!control) return;
      if (control.kind === 'page') {
        this.cycleNamePage();
        return;
      }
      if (control.kind === 'del') {
        this.backspaceName();
        return;
      }
      if (control.kind === 'ok') {
        this.confirmNameEntry();
      }
    }

    moveSelection(dx, dy) {
      switch (this.state) {
        case 'main-menu':
          this.menuIndex = clamp(this.menuIndex + dy, 0, 1);
          break;
        case 'oak-speech':
          if (this.oakSpeech && typeof this.oakSpeech.moveSelection === 'function') {
            this.oakSpeech.moveSelection(dx, dy);
          }
          break;
        case 'gender-select':
          this.genderIndex = clamp(this.genderIndex + (dx || dy), 0, 1);
          break;
        case 'name-entry':
          this.moveNameSelection(dx, dy);
          break;
        case 'field':
          if (this.hasFieldDialogue()) return;
          this.movePlayer(dx, dy);
          break;
        case 'field-menu': {
          const max = Math.max(0, this.getFieldMenuItems().length - 1);
          this.fieldMenuIndex = clamp(this.fieldMenuIndex + dy, 0, max);
          break;
        }
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

    formatDialogueLine(line) {
      const player = this.profile && this.profile.playerName ? this.profile.playerName : 'PLAYER';
      const rival = this.profile && this.profile.rivalName ? this.profile.rivalName : 'RIVAL';
      return String(line || '')
        .replace(/\{PLAYER\}/g, player)
        .replace(/\{RIVAL\}/g, rival)
        .replace(/\{STR_VAR_1\}/g, player)
        .replace(/\{STR_VAR_2\}/g, rival);
    }

    formatDialogueLines(lines) {
      if (!Array.isArray(lines)) return [];
      return lines.map((line) => this.formatDialogueLine(line));
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
        `${rivalLabel}에게 ${playerDamage}의 데미지!`
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
        `${playerLabel}이(가) ${rivalDamage}의 데미지를 받았다!`
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
        '첫 라이벌 배틀이 끝났다.',
        '이제 연구소 안의 다음 흐름을 이어갈 차례다.'
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
      this.fieldDialogue = { lines: this.formatDialogueLines(lines), index: 0 };
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
        return (this.flow.events && this.flow.events.oakStopsYou) || ['오박사: 이리 따라오너라.'];
      }
      if (script === 'PalletTown_EventScript_SignLady') {
        if (!this.getFlag('openedStartMenu')) {
          this.setFlag('openedStartMenu', true);
          this.saveLocalSnapshot();
          return [
            '여자아이: 저 표지판, 한번 읽어 봐!',
            '여자아이: START 버튼을 누르면 메뉴를 열 수 있대.'
          ];
        }
        return (this.flow.npcLines && this.flow.npcLines[script]) || ['여자아이: 표지판은 정말 유용해!'];
      }
      if (script === 'PalletTown_PlayersHouse_1F_EventScript_Mom') {
        if (!this.getFlag('starterChosen')) {
          return (this.flow.npcLines && this.flow.npcLines[script]) || ['엄마: 오박사님이 너를 찾으시는 것 같더라.'];
        }
        if (!this.getFlag('firstBattleDone')) {
          return ['엄마: 포켓몬을 받았구나!', '조심해서 다녀오렴.'];
        }
        return ['엄마: 네 포켓몬, 아주 씩씩해 보이는구나.', '항상 몸조심하렴.'];
      }
      if (script === 'PalletTown_RivalsHouse_EventScript_Daisy') {
        if (!this.getFlag('starterChosen')) {
          return (this.flow.npcLines && this.flow.npcLines[script]) || ['다이: {RIVAL}는 연구소에 있어.'];
        }
        if (!this.getFlag('firstBattleDone')) {
          return ['다이: 연구소에서 무슨 일이 있었던 거야?', '왠지 재미있는 일이 벌어질 것 같네.'];
        }
        return ['다이: {RIVAL}와 배틀했다며?', '나도 그 장면을 봤으면 좋았을 텐데!'];
      }
      if (script === 'PalletTown_RivalsHouse_EventScript_TownMap') {
        return ['칸토 지방의 큰 지도다.', '지금은 보기만 할 수 있다.'];
      }
      if (script === 'PalletTown_ProfessorOaksLab_EventScript_Rival') {
        if (!this.getFlag('starterChosen')) {
          return (this.flow.npcLines && this.flow.npcLines[script]) || ['{RIVAL}: 먼저 골라, {PLAYER}!'];
        }
        if (!this.getFlag('firstBattleDone')) {
          this.queueBattleStart('starter-rival');
          return (this.flow.events && this.flow.events.rivalBattleChallenge) || [
            `${this.profile.rivalName || 'RIVAL'}: 좋아! 바로 한판 해 보자!`
          ];
        }
        return (this.flow.events && this.flow.events.rivalAfterBattle) || ['{RIVAL}: 다음엔 절대 안 져!'];
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

    handleVirtualButton(name) {
      switch (name) {
        case 'reset':
          this.restart();
          return;
        case 'menu':
          if (this.state === 'field') {
            this.openFieldMenu();
            return;
          }
          if (this.state === 'field-menu') {
            this.closeFieldMenu();
          }
          return;
        case 'a':
          this.onAction();
          return;
        case 'b':
          if (this.state === 'oak-speech' && this.oakSpeech && typeof this.oakSpeech.onBackspace === 'function') {
            this.oakSpeech.onBackspace();
            return;
          }
          if (this.state === 'name-entry') {
            this.backspaceName();
            return;
          }
          if (this.state === 'field-menu') {
            this.closeFieldMenu();
          }
          return;
        case 'up':
          this.moveSelection(0, -1);
          return;
        case 'down':
          this.moveSelection(0, 1);
          return;
        case 'left':
          this.moveSelection(-1, 0);
          return;
        case 'right':
          this.moveSelection(1, 0);
          return;
      }
    }

    handleKeyDown(e) {
      if (e.key === 'r' || e.key === 'R') {
        e.preventDefault();
        this.restart();
        return;
      }
      if (e.key === 'Escape' || e.code === 'KeyX') {
        if (this.state === 'field') {
          e.preventDefault();
          this.openFieldMenu();
          return;
        }
        if (this.state === 'field-menu') {
          e.preventDefault();
          this.closeFieldMenu();
          return;
        }
      }
      if (KEY_ENTER.has(e.code)) {
        e.preventDefault();
        this.onAction();
        return;
      }
      if (e.key === 'Backspace') {
        e.preventDefault();
        if (this.state === 'oak-speech' && this.oakSpeech && typeof this.oakSpeech.onBackspace === 'function') {
          this.oakSpeech.onBackspace();
        } else {
          this.backspaceName();
        }
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
      drawOakSpeechBackdrop(this.ctx);

      const oakDrawn = drawOakSpeechCharacter(this.ctx, PACKEGE_OAK_SPEECH_ASSETS.oak, 240, 220, 2);
      if (!oakDrawn) drawOak(this.ctx, 240, 126);

      const showNidoran = this.oakLine >= 2 && this.oakLine <= 3;
      if (showNidoran) {
        drawOakSpeechPlatform(this.ctx, 198, 224, 92, 18);
        const nidoranDrawn = drawOakSpeechCharacter(this.ctx, PACKEGE_OAK_SPEECH_ASSETS.nidoran, 198, 212, 2);
        if (!nidoranDrawn) drawPokemonBuddy(this.ctx, 198, 190);
      }

      this.drawDialogue(this.flow.oakIntro[this.oakLine]);
    }

    drawGenderSelect() {
      const ctx = this.ctx;
      drawOakSpeechBackdrop(ctx);
      this.drawDialogue('Are you a boy? Or are you a girl?');

      drawWindow(ctx, 320, 146, 104, 60, false, '#102030', '#365d79', 'std');
      drawMenuArrow(ctx, 334, this.genderIndex === 0 ? 164 : 184, '#29343d');

      ctx.save();
      ctx.fillStyle = '#2d3942';
      ctx.font = '16px Arial';
      ctx.fillText('BOY', 352, 168);
      ctx.fillText('GIRL', 352, 188);
      ctx.restore();
    }

    drawPostGender() {
      const ctx = this.ctx;
      drawOakSpeechBackdrop(ctx);

      const trainerSprite = this.profile.gender === 'F' ? PACKEGE_OAK_SPEECH_ASSETS.leaf : PACKEGE_OAK_SPEECH_ASSETS.red;
      const trainerDrawn = drawOakSpeechCharacter(ctx, trainerSprite, 240, 220, 2);
      if (!trainerDrawn) {
        drawTrainerBust(ctx, 240, 138, PLAYER_COLORS[this.profile.gender], 1.1);
      }

      this.drawDialogue(this.flow.postGender[this.postGenderLine]);
    }

    drawNameEntry(now) {
      this.drawBg('#ebeff4', '#9fb6c6');
      const ctx = this.ctx;
      const targetLabel = this.nameMode === 'player' ? '플레이어 이름' : '라이벌 이름';
      const targetValue = this.nameMode === 'player' ? this.profile.playerName : this.profile.rivalName;
      const maxChars = this.getNameMaxLength();
      const chars = targetValue.split('');
      const rows = this.getCurrentNameRows();
      const controls = this.getNameControlItems();
      const pageKey = this.nameEntry.page === 'upper'
        ? 'pageSwapUpper'
        : this.nameEntry.page === 'lower'
          ? 'pageSwapLower'
          : 'pageSwapOthers';

      centerText(ctx, targetLabel, 240, 30, 20, 1, '#1b2732');
      centerText(ctx, `Packege naming_screen 자산 1단계 · ${NAME_ENTRY_PAGE_TITLE[this.nameEntry.page] || 'ABC'} 페이지 · 방향키/Enter/Backspace`, 240, 50, 11, 0.9, '#334957');

      drawWindow(ctx, 68, 60, 344, 56, false, '#f8fff8', '#6f86a0', 'std');
      drawUiBanner(ctx, PACKEGE_UI_ASSETS.namingMenu, 76, 67, 128, 24);
      centerText(ctx, targetLabel, 288, 83, 14, 1, '#22384f');
      for (let i = 0; i < maxChars; i++) {
        const x = 96 + i * 40;
        const filled = chars[i] !== undefined;
        drawUiGlyph(ctx, PACKEGE_UI_ASSETS.underscore, x + 11, 94, 8, 8);
        if (filled) {
          centerText(ctx, displayNameChar(chars[i]), x + 15, 89, 16, 1, '#18324a');
        }
      }
      const nextIndex = Math.min(targetValue.length, maxChars - 1);
      drawUiGlyph(ctx, PACKEGE_UI_ASSETS.inputArrow, 96 + nextIndex * 40 + 11, 72, 8, 8);

      const keyLeft = 46;
      const keyTop = 124;
      const cellW = 48;
      const cellH = 24;
      drawWindow(ctx, 28, 114, 424, 128, false, '#f8fff8', '#6f86a0', 'std');
      rows.forEach((row, rowIndex) => {
        row.forEach((ch, colIndex) => {
          const x = keyLeft + colIndex * cellW;
          const y = keyTop + rowIndex * 26;
          const active = this.nameEntry.focus === 'grid' && this.nameEntry.y === rowIndex && this.nameEntry.x === colIndex;
          drawUiCell(ctx, x, y, cellW - 6, cellH, active);
          centerText(ctx, displayNameChar(ch), x + (cellW - 6) / 2, y + 16, 15, ch === ' ' ? 0.9 : 1, '#18324a');
        });
      });

      const ctrlTop = 224;
      controls.forEach((control, idx) => {
        const x = 72 + idx * 114;
        const active = this.nameEntry.focus === 'controls' && this.nameEntry.controlIndex === idx;
        drawWindow(ctx, x, ctrlTop, 96, 24, false, '#f8fff8', '#6f86a0', 'std');
        if (control.action === 'page') {
          drawUiBanner(ctx, PACKEGE_UI_ASSETS[pageKey], x + 28, ctrlTop + 8, 40, 8);
        } else if (control.action === 'ok') {
          drawUiBanner(ctx, PACKEGE_UI_ASSETS.namingOkButton, x + 28, ctrlTop, 40, 24);
        } else if (control.action === 'del') {
          drawUiBanner(ctx, PACKEGE_UI_ASSETS.namingBackButton, x + 28, ctrlTop, 40, 24);
          centerText(ctx, 'DEL', x + 49, ctrlTop + 16, 9, 1, '#18324a');
        } else {
          centerText(ctx, control.label, x + 48, ctrlTop + 16, 14, 1, '#18324a');
        }
        if (active) {
          drawUiGlyph(ctx, PACKEGE_UI_ASSETS.namingCursor, x + 6, ctrlTop, 16, 24);
        }
      });

      centerText(ctx, `현재 길이 ${targetValue.length}/${maxChars}`, 392, 92, 11, 0.92, '#35526a');
      this.drawDialogue(this.nameMode === 'player' ? '너의 이름을 문자판으로 조합해라.' : '이제 라이벌의 이름을 문자판으로 조합해라.');
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

    drawFieldMenu() {
      this.drawField();
      const ctx = this.ctx;
      ctx.save();
      ctx.fillStyle = 'rgba(0, 0, 0, 0.14)';
      ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
      ctx.restore();

      const items = this.getFieldMenuItems();
      const selected = items[this.fieldMenuIndex];
      const starterLabel = this.progress && this.progress.starter ? this.getStarterLabel(this.progress.starter) : '없음';
      const cursorPulse = Math.sin(performance.now() / 160) * 2;

      const infoX = 28;
      const infoY = 28;
      drawWindow(ctx, infoX, infoY, 186, 94, false, '#f8fff8', '#2f5676', 'std');
      ctx.fillStyle = '#d7edf9';
      ctx.fillRect(infoX + 6, infoY + 6, 174, 18);
      ctx.fillStyle = '#1c3850';
      ctx.font = 'bold 12px Arial';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      ctx.fillText(this.profile.playerName || 'PLAYER', infoX + 14, infoY + 16);
      ctx.font = '12px Arial';
      ctx.fillStyle = '#284156';
      ctx.fillText(`MAP  ${this.world.mapId}`, infoX + 14, infoY + 40);
      ctx.fillText(`POS  ${this.world.x}, ${this.world.y}`, infoX + 14, infoY + 58);
      ctx.fillText(`STARTER  ${starterLabel}`, infoX + 14, infoY + 76);

      const panelX = 324;
      const panelY = 24;
      const itemH = 20;
      const panelH = 26 + items.length * itemH + 12;
      drawWindow(ctx, panelX, panelY, 128, panelH, false, '#f8fff8', '#2f5676', 'std');
      ctx.fillStyle = '#d7edf9';
      ctx.fillRect(panelX + 6, panelY + 6, 116, 16);
      ctx.fillStyle = '#1c3850';
      ctx.font = 'bold 12px Arial';
      ctx.textAlign = 'center';
      ctx.fillText('MENU', panelX + 64, panelY + 18);

      items.forEach((item, idx) => {
        const baseY = panelY + 38 + idx * itemH;
        const active = idx === this.fieldMenuIndex;
        if (active) {
          ctx.fillStyle = '#dcebf6';
          ctx.fillRect(panelX + 18, baseY - 12, 98, 16);
          drawMenuArrow(ctx, panelX + 12 + cursorPulse, baseY - 4, '#29455b');
        }
        ctx.font = '13px Arial';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = active ? '#12293c' : '#21384d';
        ctx.fillText(item.label, panelX + 28, baseY - 3);
      });

      drawWindow(ctx, 24, 246, 432, 60, false, '#102030', '#365d79', 'std');
      const footerLine = selected ? `${selected.label} 을(를) 선택할 수 있다.` : '메뉴';
      wrapText(ctx, footerLine, 44, 266, 392, 18, '#ffffff', 14);
      wrapText(ctx, 'ESC / X 메뉴 열기·닫기 · 방향키 선택 · Enter 확인', 44, 286, 392, 18, '#d9efff', 12);
    }

    drawIndoorMap(map) {
      const ctx = this.ctx;
      this.drawBg('#7aa6cc', '#29445e');
      const tile = fitTileSize(map.width, map.height, 300, 196, 16, 24);
      const origin = centeredOrigin(this.canvas.width, this.canvas.height, map.width, map.height, tile, 32);
      drawWindow(ctx, origin.x - 12, origin.y - 12, map.width * tile + 24, map.height * tile + 24, false, '#a27551', '#623f26', 'std');
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
        drawBgEventSprite(ctx, origin, tile, event);
      });
      (map.warpEvents || []).forEach((event) => {
        drawMarker(ctx, origin, tile, event.x, event.y, '#6fd7ff', 'W');
      });
      (map.objectEvents || []).forEach((event) => {
        if (this.isObjectHidden(event)) return;
        const isInteract = this.isFacingTile(event.x, event.y);
        drawObjectEventSprite(ctx, origin, tile, event, isInteract);
      });
      drawPlayerSprite(ctx, origin.x + this.world.x * tile, origin.y + this.world.y * tile, this.profile.gender, this.world.dir, tile);
    }

    drawDialogue(text) {
      drawWindow(this.ctx, 24, 246, 432, 60, false, '#102030', '#365d79', 'std');
      wrapText(this.ctx, text, 44, 270, 392, 20, '#2e3a44', 14);
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
      } else if (this.state === 'oak-speech') {
        if (this.oakSpeech && typeof this.oakSpeech.draw === 'function') {
          this.oakSpeech.draw(now);
        }
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
      } else if (this.state === 'field-menu') {
        this.drawFieldMenu();
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


  function createPackegeFieldAssets() {
    const load = (path) => {
      const img = new Image();
      img.src = path;
      return img;
    };
    return {
      playerMale: load(`${PACKEGE_ASSET_BASE}/people/red_normal.png`),
      playerFemale: load(`${PACKEGE_ASSET_BASE}/people/green_normal.png`),
      rivalBlue: load(`${PACKEGE_ASSET_BASE}/people/blue.png`),
      profOak: load(`${PACKEGE_ASSET_BASE}/people/prof_oak.png`),
      mom: load(`${PACKEGE_ASSET_BASE}/people/mom.png`),
      daisy: load(`${PACKEGE_ASSET_BASE}/people/daisy.png`),
      fatMan: load(`${PACKEGE_ASSET_BASE}/people/fat_man.png`),
      littleGirl: load(`${PACKEGE_ASSET_BASE}/people/little_girl.png`),
      scientist: load(`${PACKEGE_ASSET_BASE}/people/scientist.png`),
      itemBall: load(`${PACKEGE_ASSET_BASE}/misc/item_ball.png`),
      pokedex: load(`${PACKEGE_ASSET_BASE}/misc/pokedex.png`),
      townMap: load(`${PACKEGE_ASSET_BASE}/misc/town_map.png`),
      sign: load(`${PACKEGE_ASSET_BASE}/misc/wooden_sign.png`)
    };
  }


  function createPackegeUiAssets() {
    const load = (path) => {
      const img = new Image();
      img.src = path;
      return img;
    };
    return {
      windowStd: load(`${PACKEGE_TEXT_WINDOW_BASE}/std.png`),
      windowMenuMessage: load(`${PACKEGE_TEXT_WINDOW_BASE}/menu_message.png`),
      redArrow: load(`${PACKEGE_INTERFACE_BASE}/red_arrow.png`),
      namingCursor: load(`${PACKEGE_NAMING_BASE}/cursor.png`),
      namingCursorFilled: load(`${PACKEGE_NAMING_BASE}/cursor_filled.png`),
      namingMenu: load(`${PACKEGE_NAMING_BASE}/menu.png`),
      namingBackButton: load(`${PACKEGE_NAMING_BASE}/back_button.png`),
      namingOkButton: load(`${PACKEGE_NAMING_BASE}/ok_button.png`),
      pageSwapUpper: load(`${PACKEGE_NAMING_BASE}/page_swap_upper.png`),
      pageSwapLower: load(`${PACKEGE_NAMING_BASE}/page_swap_lower.png`),
      pageSwapOthers: load(`${PACKEGE_NAMING_BASE}/page_swap_others.png`),
      inputArrow: load(`${PACKEGE_NAMING_BASE}/input_arrow.png`),
      underscore: load(`${PACKEGE_NAMING_BASE}/underscore.png`)
    };
  }

  function createPackegeOakSpeechAssets() {
    const load = (path) => {
      const img = new Image();
      img.src = path;
      return img;
    };
    return {
      bg: load(`${PACKEGE_OAK_SPEECH_BASE}/oak_speech_bg.png`),
      platform: load(`${PACKEGE_OAK_SPEECH_BASE}/platform.png`),
      oak: load(`${PACKEGE_OAK_SPEECH_BASE}/oak/pic.png`),
      red: load(`${PACKEGE_OAK_SPEECH_BASE}/red/pic.png`),
      leaf: load(`${PACKEGE_OAK_SPEECH_BASE}/leaf/pic.png`),
      rival: load(`${PACKEGE_OAK_SPEECH_BASE}/rival/pic.png`)
    };
  }

  function drawPackegeImage(ctx, img, sx, sy, sw, sh, dx, dy, dw, dh, flipX = false) {
    if (!img || !img.complete || !img.naturalWidth) return false;
    ctx.save();
    ctx.imageSmoothingEnabled = false;
    if (flipX) {
      ctx.translate(dx + dw, dy);
      ctx.scale(-1, 1);
      ctx.drawImage(img, sx, sy, sw, sh, 0, 0, dw, dh);
    } else {
      ctx.drawImage(img, sx, sy, sw, sh, dx, dy, dw, dh);
    }
    ctx.restore();
    return true;
  }

  function spriteFrameMeta(img) {
    if (!img || !img.naturalWidth || !img.naturalHeight) return null;
    const frameH = img.naturalHeight;
    const frameW = 16;
    const frames = Math.max(1, Math.floor(img.naturalWidth / frameW));
    return { frameW, frameH, frames };
  }

  function getOverworldFrameIndex(dir, frames) {
    if (frames >= 9) {
      if (dir === 'down') return 1;
      if (dir === 'up') return 4;
      return 7;
    }
    if (frames >= 3) {
      if (dir === 'down') return 1;
      return 2;
    }
    return 0;
  }

  function pickPackegeSprite(event) {
    const gid = String(event?.graphics_id || '');
    const lid = String(event?.local_id || '');
    if (gid.includes('PROF_OAK')) return PACKEGE_FIELD_ASSETS.profOak;
    if (gid.includes('BLUE')) return PACKEGE_FIELD_ASSETS.rivalBlue;
    if (gid.includes('SCIENTIST')) return PACKEGE_FIELD_ASSETS.scientist;
    if (gid.includes('WORKER_F')) return PACKEGE_FIELD_ASSETS.scientist;
    if (gid.includes('ITEM_BALL')) return PACKEGE_FIELD_ASSETS.itemBall;
    if (gid.includes('POKEDEX')) return PACKEGE_FIELD_ASSETS.pokedex;
    if (gid.includes('TOWN_MAP')) return PACKEGE_FIELD_ASSETS.townMap;
    if (lid.includes('MOM')) return PACKEGE_FIELD_ASSETS.mom;
    if (lid.includes('DAISY')) return PACKEGE_FIELD_ASSETS.daisy;
    if (lid.includes('SIGN_LADY')) return PACKEGE_FIELD_ASSETS.littleGirl;
    if (lid.includes('FAT_MAN')) return PACKEGE_FIELD_ASSETS.fatMan;
    return null;
  }

  function drawObjectEventSprite(ctx, origin, tile, event, active = false) {
    const img = pickPackegeSprite(event);
    const px = origin.x + event.x * tile;
    const py = origin.y + event.y * tile;
    let drawn = false;

    if (img && img.complete && img.naturalWidth) {
      const meta = spriteFrameMeta(img);
      if (meta) {
        const isSmallObject = meta.frameH <= 16;
        const frameIdx = Math.min(getOverworldFrameIndex('down', meta.frames), meta.frames - 1);
        const sx = frameIdx * meta.frameW;
        const sy = 0;
        const drawW = tile * (isSmallObject ? 0.92 : 1.05);
        const drawH = tile * (isSmallObject ? 0.92 : 1.75);
        const dx = px + (tile - drawW) / 2;
        const dy = py + tile - drawH;
        drawn = drawPackegeImage(ctx, img, sx, sy, meta.frameW, meta.frameH, dx, dy, drawW, drawH);
      }
    }

    if (!drawn) {
      drawNpc(ctx, origin, tile, event.x, event.y, objectColor(event), active);
      return;
    }

    if (active) {
      ctx.save();
      ctx.strokeStyle = '#fff6a2';
      ctx.lineWidth = Math.max(2, tile * 0.10);
      ctx.strokeRect(px + tile * 0.10, py - tile * 0.40, tile * 0.80, tile * 1.40);
      ctx.restore();
    }
  }

  function drawBgEventSprite(ctx, origin, tile, event) {
    const px = origin.x + event.x * tile;
    const py = origin.y + event.y * tile;
    const img = PACKEGE_FIELD_ASSETS.sign;
    if (img && img.complete && img.naturalWidth) {
      const size = tile * 0.92;
      drawPackegeImage(ctx, img, 0, 0, img.naturalWidth, img.naturalHeight, px + (tile - size) / 2, py + tile - size, size, size);
      return;
    }
    drawMarker(ctx, origin, tile, event.x, event.y, '#ffe46f', '?');
  }

  function drawPlayerSprite(ctx, x, y, gender, dir, tile = 24) {
    const img = gender === 'F' ? PACKEGE_FIELD_ASSETS.playerFemale : PACKEGE_FIELD_ASSETS.playerMale;
    const meta = spriteFrameMeta(img);
    if (meta && img.complete && img.naturalWidth) {
      const frameIdx = Math.min(getOverworldFrameIndex(dir, meta.frames), meta.frames - 1);
      const sx = frameIdx * meta.frameW;
      const sy = 0;
      const drawW = tile * 1.05;
      const drawH = tile * 1.75;
      const dx = x + (tile - drawW) / 2;
      const dy = y + tile - drawH;
      const flipX = dir === 'left';
      drawPackegeImage(ctx, img, sx, sy, meta.frameW, meta.frameH, dx, dy, drawW, drawH, flipX);
      return;
    }
    drawTrainerMini(ctx, x, y, PLAYER_COLORS[gender], dir, tile);
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

  function drawWindow(ctx, x, y, w, h, selected = false, fill = '#0e2132', line = '#4ca7db', skin = 'plain') {
    ctx.save();

    const outer = skin === 'std' ? '#274764' : line;
    const innerFill = skin === 'std' ? '#f8fff8' : fill;
    const shadow = skin === 'std' ? 'rgba(9, 20, 31, 0.45)' : 'rgba(0, 0, 0, 0.18)';

    ctx.fillStyle = shadow;
    ctx.fillRect(x + 3, y + 3, w, h);

    ctx.fillStyle = outer;
    ctx.fillRect(x, y, w, h);

    ctx.fillStyle = innerFill;
    ctx.fillRect(x + 3, y + 3, Math.max(0, w - 6), Math.max(0, h - 6));

    ctx.strokeStyle = selected ? '#ffffff' : outer;
    ctx.lineWidth = selected ? 3 : 2;
    ctx.strokeRect(x + 1, y + 1, w - 2, h - 2);

    if (skin === 'std' && h >= 20) {
      ctx.fillStyle = 'rgba(255, 255, 255, 0.55)';
      ctx.fillRect(x + 5, y + 5, Math.max(0, w - 10), 2);
    }

    ctx.restore();
  }

  function drawMenuArrow(ctx, x, y, color = '#ffffff') {
    if (drawUiGlyph(ctx, PACKEGE_UI_ASSETS.redArrow, x - 2, y - 3, 16, 16)) {
      return;
    }
    ctx.save();
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x + 8, y + 5);
    ctx.lineTo(x, y + 10);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
  }

  function tileUiTexture(ctx, img, x, y, w, h) {
    if (!img || !img.complete || !img.naturalWidth) {
      ctx.fillStyle = '#f8fff8';
      ctx.fillRect(x, y, w, h);
      return false;
    }
    ctx.save();
    ctx.imageSmoothingEnabled = false;
    for (let py = y; py < y + h; py += img.naturalHeight) {
      for (let px = x; px < x + w; px += img.naturalWidth) {
        const dw = Math.min(img.naturalWidth, x + w - px);
        const dh = Math.min(img.naturalHeight, y + h - py);
        ctx.drawImage(img, 0, 0, dw, dh, px, py, dw, dh);
      }
    }
    ctx.restore();
    return true;
  }

  function drawUiBanner(ctx, img, x, y, w, h) {
    if (!img || !img.complete || !img.naturalWidth) return false;
    ctx.save();
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(img, 0, 0, img.naturalWidth, img.naturalHeight, x, y, w, h);
    ctx.restore();
    return true;
  }

  function drawUiGlyph(ctx, img, x, y, w, h) {
    if (!img || !img.complete || !img.naturalWidth) return false;
    ctx.save();
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(img, 0, 0, img.naturalWidth, img.naturalHeight, x, y, w, h);
    ctx.restore();
    return true;
  }

  function drawUiCell(ctx, x, y, w, h, active = false) {
    ctx.save();
    ctx.fillStyle = active ? '#e2eff9' : '#f8fff8';
    ctx.fillRect(x, y, w, h);
    ctx.strokeStyle = '#6f86a0';
    ctx.lineWidth = 1;
    ctx.strokeRect(x + 0.5, y + 0.5, w - 1, h - 1);
    ctx.restore();
    const glyph = active ? PACKEGE_UI_ASSETS.namingCursorFilled : PACKEGE_UI_ASSETS.namingCursor;
    drawUiGlyph(ctx, glyph, x + 2, y, 16, 24);
  }

  function drawOakSpeechBackdrop(ctx) {
    const bg = PACKEGE_OAK_SPEECH_ASSETS.bg;
    if (bg && bg.complete && bg.naturalWidth) {
      ctx.save();
      ctx.imageSmoothingEnabled = false;
      for (let y = 0; y < 246; y += bg.naturalHeight) {
        for (let x = 0; x < 480; x += bg.naturalWidth) {
          ctx.drawImage(bg, x, y);
        }
      }
      ctx.restore();
    } else {
      const gradient = ctx.createLinearGradient(0, 0, 0, 246);
      gradient.addColorStop(0, '#edf5fa');
      gradient.addColorStop(1, '#c8dceb');
      ctx.fillStyle = gradient;
      ctx.fillRect(0, 0, 480, 246);
    }
    ctx.fillStyle = '#d8e6ef';
    ctx.fillRect(0, 210, 480, 36);
  }

  function drawOakSpeechPlatform(ctx, cx, baseY, width = 128, height = 24) {
    const img = PACKEGE_OAK_SPEECH_ASSETS.platform;
    const x = Math.round(cx - width / 2);
    const y = Math.round(baseY - height / 2);
    if (img && img.complete && img.naturalWidth) {
      ctx.save();
      ctx.imageSmoothingEnabled = false;
      ctx.drawImage(img, x, y, width, height);
      ctx.restore();
      return;
    }
    ctx.save();
    ctx.fillStyle = '#b9ded7';
    ctx.beginPath();
    ctx.ellipse(cx, baseY, width / 2, height / 2, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }

  function drawOakSpeechCharacter(ctx, img, cx, footY, scale = 2, alpha = 1) {
    if (!img || !img.complete || !img.naturalWidth) return false;
    const w = Math.round(img.naturalWidth * scale);
    const h = Math.round(img.naturalHeight * scale);
    ctx.save();
    ctx.globalAlpha = alpha;
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(img, Math.round(cx - w / 2), Math.round(footY - h), w, h);
    ctx.restore();
    return true;
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

  function displayNameChar(ch) {
    if (ch === ' ') return '␣';
    return ch;
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

  window.FRLG.Shared = {
    centerText,
    wrapText,
    clamp,
    drawWindow,
    drawMenuArrow,
    drawUiBanner,
    drawUiGlyph,
    drawUiCell,
    drawOakSpeechBackdrop,
    drawOakSpeechPlatform,
    drawOakSpeechCharacter,
    drawOak,
    drawPokemonBuddy,
    drawTrainerBust,
    displayNameChar,
    PACKEGE_UI_ASSETS,
    PACKEGE_OAK_SPEECH_ASSETS,
    PLAYER_COLORS,
    NAME_ENTRY_MAX_CHARS,
    NAME_ENTRY_PAGE_NEXT,
    NAME_ENTRY_PAGE_BUTTON_LABEL,
    NAME_ENTRY_PAGE_TITLE,
    NAME_ENTRY_PAGES
  };

  window.FRLG.IntroEngine = IntroEngine;
})();
