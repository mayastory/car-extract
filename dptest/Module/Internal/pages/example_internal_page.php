<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }


// example_internal_page.php : 공용 메뉴/유저바 적용 예시
require_once JTMES_ROOT . '/inc/common.php';
dp_require_login();

require_once JTMES_ROOT . '/inc/sidebar.php';
require_once JTMES_ROOT . '/inc/dp_userbar.php';
?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>Example</title>
  <style>
    body{margin:0;background:#202124;color:#e8eaed;}
    .dp-page{padding-left:72px; box-sizing:border-box;}
    .box{padding:22px;}
    a{color:#8ab4f8;}
  </style>
</head>
<body>
<?php
echo dp_sidebar_render('shipinglist');
echo dp_render_userbar(['admin_badge_mode'=>'modal','admin_iframe_src'=>'admin_settings','logout_action'=>'logout']);
?>
<div class="dp-page">
  <div class="box">
    <h2>Example Internal Page</h2>
    <p>메뉴가 페이지 이동해도 사라지지 않게 하려면, 내부 페이지마다 sidebar/userbar를 include 해야 함.</p>
    <p><a href="<?php echo h(dp_url('shipinglist')); ?>">QA 출하내역으로 이동</a></p>
  </div>
</div>
</body>
</html>
