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
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
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

if (!function_exists('jtgpt_planner_unique_values')) {
    function jtgpt_planner_unique_values(array $values): array {
        $seen = [];
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $key = strtoupper($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $value;
        }
        return $out;
    }
}

if (!function_exists('jtgpt_planner_compact_text')) {
    function jtgpt_planner_compact_text(string $text): string {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text, 'UTF-8'));
    }
}

if (!function_exists('jtgpt_planner_part_alias_groups')) {
    function jtgpt_planner_part_alias_groups(): array {
        return [
            'MEM-IR-BASE' => [
                'ir base', 'irbase', 'IR BASE', '아이알베이스', '아이알배이스', 'ir',
            ],
            'MEM-X-CARRIER' => [
                'x carrier', 'x-carrier', 'xcarrier', 'X CARRIER', '엑스케리어', '엑스캐리어', 'xc',
            ],
            'MEM-Y-CARRIER' => [
                'y carrier', 'y-carrier', 'ycarrier', 'Y CARRIER', '와이케리어', '와이캐리어', 'yc',
            ],
            'MEM-Z-CARRIER' => [
                'z carrier', 'z-carrier', 'zcarrier', 'Z CARRIER', '지케리어', '지캐리어',
                '제트케리어', '제트캐리어', '재트케리어', '재트캐리어', 'zc',
            ],
            'MEM-Z-STOPPER' => [
                'z stopper', 'z-stopper', 'zstopper', 'Z STOPPER', '지스토퍼', '제트스토퍼', '재트스토퍼', 'zs',
            ],
        ];
    }
}


/*
 * JTGPT 자연어 라우팅 핵심 규칙
 * ------------------------------------------------------------------
 * 1) LG/엘지/자화/jawha 는 도메인 키워드가 아니라 공용 슬롯값이다.
 *    - 출하 도메인에서는 납품처/고객사 의미
 *    - OQC 플래그/성적서 가능 데이터 도메인에서는 meas_date/jmeas_date 의미
 *    - 따라서 LG/자화 단어 하나만으로 출하/플래그 도메인을 결정하면 안 된다.
 *
 * 2) OQC/OMM/AOI/CMM 은 "영역" 단어이지 NG intent 자체가 아니다.
 *    - OQC 라는 단어만 보고 recent_ng 로 보내면 안 된다.
 *    - 플래그/성적서 가능/남은 데이터가 같이 있으면 cert_remaining 이 우선이다.
 *
 * 3) 몇건/몇개/얼마나/건수/수량/개수 는 모든 도메인에서 공통으로 쓰는 output=count 표현이다.
 *    - count 표현만으로 출하/플래그/NG 도메인을 결정하면 안 된다.
 *
 * 4) 도메인 잠금 순서는 shipping -> cert_remaining -> ng 이다.
 *    - 출하 단어가 있으면 출하로 잠금
 *    - 플래그/성적서 가능/남은 데이터 단어가 있으면 cert_remaining 으로 잠금
 *    - NG/USL/LSL/상한/하한/측정값/FAI 가 있을 때만 NG 도메인을 허용
 *
 * 5) fallback 안전장치
 *    - 플래그 문장은 NG fallback 금지
 *    - 출하 문장은 플래그/NG fallback 금지
 *    - 애매하다고 OQC -> recent_ng 로 바로 밀어 넣지 않는다.
 */
if (!function_exists('jtgpt_planner_has_common_count_request')) {
    function jtgpt_planner_has_common_count_request(string $message): bool {
        $lower = mb_strtolower($message, 'UTF-8');
        $compact = jtgpt_planner_compact_text($message);
        if (jtgpt_planner_contains_any($lower, ['몇건', '몇 건', '몇개', '몇 개', '얼마나', '개수', '건수', '수량', '몇이나'])) {
            return true;
        }
        return preg_match('/(?:몇건|몇개|얼마나|개수|건수|수량)/u', $compact) === 1;
    }
}

if (!function_exists('jtgpt_planner_has_shipping_domain_signal')) {
    function jtgpt_planner_has_shipping_domain_signal(string $message): bool {
        $lower = mb_strtolower($message, 'UTF-8');
        return jtgpt_planner_contains_any($lower, [
            '출하내역', '출하', '출고', 'ship', 'shipping', 'lot', '포장', '납품처', '납품', '출하일자', '포장일자', 'tray'
        ]);
    }
}

if (!function_exists('jtgpt_planner_has_cert_status_signal')) {
    function jtgpt_planner_has_cert_status_signal(string $message): bool {
        $lower = mb_strtolower($message, 'UTF-8');
        $compact = jtgpt_planner_compact_text($message);
        if (jtgpt_planner_contains_any($lower, [
            '충분하', '충분해', '충분함', '부족하', '부족해', '모자라', '모자람',
            '쓸만하', '쓸 만하', '쓸 거 있', '쓸거있', '될 만큼 있', '될만큼있',
            '성적서 쓸 거', '성적서쓸거', '발행할 만큼 있', '발행할만큼있'
        ])) {
            return true;
        }
        return preg_match('/(?:충분하|부족하|모자라|쓸만하|쓸거있|될만큼있)/u', $compact) === 1;
    }
}

if (!function_exists('jtgpt_planner_has_cert_existence_signal')) {
    function jtgpt_planner_has_cert_existence_signal(string $message): bool {
        $lower = mb_strtolower($message, 'UTF-8');
        $compact = jtgpt_planner_compact_text($message);
        if (jtgpt_planner_contains_any($lower, [
            '있냐', '있나', '있니', '있어', '있음', '남겨있', '남겨 있어', '남겨있나', '남겨있어',
            '남아있', '남아 있어', '남아있나', '남아있어', '있을까', '있을지'
        ])) {
            return true;
        }
        return preg_match('/(?:있냐|있나|있니|있어|있음|남겨있|남아있|있을까|있을지)/u', $compact) === 1;
    }
}

if (!function_exists('jtgpt_planner_has_cert_domain_signal')) {
    function jtgpt_planner_has_cert_domain_signal(string $message): bool {
        $lower = mb_strtolower($message, 'UTF-8');
        $compact = jtgpt_planner_compact_text($message);

        $hasFlagWord = jtgpt_planner_contains_any($lower, ['flag', '플래그', '깃발']);
        $hasDateEntity = jtgpt_planner_contains_any($lower, ['자화', 'jawha', '엘지', 'lg', 'meas_date', 'jmeas_date', 'jmeas', 'meas']);
        $hasArea = jtgpt_planner_contains_any($lower, ['oqc']);
        $hasStatusWord = jtgpt_planner_has_cert_status_signal($message);
        $hasExistenceWord = jtgpt_planner_has_cert_existence_signal($message);
        $hasCertAction = jtgpt_planner_contains_any($lower, [
            '성적서', '발행 가능', '발행가능', '쓸 수 있는', '쓸수있는', '사용 가능', '사용가능', 'usable',
            '남은 데이터', '남은데이터', '남은 거', '남은거', '잔여', '현황', '남아있는', '남아 있는', '남아있', '남아 있',
            '남겨있는', '남겨 있는', '남겨있', '남겨 있', '남았', '남은', '남았니', '남았어'
        ]) || preg_match('/(?:남은데이터|남은거|남았|남은|남아있|남아있는|남겨있|남겨있는|잔여)/u', $compact) === 1;
        $hasDataWord = jtgpt_planner_contains_any($lower, ['데이터', '현황', '목록', '리스트']);
        $hasCountWord = jtgpt_planner_has_common_count_request($message);

        if (jtgpt_planner_has_shipping_domain_signal($message)) {
            return false;
        }

        if ($hasFlagWord && ($hasDateEntity || $hasArea || $hasCertAction || $hasStatusWord || $hasExistenceWord || $hasDataWord || $hasCountWord)) {
            return true;
        }
        if (($hasDateEntity || $hasArea) && ($hasCertAction || $hasStatusWord || $hasExistenceWord)) {
            return true;
        }
        if (($hasDateEntity || $hasArea) && $hasDataWord && ($hasCountWord || $hasStatusWord || $hasExistenceWord)) {
            return true;
        }
        // "oqc 데이터 있냐 / 남겨있나" 는 플래그/성적서 가능 데이터 현황 질의로 본다.
        if ($hasArea && $hasDataWord && ($hasExistenceWord || preg_match('/(?:데이터.*있|데이터.*남겨|데이터.*남아)/u', $compact) === 1)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('jtgpt_planner_has_ng_domain_signal')) {
    function jtgpt_planner_has_ng_domain_signal(string $message, array $pointTerms = [], ?array $valueFilter = null): bool {
        $lower = mb_strtolower($message, 'UTF-8');
        if (jtgpt_planner_has_shipping_domain_signal($message) || jtgpt_planner_has_cert_domain_signal($message)) {
            return false;
        }

        if (is_array($valueFilter) && !empty($valueFilter['enabled'])) {
            return true;
        }

        $hasNgWords = jtgpt_planner_contains_any($lower, [
            'ng', '불량', '포인트', 'point', 'fai', '측정값', 'value', 'usl', 'lsl', '상한', '하한', '초과', '미만'
        ]);
        if ($hasNgWords) {
            return true;
        }

        $hasArea = jtgpt_planner_contains_any($lower, ['oqc', 'omm', 'cmm', 'aoi']);
        if ($hasArea && (!empty($pointTerms) || preg_match('/\d+\s*(?:캐비티|cav|cavity)/u', $lower) || preg_match('/[a-z]\s*(?:차|차수|tool)/iu', $lower))) {
            return true;
        }
        return false;
    }
}

if (!function_exists('jtgpt_planner_detect_primary_domain')) {
    function jtgpt_planner_detect_primary_domain(string $message, array $pointTerms = [], ?array $valueFilter = null): string {
        if (jtgpt_planner_has_shipping_domain_signal($message)) {
            return 'shipping';
        }
        if (jtgpt_planner_has_cert_domain_signal($message)) {
            return 'cert_remaining';
        }
        if (jtgpt_planner_has_ng_domain_signal($message, $pointTerms, $valueFilter)) {
            return 'ng';
        }
        return 'unknown';
    }
}

if (!function_exists('jtgpt_planner_strip_part_aliases')) {
    function jtgpt_planner_strip_part_aliases(string $message): string {
        $text = $message;
        foreach (jtgpt_planner_part_alias_groups() as $partName => $aliases) {
            foreach ($aliases as $alias) {
                $aliasLower = mb_strtolower((string)$alias, 'UTF-8');
                if ($aliasLower === '') {
                    continue;
                }
                if (preg_match('/^[a-z0-9]{1,3}$/', $aliasLower)) {
                    $text = preg_replace('/(?:^|[^a-z0-9])' . preg_quote($aliasLower, '/') . '(?:$|[^a-z0-9])/iu', ' ', $text);
                } else {
                    $pattern = preg_quote($alias, '/');
                    $pattern = str_replace(['\ ', '\-', '\_','\/'], '[\s\-_\/]*', $pattern);
                    $text = preg_replace('/' . $pattern . '/iu', ' ', $text);
                }
            }
        }
        return $text;
    }
}

if (!function_exists('jtgpt_planner_extract_part_name')) {
    function jtgpt_planner_extract_part_name(string $message): ?string {
        $original = trim($message);
        if ($original === '') {
            return null;
        }

        $lower = mb_strtolower($original, 'UTF-8');
        $compact = jtgpt_planner_compact_text($original);
        foreach (jtgpt_planner_part_alias_groups() as $partName => $aliases) {
            foreach ($aliases as $alias) {
                $aliasLower = mb_strtolower((string)$alias, 'UTF-8');
                if ($aliasLower === '') {
                    continue;
                }
                if (preg_match('/^[a-z0-9]{1,3}$/', $aliasLower)) {
                    if (preg_match('/(?:^|[^a-z0-9])' . preg_quote($aliasLower, '/') . '(?:$|[^a-z0-9])/u', $lower)) {
                        return $partName;
                    }
                    continue;
                }
                if (mb_strpos($compact, jtgpt_planner_compact_text($aliasLower)) !== false) {
                    return $partName;
                }
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

if (!function_exists('jtgpt_planner_extract_quality_date_field')) {
    function jtgpt_planner_extract_quality_date_field(string $message): string {
        $lower = mb_strtolower($message, 'UTF-8');
        if (jtgpt_planner_contains_any($lower, ['자화 플래그', '자화깃발', 'j flag', 'jflag', 'j 플래그', 'j플래그', 'j 깃발', 'j깃발', 'jawha flag', 'jawha'])) {
            return 'jmeas_date';
        }
        if (jtgpt_planner_contains_any($lower, ['엘지 플래그', '엘지깃발', 'lg flag', 'lgflag', 'lg 플래그', 'lg플래그', 'lg 깃발', 'lg깃발', 'm flag', 'mflag', 'meas flag', 'meas_date', '엘지', 'lg'])) {
            return 'meas_date';
        }
        if (jtgpt_planner_contains_any($lower, ['jmeas_date', 'jmeas'])) {
            return 'jmeas_date';
        }
        if (jtgpt_planner_contains_any($lower, ['flag', '플래그', '깃발'])) {
            return 'both';
        }
        return 'both';
    }
}

if (!function_exists('jtgpt_planner_extract_cert_group_by')) {
    function jtgpt_planner_extract_cert_group_by(string $message): string {
        $lower = mb_strtolower($message, 'UTF-8');
        if (jtgpt_planner_contains_any($lower, ['툴캐비티별', '툴 캐비티별', 'tool cavity', 'tool-cavity', 'tool#cavity', '툴#캐비티별'])) {
            return 'tool_cavity';
        }
        if (jtgpt_planner_contains_any($lower, ['캐비티별', 'cavity별', 'cav별'])) {
            return 'cavity';
        }
        if (jtgpt_planner_contains_any($lower, ['툴별', '차수별', 'tool별'])) {
            return 'tool';
        }
        if (jtgpt_planner_contains_any($lower, ['모델별'])) {
            return 'model';
        }
        return 'model_tool_cavity';
    }
}

if (!function_exists('jtgpt_planner_is_cert_remaining_query')) {
    function jtgpt_planner_is_cert_remaining_query(string $message): bool {
        $lower = mb_strtolower($message, 'UTF-8');

        if (jtgpt_planner_contains_any($lower, ['ng만', 'ng 만', '불량만', '불량 만'])) {
            return false;
        }

        return jtgpt_planner_has_cert_domain_signal($message);
    }
}

if (!function_exists('jtgpt_planner_detect_time_hint')) {
    function jtgpt_planner_detect_time_hint(string $text): ?string {
        if (preg_match('/최근\s*(\d{1,3})\s*일/u', $text, $m)) {
            return '최근 ' . max(1, min(365, (int)$m[1])) . '일';
        }
        if (preg_match('/최근\s*(\d{1,2})\s*(?:개월|달)\b/u', $text, $m)) {
            $months = max(1, min(24, (int)$m[1]));
            return '최근 ' . $months . '개월';
        }

        $map = [
            '전체' => ['전체', 'all', '전기간'],
            '오늘' => ['오늘', '금일', 'today', 'now'],
            '어제' => ['어제', 'yesterday'],
            '이번 주' => ['이번주', '이번 주', '금주', 'this week'],
            '최근 7일' => ['최근 7일', '일주일', '1주일', '최근 일주일', '최근', 'latest', 'recent'],
            '최근 30일' => ['최근 30일', '30일', '한달', '1달', '1개월', '최근 한달', '최근 1달', '최근 1개월', '지난달', '지난 달', '저번달', '저번 달', 'last month'],
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

if (!function_exists('jtgpt_planner_detect_month_range')) {
    function jtgpt_planner_detect_month_range(string $text, DateTime $now): ?array {
        if (preg_match('/\b(20\d{2})\s*[년\.\/-]?\s*(\d{1,2})\s*월?\b/u', $text, $m)) {
            $year = (int)$m[1];
            $month = (int)$m[2];
            if ($month >= 1 && $month <= 12) {
                $start = DateTime::createFromFormat('Y-n-j H:i:s', sprintf('%04d-%d-1 00:00:00', $year, $month), new DateTimeZone('Asia/Seoul'));
                if ($start instanceof DateTime) {
                    $end = (clone $start)->modify('last day of this month');
                    return [
                        'from' => $start->format('Y-m-d'),
                        'to' => $end->format('Y-m-d'),
                        'label' => sprintf('%04d-%02d', $year, $month),
                        'implicit' => false,
                    ];
                }
            }
        }

        if (preg_match('/(^|[^\d])((?:1[0-2])|(?:0?[1-9]))\s*월(?:달)?(?!\d)/u', $text, $m)) {
            $month = (int)$m[2];
            $year = (int)$now->format('Y');
            $currentMonth = (int)$now->format('n');
            if ($month > $currentMonth) {
                $year -= 1;
            }
            $start = DateTime::createFromFormat('Y-n-j H:i:s', sprintf('%04d-%d-1 00:00:00', $year, $month), new DateTimeZone('Asia/Seoul'));
            if ($start instanceof DateTime) {
                $end = (clone $start)->modify('last day of this month');
                return [
                    'from' => $start->format('Y-m-d'),
                    'to' => $end->format('Y-m-d'),
                    'label' => sprintf('%04d-%02d', $year, $month),
                    'implicit' => false,
                ];
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

        $monthRange = jtgpt_planner_detect_month_range($text, $now);
        if ($monthRange !== null) {
            return $monthRange;
        }

        $hint = jtgpt_planner_detect_time_hint($text);
        if ($hint === '전체') {
            return ['from' => null, 'to' => null, 'label' => '전체', 'implicit' => false];
        }
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
        if (preg_match('/^최근\s*(\d{1,3})일$/u', (string)$hint, $m)) {
            $days = max(1, min(365, (int)$m[1]));
            $s = (clone $now)->modify('-' . ($days - 1) . ' day')->format('Y-m-d');
            return ['from' => $s, 'to' => $today, 'label' => '최근 ' . $days . '일', 'implicit' => false];
        }
        if (preg_match('/^최근\s*(\d{1,2})개월$/u', (string)$hint, $m)) {
            $months = max(1, min(24, (int)$m[1]));
            $s = (clone $now)->modify('-' . $months . ' month')->modify('+1 day')->format('Y-m-d');
            return ['from' => $s, 'to' => $today, 'label' => '최근 ' . $months . '개월', 'implicit' => false];
        }
        return ['from' => $today, 'to' => $today, 'label' => '오늘', 'implicit' => true];
    }
}

if (!function_exists('jtgpt_planner_quality_all_modules')) {
    function jtgpt_planner_quality_all_modules(): array {
        return ['oqc', 'omm', 'aoi', 'cmm'];
    }
}

if (!function_exists('jtgpt_planner_extract_quality_modules')) {
    function jtgpt_planner_extract_quality_modules(string $message, array $state = []): array {
        $lower = mb_strtolower($message, 'UTF-8');
        $modules = [];
        foreach (jtgpt_planner_quality_all_modules() as $module) {
            if (mb_strpos($lower, $module) !== false) {
                $modules[] = $module;
            }
        }

        if ($modules) {
            return jtgpt_planner_unique_values($modules);
        }

        $followUpNeedles = ['그 포인트', '해당 포인트', '그거', '그건', '상세', 'detail', '1위', '방금', '아까'];
        $lastModule = strtolower((string)($state['last_module'] ?? ''));
        if ($lastModule !== '' && in_array($lastModule, jtgpt_planner_quality_all_modules(), true) && jtgpt_planner_contains_any($lower, $followUpNeedles)) {
            return [$lastModule];
        }

        return jtgpt_planner_quality_all_modules();
    }
}

if (!function_exists('jtgpt_planner_extract_quality_module')) {
    function jtgpt_planner_extract_quality_module(string $message, array $state = []): string {
        $modules = jtgpt_planner_extract_quality_modules($message, $state);
        return $modules[0] ?? 'oqc';
    }
}

if (!function_exists('jtgpt_planner_extract_quality_intent')) {
    function jtgpt_planner_extract_quality_intent(string $message, array $pointTerms = []): string {
        $lower = mb_strtolower($message, 'UTF-8');
        if (jtgpt_planner_contains_any($lower, ['비교', 'compare'])) {
            return 'compare';
        }
        // 몇건/몇개/얼마나/건수/수량은 NG 전용 표현이 아니라 모든 도메인 공통 count 요청이다.
        // quality 도메인 안으로 들어온 뒤에만 count intent 로 해석한다.
        if (jtgpt_planner_has_common_count_request($message) || jtgpt_planner_contains_any($lower, ['count'])) {
            return 'count';
        }
        if (jtgpt_planner_contains_any($lower, ['많은 포인트', 'ng 포인트', '불량 포인트', 'top', '상위', '가장 많은'])) {
            return 'top_ng_points';
        }
        if (!empty($pointTerms) && jtgpt_planner_contains_any($lower, ['상세', 'detail', '그 포인트', '해당 포인트'])) {
            return 'point_detail';
        }
        if (jtgpt_planner_contains_any($lower, ['요약', 'summary', '정리'])) {
            return 'summary';
        }
        return 'recent_ng';
    }
}

if (!function_exists('jtgpt_planner_extract_quality_output_mode')) {
    function jtgpt_planner_extract_quality_output_mode(string $message, array $pointTerms = []): string {
        $intent = jtgpt_planner_extract_quality_intent($message, $pointTerms);
        if ($intent === 'count') return 'count';
        if ($intent === 'summary' || $intent === 'compare') return 'summary';
        if ($intent === 'top_ng_points') return 'top';
        if ($intent === 'point_detail') return 'detail';
        return 'rows';
    }
}

if (!function_exists('jtgpt_planner_has_general_export_signal')) {
    function jtgpt_planner_has_general_export_signal(string $message): bool {
        $lower = mb_strtolower(trim($message), 'UTF-8');
        $compact = jtgpt_planner_compact_text($message);
        if ($lower === '') {
            return false;
        }
        // follow-up export 는 "엑셀"을 직접 말하지 않아도 파일/다운로드/내보내기 표현이면 기본 XLSX 로 본다.
        if (jtgpt_planner_contains_any($lower, ['엑셀', 'excel', 'xlsx', 'csv', '표로', '테이블', 'table', '출력', '다운로드', '내려', '저장', '파일', '내보내', '추출', '뽑아'])) {
            return true;
        }
        return preg_match('/(?:파일줘|파일로줘|파일로주|다운로드해|내보내줘|추출해줘|뽑아줘)/u', $compact) === 1;
    }
}

if (!function_exists('jtgpt_planner_extract_quality_output_format')) {
    function jtgpt_planner_extract_quality_output_format(string $message): string {
        $lower = mb_strtolower($message, 'UTF-8');
        if (jtgpt_planner_contains_any($lower, ['csv', '.csv', '콤마분리', '쉼표파일'])) {
            return 'csv';
        }
        if (jtgpt_planner_contains_any($lower, ['표로', '테이블', 'table'])) {
            return 'table';
        }
        if (jtgpt_planner_contains_any($lower, ['엑셀', 'excel', 'xlsx', '.xlsx', 'xls', '.xls'])) {
            return 'excel';
        }
        if (jtgpt_planner_has_general_export_signal($message)) {
            return 'excel';
        }
        // "상세내역 줘" 같이 형식을 안 말해도 후속 export 관용 표현이면 기본 XLSX 로 본다.
        if (preg_match('/(?:상세내역|상세|내역)(?:줘|주라|줄래|보여줘|보여줄래)?$/u', jtgpt_planner_compact_text($message)) === 1) {
            return 'excel';
        }
        return 'chat';
    }
}

if (!function_exists('jtgpt_planner_is_detail_export_message')) {
    function jtgpt_planner_is_detail_export_message(string $message): bool {
        $lower = mb_strtolower(trim($message), 'UTF-8');
        $compact = jtgpt_planner_compact_text($message);
        if ($lower === '') {
            return false;
        }
        $hasDetail = jtgpt_planner_contains_any($lower, ['상세내역', '상세 내역', '상세', 'detail', '내역']);
        if (!$hasDetail) {
            return false;
        }
        if (jtgpt_planner_has_general_export_signal($message)) {
            return true;
        }
        // "상세내역 줘/보여줘" 같은 follow-up 은 파일 형식을 안 말해도 상세 export 로 본다.
        return preg_match('/(?:상세내역|상세내역으로|상세|내역)(?:줘|주라|줄래|보여줘|보여주라|보여줄래)?$/u', $compact) === 1;
    }
}


if (!function_exists('jtgpt_planner_last_quality_context')) {
    function jtgpt_planner_last_quality_context(array $state = []): ?array {
        $tool = trim((string)($state['last_quality_tool'] ?? ''));
        $args = (array)($state['last_quality_args'] ?? []);
        if ($tool !== '' && $args) {
            return ['tool' => $tool, 'args' => $args, 'source' => 'state'];
        }

        if (function_exists('jtgpt_session_history')) {
            $hist = (array)jtgpt_session_history(12);
            for ($i = count($hist) - 1; $i >= 0; $i--) {
                $entry = (array)($hist[$i] ?? []);
                $meta = (array)($entry['meta'] ?? []);
                $plan = (array)($meta['plan'] ?? []);
                $planTool = trim((string)($plan['tool'] ?? ''));
                if ($planTool === '' || strpos($planTool, 'quality_') !== 0) {
                    continue;
                }
                $planArgs = (array)($plan['args'] ?? []);
                if (!$planArgs) {
                    continue;
                }
                if (($plan['kind'] ?? '') !== 'tool') {
                    continue;
                }
                return ['tool' => $planTool, 'args' => $planArgs, 'source' => 'history'];
            }
        }

        return null;
    }
}

if (!function_exists('jtgpt_planner_is_quality_export_followup')) {
    function jtgpt_planner_is_quality_export_followup(string $message, array $state, string $output, ?string $partName, array $tools, array $cavities, array $pointTerms, array $range, ?array $valueFilter = null): bool {
        if (!in_array($output, ['excel', 'csv', 'table'], true)) {
            return false;
        }
        if (!jtgpt_planner_last_quality_context($state)) {
            return false;
        }

        $lower = mb_strtolower($message, 'UTF-8');
        $followNeedles = ['엑셀', 'excel', 'xlsx', 'csv', '표로', '테이블', 'table', '출력', '다운로드', '내려', '저장', '파일', '그거', '그 결과', '방금', '아까', '저거', '그걸', '내보내', '추출', '뽑아'];
        if (!jtgpt_planner_contains_any($lower, $followNeedles)) {
            return false;
        }

        $isDetailExport = jtgpt_planner_is_detail_export_message($message);

        $hasNewScope = false;
        if (trim((string)$partName) !== '') $hasNewScope = true;
        if (!empty($tools) || !empty($cavities) || !empty($pointTerms)) $hasNewScope = true;
        if (is_array($valueFilter) && !empty($valueFilter['enabled'])) $hasNewScope = true;
        if (empty($range['implicit'])) $hasNewScope = true;
        if (jtgpt_planner_contains_any($lower, ['oqc', 'omm', 'aoi', 'cmm', 'ng', '불량', '측정값', 'usl', 'lsl'])) $hasNewScope = true;

        if ($isDetailExport) {
            $detailOnly = preg_replace('/(?:상세내역|상세\s*내역|상세|detail|내역|엑셀|excel|xlsx|csv|표로|테이블|table|출력|다운로드|내려|저장|파일|내보내|추출|뽑아|줘|주라|줄래|해줘|해\s*줘|보여줘|보여\s*줘|좀|으로|로|만|부탁|바로|해|를|을|그리고|그리구|좀)/u', ' ', $lower);
            $detailOnly = trim((string)preg_replace('/\s+/u', ' ', $detailOnly));
            if ($detailOnly === '') {
                return true;
            }
        }

        return !$hasNewScope;
    }
}


if (!function_exists('jtgpt_planner_extract_quality_ng_only')) {
    function jtgpt_planner_extract_quality_ng_only(string $message, ?array $valueFilter = null): bool {
        $lower = mb_strtolower($message, 'UTF-8');
        if (jtgpt_planner_contains_any($lower, ['ng', '불량'])) {
            return true;
        }
        if (is_array($valueFilter) && !empty($valueFilter['enabled'])) {
            return false;
        }
        return true;
    }
}

if (!function_exists('jtgpt_planner_extract_quality_value_filter')) {
    function jtgpt_planner_extract_quality_value_filter(string $message): ?array {
        $lower = mb_strtolower($message, 'UTF-8');
        $work = ' ' . $lower . ' ';
        $work = preg_replace('/(20\d{2})[\.\/-](\d{1,2})[\.\/-](\d{1,2})/u', ' ', $work);

        $target = 'value';
        if (jtgpt_planner_contains_any($lower, ['usl', 'upper limit', '상한'])) {
            $target = 'usl';
        } elseif (jtgpt_planner_contains_any($lower, ['lsl', 'lower limit', '하한'])) {
            $target = 'lsl';
        }

        $number = '(-?\d+(?:\.\d+)?)';
        $betweenPatterns = [
            '/(?:측정값|값|value)?\s*' . $number . '\s*(?:~|〜|∼|\-)\s*' . $number . '/u',
            '/' . $number . '\s*(?:에서|부터)\s*' . $number . '\s*(?:사이|까지)/u',
            '/between\s*' . $number . '\s*(?:and|to)\s*' . $number . '/iu',
        ];
        foreach ($betweenPatterns as $pattern) {
            if (preg_match($pattern, $work, $m)) {
                $a = (float)$m[1];
                $b = (float)$m[2];
                if ($a > $b) {
                    [$a, $b] = [$b, $a];
                }
                return [
                    'enabled' => true,
                    'target' => $target,
                    'op' => 'between',
                    'value1' => $a,
                    'value2' => $b,
                ];
            }
        }

        $patterns = [
            'gte' => [
                '/(?:측정값|값|value)?\s*' . $number . '\s*(?:이상|이거나\s*큰|보다\s*크거나\s*같)/u',
                '/(?:이상)\s*(?:인\s*것\s*)?(?:측정값|값|value)?\s*' . $number . '/u',
            ],
            'gt' => [
                '/(?:측정값|값|value)?\s*' . $number . '\s*(?:초과|보다\s*큰)/u',
                '/(?:초과)\s*(?:인\s*것\s*)?(?:측정값|값|value)?\s*' . $number . '/u',
            ],
            'lte' => [
                '/(?:측정값|값|value)?\s*' . $number . '\s*(?:이하|이거나\s*작|보다\s*작거나\s*같)/u',
                '/(?:이하)\s*(?:인\s*것\s*)?(?:측정값|값|value)?\s*' . $number . '/u',
            ],
            'lt' => [
                '/(?:측정값|값|value)?\s*' . $number . '\s*(?:미만|보다\s*작은)/u',
                '/(?:미만)\s*(?:인\s*것\s*)?(?:측정값|값|value)?\s*' . $number . '/u',
            ],
            'eq' => [
                '/(?:측정값|값|value)?\s*' . $number . '\s*(?:같은|동일한|=)/u',
            ],
        ];
        foreach ($patterns as $op => $group) {
            foreach ($group as $pattern) {
                if (preg_match($pattern, $work, $m)) {
                    return [
                        'enabled' => true,
                        'target' => $target,
                        'op' => $op,
                        'value1' => (float)$m[1],
                        'value2' => null,
                    ];
                }
            }
        }

        return null;
    }
}

if (!function_exists('jtgpt_planner_expand_number_sequence')) {
    function jtgpt_planner_expand_number_sequence(string $expr): array {
        $expr = trim($expr);
        if ($expr === '') {
            return [];
        }
        $expr = preg_replace('/\s*(?:부터|to|and|및|하고|랑|와|과|\/|\\|&)\s*/u', ',', $expr);
        $expr = preg_replace('/\s*~\s*/u', '~', $expr);
        $expr = preg_replace('/\s*-\s*/u', '-', $expr);
        $expr = preg_replace('/\s*,\s*/u', ',', $expr);

        $items = [];
        foreach (preg_split('/\s*,\s*/u', $expr) as $chunk) {
            $chunk = trim((string)$chunk);
            if ($chunk === '') {
                continue;
            }
            if (preg_match('/^(\d{1,2})\s*[~-]\s*(\d{1,2})$/u', $chunk, $m)) {
                $start = (int)$m[1];
                $end = (int)$m[2];
                if ($start <= $end && ($end - $start) <= 20) {
                    for ($i = $start; $i <= $end; $i++) {
                        $items[] = (string)$i;
                    }
                } elseif ($start > $end && ($start - $end) <= 20) {
                    for ($i = $start; $i >= $end; $i--) {
                        $items[] = (string)$i;
                    }
                }
                continue;
            }
            if (preg_match('/^\d{1,2}$/', $chunk)) {
                $items[] = (string)((int)$chunk);
                continue;
            }
            if (preg_match_all('/\d{1,2}/u', $chunk, $m)) {
                foreach ($m[0] as $num) {
                    $items[] = (string)((int)$num);
                }
            }
        }
        return jtgpt_planner_unique_values($items);
    }
}

if (!function_exists('jtgpt_planner_extract_tools')) {
    function jtgpt_planner_extract_tools(string $message): array {
        $tools = [];
        $delim = '(?:,|\/|&|와|과|랑|하고|및|and)';
        $token = '[A-Z]';
        $seq = '(' . $token . '(?:\s*' . $delim . '\s*' . $token . ')+)';

        $appendTokens = static function (array $items) use (&$tools): void {
            foreach ($items as $item) {
                $value = strtoupper(trim((string)$item));
                if ($value === '') {
                    continue;
                }
                $tools[] = $value;
            }
        };

        $extractSeq = static function (string $expr) use ($token): array {
            if (!preg_match_all('/' . $token . '/iu', strtoupper($expr), $mm)) {
                return [];
            }
            return $mm[0] ?? [];
        };

        $labelPatterns = [
            '/'. $seq .'\s*(?:툴|tool|차수)\b/iu',
            '/(?:툴|tool|차수)\s*'. $seq .'/iu',
        ];
        foreach ($labelPatterns as $pattern) {
            if (preg_match_all($pattern, $message, $m)) {
                foreach ($m[1] as $expr) {
                    $appendTokens($extractSeq((string)$expr));
                }
            }
        }

        $singlePatterns = [
            '/('. $token .')\s*(?:툴|tool|차수)\b/iu',
            '/\b(?:툴|tool|차수)\s*('. $token .')(?=$|[^A-Z0-9])/iu',
        ];
        foreach ($singlePatterns as $pattern) {
            if (preg_match_all($pattern, $message, $m)) {
                $appendTokens($m[1] ?? []);
            }
        }

        $chaPatterns = [
            '/'. $seq .'\s*차(?=$|[^\p{L}\p{N}])/iu',
            '/\b('. $token .')\s*차(?=$|[^\p{L}\p{N}])/iu',
            '/차수\s*'. $seq .'(?=$|[^A-Z0-9])/iu',
            '/차수\s*('. $token .')(?=$|[^A-Z0-9])/iu',
        ];
        foreach ($chaPatterns as $pattern) {
            if (preg_match_all($pattern, $message, $m)) {
                foreach ($m[1] as $expr) {
                    $appendTokens($extractSeq((string)$expr));
                }
            }
        }

        return jtgpt_planner_unique_values($tools);
    }
}

if (!function_exists('jtgpt_planner_extract_cavities')) {
    function jtgpt_planner_extract_cavities(string $message): array {
        $cavities = [];

        $patterns = [
            '/((?:\d{1,2}\s*(?:,|\/|~|-|\s+))*\d{1,2})\s*(?:캐비티|cavity|cav)\b/iu',
            '/(?:캐비티|cavity|cav)\s*((?:\d{1,2}\s*(?:,|\/|~|-|\s+))*\d{1,2})\b/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $message, $m)) {
                foreach ($m[1] as $expr) {
                    foreach (jtgpt_planner_expand_number_sequence((string)$expr) as $num) {
                        $cavities[] = ((int)$num) . 'CAV';
                    }
                }
            }
        }

        if (preg_match_all('/\b(\d{1,2})\s*cav\b/iu', $message, $m)) {
            foreach ($m[1] as $num) {
                $cavities[] = ((int)$num) . 'CAV';
            }
        }

        return jtgpt_planner_unique_values($cavities);
    }
}

if (!function_exists('jtgpt_planner_extract_limit')) {
    function jtgpt_planner_extract_limit(string $message): ?int {
        if (preg_match('/\btop\s*(\d{1,3})\b/iu', $message, $m)) return max(1, min(500, (int)$m[1]));
        if (preg_match('/상위\s*(\d{1,3})/u', $message, $m)) return max(1, min(500, (int)$m[1]));
        if (preg_match('/(\d{1,3})\s*개/u', $message, $m)) return max(1, min(500, (int)$m[1]));
        return null;
    }
}

if (!function_exists('jtgpt_planner_normalize_point_term')) {
    function jtgpt_planner_normalize_point_term(string $term): string {
        $term = strtoupper(trim($term));
        $term = preg_replace('/\s+/u', ' ', $term);
        return trim($term);
    }
}

if (!function_exists('jtgpt_planner_collect_quality_point_terms')) {
    function jtgpt_planner_collect_quality_point_terms(string $message, array $tools = [], array $cavities = []): array {
        $terms = [];

        $explicitPatterns = [
            '/(?:point|포인트|fai)\s*([A-Z0-9]+(?:[\s\-\/.]+[A-Z0-9]+){0,3})/iu',
            '/([A-Z0-9]+(?:[\s\-\/.]+[A-Z0-9]+){0,3})\s*(?:point|포인트|fai)\b/iu',
            '/(?:^|[^A-Z0-9])([A-Z0-9]+(?:\s*[-\/.]\s*[A-Z0-9]+){1,4})(?=$|[^A-Z0-9])/u',
            '/(?:^|[^A-Z0-9])([A-Z]{1,3}\s*[-]?\s*FAI\s*\d+(?:\.\d+)?)(?=$|[^A-Z0-9])/iu',
            '/(?:^|[^A-Z0-9])(\d{1,4}\s+[A-Z]{1,4}\d{1,4})(?=$|[^A-Z0-9])/u',
        ];
        foreach ($explicitPatterns as $pattern) {
            if (preg_match_all($pattern, strtoupper($message), $m)) {
                foreach ($m[1] as $term) {
                    $terms[] = jtgpt_planner_normalize_point_term((string)$term);
                }
            }
        }

        $scrub = ' ' . mb_strtolower($message, 'UTF-8') . ' ';
        $scrub = jtgpt_planner_strip_part_aliases($scrub);
        $scrub = preg_replace('/(20\d{2})[\.\/-]?(\d{1,2})[\.\/-]?(\d{1,2})/u', ' ', $scrub);
        $scrub = preg_replace('/\b(?:oqc|omm|cmm|aoi|ipqc)\b/u', ' ', $scrub);
        $scrub = preg_replace('/\b(?:today|yesterday|recent|latest|show|tell|count|summary|detail|ng|search|find|filter|export|excel|xlsx|csv|download|file|table)\b/u', ' ', $scrub);
        $scrub = preg_replace('/(?:최근|오늘|어제|이번|금주|금월|전체|상세|요약|조회|보여줘|알려줘|말해줘|데이터|기록|이력|많은|상위|가장|전체|전부|에서|으로|로|좀|해줘|봐줘|부탁|랑|하고|와|과|및|검색|찾아줘|찾아주|조건|필터|엑셀|출력|다운로드|저장|파일|표로|테이블|엑셀파일|엑셀로|csv로|xlsx로|줄래|줘|만들어줘|내려줘|내려받아줘)/u', ' ', $scrub);
        $scrub = preg_replace('/(?:ng|불량|측정값|값|value|usl|lsl)/u', ' ', $scrub);
        $scrub = preg_replace('/-?\d+(?:\.\d+)?\s*(?:~|〜|∼|\-)\s*-?\d+(?:\.\d+)?/u', ' ', $scrub);
        $scrub = preg_replace('/(?:측정값|값|value)?\s*-?\d+(?:\.\d+)?\s*(?:이상|이하|초과|미만|보다\s*큰|보다\s*작은|보다\s*크거나\s*같|보다\s*작거나\s*같|같은|동일한|=)/u', ' ', $scrub);
        $scrub = preg_replace('/(?:이상|이하|초과|미만)\s*(?:인\s*것\s*)?(?:측정값|값|value)?\s*-?\d+(?:\.\d+)?/u', ' ', $scrub);
        $scrub = preg_replace('/([a-z0-9](?:\s*(?:,|\/|&|와|과|랑|하고|및|and)\s*[a-z0-9])+?)\s*(?:툴|tool|차수)/iu', ' ', $scrub);
        $scrub = preg_replace('/(?:툴|tool|차수)\s*([a-z0-9](?:\s*(?:,|\/|&|와|과|랑|하고|및|and)\s*[a-z0-9])+)/iu', ' ', $scrub);
        $scrub = preg_replace('/\b[a-z0-9]\s*(?:툴|tool|차수)\b/iu', ' ', $scrub);
        $scrub = preg_replace('/([a-z0-9](?:\s*(?:,|\/|&|와|과|랑|하고|및|and)\s*[a-z0-9])+?)\s*차(?=$|[^\p{L}\p{N}])/iu', ' ', $scrub);
        $scrub = preg_replace('/\b[a-z0-9]\s*차(?=$|[^\p{L}\p{N}])/iu', ' ', $scrub);
        $scrub = preg_replace('/([0-9\s,~\/-]+)\s*(?:캐비티|cavity|cav)\b/iu', ' ', $scrub);
        $scrub = preg_replace('/(?:캐비티|cavity|cav)\s*([0-9\s,~\/-]+)/iu', ' ', $scrub);
        $scrub = preg_replace('/[\(\)\[\]\{\}:;\|]+/u', ' ', $scrub);

        $rawTokens = preg_split('/[^\p{L}\p{N}\.\/-]+/u', strtoupper($scrub), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $filteredTokens = [];
        foreach ($rawTokens as $token) {
            $token = trim((string)$token);
            if ($token === '') {
                continue;
            }
            if (in_array($token, ['OQC', 'OMM', 'CMM', 'AOI', 'NG', 'LG', 'FLAG'], true)) {
                continue;
            }
            if (preg_match('/^(?:자화|JAWHA|플래그|깃발)$/u', $token)) {
                continue;
            }
            if (in_array($token, $tools, true)) {
                continue;
            }
            if (in_array($token . 'CAV', $cavities, true)) {
                continue;
            }
            if (preg_match('/^\d{4}$/', $token)) {
                continue;
            }
            if (preg_match('/^-?\d+\.\d+$/', $token)) {
                continue;
            }
            if (preg_match('/^(?:EXCEL|XLSX|CSV|TABLE|FILE|DOWNLOAD)$/', $token)) {
                continue;
            }
            if (preg_match('/^(?:엑셀|출력|다운로드|파일|테이블|표로|조건|검색)$/u', $token)) {
                continue;
            }
            $filteredTokens[] = $token;
            if (preg_match('/^\d{1,3}$/', $token)) {
                $terms[] = $token;
            }
        }

        for ($i = 0, $n = count($filteredTokens) - 1; $i < $n; $i++) {
            $a = $filteredTokens[$i];
            $b = $filteredTokens[$i + 1];
            if ($a === '' || $b === '') {
                continue;
            }
            if (preg_match('/\d/u', $a . $b) || mb_strlen($a, 'UTF-8') > 1 || mb_strlen($b, 'UTF-8') > 1) {
                $terms[] = jtgpt_planner_normalize_point_term($a . ' ' . $b);
            }
        }

        $out = [];
        foreach ($terms as $term) {
            $term = jtgpt_planner_normalize_point_term((string)$term);
            if ($term === '') {
                continue;
            }
            if (preg_match('/^(OQC|OMM|CMM|AOI|NG)$/', $term)) {
                continue;
            }
            if (preg_match('/(^|\s)NG($|\s)/', $term)) {
                continue;
            }
            if (preg_match('/\S+-\S+\s+\S+-\S+/', $term)) {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $term)) {
                continue;
            }
            if (preg_match('/^-?\d+\.\d+$/', $term)) {
                continue;
            }
            if (preg_match('/(?:엑셀|출력|다운로드|파일|테이블|표로|조건|검색|EXCEL|XLSX|CSV|TABLE|FILE|DOWNLOAD|LG|FLAG|자화|JAWHA|플래그|깃발)/u', $term)) {
                continue;
            }
            $out[] = $term;
        }

        return array_slice(jtgpt_planner_unique_values($out), 0, 12);
    }
}

if (!function_exists('jtgpt_planner_extract_point_no')) {
    function jtgpt_planner_extract_point_no(string $message): ?string {
        $tools = jtgpt_planner_extract_tools($message);
        $cavities = jtgpt_planner_extract_cavities($message);
        $terms = jtgpt_planner_collect_quality_point_terms($message, $tools, $cavities);
        return $terms[0] ?? null;
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

if (!function_exists('jtgpt_planner_build_quality_slots')) {
    function jtgpt_planner_build_quality_slots(string $message, array $state, array $range, ?string $partName, array $tools, array $cavities, array $pointTerms, ?int $limit, ?array $valueFilter = null): array {
        $lower = mb_strtolower($message, 'UTF-8');
        if ($range['implicit']) {
            $range = jtgpt_planner_default_recent_range(7);
        }

        if (!$pointTerms && jtgpt_planner_contains_any($lower, ['1위', '그 포인트', '해당 포인트', '상세'])) {
            $ranked = $state['last_ranked_points'] ?? [];
            if (!empty($ranked[0])) {
                $pointTerms = [(string)$ranked[0]];
            }
        }

        $modules = jtgpt_planner_extract_quality_modules($message, $state);
        $intent = jtgpt_planner_extract_quality_intent($message, $pointTerms);
        $outputMode = jtgpt_planner_extract_quality_output_mode($message, $pointTerms);
        $ngOnly = jtgpt_planner_extract_quality_ng_only($message, $valueFilter);
        $output = jtgpt_planner_extract_quality_output_format($message);

        $slots = [
            'intent' => $intent,
            'type' => count($modules) === 1 ? strtoupper($modules[0]) : 'ALL',
            'module' => $modules[0] ?? 'oqc',
            'modules' => $modules,
            'all_modules' => count($modules) !== 1,
            'model' => $partName,
            'part_name' => $partName,
            'period' => $range,
            'from' => $range['from'],
            'to' => $range['to'],
            'range' => $range,
            'tool' => $tools[0] ?? null,
            'tools' => $tools,
            'cavity' => $cavities[0] ?? null,
            'cavities' => $cavities,
            'fais' => $pointTerms,
            'point_terms' => $pointTerms,
            'point_no' => null,
            'limit' => $limit,
            'ng_only' => $ngOnly,
            'value_filter' => $valueFilter,
            'output_mode' => $outputMode,
            'output' => $output,
            'date_field' => jtgpt_planner_extract_quality_date_field($message),
            'group_by' => jtgpt_planner_extract_cert_group_by($message),
            'slot_mode' => true,
        ];

        if ($intent === 'top_ng_points' && $slots['limit'] === null) {
            $slots['limit'] = 5;
        }

        return $slots;
    }
}

if (!function_exists('jtgpt_planner_plan')) {
    function jtgpt_planner_plan(string $message, array $state = []): array {
        $text = trim($message);
        $lower = mb_strtolower($text, 'UTF-8');
        $range = jtgpt_planner_detect_date_range($lower);
        $partName = jtgpt_planner_extract_part_name($text);
        $customer = jtgpt_planner_extract_customer($text);
        $tools = jtgpt_planner_extract_tools($text);
        $cavities = jtgpt_planner_extract_cavities($text);
        $pointTerms = jtgpt_planner_collect_quality_point_terms($text, $tools, $cavities);
        $limit = jtgpt_planner_extract_limit($text);
        $valueFilter = jtgpt_planner_extract_quality_value_filter($text);
        $output = jtgpt_planner_extract_quality_output_format($text);
        $domain = jtgpt_planner_detect_primary_domain($text, $pointTerms, $valueFilter);

        if ($text === '') {
            return [
                'kind' => 'clarify',
                'answer' => '질문이 비어 있어요. 출하, OQC/OMM/AOI/CMM NG, 그래프빌더 중에서 먼저 말해 주세요.',
            ];
        }

        if (jtgpt_planner_contains_any($lower, ['관리자', '권한', '비밀번호', '삭제', '수정', '업로드', '해킹', 'insert', 'update', 'delete', 'replace', 'alter', 'drop'])) {
            return ['kind' => 'answer', 'tool' => 'guard_read_only', 'args' => []];
        }

        if (jtgpt_planner_is_quality_export_followup($text, $state, $output, $partName, $tools, $cavities, $pointTerms, $range, $valueFilter)) {
            $lastQuality = jtgpt_planner_last_quality_context($state);
            $savedTool = trim((string)($lastQuality['tool'] ?? ''));
            $savedArgs = (array)($lastQuality['args'] ?? []);
            if ($savedTool !== '' && $savedArgs) {
                $savedArgs['output'] = $output;
                if ($savedTool === 'quality_cert_remaining') {
                    if (jtgpt_planner_is_detail_export_message($text)) {
                        $savedArgs['detail_export'] = true;
                    } else {
                        unset($savedArgs['detail_export']);
                    }
                }
                return ['kind' => 'tool', 'tool' => $savedTool, 'args' => $savedArgs, 'slots' => $savedArgs, 'followup' => true, 'followup_source' => (string)($lastQuality['source'] ?? 'state')];
            }
        }

        $wantsGraph = jtgpt_planner_contains_any($lower, ['그래프빌더', 'graph builder', '차트', '그래프', '히스토그램', '상자그림', '선그래프', '막대그래프', 'jmp']);
        if ($wantsGraph) {
            $spec = jtgpt_planner_build_graph_spec($text);
            $actionType = jtgpt_planner_contains_any($lower, ['공정 능력', '공정능력']) ? 'open_ipqc_process_capability' : 'open_ipqc_quick_graph';
            return ['kind' => 'action', 'tool' => $actionType, 'args' => ['graph_spec' => $spec], 'autorun' => true];
        }

        // 도메인 잠금 순서: shipping -> cert_remaining -> ng
        // LG/자화/OQC/몇건 같은 공용 단어는 도메인 결정 키워드가 아니므로,
        // 반드시 행동 단어가 강한 도메인을 먼저 잠근 뒤 세부 intent 를 해석한다.
        if ($domain === 'shipping') {
            $metric = 'summary';
            if (jtgpt_planner_contains_any($lower, ['최근 출하일', '제일 최근 출하일', '최근출하일', '마지막 출하일', '마지막으로 출하', '최신 출하일'])) {
                return ['kind' => 'tool', 'tool' => 'shipping_last_ship_date', 'args' => ['from' => $range['from'], 'to' => $range['to'], 'range' => $range, 'part_name' => $partName, 'customer' => $customer]];
            }
            if (jtgpt_planner_contains_any($lower, ['lot'])) $metric = 'lot_count';
            elseif (jtgpt_planner_contains_any($lower, ['tray'])) $metric = 'tray_count';
            elseif (jtgpt_planner_contains_any($lower, ['수량', 'qty', 'ea'])) $metric = 'qty';
            return ['kind' => 'tool', 'tool' => 'shipping_summary', 'args' => ['from' => $range['from'], 'to' => $range['to'], 'range' => $range, 'part_name' => $partName, 'customer' => $customer, 'metric' => $metric]];
        }

        // 플래그/성적서 가능/남은 데이터 문장은 NG fallback 금지.
        // date_field 는 LG/엘지=meas_date, 자화/jawha=jmeas_date 로 해석한다.
        if ($domain === 'cert_remaining') {
            $slots = jtgpt_planner_build_quality_slots($text, $state, $range, $partName, $tools, $cavities, $pointTerms, $limit, null);
            $slots['intent'] = 'cert_remaining';
            $slots['module'] = 'oqc';
            $slots['modules'] = ['oqc'];
            $slots['type'] = 'OQC';
            $slots['all_modules'] = false;
            $slots['ng_only'] = false;
            $slots['point_terms'] = [];
            $slots['fais'] = [];
            $slots['point_no'] = null;
            $slots['date_field'] = jtgpt_planner_extract_quality_date_field($text);
            $slots['group_by'] = jtgpt_planner_extract_cert_group_by($text);
            // 한 문장 안에 조회 + 상세 export 가 같이 들어오면 follow-up 을 기다리지 말고 바로 detail_export 로 넘긴다.
            if (jtgpt_planner_is_detail_export_message($text)) {
                $slots['detail_export'] = true;
            }
            return ['kind' => 'tool', 'tool' => 'quality_cert_remaining', 'args' => $slots, 'slots' => $slots];
        }

        if ($domain === 'ng') {
            $slots = jtgpt_planner_build_quality_slots($text, $state, $range, $partName, $tools, $cavities, $pointTerms, $limit, $valueFilter);
            $intent = (string)($slots['intent'] ?? 'recent_ng');

            if ($intent === 'top_ng_points') {
                return ['kind' => 'tool', 'tool' => 'quality_top_ng_points', 'args' => $slots, 'slots' => $slots];
            }
            if ($intent === 'point_detail' && !empty($slots['point_terms']) && count((array)$slots['point_terms']) === 1) {
                return ['kind' => 'tool', 'tool' => 'quality_point_detail', 'args' => $slots, 'slots' => $slots];
            }
            if ($intent === 'count') {
                return ['kind' => 'tool', 'tool' => 'quality_count_ng_rows', 'args' => $slots, 'slots' => $slots];
            }
            if ($intent === 'summary' || $intent === 'compare') {
                return ['kind' => 'tool', 'tool' => 'quality_summary', 'args' => $slots, 'slots' => $slots];
            }
            return ['kind' => 'tool', 'tool' => 'quality_recent_ng_rows', 'args' => $slots, 'slots' => $slots];
        }

        if (jtgpt_planner_contains_any($lower, ['ipqc', 'jmp', '공정능력'])) {
            return ['kind' => 'action', 'tool' => 'open_ipqc_route', 'args' => [], 'autorun' => true];
        }

        return [
            'kind' => 'clarify',
            'answer' => '출하, OQC/OMM/CMM/AOI NG, 그래프빌더 중에서 어느 쪽인지 조금만 더 말해 주세요.',
        ];
    }
}
