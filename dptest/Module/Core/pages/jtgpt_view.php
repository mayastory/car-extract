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

if (!function_exists('jtgpt_format_int')) {
    function jtgpt_format_int($v): string {
        return number_format((int)$v);
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

if (!function_exists('jtgpt_try_parse_ymd')) {
    function jtgpt_try_parse_ymd(string $y, string $m, string $d): ?string {
        $m = str_pad((string)((int)$m), 2, '0', STR_PAD_LEFT);
        $d = str_pad((string)((int)$d), 2, '0', STR_PAD_LEFT);
        $ymd = trim($y) . '-' . $m . '-' . $d;
        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        if (!$dt || $dt->format('Y-m-d') !== $ymd) {
            return null;
        }
        return $ymd;
    }
}

if (!function_exists('jtgpt_detect_date_range')) {
    function jtgpt_detect_date_range(string $text): array {
        $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
        $today = $now->format('Y-m-d');

        if (preg_match_all('/(20\d{2})[\.\/-]?(\d{1,2})[\.\/-]?(\d{1,2})/', $text, $m, PREG_SET_ORDER)) {
            $dates = [];
            foreach ($m as $hit) {
                $ymd = jtgpt_try_parse_ymd($hit[1], $hit[2], $hit[3]);
                if ($ymd !== null) {
                    $dates[] = $ymd;
                }
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
            $end = $today;
            return ['from' => $start, 'to' => $end, 'label' => '이번 주', 'implicit' => false];
        }
        if ($hint === '최근 7일') {
            $start = (clone $now)->modify('-6 day')->format('Y-m-d');
            $end = $today;
            return ['from' => $start, 'to' => $end, 'label' => '최근 7일', 'implicit' => false];
        }
        if ($hint === '이번 달') {
            $start = (clone $now)->modify('first day of this month')->format('Y-m-d');
            $end = $today;
            return ['from' => $start, 'to' => $end, 'label' => '이번 달', 'implicit' => false];
        }

        return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => true];
    }
}

if (!function_exists('jtgpt_extract_part_name')) {
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
            if (mb_strpos($lower, $needle) !== false) {
                return $partName;
            }
        }

        if (preg_match('/\b(MEM-[A-Z0-9\.\-]+)\b/i', $original, $m)) {
            return strtoupper(trim($m[1]));
        }

        if (preg_match('/\b([A-Z0-9]+(?:-[A-Z0-9\.]+){2,})\b/', strtoupper($original), $m)) {
            return trim($m[1]);
        }

        return null;
    }
}

if (!function_exists('jtgpt_build_shipinglist_href')) {
    function jtgpt_build_shipinglist_href(string $fromDate, string $toDate, ?string $partName = null): string {
        $qs = [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
        if ($partName !== null && $partName !== '') {
            $qs['part_name'] = $partName;
        }
        return dp_url('shipinglist') . '?' . http_build_query($qs);
    }
}

if (!function_exists('jtgpt_shipping_reply')) {
    function jtgpt_shipping_reply(PDO $pdo, string $message): array {
        $text = trim($message);
        $normalized = mb_strtolower($text, 'UTF-8');
        $range = jtgpt_detect_date_range($normalized);
        $partName = jtgpt_extract_part_name($text);

        $where = [
            'ship_datetime >= :from_dt',
            'ship_datetime < :to_dt',
        ];
        $params = [
            ':from_dt' => $range['from'] . ' 00:00:00',
            ':to_dt' => date('Y-m-d 00:00:00', strtotime($range['to'] . ' +1 day')),
        ];

        if ($partName !== null && $partName !== '') {
            $where[] = 'part_name LIKE :part_name';
            $params[':part_name'] = '%' . $partName . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

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

        $actions = [
            ['label' => 'QA 출하내역 열기', 'href' => jtgpt_build_shipinglist_href($range['from'], $range['to'], $partName)],
        ];

        if ($rowCount <= 0) {
            $answer = $range['label'] . ' 기준 출하 데이터가 없어요.';
            if (!empty($range['implicit'])) {
                $answer .= " 날짜를 따로 말하지 않아서 오늘 기준으로 확인했어요.";
            }
            if ($partName) {
                $answer .= ' 품번 필터는 ' . $partName . ' 로 봤어요.';
            }
            return [
                'answer' => $answer,
                'actions' => $actions,
                'suggestions' => [
                    '오늘 출하수량 알려줘',
                    '어제 출하 lot 몇개야',
                    '이번 주 MEM-IR-BASE 출하수량',
                ],
            ];
        }

        $topSql = "
            SELECT part_name, COALESCE(SUM(qty), 0) AS total_qty
            FROM ShipingList
            {$whereSql}
            GROUP BY part_name
            ORDER BY total_qty DESC, part_name ASC
            LIMIT 5
        ";
        $st = $pdo->prepare($topSql);
        $st->execute($params);
        $topRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $answer = $range['label'] . ' 출하 기준';
        if (!empty($range['implicit'])) {
            $answer .= '으로 봤어요. 날짜를 따로 말하지 않아서 오늘 기준으로 확인했어요.';
        }
        if ($partName) {
            $answer .= ' ' . $partName . ' 필터로';
        }
        $answer .= ' 총 출하수량은 ' . jtgpt_format_int($totalQty) . ' EA예요.';
        $answer .= ' lot ' . jtgpt_format_int($lotCount) . '건, tray ' . jtgpt_format_int($trayCount) . '건, 조회 행 ' . jtgpt_format_int($rowCount) . '건';
        if ($partName === null) {
            $answer .= ', 품번 ' . jtgpt_format_int($partCount) . '종';
        }
        $answer .= '이 잡혔어요.';

        if ($partName === null && $topRows) {
            $parts = [];
            foreach ($topRows as $r) {
                $pn = trim((string)($r['part_name'] ?? ''));
                if ($pn === '') continue;
                $parts[] = $pn . ' ' . jtgpt_format_int((int)($r['total_qty'] ?? 0)) . ' EA';
            }
            if ($parts) {
                $answer .= "\n상위 품번은 " . implode(', ', $parts) . '예요.';
            }
        }

        $suggestions = [];
        if ($partName === null) {
            $suggestions[] = $range['label'] . ' MEM-IR-BASE 출하수량';
        }
        $suggestions[] = $range['label'] . ' 출하 lot 몇개야';
        $suggestions[] = '어제 출하수량 알려줘';

        return [
            'answer' => $answer,
            'actions' => $actions,
            'suggestions' => $suggestions,
        ];
    }
}

if (!function_exists('jtgpt_parse_message')) {
    function jtgpt_parse_message(string $message): array {
        $text = trim($message);
        $normalized = mb_strtolower($text, 'UTF-8');
        $timeHint = jtgpt_detect_time_hint($normalized);

        if ($text === '') {
            return [
                'answer' => '질문이 비어 있어요. 예: 오늘 출하수량 알려줘, 어제 출하 lot 몇개야, 이번 주 MEM-IR-BASE 출하수량',
                'actions' => [],
                'suggestions' => ['오늘 출하수량 알려줘', '어제 출하 lot 몇개야', '이번 주 MEM-IR-BASE 출하수량'],
            ];
        }

        $shippingNeedles = ['출하', '출고', 'ship', 'shipping', 'lot', '포장', '납품', '수량', 'qty', 'ea'];
        if (jtgpt_contains_any($normalized, $shippingNeedles)) {
            try {
                $pdo = dp_get_pdo();
                return jtgpt_shipping_reply($pdo, $text);
            } catch (Throwable $e) {
                return [
                    'answer' => '출하 데이터를 조회하는 중 오류가 났어요. DB 연결이나 테이블 상태를 확인해 주세요.\n' . $e->getMessage(),
                    'actions' => [
                        ['label' => 'QA 출하내역 열기', 'href' => dp_url('shipinglist')],
                    ],
                    'suggestions' => ['오늘 출하수량 알려줘', '어제 출하 lot 몇개야'],
                ];
            }
        }

        $modules = [
            'rma' => [
                'title' => 'RMA 내역',
                'href' => dp_url('rma'),
                'needles' => ['rma', 'return', '반품', '회수', '리턴'],
                'summary' => 'RMA/반품/회수 관련 요청으로 이해했어요. 아직 이쪽은 실데이터 답변보다 화면 이동 중심이에요.',
            ],
            'oqc' => [
                'title' => 'OQC 측정 데이터 조회',
                'href' => dp_url('oqc'),
                'needles' => ['oqc', '측정', 'ng', '불량', '검사', '측정값', 'cavity', 'tool'],
                'summary' => 'OQC 측정/NG/검사 관련 요청으로 이해했어요. 아직 이쪽은 실데이터 답변보다 화면 이동 중심이에요.',
            ],
            'ipqc' => [
                'title' => 'JMP Assist (IPQC)',
                'href' => dp_url('ipqc'),
                'needles' => ['ipqc', 'jmp', '공정능력', 'cpk', 'cp', 'cpu', 'cpl', 'spc', '히스토그램', '그래프'],
                'summary' => 'IPQC/JMP/공정능력 관련 요청으로 이해했어요. 아직 이쪽은 실데이터 답변보다 화면 이동 중심이에요.',
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

        if ($topScore > 0 && isset($modules[$topKey])) {
            $meta = $modules[$topKey];
            $answer = $meta['summary'];
            if ($timeHint !== null) {
                $answer .= ' 날짜 키워드 ' . $timeHint . ' 도 감지했어요.';
            }
            return [
                'answer' => $answer,
                'actions' => [
                    ['label' => $meta['title'] . ' 열기', 'href' => $meta['href']],
                ],
                'suggestions' => ['오늘 출하수량 알려줘', '어제 출하 lot 몇개야', '이번 주 MEM-IR-BASE 출하수량'],
            ];
        }

        return [
            'answer' => '지금은 출하 수량/lot 수는 실제 DB에서 답하고 있어요. 예를 들어 오늘 출하수량 알려줘, 어제 출하 lot 몇개야, 이번 주 MEM-IR-BASE 출하수량처럼 물어보면 돼요.',
            'actions' => [
                ['label' => 'QA 출하내역 열기', 'href' => dp_url('shipinglist')],
            ],
            'suggestions' => ['오늘 출하수량 알려줘', '어제 출하 lot 몇개야', '이번 주 MEM-IR-BASE 출하수량'],
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
            <div class="jtgpt-status">출하 수량/lot 실데이터 응답 2차</div>
        </div>
        <div class="jtgpt-main">
            <div class="jtgpt-hero">
                <h1>어디서부터 시작할까요?</h1>
                <p>이제 출하 수량과 lot 수는 실제 DB에서 답해요. 예: 오늘 출하수량 알려줘</p>
            </div>
            <div class="jtgpt-thread" id="jtgptThread"></div>
            <div class="composer-wrap">
                <form class="composer" id="jtgptForm" autocomplete="off">
                    <textarea class="composer-input" id="jtgptInput" name="message" placeholder="무엇이든 물어보세요" rows="1"></textarea>
                    <div class="composer-bottom">
                        <div class="composer-tools">
                            <span class="composer-pill">＋</span>
                            <span class="composer-pill">실데이터</span>
                            <span class="composer-pill">출하 수량</span>
                        </div>
                        <button class="send-btn" id="jtgptSend" type="submit" aria-label="보내기">↑</button>
                    </div>
                </form>
                <div class="quick-row">
                    <button class="quick-btn" type="button" data-quick="오늘 출하수량 알려줘">오늘 출하수량 알려줘</button>
                    <button class="quick-btn" type="button" data-quick="어제 출하 lot 몇개야">어제 출하 lot 몇개야</button>
                    <button class="quick-btn" type="button" data-quick="이번 주 MEM-IR-BASE 출하수량">이번 주 MEM-IR-BASE 출하수량</button>
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
            appendMessage('assistant', '지금은 응답 연결 중 문제가 있어요. 잠시 후 다시 시도해 주세요.', [], ['오늘 출하수량 알려줘', '어제 출하 lot 몇개야']);
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
