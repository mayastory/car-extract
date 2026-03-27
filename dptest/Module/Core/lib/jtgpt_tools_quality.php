<?php

if (!function_exists('jtgpt_tool_format_float')) {
    function jtgpt_tool_format_float($v): string {
        if ($v === null || $v === '') return '';
        $num = (float)$v;
        $txt = number_format($num, 4, '.', '');
        return rtrim(rtrim($txt, '0'), '.');
    }
}

if (!function_exists('jtgpt_quality_module_def')) {
    function jtgpt_quality_module_def(string $module): ?array {
        static $map = [
            'oqc' => ['label' => 'OQC', 'header' => 'oqc_header', 'result' => 'oqc_result_header', 'measurement' => 'oqc_measurements'],
            'omm' => ['label' => 'OMM', 'header' => 'ipqc_omm_header', 'result' => 'ipqc_omm_result', 'measurement' => 'ipqc_omm_measurements'],
            'aoi' => ['label' => 'AOI', 'header' => 'ipqc_aoi_header', 'result' => 'ipqc_aoi_result', 'measurement' => 'ipqc_aoi_measurements'],
            'cmm' => ['label' => 'CMM', 'header' => 'ipqc_cmm_header', 'result' => 'ipqc_cmm_result', 'measurement' => 'ipqc_cmm_measurements'],
        ];
        $key = strtolower(trim($module));
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
            return $cache[$module] = [
                'available' => false,
                'label' => strtoupper($module),
                'error' => '지원하지 않는 종류입니다.',
            ];
        }

        $headerCols = jtgpt_quality_columns($pdo, $def['header']);
        $resultCols = jtgpt_quality_columns($pdo, $def['result']);
        $measurementCols = !empty($def['measurement']) ? jtgpt_quality_columns($pdo, $def['measurement']) : [];

        if (!$headerCols || !$resultCols) {
            return $cache[$module] = [
                'available' => false,
                'label' => $def['label'],
                'error' => '관련 테이블을 찾지 못했습니다.',
            ];
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
            'result_value_col' => jtgpt_quality_first_col($resultCols, ['value','meas_value','measured_value','val','result_value','max_val']),
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

if (!function_exists('jtgpt_quality_unique_list')) {
    function jtgpt_quality_unique_list(array $values): array {
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

if (!function_exists('jtgpt_quality_collect_values')) {
    function jtgpt_quality_collect_values(array $args, string $arrayKey, string $singleKey, callable $normalizer): array {
        $values = [];
        $list = $args[$arrayKey] ?? [];
        if (!is_array($list)) {
            $list = [$list];
        }
        foreach ($list as $value) {
            $normalized = $normalizer($value);
            if ($normalized !== '') {
                $values[] = $normalized;
            }
        }
        $single = $normalizer($args[$singleKey] ?? '');
        if ($single !== '') {
            $values[] = $single;
        }
        return jtgpt_quality_unique_list($values);
    }
}

if (!function_exists('jtgpt_quality_named_placeholders')) {
    function jtgpt_quality_named_placeholders(string $prefix, array $values, array &$params): array {
        $placeholders = [];
        foreach (array_values($values) as $i => $value) {
            $name = ':' . $prefix . '_' . $i;
            $placeholders[] = $name;
            $params[$name] = $value;
        }
        return $placeholders;
    }
}

if (!function_exists('jtgpt_quality_from_clause')) {
    function jtgpt_quality_from_clause(array $schema, string $module): string {
        $join = " FROM `{$schema['result_table']}` r JOIN `{$schema['header_table']}` h ON h.`{$schema['header_pk_col']}` = r.`{$schema['header_id_col']}` ";
        if ($module === 'oqc' && !empty($schema['measurement_table']) && !empty($schema['measurement_header_id_col']) && !empty($schema['measurement_point_col'])) {
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

if (!function_exists('jtgpt_tool_quality_base_where')) {
    function jtgpt_tool_quality_base_where(PDO $pdo, string $module, array $args, array $options = []): array {
        $schema = jtgpt_quality_module_schema($pdo, $module);
        if (empty($schema['available'])) {
            return ['schema' => $schema, 'ok' => false, 'sql' => '', 'params' => []];
        }

        $skipPointFilters = !empty($options['skip_point_filters']);
        $where = [];
        $params = [];

        $pointExpr = jtgpt_quality_point_expr($schema, strtolower($module));
        $valueExpr = jtgpt_quality_value_expr($schema, strtolower($module));
        $uslExpr = !empty($schema['usl_col']) ? "r.`{$schema['usl_col']}`" : 'NULL';
        $lslExpr = !empty($schema['lsl_col']) ? "r.`{$schema['lsl_col']}`" : 'NULL';
        $directNg = "(({$uslExpr} IS NOT NULL AND {$valueExpr} IS NOT NULL AND {$valueExpr} > {$uslExpr}) OR ({$lslExpr} IS NOT NULL AND {$valueExpr} IS NOT NULL AND {$valueExpr} < {$lslExpr}))";
        if (!empty($schema['ng_predicate'])) {
            $where[] = "({$directNg} OR {$schema['ng_predicate']})";
        } else {
            $where[] = $directNg;
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

        if (empty($args['include_dc'])) {
            $where[] = "{$pointExpr} NOT LIKE :dc_like";
            $params[':dc_like'] = '%(DC)%';
        }

        if (!$skipPointFilters) {
            $pointList = jtgpt_quality_collect_values($args, 'point_no_list', 'point_no', static function ($value): string {
                return strtoupper(trim((string)$value));
            });
            if ($pointList) {
                $placeholders = jtgpt_quality_named_placeholders('point_no', $pointList, $params);
                $where[] = 'UPPER(' . $pointExpr . ') IN (' . implode(', ', $placeholders) . ')';
            }
        }

        $tools = jtgpt_quality_collect_values($args, 'tools', 'tool', 'jtgpt_quality_normalize_tool');
        if ($tools) {
            if (!empty($schema['tool_col'])) {
                $placeholders = jtgpt_quality_named_placeholders('tool', $tools, $params);
                $where[] = "UPPER(CAST(h.`{$schema['tool_col']}` AS CHAR)) IN (" . implode(', ', $placeholders) . ')';
            } elseif (!empty($schema['tool_cavity_col'])) {
                $conds = [];
                foreach (array_values($tools) as $i => $tool) {
                    $name = ':tool_like_' . $i;
                    $params[$name] = '%' . $tool . '%';
                    $conds[] = "UPPER(h.`{$schema['tool_cavity_col']}`) LIKE {$name}";
                }
                if ($conds) {
                    $where[] = '(' . implode(' OR ', $conds) . ')';
                }
            }
        }

        $cavities = jtgpt_quality_collect_values($args, 'cavities', 'cavity', 'jtgpt_quality_normalize_cavity');
        if ($cavities) {
            if (!empty($schema['cavity_col'])) {
                $colExpr = "REPLACE(UPPER(CAST(h.`{$schema['cavity_col']}` AS CHAR)), ' ', '')";
                $conds = [];
                foreach (array_values($cavities) as $i => $cavity) {
                    $rawKey = ':cavity_raw_' . $i;
                    $numKey = ':cavity_num_' . $i;
                    $params[$rawKey] = $cavity;
                    $params[$numKey] = (string)((int)$cavity);
                    $conds[] = "{$colExpr} = {$rawKey}";
                    $conds[] = "{$colExpr} = {$numKey}";
                }
                if ($conds) {
                    $where[] = '(' . implode(' OR ', $conds) . ')';
                }
            } elseif (!empty($schema['tool_cavity_col'])) {
                $conds = [];
                foreach (array_values($cavities) as $i => $cavity) {
                    $name = ':cavity_like_' . $i;
                    $params[$name] = '%' . $cavity . '%';
                    $conds[] = "UPPER(h.`{$schema['tool_cavity_col']}`) LIKE {$name}";
                }
                if ($conds) {
                    $where[] = '(' . implode(' OR ', $conds) . ')';
                }
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

if (!function_exists('jtgpt_quality_distinct_points_in_scope')) {
    function jtgpt_quality_distinct_points_in_scope(PDO $pdo, string $module, array $args): array {
        $base = jtgpt_tool_quality_base_where($pdo, $module, $args, ['skip_point_filters' => true]);
        if (empty($base['ok'])) {
            return ['ok' => false, 'schema' => $base['schema'], 'points' => []];
        }
        $schema = $base['schema'];
        $pointExpr = jtgpt_quality_point_expr($schema, strtolower($module));
        $fromClause = jtgpt_quality_from_clause($schema, strtolower($module));
        $sql = "SELECT DISTINCT {$pointExpr} AS point_no {$fromClause} {$base['sql']} ORDER BY {$pointExpr} ASC";
        $st = $pdo->prepare($sql);
        foreach ($base['params'] as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->execute();
        $points = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $point = trim((string)($row['point_no'] ?? ''));
            if ($point !== '') {
                $points[] = $point;
            }
        }
        return ['ok' => true, 'schema' => $schema, 'points' => jtgpt_quality_unique_list($points)];
    }
}

if (!function_exists('jtgpt_quality_resolve_point_terms')) {
    function jtgpt_quality_resolve_point_terms(PDO $pdo, string $module, array $args): array {
        $terms = $args['point_terms'] ?? [];
        if (!is_array($terms)) {
            $terms = [$terms];
        }
        $terms = jtgpt_quality_unique_list(array_map(static function ($value): string {
            return strtoupper(trim((string)$value));
        }, $terms));
        if (!$terms) {
            return ['ok' => true, 'resolved_points' => [], 'resolved_terms' => [], 'ambiguous_terms' => [], 'unmatched_terms' => []];
        }

        $scope = jtgpt_quality_distinct_points_in_scope($pdo, $module, $args);
        if (empty($scope['ok'])) {
            return ['ok' => false, 'error' => $scope['schema']['error'] ?? '포인트 후보 조회 실패', 'resolved_points' => [], 'resolved_terms' => [], 'ambiguous_terms' => [], 'unmatched_terms' => []];
        }

        $points = $scope['points'];
        $pointNormMap = [];
        foreach ($points as $point) {
            $pointNormMap[$point] = jtgpt_quality_norm_token($point);
        }

        $resolvedPoints = [];
        $resolvedTerms = [];
        $ambiguousTerms = [];
        $unmatchedTerms = [];

        foreach ($terms as $term) {
            $termNorm = jtgpt_quality_norm_token($term);
            if ($termNorm === '') {
                continue;
            }

            $exact = [];
            $normalized = [];
            $fuzzy = [];
            foreach ($points as $point) {
                $pointUpper = strtoupper($point);
                $pointNorm = $pointNormMap[$point] ?? '';
                if ($pointUpper === $term) {
                    $exact[] = $point;
                    continue;
                }
                if ($pointNorm !== '' && $pointNorm === $termNorm) {
                    $normalized[] = $point;
                    continue;
                }
                if ($pointNorm !== '' && (strpos($pointNorm, $termNorm) !== false || strpos($termNorm, $pointNorm) !== false)) {
                    $fuzzy[] = $point;
                }
            }

            $exact = jtgpt_quality_unique_list($exact);
            $normalized = jtgpt_quality_unique_list($normalized);
            $fuzzy = jtgpt_quality_unique_list($fuzzy);

            if (count($exact) === 1) {
                $resolvedPoints[] = $exact[0];
                $resolvedTerms[] = ['term' => $term, 'point_no' => $exact[0], 'match_type' => 'exact'];
                continue;
            }
            if (count($normalized) === 1) {
                $resolvedPoints[] = $normalized[0];
                $resolvedTerms[] = ['term' => $term, 'point_no' => $normalized[0], 'match_type' => 'normalized'];
                continue;
            }
            if (count($fuzzy) === 1) {
                $resolvedPoints[] = $fuzzy[0];
                $resolvedTerms[] = ['term' => $term, 'point_no' => $fuzzy[0], 'match_type' => 'fuzzy'];
                continue;
            }

            $candidates = $exact ?: ($normalized ?: $fuzzy);
            if ($candidates) {
                $ambiguousTerms[] = ['term' => $term, 'candidates' => array_slice($candidates, 0, 10)];
            } else {
                $unmatchedTerms[] = $term;
            }
        }

        return [
            'ok' => true,
            'resolved_points' => jtgpt_quality_unique_list($resolvedPoints),
            'resolved_terms' => $resolvedTerms,
            'ambiguous_terms' => $ambiguousTerms,
            'unmatched_terms' => jtgpt_quality_unique_list($unmatchedTerms),
        ];
    }
}

if (!function_exists('jtgpt_quality_prepare_point_filters')) {
    function jtgpt_quality_prepare_point_filters(PDO $pdo, string $module, array $args): array {
        $exactPoints = jtgpt_quality_collect_values($args, 'point_no_list', 'point_no', static function ($value): string {
            return strtoupper(trim((string)$value));
        });
        if ($exactPoints) {
            $args['point_no_list'] = $exactPoints;
            if (count($exactPoints) === 1) {
                $args['point_no'] = $exactPoints[0];
            }
            return ['args' => $args, 'resolution' => null, 'ok' => true];
        }

        $terms = $args['point_terms'] ?? [];
        if (!is_array($terms) || !$terms) {
            return ['args' => $args, 'resolution' => null, 'ok' => true];
        }

        $resolution = jtgpt_quality_resolve_point_terms($pdo, $module, $args);
        if (empty($resolution['ok'])) {
            return ['args' => $args, 'resolution' => $resolution, 'ok' => false];
        }
        $resolvedPoints = $resolution['resolved_points'] ?? [];
        if ($resolvedPoints) {
            $args['point_no_list'] = $resolvedPoints;
            if (count($resolvedPoints) === 1) {
                $args['point_no'] = $resolvedPoints[0];
            }
        }
        return ['args' => $args, 'resolution' => $resolution, 'ok' => true];
    }
}

if (!function_exists('jtgpt_quality_bind_limit')) {
    function jtgpt_quality_bind_limit(PDOStatement $st, int $limit): void {
        $st->bindValue(':limit_n', max(1, min(500, $limit)), PDO::PARAM_INT);
    }
}

if (!function_exists('jtgpt_tool_quality_top_ng_points')) {
    function jtgpt_tool_quality_top_ng_points(PDO $pdo, string $module, array $args): array {
        $prepared = jtgpt_quality_prepare_point_filters($pdo, $module, $args);
        $args = $prepared['args'];
        $resolution = $prepared['resolution'];
        $base = jtgpt_tool_quality_base_where($pdo, $module, $args);
        if (empty($base['ok'])) {
            return ['found' => false, 'module' => strtolower($module), 'error' => $base['schema']['error'] ?? '조회 준비 실패', 'rows' => [], 'resolution' => $resolution];
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
        return ['found' => !empty($rows), 'module' => $schema['module'], 'label' => $schema['label'], 'rows' => $rows, 'resolution' => $resolution];
    }
}

if (!function_exists('jtgpt_tool_quality_recent_ng_rows')) {
    function jtgpt_tool_quality_recent_ng_rows(PDO $pdo, string $module, array $args): array {
        $prepared = jtgpt_quality_prepare_point_filters($pdo, $module, $args);
        $args = $prepared['args'];
        $resolution = $prepared['resolution'];

        $base = jtgpt_tool_quality_base_where($pdo, $module, $args);
        if (empty($base['ok'])) {
            return ['found' => false, 'module' => strtolower($module), 'error' => $base['schema']['error'] ?? '조회 준비 실패', 'rows' => [], 'resolution' => $resolution];
        }
        $schema = $base['schema'];
        $limit = array_key_exists('limit', $args) && $args['limit'] !== null && $args['limit'] !== '' ? (int)$args['limit'] : null;

        $partExpr = !empty($schema['part_col']) ? "h.`{$schema['part_col']}`" : "''";
        $toolCavityExpr = jtgpt_quality_tool_cavity_expr($schema);
        $pointExpr = jtgpt_quality_point_expr($schema, strtolower($module));
        $valueExpr = jtgpt_quality_value_expr($schema, strtolower($module));
        $uslExpr = !empty($schema['usl_col']) ? "r.`{$schema['usl_col']}`" : 'NULL';
        $lslExpr = !empty($schema['lsl_col']) ? "r.`{$schema['lsl_col']}`" : 'NULL';
        $fromClause = jtgpt_quality_from_clause($schema, strtolower($module));

        $ngSideExpr = "CASE WHEN {$uslExpr} IS NOT NULL AND {$valueExpr} IS NOT NULL AND {$valueExpr} > {$uslExpr} THEN 'USL' WHEN {$lslExpr} IS NOT NULL AND {$valueExpr} IS NOT NULL AND {$valueExpr} < {$lslExpr} THEN 'LSL' ELSE '' END";
        $ngLimitExpr = "CASE WHEN {$uslExpr} IS NOT NULL AND {$valueExpr} IS NOT NULL AND {$valueExpr} > {$uslExpr} THEN {$uslExpr} WHEN {$lslExpr} IS NOT NULL AND {$valueExpr} IS NOT NULL AND {$valueExpr} < {$lslExpr} THEN {$lslExpr} ELSE NULL END";

        $sql = "SELECT h.`{$schema['date_col']}` AS event_date, {$pointExpr} AS raw_point_no, {$partExpr} AS part_name, {$toolCavityExpr} AS raw_tool_cavity, {$valueExpr} AS value, {$uslExpr} AS usl, {$lslExpr} AS lsl, {$ngSideExpr} AS ng_side, {$ngLimitExpr} AS ng_limit {$fromClause} {$base['sql']} ORDER BY h.`{$schema['date_col']}` ASC, {$pointExpr} ASC, {$toolCavityExpr} ASC, {$partExpr} ASC, h.`{$schema['header_pk_col']}` ASC";
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT :limit_n';
        }

        $st = $pdo->prepare($sql);
        foreach ($base['params'] as $k => $v) $st->bindValue($k, $v);
        if ($limit !== null && $limit > 0) {
            jtgpt_quality_bind_limit($st, $limit);
        }
        $st->execute();
        $rawRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rows = [];
        foreach ($rawRows as $row) {
            $rows[] = [
                'event_date' => (string)($row['event_date'] ?? '-'),
                'point_no' => trim((string)($row['raw_point_no'] ?? '')),
                'tool_cavity' => trim((string)($row['raw_tool_cavity'] ?? '')),
                'part_name' => (string)($row['part_name'] ?? ''),
                'kind' => '',
                'ng_side' => strtoupper(trim((string)($row['ng_side'] ?? ''))),
                'ng_limit' => $row['ng_limit'] ?? null,
                'value' => $row['value'] ?? null,
                'usl' => $row['usl'] ?? null,
                'lsl' => $row['lsl'] ?? null,
            ];
        }

        return ['found' => !empty($rows), 'module' => $schema['module'], 'label' => $schema['label'], 'rows' => $rows, 'resolution' => $resolution];
    }
}

if (!function_exists('jtgpt_tool_quality_point_detail')) {
    function jtgpt_tool_quality_point_detail(PDO $pdo, string $module, array $args): array {
        $prepared = jtgpt_quality_prepare_point_filters($pdo, $module, $args);
        $args = $prepared['args'];
        $resolution = $prepared['resolution'];
        $resolvedPoints = jtgpt_quality_collect_values($args, 'point_no_list', 'point_no', static function ($value): string {
            return strtoupper(trim((string)$value));
        });

        if ($resolution && count($resolvedPoints) > 1) {
            return ['found' => false, 'module' => strtolower($module), 'error' => '상세 조회는 FAI 1개만 확정돼야 합니다.', 'summary' => null, 'latest_rows' => [], 'resolution' => $resolution];
        }

        $base = jtgpt_tool_quality_base_where($pdo, $module, $args);
        if (empty($base['ok'])) {
            return ['found' => false, 'module' => strtolower($module), 'error' => $base['schema']['error'] ?? '조회 준비 실패', 'summary' => null, 'latest_rows' => [], 'resolution' => $resolution];
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
            return ['found' => false, 'module' => $schema['module'], 'label' => $schema['label'], 'summary' => null, 'latest_rows' => [], 'resolution' => $resolution];
        }
        $detailArgs = $args;
        if (!array_key_exists('limit', $detailArgs)) {
            $detailArgs['limit'] = null;
        }
        $latest = jtgpt_tool_quality_recent_ng_rows($pdo, $module, $detailArgs);
        return ['found' => true, 'module' => $schema['module'], 'label' => $schema['label'], 'summary' => $summary, 'latest_rows' => $latest['rows'] ?? [], 'resolution' => $resolution];
    }
}

if (!function_exists('jtgpt_quality_module_list_from_args')) {
    function jtgpt_quality_module_list_from_args(array $args): array {
        $modules = [];
        $list = $args['modules'] ?? [];
        if (!is_array($list)) {
            $list = [$list];
        }
        foreach ($list as $module) {
            $module = strtolower(trim((string)$module));
            if ($module !== '' && jtgpt_quality_module_def($module)) {
                $modules[] = $module;
            }
        }
        $single = strtolower(trim((string)($args['module'] ?? '')));
        if ($single !== '' && jtgpt_quality_module_def($single)) {
            $modules[] = $single;
        }
        $modules = jtgpt_quality_unique_list($modules);
        if (!$modules) {
            $modules = ['oqc', 'omm', 'aoi', 'cmm'];
        }
        return $modules;
    }
}

if (!function_exists('jtgpt_quality_merge_resolutions')) {
    function jtgpt_quality_merge_resolutions(array $items, bool $multiModule = false): ?array {
        $merged = [
            'resolved_points' => [],
            'resolved_terms' => [],
            'ambiguous_terms' => [],
            'unmatched_terms' => [],
        ];
        $hasAny = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $moduleLabel = trim((string)($item['module_label'] ?? $item['label'] ?? $item['module'] ?? ''));
            $resolution = $item['resolution'] ?? null;
            if (!is_array($resolution)) {
                continue;
            }
            $hasAny = true;
            foreach (($resolution['resolved_points'] ?? []) as $point) {
                $merged['resolved_points'][] = (string)$point;
            }
            foreach (($resolution['resolved_terms'] ?? []) as $row) {
                if (!is_array($row)) continue;
                if ($multiModule && $moduleLabel !== '') {
                    $row['module_label'] = $moduleLabel;
                }
                $merged['resolved_terms'][] = $row;
            }
            foreach (($resolution['ambiguous_terms'] ?? []) as $row) {
                if (!is_array($row)) continue;
                if ($multiModule && $moduleLabel !== '') {
                    $row['term'] = trim((string)($row['term'] ?? '')) . ' [' . $moduleLabel . ']';
                }
                $merged['ambiguous_terms'][] = $row;
            }
            foreach (($resolution['unmatched_terms'] ?? []) as $term) {
                $term = trim((string)$term);
                if ($term === '') continue;
                $merged['unmatched_terms'][] = $multiModule && $moduleLabel !== '' ? ($term . ' [' . $moduleLabel . ']') : $term;
            }
        }
        if (!$hasAny) {
            return null;
        }
        $merged['resolved_points'] = jtgpt_quality_unique_list($merged['resolved_points']);
        $merged['unmatched_terms'] = jtgpt_quality_unique_list($merged['unmatched_terms']);
        return $merged;
    }
}

if (!function_exists('jtgpt_quality_sort_recent_rows')) {
    function jtgpt_quality_sort_recent_rows(array &$rows): void {
        usort($rows, static function (array $a, array $b): int {
            $ka = [
                (string)($a['event_date'] ?? ''),
                (string)($a['module'] ?? ''),
                (string)($a['point_no'] ?? ''),
                (string)($a['tool_cavity'] ?? ''),
            ];
            $kb = [
                (string)($b['event_date'] ?? ''),
                (string)($b['module'] ?? ''),
                (string)($b['point_no'] ?? ''),
                (string)($b['tool_cavity'] ?? ''),
            ];
            return $ka <=> $kb;
        });
    }
}

if (!function_exists('jtgpt_tool_quality_recent_ng_rows_multi')) {
    function jtgpt_tool_quality_recent_ng_rows_multi(PDO $pdo, array $args): array {
        $modules = jtgpt_quality_module_list_from_args($args);
        $multiModule = count($modules) > 1;
        $globalLimit = array_key_exists('limit', $args) && $args['limit'] !== null && $args['limit'] !== '' ? (int)$args['limit'] : null;
        $rows = [];
        $resolutions = [];
        $errors = [];

        foreach ($modules as $module) {
            $moduleArgs = $args;
            $moduleArgs['module'] = $module;
            $moduleArgs['modules'] = [$module];
            if ($multiModule) {
                $moduleArgs['limit'] = null;
            }
            $result = jtgpt_tool_quality_recent_ng_rows($pdo, $module, $moduleArgs);
            if (!empty($result['resolution'])) {
                $resolutions[] = [
                    'module' => $module,
                    'module_label' => $result['label'] ?? strtoupper($module),
                    'resolution' => $result['resolution'],
                ];
            }
            if (!empty($result['error'])) {
                $errors[] = '[' . strtoupper($module) . '] ' . $result['error'];
            }
            foreach (($result['rows'] ?? []) as $row) {
                $row['module'] = $module;
                $row['module_label'] = $result['label'] ?? strtoupper($module);
                $rows[] = $row;
            }
        }

        jtgpt_quality_sort_recent_rows($rows);
        if ($globalLimit !== null && $globalLimit > 0) {
            $rows = array_slice($rows, 0, $globalLimit);
        }

        return [
            'found' => !empty($rows),
            'module' => $multiModule ? 'all' : ($modules[0] ?? 'oqc'),
            'modules' => $modules,
            'multi_module' => $multiModule,
            'label' => $multiModule ? '전체' : strtoupper((string)($modules[0] ?? 'oqc')),
            'rows' => $rows,
            'resolution' => jtgpt_quality_merge_resolutions($resolutions, $multiModule),
            'error' => empty($rows) && $errors ? implode(' | ', $errors) : null,
        ];
    }
}

if (!function_exists('jtgpt_tool_quality_top_ng_points_multi')) {
    function jtgpt_tool_quality_top_ng_points_multi(PDO $pdo, array $args): array {
        $workingArgs = $args;
        $workingArgs['limit'] = null;
        $recent = jtgpt_tool_quality_recent_ng_rows_multi($pdo, $workingArgs);
        $limit = (int)($args['limit'] ?? 5);
        $bucket = [];
        foreach (($recent['rows'] ?? []) as $row) {
            $module = strtolower(trim((string)($row['module'] ?? '')));
            $moduleLabel = trim((string)($row['module_label'] ?? strtoupper($module)));
            $point = trim((string)($row['point_no'] ?? ''));
            if ($point === '') {
                continue;
            }
            $key = $module . '|' . $point;
            if (!isset($bucket[$key])) {
                $bucket[$key] = [
                    'module' => $module,
                    'module_label' => $moduleLabel,
                    'point_no' => $point,
                    'ng_count' => 0,
                    'header_count' => 0,
                    'last_date' => '',
                ];
            }
            $bucket[$key]['ng_count']++;
            $bucket[$key]['header_count']++;
            $date = (string)($row['event_date'] ?? '');
            if ($date !== '' && ($bucket[$key]['last_date'] === '' || $date > $bucket[$key]['last_date'])) {
                $bucket[$key]['last_date'] = $date;
            }
        }
        $rows = array_values($bucket);
        usort($rows, static function (array $a, array $b): int {
            return [$b['ng_count'], (string)$a['module'], (string)$a['point_no']] <=> [$a['ng_count'], (string)$b['module'], (string)$b['point_no']];
        });
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        return [
            'found' => !empty($rows),
            'module' => $recent['module'] ?? 'all',
            'modules' => $recent['modules'] ?? [],
            'multi_module' => !empty($recent['multi_module']),
            'label' => $recent['label'] ?? '전체',
            'rows' => $rows,
            'resolution' => $recent['resolution'] ?? null,
            'error' => empty($rows) ? ($recent['error'] ?? null) : null,
        ];
    }
}

if (!function_exists('jtgpt_tool_quality_count_ng_rows')) {
    function jtgpt_tool_quality_count_ng_rows(PDO $pdo, array $args): array {
        $workingArgs = $args;
        $workingArgs['limit'] = null;
        $recent = jtgpt_tool_quality_recent_ng_rows_multi($pdo, $workingArgs);
        $moduleCounts = [];
        foreach (($recent['rows'] ?? []) as $row) {
            $module = strtolower(trim((string)($row['module'] ?? '')));
            $label = trim((string)($row['module_label'] ?? strtoupper($module)));
            if (!isset($moduleCounts[$module])) {
                $moduleCounts[$module] = ['module' => $module, 'label' => $label, 'ng_count' => 0];
            }
            $moduleCounts[$module]['ng_count']++;
        }
        return [
            'found' => !empty($recent['rows']),
            'module' => $recent['module'] ?? 'all',
            'modules' => $recent['modules'] ?? [],
            'multi_module' => !empty($recent['multi_module']),
            'label' => $recent['label'] ?? '전체',
            'total_ng_count' => count($recent['rows'] ?? []),
            'module_counts' => array_values($moduleCounts),
            'resolution' => $recent['resolution'] ?? null,
            'error' => empty($recent['rows']) ? ($recent['error'] ?? null) : null,
        ];
    }
}

if (!function_exists('jtgpt_tool_quality_summary')) {
    function jtgpt_tool_quality_summary(PDO $pdo, array $args): array {
        $workingArgs = $args;
        $workingArgs['limit'] = null;
        $recent = jtgpt_tool_quality_recent_ng_rows_multi($pdo, $workingArgs);
        $moduleCounts = [];
        $pointKeys = [];
        $topBucket = [];
        foreach (($recent['rows'] ?? []) as $row) {
            $module = strtolower(trim((string)($row['module'] ?? '')));
            $label = trim((string)($row['module_label'] ?? strtoupper($module)));
            $point = trim((string)($row['point_no'] ?? ''));
            if (!isset($moduleCounts[$module])) {
                $moduleCounts[$module] = ['module' => $module, 'label' => $label, 'ng_count' => 0, 'point_count' => 0];
            }
            $moduleCounts[$module]['ng_count']++;
            if ($point !== '') {
                $pointKey = $module . '|' . $point;
                if (!isset($pointKeys[$pointKey])) {
                    $pointKeys[$pointKey] = true;
                    $moduleCounts[$module]['point_count']++;
                }
                if (!isset($topBucket[$pointKey])) {
                    $topBucket[$pointKey] = ['module' => $module, 'module_label' => $label, 'point_no' => $point, 'ng_count' => 0];
                }
                $topBucket[$pointKey]['ng_count']++;
            }
        }
        $topRows = array_values($topBucket);
        usort($topRows, static function (array $a, array $b): int {
            return [$b['ng_count'], (string)$a['module'], (string)$a['point_no']] <=> [$a['ng_count'], (string)$b['module'], (string)$b['point_no']];
        });
        $topRows = array_slice($topRows, 0, 5);
        return [
            'found' => !empty($recent['rows']),
            'module' => $recent['module'] ?? 'all',
            'modules' => $recent['modules'] ?? [],
            'multi_module' => !empty($recent['multi_module']),
            'label' => $recent['label'] ?? '전체',
            'total_ng_count' => count($recent['rows'] ?? []),
            'module_counts' => array_values($moduleCounts),
            'top_rows' => $topRows,
            'resolution' => $recent['resolution'] ?? null,
            'error' => empty($recent['rows']) ? ($recent['error'] ?? null) : null,
        ];
    }
}


if (!function_exists('jtgpt_tool_quality_point_detail_multi')) {
    function jtgpt_tool_quality_point_detail_multi(PDO $pdo, array $args): array {
        $modules = jtgpt_quality_module_list_from_args($args);
        $multiModule = count($modules) > 1;
        $found = [];
        $resolutions = [];
        $errors = [];
        foreach ($modules as $module) {
            $moduleArgs = $args;
            $moduleArgs['module'] = $module;
            $moduleArgs['modules'] = [$module];
            $result = jtgpt_tool_quality_point_detail($pdo, $module, $moduleArgs);
            if (!empty($result['resolution'])) {
                $resolutions[] = [
                    'module' => $module,
                    'module_label' => $result['label'] ?? strtoupper($module),
                    'resolution' => $result['resolution'],
                ];
            }
            if (!empty($result['error'])) {
                $errors[] = '[' . strtoupper($module) . '] ' . $result['error'];
            }
            if (!empty($result['found'])) {
                $result['module'] = $module;
                $result['module_label'] = $result['label'] ?? strtoupper($module);
                foreach (($result['latest_rows'] ?? []) as $idx => $row) {
                    $row['module'] = $module;
                    $row['module_label'] = $result['label'] ?? strtoupper($module);
                    $result['latest_rows'][$idx] = $row;
                }
                $found[] = $result;
            }
        }
        if (!$found) {
            return [
                'found' => false,
                'module' => $multiModule ? 'all' : ($modules[0] ?? 'oqc'),
                'modules' => $modules,
                'multi_module' => $multiModule,
                'label' => $multiModule ? '전체' : strtoupper((string)($modules[0] ?? 'oqc')),
                'summary' => null,
                'latest_rows' => [],
                'resolution' => jtgpt_quality_merge_resolutions($resolutions, $multiModule),
                'error' => $errors ? implode(' | ', $errors) : null,
                'results' => [],
            ];
        }
        if (count($found) === 1) {
            $only = $found[0];
            $only['modules'] = $modules;
            $only['multi_module'] = $multiModule;
            $only['resolution'] = jtgpt_quality_merge_resolutions($resolutions, $multiModule);
            return $only;
        }
        return [
            'found' => true,
            'module' => 'all',
            'modules' => $modules,
            'multi_module' => true,
            'label' => '전체',
            'summary' => null,
            'latest_rows' => [],
            'resolution' => jtgpt_quality_merge_resolutions($resolutions, true),
            'error' => null,
            'results' => $found,
        ];
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
