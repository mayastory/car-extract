<?php
if (!function_exists('jtgpt_tool_format_int')) {
    function jtgpt_tool_format_int($v): string {
        return number_format((int)$v);
    }
}

if (!function_exists('jtgpt_tool_shipping_detect_columns')) {
    function jtgpt_tool_shipping_detect_columns(PDO $pdo): array {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `ShipingList`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower((string)$r['Field'])] = true;
        }
        $cache = $cols;
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_where')) {
    function jtgpt_tool_shipping_where(PDO $pdo, array $args): array {
        $cols = jtgpt_tool_shipping_detect_columns($pdo);
        $where = ['ship_datetime >= :from_dt', 'ship_datetime < :to_dt'];
        $params = [
            ':from_dt' => ($args['from'] ?? date('Y-m-d')) . ' 00:00:00',
            ':to_dt'   => date('Y-m-d 00:00:00', strtotime(($args['to'] ?? date('Y-m-d')) . ' +1 day')),
        ];
        $partName = trim((string)($args['part_name'] ?? ''));
        if ($partName !== '') {
            $where[] = 'part_name LIKE :part_name';
            $params[':part_name'] = '%' . $partName . '%';
        }
        $customer = trim((string)($args['customer'] ?? ''));
        if ($customer !== '' && isset($cols['ship_to'])) {
            $where[] = 'ship_to LIKE :ship_to';
            $params[':ship_to'] = '%' . $customer . '%';
        }
        return ['sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
    }
}

if (!function_exists('jtgpt_tool_shipping_summary')) {
    function jtgpt_tool_shipping_summary(PDO $pdo, array $args): array {
        $where = jtgpt_tool_shipping_where($pdo, $args);
        $sql = "
            SELECT
                COUNT(*) AS row_count,
                COALESCE(SUM(qty), 0) AS total_qty,
                COUNT(DISTINCT small_pack_no) AS lot_count,
                COUNT(DISTINCT tray_no) AS tray_count,
                COUNT(DISTINCT part_name) AS part_count
            FROM ShipingList
            {$where['sql']}
        ";
        $st = $pdo->prepare($sql);
        $st->execute($where['params']);
        $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $top = [];
        if (empty($args['part_name'])) {
            $sqlTop = "
                SELECT part_name, COALESCE(SUM(qty), 0) AS total_qty
                FROM ShipingList
                {$where['sql']}
                GROUP BY part_name
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
    }
}

if (!function_exists('jtgpt_tool_shipping_last_ship_date')) {
    function jtgpt_tool_shipping_last_ship_date(PDO $pdo, array $args): array {
        $cols = jtgpt_tool_shipping_detect_columns($pdo);
        $where = [];
        $params = [];
        $partName = trim((string)($args['part_name'] ?? ''));
        $customer = trim((string)($args['customer'] ?? ''));
        if ($partName !== '') {
            $where[] = 'part_name LIKE :part_name';
            $params[':part_name'] = '%' . $partName . '%';
        }
        if ($customer !== '' && isset($cols['ship_to'])) {
            $where[] = 'ship_to LIKE :ship_to';
            $params[':ship_to'] = '%' . $customer . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "
            SELECT ship_datetime, part_name, ship_to, qty, small_pack_no, tray_no
            FROM ShipingList
            {$whereSql}
            ORDER BY ship_datetime DESC, id DESC
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return [
            'found' => is_array($row) && !empty($row),
            'row' => $row,
        ];
    }
}
