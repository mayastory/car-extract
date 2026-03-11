<?php
// app.php : ✅ SHELL(고정) 페이지 - 매트릭스 배경 유지 + 가운데 내용만 iframe 전환
date_default_timezone_set('Asia/Seoul');

session_start();
require_once __DIR__ . '/config/dp_config.php';
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/sidebar.php';
require_once __DIR__ . '/inc/dp_userbar.php';

// 로그인 체크(바깥 쉘에서 1차)
if (empty($_SESSION['ship_user_id'])) {
    header('Location: ' . dp_url('index'));
    exit;
}

// view 결정
$view = $_GET['view'] ?? '';
$uri  = $_SERVER['REQUEST_URI'] ?? '';
if ($view === '') {
    if (preg_match('#/shipinglist/?(\?|$)#', $uri)) $view = 'shipinglist';
    elseif (preg_match('#/rma/?(\?|$)#', $uri)) $view = 'rma';
    elseif (preg_match('#/oqc/?(\?|$)#', $uri)) $view = 'oqc';
    elseif (preg_match('#/ipqc/?(\?|$)#', $uri)) $view = 'ipqc';
    else $view = 'shipinglist';
}

$views = [
  'shipinglist' => ['src' => 'shipinglist_list.php'],
  'rma'         => ['src' => 'RMAlist_list.php'],
  'oqc'         => ['src' => 'oqc_view.php'],
  'ipqc'        => ['src' => 'ipqc_view.php'],
];
if (!isset($views[$view])) $view = 'shipinglist';

// iframe에 넘길 쿼리(현재 querystring 그대로 전달하되 view 제거 + embed=1 추가)
$q = $_GET;
unset($q['view']);
$q['embed'] = '1';
$qs = http_build_query($q);
$iframeSrc = $views[$view]['src'] . ($qs ? ('?' . $qs) : '?embed=1');

// 매트릭스 설정 로드(한 곳에서 관리)
$mb = @include __DIR__ . '/config/matrix_bg.php';
if (!is_array($mb)) {
  $mb = ['enabled'=>true,'text'=>'01','speed'=>1.15,'size'=>16,'zIndex'=>0,'scanlines'=>true,'vignette'=>true];
}

// ✅ 캐시 무효화용 버전
$matrixJsV = @filemtime(__DIR__ . '/assets/matrix-bg.js');
if (!$matrixJsV) $matrixJsV = time();

$shellJsV = @filemtime(__DIR__ . '/assets/dp_shell.js');
if (!$shellJsV) $shellJsV = time();

$unifiedCssV = @filemtime(__DIR__ . '/assets/dp_theme_unified.css');
if (!$unifiedCssV) $unifiedCssV = time();
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>JTMES</title>
  <style>
    html,body{height:100%; margin:0;}
    body{overflow:hidden; background:#202124; position:relative;}
    /* ✅ 좌측 레일(72px) 공간 확보 */
    .dp-shell-wrap{height:100vh; box-sizing:border-box; padding-left:72px; display:flex; flex-direction:column; position:relative; z-index:20;}
    .dp-shell-body{flex:1; min-height:0; overflow:hidden; position:relative; z-index:20;}
    #dpShellFrame{width:100%; height:100%; border:none; display:block; background:transparent; position:relative; z-index:20;}
  </style>

  <script>
    // ✅ SHELL 모드 플래그
    window.DP_SHELL = true;
    window.DP_SHELL_VIEW_MAP = {
      shipinglist: 'shipinglist_list.php',
      rma: 'RMAlist_list.php',
      oqc: 'oqc_view.php',
      ipqc: 'ipqc_view.php'
    };
  </script>
  <!-- ✅ 통합 테마(카드/hover/투명도 통일) -->
  <link rel="stylesheet" href="assets/dp_theme_unified.css?v=<?php echo (int)$unifiedCssV; ?>">
  <script>
    window.DP_UNIFIED_CSS_HREF = "assets/dp_theme_unified.css?v=<?php echo (int)$unifiedCssV; ?>";
  </script>



  <!-- ✅ 매트릭스 배경 (config/matrix_bg.php에서 설정) -->
  <script>
    window.MATRIX_BG = <?php echo json_encode($mb, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  </script>
  <script defer src="assets/matrix-bg.js?v=<?php echo (int)$matrixJsV; ?>"></script>

  <script defer src="assets/dp_shell.js?v=<?php echo (int)$shellJsV; ?>"></script>
</head>
<body>
<?php
echo dp_sidebar_render($view);
?>
<div class="dp-shell-wrap">
  <?php
    echo dp_render_userbar([
      'admin_badge_mode' => 'modal',
      'admin_iframe_src' => 'admin_settings',
      'logout_action'    => 'logout'
    ]);
  ?>
  <div class="dp-shell-body">
    <iframe id="dpShellFrame" src="<?php echo h($iframeSrc); ?>" loading="eager" referrerpolicy="no-referrer"></iframe>
  </div>
</div>
</body>
</html>
