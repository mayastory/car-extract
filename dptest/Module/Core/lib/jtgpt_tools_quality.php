<?php
if (!function_exists('jtgpt_tool_quality_norm_token')) {
    function jtgpt_tool_quality_norm_token(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $value = preg_replace('/[^A-Z0-9가-힣]+/u', '', $value);
        return $value ?? '';
    }
}

if (!function_exists('jtgpt_tool_quality_pick_range')) {
    function jtgpt_tool_quality_pick_range(array $args): array
    {
        $from = trim((string)($args['from'] ?? ''));
        $to   = trim((string)($args['to'] ?? ''));
        $isDate = static function (string $v): bool {
            return $v !== '' && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        };
        if ($isDate($from) && $isDate($to)) {
            return [$from, $to];
        }
        $today = new DateTimeImmutable('today');
        if ($isDate($to) && !$isDate($from)) {
            $from = (new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
            return [$from, $to];
        }
        if ($isDate($from) && !$isDate($to)) {
            $to = (new DateTimeImmutable($from))->modify('+6 days')->format('Y-m-d');
            return [$from, $to];
        }
        return [$today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')];
    }
}

if (!function_exists('jtgpt_tool_oqc_schema')) {
    function jtgpt_tool_oqc_schema(PDO $pdo): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $headerCols = [];
        foreach (['oqc_header', 'oqc_result_header'] as $table) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $headerCols[$table][strtolower((string)$r['Field'])] = true;
                }
            } catch (Throwable $e) {
                $headerCols[$table] = [];
            }
        }
        $cache = [
            'date_col'  => isset($headerCols['oqc_header']['ship_date']) ? 'ship_date' : 'lot_date',
            'has_kind'  => isset($headerCols['oqc_header']['kind']),
            'has_tc'    => isset($headerCols['oqc_header']['tool_cavity']),
        ];
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_oqc_base_where')) {
    function jtgpt_tool_oqc_base_where(PDO $pdo, array $args, bool $ignorePart = false): array
    {
        $schema = jtgpt_tool_oqc_schema($pdo);
        $dateCol = $schema['date_col'];
        [$from, $to] = jtgpt_tool_quality_pick_range($args);

        $where = ['r.result_ok = 0'];
        $params = [
            ':from_d' => $from,
            ':to_d'   => $to,
        ];
        $where[] = "h.`{$dateCol}` >= :from_d";
        $where[] = "h.`{$dateCol}` <= :to_d";

        $partName = trim((string)($args['part_name'] ?? ''));
        if (!$ignorePart && $partName !== '') {
            $norm = jtgpt_tool_quality_norm_token($partName);
            if ($norm !== '') {
                $where[] = "REPLACE(REPLACE(REPLACE(UPPER(h.part_name), '-', ''), '_', ''), ' ', '') LIKE :part_name_norm";
                $params[':part_name_norm'] = '%' . $norm . '%';
            }
        }

        $pointNo = trim((string)($args['point_no'] ?? ''));
        if ($pointNo !== '') {
            $where[] = 'r.point_no = :point_no';
            $params[':point_no'] = $pointNo;
        }

        $tool = trim((string)($args['tool'] ?? ''));
        if ($tool !== '' && $schema['has_tc']) {
            $normTool = jtgpt_tool_quality_norm_token($tool);
            if ($normTool !== '') {
                $where[] = "REPLACE(REPLACE(REPLACE(UPPER(h.tool_cavity), '-', ''), '_', ''), ' ', '') LIKE :tool_norm";
                $params[':tool_norm'] = $normTool . '%';
            }
        }

        $cavity = trim((string)($args['cavity'] ?? ''));
        if ($cavity !== '' && $schema['has_tc']) {
            $normCavity = jtgpt_tool_quality_norm_token($cavity);
            if ($normCavity !== '') {
                if (!str_contains($normCavity, 'CAV')) {
                    $normCavity .= 'CAV';
                }
                $where[] = "REPLACE(REPLACE(REPLACE(UPPER(h.tool_cavity), '-', ''), '_', ''), ' ', '') LIKE :cavity_norm";
                $params[':cavity_norm'] = '%' . $normCavity . '%';
            }
        }

        return [
            'schema' => $schema,
            'from' => $from,
            'to' => $to,
            'sql' => 'WHERE ' . implode(' AND ', $where),
            'params' => $params,
            'used_part_filter' => (!$ignorePart && $partName !== ''),
        ];
    }
}

if (!function_exists('jtgpt_tool_oqc_top_ng_points')) {
    function jtgpt_tool_oqc_top_ng_points(PDO $pdo, array $args): array
    {
        $limit = (int)($args['limit'] ?? 50);
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;

        $run = static function (array $base) use ($pdo, $limit): array {
            $dateCol = $base['schema']['date_col'];
            $sql = "
                SELECT
                    r.point_no,
                    COUNT(*) AS ng_count,
                    COUNT(DISTINCT r.header_id) AS header_count,
                    MAX(h.`{$dateCol}`) AS last_ship_date,
                    GROUP_CONCAT(DISTINCT h.part_name ORDER BY h.part_name SEPARATOR ', ') AS parts
                FROM oqc_result_header r
                JOIN oqc_header h ON h.id = r.header_id
                {$base['sql']}
                GROUP BY r.point_no
                ORDER BY last_ship_date DESC, ng_count DESC, r.point_no ASC
                LIMIT {$limit}
            ";
            $st = $pdo->prepare($sql);
            $st->execute($base['params']);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        };

        $base = jtgpt_tool_oqc_base_where($pdo, $args, false);
        $rows = $run($base);
        $fellBack = false;
        if (empty($rows) && !empty($args['part_name'])) {
            $base = jtgpt_tool_oqc_base_where($pdo, $args, true);
            $rows = $run($base);
            $fellBack = !empty($rows);
        }

        return [
            'found' => !empty($rows),
            'rows' => $rows,
            'from' => $base['from'],
            'to' => $base['to'],
            'fallback_broad_recent' => $fellBack,
            'message' => $fellBack ? '정확한 모델명이 애매해서 최근 7일 OQC NG 포인트 전체 기준으로 보여줬습니다.' : null,
        ];
    }
}

if (!function_exists('jtgpt_tool_oqc_point_detail')) {
    function jtgpt_tool_oqc_point_detail(PDO $pdo, array $args): array
    {
        $run = static function (array $base) use ($pdo): array {
            $dateCol = $base['schema']['date_col'];
            $sqlSummary = "
                SELECT
                    r.point_no,
                    COUNT(*) AS ng_count,
                    COUNT(DISTINCT r.header_id) AS header_count,
                    COUNT(DISTINCT h.part_name) AS part_count,
                    MAX(h.`{$dateCol}`) AS last_ship_date
                FROM oqc_result_header r
                JOIN oqc_header h ON h.id = r.header_id
                {$base['sql']}
                GROUP BY r.point_no
                LIMIT 1
            ";
            $st = $pdo->prepare($sqlSummary);
            $st->execute($base['params']);
            $summary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$summary) {
                return ['summary' => null, 'latest' => []];
            }
            $sqlLatest = "
                SELECT
                    h.part_name,
                    h.tool_cavity,
                    " . ($base['schema']['has_kind'] ? "h.kind" : "''") . " AS kind,
                    h.`{$dateCol}` AS ship_date,
                    r.point_no
                FROM oqc_result_header r
                JOIN oqc_header h ON h.id = r.header_id
                {$base['sql']}
                ORDER BY h.`{$dateCol}` DESC, h.id DESC
                LIMIT 10
            ";
            $st = $pdo->prepare($sqlLatest);
            $st->execute($base['params']);
            return [
                'summary' => $summary,
                'latest' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ];
        };

        $base = jtgpt_tool_oqc_base_where($pdo, $args, false);
        $data = $run($base);
        $fellBack = false;
        if (!$data['summary'] && !empty($args['part_name'])) {
            $base = jtgpt_tool_oqc_base_where($pdo, $args, true);
            $data = $run($base);
            $fellBack = (bool)$data['summary'];
        }

        return [
            'found' => (bool)$data['summary'],
            'summary' => $data['summary'],
            'latest_rows' => $data['latest'],
            'from' => $base['from'],
            'to' => $base['to'],
            'fallback_broad_recent' => $fellBack,
            'message' => $fellBack ? '정확한 모델명이 애매해서 최근 7일 OQC NG 기준으로 다시 찾았습니다.' : null,
        ];
    }
}
