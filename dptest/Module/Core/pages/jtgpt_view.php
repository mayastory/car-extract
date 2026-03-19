<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }

date_default_timezone_set('Asia/Seoul');

session_start();
require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/inc/common.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';

dp_auth_guard();

$EMBED = !empty($_GET['embed']);
if (!$EMBED) {
    require_once JTMES_ROOT . '/inc/sidebar.php';
    require_once JTMES_ROOT . '/inc/dp_userbar.php';
}

if (!function_exists('jtgpt_h')) {
    function jtgpt_h(?string $s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('jtgpt_contains_any')) {
    function jtgpt_contains_any(string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('jtgpt_detect_time_hint')) {
    function jtgpt_detect_time_hint(string $text): ?string {
        $map = [
            '오늘' => ['오늘', '금일', 'today', 'now'],
            '어제' => ['어제', 'yesterday'],
            '이번 주' => ['이번주', '이번 주', '금주', 'this week'],
            '최근 7일' => ['최근 7일', '7일', '일주일', '1주일', '최근 일주일'],
            '이번 달' => ['이번달', '이번 달', '금월', 'this month'],
        ];
        foreach ($map as $label => $needles) {
            if (jtgpt_contains_any($text, $needles)) {
                return $label;
            }
        }
        return null;
    }
}

if (!function_exists('jtgpt_parse_message')) {
    function jtgpt_parse_message(string $message): array {
        $text = trim($message);
        $normalized = mb_strtolower($text, 'UTF-8');
        $timeHint = jtgpt_detect_time_hint($normalized);

        $modules = [
            'shipinglist' => [
                'title' => 'QA 출하내역',
                'href' => dp_url('shipinglist'),
                'needles' => ['출하', '출고', 'ship', 'shipping', 'lot', '포장', '납품', 'qa'],
                'summary' => '출하/출고/lot/포장 관련 요청으로 이해했어요.',
            ],
            'rma' => [
                'title' => 'RMA 내역',
                'href' => dp_url('rma'),
                'needles' => ['rma', 'return', '반품', '회수', '리턴'],
                'summary' => 'RMA/반품/회수 관련 요청으로 이해했어요.',
            ],
            'oqc' => [
                'title' => 'OQC 측정 데이터 조회',
                'href' => dp_url('oqc'),
                'needles' => ['oqc', '측정', 'ng', '불량', '검사', '측정값', 'cavity', 'tool'],
                'summary' => 'OQC 측정/NG/검사 관련 요청으로 이해했어요.',
            ],
            'ipqc' => [
                'title' => 'JMP Assist (IPQC)',
                'href' => dp_url('ipqc'),
                'needles' => ['ipqc', 'jmp', '공정능력', 'cpk', 'cp', 'cpu', 'cpl', 'spc', '히스토그램', '그래프'],
                'summary' => 'IPQC/JMP/공정능력 관련 요청으로 이해했어요.',
            ],
        ];

        $scores = [];
        foreach ($modules as $key => $meta) {
            $score = 0;
            foreach ($meta['needles'] as $needle) {
                if ($needle !== '' && mb_strpos($normalized, mb_strtolower($needle, 'UTF-8')) !== false) {
                    $score++;
                }
            }
            $scores[$key] = $score;
        }

        arsort($scores);
        $topKey = (string)key($scores);
        $topScore = (int)current($scores);
        $matched = array_keys(array_filter($scores, static fn($v) => $v > 0));

        if ($text === '') {
            return [
                'answer' => '질문이 비어 있어요. 출하, RMA, OQC, IPQC 중 원하는 걸 자연스럽게 말해보세요.',
                'actions' => [],
                'suggestions' => ['오늘 출하 lot 보여줘', '최근 OQC NG 쪽 먼저 보자', '공정능력 그래프 쪽 보고 싶어'],
            ];
        }

        if (count($matched) > 1 && $topScore > 0) {
            $titles = [];
            foreach ($matched as $m) {
                $titles[] = $modules[$m]['title'];
            }
            $actions = [];
            foreach ($matched as $m) {
                $actions[] = ['label' => $modules[$m]['title'] . ' 열기', 'href' => $modules[$m]['href']];
            }
            $answer = '여러 메뉴가 함께 감지됐어요: ' . implode(', ', $titles) . '. 우선 어느 화면으로 갈지 하나 골라주세요.';
            if ($timeHint !== null) {
                $answer .= ' 날짜 표현 ' . $timeHint . ' 도 감지했어요.';
            }
            return [
                'answer' => $answer,
                'actions' => $actions,
                'suggestions' => ['오늘 출하만 보여줘', 'OQC NG만 보자', 'IPQC 공정능력 화면 열어줘'],
            ];
        }

        if ($topScore > 0 && isset($modules[$topKey])) {
            $meta = $modules[$topKey];
            $answer = $meta['summary'] . ' 1차 버전이라 아직은 해당 메뉴로 이동 중심으로 연결돼 있어요.';
            if ($timeHint !== null) {
                $answer .= ' 날짜 키워드 ' . $timeHint . ' 도 감지했어요.';
            }
            if (jtgpt_contains_any($normalized, ['열어', '이동', '가자', '띄워', '보여줘', '조회'])) {
                $answer .= ' 아래 버튼으로 바로 이동하면 돼요.';
            }
            return [
                'answer' => $answer,
                'actions' => [
                    ['label' => $meta['title'] . ' 열기', 'href' => $meta['href']],
                ],
                'suggestions' => ['오늘 lot 쪽 보자', '최근 NG 있는 측정값 보고 싶어', '공정능력 그래프 열어줘'],
            ];
        }

        return [
            'answer' => '아직 1차 버전이라 출하/RMA/OQC/IPQC 중심으로 이해하고 있어요. 아래 예시처럼 말해보면 더 잘 맞아요.',
            'actions' => [],
            'suggestions' => ['오늘 출하 lot 보여줘', 'RMA 쪽 먼저 보자', '최근 OQC NG 데이터', 'IPQC 공정능력 화면 열어줘'],
        ];
    }
}

$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST') && (
    stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false
    || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
    || (($_POST['action'] ?? '') === 'chat')
);

if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    $raw = file_get_contents('php://input') ?: '';
    $payload = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if (!$payload) {
        $payload = $_POST;
    }
    $message = trim((string)($payload['message'] ?? ''));
    $result = jtgpt_parse_message($message);
    echo json_encode([
        'ok' => true,
        'answer' => (string)($result['answer'] ?? ''),
        'actions' => array_values($result['actions'] ?? []),
        'suggestions' => array_values($result['suggestions'] ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$selfUrl = $EMBED ? 'jtgpt_view.php?embed=1' : dp_url('jtgpt_view.php');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JTGPT</title>
<style>
    :root{
        --bg:#202124;
        --panel:rgba(49,49,52,.88);
        --panel-2:rgba(36,36,39,.96);
        --line:rgba(255,255,255,.08);
        --text:#ececec;
        --muted:#b3b3b3;
        --accent:#ffffff;
        --bubble-user:#303134;
        --bubble-ai:#252629;
        --shadow:0 20px 80px rgba(0,0,0,.28);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
        margin:0;
        color:var(--text);
        background:transparent;
        font-family:Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif;
    }
    .jtgpt-page{
        min-height:100%;
        display:flex;
        flex-direction:column;
        position:relative;
        overflow:hidden;
        background:linear-gradient(180deg, rgba(32,33,36,.82) 0%, rgba(24,24,27,.90) 100%);
    }
    .jtgpt-page::before{
        content:"";
        position:absolute;
        inset:-20% auto auto 50%;
        width:520px;
        height:520px;
        transform:translateX(-50%);
        background:radial-gradient(circle, rgba(255,255,255,.08), rgba(255,255,255,0));
        pointer-events:none;
        filter:blur(10px);
    }
    .jtgpt-inner{
        width:min(920px, calc(100% - 48px));
        margin:0 auto;
        flex:1;
        display:flex;
        flex-direction:column;
        position:relative;
        z-index:1;
    }
    .jtgpt-top{
        padding:26px 0 0;
        display:flex;
        justify-content:space-between;
        align-items:center;
        min-height:78px;
    }
    .jtgpt-badge{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:10px 14px;
        border-radius:999px;
        border:1px solid rgba(255,255,255,.10);
        background:rgba(255,255,255,.04);
        color:#f4f4f5;
        font-size:13px;
        letter-spacing:.2px;
        backdrop-filter:blur(8px);
    }
    .jtgpt-status{
        color:var(--muted);
        font-size:13px;
    }
    .jtgpt-main{
        flex:1;
        display:flex;
        flex-direction:column;
        justify-content:center;
        padding:24px 0 26px;
        min-height:0;
    }
    .jtgpt-page.has-chat .jtgpt-main{
        justify-content:flex-end;
        gap:18px;
        padding-top:18px;
    }
    .jtgpt-hero{
        text-align:center;
        margin-bottom:30px;
        transition:all .22s ease;
    }
    .jtgpt-page.has-chat .jtgpt-hero{
        display:none;
    }
    .jtgpt-hero h1{
        margin:0 0 14px;
        font-size:48px;
        font-weight:700;
        letter-spacing:-0.03em;
    }
    .jtgpt-hero p{
        margin:0;
        color:var(--muted);
        font-size:17px;
    }
    .jtgpt-thread{
        display:none;
        flex:1;
        min-height:0;
        overflow:auto;
        padding:6px 4px 10px;
        gap:18px;
        scroll-behavior:smooth;
    }
    .jtgpt-page.has-chat .jtgpt-thread{
        display:flex;
        flex-direction:column;
    }
    .msg{
        display:flex;
        flex-direction:column;
        gap:8px;
    }
    .msg.user{align-items:flex-end}
    .msg.assistant{align-items:flex-start}
    .msg-role{
        font-size:12px;
        color:var(--muted);
        padding:0 6px;
    }
    .bubble{
        max-width:min(760px, 92%);
        padding:16px 18px;
        border-radius:22px;
        box-shadow:var(--shadow);
        line-height:1.65;
        white-space:pre-wrap;
        word-break:keep-all;
        border:1px solid var(--line);
    }
    .msg.user .bubble{
        background:var(--bubble-user);
        color:#fff;
        border-bottom-right-radius:8px;
    }
    .msg.assistant .bubble{
        background:var(--bubble-ai);
        color:#f2f2f2;
        border-bottom-left-radius:8px;
    }
    .msg-actions,
    .msg-suggestions{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        padding:0 4px;
    }
    .chip,
    .action-btn{
        appearance:none;
        border:none;
        cursor:pointer;
        border-radius:999px;
        padding:10px 14px;
        font-size:13px;
        line-height:1;
        text-decoration:none;
        transition:transform .12s ease, background .12s ease, border-color .12s ease;
    }
    .chip{
        background:rgba(255,255,255,.06);
        color:#e6e6e6;
        border:1px solid rgba(255,255,255,.08);
    }
    .chip:hover,
    .action-btn:hover{
        transform:translateY(-1px);
    }
    .action-btn{
        background:#f3f4f6;
        color:#111827;
        font-weight:600;
    }
    .composer-wrap{
        width:min(740px, 100%);
        margin:0 auto;
        transition:all .22s ease;
    }
    .composer{
        border:1px solid rgba(255,255,255,.08);
        background:var(--panel);
        border-radius:30px;
        box-shadow:var(--shadow);
        backdrop-filter:blur(10px);
        overflow:hidden;
    }
    .composer-input{
        width:100%;
        min-height:88px;
        max-height:220px;
        resize:none;
        border:none;
        outline:none;
        background:transparent;
        color:#fff;
        padding:18px 20px 12px;
        font-size:18px;
        line-height:1.5;
        font-family:inherit;
    }
    .composer-input::placeholder{color:#a8a8a8}
    .composer-bottom{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding:0 14px 12px 14px;
    }
    .composer-tools{
        display:flex;
        align-items:center;
        gap:10px;
        color:var(--muted);
        font-size:13px;
        flex-wrap:wrap;
    }
    .composer-pill{
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:8px 10px;
        border-radius:999px;
        border:1px solid rgba(255,255,255,.08);
        background:rgba(255,255,255,.04);
    }
    .send-btn{
        width:46px;
        height:46px;
        border:none;
        border-radius:50%;
        background:#f3f4f6;
        color:#111827;
        font-size:18px;
        cursor:pointer;
        font-weight:700;
    }
    .send-btn[disabled]{opacity:.45; cursor:not-allowed}
    .quick-row{
        width:min(740px, 100%);
        margin:16px auto 0;
        display:flex;
        flex-wrap:wrap;
        justify-content:center;
        gap:10px;
    }
    .jtgpt-page.has-chat .quick-row{
        display:none;
    }
    .quick-btn{
        appearance:none;
        border:1px solid rgba(255,255,255,.08);
        background:rgba(255,255,255,.04);
        color:#efefef;
        border-radius:999px;
        padding:12px 16px;
        cursor:pointer;
        font-size:14px;
    }
    .typing{
        display:inline-flex;
        align-items:center;
        gap:6px;
    }
    .typing i{
        width:7px;
        height:7px;
        border-radius:50%;
        background:#d4d4d8;
        display:block;
        animation:blink 1s infinite ease-in-out;
    }
    .typing i:nth-child(2){animation-delay:.15s}
    .typing i:nth-child(3){animation-delay:.3s}
    @keyframes blink{
        0%, 80%, 100%{opacity:.25; transform:translateY(0)}
        40%{opacity:1; transform:translateY(-2px)}
    }
    @media (max-width: 900px){
        .jtgpt-inner{width:min(100%, calc(100% - 28px));}
        .jtgpt-hero h1{font-size:36px;}
        .composer-input{font-size:16px; min-height:82px;}
    }
</style>
</head>
<body>
<?php if (!$EMBED): ?>
<?php echo dp_sidebar_render('jtgpt'); ?>
<div class="dp-shell-wrap" style="height:100vh; box-sizing:border-box; padding-left:72px; display:flex; flex-direction:column; position:relative; z-index:20;">
    <?php echo dp_render_userbar(['admin_badge_mode' => 'modal', 'admin_iframe_src' => 'admin_settings', 'logout_action' => 'logout']); ?>
    <div style="flex:1; min-height:0;">
<?php endif; ?>
<div class="jtgpt-page" id="jtgptPage">
    <div class="jtgpt-inner">
        <div class="jtgpt-top">
            <div class="jtgpt-badge">✦ JTGPT <span style="opacity:.7">BETA</span></div>
            <div class="jtgpt-status">자연어 메뉴 라우팅 1차 버전</div>
        </div>
        <div class="jtgpt-main">
            <div class="jtgpt-hero">
                <h1>어디서부터 시작할까요?</h1>
                <p>출하, RMA, OQC, IPQC를 자연스럽게 말하면 먼저 맞는 화면으로 연결해볼게요.</p>
            </div>
            <div class="jtgpt-thread" id="jtgptThread"></div>
            <div class="composer-wrap">
                <form class="composer" id="jtgptForm" autocomplete="off">
                    <textarea class="composer-input" id="jtgptInput" name="message" placeholder="무엇이든 물어보세요" rows="1"></textarea>
                    <div class="composer-bottom">
                        <div class="composer-tools">
                            <span class="composer-pill">＋</span>
                            <span class="composer-pill">생각 확장</span>
                            <span class="composer-pill">JTGPT 1차</span>
                        </div>
                        <button class="send-btn" id="jtgptSend" type="submit" aria-label="보내기">↑</button>
                    </div>
                </form>
                <div class="quick-row">
                    <button class="quick-btn" type="button" data-quick="오늘 출하 lot 보여줘">오늘 출하 lot 보여줘</button>
                    <button class="quick-btn" type="button" data-quick="최근 OQC NG 쪽 먼저 보자">최근 OQC NG 쪽 먼저 보자</button>
                    <button class="quick-btn" type="button" data-quick="IPQC 공정능력 화면 열어줘">IPQC 공정능력 화면 열어줘</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if (!$EMBED): ?>
    </div>
</div>
<?php endif; ?>
<script>
(function(){
    var page = document.getElementById('jtgptPage');
    var form = document.getElementById('jtgptForm');
    var input = document.getElementById('jtgptInput');
    var thread = document.getElementById('jtgptThread');
    var send = document.getElementById('jtgptSend');
    var endpoint = <?php echo json_encode($selfUrl, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

    function escapeHtml(str){
        return String(str).replace(/[&<>"']/g, function(ch){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[ch];
        });
    }

    function autoResize(){
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 220) + 'px';
    }

    function ensureChatMode(){
        if (!page.classList.contains('has-chat')) {
            page.classList.add('has-chat');
        }
    }

    function appendMessage(role, text, actions, suggestions){
        ensureChatMode();
        var wrap = document.createElement('div');
        wrap.className = 'msg ' + role;
        var html = '';
        html += '<div class="msg-role">' + (role === 'user' ? '나' : 'JTGPT') + '</div>';
        html += '<div class="bubble">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>';
        if (actions && actions.length) {
            html += '<div class="msg-actions">';
            actions.forEach(function(item){
                html += '<a class="action-btn" href="' + encodeURI(item.href || '#') + '" data-jtgpt-nav="' + escapeHtml(item.href || '#') + '">' + escapeHtml(item.label || '이동') + '</a>';
            });
            html += '</div>';
        }
        if (suggestions && suggestions.length) {
            html += '<div class="msg-suggestions">';
            suggestions.forEach(function(item){
                html += '<button class="chip" type="button" data-jtgpt-suggest="' + escapeHtml(item) + '">' + escapeHtml(item) + '</button>';
            });
            html += '</div>';
        }
        wrap.innerHTML = html;
        thread.appendChild(wrap);
        thread.scrollTop = thread.scrollHeight;
    }

    function appendTyping(){
        ensureChatMode();
        var wrap = document.createElement('div');
        wrap.className = 'msg assistant';
        wrap.id = 'jtgptTyping';
        wrap.innerHTML = '<div class="msg-role">JTGPT</div><div class="bubble"><span class="typing"><i></i><i></i><i></i></span></div>';
        thread.appendChild(wrap);
        thread.scrollTop = thread.scrollHeight;
    }

    function removeTyping(){
        var el = document.getElementById('jtgptTyping');
        if (el) el.remove();
    }

    async function ask(message){
        var text = String(message || '').trim();
        if (!text) return;
        appendMessage('user', text);
        input.value = '';
        autoResize();
        appendTyping();
        send.disabled = true;
        try {
            var res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({message: text})
            });
            var data = await res.json();
            removeTyping();
            appendMessage('assistant', data.answer || '응답을 만들지 못했어요.', data.actions || [], data.suggestions || []);
        } catch (err) {
            removeTyping();
            appendMessage('assistant', '지금은 응답 연결 중 문제가 있어요. 잠시 후 다시 시도해 주세요.', [], ['오늘 출하 lot 보여줘', 'OQC 쪽 보자']);
        } finally {
            send.disabled = false;
            input.focus();
        }
    }

    form.addEventListener('submit', function(e){
        e.preventDefault();
        ask(input.value);
    });

    input.addEventListener('input', autoResize);
    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    document.addEventListener('click', function(e){
        var sug = e.target.closest('[data-jtgpt-suggest]');
        if (sug) {
            ask(sug.getAttribute('data-jtgpt-suggest') || '');
            return;
        }
        var quick = e.target.closest('[data-quick]');
        if (quick) {
            ask(quick.getAttribute('data-quick') || '');
            return;
        }
        var nav = e.target.closest('[data-jtgpt-nav]');
        if (nav) {
            e.preventDefault();
            var href = nav.getAttribute('data-jtgpt-nav') || nav.getAttribute('href') || '#';
            if (window.top && href && href !== '#') {
                window.top.location.href = href;
            }
        }
    });

    autoResize();
})();
</script>
</body>
</html>
