<?php

declare(strict_types=1);

if (!function_exists('sfr_header_key')) {
    function sfr_header_key($v): string {
        $s = trim((string)$v);
        $s = preg_replace('/[\s_\-]+/u', '', $s);
        return strtolower((string)$s);
    }
}

if (!function_exists('sfr_detect_ship_to_from_filename')) {
    function sfr_detect_ship_to_from_filename(string $name): string {
        $hasLgit = (stripos($name, 'LGIT') !== false);
        $hasJahwa = (stripos($name, 'Jahwa') !== false);
        if ($hasLgit === $hasJahwa) return '';
        return $hasLgit ? '엘지이노텍(주)' : '자화전자(주)';
    }
}

if (!function_exists('sfr_find_headers')) {
    function sfr_find_headers(array $headers): array {
        $packAliases = ['포장번호'];
        $partAliases = ['partname', '품목명', '품번명'];
        $qtyAliases  = ['총납품수량', '출고수량', '출하수량', '품번출수량', '중포수', '수량'];
        $out = ['pack_no' => null, 'part' => null, 'qty' => null];
        foreach ($headers as $i => $v) {
            $k = sfr_header_key($v);
            if ($out['pack_no'] === null && in_array($k, $packAliases, true)) $out['pack_no'] = $i;
            if ($out['part'] === null && in_array($k, $partAliases, true)) $out['part'] = $i;
            if ($out['qty'] === null && in_array($k, $qtyAliases, true)) $out['qty'] = $i;
        }
        return $out;
    }
}

if (!function_exists('sfr_normalize_pack_no')) {
    function sfr_normalize_pack_no($v): string {
        $s = trim((string)$v);
        $s = preg_replace('/\s+/u', '', $s);
        return (string)$s;
    }
}

if (!function_exists('sfr_pack_barcode_tail')) {
    function sfr_pack_barcode_tail($v): string {
        $s = trim((string)$v);
        if ($s === '') return '';
        $p = preg_split('~[\\/]~', $s);
        return sfr_normalize_pack_no((string)end($p));
    }
}

if (!function_exists('sfr_parse_qty')) {
    function sfr_parse_qty($v): int {
        if (is_int($v)) return $v;
        if (is_float($v)) return (int)round($v);
        $s = str_replace(',', '', trim((string)$v));
        if (preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return (int)round((float)$m[0]);
        return 0;
    }
}

if (!function_exists('sfr_selection_dir')) {
    function sfr_selection_dir(): string {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dptest_file_report_selection';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir;
    }
}

if (!function_exists('sfr_selection_path')) {
    function sfr_selection_path(string $token): string {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);
        return sfr_selection_dir() . DIRECTORY_SEPARATOR . 'sel_' . $token . '.json';
    }
}

if (!function_exists('sfr_selection_save')) {
    function sfr_selection_save(array $data): string {
        $token = bin2hex(random_bytes(16));
        $data['created_at'] = time();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents(sfr_selection_path($token), $json) === false) {
            throw new RuntimeException('파일선택 정보를 저장할 수 없습니다.');
        }
        return $token;
    }
}

if (!function_exists('sfr_selection_load')) {
    function sfr_selection_load(string $token, int $ttl = 7200): ?array {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);
        if ($token === '') return null;
        $path = sfr_selection_path($token);
        if (!is_file($path)) return null;
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) return null;
        $created = (int)($data['created_at'] ?? 0);
        if ($created <= 0 || time() - $created > $ttl) {
            @unlink($path);
            return null;
        }
        return $data;
    }
}

if (!function_exists('sfr_selection_sql')) {
    function sfr_selection_sql(array $packNos, string $prefix = 'sfr_pack_'): array {
        $clean = [];
        foreach ($packNos as $v) {
            $v = sfr_normalize_pack_no($v);
            if ($v !== '') $clean[$v] = true;
        }
        $packNos = array_keys($clean);
        if (!$packNos) return ['', []];

        $ph = [];
        $params = [];
        foreach ($packNos as $i => $v) {
            $k = ':' . $prefix . $i;
            $ph[] = $k;
            $params[$k] = $v;
        }

        // QA 출하내역의 포장바코드 끝부분(마지막 / 뒤)을 정확히 비교한다.
        // 포장바코드가 비어 있는 구형 행만 pack_no를 폴백으로 사용한다.
        $expr = "TRIM(SUBSTRING_INDEX(COALESCE(NULLIF(TRIM(pack_barcode),''), NULLIF(TRIM(pack_no),''), ''), '/', -1))";
        return [' AND ' . $expr . ' IN (' . implode(',', $ph) . ') ', $params];
    }
}

if (!function_exists('sfr_extract_sheet_rows')) {
    function sfr_extract_sheet_rows(array $matrix, int $headerScanRows = 30): array {
        $headerIndex = null;
        $headers = null;
        $limit = min(count($matrix), max(1, $headerScanRows));

        for ($i = 0; $i < $limit; $i++) {
            $row = is_array($matrix[$i] ?? null) ? array_values($matrix[$i]) : [];
            $found = sfr_find_headers($row);
            if ($found['pack_no'] !== null) {
                $headerIndex = $i;
                $headers = $found;
                break;
            }
        }

        if ($headerIndex === null || !$headers) {
            return ['header_found' => false, 'header_row' => null, 'headers' => [], 'rows' => []];
        }

        $rows = [];
        for ($i = $headerIndex + 1; $i < count($matrix); $i++) {
            $row = is_array($matrix[$i] ?? null) ? array_values($matrix[$i]) : [];
            $pack = sfr_normalize_pack_no($row[$headers['pack_no']] ?? '');
            if ($pack === '') continue;
            $rows[] = [
                'pack_no' => $pack,
                'part' => $headers['part'] !== null ? trim((string)($row[$headers['part']] ?? '')) : '',
                'qty' => $headers['qty'] !== null ? sfr_parse_qty($row[$headers['qty']] ?? 0) : 0,
            ];
        }

        return [
            'header_found' => true,
            'header_row' => $headerIndex,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }
}

if (!function_exists('sfr_choose_sheet_candidate')) {
    function sfr_choose_sheet_candidate(array $candidates): array {
        if (!$candidates) return ['sheet' => '', 'rows' => [], 'headers' => []];
        usort($candidates, static function($a, $b) {
            $ac = is_array($a['rows'] ?? null) ? count($a['rows']) : 0;
            $bc = is_array($b['rows'] ?? null) ? count($b['rows']) : 0;
            if ($ac !== $bc) return $bc <=> $ac;
            return strcmp((string)($a['sheet'] ?? ''), (string)($b['sheet'] ?? ''));
        });
        return $candidates[0];
    }
}

if (!function_exists('sfr_build_preview')) {
    function sfr_build_preview(array $fileRows, array $dbRows): array {
        // 파일은 '포장번호'가 같은 여러 LOT 행이 존재할 수 있다(Jahwa 형식).
        // 따라서 중복을 오류로 보지 않고 포장번호 단위로 수량을 합산한다.
        $fileByPack = [];
        $fileAmbiguous = [];
        foreach ($fileRows as $row) {
            $pack = sfr_normalize_pack_no($row['pack_no'] ?? '');
            if ($pack === '') continue;
            $part = trim((string)($row['part'] ?? ''));
            if (!isset($fileByPack[$pack])) {
                $fileByPack[$pack] = ['qty' => 0, 'parts' => []];
            }
            $fileByPack[$pack]['qty'] += sfr_parse_qty($row['qty'] ?? 0);
            if ($part !== '') $fileByPack[$pack]['parts'][$part] = true;
        }
        foreach ($fileByPack as $pack => $info) {
            if (count($info['parts']) > 1) $fileAmbiguous[$pack] = true;
        }

        $dbByPack = [];
        foreach ($dbRows as $row) {
            $pack = sfr_pack_barcode_tail($row['pack_barcode'] ?? '');
            if ($pack === '') $pack = sfr_normalize_pack_no($row['pack_no'] ?? '');
            if ($pack === '') continue;
            $part = trim((string)($row['part_name'] ?? ''));
            if (!isset($dbByPack[$pack])) $dbByPack[$pack] = [];
            $dbByPack[$pack][] = [
                'part' => $part,
                'qty' => sfr_parse_qty($row['qty'] ?? 0),
            ];
        }

        $grouped = [];
        $unmatched = [];
        $ambiguous = $fileAmbiguous;
        $matchedPackNos = [];

        foreach ($fileByPack as $pack => $fileInfo) {
            $matches = $dbByPack[$pack] ?? [];
            if (!$matches) {
                $unmatched[$pack] = true;
                continue;
            }

            $parts = [];
            foreach ($matches as $m) {
                if ($m['part'] !== '') $parts[$m['part']] = true;
            }
            if (count($parts) !== 1) {
                $ambiguous[$pack] = true;
                continue;
            }

            $part = (string)array_key_first($parts);
            if (!isset($grouped[$part])) {
                $grouped[$part] = ['part' => $part, 'file_qty' => 0, 'db_qty' => 0, 'pack_count' => 0];
            }
            $grouped[$part]['file_qty'] += (int)$fileInfo['qty'];
            foreach ($matches as $m) $grouped[$part]['db_qty'] += (int)$m['qty'];
            $grouped[$part]['pack_count']++;
            $matchedPackNos[$pack] = true;
        }

        ksort($grouped, SORT_NATURAL);
        $rows = [];
        $fileTotal = 0;
        $dbTotal = 0;
        foreach ($grouped as $g) {
            $g['diff'] = (int)$g['db_qty'] - (int)$g['file_qty'];
            $g['match'] = ($g['diff'] === 0);
            $fileTotal += (int)$g['file_qty'];
            $dbTotal += (int)$g['db_qty'];
            $rows[] = $g;
        }

        $unmatched = array_keys($unmatched);
        $ambiguous = array_keys($ambiguous);
        $matchedPackNos = array_keys($matchedPackNos);
        sort($unmatched, SORT_NATURAL);
        sort($ambiguous, SORT_NATURAL);
        sort($matchedPackNos, SORT_NATURAL);

        return [
            'rows' => $rows,
            'totals' => [
                'file_qty' => $fileTotal,
                'db_qty' => $dbTotal,
                'diff' => $dbTotal - $fileTotal,
            ],
            'file_pack_count' => count($fileByPack),
            'matched_pack_count' => count($matchedPackNos),
            'matched_pack_nos' => $matchedPackNos,
            'unmatched_pack_nos' => $unmatched,
            'ambiguous_pack_nos' => $ambiguous,
        ];
    }
}
