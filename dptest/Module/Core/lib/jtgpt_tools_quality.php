<?php
if (!function_exists('jtgpt_tool_oqc_schema')) {
    function jtgpt_tool_oqc_schema(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $headerCols = [];
        $resCols = [];
        foreach ([['oqc_header', &$headerCols], ['oqc_result_header', &$resCols]] as $ent) {
            [$table, &$target] = $ent;
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $target[strtolower((string)$r['Field'])] = true;
            }
        }
        $cache = [
            'date_col' => isset($headerCols['ship_date']) ? 'ship_date' : 'lot_date',
            'has_kind' => isset($headerCols['kind']),
        ];
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_oqc_base_where')) {
    function jtgpt_tool_oqc_base_where(PDO $pdo, array $args): array {
        $schema = jtgpt_tool_oqc_schema($pdo);
        $dateCol = $schema['date_col'];
        $where = ['r.result_ok = 0'];
        $params = [];
        if (!empty($args['from']) && !empty($args['to'])) {
            $where[] = "h.`{$dateCol}` >= :from_d";
            $where[] = "h.`{$dateCol}` <= :to_d";
            $params[':from_d'] = (string)$args['from'];
            $params[':to_d'] = (string)$args['to'];
        }
        $partName = trim((string)($args['part_name'] ?? ''));
        if ($partName !== '') {
            $where[] = 'h.part_name LIKE :part_name';
            $params[':part_name'] = '%' . $partName . '%';
        }
        $pointNo = trim((string)($args['point_no'] ?? ''));
        if ($pointNo !== '') {
            $where[] = 'r.point_no = :point_no';
            $params[':point_no'] = $pointNo;
        }
        return [
            'schema' => $schema,
            'sql' => 'WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }
}

if (!function_exists('jtgpt_tool_oqc_top_ng_points')) {
    function jtgpt_tool_oqc_top_ng_points(PDO $pdo, array $args): array {
        $base = jtgpt_tool_oqc_base_where($pdo, $args);
        $dateCol = $base['schema']['date_col'];
        $sql = "
            SELECT
                r.point_no,
                COUNT(*) AS ng_count,
                COUNT(DISTINCT r.header_id) AS header_count,
                MAX(h.`{$dateCol}`) AS last_ship_date
            FROM oqc_result_header r
            JOIN oqc_header h ON h.id = r.header_id
            {$base['sql']}
            GROUP BY r.point_no
            ORDER BY ng_count DESC, r.point_no ASC
            LIMIT 5
        ";
        $st = $pdo->prepare($sql);
        $st->execute($base['params']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'found' => !empty($rows),
            'rows' => $rows,
        ];
    }
}

if (!function_exists('jtgpt_tool_oqc_point_detail')) {
    function jtgpt_tool_oqc_point_detail(PDO $pdo, array $args): array {
        $base = jtgpt_tool_oqc_base_where($pdo, $args);
        $dateCol = $base['schema']['date_col'];
        $sql = "
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
        $st = $pdo->prepare($sql);
        $st->execute($base['params']);
        $summary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$summary) {
            return ['found' => false, 'summary' => null, 'latest_rows' => []];
        }
        $sqlLatest = "
            SELECT h.part_name, h.tool_cavity, " . ($base['schema']['has_kind'] ? "h.kind, " : "'' AS kind, ") . " h.`{$dateCol}` AS ship_date
            FROM oqc_result_header r
            JOIN oqc_header h ON h.id = r.header_id
            {$base['sql']}
            ORDER BY h.`{$dateCol}` DESC, h.id DESC
            LIMIT 5
        ";
        $st = $pdo->prepare($sqlLatest);
        $st->execute($base['params']);
        $latest = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'found' => true,
            'summary' => $summary,
            'latest_rows' => $latest,
        ];
    }
}
