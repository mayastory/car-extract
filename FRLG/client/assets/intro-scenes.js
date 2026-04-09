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
    PalletTown_EventScript_OaksLabSign: 'OAK POKéMON RESEARCH LAB',
    PalletTown_EventScript_PlayersHouseSign: "{PLAYER}'s house",
    PalletTown_EventScript_RivalsHouseSign: "{RIVAL}'s house",
    PalletTown_EventScript_TownSign: 'PALLET TOWN\nShades of your journey await!',
    PalletTown_EventScript_TrainerTipsSign: '트레이너 팁! START 버튼으로 메뉴를 열 수 있다.',
    PalletTown_PlayersHouse_2F_EventScript_NES: "{PLAYER} played with the NES.\n…Okay!\nIt's time to go!",
    PalletTown_PlayersHouse_2F_EventScript_PC: '컴퓨터다. 아직 볼 일은 없어 보인다.',
    PalletTown_PlayersHouse_2F_EventScript_Sign: "It's a posted notice…\nIf you're confused, ask for HELP!\nPress the L or R Button!",
    PalletTown_PlayersHouse_1F_EventScript_TV_BOY: "There's a movie on TV.\nFour boys are walking on railroad tracks.\n…I better go, too.",
    PalletTown_PlayersHouse_1F_EventScript_TV_GIRL: "There's a movie on TV.\nA girl with her hair in pigtails is walking up a brick road.\n…I better go, too.",
    PalletTown_RivalsHouse_EventScript_Bookshelf: 'The shelves are crammed full of\nbooks on POKéMON.',
    PalletTown_RivalsHouse_EventScript_Picture: "It's a big map of the KANTO region.\nNow this would be useful!",
    PalletTown_RivalsHouse_EventScript_TownMap: "It's a big map of the KANTO region.\nNow this would be useful!",
    PalletTown_ProfessorOaksLab_EventScript_Computer: '연구용 컴퓨터다. 자료가 가득 저장돼 있다.',
    PalletTown_ProfessorOaksLab_EventScript_LeftSign: "On the desk there is my invention, the POKéDEX!\nIt automatically records data on POKéMON you've seen or caught.",
    PalletTown_ProfessorOaksLab_EventScript_RightSign: "On the desk there is my invention, the POKéDEX!\nIt's a high-tech encyclopedia!"
  },
  npcLines: {
    PalletTown_PlayersHouse_1F_EventScript_Mom_BOY: [
      'MOM: …Right.\nAll boys leave home someday.\nIt said so on TV.',
      'Oh, yes. PROF. OAK, next door, was\nlooking for you.'
    ],
    PalletTown_PlayersHouse_1F_EventScript_Mom_GIRL: [
      'MOM: …Right.\nAll girls dream of traveling.\nIt said so on TV.',
      'Oh, yes. PROF. OAK, next door, was\nlooking for you.'
    ],
    PalletTown_PlayersHouse_1F_EventScript_Mom_AFTER_STARTER: [
      'MOM: {PLAYER}!\nYou should take a quick rest.'
    ],
    PalletTown_PlayersHouse_1F_EventScript_Mom_AFTER_BATTLE: [
      'MOM: Oh, good! You and your\nPOKéMON are looking great.\nTake care now!'
    ],
    PalletTown_EventScript_SignLady: ['여자아이: 표지판은 정말 유용해!', '궁금할 땐 직접 읽어 보는 게 좋아.'],
    PalletTown_EventScript_FatMan: [
      'Technology is incredible!',
      'You can now store and recall items\nand POKéMON as data via PC.'
    ],
    PalletTown_RivalsHouse_EventScript_Daisy_BEFORE_STARTER: [
      'DAISY: Hi, {PLAYER}!',
      "My brother, {RIVAL}, is out at\nGrandpa's LAB."
    ],
    PalletTown_RivalsHouse_EventScript_Daisy_AFTER_BATTLE: [
      'DAISY: {PLAYER}, I heard you had\na battle against {RIVAL}.',
      "I wish I'd seen that!"
    ],
    PalletTown_ProfessorOaksLab_EventScript_Aide1: ['조수: 오박사님의 연구는 늘 바쁘게 돌아가고 있어.'],
    PalletTown_ProfessorOaksLab_EventScript_Aide2: ['조수: 책상 위에는 포켓몬 연구 자료가 잔뜩이야.'],
    PalletTown_ProfessorOaksLab_EventScript_Aide3: ['조수: 오늘은 뭔가 중요한 일이 생길 것 같네.'],
    PalletTown_ProfessorOaksLab_EventScript_ProfOak_BEFORE_STARTER: [
      'OAK: Now, {PLAYER}.',
      'Inside those three POKé BALLS are POKéMON. Which one will you choose for yourself?'
    ],
    PalletTown_ProfessorOaksLab_EventScript_ProfOak_AFTER_STARTER: [
      'OAK: If a wild POKéMON appears, your POKéMON can battle it.',
      'With it at your side, you should be able to reach the next town.'
    ],
    PalletTown_ProfessorOaksLab_EventScript_ProfOak_AFTER_BATTLE: [
      'OAK: {PLAYER}, raise your young POKéMON by making it battle.',
      'It has to battle for it to grow.'
    ],
    PalletTown_ProfessorOaksLab_EventScript_Rival_BEFORE_STARTER: [
      "{RIVAL}: Heh, I don't need to be greedy like you. I'm mature!",
      'Go ahead and choose, {PLAYER}!'
    ],
    PalletTown_ProfessorOaksLab_EventScript_Rival_AFTER_BATTLE: [
      "{RIVAL}: Okay! I'll make my POKéMON battle to toughen it up!",
      '{PLAYER}! Gramps!\nSmell you later!'
    ],
    PalletTown_ProfessorOaksLab_EventScript_Pokedex: [
      'On the desk there is my invention, the POKéDEX!',
      "It's a high-tech encyclopedia!"
    ],
    PalletTown_ProfessorOaksLab_EventScript_BulbasaurBall: ['I see! BULBASAUR is your choice.', "It's very easy to raise."],
    PalletTown_ProfessorOaksLab_EventScript_SquirtleBall: ['Hm! SQUIRTLE is your choice.', "It's one worth raising."],
    PalletTown_ProfessorOaksLab_EventScript_CharmanderBall: ['Ah! CHARMANDER is your choice.', 'You should raise it patiently.']
  },
  events: {
    oakStopsYou: [
      "OAK: Hey! Wait!\nDon't go out!",
      "OAK: It's unsafe!\nWild POKéMON live in tall grass!\nYou need your own POKéMON for your protection.\nI know!\nHere, come with me!"
    ],
    starterAlreadyChosen: ["OAK: Hey!\nDon't go away yet!"],
    starterPick: {
      BULBASAUR: [
        "I see! BULBASAUR is your choice.\nIt's very easy to raise.",
        '{PLAYER} received the BULBASAUR\nfrom PROF. OAK!'
      ],
      CHARMANDER: [
        'Ah! CHARMANDER is your choice.\nYou should raise it patiently.',
        '{PLAYER} received the CHARMANDER\nfrom PROF. OAK!'
      ],
      SQUIRTLE: [
        "Hm! SQUIRTLE is your choice.\nIt's one worth raising.",
        '{PLAYER} received the SQUIRTLE\nfrom PROF. OAK!'
      ]
    },
    rivalBattleChallenge: [
      "{RIVAL}: Wait, {PLAYER}!\nLet's check out our POKéMON!",
      "Come on, I'll take you on!"
    ],
    rivalBattleWin: ['WHAT?\nUnbelievable!\nI picked the wrong POKéMON!'],
    rivalAfterBattle: [
      "{RIVAL}: Okay! I'll make my POKéMON battle to toughen it up!",
      '{PLAYER}! Gramps!\nSmell you later!'
    ],
    oakAfterStarter: [
      'OAK: If a wild POKéMON appears, your POKéMON can battle it.',
      'With it at your side, you should be able to reach the next town.'
    ],
    oakAfterBattle: [
      'OAK: {PLAYER}, raise your young POKéMON by making it battle.',
      'It has to battle for it to grow.'
    ]
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
