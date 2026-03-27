<?php
declare(strict_types=1);

if (!function_exists('jtgpt_tool_shipping_detect_columns')) {
    function jtgpt_tool_shipping_detect_columns(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $cols = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `ShipingList`");
            foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                $field = trim((string)($r['Field'] ?? ''));
                if ($field !== '') {
                    $cols[strtolower($field)] = $field;
                }
            }
        } catch (Throwable $e) {
            // keep empty
        }

        $cache = $cols;
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_first_col')) {
    function jtgpt_tool_shipping_first_col(array $cols, array $candidates): ?string {
        foreach ($candidates as $candidate) {
            $key = strtolower(trim((string)$candidate));
            if ($key !== '' && isset($cols[$key])) {
                return (string)$cols[$key];
            }
        }
        return null;
    }
}

if (!function_exists('jtgpt_tool_shipping_schema')) {
    function jtgpt_tool_shipping_schema(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $cols = jtgpt_tool_shipping_detect_columns($pdo);

        $schema = [
            'available' => !empty($cols),
            'date_col' => jtgpt_tool_shipping_first_col($cols, [
                'ship_datetime', 'ship_date', 'shipment_datetime', 'shipment_date', 'date', 'out_date'
            ]),
            'qty_col' => jtgpt_tool_shipping_first_col($cols, [
                'qty', 'ship_qty', 'out_qty', 'qty_ea'
            ]),
            'lot_col' => jtgpt_tool_shipping_first_col($cols, [
                'small_pack_no', 'smallpack_no', 'small_pack', 'small_box_no', 'pack_no'
            ]),
            'tray_col' => jtgpt_tool_shipping_first_col($cols, [
                'tray_no', 'trayno', 'tray'
            ]),
            'part_col' => jtgpt_tool_shipping_first_col($cols, [
                'part_name', 'part', 'model_name', 'model'
            ]),
            'customer_col' => jtgpt_tool_shipping_first_col($cols, [
                'ship_to', 'customer', 'customer_name'
            ]),
            'id_col' => jtgpt_tool_shipping_first_col($cols, [
                'id', 'idx'
            ]),
        ];

        $cache = $schema;
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_empty_summary')) {
    function jtgpt_tool_shipping_empty_summary(): array {
        return [
            'found' => false,
            'row_count' => 0,
            'total_qty' => 0,
            'lot_count' => 0,
            'tray_count' => 0,
            'part_count' => 0,
            'top_parts' => [],
        ];
    }
}

if (!function_exists('jtgpt_tool_shipping_where')) {
    function jtgpt_tool_shipping_where(PDO $pdo, array $args): array {
        $schema = jtgpt_tool_shipping_schema($pdo);
        $dateCol = (string)($schema['date_col'] ?? '');
        if ($dateCol === '') {
            return ['sql' => 'WHERE 1=0', 'params' => []];
        }

        $from = trim((string)($args['from'] ?? date('Y-m-d')));
        if ($from === '') {
            $from = date('Y-m-d');
        }
        $to = trim((string)($args['to'] ?? $from));
        if ($to === '') {
            $to = $from;
        }

        $where = ["DATE(`{$dateCol}`) >= :from_date", "DATE(`{$dateCol}`) <= :to_date"];
        $params = [
            ':from_date' => $from,
            ':to_date' => $to,
        ];

        $partCol = (string)($schema['part_col'] ?? '');
        $partName = trim((string)($args['part_name'] ?? ''));
        if ($partCol !== '' && $partName !== '') {
            $where[] = "`{$partCol}` LIKE :part_name";
            $params[':part_name'] = '%' . $partName . '%';
        }

        $customerCol = (string)($schema['customer_col'] ?? '');
        $customer = trim((string)($args['customer'] ?? ''));
        if ($customerCol !== '' && $customer !== '') {
            $where[] = "`{$customerCol}` LIKE :ship_to";
            $params[':ship_to'] = '%' . $customer . '%';
        }

        return [
            'sql' => 'WHERE ' . implode(' AND ', $where),
            'params' => $params,
            'schema' => $schema,
        ];
    }
}

if (!function_exists('jtgpt_tool_shipping_summary')) {
    function jtgpt_tool_shipping_summary(PDO $pdo, array $args): array {
        try {
            $where = jtgpt_tool_shipping_where($pdo, $args);
            $schema = (array)($where['schema'] ?? jtgpt_tool_shipping_schema($pdo));
            $dateCol = (string)($schema['date_col'] ?? '');
            if ($dateCol === '') {
                return jtgpt_tool_shipping_empty_summary();
            }

            $qtyExpr = !empty($schema['qty_col']) ? "COALESCE(SUM(`{$schema['qty_col']}`), 0)" : "0";
            $lotExpr = !empty($schema['lot_col']) ? "COUNT(DISTINCT `{$schema['lot_col']}`)" : "0";
            $trayExpr = !empty($schema['tray_col']) ? "COUNT(DISTINCT `{$schema['tray_col']}`)" : "0";
            $partExpr = !empty($schema['part_col']) ? "COUNT(DISTINCT `{$schema['part_col']}`)" : "0";

            $sql = "SELECT COUNT(*) AS row_count,
                           {$qtyExpr} AS total_qty,
                           {$lotExpr} AS lot_count,
                           {$trayExpr} AS tray_count,
                           {$partExpr} AS part_count
                    FROM `ShipingList` {$where['sql']}";
            $st = $pdo->prepare($sql);
            $st->execute((array)$where['params']);
            $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $top = [];
            $partCol = (string)($schema['part_col'] ?? '');
            if ($partCol !== '' && empty($args['part_name'])) {
                $qtyTopExpr = !empty($schema['qty_col']) ? "COALESCE(SUM(`{$schema['qty_col']}`), 0)" : "COUNT(*)";
                $sqlTop = "SELECT `{$partCol}` AS part_name, {$qtyTopExpr} AS total_qty
                           FROM `ShipingList` {$where['sql']}
                           GROUP BY `{$partCol}`
                           ORDER BY total_qty DESC, `{$partCol}` ASC
                           LIMIT 5";
                $st = $pdo->prepare($sqlTop);
                $st->execute((array)$where['params']);
                $top = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            return [
                'found' => ((int)($summary['row_count'] ?? 0)) > 0,
                'row_count' => (int)($summary['row_count'] ?? 0),
                'total_qty' => (int)round((float)($summary['total_qty'] ?? 0)),
                'lot_count' => (int)($summary['lot_count'] ?? 0),
                'tray_count' => (int)($summary['tray_count'] ?? 0),
                'part_count' => (int)($summary['part_count'] ?? 0),
                'top_parts' => $top,
            ];
        } catch (Throwable $e) {
            return jtgpt_tool_shipping_empty_summary();
        }
    }
}

if (!function_exists('jtgpt_tool_shipping_last_ship_date')) {
    function jtgpt_tool_shipping_last_ship_date(PDO $pdo, array $args): array {
        try {
            $where = jtgpt_tool_shipping_where($pdo, $args);
            $schema = (array)($where['schema'] ?? jtgpt_tool_shipping_schema($pdo));

            $dateCol = (string)($schema['date_col'] ?? '');
            if ($dateCol === '') {
                return ['found' => false, 'row' => null];
            }

            $selects = [
                "`{$dateCol}` AS ship_datetime",
            ];

            $partCol = (string)($schema['part_col'] ?? '');
            $customerCol = (string)($schema['customer_col'] ?? '');
            $qtyCol = (string)($schema['qty_col'] ?? '');
            $lotCol = (string)($schema['lot_col'] ?? '');
            $trayCol = (string)($schema['tray_col'] ?? '');

            $selects[] = $partCol !== '' ? "`{$partCol}` AS part_name" : "'' AS part_name";
            $selects[] = $customerCol !== '' ? "`{$customerCol}` AS ship_to" : "'' AS ship_to";
            $selects[] = $qtyCol !== '' ? "`{$qtyCol}` AS qty" : "0 AS qty";
            $selects[] = $lotCol !== '' ? "`{$lotCol}` AS small_pack_no" : "'' AS small_pack_no";
            $selects[] = $trayCol !== '' ? "`{$trayCol}` AS tray_no" : "'' AS tray_no";

            $order = ["`{$dateCol}` DESC"];
            if (!empty($schema['id_col'])) {
                $order[] = "`{$schema['id_col']}` DESC";
            }

            $sql = "SELECT " . implode(", ", $selects) . "
                    FROM `ShipingList` {$where['sql']}
                    ORDER BY " . implode(', ', $order) . "
                    LIMIT 1";
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
