<?php
// FRLG starter client entry. Purpose: establish a local, PHP-served web client
// that can later load extracted Packege assets and connect to SQL/runtime APIs.
?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>FRLG Web Client Starter</title>
  <link rel="stylesheet" href="assets/frlg.css">
</head>
<body>
  <div class="shell">
    <header class="hud">
      <div class="title">FRLG Web Client Starter</div>
      <div class="subtitle">intro scene runner / packege-ready skeleton</div>
    </header>

    <main class="stage-wrap">
      <canvas id="game" width="480" height="320" aria-label="FRLG canvas"></canvas>
      <div class="overlay">
        <div class="badge">LOCAL</div>
        <div id="sceneName" class="scene-name">boot</div>
        <div class="hint">Enter/Space: next scene · R: restart</div>
      </div>
    </main>

    <section class="panel">
      <div class="panel-title">현재 목적</div>
      <ul>
        <li>PHP에서 바로 열리는 로컬 FRLG 클라이언트 시작점</li>
        <li>원본 intro.c 구조를 흉내 낼 수 있는 scene sequencer 골격</li>
        <li>나중에 Packege 추출 자산으로 교체 가능한 구조</li>
      </ul>
    </section>
  </div>

  <script src="assets/intro-scenes.js"></script>
  <script src="assets/intro-engine.js"></script>
  <script src="assets/app.js"></script>
</body>
</html>
