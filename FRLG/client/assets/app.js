(function() {
  const canvas = document.getElementById('game');
  const sceneName = document.getElementById('sceneName');
  const phaseName = document.getElementById('phaseName');
  const saveState = document.getElementById('saveState');

  const engine = new window.FRLG.IntroEngine(
    canvas,
    {
      flow: window.FRLG_FLOW_CONFIG,
      maps: window.FRLG_MAP_DATA
    },
    {
      onSceneChange(id) {
        sceneName.textContent = id;
      },
      onPhaseChange(phase) {
        phaseName.textContent = phase;
      },
      onSaveStateChange(text) {
        saveState.textContent = text;
      }
    }
  );

  window.addEventListener('keydown', (e) => {
    engine.handleKeyDown(e);
  });

  engine.start();
})();
