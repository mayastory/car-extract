<?php
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$models = [
    'MEM-IR-BASE',
    'MEM-X-CARRIER',
    'MEM-Y-CARRIER',
    'MEM-Z-CARRIER',
    'MEM-Z-STOPPER',
];

$currentModel = trim((string)($_GET['model'] ?? 'MEM-IR-BASE'));
if (!in_array($currentModel, $models, true)) {
    $currentModel = 'MEM-IR-BASE';
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OQC 홀딩리스트</title>
<style>
html, body {
    margin: 0;
    padding: 0;
    background: transparent !important;
    color: #e8edf3;
    font-family: inherit;
}

body {
    min-height: 100%;
}

.oqc-holding-page {
    padding: 18px 18px 28px;
    background: transparent;
}

.oqc-holding-window {
    max-width: 1280px;
}

.oqc-holding-title {
    margin: 0 0 10px;
    font-size: 15px;
    font-weight: 700;
    color: #f0f4f8;
    letter-spacing: 0.2px;
}

.folder-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    align-items: flex-end;
    margin: 0 0 -1px;
    padding: 0 10px;
    position: relative;
    z-index: 2;
}

.folder-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 30px;
    padding: 0 12px;
    border: 1px solid rgba(109, 124, 150, 0.72);
    border-bottom: 0;
    border-radius: 6px 6px 0 0;
    background: linear-gradient(180deg, rgba(55, 65, 84, 0.96) 0%, rgba(28, 36, 49, 0.96) 100%);
    color: #f5f8fb;
    text-decoration: none;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    white-space: nowrap;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.06);
}

.folder-tab:hover {
    color: #ffffff;
    background: linear-gradient(180deg, rgba(68, 78, 98, 0.98) 0%, rgba(36, 45, 60, 0.98) 100%);
}

.folder-tab.active {
    border-color: rgba(138, 221, 154, 0.92);
    background: linear-gradient(180deg, rgba(43, 159, 79, 0.98) 0%, rgba(31, 121, 58, 0.98) 100%);
    color: #ffffff;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.16);
}

.folder-pane {
    position: relative;
    border: 1px solid rgba(77, 95, 122, 0.7);
    border-radius: 0 12px 12px 12px;
    background: linear-gradient(180deg, rgba(20, 24, 31, 0.88) 0%, rgba(24, 27, 35, 0.84) 100%);
    box-shadow:
        0 10px 28px rgba(0, 0, 0, 0.26),
        inset 0 1px 0 rgba(255,255,255,0.03);
    padding: 14px;
    backdrop-filter: blur(1px);
}

.placeholder-viewer {
    min-height: 430px;
    border: 1px solid rgba(56, 70, 90, 0.9);
    border-radius: 8px;
    background: linear-gradient(90deg, rgba(14, 19, 28, 0.95) 0%, rgba(29, 36, 49, 0.96) 52%, rgba(14, 19, 28, 0.95) 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
}

@media (max-width: 900px) {
    .oqc-holding-page {
        padding: 14px 14px 24px;
    }

    .folder-tabs {
        padding: 0 6px;
    }

    .folder-tab {
        font-size: 10px;
        padding: 0 10px;
    }

    .placeholder-viewer {
        min-height: 340px;
    }
}
</style>
</head>
<body>
<div class="oqc-holding-page">
    <div class="oqc-holding-window">
        <h1 class="oqc-holding-title">OQC 홀딩리스트</h1>

        <div class="folder-tabs" role="tablist" aria-label="OQC 홀딩리스트 모델 탭">
            <?php foreach ($models as $model): ?>
                <a
                    class="folder-tab<?= $model === $currentModel ? ' active' : '' ?>"
                    href="?model=<?= rawurlencode($model) ?>"
                    role="tab"
                    aria-selected="<?= $model === $currentModel ? 'true' : 'false' ?>"
                ><?= h($model) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="folder-pane">
            <div class="placeholder-viewer" aria-label="<?= h($currentModel) ?> placeholder viewer"></div>
        </div>
    </div>
</div>
</body>
</html>
