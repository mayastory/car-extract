<?php
if (!function_exists('jtgpt_planner_contains_any')) {
    function jtgpt_planner_contains_any(string $text, array $needles): bool {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_strpos($text, mb_strtolower((string)$needle, 'UTF-8')) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('jtgpt_planner_try_parse_ymd')) {
    function jtgpt_planner_try_parse_ymd($year, $month, $day): ?string {
        $y = (int)$year;
        $m = (int)$month;
        $d = (int)$day;
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        $ymd = sprintf('%04d-%02d-%02d', $y, $m, $d);
        return $ymd;
    }
}

if (!function_exists('jtgpt_planner_default_recent_range')) {
    function jtgpt_planner_default_recent_range(int $days = 7): array {
        $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
        $today = $now->format('Y-m-d');
        $start = (clone $now)->modify('-' . max(0, $days - 1) . ' day')->format('Y-m-d');
        return ['from' => $start, 'to' => $today, 'label' => '최근 ' . $days . '일', 'implicit' => true];
    }
}

if (!function_exists('jtgpt_planner_detect_time_hint')) {
    function jtgpt_planner_detect_time_hint(string $text): ?string {
        $map = [
            '오늘' => ['오늘', '금일', 'today', 'now'],
            '어제' => ['어제', 'yesterday'],
            '이번 주' => ['이번주', '이번 주', '금주', 'this week'],
            '최근 7일' => ['최근 7일', '7일', '일주일', '1주일', '최근 일주일'],
            '최근 30일' => ['최근 30일', '30일', '한달', '1달', '최근 한달'],
            '이번 달' => ['이번달', '이번 달', '금월', 'this month'],
        ];
        foreach ($map as $label => $needles) {
            if (jtgpt_planner_contains_any($text, $needles)) {
                return $label;
            }
        }
        return null;
    }
}

if (!function_exists('jtgpt_planner_detect_date_range')) {
    function jtgpt_planner_detect_date_range(string $text): array {
        $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
        $today = $now->format('Y-m-d');

        if (preg_match_all('/(20\d{2})[\.\/-]?(\d{1,2})[\.\/-]?(\d{1,2})/u', $text, $m, PREG_SET_ORDER)) {
            $dates = [];
            foreach ($m as $hit) {
                $ymd = jtgpt_planner_try_parse_ymd($hit[1], $hit[2], $hit[3]);
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

        $hint = jtgpt_planner_detect_time_hint($text);
        if ($hint === '오늘') {
            return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => false];
        }
        if ($hint === '어제') {
            $d = (clone $now)->modify('-1 day')->format('Y-m-d');
            return ['from' => $d, 'to' => $d, 'label' => '어제', 'implicit' => false];
        }
        if ($hint === '이번 주') {
            $s = (clone $now)->modify('monday this week')->format('Y-m-d');
            return ['from' => $s, 'to' => $today, 'label' => '이번 주', 'implicit' => false];
        }
        if ($hint === '최근 7일') {
            $s = (clone $now)->modify('-6 day')->format('Y-m-d');
            return ['from' => $s, 'to' => $today, 'label' => '최근 7일', 'implicit' => false];
        }
        if ($hint === '최근 30일') {
            $s = (clone $now)->modify('-29 day')->format('Y-m-d');
            return ['from' => $s, 'to' => $today, 'label' => '최근 30일', 'implicit' => false];
        }
        if ($hint === '이번 달') {
            $s = (clone $now)->modify('first day of this month')->format('Y-m-d');
            return ['from' => $s, 'to' => $today, 'label' => '이번 달', 'implicit' => false];
        }
        return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => true];
    }
}

if (!function_exists('jtgpt_planner_extract_part_name')) {
    function jtgpt_planner_extract_part_name(string $message): ?string {
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

if (!function_exists('jtgpt_planner_extract_customer')) {
    function jtgpt_planner_extract_customer(string $message): ?string {
        $lower = mb_strtolower($message, 'UTF-8');
        if (jtgpt_planner_contains_any($lower, ['자화전자', '자화', 'jh'])) return '자화전자';
        if (jtgpt_planner_contains_any($lower, ['엘지이노텍', '이노텍', 'lg', '엘지'])) return '엘지이노텍';
        return null;
    }
}

if (!function_exists('jtgpt_planner_extract_point_no')) {
    function jtgpt_planner_extract_point_no(string $message): ?string {
        if (preg_match('/(?:point|포인트)\s*([0-9A-Z]{1,4}(?:-[0-9A-Z]{1,4})+)/iu', $message, $m)) {
            return strtoupper(trim($m[1]));
        }
        if (preg_match('/\b([0-9A-Z]{1,4}(?:-[0-9A-Z]{1,4})+)\b/u', strtoupper($message), $m)) {
            return strtoupper(trim($m[1]));
        }
        return null;
    }
}

if (!function_exists('jtgpt_planner_extract_tool')) {
    function jtgpt_planner_extract_tool(string $message): ?string {
        if (preg_match('/\btool\s*([A-Z0-9]{1,6})\b/iu', $message, $m)) return strtoupper(trim($m[1]));
        if (preg_match('/\b([A-Z0-9]{1,6})\s*tool\b/iu', $message, $m)) return strtoupper(trim($m[1]));
        if (preg_match('/툴\s*([A-Z0-9]{1,6})/iu', $message, $m)) return strtoupper(trim($m[1]));
        if (preg_match('/([A-Z0-9]{1,6})\s*툴/iu', $message, $m)) return strtoupper(trim($m[1]));
        return null;
    }
}

if (!function_exists('jtgpt_planner_extract_cavity')) {
    function jtgpt_planner_extract_cavity(string $message): ?string {
        if (preg_match('/\b([0-9]{1,2})\s*cav\b/iu', $message, $m)) return ((int)$m[1]) . 'CAV';
        if (preg_match('/\bcavity\s*([0-9]{1,2})\b/iu', $message, $m)) return ((int)$m[1]) . 'CAV';
        if (preg_match('/([0-9]{1,2})\s*캐비티/u', $message, $m)) return ((int)$m[1]) . 'CAV';
        return null;
    }
}

if (!function_exists('jtgpt_planner_extract_limit')) {
    function jtgpt_planner_extract_limit(string $message): int {
        if (preg_match('/\btop\s*(\d{1,2})\b/iu', $message, $m)) return max(1, min(30, (int)$m[1]));
        if (preg_match('/상위\s*(\d{1,2})/u', $message, $m)) return max(1, min(30, (int)$m[1]));
        if (preg_match('/(\d{1,2})\s*개/u', $message, $m)) return max(1, min(30, (int)$m[1]));
        return 5;
    }
}

if (!function_exists('jtgpt_planner_build_graph_spec')) {
    function jtgpt_planner_build_graph_spec(string $message): array {
        $lower = mb_strtolower($message, 'UTF-8');
        $chart = 'line';
        if (jtgpt_planner_contains_any($lower, ['막대', 'bar'])) $chart = 'bar';
        elseif (jtgpt_planner_contains_any($lower, ['산점', 'scatter', '점그래프'])) $chart = 'scatter';
        elseif (jtgpt_planner_contains_any($lower, ['박스', 'boxplot', 'box plot', '상자'])) $chart = 'box';

        $source = 'ipqc';
        foreach (['oqc', 'omm', 'cmm', 'aoi', 'ipqc'] as $src) {
            if (mb_strpos($lower, $src) !== false) {
                $source = $src;
                break;
            }
        }

        $color = null;
        if (jtgpt_planner_contains_any($lower, ['cavity별 색상', '색상 cavity', '색상은 cavity', 'cavity 색상'])) $color = 'cavity';
        elseif (jtgpt_planner_contains_any($lower, ['tool별 색상', '색상 tool', 'tool 색상'])) $color = 'tool';

        $group = null;
        if (jtgpt_planner_contains_any($lower, ['그룹 y', 'group y'])) $group = 'group_y';

        return [
            'source' => $source,
            'chart' => $chart,
            'part_name' => jtgpt_planner_extract_part_name($message),
            'point_no' => jtgpt_planner_extract_point_no($message),
            'date_range' => jtgpt_planner_detect_date_range($lower),
            'color' => $color,
            'group' => $group,
            'raw' => $message,
        ];
    }
}

if (!function_exists('jtgpt_planner_plan')) {
    function jtgpt_planner_plan(string $message, array $state = []): array {
        $text = trim($message);
        $lower = mb_strtolower($text, 'UTF-8');
        $range = jtgpt_planner_detect_date_range($lower);
        $partName = jtgpt_planner_extract_part_name($text);
        $customer = jtgpt_planner_extract_customer($text);
        $pointNo = jtgpt_planner_extract_point_no($text);
        $tool = jtgpt_planner_extract_tool($text);
        $cavity = jtgpt_planner_extract_cavity($text);
        $limit = jtgpt_planner_extract_limit($text);

        if ($text === '') {
            return [
                'kind' => 'clarify',
                'answer' => '질문이 비어 있어요. 출하, OQC/OMM/AOI/CMM NG, 그래프빌더 중에서 먼저 말해 주세요.',
            ];
        }

        if (jtgpt_planner_contains_any($lower, ['관리자','권한','비밀번호','삭제','수정','업로드','해킹'])) {
            return ['kind' => 'answer', 'tool' => 'guard_read_only', 'args' => []];
        }

        $wantsGraph = jtgpt_planner_contains_any($lower, ['그래프빌더','graph builder','차트','그래프','히스토그램','상자그림','선그래프','막대그래프','jmp']);
        if ($wantsGraph) {
            $spec = jtgpt_planner_build_graph_spec($text);
            $actionType = jtgpt_planner_contains_any($lower, ['공정 능력','공정능력']) ? 'open_ipqc_process_capability' : 'open_ipqc_quick_graph';
            return ['kind' => 'action', 'tool' => $actionType, 'args' => ['graph_spec' => $spec], 'autorun' => true];
        }

        $shippingNeedles = ['출하','출고','ship','shipping','lot','포장','납품','수량','qty','ea','tray'];
        if (jtgpt_planner_contains_any($lower, $shippingNeedles)) {
            $metric = 'summary';
            if (jtgpt_planner_contains_any($lower, ['최근 출하일','제일 최근 출하일','최근출하일','마지막 출하일','마지막으로 출하','최신 출하일'])) {
                return ['kind'=>'tool','tool'=>'shipping_last_ship_date','args'=>['from'=>$range['from'],'to'=>$range['to'],'range'=>$range,'part_name'=>$partName,'customer'=>$customer]];
            }
            if (jtgpt_planner_contains_any($lower, ['lot'])) $metric = 'lot_count';
            elseif (jtgpt_planner_contains_any($lower, ['tray'])) $metric = 'tray_count';
            elseif (jtgpt_planner_contains_any($lower, ['수량','qty','ea'])) $metric = 'qty';
            return ['kind'=>'tool','tool'=>'shipping_summary','args'=>['from'=>$range['from'],'to'=>$range['to'],'range'=>$range,'part_name'=>$partName,'customer'=>$customer,'metric'=>$metric]];
        }

        $mentionsQuality = jtgpt_planner_contains_any($lower, ['ng','불량','포인트','point','oqc','omm','cmm','aoi']);
        if ($mentionsQuality) {
            if ($range['implicit']) {
                $range = jtgpt_planner_default_recent_range(7);
            }

            $explicitModule = null;
            foreach (['oqc','omm','cmm','aoi'] as $module) {
                if (mb_strpos($lower, $module) !== false) {
                    $explicitModule = $module;
                    break;
                }
            }

            if ($explicitModule === null) {
                $lastModule = strtolower((string)($state['last_module'] ?? ''));
                $explicitModule = in_array($lastModule, ['oqc','omm','cmm','aoi'], true) ? $lastModule : 'oqc';
            }

            if (!$pointNo && jtgpt_planner_contains_any($lower, ['1위','그 포인트','상세'])) {
                $ranked = $state['last_ranked_points'] ?? [];
                if (!empty($ranked[0])) {
                    $pointNo = (string)$ranked[0];
                }
            }

            $baseArgs = [
                'module' => $explicitModule,
                'from' => $range['from'],
                'to' => $range['to'],
                'range' => $range,
                'part_name' => $partName,
                'point_no' => $pointNo,
                'tool' => $tool,
                'cavity' => $cavity,
                'limit' => $limit,
            ];

            if ($pointNo) {
                return ['kind' => 'tool', 'tool' => 'quality_point_detail', 'args' => $baseArgs];
            }
            if (jtgpt_planner_contains_any($lower, ['많은 포인트','ng 포인트','불량 포인트','top','상위','가장 많은'])) {
                return ['kind' => 'tool', 'tool' => 'quality_top_ng_points', 'args' => $baseArgs];
            }
            return ['kind' => 'tool', 'tool' => 'quality_recent_ng_rows', 'args' => $baseArgs];
        }

        if (jtgpt_planner_contains_any($lower, ['ipqc','jmp','공정능력'])) {
            return ['kind' => 'action', 'tool' => 'open_ipqc_route', 'args' => [], 'autorun' => true];
        }

        return [
            'kind' => 'clarify',
            'answer' => '출하, OQC/OMM/CMM/AOI NG, 그래프빌더 중에서 어느 쪽인지 조금만 더 말해 주세요.',
        ];
    }
}
