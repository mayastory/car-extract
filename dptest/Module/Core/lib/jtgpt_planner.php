<?php
require_once __DIR__ . '/jtgpt_session.php';

if (!function_exists('jtgpt_planner_contains_any')) {
    function jtgpt_planner_contains_any(string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('jtgpt_planner_try_parse_ymd')) {
    function jtgpt_planner_try_parse_ymd(string $y, string $m, string $d): ?string {
        $m = str_pad((string)((int)$m), 2, '0', STR_PAD_LEFT);
        $d = str_pad((string)((int)$d), 2, '0', STR_PAD_LEFT);
        $ymd = trim($y) . '-' . $m . '-' . $d;
        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        return ($dt && $dt->format('Y-m-d') === $ymd) ? $ymd : null;
    }
}

if (!function_exists('jtgpt_planner_detect_time_hint')) {
    function jtgpt_planner_detect_time_hint(string $text): ?string {
        $map = [
            '오늘'    => ['오늘','금일','today','now'],
            '어제'    => ['어제','yesterday'],
            '이번 주' => ['이번주','이번 주','금주','this week'],
            '최근 7일' => ['최근 7일','7일','일주일','1주일','최근 일주일'],
            '최근 30일' => ['최근 30일','30일','한달','1달','최근 한달'],
            '이번 달' => ['이번달','이번 달','금월','this month'],
        ];
        foreach ($map as $label => $needles) {
            if (jtgpt_planner_contains_any($text, $needles)) return $label;
        }
        return null;
    }
}

if (!function_exists('jtgpt_planner_detect_date_range')) {
    function jtgpt_planner_detect_date_range(string $text): array {
        $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
        $today = $now->format('Y-m-d');
        if (preg_match_all('/(20\d{2})[\.\/-]?(\d{1,2})[\.\/-]?(\d{1,2})/', $text, $m, PREG_SET_ORDER)) {
            $dates = [];
            foreach ($m as $hit) {
                $ymd = jtgpt_planner_try_parse_ymd($hit[1], $hit[2], $hit[3]);
                if ($ymd !== null) $dates[] = $ymd;
            }
            $dates = array_values(array_unique($dates));
            sort($dates);
            if (count($dates) >= 2) return ['from'=>$dates[0],'to'=>$dates[count($dates)-1],'label'=>$dates[0].' ~ '.$dates[count($dates)-1],'implicit'=>false];
            if (count($dates) === 1) return ['from'=>$dates[0],'to'=>$dates[0],'label'=>$dates[0],'implicit'=>false];
        }
        $hint = jtgpt_planner_detect_time_hint($text);
        if ($hint === '오늘') return ['from'=>$today,'to'=>$today,'label'=>'오늘','implicit'=>false];
        if ($hint === '어제') { $d = (clone $now)->modify('-1 day')->format('Y-m-d'); return ['from'=>$d,'to'=>$d,'label'=>'어제','implicit'=>false]; }
        if ($hint === '이번 주') { $s=(clone $now)->modify('monday this week')->format('Y-m-d'); return ['from'=>$s,'to'=>$today,'label'=>'이번 주','implicit'=>false]; }
        if ($hint === '최근 7일') { $s=(clone $now)->modify('-6 day')->format('Y-m-d'); return ['from'=>$s,'to'=>$today,'label'=>'최근 7일','implicit'=>false]; }
        if ($hint === '최근 30일') { $s=(clone $now)->modify('-29 day')->format('Y-m-d'); return ['from'=>$s,'to'=>$today,'label'=>'최근 30일','implicit'=>false]; }
        if ($hint === '이번 달') { $s=(clone $now)->modify('first day of this month')->format('Y-m-d'); return ['from'=>$s,'to'=>$today,'label'=>'이번 달','implicit'=>false]; }
        return ['from'=>$today,'to'=>$today,'label'=>'오늘','implicit'=>true];
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
            if (mb_strpos($lower, $needle) !== false) return $partName;
        }
        if (preg_match('/(MEM-[A-Z0-9\.\-]+)/i', $original, $m)) return strtoupper(trim($m[1]));
        if (preg_match('/([A-Z0-9]+(?:-[A-Z0-9\.]+){2,})/', strtoupper($original), $m)) return trim($m[1]);
        return null;
    }
}

if (!function_exists('jtgpt_planner_extract_customer')) {
    function jtgpt_planner_extract_customer(string $message): ?string {
        $lower = mb_strtolower($message, 'UTF-8');
        if (jtgpt_planner_contains_any($lower, ['자화전자','자화','jh'])) return '자화전자';
        if (jtgpt_planner_contains_any($lower, ['엘지이노텍','이노텍','lg','엘지'])) return '엘지이노텍';
        return null;
    }
}

if (!function_exists('jtgpt_planner_extract_point_no')) {
    function jtgpt_planner_extract_point_no(string $message): ?string {
        if (preg_match('/(?:point|포인트)\s*([0-9]{1,3}(?:-[0-9]{1,3})?)/iu', $message, $m)) {
            return $m[1];
        }
        if (preg_match('/([0-9]{1,3}-[0-9]{1,3})/', $message, $m)) {
            return $m[1];
        }
        return null;
    }
}

if (!function_exists('jtgpt_planner_build_graph_spec')) {
    function jtgpt_planner_build_graph_spec(string $message): array {
        $lower = mb_strtolower($message, 'UTF-8');
        $chart = 'line';
        if (jtgpt_planner_contains_any($lower, ['막대','bar'])) $chart = 'bar';
        elseif (jtgpt_planner_contains_any($lower, ['산점','scatter','점그래프'])) $chart = 'scatter';
        elseif (jtgpt_planner_contains_any($lower, ['박스','boxplot','box plot','상자'])) $chart = 'box';
        $source = 'ipqc';
        foreach (['oqc','omm','cmm','aoi','ipqc'] as $src) {
            if (mb_strpos($lower, $src) !== false) { $source = $src; break; }
        }
        $color = null;
        if (jtgpt_planner_contains_any($lower, ['cavity별 색상','색상 cavity','색상은 cavity','cavity 색상'])) $color = 'cavity';
        elseif (jtgpt_planner_contains_any($lower, ['tool별 색상','색상 tool','tool 색상'])) $color = 'tool';
        $group = null;
        if (jtgpt_planner_contains_any($lower, ['그룹 y','group y'])) $group = 'group_y';
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

        if ($text === '') {
            return [
                'kind' => 'clarify',
                'answer' => '질문이 비어 있어요. 출하, OQC NG, 그래프빌더 중에서 먼저 말해 주세요.',
                'suggestions' => ['자화전자 제일 최근 출하일은?', '최근 7일 OQC NG 많은 포인트', '그래프빌더 열어줘'],
            ];
        }

        if (jtgpt_planner_contains_any($lower, ['관리자','권한','비밀번호','삭제','수정','업로드','해킹'])) {
            return [
                'kind' => 'answer',
                'tool' => 'guard_read_only',
                'args' => [],
            ];
        }

        $wantsGraph = jtgpt_planner_contains_any($lower, ['그래프빌더','graph builder','차트','그래프','히스토그램','상자그림','선그래프','막대그래프','jmp']);
        $wantsOpen = jtgpt_planner_contains_any($lower, ['켜','열','띄','보여줘','실행']);
        if ($wantsGraph) {
            $spec = jtgpt_planner_build_graph_spec($text);
            $actionType = jtgpt_planner_contains_any($lower, ['공정 능력','공정능력']) ? 'open_ipqc_process_capability' : 'open_ipqc_quick_graph';
            return [
                'kind' => 'action',
                'tool' => $actionType,
                'args' => ['graph_spec' => $spec],
                'autorun' => true,
            ];
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
            $explicitModule = null;
            foreach (['oqc','omm','cmm','aoi'] as $module) {
                if (mb_strpos($lower, $module) !== false) { $explicitModule = $module; break; }
            }
            if ($explicitModule === null && (jtgpt_planner_contains_any($lower, ['ng 많은 포인트','ng 포인트','불량 포인트']) || $pointNo || jtgpt_planner_contains_any($lower, ['1위','그 포인트','상세']))) {
                $lastModule = (string)($state['last_module'] ?? '');
                if ($lastModule === 'oqc') {
                    $explicitModule = 'oqc';
                } else {
                    return [
                        'kind' => 'clarify',
                        'answer' => 'NG 포인트는 OQC / OMM / CMM / AOI 중 어디를 볼까요?',
                        'suggestions' => ['최근 7일 OQC NG 많은 포인트', '최근 7일 OMM NG 많은 포인트', '최근 7일 CMM NG 많은 포인트'],
                    ];
                }
            }
            if ($explicitModule === 'oqc') {
                if (!$pointNo && jtgpt_planner_contains_any($lower, ['1위','그 포인트','상세'])) {
                    $ranked = $state['last_ranked_points'] ?? [];
                    if (!empty($ranked[0])) $pointNo = (string)$ranked[0];
                }
                if ($pointNo) {
                    return ['kind'=>'tool','tool'=>'oqc_point_detail','args'=>['from'=>$range['from'],'to'=>$range['to'],'range'=>$range,'part_name'=>$partName,'point_no'=>$pointNo]];
                }
                return ['kind'=>'tool','tool'=>'oqc_top_ng_points','args'=>['from'=>$range['from'],'to'=>$range['to'],'range'=>$range,'part_name'=>$partName]];
            }
            if ($explicitModule !== null) {
                return [
                    'kind' => 'clarify',
                    'answer' => strtoupper($explicitModule) . ' 쪽은 1차에서는 창 열기보다 OQC/출하부터 먼저 붙였어요. 지금은 OQC/출하 쪽 질문이 가장 정확해요.',
                    'suggestions' => ['최근 7일 OQC NG 많은 포인트', '자화전자 제일 최근 출하일은?', '그래프빌더 열어줘'],
                ];
            }
        }

        if (jtgpt_planner_contains_any($lower, ['ipqc','jmp','공정능력'])) {
            return [
                'kind' => 'action',
                'tool' => 'open_ipqc_route',
                'args' => [],
                'autorun' => true,
            ];
        }

        return [
            'kind' => 'clarify',
            'answer' => '출하, OQC NG, 그래프빌더 중에서 어느 쪽인지 조금만 더 말해 주세요.',
            'suggestions' => ['자화전자 제일 최근 출하일은?', '최근 7일 OQC NG 많은 포인트', '그래프빌더 열어줘'],
        ];
    }
}
