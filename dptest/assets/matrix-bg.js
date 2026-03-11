/* DPTest/assets/matrix-bg.js
   - 영화 느낌 Matrix Rain (헤드 밝게 + 트레일 + 비네팅/스캔라인 옵션)
   - 페이지마다 window.MATRIX_BG 설정으로 제어
*/
(function () {
  // ✅ body가 아직 없을 수 있음(app.php에서 head에 로드될 때)
  //    -> DOM 준비 후 초기화해서 매트릭스가 안 뜨는 문제 방지
  function init(){
    if (!document.body) return;
    if (document.getElementById('matrixBg')) return; // 중복 방지

    const cfg = window.MATRIX_BG || {};
    const enabled  = (cfg.enabled !== false);
    if (!enabled) return;

    const text     = (cfg.text ?? "").toString();
    // ✅ 기본 텍스트(평상시) 보관: 날씨가 비/눈일 때만 RAIN/SNOW로 임시 치환
    if (cfg._baseText == null) cfg._baseText = (cfg.text ?? "").toString();

    // ✅ 군포시(Gunpo) 날씨: 비/눈이면 매트릭스 텍스트 자동 변경(평상시엔 기존 text 유지)
    // - 네트워크 실패/응답 없음이면 아무 것도 안 바꿈
    (function weatherAutoText(){
      try{
        var cacheKey = 'dp_gunpo_weather_cache_v1';
        var now = Date.now();
        var cached = null;
        try{ cached = JSON.parse(localStorage.getItem(cacheKey) || 'null'); }catch(e){ cached=null; }

        // 30분 캐시
        if (cached && cached.ts && (now - cached.ts) < 30*60*1000) {
          applyCode(cached.code);
          return;
        }

        // Open-Meteo geocoding -> forecast (키 필요 없음)
        var geoUrl = 'https://geocoding-api.open-meteo.com/v1/search?name=' + encodeURIComponent('Gunpo') + '&count=1&language=ko&format=json';
        fetch(geoUrl, {cache:'no-store'}).then(function(r){ return r.json(); }).then(function(g){
          var lat = g && g.results && g.results[0] ? g.results[0].latitude : null;
          var lon = g && g.results && g.results[0] ? g.results[0].longitude : null;
          if (lat == null || lon == null) throw new Error('no geo');
          var wUrl = 'https://api.open-meteo.com/v1/forecast?latitude=' + lat + '&longitude=' + lon + '&current_weather=true&timezone=Asia%2FSeoul';
          return fetch(wUrl, {cache:'no-store'}).then(function(r){ return r.json(); });
        }).then(function(w){
          var code = w && w.current_weather ? w.current_weather.weathercode : null;
          try{ localStorage.setItem(cacheKey, JSON.stringify({ts:now, code:code})); }catch(e){}
          applyCode(code);
        }).catch(function(){
          // ignore
        });

        function applyCode(code){
          // Open-Meteo weathercode 기준
          // snow: 71-77, 85-86 / rain: 51-67, 80-82, 95-99
          var isSnow = (code>=71 && code<=77) || (code>=85 && code<=86);
          var isRain = (code>=51 && code<=67) || (code>=80 && code<=82) || (code>=95 && code<=99);

          if (isSnow) window.MATRIX_BG.text = 'SNOW';
          else if (isRain) window.MATRIX_BG.text = 'RAIN';
          else window.MATRIX_BG.text = (cfg._baseText ?? '').toString();
        }
      }catch(e){
        // ignore
      }
    })();

    const speed    = Math.max(0.2, Math.min(5.0, +cfg.speed || 1.10));
    const size     = Math.max(10,  Math.min(40,  parseInt(cfg.size, 10) || 16));
    const zIndex   = (cfg.zIndex != null ? cfg.zIndex : 0);
    const scan     = (cfg.scanlines != null ? !!cfg.scanlines : true);
    const vignette = (cfg.vignette  != null ? !!cfg.vignette  : true);

    // CSS 주입 (각 페이지 CSS 손대기 최소화)
    const style = document.createElement('style');
    style.textContent = `
    #matrixBg{
      position:fixed; inset:0; width:100%; height:100%;
      z-index:${zIndex}; pointer-events:none;
    }
    .matrix-overlay-vignette{
      position:fixed; inset:0; z-index:${zIndex + 1}; pointer-events:none;
      background: radial-gradient(circle at 50% 35%, rgba(0,120,0,.10), rgba(0,0,0,.50));
    }
    .matrix-overlay-scan{
      position:fixed; inset:0; z-index:${zIndex + 1}; pointer-events:none;
      background: linear-gradient(
        to bottom,
        rgba(0,0,0,0.00) 0%,
        rgba(0,0,0,0.00) 50%,
        rgba(0,0,0,0.08) 51%,
        rgba(0,0,0,0.00) 100%
      );
      background-size:100% 3px;
      opacity:0.22;
      mix-blend-mode:multiply;
    }
  `;
    document.head.appendChild(style);

    // 캔버스 삽입 (body 최상단)
    const canvas = document.createElement('canvas');
    canvas.id = 'matrixBg';
    document.body.prepend(canvas);

    // 오버레이 삽입
    if (vignette) {
      const v = document.createElement('div');
      v.className = 'matrix-overlay-vignette';
      document.body.appendChild(v);
    }
    if (scan) {
      const s = document.createElement('div');
      s.className = 'matrix-overlay-scan';
      document.body.appendChild(s);
    }

    const ctx = canvas.getContext('2d', { alpha: true });

    const charset = (
    "アイウエオカキクケコサシスセソタチツテトナニヌネノ" +
    "ハヒフヘホマミムメモヤユヨラリルレロワヲン" +
    "0123456789@#$%&*+-=<>/?"
  ).split("");

    let dpr = 1, cols = 0;
    let drops = [], speeds = [], trails = [];
    let txtIdx = 0;
    let lastFrame = 0;

    function rand(min, max) { return min + Math.random() * (max - min); }

    function pickChar() {
      // ✅ text는 동적으로 읽어서(날씨 RAIN/SNOW 등) 실행 중에도 바뀌도록
      const dynText = ((window.MATRIX_BG && window.MATRIX_BG.text != null) ? String(window.MATRIX_BG.text) : text);
      if (dynText && dynText.length) {
        const ch = dynText[txtIdx % dynText.length];
        txtIdx++;
        return ch;
      }
      return charset[(Math.random() * charset.length) | 0];
    }

    function resize() {
      dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
      const w = window.innerWidth;
      const h = window.innerHeight;

      canvas.style.width = w + 'px';
      canvas.style.height = h + 'px';
      canvas.width = Math.floor(w * dpr);
      canvas.height = Math.floor(h * dpr);

      // CSS 픽셀 좌표계로
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      cols = Math.floor(w / size);
      drops  = new Array(cols).fill(0).map(() => -Math.random() * (h / size));
      speeds = new Array(cols).fill(0).map(() => rand(0.65, 1.55) * speed);
      trails = new Array(cols).fill(0).map(() => Math.floor(rand(8, 22)));
    }

    window.addEventListener('resize', resize);
    resize();

    function draw(ts) {
      // 30fps 제한(부하 방지)
      if (ts - lastFrame < 33) { requestAnimationFrame(draw); return; }
      lastFrame = ts;

      const w = window.innerWidth;
      const h = window.innerHeight;
      const hidden = document.hidden;

      // 잔상(트레일) - 어둡게 덮지 않게 조절
      ctx.fillStyle = hidden ? "rgba(0,0,0,0.16)" : "rgba(0,12,0,0.045)";
      ctx.fillRect(0, 0, w, h);

      ctx.font = `${size}px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace`;
      ctx.textBaseline = 'top';

      for (let i = 0; i < cols; i++) {
        const x = i * size;
        const y = drops[i] * size;

        // 헤드(밝게)
        const head = pickChar();
        const headA = hidden ? 0.20 : rand(0.72, 0.92);

        ctx.shadowColor = "rgba(170,255,170,0.65)";
        ctx.shadowBlur  = hidden ? 0 : 22;
        ctx.fillStyle   = `rgba(230,255,230,${headA})`;
        ctx.fillText(head, x, y);

        // 꼬리 색감(살짝만) — 트레일은 잔상으로 자연스럽게 나오게
        if (!hidden) {
          for (let t = 1; t <= 3; t++) {
            const ty = y - t * size;
            const a = 0.18 - (t * 0.045);
            if (ty < 0 || a <= 0) continue;
            ctx.shadowBlur = 10;
            ctx.fillStyle  = `rgba(0,255,90,${a})`;
            ctx.fillText(pickChar(), x, ty);
          }
        }

        // 이동
        drops[i] += (hidden ? 0.20 : speeds[i]) * rand(0.90, 1.18);

        // 화면 아래로 지나가면 확률적으로 리셋
        if (y > h + trails[i] * size) {
          if (Math.random() > 0.90) {
            drops[i]  = -rand(1, 30);
            speeds[i] = rand(0.65, 1.55) * speed;
            trails[i] = Math.floor(rand(8, 22));
          }
        }
      }

      requestAnimationFrame(draw);
    }

    requestAnimationFrame(draw);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
