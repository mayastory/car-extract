<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) {
    define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}
date_default_timezone_set('Asia/Seoul');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/inc/common.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();

$EMBED = !empty($_GET['embed']);
if (!$EMBED) {
    require_once JTMES_ROOT . '/inc/sidebar.php';
    require_once JTMES_ROOT . '/inc/dp_userbar.php';
}

function jtgpt_h(?string $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function jtgpt_contains_any(string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function jtgpt_format_int($v): string {
    return number_format((int)$v);
}

function jtgpt_get_pdo(): PDO {
    if (function_exists('dp_get_pdo')) {
        $pdo = dp_get_pdo();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (function_exists('ndp_get_pdo')) {
        $pdo = ndp_get_pdo();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    throw new RuntimeException('dp_get_pdo not found');
}

function jtgpt_detect_time_hint(string $text): ?string {
    $map = [
        '오늘' => ['오늘', '금일', 'today', 'now'],
        '어제' => ['어제', 'yesterday'],
        '이번 주' => ['이번주', '이번 주', '금주', 'this week'],
        '최근 7일' => ['최근 7일', '최근7일', '7일', '일주일', '1주일', '최근 일주일'],
        '이번 달' => ['이번달', '이번 달', '금월', 'this month'],
    ];
    foreach ($map as $label => $needles) {
        if (jtgpt_contains_any($text, $needles)) return $label;
    }
    return null;
}

function jtgpt_try_parse_ymd(string $y, string $m, string $d): ?string {
    $m = str_pad((string)((int)$m), 2, '0', STR_PAD_LEFT);
    $d = str_pad((string)((int)$d), 2, '0', STR_PAD_LEFT);
    $ymd = trim($y) . '-' . $m . '-' . $d;
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt || $dt->format('Y-m-d') !== $ymd) return null;
    return $ymd;
}

function jtgpt_detect_date_range(string $text): ?array {
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $today = $now->format('Y-m-d');

    if (preg_match_all('/(20\d{2})[\.\/-]?(\d{1,2})[\.\/-]?(\d{1,2})/', $text, $m, PREG_SET_ORDER)) {
        $dates = [];
        foreach ($m as $hit) {
            $ymd = jtgpt_try_parse_ymd($hit[1], $hit[2], $hit[3]);
            if ($ymd !== null) $dates[] = $ymd;
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
        if (count($dates) >= 2) {
            return ['from' => $dates[0], 'to' => end($dates), 'label' => $dates[0] . ' ~ ' . end($dates), 'implicit' => false];
        }
        if (count($dates) === 1) {
            return ['from' => $dates[0], 'to' => $dates[0], 'label' => $dates[0], 'implicit' => false];
        }
    }

    if (preg_match('/오늘\s*까지/u', $text)) {
        return ['from' => null, 'to' => $today, 'label' => '오늘까지', 'implicit' => false, 'end_only' => true];
    }
    if (preg_match('/어제\s*까지/u', $text)) {
        $d = (clone $now)->modify('-1 day')->format('Y-m-d');
        return ['from' => null, 'to' => $d, 'label' => '어제까지', 'implicit' => false, 'end_only' => true];
    }

    $hint = jtgpt_detect_time_hint($text);
    if ($hint === '오늘') return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => false];
    if ($hint === '어제') {
        $d = (clone $now)->modify('-1 day')->format('Y-m-d');
        return ['from' => $d, 'to' => $d, 'label' => '어제', 'implicit' => false];
    }
    if ($hint === '이번 주') {
        $start = (clone $now)->modify('monday this week')->format('Y-m-d');
        return ['from' => $start, 'to' => $today, 'label' => '이번 주', 'implicit' => false];
    }
    if ($hint === '최근 7일') {
        $start = (clone $now)->modify('-6 day')->format('Y-m-d');
        return ['from' => $start, 'to' => $today, 'label' => '최근 7일', 'implicit' => false];
    }
    if ($hint === '이번 달') {
        $start = (clone $now)->modify('first day of this month')->format('Y-m-d');
        return ['from' => $start, 'to' => $today, 'label' => '이번 달', 'implicit' => false];
    }
    return null;
}

function jtgpt_default_today_range(): array {
    $today = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => true];
}

function jtgpt_detect_metric(string $normalized): string {
    if (jtgpt_contains_any($normalized, ['제일최근출하일', '제일 최근 출하일', '최근출하일', '최근 출하일', '마지막출하일', '마지막 출하일', '최종출하일', '최종 출하일'])) {
        return 'last_ship_date';
    }
    if (jtgpt_contains_any($normalized, ['lot', '소포장'])) return 'lot_count';
    if (jtgpt_contains_any($normalized, ['tray', '트레이'])) return 'tray_count';
    if (jtgpt_contains_any($normalized, ['있어', '있냐', '있니', '있음'])) return 'exists';
    if (jtgpt_contains_any($normalized, ['누적', '합계', '총합'])) return 'summary';
    if (jtgpt_contains_any($normalized, ['수량', 'qty', 'ea', '개수'])) return 'total_qty';
    return 'summary';
}

function jtgpt_extract_part_name(string $message): ?string {
    $original = trim($message);
    if ($original === '') return null;
    $aliasMap = [
        'ir base' => 'MEM-IR-BASE',
        'irbase' => 'MEM-IR-BASE',
        'x carrier' => 'MEM-X-CARRIER',
        'x-carrier' => 'MEM-X-CARRIER',
        'y carrier' => 'MEM-Y-CARRIER',
        'y-carrier' => 'MEM-Y-CARRIER',
        'z carrier' => 'MEM-Z-CARRIER',
        'z-carrier' => 'MEM-Z-CARRIER',
        'z stopper' => 'MEM-Z-STOPPER',
        'z-stopper' => 'MEM-Z-STOPPER',
    ];
    $lower = mb_strtolower($original, 'UTF-8');
    foreach ($aliasMap as $needle => $partName) {
        if (mb_strpos($lower, $needle) !== false) return $partName;
    }
    if (preg_match('/\b(MEM-[A-Z0-9\.\-]+)\b/i', $original, $m)) {
        return strtoupper(trim($m[1]));
    }
    return null;
}

function jtgpt_extract_customer(string $message): ?string {
    $lower = mb_strtolower(trim($message), 'UTF-8');
    if ($lower === '') return null;
    $aliases = [
        '자화전자' => '자화전자',
        '엘지이노텍' => '엘지이노텍',
        'lg이노텍' => '엘지이노텍',
        'lg innotek' => '엘지이노텍',
    ];
    foreach ($aliases as $needle => $value) {
        if (mb_strpos($lower, mb_strtolower($needle, 'UTF-8')) !== false) {
            return $value;
        }
    }
    return null;
}

function jtgpt_build_ship_where(?array $range = null, ?string $partName = null, ?string $customer = null): array {
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
    if ($partName !== null && $partName !== '') {
        $where[] = 'part_name LIKE :part_name';
        $params[':part_name'] = '%' . $partName . '%';
    }
    if ($customer !== null && $customer !== '') {
        $where[] = 'ship_to LIKE :ship_to';
        $params[':ship_to'] = '%' . $customer . '%';
    }
    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}

function jtgpt_shipping_reply(PDO $pdo, string $message): array {
    $text = trim($message);
    $normalized = mb_strtolower($text, 'UTF-8');
    $metric = jtgpt_detect_metric($normalized);
    $range = jtgpt_detect_date_range($text);
    $partName = jtgpt_extract_part_name($text);
    $customer = jtgpt_extract_customer($text);

    if (preg_match('/(오늘|어제)\s*까지/u', $text) && in_array($metric, ['total_qty', 'lot_count', 'tray_count', 'summary', 'exists'], true)) {
        return ['answer' => '"오늘까지"를 오늘 하루로 볼지, 오늘까지 누적으로 볼지 애매해요. "오늘 출하수량"인지 "오늘까지 누적 출하수량"인지 말해 주세요.'];
    }

    if ($metric === 'last_ship_date' && $range === null) {
        [$whereSql, $params] = jtgpt_build_ship_where(null, $partName, $customer);
        $st = $pdo->prepare("SELECT MAX(ship_datetime) AS last_ship_datetime FROM ShipingList {$whereSql}");
        $st->execute($params);
        $lastShip = (string)($st->fetchColumn() ?: '');
        if ($lastShip === '' || strpos($lastShip, '0000-00-00') === 0) {
            return ['answer' => '최근 출하일을 찾지 못했어요.'];
        }
        $prefix = '';
        if ($customer) $prefix .= $customer . ' ';
        if ($partName) $prefix .= $partName . ' ';
        if ($prefix === '') $prefix = '전체 출하 기준으로 ';
        return ['answer' => trim($prefix) . '가장 최근 출하일은 ' . substr($lastShip, 0, 10) . '입니다.'];
    }

    if ($range === null) {
        $range = jtgpt_default_today_range();
    }

    [$whereSql, $params] = jtgpt_build_ship_where($range, $partName, $customer);
    $sql = "SELECT COUNT(*) AS row_count, COALESCE(SUM(qty), 0) AS total_qty, COUNT(DISTINCT small_pack_no) AS lot_count, COUNT(DISTINCT tray_no) AS tray_count, COUNT(DISTINCT part_name) AS part_count FROM ShipingList {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $rowCount = (int)($summary['row_count'] ?? 0);
    $totalQty = (int)($summary['total_qty'] ?? 0);
    $lotCount = (int)($summary['lot_count'] ?? 0);
    $trayCount = (int)($summary['tray_count'] ?? 0);
    $subject = [];
    if ($customer) $subject[] = $customer;
    if ($partName) $subject[] = $partName;
    $subjectPrefix = $subject ? implode(' / ', $subject) . ' ' : '';

    if ($rowCount <= 0) {
        if ($metric === 'exists') {
            return ['answer' => $subjectPrefix . $range['label'] . ' 기준 출하는 없어요.'];
        }
        return ['answer' => $subjectPrefix . $range['label'] . ' 기준 출하 데이터가 없어요.'];
    }

    if ($metric === 'exists') {
        return ['answer' => $subjectPrefix . $range['label'] . ' 기준 출하 있어요. 총 ' . jtgpt_format_int($totalQty) . ' EA입니다.'];
    }
    if ($metric === 'total_qty') {
        return ['answer' => $subjectPrefix . $range['label'] . ' 출하수량은 ' . jtgpt_format_int($totalQty) . ' EA입니다.'];
    }
    if ($metric === 'lot_count') {
        return ['answer' => $subjectPrefix . $range['label'] . ' 출하 lot는 ' . jtgpt_format_int($lotCount) . '건입니다.'];
    }
    if ($metric === 'tray_count') {
        return ['answer' => $subjectPrefix . $range['label'] . ' 출하 tray는 ' . jtgpt_format_int($trayCount) . '건입니다.'];
    }

    return ['answer' => $subjectPrefix . $range['label'] . ' 기준 총 출하수량은 ' . jtgpt_format_int($totalQty) . ' EA이고, lot ' . jtgpt_format_int($lotCount) . '건, tray ' . jtgpt_format_int($trayCount) . '건입니다.'];
}

function jtgpt_parse_message(string $message): array {
    $text = trim($message);
    if ($text === '') {
        return ['answer' => ''];
    }
    $normalized = mb_strtolower($text, 'UTF-8');
    $shippingNeedles = ['출하', '출고', 'ship', 'shipping', 'lot', '포장', '납품', '수량', 'qty', 'ea', '최근 출하일', '최근출하일', '마지막출하일', '마지막 출하일'];
    if (jtgpt_contains_any($normalized, $shippingNeedles)) {
        try {
            $pdo = jtgpt_get_pdo();
            return jtgpt_shipping_reply($pdo, $text);
        } catch (Throwable $e) {
            return ['answer' => '출하 데이터를 조회하는 중 오류가 났어요. ' . $e->getMessage()];
        }
    }
    return ['answer' => '지금은 출하 질문부터 먼저 정확하게 처리하고 있어요.'];
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
        if (is_array($decoded)) $payload = $decoded;
    }
    if (!$payload) $payload = $_POST;
    $message = trim((string)($payload['message'] ?? ''));
    $result = jtgpt_parse_message($message);
    echo json_encode([
        'ok' => true,
        'answer' => (string)($result['answer'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$selfUrl = $EMBED ? 'jtgpt_view.php?embed=1' : dp_url('jtgpt');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JTGPT</title>
<style>
:root{
    --bg:#1f1f22;
    --panel:#2a2b31;
    --panel-2:#2e3037;
    --text:#f5f5f7;
    --muted:#a8a8b3;
    --border:rgba(255,255,255,.08);
    --shadow:0 16px 48px rgba(0,0,0,.30);
}
*{box-sizing:border-box}
html,body{height:100%}
body{
    margin:0;
    color:var(--text);
    font-family:Segoe UI, Apple SD Gothic Neo, Malgun Gothic, sans-serif;
    background:
        radial-gradient(circle at 50% 22%, rgba(92,151,255,.12), transparent 22%),
        linear-gradient(90deg, rgba(0,255,120,.06), transparent 18%, rgba(0,255,120,.02) 50%, transparent 82%, rgba(0,255,120,.06)),
        #0d1311;
}
body::before{
    content:'';
    position:fixed; inset:0;
    pointer-events:none;
    background-image:repeating-linear-gradient(90deg, transparent 0 38px, rgba(91,255,145,.035) 38px 39px), repeating-linear-gradient(180deg, transparent 0 44px, rgba(91,255,145,.02) 44px 45px);
    opacity:.45;
}
.jtgpt-shell{height:100vh; position:relative; z-index:1;}
<?php if (!$EMBED): ?>
.dp-shell-wrap{height:100vh; box-sizing:border-box; padding-left:72px; display:flex; flex-direction:column; position:relative; z-index:20;}
.dp-shell-main{flex:1; min-height:0;}
<?php endif; ?>
.jtgpt-page{
    min-height:100%;
    display:flex;
    flex-direction:column;
}
.jtgpt-inner{
    width:min(760px, calc(100% - 32px));
    margin:0 auto;
    display:flex;
    flex-direction:column;
    flex:1;
    min-height:0;
}
.jtgpt-top{
    padding:16px 0 10px;
}
.jtgpt-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 16px;
    border:1px solid rgba(255,255,255,.16);
    border-radius:999px;
    background:rgba(255,255,255,.03);
    font-size:14px;
}
.jtgpt-main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}
.jtgpt-page:not(.has-chat) .jtgpt-main{
    justify-content:center;
    padding-bottom:12vh;
}
.jtgpt-page.has-chat .jtgpt-main{
    justify-content:flex-end;
    padding-bottom:18px;
}
.jtgpt-hero{
    text-align:center;
    margin:0 0 28px;
}
.jtgpt-hero h1{
    margin:0;
    font-size:56px;
    font-weight:500;
    letter-spacing:-0.03em;
}
.jtgpt-thread{
    display:none;
    flex:1;
    min-height:0;
    overflow:auto;
    padding:8px 0 24px;
    gap:20px;
}
.jtgpt-page.has-chat .jtgpt-thread{
    display:flex;
    flex-direction:column;
}
.jtgpt-page.has-chat .jtgpt-hero{
    display:none;
}
.msg{display:flex; flex-direction:column; gap:8px; max-width:78%;}
.msg.assistant{align-self:flex-start;}
.msg.user{align-self:flex-end; align-items:flex-end;}
.msg-role{font-size:12px; color:#c9cad2; opacity:.9; padding:0 6px;}
.bubble{
    border:1px solid var(--border);
    background:rgba(39,42,49,.92);
    border-radius:22px;
    padding:18px 20px;
    line-height:1.7;
    font-size:16px;
    box-shadow:var(--shadow);
    white-space:pre-wrap;
    word-break:break-word;
}
.msg.user .bubble{background:rgba(56,59,68,.96)}
.composer-wrap{
    width:100%;
}
.composer{
    width:100%;
    background:rgba(43,46,56,.95);
    border:1px solid rgba(255,255,255,.08);
    border-radius:30px;
    box-shadow:var(--shadow);
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
    padding:18px 20px 10px;
    font-size:18px;
    line-height:1.5;
    font-family:inherit;
}
.composer-input::placeholder{color:#aaa}
.composer-bottom{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    padding:0 14px 14px;
}
.send-btn{
    width:46px;
    height:46px;
    border:none;
    border-radius:50%;
    background:#f3f4f6;
    color:#111827;
    font-size:20px;
    cursor:pointer;
    font-weight:700;
}
.send-btn[disabled]{opacity:.45; cursor:not-allowed}
.typing{display:inline-flex; align-items:center; gap:6px}
.typing i{width:7px; height:7px; border-radius:50%; background:#d4d4d8; display:block; animation:blink 1s infinite ease-in-out}
.typing i:nth-child(2){animation-delay:.15s}
.typing i:nth-child(3){animation-delay:.3s}
@keyframes blink{0%,80%,100%{opacity:.25; transform:translateY(0)} 40%{opacity:1; transform:translateY(-2px)}}
@media (max-width: 900px){
    .jtgpt-inner{width:min(100%, calc(100% - 24px));}
    .jtgpt-hero h1{font-size:32px;}
    .msg{max-width:92%;}
    .composer-input{font-size:16px; min-height:84px;}
}
</style>
</head>
<body>
<?php if (!$EMBED): ?>
<?php echo dp_sidebar_render('jtgpt'); ?>
<div class="dp-shell-wrap">
    <?php echo dp_render_userbar(['admin_badge_mode' => 'modal', 'admin_iframe_src' => 'admin_settings', 'logout_action' => 'logout']); ?>
    <div class="dp-shell-main">
<?php endif; ?>
<div class="jtgpt-page" id="jtgptPage">
    <div class="jtgpt-inner">
        <div class="jtgpt-top">
            <div class="jtgpt-badge">✦ JTGPT <span style="opacity:.72">BETA</span></div>
        </div>
        <div class="jtgpt-main">
            <div class="jtgpt-hero">
                <h1>지금 무슨 생각을 하시나요?</h1>
            </div>
            <div class="jtgpt-thread" id="jtgptThread"></div>
            <div class="composer-wrap">
                <form class="composer" id="jtgptForm" autocomplete="off">
                    <textarea class="composer-input" id="jtgptInput" name="message" placeholder="무엇이든 물어보세요" rows="1"></textarea>
                    <div class="composer-bottom">
                        <button type="submit" class="send-btn" id="jtgptSend">↑</button>
                    </div>
                </form>
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
    const page = document.getElementById('jtgptPage');
    const thread = document.getElementById('jtgptThread');
    const form = document.getElementById('jtgptForm');
    const input = document.getElementById('jtgptInput');
    const send = document.getElementById('jtgptSend');
    const endpoint = <?php echo json_encode($selfUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function autoresize(){
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 220) + 'px';
    }

    function ensureChatMode(){
        page.classList.add('has-chat');
    }

    function scrollToBottom(){
        thread.scrollTop = thread.scrollHeight;
    }

    function createMessage(role, html){
        const wrap = document.createElement('div');
        wrap.className = 'msg ' + role;
        const roleEl = document.createElement('div');
        roleEl.className = 'msg-role';
        roleEl.textContent = role === 'user' ? '나' : 'JTGPT';
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.innerHTML = html || '';
        wrap.appendChild(roleEl);
        wrap.appendChild(bubble);
        thread.appendChild(wrap);
        scrollToBottom();
        return bubble;
    }

    function escapeHtml(text){
        return text.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    }

    function nl2br(text){
        return escapeHtml(text).replace(/\n/g, '<br>');
    }

    function showTyping(){
        ensureChatMode();
        return createMessage('assistant', '<span class="typing"><i></i><i></i><i></i></span>');
    }

    function typeOut(el, text){
        return new Promise(resolve => {
            const chars = Array.from(text || '');
            let out = '';
            let i = 0;
            function step(){
                if (i >= chars.length) {
                    el.innerHTML = nl2br(out);
                    scrollToBottom();
                    resolve();
                    return;
                }
                const chunk = Math.max(1, Math.min(4, Math.floor(chars.length / 80) || 1));
                for (let c = 0; c < chunk && i < chars.length; c++, i++) {
                    out += chars[i];
                }
                el.innerHTML = nl2br(out);
                scrollToBottom();
                setTimeout(step, 12);
            }
            step();
        });
    }

    async function submitMessage(message){
        const text = (message || '').trim();
        if (!text) return;
        ensureChatMode();
        createMessage('user', nl2br(text));
        input.value = '';
        autoresize();
        send.disabled = true;
        const bubble = showTyping();
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({message: text})
            });
            const data = await res.json();
            const answer = (data && data.answer) ? String(data.answer) : '응답을 받지 못했어요.';
            await typeOut(bubble, answer);
        } catch (err) {
            await typeOut(bubble, '질문을 처리하는 중 오류가 났어요.');
        } finally {
            send.disabled = false;
            input.focus();
        }
    }

    form.addEventListener('submit', function(e){
        e.preventDefault();
        submitMessage(input.value);
    });
    input.addEventListener('input', autoresize);
    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });
    autoresize();
})();
</script>
</body>
</html>
