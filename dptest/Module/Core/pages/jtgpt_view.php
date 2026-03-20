<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

function jtgpt_bootstrap_pdo(): ?PDO {
    static $pdo = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($pdo === null) return null;

    $candidates = [
        __DIR__ . '/../../../config/dp_config.php',
        __DIR__ . '/../../../config/config.php',
        __DIR__ . '/../../../config/db.php',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            require_once $path;
            break;
        }
    }

    foreach (['pdo', 'db', 'conn'] as $name) {
        if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
            $pdo = $GLOBALS[$name];
            return $pdo;
        }
    }

    foreach (['dp_get_pdo', 'get_pdo', 'db', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            try {
                $maybe = $fn();
                if ($maybe instanceof PDO) {
                    $pdo = $maybe;
                    return $pdo;
                }
            } catch (Throwable $e) {
                // ignore and continue
            }
        }
    }

    $pdo = null;
    return null;
}

function jtgpt_json(array $payload): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jtgpt_ctx_get(): array {
    return is_array($_SESSION['jtgpt_ctx'] ?? null) ? $_SESSION['jtgpt_ctx'] : [];
}

function jtgpt_ctx_set(array $patch): void {
    $_SESSION['jtgpt_ctx'] = array_merge(jtgpt_ctx_get(), $patch);
}

function jtgpt_trim(string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return is_string($s) ? $s : '';
}

function jtgpt_msg_lower(string $s): string {
    return mb_strtolower(jtgpt_trim($s), 'UTF-8');
}

function jtgpt_extract_customer(string $text): ?string {
    $patterns = [
        '/자화전자/u' => '자화전자',
        '/엘지이노텍|lg\s*이노텍|lginnotek|lg/u' => 'LG',
        '/삼성/u' => '삼성',
        '/현대/u' => '현대',
        '/기아/u' => '기아',
    ];
    foreach ($patterns as $pat => $value) {
        if (preg_match($pat, $text)) return $value;
    }
    if (preg_match('/^[A-Za-z가-힣0-9()\- ]{2,30}$/u', $text)) {
        $ban = ['오늘','어제','수량','lot','tray','출하','출하내역','출고','최근 출하일','제일 최근 출하일','누적'];
        if (!in_array($text, $ban, true)) return $text;
    }
    return null;
}

function jtgpt_detect_range(string $text, array $ctx): ?string {
    if (preg_match('/오늘까지|금일까지|누적/u', $text)) return 'until_today';
    if (preg_match('/최근\s*7일|최근\s*일주일/u', $text)) return 'recent7';
    if (preg_match('/어제/u', $text)) return 'yesterday';
    if (preg_match('/오늘|금일/u', $text)) return 'today';
    if (($ctx['module'] ?? '') === 'shipping') return $ctx['range'] ?? null;
    return null;
}

function jtgpt_detect_type(string $text, array $ctx): ?string {
    if (preg_match('/(?:제일\s*)?(?:최근|마지막|최신)\s*출하일|언제\s*출하/u', $text)) return 'last_date';
    if (preg_match('/lot|로트/u', $text)) return 'lot';
    if (preg_match('/tray|트레이/u', $text)) return 'tray';
    if (preg_match('/수량|ea\b|알려줘|내역/u', $text)) return 'qty';
    if (preg_match('/있어|있나|출하|출고/u', $text)) return 'exists';
    if (($ctx['module'] ?? '') === 'shipping') return $ctx['type'] ?? 'exists';
    return null;
}

function jtgpt_parse_intent(string $message, array $ctx): array {
    $text = jtgpt_trim($message);
    $lower = jtgpt_msg_lower($text);

    $shippingWords = (bool)preg_match('/출하|출고|ship|shipping/u', $text);
    $shortSlot = (bool)preg_match('/^(오늘|금일|어제|최근\s*7일|최근\s*일주일|오늘까지|누적|수량|lot|tray|lg|자화전자)$/iu', $text);
    $customer = jtgpt_extract_customer($text);
    $range = jtgpt_detect_range($text, $ctx);
    $type = jtgpt_detect_type($text, $ctx);

    if ($type === 'last_date') {
        return ['module' => 'shipping', 'type' => 'last_date', 'range' => null, 'customer' => $customer ?: ($ctx['customer'] ?? null)];
    }

    if (($ctx['module'] ?? '') === 'shipping' && ($shortSlot || $customer !== null)) {
        return [
            'module' => 'shipping',
            'type' => $type ?: ($ctx['type'] ?? 'exists'),
            'range' => $range ?: ($ctx['range'] ?? 'today'),
            'customer' => $customer ?: ($ctx['customer'] ?? null),
        ];
    }

    if ($shippingWords || $type !== null) {
        return [
            'module' => 'shipping',
            'type' => $type ?: 'exists',
            'range' => $range ?: 'today',
            'customer' => $customer,
        ];
    }

    return ['module' => 'unknown'];
}

function jtgpt_range_bounds(?string $range): array {
    $today = new DateTimeImmutable('today');
    $tomorrow = $today->modify('+1 day');
    switch ($range) {
        case 'yesterday':
            return [$today->modify('-1 day')->format('Y-m-d 00:00:00'), $today->format('Y-m-d 00:00:00')];
        case 'recent7':
            return [$today->modify('-6 day')->format('Y-m-d 00:00:00'), $tomorrow->format('Y-m-d 00:00:00')];
        case 'until_today':
            return [null, $tomorrow->format('Y-m-d 00:00:00')];
        case 'today':
        default:
            return [$today->format('Y-m-d 00:00:00'), $tomorrow->format('Y-m-d 00:00:00')];
    }
}

function jtgpt_customer_sql(?string $customer): array {
    if ($customer === null || $customer === '') return ['', []];
    $map = [
        'LG' => '%LG%',
        '자화전자' => '%자화전자%',
    ];
    $kw = $map[$customer] ?? ('%' . $customer . '%');
    return ['ship_to LIKE :ship_to', [':ship_to' => $kw]];
}

function jtgpt_shipping_summary(PDO $pdo, ?string $range, ?string $customer): array {
    [$fromDt, $toDt] = jtgpt_range_bounds($range);
    $where = [];
    $params = [];

    if ($fromDt !== null) {
        $where[] = 'ship_datetime >= :from_dt';
        $params[':from_dt'] = $fromDt;
    }
    if ($toDt !== null) {
        $where[] = 'ship_datetime < :to_dt';
        $params[':to_dt'] = $toDt;
    }

    [$customerWhere, $customerParams] = jtgpt_customer_sql($customer);
    if ($customerWhere !== '') {
        $where[] = $customerWhere;
        $params += $customerParams;
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
    ];
}

function jtgpt_shipping_last_date(PDO $pdo, ?string $customer): ?string {
    $where = [];
    $params = [];
    [$customerWhere, $customerParams] = jtgpt_customer_sql($customer);
    if ($customerWhere !== '') {
        $where[] = $customerWhere;
        $params += $customerParams;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT MAX(ship_datetime) AS last_ship_datetime FROM ShipingList {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = (string)$st->fetchColumn();
    return $v !== '' ? $v : null;
}

function jtgpt_scope_label(?string $range, ?string $customer): string {
    $rangeLabel = [
        'today' => '오늘',
        'yesterday' => '어제',
        'recent7' => '최근 7일',
        'until_today' => '오늘까지 누적',
        null => '전체 기간',
    ][$range] ?? '기준 기간';
    return $customer ? ($customer . ' / ' . $rangeLabel) : $rangeLabel;
}

function jtgpt_answer_shipping(array $intent): string {
    $pdo = jtgpt_bootstrap_pdo();
    if (!$pdo) {
        return '출하 데이터를 지금 바로 읽지 못했어요.';
    }

    $type = $intent['type'] ?? 'exists';
    $range = $intent['range'] ?? 'today';
    $customer = $intent['customer'] ?? null;

    if ($type === 'last_date') {
        $last = jtgpt_shipping_last_date($pdo, $customer);
        jtgpt_ctx_set(['module' => 'shipping', 'type' => $type, 'range' => null, 'customer' => $customer]);
        if (!$last) {
            return $customer ? ($customer . ' 기준 출하 이력을 찾지 못했어요.') : '출하 이력을 찾지 못했어요.';
        }
        $dateOnly = substr($last, 0, 10);
        return $customer ? ($customer . '의 가장 최근 출하일은 ' . $dateOnly . '입니다.') : ('가장 최근 출하일은 ' . $dateOnly . '입니다.');
    }

    $summary = jtgpt_shipping_summary($pdo, $range, $customer);
    jtgpt_ctx_set(['module' => 'shipping', 'type' => $type, 'range' => $range, 'customer' => $customer, 'summary' => $summary]);
    $scope = jtgpt_scope_label($range, $customer);

    if ((int)$summary['row_count'] === 0) {
        return $scope . ' 기준 출하 데이터가 없습니다.';
    }

    if ($type === 'exists') {
        return $scope . ' 기준 출하는 있습니다. 총 ' . number_format((int)$summary['total_qty']) . ' EA입니다.';
    }
    if ($type === 'qty') {
        return $scope . ' 출하수량은 ' . number_format((int)$summary['total_qty']) . ' EA입니다.';
    }
    if ($type === 'lot') {
        return $scope . ' 출하 lot는 ' . number_format((int)$summary['lot_count']) . '건입니다.';
    }
    if ($type === 'tray') {
        return $scope . ' tray는 ' . number_format((int)$summary['tray_count']) . '건입니다.';
    }
    return $scope . ' 기준 출하는 있습니다.';
}

function jtgpt_answer(string $message): string {
    $intent = jtgpt_parse_intent($message, jtgpt_ctx_get());
    if (($intent['module'] ?? '') === 'shipping') {
        try {
            return jtgpt_answer_shipping($intent);
        } catch (Throwable $e) {
            return '출하 데이터를 읽는 중 문제가 생겼어요.';
        }
    }
    return '지금은 출하 조회부터 연결해 두었어요.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = $_POST;
    $message = trim((string)($payload['message'] ?? ''));
    jtgpt_json(['ok' => true, 'answer' => jtgpt_answer($message)]);
}
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JTGPT</title>
<style>
:root{
  --bg:#1d1e23;
  --panel:rgba(43,46,56,.84);
  --panel-2:rgba(35,38,46,.88);
  --line:rgba(255,255,255,.08);
  --line-2:rgba(255,255,255,.12);
  --text:#f3f5f8;
  --muted:#aeb4c0;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:Segoe UI, Apple SD Gothic Neo, Malgun Gothic, sans-serif;
  color:var(--text);
  background:linear-gradient(180deg, rgba(26,28,34,.97), rgba(22,24,30,.98));
}
.wrap{min-height:100%; display:flex; flex-direction:column;}
.brand{position:fixed; top:24px; left:28px; font-size:24px; letter-spacing:-.02em; color:#e9edf5; opacity:.95}
.hero{flex:1; display:flex; align-items:center; justify-content:center; padding:72px 24px 220px; text-align:center; transition:.22s ease;}
.hero.hidden{opacity:0; transform:translateY(-8px); pointer-events:none; height:0; padding:0; overflow:hidden}
.hero-inner{max-width:860px}
.hero-badge{width:42px; height:42px; margin:0 auto 18px; border-radius:14px; border:1px solid var(--line); background:rgba(255,255,255,.03); display:grid; place-items:center; font-size:18px}
.hero h1{margin:0; font-size:clamp(36px,5.6vw,62px); line-height:1.08; letter-spacing:-.045em; font-weight:700}
.thread{width:min(980px, calc(100% - 36px)); margin:0 auto; padding:30px 0 200px; display:flex; flex-direction:column; gap:22px}
.thread.hidden{display:none}
.msg{display:flex; flex-direction:column; gap:8px; max-width:100%}
.msg .role{font-size:13px; color:#c9cfda; opacity:.9; padding:0 8px}
.msg .bubble{
  max-width:min(780px, 88%);
  width:fit-content;
  padding:18px 20px;
  border-radius:22px;
  border:1px solid var(--line);
  background:linear-gradient(180deg, rgba(41,44,55,.9), rgba(36,39,48,.9));
  box-shadow:0 8px 28px rgba(0,0,0,.18);
  font-size:18px;
  line-height:1.62;
  letter-spacing:-.02em;
  word-break:keep-all;
  overflow-wrap:anywhere;
}
.msg.user{align-items:flex-end}
.msg.user .bubble{background:linear-gradient(180deg, rgba(56,59,72,.9), rgba(49,52,64,.9));}
.composer-wrap{position:fixed; left:0; right:0; bottom:18px; padding:0 20px;}
.composer{width:min(980px, calc(100% - 40px)); margin:0 auto; border-radius:28px; border:1px solid var(--line); background:linear-gradient(180deg, rgba(48,50,60,.9), rgba(44,47,57,.92)); box-shadow:0 22px 50px rgba(0,0,0,.22);}
.composer textarea{width:100%; min-height:148px; resize:none; border:0; outline:0; background:transparent; color:var(--text); padding:24px 92px 18px 24px; font:inherit; font-size:17px; line-height:1.6; letter-spacing:-.02em}
.composer textarea::placeholder{color:var(--muted)}
.composer-foot{display:flex; align-items:center; justify-content:space-between; padding:0 18px 18px 18px}
.composer-hint{font-size:13px; color:var(--muted)}
.send{width:48px;height:48px;border-radius:999px;border:1px solid var(--line-2);background:#f4f6f9;color:#111827;font-size:19px;font-weight:700;cursor:pointer}
@media (max-width:900px){.brand{font-size:20px}.hero h1{font-size:42px}.msg .bubble{max-width:92%; font-size:17px}.composer textarea{min-height:132px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">JTGPT</div>
  <section class="hero" id="hero">
    <div class="hero-inner">
      <div class="hero-badge">✦</div>
      <h1>무엇을 도와드릴까요?</h1>
    </div>
  </section>
  <section class="thread hidden" id="thread"></section>
</div>
<div class="composer-wrap">
  <form class="composer" id="composerForm">
    <textarea id="composerInput" placeholder="무엇이든 물어보세요"></textarea>
    <div class="composer-foot">
      <div class="composer-hint">Enter로 전송 · Shift+Enter로 줄바꿈</div>
      <button type="submit" class="send" aria-label="전송">✦</button>
    </div>
  </form>
</div>
<script>
const form = document.getElementById('composerForm');
const input = document.getElementById('composerInput');
const thread = document.getElementById('thread');
const hero = document.getElementById('hero');

function showThread(){ hero.classList.add('hidden'); thread.classList.remove('hidden'); }
function addMessage(role, text){
  const box = document.createElement('div');
  box.className = 'msg ' + role;
  const label = document.createElement('div');
  label.className = 'role';
  label.textContent = role === 'user' ? '나' : 'JTGPT';
  const bubble = document.createElement('div');
  bubble.className = 'bubble';
  bubble.textContent = text;
  box.append(label, bubble);
  thread.appendChild(box);
  box.scrollIntoView({behavior:'smooth', block:'end'});
  return bubble;
}
function typeTo(el, text){
  el.textContent='';
  let i=0;
  const tick=()=>{
    el.textContent = text.slice(0, i++);
    if(i<=text.length){ requestAnimationFrame(tick); }
  };
  tick();
}
form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const text = input.value.trim();
  if(!text) return;
  showThread();
  addMessage('user', text);
  input.value='';
  input.style.height='auto';
  const bubble = addMessage('assistant', '');
  try{
    const res = await fetch(location.href, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({message:text})
    });
    const data = await res.json();
    typeTo(bubble, data.answer || '지금 응답을 불러오지 못했어요.');
  }catch(err){
    typeTo(bubble, '지금 응답을 불러오지 못했어요.');
  }
});
input.addEventListener('keydown', (e)=>{
  if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); form.requestSubmit(); }
});
input.addEventListener('input', ()=>{
  input.style.height='auto';
  input.style.height=Math.min(input.scrollHeight, 260)+'px';
});
</script>
</body>
</html>
