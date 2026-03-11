<?php
// inc/layout_top.php : (표준) 공용 헤더/레이아웃 시작
// 내부 페이지에서:
//   $PAGE_TITLE, $ACTIVE_MENU, $ENABLE_MATRIX_BG 설정 후 include

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/dp_userbar.php';
require_once __DIR__ . '/sidebar.php';

dp_require_login();

$PAGE_TITLE = $PAGE_TITLE ?? 'DPTest';
$ACTIVE_MENU = $ACTIVE_MENU ?? 'shipinglist';
$ENABLE_MATRIX_BG = $ENABLE_MATRIX_BG ?? true;

// matrix 설정(필요시 페이지에서 $MATRIX_BG 로 덮어쓰기)
$MATRIX_BG = $MATRIX_BG ?? [
    'enabled'   => true,
    'text'      => '',     // ✅ 비우면 가타카나/숫자/기호 랜덤 (기능 유지)
    'speed'     => 1.15,
    'size'      => 16,
    'zIndex'    => 0,
    'scanlines' => true,
    'vignette'  => true
];

?><!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h($PAGE_TITLE); ?></title>

  <!-- 공용 CSS -->
  <link rel="stylesheet" href="<?php echo h(dp_url('assets/dp_userbar.css')); ?>">
  <link rel="stylesheet" href="<?php echo h(dp_url('assets/dp_sidebar.css')); ?>">

  <!-- 최소 공용 스타일 -->
  <style>
    body{
      margin:0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:#202124;
      color:#e8eaed;
    }
    /* 좌측 레일(72px) 공간 확보 */
    .page-wrap{ padding-left: 86px; box-sizing:border-box; }
  </style>

  <?php if ($ENABLE_MATRIX_BG): ?>
    <script>
      window.MATRIX_BG = <?php echo json_encode($MATRIX_BG, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    </script>
  <?php endif; ?>
</head>
<body>

<?php echo dp_sidebar_render($ACTIVE_MENU); ?>
<?php echo dp_render_userbar(['admin_badge_mode'=>'modal','logout_action'=>'logout']); ?>

<div class="page-wrap">
