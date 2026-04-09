window.FRLG = window.FRLG || {};

(function() {
  function getShared() {
    return window.FRLG.Shared || {};
  }

  class OakSpeech {
    constructor(engine) {
      this.engine = engine;
      this.genderIndex = engine.profile.gender === 'F' ? 1 : 0;
      this.confirmIndex = 0;
      this.nameMode = 'player';
      this.nameEntry = this.createNameEntryState();
      this.phase = 'welcome';
      this.phaseStartedAt = performance.now();
      this.syncSceneLabel();
    }

    setPhase(nextPhase) {
      this.phase = nextPhase;
      this.phaseStartedAt = performance.now();
      this.confirmIndex = 0;
      if (nextPhase === 'playerNaming') {
        this.nameMode = 'player';
        this.nameEntry = this.createNameEntryState();
      } else if (nextPhase === 'rivalNaming') {
        this.nameMode = 'rival';
        this.nameEntry = this.createNameEntryState();
      }
      this.syncSceneLabel();
    }

    syncSceneLabel() {
      if (this.engine && this.engine.hooks && typeof this.engine.hooks.onSceneChange === 'function') {
        this.engine.hooks.onSceneChange(`oak:${this.phase}`);
      }
    }

    onAction() {
      switch (this.phase) {
        case 'welcome':
          this.setPhase('thisWorld');
          return;
        case 'thisWorld':
          this.setPhase('inhabited');
          return;
        case 'inhabited':
          this.setPhase('study');
          return;
        case 'study':
          this.setPhase('tellMe');
          return;
        case 'tellMe':
          this.setPhase('gender');
          return;
        case 'gender':
          this.engine.profile.gender = this.genderIndex === 0 ? 'M' : 'F';
          this.setPhase('playerPrompt');
          return;
        case 'playerPrompt':
          this.setPhase('playerNaming');
          return;
        case 'playerNaming':
          this.handleNamingAction();
          return;
        case 'playerConfirm':
          if (this.confirmIndex === 0) {
            this.setPhase('rivalPrompt');
          } else {
            this.setPhase('playerNaming');
          }
          return;
        case 'rivalPrompt':
          this.setPhase('rivalNaming');
          return;
        case 'rivalNaming':
          this.handleNamingAction();
          return;
        case 'rivalConfirm':
          if (this.confirmIndex === 0) {
            this.setPhase('letsGo');
          } else {
            this.setPhase('rivalNaming');
          }
          return;
        case 'letsGo':
          this.setPhase('exit');
          return;
      }
    }

    onBackspace() {
      if (this.phase === 'playerNaming' || this.phase === 'rivalNaming') {
        this.backspaceName();
      }
    }

    moveSelection(dx, dy) {
      switch (this.phase) {
        case 'gender':
          this.genderIndex = this.clamp(this.genderIndex + (dx || dy), 0, 1);
          return;
        case 'playerNaming':
        case 'rivalNaming':
          this.moveNameSelection(dx, dy);
          return;
        case 'playerConfirm':
        case 'rivalConfirm':
          this.confirmIndex = this.clamp(this.confirmIndex + (dx || dy), 0, 1);
          return;
      }
    }

    draw(now) {
      const ctx = this.engine.ctx;
      const elapsed = now - this.phaseStartedAt;
      const kind = this.getPhaseKind();

      if (kind === 'naming') {
        this.drawNaming(ctx);
        return;
      }

      this.drawBackdrop(ctx);

      if (kind === 'oak') {
        this.drawOak(ctx, elapsed);
      } else if (kind === 'gender') {
        this.drawGender(ctx);
      } else if (kind === 'player') {
        this.drawPlayer(ctx, this.currentPlayerSprite(), 1);
      } else if (kind === 'rival') {
        this.drawPlayer(ctx, (getShared().PACKEGE_OAK_SPEECH_ASSETS || {}).rival, 1);
      } else if (kind === 'exit') {
        this.drawExit(ctx, elapsed);
      }

      this.drawDialogue(ctx, this.getDialogue());

      if (this.phase === 'gender') {
        this.drawGenderMenu(ctx);
      } else if (this.phase === 'playerConfirm' || this.phase === 'rivalConfirm') {
        this.drawConfirmMenu(ctx);
      }
    }

    drawBackdrop(ctx) {
      const shared = getShared();
      if (typeof shared.drawOakSpeechBackdrop === 'function') {
        shared.drawOakSpeechBackdrop(ctx);
      } else {
        ctx.fillStyle = '#dfeaf2';
        ctx.fillRect(0, 0, 480, 246);
      }
    }

    drawOak(ctx, elapsed) {
      const shared = getShared();
      const assets = shared.PACKEGE_OAK_SPEECH_ASSETS || {};
      if (typeof shared.drawOakSpeechPlatform === 'function') {
        shared.drawOakSpeechPlatform(ctx, 240, 226, 132, 26);
      }
      const oakDrawn = typeof shared.drawOakSpeechCharacter === 'function'
        ? shared.drawOakSpeechCharacter(ctx, assets.oak, 240, 220, 2)
        : false;
      if (!oakDrawn && typeof shared.drawOak === 'function') {
        shared.drawOak(ctx, 240, 126);
      }

      if (this.phase === 'inhabited' || this.phase === 'study') {
        const bounce = Math.sin(elapsed / 120) * 3;
        if (typeof shared.drawOakSpeechPlatform === 'function') {
          shared.drawOakSpeechPlatform(ctx, 194, 224, 92, 18);
        }
        const nidoranDrawn = typeof shared.drawOakSpeechCharacter === 'function'
          ? shared.drawOakSpeechCharacter(ctx, assets.nidoran, 194, 212 + bounce, 2)
          : false;
        if (!nidoranDrawn && typeof shared.drawPokemonBuddy === 'function') {
          shared.drawPokemonBuddy(ctx, 194, 190 + bounce);
        }
      }
    }

    drawGender(ctx) {
      const shared = getShared();
      const sprite = this.genderIndex === 1
        ? (shared.PACKEGE_OAK_SPEECH_ASSETS || {}).leaf
        : (shared.PACKEGE_OAK_SPEECH_ASSETS || {}).red;
      this.drawPlayer(ctx, sprite, 2);
    }

    drawPlayer(ctx, sprite, scale = 1) {
      const shared = getShared();
      if (typeof shared.drawOakSpeechPlatform === 'function') {
        shared.drawOakSpeechPlatform(ctx, 240, 226, 132, 26);
      }
      const drawn = typeof shared.drawOakSpeechCharacter === 'function'
        ? shared.drawOakSpeechCharacter(ctx, sprite, 240, 220, scale)
        : false;
      if (!drawn && typeof shared.drawTrainerBust === 'function') {
        const color = this.engine.profile.gender === 'F'
          ? (shared.PLAYER_COLORS || {}).F
          : (shared.PLAYER_COLORS || {}).M;
        shared.drawTrainerBust(ctx, 240, 138, color || '#ee4e4e', 1.1);
      }
    }

    drawExit(ctx, elapsed) {
      const shared = getShared();
      const progress = Math.min(1, elapsed / 900);
      const scale = 2 - progress * 1.35;
      const alpha = Math.max(0, 1 - progress * 1.15);
      const platformAlpha = Math.max(0, 1 - progress * 1.4);

      ctx.save();
      ctx.globalAlpha = platformAlpha;
      if (typeof shared.drawOakSpeechPlatform === 'function') {
        shared.drawOakSpeechPlatform(ctx, 240, 226, 132, 26);
      }
      ctx.restore();

      const sprite = this.currentPlayerSprite();
      if (typeof shared.drawOakSpeechCharacter === 'function') {
        shared.drawOakSpeechCharacter(ctx, sprite, 240, 220 - progress * 24, Math.max(0.6, scale), alpha);
      }

      if (progress >= 1) {
        this.finish();
      }
    }

    drawDialogue(ctx, text) {
      const shared = getShared();
      if (typeof shared.drawWindow === 'function') {
        shared.drawWindow(ctx, 24, 246, 432, 60, false, '#102030', '#365d79', 'std');
      } else {
        ctx.fillStyle = '#102030';
        ctx.fillRect(24, 246, 432, 60);
      }
      if (typeof shared.wrapText === 'function') {
        shared.wrapText(ctx, text, 44, 270, 392, 18, '#2e3a44', 14);
      } else {
        ctx.fillStyle = '#2e3a44';
        ctx.font = '14px Arial';
        ctx.fillText(text, 44, 270);
      }
    }

    drawGenderMenu(ctx) {
      const shared = getShared();
      shared.drawWindow(ctx, 320, 146, 104, 60, false, '#102030', '#365d79', 'std');
      shared.drawMenuArrow(ctx, 334, this.genderIndex === 0 ? 164 : 184, '#29343d');

      ctx.save();
      ctx.fillStyle = '#2d3942';
      ctx.font = '16px Arial';
      ctx.fillText('BOY', 352, 168);
      ctx.fillText('GIRL', 352, 188);
      ctx.restore();
    }

    drawConfirmMenu(ctx) {
      const shared = getShared();
      shared.drawWindow(ctx, 334, 148, 88, 56, false, '#102030', '#365d79', 'std');
      shared.drawMenuArrow(ctx, 346, this.confirmIndex === 0 ? 166 : 186, '#29343d');

      ctx.save();
      ctx.fillStyle = '#2d3942';
      ctx.font = '15px Arial';
      ctx.fillText('YES', 364, 170);
      ctx.fillText('NO', 368, 190);
      ctx.restore();
    }

    drawNaming(ctx) {
      const shared = getShared();
      const targetLabel = this.nameMode === 'player' ? '플레이어 이름' : '라이벌 이름';
      const targetValue = this.currentName();
      const maxChars = shared.NAME_ENTRY_MAX_CHARS || 7;
      const chars = targetValue.split('');
      const rows = this.getCurrentNameRows();
      const controls = this.getNameControlItems();
      const pageKey = this.nameEntry.page === 'upper'
        ? 'pageSwapUpper'
        : this.nameEntry.page === 'lower'
          ? 'pageSwapLower'
          : 'pageSwapOthers';

      ctx.save();
      const g = ctx.createLinearGradient(0, 0, 0, 320);
      g.addColorStop(0, '#ebeff4');
      g.addColorStop(1, '#9fb6c6');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, 480, 320);
      ctx.restore();

      shared.centerText(ctx, targetLabel, 240, 30, 20, 1, '#1b2732');
      shared.centerText(ctx, `${this.nameMode === 'player' ? '네 이름을 정해라.' : '라이벌 이름을 정해라.'} · ${shared.NAME_ENTRY_PAGE_TITLE[this.nameEntry.page] || 'ABC'}`, 240, 50, 11, 0.9, '#334957');

      shared.drawWindow(ctx, 68, 60, 344, 56, false, '#f8fff8', '#6f86a0', 'std');
      shared.drawUiBanner(ctx, shared.PACKEGE_UI_ASSETS.namingMenu, 76, 67, 128, 24);
      shared.centerText(ctx, targetLabel, 288, 83, 14, 1, '#22384f');

      for (let i = 0; i < maxChars; i++) {
        const x = 96 + i * 40;
        const filled = chars[i] !== undefined;
        shared.drawUiGlyph(ctx, shared.PACKEGE_UI_ASSETS.underscore, x + 11, 94, 8, 8);
        if (filled) {
          shared.centerText(ctx, shared.displayNameChar(chars[i]), x + 15, 89, 16, 1, '#18324a');
        }
      }

      const nextIndex = Math.min(targetValue.length, maxChars - 1);
      shared.drawUiGlyph(ctx, shared.PACKEGE_UI_ASSETS.inputArrow, 96 + nextIndex * 40 + 11, 72, 8, 8);

      const keyLeft = 46;
      const keyTop = 124;
      const cellW = 48;
      const cellH = 24;
      shared.drawWindow(ctx, 28, 114, 424, 128, false, '#f8fff8', '#6f86a0', 'std');
      rows.forEach((row, rowIndex) => {
        row.forEach((ch, colIndex) => {
          const x = keyLeft + colIndex * cellW;
          const y = keyTop + rowIndex * 26;
          const active = this.nameEntry.focus === 'grid' && this.nameEntry.y === rowIndex && this.nameEntry.x === colIndex;
          shared.drawUiCell(ctx, x, y, cellW - 6, cellH, active);
          shared.centerText(ctx, shared.displayNameChar(ch), x + (cellW - 6) / 2, y + 16, 15, ch === ' ' ? 0.9 : 1, '#18324a');
        });
      });

      const ctrlTop = 224;
      controls.forEach((control, idx) => {
        const x = 72 + idx * 114;
        const active = this.nameEntry.focus === 'controls' && this.nameEntry.controlIndex === idx;
        shared.drawWindow(ctx, x, ctrlTop, 96, 24, false, '#f8fff8', '#6f86a0', 'std');
        if (control.kind === 'page') {
          shared.drawUiBanner(ctx, shared.PACKEGE_UI_ASSETS[pageKey], x + 28, ctrlTop + 8, 40, 8);
        } else if (control.kind === 'ok') {
          shared.drawUiBanner(ctx, shared.PACKEGE_UI_ASSETS.namingOkButton, x + 28, ctrlTop, 40, 24);
        } else if (control.kind === 'del') {
          shared.drawUiBanner(ctx, shared.PACKEGE_UI_ASSETS.namingBackButton, x + 28, ctrlTop, 40, 24);
          shared.centerText(ctx, 'DEL', x + 49, ctrlTop + 16, 9, 1, '#18324a');
        } else {
          shared.centerText(ctx, control.label, x + 48, ctrlTop + 16, 14, 1, '#18324a');
        }
        if (active) {
          shared.drawUiGlyph(ctx, shared.PACKEGE_UI_ASSETS.namingCursor, x + 6, ctrlTop, 16, 24);
        }
      });

      shared.centerText(ctx, `현재 길이 ${targetValue.length}/${maxChars}`, 392, 92, 11, 0.92, '#35526a');
      this.drawDialogue(ctx, this.getDialogue());
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

    getCurrentNameRows() {
      const pages = getShared().NAME_ENTRY_PAGES || {};
      return pages[this.nameEntry.page] || pages.upper || [];
    }

    getNameControlItems() {
      return [
        { kind: 'page', label: (getShared().NAME_ENTRY_PAGE_BUTTON_LABEL || {})[this.nameEntry.page] || 'abc' },
        { kind: 'del', label: 'DEL' },
        { kind: 'ok', label: 'OK' }
      ];
    }

    normalizeNameCursor() {
      const rows = this.getCurrentNameRows();
      const maxY = Math.max(0, rows.length - 1);
      this.nameEntry.y = this.clamp(this.nameEntry.y, 0, maxY);
      const row = rows[this.nameEntry.y] || [];
      this.nameEntry.x = this.clamp(this.nameEntry.x, 0, Math.max(0, row.length - 1));
    }

    currentName() {
      return this.nameMode === 'player' ? this.engine.profile.playerName : this.engine.profile.rivalName;
    }

    setCurrentName(value) {
      if (this.nameMode === 'player') {
        this.engine.profile.playerName = value;
      } else {
        this.engine.profile.rivalName = value;
      }
    }

    appendChar(ch) {
      const maxChars = getShared().NAME_ENTRY_MAX_CHARS || 7;
      const current = this.currentName();
      if (current.length >= maxChars) return;
      this.setCurrentName(current + ch);
    }

    backspaceName() {
      this.setCurrentName(this.currentName().slice(0, -1));
    }

    cycleNamePage() {
      const next = (getShared().NAME_ENTRY_PAGE_NEXT || {})[this.nameEntry.page] || 'upper';
      this.nameEntry.page = next;
      this.normalizeNameCursor();
    }

    handleNamingAction() {
      if (this.nameEntry.focus === 'grid') {
        const row = this.getCurrentNameRows()[this.nameEntry.y] || [];
        const ch = row[this.nameEntry.x];
        if (typeof ch === 'string') {
          this.appendChar(ch);
        }
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
        if (!this.currentName().trim()) return;
        this.setPhase(this.nameMode === 'player' ? 'playerConfirm' : 'rivalConfirm');
      }
    }

    moveNameSelection(dx, dy) {
      if (this.nameEntry.focus === 'grid') {
        if (dy > 0 && this.nameEntry.y === this.getCurrentNameRows().length - 1) {
          this.nameEntry.focus = 'controls';
          this.nameEntry.controlIndex = this.clamp(this.nameEntry.controlIndex, 0, 2);
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

      this.nameEntry.controlIndex = this.clamp(this.nameEntry.controlIndex + dx, 0, 2);
    }

    getPhaseKind() {
      switch (this.phase) {
        case 'welcome':
        case 'thisWorld':
        case 'inhabited':
        case 'study':
        case 'tellMe':
          return 'oak';
        case 'gender':
          return 'gender';
        case 'playerPrompt':
        case 'playerConfirm':
        case 'letsGo':
          return 'player';
        case 'rivalPrompt':
        case 'rivalConfirm':
          return 'rival';
        case 'playerNaming':
        case 'rivalNaming':
          return 'naming';
        case 'exit':
          return 'exit';
      }
      return 'oak';
    }

    getDialogue() {
      const flow = this.engine.flow || {};
      switch (this.phase) {
        case 'welcome':
          return (flow.oakIntro && flow.oakIntro[0]) || '포켓몬의 세계에 온 것을 환영한다!';
        case 'thisWorld':
          return (flow.oakIntro && flow.oakIntro[1]) || '모두가 나를 포켓몬 박사라 부른다.';
        case 'inhabited':
          return (flow.oakIntro && flow.oakIntro[2]) || '이 세계에는 포켓몬이 살고 있단다.';
        case 'study':
          return (flow.oakIntro && flow.oakIntro[3]) || '포켓몬은 친구이자 파트너란다.';
        case 'tellMe':
          return (flow.oakIntro && flow.oakIntro[5]) || '먼저 네가 어떤 아이인지 알려다오.';
        case 'gender':
          return '너는 소년이냐? 소녀냐?';
        case 'playerPrompt':
        case 'playerNaming':
          return (flow.postGender && flow.postGender[0]) || '좋다! 이제 네 이름을 알려다오.';
        case 'playerConfirm':
          return `${this.engine.profile.playerName || 'PLAYER'}, 이 이름으로 좋겠느냐?`;
        case 'rivalPrompt':
        case 'rivalNaming':
          return (flow.postGender && flow.postGender[1]) || '이어서 네 라이벌의 이름도 정해야 해.';
        case 'rivalConfirm':
          return `${this.engine.profile.rivalName || 'RIVAL'}, 그 이름이었지?`;
        case 'letsGo':
          return `${this.engine.profile.playerName || 'PLAYER'}! 이제 너 자신의 이야기가 시작된다!`;
        case 'exit':
          return `${this.engine.profile.playerName || 'PLAYER'}의 여정으로 이동한다...`;
      }
      return '';
    }

    currentPlayerSprite() {
      const shared = getShared();
      return this.engine.profile.gender === 'F'
        ? (shared.PACKEGE_OAK_SPEECH_ASSETS || {}).leaf
        : (shared.PACKEGE_OAK_SPEECH_ASSETS || {}).red;
    }

    finish() {
      this.engine.saveLocalSnapshot();
      this.engine.oakSpeech = null;
      this.engine.setState('field');
    }

    clamp(v, min, max) {
      return typeof getShared().clamp === 'function'
        ? getShared().clamp(v, min, max)
        : Math.max(min, Math.min(max, v));
    }
  }

  window.FRLG.OakSpeech = OakSpeech;
})();
