<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

header('X-Frame-Options: SAMEORIGIN');

function jtgpt_ui_mock_reply(string $message): string {
    $t = trim($message);
    if ($t === '') {
        return '말씀하신 내용을 다시 한번 적어주세요.';
    }

    $lower = mb_strtolower($t, 'UTF-8');

    if (preg_match('/(안녕|반가|hello|hi)/u', $lower)) {
        return '안녕하세요. JTGPT UI 시안입니다. 지금은 화면과 입력감만 먼저 맞추고 있어요.';
    }
    if (preg_match('/(출하|oqc|omm|cmm|aoi|그래프|jmp|ipqc)/u', $lower)) {
        return '지금은 UI 전용 단계라 실제 조회는 아직 연결하지 않았습니다. 다음 단계에서 출하, OQC, OMM, CMM, AOI, 그래프빌더 순서로 붙일 예정입니다.';
    }
    if (preg_match('/(오늘|최근|누적|제일 최근|마지막)/u', $lower)) {
        return '이 질문은 실제 DB 해석이 필요한 유형입니다. 현재는 화면만 먼저 정리한 상태라, 다음 패치에서 읽기 전용 조회를 연결하면 이 자리에서 바로 답하게 됩니다.';
    }

    return '현재는 JTGPT 화면만 먼저 맞춘 상태입니다. 로직은 일부러 최소화했고, 다음 단계에서 문맥 저장과 실제 DB 조회를 붙이면 됩니다.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jtgpt_ui_chat'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $message = isset($_POST['message']) ? (string)$_POST['message'] : '';
    echo json_encode([
        'ok' => true,
        'reply' => jtgpt_ui_mock_reply($message),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JTGPT</title>
<style>
    :root {
        color-scheme: dark;
        --bg: #212121;
        --panel: #2f2f2f;
        --panel-2: #303030;
        --border: rgba(255,255,255,.08);
        --text: #ececec;
        --muted: #a8a8a8;
        --muted-2: #8b8b8b;
        --assistant: transparent;
        --user: #303030;
        --shadow: 0 12px 40px rgba(0,0,0,.28);
        --radius: 28px;
        --radius-sm: 18px;
        --content-w: 768px;
        --sidebar-gap: 0px;
    }

    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font: 400 15px/1.6 ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", "Apple SD Gothic Neo", "Noto Sans KR", sans-serif;
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
        overflow: hidden;
    }

    .page {
        position: relative;
        height: 100%;
        display: flex;
        justify-content: center;
        padding: 0 18px;
    }

    .shell {
        width: min(calc(100vw - 36px), var(--content-w));
        height: 100%;
        display: grid;
        grid-template-rows: 1fr auto;
    }

    .conversation {
        min-height: 0;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(255,255,255,.14) transparent;
        padding: 22px 0 140px;
    }

    .conversation::-webkit-scrollbar { width: 10px; }
    .conversation::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,.14);
        border-radius: 999px;
        border: 2px solid transparent;
        background-clip: padding-box;
    }

    .hero-wrap {
        height: 100%;
        display: grid;
        place-items: center;
        transition: opacity .24s ease, transform .24s ease;
    }

    .hero-wrap.hidden {
        opacity: 0;
        transform: translateY(-8px);
        pointer-events: none;
        position: absolute;
        inset: 0;
    }

    .hero {
        width: 100%;
        max-width: 700px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 14px;
        text-align: center;
        padding-bottom: 56px;
    }

    .hero-badge {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        background: linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.04));
        border: 1px solid rgba(255,255,255,.09);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
        font-size: 18px;
        letter-spacing: .02em;
    }

    .hero-title {
        margin: 0;
        font-size: clamp(28px, 4vw, 40px);
        line-height: 1.15;
        font-weight: 500;
        letter-spacing: -.03em;
    }

    .hero-subtitle {
        margin: 0;
        color: var(--muted);
        font-size: 14px;
        line-height: 1.6;
    }

    .messages {
        width: 100%;
        max-width: 100%;
        display: none;
        flex-direction: column;
        gap: 26px;
        padding: 18px 0 0;
    }

    .messages.active { display: flex; }

    .message-row {
        width: 100%;
        display: flex;
        align-items: flex-start;
        gap: 14px;
    }

    .message-row.user {
        justify-content: flex-end;
    }

    .avatar {
        width: 30px;
        height: 30px;
        flex: 0 0 30px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        font-size: 13px;
        font-weight: 600;
        color: #fff;
        background: rgba(255,255,255,.08);
        border: 1px solid rgba(255,255,255,.08);
    }

    .message {
        max-width: min(100%, 740px);
        min-width: 0;
    }

    .message-body {
        color: var(--text);
        white-space: pre-wrap;
        word-break: keep-all;
        overflow-wrap: anywhere;
        font-size: 15px;
        line-height: 1.75;
        letter-spacing: -.01em;
    }

    .message-row.user .message-body {
        background: var(--user);
        border: 1px solid var(--border);
        padding: 13px 16px;
        border-radius: 22px;
        line-height: 1.55;
        box-shadow: var(--shadow);
    }

    .composer-wrap {
        position: sticky;
        bottom: 0;
        padding: 18px 0 24px;
        background: linear-gradient(180deg, rgba(33,33,33,0) 0%, rgba(33,33,33,.88) 24%, rgba(33,33,33,1) 44%);
        backdrop-filter: blur(10px);
    }

    .composer {
        position: relative;
        background: var(--panel);
        border: 1px solid rgba(255,255,255,.09);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .composer-main {
        display: grid;
        grid-template-columns: 1fr auto;
        align-items: end;
        gap: 10px;
        padding: 14px 14px 12px 18px;
    }

    .composer textarea {
        width: 100%;
        min-height: 26px;
        max-height: 180px;
        resize: none;
        border: 0;
        outline: 0;
        background: transparent;
        color: var(--text);
        font: inherit;
        line-height: 1.7;
        padding: 0;
        margin: 0;
    }

    .composer textarea::placeholder { color: var(--muted-2); }

    .send {
        width: 38px;
        height: 38px;
        border-radius: 999px;
        border: 0;
        background: #f0f0f0;
        color: #111;
        display: grid;
        place-items: center;
        cursor: pointer;
        transition: transform .15s ease, opacity .15s ease, background .15s ease;
        font-size: 16px;
        font-weight: 700;
    }

    .send:hover { transform: translateY(-1px); }
    .send:disabled {
        cursor: default;
        opacity: .38;
        transform: none;
    }

    .composer-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 0 18px 12px;
        color: var(--muted-2);
        font-size: 12px;
    }

    .typing {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 20px;
    }

    .typing span {
        width: 5px;
        height: 5px;
        border-radius: 999px;
        background: rgba(255,255,255,.72);
        animation: pulse 1s infinite ease-in-out;
    }
    .typing span:nth-child(2) { animation-delay: .16s; }
    .typing span:nth-child(3) { animation-delay: .32s; }

    @keyframes pulse {
        0%, 80%, 100% { transform: scale(.72); opacity: .36; }
        40% { transform: scale(1); opacity: 1; }
    }

    @media (max-width: 820px) {
        .page { padding: 0 12px; }
        .shell { width: min(calc(100vw - 24px), var(--content-w)); }
        .conversation { padding-bottom: 132px; }
        .hero { padding-bottom: 30px; }
        .hero-title { font-size: 30px; }
        .message-row { gap: 10px; }
        .avatar { width: 28px; height: 28px; flex-basis: 28px; }
        .composer-main { padding: 13px 13px 11px 15px; }
        .composer-foot { padding: 0 15px 11px; }
    }
</style>
</head>
<body>
<div class="page">
    <div class="shell">
        <div class="conversation" id="conversation">
            <div class="hero-wrap" id="heroWrap">
                <div class="hero">
                    <div class="hero-badge">✦</div>
                    <h1 class="hero-title">무엇을 도와드릴까요?</h1>
                    <p class="hero-subtitle">JTGPT UI 시안입니다. 먼저 화면과 입력 경험을 맞춘 뒤, 읽기 전용 조회와 그래프 기능을 붙일 수 있습니다.</p>
                </div>
            </div>
            <div class="messages" id="messages"></div>
        </div>

        <div class="composer-wrap">
            <form class="composer" id="composerForm" autocomplete="off">
                <div class="composer-main">
                    <textarea id="composerInput" name="message" rows="1" placeholder="JTGPT에 메시지 보내기" aria-label="메시지 입력"></textarea>
                    <button type="submit" class="send" id="sendButton" disabled aria-label="보내기">↑</button>
                </div>
                <div class="composer-foot">
                    <span>Enter로 전송 · Shift+Enter로 줄바꿈</span>
                    <span>UI 전용 시안</span>
                </div>
                <input type="hidden" name="jtgpt_ui_chat" value="1">
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('composerForm');
    const input = document.getElementById('composerInput');
    const sendButton = document.getElementById('sendButton');
    const heroWrap = document.getElementById('heroWrap');
    const messages = document.getElementById('messages');
    const conversation = document.getElementById('conversation');

    let busy = false;

    function autoGrow() {
        input.style.height = '26px';
        input.style.height = Math.min(input.scrollHeight, 180) + 'px';
    }

    function updateSendState() {
        sendButton.disabled = busy || !input.value.trim();
    }

    function scrollToBottom(smooth = true) {
        conversation.scrollTo({
            top: conversation.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto'
        });
    }

    function ensureChatMode() {
        heroWrap.classList.add('hidden');
        messages.classList.add('active');
    }

    function createRow(role, text = '') {
        const row = document.createElement('div');
        row.className = 'message-row ' + role;

        if (role !== 'user') {
            const avatar = document.createElement('div');
            avatar.className = 'avatar';
            avatar.textContent = 'JT';
            row.appendChild(avatar);
        }

        const message = document.createElement('div');
        message.className = 'message';
        const body = document.createElement('div');
        body.className = 'message-body';
        body.textContent = text;
        message.appendChild(body);
        row.appendChild(message);

        messages.appendChild(row);
        return body;
    }

    function createTypingRow() {
        const row = document.createElement('div');
        row.className = 'message-row assistant';

        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.textContent = 'JT';
        row.appendChild(avatar);

        const message = document.createElement('div');
        message.className = 'message';
        const body = document.createElement('div');
        body.className = 'message-body';
        body.innerHTML = '<span class="typing"><span></span><span></span><span></span></span>';
        message.appendChild(body);
        row.appendChild(message);

        messages.appendChild(row);
        return body;
    }

    async function typeText(el, text) {
        el.textContent = '';
        const chars = Array.from(text);
        const fast = text.length > 140 ? 8 : 16;
        for (let i = 0; i < chars.length; i++) {
            el.textContent += chars[i];
            if (i % 2 === 0) scrollToBottom(false);
            await new Promise(r => setTimeout(r, fast));
        }
        scrollToBottom();
    }

    async function requestReply(message) {
        const body = new FormData();
        body.append('jtgpt_ui_chat', '1');
        body.append('message', message);

        const res = await fetch(window.location.href, {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!res.ok) {
            throw new Error('응답을 불러오지 못했습니다.');
        }
        return res.json();
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text || busy) return;

        busy = true;
        updateSendState();
        ensureChatMode();

        createRow('user', text);
        input.value = '';
        autoGrow();
        scrollToBottom();

        const typingBody = createTypingRow();
        scrollToBottom();

        try {
            const data = await requestReply(text);
            await typeText(typingBody, (data && data.reply) ? data.reply : '응답을 표시할 수 없습니다.');
        } catch (err) {
            typingBody.textContent = '지금은 UI 시안 단계라 응답 연결에 실패했습니다.';
        } finally {
            busy = false;
            updateSendState();
            input.focus();
        }
    });

    input.addEventListener('input', () => {
        autoGrow();
        updateSendState();
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    autoGrow();
    updateSendState();
    setTimeout(() => input.focus(), 30);
})();
</script>
</body>
</html>
