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
  roomMessage: 'Packege 맵 json skeleton 유지. starter 선택 이후 rival 첫 배틀 skeleton까지 연결 중.',
  saveMessage: 'LOCAL 저장 완료.',
  signs: {
    PalletTown_EventScript_OaksLabSign: '오박사의 연구소다. 안에 들어가 볼까?',
    PalletTown_EventScript_PlayersHouseSign: '여기가 너의 집이다.',
    PalletTown_EventScript_RivalsHouseSign: '라이벌의 집이다.',
    PalletTown_EventScript_TownSign: '태초마을. 모든 것이 시작되는 곳.',
    PalletTown_EventScript_TrainerTipsSign: 'TRAINER TIPS! SAVE는 자주 하는 편이 좋다.',
    PalletTown_PlayersHouse_2F_EventScript_NES: '게임기가 놓여 있다. 아직은 플레이하지 않는 편이 좋겠다.',
    PalletTown_PlayersHouse_2F_EventScript_PC: 'PC다. 아직 조작 기능은 skeleton 상태다.',
    PalletTown_PlayersHouse_2F_EventScript_Sign: '벽 장식이다. 나중에 원문으로 교체된다.',
    PalletTown_PlayersHouse_1F_EventScript_TV: 'TV다. 흥미로운 영화가 방영 중인 것 같다.',
    PalletTown_RivalsHouse_EventScript_Bookshelf: '책장이 빼곡하다. 포켓몬 관련 책들이다.',
    PalletTown_RivalsHouse_EventScript_Picture: '마을 지도가 걸려 있다.',
    PalletTown_ProfessorOaksLab_EventScript_Computer: '연구용 컴퓨터가 작동 중이다.',
    PalletTown_ProfessorOaksLab_EventScript_LeftSign: '왼쪽 받침대. 어떤 포켓몬이 들어 있었던 자리처럼 보인다.',
    PalletTown_ProfessorOaksLab_EventScript_RightSign: '오른쪽 받침대. 스타터 연출은 아직 skeleton이다.'
  },
  npcLines: {
    PalletTown_PlayersHouse_1F_EventScript_Mom: ['엄마: 이제 아래층까지도 연결됐구나.', '다음엔 원본 대사와 이벤트 플래그를 붙이면 되겠네.'],
    PalletTown_EventScript_SignLady: ['주민: 표지판도 이제 읽을 수 있게 됐네.'],
    PalletTown_EventScript_FatMan: ['주민: 아직은 placeholder지만, 맵 흐름은 점점 잡히고 있어.'],
    PalletTown_RivalsHouse_EventScript_Daisy: ['다이: 안녕! 여긴 라이벌의 집이야.', '나중엔 town map 연출도 붙일 수 있겠지.'],
    PalletTown_ProfessorOaksLab_EventScript_Aide1: ['조수: 오박사님은 언제나 연구에 몰두하고 계셔.'],
    PalletTown_ProfessorOaksLab_EventScript_Aide2: ['조수: 스타터 볼과 이벤트는 아직 구현 전이야.'],
    PalletTown_ProfessorOaksLab_EventScript_Aide3: ['조수: 지금은 원본 흐름 skeleton을 먼저 맞추는 중이야.'],
    PalletTown_ProfessorOaksLab_EventScript_ProfOak: ['오박사: 좋아, 이제 연구소 안까지 왔구나.'],
    PalletTown_ProfessorOaksLab_EventScript_Rival: ['라이벌: 흥, 먼저 포켓몬을 골라 봐.'],
    PalletTown_ProfessorOaksLab_EventScript_Pokedex: ['도감이다. 아직은 placeholder 장식이다.'],
    PalletTown_ProfessorOaksLab_EventScript_BulbasaurBall: ['이건 이상해보이는 몬스터볼이다.'],
    PalletTown_ProfessorOaksLab_EventScript_SquirtleBall: ['물타입 스타터가 들어 있을 것 같은 볼이다.'],
    PalletTown_ProfessorOaksLab_EventScript_CharmanderBall: ['불꽃 타입 스타터가 들어 있을 것 같은 볼이다.']
  },
  events: {
    oakStopsYou: [
      '오박사: 잠깐! 아직 혼자 밖으로 나가면 위험하단다.',
      '오박사: 연구소로 오렴. 보여줄 것이 있단다.'
    ],
    starterAlreadyChosen: ['이미 스타터를 골랐다. 이제 라이벌과 첫 배틀 흐름을 붙이면 된다.'],
    starterPick: {
      BULBASAUR: ['이상해씨를 골랐다!', '스타터 선택 skeleton 완료. 다음엔 전투/라이벌 선택 흐름을 붙이면 된다.'],
      CHARMANDER: ['파이리를 골랐다!', '스타터 선택 skeleton 완료. 다음엔 전투/라이벌 선택 흐름을 붙이면 된다.'],
      SQUIRTLE: ['꼬부기를 골랐다!', '스타터 선택 skeleton 완료. 다음엔 전투/라이벌 선택 흐름을 붙이면 된다.']
    },
    rivalBattleChallenge: ['라이벌: 좋아! 그럼 곧바로 승부다!', '원본처럼 첫 라이벌 배틀 skeleton으로 이어진다.'],
    rivalBattleWin: ['첫 라이벌 배틀 skeleton 완료.', '다음엔 라이벌 퇴장 / 오박사 후속 대사 / 실제 전투 규칙을 붙이면 된다.'],
    rivalAfterBattle: ['라이벌: 흥! 다음엔 절대 안 질 거야!'],
    oakAfterStarter: ['오박사: 스타터를 골랐구나. 이제 서로 실력을 시험해 보렴.'],
    oakAfterBattle: ['오박사: 훌륭하구나. 이제 본격적으로 모험을 떠날 준비를 하자.']
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
