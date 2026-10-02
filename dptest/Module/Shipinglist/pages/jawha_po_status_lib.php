<?php
declare(strict_types=1);

if (!function_exists('jawha_po_status_default_parts')) {
    function jawha_po_status_default_parts(): array {
        return [
            'MEM-IR-BASE',
            'MEM-X-CARRIER',
            'MEM-Y-CARRIER',
            'MEM-Z-CARRIER',
            'MEM-Z-STOPPER',
        ];
    }
}

if (!function_exists('jawha_po_status_pick_cavs')) {
    function jawha_po_status_pick_cavs(array $cavities): array {
        $vals = [];
        foreach ($cavities as $c) {
            $c = (int)$c;
            if ($c >= 1 && $c <= 8) $vals[$c] = true;
        }
        $vals = array_keys($vals);
        sort($vals, SORT_NUMERIC);
        if (!$vals) return [1, 2, 3, 4];
        $hasLow = false;
        $hasHigh = false;
        foreach ($vals as $c) {
            if ($c <= 4) $hasLow = true;
            if ($c >= 5) $hasHigh = true;
        }
        if ($hasLow && !$hasHigh) return [1, 2, 3, 4];
        if ($hasHigh && !$hasLow) return [5, 6, 7, 8];
        return $vals;
    }
}

if (!function_exists('jawha_po_status_build_groups')) {
    /**
     * PO tuple rows -> 소포장 상세내역 스타일의 모델별 Tool/생산일/Cavity matrix.
     * $reportContrib key: part|prod_date|tool => [report_finish_id => true]
     * $partTotals key: part => linked report_finish.parts_json의 ship_qty 합계
     */
    function jawha_po_status_build_groups(array $items, array $reportContrib = [], array $partTotals = []): array {
        $parts = jawha_po_status_default_parts();
        $seenParts = array_fill_keys($parts, true);
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $part = trim((string)($row['part_name'] ?? ''));
            if ($part !== '' && !isset($seenParts[$part])) {
                $seenParts[$part] = true;
                $parts[] = $part;
            }
        }
        foreach (array_keys($partTotals) as $part) {
            $part = trim((string)$part);
            if ($part !== '' && !isset($seenParts[$part])) {
                $seenParts[$part] = true;
                $parts[] = $part;
            }
        }

        $itemsByPart = [];
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $part = trim((string)($row['part_name'] ?? ''));
            $date = trim((string)($row['prod_date'] ?? ''));
            $tool = strtoupper(trim((string)($row['tool'] ?? '')));
            $cav = (int)($row['cavity'] ?? 0);
            if ($part === '' || $tool === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $cav < 1 || $cav > 8) continue;
            $itemsByPart[$part][] = [
                'part_name' => $part,
                'prod_date' => $date,
                'tool' => $tool,
                'cavity' => $cav,
                'seen_count' => max(1, (int)($row['seen_count'] ?? 1)),
            ];
        }

        $groups = [];
        foreach ($parts as $part) {
            $rows = $itemsByPart[$part] ?? [];
            $cavVals = array_map(static fn(array $r): int => (int)$r['cavity'], $rows);
            $cavs = jawha_po_status_pick_cavs($cavVals);
            $matrix = [];
            foreach ($rows as $r) {
                $key = $r['tool'] . "\x1F" . $r['prod_date'];
                if (!isset($matrix[$key])) {
                    $cmap = [];
                    foreach ($cavs as $c) $cmap[(int)$c] = false;
                    $contribKey = $part . '|' . $r['prod_date'] . '|' . $r['tool'];
                    $matrix[$key] = [
                        'part_name' => $part,
                        'tool' => $r['tool'],
                        'date' => $r['prod_date'],
                        'cavs' => $cmap,
                        'report_count' => count((array)($reportContrib[$contribKey] ?? [])),
                    ];
                }
                if (!array_key_exists($r['cavity'], $matrix[$key]['cavs'])) {
                    $matrix[$key]['cavs'][$r['cavity']] = false;
                    ksort($matrix[$key]['cavs'], SORT_NUMERIC);
                }
                $matrix[$key]['cavs'][$r['cavity']] = true;
            }
            $rowsOut = array_values($matrix);
            usort($rowsOut, static function(array $a, array $b): int {
                $t = strnatcasecmp((string)$a['tool'], (string)$b['tool']);
                if ($t !== 0) return $t;
                return strcmp((string)$a['date'], (string)$b['date']);
            });
            $groups[] = [
                'part_name' => $part,
                'cavs' => array_values(array_map('intval', $cavs)),
                'rows' => $rowsOut,
                'total_qty' => max(0, (int)($partTotals[$part] ?? 0)),
                'tuple_count' => count($rows),
            ];
        }
        return $groups;
    }
}

if (!function_exists('jawha_po_status_side_lines')) {
    function jawha_po_status_side_lines(array $rows, array $cavs): array {
        $dates = [];
        $toolCavs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $date = trim((string)($row['date'] ?? ''));
            $tool = strtoupper(trim((string)($row['tool'] ?? '')));
            if ($date !== '') $dates[$date] = true;
            if ($tool === '') continue;
            $cmap = (array)($row['cavs'] ?? []);
            foreach ($cavs as $c) {
                $c = (int)$c;
                if (!empty($cmap[$c])) $toolCavs[$tool][$c] = true;
            }
            foreach ($cmap as $c => $v) {
                $c = (int)$c;
                if ($v && $c >= 1 && $c <= 8) $toolCavs[$tool][$c] = true;
            }
        }
        $dateList = array_keys($dates);
        sort($dateList, SORT_STRING);
        $tools = array_keys($toolCavs);
        natcasesort($tools);
        $out = $dateList;
        foreach ($tools as $tool) {
            $cv = array_keys($toolCavs[$tool]);
            sort($cv, SORT_NUMERIC);
            $out[] = sprintf('%-4s %-11s /Cav', $tool, $cv ? implode(',', $cv) : '-');
        }
        return $out;
    }
}

if (!function_exists('jawha_po_status_part_totals_from_reports')) {
    function jawha_po_status_part_totals_from_reports(array $reportRows): array {
        $totals = [];
        foreach ($reportRows as $row) {
            if (!is_array($row)) continue;
            $raw = trim((string)($row['parts_json'] ?? ''));
            if ($raw === '') continue;
            $payload = json_decode($raw, true);
            if (!is_array($payload) || !isset($payload['parts']) || !is_array($payload['parts'])) continue;
            foreach ($payload['parts'] as $partRow) {
                if (!is_array($partRow)) continue;
                $part = trim((string)($partRow['part'] ?? ''));
                $qty = (int)($partRow['ship_qty'] ?? 0);
                if ($part === '' || $qty <= 0) continue;
                $totals[$part] = ($totals[$part] ?? 0) + $qty;
            }
        }
        return $totals;
    }
}
