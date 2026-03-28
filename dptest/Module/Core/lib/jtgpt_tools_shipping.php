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
            'prod_date' => jtgpt_tool_shipping_column_name($pdo, ['prod_date', 'production_date', 'proddate', 'prod_dt']),
            'tool' => jtgpt_tool_shipping_column_name($pdo, ['revision', 'rev', 'tool', 'tool_no']),
            'cavity' => jtgpt_tool_shipping_column_name($pdo, ['cavity', 'cav', 'cavity_no', 'cav_no']),
            'model' => jtgpt_tool_shipping_column_name($pdo, ['model']),
            'part_name' => jtgpt_tool_shipping_column_name($pdo, ['part_name', '품번명', 'item_name', 'product_name']),
            'part_code' => jtgpt_tool_shipping_column_name($pdo, ['part_code', '품번코드', 'item_code']),
            'customer' => jtgpt_tool_shipping_column_name($pdo, ['ship_to', '납품처', 'customer', 'customer_name']),
            'id' => jtgpt_tool_shipping_column_name($pdo, ['id']),
        ];
        return $cache;
    }
}

if (!function_exists('jtgpt_tool_shipping_normalize_date_text')) {
    function jtgpt_tool_shipping_normalize_date_text($value): string {
        $text = trim((string)$value);
        if ($text === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(\d{4})[\.\/](\d{1,2})[\.\/](\d{1,2})/', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        $ts = strtotime($text);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return $text;
    }
}

if (!function_exists('jtgpt_tool_shipping_model_label')) {
    function jtgpt_tool_shipping_model_label(array $row): string {
        foreach (['model_name', 'part_name', 'part_code'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return '-';
    }
}

if (!function_exists('jtgpt_tool_shipping_sort_values')) {
    function jtgpt_tool_shipping_sort_values(array $values): array {
        $values = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $values), static function (string $value): bool {
            return $value !== '';
        }));
        usort($values, static function (string $a, string $b): int {
            return strnatcasecmp($a, $b);
        });
        return array_values(array_unique($values));
    }
}

if (!function_exists('jtgpt_tool_shipping_group_summary_rows')) {
    function jtgpt_tool_shipping_group_summary_rows(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $label = jtgpt_tool_shipping_model_label($row);
            $key = mb_strtoupper($label, 'UTF-8');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'model_name' => $label,
                    'total_qty' => 0,
                    'prod_dates' => [],
                    'tools' => [],
                    'cavities' => [],
                    'tool_cavity_map' => [],
                ];
            }
            $groups[$key]['total_qty'] += (int)($row['qty'] ?? 0);
            $prodDate = jtgpt_tool_shipping_normalize_date_text($row['prod_date'] ?? '');
            if ($prodDate !== '') $groups[$key]['prod_dates'][$prodDate] = $prodDate;
            $tool = strtoupper(trim((string)($row['tool'] ?? '')));
            $cavity = strtoupper(trim((string)($row['cavity'] ?? '')));
            if ($tool !== '') {
                $groups[$key]['tools'][$tool] = $tool;
                if (!isset($groups[$key]['tool_cavity_map'][$tool])) {
                    $groups[$key]['tool_cavity_map'][$tool] = [];
                }
                if ($cavity !== '') {
                    $groups[$key]['tool_cavity_map'][$tool][$cavity] = $cavity;
                }
            }
            if ($cavity !== '') $groups[$key]['cavities'][$cavity] = $cavity;
        }

        $out = [];
        foreach ($groups as $group) {
            $prodDates = jtgpt_tool_shipping_sort_values(array_values($group['prod_dates']));
            $tools = jtgpt_tool_shipping_sort_values(array_values($group['tools']));
            $cavities = jtgpt_tool_shipping_sort_values(array_values($group['cavities']));
            $toolCavityMap = [];
            foreach (($group['tool_cavity_map'] ?? []) as $tool => $toolCavities) {
                $toolKey = strtoupper(trim((string)$tool));
                if ($toolKey === '') continue;
                $toolCavityMap[$toolKey] = jtgpt_tool_shipping_sort_values(array_values((array)$toolCavities));
            }
            uksort($toolCavityMap, static function (string $a, string $b): int {
                return strnatcasecmp($a, $b);
            });
            $out[] = [
                'model_name' => $group['model_name'],
                'total_qty' => (int)$group['total_qty'],
                'prod_dates' => $prodDates,
                'tools' => $tools,
                'cavities' => $cavities,
                'tool_cavity_map' => $toolCavityMap,
                'lot_count' => count($prodDates),
                'tool_count' => count($tools),
                'cavity_count' => count($cavities),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $qtyCmp = ((int)($b['total_qty'] ?? 0)) <=> ((int)($a['total_qty'] ?? 0));
            if ($qtyCmp !== 0) return $qtyCmp;
            return strnatcasecmp((string)($a['model_name'] ?? ''), (string)($b['model_name'] ?? ''));
        });

        return $out;
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
            $lotBaseCol = !empty($schema['prod_date']) ? ('DATE(`' . $schema['prod_date'] . '`)') : (!empty($schema['lot']) ? ('`' . $schema['lot'] . '`') : 'NULL');
            $trayCol = !empty($schema['tray']) ? ('`' . $schema['tray'] . '`') : 'NULL';
            $partCol = !empty($schema['part_name']) ? ('`' . $schema['part_name'] . '`') : (!empty($schema['part_code']) ? ('`' . $schema['part_code'] . '`') : 'NULL');
            $sql = "
                SELECT
                    COUNT(*) AS row_count,
                    COALESCE(SUM($qtyCol), 0) AS total_qty,
                    COUNT(DISTINCT $lotBaseCol) AS lot_count,
                    COUNT(DISTINCT $trayCol) AS tray_count,
                    COUNT(DISTINCT $partCol) AS part_count
                FROM ShipingList
                {$where['sql']}
            ";
            $st = $pdo->prepare($sql);
            $st->execute($where['params']);
            $summary = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $selectCols = [];
            $selectCols[] = !empty($schema['model']) ? ('`' . $schema['model'] . '` AS model_name') : "'' AS model_name";
            $selectCols[] = $partCol !== 'NULL' ? ($partCol . ' AS part_name') : "'' AS part_name";
            $selectCols[] = !empty($schema['part_code']) ? ('`' . $schema['part_code'] . '` AS part_code') : "'' AS part_code";
            $selectCols[] = $qtyCol . ' AS qty';
            $selectCols[] = !empty($schema['prod_date']) ? ('`' . $schema['prod_date'] . '` AS prod_date') : "'' AS prod_date";
            $selectCols[] = !empty($schema['tool']) ? ('`' . $schema['tool'] . '` AS tool') : "'' AS tool";
            $selectCols[] = !empty($schema['cavity']) ? ('`' . $schema['cavity'] . '` AS cavity') : "'' AS cavity";

            $sqlRows = "
                SELECT " . implode(",\n                    ", $selectCols) . "
                FROM ShipingList
                {$where['sql']}
            ";
            $st = $pdo->prepare($sqlRows);
            $st->execute($where['params']);
            $groupRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $modelSummaries = jtgpt_tool_shipping_group_summary_rows($groupRows);

            $top = [];
            if ($modelSummaries) {
                foreach (array_slice($modelSummaries, 0, 5) as $row) {
                    $top[] = [
                        'part_name' => $row['model_name'] ?? '-',
                        'total_qty' => (int)($row['total_qty'] ?? 0),
                    ];
                }
            }

            return [
                'found' => ((int)($summary['row_count'] ?? 0)) > 0,
                'row_count' => (int)($summary['row_count'] ?? 0),
                'total_qty' => (int)($summary['total_qty'] ?? 0),
                'lot_count' => (int)($summary['lot_count'] ?? 0),
                'tray_count' => (int)($summary['tray_count'] ?? 0),
                'part_count' => (int)($summary['part_count'] ?? 0),
                'top_parts' => $top,
                'model_summaries' => $modelSummaries,
            ];
        } catch (Throwable $e) {
            return ['found' => false, 'row_count' => 0, 'total_qty' => 0, 'lot_count' => 0, 'tray_count' => 0, 'part_count' => 0, 'top_parts' => [], 'model_summaries' => []];
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
