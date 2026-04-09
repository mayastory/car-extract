window.FRLG = window.FRLG || {};

(function() {
  class IntroEngine {
    constructor(canvas, scenes, onSceneChange) {
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.scenes = scenes;
      this.onSceneChange = onSceneChange || (() => {});
      this.sceneIndex = 0;
      this.sceneStart = 0;
      this.running = false;
      this.boundLoop = this.loop.bind(this);
      this.onSceneChange(this.currentScene().id);
    }

    currentScene() {
      return this.scenes[this.sceneIndex];
    }

    start() {
      this.running = true;
      this.sceneStart = performance.now();
      requestAnimationFrame(this.boundLoop);
    }

    restart() {
      this.sceneIndex = 0;
      this.sceneStart = performance.now();
      this.onSceneChange(this.currentScene().id);
    }

    nextScene() {
      this.sceneIndex = (this.sceneIndex + 1) % this.scenes.length;
      this.sceneStart = performance.now();
      this.onSceneChange(this.currentScene().id);
    }

    drawBg() {
      const g = this.ctx.createLinearGradient(0, 0, 0, this.canvas.height);
      g.addColorStop(0, '#020406');
      g.addColorStop(1, '#000000');
      this.ctx.fillStyle = g;
      this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
    }

    loop(now) {
      if (!this.running) return;
      const scene = this.currentScene();
      const elapsed = now - this.sceneStart;
      const duration = scene.duration || 1000;
      const t = Math.max(0, Math.min(1, elapsed / duration));

      this.drawBg();
      scene.draw(this.ctx, t, this);

      if (elapsed >= duration) {
        this.nextScene();
      }
      requestAnimationFrame(this.boundLoop);
    }
  }

  window.FRLG.IntroEngine = IntroEngine;
})();
