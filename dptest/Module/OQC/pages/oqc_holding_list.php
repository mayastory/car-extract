<?php
if (!defined('JTMES_ROOT')) {
    $cands = [
        realpath(dirname(__DIR__, 3) ?: ''),
        realpath(dirname(__DIR__, 2) ?: ''),
        realpath(dirname(__DIR__, 1) ?: ''),
        realpath(__DIR__),
    ];
    foreach ($cands as $cand) {
        if ($cand && is_dir($cand . '/config')) {
            define('JTMES_ROOT', $cand);
            break;
        }
    }
    if (!defined('JTMES_ROOT')) {
        define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
    }
}

date_default_timezone_set('Asia/Seoul');
session_start();
require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/inc/common.php';

if (empty($_SESSION['ship_user_id'])) {
    header('Location: ' . dp_url('index'));
    exit;
}

$embed = (($_GET['embed'] ?? '') === '1');
$tabs = [
    'MEM-IR-BASE',
    'MEM-X-CARRIER',
    'MEM-Y-CARRIER',
    'MEM-Z-CARRIER',
    'MEM-Z-STOPPER',
];
$defaultTab = $tabs[0];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OQC 홀딩리스트</title>
<style>
html,body{height:100%;margin:0;background:<?= $embed ? '#202124' : '#f3f4f6' ?>;color:#111;font-family:Arial,"Malgun Gothic","맑은 고딕",sans-serif;font-size:12px;}
body[data-embed="1"]{overflow:hidden;}
.page{min-height:100%;box-sizing:border-box;padding:<?= $embed ? '18px 18px 22px' : '20px' ?>;overflow:auto;}
.page-title{margin:0 0 12px;font-size:14px;font-weight:700;color:#e8eaed;}
.hold-window{width:min(100%,1400px);background:rgba(27,31,38,.96);border:1px solid #30363d;border-radius:12px;box-shadow:0 18px 40px rgba(0,0,0,.24), inset 0 1px 0 rgba(255,255,255,.03);overflow:hidden;}
.hold-window-head{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.05);background:linear-gradient(180deg,rgba(38,45,58,.98) 0%,rgba(29,34,43,.98) 100%);}
.hold-window-dot{width:10px;height:10px;border-radius:50%;background:#2e7d32;box-shadow:0 0 0 1px rgba(255,255,255,.08) inset;flex:0 0 auto;}
.hold-window-title{font-size:12px;font-weight:700;letter-spacing:.01em;color:#e8eaed;}
.hold-window-body{padding:12px 12px 14px;}
.hold-subtabs{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 12px;}
.hold-subtab{appearance:none;border:1px solid #2b313a;background:linear-gradient(180deg,#272c35 0%,#1f2430 100%);color:#e8eaed;font-weight:700;font-size:12px;padding:8px 14px;border-radius:6px 6px 0 0;cursor:pointer;line-height:1;box-shadow:inset 0 1px 0 rgba(255,255,255,.06);}
.hold-subtab:hover{filter:brightness(1.05);}
.hold-subtab.active{border-color:#2e7d32;background:linear-gradient(180deg,#1f5530 0%,#1a4628 100%);color:#fff;box-shadow:inset 0 1px 0 rgba(255,255,255,.14),0 0 0 1px rgba(46,125,50,.16);}
.hold-panel{display:none;}
.hold-panel.active{display:block;}
.hold-viewer{min-height:340px;border:1px solid #34383d;border-radius:0 10px 10px 10px;background:linear-gradient(180deg,rgba(39,44,53,.96) 0%,rgba(24,28,34,.96) 100%);box-shadow:inset 0 1px 0 rgba(255,255,255,.03),0 1px 3px rgba(0,0,0,.18);}
@media (max-width: 900px){
  .page{padding:<?= $embed ? '12px 12px 16px' : '14px' ?>;}
  .hold-window-body{padding:10px;}
  .hold-viewer{min-height:300px;}
}
</style>
</head>
<body data-embed="<?= $embed ? '1' : '0' ?>">
<div class="page">
    <?php if (!$embed): ?>
        <h1 class="page-title">OQC 홀딩리스트</h1>
    <?php endif; ?>

    <section class="hold-window" aria-label="OQC 홀딩리스트 창">
        <div class="hold-window-head">
            <span class="hold-window-dot" aria-hidden="true"></span>
            <div class="hold-window-title">OQC 홀딩리스트</div>
        </div>

        <div class="hold-window-body">
            <div class="hold-subtabs" role="tablist" aria-label="OQC 홀딩리스트 모델 탭">
                <?php foreach ($tabs as $tab): ?>
                    <button
                        type="button"
                        class="hold-subtab<?= $tab === $defaultTab ? ' active' : '' ?>"
                        data-hold-panel="<?= h($tab) ?>"
                        aria-selected="<?= $tab === $defaultTab ? 'true' : 'false' ?>"
                    ><?= h($tab) ?></button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($tabs as $tab): ?>
                <section class="hold-panel<?= $tab === $defaultTab ? ' active' : '' ?>" data-hold-panel-id="<?= h($tab) ?>">
                    <div class="hold-viewer" aria-label="<?= h($tab) ?> placeholder viewer"></div>
                </section>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<script>
(function(){
    const tabs = Array.from(document.querySelectorAll('.hold-subtab'));
    const panels = Array.from(document.querySelectorAll('.hold-panel'));
    function activate(id){
        tabs.forEach(btn => {
            const on = btn.getAttribute('data-hold-panel') === id;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(panel => {
            panel.classList.toggle('active', panel.getAttribute('data-hold-panel-id') === id);
        });
    }
    tabs.forEach(btn => btn.addEventListener('click', () => activate(btn.getAttribute('data-hold-panel'))));
})();
</script>
</body>
</html>
