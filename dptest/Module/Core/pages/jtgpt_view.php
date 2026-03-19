<?php
// JTGPT minimal replacement focused on:
// 1) typewriter-style assistant rendering (front-end)
// 2) better disambiguation for shipping questions such as
//    "오늘 출하수량" vs "오늘까지 누적" and latest ship date queries.

$__bootstrapCandidates = [
    dirname(__DIR__, 3) . '/bootstrap.php',
    dirname(__DIR__, 4) . '/bootstrap.php',
    dirname(__DIR__, 3) . '/public/bootstrap.php',
];
foreach ($__bootstrapCandidates as $__bootstrapFile) {
    if (is_file($__bootstrapFile)) {
        require_once $__bootstrapFile;
        break;
    }
}

if (!defined('JTMES_ROOT')) {
    define('JTMES_ROOT', dirname(__DIR__, 3));
}

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('dp_url')) {
    function dp_url(string $route = ''): string {
        $route = trim($route, '/');
        if ($route === '') return '/';
        return '/' . $route;
    }
}
if (!function_exists('jtgpt_get_pdo')) {
    function jtgpt_get_pdo(): PDO {
        if (!function_exists('dp_get_pdo')) {
            throw new RuntimeException('dp_get_pdo() not found');
        }
        return dp_get_pdo();
    }
}

$EMBED = (string)($_GET['embed'] ?? '') === '1';
$selfUrl = $EMBED ? 'jtgpt_view.php?embed=1' : dp_url('jtgpt_view.php');

function jtgpt_format_int($n): string {
    return number_format((int)$n);
}
function jtgpt_mb_contains(string $haystack, string $needle): bool {
    return $needle !== '' && mb_strpos($haystack, $needle) !== false;
}
function jtgpt_contains_any(string $text, array $needles): bool {
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_strpos($text, mb_strtolower($needle, 'UTF-8')) !== false) {
            return true;
        }
    }
    return false;
}
function jtgpt_normalize_compare(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    return preg_replace('/[\s\-\_\(\)\[\]\{\}\.,\/]+/u', '', $text) ?? $text;
}
function jtgpt_detect_time_hint(string $text): ?string {
    $map = [
        '오늘' => ['오늘', '금일', 'today'],
        '어제' => ['어제', 'yesterday'],
        '이번 주' => ['이번주', '이번 주', '금주', 'this week'],
        '최근 7일' => ['최근7일', '최근 7일', '7일', '일주일', '1주일', '최근 일주일'],
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
    return ($dt && $dt->format('Y-m-d') === $ymd) ? $ymd : null;
}
function jtgpt_detect_metric(string $normalized): string {
    if (jtgpt_contains_any($normalized, ['제일최근출하일', '제일 최근 출하일', '최근출하일', '최근 출하일', '마지막출하일', '마지막 출하일', '최종출하일', '최종 출하일'])) {
        return 'last_ship_date';
    }
    if (jtgpt_contains_any($normalized, ['lot', '소포장', 'small_pack'])) {
        return 'lot_count';
    }
    if (jtgpt_contains_any($normalized, ['tray', '트레이'])) {
        return 'tray_count';
    }
    if (jtgpt_contains_any($normalized, ['요약', '정리', 'summary'])) {
        return 'summary';
    }
    if (jtgpt_contains_any($normalized, ['수량', 'qty', 'ea', '개수'])) {
        return 'total_qty';
    }
    return 'summary';
}
function jtgpt_is_shipping_question(string $normalized): bool {
    return jtgpt_contains_any($normalized, ['출하', '출고', 'ship', 'shipping', '납품', 'lot', 'tray', '소포장']);
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
            return ['from' => $dates[0], 'to' => $dates[count($dates) - 1], 'label' => $dates[0] . ' ~ ' . $dates[count($dates) - 1], 'implicit' => false];
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
    if ($hint === '오늘') {
        return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => false];
    }
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
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $today = $now->format('Y-m-d');
    return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => true];
}
function jtgpt_extract_part_name(string $message): ?string {
    $original = trim($message);
    if ($original === '') return null;
    $aliasMap = [
        'ir base' => 'MEM-IR-BASE', 'irbase' => 'MEM-IR-BASE',
        'x carrier' => 'MEM-X-CARRIER', 'x-carrier' => 'MEM-X-CARRIER',
        'y carrier' => 'MEM-Y-CARRIER', 'y-carrier' => 'MEM-Y-CARRIER',
        'z carrier' => 'MEM-Z-CARRIER', 'z-carrier' => 'MEM-Z-CARRIER',
        'z stopper' => 'MEM-Z-STOPPER', 'z-stopper' => 'MEM-Z-STOPPER',
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
function jtgpt_extract_customer(PDO $pdo, string $message): ?string {
    static $customers = null;
    $original = trim($message);
    if ($original === '') return null;
    $aliases = [
        '자화전자' => '자화전자',
        '엘지이노텍' => '엘지이노텍',
        'lg이노텍' => '엘지이노텍',
        'lg innotek' => '엘지이노텍',
    ];
    foreach ($aliases as $needle => $value) {
        if (mb_strpos(mb_strtolower($original, 'UTF-8'), mb_strtolower($needle, 'UTF-8')) !== false) {
            return $value;
        }
    }
    if ($customers === null) {
        $customers = [];
        try {
            $st = $pdo->query("SELECT DISTINCT TRIM(ship_to) AS ship_to FROM ShipingList WHERE COALESCE(TRIM(ship_to),'') <> '' ORDER BY CHAR_LENGTH(TRIM(ship_to)) DESC, ship_to ASC");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $r) {
                $shipTo = trim((string)($r['ship_to'] ?? ''));
                if ($shipTo !== '') $customers[] = $shipTo;
            }
        } catch (Throwable $e) {
            $customers = [];
        }
    }
    $compactMessage = jtgpt_normalize_compare($original);
    foreach ($customers as $candidate) {
        $compactCandidate = jtgpt_normalize_compare($candidate);
        if ($compactCandidate !== '' && mb_strpos($compactMessage, $compactCandidate) !== false) {
            return $candidate;
        }
    }
    return null;
}
function jtgpt_build_shipinglist_href(?string $fromDate, ?string $toDate, ?string $partName = null, ?string $customer = null): string {
    $qs = [];
    if ($fromDate) $qs['from_date'] = $fromDate;
    if ($toDate) $qs['to_date'] = $toDate;
    if ($partName) $qs['part_name'] = $partName;
    if ($customer) $qs['ship_to'] = $customer;
    $base = dp_url('shipinglist');
    return $qs ? ($base . '?' . http_build_query($qs)) : $base;
}
function jtgpt_build_ship_where(array $range = null, ?string $partName = null, ?string $customer = null): array {
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
function jtgpt_reply(array $data): array {
    return array_merge(['answer' => '', 'actions' => [], 'suggestions' => []], $data);
}
function jtgpt_shipping_reply(PDO $pdo, string $message): array {
    $text = trim($message);
    $normalized = mb_strtolower($text, 'UTF-8');
    $metric = jtgpt_detect_metric($normalized);
    $range = jtgpt_detect_date_range($normalized);
    $partName = jtgpt_extract_part_name($text);
    $customer = jtgpt_extract_customer($pdo, $text);

    if (preg_match('/(오늘|어제)\s*까지/u', $text) && in_array($metric, ['total_qty', 'lot_count', 'tray_count', 'summary'], true)) {
        return jtgpt_reply([
            'answer' => '"오늘까지"를 오늘 하루로 볼지, 오늘까지 누적으로 볼지 애매해요.\n예: "오늘 출하수량" 또는 "오늘까지 누적 출하수량"처럼 말해 주세요.',
            'suggestions' => ['오늘 출하수량 알려줘', '오늘까지 누적 출하수량', '자화전자 제일 최근 출하일은?'],
        ]);
    }

    if ($metric === 'last_ship_date' && $range === null) {
        // 최근 출하일은 날짜를 안 말했으면 전체 기간 기준으로 조회.
        [$whereSql, $params] = jtgpt_build_ship_where(null, $partName, $customer);
        $sql = "SELECT MAX(ship_datetime) AS last_ship_datetime FROM ShipingList {$whereSql}";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $lastShip = (string)($st->fetchColumn() ?: '');
        if ($lastShip === '' || strpos($lastShip, '0000-00-00') === 0) {
            $prefix = $customer ? ($customer . ' ') : ($partName ? ($partName . ' ') : '');
            return jtgpt_reply([
                'answer' => $prefix . '기준으로 최근 출하일을 찾지 못했어요.',
                'actions' => [[ 'label' => 'QA 출하내역 열기', 'href' => jtgpt_build_shipinglist_href(null, null, $partName, $customer) ]],
                'suggestions' => ['자화전자 제일 최근 출하일은?', '오늘 출하수량 알려줘'],
            ]);
        }
        $dateOnly = substr($lastShip, 0, 10);
        $prefix = '';
        if ($customer) $prefix .= $customer . ' ';
        if ($partName) $prefix .= $partName . ' ';
        if ($prefix === '') $prefix = '전체 출하 기준으로 ';
        return jtgpt_reply([
            'answer' => trim($prefix) . '가장 최근 출하일은 ' . $dateOnly . '입니다.',
            'actions' => [[ 'label' => 'QA 출하내역 열기', 'href' => jtgpt_build_shipinglist_href($dateOnly, $dateOnly, $partName, $customer) ]],
            'suggestions' => ['그날 출하수량 알려줘', '오늘 출하수량 알려줘', '어제 출하 lot 몇개야'],
        ]);
    }

    if ($range === null) {
        $range = jtgpt_default_today_range();
    }

    [$whereSql, $params] = jtgpt_build_ship_where($range, $partName, $customer);
    $summarySql = "
        SELECT
            COUNT(*) AS row_count,
            COALESCE(SUM(qty), 0) AS total_qty,
            COUNT(DISTINCT small_pack_no) AS lot_count,
            COUNT(DISTINCT tray_no) AS tray_count,
            COUNT(DISTINCT part_name) AS part_count
        FROM ShipingList
        {$whereSql}
    ";
    $st = $pdo->prepare($summarySql);
    $st->execute($params);
    $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $rowCount = (int)($summary['row_count'] ?? 0);
    $totalQty = (int)($summary['total_qty'] ?? 0);
    $lotCount = (int)($summary['lot_count'] ?? 0);
    $trayCount = (int)($summary['tray_count'] ?? 0);
    $partCount = (int)($summary['part_count'] ?? 0);

    $actions = [[
        'label' => 'QA 출하내역 열기',
        'href' => jtgpt_build_shipinglist_href($range['from'] ?? null, $range['to'] ?? null, $partName, $customer),
    ]];

    if ($rowCount <= 0) {
        $subject = [];
        if ($customer) $subject[] = $customer;
        if ($partName) $subject[] = $partName;
        $prefix = $subject ? implode(' / ', $subject) . ' ' : '';
        $answer = $prefix . $range['label'] . ' 기준 출하 데이터가 없어요.';
        if (!empty($range['implicit'])) {
            $answer .= ' 날짜를 따로 말하지 않아서 오늘 하루 기준으로 봤어요.';
        }
        return jtgpt_reply([
            'answer' => $answer,
            'actions' => $actions,
            'suggestions' => ['어제 출하수량 알려줘', '이번 주 출하 요약해줘', '자화전자 제일 최근 출하일은?'],
        ]);
    }

    $subject = [];
    if ($customer) $subject[] = $customer;
    if ($partName) $subject[] = $partName;
    $subjectPrefix = $subject ? implode(' / ', $subject) . ' ' : '';

    if ($metric === 'total_qty') {
        $answer = $subjectPrefix . $range['label'] . ' 출하수량은 ' . jtgpt_format_int($totalQty) . ' EA입니다.';
        if (!empty($range['implicit'])) $answer .= ' 날짜를 따로 말하지 않아서 오늘 하루 기준으로 봤어요.';
        return jtgpt_reply([
            'answer' => $answer,
            'actions' => $actions,
            'suggestions' => [$range['label'] . ' 출하 lot 몇개야', $range['label'] . ' 출하 요약해줘', '자화전자 제일 최근 출하일은?'],
        ]);
    }
    if ($metric === 'lot_count') {
        $answer = $subjectPrefix . $range['label'] . ' 출하 lot는 ' . jtgpt_format_int($lotCount) . '건입니다.';
        if (!empty($range['implicit'])) $answer .= ' 날짜를 따로 말하지 않아서 오늘 하루 기준으로 봤어요.';
        return jtgpt_reply([
            'answer' => $answer,
            'actions' => $actions,
            'suggestions' => [$range['label'] . ' 출하수량 알려줘', $range['label'] . ' tray 몇개야', '자화전자 제일 최근 출하일은?'],
        ]);
    }
    if ($metric === 'tray_count') {
        $answer = $subjectPrefix . $range['label'] . ' tray는 ' . jtgpt_format_int($trayCount) . '건입니다.';
        if (!empty($range['implicit'])) $answer .= ' 날짜를 따로 말하지 않아서 오늘 하루 기준으로 봤어요.';
        return jtgpt_reply([
            'answer' => $answer,
            'actions' => $actions,
            'suggestions' => [$range['label'] . ' 출하수량 알려줘', $range['label'] . ' 출하 lot 몇개야', '자화전자 제일 최근 출하일은?'],
        ]);
    }

    $topParts = [];
    if ($partName === null) {
        $topSql = "SELECT part_name, COALESCE(SUM(qty),0) AS total_qty FROM ShipingList {$whereSql} GROUP BY part_name ORDER BY total_qty DESC, part_name ASC LIMIT 5";
        $st = $pdo->prepare($topSql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $pn = trim((string)($r['part_name'] ?? ''));
            if ($pn === '') continue;
            $topParts[] = $pn . ' ' . jtgpt_format_int((int)($r['total_qty'] ?? 0)) . ' EA';
        }
    }

    $answer = $subjectPrefix . $range['label'] . ' 출하 요약입니다.\n';
    $answer .= '- 출하수량: ' . jtgpt_format_int($totalQty) . ' EA\n';
    $answer .= '- lot: ' . jtgpt_format_int($lotCount) . '건\n';
    $answer .= '- tray: ' . jtgpt_format_int($trayCount) . '건\n';
    $answer .= '- 조회 행: ' . jtgpt_format_int($rowCount) . '건';
    if ($partName === null) {
        $answer .= '\n- 품번: ' . jtgpt_format_int($partCount) . '종';
    }
    if ($topParts) {
        $answer .= '\n상위 품번: ' . implode(', ', $topParts);
    }
    if (!empty($range['implicit'])) {
        $answer .= '\n날짜를 따로 말하지 않아서 오늘 하루 기준으로 봤어요.';
    }
    return jtgpt_reply([
        'answer' => $answer,
        'actions' => $actions,
        'suggestions' => [$range['label'] . ' 출하수량 알려줘', $range['label'] . ' 출하 lot 몇개야', '자화전자 제일 최근 출하일은?'],
    ]);
}
function jtgpt_parse_message(string $message): array {
    $text = trim($message);
    if ($text === '') {
        return jtgpt_reply([
            'answer' => '질문이 비어 있어요. 예: 오늘 출하수량 알려줘, 자화전자 제일 최근 출하일은?, 오늘까지 누적 출하수량',
            'suggestions' => ['오늘 출하수량 알려줘', '자화전자 제일 최근 출하일은?', '오늘까지 누적 출하수량'],
        ]);
    }
    $normalized = mb_strtolower($text, 'UTF-8');

    if (jtgpt_is_shipping_question($normalized) || jtgpt_contains_any($normalized, ['최근출하일', '마지막출하일', '최종출하일'])) {
        try {
            $pdo = jtgpt_get_pdo();
            return jtgpt_shipping_reply($pdo, $text);
        } catch (Throwable $e) {
            return jtgpt_reply([
                'answer' => '출하 데이터를 조회하는 중 오류가 났어요.\n' . $e->getMessage(),
                'actions' => [[ 'label' => 'QA 출하내역 열기', 'href' => dp_url('shipinglist') ]],
                'suggestions' => ['오늘 출하수량 알려줘', '자화전자 제일 최근 출하일은?'],
            ]);
        }
    }

    return jtgpt_reply([
        'answer' => '이번 패치는 출하 질문 의도 분리와 타이핑 표시만 먼저 고쳤어요.\n지금은 출하 쪽 질문부터 정확히 처리합니다. 예: 오늘 출하수량 알려줘, 자화전자 제일 최근 출하일은?',
        'actions' => [[ 'label' => 'QA 출하내역 열기', 'href' => dp_url('shipinglist') ]],
        'suggestions' => ['오늘 출하수량 알려줘', '자화전자 제일 최근 출하일은?', '오늘까지 누적 출하수량'],
    ]);
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
        'actions' => array_values($result['actions'] ?? []),
        'suggestions' => array_values($result['suggestions'] ?? []),
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
:root{
  --bg:#0a1412;
  --panel:#20232b;
  --panel2:#2a2d35;
  --line:rgba(255,255,255,.10);
  --text:#f4f6f8;
  --muted:#aab1ba;
  --chip:#2d3138;
  --accent:#dfe4ea;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:Segoe UI, Apple SD Gothic Neo, Malgun Gothic, sans-serif;
  color:var(--text);
  background:
    radial-gradient(circle at 50% 10%, rgba(90,110,130,.22), transparent 26%),
    linear-gradient(180deg, #08110f 0%, #0b1714 100%);
}
.jtgpt-wrap{min-height:100%; display:flex; flex-direction:column; max-width:980px; margin:0 auto; padding:16px 18px 28px}
.jtgpt-badge{display:inline-flex; align-items:center; gap:6px; padding:9px 14px; border:1px solid rgba(255,255,255,.14); border-radius:999px; color:#f4f6f8; font-size:14px; background:rgba(255,255,255,.03); backdrop-filter:blur(8px)}
.jtgpt-badge small{opacity:.72; font-weight:600}
.chat{flex:1; padding:18px 0 10px; overflow:auto}
.msg{display:flex; margin:18px 0}
.msg.user{justify-content:flex-end}
.msg.assistant{justify-content:flex-start}
.msg-inner{max-width:min(78%, 760px)}
.msg-role{font-size:13px; color:var(--muted); margin:0 0 8px 6px}
.bubble{white-space:pre-wrap; line-height:1.6; border-radius:22px; padding:16px 18px; border:1px solid var(--line); box-shadow:0 10px 28px rgba(0,0,0,.22)}
.msg.user .bubble{background:#2a2d35}
.msg.assistant .bubble{background:#1e232d}
.cursor{display:inline-block; width:8px; height:1.1em; vertical-align:-2px; background:#cfd6df; margin-left:2px; animation:blink 1s step-end infinite}
@keyframes blink{50%{opacity:0}}
.actions,.suggestions{display:flex; flex-wrap:wrap; gap:8px; margin:10px 0 0 0}
.chip{appearance:none; border:1px solid rgba(255,255,255,.12); background:var(--chip); color:#eef2f6; border-radius:999px; padding:10px 14px; font-size:14px; cursor:pointer}
.composer{position:sticky; bottom:0; padding-top:14px; background:linear-gradient(180deg, rgba(10,20,18,0), rgba(10,20,18,.88) 22%, rgba(10,20,18,.98) 100%)}
.composer-box{background:var(--panel2); border:1px solid rgba(255,255,255,.10); border-radius:28px; padding:16px 18px 14px; box-shadow:0 16px 40px rgba(0,0,0,.28)}
.textarea{width:100%; min-height:96px; resize:none; border:none; outline:none; background:transparent; color:var(--text); font-size:18px; line-height:1.5}
.toolbar{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:10px}
.left-tools{display:flex; align-items:center; gap:8px; flex-wrap:wrap}
.icon-btn,.send-btn{appearance:none; border:none; cursor:pointer}
.icon-btn{width:40px; height:40px; border-radius:999px; background:rgba(255,255,255,.05); color:#e9eef5; font-size:24px}
.send-btn{width:44px; height:44px; border-radius:999px; background:#eef2f6; color:#15181d; font-size:20px}
.helper-chip{padding:9px 12px; border-radius:999px; border:1px solid rgba(255,255,255,.10); background:rgba(255,255,255,.03); color:var(--muted); font-size:14px}
@media (max-width: 760px){
  .jtgpt-wrap{padding:10px 12px 20px}
  .msg-inner{max-width:92%}
  .textarea{font-size:17px; min-height:88px}
}
</style>
</head>
<body>
<div class="jtgpt-wrap">
  <div><span class="jtgpt-badge">✦ JTGPT <small>BETA</small></span></div>
  <div id="chat" class="chat"></div>
  <div class="composer">
    <div class="composer-box">
      <textarea id="messageInput" class="textarea" placeholder="무엇이든 물어보세요"></textarea>
      <div class="toolbar">
        <div class="left-tools">
          <button type="button" class="icon-btn" aria-label="추가">＋</button>
          <span class="helper-chip">타이핑 응답</span>
          <button type="button" class="helper-chip" data-fill="오늘 출하수량 알려줘">출하 수량</button>
          <button type="button" class="helper-chip" data-fill="자화전자 제일 최근 출하일은?">최근 출하일</button>
        </div>
        <button id="sendBtn" type="button" class="send-btn" aria-label="전송">↑</button>
      </div>
    </div>
  </div>
</div>
<script>
(() => {
  const chatEl = document.getElementById('chat');
  const inputEl = document.getElementById('messageInput');
  const sendBtn = document.getElementById('sendBtn');

  function escapeHtml(str){
    return String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
  }
  function scrollBottom(){
    requestAnimationFrame(() => { chatEl.scrollTop = chatEl.scrollHeight; });
  }
  function createMessage(role, text){
    const msg = document.createElement('div');
    msg.className = 'msg ' + role;
    const inner = document.createElement('div');
    inner.className = 'msg-inner';
    const roleEl = document.createElement('div');
    roleEl.className = 'msg-role';
    roleEl.textContent = role === 'user' ? '나' : 'JTGPT';
    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.textContent = text || '';
    inner.appendChild(roleEl);
    inner.appendChild(bubble);
    msg.appendChild(inner);
    chatEl.appendChild(msg);
    scrollBottom();
    return {msg, inner, bubble};
  }
  function appendChips(container, items, kind){
    if (!Array.isArray(items) || !items.length) return;
    const wrap = document.createElement('div');
    wrap.className = kind;
    items.forEach(item => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'chip';
      if (kind === 'actions') {
        btn.textContent = item.label || '열기';
        btn.addEventListener('click', () => {
          const href = item.href || '';
          if (!href) return;
          try {
            window.top.location.href = href;
          } catch (_) {
            window.location.href = href;
          }
        });
      } else {
        btn.textContent = String(item);
        btn.addEventListener('click', () => {
          inputEl.value = String(item);
          inputEl.focus();
        });
      }
      wrap.appendChild(btn);
    });
    container.appendChild(wrap);
    scrollBottom();
  }
  function typeText(target, text, done){
    const chars = Array.from(String(text || ''));
    let i = 0;
    target.textContent = '';
    const cursor = document.createElement('span');
    cursor.className = 'cursor';
    target.appendChild(cursor);
    function step(){
      if (i >= chars.length) {
        cursor.remove();
        if (done) done();
        scrollBottom();
        return;
      }
      const node = document.createTextNode(chars[i]);
      target.insertBefore(node, cursor);
      i += 1;
      scrollBottom();
      setTimeout(step, 14);
    }
    step();
  }
  function showWelcome(){
    const m = createMessage('assistant', '');
    typeText(m.bubble, '이번 패치는 두 가지만 먼저 고쳤어요.\n- 답변이 한 번에 팡 뜨지 않고 타이핑처럼 보이게\n- "오늘 출하수량"과 "오늘까지 누적"을 헷갈리면 되묻기\n예: 오늘 출하수량 알려줘, 자화전자 제일 최근 출하일은?, 오늘까지 누적 출하수량', () => {
      appendChips(m.inner, ['오늘 출하수량 알려줘', '자화전자 제일 최근 출하일은?', '오늘까지 누적 출하수량'], 'suggestions');
    });
  }
  async function sendMessage(prefill){
    const text = (typeof prefill === 'string' ? prefill : inputEl.value).trim();
    if (!text) return;
    createMessage('user', text);
    inputEl.value = '';
    inputEl.focus();

    const assistant = createMessage('assistant', '');
    assistant.bubble.textContent = '생각 중';
    const waitCursor = document.createElement('span');
    waitCursor.className = 'cursor';
    assistant.bubble.appendChild(waitCursor);
    scrollBottom();

    try {
      const res = await fetch(<?php echo json_encode($selfUrl); ?>, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ message: text })
      });
      const data = await res.json();
      assistant.bubble.textContent = '';
      typeText(assistant.bubble, data.answer || '응답을 받지 못했어요.', () => {
        appendChips(assistant.inner, data.actions || [], 'actions');
        appendChips(assistant.inner, data.suggestions || [], 'suggestions');
      });
    } catch (err) {
      assistant.bubble.textContent = '';
      typeText(assistant.bubble, '응답 중 오류가 났어요. 잠시 후 다시 시도해 주세요.', null);
    }
  }

  sendBtn.addEventListener('click', () => sendMessage());
  inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });
  document.querySelectorAll('[data-fill]').forEach(el => {
    el.addEventListener('click', () => {
      inputEl.value = el.getAttribute('data-fill') || '';
      inputEl.focus();
    });
  });
  showWelcome();
})();
</script>
</body>
</html>
