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
.page{min-height:100%;padding:<?= $embed ? '10px 10px 14px' : '16px' ?>;overflow:auto;}
.page-title{margin:0 0 10px;font-size:14px;font-weight:700;color:#e8eaed;}
.hold-subtabs{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 10px;}
.hold-subtab{appearance:none;border:1px solid #2b313a;background:linear-gradient(180deg,#272c35 0%,#1f2430 100%);color:#e8eaed;font-weight:700;font-size:12px;padding:8px 14px;border-radius:6px 6px 0 0;cursor:pointer;line-height:1;box-shadow:inset 0 1px 0 rgba(255,255,255,.06);}
.hold-subtab:hover{filter:brightness(1.05);}
.hold-subtab.active{border-color:#2e7d32;background:linear-gradient(180deg,#1f5530 0%,#1a4628 100%);color:#fff;box-shadow:inset 0 1px 0 rgba(255,255,255,.14),0 0 0 1px rgba(46,125,50,.16);}
.hold-panel{display:none;}
.hold-panel.active{display:block;}
.hold-viewer{min-height:320px;border:1px solid #34383d;border-radius:0 8px 8px 8px;background:linear-gradient(180deg,rgba(39,44,53,.96) 0%,rgba(24,28,34,.96) 100%);box-shadow:inset 0 1px 0 rgba(255,255,255,.03),0 1px 3px rgba(0,0,0,.18);}
</style>
</head>
<body data-embed="<?= $embed ? '1' : '0' ?>">
<div class="page">
    <?php if (!$embed): ?>
        <h1 class="page-title">OQC 홀딩리스트</h1>
    <?php endif; ?>

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
