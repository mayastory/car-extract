<?php
if (!defined('JTMES_ROOT')) {
    define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

date_default_timezone_set('Asia/Seoul');
session_start();

require_once JTMES_ROOT . '/config/dp_config.php';

$EMBED = !empty($_GET['embed']);

if (!$EMBED) {
    require_once JTMES_ROOT . '/inc/sidebar.php';
    require_once JTMES_ROOT . '/inc/dp_userbar.php';
}

require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function table_columns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[strtolower((string)$row['Field'])] = true;
        }
    } catch (Throwable $e) {
    }
    return $cols;
}

function normalize_simple(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9가-힣]/u', '', $s) ?? $s;
    return $s;
}

function fmt_display_value($value): string
{
    if ($value === null) return '';
    $text = trim((string)$value);
    if ($text === '') return '';
    if (!is_numeric($text)) return $text;

    $negative = str_starts_with($text, '-');
    $absText  = ltrim($text, '+-');
    $parts    = explode('.', $absText, 2);
    $intPart  = $parts[0] !== '' ? $parts[0] : '0';
    $decPart  = $parts[1] ?? '';

    if ($decPart === '') {
        return ($negative ? '-' : '') . $intPart;
    }

    if (strlen($decPart) <= 4) {
        return ($negative ? '-' : '') . $intPart . '.' . $decPart;
    }

    return number_format((float)$text, 4, '.', '');
}

function parse_numeric_or_null($value): ?float
{
    if ($value === null) return null;
    $text = trim((string)$value);
    if ($text === '') return null;
    if (!is_numeric($text)) return null;
    return (float)$text;
}

function parse_tool_cavity(?string $toolCavity): array
{
    $text = trim((string)$toolCavity);
    if ($text === '') return ['', ''];

    $patterns = [
        '/([A-Z])\s*#?\s*(\d+)/i',
        '/TOOL\s*[:#-]?\s*([A-Z0-9]+).*?CAV(?:ITY)?\s*[:#-]?\s*(\d+)/i',
        '/([A-Z])\s*\/\s*(\d+)/i',
        '/([A-Z])(\d)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return [strtoupper(trim($m[1])), trim($m[2])];
        }
    }

    return ['', ''];
}

function parse_date_text(?string $value): ?string
{
    $text = trim((string)$value);
    if ($text === '') return null;

    $formats = ['Y-m-d', 'Y/m/d', 'Y.m.d', 'y-m-d', 'y/m/d', 'y.m.d'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $text);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    if (preg_match('/\b(20\d{2}|19\d{2})[-_\/.]?(\d{2})[-_\/.]?(\d{2})\b/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    if (preg_match('/\b(\d{2})[-_\/.]?(\d{2})[-_\/.]?(\d{2})\b/', $text, $m)) {
        $year = (int)$m[1];
        $year += ($year >= 70) ? 1900 : 2000;
        return sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[3]);
    }

    $ts = strtotime($text);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

function pick_header_date(array $row, array $priorityCols): ?string
{
    $sourceFile = $row['source_file'] ?? '';
    $fromSource = parse_date_text($sourceFile);
    if ($fromSource !== null) {
        return $fromSource;
    }

    foreach ($priorityCols as $col) {
        if (!array_key_exists($col, $row)) continue;
        $parsed = parse_date_text((string)$row[$col]);
        if ($parsed !== null) return $parsed;
    }

    return null;
}

function lot_label(string $date): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt instanceof DateTime) return $date;
    return $dt->format('m월 d일');
}

function build_model_condition(string $fieldExpr, string $token): array
{
    $patterns = ["%{$token}%"];
    if ($token === 'irbase') {
        $patterns[] = '%ir%base%';
    } elseif ($token === 'xcarrier') {
        $patterns[] = '%xcarrier%';
        $patterns[] = '%x%carrier%';
    } elseif ($token === 'ycarrier') {
        $patterns[] = '%ycarrier%';
        $patterns[] = '%y%carrier%';
    } elseif ($token === 'zcarrier') {
        $patterns[] = '%zcarrier%';
        $patterns[] = '%z%carrier%';
    } elseif ($token === 'zstopper') {
        $patterns[] = '%zstopper%';
        $patterns[] = '%z%stopper%';
    }

    $patterns = array_values(array_unique($patterns));
    $parts = [];
    $params = [];
    foreach ($patterns as $idx => $pattern) {
        $key = ':model_like_' . $idx;
        $parts[] = "{$fieldExpr} LIKE {$key}";
        $params[$key] = $pattern;
    }

    return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
}

function classify_ng_side(array $row): string
{
    $value = parse_numeric_or_null($row['measured_value'] ?? null);
    $usl   = parse_numeric_or_null($row['usl'] ?? null);
    $lsl   = parse_numeric_or_null($row['lsl'] ?? null);

    if ($value !== null && $usl !== null && $value > $usl) return 'USL';
    if ($value !== null && $lsl !== null && $value < $lsl) return 'LSL';
    if ($usl !== null && $lsl === null) return 'USL';
    if ($lsl !== null && $usl === null) return 'LSL';

    if ($value !== null && $usl !== null && $lsl !== null) {
        return abs($value - $usl) <= abs($value - $lsl) ? 'USL' : 'LSL';
    }

    return 'USL';
}

function daterange_inclusive(string $start, string $end): array
{
    $out = [];
    $cur = DateTime::createFromFormat('Y-m-d', $start);
    $last = DateTime::createFromFormat('Y-m-d', $end);
    if (!$cur instanceof DateTime || !$last instanceof DateTime) return $out;

    while ($cur <= $last) {
        $out[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }

    return $out;
}

function build_fai_whitelist_from_tsv(string $tsv): array
{
    $modelMap = [
        0 => 'irbase',
        1 => 'xcarrier',
        2 => 'ycarrier',
        3 => 'zcarrier',
        4 => 'zstopper',
    ];

    $lookup = [
        'irbase'   => [],
        'xcarrier' => [],
        'ycarrier' => [],
        'zcarrier' => [],
        'zstopper' => [],
    ];

    $lines = preg_split('/\r\n|\n|\r/', trim($tsv));
    if (!$lines) return $lookup;

    array_shift($lines);

    foreach ($lines as $line) {
        $cols = str_getcsv($line, "\t");
        for ($i = 0; $i <= 4; $i++) {
            $raw = trim((string)($cols[$i] ?? ''));
            if ($raw === '') continue;
            $norm = normalize_simple($raw);
            if ($norm === '') continue;
            $lookup[$modelMap[$i]][$norm] = $raw;
        }
    }

    return $lookup;
}

$holdingTabs = [
    'MEM-IR-BASE' => [
        'label'        => 'MEM-IR-BASE',
        'product_name' => 'IR BASE',
        'model_norm'   => 'irbase',
    ],
    'MEM-X-CARRIER' => [
        'label'        => 'MEM-X-CARRIER',
        'product_name' => 'X CARRIER',
        'model_norm'   => 'xcarrier',
    ],
    'MEM-Y-CARRIER' => [
        'label'        => 'MEM-Y-CARRIER',
        'product_name' => 'Y CARRIER',
        'model_norm'   => 'ycarrier',
    ],
    'MEM-Z-CARRIER' => [
        'label'        => 'MEM-Z-CARRIER',
        'product_name' => 'Z CARRIER',
        'model_norm'   => 'zcarrier',
    ],
    'MEM-Z-STOPPER' => [
        'label'        => 'MEM-Z-STOPPER',
        'product_name' => 'Z STOPPER',
        'model_norm'   => 'zstopper',
    ],
];

$FAI_WHITELIST_TSV = <<<'TSV'
IRBASE	X Carrier	Y Carrier	Z Carrier	Z Stopper
1	4-1-a	3-1(P1)	2-1	1
3-D3	4-2-b	3-2(P2)	2-2	2-1
3-D4	4-3-c	3-3(P3)	2-3	2-2
4	6-1-a	4	2-4	2-3
5	6-2-b	5	3	2-4
6-D1	6-3-c	6	4-a	5-1
6-D2	8	7	4-b	5-2
8-1 (V2)	9	8-1(P1)	4-c	6-1
8-2 (V1)	10-1-d	8-2(P2)	6-a	6-2
8-3 (U)	10-2-e	9	6-b	7-1
9	10-3-f	10	6-c	7-2
10	23	11	7	7-3
13	24	12-1(P1)	8	7-4
14-P1	25A-d	12-2(P2)	9-1(D1)	9-1
14-P2	25A-e	13-1(P1)	9-2(D2)	9-2
17-P1	25A-f	13-2(P2)	9-3(D3)	10-1
17-P2	26	13-3(P3)	9-4(D4)	10-2
17-P3	27	14-1(P1)	9-5(D5)	12
18	28	14-2(P2)	9-6(D6)	13
19	30	14-3(P3)	9-7(D7)	14
20-P1	31	16-1(P1)	9-8(D8)	15
20-P2	32	16-2(P2)	11	16
20-P3	33	18-1(P1)	12-C1	17-1
21-P73	34	18-2(P2)	12-C2	17-2
21-P52	35	18-3(P3)	12-C3	19-1
21-P36	36	19-1(P1)	12-C4	19-2
21-P21	37	19-2(P2)	13-1(P1)	20
21-P2	38	19-3(P3)	13-2(P2)	21
21-P1	39	24-1(P1)	14-1(P1-P2)	22
22-S1	40-1	24-2(P2)	14-2(P3-P4)	23
22-S2	40-2	25	L-15-P1	24-1
22-S3	40-3	26	L-15-P2	24-2
22-S4	40-4	27-1(B1)	R-15-Q1	25-1
22-S5	41	27-2(B2)	R-15-Q2	25-2
23-S6	54	27-3(B3)	20	26
23-S7	55	27-4(B4)	21	27-1
25	56	28	22	27-2
26	57	29	23	28
27-P1	58	30-1(P1)	24	29
27-P2	59	30-2(P2)	25-1(P1)	31
28-P1	60	31-1(P1)	25-2(P2)	32
28-P2	61	31-2(P3)	26-1	33
29	62	31-3(P5)	26-2	34-1
30	63	32-1(P2)	27	34-2
31	64	32-2(P4)	28	35-1
32-P1	65	32-3(P6)	29	35-2
32-P2	66	33	31	39-1
33-P1	68-1	34-1(P1)	33	39-2
33-P2	68-2	34-2(P3)	34	40
35-P1	69	34-3(P5)	35-1(P1)	41
35-P2	70-1	35-1(P1)	35-2(P2)	42
37-P4	70-2	35-2(P3)	36	43
37-P5	71	36	37-1(C1)	44-1
37-P6		37-1	37-2(C2)	44-2
38-P1		37-2	38-1(C1)	46
38-P2		38-1(P1)	38-2(C2)	47
39-P1		38-2(P2)	39	48
39-P2		39	40	49
40-P1		41	41	50-1
40-P2		42	45	50-2
40-P3		43	46	51-1
41-P1		44	47	51-2
41-P2		45	50	52-1
41-P3		46	51	52-2
41-P4		48-1(P2)	52	53-1
42-P1		48-2(P4)	53	53-2
42-P2		48-3(P6)	54-P1	54-1
42-P3		49	54-P2	54-2
43		50-1(P1)	55-P1	54-3
44-P73		50-2(P2)	55-P2	54-4
44-P52		50-3(P3)	56-1(P1)	55-1
44-P36		51-1(P1)	56-2(P2)	55-2
44-P21		51-2(P2)	57	56-1
44_P2		51-3(P3)	66	56-2
44_P1		52-1	67	59
45-1		52-2	68	60
45-2		52-3	69-1	61
45-3		53-1	69-2	62
46-P1		53-2	70-1	
46-P2		56	70-2	
60		57	80	
61-U		58-1(P1)	81	
61-M		58-2(P2)	82	
61-D		59-1(P1)	83	
63		59-2(P2)	84	
64-U		60-1(P1)	85	
64-M		60-2(P2)	86-A1	
64-D		61	86-A2	
65-U		62-1(P1)	86-A3	
65-M		62-2(P2)	86-A4	
65-D		63-1(P1)	86-A5	
66		63-2(P2)	86-A6	
67		64	87	
68-P1		65-1(P2)	88-A1	
68-P2		65-2(P4)	88-A2	
68-P3		66-1(P1)	88-A3	
69-P1		66-2(P2)	88-A4	
69-P2		67-1A(P1)	88-A5	
69-P3		67-2A(P2)	88-A6	
70		67-3A(P3)	89	
71-P1		67-4A(P4)	96-1	
71-P2		67-5A(P5)	96-2	
72-P1		67-6A(P6)	97-1	
72-P2		67-7A(P7)	97-2	
73-P1		67-8A(P8)	98-V1	
73-P2		67-9A(P9)	99-V1	
74-P1		67-10A(P10)	100-V1	
74-P2		67-11A(P11)	101-V1	
75-P1		67-12A(P12)	102-V1	
75-P2		67-13A(P13)	103-V1	
76		70	98-V2	
77		71	99-V2	
78-P1		72-1(P1)	100-V2	
78-P2		72-2(P2)	101-V2	
82-C1		72-3(P3)	102-V2	
82-C2		73-1(P1)	103-V2	
82-C3		73-2(P2)	104-V3	
82-C4		73-3(P3)	105-V3	
82-C5		74	106-V3	
82-C6		80	107-V3	
86		81	108-V3	
87-P1		82	109-V3	
87-P2		83	104-V4	
87-P3		84	105-V4	
88-P1		85-1(A1)	106-V4	
88-P2		85-2(A2)	107-V4	
89-P1		85-3(A3)	108-V4	
89-P3		85-4(A4)	109-V4	
94-E1		85-5(A5)	111-1	
94-E2		85-6(A6)	111-2	
94-E3		86-1(A1)	111-3	
94-E4		86-2(A2)	111-4	
94-E5		86-3(A3)	111-5	
94-E6		86-4(A4)	111-6	
94-E7		86-5(A5)	111-7	
94-E8		86-6(A6)	111-8	
94-E9		90-1(M1)	111-9	
94-E10		90-2(M2)	111-10	
94-E11		91	111-11	
94-E12		92	111-12	
94-E13		94-1(C1)	111-13	
94-E14		94-2(C2)	111-14	
94-E15		95	111-15	
94-E16		96	113	
94-E17		97	114-1	
94-E18		99	114-2	
94-E19		100	115	
94-E20			116-1	
94-E21			116-2	
97			117-1	
98			117-2	
99				
100				
101-1				
101-2				
101-3				
101-4				
102				
103				
105-K1				
105-K2				
105-K3				
106-K4				
106-K5				
106-K6				
107-P1				
107-P2				
107-P3				
108-P4				
108-P8				
109-P4				
109-P8				
110-P5				
110-P6				
111				
112-V1				
113-V1				
114-V1				
115-V1				
116-V1				
117-V1				
112-V2				
113-V2				
114-V2				
115-V2				
116-V2				
117-V2				
118				
119				
120				
121				
122				
123				
124				
125				
126				
127-P1				
127-P2				
128-P1				
128-P2				
129-P1				
129-P2				
130-P1				
130-P2				
131				
132				
133				
135				
136-P1				
136-P2				
136-P3				
137				
138-P1				
138-P2				
138-P3				
139				
140				
141				
143				
144				
145-Plastic				
145-Terminal				
145				
146-Plastic				
146-Terminal				
146				
147				
148				
149-S8				
149-S9				
150				
151				
152				
153				
154				
155				
157				
158				
TSV;

$faiWhitelist = build_fai_whitelist_from_tsv($FAI_WHITELIST_TSV);

$activeTab = $_GET['tab'] ?? 'MEM-IR-BASE';
if (!isset($holdingTabs[$activeTab])) {
    $activeTab = 'MEM-IR-BASE';
}

$tab = $holdingTabs[$activeTab];
$allowedPointSet = $faiWhitelist[$tab['model_norm']] ?? [];

$state = [
    'rows' => [],
    'year' => null,
    'empty_message' => '',
];

try {
    $pdo = dp_get_pdo();
    $headerCols = table_columns($pdo, 'oqc_header');
    $hasResultHeader = table_exists($pdo, 'oqc_result_header');
    $hasMeasurements = table_exists($pdo, 'oqc_measurements');
    $hasIgnoreTable  = table_exists($pdo, 'oqc_ng_ignore_point');

    // meas_date / jmeas_date 계열은 플래그로 쓰이므로 날짜 축에서 제외한다.
    $priorityCols = [];
    foreach (['measurement_date', 'measure_date', 'measured_date', 'inspect_date', 'inspection_date', 'date', 'lot_date', 'ship_date'] as $col) {
        if (isset($headerCols[$col])) {
            $priorityCols[] = $col;
        }
    }

    $partNameExpr = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(h.part_name,'-',''),'_',''),' ',''),'.',''))";
    $cond = build_model_condition($partNameExpr, $tab['model_norm']);

    $selectCols = ['h.id', 'h.part_name', 'h.tool_cavity', 'h.source_file'];
    foreach ($priorityCols as $col) {
        $selectCols[] = "h.`{$col}`";
    }

    $headerSql = "SELECT " . implode(', ', $selectCols) . " FROM oqc_header h WHERE {$cond['sql']} ORDER BY h.id ASC";
    $stmtHeaders = $pdo->prepare($headerSql);
    $stmtHeaders->execute($cond['params']);
    $allHeaders = $stmtHeaders->fetchAll(PDO::FETCH_ASSOC);

    $headersById = [];
    $dates = [];
    foreach ($allHeaders as $row) {
        $eventDate = pick_header_date($row, $priorityCols);
        if ($eventDate === null) continue;
        $row['event_date'] = $eventDate;
        $headersById[(int)$row['id']] = $row;
        $dates[] = $eventDate;
    }

    if (!$headersById) {
        $state['empty_message'] = '표시할 OQC 데이터가 없습니다.';
    } else {
        rsort($dates);
        $latestDate = $dates[0];
        $targetYear = substr($latestDate, 0, 4);
        $state['year'] = $targetYear;

        foreach ($headersById as $id => $row) {
            if (substr($row['event_date'], 0, 4) !== $targetYear) {
                unset($headersById[$id]);
            }
        }

        if (!$headersById) {
            $state['empty_message'] = '표시할 OQC 데이터가 없습니다.';
        } else {
            $ignorePoints = [];
            if ($hasIgnoreTable) {
                try {
                    $modelExpr = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(model_name,'-',''),'_',''),' ',''),'.',''))";
                    $sqlIgnore = "SELECT point_no FROM oqc_ng_ignore_point WHERE enabled = 1 AND {$modelExpr} = :model_norm";
                    $stmtIgnore = $pdo->prepare($sqlIgnore);
                    $stmtIgnore->execute([':model_norm' => $tab['model_norm']]);
                    foreach ($stmtIgnore->fetchAll(PDO::FETCH_ASSOC) as $ignoreRow) {
                        $pointNo = trim((string)($ignoreRow['point_no'] ?? ''));
                        if ($pointNo === '') continue;
                        $ignorePoints[normalize_simple($pointNo)] = true;
                    }
                } catch (Throwable $e) {
                }
            }

            $headersByDate = [];
            $dateMin = null;
            $dateMax = null;

            foreach ($headersById as $id => $row) {
                $date = $row['event_date'];
                $headersByDate[$date][$id] = $row;
                if ($dateMin === null || $date < $dateMin) $dateMin = $date;
                if ($dateMax === null || $date > $dateMax) $dateMax = $date;
            }

            $ngRowsByDate = [];
            if ($hasResultHeader && $hasMeasurements) {
                $headerIds = array_keys($headersById);
                $chunks = array_chunk($headerIds, 500);

                foreach ($chunks as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $sqlNg = "
                        SELECT
                            r.header_id,
                            r.point_no,
                            r.usl,
                            r.lsl,
                            m.value AS measured_value
                        FROM oqc_result_header r
                        LEFT JOIN oqc_measurements m
                          ON m.header_id = r.header_id
                         AND m.point_no = r.point_no
                        WHERE r.header_id IN ({$ph})
                          AND r.result_ok = 0
                        ORDER BY r.header_id ASC, r.point_no ASC
                    ";
                    $stmtNg = $pdo->prepare($sqlNg);
                    $stmtNg->execute($chunk);

                    foreach ($stmtNg->fetchAll(PDO::FETCH_ASSOC) as $ng) {
                        $pointNo = trim((string)($ng['point_no'] ?? ''));
                        if ($pointNo === '') continue;

                        $pointNorm = normalize_simple($pointNo);
                        if ($pointNorm === '' || !isset($allowedPointSet[$pointNorm])) {
                            continue;
                        }

                        if (isset($ignorePoints[$pointNorm])) {
                            continue;
                        }

                        $headerId = (int)$ng['header_id'];
                        if (!isset($headersById[$headerId])) continue;

                        $header = $headersById[$headerId];
                        $side   = classify_ng_side($ng);
                        [$tool, $cav] = parse_tool_cavity((string)($header['tool_cavity'] ?? ''));
                        $date = $header['event_date'];

                        $ngRowsByDate[$date][] = [
                            'state'        => 'ng',
                            'product_name' => $tab['product_name'],
                            'lot_label'    => lot_label($date),
                            'tool'         => $tool,
                            'cav'          => $cav,
                            'side'         => $side,
                            'fai'          => $pointNo,
                            'usl'          => fmt_display_value($ng['usl'] ?? ''),
                            'lsl'          => fmt_display_value($ng['lsl'] ?? ''),
                            'measured'     => fmt_display_value($ng['measured_value'] ?? ''),
                        ];
                    }
                }
            }

            $renderRows = [];
            if ($dateMin !== null && $dateMax !== null) {
                foreach (daterange_inclusive($dateMin, $dateMax) as $date) {
                    if (!empty($ngRowsByDate[$date])) {
                        foreach ($ngRowsByDate[$date] as $row) {
                            $renderRows[] = $row;
                        }
                        continue;
                    }

                    $hasAnyFile = !empty($headersByDate[$date]);
                    $renderRows[] = [
                        'state'        => $hasAnyFile ? 'ok' : 'idle',
                        'product_name' => $tab['product_name'],
                        'lot_label'    => lot_label($date),
                        'tool'         => '',
                        'cav'          => '',
                    ];
                }
            }

            $state['rows'] = $renderRows;
            if (!$renderRows) {
                $state['empty_message'] = '표시할 OQC 데이터가 없습니다.';
            }
        }
    }
} catch (PDOException $e) {
    $state['empty_message'] = 'DB 접속 실패: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OQC 홀딩리스트</title>
<style>
:root{
    --bg:#202124;
    --card:#2b2b2b;
    --card-border:#4a4d55;
    --card-shadow:0 14px 28px rgba(0,0,0,.28), 0 10px 10px rgba(0,0,0,.18);
    --title:#f2f4f7;
    --folder:#535d6c;
    --folder-border:#77808e;
    --folder-text:#f4f6fa;
    --folder-active:#3b9857;
    --folder-active-border:#5ebf79;
    --table-bg:#f6f6f6;
    --th-bg:#ece8e2;
    --th-bg-2:#f3eee8;
    --cell-bg:#ffffff;
    --ng-bg:#FDF2ED;
    --ok-bg:#CFE9CF;
    --idle-bg:#f5f5f5;
    --grid:#2d2d2d;
    --text:#111;
}
*{box-sizing:border-box;}
html,body{margin:0;min-height:100%;}
body{
    font-family:Arial, "Malgun Gothic", "맑은 고딕", sans-serif;
    background:<?= $EMBED ? 'transparent' : 'var(--bg)' ?>;
    color:var(--title);
    <?= $EMBED ? '' : 'padding-left:72px;' ?>
}
.main-shell{
    width:min(980px, calc(100vw - 132px));
    margin:34px auto 44px;
}
.page-title{
    margin:0 0 10px;
    font-size:20px;
    line-height:1.15;
    font-weight:800;
    color:var(--title);
    letter-spacing:-.02em;
}
.folder-tabs{
    display:flex;
    align-items:flex-end;
    gap:0;
    padding-left:8px;
    margin:0 0 -1px;
}
.folder-tab{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:29px;
    padding:0 16px;
    margin-right:2px;
    border:1px solid var(--folder-border);
    border-bottom:none;
    border-radius:6px 6px 0 0;
    background:linear-gradient(to bottom, #5e6877 0%, #4f5866 100%);
    color:var(--folder-text);
    text-decoration:none;
    font-size:11px;
    font-weight:800;
    line-height:1;
    letter-spacing:.01em;
    box-shadow:0 -1px 0 rgba(255,255,255,.08) inset;
    white-space:nowrap;
}
.folder-tab.active{
    background:linear-gradient(to bottom, var(--folder-active) 0%, #2f8448 100%);
    border-color:var(--folder-active-border);
    color:#fff;
    position:relative;
    z-index:2;
}
.viewer-card{
    background:var(--card);
    border:1px solid var(--card-border);
    border-radius:12px;
    box-shadow:var(--card-shadow);
    padding:12px;
}
.viewer-surface{
    background:#323232;
    border:1px solid rgba(255,255,255,.08);
    border-radius:10px;
    padding:10px;
    overflow:auto;
}
.holding-table{
    width:100%;
    border-collapse:collapse;
    background:var(--table-bg);
    color:var(--text);
    table-layout:fixed;
}
.holding-table col.c-product{width:92px;}
.holding-table col.c-lot{width:102px;}
.holding-table col.c-tool{width:40px;}
.holding-table col.c-cav{width:34px;}
.holding-table col.c-oqc{width:70px;}
.holding-table col.c-qty{width:54px;}
.holding-table th,
.holding-table td{
    border:1px solid var(--grid);
    padding:3px 6px;
    font-size:12px;
    line-height:1.35;
    text-align:center;
    vertical-align:middle;
    background:var(--cell-bg);
}
.holding-table thead th{
    background:var(--th-bg);
    font-weight:700;
}
.holding-table thead tr:last-child th{
    background:var(--th-bg-2);
}
.holding-table td.product,
.holding-table td.lot,
.holding-table td.tool,
.holding-table td.cav,
.holding-table td.qty{
    background:#fff;
}
.holding-table td.ng-cell{
    background:var(--ng-bg);
}
.holding-table td.blank-oqc{
    background:#fff;
}
.holding-table td.state-cell{
    font-weight:400;
    font-size:13px;
}
.holding-table td.state-ok{
    background:var(--ok-bg);
    color:#0a7c2b;
}
.holding-table td.state-idle{
    background:var(--idle-bg);
    color:#111;
}
.empty-box{
    min-height:200px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    color:#d6d9dd;
    font-size:14px;
    padding:24px;
}
@media (max-width:1100px){
    .main-shell{width:calc(100vw - 92px);}
}
@media (max-width:860px){
    .folder-tabs{overflow-x:auto;padding-bottom:2px;}
    .folder-tab{flex:0 0 auto;}
    .main-shell{width:calc(100vw - 84px);}
}
</style>
</head>
<body>
<?php if (!$EMBED): ?>
<?php echo dp_sidebar_render('oqc_holdinglist'); ?>
<div class="dp-shell-wrap" style="height:auto; min-height:100vh; padding-left:72px; position:relative; z-index:20; display:block;">
<?php echo dp_render_userbar([
    'iframe_id' => 'modal',
    'admin_iframe_src' => 'admin_settings',
    'logout_action' => 'logout',
]); ?>
<?php endif; ?>

<div class="main-shell">
    <h1 class="page-title">OQC 홀딩리스트</h1>

    <div class="folder-tabs" role="tablist" aria-label="OQC 홀딩리스트 모델 탭">
        <?php foreach ($holdingTabs as $key => $cfg): ?>
            <?php
            $query = ['tab' => $key];
            if ($EMBED) $query['embed'] = '1';
            $href = '?' . http_build_query($query);
            ?>
            <a class="folder-tab <?= $key === $activeTab ? 'active' : '' ?>"
               href="<?= h($href) ?>"
               role="tab"
               aria-selected="<?= $key === $activeTab ? 'true' : 'false' ?>">
                <?= h($cfg['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="viewer-card">
        <div class="viewer-surface">
            <?php if ($state['empty_message'] !== ''): ?>
                <div class="empty-box"><?= h($state['empty_message']) ?></div>
            <?php else: ?>
                <table class="holding-table">
                    <colgroup>
                        <col class="c-product">
                        <col class="c-lot">
                        <col class="c-tool">
                        <col class="c-cav">
                        <col class="c-oqc">
                        <col class="c-oqc">
                        <col class="c-oqc">
                        <col class="c-oqc">
                        <col class="c-oqc">
                        <col class="c-oqc">
                        <col class="c-qty">
                    </colgroup>
                    <thead>
                        <tr>
                            <th rowspan="2">제품명</th>
                            <th rowspan="2">Lot</th>
                            <th rowspan="2">Tool</th>
                            <th rowspan="2">Cav</th>
                            <th colspan="6" class="oqc-span">OQC</th>
                            <th rowspan="2">격리<br>수량</th>
                        </tr>
                        <tr>
                            <th>FAI</th>
                            <th>USL</th>
                            <th>측정값</th>
                            <th>FAI</th>
                            <th>LSL</th>
                            <th>측정값</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($state['rows'] as $row): ?>
                            <tr>
                                <td class="product"><?= h($row['product_name']) ?></td>
                                <td class="lot"><?= h($row['lot_label']) ?></td>
                                <td class="tool"><?= h($row['tool'] ?? '') ?></td>
                                <td class="cav"><?= h($row['cav'] ?? '') ?></td>

                                <?php if (($row['state'] ?? '') === 'ok'): ?>
                                    <td colspan="6" class="state-cell state-ok">OK</td>
                                <?php elseif (($row['state'] ?? '') === 'idle'): ?>
                                    <td colspan="6" class="state-cell state-idle">비가동</td>
                                <?php else: ?>
                                    <?php if (($row['side'] ?? '') === 'USL'): ?>
                                        <td class="ng-cell"><?= h($row['fai'] ?? '') ?></td>
                                        <td class="ng-cell"><?= h($row['usl'] ?? '') ?></td>
                                        <td class="ng-cell"><?= h($row['measured'] ?? '') ?></td>
                                        <td class="blank-oqc"></td>
                                        <td class="blank-oqc"></td>
                                        <td class="blank-oqc"></td>
                                    <?php else: ?>
                                        <td class="blank-oqc"></td>
                                        <td class="blank-oqc"></td>
                                        <td class="blank-oqc"></td>
                                        <td class="ng-cell"><?= h($row['fai'] ?? '') ?></td>
                                        <td class="ng-cell"><?= h($row['lsl'] ?? '') ?></td>
                                        <td class="ng-cell"><?= h($row['measured'] ?? '') ?></td>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <td class="qty"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$EMBED): ?>
<?php
$__mb = @include JTMES_ROOT . '/config/matrix_bg.php';
if (!is_array($__mb)) {
    $__mb = ['enabled'=>true,'text'=>'01','speed'=>1.15,'size'=>16,'zIndex'=>0,'scanlines'=>true,'vignette'=>true];
}
?>
<script>
window.MATRIX_BG = <?php echo json_encode($__mb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/matrix-bg.js"></script>
</div>
<?php endif; ?>
</body>
</html>
