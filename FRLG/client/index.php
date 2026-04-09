<?php
?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>FRLG Web Client Prototype</title>
  <link rel="stylesheet" href="assets/frlg.css">
</head>
<body>
  <div class="shell">
    <header class="hud">
      <div>
        <div class="title">FRLG Web Client Prototype</div>
        <div class="subtitle">intro → title → new game → oak → gender → names → PlayersHouse 2F → 1F → PalletTown → RivalHouse / OaksLab → starter → first rival battle skeleton</div>
      </div>
      <div class="hud-right">
        <div class="mini" id="phaseName">boot</div>
        <div class="mini muted" id="saveState">local only</div>
      </div>
    </header>

    <main class="stage-wrap">
      <canvas id="game" width="480" height="320" aria-label="FRLG canvas"></canvas>
      <div class="overlay">
        <div class="badge">LOCAL</div>
        <div id="sceneName" class="scene-name">boot</div>
        <div class="hint">Enter/Space 진행·대화·입력 · 방향키 선택/이동 · ESC/X 메뉴 · Backspace 삭제 · R 리셋 · 모바일은 아래 터치 버튼</div>
      </div>
    </main>

    <section class="touch-ui" id="touchControls" aria-label="터치 컨트롤">
      <div class="touch-dpad" aria-label="방향키">
        <button type="button" class="touch-btn up" data-key="ArrowUp" aria-label="위">▲</button>
        <button type="button" class="touch-btn left" data-key="ArrowLeft" aria-label="왼쪽">◀</button>
        <button type="button" class="touch-btn down" data-key="ArrowDown" aria-label="아래">▼</button>
        <button type="button" class="touch-btn right" data-key="ArrowRight" aria-label="오른쪽">▶</button>
      </div>
      <div class="touch-actions" aria-label="액션 버튼">
        <button type="button" class="touch-btn action action-a" data-key="Enter" aria-label="A 버튼">A</button>
        <button type="button" class="touch-btn action action-b" data-key="Backspace" aria-label="B 버튼">B</button>
        <button type="button" class="touch-btn action action-menu" data-key="Escape" aria-label="메뉴 버튼">MENU</button>
        <button type="button" class="touch-btn action action-reset" data-key="r" aria-label="리셋 버튼">R</button>
      </div>
    </section>

    <section class="panel panel-grid">
      <div>
        <div class="panel-title">현재 단계</div>
        <ul>
          <li>원본처럼 바로 PalletTown 직행하지 않고 시작 순서를 먼저 연결</li>
          <li>Packege 맵 json 기준으로 PlayersHouse 2F / 1F / PalletTown / RivalHouse / OaksLab 연결</li>
          <li>맵 타일은 아직 placeholder지만 플레이어 / NPC / 표지판 / 일부 오브젝트 자산 + text_window / naming_screen / red_arrow UI 자산 첫 연결</li>
          <li>OaksLab에서 starter 선택 skeleton / 첫 rival battle skeleton / PalletTown 북쪽 Oak 차단 placeholder 추가</li>
        </ul>
      </div>
      <div>
        <div class="panel-title">이번 기준</div>
        <ul>
          <li>인트로 scene 러너 유지</li>
          <li>타이틀 / New Game / Continue(local) 흐름 유지</li>
          <li>오박사 대사 / 성별 / 이름 / 라이벌 이름 흐름 유지</li>
          <li>이름 입력은 browser input 박스 대신 Packege naming_screen 스타일 문자판 skeleton으로 교체</li>
          <li>필드에서 ESC / X 로 여는 게임 내 메뉴 skeleton 추가 (창/커서 UI 보강)</li>
          <li>Packege object_events/pics 기준 필드 캐릭터 / 표지판 스프라이트 적용 + text_window/std / naming_screen / red_arrow UI 자산 1단계 적용</li>
          <li>최종 도착점은 PlayersHouse 2F 시작 후 1F / PalletTown / RivalHouse / OaksLab 이동 가능</li>
          <li>starter / rival starter / first battle 완료 상태가 local continue에도 저장됨</li>
        </ul>
      </div>
    </section>
  </div>

  <script src="assets/intro-scenes.js"></script>
  <script src="assets/map-data.js"></script>
  <script src="assets/intro-engine.js"></script>
  <script src="assets/app.js"></script>
</body>
</html>
