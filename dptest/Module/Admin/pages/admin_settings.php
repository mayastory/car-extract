<?php
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }



require_once JTMES_ROOT . '/inc/common.php';

dp_require_login();

$role = $_SESSION['ship_user_role'] ?? '';
if ($role !== 'admin') {
    http_response_code(403);
    echo "<!doctype html><html lang='ko'><meta charset='utf-8'><body style='margin:0;background:#202124;color:#e8eaed;font-family:system-ui;padding:18px'>관리자 전용 페이지입니다.</body></html>";
    exit;
}

/* security headers for admin page (best-effort) */
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $pdo = dp_get_pdo();
} catch (PDOException $e) {
    http_response_code(500);
    echo "<!doctype html><html lang='ko'><meta charset='utf-8'><body style='margin:0;background:#202124;color:#e8eaed;font-family:system-ui;padding:18px'>DB 접속 실패: " . h($e->getMessage()) . "</body></html>";
    exit;
}

$msg = '';

$csrfToken = $_SESSION['_csrf_admin_settings'] ?? '';
if (!is_string($csrfToken) || $csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['_csrf_admin_settings'] = $csrfToken;
}

// ─────────────────────────────
// 접속 정책(Allow/Deny) 테이블 헬퍼
// ─────────────────────────────
if (!function_exists('adm_acl__table_exists')) {
    function adm_acl__table_exists(PDO $pdo, string $table): bool {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE :t");
            $st->execute([':t' => $table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('adm_acl__columns')) {
    function adm_acl__columns(PDO $pdo, string $table): array {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            $cols = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
            $out = [];
            foreach ($cols as $c) {
                if (!empty($c['Field'])) $out[] = (string)$c['Field'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('adm_acl__safe_table')) {
    function adm_acl__safe_table(string $t): ?string {
        $t = trim($t);
        $allowed = ['ip_allow','ip_deny','country_allow','country_deny'];
        return in_array($t, $allowed, true) ? $t : null;
    }
}

if (!function_exists('adm_acl__value_col')) {
    function adm_acl__value_col(string $table, array $cols): string {
        if (strpos($table, 'ip_') === 0) {
            if (in_array('ip', $cols, true)) return 'ip';
            if (in_array('pattern', $cols, true)) return 'pattern';
            return 'ip';
        }
        if (in_array('country_code', $cols, true)) return 'country_code';
        if (in_array('code', $cols, true)) return 'code';
        return 'country_code';
    }
}

if (!function_exists('adm_acl__read_rows')) {
    function adm_acl__read_rows(PDO $pdo, string $table): array {
        try {
            return $pdo->query("SELECT * FROM `{$table}` ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            try {
                return $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e2) {
                return [];
            }
        }
    }
}

if (!function_exists('adm_acl__wildcard_hint')) {
    function adm_acl__wildcard_hint(string $type): string {
        if ($type === 'ip') return "예: 220.74.*.* / 220.74.62.141 / 2001:db8:*";
        return "예: KR / US / JP / *";
    }
}


// ─────────────────────────────
// POST 처리
// ─────────────────────────────
$mode = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string)($_POST['mode'] ?? '');
$csrfIn = (string)($_POST['csrf'] ?? '');
if ($csrfIn === '' || !hash_equals($csrfToken, $csrfIn)) {
    $msg = '보안 토큰(CSRF) 오류: 새로고침 후 다시 시도하세요.';
    $mode = '__csrf_invalid__';
}


    if ($mode === 'approve_user') {
        $no = (int)($_POST['no'] ?? 0);
        if ($no > 0) {
            $st = $pdo->prepare("UPDATE `account` SET status='approved' WHERE No=:no AND status='pending'");
            $st->execute([':no' => $no]);
            $msg = ($st->rowCount() > 0) ? '승인 완료' : '대상 계정이 없거나 이미 처리됨';
        }
    } elseif ($mode === 'reject_user') {
        $no = (int)($_POST['no'] ?? 0);
        if ($no > 0) {
            $st = $pdo->prepare("UPDATE `account` SET status='rejected' WHERE No=:no AND status='pending'");
            $st->execute([':no' => $no]);
            $msg = ($st->rowCount() > 0) ? '거절 처리 완료' : '대상 계정이 없거나 이미 처리됨';
        }
    } elseif ($mode === 'change_password') {
        $no = (int)($_POST['no'] ?? 0);
        $newPw = trim($_POST['new_pw'] ?? '');
        if ($no > 0 && $newPw !== '') {
            $st = $pdo->prepare("UPDATE `account` SET PW=:pw WHERE No=:no");
            $st->execute([':pw' => password_hash($newPw, PASSWORD_DEFAULT), ':no' => $no]);
            $msg = ($st->rowCount() > 0) ? '비밀번호 변경 완료' : '변경 실패(대상 없음)';
        } else {
            $msg = '대상 계정/새 비밀번호를 입력하세요.';
        }

    } elseif ($mode === 'acl_add') {
        $table = adm_acl__safe_table((string)($_POST['table'] ?? ''));
        $value = trim((string)($_POST['value'] ?? ''));
        $note  = trim((string)($_POST['note'] ?? ''));
        $enabled = (int)($_POST['enabled'] ?? 1);

        if ($table === null) {
            $msg = '잘못된 요청(table)';
        } elseif ($value === '') {
            $msg = '값을 입력하세요.';
        } elseif (!adm_acl__table_exists($pdo, $table)) {
            $msg = "테이블이 없습니다: {$table}";
        } else {
            $cols = adm_acl__columns($pdo, $table);
            $valCol = adm_acl__value_col($table, $cols);

            // 입력 정리
            if (strpos($table, 'country_') === 0) {
                $value = strtoupper($value);
                $value = preg_replace('/[^A-Z\*]/', '', $value);
                if ($value === '') {
                    $msg = '국가 코드는 KR/US 처럼 영문(또는 *)만 허용합니다.';
                }
            } else {
                if (strlen($value) > 64) $msg = 'IP 패턴 길이가 너무 깁니다(최대 64자).';
            }

            if ($msg === '') {
                $fields = [$valCol];
                $holders = [':v'];
                $params = [':v' => $value];

                if (in_array('enabled', $cols, true)) {
                    $fields[] = 'enabled';
                    $holders[] = ':en';
                    $params[':en'] = ($enabled ? 1 : 0);
                }
                if (in_array('note', $cols, true)) {
                    $fields[] = 'note';
                    $holders[] = ':note';
                    $params[':note'] = $note;
                }

                $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(fn($x)=>"`{$x}`", $fields)) . ") VALUES (" . implode(',', $holders) . ")";
                try {
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $msg = '추가 완료';
                } catch (Throwable $e) {
                    $msg = '추가 실패: ' . $e->getMessage();
                }
            }
        }

    } elseif ($mode === 'acl_toggle') {
        $table = adm_acl__safe_table((string)($_POST['table'] ?? ''));
        $id = (int)($_POST['id'] ?? 0);
        $enabled = (int)($_POST['enabled'] ?? 0);

        if ($table === null || $id <= 0) {
            $msg = '잘못된 요청';
        } elseif (!adm_acl__table_exists($pdo, $table)) {
            $msg = "테이블이 없습니다: {$table}";
        } else {
            $cols = adm_acl__columns($pdo, $table);
            if (!in_array('enabled', $cols, true)) {
                $msg = 'enabled 컬럼이 없어 토글할 수 없습니다.';
            } else {
                try {
                    $st = $pdo->prepare("UPDATE `{$table}` SET enabled=:en WHERE id=:id");
                    $st->execute([':en' => ($enabled ? 1 : 0), ':id' => $id]);
                    $msg = '저장 완료';
                } catch (Throwable $e) {
                    $msg = '저장 실패: ' . $e->getMessage();
                }
            }
        }

    } elseif ($mode === 'acl_delete') {
        $table = adm_acl__safe_table((string)($_POST['table'] ?? ''));
        $id = (int)($_POST['id'] ?? 0);

        if ($table === null || $id <= 0) {
            $msg = '잘못된 요청';
        } elseif (!adm_acl__table_exists($pdo, $table)) {
            $msg = "테이블이 없습니다: {$table}";
        } else {
            try {
                $st = $pdo->prepare("DELETE FROM `{$table}` WHERE id=:id");
                $st->execute([':id' => $id]);
                $msg = '삭제 완료';
            } catch (Throwable $e) {
                $msg = '삭제 실패: ' . $e->getMessage();
            }
        }
    }
}

// ─────────────────────────────
// 데이터 조회

// ─────────────────────────────
$pending = [];
$accounts = [];
try {
    $pending = $pdo->query("SELECT No, ID, role, status, last_login_at FROM `account` WHERE status='pending' ORDER BY No DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pending = [];
}
try {
    $accounts = $pdo->query("SELECT No, ID, role, status, last_login_at FROM `account` ORDER BY No DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts = [];
}


// ─────────────────────────────
// 접속 정책(Allow/Deny) 데이터
// ─────────────────────────────
$aclTables = [
    'ip_allow' => 'IP 허용',
    'ip_deny' => 'IP 차단',
    'country_allow' => '국가 허용',
    'country_deny' => '국가 차단',
];

$aclExists = [];
$aclCols = [];
$aclValueCol = [];
$aclRows = [];
$aclEnabledCount = [];

foreach ($aclTables as $t => $_label) {
    $aclExists[$t] = adm_acl__table_exists($pdo, $t);
    $aclCols[$t] = $aclExists[$t] ? adm_acl__columns($pdo, $t) : [];
    $aclValueCol[$t] = $aclExists[$t] ? adm_acl__value_col($t, $aclCols[$t]) : (strpos($t, 'ip_') === 0 ? 'ip' : 'country_code');
    $aclRows[$t] = $aclExists[$t] ? adm_acl__read_rows($pdo, $t) : [];
    $aclEnabledCount[$t] = 0;
    foreach ($aclRows[$t] as $r) {
        if (!array_key_exists('enabled', $r) || (string)$r['enabled'] !== '0') $aclEnabledCount[$t]++;
    }
}

// ─────────────────────────────
// 초기 탭 결정 (POST 후에도 같은 탭 유지)
// ─────────────────────────────
$activeTab = 'approve';
$qTab = (string)($_GET['tab'] ?? '');
$qTab = preg_replace('/[^a-z]/', '', strtolower($qTab));
if (in_array($qTab, ['approve','pw','acl'], true)) {
    $activeTab = $qTab;
} else {
    if ($mode === 'change_password') {
        $activeTab = 'pw';
    } elseif (strpos($mode, 'acl_') === 0) {
        $activeTab = 'acl';
    } else {
        $activeTab = 'approve';
    }
}


$activeAclTab = 'ip_allow';
$qAcl = (string)($_GET['acl_tab'] ?? '');
$qAcl = preg_replace('/[^a-z_]/', '', strtolower($qAcl));
$allowedAclTabs = ['ip_allow','ip_deny','country_allow','country_deny'];

if (in_array($qAcl, $allowedAclTabs, true)) {
    $activeAclTab = $qAcl;
} else {
    $pAcl = (string)($_POST['acl_tab'] ?? '');
    $pAcl = preg_replace('/[^a-z_]/', '', strtolower($pAcl));
    if (in_array($pAcl, $allowedAclTabs, true)) {
        $activeAclTab = $pAcl;
    } else {
        if (strpos($mode, 'acl_') === 0) {
            $t = (string)($_POST['table'] ?? '');
            $t = preg_replace('/[^a-z_]/', '', strtolower($t));
            if (in_array($t, $allowedAclTabs, true)) $activeAclTab = $t;
        }
    }
}

?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>관리자 설정</title>



  <style>
    :root{
      --bg:#202124;
      --card:#2b2b2b;
      --card2:#303134;
      --txt:#e8eaed;
      --mut:#9aa0a6;
      --line:rgba(255,255,255,.08);
      --blue:#4c8bf5;
      --red:#f28b82;
    }
    html,body{height:100%;}
    body{
      margin:0;
      background:var(--bg);
      color:var(--txt);
      font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    }
    .wrap{padding:16px 16px 24px;}

    .tabs{display:flex; gap:10px; margin-bottom:12px;}
    .tab-btn{
      border:1px solid var(--line);
      background:var(--card2);
      color:var(--txt);
      padding:8px 12px;
      border-radius:999px;
      cursor:pointer;
      font-size:13px;
    }
    .tab-btn.active{background:var(--blue); border-color:transparent; color:#fff; font-weight:700;}

    .pill{display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); background:rgba(255,255,255,.03); color:#cfd3d7;}
    .pill-on{background:rgba(52,168,83,.18); border-color:rgba(52,168,83,.35); color:#d7ffe1;}
    .pill-off{background:rgba(242,139,130,.12); border-color:rgba(242,139,130,.35); color:#ffe1df;}
    .grid2{display:grid; grid-template-columns:1fr 1fr; gap:12px;}
    @media (max-width: 860px){ .grid2{grid-template-columns:1fr;} }
    .mini{font-size:12px; color:var(--mut);}
    .danger{color:var(--red);}

    .msg{min-height:18px; color:#ffe352; font-size:12px; margin:6px 0 10px; white-space:pre-line;}

    .panel{display:none;}
    .panel.active{display:block;}

    .card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:16px;
      padding:14px;
      box-shadow:0 10px 26px rgba(0,0,0,.35);
    }

    table{width:100%; border-collapse:collapse; font-size:13px;}
    th,td{padding:9px 8px; border-bottom:1px solid rgba(255,255,255,.06); text-align:left;}
    th{color:#cfd3d7; font-weight:700; background:rgba(255,255,255,.03); position:sticky; top:0;}

    .btn{
      border:none; border-radius:10px;
      padding:7px 10px; cursor:pointer;
      font-size:12px; font-weight:700;
    }
    .btn-ok{background:#34a853; color:#08140c;}
    .btn-no{background:var(--red); color:#1a0908;}
    .btn-blue{background:var(--blue); color:#fff;}

    .row{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
    .inp{
      height:34px; border-radius:10px;
      border:1px solid rgba(255,255,255,.14);
      background:#1f1f1f; color:var(--txt);
      padding:0 10px;
    }
    .hint{color:var(--mut); font-size:12px; margin-top:8px;}
  
/* ACL inner tabs */
.subtabs{display:flex; gap:8px; flex-wrap:wrap; margin:12px 0 10px;}
.subtab-btn{
  border:1px solid var(--line);
  background:#1f1f1f;
  color:var(--txt);
  padding:7px 10px;
  border-radius:999px;
  cursor:pointer;
  font-size:12px;
  font-weight:800;
  opacity:.9;
}
.subtab-btn.active{background:rgba(76,139,245,.18); border-color:rgba(76,139,245,.35); opacity:1;}
.acl-panel{display:none;}
.acl-panel.active{display:block;}
.table-wrap{overflow:auto; max-height:340px; border-radius:12px; border:1px solid rgba(255,255,255,.06);}
.table-wrap table{min-width:520px;}
.acl-actions{display:flex; gap:8px; align-items:center; justify-content:flex-end; flex-wrap:wrap;}

    /* 제로보드 느낌: 좌측 메뉴 + 우측 컨텐츠 */
    .adm-layout{height:100%; display:flex; min-height:0;}
    .adm-nav{
      width: 210px;
      min-width: 210px;
      background: rgba(10, 18, 14, 0.72);
      border-right: 1px solid var(--line);
      padding: 14px 12px;
      box-sizing:border-box;
      display:flex;
      flex-direction:column;
      gap:10px;
      overflow:auto;
    }
    .adm-nav-title{
      font-weight:900;
      letter-spacing:.3px;
      margin:2px 0 10px;
      font-size:14px;
      color: var(--txt);
      opacity:.95;
    }
    .adm-nav-btn{
      width:100%;
      text-align:left;
      border:1px solid var(--line);
      background: rgba(0,0,0,.18);
      color: var(--txt);
      padding:10px 12px;
      border-radius:12px;
      cursor:pointer;
      font-size:13px;
      font-weight:800;
    }
    .adm-nav-btn:hover{background: rgba(255,255,255,.06);}
    .adm-nav-btn.active{background: var(--blue); border-color: transparent; color:#fff;}
    .adm-nav-foot{
      margin-top:auto;
      border-top:1px solid rgba(255,255,255,.06);
      padding-top:10px;
      display:flex;
      flex-direction:column;
      gap:6px;
    }
    .adm-main{
      flex:1;
      min-width:0;
      min-height:0;
      overflow:auto;
      padding:16px 16px 22px;
      box-sizing:border-box;
    }
    .wrap{padding:0;} /* legacy */

</style>
</head>
<body>
  <div class="adm-layout">
    <aside class="adm-nav">
      <div class="adm-nav-title">관리자 설정</div>

      <button class="adm-nav-btn <?php echo ($activeTab==='approve')?'active':''; ?>" data-tab="approve">가입 승인</button>
      <button class="adm-nav-btn <?php echo ($activeTab==='pw')?'active':''; ?>" data-tab="pw">비밀번호 변경</button>
      <button class="adm-nav-btn <?php echo ($activeTab==='acl')?'active':''; ?>" data-tab="acl">접속 정책</button>
    </aside>

    <main class="adm-main">
      <div class="msg"><?php echo $msg ? h($msg) : ''; ?></div>

    <!-- 가입 승인 -->
    <section class="panel <?php echo ($activeTab==='approve')?'active':''; ?>" id="tab-approve">
      <div class="card">
        <?php if (!$pending): ?>
          <div class="hint">가입 승인 대기 중인 계정이 없습니다.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th style="width:70px">No</th>
                <th>ID</th>
                <th style="width:110px">role</th>
                <th style="width:110px">status</th>
                <th style="width:160px">last_login</th>
                <th style="width:180px">처리</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($pending as $u): ?>
              <tr>
                <td><?php echo h((string)$u['No']); ?></td>
                <td><?php echo h((string)$u['ID']); ?></td>
                <td><?php echo h((string)($u['role'] ?? '')); ?></td>
                <td><?php echo h((string)($u['status'] ?? '')); ?></td>
                <td><?php echo h((string)($u['last_login_at'] ?? '')); ?></td>
                <td>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="mode" value="approve_user">
                    <input type="hidden" name="no" value="<?php echo h((string)$u['No']); ?>">
                    <button class="btn btn-ok" type="submit">승인</button>
                  </form>
                  <form method="post" style="display:inline; margin-left:6px">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                    
                    <input type="hidden" name="mode" value="reject_user">
                    <input type="hidden" name="no" value="<?php echo h((string)$u['No']); ?>">
                    <button class="btn btn-no" type="submit">거절</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- 비밀번호 변경 -->
    <section class="panel <?php echo ($activeTab==='pw')?'active':''; ?>" id="tab-pw">
      <div class="card">
        <div class="row" style="margin-bottom:10px">
          <form method="post" class="row">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                    
            <input type="hidden" name="mode" value="change_password">
            <select class="inp" name="no" required>
              <option value="">계정 선택</option>
              <?php foreach ($accounts as $u): ?>
                <option value="<?php echo h((string)$u['No']); ?>"><?php echo h((string)$u['ID']); ?> (No=<?php echo h((string)$u['No']); ?>)</option>
              <?php endforeach; ?>
            </select>
            <input class="inp" type="text" name="new_pw" placeholder="새 비밀번호" required>
            <button class="btn btn-blue" type="submit">변경</button>
          </form>
        </div>
</div>
    </section>


    <!-- 접속 정책 (IP/국가 Allow/Deny) -->
    <section class="panel <?php echo ($activeTab==='acl')?'active':''; ?>" id="tab-acl">
      <div class="card" style="margin-bottom:12px">
        <div class="row" style="justify-content:space-between">
          <div>
            <div style="font-weight:800; font-size:14px;">접속 정책</div>
          </div>
          <div class="row" style="gap:8px">
            <span class="pill <?php echo ($aclEnabledCount['ip_allow']>0) ? 'pill-on' : 'pill-off'; ?>">IP allow 활성: <?php echo (int)$aclEnabledCount['ip_allow']; ?></span>
            <span class="pill <?php echo ($aclEnabledCount['country_allow']>0) ? 'pill-on' : 'pill-off'; ?>">Country allow 활성: <?php echo (int)$aclEnabledCount['country_allow']; ?></span>
          </div>
        </div>
      </div>

      <div id="aclBox" class="card">
  <div class="acl-actions" style="margin-bottom:8px"></div>

  <div class="subtabs">
    <button class="subtab-btn" data-acl="ip_allow">IP 허용</button>
    <button class="subtab-btn" data-acl="ip_deny">IP 차단</button>
    <button class="subtab-btn" data-acl="country_allow">국가 허용</button>
    <button class="subtab-btn" data-acl="country_deny">국가 차단</button>
  </div>


    <div class="acl-panel" id="acl-ip_allow">
      <div style="font-weight:900; font-size:14px; margin-bottom:2px;">IP 허용</div>
      <?php if (!$aclExists['ip_allow']): ?>
      <div class="mini danger">ip_allow 테이블이 없습니다.</div>
    <?php endif; ?>
      
      
    <div class="row" style="margin:10px 0 6px">
      <div style="font-weight:800">IP 허용 추가</div>
      <div class="mini"><?php echo h(adm_acl__wildcard_hint('ip')); ?></div>
    </div>
    <form method="post" class="row" style="margin-bottom:10px">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
      <input type="hidden" name="mode" value="acl_add">
      <input type="hidden" name="table" value="ip_allow">
      <input type="hidden" name="acl_tab" value="ip_allow">
      <input class="inp" name="value" placeholder="220.74.*.*" style="min-width:210px" required>
      <input class="inp" name="note" placeholder="메모(선택)" style="min-width:180px">
      <select class="inp" name="enabled">
        <option value="1">enabled</option>
        <option value="0">disabled</option>
      </select>
      <button class="btn btn-blue" type="submit">추가</button>
    </form>

      
    <?php if ($aclExists['ip_allow']): ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:56px">id</th>
              <th>ip</th>
              <th style="width:90px">enabled</th>
              <th>note</th>
              <th style="width:160px">action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($aclRows['ip_allow'] as $r):
              $rid = (int)($r['id'] ?? 0);
              $val = (string)($r[$aclValueCol['ip_allow']] ?? '');
              $en  = (!array_key_exists('enabled',$r) || (string)$r['enabled'] !== '0');
              $note = (string)($r['note'] ?? '');
            ?>
            <tr>
              <td><?php echo h((string)$rid); ?></td>
              <td><?php echo h($val); ?></td>
              <td>
                <?php if (array_key_exists('enabled',$r)): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="mode" value="acl_toggle">
                    <input type="hidden" name="table" value="ip_allow">
                    <input type="hidden" name="acl_tab" value="ip_allow">
                    <input type="hidden" name="id" value="<?php echo h((string)$rid); ?>">
                    <input type="hidden" name="enabled" value="<?php echo $en ? '0' : '1'; ?>">
                    <button class="btn <?php echo $en ? 'btn-ok' : 'btn-no'; ?>" type="submit"><?php echo $en ? 'ON' : 'OFF'; ?></button>
                  </form>
                <?php else: ?>
                  <span class="pill">(n/a)</span>
                <?php endif; ?>
              </td>
              <td><?php echo h($note); ?></td>
              <td>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="mode" value="acl_delete">
                  <input type="hidden" name="table" value="ip_allow">
                  <input type="hidden" name="acl_tab" value="ip_allow">
                  <input type="hidden" name="id" value="<?php echo h((string)$rid); ?>">
                  <button class="btn btn-no" type="submit" onclick="return confirm('삭제할까요?');">삭제</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    </div>

    <div class="acl-panel" id="acl-ip_deny">
      <div style="font-weight:900; font-size:14px; margin-bottom:2px;">IP 차단</div>
      <?php if (!$aclExists['ip_deny']): ?>
      <div class="mini danger">ip_deny 테이블이 없습니다.</div>
    <?php endif; ?>
      
      
    <div class="row" style="margin:10px 0 6px">
      <div style="font-weight:800">IP 차단 추가</div>
      <div class="mini"><?php echo h(adm_acl__wildcard_hint('ip')); ?></div>
    </div>
    <form method="post" class="row" style="margin-bottom:10px">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
      <input type="hidden" name="mode" value="acl_add">
      <input type="hidden" name="table" value="ip_deny">
      <input type="hidden" name="acl_tab" value="ip_deny">
      <input class="inp" name="value" placeholder="220.74.*.*" style="min-width:210px" required>
      <input class="inp" name="note" placeholder="메모(선택)" style="min-width:180px">
      <select class="inp" name="enabled">
        <option value="1">enabled</option>
        <option value="0">disabled</option>
      </select>
      <button class="btn btn-blue" type="submit">추가</button>
    </form>

      
    <?php if ($aclExists['ip_deny']): ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:56px">id</th>
              <th>ip</th>
              <th style="width:90px">enabled</th>
              <th>note</th>
              <th style="width:160px">action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($aclRows['ip_deny'] as $r):
              $rid = (int)($r['id'] ?? 0);
              $val = (string)($r[$aclValueCol['ip_deny']] ?? '');
              $en  = (!array_key_exists('enabled',$r) || (string)$r['enabled'] !== '0');
              $note = (string)($r['note'] ?? '');
            ?>
            <tr>
              <td><?php echo h((string)$rid); ?></td>
              <td><?php echo h($val); ?></td>
              <td>
                <?php if (array_key_exists('enabled',$r)): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="mode" value="acl_toggle">
                    <input type="hidden" name="table" value="ip_deny">
                    <input type="hidden" name="acl_tab" value="ip_deny">
                    <input type="hidden" name="id" value="<?php echo h((string)$rid); ?>">
                    <input type="hidden" name="enabled" value="<?php echo $en ? '0' : '1'; ?>">
                    <button class="btn <?php echo $en ? 'btn-ok' : 'btn-no'; ?>" type="submit"><?php echo $en ? 'ON' : 'OFF'; ?></button>
                  </form>
                <?php else: ?>
                  <span class="pill">(n/a)</span>
                <?php endif; ?>
              </td>
              <td><?php echo h($note); ?></td>
              <td>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="mode" value="acl_delete">
                  <input type="hidden" name="table" value="ip_deny">
                  <input type="hidden" name="acl_tab" value="ip_deny">
                  <input type="hidden" name="id" value="<?php echo h((string)$rid); ?>">
                  <button class="btn btn-no" type="submit" onclick="return confirm('삭제할까요?');">삭제</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    </div>

    <div class="acl-panel" id="acl-country_allow">
      <div style="font-weight:900; font-size:14px; margin-bottom:2px;">국가 허용</div>
      <?php if (!$aclExists['country_allow']): ?>
      <div class="mini danger">country_allow 테이블이 없습니다.</div>
    <?php endif; ?>
      
      
    <div class="row" style="margin:10px 0 6px">
      <div style="font-weight:800">국가 허용 추가</div>
      <div class="mini"><?php echo h(adm_acl__wildcard_hint('country')); ?></div>
    </div>
    <form method="post" class="row" style="margin-bottom:10px">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
      <input type="hidden" name="mode" value="acl_add">
      <input type="hidden" name="table" value="country_allow">
      <input type="hidden" name="acl_tab" value="country_allow">
      <input class="inp" name="value" placeholder="KR" style="min-width:160px" required>
      <input class="inp" name="note" placeholder="메모(선택)" style="min-width:180px">
      <select class="inp" name="enabled">
        <option value="1">enabled</option>
        <option value="0">disabled</option>
      </select>
      <button class="btn btn-blue" type="submit">추가</button>
    </form>

      
    <?php if ($aclExists['country_allow']): ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:56px">id</th>
              <th>code</th>
              <th style="width:90px">enabled</th>
              <th>note</th>
              <th style="width:160px">action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($aclRows['country_allow'] as $r):
              $rid = (int)($r['id'] ?? 0);
              $val = (string)($r[$aclValueCol['country_allow']] ?? '');
              $en  = (!array_key_exists('enabled',$r) || (string)$r['enabled'] !== '0');
              $note = (string)($r['note'] ?? '');
            ?>
            <tr>
              <td><?php echo h((string)$rid); ?></td>
              <td><?php echo h(strtoupper($val)); ?></td>
              <td>
                <?php if (array_key_exists('enabled',$r)): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="mode" value="acl_toggle">
                    <input type="hidden" name="table" value="country_allow">
                    <input type="hidden" name="acl_tab" value="country_allow">
                    <input type="hidden" name="id" value="<?php echo h((string)$rid); ?>">
                    <input type="hidden" name="enabled" value="<?php echo $en ? '0' : '1'; ?>">
                    <button class="btn <?php echo $en ? 'btn-ok' : 'btn-no'; ?>" type="submit"><?php echo $en ? 'ON' : 'OFF'; ?></button>
                  </form>
                <?php else: ?>
                  <span class="pill">(n/a)</span>
                <?php endif; ?>
              </td>
              <td><?php echo h($note); ?></td>
              <td>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="mode" value="acl_delete">
                  <input type="hidden" name="table" value="country_allow">
                  <input type="hidden" name="acl_tab" value="country_allow">
                  <input type="hidden" name="id" value="<?php echo h((string)$rid); ?>">
                  <button class="btn btn-no" type="submit" onclick="return confirm('삭제할까요?');">삭제</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    </div>

    <div class="acl-panel" id="acl-country_deny">
      <div style="font-weight:900; font-size:14px; margin-bottom:2px;">국가 차단</div>
      <?php if (!$aclExists['country_deny']): ?>
      <div class="mini danger">country_deny 테이블이 없습니다.</div>
    <?php endif; ?>
      
      
    <div class="row" style="margin:10px 0 6px">
      <div style="font-weight:800">국가 차단 추가</div>
      <div class="mini"><?php echo h(adm_acl__wildcard_hint('country')); ?></div>
    </div>
    <form method="post" class="row" style="margin-bottom:10px">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
      <input type="hidden" name="mode" value="acl_add">
      <input type="hidden" name="table" value="country_deny">
      <input type="hidden" name="acl_tab" value="country_deny">
      <input class="inp" name="value" placeholder="US" style="min-width:160px" required>
      <input class="inp" name="note" placeholder="메모(선택)" style="min-width:180px">
      <select class="inp" name="enabled">
        <option value="1">enabled</option>
        <option value="0">disabled</option>
      </select>
      <button class="btn btn-blue" type="submit">추가</button>
    </form>

      
    <?php if ($aclExists['country_deny']): ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:56px">id</th>
              <th>code</th>
              <th style="width:90px">enabled</th>
              <th>note</th>
              <th style="width:160px">action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($aclRows['country_deny'] as $r):
              $rid = (int)($r['id'] ?? 0);
              $val = (string)($r[$aclValueCol['country_deny']] ?? '');
              $en  = (!array_key_exists('enabled',$r) || (string)$r['enabled'] !== '0');
              $note = (string)($r['note'] ?? '');
            ?>
            <tr>
              <td><?php echo h((string)$rid); ?></td>
              <td><?php echo h(strtoupper($val)); ?></td>
              <td>
                <?php if (array_key_exists('enabled',$r)): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="mode" value="acl_toggle">
                    <input type="hidden" name="table" value="country_deny">
                    <input type="hidden" name="acl_tab" value="country_deny">
                    <input type="hidden" name="id" value="<?php echo h((string)$rid); ?>">
                    <input type="hidden" name="enabled" value="<?php echo $en ? '0' : '1'; ?>">
                    <button class="btn <?php echo $en ? 'btn-ok' : 'btn-no'; ?>" type="submit"><?php echo $en ? 'ON' : 'OFF'; ?></button>
                  </form>
                <?php else: ?>
                  <span class="pill">(n/a)</span>
                <?php endif; ?>
              </td>
              <td><?php echo h($note); ?></td>
              <td>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="mode" value="acl_delete">
                  <input type="hidden" name="table" value="country_deny">
                  <input type="hidden" name="acl_tab" value="country_deny">
                  <input type="hidden" name="id" value="<?php echo h((string)$rid); ?>">
                  <button class="btn btn-no" type="submit" onclick="return confirm('삭제할까요?');">삭제</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    </div>

      </div>

    </section>


    </main>
  </div>

  <script>
(function(){
  const btns = Array.from(document.querySelectorAll('.adm-nav-btn'));
  const panels = {
    approve: document.getElementById('tab-approve'),
    pw: document.getElementById('tab-pw'),
    acl: document.getElementById('tab-acl')
  };

  function activate(key){
    btns.forEach(x=>x.classList.remove('active'));
    btns.forEach(x=>{
      if (x.getAttribute('data-tab') === key) x.classList.add('active');
    });
    Object.values(panels).forEach(p=>p && p.classList.remove('active'));
    if (panels[key]) panels[key].classList.add('active');
  }

  // hash(#pw/#acl/#approve) 우선, 없으면 서버가 지정한 탭
  const hashKey = (location.hash || '').replace('#','');
  const serverKey = <?php echo json_encode($activeTab, JSON_UNESCAPED_UNICODE); ?>;
  const initKey = (hashKey && panels[hashKey]) ? hashKey : serverKey;

  activate(initKey);

  btns.forEach(b=>{
    b.addEventListener('click', (e)=>{
      e.preventDefault();
      const key = b.getAttribute('data-tab');
      if (!key || !panels[key]) return;
      activate(key);
      try { history.replaceState(null, '', '#'+key); } catch(_) {}
    });
  });
})();


// ACL inner tabs
(function(){
  const subBtns = Array.from(document.querySelectorAll('.subtab-btn'));
  const panels = {
    ip_allow: document.getElementById('acl-ip_allow'),
    ip_deny: document.getElementById('acl-ip_deny'),
    country_allow: document.getElementById('acl-country_allow'),
    country_deny: document.getElementById('acl-country_deny'),
  };

  function setAclTab(key){
    subBtns.forEach(b=>b.classList.remove('active'));
    subBtns.forEach(b=>{ if (b.getAttribute('data-acl') === key) b.classList.add('active'); });
    Object.values(panels).forEach(p=>p && p.classList.remove('active'));
    if (panels[key]) panels[key].classList.add('active');

    try {
      const u = new URL(location.href);
      u.searchParams.set('tab','acl');
      u.searchParams.set('acl_tab', key);
      // 유지: 메인 탭 hash
      if (!u.hash) u.hash = '#acl';
      history.replaceState(null,'',u.toString());
    } catch(_) {}
  }

  const serverAcl = <?php echo json_encode($activeAclTab, JSON_UNESCAPED_UNICODE); ?>;
  let init = serverAcl;
  try {
    const u = new URL(location.href);
    const q = (u.searchParams.get('acl_tab') || '').toLowerCase();
    if (q && panels[q]) init = q;
  } catch(_) {}

  setAclTab(init);

  subBtns.forEach(b=>{
    b.addEventListener('click', (e)=>{
      e.preventDefault();
      const key = b.getAttribute('data-acl');
      if (!key || !panels[key]) return;
      setAclTab(key);
    });
  });
})();
</script>
</body>
</html>