<?php
// index.php : 로그인 화면 + 회원가입 팝업
session_start();
require_once __DIR__ . '/config/dp_config.php';

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// 이미 로그인 되어 있으면 출하내역으로 이동 (깔끔 URL)
if (!empty($_SESSION['ship_user_id'])) {
    header('Location: shipinglist');
    exit;
}

$loginMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputId = trim($_POST['user_id'] ?? '');
    $pwInput = trim($_POST['password'] ?? '');

    if ($inputId === '' || $pwInput === '') {
        $loginMessage = "아이디와 비밀번호를 모두 입력하세요.";
    } else {
        try {
            $pdo = dp_get_pdo();
        } catch (PDOException $e) {
            $loginMessage = "DB 접속 실패: " . $e->getMessage();
        }

        if ($loginMessage === '') {
            $stmt = $pdo->prepare("
                SELECT No, ID, PW, role, status
                  FROM Account
                 WHERE ID = :id
                 LIMIT 1
            ");
            $stmt->execute([':id' => $inputId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || $pwInput !== $row['PW']) {
                $loginMessage = "아이디 또는 비밀번호가 올바르지 않습니다.";
            } else {
                if ($row['status'] === 'pending') {
                    $loginMessage = "가입 승인 대기 중인 계정입니다.\n관리자 승인 후 로그인 가능합니다.";
                } elseif ($row['status'] === 'rejected') {
                    $loginMessage = "승인 거절된 계정입니다.\n관리자에게 문의하세요.";
                } else {
                    // 로그인 성공
                    $_SESSION['ship_user_no']   = $row['No'];
                    $_SESSION['ship_user_id']   = $row['ID'];
                    $_SESSION['ship_user_role'] = $row['role'];

                    // 마지막 로그인 시간 기록 (실패해도 로그인은 유지)
                    try {
                        $upd = $pdo->prepare("
                            UPDATE Account
                               SET last_login_at = NOW()
                             WHERE No = :no
                        ");
                        $upd->execute([':no' => $row['No']]);
                    } catch (PDOException $e) {
                        // 무시
                    }

                    // ✅ 로그인 후 바로 QA 출하내역(깔끔 URL)
                    header('Location: shipinglist');
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>JTMES</title>
    <style>
        body {
            margin:0;
            padding:0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:#202124;
            color:#f1f3f4;
            overflow:hidden; /* ✅ 캔버스 풀스크린에서 스크롤바 방지 */
        }
        .center-wrap {
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:40px 10px;
            box-sizing:border-box;

            position:relative; /* ✅ 매트릭스 캔버스보다 위로 */
            z-index:2;
        }
        .card-login {
            background:#2b2b2b;
            padding:22px 26px 20px;
            border-radius:18px;
            box-shadow:0 12px 30px rgba(0,0,0,0.55);
            width:460px;
            max-width:100%;
        }
        .card-login h2 {
            margin:0 0 16px;
            font-size:20px;
        }
        .card-login label {
            display:block;
            margin-bottom:6px;
            font-size:13px;
        }
        .card-login input[type="text"],
        .card-login input[type="password"] {
            width:100%;
            padding:8px 10px;
            border-radius:999px;
            border:1px solid #555;
            background:#1f1f1f;
            color:#f1f3f4;
            box-sizing:border-box;
            font-size:14px;
            margin-bottom:12px;
        }
        .card-login input:focus {
            outline:none;
            border-color:#8ab4f8;
            box-shadow:0 0 0 1px #8ab4f8;
        }
        .btn-login {
            width:100%;
            padding:9px 0;
            border-radius:999px;
            border:none;
            background:#ffe352;
            color:#000;
            font-weight:600;
            cursor:pointer;
            font-size:14px;
        }
        .btn-login:hover { background:#fff066; }
        .btn-sub {
            width:100%;
            padding:8px 0;
            border-radius:999px;
            border:1px solid #555;
            background:#303134;
            color:#e8eaed;
            font-size:13px;
            cursor:pointer;
            margin-top:8px;
        }
        .btn-sub:hover { background:#3c4043; }

        /* 에러 메시지: 항상 일정 높이 유지해서 레이아웃 안 튀게 */
        .msg-box {
            font-size:12px;
            color:#f28b82;
            white-space:pre-line;
            min-height:18px;
            margin-top:8px;
        }

        .hint  {
            font-size:12px;
            color:#9aa0a6;
            white-space:pre-line;
        }

        /* 회원가입 팝업 공통 */
        .modal-backdrop {
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.55);
            display:none;
            z-index:900;
        }
        .modal-backdrop.show {
            display:block;
        }
        .signup-modal {
            position:fixed;
            left:50%;
            top:50%;
            transform:translate(-50%, -50%);
            background:#2b2b2b;
            border-radius:18px;
            box-shadow:0 20px 40px rgba(0,0,0,0.7);
            width:460px;
            max-width:95vw;
            max-height:calc(100vh - 24px);
            padding:10px 12px 12px;
            display:none;
            z-index:901;
            box-sizing:border-box;
        }
        .signup-modal.show {
            display:block;
        }
        .signup-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:4px 4px 6px;
            cursor:move;
        }
        .signup-title {
            font-size:15px;
            font-weight:600;
        }
        .signup-close {
            border:none;
            background:transparent;
            color:#f1f3f4;
            font-size:18px;
            cursor:pointer;
        }
        .signup-modal iframe {
            width:100%;
            /* 높이는 JS로 자동 조절 (초기값/하한만 둠) */
            height:340px;
            min-height:280px;
            max-height:calc(100vh - 110px);
            border:none;
            border-radius:12px;
            background:#202124;
        }
    </style>
</head>
<body>

<div class="center-wrap">
    <div class="card-login">
        <h2>로그인</h2>

        <form method="post">
            <label for="user_id">아이디</label>
            <input type="text" id="user_id" name="user_id" required>

            <label for="password">비밀번호</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn-login">로그인</button>
        </form>

        <!-- 로그인 바로 아래 회원가입 버튼 -->
        <button type="button" class="btn-sub" id="openSignupBtn">회원가입</button>

        <!-- 에러 메시지 : 내용 없으면 빈 줄이지만 높이는 고정 -->
        <div class="msg-box"><?php if ($loginMessage !== '') echo nl2br(h($loginMessage)); ?></div>

        <!-- 안내 문구 하나만 -->
        <div class="hint" style="margin-top:4px;">가입 신청 후 관리자가 승인하면 로그인할 수 있습니다.</div>
    </div>
</div>

<!-- 회원가입 팝업 (내용은 register.php를 iframe으로 띄움) -->
<div class="modal-backdrop" id="signupBackdrop"></div>
<div class="signup-modal" id="signupModal">
    <div class="signup-header" id="signupHeader">
        <div class="signup-title">회원가입</div>
        <button type="button" class="signup-close" id="signupCloseBtn">&times;</button>
    </div>
    <iframe id="signupFrame" src="register.php"></iframe>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ✅ 회원가입 iframe 높이를 내용에 맞춰 자동으로 늘려서 모달 내부 스크롤 방지
    function resizeSignupFrame(forcedHeight) {
        var frame = document.getElementById('signupFrame');
        if (!frame) return;

        var h = 0;

        // 자식에서 height를 보내온 경우 우선 사용
        if (typeof forcedHeight === 'number' && forcedHeight > 0) {
            h = forcedHeight;
        } else {
            // 같은 도메인이라면 직접 내용 높이 측정
            try {
                var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
                if (doc) {
                    // ✅ register.php 내부의 .wrap 높이를 우선 사용 (scrollHeight 피드백 루프 방지)
                    var wrap = doc.querySelector ? doc.querySelector('.wrap') : null;
                    if (wrap && wrap.getBoundingClientRect) {
                        h = Math.ceil(wrap.getBoundingClientRect().height);
                    } else {
                        var bodyH = doc.body ? doc.body.scrollHeight : 0;
                        var docH  = doc.documentElement ? doc.documentElement.scrollHeight : 0;
                        h = Math.max(bodyH, docH);

                        // scrollHeight가 현재 iframe 높이(뷰포트)로 부풀려진 경우가 있어 피드백 루프 방지
                        var cur = frame.getBoundingClientRect().height;
                        if (h >= cur - 1 && h <= cur + 1) {
                            h = 0; // 자식에서 보내는 정확한 높이를 기다림
                        }
                    }
                }
            } catch (e) {
                // cross-origin 등 예외는 무시
            }
        }

        // 화면 안에 들어오도록 상한 적용 (헤더/패딩 고려)
        var maxH = Math.max(320, window.innerHeight - 110);
        if (h > 0) {
            frame.style.height = Math.min(h + 6, maxH) + 'px';
        } else {
            // 측정 실패 시 안전한 기본값
            frame.style.height = Math.min(360, maxH) + 'px';
        }
    }
    window.resizeSignupFrame = resizeSignupFrame;

    // 자식(iframe)에서 postMessage로 높이 보낼 때 처리
    window.addEventListener('message', function (ev) {
        if (!ev || !ev.data) return;
        if (ev.data.type === 'signupFrameHeight' && typeof ev.data.height === 'number') {
            resizeSignupFrame(ev.data.height);
        }
    });



    function makeDraggable(modal, handle) {
        if (!modal || !handle) return;

        handle.style.cursor = 'move';

        let isDown = false;
        let startX = 0, startY = 0;
        let origX = 0, origY = 0;

        handle.addEventListener('mousedown', function (e) {
            isDown = true;
            startX = e.clientX;
            startY = e.clientY;

            const rect = modal.getBoundingClientRect();
            origX = rect.left;
            origY = rect.top;

            modal.style.position = 'fixed';
            modal.style.transform = 'none';
            modal.style.transition = 'none';

            function onMove(ev) {
                if (!isDown) return;
                const dx = ev.clientX - startX;
                const dy = ev.clientY - startY;
                modal.style.left = (origX + dx) + 'px';
                modal.style.top  = (origY + dy) + 'px';
            }

            function onUp() {
                isDown = false;
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);

            e.preventDefault();
        });
    }

    var openSignupBtn  = document.getElementById('openSignupBtn');
    var signupBackdrop = document.getElementById('signupBackdrop');
    var signupModal    = document.getElementById('signupModal');
    var signupHeader   = document.getElementById('signupHeader');
    var signupCloseBtn = document.getElementById('signupCloseBtn');

    var signupFrame = document.getElementById('signupFrame');
    if (signupFrame) {
        signupFrame.addEventListener('load', function () {
            // iframe 내용 로드/리로드 될 때마다 높이 재계산
            resizeSignupFrame();
        });
    }

    // 창 크기 바뀌면 (모달이 열려있을 때) 높이 재계산
    window.addEventListener('resize', function () {
        if (signupModal && signupModal.classList.contains('show')) {
            resizeSignupFrame();
        }
    });



    function openSignup() {
        if (!signupModal || !signupBackdrop) return;
        // 항상 최신으로 로드하고 싶으면 타임스탬프 붙이기
        var frame = document.getElementById('signupFrame');
        if (frame) frame.src = 'register.php?ts=' + Date.now();

        // 로드 전에도 일단 기본 높이 적용 (로드 후 load 이벤트에서 재조정)
        setTimeout(function(){ resizeSignupFrame(); }, 0);


        signupModal.classList.add('show');
        signupBackdrop.classList.add('show');
    }
    function closeSignup() {
        if (!signupModal || !signupBackdrop) return;
        signupModal.classList.remove('show');
        signupBackdrop.classList.remove('show');
    }

    // 부모에서 iframe이 호출할 수 있게
    window.closeSignupModal = closeSignup;

    if (openSignupBtn)  openSignupBtn.addEventListener('click', openSignup);
    if (signupBackdrop) signupBackdrop.addEventListener('click', closeSignup);
    if (signupCloseBtn) signupCloseBtn.addEventListener('click', closeSignup);

    makeDraggable(signupModal, signupHeader);
});
</script>

<!-- ✅ 여기부터 “매트릭스 배경” 외부 연결 -->
<script>
  // 페이지별로 설정만 바꾸면 됨
  window.MATRIX_BG = {
    enabled: true,
    text: "01",       // "" 로 두면 랜덤(가타카나/숫자/기호)
    speed: 1.15,
    size: 16,
    zIndex: 0,
    scanlines: true,
    vignette: true
  };
</script>
<script src="assets/matrix-bg.js"></script>
<!-- ✅ 여기까지 -->

</body>
</html>
