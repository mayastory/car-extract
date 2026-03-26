<?php
if (!function_exists('jtgpt_tool_format_int')) {
    function jtgpt_tool_format_int($v): string {
        return number_format((int)$v);
    }
}

if (!function_exists('jtgpt_quality_module_def')) {
    function jtgpt_quality_module_def(string $module): ?array {
        $key = strtolower(trim($module));
        $map = [
            'oqc' => ['label' => 'OQC', 'header' => 'oqc_header', 'result' => 'oqc_result_header'],
            'omm' => ['label' => 'OMM', 'header' => 'ipqc_omm_header', 'result' => 'ipqc_omm_result'],
            'aoi' => ['label' => 'AOI', 'header' => 'ipqc_aoi_header', 'result' => 'ipqc_aoi_result'],
            'cmm' => ['label' => 'CMM', 'header' => 'ipqc_cmm_header', 'result' => 'ipqc_cmm_result'],
        ];
        return $map[$key] ?? null;
    }
}

if (!function_exists('jtgpt_quality_table_exists')) {
    function jtgpt_quality_table_exists(PDO $pdo, string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) return $cache[$table];
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE ?');
            $st->execute([$table]);
            $cache[$table] = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('jtgpt_quality_columns')) {
    function jtgpt_quality_columns(PDO $pdo, string $table): array {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        $cols = [];
        if (!jtgpt_quality_table_exists($pdo, $table)) {
            $cache[$table] = $cols;
            return $cols;
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $cols[strtolower((string)$r['Field'])] = true;
        }
        $cache[$table] = $cols;
        return $cols;
    }
}

if (!function_exists('jtgpt_quality_first_col')) {
    function jtgpt_quality_first_col(array $cols, array $candidates): ?string {
        foreach ($candidates as $name) {
            if (isset($cols[strtolower($name)])) return $name;
        }
        return null;
    }
}

if (!function_exists('jtgpt_quality_module_schema')) {
    function jtgpt_quality_module_schema(PDO $pdo, string $module): array {
        static $cache = [];
        $module = strtolower(trim($module));
        if (isset($cache[$module])) return $cache[$module];
        $def = jtgpt_quality_module_def($module);
        if (!$def) {
            return $cache[$module] = ['available' => false, 'label' => strtoupper($module), 'error' => '지원하지 않는 종류입니다.'];
        }
        $headerCols = jtgpt_quality_columns($pdo, $def['header']);
        $resultCols = jtgpt_quality_columns($pdo, $def['result']);
        if (!$headerCols || !$resultCols) {
            return $cache[$module] = ['available' => false, 'label' => $def['label'], 'error' => '관련 테이블을 찾지 못했습니다.'];
        }
        $schema = [
            'available' => true,
            'module' => $module,
            'label' => $def['label'],
            'header_table' => $def['header'],
            'result_table' => $def['result'],
            'header_id_col' => jtgpt_quality_first_col($resultCols, ['header_id','header_idx','parent_id']),
            'header_pk_col' => jtgpt_quality_first_col($headerCols, ['id','idx','header_id']),
            'date_col' => jtgpt_quality_first_col($headerCols, ['meas_date','ship_date','lot_date','date','created_at','reg_date','inspection_date']),
            'part_col' => jtgpt_quality_first_col($headerCols, ['part_name','model_name','part','model']),
            'kind_col' => jtgpt_quality_first_col($headerCols, ['kind','source_type','category']),
            'tool_col' => jtgpt_quality_first_col($headerCols, ['tool']),
            'cavity_col' => jtgpt_quality_first_col($headerCols, ['cavity']),
            'tool_cavity_col' => jtgpt_quality_first_col($headerCols, ['tool_cavity','tc']),
            'point_col' => jtgpt_quality_first_col($resultCols, ['point_no','point_code','point','fai']),
            'value_col' => jtgpt_quality_first_col($resultCols, ['value','meas_value','measured_value','val','result_value']),
            'usl_col' => jtgpt_quality_first_col($resultCols, ['usl','upper_limit']),
            'lsl_col' => jtgpt_quality_first_col($resultCols, ['lsl','lower_limit']),
        ];
        $schema['ng_predicate'] = null;
        if (isset($resultCols['result_ok'])) $schema['ng_predicate'] = 'r.result_ok = 0';
        elseif (isset($resultCols['is_ng'])) $schema['ng_predicate'] = 'r.is_ng = 1';
        elseif (isset($resultCols['ng'])) $schema['ng_predicate'] = 'r.ng = 1';
        elseif (isset($resultCols['result'])) $schema['ng_predicate'] = "UPPER(COALESCE(r.result,'')) = 'NG'";
        elseif (isset($resultCols['judgement'])) $schema['ng_predicate'] = "UPPER(COALESCE(r.judgement,'')) = 'NG'";

        if (!$schema['header_id_col'] || !$schema['header_pk_col'] || !$schema['date_col'] || !$schema['point_col'] || !$schema['ng_predicate']) {
            $schema['available'] = false;
            $schema['error'] = '필수 컬럼 구조를 자동 인식하지 못했습니다.';
        }
        return $cache[$module] = $schema;
    }
}

if (!function_exists('jtgpt_quality_normalize_tool')) {
    function jtgpt_quality_normalize_tool(?string $tool): string {
        return strtoupper(trim((string)$tool));
    }
}

if (!function_exists('jtgpt_quality_normalize_cavity')) {
    function jtgpt_quality_normalize_cavity(?string $cavity): string {
        $cavity = strtoupper(trim((string)$cavity));
        if ($cavity === '') return '';
        if (preg_match('/^(\d{1,2})$/', $cavity, $m)) return ((int)$m[1]) . 'CAV';
        if (preg_match('/^(\d{1,2})\s*CAV$/', $cavity, $m)) return ((int)$m[1]) . 'CAV';
        return $cavity;
    }
}

if (!function_exists('jtgpt_tool_quality_base_where')) {
    function jtgpt_tool_quality_base_where(PDO $pdo, string $module, array $args): array {
        $schema = jtgpt_quality_module_schema($pdo, $module);
        if (empty($schema['available'])) {
            return ['schema' => $schema, 'ok' => false, 'sql' => '', 'params' => []];
        }
        $where = [$schema['ng_predicate']];
        $params = [];

        if (!empty($args['from']) && !empty($args['to'])) {
            $where[] = "h.`{$schema['date_col']}` >= :from_d";
            $where[] = "h.`{$schema['date_col']}` <= :to_d";
            $params[':from_d'] = (string)$args['from'];
            $params[':to_d'] = (string)$args['to'];
        }

        $partName = trim((string)($args['part_name'] ?? ''));
        if ($partName !== '' && !empty($schema['part_col'])) {
            $where[] = "h.`{$schema['part_col']}` LIKE :part_name";
            $params[':part_name'] = '%' . $partName . '%';
        }

        $pointNo = strtoupper(trim((string)($args['point_no'] ?? '')));
        if ($pointNo !== '') {
            $where[] = "UPPER(r.`{$schema['point_col']}`) = :point_no";
            $params[':point_no'] = $pointNo;
        }

        $tool = jtgpt_quality_normalize_tool($args['tool'] ?? '');
        if ($tool !== '') {
            if (!empty($schema['tool_col'])) {
                $where[] = "UPPER(h.`{$schema['tool_col']}`) = :tool";
                $params[':tool'] = $tool;
            } elseif (!empty($schema['tool_cavity_col'])) {
                $where[] = "UPPER(h.`{$schema['tool_cavity_col']}`) LIKE :tool_like";
                $params[':tool_like'] = '%' . $tool . '%';
            }
        }

        $cavity = jtgpt_quality_normalize_cavity($args['cavity'] ?? '');
        if ($cavity !== '') {
            if (!empty($schema['cavity_col'])) {
                $where[] = "REPLACE(UPPER(CAST(h.`{$schema['cavity_col']}` AS CHAR)), ' ', '') IN (:cavity_raw, :cavity_num)";
                $params[':cavity_raw'] = $cavity;
                $params[':cavity_num'] = (string)((int)$cavity);
            } elseif (!empty($schema['tool_cavity_col'])) {
                $where[] = "UPPER(h.`{$schema['tool_cavity_col']}`) LIKE :cavity_like";
                $params[':cavity_like'] = '%' . $cavity . '%';
            }
        }

        return [
            'schema' => $schema,
            'ok' => true,
            'sql' => 'WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }
}

if (!function_exists('jtgpt_quality_bind_limit')) {
    function jtgpt_quality_bind_limit(PDOStatement $st, int $limit): void {
        $st->bindValue(':limit_n', max(1, min(50, $limit)), PDO::PARAM_INT);
    }
}

if (!function_exists('jtgpt_tool_quality_top_ng_points')) {
    function jtgpt_tool_quality_top_ng_points(PDO $pdo, string $module, array $args): array {
        $base = jtgpt_tool_quality_base_where($pdo, $module, $args);
        if (empty($base['ok'])) {
            return ['found' => false, 'module' => strtolower($module), 'error' => $base['schema']['error'] ?? '조회 준비 실패', 'rows' => []];
        }
        $schema = $base['schema'];
        $limit = (int)($args['limit'] ?? 5);
        $sql = "
            SELECT
                r.`{$schema['point_col']}` AS point_no,
                COUNT(*) AS ng_count,
                COUNT(DISTINCT r.`{$schema['header_id_col']}`) AS header_count,
                MAX(h.`{$schema['date_col']}`) AS last_date
            FROM `{$schema['result_table']}` r
            JOIN `{$schema['header_table']}` h ON h.`{$schema['header_pk_col']}` = r.`{$schema['header_id_col']}`
            {$base['sql']}
            GROUP BY r.`{$schema['point_col']}`
            ORDER BY ng_count DESC, point_no ASC
            LIMIT :limit_n
        ";
        $st = $pdo->prepare($sql);
        foreach ($base['params'] as $k => $v) $st->bindValue($k, $v);
        jtgpt_quality_bind_limit($st, $limit);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['found' => !empty($rows), 'module' => $schema['module'], 'label' => $schema['label'], 'rows' => $rows];
    }
}

if (!function_exists('jtgpt_tool_quality_recent_ng_rows')) {
    function jtgpt_tool_quality_recent_ng_rows(PDO $pdo, string $module, array $args): array {
        $base = jtgpt_tool_quality_base_where($pdo, $module, $args);
        if (empty($base['ok'])) {
            return ['found' => false, 'module' => strtolower($module), 'error' => $base['schema']['error'] ?? '조회 준비 실패', 'rows' => []];
        }
        $schema = $base['schema'];
        $limit = (int)($args['limit'] ?? 10);
        $partExpr = !empty($schema['part_col']) ? "h.`{$schema['part_col']}`" : "''";
        $kindExpr = !empty($schema['kind_col']) ? "h.`{$schema['kind_col']}`" : "''";
        if (!empty($schema['tool_cavity_col'])) {
            $toolCavityExpr = "h.`{$schema['tool_cavity_col']}`";
        } elseif (!empty($schema['tool_col']) || !empty($schema['cavity_col'])) {
            $toolExpr = !empty($schema['tool_col']) ? "COALESCE(CAST(h.`{$schema['tool_col']}` AS CHAR), '')" : "''";
            $cavityExpr = !empty($schema['cavity_col']) ? "COALESCE(CAST(h.`{$schema['cavity_col']}` AS CHAR), '')" : "''";
            $toolCavityExpr = "TRIM(CONCAT({$toolExpr}, CASE WHEN {$cavityExpr} <> '' THEN '#' ELSE '' END, {$cavityExpr}))";
        } else {
            $toolCavityExpr = "''";
        }
        $valueExpr = !empty($schema['value_col']) ? "r.`{$schema['value_col']}`" : 'NULL';
        $uslExpr = !empty($schema['usl_col']) ? "r.`{$schema['usl_col']}`" : 'NULL';
        $lslExpr = !empty($schema['lsl_col']) ? "r.`{$schema['lsl_col']}`" : 'NULL';
        $sql = "
            SELECT
                h.`{$schema['date_col']}` AS event_date,
                r.`{$schema['point_col']}` AS point_no,
                {$partExpr} AS part_name,
                {$kindExpr} AS kind,
                {$toolCavityExpr} AS tool_cavity,
                {$valueExpr} AS value,
                {$uslExpr} AS usl,
                {$lslExpr} AS lsl
            FROM `{$schema['result_table']}` r
            JOIN `{$schema['header_table']}` h ON h.`{$schema['header_pk_col']}` = r.`{$schema['header_id_col']}`
            {$base['sql']}
            ORDER BY h.`{$schema['date_col']}` DESC, h.`{$schema['header_pk_col']}` DESC
            LIMIT :limit_n
        ";
        $st = $pdo->prepare($sql);
        foreach ($base['params'] as $k => $v) $st->bindValue($k, $v);
        jtgpt_quality_bind_limit($st, $limit);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['found' => !empty($rows), 'module' => $schema['module'], 'label' => $schema['label'], 'rows' => $rows];
    }
}

if (!function_exists('jtgpt_tool_quality_point_detail')) {
    function jtgpt_tool_quality_point_detail(PDO $pdo, string $module, array $args): array {
        $base = jtgpt_tool_quality_base_where($pdo, $module, $args);
        if (empty($base['ok'])) {
            return ['found' => false, 'module' => strtolower($module), 'error' => $base['schema']['error'] ?? '조회 준비 실패', 'summary' => null, 'latest_rows' => []];
        }
        $schema = $base['schema'];
        $sql = "
            SELECT
                r.`{$schema['point_col']}` AS point_no,
                COUNT(*) AS ng_count,
                COUNT(DISTINCT r.`{$schema['header_id_col']}`) AS header_count,
                MAX(h.`{$schema['date_col']}`) AS last_date
            FROM `{$schema['result_table']}` r
            JOIN `{$schema['header_table']}` h ON h.`{$schema['header_pk_col']}` = r.`{$schema['header_id_col']}`
            {$base['sql']}
            GROUP BY r.`{$schema['point_col']}`
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute($base['params']);
        $summary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$summary) {
            return ['found' => false, 'module' => $schema['module'], 'label' => $schema['label'], 'summary' => null, 'latest_rows' => []];
        }
        $latest = jtgpt_tool_quality_recent_ng_rows($pdo, $module, array_merge($args, ['limit' => max(5, (int)($args['limit'] ?? 5))]));
        return ['found' => true, 'module' => $schema['module'], 'label' => $schema['label'], 'summary' => $summary, 'latest_rows' => $latest['rows'] ?? []];
    }
}

if (!function_exists('jtgpt_tool_oqc_top_ng_points')) {
    function jtgpt_tool_oqc_top_ng_points(PDO $pdo, array $args): array {
        return jtgpt_tool_quality_top_ng_points($pdo, 'oqc', $args);
    }
}

if (!function_exists('jtgpt_tool_oqc_point_detail')) {
    function jtgpt_tool_oqc_point_detail(PDO $pdo, array $args): array {
        return jtgpt_tool_quality_point_detail($pdo, 'oqc', $args);
    }
}
