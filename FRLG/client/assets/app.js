(function() {
  const canvas = document.getElementById('game');
  const sceneName = document.getElementById('sceneName');
  const engine = new window.FRLG.IntroEngine(
    canvas,
    window.FRLG_INTRO_SCENES,
    (id) => { sceneName.textContent = id; }
  );

  window.addEventListener('keydown', (e) => {
    if (e.code === 'Space' || e.code === 'Enter') {
      e.preventDefault();
      engine.nextScene();
    }
    if (e.key === 'r' || e.key === 'R') {
      e.preventDefault();
      engine.restart();
    }
  });

  engine.start();
})();
