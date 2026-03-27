<?php
if (!function_exists('jtgpt_tool_shipping_detect_columns')) {
    function jtgpt_tool_shipping_detect_columns(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $cols = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `ShipingList`");
            foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                $name = strtolower(trim((string)($row['Field'] ?? '')));
                if ($name !== '') {
                    $cols[$name] = (string)($row['Field'] ?? '');
                }
            }
        } catch (Throwable $e) {
            $cols = [];
        }
        $cache = $cols;
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_pick_column')) {
    function jtgpt_tool_shipping_pick_column(array $cols, array $candidates, array $contains = []): ?string {
        foreach ($candidates as $name) {
            $key = strtolower(trim((string)$name));
            if ($key !== '' && isset($cols[$key])) {
                return $cols[$key];
            }
        }
        if ($contains) {
            foreach ($cols as $lower => $real) {
                foreach ($contains as $needle) {
                    $needle = strtolower(trim((string)$needle));
                    if ($needle !== '' && strpos($lower, $needle) !== false) {
                        return $real;
                    }
                }
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
        $cache = [
            'date' => jtgpt_tool_shipping_pick_column($cols, ['ship_datetime', 'ship_date', 'out_date', '출하일자', '출하일', '납품일자'], ['ship', '출하']),
            'qty' => jtgpt_tool_shipping_pick_column($cols, ['qty', 'ship_qty', 'out_qty', '출고수량', '수량'], ['qty', '수량']),
            'lot' => jtgpt_tool_shipping_pick_column($cols, ['small_pack_no', 'smallpack_no', 'lot_no', 'lot', '소포장_no', '소포장no'], ['small_pack', 'lot', '소포장']),
            'tray' => jtgpt_tool_shipping_pick_column($cols, ['tray_no', 'tray', 'trayno'], ['tray']),
            'part_name' => jtgpt_tool_shipping_pick_column($cols, ['part_name', 'partname', '품번명', 'model_name'], ['part_name', '품번명']),
            'ship_to' => jtgpt_tool_shipping_pick_column($cols, ['ship_to', 'customer', 'customer_name', '납품처', '고객사'], ['ship_to', '납품처', 'customer']),
            'id' => jtgpt_tool_shipping_pick_column($cols, ['id', 'idx', 'no'], []),
        ];
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_where')) {
    function jtgpt_tool_shipping_where(PDO $pdo, array $args): array {
        $schema = jtgpt_tool_shipping_schema($pdo);
        $where = [];
        $params = [];

        $from = trim((string)($args['from'] ?? date('Y-m-d')));
        $to = trim((string)($args['to'] ?? $from));
        $dateCol = (string)($schema['date'] ?? '');
        if ($dateCol !== '') {
            $where[] = "`{$dateCol}` >= :from_dt";
            $where[] = "`{$dateCol}` < :to_dt";
            $params[':from_dt'] = $from . ' 00:00:00';
            $params[':to_dt'] = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
        }

        $partName = trim((string)($args['part_name'] ?? ''));
        $partCol = (string)($schema['part_name'] ?? '');
        if ($partName !== '' && $partCol !== '') {
            $where[] = "`{$partCol}` LIKE :part_name";
            $params[':part_name'] = '%' . $partName . '%';
        }

        $customer = trim((string)($args['customer'] ?? ''));
        $shipToCol = (string)($schema['ship_to'] ?? '');
        if ($customer !== '' && $shipToCol !== '') {
            $where[] = "`{$shipToCol}` LIKE :ship_to";
            $params[':ship_to'] = '%' . $customer . '%';
        }

        return [
            'sql' => $where ? ('WHERE ' . implode(' AND ', $where)) : '',
            'params' => $params,
            'schema' => $schema,
        ];
    }
}

if (!function_exists('jtgpt_tool_shipping_summary')) {
    function jtgpt_tool_shipping_summary(PDO $pdo, array $args): array {
        try {
            $where = jtgpt_tool_shipping_where($pdo, $args);
            $schema = (array)($where['schema'] ?? []);
            $qtyCol = (string)($schema['qty'] ?? '');
            $lotCol = (string)($schema['lot'] ?? '');
            $trayCol = (string)($schema['tray'] ?? '');
            $partCol = (string)($schema['part_name'] ?? '');

            $sumQty = $qtyCol !== '' ? "COALESCE(SUM(`{$qtyCol}`), 0)" : '0';
            $lotCount = $lotCol !== '' ? "COUNT(DISTINCT `{$lotCol}`)" : '0';
            $trayCount = $trayCol !== '' ? "COUNT(DISTINCT `{$trayCol}`)" : '0';
            $partCount = $partCol !== '' ? "COUNT(DISTINCT `{$partCol}`)" : '0';

            $sql = "SELECT COUNT(*) AS row_count, {$sumQty} AS total_qty, {$lotCount} AS lot_count, {$trayCount} AS tray_count, {$partCount} AS part_count FROM `ShipingList` {$where['sql']}";
            $st = $pdo->prepare($sql);
            $st->execute((array)$where['params']);
            $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $top = [];
            if (empty($args['part_name']) && $partCol !== '' && $qtyCol !== '') {
                $sqlTop = "SELECT `{$partCol}` AS part_name, COALESCE(SUM(`{$qtyCol}`),0) AS total_qty FROM `ShipingList` {$where['sql']} GROUP BY `{$partCol}` ORDER BY total_qty DESC, `{$partCol}` ASC LIMIT 5";
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
            return [
                'found' => false,
                'row_count' => 0,
                'total_qty' => 0,
                'lot_count' => 0,
                'tray_count' => 0,
                'part_count' => 0,
                'top_parts' => [],
                '__error' => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('jtgpt_tool_shipping_last_ship_date')) {
    function jtgpt_tool_shipping_last_ship_date(PDO $pdo, array $args): array {
        try {
            $where = jtgpt_tool_shipping_where($pdo, $args);
            $schema = (array)($where['schema'] ?? []);
            $dateCol = (string)($schema['date'] ?? '');
            $partCol = (string)($schema['part_name'] ?? '');
            $shipToCol = (string)($schema['ship_to'] ?? '');
            $qtyCol = (string)($schema['qty'] ?? '');
            $lotCol = (string)($schema['lot'] ?? '');
            $trayCol = (string)($schema['tray'] ?? '');
            $idCol = (string)($schema['id'] ?? '');

            $select = [];
            if ($dateCol !== '') $select[] = "`{$dateCol}` AS ship_datetime";
            if ($partCol !== '') $select[] = "`{$partCol}` AS part_name";
            if ($shipToCol !== '') $select[] = "`{$shipToCol}` AS ship_to";
            if ($qtyCol !== '') $select[] = "`{$qtyCol}` AS qty";
            if ($lotCol !== '') $select[] = "`{$lotCol}` AS small_pack_no";
            if ($trayCol !== '') $select[] = "`{$trayCol}` AS tray_no";
            if (!$select) {
                $select[] = '*';
            }

            $order = [];
            if ($dateCol !== '') $order[] = "`{$dateCol}` DESC";
            if ($idCol !== '') $order[] = "`{$idCol}` DESC";
            $orderSql = $order ? (' ORDER BY ' . implode(', ', $order)) : '';

            $sql = "SELECT " . implode(', ', $select) . " FROM `ShipingList` {$where['sql']}{$orderSql} LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute((array)$where['params']);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            return [
                'found' => is_array($row) && !empty($row),
                'row' => $row,
            ];
        } catch (Throwable $e) {
            return [
                'found' => false,
                'row' => null,
                '__error' => $e->getMessage(),
            ];
        }
    }
}
