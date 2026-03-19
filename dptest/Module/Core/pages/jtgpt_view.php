<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function jtgpt_bootstrap_once(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
    $candidates = [
        $root . '/bootstrap.php',
        $root . '/config/config.php',
        $root . '/config/db.php',
        $root . '/lib/db.php',
        $root . '/lib/common.php',
        $root . '/common.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
        }
    }
}

jtgpt_bootstrap_once();

function jtgpt_json_response(array $payload): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jtgpt_contains_any(string $text, array $needles): bool {
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_strpos($text, mb_strtolower($needle, 'UTF-8')) !== false) {
            return true;
        }
    }
    return false;
}

function jtgpt_format_int(int $n): string {
    return number_format($n);
}

function jtgpt_first_defined(array $names): ?string {
    foreach ($names as $name) {
        if (defined($name)) {
            $value = constant($name);
            if ($value !== null && $value !== '') return (string)$value;
        }
    }
    return null;
}

function jtgpt_get_pdo_safe(): PDO {
    foreach (['dp_get_pdo', 'ndp_get_pdo', 'get_pdo'] as $fn) {
        if (function_exists($fn)) {
            $pdo = $fn();
            if ($pdo instanceof PDO) return $pdo;
        }
    }

    foreach (['pdo', 'db', 'conn'] as $key) {
        if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof PDO) {
            return $GLOBALS[$key];
        }
    }

    $host = jtgpt_first_defined(['DB_HOST', 'MYSQL_HOST', 'DP_DB_HOST']) ?? '127.0.0.1';
    $port = jtgpt_first_defined(['DB_PORT', 'MYSQL_PORT', 'DP_DB_PORT']) ?? '3306';
    $name = jtgpt_first_defined(['DB_NAME', 'MYSQL_DATABASE', 'DP_DB_NAME', 'DB_DATABASE']) ?? 'dp';
    $user = jtgpt_first_defined(['DB_USER', 'MYSQL_USER', 'DP_DB_USER', 'DB_USERNAME']) ?? 'root';
    $pass = jtgpt_first_defined(['DB_PASS', 'DB_PASSWORD', 'MYSQL_PASSWORD', 'DP_DB_PASS']) ?? '';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function jtgpt_detect_metric(string $normalized): string {
    if (jtgpt_contains_any($normalized, ['제일최근출하일', '제일 최근 출하일', '최근출하일', '최근 출하일', '마지막출하일', '마지막 출하일'])) {
        return 'last_ship_date';
    }
    if (jtgpt_contains_any($normalized, ['lot', '소포장'])) return 'lot_count';
    if (jtgpt_contains_any($normalized, ['tray', '트레이'])) return 'tray_count';
    if (jtgpt_contains_any($normalized, ['있어', '있냐', '있니', '있음'])) return 'exists';
    if (jtgpt_contains_any($normalized, ['수량', 'ea', '개수', 'qty'])) return 'total_qty';
    return 'summary';
}

function jtgpt_detect_range(string $text): ?array {
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $today = $now->format('Y-m-d');

    if (preg_match('/오늘\s*까지/u', $text)) {
        return ['from' => null, 'to' => $today, 'label' => '오늘까지', 'end_only' => true];
    }
    if (preg_match('/어제\s*까지/u', $text)) {
        $d = (clone $now)->modify('-1 day')->format('Y-m-d');
        return ['from' => null, 'to' => $d, 'label' => '어제까지', 'end_only' => true];
    }
    if (preg_match('/어제/u', $text)) {
        $d = (clone $now)->modify('-1 day')->format('Y-m-d');
        return ['from' => $d, 'to' => $d, 'label' => '어제'];
    }
    if (preg_match('/최근\s*7일|최근7일|일주일|1주일/u', $text)) {
        $start = (clone $now)->modify('-6 day')->format('Y-m-d');
        return ['from' => $start, 'to' => $today, 'label' => '최근 7일'];
    }
    if (preg_match('/오늘|금일/u', $text)) {
        return ['from' => $today, 'to' => $today, 'label' => '오늘'];
    }
    return null;
}

function jtgpt_default_today_range(): array {
    $today = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    return ['from' => $today, 'to' => $today, 'label' => '오늘'];
}

function jtgpt_extract_customer(string $message): ?string {
    $lower = mb_strtolower($message, 'UTF-8');
    $aliases = [
        '자화전자' => '자화전자',
        '엘지이노텍' => '엘지이노텍',
        'lg이노텍' => '엘지이노텍',
        'lg innotek' => '엘지이노텍',
    ];
    foreach ($aliases as $needle => $value) {
        if (mb_strpos($lower, mb_strtolower($needle, 'UTF-8')) !== false) return $value;
    }
    return null;
}

function jtgpt_build_where(?array $range, ?string $customer): array {
    $where = [];
    $params = [];

    if ($range !== null) {
        if (!empty($range['from'])) {
            $where[] = 'ship_datetime >= :from_dt';
            $params[':from_dt'] = $range['from'] . ' 00:00:00';
        }
        if (!empty($range['to'])) {
            $where[] = 'ship_datetime < :to_dt';
            $params[':to_dt'] = date('Y-m-d 00:00:00', strtotime($range['to'] . ' +1 day'));
        }
    }

    if ($customer) {
        $where[] = 'ship_to LIKE :ship_to';
        $params[':ship_to'] = '%' . $customer . '%';
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function jtgpt_shipping_reply(PDO $pdo, string $message): string {
    $normalized = mb_strtolower(trim($message), 'UTF-8');
    $metric = jtgpt_detect_metric($normalized);
    $range = jtgpt_detect_range($message);
    $customer = jtgpt_extract_customer($message);

    if (preg_match('/(오늘|어제)\s*까지/u', $message) && in_array($metric, ['summary', 'total_qty', 'lot_count', 'tray_count', 'exists'], true)) {
        return '"오늘까지"를 오늘 하루로 볼지 누적으로 볼지 애매해요. "오늘 출하수량"인지 "오늘까지 누적 출하수량"인지 말해 주세요.';
    }

    if ($metric === 'last_ship_date' && $range === null) {
        [$whereSql, $params] = jtgpt_build_where(null, $customer);
        $st = $pdo->prepare("SELECT MAX(ship_datetime) AS last_ship_datetime FROM ShipingList {$whereSql}");
        $st->execute($params);
        $lastShip = (string)($st->fetchColumn() ?: '');
        if ($lastShip === '' || strpos($lastShip, '0000-00-00') === 0) {
            return '최근 출하일을 찾지 못했어요.';
        }
        $prefix = $customer ? ($customer . ' ') : '전체 출하 기준 ';
        return trim($prefix) . '가장 최근 출하일은 ' . substr($lastShip, 0, 10) . '입니다.';
    }

    if ($range === null) {
        $range = jtgpt_default_today_range();
    }

    [$whereSql, $params] = jtgpt_build_where($range, $customer);
    $st = $pdo->prepare("SELECT COUNT(*) AS row_count, COALESCE(SUM(qty),0) AS total_qty, COUNT(DISTINCT small_pack_no) AS lot_count, COUNT(DISTINCT tray_no) AS tray_count FROM ShipingList {$whereSql}");
    $st->execute($params);
    $row = $st->fetch() ?: [];

    $rowCount = (int)($row['row_count'] ?? 0);
    $totalQty = (int)($row['total_qty'] ?? 0);
    $lotCount = (int)($row['lot_count'] ?? 0);
    $trayCount = (int)($row['tray_count'] ?? 0);
    $prefix = $customer ? ($customer . ' ') : '';

    if ($rowCount <= 0) {
        if ($metric === 'exists') return $prefix . $range['label'] . ' 기준 출하는 없어요.';
        return $prefix . $range['label'] . ' 기준 출하 데이터가 없어요.';
    }

    if ($metric === 'exists') {
        return $prefix . $range['label'] . ' 기준 출하 있어요.';
    }
    if ($metric === 'total_qty') {
        return $prefix . $range['label'] . ' 출하수량은 ' . jtgpt_format_int($totalQty) . ' EA입니다.';
    }
    if ($metric === 'lot_count') {
        return $prefix . $range['label'] . ' 출하 lot는 ' . jtgpt_format_int($lotCount) . '건입니다.';
    }
    if ($metric === 'tray_count') {
        return $prefix . $range['label'] . ' 출하 tray는 ' . jtgpt_format_int($trayCount) . '건입니다.';
    }

    return $prefix . $range['label'] . ' 기준 총 출하수량은 ' . jtgpt_format_int($totalQty) . ' EA이고, lot ' . jtgpt_format_int($lotCount) . '건, tray ' . jtgpt_format_int($trayCount) . '건입니다.';
}

function jtgpt_answer(string $message): string {
    $text = trim($message);
    if ($text === '') return '';

    $normalized = mb_strtolower($text, 'UTF-8');
    $shippingNeedles = ['출하', '출고', 'ship', 'shipping', 'lot', '포장', '납품', '수량', 'qty', 'ea', '최근 출하일', '최근출하일', '마지막출하일', '마지막 출하일', '있어', '있냐', '있니'];
    if (jtgpt_contains_any($normalized, $shippingNeedles)) {
        try {
            $pdo = jtgpt_get_pdo_safe();
            return jtgpt_shipping_reply($pdo, $text);
        } catch (Throwable $e) {
            error_log('[JTGPT] shipping query failed: ' . $e->getMessage());
            return '출하 데이터를 지금 불러오지 못했어요. DB 연결 상태를 먼저 확인해 주세요.';
        }
    }

    return '지금은 UI 먼저 맞추는 단계라, 출하 질문부터 연결해 두었어요.';
}

$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($isAjax) {
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = $_POST;
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
        --bg:#202124;
        --panel:#2c2d30;
        --panel-2:#303134;
        --text:#f2f2f2;
        --muted:#a8adb4;
        --line:rgba(255,255,255,.10);
        --bubble:#2b2c33;
        --bubble-user:#333541;
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
    .topmark{
        position:fixed;left:24px;top:18px;z-index:20;
        font-size:14px;color:#d8d8d8;letter-spacing:.2px;opacity:.9;
    }
    .chat{flex:1;overflow:auto;padding:32px 24px 180px}
    .chat::-webkit-scrollbar{width:10px}
    .chat::-webkit-scrollbar-thumb{background:rgba(255,255,255,.10);border-radius:999px}
    .home{
        min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;
        gap:18px;padding:80px 20px 180px;text-align:center;
    }
    .home.hidden{display:none}
    .home-icon{
        width:40px;height:40px;border-radius:12px;display:grid;place-items:center;
        border:1px solid var(--line);background:rgba(255,255,255,.04);font-size:19px;line-height:1;
    }
    .home-title{font-size:56px;font-weight:600;letter-spacing:-.03em;line-height:1.1;margin:0}
    .messages{display:none;max-width:920px;margin:0 auto}
    .messages.active{display:block}
    .msg{display:flex;margin:0 0 28px}
    .msg.user{justify-content:flex-end}
    .msg.assistant{justify-content:flex-start}
    .label{font-size:12px;color:var(--muted);margin-bottom:8px}
    .bubble-wrap{max-width:min(820px,82%)}
    .bubble{
        padding:18px 20px;border-radius:24px;line-height:1.75;font-size:15px;box-shadow:var(--shadow);
        border:1px solid var(--line);background:var(--bubble);white-space:pre-wrap;word-break:keep-all;
    }
    .msg.user .bubble{background:var(--bubble-user)}
    .composer-wrap{
        position:fixed;left:50%;bottom:22px;transform:translateX(-50%);
        width:min(860px,calc(100vw - 32px));z-index:30;
    }
    .composer{border:1px solid var(--line);background:var(--panel-2);border-radius:28px;box-shadow:var(--shadow);padding:18px 18px 16px}
    .composer textarea{
        width:100%;min-height:78px;max-height:220px;resize:none;border:0;outline:none;background:transparent;
        color:var(--text);font:inherit;font-size:17px;line-height:1.6;
    }
    .composer textarea::placeholder{color:#b5b8be}
    .composer-bottom{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:8px}
    .composer-hint{font-size:12px;color:var(--muted);white-space:nowrap}
    .send{
        width:44px;height:44px;border-radius:999px;border:1px solid var(--line);background:#ececec;color:#111;
        font-size:21px;display:grid;place-items:center;cursor:pointer;flex:0 0 auto;
        transition:transform .12s ease, opacity .12s ease;
    }
    .send:hover{transform:translateY(-1px)}
    .send:active{transform:translateY(0)}
    .send[disabled]{opacity:.45;cursor:default}
    .typing-cursor{display:inline-block;width:1px;height:1.1em;background:rgba(255,255,255,.85);vertical-align:-2px;margin-left:2px;animation:blink 1s step-end infinite}
    @keyframes blink{50%{opacity:0}}
    @media (max-width: 900px){.home-title{font-size:42px}.bubble-wrap{max-width:88%}}
    @media (max-width: 640px){
        .chat{padding:24px 14px 176px}.topmark{left:14px;top:14px}.home-title{font-size:34px}
        .composer-wrap{width:calc(100vw - 16px);bottom:10px}.composer{padding:14px 14px 12px;border-radius:24px}
        .composer textarea{min-height:66px;font-size:16px}.bubble-wrap{max-width:92%}.bubble{padding:16px 17px;border-radius:22px}
    }
</style>
</head>
<body>
<div class="app">
    <div class="topmark">JTGPT</div>

    <div id="chat" class="chat">
        <section id="home" class="home">
            <div class="home-icon">✦</div>
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

    function ensureConversationMode() {
        homeEl.classList.add('hidden');
        messagesEl.classList.add('active');
    }

    function createMessage(role, text = '') {
        ensureConversationMode();
        const item = document.createElement('div');
        item.className = 'msg ' + role;

        const wrap = document.createElement('div');
        wrap.className = 'bubble-wrap';

        const label = document.createElement('div');
        label.className = 'label';
        if (role === 'assistant') {
            label.textContent = 'JTGPT';
        } else {
            label.style.textAlign = 'right';
            label.textContent = '나';
        }
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
            await new Promise(r => setTimeout(r, 16));
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
        } catch (err) {
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
