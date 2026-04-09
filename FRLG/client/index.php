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
        <div class="hint">Enter/Space 진행·대화·입력 · 방향키 선택/이동 · ESC/X 메뉴 · Backspace 삭제 · R 리셋</div>
      </div>
    </main>

    <section class="panel panel-grid">
      <div>
        <div class="panel-title">현재 단계</div>
        <ul>
          <li>원본처럼 바로 PalletTown 직행하지 않고 시작 순서를 먼저 연결</li>
          <li>Packege 맵 json 기준으로 PlayersHouse 2F / 1F / PalletTown / RivalHouse / OaksLab 연결</li>
          <li>자산 추출 전이라 그래픽은 placeholder 렌더링 유지</li>
          <li>PalletTown / 집 / 연구소 주요 문구는 Packege text.inc 기준으로 일부 직접 반영 시작</li>
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
          <li>MOM / DAISY / OAK / starter / town sign / TV 등 일부 대사를 Packege 원문에 더 가깝게 교체</li>
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
