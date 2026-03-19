<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!defined('JTMES_ROOT')) {
    define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

date_default_timezone_set('Asia/Seoul');

function jtgpt_json_response(array $payload): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jtgpt_bootstrap_pdo(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }

    $candidates = [
        JTMES_ROOT . '/config/dp_config.php',
        JTMES_ROOT . '/config/db.php',
        JTMES_ROOT . '/config/bootstrap.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
        }
    }

    try {
        if (function_exists('dp_get_pdo')) {
            $pdo = dp_get_pdo();
            return $pdo;
        }
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
            return $pdo;
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function jtgpt_ctx_get(): array {
    return is_array($_SESSION['jtgpt_ctx'] ?? null) ? $_SESSION['jtgpt_ctx'] : [];
}

function jtgpt_ctx_set(array $ctx): void {
    $_SESSION['jtgpt_ctx'] = $ctx;
}

function jtgpt_trim_spaces(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function jtgpt_extract_customer_keyword(string $text): ?string {
    $patterns = [
        '/([가-힣A-Za-z0-9()\-]{2,30})\s*(?:의\s*)?(?:제일\s*)?(?:최근|마지막|최신)\s*출하일/u',
        '/([가-힣A-Za-z0-9()\-]{2,30})\s*(?:의\s*)?(?:오늘까지|금일까지|누적)\s*출하(?:수량|량)?/u',
        '/([가-힣A-Za-z0-9()\-]{2,30})\s*(?:의\s*)?(?:오늘|금일|어제)\s*출하(?:수량|량|lot|tray)?/u',
        '/([가-힣A-Za-z0-9()\-]{2,30})\s*(?:의\s*)?출하(?:수량|량|lot|tray|일)?/u',
    ];
    $ban = [
        '오늘','금일','어제','최근','출하','출고','수량','출하수량','누적','lot','tray','ea','제일','마지막','최신','있어',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $candidate = jtgpt_trim_spaces((string)($m[1] ?? ''));
            if ($candidate === '' || in_array(mb_strtolower($candidate, 'UTF-8'), $ban, true) || in_array($candidate, $ban, true)) {
                continue;
            }
            return $candidate;
        }
    }
    return null;
}

function jtgpt_shipping_date_range(string $kind): array {
    $today = new DateTimeImmutable('today');
    if ($kind === 'yesterday') {
        $from = $today->modify('-1 day');
        $to = $today;
        return [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')];
    }
    if ($kind === 'today') {
        $from = $today;
        $to = $today->modify('+1 day');
        return [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')];
    }
    if ($kind === 'recent7') {
        $from = $today->modify('-6 day');
        $to = $today->modify('+1 day');
        return [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')];
    }
    return ['', ''];
}

function jtgpt_shipping_summary(PDO $pdo, ?string $rangeKind, ?string $customer): array {
    [$fromDt, $toDt] = $rangeKind ? jtgpt_shipping_date_range($rangeKind) : ['', ''];
    $where = [];
    $params = [];
    if ($fromDt !== '' && $toDt !== '') {
        $where[] = 'ship_datetime >= :from_dt';
        $where[] = 'ship_datetime < :to_dt';
        $params[':from_dt'] = $fromDt;
        $params[':to_dt'] = $toDt;
    }
    if ($customer !== null && $customer !== '') {
        $where[] = '(ship_to LIKE :kw OR customer_part_no LIKE :kw OR part_name LIKE :kw OR model LIKE :kw OR project LIKE :kw)';
        $params[':kw'] = '%' . $customer . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT COALESCE(SUM(COALESCE(qty,0)),0) AS total_qty,
                   COUNT(DISTINCT NULLIF(TRIM(COALESCE(small_pack_no,'')),'')) AS lot_count,
                   COUNT(DISTINCT NULLIF(TRIM(COALESCE(tray_no,'')),'')) AS tray_count,
                   COUNT(*) AS row_count,
                   MAX(ship_datetime) AS last_ship_datetime
            FROM ShipingList {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total_qty' => (int)($row['total_qty'] ?? 0),
        'lot_count' => (int)($row['lot_count'] ?? 0),
        'tray_count' => (int)($row['tray_count'] ?? 0),
        'row_count' => (int)($row['row_count'] ?? 0),
        'last_ship_datetime' => (string)($row['last_ship_datetime'] ?? ''),
        'range_kind' => $rangeKind,
        'customer' => $customer,
    ];
}

function jtgpt_shipping_last_date(PDO $pdo, ?string $customer): ?string {
    $where = [];
    $params = [];
    if ($customer !== null && $customer !== '') {
        $where[] = '(ship_to LIKE :kw OR customer_part_no LIKE :kw OR part_name LIKE :kw OR model LIKE :kw OR project LIKE :kw)';
        $params[':kw'] = '%' . $customer . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT MAX(ship_datetime) AS last_ship_datetime FROM ShipingList {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $value = (string)$st->fetchColumn();
    return $value !== '' ? $value : null;
}

function jtgpt_parse_shipping_intent(string $message, array $ctx): array {
    $text = jtgpt_trim_spaces($message);
    $lower = mb_strtolower($text, 'UTF-8');

    $hasShip = (bool)preg_match('/출하|출고|ship|shipping/u', $lower);
    $hasQty = (bool)preg_match('/출하수량|출고수량|수량|ea|ea\b/u', $lower);
    $hasLot = (bool)preg_match('/\blot\b|lot수|lot 개수|로트/u', $lower);
    $hasTray = (bool)preg_match('/tray|트레이/u', $lower);
    $hasLastDate = (bool)preg_match('/(?:제일\s*)?(?:최근|마지막|최신)\s*출하일|언제\s*출하/u', $text);
    $asksExist = (bool)preg_match('/있어\?|있나\?|있어$/u', $text);

    $range = null;
    if (preg_match('/오늘까지|금일까지|현재까지|누적/u', $text)) {
        $range = 'until_today';
    } elseif (preg_match('/어제/u', $text)) {
        $range = 'yesterday';
    } elseif (preg_match('/최근\s*7일|최근\s*일주일/u', $text)) {
        $range = 'recent7';
    } elseif (preg_match('/오늘|금일/u', $text)) {
        $range = 'today';
    }

    $customer = jtgpt_extract_customer_keyword($text);

    if ($hasLastDate) {
        return ['type' => 'shipping_last_date', 'range' => null, 'customer' => $customer];
    }

    if ($asksExist && $hasShip) {
        if ($range === null) {
            $range = 'today';
        }
        return ['type' => 'shipping_exists', 'range' => $range, 'customer' => $customer];
    }

    if ($hasShip || $hasQty || $hasLot || $hasTray) {
        if ($range === 'until_today' && !$hasQty && !$hasLot && !$hasTray) {
            return [
                'type' => 'clarify',
                'question' => '“오늘까지 누적 출하수량”을 말하는 건지, “오늘 출하수량”을 말하는 건지 알려주세요.',
            ];
        }

        if ($range === null && ($hasQty || $hasLot || $hasTray)) {
            return [
                'type' => 'clarify',
                'question' => '기준 기간이 빠졌어요. 오늘 / 오늘까지 누적 / 어제 / 최근 7일 중 어떤 기준으로 볼까요?',
            ];
        }

        if ($hasQty) {
            return ['type' => 'shipping_qty', 'range' => $range ?? 'today', 'customer' => $customer];
        }
        if ($hasLot) {
            return ['type' => 'shipping_lot', 'range' => $range ?? 'today', 'customer' => $customer];
        }
        if ($hasTray) {
            return ['type' => 'shipping_tray', 'range' => $range ?? 'today', 'customer' => $customer];
        }

        return [
            'type' => 'clarify',
            'question' => '출하 쪽으로 이해했어요. 수량 / lot / tray / 최근 출하일 중 무엇을 볼까요?',
        ];
    }

    if (($ctx['module'] ?? '') === 'shipping' && preg_match('/최근\s*출하일|마지막/u', $text)) {
        return ['type' => 'shipping_last_date', 'range' => null, 'customer' => $customer ?: ($ctx['customer'] ?? null)];
    }

    return ['type' => 'unknown'];
}

function jtgpt_format_ship_scope(?string $rangeKind, ?string $customer): string {
    $labels = [
        'today' => '오늘',
        'yesterday' => '어제',
        'recent7' => '최근 7일',
        'until_today' => '오늘까지 누적',
        null => '전체 기간',
    ];
    $scope = $labels[$rangeKind] ?? '기준 기간';
    if ($customer !== null && $customer !== '') {
        return $customer . ' / ' . $scope;
    }
    return $scope;
}

function jtgpt_answer_shipping(array $intent): string {
    $pdo = jtgpt_bootstrap_pdo();
    if (!$pdo) {
        return 'DB 연결을 아직 불러오지 못했어요. 설정 파일 연결부터 다시 확인해 주세요.';
    }

    $customer = $intent['customer'] ?? null;
    $type = $intent['type'] ?? 'unknown';
    $range = $intent['range'] ?? null;

    if ($type === 'shipping_last_date') {
        $last = jtgpt_shipping_last_date($pdo, $customer);
        jtgpt_ctx_set([
            'module' => 'shipping',
            'intent' => $type,
            'customer' => $customer,
            'last_ship_datetime' => $last,
        ]);
        if (!$last) {
            return $customer ? $customer . ' 기준 출하 이력을 찾지 못했어요.' : '출하 이력을 찾지 못했어요.';
        }
        $dateOnly = substr($last, 0, 10);
        return $customer
            ? $customer . '의 가장 최근 출하일은 ' . $dateOnly . '입니다.'
            : '가장 최근 출하일은 ' . $dateOnly . '입니다.';
    }

    $queryRange = $range === 'until_today' ? null : $range;
    $summary = jtgpt_shipping_summary($pdo, $queryRange, $customer);
    $scope = jtgpt_format_ship_scope($range, $customer);

    jtgpt_ctx_set([
        'module' => 'shipping',
        'intent' => $type,
        'range' => $range,
        'customer' => $customer,
        'summary' => $summary,
    ]);

    if ((int)$summary['row_count'] === 0) {
        return $scope . ' 기준 출하 데이터가 없습니다.';
    }

    if ($type === 'shipping_exists') {
        return $scope . ' 기준 출하는 있습니다. 총 ' . number_format((int)$summary['total_qty']) . ' EA, lot ' . number_format((int)$summary['lot_count']) . '건입니다.';
    }
    if ($type === 'shipping_qty') {
        return $scope . ' 출하수량은 ' . number_format((int)$summary['total_qty']) . ' EA입니다.';
    }
    if ($type === 'shipping_lot') {
        return $scope . ' 출하 lot는 ' . number_format((int)$summary['lot_count']) . '건입니다.';
    }
    if ($type === 'shipping_tray') {
        return $scope . ' tray는 ' . number_format((int)$summary['tray_count']) . '건입니다.';
    }

    return '출하 질문으로 이해했지만, 아직 처리하지 못한 요청이에요.';
}

function jtgpt_answer(string $message): string {
    $ctx = jtgpt_ctx_get();
    $intent = jtgpt_parse_shipping_intent($message, $ctx);

    if (($intent['type'] ?? '') === 'clarify') {
        return (string)($intent['question'] ?? '어떤 기준으로 볼까요?');
    }
    if (($intent['type'] ?? '') === 'unknown') {
        return '지금은 출하 조회 1차만 먼저 연결했어요. 예를 들면 “오늘 출하수량”, “오늘까지 누적 출하수량”, “자화전자 최근 출하일”처럼 물어보면 됩니다.';
    }

    try {
        return jtgpt_answer_shipping($intent);
    } catch (Throwable $e) {
        return '출하 데이터를 읽는 중 문제가 생겼어요. 조회 조건을 조금만 바꿔서 다시 물어봐 주세요.';
    }
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
        'answer' => jtgpt_answer($message),
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
