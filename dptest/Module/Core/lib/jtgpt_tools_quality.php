<?php
if (!function_exists('jtgpt_quality_module_def')) {
    function jtgpt_quality_module_def(string $key): ?array {
        $key = strtolower(trim($key));
        $map = [
            'oqc' => ['label' => 'OQC', 'header' => 'oqc_header', 'result' => 'oqc_result_header', 'measurement' => 'oqc_measurements'],
            'omm' => ['label' => 'OMM', 'header' => 'ipqc_omm_header', 'result' => 'ipqc_omm_result', 'measurement' => 'ipqc_omm_measurements'],
            'aoi' => ['label' => 'AOI', 'header' => 'ipqc_aoi_header', 'result' => 'ipqc_aoi_result', 'measurement' => 'ipqc_aoi_measurements'],
            'cmm' => ['label' => 'CMM', 'header' => 'ipqc_cmm_header', 'result' => 'ipqc_cmm_result', 'measurement' => 'ipqc_cmm_measurements'],
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
        $measurementCols = !empty($def['measurement']) ? jtgpt_quality_columns($pdo, $def['measurement']) : [];

        if (!$headerCols || !$resultCols) {
            return $cache[$module] = ['available' => false, 'label' => $def['label'], 'error' => '관련 테이블을 찾지 못했습니다.'];
        }

        $dateCandidates = ($module === 'oqc')
            ? ['ship_date','lot_date','meas_date','meas_date2','jmeas_date','jmeas_date2','date','created_at','reg_date','inspection_date']
            : ['meas_date','date','created_at','reg_date','inspection_date','ship_date','lot_date'];

        $schema = [
            'available' => true,
            'module' => $module,
            'label' => $def['label'],
            'header_table' => $def['header'],
            'result_table' => $def['result'],
            'measurement_table' => $def['measurement'],
            'header_id_col' => jtgpt_quality_first_col($resultCols, ['header_id','header_idx','parent_id']),
            'header_pk_col' => jtgpt_quality_first_col($headerCols, ['id','idx','header_id']),
            'date_col' => jtgpt_quality_first_col($headerCols, $dateCandidates),
            'part_col' => jtgpt_quality_first_col($headerCols, ['part_name','model_name','part','model']),
            'kind_col' => jtgpt_quality_first_col($headerCols, ['kind','source_type','category']),
            'tool_col' => jtgpt_quality_first_col($headerCols, ['tool']),
            'cavity_col' => jtgpt_quality_first_col($headerCols, ['cavity']),
            'tool_cavity_col' => jtgpt_quality_first_col($headerCols, ['tool_cavity','tc']),
            'result_point_col' => jtgpt_quality_first_col($resultCols, ['point_no','point_code','point','fai']),
            'result_value_col' => jtgpt_quality_first_col($resultCols, ['value','meas_value','measured_value','val','result_value']),
            'usl_col' => jtgpt_quality_first_col($resultCols, ['usl','upper_limit']),
            'lsl_col' => jtgpt_quality_first_col($resultCols, ['lsl','lower_limit']),
            'measurement_point_col' => jtgpt_quality_first_col($measurementCols, ['point_no','point_code','point','fai']),
            'measurement_value_col' => jtgpt_quality_first_col($measurementCols, ['value','meas_value','measured_value','val','result_value']),
            'measurement_header_id_col' => jtgpt_quality_first_col($measurementCols, ['header_id','header_idx','parent_id']),
        ];

        $schema['ng_predicate'] = null;
        if (isset($resultCols['result_ok'])) $schema['ng_predicate'] = 'r.result_ok = 0';
        elseif (isset($resultCols['is_ng'])) $schema['ng_predicate'] = 'r.is_ng = 1';
        elseif (isset($resultCols['ng'])) $schema['ng_predicate'] = 'r.ng = 1';
        elseif (isset($resultCols['result'])) $schema['ng_predicate'] = "UPPER(COALESCE(r.result,'')) = 'NG'";
        elseif (isset($resultCols['judgement'])) $schema['ng_predicate'] = "UPPER(COALESCE(r.judgement,'')) = 'NG'";

        if (!$schema['header_id_col'] || !$schema['header_pk_col'] || !$schema['date_col'] || !$schema['result_point_col']) {
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

if (!function_exists('jtgpt_quality_norm_token')) {
    function jtgpt_quality_norm_token(?string $value): string {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$value)));
    }
}

if (!function_exists('jtgpt_tool_quality_base_where')) {
    function jtgpt_tool_quality_base_where(PDO $pdo, string $module, array $args): array {
        $schema = jtgpt_quality_module_schema($pdo, $module);
        if (empty($schema['available'])) {
            return ['schema' => $schema, 'ok' => false, 'sql' => '', 'params' => []];
        }

        $where = [];
        $params = [];

        if ($module === 'oqc') {
            $measAlias = (!empty($schema['measurement_table']) && !empty($schema['measurement_header_id_col']) && !empty($schema['measurement_point_col'])) ? 'm' : 'r';
            $valueExpr = ($measAlias === 'm' && !empty($schema['measurement_value_col'])) ? "m.`{$schema['measurement_value_col']}`" : (!empty($schema['result_value_col']) ? "r.`{$schema['result_value_col']}`" : 'NULL');
            $uslExpr = !empty($schema['usl_col']) ? "r.`{$schema['usl_col']}`" : 'NULL';
            $lslExpr = !empty($schema['lsl_col']) ? "r.`{$schema['lsl_col']}`" : 'NULL';
            $directNg = "(($uslExpr IS NOT NULL AND $valueExpr IS NOT NULL AND $valueExpr > $uslExpr) OR ($lslExpr IS NOT NULL AND $valueExpr IS NOT NULL AND $valueExpr < $lslExpr))";
            if (!empty($schema['ng_predicate'])) {
                $where[] = "($directNg OR {$schema['ng_predicate']})";
            } else {
                $where[] = $directNg;
            }
        } else {
            if (!empty($schema['ng_predicate'])) {
                $where[] = $schema['ng_predicate'];
            }
        }

        if (!empty($args['from']) && !empty($args['to'])) {
            $where[] = "h.`{$schema['date_col']}` >= :from_d";
            $where[] = "h.`{$schema['date_col']}` <= :to_d";
            $params[':from_d'] = (string)$args['from'];
            $params[':to_d'] = (string)$args['to'];
        }

        $partName = trim((string)($args['part_name'] ?? ''));
        if ($partName !== '' && !empty($schema['part_col'])) {
            $partNorm = jtgpt_quality_norm_token($partName);
            if ($partNorm !== '') {
                $where[] = "REPLACE(REPLACE(UPPER(h.`{$schema['part_col']}`), '-', ''), ' ', '') LIKE :part_norm";
                $params[':part_norm'] = '%' . $partNorm . '%';
            }
        }

        $ptCol = ($module === 'oqc' && !empty($schema['measurement_point_col'])) ? "COALESCE(UPPER(m.`{$schema['measurement_point_col']}`), UPPER(r.`{$schema['result_point_col']}`))" : "UPPER(r.`{$schema['result_point_col']}`)";
        if (empty($args['include_dc'])) {
            $where[] = $ptCol . " NOT LIKE :dc_like";
            $params[':dc_like'] = '%(DC)%';
        }
        $pointNo = strtoupper(trim((string)($args['point_no'] ?? '')));
        if ($pointNo !== '') {
            $where[] = $ptCol . " = :point_no";
            $params[':point_no'] = $pointNo;
        }

        $tool = jtgpt_quality_normalize_tool($args['tool'] ?? '');
        if ($tool !== '') {
            if (!empty($schema['tool_col'])) {
                $where[] = "UPPER(CAST(h.`{$schema['tool_col']}` AS CHAR)) = :tool";
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
        $st->bindValue(':limit_n', max(1, min(500, $limit)), PDO::PARAM_INT);
    }
}

if (!function_exists('jtgpt_quality_from_clause')) {
    function jtgpt_quality_from_clause(array $schema, string $module): string {
        $join = " FROM `{$schema['result_table']}` r JOIN `{$schema['header_table']}` h ON h.`{$schema['header_pk_col']}` = r.`{$schema['header_id_col']}` ";
        if ($module === 'oqc'
            && !empty($schema['measurement_table'])
            && !empty($schema['measurement_header_id_col'])
            && !empty($schema['measurement_point_col'])
        ) {
            $join .= " LEFT JOIN `{$schema['measurement_table']}` m ON m.`{$schema['measurement_header_id_col']}` = r.`{$schema['header_id_col']}` AND UPPER(m.`{$schema['measurement_point_col']}`) = UPPER(r.`{$schema['result_point_col']}`) ";
        }
        return $join;
    }
}

if (!function_exists('jtgpt_quality_tool_cavity_expr')) {
    function jtgpt_quality_tool_cavity_expr(array $schema): string {
        if (!empty($schema['tool_cavity_col'])) {
            return "h.`{$schema['tool_cavity_col']}`";
        }
        if (!empty($schema['tool_col']) || !empty($schema['cavity_col'])) {
            $toolExpr = !empty($schema['tool_col']) ? "COALESCE(CAST(h.`{$schema['tool_col']}` AS CHAR), '')" : "''";
            $cavityExpr = !empty($schema['cavity_col']) ? "COALESCE(CAST(h.`{$schema['cavity_col']}` AS CHAR), '')" : "''";
            return "TRIM(CONCAT({$toolExpr}, CASE WHEN {$cavityExpr} <> '' THEN '#' ELSE '' END, {$cavityExpr}))";
        }
        return "''";
    }
}

if (!function_exists('jtgpt_quality_value_expr')) {
    function jtgpt_quality_value_expr(array $schema, string $module): string {
        if ($module === 'oqc' && !empty($schema['measurement_value_col'])) {
            return "m.`{$schema['measurement_value_col']}`";
        }
        if (!empty($schema['result_value_col'])) {
            return "r.`{$schema['result_value_col']}`";
        }
        return 'NULL';
    }
}

if (!function_exists('jtgpt_quality_point_expr')) {
    function jtgpt_quality_point_expr(array $schema, string $module): string {
        if ($module === 'oqc' && !empty($schema['measurement_point_col'])) {
            return "COALESCE(m.`{$schema['measurement_point_col']}`, r.`{$schema['result_point_col']}`)";
        }
        return "r.`{$schema['result_point_col']}`";
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
        $pointExpr = jtgpt_quality_point_expr($schema, strtolower($module));
        $fromClause = jtgpt_quality_from_clause($schema, strtolower($module));

        $sql = "SELECT {$pointExpr} AS point_no, COUNT(*) AS ng_count, COUNT(DISTINCT r.`{$schema['header_id_col']}`) AS header_count, MAX(h.`{$schema['date_col']}`) AS last_date {$fromClause} {$base['sql']} GROUP BY {$pointExpr} ORDER BY ng_count DESC, point_no ASC LIMIT :limit_n";
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
        $limit = array_key_exists('limit', $args) && $args['limit'] !== null && $args['limit'] !== ''
            ? (int)$args['limit']
            : null;
        $partExpr = !empty($schema['part_col']) ? "h.`{$schema['part_col']}`" : "''";
        $kindExpr = !empty($schema['kind_col']) ? "h.`{$schema['kind_col']}`" : "''";
        $toolCavityExpr = jtgpt_quality_tool_cavity_expr($schema);
        $pointExpr = jtgpt_quality_point_expr($schema, strtolower($module));
        $valueExpr = jtgpt_quality_value_expr($schema, strtolower($module));
        $uslExpr = !empty($schema['usl_col']) ? "r.`{$schema['usl_col']}`" : 'NULL';
        $lslExpr = !empty($schema['lsl_col']) ? "r.`{$schema['lsl_col']}`" : 'NULL';
        $fromClause = jtgpt_quality_from_clause($schema, strtolower($module));

        $sql = "SELECT h.`{$schema['date_col']}` AS event_date, {$pointExpr} AS point_no, {$partExpr} AS part_name, {$kindExpr} AS kind, {$toolCavityExpr} AS tool_cavity, {$valueExpr} AS value, {$uslExpr} AS usl, {$lslExpr} AS lsl {$fromClause} {$base['sql']} ORDER BY h.`{$schema['date_col']}` DESC, {$pointExpr} ASC, {$toolCavityExpr} ASC, {$partExpr} ASC, h.`{$schema['header_pk_col']}` DESC";
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT :limit_n';
        }
        $st = $pdo->prepare($sql);
        foreach ($base['params'] as $k => $v) $st->bindValue($k, $v);
        if ($limit !== null && $limit > 0) {
            jtgpt_quality_bind_limit($st, $limit);
        }
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
        $pointExpr = jtgpt_quality_point_expr($schema, strtolower($module));
        $fromClause = jtgpt_quality_from_clause($schema, strtolower($module));

        $sql = "SELECT {$pointExpr} AS point_no, COUNT(*) AS ng_count, COUNT(DISTINCT r.`{$schema['header_id_col']}`) AS header_count, MAX(h.`{$schema['date_col']}`) AS last_date {$fromClause} {$base['sql']} GROUP BY {$pointExpr} LIMIT 1";
        $st = $pdo->prepare($sql);
        foreach ($base['params'] as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $summary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$summary) {
            return ['found' => false, 'module' => $schema['module'], 'label' => $schema['label'], 'summary' => null, 'latest_rows' => []];
        }

        $detailArgs = $args;
        if (!array_key_exists('limit', $detailArgs)) {
            $detailArgs['limit'] = null;
        }
        $latest = jtgpt_tool_quality_recent_ng_rows($pdo, $module, $detailArgs);
        return ['found' => true, 'module' => $schema['module'], 'label' => $schema['label'], 'summary' => $summary, 'latest_rows' => $latest['rows'] ?? []];
    }
}

if (!function_exists('jtgpt_tool_oqc_top_ng_points')) {
    function jtgpt_tool_oqc_top_ng_points(PDO $pdo, array $args): array {
        return jtgpt_tool_quality_top_ng_points($pdo, 'oqc', $args);
    }
}

if (!function_exists('jtgpt_tool_oqc_recent_ng_rows')) {
    function jtgpt_tool_oqc_recent_ng_rows(PDO $pdo, array $args): array {
        return jtgpt_tool_quality_recent_ng_rows($pdo, 'oqc', $args);
    }
}

if (!function_exists('jtgpt_tool_oqc_point_detail')) {
    function jtgpt_tool_oqc_point_detail(PDO $pdo, array $args): array {
        return jtgpt_tool_quality_point_detail($pdo, 'oqc', $args);
    }
}
