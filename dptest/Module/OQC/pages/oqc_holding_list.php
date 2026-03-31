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
    *{box-sizing:border-box}
    html,body{height:100%}
    html{background:transparent}
    body{
        margin:0;
        padding:0;
        font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        background:#202124;
        color:#e8eaed;
    }
    body[data-embed="1"]{
        overflow:auto;
        background:transparent;
    }
    .page-wrap{
        width:1550px;
        max-width:calc(100vw - 24px);
        margin:20px auto 40px;
        padding:0 24px;
        box-sizing:border-box;
        position:relative;
    }
    .page-title{
        font-size:20px;
        font-weight:600;
        margin:0 0 10px;
        color:#e8eaed;
    }
    .card-filter{
        background:#2b2b2b;
        border-radius:14px;
        box-shadow:0 8px 20px rgba(0,0,0,.45);
        padding:12px 16px 10px;
        margin-bottom:14px;
    }
    .hold-subtabs{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        align-items:center;
    }
    .hold-subtab{
        appearance:none;
        border:1px solid #3c4043;
        background:#202124;
        color:#e8eaed;
        font-size:12px;
        font-weight:600;
        line-height:1;
        padding:7px 12px;
        border-radius:6px;
        cursor:pointer;
        transition:background-color .12s ease,border-color .12s ease,color .12s ease,box-shadow .12s ease;
    }
    .hold-subtab:hover{
        background:#2a2d31;
    }
    .hold-subtab.active{
        background:#1f6f3f;
        border-color:#2ea043;
        color:#ffffff;
        box-shadow:0 0 0 1px rgba(46,160,67,.18) inset;
    }
    .card-list{
        background:#2b2b2b;
        border-radius:18px;
        box-shadow:0 12px 30px rgba(0,0,0,.55);
        padding:14px 16px 16px;
    }
    .top-bar{
        display:flex;
        justify-content:space-between;
        align-items:center;
        margin-bottom:6px;
        min-height:22px;
    }
    .top-bar-title{
        font-size:13px;
        font-weight:600;
        color:#e8eaed;
    }
    .table-wrap{
        margin-top:6px;
        border-radius:12px;
        overflow:hidden;
        border:1px solid #3c4043;
        background:#202124;
    }
    .hold-panel{display:none}
    .hold-panel.active{display:block}
    .hold-viewer{
        min-height:650px;
        background:#202124;
    }
    @media (max-width: 900px){
        .page-wrap{
            width:1550px;
            max-width:calc(100vw - 12px);
            margin:12px auto 24px;
            padding:0 10px;
        }
        .page-title{
            font-size:18px;
            margin-bottom:8px;
        }
        .card-filter{
            padding:10px 12px 9px;
            margin-bottom:12px;
        }
        .card-list{
            padding:12px 12px 0;
        }
        .hold-subtabs{gap:6px}
        .hold-subtab{padding:7px 10px}
        .hold-viewer{min-height:520px}
    }
</style>
</head>
<body data-embed="<?= $embed ? '1' : '0' ?>">
<div class="page-wrap">
    <h1 class="page-title">OQC 홀딩리스트</h1>

    <section class="card-filter" aria-label="OQC 홀딩리스트 모델 탭">
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
    </section>

    <section class="card-list" aria-label="OQC 홀딩리스트 뷰어">
        <div class="top-bar">
            <div class="top-bar-title"></div>
        </div>

        <div class="table-wrap">
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
