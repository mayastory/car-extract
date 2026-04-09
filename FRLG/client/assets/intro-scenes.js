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
  roomMessage: '방 시작점까지 연결 완료. 다음 단계는 Packege 실제 자산/방 맵 로더로 교체.'
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
