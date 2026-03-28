<?php
if (!function_exists('jtgpt_tool_format_int')) {
    function jtgpt_tool_format_int($v): string {
        return number_format((int)$v);
    }
}

if (!function_exists('jtgpt_tool_shipping_quote_ident')) {
    function jtgpt_tool_shipping_quote_ident(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

if (!function_exists('jtgpt_tool_shipping_norm_key')) {
    function jtgpt_tool_shipping_norm_key(string $name): string {
        $name = mb_strtolower(trim($name), 'UTF-8');
        return preg_replace('/[\s\-_\.\/]+/u', '', $name);
    }
}

if (!function_exists('jtgpt_tool_shipping_detect_columns')) {
    function jtgpt_tool_shipping_detect_columns(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `ShipingList`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $field = trim((string)($r['Field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $cols[mb_strtolower($field, 'UTF-8')] = $field;
            $cols[jtgpt_tool_shipping_norm_key($field)] = $field;
        }
        $cache = $cols;
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_pick_column')) {
    function jtgpt_tool_shipping_pick_column(array $cols, array $candidates): ?string {
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            $lower = mb_strtolower($candidate, 'UTF-8');
            if (isset($cols[$lower])) {
                return (string)$cols[$lower];
            }
            $norm = jtgpt_tool_shipping_norm_key($candidate);
            if ($norm !== '' && isset($cols[$norm])) {
                return (string)$cols[$norm];
            }
        }
        return null;
    }
}

if (!function_exists('jtgpt_tool_shipping_detect_schema')) {
    function jtgpt_tool_shipping_detect_schema(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $cols = jtgpt_tool_shipping_detect_columns($pdo);
        $cache = [
            'date' => jtgpt_tool_shipping_pick_column($cols, [
                'ship_datetime', 'ship_date', 'shipping_date', '출하일자', '출하일', '출고일자', '출고일', '납품일자', '납품일',
            ]),
            'qty' => jtgpt_tool_shipping_pick_column($cols, [
                'qty', 'quantity', 'ship_qty', '출고수량', '출하수량', '납품수량',
            ]),
            'lot' => jtgpt_tool_shipping_pick_column($cols, [
                'small_pack_no', 'smallpackno', 'small_pack', 'lot_no', 'lotno', 'lot', '소포장no', '소포장 no', '소포장번호', 'lot 번호',
            ]),
            'tray' => jtgpt_tool_shipping_pick_column($cols, [
                'tray_no', 'trayno', 'tray', 'tray no', 'tray 번호', 'tray번호',
            ]),
            'part_name' => jtgpt_tool_shipping_pick_column($cols, [
                'part_name', 'partname', '품번명', '모델', 'model_name', 'model',
            ]),
            'part_code' => jtgpt_tool_shipping_pick_column($cols, [
                'part_code', 'partcode', '품번코드',
            ]),
            'customer' => jtgpt_tool_shipping_pick_column($cols, [
                'ship_to', 'shipto', 'customer', 'customer_name', '납품처', '고객사', 'customer part', '고객사 품번',
            ]),
            'id' => jtgpt_tool_shipping_pick_column($cols, ['id', 'idx', 'no']),
        ];
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_select_expr')) {
    function jtgpt_tool_shipping_select_expr(?string $column, string $alias): string {
        if ($column === null || $column === '') {
            return 'NULL AS ' . jtgpt_tool_shipping_quote_ident($alias);
        }
        return jtgpt_tool_shipping_quote_ident($column) . ' AS ' . jtgpt_tool_shipping_quote_ident($alias);
    }
}

if (!function_exists('jtgpt_tool_shipping_where')) {
    function jtgpt_tool_shipping_where(PDO $pdo, array $args): array {
        $schema = jtgpt_tool_shipping_detect_schema($pdo);
        if (empty($schema['date'])) {
            return ['sql' => 'WHERE 1=0', 'params' => []];
        }
        $dateCol = jtgpt_tool_shipping_quote_ident((string)$schema['date']);
        $where = ['DATE(' . $dateCol . ') >= :from_dt', 'DATE(' . $dateCol . ') <= :to_dt'];
        $params = [
            ':from_dt' => (string)($args['from'] ?? date('Y-m-d')),
            ':to_dt' => (string)($args['to'] ?? date('Y-m-d')),
        ];

        $partName = trim((string)($args['part_name'] ?? ''));
        if ($partName !== '') {
            $partCols = array_values(array_filter([$schema['part_name'] ?? null, $schema['part_code'] ?? null]));
            if ($partCols) {
                $likeParts = [];
                foreach ($partCols as $idx => $col) {
                    $key = ':part_name_' . $idx;
                    $likeParts[] = jtgpt_tool_shipping_quote_ident((string)$col) . ' LIKE ' . $key;
                    $params[$key] = '%' . $partName . '%';
                }
                $where[] = '(' . implode(' OR ', $likeParts) . ')';
            }
        }

        $customer = trim((string)($args['customer'] ?? ''));
        if ($customer !== '' && !empty($schema['customer'])) {
            $where[] = jtgpt_tool_shipping_quote_ident((string)$schema['customer']) . ' LIKE :ship_to';
            $params[':ship_to'] = '%' . $customer . '%';
        }

        return ['sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params, 'schema' => $schema];
    }
}

if (!function_exists('jtgpt_tool_shipping_summary')) {
    function jtgpt_tool_shipping_summary(PDO $pdo, array $args): array {
        try {
            $where = jtgpt_tool_shipping_where($pdo, $args);
            $schema = (array)($where['schema'] ?? jtgpt_tool_shipping_detect_schema($pdo));
            if (empty($schema['date'])) {
                return ['found' => false, 'row_count' => 0, 'total_qty' => 0, 'lot_count' => 0, 'tray_count' => 0, 'part_count' => 0, 'top_parts' => []];
            }
            $qtyExpr = !empty($schema['qty']) ? ('COALESCE(SUM(' . jtgpt_tool_shipping_quote_ident((string)$schema['qty']) . '), 0)') : 'COUNT(*)';
            $lotExpr = !empty($schema['lot']) ? ('COUNT(DISTINCT ' . jtgpt_tool_shipping_quote_ident((string)$schema['lot']) . ')') : '0';
            $trayExpr = !empty($schema['tray']) ? ('COUNT(DISTINCT ' . jtgpt_tool_shipping_quote_ident((string)$schema['tray']) . ')') : '0';
            $partExpr = !empty($schema['part_name']) ? ('COUNT(DISTINCT ' . jtgpt_tool_shipping_quote_ident((string)$schema['part_name']) . ')') : '0';

            $sql = "
                SELECT
                    COUNT(*) AS row_count,
                    {$qtyExpr} AS total_qty,
                    {$lotExpr} AS lot_count,
                    {$trayExpr} AS tray_count,
                    {$partExpr} AS part_count
                FROM ShipingList
                {$where['sql']}
            ";
            $st = $pdo->prepare($sql);
            $st->execute((array)$where['params']);
            $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $top = [];
            if (empty($args['part_name']) && !empty($schema['part_name'])) {
                $qtyTopExpr = !empty($schema['qty']) ? ('COALESCE(SUM(' . jtgpt_tool_shipping_quote_ident((string)$schema['qty']) . '), 0)') : 'COUNT(*)';
                $partCol = jtgpt_tool_shipping_quote_ident((string)$schema['part_name']);
                $sqlTop = "
                    SELECT {$partCol} AS part_name, {$qtyTopExpr} AS total_qty
                    FROM ShipingList
                    {$where['sql']}
                    GROUP BY {$partCol}
                    ORDER BY total_qty DESC, part_name ASC
                    LIMIT 5
                ";
                $st = $pdo->prepare($sqlTop);
                $st->execute((array)$where['params']);
                $top = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            return [
                'found' => ((int)($summary['row_count'] ?? 0)) > 0,
                'row_count' => (int)($summary['row_count'] ?? 0),
                'total_qty' => (int)($summary['total_qty'] ?? 0),
                'lot_count' => (int)($summary['lot_count'] ?? 0),
                'tray_count' => (int)($summary['tray_count'] ?? 0),
                'part_count' => (int)($summary['part_count'] ?? 0),
                'top_parts' => $top,
            ];
        } catch (Throwable $e) {
            return ['found' => false, 'row_count' => 0, 'total_qty' => 0, 'lot_count' => 0, 'tray_count' => 0, 'part_count' => 0, 'top_parts' => []];
        }
    }
}

if (!function_exists('jtgpt_tool_shipping_last_ship_date')) {
    function jtgpt_tool_shipping_last_ship_date(PDO $pdo, array $args): array {
        try {
            $where = jtgpt_tool_shipping_where($pdo, $args);
            $schema = (array)($where['schema'] ?? jtgpt_tool_shipping_detect_schema($pdo));
            if (empty($schema['date'])) {
                return ['found' => false, 'row' => null];
            }
            $dateOrder = jtgpt_tool_shipping_quote_ident((string)$schema['date']) . ' DESC';
            $extraOrder = !empty($schema['id']) ? (', ' . jtgpt_tool_shipping_quote_ident((string)$schema['id']) . ' DESC') : '';
            $sql = "
                SELECT
                    " . jtgpt_tool_shipping_select_expr($schema['date'] ?? null, 'ship_datetime') . ",
                    " . jtgpt_tool_shipping_select_expr($schema['part_name'] ?? null, 'part_name') . ",
                    " . jtgpt_tool_shipping_select_expr($schema['customer'] ?? null, 'ship_to') . ",
                    " . jtgpt_tool_shipping_select_expr($schema['qty'] ?? null, 'qty') . ",
                    " . jtgpt_tool_shipping_select_expr($schema['lot'] ?? null, 'small_pack_no') . ",
                    " . jtgpt_tool_shipping_select_expr($schema['tray'] ?? null, 'tray_no') . "
                FROM ShipingList
                {$where['sql']}
                ORDER BY {$dateOrder}{$extraOrder}
                LIMIT 1
            ";
            $st = $pdo->prepare($sql);
            $st->execute((array)$where['params']);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            return [
                'found' => is_array($row) && !empty($row),
                'row' => $row,
            ];
        } catch (Throwable $e) {
            return ['found' => false, 'row' => null];
        }
    }
}
