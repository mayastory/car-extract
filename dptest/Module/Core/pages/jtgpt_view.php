<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__, 3);
if (!defined('JTMES_ROOT')) {
    define('JTMES_ROOT', $ROOT);
}

$bootstrapCandidates = [
    $ROOT . '/inc/common.php',
    $ROOT . '/lib/auth_guard.php',
    $ROOT . '/config/config.php',
    $ROOT . '/config/db.php',
    $ROOT . '/config/db_config.php',
    $ROOT . '/lib/db.php',
    $ROOT . '/inc/db.php',
    $ROOT . '/bootstrap.php',
    $ROOT . '/inc/bootstrap.php',
];
foreach ($bootstrapCandidates as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (function_exists('dp_auth_guard')) {
    dp_auth_guard();
} elseif (function_exists('dp_require_login')) {
    dp_require_login();
}

if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('dp_url')) {
    function dp_url(string $path): string {
        $path = ltrim($path, '/');
        return '/' . $path;
    }
}
if (!function_exists('jt_now')) {
    function jt_now(): DateTime {
        return new DateTime('now', new DateTimeZone('Asia/Seoul'));
    }
}
if (!function_exists('jt_format_int')) {
    function jt_format_int($n): string {
        return number_format((int)$n);
    }
}
if (!function_exists('jt_contains_any')) {
    function jt_contains_any(string $text, array $needles): bool {
        foreach ($needles as $needle) {
            $needle = trim((string)$needle);
            if ($needle !== '' && mb_strpos($text, mb_strtolower($needle, 'UTF-8')) !== false) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('jt_try_parse_ymd')) {
    function jt_try_parse_ymd(string $y, string $m, string $d): ?string {
        $ymd = trim($y) . '-' . str_pad((string)((int)$m), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)((int)$d), 2, '0', STR_PAD_LEFT);
        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        return ($dt && $dt->format('Y-m-d') === $ymd) ? $ymd : null;
    }
}
if (!function_exists('jt_detect_time_hint')) {
    function jt_detect_time_hint(string $text): ?string {
        $map = [
            '오늘' => ['오늘', '금일', 'today', 'now'],
            '어제' => ['어제', 'yesterday'],
            '이번 주' => ['이번주', '이번 주', '금주', 'this week'],
            '최근 7일' => ['최근7일', '최근 7일', '7일', '일주일', '1주일'],
            '이번 달' => ['이번달', '이번 달', '금월', 'this month'],
        ];
        foreach ($map as $label => $needles) {
            if (jt_contains_any($text, $needles)) {
                return $label;
            }
        }
        return null;
    }
}
if (!function_exists('jt_detect_date_range')) {
    function jt_detect_date_range(string $text): array {
        $now = jt_now();
        $today = $now->format('Y-m-d');

        if (preg_match_all('/(20\d{2})[\.\/-]?(\d{1,2})[\.\/-]?(\d{1,2})/', $text, $m, PREG_SET_ORDER)) {
            $dates = [];
            foreach ($m as $hit) {
                $ymd = jt_try_parse_ymd($hit[1], $hit[2], $hit[3]);
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

        $hint = jt_detect_time_hint($text);
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
        return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => true];
    }
}
if (!function_exists('jt_extract_part_name')) {
    function jt_extract_part_name(string $message): ?string {
        $text = trim($message);
        if ($text === '') return null;
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
        $lower = mb_strtolower($text, 'UTF-8');
        foreach ($aliasMap as $needle => $mapped) {
            if (mb_strpos($lower, $needle) !== false) return $mapped;
        }
        if (preg_match('/\b(MEM-[A-Z0-9\.\-]+)\b/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }
        return null;
    }
}
if (!function_exists('jt_extract_customer')) {
    function jt_extract_customer(string $message): ?array {
        $text = trim($message);
        $map = [
            ['label' => '자화전자', 'like' => '%자화전자%'],
            ['label' => '엘지이노텍', 'like' => '%엘지이노텍%'],
            ['label' => 'LG이노텍', 'like' => '%엘지이노텍%'],
            ['label' => 'LG', 'like' => '%엘지이노텍%'],
        ];
        foreach ($map as $row) {
            if (mb_strpos($text, $row['label']) !== false) {
                return $row;
            }
        }
        return null;
    }
}
if (!function_exists('jt_extract_point_no')) {
    function jt_extract_point_no(string $message): ?string {
        if (preg_match('/\b(\d{1,3}(?:-\d{1,2})?)\b/u', $message, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
if (!function_exists('jt_shipping_intent')) {
    function jt_shipping_intent(string $text): string {
        $t = mb_strtolower(trim($text), 'UTF-8');
        if ($t === '') return 'unknown';

        $hasRecent = jt_contains_any($t, ['최근', '제일최근', '가장최근', '마지막', '최신']);
        if (jt_contains_any($t, ['출하일', '출고일']) || ($hasRecent && jt_contains_any($t, ['출하', '출고']))) {
            return 'latest_ship_date';
        }
        if (jt_contains_any($t, ['lot']) && jt_contains_any($t, ['몇', '건', '갯수', '개수'])) {
            return 'lot_count';
        }
        if (jt_contains_any($t, ['tray']) && jt_contains_any($t, ['몇', '건', '갯수', '개수'])) {
            return 'tray_count';
        }
        if (jt_contains_any($t, ['수량', 'qty', 'ea', '합계', '총']) && jt_contains_any($t, ['출하', '출고'])) {
            return 'total_qty';
        }
        if (jt_contains_any($t, ['뭐가 제일 많이', '가장 많이', '최다']) && jt_contains_any($t, ['출하', '출고'])) {
            return 'top_part';
        }
        if (jt_contains_any($t, ['요약', '정리'])) {
            return 'summary';
        }
        if (jt_contains_any($t, ['출하', '출고', 'ship', 'shipping'])) {
            return 'summary';
        }
        return 'unknown';
    }
}
if (!function_exists('jt_shipping_where')) {
    function jt_shipping_where(array $range, ?string $partName, ?array $customer): array {
        $where = ['ship_datetime >= :from_dt', 'ship_datetime < :to_dt'];
        $params = [
            ':from_dt' => $range['from'] . ' 00:00:00',
            ':to_dt' => date('Y-m-d 00:00:00', strtotime($range['to'] . ' +1 day')),
        ];
        if ($partName) {
            $where[] = 'part_name LIKE :part_name';
            $params[':part_name'] = '%' . $partName . '%';
        }
        if ($customer) {
            $where[] = 'ship_to LIKE :ship_to';
            $params[':ship_to'] = $customer['like'];
        }
        return [$where, $params];
    }
}
if (!function_exists('jt_shipping_href')) {
    function jt_shipping_href(array $range, ?string $partName, ?array $customer): string {
        $q = [
            'from_date' => $range['from'],
            'to_date' => $range['to'],
        ];
        if ($partName) $q['part_name'] = $partName;
        if ($customer) $q['ship_to'] = $customer['label'];
        return dp_url('shipinglist') . '?' . http_build_query($q);
    }
}
if (!function_exists('jt_shipping_reply')) {
    function jt_shipping_reply(PDO $pdo, string $message): array {
        $text = trim($message);
        $range = jt_detect_date_range(mb_strtolower($text, 'UTF-8'));
        $partName = jt_extract_part_name($text);
        $customer = jt_extract_customer($text);
        $intent = jt_shipping_intent($text);
        [$where, $params] = jt_shipping_where($range, $partName, $customer);
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $scopeBits = [];
        if ($customer) $scopeBits[] = $customer['label'];
        if ($partName) $scopeBits[] = $partName;
        $scope = $scopeBits ? implode(' / ', $scopeBits) . ' ' : '';
        $actions = [
            ['label' => 'QA 출하내역 열기', 'href' => jt_shipping_href($range, $partName, $customer)],
        ];

        if ($intent === 'latest_ship_date') {
            $sql = "SELECT MAX(ship_datetime) AS last_ship_datetime FROM ShipingList {$whereSql}";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $last = (string)($st->fetchColumn() ?: '');
            if ($last === '') {
                return [
                    'answer' => $scope . $range['label'] . ' 범위에서는 출하 이력이 없어요.',
                    'actions' => $actions,
                    'suggestions' => ['어제 출하수량 알려줘', '자화전자 최근 7일 출하수량', '오늘 출하 lot 몇개야'],
                ];
            }
            $dateOnly = substr($last, 0, 10);
            return [
                'answer' => $scope . '기준 가장 최근 출하일은 ' . $dateOnly . '입니다.',
                'actions' => $actions,
                'suggestions' => ['그날 출하수량 알려줘', '그날 lot 몇개야', '자화전자 최근 출하수량 요약'],
            ];
        }

        $summarySql = "SELECT COUNT(*) AS row_count, COALESCE(SUM(qty),0) AS total_qty, COUNT(DISTINCT small_pack_no) AS lot_count, COUNT(DISTINCT tray_no) AS tray_count, COUNT(DISTINCT part_name) AS part_count FROM ShipingList {$whereSql}";
        $st = $pdo->prepare($summarySql);
        $st->execute($params);
        $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $rowCount = (int)($summary['row_count'] ?? 0);
        $totalQty = (int)($summary['total_qty'] ?? 0);
        $lotCount = (int)($summary['lot_count'] ?? 0);
        $trayCount = (int)($summary['tray_count'] ?? 0);
        $partCount = (int)($summary['part_count'] ?? 0);

        if ($rowCount <= 0) {
            return [
                'answer' => $scope . $range['label'] . ' 기준 출하 데이터가 없어요.',
                'actions' => $actions,
                'suggestions' => ['어제 출하수량 알려줘', '최근 7일 출하 요약해줘', '자화전자 최근 출하일 알려줘'],
            ];
        }

        if ($intent === 'lot_count') {
            return [
                'answer' => $scope . $range['label'] . ' 출하 lot는 ' . jt_format_int($lotCount) . '건입니다.',
                'actions' => $actions,
                'suggestions' => ['같은 조건 출하수량 알려줘', '같은 조건 tray 몇개야', '같은 조건 출하 요약해줘'],
            ];
        }
        if ($intent === 'tray_count') {
            return [
                'answer' => $scope . $range['label'] . ' tray는 ' . jt_format_int($trayCount) . '건입니다.',
                'actions' => $actions,
                'suggestions' => ['같은 조건 lot 몇개야', '같은 조건 출하수량 알려줘', '같은 조건 출하 요약해줘'],
            ];
        }
        if ($intent === 'total_qty') {
            return [
                'answer' => $scope . $range['label'] . ' 총 출하수량은 ' . jt_format_int($totalQty) . ' EA입니다.',
                'actions' => $actions,
                'suggestions' => ['같은 조건 lot 몇개야', '같은 조건 tray 몇개야', '같은 조건 출하 요약해줘'],
            ];
        }
        if ($intent === 'top_part') {
            $topSql = "SELECT part_name, COALESCE(SUM(qty),0) AS total_qty FROM ShipingList {$whereSql} GROUP BY part_name ORDER BY total_qty DESC, part_name ASC LIMIT 1";
            $st = $pdo->prepare($topSql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $pn = trim((string)($row['part_name'] ?? ''));
            $q = (int)($row['total_qty'] ?? 0);
            if ($pn !== '') {
                return [
                    'answer' => $scope . $range['label'] . ' 가장 많이 출하된 품번은 ' . $pn . '이고, 수량은 ' . jt_format_int($q) . ' EA입니다.',
                    'actions' => $actions,
                    'suggestions' => [$pn . ' 출하수량 알려줘', '같은 조건 lot 몇개야', '같은 조건 출하 요약해줘'],
                ];
            }
        }

        $topSql = "SELECT part_name, COALESCE(SUM(qty),0) AS total_qty FROM ShipingList {$whereSql} GROUP BY part_name ORDER BY total_qty DESC, part_name ASC LIMIT 3";
        $st = $pdo->prepare($topSql);
        $st->execute($params);
        $topRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pieces = [];
        foreach ($topRows as $row) {
            $pn = trim((string)($row['part_name'] ?? ''));
            if ($pn === '') continue;
            $pieces[] = $pn . ' ' . jt_format_int((int)($row['total_qty'] ?? 0)) . ' EA';
        }
        $answer = $scope . $range['label'] . ' 기준 출하수량은 ' . jt_format_int($totalQty) . ' EA, lot ' . jt_format_int($lotCount) . '건, tray ' . jt_format_int($trayCount) . '건입니다.';
        if (!$partName) {
            $answer .= ' 품번은 ' . jt_format_int($partCount) . '종입니다.';
        }
        if ($pieces) {
            $answer .= "\n상위 품번은 " . implode(', ', $pieces) . '입니다.';
        }
        return [
            'answer' => $answer,
            'actions' => $actions,
            'suggestions' => ['같은 조건 출하수량만 알려줘', '같은 조건 lot 몇개야', '자화전자 최근 출하일 알려줘'],
        ];
    }
}
if (!function_exists('jt_oqc_schema')) {
    function jt_oqc_schema(PDO $pdo): array {
        $headerCols = [];
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `oqc_header`');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $headerCols[strtolower((string)($r['Field'] ?? ''))] = true;
            }
        } catch (Throwable $e) {
        }
        return [
            'has_ship_date' => isset($headerCols['ship_date']),
            'has_lot_date' => isset($headerCols['lot_date']),
        ];
    }
}
if (!function_exists('jt_oqc_date_sql')) {
    function jt_oqc_date_sql(array $schema): string {
        if (!empty($schema['has_ship_date'])) {
            return 'COALESCE(h.ship_date, h.lot_date)';
        }
        if (!empty($schema['has_lot_date'])) {
            return 'h.lot_date';
        }
        return 'NULL';
    }
}
if (!function_exists('jt_oqc_reply')) {
    function jt_oqc_reply(PDO $pdo, string $message): array {
        $text = trim($message);
        $normalized = mb_strtolower($text, 'UTF-8');
        $pointNo = jt_extract_point_no($text);
        $partName = jt_extract_part_name($text);
        $range = jt_detect_date_range($normalized);
        $schema = jt_oqc_schema($pdo);
        $dateExpr = jt_oqc_date_sql($schema);

        $where = ['r.result_ok = 0'];
        $params = [];
        if ($partName) {
            $where[] = 'h.part_name = :part_name';
            $params[':part_name'] = $partName;
        }
        if ($pointNo) {
            $where[] = 'r.point_no = :point_no';
            $params[':point_no'] = $pointNo;
        }
        if ($dateExpr !== 'NULL') {
            $where[] = "{$dateExpr} >= :from_date";
            $where[] = "{$dateExpr} <= :to_date";
            $params[':from_date'] = $range['from'];
            $params[':to_date'] = $range['to'];
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $actions = [
            ['label' => 'OQC 측정 데이터 조회 열기', 'href' => dp_url('oqc')],
        ];

        if (!$pointNo && !jt_contains_any($normalized, ['ng 많은', 'ng많은', '자주', '많은 포인트', '탑', 'top'])) {
            $ask = 'OQC는 바로 찾을 수 있게 좁혀 말해줘. 예: "최근 7일 MEM-IR-BASE OQC NG 많은 포인트", "어제 OQC 55-1 포인트 NG"';
            return [
                'answer' => $ask,
                'actions' => $actions,
                'suggestions' => ['최근 7일 OQC NG 많은 포인트', '어제 OQC 55-1 포인트 NG', 'MEM-IR-BASE OQC NG 많은 포인트'],
            ];
        }

        if ($pointNo) {
            $sql = "SELECT COUNT(*) AS ng_count, MAX({$dateExpr}) AS last_date FROM oqc_result_header r JOIN oqc_header h ON h.id = r.header_id {$whereSql}";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $count = (int)($row['ng_count'] ?? 0);
            $lastDate = trim((string)($row['last_date'] ?? ''));
            if ($count <= 0) {
                return [
                    'answer' => $range['label'] . ' 범위에서 OQC 포인트 ' . $pointNo . ' NG는 잡히지 않았어요' . ($partName ? ' (' . $partName . ')' : '') . '.',
                    'actions' => $actions,
                    'suggestions' => ['범위를 넓혀서 다시 찾아줘', '최근 7일 OQC NG 많은 포인트', 'OQC 측정 데이터 조회 열기'],
                ];
            }
            return [
                'answer' => $range['label'] . ' 범위에서 OQC 포인트 ' . $pointNo . ' NG는 ' . jt_format_int($count) . '건입니다' . ($partName ? ' (' . $partName . ')' : '') . ($lastDate ? '. 마지막 검출일은 ' . $lastDate . '입니다.' : '.'),
                'actions' => $actions,
                'suggestions' => ['같은 범위 OQC NG 많은 포인트', '같은 범위 다른 포인트도 찾아줘', 'OQC 측정 데이터 조회 열기'],
            ];
        }

        $sql = "SELECT r.point_no, COUNT(*) AS ng_count FROM oqc_result_header r JOIN oqc_header h ON h.id = r.header_id {$whereSql} GROUP BY r.point_no ORDER BY ng_count DESC, r.point_no ASC LIMIT 5";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return [
                'answer' => $range['label'] . ' 범위에서는 OQC NG 포인트가 없습니다' . ($partName ? ' (' . $partName . ')' : '') . '.',
                'actions' => $actions,
                'suggestions' => ['범위를 넓혀서 다시 찾아줘', '어제 OQC 55-1 포인트 NG', 'OQC 측정 데이터 조회 열기'],
            ];
        }
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = trim((string)$r['point_no']) . ' ' . jt_format_int((int)$r['ng_count']) . '건';
        }
        return [
            'answer' => $range['label'] . ' OQC에서 NG가 많은 포인트는 ' . implode(', ', $parts) . '입니다' . ($partName ? ' (' . $partName . ')' : '') . '.',
            'actions' => $actions,
            'suggestions' => ['1위 포인트 상세 보여줘', '어제 OQC 55-1 포인트 NG', 'OQC 측정 데이터 조회 열기'],
        ];
    }
}
if (!function_exists('jt_action_reply')) {
    function jt_action_reply(string $message): ?array {
        $text = mb_strtolower(trim($message), 'UTF-8');
        if ($text === '') return null;

        if (jt_contains_any($text, ['그래프빌더', 'graph builder', 'jmp', 'ipqc']) && jt_contains_any($text, ['켜줘', '열어줘', '열기', '실행'])) {
            return [
                'answer' => 'JMP Assist(IPQC)를 새 창으로 열게요.',
                'actions' => [
                    ['label' => 'JMP Assist 새 창 열기', 'href' => dp_url('ipqc'), 'open' => 'new_window'],
                ],
                'suggestions' => ['공정능력 그래프 열어줘', 'IPQC 화면 열어줘', 'OQC 창도 열어줘'],
            ];
        }
        if (jt_contains_any($text, ['oqc']) && jt_contains_any($text, ['켜줘', '열어줘', '열기', '실행'])) {
            return [
                'answer' => 'OQC 측정 데이터 조회 화면을 열게요.',
                'actions' => [
                    ['label' => 'OQC 화면 열기', 'href' => dp_url('oqc')],
                ],
                'suggestions' => ['최근 7일 OQC NG 많은 포인트', '어제 OQC 55-1 포인트 NG', 'JMP Assist 새 창 열기'],
            ];
        }
        if (jt_contains_any($text, ['qa', '출하', 'shipinglist']) && jt_contains_any($text, ['켜줘', '열어줘', '열기', '실행'])) {
            return [
                'answer' => 'QA 출하내역 화면을 열게요.',
                'actions' => [
                    ['label' => 'QA 출하내역 열기', 'href' => dp_url('shipinglist')],
                ],
                'suggestions' => ['오늘 출하수량 알려줘', '자화전자 최근 출하일 알려줘', 'JMP Assist 새 창 열기'],
            ];
        }
        return null;
    }
}
if (!function_exists('jt_parse_message')) {
    function jt_parse_message(string $message): array {
        $text = trim($message);
        $normalized = mb_strtolower($text, 'UTF-8');

        if ($text === '') {
            return [
                'answer' => '질문이 비어 있어요. 예: 자화전자 제일 최근 출하일은?, 어제 출하 lot 몇개야, 최근 7일 OQC NG 많은 포인트',
                'actions' => [],
                'suggestions' => ['자화전자 제일 최근 출하일은?', '어제 출하 lot 몇개야', '최근 7일 OQC NG 많은 포인트'],
            ];
        }

        $action = jt_action_reply($text);
        if ($action !== null) {
            return $action;
        }

        $needsNgClarify = jt_contains_any($normalized, ['ng', '불량', '이상']) && !jt_contains_any($normalized, ['oqc', 'omm', 'cmm', 'aoi']);
        if ($needsNgClarify) {
            return [
                'answer' => 'NG는 어느 공정으로 볼지 먼저 말해줘. OMM / CMM / AOI / OQC 중 하나로 좁혀주면 바로 찾을게요.',
                'actions' => [],
                'suggestions' => ['최근 7일 OQC NG 많은 포인트', '최근 7일 CMM NG 찾아줘', 'AOI NG 많은 모델 보여줘'],
            ];
        }

        $shippingNeedles = ['출하', '출고', 'ship', 'shipping', 'lot', 'tray', '납품'];
        if (jt_contains_any($normalized, $shippingNeedles)) {
            try {
                if (!function_exists('dp_get_pdo')) {
                    throw new RuntimeException('dp_get_pdo() 가 로드되지 않았습니다.');
                }
                $pdo = dp_get_pdo();
                return jt_shipping_reply($pdo, $text);
            } catch (Throwable $e) {
                return [
                    'answer' => '출하 데이터를 조회하는 중 오류가 났어요. ' . $e->getMessage(),
                    'actions' => [['label' => 'QA 출하내역 열기', 'href' => dp_url('shipinglist')]],
                    'suggestions' => ['어제 출하수량 알려줘', '자화전자 제일 최근 출하일은?', '오늘 출하 lot 몇개야'],
                ];
            }
        }

        if (jt_contains_any($normalized, ['oqc', '포인트', 'ng', '불량'])) {
            try {
                if (!function_exists('dp_get_pdo')) {
                    throw new RuntimeException('dp_get_pdo() 가 로드되지 않았습니다.');
                }
                $pdo = dp_get_pdo();
                return jt_oqc_reply($pdo, $text);
            } catch (Throwable $e) {
                return [
                    'answer' => 'OQC 데이터를 조회하는 중 오류가 났어요. ' . $e->getMessage(),
                    'actions' => [['label' => 'OQC 측정 데이터 조회 열기', 'href' => dp_url('oqc')]],
                    'suggestions' => ['최근 7일 OQC NG 많은 포인트', '어제 OQC 55-1 포인트 NG', 'OQC 화면 열어줘'],
                ];
            }
        }

        return [
            'answer' => '아직 바로 처리할 수 있는 건 출하 실데이터, OQC NG 포인트, 그리고 화면 열기 요청이에요. OMM / CMM / AOI도 같은 방식으로 붙일 수 있게 이어서 확장하면 됩니다.',
            'actions' => [
                ['label' => 'JMP Assist 새 창 열기', 'href' => dp_url('ipqc'), 'open' => 'new_window'],
                ['label' => 'OQC 측정 데이터 조회 열기', 'href' => dp_url('oqc')],
            ],
            'suggestions' => ['자화전자 제일 최근 출하일은?', '최근 7일 OQC NG 많은 포인트', '그래프빌더 켜줘'],
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
    $result = jt_parse_message($message);
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
        --bg:#111417;
        --bg2:#171b20;
        --panel:rgba(33,36,44,.88);
        --panel2:rgba(44,48,57,.88);
        --border:rgba(255,255,255,.08);
        --text:#f3f5f8;
        --muted:#b7bec8;
        --accent:#cfd5de;
        --chip:rgba(255,255,255,.08);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
        margin:0;
        font-family:Segoe UI,Apple SD Gothic Neo,Pretendard,Malgun Gothic,sans-serif;
        color:var(--text);
        background:
            radial-gradient(ellipse at top, rgba(37,74,62,.45), transparent 45%),
            radial-gradient(ellipse at center, rgba(30,51,59,.35), transparent 55%),
            linear-gradient(180deg, #14181d 0%, #101317 100%);
        overflow:hidden;
    }
    body::before{
        content:"0 1 0 1 1 0 0 1 0 1 0 0 1 1 0 1 0 0 1 1 0 1 0 1 0 0 1 1 0 1 0 0 1 1 0 1 0 0 1";
        position:fixed;inset:0;
        color:rgba(170,255,190,.07);
        font-family:Consolas,monospace;
        font-size:22px;
        line-height:32px;
        letter-spacing:22px;
        white-space:pre-wrap;
        pointer-events:none;
        padding:10px 30px;
        overflow:hidden;
    }
    .page{position:relative;height:100%;display:flex;flex-direction:column;max-width:1200px;margin:0 auto;padding:18px 22px 24px}
    .badge{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border:1px solid rgba(255,255,255,.16);border-radius:999px;background:rgba(255,255,255,.03);font-weight:600;color:#eef2f6;backdrop-filter:blur(8px);align-self:flex-start}
    .chat{flex:1;overflow:auto;padding:18px 0 16px;display:flex;flex-direction:column;gap:18px}
    .row{display:flex;flex-direction:column;gap:8px}
    .row.user{align-items:flex-end}
    .who{font-size:13px;color:var(--muted);padding:0 6px}
    .bubble{max-width:820px;border-radius:24px;padding:18px 22px;line-height:1.65;font-size:15px;white-space:pre-wrap;backdrop-filter:blur(10px);border:1px solid var(--border);box-shadow:0 12px 40px rgba(0,0,0,.22)}
    .row.assistant .bubble{background:var(--panel)}
    .row.user .bubble{background:var(--panel2)}
    .actions,.suggestions{display:flex;flex-wrap:wrap;gap:10px;margin-top:2px}
    .chip{border:1px solid rgba(255,255,255,.12);background:var(--chip);color:#eef2f6;border-radius:999px;padding:10px 14px;font-size:14px;text-decoration:none;cursor:pointer}
    .chip:hover{background:rgba(255,255,255,.12)}
    .composerWrap{padding-top:8px}
    .composer{max-width:760px;margin:0 auto;background:rgba(50,54,64,.95);border:1px solid var(--border);border-radius:30px;padding:18px 18px 14px;box-shadow:0 24px 50px rgba(0,0,0,.28);backdrop-filter:blur(12px)}
    textarea{width:100%;min-height:78px;max-height:240px;resize:none;border:0;outline:0;background:transparent;color:#f5f7fb;font-size:18px;line-height:1.5;font-family:inherit}
    textarea::placeholder{color:#d0d5dd}
    .composerBottom{display:flex;align-items:center;justify-content:space-between;margin-top:10px;gap:12px}
    .leftTools,.rightTools{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .mini{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:var(--muted);font-size:14px}
    .send{width:44px;height:44px;border-radius:50%;border:0;background:#eef2f6;color:#111;cursor:pointer;font-size:20px;font-weight:700}
    .hero{padding:40px 0 12px;text-align:center;color:#eef3f9;font-size:24px}
    .hidden{display:none !important}
</style>
</head>
<body>
<div class="page">
    <div class="badge">✦ JTGPT <span style="opacity:.7;font-size:12px">BETA</span></div>
    <div id="hero" class="hero">어디서부터 시작할까요?</div>
    <div id="chat" class="chat"></div>
    <div class="composerWrap">
        <div class="composer">
            <textarea id="message" placeholder="무엇이든 물어보세요"></textarea>
            <div class="composerBottom">
                <div class="leftTools">
                    <div class="mini">＋</div>
                    <div class="mini">실데이터</div>
                    <div class="mini">질문 애매하면 되묻기</div>
                </div>
                <div class="rightTools">
                    <button id="sendBtn" class="send" type="button">↑</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    const chat = document.getElementById('chat');
    const hero = document.getElementById('hero');
    const textarea = document.getElementById('message');
    const sendBtn = document.getElementById('sendBtn');

    function createRow(role, text, actions, suggestions){
        const row = document.createElement('div');
        row.className = 'row ' + role;
        const who = document.createElement('div');
        who.className = 'who';
        who.textContent = role === 'user' ? '나' : 'JTGPT';
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.textContent = text || '';
        row.appendChild(who);
        row.appendChild(bubble);

        if (actions && actions.length) {
            const box = document.createElement('div');
            box.className = 'actions';
            actions.forEach(a => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'chip';
                btn.textContent = a.label || '열기';
                btn.addEventListener('click', () => {
                    if (!a.href) return;
                    if (a.open === 'new_window') {
                        window.open(a.href, '_blank', 'noopener');
                        return;
                    }
                    if (window.top && window.top !== window) {
                        window.top.location.href = a.href;
                    } else {
                        window.location.href = a.href;
                    }
                });
                box.appendChild(btn);
            });
            row.appendChild(box);
        }
        if (suggestions && suggestions.length) {
            const box = document.createElement('div');
            box.className = 'suggestions';
            suggestions.forEach(s => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'chip';
                btn.textContent = s;
                btn.addEventListener('click', () => {
                    textarea.value = s;
                    textarea.focus();
                });
                box.appendChild(btn);
            });
            row.appendChild(box);
        }
        chat.appendChild(row);
        hero.classList.add('hidden');
        chat.scrollTop = chat.scrollHeight;
    }

    async function send(){
        const message = textarea.value.trim();
        if (!message) return;
        createRow('user', message);
        textarea.value = '';
        textarea.style.height = '78px';
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({message})
            });
            const data = await res.json();
            createRow('assistant', data.answer || '응답이 비어 있어요.', data.actions || [], data.suggestions || []);
        } catch (err) {
            createRow('assistant', '응답을 불러오지 못했어요. 네트워크나 서버 상태를 확인해 주세요.');
        }
    }

    textarea.addEventListener('input', () => {
        textarea.style.height = '78px';
        textarea.style.height = Math.min(textarea.scrollHeight, 240) + 'px';
    });
    textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    });
    sendBtn.addEventListener('click', send);
})();
</script>
</body>
</html>
