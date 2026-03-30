<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/../lib/jtgpt_session.php';
require_once __DIR__ . '/../lib/jtgpt_planner.php';
require_once __DIR__ . '/../lib/jtgpt_tools_quality.php';
require_once __DIR__ . '/../lib/jtgpt_tools_shipping.php';

function jtgpt_json_response(array $payload): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jtgpt_root_path(): string {
    if (defined('JTMES_ROOT')) {
        $root = (string) constant('JTMES_ROOT');
        if ($root !== '') return $root;
    }
    return dirname(__DIR__, 3);
}

function jtgpt_require_dp_config(): void {
    static $loaded = false;
    if ($loaded) return;
    $root = jtgpt_root_path();
    $candidates = [
        $root . '/config/dp_config.php',
        $root . '/dp_config.php',
        dirname($root) . '/config/dp_config.php',
        dirname($root) . '/dp_config.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            $loaded = true;
            return;
        }
    }
}

function jtgpt_try_callable_result(string $fn) {
    if (!function_exists($fn)) return null;
    try {
        $res = $fn();
        if ($res instanceof PDO || $res instanceof mysqli) return $res;
    } catch (Throwable $e) {
    }
    return null;
}

function jtgpt_resolve_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    jtgpt_require_dp_config();

    if (function_exists('dp_get_pdo')) {
        try {
            $res = dp_get_pdo();
            if ($res instanceof PDO) {
                $pdo = $res;
                return $pdo;
            }
        } catch (Throwable $e) {
        }
    }

    foreach (['pdo','db','dbh','pdo_db','pdo_conn'] as $key) {
        if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof PDO) {
            $pdo = $GLOBALS[$key];
            return $pdo;
        }
    }

    foreach (['getPDO','getPdo','db_pdo','pdo_conn','dbconn','dbConn','getDB','getDb','dp_pdo','dp_get_pdo'] as $fn) {
        $res = jtgpt_try_callable_result($fn);
        if ($res instanceof PDO) {
            $pdo = $res;
            return $pdo;
        }
    }

    $host = null;
    $name = null;
    $user = null;
    $pass = null;
    $charset = 'utf8mb4';
    foreach (['DB_HOST','MYSQL_HOST','DB_SERVER','HOST'] as $c) if ($host === null && defined($c)) $host = constant($c);
    foreach (['DB_NAME','MYSQL_DB','DB_DATABASE','DB'] as $c) if ($name === null && defined($c)) $name = constant($c);
    foreach (['DB_USER','MYSQL_USER','DB_USERNAME','USER'] as $c) if ($user === null && defined($c)) $user = constant($c);
    foreach (['DB_PASS','MYSQL_PASS','DB_PASSWORD','PASSWORD'] as $c) if ($pass === null && defined($c)) $pass = constant($c);
    foreach (['DB_CHARSET','MYSQL_CHARSET'] as $c) if (defined($c)) $charset = (string)constant($c);

    foreach (['db_host','mysql_host','db_server'] as $g) if ($host === null && isset($GLOBALS[$g])) $host = $GLOBALS[$g];
    foreach (['db_name','mysql_db','db_database'] as $g) if ($name === null && isset($GLOBALS[$g])) $name = $GLOBALS[$g];
    foreach (['db_user','mysql_user','db_username'] as $g) if ($user === null && isset($GLOBALS[$g])) $user = $GLOBALS[$g];
    foreach (['db_pass','mysql_pass','db_password'] as $g) if ($pass === null && isset($GLOBALS[$g])) $pass = $GLOBALS[$g];

    if ($host !== null && $name !== null && $user !== null) {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=' . ($charset ?: 'utf8mb4');
        $pdo = new PDO($dsn, (string)$user, (string)$pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    }

    throw new RuntimeException('dp_config.php에서 DB 연결을 찾지 못했습니다.');
}

if (!function_exists('jtgpt_tool_format_float')) {
    function jtgpt_tool_format_float($v): string {
        if ($v === null || $v === '') return '';
        $num = (float)$v;
        $txt = number_format($num, 4, '.', '');
        return rtrim(rtrim($txt, '0'), '.');
    }
}

function jtgpt_quality_debug_enabled(): bool {
    if (defined('DEBUG_JTGPT')) {
        return (bool) constant('DEBUG_JTGPT');
    }
    if (isset($_GET['jtgpt_debug'])) {
        $v = strtolower(trim((string)$_GET['jtgpt_debug']));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
    return false;
}

function jtgpt_quality_output_format(array $args): string {
    $output = strtolower(trim((string)($args['output'] ?? 'chat')));
    return in_array($output, ['excel', 'csv', 'table', 'chat'], true) ? $output : 'chat';
}

function jtgpt_quality_temp_dir_fs(): string {
    $candidates = [];
    $sysTmp = (string)sys_get_temp_dir();
    if ($sysTmp !== '') {
        $candidates[] = rtrim($sysTmp, '/\\') . '/jtgpt_exports';
    }
    $candidates[] = rtrim(jtgpt_root_path(), '/\\') . '/runtime/jtgpt_exports';
    $candidates[] = rtrim(jtgpt_root_path(), '/\\') . '/tmp/jtgpt_exports';

    foreach ($candidates as $dir) {
        if ($dir === '') {
            continue;
        }
        if (is_dir($dir)) {
            return $dir;
        }
        if (@mkdir($dir, 0777, true) || is_dir($dir)) {
            return $dir;
        }
    }

    throw new RuntimeException('임시 export 폴더를 만들 수 없습니다.');
}

function jtgpt_quality_current_script_url(): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return $script;
    }
    $uri = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
    return $uri !== '' ? $uri : '';
}

function jtgpt_quality_download_mime(string $format): string {
    $format = strtolower(trim($format));
    if ($format === 'csv') {
        return 'text/csv; charset=UTF-8';
    }
    return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}

function jtgpt_quality_cleanup_download_registry(): void {
    if (!isset($_SESSION['jtgpt_downloads']) || !is_array($_SESSION['jtgpt_downloads'])) {
        $_SESSION['jtgpt_downloads'] = [];
        return;
    }

    $now = time();
    foreach ($_SESSION['jtgpt_downloads'] as $token => $meta) {
        if (!is_array($meta)) {
            unset($_SESSION['jtgpt_downloads'][$token]);
            continue;
        }
        $createdAt = (int)($meta['created_at'] ?? 0);
        $downloadedAt = (int)($meta['downloaded_at'] ?? 0);
        $downloadTtl = max(60, (int)($meta['download_ttl_sec'] ?? 600));
        $pendingTtl = max(300, (int)($meta['pending_ttl_sec'] ?? 1800));
        $expired = false;
        if ($downloadedAt > 0) {
            $expired = ($downloadedAt + $downloadTtl) <= $now;
        } elseif ($createdAt > 0) {
            $expired = ($createdAt + $pendingTtl) <= $now;
        } else {
            $expired = true;
        }
        if ($expired) {
            $path = (string)($meta['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
            unset($_SESSION['jtgpt_downloads'][$token]);
        }
    }
}

function jtgpt_quality_register_download(string $path, string $filename, string $format, int $rowCount): array {
    jtgpt_quality_cleanup_download_registry();

    $token = bin2hex(random_bytes(16));
    $createdAt = time();
    $_SESSION['jtgpt_downloads'][$token] = [
        'path' => $path,
        'name' => $filename,
        'format' => $format,
        'mime' => jtgpt_quality_download_mime($format),
        'row_count' => $rowCount,
        'created_at' => $createdAt,
        'downloaded_at' => 0,
        'download_ttl_sec' => 600,
        'pending_ttl_sec' => 1800,
    ];

    $script = jtgpt_quality_current_script_url();
    $url = $script !== '' ? ($script . '?jtgpt_download=' . rawurlencode($token)) : ('?jtgpt_download=' . rawurlencode($token));

    return [
        'token' => $token,
        'url' => $url,
        'expires_in_sec' => 600,
    ];
}

function jtgpt_quality_stream_download(string $token): void {
    jtgpt_quality_cleanup_download_registry();

    $meta = $_SESSION['jtgpt_downloads'][$token] ?? null;
    if (!is_array($meta)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '다운로드 링크가 없거나 만료되었습니다.';
        exit;
    }

    $path = (string)($meta['path'] ?? '');
    if ($path === '' || !is_file($path)) {
        unset($_SESSION['jtgpt_downloads'][$token]);
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '파일을 찾지 못했습니다.';
        exit;
    }

    $_SESSION['jtgpt_downloads'][$token]['downloaded_at'] = time();
    $name = (string)($meta['name'] ?? basename($path));
    $mime = (string)($meta['mime'] ?? 'application/octet-stream');
    session_write_close();

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header("Content-Disposition: attachment; filename=\"" . rawurlencode($name) . "\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    readfile($path);
    exit;
}

function jtgpt_quality_export_filename(array $args, string $ext): string {
    $modules = $args['modules'] ?? [];
    if (!is_array($modules)) $modules = [$modules];
    $modules = array_values(array_filter(array_map(static function ($module): string {
        return strtolower(trim((string)$module));
    }, $modules)));
    $modulePart = $modules ? implode('-', $modules) : 'quality';

    $partName = strtoupper(trim((string)($args['part_name'] ?? 'all')));
    $partName = preg_replace('/[^A-Z0-9\-]+/', '-', $partName);
    $partName = trim((string)$partName, '-');
    if ($partName === '') $partName = 'ALL';

    $stamp = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Ymd_His');
    return 'jtgpt_' . $modulePart . '_' . strtolower($partName) . '_' . $stamp . '.' . $ext;
}

function jtgpt_quality_export_rows_normalize(array $rows, array $args, array $result = []): array {
    $out = [];
    $defaultModule = strtolower(trim((string)($result['module'] ?? $args['module'] ?? '')));
    $defaultLabel = trim((string)($result['label'] ?? strtoupper($defaultModule)));
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $moduleLabel = trim((string)($row['module_label'] ?? $defaultLabel));
        $module = strtolower(trim((string)($row['module'] ?? $defaultModule)));
        $ngSide = strtoupper(trim((string)($row['ng_side'] ?? '')));
        $line = [
            '날짜' => (string)($row['event_date'] ?? '-'),
            '타입' => $moduleLabel !== '' ? $moduleLabel : strtoupper($module),
            'Tool/Cavity' => trim((string)($row['tool_cavity'] ?? '')),
            'FAI' => trim((string)($row['point_no'] ?? '')),
            'NG' => $ngSide,
            '기준값' => jtgpt_tool_format_float($row['ng_limit'] ?? null),
            '측정값' => jtgpt_tool_format_float($row['value'] ?? null),
            'USL' => jtgpt_tool_format_float($row['usl'] ?? null),
            'LSL' => jtgpt_tool_format_float($row['lsl'] ?? null),
            '모델' => trim((string)($row['part_name'] ?? $args['part_name'] ?? '')),
        ];
        if ($ngSide !== '') {
            $line['__cell_styles'] = ['날짜' => 'ng_date'];
        }
        $out[] = $line;
    }
    return $out;
}

function jtgpt_quality_export_cert_remaining_rows(array $result, array $args): array {
    $scope = trim(jtgpt_format_scope($args));
    $fieldLabel = jtgpt_quality_cert_field_label_text($result, $args);
    $rows = [];
    foreach ((array)($result['model_groups'] ?? []) as $modelGroup) {
        $modelName = trim((string)($modelGroup['model'] ?? '모델미상'));
        foreach ((array)($modelGroup['tools'] ?? []) as $toolGroup) {
            $toolLabel = jtgpt_quality_cert_tool_label_text((string)($toolGroup['tool'] ?? '-'));
            $toolCount = (int)($toolGroup['count'] ?? 0);
            $toolNgCount = (int)($toolGroup['ng_count'] ?? 0);
            $rows[] = [
                '구분' => 'TOOL',
                '기간' => $scope,
                '플래그' => $fieldLabel,
                '모델' => $modelName,
                '차수' => $toolLabel,
                '캐비티' => '',
                '성적서 가능 건수' => (string)$toolCount,
                '사용 불가 예상(NG)' => (string)$toolNgCount,
            ];
            foreach ((array)($toolGroup['cavities'] ?? []) as $cavityGroup) {
                $rows[] = [
                    '구분' => 'CAVITY',
                    '기간' => $scope,
                    '플래그' => $fieldLabel,
                    '모델' => $modelName,
                    '차수' => $toolLabel,
                    '캐비티' => (string)($cavityGroup['cavity'] ?? '-'),
                    '성적서 가능 건수' => (string)((int)($cavityGroup['count'] ?? 0)),
                    '사용 불가 예상(NG)' => '',
                ];
            }
        }
    }
    return $rows;
}

function jtgpt_quality_export_source_rows(PDO $pdo, string $tool, array $args, array $result): array {
    if ($tool === 'quality_recent_ng_rows') {
        return jtgpt_quality_export_rows_normalize((array)($result['rows'] ?? []), $args, $result);
    }
    if ($tool === 'quality_point_detail') {
        $rows = [];
        foreach ((array)($result['latest_rows'] ?? []) as $row) {
            $rows[] = $row;
        }
        foreach ((array)($result['results'] ?? []) as $item) {
            foreach ((array)($item['latest_rows'] ?? []) as $row) {
                $rows[] = $row;
            }
        }
        return jtgpt_quality_export_rows_normalize($rows, $args, $result);
    }
    if ($tool === 'quality_cert_remaining') {
        return jtgpt_quality_export_cert_remaining_rows($result, $args);
    }

    $workingArgs = $args;
    $workingArgs['limit'] = null;
    $recent = jtgpt_tool_quality_recent_ng_rows_multi($pdo, $workingArgs);
    return jtgpt_quality_export_rows_normalize((array)($recent['rows'] ?? []), $workingArgs, $recent);
}

function jtgpt_quality_write_csv_file(string $path, array $rows): void {
    $fp = fopen($path, 'wb');
    if (!$fp) {
        throw new RuntimeException('CSV 파일을 만들 수 없습니다.');
    }
    fwrite($fp, "ï»¿");
    $headers = $rows ? array_keys($rows[0]) : ['날짜','타입','Tool/Cavity','FAI','NG','기준값','측정값','USL','LSL','모델'];
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $header) {
            $line[] = (string)($row[$header] ?? '');
        }
        fputcsv($fp, $line);
    }
    fclose($fp);
}

function jtgpt_quality_xlsx_col_name(int $index): string {
    $index = max(1, $index);
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function jtgpt_quality_xlsx_xml_escape(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function jtgpt_quality_cert_detail_sheet_name(string $modelName, int $index): string {
    $name = trim($modelName);
    if ($name === '') {
        $name = 'MODEL' . $index;
    }
    $name = preg_replace('/[\\\/?\*\[\]:]+/u', '_', $name);
    $name = trim((string)$name);
    if ($name === '') {
        $name = 'MODEL' . $index;
    }
    if (mb_strlen($name, 'UTF-8') > 31) {
        $name = mb_substr($name, 0, 31, 'UTF-8');
    }
    return $name;
}

function jtgpt_quality_cert_detail_build_sheets(array $result, array $args): array {
    $sheets = [];
    $sheetIndex = 1;
    foreach ((array)($result['model_groups'] ?? []) as $modelGroup) {
        $modelName = trim((string)($modelGroup['model'] ?? '모델미상'));
        $toolGroups = array_values((array)($modelGroup['tools'] ?? []));
        if (!$toolGroups) {
            continue;
        }
        $grid = [];
        $colCursor = 1;
        foreach ($toolGroups as $toolGroup) {
            $cavityGroups = array_values((array)($toolGroup['cavities'] ?? []));
            if (!$cavityGroups) {
                $cavityGroups = [['cavity' => '-', 'dates' => []]];
            }
            $startCol = $colCursor;
            $grid[1]['values'][$startCol] = jtgpt_quality_cert_tool_label_text((string)($toolGroup['tool'] ?? '-'));
            $grid[1]['styles'][$startCol] = 1;
            $blockBottom = 2;
            foreach (array_values($cavityGroups) as $offset => $cavityGroup) {
                $col = $startCol + $offset;
                $grid[2]['values'][$col] = (string)($cavityGroup['cavity'] ?? '-');
                $grid[2]['styles'][$col] = 1;
                $rowNo = 3;
                foreach ((array)($cavityGroup['dates'] ?? []) as $dateRow) {
                    $grid[$rowNo]['values'][$col] = (string)($dateRow['date'] ?? '');
                    if (!empty($dateRow['is_ng'])) {
                        $grid[$rowNo]['styles'][$col] = 2;
                    }
                    $rowNo++;
                }
                $blockBottom = max($blockBottom, $rowNo - 1);
            }
            $toolNgCount = (int)($toolGroup['ng_count'] ?? 0);
            if ($toolNgCount > 0) {
                $grid[$blockBottom + 1]['values'][$startCol] = '사용 불가 예상(NG): ' . $toolNgCount . '건';
                $grid[$blockBottom + 1]['styles'][$startCol] = 2;
                $blockBottom++;
            }
            $colCursor = $startCol + max(1, count($cavityGroups)) + 1;
        }

        ksort($grid);
        $sheetRows = [];
        foreach ($grid as $rowNo => $rowDef) {
            $valuesMap = (array)($rowDef['values'] ?? []);
            $stylesMap = (array)($rowDef['styles'] ?? []);
            if (!$valuesMap) {
                continue;
            }
            $maxCol = max(array_keys($valuesMap));
            $values = [];
            $styles = [];
            for ($col = 1; $col <= $maxCol; $col++) {
                $values[] = (string)($valuesMap[$col] ?? '');
                $styles[] = (int)($stylesMap[$col] ?? 0);
            }
            $sheetRows[] = ['values' => $values, 'styles' => $styles];
        }

        $sheets[] = [
            'name' => jtgpt_quality_cert_detail_sheet_name($modelName, $sheetIndex++),
            'rows' => $sheetRows,
        ];
    }
    return $sheets;
}

function jtgpt_quality_sheet_rows_to_xml(array $sheetRows, array $styleMap): string {
    $sheetXml = [];
    $sheetXml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheetXml[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($sheetRows as $rIdx => $row) {
        $rowNo = $rIdx + 1;
        $values = (array)($row['values'] ?? []);
        $styles = (array)($row['styles'] ?? []);
        $sheetXml[] = '<row r="' . $rowNo . '">';
        foreach ($values as $cIdx => $value) {
            if ($value === '' && (int)($styles[$cIdx] ?? 0) === 0) {
                continue;
            }
            $cellRef = jtgpt_quality_xlsx_col_name($cIdx + 1) . $rowNo;
            $styleIndex = (int)($styles[$cIdx] ?? $styleMap['default']);
            $style = $styleIndex > 0 ? ' s="' . $styleIndex . '"' : '';
            if ($value !== '' && is_numeric($value) && !preg_match('/^0\d+/', $value)) {
                $sheetXml[] = '<c r="' . $cellRef . '"' . $style . '><v>' . jtgpt_quality_xlsx_xml_escape((string)$value) . '</v></c>';
            } else {
                $sheetXml[] = '<c r="' . $cellRef . '" t="inlineStr"' . $style . '><is><t>' . jtgpt_quality_xlsx_xml_escape((string)$value) . '</t></is></c>';
            }
        }
        $sheetXml[] = '</row>';
    }
    $sheetXml[] = '</sheetData></worksheet>';
    return implode('', $sheetXml);
}

function jtgpt_quality_styles_xml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FF9C0006"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFDE9D9"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function jtgpt_quality_write_cert_detail_xlsx_file(string $path, array $result, array $args): void {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive 확장을 찾지 못했습니다.');
    }
    $styleMap = ['default' => 0, 'header' => 1, 'ng_date' => 2];
    $sheets = jtgpt_quality_cert_detail_build_sheets($result, $args);
    if (!$sheets) {
        throw new RuntimeException('상세 엑셀 시트 데이터를 만들 수 없습니다.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('엑셀 파일을 만들 수 없습니다.');
    }

    $contentTypes = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">', '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>', '<Default Extension="xml" ContentType="application/xml"/>', '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>', '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>', '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>', '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'];
    $workbookSheets = [];
    $workbookRels = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'];
    foreach (array_values($sheets) as $i => $sheet) {
        $sheetId = $i + 1;
        $sheetName = (string)($sheet['name'] ?? ('Sheet' . $sheetId));
        $sheetXml = jtgpt_quality_sheet_rows_to_xml((array)($sheet['rows'] ?? []), $styleMap);
        $zip->addFromString('xl/worksheets/sheet' . $sheetId . '.xml', $sheetXml);
        $contentTypes[] = '<Override PartName="/xl/worksheets/sheet' . $sheetId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets[] = '<sheet name="' . jtgpt_quality_xlsx_xml_escape($sheetName) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
        $workbookRels[] = '<Relationship Id="rId' . $sheetId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetId . '.xml"/>';
    }
    $styleRelId = count($sheets) + 1;
    $workbookRels[] = '<Relationship Id="rId' . $styleRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $workbookRels[] = '</Relationships>';
    $contentTypes[] = '</Types>';

    $zip->addFromString('[Content_Types].xml', implode('', $contentTypes));
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . implode('', $workbookSheets) . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', implode('', $workbookRels));
    $zip->addFromString('xl/styles.xml', jtgpt_quality_styles_xml());
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>JTGPT Export</dc:title><dc:creator>JTGPT</dc:creator></cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>JTGPT</Application></Properties>');
    $zip->close();
}

function jtgpt_quality_write_xlsx_file(string $path, array $rows): void {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive 확장을 찾지 못했습니다.');
    }

    $styleMap = [
        'default' => 0,
        'header' => 1,
        'ng_date' => 2,
    ];

    $headers = ['날짜','타입','Tool/Cavity','FAI','NG','기준값','측정값','USL','LSL','모델'];
    if ($rows) {
        $headers = [];
        foreach (array_keys($rows[0]) as $key) {
            if (strncmp((string)$key, '__', 2) === 0) continue;
            $headers[] = (string)$key;
        }
        if (!$headers) {
            $headers = ['날짜','타입','Tool/Cavity','FAI','NG','기준값','측정값','USL','LSL','모델'];
        }
    }

    $sheetRows = [];
    $sheetRows[] = ['values' => $headers, 'styles' => array_fill(0, count($headers), $styleMap['header'])];
    foreach ($rows as $row) {
        $line = [];
        $lineStyles = [];
        $cellStyles = is_array($row['__cell_styles'] ?? null) ? (array)$row['__cell_styles'] : [];
        foreach ($headers as $header) {
            $line[] = (string)($row[$header] ?? '');
            $styleKey = (string)($cellStyles[$header] ?? 'default');
            $lineStyles[] = $styleMap[$styleKey] ?? $styleMap['default'];
        }
        $sheetRows[] = ['values' => $line, 'styles' => $lineStyles];
    }

    $sheetXml = [];
    $sheetXml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheetXml[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($sheetRows as $rIdx => $row) {
        $rowNo = $rIdx + 1;
        $values = (array)($row['values'] ?? []);
        $styles = (array)($row['styles'] ?? []);
        $sheetXml[] = '<row r="' . $rowNo . '">';
        foreach ($values as $cIdx => $value) {
            $cellRef = jtgpt_quality_xlsx_col_name($cIdx + 1) . $rowNo;
            $styleIndex = (int)($styles[$cIdx] ?? $styleMap['default']);
            $style = $styleIndex > 0 ? ' s="' . $styleIndex . '"' : '';
            if ($value !== '' && is_numeric($value) && !preg_match('/^0\d+/', $value)) {
                $sheetXml[] = '<c r="' . $cellRef . '"' . $style . '><v>' . jtgpt_quality_xlsx_xml_escape((string)$value) . '</v></c>';
            } else {
                $sheetXml[] = '<c r="' . $cellRef . '" t="inlineStr"' . $style . '><is><t>' . jtgpt_quality_xlsx_xml_escape((string)$value) . '</t></is></c>';
            }
        }
        $sheetXml[] = '</row>';
    }
    $sheetXml[] = '</sheetData></worksheet>';
    $sheetXml = implode('', $sheetXml);

    $stylesXml = jtgpt_quality_styles_xml();

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('엑셀 파일을 만들 수 없습니다.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="JTGPT" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>JTGPT Export</dc:title><dc:creator>JTGPT</dc:creator></cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>JTGPT</Application></Properties>');
    $zip->close();
}

function jtgpt_quality_create_export(PDO $pdo, string $tool, array $args, array $result): ?array {
    $output = jtgpt_quality_output_format($args);
    if (!in_array($output, ['excel', 'csv'], true)) {
        return null;
    }

    $dir = jtgpt_quality_temp_dir_fs();
    $ext = $output === 'csv' ? 'csv' : 'xlsx';
    $filename = jtgpt_quality_export_filename($args, $ext);
    $path = rtrim($dir, '/\\') . '/' . $filename;

    if ($tool === 'quality_cert_remaining' && $output === 'excel' && !empty($args['detail_export'])) {
        jtgpt_quality_write_cert_detail_xlsx_file($path, $result, $args);
        $download = jtgpt_quality_register_download($path, $filename, $output, (int)($result['total_count'] ?? 0));
        return [
            'name' => $filename,
            'path' => $path,
            'url' => $download['url'],
            'token' => $download['token'],
            'row_count' => (int)($result['total_count'] ?? 0),
            'format' => $output,
            'expires_in_sec' => $download['expires_in_sec'],
        ];
    }

    $rows = jtgpt_quality_export_source_rows($pdo, $tool, $args, $result);
    if (!$rows) {
        return null;
    }
    if ($output === 'csv') {
        jtgpt_quality_write_csv_file($path, $rows);
    } else {
        jtgpt_quality_write_xlsx_file($path, $rows);
    }

    $download = jtgpt_quality_register_download($path, $filename, $output, count($rows));
    return [
        'name' => $filename,
        'path' => $path,
        'url' => $download['url'],
        'token' => $download['token'],
        'row_count' => count($rows),
        'format' => $output,
        'expires_in_sec' => $download['expires_in_sec'],
    ];
}

function jtgpt_format_scope(array $args): string {
    $parts = [];
    $range = $args['range']['label'] ?? '';
    if ($range !== '') $parts[] = $range;

    $partName = trim((string)($args['part_name'] ?? ''));
    if ($partName !== '') $parts[] = $partName;

    $tools = $args['tools'] ?? [];
    if (!is_array($tools)) {
        $tools = [$tools];
    }
    $tools = array_values(array_filter(array_map(static function ($tool): string {
        return strtoupper(trim((string)$tool));
    }, $tools)));
    if (!$tools) {
        $tool = trim((string)($args['tool'] ?? ''));
        if ($tool !== '') $tools[] = strtoupper($tool);
    }
    if ($tools) {
        $parts[] = implode(',', $tools) . '툴';
    }

    $cavities = $args['cavities'] ?? [];
    if (!is_array($cavities)) {
        $cavities = [$cavities];
    }
    $cavities = array_values(array_filter(array_map(static function ($cavity): string {
        return strtoupper(trim((string)$cavity));
    }, $cavities)));
    if (!$cavities) {
        $cavity = trim((string)($args['cavity'] ?? ''));
        if ($cavity !== '') $cavities[] = strtoupper($cavity);
    }
    if ($cavities) {
        $parts[] = implode(',', $cavities);
    }

    return $parts ? ('[' . implode(' / ', $parts) . ']') : '';
}

function jtgpt_shipping_tool_cavity_lines(array $row): array {
    $toolCavityMap = $row['tool_cavity_map'] ?? [];
    if (!is_array($toolCavityMap)) {
        $toolCavityMap = [];
    }

    $lines = [];
    foreach ($toolCavityMap as $tool => $cavities) {
        $tool = strtoupper(trim((string)$tool));
        if ($tool === '') {
            continue;
        }
        $cavities = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, (array)$cavities)));
        $lines[] = $cavities ? ($tool . ': ' . implode(',', $cavities)) : $tool;
    }
    if ($lines) {
        return $lines;
    }

    $tools = array_values(array_filter(array_map(static function ($value): string {
        return trim((string)$value);
    }, (array)($row['tools'] ?? []))));
    $cavities = array_values(array_filter(array_map(static function ($value): string {
        return trim((string)$value);
    }, (array)($row['cavities'] ?? []))));
    if ($tools && $cavities) {
        return ['Tool ' . implode(', ', $tools) . ' | Cavity ' . implode(', ', $cavities)];
    }
    if ($tools) {
        return ['Tool ' . implode(', ', $tools)];
    }
    if ($cavities) {
        return ['Cavity ' . implode(', ', $cavities)];
    }
    return [];
}

function jtgpt_answer_shipping_summary(array $result, array $args): string {
    if (empty($result['found'])) {
        return '조건에 맞는 출하 데이터가 없습니다.';
    }
    $metric = (string)($args['metric'] ?? 'summary');
    $scope = jtgpt_format_scope($args);
    if ($metric === 'qty') {
        return trim($scope . ' 총 출하수량은 ' . jtgpt_tool_format_int($result['total_qty']) . ' EA 입니다.');
    }
    if ($metric === 'lot_count') {
        return trim($scope . ' 생산일자 기준 LOT 수는 ' . jtgpt_tool_format_int($result['lot_count']) . '개 입니다.');
    }
    if ($metric === 'tray_count') {
        return trim($scope . ' Tray 수는 ' . jtgpt_tool_format_int($result['tray_count']) . '개 입니다.');
    }
    $lines = [trim($scope . ' 출하 요약입니다.')];
    $lines[] = '- 총 수량: ' . jtgpt_tool_format_int($result['total_qty']) . ' EA';

    $modelSummaries = $result['model_summaries'] ?? [];
    if (is_array($modelSummaries) && $modelSummaries) {
        foreach ($modelSummaries as $row) {
            if (!is_array($row)) continue;
            $label = trim((string)($row['model_name'] ?? '-'));
            if ($label === '') $label = '-';

            $lines[] = '';
            $lines[] = '- ' . $label . ': ' . jtgpt_tool_format_int($row['total_qty'] ?? 0) . ' EA';

            $prodDates = array_values(array_filter(array_map(static function ($value): string {
                return trim((string)$value);
            }, (array)($row['prod_dates'] ?? []))));
            if ($prodDates) {
                $lines[] = '';
                $lines[] = '생산일';
                foreach ($prodDates as $prodDate) {
                    $lines[] = $prodDate;
                }
            }

            $toolCavityLines = jtgpt_shipping_tool_cavity_lines($row);
            if ($toolCavityLines) {
                $lines[] = '';
                $lines[] = 'Tool/Cavity';
                foreach ($toolCavityLines as $toolCavityLine) {
                    $lines[] = $toolCavityLine;
                }
            }
        }
    } elseif (!empty($result['top_parts'])) {
        foreach ($result['top_parts'] as $row) {
            $lines[] = '- ' . ($row['part_name'] ?? '-') . ' : ' . jtgpt_tool_format_int($row['total_qty'] ?? 0) . ' EA';
        }
    }
    return implode("
", $lines);
}

function jtgpt_answer_shipping_last_ship(array $result, array $args): string {
    if (empty($result['found']) || empty($result['row'])) {
        return '조건에 맞는 최근 출하 이력이 없습니다.';
    }
    $row = $result['row'];
    $scope = jtgpt_format_scope($args);
    $lines = [trim($scope . ' 가장 최근 출하 이력입니다.')];
    $lines[] = '- 일시: ' . (string)($row['ship_datetime'] ?? '-');
    $lines[] = '- 품번: ' . (string)($row['part_name'] ?? '-');
    $lines[] = '- 납품처: ' . (string)($row['ship_to'] ?? '-');
    $lines[] = '- 수량: ' . jtgpt_tool_format_int($row['qty'] ?? 0) . ' EA';
    return implode("\n", $lines);
}

function jtgpt_format_ng_limit_value(array $row): string {
    $side = strtoupper(trim((string)($row['ng_side'] ?? '')));
    $limit = $row['ng_limit'] ?? null;
    $value = $row['value'] ?? null;
    $bits = [];
    if ($side !== '' && $limit !== null && $limit !== '') {
        $bits[] = $side . ' ' . jtgpt_tool_format_float($limit);
    } elseif ($side !== '') {
        $bits[] = $side;
    } elseif ($limit !== null && $limit !== '') {
        $bits[] = jtgpt_tool_format_float($limit);
    }
    if ($value !== null && $value !== '') {
        $bits[] = '측정값 ' . jtgpt_tool_format_float($value);
    }
    if (!$bits) {
        if ($value !== null && $value !== '') {
            return '측정값 ' . jtgpt_tool_format_float($value);
        }
        return '';
    }
    return implode(' | ', $bits);
}

function jtgpt_quality_resolution_prompt(array $resolution): string {
    $ambiguous = $resolution['ambiguous_terms'] ?? [];
    if ($ambiguous) {
        $chunks = [];
        foreach ($ambiguous as $item) {
            $term = trim((string)($item['term'] ?? ''));
            $candidates = $item['candidates'] ?? [];
            if ($term === '' || !$candidates) {
                continue;
            }
            $chunks[] = $term . ' → ' . implode(' / ', $candidates);
        }
        if ($chunks) {
            return 'FAI명이 여러 개로 해석됩니다. 어느 것을 찾을까요? ' . implode(' | ', $chunks);
        }
    }

    $unmatched = $resolution['unmatched_terms'] ?? [];
    if ($unmatched) {
        return '해당 조건 범위에서 FAI를 찾지 못했습니다: ' . implode(', ', $unmatched);
    }

    return '';
}

function jtgpt_quality_resolution_tail_lines(array $resolution): array {
    $lines = [];
    $ambiguous = $resolution['ambiguous_terms'] ?? [];
    foreach ($ambiguous as $item) {
        $term = trim((string)($item['term'] ?? ''));
        $candidates = $item['candidates'] ?? [];
        if ($term !== '' && $candidates) {
            $lines[] = '추가 확인 필요: ' . $term . ' → ' . implode(' / ', $candidates);
        }
    }
    $unmatched = $resolution['unmatched_terms'] ?? [];
    if ($unmatched) {
        $lines[] = '미일치 FAI: ' . implode(', ', $unmatched);
    }
    return $lines;
}


function jtgpt_quality_value_filter_text(array $args): string {
    $filter = $args['value_filter'] ?? null;
    if (!is_array($filter) || empty($filter['enabled'])) {
        return '';
    }
    $target = strtolower(trim((string)($filter['target'] ?? 'value')));
    $targetLabel = '측정값';
    if ($target === 'usl') $targetLabel = 'USL';
    elseif ($target === 'lsl') $targetLabel = 'LSL';
    $op = strtolower(trim((string)($filter['op'] ?? '')));
    $v1 = $filter['value1'] ?? null;
    $v2 = $filter['value2'] ?? null;
    if ($v1 === null || $v1 === '') {
        return '';
    }
    switch ($op) {
        case 'gt':
            return $targetLabel . ' > ' . jtgpt_tool_format_float($v1);
        case 'gte':
            return $targetLabel . ' >= ' . jtgpt_tool_format_float($v1);
        case 'lt':
            return $targetLabel . ' < ' . jtgpt_tool_format_float($v1);
        case 'lte':
            return $targetLabel . ' <= ' . jtgpt_tool_format_float($v1);
        case 'eq':
            return $targetLabel . ' = ' . jtgpt_tool_format_float($v1);
        case 'between':
            if ($v2 === null || $v2 === '') return '';
            return $targetLabel . ' ' . jtgpt_tool_format_float($v1) . ' ~ ' . jtgpt_tool_format_float($v2);
    }
    return '';
}

function jtgpt_quality_query_text(array $args, string $kind = 'rows'): string {
    $ngOnly = !array_key_exists('ng_only', $args) || !empty($args['ng_only']);
    $filterText = jtgpt_quality_value_filter_text($args);
    if ($kind === 'count') {
        if ($ngOnly && $filterText !== '') return 'NG + ' . $filterText . ' 건수';
        if ($ngOnly) return 'NG 건수';
        if ($filterText !== '') return $filterText . ' 조건 건수';
        return '건수';
    }
    if ($kind === 'summary') {
        if ($ngOnly && $filterText !== '') return 'NG + ' . $filterText . ' 요약';
        if ($ngOnly) return 'NG 요약';
        if ($filterText !== '') return $filterText . ' 조건 요약';
        return '요약';
    }
    if ($kind === 'top') {
        if ($ngOnly && $filterText !== '') return 'NG + ' . $filterText . ' 많은 포인트';
        if ($ngOnly) return 'NG 많은 포인트';
        if ($filterText !== '') return $filterText . ' 많은 포인트';
        return '많은 포인트';
    }
    if ($kind === 'detail') {
        if ($ngOnly && $filterText !== '') return 'NG + ' . $filterText . ' 상세';
        if ($ngOnly) return 'NG 상세';
        if ($filterText !== '') return $filterText . ' 상세';
        return '상세';
    }
    if ($ngOnly && $filterText !== '') return '최근 NG + ' . $filterText . ' 이력';
    if ($ngOnly) return '최근 NG 이력';
    if ($filterText !== '') return '최근 ' . $filterText . ' 이력';
    return '최근 이력';
}


function jtgpt_quality_interpreted_lines(array $args): array {
    $lines = [];

    $modules = $args['modules'] ?? [];
    if (!is_array($modules)) {
        $modules = [$modules];
    }
    $modules = array_values(array_filter(array_map(static function ($module): string {
        return strtoupper(trim((string)$module));
    }, $modules)));
    if (!$modules) {
        $module = trim((string)($args['module'] ?? ''));
        if ($module !== '') {
            $modules[] = strtoupper($module);
        }
    }
    if ($modules) {
        $lines[] = '해석된 타입: ' . implode(', ', $modules);
    }

    $partName = trim((string)($args['part_name'] ?? ''));
    if ($partName !== '') {
        $lines[] = '해석된 모델: ' . $partName;
    }

    $range = $args['range'] ?? [];
    if (is_array($range)) {
        $from = trim((string)($range['from'] ?? ''));
        $to = trim((string)($range['to'] ?? ''));
        $label = trim((string)($range['label'] ?? ''));
        if ($from !== '' || $to !== '') {
            $lines[] = '해석된 기간: ' . ($label !== '' ? $label . ' ' : '') . '(' . ($from !== '' ? $from : '-') . ' ~ ' . ($to !== '' ? $to : '-') . ')';
        } elseif ($label !== '') {
            $lines[] = '해석된 기간: ' . $label;
        }
    }

    $tools = $args['tools'] ?? [];
    if (!is_array($tools)) {
        $tools = [$tools];
    }
    $tools = array_values(array_filter(array_map(static function ($tool): string {
        return strtoupper(trim((string)$tool));
    }, $tools)));
    if (!$tools) {
        $tool = trim((string)($args['tool'] ?? ''));
        if ($tool !== '') {
            $tools[] = strtoupper($tool);
        }
    }
    if ($tools) {
        $lines[] = '해석된 Tool: ' . implode(', ', $tools);
    }

    $cavities = $args['cavities'] ?? [];
    if (!is_array($cavities)) {
        $cavities = [$cavities];
    }
    $cavities = array_values(array_filter(array_map(static function ($cavity): string {
        return strtoupper(trim((string)$cavity));
    }, $cavities)));
    if (!$cavities) {
        $cavity = trim((string)($args['cavity'] ?? ''));
        if ($cavity !== '') {
            $cavities[] = strtoupper($cavity);
        }
    }
    if ($cavities) {
        $lines[] = '해석된 Cavity: ' . implode(', ', $cavities);
    }

    $points = $args['point_terms'] ?? ($args['fais'] ?? []);
    if (!is_array($points)) {
        $points = [$points];
    }
    $points = array_values(array_filter(array_map(static function ($point): string {
        return strtoupper(trim((string)$point));
    }, $points)));
    if (!$points) {
        $point = trim((string)($args['point_no'] ?? ''));
        if ($point !== '') {
            $points[] = strtoupper($point);
        }
    }
    if ($points) {
        $lines[] = '해석된 FAI: ' . implode(', ', $points);
    }

    $filterText = jtgpt_quality_value_filter_text($args);
    if ($filterText !== '') {
        $lines[] = '값 조건: ' . $filterText;
    }

    return $lines;
}

function jtgpt_quality_no_data_text(array $result, array $args, string $fallback): string {
    $resolutionText = !empty($result['resolution']) ? jtgpt_quality_resolution_prompt((array)$result['resolution']) : '';
    if ($resolutionText !== '') {
        return $resolutionText;
    }

    $lines = [trim((string)($result['error'] ?? $fallback))];
    if (jtgpt_quality_debug_enabled()) {
        foreach (jtgpt_quality_interpreted_lines($args) as $line) {
            $lines[] = '- ' . $line;
        }
    }
    return implode("
", $lines);
}

function jtgpt_answer_quality_top_points(array $result, array $args): string {
    if (empty($result['found'])) {
        return jtgpt_quality_no_data_text($result, $args, '조건에 맞는 포인트가 없습니다.');
    }
    $scope = jtgpt_format_scope($args);
    $limit = (int)($args['limit'] ?? 5);
    $multiModule = !empty($result['multi_module']) || count((array)($args['modules'] ?? [])) > 1;
    $title = $multiModule ? '전체' : ($result['label'] ?? strtoupper((string)($args['module'] ?? '')));
    $lines = [trim($title . ' ' . $scope . ' ' . jtgpt_quality_query_text($args, 'top') . ' Top ' . $limit)];
    foreach (($result['rows'] ?? []) as $i => $row) {
        $prefix = '';
        if ($multiModule) {
            $prefix = trim((string)($row['module_label'] ?? ''));
            $prefix = $prefix !== '' ? ($prefix . ' | ') : '';
        }
        $lines[] = ($i + 1) . ') ' . $prefix . (string)($row['point_no'] ?? '-') . ' - ' . jtgpt_tool_format_int($row['ng_count'] ?? 0) . '건';
    }
    if (!empty($result['resolution'])) {
        foreach (jtgpt_quality_resolution_tail_lines((array)$result['resolution']) as $line) {
            $lines[] = '- ' . $line;
        }
    }
    return implode("
", $lines);
}


function jtgpt_quality_recent_rows_parse_tool_cavity(string $toolCavity): array {
    $toolCavity = strtoupper(trim($toolCavity));
    if ($toolCavity === '') {
        return ['', ''];
    }
    if (preg_match('/^([A-Z0-9]+)\s*#\s*([0-9]+)$/', $toolCavity, $m)) {
        return [trim((string)$m[1]), trim((string)$m[2])];
    }
    if (preg_match('/^([A-Z]+)\s*([0-9]+)$/', $toolCavity, $m)) {
        return [trim((string)$m[1]), trim((string)$m[2])];
    }
    if (preg_match('/^([A-Z0-9]+)\s*[-_/]\s*([0-9]+)$/', $toolCavity, $m)) {
        return [trim((string)$m[1]), trim((string)$m[2])];
    }
    return [$toolCavity, ''];
}

function jtgpt_quality_recent_rows_pick_phrase(string $bucket, array $options, string $fingerprint = ''): string {
    if (!$options) {
        return '';
    }
    if (!isset($_SESSION['jtgpt_phrase_rotate']) || !is_array($_SESSION['jtgpt_phrase_rotate'])) {
        $_SESSION['jtgpt_phrase_rotate'] = [];
    }
    $count = count($options);
    $base = (int)($_SESSION['jtgpt_phrase_rotate'][$bucket] ?? 0);
    $_SESSION['jtgpt_phrase_rotate'][$bucket] = $base + 1;
    $salt = $fingerprint !== '' ? abs(crc32($fingerprint)) : 0;
    $index = ($base + $salt) % $count;
    return (string)$options[$index];
}

function jtgpt_quality_recent_rows_features(array $rows, bool $multiModule = false): array {
    $toolMap = [];
    $moduleToolMap = [];
    $pointStats = [];
    $dateCounts = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $moduleLabel = trim((string)($row['module_label'] ?? ''));
        if ($moduleLabel === '') {
            $moduleLabel = strtoupper(trim((string)($row['module'] ?? '')));
        }
        $toolCavity = trim((string)($row['tool_cavity'] ?? ''));
        [$tool, $cavity] = jtgpt_quality_recent_rows_parse_tool_cavity($toolCavity);
        if ($tool !== '') {
            if (!isset($toolMap[$tool])) {
                $toolMap[$tool] = [];
            }
            if ($cavity !== '') {
                $toolMap[$tool][$cavity] = true;
            }
            if ($multiModule) {
                if (!isset($moduleToolMap[$moduleLabel])) {
                    $moduleToolMap[$moduleLabel] = [];
                }
                if (!isset($moduleToolMap[$moduleLabel][$tool])) {
                    $moduleToolMap[$moduleLabel][$tool] = [];
                }
                if ($cavity !== '') {
                    $moduleToolMap[$moduleLabel][$tool][$cavity] = true;
                }
            }
        }

        $pointNo = trim((string)($row['point_no'] ?? ''));
        if ($pointNo !== '') {
            if (!isset($pointStats[$pointNo])) {
                $pointStats[$pointNo] = [
                    'count' => 0,
                    'tool_cavities' => [],
                    'dates' => [],
                ];
            }
            $pointStats[$pointNo]['count']++;
            if ($tool !== '') {
                if (!isset($pointStats[$pointNo]['tool_cavities'][$tool])) {
                    $pointStats[$pointNo]['tool_cavities'][$tool] = [];
                }
                if ($cavity !== '') {
                    $pointStats[$pointNo]['tool_cavities'][$tool][$cavity] = true;
                }
            }
            $eventDate = trim((string)($row['event_date'] ?? ''));
            if ($eventDate !== '') {
                $pointStats[$pointNo]['dates'][$eventDate] = true;
            }
        }

        $eventDate = trim((string)($row['event_date'] ?? ''));
        if ($eventDate !== '') {
            $dateCounts[$eventDate] = (int)($dateCounts[$eventDate] ?? 0) + 1;
        }
    }

    ksort($toolMap, SORT_NATURAL);
    foreach ($toolMap as $tool => $cavities) {
        $list = array_keys($cavities);
        natsort($list);
        $toolMap[$tool] = array_values($list);
    }
    ksort($moduleToolMap, SORT_NATURAL);
    foreach ($moduleToolMap as $moduleLabel => $toolRows) {
        ksort($toolRows, SORT_NATURAL);
        foreach ($toolRows as $tool => $cavities) {
            $list = array_keys($cavities);
            natsort($list);
            $moduleToolMap[$moduleLabel][$tool] = array_values($list);
        }
    }

    foreach ($pointStats as $pointNo => $stat) {
        $tcCount = 0;
        foreach ((array)$stat['tool_cavities'] as $tool => $cavities) {
            $list = array_keys((array)$cavities);
            natsort($list);
            $pointStats[$pointNo]['tool_cavities'][$tool] = array_values($list);
            $tcCount += count($list);
        }
        ksort($pointStats[$pointNo]['tool_cavities'], SORT_NATURAL);
        $dates = array_keys((array)$stat['dates']);
        sort($dates, SORT_NATURAL);
        $pointStats[$pointNo]['dates'] = $dates;
        $pointStats[$pointNo]['tc_count'] = $tcCount;
        $pointStats[$pointNo]['tool_count'] = count((array)$pointStats[$pointNo]['tool_cavities']);
    }

    uasort($pointStats, static function (array $a, array $b): int {
        $aCount = (int)($a['count'] ?? 0);
        $bCount = (int)($b['count'] ?? 0);
        if ($aCount !== $bCount) {
            return $bCount <=> $aCount;
        }
        $aTc = (int)($a['tc_count'] ?? 0);
        $bTc = (int)($b['tc_count'] ?? 0);
        if ($aTc !== $bTc) {
            return $bTc <=> $aTc;
        }
        return 0;
    });
    arsort($dateCounts, SORT_NUMERIC);

    return [
        'tool_map' => $toolMap,
        'module_tool_map' => $moduleToolMap,
        'point_stats' => $pointStats,
        'date_counts' => $dateCounts,
    ];
}

function jtgpt_quality_recent_rows_tool_cavity_summary_lines(array $features, bool $multiModule = false): array {
    $lines = [];
    if ($multiModule) {
        $moduleToolMap = (array)($features['module_tool_map'] ?? []);
        if ($moduleToolMap) {
            $lines[] = 'Tool/Cavity 요약';
            foreach ($moduleToolMap as $moduleLabel => $toolRows) {
                $lines[] = '- ' . $moduleLabel;
                foreach ((array)$toolRows as $tool => $cavities) {
                    $lines[] = '  - ' . $tool . ':' . implode(',', (array)$cavities);
                }
            }
            return $lines;
        }
    }
    $toolMap = (array)($features['tool_map'] ?? []);
    if (!$toolMap) {
        return $lines;
    }
    $lines[] = 'Tool/Cavity 요약';
    foreach ($toolMap as $tool => $cavities) {
        $lines[] = '- ' . $tool . ':' . implode(',', (array)$cavities);
    }
    return $lines;
}

function jtgpt_quality_recent_rows_tc_text(array $toolCavities, int $maxTools = 2): string {
    $chunks = [];
    $count = 0;
    foreach ($toolCavities as $tool => $cavities) {
        $tool = strtoupper(trim((string)$tool));
        if ($tool === '') {
            continue;
        }
        $cavityList = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, (array)$cavities), static function (string $value): bool {
            return $value !== '';
        }));
        if (!$cavityList) {
            continue;
        }
        $chunks[] = $tool . '#' . implode(',#', $cavityList);
        $count++;
        if ($count >= $maxTools) {
            break;
        }
    }
    return implode(' / ', $chunks);
}

function jtgpt_quality_recent_rows_natural_lines(array $features): array {
    $pointStats = (array)($features['point_stats'] ?? []);
    $dateCounts = (array)($features['date_counts'] ?? []);
    if (!$pointStats && !$dateCounts) {
        return [];
    }

    $fingerprint = implode('|', array_slice(array_keys($pointStats), 0, 5)) . '|' . implode('|', array_slice(array_keys($dateCounts), 0, 5));
    $lines = [];

    $topPointNo = '';
    $topPoint = null;
    foreach ($pointStats as $pointNo => $stat) {
        $topPointNo = (string)$pointNo;
        $topPoint = $stat;
        break;
    }
    if ($topPointNo !== '' && is_array($topPoint) && (int)($topPoint['count'] ?? 0) > 0) {
        $lead = jtgpt_quality_recent_rows_pick_phrase('quality_nat_point_lead', ['추가로 보면', '결과를 보면', '흐름상 보면', '패턴으로 보면'], $fingerprint);
        $tail = jtgpt_quality_recent_rows_pick_phrase('quality_nat_point_tail', ['반복해서 보입니다.', '겹쳐서 보입니다.', '상대적으로 자주 보입니다.', '눈에 자주 들어옵니다.'], $fingerprint);
        $tcText = jtgpt_quality_recent_rows_tc_text((array)($topPoint['tool_cavities'] ?? []), 2);
        $line = $lead . ' ' . $topPointNo . ' 포인트';
        if ($tcText !== '') {
            $line .= '는 ' . $tcText . ' 쪽에서 ' . $tail;
        } else {
            $line .= '가 ' . jtgpt_tool_format_int($topPoint['count'] ?? 0) . '건으로 가장 많이 잡힙니다.';
        }
        $lines[] = trim($line);
    }

    if ($dateCounts) {
        $topDateCount = (int)reset($dateCounts);
        $topDates = [];
        foreach ($dateCounts as $eventDate => $count) {
            if ((int)$count !== $topDateCount) {
                break;
            }
            $topDates[] = (string)$eventDate;
            if (count($topDates) >= 3) {
                break;
            }
        }
        if ($topDates) {
            $lead = jtgpt_quality_recent_rows_pick_phrase('quality_nat_date_lead', ['생산일로는', '날짜 흐름으로는', '생산일 기준으로는', '날짜로 보면'], $fingerprint);
            $tail = jtgpt_quality_recent_rows_pick_phrase('quality_nat_date_tail', ['상대적으로 많이 모여 있습니다.', 'NG가 조금 더 모여 보입니다.', '같이 묶여 보입니다.', '건수가 상대적으로 두드러집니다.'], $fingerprint);
            $lines[] = $lead . ' ' . implode(', ', $topDates) . ' 쪽이 ' . $tail;
        }
    }

    $narrowPointNo = '';
    $narrowPoint = null;
    foreach ($pointStats as $pointNo => $stat) {
        if ($pointNo === $topPointNo) {
            continue;
        }
        $tcCount = (int)($stat['tc_count'] ?? 0);
        if ($tcCount > 0 && $tcCount <= 2) {
            $narrowPointNo = (string)$pointNo;
            $narrowPoint = $stat;
            break;
        }
    }
    if ($narrowPointNo !== '' && is_array($narrowPoint)) {
        $lead = jtgpt_quality_recent_rows_pick_phrase('quality_nat_narrow_lead', ['반면', '같이 보면', '한편', '또'], $fingerprint);
        $tail = jtgpt_quality_recent_rows_pick_phrase('quality_nat_narrow_tail', ['일부 Tool#Cavity에만 제한적으로 보입니다.', '국소적으로만 보입니다.', '넓게 퍼지기보다는 일부 위치에 모여 있습니다.', '범위가 넓지는 않은 편입니다.'], $fingerprint);
        $tcText = jtgpt_quality_recent_rows_tc_text((array)($narrowPoint['tool_cavities'] ?? []), 2);
        if ($tcText !== '') {
            $lines[] = $lead . ' ' . $narrowPointNo . ' 포인트는 ' . $tcText . '처럼 ' . $tail;
        }
    }

    return array_slice($lines, 0, 3);
}

function jtgpt_answer_quality_recent_rows(array $result, array $args): string {
    if (empty($result['found'])) {
        return jtgpt_quality_no_data_text($result, $args, '조건에 맞는 이력이 없습니다.');
    }
    $scope = jtgpt_format_scope($args);
    $multiModule = !empty($result['multi_module']) || count((array)($args['modules'] ?? [])) > 1;
    $title = $multiModule ? '전체' : ($result['label'] ?? strtoupper((string)($args['module'] ?? '')));
    $lines = [trim($title . ' ' . $scope . ' ' . jtgpt_quality_query_text($args, 'rows'))];
    foreach (($result['rows'] ?? []) as $row) {
        $chunk = [
            (string)($row['event_date'] ?? '-'),
        ];
        if ($multiModule) {
            $moduleLabel = trim((string)($row['module_label'] ?? ''));
            if ($moduleLabel !== '') $chunk[] = $moduleLabel;
        }
        $tc = trim((string)($row['tool_cavity'] ?? ''));
        if ($tc !== '') $chunk[] = $tc;
        $pointNo = trim((string)($row['point_no'] ?? ''));
        if ($pointNo !== '') $chunk[] = $pointNo;
        $ngText = jtgpt_format_ng_limit_value($row);
        if ($ngText !== '') $chunk[] = $ngText;
        $lines[] = implode(' | ', $chunk);
    }

    $features = jtgpt_quality_recent_rows_features((array)($result['rows'] ?? []), $multiModule);
    $summaryLines = jtgpt_quality_recent_rows_tool_cavity_summary_lines($features, $multiModule);
    if ($summaryLines) {
        $lines[] = '';
        foreach ($summaryLines as $line) {
            $lines[] = $line;
        }
    }

    $naturalLines = jtgpt_quality_recent_rows_natural_lines($features);
    if ($naturalLines) {
        $lines[] = '';
        foreach ($naturalLines as $line) {
            $lines[] = '- ' . $line;
        }
    }

    if (!empty($result['resolution'])) {
        foreach (jtgpt_quality_resolution_tail_lines((array)$result['resolution']) as $line) {
            $lines[] = '- ' . $line;
        }
    }
    return implode("
", $lines);
}


function jtgpt_answer_quality_point_detail(array $result, array $args): string {
    if (empty($result['found'])) {
        return jtgpt_quality_no_data_text($result, $args, '조건에 맞는 상세 이력이 없습니다.');
    }
    $scope = jtgpt_format_scope($args);
    if (!empty($result['results']) && is_array($result['results'])) {
        $lines = [trim('전체 ' . $scope . ' 포인트 ' . jtgpt_quality_query_text($args, 'detail'))];
        foreach ($result['results'] as $entry) {
            $summary = $entry['summary'] ?? [];
            $moduleLabel = trim((string)($entry['module_label'] ?? $entry['label'] ?? strtoupper((string)($entry['module'] ?? ''))));
            $pointNo = (string)($summary['point_no'] ?? ($args['point_no'] ?? '-'));
            $lines[] = '- ' . $moduleLabel . ' | ' . $pointNo . ' | ' . jtgpt_tool_format_int($summary['ng_count'] ?? 0) . '건 | 마지막 ' . (string)($summary['last_date'] ?? '-');
        }
        if (!empty($result['resolution'])) {
            foreach (jtgpt_quality_resolution_tail_lines((array)$result['resolution']) as $line) {
                $lines[] = '- ' . $line;
            }
        }
        return implode("
", $lines);
    }
    if (empty($result['summary'])) {
        return jtgpt_quality_no_data_text($result, $args, '조건에 맞는 상세 이력이 없습니다.');
    }
    $summary = $result['summary'];
    $pointNo = (string)($summary['point_no'] ?? ($args['point_no'] ?? '-'));
    $title = ($result['module_label'] ?? $result['label'] ?? strtoupper((string)($args['module'] ?? '')));
    $lines = [trim($title . ' ' . $scope . ' ' . $pointNo . ' ' . jtgpt_quality_query_text($args, 'detail'))];
    $lines[] = '- 건수: ' . jtgpt_tool_format_int($summary['ng_count'] ?? 0) . '건';
    $lines[] = '- 마지막 발생일: ' . (string)($summary['last_date'] ?? '-');
    if (!empty($result['latest_rows'])) {
        $lines[] = '- 최근 이력:';
        foreach ($result['latest_rows'] as $row) {
            $chunk = [
                (string)($row['event_date'] ?? '-'),
            ];
            if (!empty($result['multi_module'])) {
                $moduleLabel = trim((string)($row['module_label'] ?? ''));
                if ($moduleLabel !== '') $chunk[] = $moduleLabel;
            }
            $tc = trim((string)($row['tool_cavity'] ?? ''));
            if ($tc !== '') $chunk[] = $tc;
            $pointNo = trim((string)($row['point_no'] ?? ''));
            if ($pointNo !== '') $chunk[] = $pointNo;
            $ngText = jtgpt_format_ng_limit_value($row);
            if ($ngText !== '') $chunk[] = $ngText;
            $lines[] = '  ' . implode(' | ', $chunk);
        }
    }
    if (!empty($result['resolution'])) {
        foreach (jtgpt_quality_resolution_tail_lines((array)$result['resolution']) as $line) {
            $lines[] = '- ' . $line;
        }
    }
    return implode("
", $lines);
}


function jtgpt_answer_quality_count(array $result, array $args): string {
    if (empty($result['found'])) {
        return jtgpt_quality_no_data_text($result, $args, '조건에 맞는 건수가 없습니다.');
    }
    $scope = jtgpt_format_scope($args);
    $title = !empty($result['multi_module']) ? '전체' : ($result['label'] ?? strtoupper((string)($args['module'] ?? '')));
    $lines = [trim($title . ' ' . $scope . ' ' . jtgpt_quality_query_text($args, 'count') . '는 ' . jtgpt_tool_format_int($result['total_ng_count'] ?? 0) . '건입니다.')];
    foreach (($result['module_counts'] ?? []) as $row) {
        $lines[] = '- ' . (string)($row['label'] ?? strtoupper((string)($row['module'] ?? ''))) . ': ' . jtgpt_tool_format_int($row['ng_count'] ?? 0) . '건';
    }
    if (!empty($result['resolution'])) {
        foreach (jtgpt_quality_resolution_tail_lines((array)$result['resolution']) as $line) {
            $lines[] = '- ' . $line;
        }
    }
    return implode("
", $lines);
}



function jtgpt_quality_cert_field_label_text(array $result, array $args): string {
    $dateField = strtolower(trim((string)($result['date_field'] ?? $args['date_field'] ?? 'both')));
    if ($dateField === 'meas_date') return 'LG';
    if ($dateField === 'jmeas_date') return '자화';
    return '플래그';
}

function jtgpt_quality_cert_tool_label_text(?string $tool): string {
    $tool = strtoupper(trim((string)$tool));
    if ($tool === '' || $tool === '-') {
        return '-';
    }
    if (preg_match('/^[A-Z]$/', $tool)) {
        return $tool . '차';
    }
    if (preg_match('/^[A-Z]차$/u', $tool)) {
        return $tool;
    }
    return $tool;
}

function jtgpt_answer_quality_cert_remaining(array $result, array $args): string {
    if (empty($result['found'])) {
        return trim((string)($result['error'] ?? '조건에 맞는 성적서 가능 데이터가 없습니다.'));
    }

    $scope = jtgpt_format_scope($args);
    $fieldLabel = jtgpt_quality_cert_field_label_text($result, $args);
    $totalCount = (int)($result['total_count'] ?? 0);
    $isStatusRequest = !empty($args['cert_status_request']) || (string)($args['output_mode'] ?? '') === 'status';
    if ($isStatusRequest) {
        $headline = $totalCount > 0
            ? trim('OQC ' . $scope . ' ' . $fieldLabel . ' 성적서 가능 데이터는 현재 확인됩니다. 아래는 현황입니다.')
            : trim('OQC ' . $scope . ' ' . $fieldLabel . ' 성적서 가능 데이터는 현재 부족합니다.');
    } else {
        $headline = trim('OQC ' . $scope . ' ' . $fieldLabel . ' 성적서 가능 데이터 현황입니다.');
    }
    $lines = [$headline];

    $groupBy = (string)($result['group_by'] ?? $args['group_by'] ?? 'model_tool_cavity');
    foreach (($result['model_groups'] ?? []) as $modelGroup) {
        $modelName = trim((string)($modelGroup['model'] ?? '모델미상'));
        $modelCount = jtgpt_tool_format_int($modelGroup['count'] ?? 0);

        if ($groupBy === 'model') {
            $lines[] = '- ' . $modelName . ' - ' . $modelCount . '건';
            continue;
        }

        $lines[] = '- ' . $modelName . ' - ' . $modelCount . '건';

        if ($groupBy === 'cavity') {
            foreach (($modelGroup['cavities'] ?? []) as $cavityRow) {
                $lines[] = '  - ' . (string)($cavityRow['cavity'] ?? '-') . ' - ' . jtgpt_tool_format_int($cavityRow['count'] ?? 0) . '건';
            }
            continue;
        }

        if ($groupBy === 'tool_cavity') {
            foreach (($modelGroup['tool_cavity'] ?? []) as $tcRow) {
                $tool = jtgpt_quality_cert_tool_label_text((string)($tcRow['tool'] ?? '-'));
                $cavity = (string)($tcRow['cavity'] ?? '-');
                $lines[] = '  - ' . $tool . ' / ' . $cavity . ' - ' . jtgpt_tool_format_int($tcRow['count'] ?? 0) . '건';
            }
            continue;
        }

        foreach (($modelGroup['tools'] ?? []) as $toolGroup) {
            $tool = jtgpt_quality_cert_tool_label_text((string)($toolGroup['tool'] ?? '-'));
            $toolCount = jtgpt_tool_format_int($toolGroup['count'] ?? 0);
            $toolNgCount = jtgpt_tool_format_int($toolGroup['ng_count'] ?? 0);

            if ($groupBy === 'tool') {
                $lines[] = '  - ' . $tool . ' - ' . $toolCount . '건';
                if ((int)($toolGroup['ng_count'] ?? 0) > 0) {
                    if ((int)($toolGroup['ng_count'] ?? 0) > 0) {
                $lines[] = '    - 사용 불가 예상(NG): ' . $toolNgCount . '건';
            }
                }
                continue;
            }

            $lines[] = '  - ' . $tool . ' - ' . $toolCount . '건';
            foreach (($toolGroup['cavities'] ?? []) as $cavityRow) {
                $lines[] = '    - ' . (string)($cavityRow['cavity'] ?? '-') . ' - ' . jtgpt_tool_format_int($cavityRow['count'] ?? 0) . '건';
            }
            if ((int)($toolGroup['ng_count'] ?? 0) > 0) {
                $lines[] = '    - 사용 불가 예상(NG): ' . $toolNgCount . '건';
            }
        }
    }

    return implode("
", $lines);
}

function jtgpt_answer_quality_summary(array $result, array $args): string {
    if (empty($result['found'])) {
        return jtgpt_quality_no_data_text($result, $args, '조건에 맞는 요약이 없습니다.');
    }
    $scope = jtgpt_format_scope($args);
    $title = !empty($result['multi_module']) ? '전체' : ($result['label'] ?? strtoupper((string)($args['module'] ?? '')));
    $lines = [trim($title . ' ' . $scope . ' ' . jtgpt_quality_query_text($args, 'summary') . '입니다.')];
    $lines[] = '- 총 건수: ' . jtgpt_tool_format_int($result['total_ng_count'] ?? 0) . '건';
    foreach (($result['module_counts'] ?? []) as $row) {
        $lines[] = '- ' . (string)($row['label'] ?? strtoupper((string)($row['module'] ?? ''))) . ': ' . jtgpt_tool_format_int($row['ng_count'] ?? 0) . '건, 포인트 ' . jtgpt_tool_format_int($row['point_count'] ?? 0) . '개';
    }
    if (!empty($result['top_rows'])) {
        $lines[] = '- 상위 포인트:';
        foreach (($result['top_rows'] ?? []) as $row) {
            $prefix = !empty($result['multi_module']) ? ((string)($row['module_label'] ?? '') . ' | ') : '';
            $lines[] = '  · ' . $prefix . (string)($row['point_no'] ?? '-') . ' - ' . jtgpt_tool_format_int($row['ng_count'] ?? 0) . '건';
        }
    }
    if (!empty($result['resolution'])) {
        foreach (jtgpt_quality_resolution_tail_lines((array)$result['resolution']) as $line) {
            $lines[] = '- ' . $line;
        }
    }
    return implode("
", $lines);
}

function jtgpt_shipping_export_filename(array $args, string $ext): string {
    $partName = strtoupper(trim((string)($args['part_name'] ?? 'all')));
    $partName = preg_replace('/[^A-Z0-9\-]+/', '-', $partName);
    $partName = trim((string)$partName, '-');
    if ($partName === '') {
        $partName = 'ALL';
    }
    $stamp = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Ymd_His');
    return 'jtgpt_shipping_' . strtolower($partName) . '_' . $stamp . '.' . $ext;
}

function jtgpt_shipping_export_scalar_text($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_scalar($v)) return (string)$v;
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function jtgpt_shipping_export_date_text($v): string {
    $text = trim(jtgpt_shipping_export_scalar_text($v));
    if ($text === '') return '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T].*$/', $text, $m)) {
        return $m[1];
    }
    return $text;
}

function jtgpt_shipping_export_pick(array $row, array $aliases): string {
    $map = [];
    foreach ($row as $k => $v) {
        $lk = strtolower(trim((string)$k));
        if ($lk === '') continue;
        $map[$lk] = $v;
        $map[preg_replace('/[^a-z0-9]+/', '', $lk)] = $v;
    }
    foreach ($aliases as $alias) {
        $alias = strtolower(trim((string)$alias));
        if ($alias === '') continue;
        if (array_key_exists($alias, $map)) return jtgpt_shipping_export_scalar_text($map[$alias]);
        $compact = preg_replace('/[^a-z0-9]+/', '', $alias);
        if ($compact !== '' && array_key_exists($compact, $map)) return jtgpt_shipping_export_scalar_text($map[$compact]);
    }
    return '';
}

function jtgpt_shipping_export_normalize_rows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $out[] = [
            '창고' => jtgpt_shipping_export_pick($row, ['warehouse', 'warehous', 'wh', 'storehouse']),
            '포장일자' => jtgpt_shipping_export_date_text(jtgpt_shipping_export_pick($row, ['pack_date', 'packdate', 'packing_date', 'pack_dt'])),
            '생산일자' => jtgpt_shipping_export_date_text(jtgpt_shipping_export_pick($row, ['prod_date', 'production_date', 'proddate', 'prod_dt'])),
            '어닐링일자' => jtgpt_shipping_export_date_text(jtgpt_shipping_export_pick($row, ['anneal_date', 'annealing_date', 'anneal_dt'])),
            '설비' => jtgpt_shipping_export_pick($row, ['facility', 'equip', 'equipment', 'machine']),
            'CAVITY' => jtgpt_shipping_export_pick($row, ['cavity', 'cav', 'cavity_no', 'cav_no']),
            '품번코드' => jtgpt_shipping_export_pick($row, ['part_code', 'partcode', 'item_code']),
            '차수' => jtgpt_shipping_export_pick($row, ['revision', 'rev', 'tool', 'tool_no']),
            '고객사 품번' => jtgpt_shipping_export_pick($row, ['customer_part', 'customer_part_no', 'customer_part_name', 'cust_part']),
            '품번명' => jtgpt_shipping_export_pick($row, ['part_name', 'item_name', 'product_name']),
            '모델' => jtgpt_shipping_export_pick($row, ['model']),
            '프로젝트' => jtgpt_shipping_export_pick($row, ['project']),
            '소포장 NO' => jtgpt_shipping_export_pick($row, ['small_pack_no', 'smallpackno', 'small_pack', 'small_pack_tray_no', 'smallpacktrayno']),
            'Tray NO' => jtgpt_shipping_export_pick($row, ['tray_no', 'trayno', 'tray']),
            '납품처' => jtgpt_shipping_export_pick($row, ['ship_to', 'customer', 'customer_name', 'consignee']),
            '출고수량' => jtgpt_shipping_export_pick($row, ['qty', 'quantity', 'ship_qty', 'out_qty']),
            '출하일자' => jtgpt_shipping_export_date_text(jtgpt_shipping_export_pick($row, ['ship_date', 'ship_datetime', 'ship_dt', 'shipping_date', 'shipment_date'])),
            '고객 LOTID' => jtgpt_shipping_export_pick($row, ['customer_pack', 'customer_lotid', 'customer_lot_id', 'customer_lot', 'customerlotid']),
            '포장바코드' => jtgpt_shipping_export_pick($row, ['barc_pack_no', 'pack_barcode', 'packing_barcode', 'barcode', 'barcode_pack_no']),
            '포장번호' => jtgpt_shipping_export_pick($row, ['pack_no', 'packing_no', 'package_no', 'box_no', 'packnumber']),
            'AVI' => jtgpt_shipping_export_pick($row, ['avi']),
            'RETURNDATE' => jtgpt_shipping_export_date_text(jtgpt_shipping_export_pick($row, ['returndate', 'return_date', 'return_dt'])),
            'RMA' => jtgpt_shipping_export_pick($row, ['rma', 'ls_rma']),
        ];
    }
    return $out;
}

function jtgpt_shipping_export_source_rows(PDO $pdo, array $args): array {
    if (!function_exists('jtgpt_tool_shipping_where')) {
        return [];
    }
    $where = jtgpt_tool_shipping_where($pdo, $args);
    $schema = $where['schema'] ?? (function_exists('jtgpt_tool_shipping_schema') ? jtgpt_tool_shipping_schema($pdo) : []);
    $order = [];
    if (!empty($schema['date'])) {
        $order[] = '`' . $schema['date'] . '` DESC';
    } elseif (function_exists('jtgpt_tool_shipping_column_name')) {
        $fallbackDate = jtgpt_tool_shipping_column_name($pdo, ['ship_date', 'ship_datetime', 'ship_dt', 'shipping_date', 'shipment_date']);
        if ($fallbackDate) $order[] = '`' . $fallbackDate . '` DESC';
    }
    if (!empty($schema['id'])) {
        $order[] = '`' . $schema['id'] . '` DESC';
    } elseif (function_exists('jtgpt_tool_shipping_column_name')) {
        $fallbackId = jtgpt_tool_shipping_column_name($pdo, ['id']);
        if ($fallbackId) $order[] = '`' . $fallbackId . '` DESC';
    }
    $sql = 'SELECT * FROM ShipingList ' . ($where['sql'] ?? '');
    if ($order) {
        $sql .= ' ORDER BY ' . implode(', ', $order);
    }
    $st = $pdo->prepare($sql);
    $st->execute($where['params'] ?? []);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return jtgpt_shipping_export_normalize_rows($rows);
}

function jtgpt_shipping_create_export(PDO $pdo, array $args): ?array {
    $output = jtgpt_quality_output_format($args);
    if (!in_array($output, ['excel', 'csv'], true)) {
        return null;
    }
    $rows = jtgpt_shipping_export_source_rows($pdo, $args);
    if (!$rows) {
        return null;
    }
    $dir = jtgpt_quality_temp_dir_fs();
    $ext = $output === 'csv' ? 'csv' : 'xlsx';
    $filename = jtgpt_shipping_export_filename($args, $ext);
    $path = rtrim($dir, '/\\') . '/' . $filename;
    if ($output === 'csv') {
        jtgpt_quality_write_csv_file($path, $rows);
    } else {
        jtgpt_quality_write_xlsx_file($path, $rows);
    }
    $download = jtgpt_quality_register_download($path, $filename, $output, count($rows));
    return [
        'name' => $filename,
        'path' => $path,
        'url' => $download['url'],
        'token' => $download['token'],
        'row_count' => count($rows),
        'format' => $output,
        'expires_in_sec' => $download['expires_in_sec'],
    ];
}

function jtgpt_shipping_export_brief_text(array $args, ?array $download, bool $followup = false): string {
    $format = strtolower(trim((string)($download['format'] ?? $args['output'] ?? 'excel')));
    $label = $format === 'csv' ? 'CSV' : '엑셀';
    $count = (int)($download['row_count'] ?? 0);
    if ($followup) {
        if ($count > 0) {
            return '방금 조회 결과를 ' . $label . ' 파일로 만들었습니다.';
        }
        return $label . ' 파일을 만들었습니다.';
    }
    $scope = jtgpt_format_scope($args);
    $prefix = trim(($scope !== '' ? ($scope . ' ') : '') . '출하내역 상세');
    if ($count > 0) {
        return $prefix . ' ' . jtgpt_tool_format_int($count) . '건을 ' . $label . ' 파일로 만들었습니다.';
    }
    return $prefix . '을 ' . $label . ' 파일로 만들었습니다.';
}

function jtgpt_quality_export_brief_text(string $tool, array $args, array $result, bool $followup = false): string {
    $format = jtgpt_quality_output_format($args);
    $label = $format === 'csv' ? 'CSV' : '엑셀';
    if ($followup) {
        return '방금 조회 결과를 ' . $label . ' 파일로 만들었습니다.';
    }

    $scope = trim(jtgpt_format_scope($args));
    $title = !empty($result['multi_module']) ? '전체' : trim((string)($result['label'] ?? strtoupper((string)($args['module'] ?? ''))));
    $queryKind = 'rows';
    if ($tool === 'quality_top_ng_points') {
        $queryKind = 'top';
    } elseif ($tool === 'quality_point_detail') {
        $queryKind = 'detail';
    } elseif ($tool === 'quality_count_ng_rows') {
        $queryKind = 'count';
    } elseif ($tool === 'quality_summary') {
        $queryKind = 'summary';
    }
    $queryText = trim(jtgpt_quality_query_text($args, $queryKind));

    $count = null;
    if ($tool === 'quality_recent_ng_rows') {
        $count = count((array)($result['rows'] ?? []));
    } elseif ($tool === 'quality_top_ng_points') {
        $count = count((array)($result['rows'] ?? []));
    } elseif ($tool === 'quality_point_detail') {
        if (!empty($result['latest_rows']) && is_array($result['latest_rows'])) {
            $count = count($result['latest_rows']);
        } elseif (!empty($result['results']) && is_array($result['results'])) {
            $tmp = 0;
            foreach ($result['results'] as $entry) {
                $tmp += count((array)($entry['latest_rows'] ?? []));
            }
            if ($tmp > 0) {
                $count = $tmp;
            }
        }
    } elseif ($tool === 'quality_count_ng_rows' && isset($result['total_ng_count'])) {
        $count = (int)$result['total_ng_count'];
    } elseif ($tool === 'quality_summary' && isset($result['total_ng_count'])) {
        $count = (int)$result['total_ng_count'];
    } elseif ($tool === 'quality_cert_remaining' && isset($result['total_count'])) {
        $count = (int)$result['total_count'];
    }

    $parts = array_values(array_filter([$title !== '' ? $title : '', $scope !== '' ? $scope : '', $queryText !== '' ? $queryText : '결과']));
    $prefix = trim(implode(' ', $parts));
    if ($count !== null) {
        return trim($prefix . ' ' . jtgpt_tool_format_int($count) . '건을 ' . $label . ' 파일로 만들었습니다.');
    }
    return trim($prefix . ' 결과를 ' . $label . ' 파일로 만들었습니다.');
}

function jtgpt_client_history_last_user_query(array $clientHistory, string $currentMessage): ?string {
    for ($i = count($clientHistory) - 1; $i >= 0; $i--) {
        $entry = (array)($clientHistory[$i] ?? []);
        if (strtolower((string)($entry['role'] ?? '')) !== 'user') {
            continue;
        }
        $text = trim((string)($entry['text'] ?? ''));
        if ($text === '' || $text === trim($currentMessage)) {
            continue;
        }
        return $text;
    }
    return null;
}

function jtgpt_is_output_only_followup_message(string $message): bool {
    $output = jtgpt_planner_extract_quality_output_format($message);
    if (!in_array($output, ['excel', 'csv', 'table'], true)) {
        return false;
    }
    $lower = mb_strtolower(trim($message), 'UTF-8');
    if ($lower === '') {
        return false;
    }
    if (!jtgpt_planner_contains_any($lower, ['엑셀', 'excel', 'xlsx', 'csv', '표로', '테이블', 'table', '출력', '다운로드', '내려', '저장', '파일', '내보내', '추출', '뽑아'])) {
        return false;
    }

    if (function_exists('jtgpt_planner_is_detail_export_message') && jtgpt_planner_is_detail_export_message($message)) {
        $detailOnly = preg_replace('/(?:상세내역|상세\s*내역|상세|detail|내역|엑셀|excel|xlsx|csv|표로|테이블|table|출력|다운로드|내려|저장|파일|내보내|추출|뽑아|줘|주라|줄래|해줘|해\s*줘|좀|으로|로|만|부탁|바로|해|를|을|좀)/u', ' ', $lower);
        $detailOnly = trim((string)preg_replace('/\s+/u', ' ', $detailOnly));
        if ($detailOnly === '') {
            return true;
        }
    }

    $range = jtgpt_planner_detect_date_range($lower);
    $partName = jtgpt_planner_extract_part_name($message);
    $tools = jtgpt_planner_extract_tools($message);
    $cavities = jtgpt_planner_extract_cavities($message);
    $pointTerms = jtgpt_planner_collect_quality_point_terms($message, $tools, $cavities);
    $valueFilter = jtgpt_planner_extract_quality_value_filter($message);

    if (trim((string)$partName) !== '') return false;
    if (!empty($tools) || !empty($cavities) || !empty($pointTerms)) return false;
    if (is_array($valueFilter) && !empty($valueFilter['enabled'])) return false;
    if (empty($range['implicit'])) return false;
    if (jtgpt_planner_contains_any($lower, ['oqc', 'omm', 'aoi', 'cmm', 'ng', '불량', '측정값', 'usl', 'lsl'])) return false;

    return true;
}

function jtgpt_build_quality_followup_plan_from_tool_args(string $tool, array $args, string $message, string $source): ?array {
    $tool = trim($tool);
    if ($tool === '' || strpos($tool, 'quality_') !== 0) {
        return null;
    }
    if (!$args) {
        return null;
    }
    $args['output'] = jtgpt_planner_extract_quality_output_format($message);
    return [
        'kind' => 'tool',
        'tool' => $tool,
        'args' => $args,
        'slots' => $args,
        'followup' => true,
        'followup_source' => $source,
    ];
}

function jtgpt_is_exportable_tool(string $tool): bool {
    $tool = trim($tool);
    if ($tool === '') {
        return false;
    }
    if (strpos($tool, 'quality_') === 0) {
        return true;
    }
    return in_array($tool, ['shipping_summary', 'shipping_last_ship_date'], true);
}

function jtgpt_build_exportable_followup_plan_from_tool_args(string $tool, array $args, string $message, string $source): ?array {
    $tool = trim($tool);
    if (!jtgpt_is_exportable_tool($tool)) {
        return null;
    }
    if (!$args) {
        return null;
    }
    $output = jtgpt_planner_extract_quality_output_format($message);
    if (!in_array($output, ['excel', 'csv', 'table'], true)) {
        return null;
    }
    $args['output'] = $output;
    $lower = mb_strtolower($message, 'UTF-8');
    if ($tool === 'quality_cert_remaining') {
        if ((function_exists('jtgpt_planner_is_detail_export_message') && jtgpt_planner_is_detail_export_message($message)) || jtgpt_planner_contains_any($lower, ['상세내역', '상세 내역', '상세', 'detail', '내역'])) {
            $args['detail_export'] = true;
        } else {
            unset($args['detail_export']);
        }
    }
    return [
        'kind' => 'tool',
        'tool' => $tool,
        'args' => $args,
        'slots' => $args,
        'followup' => true,
        'followup_source' => $source,
    ];
}

function jtgpt_build_exportable_followup_plan_from_plan(array $plan, string $message, string $source): ?array {
    if (($plan['kind'] ?? '') !== 'tool') {
        return null;
    }
    return jtgpt_build_exportable_followup_plan_from_tool_args((string)($plan['tool'] ?? ''), (array)($plan['args'] ?? []), $message, $source);
}

function jtgpt_restore_exportable_followup_plan(string $message, array $state, array $clientHistory = [], ?array $clientLastExportablePlan = null, ?array $clientLastQualityPlan = null): ?array {
    if (!jtgpt_is_output_only_followup_message($message)) {
        return null;
    }

    $plan = jtgpt_build_exportable_followup_plan_from_plan((array)$clientLastExportablePlan, $message, 'client_last_exportable_plan');
    if ($plan) {
        return $plan;
    }

    $plan = jtgpt_build_exportable_followup_plan_from_plan((array)$clientLastQualityPlan, $message, 'client_last_quality_plan');
    if ($plan) {
        return $plan;
    }

    $tool = trim((string)($state['last_exportable_tool'] ?? ''));
    $args = (array)($state['last_exportable_args'] ?? []);
    $plan = jtgpt_build_exportable_followup_plan_from_tool_args($tool, $args, $message, 'state_exportable');
    if ($plan) {
        return $plan;
    }

    $tool = trim((string)($state['last_quality_tool'] ?? ''));
    $args = (array)($state['last_quality_args'] ?? []);
    $plan = jtgpt_build_exportable_followup_plan_from_tool_args($tool, $args, $message, 'state_quality');
    if ($plan) {
        return $plan;
    }

    if (function_exists('jtgpt_session_history')) {
        $hist = (array)jtgpt_session_history(20);
        for ($i = count($hist) - 1; $i >= 0; $i--) {
            $entry = (array)($hist[$i] ?? []);
            $meta = (array)($entry['meta'] ?? []);
            $savedPlan = (array)($meta['plan'] ?? []);
            $plan = jtgpt_build_exportable_followup_plan_from_plan($savedPlan, $message, 'session_history_plan');
            if ($plan) {
                return $plan;
            }
        }

        for ($i = count($hist) - 1; $i >= 0; $i--) {
            $entry = (array)($hist[$i] ?? []);
            if (strtolower((string)($entry['role'] ?? '')) !== 'user') {
                continue;
            }
            $text = trim((string)($entry['text'] ?? ''));
            if ($text === '' || $text === trim($message)) {
                continue;
            }
            $savedPlan = jtgpt_planner_plan($text, $state);
            if (jtgpt_is_exportable_tool((string)($savedPlan['tool'] ?? ''))) {
                $savedArgs = (array)($savedPlan['args'] ?? []);
                $savedArgs['output'] = jtgpt_planner_extract_quality_output_format($message);
                $savedPlan['args'] = $savedArgs;
                $savedPlan['slots'] = $savedArgs;
                $savedPlan['followup'] = true;
                $savedPlan['followup_source'] = 'session_history_user';
                return $savedPlan;
            }
        }
    }

    $previousUserQuery = jtgpt_client_history_last_user_query($clientHistory, $message);
    if ($previousUserQuery !== null) {
        $savedPlan = jtgpt_planner_plan($previousUserQuery, $state);
        if (jtgpt_is_exportable_tool((string)($savedPlan['tool'] ?? ''))) {
            $savedArgs = (array)($savedPlan['args'] ?? []);
            $savedArgs['output'] = jtgpt_planner_extract_quality_output_format($message);
            $savedPlan['args'] = $savedArgs;
            $savedPlan['slots'] = $savedArgs;
            $savedPlan['followup'] = true;
            $savedPlan['followup_source'] = 'client_history_user';
            return $savedPlan;
        }
    }

    return null;
}

function jtgpt_build_quality_followup_plan_from_plan(array $plan, string $message, string $source): ?array {
    if (($plan['kind'] ?? '') !== 'tool') {
        return null;
    }
    return jtgpt_build_quality_followup_plan_from_tool_args((string)($plan['tool'] ?? ''), (array)($plan['args'] ?? []), $message, $source);
}

function jtgpt_restore_quality_followup_plan(string $message, array $state, array $clientHistory = [], ?array $clientLastQualityPlan = null): ?array {
    if (!jtgpt_is_output_only_followup_message($message)) {
        return null;
    }

    $plan = jtgpt_build_quality_followup_plan_from_plan((array)$clientLastQualityPlan, $message, 'client_last_plan');
    if ($plan) {
        return $plan;
    }

    $tool = trim((string)($state['last_quality_tool'] ?? ''));
    $args = (array)($state['last_quality_args'] ?? []);
    $plan = jtgpt_build_quality_followup_plan_from_tool_args($tool, $args, $message, 'state');
    if ($plan) {
        return $plan;
    }

    if (function_exists('jtgpt_session_history')) {
        $hist = (array)jtgpt_session_history(20);
        for ($i = count($hist) - 1; $i >= 0; $i--) {
            $entry = (array)($hist[$i] ?? []);
            $meta = (array)($entry['meta'] ?? []);
            $savedPlan = (array)($meta['plan'] ?? []);
            $plan = jtgpt_build_quality_followup_plan_from_tool_args((string)($savedPlan['tool'] ?? ''), (array)($savedPlan['args'] ?? []), $message, 'session_history_plan');
            if ($plan) {
                return $plan;
            }
        }

        for ($i = count($hist) - 1; $i >= 0; $i--) {
            $entry = (array)($hist[$i] ?? []);
            if (strtolower((string)($entry['role'] ?? '')) !== 'user') {
                continue;
            }
            $text = trim((string)($entry['text'] ?? ''));
            if ($text === '' || $text === trim($message)) {
                continue;
            }
            $savedPlan = jtgpt_planner_plan($text, $state);
            if (($savedPlan['kind'] ?? '') === 'tool' && strpos((string)($savedPlan['tool'] ?? ''), 'quality_') === 0) {
                $savedArgs = (array)($savedPlan['args'] ?? []);
                $savedArgs['output'] = jtgpt_planner_extract_quality_output_format($message);
                $savedPlan['args'] = $savedArgs;
                $savedPlan['slots'] = $savedArgs;
                $savedPlan['followup'] = true;
                $savedPlan['followup_source'] = 'session_history_user';
                return $savedPlan;
            }
        }
    }

    $previousUserQuery = jtgpt_client_history_last_user_query($clientHistory, $message);
    if ($previousUserQuery !== null) {
        $savedPlan = jtgpt_planner_plan($previousUserQuery, $state);
        if (($savedPlan['kind'] ?? '') === 'tool' && strpos((string)($savedPlan['tool'] ?? ''), 'quality_') === 0) {
            $savedArgs = (array)($savedPlan['args'] ?? []);
            $savedArgs['output'] = jtgpt_planner_extract_quality_output_format($message);
            $savedPlan['args'] = $savedArgs;
            $savedPlan['slots'] = $savedArgs;
            $savedPlan['followup'] = true;
            $savedPlan['followup_source'] = 'client_history_user';
            return $savedPlan;
        }
    }

    return null;
}

function jtgpt_build_answer(string $message, array $clientHistory = [], ?array $clientLastQualityPlan = null, ?array $clientLastExportablePlan = null): array {
    $state = jtgpt_session_state();
    $plan = jtgpt_restore_exportable_followup_plan($message, $state, $clientHistory, $clientLastExportablePlan, $clientLastQualityPlan);
    if (!$plan) {
        $plan = jtgpt_planner_plan($message, $state);
    }
    $answer = '';
    $statePatch = [];
    $download = null;

    try {
        $pdo = jtgpt_resolve_pdo();
    } catch (Throwable $e) {
        $pdo = null;
    }

    if (($plan['kind'] ?? '') === 'clarify') {
        $answer = (string)($plan['answer'] ?? '조금 더 구체적으로 말해 주세요.');
    } elseif (($plan['kind'] ?? '') === 'answer' && ($plan['tool'] ?? '') === 'guard_read_only') {
        $answer = 'JTGPT는 읽기 전용 조회만 지원합니다. 수정/삭제/업로드 작업은 여기서 하지 않습니다.';
    } elseif (($plan['kind'] ?? '') === 'action') {
        $answer = '그래프/화면 열기 쪽은 기존 UI와 연결된 상태에서 다음 단계로 붙이면 됩니다. 이번 패치는 자연어 조회 엔진부터 연결했습니다.';
    } elseif (($plan['kind'] ?? '') === 'tool') {
        if (!$pdo instanceof PDO) {
            $answer = 'DB 연결을 찾지 못했습니다. dp_config.php 경로나 연결 변수 이름을 확인해 주세요.';
        } else {
            $tool = (string)($plan['tool'] ?? '');
            $args = (array)($plan['args'] ?? []);
            if (jtgpt_is_exportable_tool($tool) && jtgpt_quality_output_format($args) === 'chat') {
                $requestedOutput = jtgpt_planner_extract_quality_output_format($message);
                if (in_array($requestedOutput, ['excel', 'csv'], true)) {
                    $args['output'] = $requestedOutput;
                    $plan['args'] = $args;
                    $plan['slots'] = $args;
                }
            }
            switch ($tool) {
                case 'shipping_summary':
                    $res = jtgpt_tool_shipping_summary($pdo, $args);
                    $answer = jtgpt_answer_shipping_summary($res, $args);
                    $baseArgs = $args;
                    $baseArgs['output'] = 'chat';
                    $statePatch['last_exportable_tool'] = $tool;
                    $statePatch['last_exportable_args'] = $baseArgs;
                    break;
                case 'shipping_last_ship_date':
                    $res = jtgpt_tool_shipping_last_ship_date($pdo, $args);
                    $answer = jtgpt_answer_shipping_last_ship($res, $args);
                    $baseArgs = $args;
                    $baseArgs['output'] = 'chat';
                    $statePatch['last_exportable_tool'] = $tool;
                    $statePatch['last_exportable_args'] = $baseArgs;
                    break;
                case 'quality_top_ng_points':
                    $modules = jtgpt_quality_module_list_from_args($args);
                    if (count($modules) > 1) {
                        $res = jtgpt_tool_quality_top_ng_points_multi($pdo, $args);
                    } else {
                        $res = jtgpt_tool_quality_top_ng_points($pdo, (string)($modules[0] ?? $args['module'] ?? 'oqc'), $args);
                    }
                    $answer = jtgpt_answer_quality_top_points($res, $args);
                    $statePatch['last_module'] = strtolower((string)($modules[0] ?? $args['module'] ?? 'oqc'));
                    $statePatch['last_ranked_points'] = array_values(array_map(static fn($r) => (string)($r['point_no'] ?? ''), $res['rows'] ?? []));
                    break;
                case 'quality_recent_ng_rows':
                    $modules = jtgpt_quality_module_list_from_args($args);
                    if (count($modules) > 1) {
                        $res = jtgpt_tool_quality_recent_ng_rows_multi($pdo, $args);
                    } else {
                        $res = jtgpt_tool_quality_recent_ng_rows($pdo, (string)($modules[0] ?? $args['module'] ?? 'oqc'), $args);
                    }
                    $answer = jtgpt_answer_quality_recent_rows($res, $args);
                    $statePatch['last_module'] = strtolower((string)($modules[0] ?? $args['module'] ?? 'oqc'));
                    break;
                case 'quality_point_detail':
                    $modules = jtgpt_quality_module_list_from_args($args);
                    if (count($modules) > 1) {
                        $res = jtgpt_tool_quality_point_detail_multi($pdo, $args);
                    } else {
                        $res = jtgpt_tool_quality_point_detail($pdo, (string)($modules[0] ?? $args['module'] ?? 'oqc'), $args);
                    }
                    $answer = jtgpt_answer_quality_point_detail($res, $args);
                    $statePatch['last_module'] = strtolower((string)($modules[0] ?? $args['module'] ?? 'oqc'));
                    break;
                case 'quality_count_ng_rows':
                    $res = jtgpt_tool_quality_count_ng_rows($pdo, $args);
                    $answer = jtgpt_answer_quality_count($res, $args);
                    $modules = jtgpt_quality_module_list_from_args($args);
                    $statePatch['last_module'] = strtolower((string)($modules[0] ?? $args['module'] ?? 'oqc'));
                    break;
                case 'quality_summary':
                    $res = jtgpt_tool_quality_summary($pdo, $args);
                    $answer = jtgpt_answer_quality_summary($res, $args);
                    $modules = jtgpt_quality_module_list_from_args($args);
                    $statePatch['last_module'] = strtolower((string)($modules[0] ?? $args['module'] ?? 'oqc'));
                    break;
                case 'quality_cert_remaining':
                    $res = jtgpt_tool_quality_cert_remaining($pdo, $args);
                    $answer = jtgpt_answer_quality_cert_remaining($res, $args);
                    $statePatch['last_module'] = 'oqc';
                    break;
                case 'oqc_top_ng_points':
                    $res = jtgpt_tool_oqc_top_ng_points($pdo, $args);
                    $answer = jtgpt_answer_quality_top_points($res, array_merge($args, ['module' => 'oqc']));
                    $statePatch['last_module'] = 'oqc';
                    $statePatch['last_ranked_points'] = array_values(array_map(static fn($r) => (string)($r['point_no'] ?? ''), $res['rows'] ?? []));
                    break;
                case 'oqc_point_detail':
                    $res = jtgpt_tool_oqc_point_detail($pdo, $args);
                    $answer = jtgpt_answer_quality_point_detail($res, array_merge($args, ['module' => 'oqc']));
                    $statePatch['last_module'] = 'oqc';
                    break;
                default:
                    $answer = '아직 연결되지 않은 도구입니다.';
                    break;
            }

            if (isset($res) && is_array($res) && strpos($tool, 'quality_') === 0) {
                $baseArgs = $args;
                $baseArgs['output'] = 'chat';
                $statePatch['last_quality_tool'] = $tool;
                $statePatch['last_quality_args'] = $baseArgs;
            }
        }
    } else {
        $answer = '질문을 해석하지 못했습니다.';
    }

    if (($plan['kind'] ?? '') === 'tool' && $pdo instanceof PDO) {
        $args = (array)($plan['args'] ?? []);
        if (in_array(jtgpt_quality_output_format($args), ['excel', 'csv'], true)) {
            try {
                if ((string)($plan['tool'] ?? '') === 'shipping_summary' || (string)($plan['tool'] ?? '') === 'shipping_last_ship_date') {
                    $download = jtgpt_shipping_create_export($pdo, $args);
                    if ($download) {
                        $ttlText = !empty($download['expires_in_sec']) ? ('다운로드 후 약 ' . max(1, (int)round(((int)$download['expires_in_sec']) / 60)) . '분 뒤 자동 정리됩니다.') : '';
                        $answer = jtgpt_shipping_export_brief_text($args, $download, !empty($plan['followup']));
                        if ($ttlText !== '') {
                            $answer .= "
" . $ttlText;
                        }
                    }
                } elseif (isset($res) && is_array($res)) {
                    $download = jtgpt_quality_create_export($pdo, (string)($plan['tool'] ?? ''), $args, $res);
                    if ($download) {
                        $ttlText = !empty($download['expires_in_sec']) ? ('다운로드 후 약 ' . max(1, (int)round(((int)$download['expires_in_sec']) / 60)) . '분 뒤 자동 정리됩니다.') : '';
                        $answer = jtgpt_quality_export_brief_text((string)($plan['tool'] ?? ''), $args, $res, !empty($plan['followup']));
                        if ($ttlText !== '') {
                            $answer .= "
" . $ttlText;
                        }
                    }
                }
            } catch (Throwable $e) {
                $answer = rtrim($answer);
                if ($answer !== '') {
                    $answer .= "
";
                }
                $answer .= '파일 생성 중 오류가 발생했습니다: ' . $e->getMessage();
            }
        }
    }

    jtgpt_session_push('user', $message, ['plan' => $plan]);
    if ($statePatch) {
        jtgpt_session_merge_state($statePatch);
    }
    jtgpt_session_push('assistant', $answer, ['plan' => $plan, 'download' => $download]);

    return ['ok' => true, 'answer' => $answer, 'plan' => $plan, 'download_url' => $download['url'] ?? null, 'download_name' => $download['name'] ?? null];
}

jtgpt_quality_cleanup_download_registry();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['jtgpt_download'])) {
    $token = trim((string)$_GET['jtgpt_download']);
    if ($token !== '') {
        jtgpt_quality_stream_download($token);
    }
}

$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($isAjax) {
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $message = trim((string)($payload['message'] ?? ''));
    $clientHistory = is_array($payload['client_history'] ?? null) ? array_values($payload['client_history']) : [];
    $clientLastQualityPlan = is_array($payload['last_quality_plan'] ?? null) ? (array)$payload['last_quality_plan'] : null;
    $clientLastExportablePlan = is_array($payload['last_exportable_plan'] ?? null) ? (array)$payload['last_exportable_plan'] : null;
    jtgpt_json_response(jtgpt_build_answer($message, $clientHistory, $clientLastQualityPlan, $clientLastExportablePlan));
}
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JTGPT</title>
<style>
    :root{
        --bg:#1e1f22;
        --surface:rgba(48,50,56,.76);
        --surface-2:rgba(255,255,255,.038);
        --surface-3:rgba(255,255,255,.05);
        --text:#eceef2;
        --muted:#9ea4ad;
        --line:rgba(255,255,255,.08);
        --line-strong:rgba(255,255,255,.12);
        --assistant:rgba(255,255,255,.038);
        --user:rgba(255,255,255,.065);
        --shadow:0 18px 40px rgba(0,0,0,.24);
        --shadow-soft:0 8px 22px rgba(0,0,0,.18);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
        margin:0;
        background:var(--bg);
        color:var(--text);
        font-family:"Inter","Segoe UI",Roboto,"Noto Sans KR","Apple SD Gothic Neo","Malgun Gothic",sans-serif;
        -webkit-font-smoothing:antialiased;
        -moz-osx-font-smoothing:grayscale;
        text-rendering:optimizeLegibility;
        overflow:hidden;
    }
    .app{height:100%;display:flex;flex-direction:column}
    .brand{
        position:fixed;
        left:24px;
        top:20px;
        z-index:10;
        font-size:13px;
        font-weight:500;
        letter-spacing:-.01em;
        color:rgba(255,255,255,.88);
        opacity:.82;
    }
    .chat{
        flex:1;
        overflow:auto;
        padding:24px 20px 182px;
    }
    .chat::-webkit-scrollbar{width:10px}
    .chat::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:999px}

    .home{
        min-height:100%;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        padding:84px 20px 210px;
        text-align:center;
    }
    .home.hidden{display:none}
    .home-badge{
        width:30px;
        height:30px;
        border-radius:10px;
        border:1px solid var(--line);
        background:rgba(255,255,255,.028);
        display:grid;
        place-items:center;
        font-size:14px;
        line-height:1;
        color:#f5f6f8;
        margin-bottom:18px;
        box-shadow:var(--shadow-soft);
    }
    .home-title{
        margin:0;
        font-size:36px;
        line-height:1.18;
        letter-spacing:-.038em;
        font-weight:560;
        color:#f2f4f7;
    }

    .messages{
        display:none;
        max-width:800px;
        margin:0 auto;
        padding-top:10px;
    }
    .messages.active{display:block}
    .msg{display:flex;margin:0 0 24px}
    .msg.user{justify-content:flex-end}
    .msg.assistant{justify-content:flex-start}
    .bubble-wrap{max-width:min(760px,80%)}
    .label{
        font-size:11px;
        color:var(--muted);
        margin-bottom:7px;
        letter-spacing:-.01em;
    }
    .msg.user .label{text-align:right}
    .bubble{
        border:1px solid var(--line);
        background:var(--assistant);
        box-shadow:var(--shadow-soft);
        border-radius:20px;
        padding:14px 16px;
        font-size:14px;
        line-height:1.72;
        letter-spacing:-.01em;
        white-space:pre-wrap;
        word-break:keep-all;
        color:#eceef2;
    }
    .msg.user .bubble{
        background:var(--user);
        border-color:rgba(255,255,255,.10);
    }

    .composer-wrap{
        position:fixed;
        left:50%;
        bottom:18px;
        transform:translateX(-50%);
        width:min(760px, calc(100vw - 24px));
        z-index:20;
    }
    .composer{
        border:1px solid var(--line);
        background:var(--surface);
        border-radius:24px;
        box-shadow:var(--shadow);
        padding:12px 13px 10px;
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        transition:border-color .16s ease, box-shadow .16s ease, background .16s ease;
    }
    .composer:focus-within{
        border-color:var(--line-strong);
        background:rgba(52,54,60,.82);
        box-shadow:0 20px 48px rgba(0,0,0,.28);
    }
    .composer textarea{
        width:100%;
        min-height:52px;
        max-height:220px;
        resize:none;
        border:0;
        outline:none;
        background:transparent;
        color:var(--text);
        font:inherit;
        font-size:14px;
        line-height:1.65;
        padding:2px 4px;
    }
    .composer textarea::placeholder{color:#aaafb8}
    .composer-bottom{
        margin-top:6px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding:0 2px;
    }
    .composer-hint{
        font-size:11px;
        color:var(--muted);
        letter-spacing:-.01em;
    }
    .send{
        width:34px;
        height:34px;
        border-radius:999px;
        border:1px solid rgba(255,255,255,.10);
        background:#ededee;
        color:#161719;
        display:grid;
        place-items:center;
        font-size:14px;
        font-weight:700;
        cursor:pointer;
        flex:0 0 auto;
        transition:transform .12s ease, box-shadow .12s ease, opacity .12s ease;
        padding:0;
        box-shadow:0 4px 12px rgba(0,0,0,.18);
    }
    .send:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.22)}
    .send:active{transform:translateY(0)}
    .send[disabled]{opacity:.5;cursor:default;box-shadow:none}
    .typing-cursor{
        display:inline-block;
        width:1px;
        height:1.02em;
        background:rgba(255,255,255,.82);
        vertical-align:-2px;
        margin-left:2px;
        animation:blink 1s step-end infinite;
    }
    .bubble-download{
        display:inline-flex;
        align-items:center;
        gap:8px;
        margin-top:10px;
        color:#dfe7ff;
        text-decoration:none;
        border:1px solid rgba(255,255,255,.12);
        background:rgba(255,255,255,.045);
        border-radius:12px;
        padding:8px 10px;
        font-size:12px;
        line-height:1.3;
    }
    .bubble-download:hover{background:rgba(255,255,255,.07)}
    @keyframes blink{50%{opacity:0}}

    @media (max-width: 900px){
        .home-title{font-size:33px}
        .bubble-wrap{max-width:86%}
    }
    @media (max-width: 640px){
        .brand{left:16px;top:16px;font-size:12px}
        .chat{padding:18px 12px 156px}
        .home{padding:72px 16px 176px}
        .home-badge{width:28px;height:28px;border-radius:9px;font-size:13px;margin-bottom:16px}
        .home-title{font-size:29px;line-height:1.22}
        .composer-wrap{width:calc(100vw - 16px);bottom:10px}
        .composer{padding:11px 12px 9px;border-radius:22px}
        .composer textarea{min-height:48px;font-size:14px}
        .bubble-wrap{max-width:92%}
        .bubble{padding:13px 15px;border-radius:18px;font-size:14px}
        .send{width:32px;height:32px}
    }
</style>
</head>
<body>
<div class="app">
    <div class="brand">JTGPT</div>

    <div id="chat" class="chat">
        <section id="home" class="home">
            <div class="home-badge">✦</div>
            <h1 class="home-title">무엇을 도와드릴까요?</h1>
        </section>
        <section id="messages" class="messages"></section>
    </div>

    <div class="composer-wrap">
        <div class="composer">
            <textarea id="messageInput" placeholder="무엇이든 물어보세요"></textarea>
            <div class="composer-bottom">
                <div class="composer-hint">Enter로 전송 · Shift+Enter로 줄바꿈</div>
                <button id="sendBtn" class="send" type="button" aria-label="전송">✦</button>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const homeEl = document.getElementById('home');
    const messagesEl = document.getElementById('messages');
    const clientHistory = [];
    let lastQualityPlan = null;
    let lastExportablePlan = null;
    const inputEl = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const chatEl = document.getElementById('chat');

    function autoResize() {
        inputEl.style.height = '0px';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 220) + 'px';
    }

    function scrollBottom(smooth = true) {
        chatEl.scrollTo({ top: chatEl.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }

    function enterConversationMode() {
        homeEl.classList.add('hidden');
        messagesEl.classList.add('active');
    }

    function createMessage(role, text = '') {
        enterConversationMode();

        const item = document.createElement('div');
        item.className = 'msg ' + role;

        const wrap = document.createElement('div');
        wrap.className = 'bubble-wrap';

        const label = document.createElement('div');
        label.className = 'label';
        label.textContent = role === 'assistant' ? 'JTGPT' : '나';
        wrap.appendChild(label);

        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.textContent = text;
        wrap.appendChild(bubble);

        item.appendChild(wrap);
        messagesEl.appendChild(item);
        scrollBottom();
        return bubble;
    }

    function shouldSkipTyping(text, meta = {}) {
        const normalized = String(text || '').trim().toLowerCase();
        if (!normalized) return true;
        if (meta && meta.downloadUrl) return true;
        if (normalized.length <= 26) return true;

        const exportHints = [
            '엑셀', 'excel', 'csv', '다운로드', '출력', '저장', '파일',
            '내보내기', '첨부', '다운로드 링크'
        ];
        if (exportHints.some(keyword => normalized.includes(keyword)) && normalized.length <= 96) {
            return true;
        }
        return false;
    }

    function typingProfile(text) {
        const normalized = String(text || '');
        const length = normalized.length;
        if (length >= 320) {
            return { chunkSize: 8, delay: 2 };
        }
        if (length >= 160) {
            return { chunkSize: 6, delay: 3 };
        }
        if (length >= 80) {
            return { chunkSize: 4, delay: 4 };
        }
        return { chunkSize: 3, delay: 5 };
    }

    async function typeText(el, text, meta = {}) {
        const plainText = String(text || '');
        el.textContent = '';

        if (shouldSkipTyping(plainText, meta)) {
            el.textContent = plainText;
            scrollBottom(false);
            return;
        }

        const cursor = document.createElement('span');
        cursor.className = 'typing-cursor';
        el.appendChild(cursor);

        const profile = typingProfile(plainText);
        const chars = Array.from(plainText);
        let index = 0;

        while (index < chars.length) {
            cursor.remove();
            const nextText = chars.slice(index, index + profile.chunkSize).join('');
            el.append(document.createTextNode(nextText));
            el.appendChild(cursor);
            index += profile.chunkSize;
            scrollBottom(false);
            await new Promise(resolve => setTimeout(resolve, profile.delay));
        }
        cursor.remove();
    }

    function appendDownloadLink(bubble, url, name) {
        if (!bubble || !url) return;
        const link = document.createElement('a');
        link.className = 'bubble-download';
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = name || '파일 다운로드';
        bubble.appendChild(document.createElement('br'));
        bubble.appendChild(link);
    }

    function parseJsonLoose(rawText) {
        const text = String(rawText || '').trim();
        if (!text) return null;
        try {
            return JSON.parse(text);
        } catch (_) {}

        const firstBrace = text.indexOf('{');
        const lastBrace = text.lastIndexOf('}');
        if (firstBrace >= 0 && lastBrace > firstBrace) {
            const candidate = text.slice(firstBrace, lastBrace + 1);
            try {
                return JSON.parse(candidate);
            } catch (_) {}
        }
        return null;
    }

    function extractServerErrorText(rawText) {
        const text = String(rawText || '').trim();
        if (!text) return '';
        const collapsed = text.replace(/\s+/g, ' ').trim();
        if (!collapsed) return '';
        return collapsed.slice(0, 240);
    }

    async function sendMessage() {
        const message = inputEl.value.trim();
        if (!message) return;

        createMessage('user', message);
        clientHistory.push({ role: 'user', text: message });
        inputEl.value = '';
        autoResize();
        sendBtn.disabled = true;

        const assistantBubble = createMessage('assistant', '');

        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ message, client_history: clientHistory.slice(-12), last_quality_plan: lastQualityPlan, last_exportable_plan: lastExportablePlan })
            });
            const rawText = await res.text();
            const json = parseJsonLoose(rawText);
            if (!json) {
                throw new Error(extractServerErrorText(rawText) || '응답 JSON 파싱 실패');
            }
            const answerText = (json && json.answer) ? json.answer : '응답을 받지 못했어요.';
            await typeText(assistantBubble, answerText, { downloadUrl: json && json.download_url ? json.download_url : '' });
            clientHistory.push({ role: 'assistant', text: answerText });
            if (json && json.plan && json.plan.kind === 'tool' && typeof json.plan.tool === 'string') {
                if (json.plan.tool.indexOf('quality_') === 0) {
                    lastQualityPlan = { kind: 'tool', tool: json.plan.tool, args: json.plan.args || {} };
                }
                if (json.plan.tool.indexOf('quality_') === 0 || json.plan.tool === 'shipping_summary' || json.plan.tool === 'shipping_last_ship_date') {
                    const rememberedArgs = Object.assign({}, json.plan.args || {});
                    rememberedArgs.output = 'chat';
                    lastExportablePlan = { kind: 'tool', tool: json.plan.tool, args: rememberedArgs };
                }
            }
            if (json && json.download_url) {
                appendDownloadLink(assistantBubble, json.download_url, json.download_name || '파일 다운로드');
                scrollBottom(false);
            }
        } catch (e) {
            const rawMessage = e && typeof e.message === 'string' ? e.message.trim() : '';
            const errText = rawMessage ? rawMessage : '지금 응답을 불러오지 못했어요.';
            await typeText(assistantBubble, errText, { downloadUrl: '' });
            clientHistory.push({ role: 'assistant', text: errText });
        } finally {
            sendBtn.disabled = false;
            inputEl.focus();
            scrollBottom();
        }
    }

    inputEl.addEventListener('input', autoResize);
    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    sendBtn.addEventListener('click', sendMessage);

    autoResize();
})();
</script>
</body>
</html>
