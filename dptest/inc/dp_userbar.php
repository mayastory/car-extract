<?php
require_once __DIR__ . '/common.php';

function dp_userbar_assets_once(): void {
    if (defined('DP_USERBAR_ASSETS_PRINTED')) return;
    define('DP_USERBAR_ASSETS_PRINTED', true);

    $css = __DIR__ . '/../assets/dp_userbar.css';
    $js  = __DIR__ . '/../assets/dp_userbar.js';
    $v1 = file_exists($css) ? filemtime($css) : time();
    $v2 = file_exists($js)  ? filemtime($js)  : time();
    $v  = (string)max($v1, $v2);

    echo '<link rel="stylesheet" href="' . h(dp_url('assets/dp_userbar.css')) . '?v=' . $v . '">';
    echo '<script defer src="' . h(dp_url('assets/dp_userbar.js')) . '?v=' . $v . '"></script>';
}

function dp_render_userbar(array $opt = []): string
{
    $title        = (string)($opt['title'] ?? '');
    $adminMode    = (string)($opt['admin_badge_mode'] ?? 'modal'); // modal|link|none
    $adminHref    = (string)($opt['admin_href'] ?? 'admin_settings');
    $logoutAction = (string)($opt['logout_action'] ?? 'logout');
    $iframeSrc    = (string)($opt['admin_iframe_src'] ?? 'admin_settings');

    $userId   = $_SESSION['ship_user_id'] ?? '';
    $role     = $_SESSION['ship_user_role'] ?? 'user';
    $isAdmin  = ($role === 'admin');

    if ($userId === '') return '';

        $userName = trim((string)($_SESSION['ship_user_name'] ?? ''));
    if ($userName === '') {
        try {
            $pdo = dp_get_pdo();
            if (!empty($_SESSION['ship_user_no'])) {
                $st = $pdo->prepare('SELECT NAME FROM `account` WHERE No = :no LIMIT 1');
                $st->execute([':no' => (int)$_SESSION['ship_user_no']]);
            } else {
                $st = $pdo->prepare('SELECT NAME FROM `account` WHERE ID = :id LIMIT 1');
                $st->execute([':id' => $userId]);
            }
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $n = trim((string)($r['NAME'] ?? ''));
            if ($n !== '') {
                $_SESSION['ship_user_name'] = $n;
                $userName = $n;
            }
        } catch (Throwable $e) {
            // 무시 (표시는 ID로 fallback)
        }
    }

    $adminLink = dp_url($adminHref);
    $logoutAct = dp_url($logoutAction);
    $iframeUrl = dp_url($iframeSrc);

    ob_start(); ?>
<?php dp_userbar_assets_once(); ?>
<div class="dp-ub">
  <div class="dp-ub-left">
    <?php if ($title !== ''): ?>
      <div class="dp-ub-title"><?php echo h($title); ?></div>
    <?php endif; ?>
  </div>

  <div class="dp-ub-right">
    <div class="dp-ub-user">로그인 : <?php echo h($userName !== '' ? $userName : $userId); ?> 님</div>

    <?php if ($isAdmin && $adminMode !== 'none'): ?>
      <?php if ($adminMode === 'link'): ?>
        <a class="dp-ub-badge" href="<?php echo h($adminLink); ?>">관리자</a>
      <?php else: ?>
        <button type="button" class="dp-ub-badge" data-dp-admin-open="1">관리자</button>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="<?php echo h($logoutAct); ?>" class="dp-ub-logout-form">
      <button type="submit" class="dp-ub-logout">로그아웃</button>
    </form>
  </div>
</div>

<?php if ($isAdmin && $adminMode === 'modal'): ?>
  <?php if (!defined('DP_ADMIN_MODAL_PRINTED')): define('DP_ADMIN_MODAL_PRINTED', true); ?>
    <div class="dp-admin-backdrop" id="dpAdminBackdrop" hidden></div>
    <div class="dp-admin-modal" id="dpAdminModal" hidden>
      <div class="dp-admin-top">
        <div class="dp-admin-title">관리자 설정</div>
        <button type="button" class="dp-admin-close" data-dp-admin-close="1">&times;</button>
      </div>
      <iframe class="dp-admin-iframe" id="dpAdminIframe" src="<?php echo h($iframeUrl); ?>" loading="lazy"></iframe>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php
    return ob_get_clean();
}
?>