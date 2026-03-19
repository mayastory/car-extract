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
        --bg:#202124;
        --panel:#2f3136;
        --panel-soft:#35373d;
        --text:#f2f2f2;
        --muted:#afb3bb;
        --line:rgba(255,255,255,.10);
        --bubble:#2b2d33;
        --bubble-user:#343740;
        --shadow:0 10px 30px rgba(0,0,0,.22);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
        margin:0;
        background:var(--bg);
        color:var(--text);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans KR","Apple SD Gothic Neo","Malgun Gothic",sans-serif;
        overflow:hidden;
    }
    .app{height:100%;display:flex;flex-direction:column}
    .brand{
        position:fixed;left:28px;top:22px;z-index:10;
        font-size:14px;font-weight:500;color:#e7e7e7;opacity:.95;
    }
    .chat{
        flex:1;overflow:auto;padding:28px 20px 210px;
    }
    .chat::-webkit-scrollbar{width:10px}
    .chat::-webkit-scrollbar-thumb{background:rgba(255,255,255,.10);border-radius:999px}

    .home{
        min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;
        padding:92px 20px 240px;text-align:center;
    }
    .home.hidden{display:none}
    .home-badge{
        width:40px;height:40px;border-radius:13px;border:1px solid var(--line);
        background:rgba(255,255,255,.035);display:grid;place-items:center;
        font-size:18px;line-height:1;color:#fff;margin-bottom:18px;
    }
    .home-title{
        margin:0;
        font-size:44px;
        line-height:1.14;
        letter-spacing:-.035em;
        font-weight:600;
        color:#f1f1f1;
    }

    .messages{
        display:none;
        max-width:860px;
        margin:0 auto;
        padding-top:14px;
    }
    .messages.active{display:block}
    .msg{display:flex;margin:0 0 26px}
    .msg.user{justify-content:flex-end}
    .msg.assistant{justify-content:flex-start}
    .bubble-wrap{max-width:min(780px,82%)}
    .label{font-size:12px;color:var(--muted);margin-bottom:8px}
    .msg.user .label{text-align:right}
    .bubble{
        border:1px solid var(--line);
        background:var(--bubble);
        box-shadow:var(--shadow);
        border-radius:24px;
        padding:16px 18px;
        font-size:15px;
        line-height:1.72;
        white-space:pre-wrap;
        word-break:keep-all;
    }
    .msg.user .bubble{background:var(--bubble-user)}

    .composer-wrap{
        position:fixed;left:50%;bottom:22px;transform:translateX(-50%);
        width:min(760px, calc(100vw - 28px));z-index:20;
    }
    .composer{
        border:1px solid var(--line);
        background:rgba(47,49,54,.96);
        border-radius:28px;
        box-shadow:var(--shadow);
        padding:16px 16px 14px;
        backdrop-filter:blur(8px);
    }
    .composer textarea{
        width:100%;min-height:66px;max-height:220px;resize:none;border:0;outline:none;background:transparent;
        color:var(--text);font:inherit;font-size:15px;line-height:1.65;
    }
    .composer textarea::placeholder{color:#b6bac2}
    .composer-bottom{
        margin-top:8px;display:flex;align-items:center;justify-content:space-between;gap:12px;
    }
    .composer-hint{font-size:12px;color:var(--muted)}
    .send{
        width:40px;height:40px;border-radius:999px;border:1px solid var(--line);background:#ededed;color:#111;
        display:grid;place-items:center;font-size:18px;cursor:pointer;flex:0 0 auto;
        transition:transform .12s ease, opacity .12s ease;
        padding:0;
    }
    .send:hover{transform:translateY(-1px)}
    .send:active{transform:translateY(0)}
    .send[disabled]{opacity:.5;cursor:default}
    .typing-cursor{
        display:inline-block;width:1px;height:1.05em;background:rgba(255,255,255,.88);vertical-align:-2px;margin-left:2px;
        animation:blink 1s step-end infinite;
    }
    @keyframes blink{50%{opacity:0}}

    @media (max-width: 900px){
        .home-title{font-size:40px}
        .bubble-wrap{max-width:88%}
    }
    @media (max-width: 640px){
        .brand{left:16px;top:16px}
        .chat{padding:20px 12px 180px}
        .home{padding:74px 16px 220px}
        .home-title{font-size:34px}
        .composer-wrap{width:calc(100vw - 16px);bottom:10px}
        .composer{padding:14px 14px 12px;border-radius:24px}
        .composer textarea{min-height:58px;font-size:14px}
        .bubble-wrap{max-width:92%}
        .bubble{padding:15px 16px;border-radius:22px}
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
