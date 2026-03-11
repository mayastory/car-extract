<?php
// admin_settings.php : (iframe용) 관리자 설정 - 가입 승인 / 비밀번호 변경

require_once __DIR__ . '/inc/common.php';

dp_require_login();

$role = $_SESSION['ship_user_role'] ?? '';
if ($role !== 'admin') {
    http_response_code(403);
    echo "<!doctype html><html lang='ko'><meta charset='utf-8'><body style='margin:0;background:#202124;color:#e8eaed;font-family:system-ui;padding:18px'>관리자 전용 페이지입니다.</body></html>";
    exit;
}

try {
    $pdo = dp_get_pdo();
} catch (PDOException $e) {
    http_response_code(500);
    echo "<!doctype html><html lang='ko'><meta charset='utf-8'><body style='margin:0;background:#202124;color:#e8eaed;font-family:system-ui;padding:18px'>DB 접속 실패: " . h($e->getMessage()) . "</body></html>";
    exit;
}

$msg = '';

// ─────────────────────────────
// POST 처리
// ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'approve_user') {
        $no = (int)($_POST['no'] ?? 0);
        if ($no > 0) {
            $st = $pdo->prepare("UPDATE Account SET status='approved' WHERE No=:no AND status='pending'");
            $st->execute([':no' => $no]);
            $msg = ($st->rowCount() > 0) ? '승인 완료' : '대상 계정이 없거나 이미 처리됨';
        }
    } elseif ($mode === 'reject_user') {
        $no = (int)($_POST['no'] ?? 0);
        if ($no > 0) {
            $st = $pdo->prepare("UPDATE Account SET status='rejected' WHERE No=:no AND status='pending'");
            $st->execute([':no' => $no]);
            $msg = ($st->rowCount() > 0) ? '거절 처리 완료' : '대상 계정이 없거나 이미 처리됨';
        }
    } elseif ($mode === 'change_password') {
        $no = (int)($_POST['no'] ?? 0);
        $newPw = trim($_POST['new_pw'] ?? '');
        if ($no > 0 && $newPw !== '') {
            // ※ 현재 프로젝트는 평문 PW 사용(기존 규칙 유지)
            $st = $pdo->prepare("UPDATE Account SET PW=:pw WHERE No=:no");
            $st->execute([':pw' => $newPw, ':no' => $no]);
            $msg = ($st->rowCount() > 0) ? '비밀번호 변경 완료' : '변경 실패(대상 없음)';
        } else {
            $msg = '대상 계정/새 비밀번호를 입력하세요.';
        }
    }
}

// ─────────────────────────────
// 데이터 조회
// ─────────────────────────────
$pending = [];
$accounts = [];
try {
    $pending = $pdo->query("SELECT No, ID, role, status, last_login_at FROM Account WHERE status='pending' ORDER BY No DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pending = [];
}
try {
    $accounts = $pdo->query("SELECT No, ID, role, status, last_login_at FROM Account ORDER BY No DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts = [];
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
  </style>
</head>
<body>
  <div class="wrap">

    <div class="tabs">
      <button class="tab-btn active" data-tab="approve">가입 승인</button>
      <button class="tab-btn" data-tab="pw">비밀번호 변경</button>
    </div>

    <div class="msg"><?php echo $msg ? h($msg) : ''; ?></div>

    <!-- 가입 승인 -->
    <section class="panel active" id="tab-approve">
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
                    <input type="hidden" name="mode" value="approve_user">
                    <input type="hidden" name="no" value="<?php echo h((string)$u['No']); ?>">
                    <button class="btn btn-ok" type="submit">승인</button>
                  </form>
                  <form method="post" style="display:inline; margin-left:6px">
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
    <section class="panel" id="tab-pw">
      <div class="card">
        <div class="row" style="margin-bottom:10px">
          <form method="post" class="row">
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
        <div class="hint">※ 현재 로그인 로직이 평문 PW 비교 방식이므로, 여기서도 그대로 저장합니다.</div>
      </div>
    </section>

  </div>

  <script>
    (function(){
      const btns = document.querySelectorAll('.tab-btn');
      const panels = {
        approve: document.getElementById('tab-approve'),
        pw: document.getElementById('tab-pw')
      };
      btns.forEach(b=>{
        b.addEventListener('click', ()=>{
          btns.forEach(x=>x.classList.remove('active'));
          b.classList.add('active');
          Object.values(panels).forEach(p=>p.classList.remove('active'));
          const key = b.getAttribute('data-tab');
          if (panels[key]) panels[key].classList.add('active');
        });
      });
    })();
  </script>
</body>
</html>
