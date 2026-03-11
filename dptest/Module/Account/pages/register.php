<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }


// register.php : 회원가입 전용 페이지 (iframe / 단독접속 둘 다 가능)
session_start();
require_once JTMES_ROOT . '/config/dp_config.php';

// 디버그용
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$registerMessage = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $regName = trim($_POST['reg_name'] ?? '');
    $regId  = trim($_POST['reg_id'] ?? '');
    $regPw1 = trim($_POST['reg_pw'] ?? '');
    $regPw2 = trim($_POST['reg_pw2'] ?? '');

    if ($regName === '' || $regId === '' || $regPw1 === '') {
        $registerMessage = "아이디/이름/비밀번호를 모두 입력하세요.";
    } elseif ($regPw1 !== $regPw2) {
        $registerMessage = "비밀번호가 서로 일치하지 않습니다.";
    } else {
        try {
            $pdo = dp_get_pdo();
        } catch (PDOException $e) {
            $registerMessage = "DB 접속 실패: " . $e->getMessage();
        }

        if ($registerMessage === '') {
            // 아이디 중복 체크
            $stmt = $pdo->prepare("SELECT 1 FROM `account` WHERE ID = :id LIMIT 1");
            $stmt->execute([':id' => $regId]);
            if ($stmt->fetch()) {
                $registerMessage = "이미 사용 중인 아이디입니다.";
            } else {
                // 기본값 : role=user, status=pending
                $stmt = $pdo->prepare("
                    INSERT INTO `account` (NAME, ID, PW, role, status)
                    VALUES (:name, :id, :pw, 'user', 'pending')
                ");
                $stmt->execute([
                    ':name' => $regName,
                    ':id'   => $regId,
                    ':pw'   => password_hash($regPw1, PASSWORD_DEFAULT),
                ]);

                $success = true;
                $registerMessage = "가입 신청이 등록되었습니다.\n관리자가 승인하면 로그인할 수 있습니다.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>회원가입</title>
    <style>
        html, body {
            margin:0;
            padding:0;
            background:#202124;
            color:#f1f3f4;
            font-family:system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .wrap {
            padding:10px 14px 8px;
            box-sizing:border-box;
        }
        .reg-title {
            font-size:15px;
            font-weight:600;
            margin-bottom:8px;
        }
        label {
            display:block;
            margin-bottom:4px;
            font-size:12px;
        }
        input[type="text"],
        input[type="password"] {
            width:100%;
            padding:7px 9px;
            border-radius:999px;
            border:1px solid #555;
            background:#1f1f1f;
            color:#f1f3f4;
            box-sizing:border-box;
            font-size:13px;
            margin-bottom:8px;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline:none;
            border-color:#8ab4f8;
            box-shadow:0 0 0 1px #8ab4f8;
        }
        .btn {
            width:100%;
            padding:8px 0;
            border-radius:999px;
            border:none;
            background:#3c8dff;
            color:#fff;
            font-weight:600;
            font-size:13px;
            cursor:pointer;
            margin-top:4px;
        }
        .btn:hover { background:#5a9eff; }

        .msg {
            margin-top:6px;
            font-size:11px;
            white-space:pre-line;
        }
        .msg.ok  { color:#81c995; }
        .msg.err { color:#f28b82; }

        .hint {
            margin-top:4px;
            font-size:11px;
            color:#9aa0a6;
            white-space:pre-line;
        }

        .btn-close-parent {
            margin-top:6px;
            padding:5px 0;
            width:100%;
            border-radius:999px;
            border:1px solid #555;
            background:#303134;
            color:#e8eaed;
            font-size:11px;
            cursor:pointer;
        }
        .btn-close-parent:hover { background:#3c4043; }
    </style>
</head>
<body>
<div class="wrap">

    <form method="post">
        <label for="reg_id">아이디</label>
        <input type="text" id="reg_id" name="reg_id" required value="<?php echo h($_POST['reg_id'] ?? ''); ?>">

        <label for="reg_name">이름</label>
        <input type="text" id="reg_name" name="reg_name" required value="<?php echo h($_POST['reg_name'] ?? ''); ?>">

        <label for="reg_pw">비밀번호</label>
        <input type="password" id="reg_pw" name="reg_pw" required>

        <label for="reg_pw2">비밀번호 확인</label>
        <input type="password" id="reg_pw2" name="reg_pw2" required>

        <button type="submit" class="btn">가입 신청</button>
    </form>

    <?php if ($registerMessage !== ''): ?>
        <div class="msg <?php echo $success ? 'ok' : 'err'; ?>">
            <?php echo nl2br(h($registerMessage)); ?>
        </div>
    <?php else: ?>
        <div class="hint">
이름/아이디/비밀번호를 입력 후 가입 신청을 하면,
관리자가 승인한 뒤부터 로그인할 수 있습니다.
        </div>
    <?php endif; ?>

    <!-- index.php 에서 iframe으로 띄웠을 때 닫기 버튼 -->
    <button type="button" class="btn-close-parent" onclick="
        if (window.parent && typeof window.parent.closeSignupModal === 'function') {
            window.parent.closeSignupModal();
        }
    ">
        창 닫기
    </button>
</div>

<script>
(function(){
    function sendHeight(){
        try {
            var wrap = document.querySelector('.wrap');
            var h = (wrap && wrap.getBoundingClientRect)
                ? Math.ceil(wrap.getBoundingClientRect().height)
                : Math.max(
                    document.body ? document.body.scrollHeight : 0,
                    document.documentElement ? document.documentElement.scrollHeight : 0
                );
            // 같은 도메인: 부모 함수가 있으면 직접 호출
            if (window.parent && window.parent !== window && typeof window.parent.resizeSignupFrame === 'function') {
                window.parent.resizeSignupFrame(h);
            } else if (window.parent && window.parent !== window) {
                window.parent.postMessage({type:'signupFrameHeight', height:h}, '*');
            }
        } catch(e){}
    }
    window.addEventListener('load', function(){
        sendHeight();
        setTimeout(sendHeight, 50);
        setTimeout(sendHeight, 250);
    });
    window.addEventListener('resize', sendHeight);

    // 내용이 바뀌는 경우(메시지 표시 등)도 다시 측정
    try {
        var mo = new MutationObserver(function(){ sendHeight(); });
        mo.observe(document.body, {subtree:true, childList:true, characterData:true});
    } catch(e){}
})();
</script>

</body>
</html>
