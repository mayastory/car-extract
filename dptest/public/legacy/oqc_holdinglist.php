<?php
session_start();
require_once dirname(__DIR__, 2) . '/config/dp_config.php';
require_once dirname(__DIR__, 2) . '/inc/common.php';
require_once dirname(__DIR__, 2) . '/inc/sidebar.php';
if (function_exists('dp_require_login')) {
    dp_require_login();
}

$models = [
    'MEM-IR-BASE',
    'MEM-X-CARRIER',
    'MEM-Y-CARRIER',
    'MEM-Z-CARRIER',
    'MEM-Z-STOPPER',
];

$selected = $_GET['model'] ?? $models[0];
if (!in_array($selected, $models, true)) {
    $selected = $models[0];
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>🔐 OQC 홀딩리스트</title>
  <style>
    html,body{margin:0;padding:0;background:#111418;color:#e8edf2;font-family:Segoe UI,Apple SD Gothic Neo,Malgun Gothic,sans-serif}
    .wrap{padding:18px 18px 24px 18px}
    .title{font-size:26px;font-weight:800;margin:0 0 12px 0;letter-spacing:-.2px}
    .subtitle{font-size:13px;color:#9fb0bc;margin:0 0 16px 0}
    .tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px 0}
    .tab{
      display:inline-flex;align-items:center;justify-content:center;
      min-height:34px;padding:0 14px;border-radius:6px 6px 0 0;
      text-decoration:none;font-size:13px;font-weight:700;
      border:1px solid rgba(255,255,255,.12);border-bottom-color:rgba(255,255,255,.2);
      background:#2a2f36;color:#eef3f7;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.06)
    }
    .tab.active{background:#1d9b57;color:#fff;border-color:#5ad08d}
    .viewer{
      border:1px solid rgba(255,255,255,.12);background:#171c21;border-radius:0 10px 10px 10px;
      min-height:420px;box-shadow:0 10px 24px rgba(0,0,0,.24)
    }
    .viewer-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08)}
    .viewer-title{font-size:16px;font-weight:800}
    .viewer-body{padding:18px}
    .placeholder{
      min-height:300px;border:1px dashed rgba(255,255,255,.18);border-radius:10px;
      display:flex;align-items:center;justify-content:center;text-align:center;
      color:#98a9b5;background:rgba(255,255,255,.02);font-size:14px;line-height:1.7
    }
  </style>
</head>
<body>
<?php echo dp_sidebar_render('oqc_holdinglist'); ?>
<div class="wrap">
  <h1 class="title">🔐 OQC 홀딩리스트</h1>
  <p class="subtitle">모델 탭과 뷰어 틀만 먼저 넣었습니다. 내용은 비워두었습니다.</p>

  <div class="tabs">
    <?php foreach ($models as $model): ?>
      <a class="tab<?php echo $model === $selected ? ' active' : ''; ?>" href="<?php echo h(dp_url('oqc_holdinglist.php?model=' . rawurlencode($model))); ?>">
        <?php echo h($model); ?>
      </a>
    <?php endforeach; ?>
  </div>

  <section class="viewer">
    <div class="viewer-head">
      <div class="viewer-title"><?php echo h($selected); ?></div>
    </div>
    <div class="viewer-body">
      <div class="placeholder">
        <div>
          <div style="font-size:15px;font-weight:700;margin-bottom:6px;">뷰어 1개 placeholder</div>
          <div>내용은 비워두었습니다.<br>다음 지시 주시면 이 모델부터 이어서 넣겠습니다.</div>
        </div>
      </div>
    </div>
  </section>
</div>
</body>
</html>
