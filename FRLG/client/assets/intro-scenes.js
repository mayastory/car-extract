window.FRLG_FLOW_CONFIG = {
  introScenes: [
    {
      id: 'copyright',
      duration: 2600,
      draw(ctx, t) {
        const a = fadeInOut(t, 0.25, 0.25);
        centerText(ctx, '©2004 GAME FREAK inc.', 240, 150, 16, a);
        centerText(ctx, 'prototype sequence runner', 240, 176, 10, 0.7 * a);
      }
    },
    {
      id: 'gf-logo',
      duration: 3000,
      draw(ctx, t) {
        const a = fadeInOut(t, 0.18, 0.18);
        const pulse = 1 + Math.sin(t * Math.PI * 2) * 0.04;
        ctx.save();
        ctx.translate(240, 158);
        ctx.scale(pulse, pulse);
        ctx.fillStyle = rgba(255, 214, 82, a);
        ctx.strokeStyle = rgba(255, 241, 197, a);
        ctx.lineWidth = 4;
        ctx.beginPath();
        ctx.moveTo(-20, -48);
        ctx.quadraticCurveTo(0, -72, 20, -48);
        ctx.lineTo(26, 44);
        ctx.quadraticCurveTo(0, 64, -26, 44);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();
        ctx.restore();
        centerText(ctx, 'GAME FREAK', 240, 244, 18, a);
      }
    },
    {
      id: 'grass-closeup',
      duration: 3600,
      draw(ctx, t) {
        const a = fadeInOut(t, 0.10, 0.15);
        for (let i = 0; i < 52; i++) {
          const x = (i * 23 + (t * 140)) % 540 - 30;
          const h = 90 + (i % 5) * 14;
          drawGrassBlade(ctx, x, 320, h, 8 + (i % 4), rgba(79, 214, 107, a));
        }
        centerText(ctx, 'scene 1 · grass close-up', 240, 28, 12, 0.75 * a);
      }
    },
    {
      id: 'wide-shot',
      duration: 4400,
      draw(ctx, t) {
        const a = fadeInOut(t, 0.08, 0.12);
        const pan = lerp(-90, 60, Math.min(1, t));
        ctx.save();
        ctx.translate(pan, 0);
        drawHorizon(ctx, a);
        drawMonster(ctx, 155, 196, 1.25, rgba(132, 98, 255, a));
        drawMonster(ctx, 352, 198, 1.15, rgba(255, 118, 118, a));
        ctx.restore();
        centerText(ctx, 'scene 2 · wide shot / pan', 240, 26, 12, 0.78 * a);
      }
    },
    {
      id: 'closeup-duel',
      duration: 4300,
      draw(ctx, t) {
        const a = fadeInOut(t, 0.10, 0.16);
        const zoom = lerp(0.92, 1.18, Math.min(1, t * 1.15));
        ctx.save();
        ctx.translate(240, 176);
        ctx.scale(zoom, zoom);
        drawCloseup(ctx, -70, 0, rgba(255, 118, 118, a), -1);
        drawCloseup(ctx, 70, 0, rgba(132, 98, 255, a), 1);
        ctx.restore();
        centerText(ctx, 'scene 3 · close-up duel', 240, 28, 12, 0.78 * a);
        centerText(ctx, 'placeholder art now · Packege extracted art later', 240, 302, 10, 0.65 * a);
      }
    },
    {
      id: 'title-bridge',
      duration: 2200,
      draw(ctx, t) {
        const a = fadeInOut(t, 0.16, 0.10);
        centerText(ctx, 'FIRE RED / LEAF GREEN', 240, 122, 28, a);
        centerText(ctx, 'title flow booting...', 240, 160, 14, 0.75 * a);
      }
    }
  ],
  oakIntro: [
    '안녕! 포켓몬의 세계에 온 것을 환영한다!',
    '내 이름은 오박사. 모두가 포켓몬 박사라고 부르지.',
    '이 세계에는 포켓몬이라는 생명체가 살고 있단다.',
    '포켓몬은 친구이자 파트너, 그리고 때로는 배틀 상대가 되기도 해.',
    '하지만 아직 너 자신에 대해서는 들은 적이 없구나.',
    '먼저 네가 어떤 아이인지 알려다오.'
  ],
  postGender: [
    '좋다! 이제 네 이름을 알려다오.',
    '이어서 네 라이벌의 이름도 정해야 해.'
  ],
  roomMessage: 'Packege 기준 맵/이벤트를 하나씩 입히는 중이다.',
  saveMessage: '리포트를 작성했다. (LOCAL)',
  signs: {
    PalletTown_EventScript_OaksLabSign: '오박사의 포켓몬 연구소다.',
    PalletTown_EventScript_PlayersHouseSign: '{PLAYER}의 집.',
    PalletTown_EventScript_RivalsHouseSign: '{RIVAL}의 집.',
    PalletTown_EventScript_TownSign: '태초마을. 여행의 빛깔이 너를 기다린다.',
    PalletTown_EventScript_TrainerTipsSign: '트레이너 팁! START 버튼으로 메뉴를 열 수 있다.',
    PalletTown_PlayersHouse_2F_EventScript_NES: '{PLAYER}는 NES를 만지작거렸다.\n…좋아! 이제 내려가자.',
    PalletTown_PlayersHouse_2F_EventScript_PC: '컴퓨터다. 아직 볼 일은 없어 보인다.',
    PalletTown_PlayersHouse_2F_EventScript_Sign: '게시물이 붙어 있다. HELP가 필요하면 L/R 버튼을 눌러 보자.',
    PalletTown_PlayersHouse_1F_EventScript_TV: 'TV에서 영화가 나오고 있다.\n…이제 슬슬 나갈 시간이다.',
    PalletTown_RivalsHouse_EventScript_Bookshelf: '책장이 포켓몬 책으로 가득하다.',
    PalletTown_RivalsHouse_EventScript_Picture: '칸토 지방의 큰 지도가 걸려 있다. 있으면 편리하겠다.',
    PalletTown_ProfessorOaksLab_EventScript_Computer: '연구용 컴퓨터다. 자료가 가득 저장돼 있다.',
    PalletTown_ProfessorOaksLab_EventScript_LeftSign: '받침대 설명문이다. 포켓몬에 대한 메모가 적혀 있다.',
    PalletTown_ProfessorOaksLab_EventScript_RightSign: '또 다른 받침대 설명문이다. 아직 자세한 내용은 읽을 수 없다.'
  },
  npcLines: {
    PalletTown_PlayersHouse_1F_EventScript_Mom: ['엄마: 오박사님이 너를 찾으시는 것 같더라.', '바로 옆 연구소에 계실 거야.'],
    PalletTown_EventScript_SignLady: ['여자아이: 표지판은 정말 유용해!', '궁금할 땐 직접 읽어 보는 게 좋아.'],
    PalletTown_EventScript_FatMan: ['아저씨: 기술은 대단하지!', '지금은 PC로 아이템과 포켓몬을 보관할 수 있단다.'],
    PalletTown_RivalsHouse_EventScript_Daisy: ['다이: 안녕, {PLAYER}!', '{RIVAL}는 할아버지 연구소에 가 있어.'],
    PalletTown_ProfessorOaksLab_EventScript_Aide1: ['조수: 오박사님의 연구는 늘 바쁘게 돌아가고 있어.'],
    PalletTown_ProfessorOaksLab_EventScript_Aide2: ['조수: 책상 위에는 포켓몬 연구 자료가 잔뜩이야.'],
    PalletTown_ProfessorOaksLab_EventScript_Aide3: ['조수: 오늘은 뭔가 중요한 일이 생길 것 같네.'],
    PalletTown_ProfessorOaksLab_EventScript_ProfOak: ['오박사: 그래, 여기까지 잘 왔다.', '이제 네 포켓몬을 골라 보거라.'],
    PalletTown_ProfessorOaksLab_EventScript_Rival: ['{RIVAL}: 난 욕심부리지 않아.', '먼저 네가 골라, {PLAYER}!'],
    PalletTown_ProfessorOaksLab_EventScript_Pokedex: ['도감이다. 지금은 아직 받아 갈 수 없다.'],
    PalletTown_ProfessorOaksLab_EventScript_BulbasaurBall: ['몬스터볼 안에서 이상해씨가 기척을 보인다.'],
    PalletTown_ProfessorOaksLab_EventScript_SquirtleBall: ['몬스터볼 안에서 꼬부기가 기척을 보인다.'],
    PalletTown_ProfessorOaksLab_EventScript_CharmanderBall: ['몬스터볼 안에서 파이리가 기척을 보인다.']
  },
  events: {
    oakStopsYou: [
      '오박사: 이봐! 잠깐만, 아직 밖은 위험하단다!',
      '오박사: 풀숲엔 야생 포켓몬이 살고 있어. 이리 따라오너라!'
    ],
    starterAlreadyChosen: ['이미 포켓몬을 골랐다.'],
    starterPick: {
      BULBASAUR: ['이상해씨를 골랐다!', '차분하지만 믿음직한 파트너다.'],
      CHARMANDER: ['파이리를 골랐다!', '작지만 불꽃은 아주 뜨겁다.'],
      SQUIRTLE: ['꼬부기를 골랐다!', '든든한 등껍질이 인상적이다.']
    },
    rivalBattleChallenge: ['{RIVAL}: 좋아! 그럼 바로 승부다!', '{RIVAL}가 첫 배틀을 걸어왔다.'],
    rivalBattleWin: ['첫 라이벌 배틀이 끝났다.', '{RIVAL}는 분한 표정으로 물러났다.'],
    rivalAfterBattle: ['{RIVAL}: 흥! 다음엔 절대 안 질 거야!'],
    oakAfterStarter: ['오박사: 좋아, 이제 서로 실력을 시험해 보렴.'],
    oakAfterBattle: ['오박사: 훌륭하구나. 이제 슬슬 여행을 준비할 때가 됐어.']
  }
};

function centerText(ctx, text, x, y, size, alpha = 1, color = '#ffffff') {
  ctx.save();
  ctx.globalAlpha = alpha;
  ctx.textAlign = 'center';
  ctx.fillStyle = color;
  ctx.font = `${size}px Arial`;
  ctx.fillText(text, x, y);
  ctx.restore();
}
function rgba(r, g, b, a) {
  return `rgba(${r}, ${g}, ${b}, ${a})`;
}
function lerp(a, b, t) {
  return a + (b - a) * t;
}
function fadeInOut(t, inPart, outPart) {
  let a = 1;
  if (t < inPart) a = t / inPart;
  if (t > 1 - outPart) a = Math.min(a, (1 - t) / outPart);
  return Math.max(0, Math.min(1, a));
}
function drawGrassBlade(ctx, x, baseY, h, bend, color) {
  ctx.save();
  ctx.strokeStyle = color;
  ctx.lineWidth = 3;
  ctx.beginPath();
  ctx.moveTo(x, baseY);
  ctx.quadraticCurveTo(x + bend, baseY - h * 0.55, x + bend * 0.25, baseY - h);
  ctx.stroke();
  ctx.restore();
}
function drawHorizon(ctx, a) {
  ctx.save();
  ctx.globalAlpha = a;
  const g = ctx.createLinearGradient(0, 0, 0, 320);
  g.addColorStop(0, '#24354d');
  g.addColorStop(0.6, '#0f1319');
  g.addColorStop(1, '#0b100d');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, 480, 320);
  ctx.fillStyle = 'rgba(104, 212, 111, 0.85)';
  ctx.fillRect(0, 220, 480, 100);
  ctx.restore();
}
function drawMonster(ctx, x, y, scale, color) {
  ctx.save();
  ctx.translate(x, y);
  ctx.scale(scale, scale);
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.arc(0, -12, 30, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillRect(-38, 8, 76, 26);
  ctx.fillRect(-50, 20, 16, 22);
  ctx.fillRect(34, 20, 16, 22);
  ctx.restore();
}
function drawCloseup(ctx, x, y, color, facing) {
  ctx.save();
  ctx.translate(x, y);
  ctx.scale(facing, 1);
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.arc(0, -10, 68, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = 'rgba(255,255,255,0.92)';
  ctx.fillRect(-20, -26, 14, 14);
  ctx.fillStyle = '#000';
  ctx.fillRect(-14, -21, 6, 6);
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.moveTo(-58, -36);
  ctx.lineTo(-94, -88);
  ctx.lineTo(-30, -54);
  ctx.closePath();
  ctx.fill();
  ctx.restore();
}
