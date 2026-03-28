<?php
if (!function_exists('jtgpt_tool_format_int')) {
    function jtgpt_tool_format_int($v): string {
        return number_format((int)$v);
    }
}

if (!function_exists('jtgpt_tool_shipping_scalar_text')) {
    function jtgpt_tool_shipping_scalar_text($v): string {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_scalar($v)) return (string)$v;
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('jtgpt_tool_shipping_detect_columns')) {
    function jtgpt_tool_shipping_detect_columns(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `ShipingList`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $field = (string)($r['Field'] ?? '');
            if ($field === '') continue;
            $cols[strtolower($field)] = $field;
        }
        $cache = $cols;
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_column_name')) {
    function jtgpt_tool_shipping_column_name(PDO $pdo, array $aliases): ?string {
        $cols = jtgpt_tool_shipping_detect_columns($pdo);
        $norm = [];
        foreach ($cols as $lower => $actual) {
            $norm[$lower] = $actual;
            $norm[preg_replace('/[^a-z0-9]+/', '', $lower)] = $actual;
        }
        foreach ($aliases as $alias) {
            $alias = strtolower(trim((string)$alias));
            if ($alias === '') continue;
            if (isset($norm[$alias])) return $norm[$alias];
            $compact = preg_replace('/[^a-z0-9]+/', '', $alias);
            if ($compact !== '' && isset($norm[$compact])) return $norm[$compact];
        }
        return null;
    }
}

if (!function_exists('jtgpt_tool_shipping_schema')) {
    function jtgpt_tool_shipping_schema(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $cache = [
            'date' => jtgpt_tool_shipping_column_name($pdo, ['ship_date', 'ship_datetime', 'ship_dt', 'shipping_date', 'shipment_date']),
            'qty' => jtgpt_tool_shipping_column_name($pdo, ['qty', 'quantity', 'ship_qty', 'out_qty']),
            'lot' => jtgpt_tool_shipping_column_name($pdo, ['small_pack_no', 'smallpackno', 'small_pack', 'small_pack_tray_no', 'lotid', 'customer_lotid']),
            'tray' => jtgpt_tool_shipping_column_name($pdo, ['tray_no', 'trayno', 'tray']),
            'part_name' => jtgpt_tool_shipping_column_name($pdo, ['part_name', '품번명', 'item_name', 'product_name']),
            'part_code' => jtgpt_tool_shipping_column_name($pdo, ['part_code', '품번코드', 'item_code']),
            'customer' => jtgpt_tool_shipping_column_name($pdo, ['ship_to', '납품처', 'customer', 'customer_name']),
            'id' => jtgpt_tool_shipping_column_name($pdo, ['id']),
        ];
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_where')) {
    function jtgpt_tool_shipping_where(PDO $pdo, array $args): array {
        $schema = jtgpt_tool_shipping_schema($pdo);
        $where = [];
        $params = [];

        if (!empty($schema['date'])) {
            $dateCol = '`' . $schema['date'] . '`';
            $from = (string)($args['from'] ?? date('Y-m-d'));
            $to = (string)($args['to'] ?? date('Y-m-d'));
            $where[] = "$dateCol >= :from_dt";
            $where[] = "$dateCol < :to_dt";
            $params[':from_dt'] = $from . ' 00:00:00';
            $params[':to_dt'] = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
        }

        $partName = trim((string)($args['part_name'] ?? ''));
        if ($partName !== '') {
            $targetCol = $schema['part_name'] ?: $schema['part_code'];
            if (!empty($targetCol)) {
                $where[] = '`' . $targetCol . '` LIKE :part_name';
                $params[':part_name'] = '%' . $partName . '%';
            }
        }

        $customer = trim((string)($args['customer'] ?? ''));
        if ($customer !== '' && !empty($schema['customer'])) {
            $where[] = '`' . $schema['customer'] . '` LIKE :ship_to';
            $params[':ship_to'] = '%' . $customer . '%';
        }

        return ['sql' => ($where ? ('WHERE ' . implode(' AND ', $where)) : ''), 'params' => $params, 'schema' => $schema];
    }
}

if (!function_exists('jtgpt_tool_shipping_summary')) {
    function jtgpt_tool_shipping_summary(PDO $pdo, array $args): array {
        try {
            $where = jtgpt_tool_shipping_where($pdo, $args);
            $schema = $where['schema'] ?? jtgpt_tool_shipping_schema($pdo);
            $qtyCol = !empty($schema['qty']) ? ('`' . $schema['qty'] . '`') : '0';
            $lotCol = !empty($schema['lot']) ? ('`' . $schema['lot'] . '`') : 'NULL';
            $trayCol = !empty($schema['tray']) ? ('`' . $schema['tray'] . '`') : 'NULL';
            $partCol = !empty($schema['part_name']) ? ('`' . $schema['part_name'] . '`') : (!empty($schema['part_code']) ? ('`' . $schema['part_code'] . '`') : 'NULL');
            $sql = "
                SELECT
                    COUNT(*) AS row_count,
                    COALESCE(SUM($qtyCol), 0) AS total_qty,
                    COUNT(DISTINCT $lotCol) AS lot_count,
                    COUNT(DISTINCT $trayCol) AS tray_count,
                    COUNT(DISTINCT $partCol) AS part_count
                FROM ShipingList
                {$where['sql']}
            ";
            $st = $pdo->prepare($sql);
            $st->execute($where['params']);
            $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $top = [];
            if (empty($args['part_name']) && $partCol !== 'NULL') {
                $sqlTop = "
                    SELECT $partCol AS part_name, COALESCE(SUM($qtyCol), 0) AS total_qty
                    FROM ShipingList
                    {$where['sql']}
                    GROUP BY $partCol
                    ORDER BY total_qty DESC, part_name ASC
                    LIMIT 5
                ";
                $st = $pdo->prepare($sqlTop);
                $st->execute($where['params']);
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
            $schema = $where['schema'] ?? jtgpt_tool_shipping_schema($pdo);
            $dateCol = !empty($schema['date']) ? ('`' . $schema['date'] . '`') : 'NULL';
            $partCol = !empty($schema['part_name']) ? ('`' . $schema['part_name'] . '`') : (!empty($schema['part_code']) ? ('`' . $schema['part_code'] . '`') : 'NULL');
            $customerCol = !empty($schema['customer']) ? ('`' . $schema['customer'] . '`') : 'NULL';
            $qtyCol = !empty($schema['qty']) ? ('`' . $schema['qty'] . '`') : 'NULL';
            $lotCol = !empty($schema['lot']) ? ('`' . $schema['lot'] . '`') : 'NULL';
            $trayCol = !empty($schema['tray']) ? ('`' . $schema['tray'] . '`') : 'NULL';
            $order = [];
            if (!empty($schema['date'])) $order[] = '`' . $schema['date'] . '` DESC';
            if (!empty($schema['id'])) $order[] = '`' . $schema['id'] . '` DESC';
            if (!$order) $order[] = '1 DESC';
            $sql = "
                SELECT
                    $dateCol AS ship_datetime,
                    $partCol AS part_name,
                    $customerCol AS ship_to,
                    $qtyCol AS qty,
                    $lotCol AS small_pack_no,
                    $trayCol AS tray_no
                FROM ShipingList
                {$where['sql']}
                ORDER BY " . implode(', ', $order) . "
                LIMIT 1
            ";
            $st = $pdo->prepare($sql);
            $st->execute($where['params']);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            return ['found' => is_array($row) && !empty($row), 'row' => $row];
        } catch (Throwable $e) {
            return ['found' => false, 'row' => null];
        }
    }
}
