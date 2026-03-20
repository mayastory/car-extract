<?php
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

function jtgpt_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function jtgpt_json(array $data): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function jtgpt_contains_any(string $text, array $needles): bool {
    foreach ($needles as $n) {
        if ($n !== '' && mb_strpos($text, $n, 0, 'UTF-8') !== false) return true;
    }
    return false;
}
function jtgpt_find_pdo(): ?PDO {
    static $booted = false;
    foreach (['pdo'] as $g) {
        if (isset($GLOBALS[$g]) && $GLOBALS[$g] instanceof PDO) return $GLOBALS[$g];
    }
    foreach (['dp_get_pdo', 'get_pdo'] as $fn) {
        if (function_exists($fn)) {
            try {
                $pdo = $fn();
                if ($pdo instanceof PDO) return $pdo;
            } catch (Throwable $e) {
            }
        }
    }
    if (!$booted) {
        $booted = true;
        $root = dirname(__DIR__, 3);
        $candidates = [
            $root . '/config/config.php',
            $root . '/config/db.php',
            $root . '/bootstrap.php',
            $root . '/init.php',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) { @include_once $f; }
        }
        foreach (['pdo'] as $g) {
            if (isset($GLOBALS[$g]) && $GLOBALS[$g] instanceof PDO) return $GLOBALS[$g];
        }
        foreach (['dp_get_pdo', 'get_pdo'] as $fn) {
            if (function_exists($fn)) {
                try {
                    $pdo = $fn();
                    if ($pdo instanceof PDO) return $pdo;
                } catch (Throwable $e) {
                }
            }
        }
    }
    return null;
}
function jtgpt_fmt(int $n): string { return number_format($n); }
function jtgpt_alias_customer(?string $v): ?string {
    if ($v === null || $v === '') return null;
    $map = [
        'lg' => '엘지이노텍',
        'lg이노텍' => '엘지이노텍',
        '엘지' => '엘지이노텍',
        '엘지이노텍' => '엘지이노텍',
        '자화전자' => '자화전자',
    ];
    $k = mb_strtolower(trim($v), 'UTF-8');
    return $map[$k] ?? trim($v);
}
function jtgpt_detect_customer(string $text): ?string {
    $map = [
        'lg innotek' => '엘지이노텍',
        'lg이노텍' => '엘지이노텍',
        '엘지이노텍' => '엘지이노텍',
        '엘지' => '엘지이노텍',
        'lg' => '엘지이노텍',
        '자화전자' => '자화전자',
    ];
    $lower = mb_strtolower($text, 'UTF-8');
    foreach ($map as $needle => $value) {
        if (mb_strpos($lower, $needle, 0, 'UTF-8') !== false) return $value;
    }
    return null;
}
function jtgpt_detect_metric(string $text): ?string {
    if (jtgpt_contains_any($text, ['제일최근출하일','제일 최근 출하일','최근출하일','최근 출하일','마지막출하일','마지막 출하일','제일 최근','최근'])) return 'last_ship_date';
    if (jtgpt_contains_any($text, ['lot','로트','소포장'])) return 'lot_count';
    if (jtgpt_contains_any($text, ['tray','트레이'])) return 'tray_count';
    if (jtgpt_contains_any($text, ['수량','ea','qty','개수'])) return 'total_qty';
    if (jtgpt_contains_any($text, ['있나','있어','있냐','출하내역','출하했어','출하 있어'])) return 'exists';
    if (jtgpt_contains_any($text, ['출하','출고','ship','shipping'])) return 'summary';
    return null;
}
function jtgpt_detect_range(string $text): ?array {
    $tz = new DateTimeZone('Asia/Seoul');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    if (preg_match('/오늘\s*까지|오늘까지\s*누적|누적/u', $text)) {
        return ['from' => null, 'to' => $today, 'label' => '오늘까지 누적', 'cumulative' => true];
    }
    if (preg_match('/어제/u', $text)) {
        $d = (clone $now)->modify('-1 day')->format('Y-m-d');
        return ['from' => $d, 'to' => $d, 'label' => '어제', 'cumulative' => false];
    }
    if (preg_match('/최근\s*7일|일주일|1주일/u', $text)) {
        $d = (clone $now)->modify('-6 day')->format('Y-m-d');
        return ['from' => $d, 'to' => $today, 'label' => '최근 7일', 'cumulative' => false];
    }
    if (preg_match('/오늘|금일/u', $text)) {
        return ['from' => $today, 'to' => $today, 'label' => '오늘', 'cumulative' => false];
    }
    return null;
}
function jtgpt_build_where(?array $range, ?string $customer): array {
    $where = [];
    $params = [];
    if ($range) {
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
    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}
function jtgpt_is_shipping(string $text, ?array $ctx): bool {
    if (jtgpt_contains_any($text, ['출하','출고','ship','shipping','lot','tray','소포장','납품'])) return true;
    if ($ctx && ($ctx['module'] ?? '') === 'shipping') {
        if (jtgpt_detect_metric($text) || jtgpt_detect_range($text) || jtgpt_detect_customer($text)) return true;
        $short = preg_replace('/\s+/u', '', $text);
        if (in_array($short, ['오늘','어제','최근7일','수량','lot','tray','lg','엘지','엘지이노텍','자화전자'], true)) return true;
    }
    return false;
}
function jtgpt_shipping_answer(PDO $pdo, string $text, array &$ctx): array {
    $metric = jtgpt_detect_metric($text) ?: ($ctx['metric'] ?? 'summary');
    $customer = jtgpt_detect_customer($text) ?: ($ctx['customer'] ?? null);
    $range = jtgpt_detect_range($text);
    if (!$range) {
        if ($metric === 'last_ship_date') {
            $range = null;
        } else {
            $range = $ctx['range'] ?? null;
        }
    }
    if (!$range && $metric !== 'last_ship_date') {
        $tz = new DateTimeZone('Asia/Seoul');
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        $range = ['from' => $today, 'to' => $today, 'label' => '오늘', 'cumulative' => false];
    }
    $ctx = [
        'module' => 'shipping',
        'metric' => $metric,
        'customer' => $customer,
        'range' => $range,
    ];

    if ($metric === 'last_ship_date') {
        [$whereSql, $params] = jtgpt_build_where(null, $customer);
        $sql = "SELECT MAX(ship_datetime) AS last_ship_datetime FROM ShipingList {$whereSql}";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $last = (string)($st->fetchColumn() ?: '');
        if ($last === '' || strpos($last, '0000-00-00') === 0) {
            return ['answer' => ($customer ? $customer . ' ' : '') . '기준 최근 출하일을 찾지 못했어요.'];
        }
        return ['answer' => ($customer ? $customer . ' ' : '전체 ') . '기준 최근 출하일은 ' . substr($last, 0, 10) . '입니다.'];
    }

    [$whereSql, $params] = jtgpt_build_where($range, $customer);
    $sql = "SELECT COUNT(*) AS row_count, COALESCE(SUM(qty),0) AS total_qty, COUNT(DISTINCT small_pack_no) AS lot_count, COUNT(DISTINCT tray_no) AS tray_count FROM ShipingList {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $rowCount = (int)($row['row_count'] ?? 0);
    $totalQty = (int)($row['total_qty'] ?? 0);
    $lotCount = (int)($row['lot_count'] ?? 0);
    $trayCount = (int)($row['tray_count'] ?? 0);
    $prefix = $customer ? ($customer . ' ') : '';
    $label = $range['label'] ?? '오늘';

    if ($metric === 'exists') {
        return ['answer' => $prefix . $label . ' 출하내역은 ' . ($rowCount > 0 ? '있어요.' : '없어요.')];
    }
    if ($rowCount <= 0) {
        return ['answer' => $prefix . $label . ' 기준 출하 데이터가 없어요.'];
    }
    if ($metric === 'total_qty') {
        return ['answer' => $prefix . $label . ' 출하수량은 ' . jtgpt_fmt($totalQty) . ' EA입니다.'];
    }
    if ($metric === 'lot_count') {
        return ['answer' => $prefix . $label . ' 출하 lot는 ' . jtgpt_fmt($lotCount) . '건입니다.'];
    }
    if ($metric === 'tray_count') {
        return ['answer' => $prefix . $label . ' 출하 tray는 ' . jtgpt_fmt($trayCount) . '건입니다.'];
    }
    return ['answer' => $prefix . $label . ' 기준 출하수량은 ' . jtgpt_fmt($totalQty) . ' EA이고, lot ' . jtgpt_fmt($lotCount) . '건, tray ' . jtgpt_fmt($trayCount) . '건입니다.'];
}
function jtgpt_mock_answer(string $text): array {
    return ['answer' => '아직 이 질문은 연결 전이에요. 지금은 출하 조회부터 먼저 붙이는 중이에요.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim((string)($_POST['message'] ?? ''));
    if ($message === '') jtgpt_json(['ok' => false, 'answer' => '']);
    $ctx = $_SESSION['jtgpt_ctx'] ?? null;
    try {
        if (jtgpt_is_shipping($message, is_array($ctx) ? $ctx : null)) {
            $pdo = jtgpt_find_pdo();
            if (!$pdo) {
                $_SESSION['jtgpt_ctx'] = ['module' => 'shipping'];
                jtgpt_json(['ok' => true, 'answer' => '지금은 DB 연결을 못 잡아서 출하 조회를 바로 못 하고 있어요.']);
            }
            $newCtx = is_array($ctx) ? $ctx : [];
            $res = jtgpt_shipping_answer($pdo, $message, $newCtx);
            $_SESSION['jtgpt_ctx'] = $newCtx;
            jtgpt_json(['ok' => true, 'answer' => $res['answer']]);
        }
        unset($_SESSION['jtgpt_ctx']);
        $res = jtgpt_mock_answer($message);
        jtgpt_json(['ok' => true, 'answer' => $res['answer']]);
    } catch (Throwable $e) {
        jtgpt_json(['ok' => true, 'answer' => '지금은 응답을 불러오지 못했어요.']);
    }
}
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>JTGPT</title>
<style>
:root{
  --bg:#191b20;
  --panel:#2a2d35;
  --panel2:#21242b;
  --line:rgba(255,255,255,.08);
  --text:#f2f2f3;
  --muted:#aeb3bf;
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:Segoe UI, Arial, sans-serif;background:var(--bg);color:var(--text)}
.page{min-height:100%;display:flex;flex-direction:column}
.hero{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 24px 220px}
.hero.hidden{display:none}
.hero-inner{text-align:center}
.hero-icon{width:40px;height:40px;margin:0 auto 18px;border:1px solid var(--line);border-radius:13px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.03);font-size:17px}
.hero-title{font-size:38px;font-weight:700;letter-spacing:-.04em;line-height:1.12;margin:0}
.chat{display:none;flex:1;padding:26px 24px 220px;max-width:1100px;width:100%;margin:0 auto}
.chat.active{display:block}
.msg{display:flex;margin:12px 0}
.msg.user{justify-content:flex-end}
.msg.assistant{justify-content:flex-start}
.bubble{max-width:min(72%,780px);padding:15px 18px;border-radius:20px;border:1px solid var(--line);background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.03));font-size:16px;line-height:1.55;letter-spacing:-.01em;white-space:pre-wrap;word-break:break-word}
.msg.user .bubble{max-width:360px;background:linear-gradient(180deg,#343844,#2f333c)}
.msg-label{font-size:12px;color:#b8bdc9;margin:0 0 6px 10px}
.composer-wrap{position:fixed;left:0;right:0;bottom:20px;padding:0 24px}
.composer{max-width:780px;margin:0 auto;background:linear-gradient(180deg,#30333b,#2b2e36);border:1px solid rgba(255,255,255,.08);border-radius:26px;padding:18px 18px 12px;box-shadow:0 10px 24px rgba(0,0,0,.18)}
textarea{width:100%;min-height:74px;max-height:220px;resize:none;border:0;outline:0;background:transparent;color:var(--text);font:inherit;font-size:18px;line-height:1.5}
textarea::placeholder{color:#a5aab6}
.composer-bar{display:flex;align-items:center;justify-content:space-between;margin-top:8px}
.helper{font-size:12px;color:#b1b6c2}
.send{width:40px;height:40px;border-radius:999px;border:0;background:#f4f4f5;color:#17191e;font-size:18px;cursor:pointer}
.send:disabled{opacity:.55;cursor:default}
@media (max-width: 900px){
  .hero{padding-bottom:200px}
  .hero-title{font-size:32px}
  .chat{padding-left:16px;padding-right:16px;padding-bottom:200px}
  .composer-wrap{padding:0 16px 16px}
  .bubble{max-width:84%}
}
</style>
</head>
<body>
<div class="page">
  <section class="hero" id="hero">
    <div class="hero-inner">
      <div class="hero-icon">✦</div>
      <h1 class="hero-title">무엇을 도와드릴까요?</h1>
    </div>
  </section>
  <main class="chat" id="chat"></main>
  <div class="composer-wrap">
    <form class="composer" id="chatForm">
      <textarea id="messageInput" placeholder="무엇이든 물어보세요" spellcheck="false"></textarea>
      <div class="composer-bar">
        <div class="helper">Enter로 전송 · Shift+Enter로 줄바꿈</div>
        <button class="send" id="sendBtn" type="submit">✦</button>
      </div>
    </form>
  </div>
</div>
<script>
const hero = document.getElementById('hero');
const chat = document.getElementById('chat');
const form = document.getElementById('chatForm');
const input = document.getElementById('messageInput');
const sendBtn = document.getElementById('sendBtn');
let started = false;
function escapeHtml(s){return s.replace(/[&<>\"]/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[m]));}
function ensureChat(){ if(started) return; started = true; hero.classList.add('hidden'); chat.classList.add('active'); }
function addMessage(role, text){
  ensureChat();
  const wrap = document.createElement('div');
  wrap.className = 'msg ' + role;
  const inner = document.createElement('div');
  if(role === 'assistant'){
    const label = document.createElement('div'); label.className='msg-label'; label.textContent='JTGPT'; inner.appendChild(label);
  }
  const bubble = document.createElement('div'); bubble.className='bubble'; bubble.textContent=''; inner.appendChild(bubble); wrap.appendChild(inner); chat.appendChild(wrap); window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'});
  if(role === 'assistant') typeInto(bubble, text); else bubble.textContent = text;
}
function typeInto(el, text){
  let i=0; const step=()=>{ el.textContent = text.slice(0, i); i++; if(i<=text.length){ setTimeout(step, 9); } }; step();
}
form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const msg = input.value.trim();
  if(!msg) return;
  addMessage('user', msg);
  input.value='';
  sendBtn.disabled=true;
  try{
    const fd = new FormData(); fd.append('message', msg);
    const res = await fetch(location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data = await res.json();
    addMessage('assistant', (data && data.answer) ? data.answer : '지금은 응답을 불러오지 못했어요.');
  }catch(err){
    addMessage('assistant', '지금은 응답을 불러오지 못했어요.');
  }finally{
    sendBtn.disabled=false;
    input.focus();
  }
});
input.addEventListener('keydown', (e)=>{
  if(e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); form.requestSubmit(); }
});
</script>
</body>
</html>
