<?php
?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>FRLG Web Client</title>
  <link rel="stylesheet" href="assets/frlg.css">
</head>
<body>
  <main class="app-shell">
    <header class="top-card">
      <div>
        <h1>FRLG Web Client</h1>
        <p class="sub">client full reset build · broken oak/field patches removed · clean base restart</p>
      </div>
      <div class="pill-row">
        <span class="pill" id="statePill">title</span>
        <span class="pill" id="savePill">continue 없음</span>
      </div>
    </header>

    <section class="game-card">
      <aside class="left-rail">
        <span class="rail-pill">LOCAL</span>
        <span class="rail-pill" id="scenePill">title</span>
        <div class="help-box">
          <div>Enter / A 진행</div>
          <div>ESC / MENU 취소</div>
          <div>방향키 / 디패드 이동</div>
          <div>R 리셋</div>
        </div>
      </aside>

      <div class="stage-wrap">
        <canvas id="gameCanvas" width="720" height="480"></canvas>
      </div>
    </section>

    <section class="touch-wrap" id="touchWrap" aria-label="touch controls">
      <div class="touch-left">
        <button class="pad up" data-key="ArrowUp" aria-label="up">▲</button>
        <button class="pad left" data-key="ArrowLeft" aria-label="left">◀</button>
        <button class="pad right" data-key="ArrowRight" aria-label="right">▶</button>
        <button class="pad down" data-key="ArrowDown" aria-label="down">▼</button>
      </div>
      <div class="touch-right">
        <button class="act b" data-key="Backspace" aria-label="B">B</button>
        <button class="act a" data-key="Enter" aria-label="A">A</button>
        <div class="aux-row">
          <button class="aux" data-key="Escape">MENU</button>
          <button class="aux" data-key="r">R</button>
        </div>
      </div>
    </section>

    <section class="bottom-card">
      <h2>현재 상태</h2>
      <ul>
        <li>오크 구간 포함 기존 임시 구현 제거</li>
        <li>클라이언트 전체를 최소 안정 베이스로 재구성</li>
        <li>터치 버튼 / 키보드 입력 / 텍스트 박스만 유지</li>
        <li>다음 단계부터 <code>oak_speech.c</code> 기준으로 다시 포팅 시작</li>
      </ul>
    </section>
  </main>

  <script src="assets/intro-engine.js"></script>
  <script src="assets/app.js"></script>
</body>
</html>
