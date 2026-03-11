<?php
// inc/sidebar.php : 좌측 레일 + 메뉴 패널
// ✅ 이 파일이 CSS/JS를 1회 자동 출력(페이지마다 추가 안 해도 됨)
require_once __DIR__ . '/common.php';

function dp_sidebar_assets_once(): void {
    if (defined('DP_SIDEBAR_ASSETS_PRINTED')) return;
    define('DP_SIDEBAR_ASSETS_PRINTED', true);
    $css = __DIR__ . '/../assets/dp_sidebar.css';
    $js  = __DIR__ . '/../assets/dp_sidebar.js';
    $v1 = file_exists($css) ? filemtime($css) : time();
    $v2 = file_exists($js)  ? filemtime($js)  : time();
    $v  = (string)max($v1, $v2);
    echo '<link rel="stylesheet" href="' . h(dp_url('assets/dp_sidebar.css')) . '?v=' . $v . '">';
    echo '<script defer src="' . h(dp_url('assets/dp_sidebar.js')) . '?v=' . $v . '"></script>';
}

function dp_sidebar_render(string $active = ''): string
{
    // 지금은 QA 출하내역 1개만
	$items = [
		[
			'key'   => 'shipinglist',
			'title' => 'QA 출하내역',
			'href'  => dp_url('shipinglist'),   // pretty url (fallback)
			'nav'   => '/shipinglist',          // dp_shell.js용 (페이지 리로드 없이 iframe만 전환)
			'icon'  => '📦',
		],
		[
			'key'   => 'rma',
			'title' => 'RMA 내역',
			'href'  => dp_url('rma'),          // pretty url (fallback)
			'nav'   => '/rma',                 // dp_shell.js용
			'icon'  => '🧾',
		],
		[
			'key'   => 'oqc',
			'title' => 'OQC 측정 데이터 조회',
			'href'  => dp_url('oqc'),          // pretty url (fallback)
			'nav'   => '/oqc',                 // dp_shell.js용
			'icon'  => '📏',
		],
		[
			'key'   => 'ipqc',
			'title' => 'JMP Assist (IPQC)',
			'href'  => dp_url('ipqc'),         // pretty url (fallback)
			'nav'   => '/ipqc',                // dp_shell.js용
			'icon'  => '📊',
		],
	];

    ob_start();
    dp_sidebar_assets_once();
    ?>

<!-- 좌측 레일 -->
<div class="dp-rail" aria-label="JTMES navigation rail">
  <a class="dp-rail-btn" href="<?= h(dp_url('shipinglist')) ?>" data-dp-view="shipinglist" data-dp-nav="/shipinglist" title="QA 출하내역">📦</a>
  <a class="dp-rail-btn" href="<?= h(dp_url('ipqc')) ?>" data-dp-view="ipqc" data-dp-nav="/ipqc" title="JMP Assist (IPQC)">📊</a>
  <button class="dp-rail-btn" type="button" data-dp-open="1" title="메뉴">≡</button>
</div>

<!-- 오버레이 메뉴 -->
<div class="dp-side-backdrop" id="dpSideBackdrop" hidden></div>
<aside class="dp-side" id="dpSidePanel" hidden>
  <div class="dp-side-head">
    <div class="dp-side-brand">JTMES</div>
    <button class="dp-side-close" type="button" data-dp-close="1" aria-label="닫기">&times;</button>
  </div>

  <nav class="dp-side-nav">
    <?php foreach ($items as $it):
      $isOn = ($active === $it['key']);
    ?>
      <a class="dp-side-item <?= $isOn ? 'active' : '' ?>" href="<?= h($it['href']) ?>" data-dp-view="<?= h($it['key']) ?>" data-dp-nav="<?= h($it['nav']) ?>">
        <span class="ico"><?php echo h($it['icon']); ?></span>
        <span class="txt"><?php echo h($it['title']); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<?php
    return ob_get_clean();
}
