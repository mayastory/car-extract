<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }

date_default_timezone_set('Asia/Seoul');

session_start();
require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/inc/common.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';
require_once JTMES_ROOT . '/Module/Core/lib/jtgpt_session.php';
require_once JTMES_ROOT . '/Module/Core/lib/jtgpt_planner.php';
require_once JTMES_ROOT . '/Module/Core/lib/jtgpt_tools_shipping.php';
require_once JTMES_ROOT . '/Module/Core/lib/jtgpt_tools_quality.php';

dp_auth_guard();
$EMBED = !empty($_GET['embed']);
if (!$EMBED) {
    require_once JTMES_ROOT . '/inc/sidebar.php';
    require_once JTMES_ROOT . '/inc/dp_userbar.php';
}

jtgpt_session_init();

if (!function_exists('jtgpt_h')) {
    function jtgpt_h(?string $s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('jtgpt_scope_label')) {
    function jtgpt_scope_label(array $args): string {
        $bits = [];
        $range = $args['range']['label'] ?? null;
        if ($range) $bits[] = (string)$range;
        if (!empty($args['customer'])) $bits[] = (string)$args['customer'];
        if (!empty($args['part_name'])) $bits[] = (string)$args['part_name'];
        return trim(implode(' ', $bits));
    }
}

if (!function_exists('jtgpt_build_shipinglist_href')) {
    function jtgpt_build_shipinglist_href(string $fromDate, string $toDate, ?string $partName = null, ?string $customer = null): string {
        $qs = ['from_date' => $fromDate, 'to_date' => $toDate];
        if ($partName !== null && $partName !== '') $qs['part_name'] = $partName;
        if ($customer !== null && $customer !== '') $qs['ship_to'] = $customer;
        return dp_url('shipinglist') . '?' . http_build_query($qs);
    }
}

if (!function_exists('jtgpt_action_nav')) {
    function jtgpt_action_nav(string $label, string $route, ?string $href = null, array $extra = []): array {
        return array_merge([
            'label' => $label,
            'kind'  => 'navigate',
            'route' => $route,
            'href'  => $href ?: dp_url(ltrim($route, '/')),
        ], $extra);
    }
}

if (!function_exists('jtgpt_action_graph')) {
    function jtgpt_action_graph(string $label, string $kind, array $graphSpec = []): array {
        return [
            'label' => $label,
            'kind'  => $kind,
            'route' => '/ipqc',
            'href'  => dp_url('ipqc'),
            'graph_spec' => $graphSpec,
        ];
    }
}

if (!function_exists('jtgpt_execute_plan')) {
    function jtgpt_execute_plan(array $plan): array {
        $kind = (string)($plan['kind'] ?? 'clarify');
        $tool = (string)($plan['tool'] ?? '');
        $args = (array)($plan['args'] ?? []);
        $base = ['answer' => '', 'actions' => [], 'suggestions' => [], 'auto_actions' => [], 'state_patch' => []];

        if ($kind === 'clarify') {
            return array_merge($base, [
                'answer' => (string)($plan['answer'] ?? '조금만 더 구체적으로 말해 주세요.'),
                'suggestions' => array_values($plan['suggestions'] ?? []),
            ]);
        }

        if ($tool === 'guard_read_only') {
            return array_merge($base, [
                'answer' => 'JTGPT는 관리자/권한/보안성 작업은 건드리지 않고, 읽기 전용 조회만 처리해요. 출하·OQC·그래프 요청처럼 조회성 질문으로 말해 주세요.',
                'suggestions' => ['자화전자 제일 최근 출하일은?', '최근 7일 OQC NG 많은 포인트', '그래프빌더 열어줘'],
            ]);
        }

        if ($kind === 'action') {
            if ($tool === 'open_ipqc_route') {
                $action = jtgpt_action_nav('JMP Assist (IPQC) 열기', '/ipqc', dp_url('ipqc'));
                return array_merge($base, [
                    'answer' => 'JMP Assist (IPQC) 화면을 열게요.',
                    'actions' => [$action],
                    'auto_actions' => !empty($plan['autorun']) ? [$action] : [],
                    'suggestions' => ['그래프빌더 열어줘', '최근 7일 OQC NG 많은 포인트'],
                ]);
            }
            if ($tool === 'open_ipqc_quick_graph') {
                $action = jtgpt_action_graph('그래프빌더 열기', 'open_ipqc_quick_graph', (array)($args['graph_spec'] ?? []));
                return array_merge($base, [
                    'answer' => 'IPQC 그래프빌더를 열어둘게요. 현재 요청은 그래프 스펙으로 같이 넘겨요.',
                    'actions' => [$action],
                    'auto_actions' => !empty($plan['autorun']) ? [$action] : [],
                    'state_patch' => ['last_graph_spec' => $args['graph_spec'] ?? null],
                    'suggestions' => ['최근 7일 MEM-IR-BASE 그래프 만들어줘', '55-1 포인트로 다시'],
                ]);
            }
            if ($tool === 'open_ipqc_process_capability') {
                $action = jtgpt_action_graph('공정 능력 열기', 'open_ipqc_process_capability', (array)($args['graph_spec'] ?? []));
                return array_merge($base, [
                    'answer' => 'IPQC 공정 능력 창을 열게요.',
                    'actions' => [$action],
                    'auto_actions' => !empty($plan['autorun']) ? [$action] : [],
                    'state_patch' => ['last_graph_spec' => $args['graph_spec'] ?? null],
                    'suggestions' => ['그래프빌더 열어줘', '최근 7일 OQC NG 많은 포인트'],
                ]);
            }
        }

        try {
            $pdo = dp_get_pdo();
        } catch (Throwable $e) {
            return array_merge($base, [
                'answer' => 'DB 연결 중 오류가 났어요. ' . $e->getMessage(),
                'suggestions' => ['자화전자 제일 최근 출하일은?', '최근 7일 OQC NG 많은 포인트'],
            ]);
        }

        if ($tool === 'shipping_last_ship_date') {
            $r = jtgpt_tool_shipping_last_ship_date($pdo, $args);
            $scope = jtgpt_scope_label($args);
            $action = jtgpt_action_nav('QA 출하내역 열기', '/shipinglist', jtgpt_build_shipinglist_href($args['range']['from'] ?? date('Y-m-d'), $args['range']['to'] ?? date('Y-m-d'), $args['part_name'] ?? null, $args['customer'] ?? null));
            if (empty($r['found'])) {
                return array_merge($base, [
                    'answer' => trim(($scope !== '' ? $scope . ' ' : '') . '조건으로는 최근 출하 이력이 없어요.'),
                    'actions' => [$action],
                    'suggestions' => ['오늘 출하수량 알려줘', '최근 7일 출하 요약해줘'],
                ]);
            }
            $row = (array)$r['row'];
            $dt = trim((string)($row['ship_datetime'] ?? ''));
            $dateText = $dt !== '' ? substr($dt, 0, 16) : '알 수 없음';
            $who = trim((string)($args['customer'] ?? ''));
            $part = trim((string)($args['part_name'] ?? ''));
            $subject = $who !== '' ? $who : ($part !== '' ? $part : '전체');
            $answer = $subject . '의 가장 최근 출하일은 ' . $dateText . '이에요.';
            if (!empty($row['part_name']) && $part === '') $answer .= ' 품번은 ' . trim((string)$row['part_name']) . ' 기준이었어요.';
            return array_merge($base, [
                'answer' => $answer,
                'actions' => [$action],
                'suggestions' => ['오늘 출하수량 알려줘', '어제 출하 lot 몇개야', '그래프빌더 열어줘'],
                'state_patch' => ['last_module' => 'shipping', 'last_shipping_subject' => $subject],
            ]);
        }

        if ($tool === 'shipping_summary') {
            $r = jtgpt_tool_shipping_summary($pdo, $args);
            $scope = jtgpt_scope_label($args);
            $action = jtgpt_action_nav('QA 출하내역 열기', '/shipinglist', jtgpt_build_shipinglist_href($args['range']['from'] ?? date('Y-m-d'), $args['range']['to'] ?? date('Y-m-d'), $args['part_name'] ?? null, $args['customer'] ?? null));
            if (empty($r['found'])) {
                $answer = trim(($scope !== '' ? $scope . ' ' : '') . '조건으로는 출하 데이터가 없어요.');
                return array_merge($base, [
                    'answer' => $answer,
                    'actions' => [$action],
                    'suggestions' => ['자화전자 제일 최근 출하일은?', '최근 7일 출하 요약해줘'],
                ]);
            }
            $metric = (string)($args['metric'] ?? 'summary');
            $scopePrefix = $scope !== '' ? ($scope . ' ') : '';
            if ($metric === 'qty') {
                $answer = $scopePrefix . '출하수량은 ' . jtgpt_tool_format_int($r['total_qty']) . ' EA예요.';
            } elseif ($metric === 'lot_count') {
                $answer = $scopePrefix . '출하 lot는 ' . jtgpt_tool_format_int($r['lot_count']) . '건이에요.';
            } elseif ($metric === 'tray_count') {
                $answer = $scopePrefix . 'tray는 ' . jtgpt_tool_format_int($r['tray_count']) . '건이에요.';
            } else {
                $answer = $scopePrefix . '출하는 ' . jtgpt_tool_format_int($r['total_qty']) . ' EA, lot ' . jtgpt_tool_format_int($r['lot_count']) . '건, tray ' . jtgpt_tool_format_int($r['tray_count']) . '건이에요.';
                if (empty($args['part_name']) && !empty($r['top_parts'][0]['part_name'])) {
                    $answer .= ' 가장 많은 품번은 ' . trim((string)$r['top_parts'][0]['part_name']) . '였어요.';
                }
            }
            return array_merge($base, [
                'answer' => $answer,
                'actions' => [$action],
                'suggestions' => ['자화전자 제일 최근 출하일은?', '최근 7일 OQC NG 많은 포인트', '그래프빌더 열어줘'],
                'state_patch' => ['last_module' => 'shipping', 'last_shipping_summary' => $r],
            ]);
        }

        if ($tool === 'oqc_top_ng_points') {
            $r = jtgpt_tool_oqc_top_ng_points($pdo, $args);
            $action = jtgpt_action_nav('OQC 화면 열기', '/oqc', dp_url('oqc'));
            if (empty($r['found'])) {
                $scope = jtgpt_scope_label($args);
                return array_merge($base, [
                    'answer' => trim(($scope !== '' ? $scope . ' ' : '') . '조건으로는 OQC NG 포인트가 안 잡혀요.'),
                    'actions' => [$action],
                    'suggestions' => ['어제 OQC NG 많은 포인트', '그래프빌더 열어줘'],
                    'state_patch' => ['last_module' => 'oqc'],
                ]);
            }
            $rows = array_values($r['rows']);
            $top = $rows[0];
            $parts = [];
            $ranked = [];
            foreach ($rows as $idx => $row) {
                $ranked[] = (string)$row['point_no'];
                $parts[] = ($idx + 1) . '위 ' . trim((string)$row['point_no']) . ' ' . jtgpt_tool_format_int($row['ng_count'] ?? 0) . '건';
            }
            $scope = jtgpt_scope_label($args);
            $answer = ($scope !== '' ? $scope . ' ' : '') . 'OQC에서 NG가 가장 많았던 포인트는 ' . trim((string)$top['point_no']) . '예요. 총 ' . jtgpt_tool_format_int($top['ng_count'] ?? 0) . '건이고, 뒤로는 ' . implode(', ', $parts) . ' 순이에요.';
            return array_merge($base, [
                'answer' => $answer,
                'actions' => [$action],
                'suggestions' => ['1위 포인트 상세 보여줘', '그 포인트 그래프 만들어줘', '그래프빌더 열어줘'],
                'state_patch' => ['last_module' => 'oqc', 'last_ranked_points' => $ranked, 'last_result_kind' => 'oqc_top_ng_points'],
            ]);
        }

        if ($tool === 'oqc_point_detail') {
            $r = jtgpt_tool_oqc_point_detail($pdo, $args);
            $action = jtgpt_action_nav('OQC 화면 열기', '/oqc', dp_url('oqc'));
            $point = (string)($args['point_no'] ?? '');
            if (empty($r['found'])) {
                return array_merge($base, [
                    'answer' => 'OQC 포인트 ' . $point . ' 기준 NG 이력이 안 잡혀요.',
                    'actions' => [$action],
                    'suggestions' => ['최근 7일 OQC NG 많은 포인트', '그래프빌더 열어줘'],
                    'state_patch' => ['last_module' => 'oqc'],
                ]);
            }
            $s = (array)$r['summary'];
            $latest = [];
            foreach ((array)$r['latest_rows'] as $row) {
                $piece = trim((string)($row['ship_date'] ?? ''));
                if (!empty($row['part_name'])) $piece .= ' ' . trim((string)$row['part_name']);
                if (!empty($row['tool_cavity'])) $piece .= ' ' . trim((string)$row['tool_cavity']);
                if (!empty($row['kind'])) $piece .= ' ' . trim((string)$row['kind']);
                if ($piece !== '') $latest[] = $piece;
            }
            $scope = jtgpt_scope_label($args);
            $answer = ($scope !== '' ? $scope . ' ' : '') . 'OQC 포인트 ' . trim((string)$s['point_no']) . ' NG는 ' . jtgpt_tool_format_int($s['ng_count'] ?? 0) . '건이에요.';
            if (!empty($s['last_ship_date'])) $answer .= ' 최근 기준일은 ' . trim((string)$s['last_ship_date']) . '이에요.';
            if ($latest) $answer .= ' 최근 이력은 ' . implode(' / ', array_slice($latest, 0, 3)) . ' 정도예요.';
            return array_merge($base, [
                'answer' => $answer,
                'actions' => [$action, jtgpt_action_graph('그래프빌더 열기', 'open_ipqc_quick_graph', ['source' => 'oqc', 'point_no' => $point, 'part_name' => $args['part_name'] ?? null, 'date_range' => $args['range'] ?? null, 'raw' => 'OQC point detail'])],
                'suggestions' => ['그 포인트 그래프 만들어줘', '최근 7일 OQC NG 많은 포인트', '그래프빌더 열어줘'],
                'state_patch' => ['last_module' => 'oqc', 'last_point_no' => $point, 'last_result_kind' => 'oqc_point_detail'],
            ]);
        }

        return array_merge($base, [
            'answer' => '이번 요청은 아직 처리 루틴이 연결되지 않았어요.',
            'suggestions' => ['자화전자 제일 최근 출하일은?', '최근 7일 OQC NG 많은 포인트', '그래프빌더 열어줘'],
        ]);
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
        if (is_array($decoded)) $payload = $decoded;
    }
    if (!$payload) $payload = $_POST;
    $message = trim((string)($payload['message'] ?? ''));
    $state = jtgpt_session_state();
    jtgpt_session_push('user', $message);
    $plan = jtgpt_planner_plan($message, $state);
    $result = jtgpt_execute_plan($plan);
    if (!empty($result['state_patch']) && is_array($result['state_patch'])) {
        jtgpt_session_merge_state($result['state_patch']);
    }
    jtgpt_session_push('assistant', (string)($result['answer'] ?? ''), ['tool' => $plan['tool'] ?? null, 'kind' => $plan['kind'] ?? null]);
    echo json_encode([
        'ok' => true,
        'answer' => (string)($result['answer'] ?? ''),
        'actions' => array_values($result['actions'] ?? []),
        'suggestions' => array_values($result['suggestions'] ?? []),
        'auto_actions' => array_values($result['auto_actions'] ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$selfUrl = $EMBED ? 'jtgpt_view.php?embed=1' : dp_url('jtgpt_view.php');
?>
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
            <div class="jtgpt-status">출하·OQC·그래프 액션 1차</div>
        </div>
        <div class="jtgpt-main">
            <div class="jtgpt-hero">
                <h1>어디서부터 시작할까요?</h1>
                <p>출하 실데이터, OQC NG 포인트, 그래프빌더 실행을 한 곳에서 처리해요.</p>
            </div>
            <div class="jtgpt-thread" id="jtgptThread"></div>
            <div class="composer-wrap">
                <form class="composer" id="jtgptForm" autocomplete="off">
                    <textarea class="composer-input" id="jtgptInput" name="message" placeholder="무엇이든 물어보세요" rows="1"></textarea>
                    <div class="composer-bottom">
                        <div class="composer-tools">
                            <span class="composer-pill">＋</span>
                            <span class="composer-pill">실데이터</span>
                            <span class="composer-pill">OQC NG</span>
                        </div>
                        <button class="send-btn" id="jtgptSend" type="submit" aria-label="보내기">↑</button>
                    </div>
                </form>
                <div class="quick-row">
                    <button class="quick-btn" type="button" data-quick="자화전자 제일 최근 출하일은?">자화전자 제일 최근 출하일은?</button>
                    <button class="quick-btn" type="button" data-quick="최근 7일 OQC NG 많은 포인트">최근 7일 OQC NG 많은 포인트</button>
                    <button class="quick-btn" type="button" data-quick="그래프빌더 열어줘">그래프빌더 열어줘</button>
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
        if (!page.classList.contains('has-chat')) page.classList.add('has-chat');
    }

    function encodeAction(item){
        try { return encodeURIComponent(JSON.stringify(item || {})); } catch (e) { return ''; }
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
                var href = item && item.href ? item.href : '#';
                html += '<a class="action-btn" href="' + escapeHtml(href) + '" data-jtgpt-action="' + encodeAction(item) + '">' + escapeHtml(item.label || '실행') + '</a>';
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

    function parseAction(raw){
        try { return JSON.parse(decodeURIComponent(String(raw || ''))); } catch (e) { return null; }
    }

    function runAction(action){
        if (!action) return false;
        try {
            if (window.top && window.top !== window && window.top.DP_SHELL_ACTIONS && typeof window.top.DP_SHELL_ACTIONS.run === 'function') {
                window.top.DP_SHELL_ACTIONS.run(action);
                return true;
            }
        } catch (e) {}
        try {
            if (window.top && window.top !== window) {
                window.top.postMessage({type:'dp-shell-action', action:action}, '*');
                if (action.href) return true;
            }
        } catch (e) {}
        if (action.href) {
            location.href = action.href;
            return true;
        }
        return false;
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
            if (Array.isArray(data.auto_actions)) {
                data.auto_actions.forEach(function(item){ runAction(item); });
            }
        } catch (err) {
            removeTyping();
            appendMessage('assistant', '지금은 응답 연결 중 문제가 있어요. 잠시 후 다시 시도해 주세요.', [], ['자화전자 제일 최근 출하일은?', '최근 7일 OQC NG 많은 포인트', '그래프빌더 열어줘']);
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
        var act = e.target.closest('[data-jtgpt-action]');
        if (act) {
            e.preventDefault();
            var action = parseAction(act.getAttribute('data-jtgpt-action') || '');
            if (!runAction(action)) {
                var href = act.getAttribute('href') || '#';
                if (href && href !== '#') location.href = href;
            }
        }
    });

    autoResize();
})();
</script>
</body>
</html>
