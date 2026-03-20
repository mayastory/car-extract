<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function jtgpt_json_response(array $payload): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jtgpt_mock_answer(string $message): string {
    $text = trim($message);
    if ($text === '') {
        return '';
    }

    $lower = mb_strtolower($text, 'UTF-8');

    if (preg_match('/그래프|차트/u', $text)) {
        return '그래프 요청 흐름은 다음 단계에서 연결할 예정이에요. 지금은 화면 톤과 입력 경험만 먼저 맞추고 있어요.';
    }
    if (preg_match('/oqc|omm|cmm|aoi|ipqc/u', $lower)) {
        return '모듈별 실조회는 아직 연결 전이에요. 지금은 JTGPT UI 전용 mock 화면입니다.';
    }
    if (preg_match('/출하|출고|ship|shipping|lot|tray|수량|ea/u', $lower)) {
        return '출하 실조회도 아직 연결 전이에요. 먼저 UI를 확정한 뒤 읽기 전용 조회 로직을 붙일 예정입니다.';
    }

    return '지금은 JTGPT UI 전용 mock 화면이에요. 먼저 화면 톤과 입력 경험을 맞춘 뒤 실제 조회 로직을 연결할 예정입니다.';
}

$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($isAjax) {
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $message = trim((string)($payload['message'] ?? ''));
    jtgpt_json_response([
        'ok' => true,
        'answer' => jtgpt_mock_answer($message),
    ]);
}
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JTGPT</title>
<style>
    :root{
        --bg:#1e1f22;
        --surface:rgba(48,50,56,.76);
        --surface-2:rgba(255,255,255,.038);
        --surface-3:rgba(255,255,255,.05);
        --text:#eceef2;
        --muted:#9ea4ad;
        --line:rgba(255,255,255,.08);
        --line-strong:rgba(255,255,255,.12);
        --assistant:rgba(255,255,255,.038);
        --user:rgba(255,255,255,.065);
        --shadow:0 18px 40px rgba(0,0,0,.24);
        --shadow-soft:0 8px 22px rgba(0,0,0,.18);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
        margin:0;
        background:var(--bg);
        color:var(--text);
        font-family:"Inter","Segoe UI",Roboto,"Noto Sans KR","Apple SD Gothic Neo","Malgun Gothic",sans-serif;
        -webkit-font-smoothing:antialiased;
        -moz-osx-font-smoothing:grayscale;
        text-rendering:optimizeLegibility;
        overflow:hidden;
    }
    .app{height:100%;display:flex;flex-direction:column}
    .brand{
        position:fixed;
        left:24px;
        top:20px;
        z-index:10;
        font-size:13px;
        font-weight:500;
        letter-spacing:-.01em;
        color:rgba(255,255,255,.88);
        opacity:.82;
    }
    .chat{
        flex:1;
        overflow:auto;
        padding:24px 20px 182px;
    }
    .chat::-webkit-scrollbar{width:10px}
    .chat::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:999px}

    .home{
        min-height:100%;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        padding:84px 20px 210px;
        text-align:center;
    }
    .home.hidden{display:none}
    .home-badge{
        width:30px;
        height:30px;
        border-radius:10px;
        border:1px solid var(--line);
        background:rgba(255,255,255,.028);
        display:grid;
        place-items:center;
        font-size:14px;
        line-height:1;
        color:#f5f6f8;
        margin-bottom:18px;
        box-shadow:var(--shadow-soft);
    }
    .home-title{
        margin:0;
        font-size:36px;
        line-height:1.18;
        letter-spacing:-.038em;
        font-weight:560;
        color:#f2f4f7;
    }

    .messages{
        display:none;
        max-width:800px;
        margin:0 auto;
        padding-top:10px;
    }
    .messages.active{display:block}
    .msg{display:flex;margin:0 0 24px}
    .msg.user{justify-content:flex-end}
    .msg.assistant{justify-content:flex-start}
    .bubble-wrap{max-width:min(760px,80%)}
    .label{
        font-size:11px;
        color:var(--muted);
        margin-bottom:7px;
        letter-spacing:-.01em;
    }
    .msg.user .label{text-align:right}
    .bubble{
        border:1px solid var(--line);
        background:var(--assistant);
        box-shadow:var(--shadow-soft);
        border-radius:20px;
        padding:14px 16px;
        font-size:14px;
        line-height:1.72;
        letter-spacing:-.01em;
        white-space:pre-wrap;
        word-break:keep-all;
        color:#eceef2;
    }
    .msg.user .bubble{
        background:var(--user);
        border-color:rgba(255,255,255,.10);
    }

    .composer-wrap{
        position:fixed;
        left:50%;
        bottom:18px;
        transform:translateX(-50%);
        width:min(760px, calc(100vw - 24px));
        z-index:20;
    }
    .composer{
        border:1px solid var(--line);
        background:var(--surface);
        border-radius:24px;
        box-shadow:var(--shadow);
        padding:12px 13px 10px;
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        transition:border-color .16s ease, box-shadow .16s ease, background .16s ease;
    }
    .composer:focus-within{
        border-color:var(--line-strong);
        background:rgba(52,54,60,.82);
        box-shadow:0 20px 48px rgba(0,0,0,.28);
    }
    .composer textarea{
        width:100%;
        min-height:52px;
        max-height:220px;
        resize:none;
        border:0;
        outline:none;
        background:transparent;
        color:var(--text);
        font:inherit;
        font-size:14px;
        line-height:1.65;
        padding:2px 4px;
    }
    .composer textarea::placeholder{color:#aaafb8}
    .composer-bottom{
        margin-top:6px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding:0 2px;
    }
    .composer-hint{
        font-size:11px;
        color:var(--muted);
        letter-spacing:-.01em;
    }
    .send{
        width:34px;
        height:34px;
        border-radius:999px;
        border:1px solid rgba(255,255,255,.10);
        background:#ededee;
        color:#161719;
        display:grid;
        place-items:center;
        font-size:14px;
        font-weight:700;
        cursor:pointer;
        flex:0 0 auto;
        transition:transform .12s ease, box-shadow .12s ease, opacity .12s ease;
        padding:0;
        box-shadow:0 4px 12px rgba(0,0,0,.18);
    }
    .send:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.22)}
    .send:active{transform:translateY(0)}
    .send[disabled]{opacity:.5;cursor:default;box-shadow:none}
    .typing-cursor{
        display:inline-block;
        width:1px;
        height:1.02em;
        background:rgba(255,255,255,.82);
        vertical-align:-2px;
        margin-left:2px;
        animation:blink 1s step-end infinite;
    }
    @keyframes blink{50%{opacity:0}}

    @media (max-width: 900px){
        .home-title{font-size:33px}
        .bubble-wrap{max-width:86%}
    }
    @media (max-width: 640px){
        .brand{left:16px;top:16px;font-size:12px}
        .chat{padding:18px 12px 156px}
        .home{padding:72px 16px 176px}
        .home-badge{width:28px;height:28px;border-radius:9px;font-size:13px;margin-bottom:16px}
        .home-title{font-size:29px;line-height:1.22}
        .composer-wrap{width:calc(100vw - 16px);bottom:10px}
        .composer{padding:11px 12px 9px;border-radius:22px}
        .composer textarea{min-height:48px;font-size:14px}
        .bubble-wrap{max-width:92%}
        .bubble{padding:13px 15px;border-radius:18px;font-size:14px}
        .send{width:32px;height:32px}
    }
</style>
</head>
<body>
<div class="app">
    <div class="brand">JTGPT</div>

    <div id="chat" class="chat">
        <section id="home" class="home">
            <div class="home-badge">✦</div>
            <h1 class="home-title">무엇을 도와드릴까요?</h1>
        </section>
        <section id="messages" class="messages"></section>
    </div>

    <div class="composer-wrap">
        <div class="composer">
            <textarea id="messageInput" placeholder="무엇이든 물어보세요"></textarea>
            <div class="composer-bottom">
                <div class="composer-hint">Enter로 전송 · Shift+Enter로 줄바꿈</div>
                <button id="sendBtn" class="send" type="button" aria-label="전송">✦</button>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const homeEl = document.getElementById('home');
    const messagesEl = document.getElementById('messages');
    const inputEl = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const chatEl = document.getElementById('chat');

    function autoResize() {
        inputEl.style.height = '0px';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 220) + 'px';
    }

    function scrollBottom(smooth = true) {
        chatEl.scrollTo({ top: chatEl.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }

    function enterConversationMode() {
        homeEl.classList.add('hidden');
        messagesEl.classList.add('active');
    }

    function createMessage(role, text = '') {
        enterConversationMode();

        const item = document.createElement('div');
        item.className = 'msg ' + role;

        const wrap = document.createElement('div');
        wrap.className = 'bubble-wrap';

        const label = document.createElement('div');
        label.className = 'label';
        label.textContent = role === 'assistant' ? 'JTGPT' : '나';
        wrap.appendChild(label);

        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.textContent = text;
        wrap.appendChild(bubble);

        item.appendChild(wrap);
        messagesEl.appendChild(item);
        scrollBottom();
        return bubble;
    }

    async function typeText(el, text) {
        el.textContent = '';
        const cursor = document.createElement('span');
        cursor.className = 'typing-cursor';
        el.appendChild(cursor);

        for (const ch of text) {
            cursor.remove();
            el.append(document.createTextNode(ch));
            el.appendChild(cursor);
            scrollBottom(false);
            await new Promise(resolve => setTimeout(resolve, 14));
        }
        cursor.remove();
    }

    async function sendMessage() {
        const message = inputEl.value.trim();
        if (!message) return;

        createMessage('user', message);
        inputEl.value = '';
        autoResize();
        sendBtn.disabled = true;

        const assistantBubble = createMessage('assistant', '');

        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ message })
            });
            const json = await res.json();
            await typeText(assistantBubble, (json && json.answer) ? json.answer : '응답을 받지 못했어요.');
        } catch (e) {
            await typeText(assistantBubble, '지금 응답을 불러오지 못했어요.');
        } finally {
            sendBtn.disabled = false;
            inputEl.focus();
            scrollBottom();
        }
    }

    inputEl.addEventListener('input', autoResize);
    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    sendBtn.addEventListener('click', sendMessage);

    autoResize();
})();
</script>
</body>
</html>
