<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/dp_level_icon.php';

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

if (!function_exists('dp_userbar_status_table_exists')) {
    function dp_userbar_status_table_exists(PDO $pdo, string $tableName): bool
    {
        static $cache = [];
        $key = spl_object_hash($pdo) . '|' . $tableName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $st = $pdo->prepare(
                'SELECT 1
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_name = :table
                  LIMIT 1'
            );
            $st->execute([':table' => $tableName]);
            $cache[$key] = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

if (!function_exists('dp_userbar_fetch_uploader_items')) {
    /**
     * Read-only uploader status chips for the top userbar.
     *
     * Expected DB table (graceful no-op when missing):
     * - module
     * - status
     * - pid
     * - host_name
     * - last_seen
     * - current_file
     * - last_message
     * - updated_at
     */
    function dp_userbar_fetch_uploader_items(array $modules = ['OQC', 'IPQC', 'SHIP'], int $staleAfterSec = 45): array
    {
        $modules = array_values(array_unique(array_filter(array_map(static function ($v): string {
            return strtoupper(trim((string)$v));
        }, $modules), static function ($v): bool {
            return $v !== '';
        })));

        if (!$modules) {
            return [];
        }

        try {
            $pdo = dp_get_pdo();
            if (!($pdo instanceof PDO)) {
                return [];
            }
            if (!dp_userbar_status_table_exists($pdo, 'uploader_status')) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($modules), '?'));
            $sql = "SELECT module, status, pid, host_name, last_seen, current_file, last_message, updated_at
                      FROM uploader_status
                     WHERE UPPER(module) IN ($placeholders)";
            $st = $pdo->prepare($sql);
            $st->execute($modules);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        if (!$rows) {
            return [];
        }

        $byModule = [];
        foreach ($rows as $row) {
            $mod = strtoupper(trim((string)($row['module'] ?? '')));
            if ($mod !== '') {
                $byModule[$mod] = $row;
            }
        }

        $now = time();
        $items = [];

        foreach ($modules as $mod) {
            if (!isset($byModule[$mod])) {
                continue;
            }

            $row = $byModule[$mod];
            $statusRaw = strtoupper(trim((string)($row['status'] ?? '')));
            $lastSeenRaw = trim((string)($row['last_seen'] ?? $row['updated_at'] ?? ''));
            $lastSeenTs = $lastSeenRaw !== '' ? strtotime($lastSeenRaw) : false;
            $isStale = !$lastSeenTs || (($now - $lastSeenTs) > max(5, $staleAfterSec));

            $tone = 'off';
            $text = 'OFF';

            if (in_array($statusRaw, ['ERROR', 'ERR', 'FAIL', 'FAILED'], true)) {
                $tone = 'error';
                $text = 'ERR';
            } elseif (!$isStale && in_array($statusRaw, ['RUNNING', 'RUN', 'IDLE', 'WATCH', 'WATCHING', 'ACTIVE', 'OK'], true)) {
                $tone = 'on';
                $text = 'ON';
            }

            $titleParts = [];
            $titleParts[] = $mod . ' · ' . $text;
            if ($statusRaw !== '') {
                $titleParts[] = 'Status: ' . $statusRaw;
            }
            if ($lastSeenRaw !== '') {
                $titleParts[] = 'Last seen: ' . $lastSeenRaw;
            }
            if (!empty($row['pid'])) {
                $titleParts[] = 'PID: ' . (string)$row['pid'];
            }
            if (!empty($row['host_name'])) {
                $titleParts[] = 'Host: ' . (string)$row['host_name'];
            }

            $items[] = [
                'module' => $mod,
                'tone' => $tone,
                'text' => $text,
                'title' => implode("\n", $titleParts),
            ];
        }

        return $items;
    }
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

    $userLv = isset($_SESSION['ship_user_lv']) && $_SESSION['ship_user_lv'] !== '' ? (int)$_SESSION['ship_user_lv'] : null;
    $userName = trim((string)($_SESSION['ship_user_name'] ?? ''));

    // 이름이 이미 세션에 있어도 lv가 없으면 다시 조회해야 아이콘이 뜬다.
    if ($userName === '' || $userLv === null) {
        try {
            $pdo = dp_get_pdo();
            if (!empty($_SESSION['ship_user_no'])) {
                $st = $pdo->prepare('SELECT NAME, lv FROM `account` WHERE No = :no LIMIT 1');
                $st->execute([':no' => (int)$_SESSION['ship_user_no']]);
            } else {
                $st = $pdo->prepare('SELECT NAME, lv FROM `account` WHERE ID = :id LIMIT 1');
                $st->execute([':id' => $userId]);
            }
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $n = trim((string)($r['NAME'] ?? ''));
            if (isset($r['lv']) && $r['lv'] !== '') {
                $_SESSION['ship_user_lv'] = (int)$r['lv'];
                $userLv = (int)$r['lv'];
            }
            if ($n !== '') {
                $_SESSION['ship_user_name'] = $n;
                $userName = $n;
            }
        } catch (Throwable $e) {
            // 무시 (표시는 ID로 fallback)
        }
    }

    $userIdentityHtml = dp_render_level_identity_html($userName !== '' ? $userName : $userId, $userLv, ['width' => 24, 'height' => 16, 'gap' => 4, 'class' => 'dp-level-identity']);

    $adminLink = dp_url($adminHref);
    $logoutAct = dp_url($logoutAction);
    $iframeUrl = dp_url($iframeSrc);

    $defaultShowUploaderStatus = $isAdmin || ($userLv !== null && $userLv >= 1);
    $showUploaderStatus = (bool)($opt['show_uploader_status'] ?? $defaultShowUploaderStatus);
    $uploaderModules = $opt['uploader_modules'] ?? ['OQC', 'IPQC', 'SHIP'];
    $uploaderStaleAfter = (int)($opt['uploader_stale_after_sec'] ?? 45);
    $uploaderItems = $showUploaderStatus ? dp_userbar_fetch_uploader_items((array)$uploaderModules, $uploaderStaleAfter) : [];

    ob_start(); ?>
<?php dp_userbar_assets_once(); ?>
<div class="dp-ub">
  <div class="dp-ub-left">
    <?php if ($title !== ''): ?>
      <div class="dp-ub-title"><?php echo h($title); ?></div>
    <?php endif; ?>
  </div>

  <div class="dp-ub-right">
    <?php if ($uploaderItems): ?>
      <div class="dp-ub-statuses" aria-label="Uploader status">
        <?php foreach ($uploaderItems as $item): ?>
          <div class="dp-ub-status-item is-<?php echo h($item['tone']); ?>" title="<?php echo h($item['title']); ?>">
            <span class="dp-ub-status-name"><?php echo h($item['module']); ?></span>
            <span class="dp-ub-status-toggle" aria-hidden="true">
              <span class="dp-ub-status-text"><?php echo h($item['text']); ?></span>
              <span class="dp-ub-status-knob"></span>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="dp-ub-user">로그인 : <?php echo $userIdentityHtml; ?> 님</div>

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
