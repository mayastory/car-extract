<?php
$holdingTabs = [
    'mem-ir-base'   => 'MEM-IR-BASE',
    'mem-x-carrier' => 'MEM-X-CARRIER',
    'mem-y-carrier' => 'MEM-Y-CARRIER',
    'mem-z-carrier' => 'MEM-Z-CARRIER',
    'mem-z-stopper' => 'MEM-Z-STOPPER',
];
$activeHoldingTab = (string)($_GET['tab'] ?? 'mem-ir-base');
if (!isset($holdingTabs[$activeHoldingTab])) {
    $activeHoldingTab = 'mem-ir-base';
}
?>
<style>
.ohl-page,
.ohl-page * {
  box-sizing: border-box;
}

.ohl-page {
  width: min(1550px, calc(100% - 32px));
  margin: 18px auto 40px;
  position: relative;
  color: #e8eaed;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.ohl-title {
  margin: 0 0 10px;
  font-size: 20px;
  font-weight: 600;
  line-height: 1.2;
}

.ohl-window {
  position: relative;
}

.ohl-tabs {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: flex-end;
  gap: 3px;
  margin: 0 0 -1px 10px;
  padding: 0;
  list-style: none;
}

.ohl-tab {
  appearance: none;
  border: 1px solid #4d5560;
  border-bottom: 1px solid #3c4043;
  background: linear-gradient(180deg, #2b3036, #1d2126);
  color: #e6ebef;
  font-weight: 700;
  font-size: 12px;
  line-height: 1;
  height: 29px;
  padding: 0 14px;
  border-radius: 10px 10px 0 0;
  cursor: pointer;
  white-space: nowrap;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.03);
  opacity: .95;
  transition: filter .12s ease, opacity .12s ease, border-color .12s ease, background .12s ease, color .12s ease;
}

.ohl-tab:hover {
  filter: brightness(1.06);
  opacity: 1;
  color: #ffffff;
  border-color: #6a7380;
}

.ohl-tab.is-active {
  background: linear-gradient(180deg, #1f8f58, #146a42);
  color: #ffffff;
  border-color: #2fb06f;
  border-bottom-color: #2b2b2b;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.18), 0 0 0 1px rgba(14,78,49,.25), 0 1px 0 rgba(0,0,0,.28);
  opacity: 1;
  filter: none;
}

.ohl-tab.is-active:hover {
  filter: none;
  border-color: #37c17a;
}

.ohl-card {
  position: relative;
  z-index: 1;
  background: #2b2b2b;
  border-radius: 18px;
  box-shadow: 0 12px 30px rgba(0,0,0,.55);
  padding: 14px 16px 16px;
}

.ohl-viewer {
  width: 100%;
  min-height: 320px;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid #3c4043;
  background: rgba(0,0,0,.10);
}

.ohl-pane {
  display: none;
  min-height: 320px;
}

.ohl-pane.is-active {
  display: block;
}

@media (max-width: 1600px) {
  .ohl-page {
    width: calc(100% - 28px);
  }
}

@media (max-width: 980px) {
  .ohl-page {
    width: calc(100% - 20px);
    margin-top: 14px;
  }

  .ohl-tabs {
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 2px;
    scrollbar-width: thin;
  }

  .ohl-card {
    padding: 12px;
  }

  .ohl-viewer,
  .ohl-pane {
    min-height: 260px;
  }
}
</style>

<div class="ohl-page">
  <h1 class="ohl-title">OQC 홀딩리스트</h1>

  <div class="ohl-window">
    <div class="ohl-tabs" role="tablist" aria-label="OQC 홀딩리스트 모델 탭">
      <?php foreach ($holdingTabs as $tabKey => $tabLabel): ?>
        <button
          type="button"
          class="ohl-tab<?= $tabKey === $activeHoldingTab ? ' is-active' : '' ?>"
          data-holding-tab="<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>"
          role="tab"
          aria-selected="<?= $tabKey === $activeHoldingTab ? 'true' : 'false' ?>"
          aria-controls="holding-pane-<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>"
          id="holding-tab-<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>"
        ><?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?></button>
      <?php endforeach; ?>
    </div>

    <div class="ohl-card">
      <?php foreach ($holdingTabs as $tabKey => $tabLabel): ?>
        <section
          class="ohl-pane<?= $tabKey === $activeHoldingTab ? ' is-active' : '' ?>"
          id="holding-pane-<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>"
          role="tabpanel"
          aria-labelledby="holding-tab-<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>"
        >
          <div class="ohl-viewer"></div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var root = document.currentScript && document.currentScript.previousElementSibling;
  while (root && !root.classList.contains('ohl-page')) {
    root = root.previousElementSibling;
  }
  if (!root) {
    root = document.querySelector('.ohl-page');
  }
  if (!root) return;

  var tabs = root.querySelectorAll('.ohl-tab');
  var panes = root.querySelectorAll('.ohl-pane');

  function activate(tabKey) {
    tabs.forEach(function (tab) {
      var active = tab.getAttribute('data-holding-tab') === tabKey;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    panes.forEach(function (pane) {
      pane.classList.toggle('is-active', pane.id === 'holding-pane-' + tabKey);
    });
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      activate(tab.getAttribute('data-holding-tab'));
    });
  });
})();
</script>
