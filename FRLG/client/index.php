<?php
?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>FRLG</title>
  <link rel="stylesheet" href="assets/frlg.css">
</head>
<body>
  <div class="shell">
    <main class="stage-wrap">
      <canvas id="game" width="480" height="320" aria-label="FRLG canvas"></canvas>
    </main>

    <section class="touch-ui" id="touchControls" aria-label="터치 컨트롤">
      <div class="touch-dpad" aria-label="방향키">
        <button type="button" class="touch-btn dpad-btn up" data-key="ArrowUp" aria-label="위">▲</button>
        <button type="button" class="touch-btn dpad-btn left" data-key="ArrowLeft" aria-label="왼쪽">◀</button>
        <button type="button" class="touch-btn dpad-btn right" data-key="ArrowRight" aria-label="오른쪽">▶</button>
        <button type="button" class="touch-btn dpad-btn down" data-key="ArrowDown" aria-label="아래">▼</button>
        <div class="dpad-center" aria-hidden="true"></div>
      </div>
      <div class="touch-actions" aria-label="액션 버튼">
        <div class="touch-ab">
          <button type="button" class="touch-btn action-circle action-b" data-key="Backspace" aria-label="B 버튼">B</button>
          <button type="button" class="touch-btn action-circle action-a" data-key="Enter" aria-label="A 버튼">A</button>
        </div>
        <div class="touch-meta">
          <button type="button" class="touch-btn action-meta action-menu" data-key="Escape" aria-label="메뉴 버튼">MENU</button>
          <button type="button" class="touch-btn action-meta action-reset" data-key="r" aria-label="리셋 버튼">R</button>
        </div>
      </div>
    </section>
  </div>

  <script src="assets/intro-scenes.js"></script>
  <script src="assets/map-data.js"></script>
  <script src="assets/intro-engine.js"></script>
  <script src="assets/oak_speech.js"></script>
  <script src="assets/app.js"></script>
</body>
</html>
