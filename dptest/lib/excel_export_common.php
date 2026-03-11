<?php
// lib/excel_export_common.php
// 목적: ShipingList / RMAlist "전체EXCEL" / "페이지EXCEL" 다운로드를 별도 엔드포인트로 제공
// 주의: 이 파일은 list 페이지에서 include 하지 말고, excel_export_all/page.php에서만 require 하세요.

declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/dp_config.php';

// 로그인 체크 (auth_guard와 동일하게 넓게 체크)
$__login_keys = [
    'ship_user_id','dp_admin_id','user_id','userid','user','username','logged_in','is_login','is_logged_in'
];
$__ok = false;
foreach ($__login_keys as $__k) { if (!empty($_SESSION[$__k])) { $__ok = true; break; } }
if (!$__ok) {
    http_response_code(403);
    echo "로그인이 필요합니다.";
    exit;
}
// 세션 락 방지: 다운로드 처리 중 다른 탭 요청 막힘 방지
if (function_exists('session_write_close')) {
    session_write_close();
}

function dp_xlsx_h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function dp_xlsx_norm_ymd(?string $s): string {
    $s = trim((string)($s ?? ''));
    if ($s === '') return '';
    // 정상: 2025-12-12
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

    // (버그 대응) '2025-12-12'에서 첫 '-'만 제거된 형태: 202512-12-12
    if (preg_match('/^\d{6}-\d{2}-\d{2}$/', $s)) {
        $yyyy = substr($s, 0, 4);
        $mm   = substr($s, 4, 2);
        $dd   = substr($s, -2);
        return $yyyy . '-' . $mm . '-' . $dd;
    }

    // 20251212
    if (preg_match('/^\d{8}$/', $s)) {
        return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
    }
    return '';
}


function dp_xlsx_valid_ymd(string $v): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return $dt && $dt->format('Y-m-d') === $v;
}

function dp_xlsx_col_name(int $n): string {
    // 1 -> A
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(($n % 26) + 65) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

function dp_xlsx_xml_escape(string $s): string {
    // Excel inlineStr용: 기본 XML escape
    return str_replace(
        ['&', '<', '>', '"', "'"],
        ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
        $s
    );
}

function dp_xlsx_cell_inline(int $colIndex, int $rowIndex, string $text): string {
    $ref = dp_xlsx_col_name($colIndex) . (string)$rowIndex;
    $t = dp_xlsx_xml_escape($text);
    // preserve whitespace/newline
    return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $t . '</t></is></c>';
}

function dp_xlsx_cell_num(int $colIndex, int $rowIndex, $num): string {
    $ref = dp_xlsx_col_name($colIndex) . (string)$rowIndex;
    // 숫자가 아니면 빈 문자열
    if ($num === null || $num === '') return '<c r="' . $ref . '"/>';
    if (!is_numeric($num)) return '<c r="' . $ref . '"/>';
    // 정수/실수 그대로
    return '<c r="' . $ref . '"><v>' . $num . '</v></c>';
}

/**
 * @return array{whereSql:string, params:array, didFallback:bool}
 */
function dp_xlsx_build_where(array $q): array {
    $today = date('Y-m-d');

    $fromDate = dp_xlsx_norm_ymd($q['from_date'] ?? '');
    $toDate   = dp_xlsx_norm_ymd($q['to_date'] ?? '');

    $fromDate = (dp_xlsx_valid_ymd($fromDate) ? $fromDate : $today);
    $toDate   = (dp_xlsx_valid_ymd($toDate)   ? $toDate   : $today);

    $filterShipTo  = trim((string)($q['ship_to']   ?? ''));
    $filterPackBc  = trim((string)($q['pack_bc']   ?? ''));
    $filterSmallNo = trim((string)($q['small_no']  ?? ''));
    $filterTrayNo  = trim((string)($q['tray_no']   ?? ''));
    $filterPname   = trim((string)($q['part_name'] ?? ''));

    $forceFallback = ((string)($q['fallback'] ?? '') === '1');
    $hasAnyKeyword = (
        $filterShipTo  !== '' ||
        $filterPackBc  !== '' ||
        $filterSmallNo !== '' ||
        $filterTrayNo  !== '' ||
        $filterPname   !== ''
    );

    $dateWhereParts   = [];
    $dateParams       = [];
    $filterWhereParts = [];
    $filterParams     = [];

    if (!$forceFallback) {
        $fromDt = $fromDate . ' 00:00:00';
        $toDt   = date('Y-m-d 00:00:00', strtotime($toDate . ' +1 day'));

        $dateWhereParts[] = "ship_datetime >= :from_dt";
        $dateWhereParts[] = "ship_datetime < :to_dt";
        $dateParams[':from_dt'] = $fromDt;
        $dateParams[':to_dt']   = $toDt;
    }

    if ($filterShipTo !== '') {
        $filterWhereParts[]       = 'ship_to LIKE :ship_to';
        $filterParams[':ship_to'] = '%' . $filterShipTo . '%';
    }
    if ($filterPackBc !== '') {
        $filterWhereParts[]       = 'pack_barcode LIKE :pack_bc';
        $filterParams[':pack_bc'] = '%' . $filterPackBc . '%';
    }
    if ($filterSmallNo !== '') {
        $filterWhereParts[]        = 'small_pack_no LIKE :small_no';
        $filterParams[':small_no'] = '%' . $filterSmallNo . '%';
    }
    if ($filterTrayNo !== '') {
        $filterWhereParts[]       = 'tray_no LIKE :tray_no';
        $filterParams[':tray_no'] = '%' . $filterTrayNo . '%';
    }
    if ($filterPname !== '') {
        $filterWhereParts[]         = 'part_name LIKE :part_name';
        $filterParams[':part_name'] = '%' . $filterPname . '%';
    }

    $whereParts = array_merge($dateWhereParts, $filterWhereParts);
    $params     = array_merge($dateParams, $filterParams);
    $whereSql   = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    return [
        'whereSql' => $whereSql,
        'params' => $params,
        'didFallback' => false,
        'forceFallback' => $forceFallback,
        'hasAnyKeyword' => $hasAnyKeyword,
        'filterWhereParts' => $filterWhereParts,
        'filterParams' => $filterParams,
    ];
}

/**
 * @return array{headers:array<int,string>, keys:array<int,string>, types:array<int,string>}
 */
function dp_xlsx_columns(): array {
    // types: 's' string, 'n' numeric
    return [
        'headers' => [
            '창고','포장일자','생산일자','어닐링일자','설비','CAVITY','품번코드','차수','고객사 품번','품번명',
            '모델','프로젝트','소포장 NO','Tray NO','납품처','출고수량','출하일자','고객 LOTID','포장바코드','포장번호','AVI','RETURNDATE','RMA'
        ],
        'keys' => [
            'warehouse','pack_date','prod_date','ann_date','facility','cavity','part_code','revision','customer_part_no','part_name',
            'model','project','small_pack_no','tray_no','ship_to','qty','ship_datetime','customer_lot_id','pack_barcode','pack_no','avi','__return_disp','rma_visit_cnt'
        ],
        'types' => [
            's','s','s','s','s','s','s','s','s','s',
            's','s','s','s','s','n','s','s','s','s','s','s','n'
        ]
    ];
}

function dp_xlsx_filename(string $src, string $mode): string {
    $base = ($src === 'rmalist') ? 'RMAlist' : 'ShipingList';
    $suffix = ($mode === 'page') ? '페이지' : '전체';
    return $base . '_' . $suffix . '.xlsx';
}

function dp_xlsx_output_file(string $zipPath, string $downloadName): void {
    if (headers_sent()) {
        // headers already sent; just output
    } else {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        // RFC 5987 filename*
        $fallbackName = preg_replace('/[^\x20-\x7E]/', '_', $downloadName);
        header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    // Clean buffers
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $size = @filesize($zipPath);
    if ($size !== false) header('Content-Length: ' . (string)$size);

    readfile($zipPath);
}

function dp_xlsx_make_zip(string $sheetXmlPath, string $outZipPath): void {
    $zip = new ZipArchive();
    if ($zip->open($outZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZipArchive open failed');
    }

    $zip->addFromString('[Content_Types].xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML);

    $zip->addFromString('_rels/.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML);

    $zip->addFromString('xl/workbook.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);

    $zip->addFromString('xl/_rels/workbook.xml.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML);

    // Minimal styles
    $zip->addFromString('xl/styles.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font></fonts>
  <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML);

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $zip->addFromString('docProps/core.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
 xmlns:dc="http://purl.org/dc/elements/1.1/"
 xmlns:dcterms="http://purl.org/dc/terms/"
 xmlns:dcmitype="http://purl.org/dc/dcmitype/"
 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:creator>DPTest</dc:creator>
  <cp:lastModifiedBy>DPTest</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">{$now}</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">{$now}</dcterms:modified>
</cp:coreProperties>
XML);

    $zip->addFromString('docProps/app.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>DPTest</Application>
</Properties>
XML);

    $zip->addFile($sheetXmlPath, 'xl/worksheets/sheet1.xml');

    $zip->close();
}

function dp_xlsx_generate_sheet_xml(string $sheetXmlPath, array $col, iterable $rows): void {
    $headers = $col['headers'];
    $keys = $col['keys'];
    $types = $col['types'];

    $fh = fopen($sheetXmlPath, 'wb');
    if (!$fh) throw new RuntimeException('Cannot write sheet xml');

    fwrite($fh, "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n");
    fwrite($fh, "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">\n");
    fwrite($fh, "  <sheetData>\n");

    // Header row r=1
    fwrite($fh, "    <row r=\"1\">");
    $c = 1;
    foreach ($headers as $h) {
        fwrite($fh, dp_xlsx_cell_inline($c, 1, (string)$h));
        $c++;
    }
    fwrite($fh, "</row>\n");

    $r = 2;
    foreach ($rows as $row) {
        fwrite($fh, "    <row r=\"" . $r . "\">");
        for ($i = 0; $i < count($keys); $i++) {
            $key = $keys[$i];
            $type = $types[$i] ?? 's';
            $val = $row[$key] ?? '';
            $colIndex = $i + 1;

            if ($type === 'n') {
                fwrite($fh, dp_xlsx_cell_num($colIndex, $r, $val));
            } else {
                fwrite($fh, dp_xlsx_cell_inline($colIndex, $r, (string)$val));
            }
        }
        fwrite($fh, "</row>\n");
        $r++;
    }

    fwrite($fh, "  </sheetData>\n");
    fwrite($fh, "</worksheet>\n");

    fclose($fh);
}

function dp_excel_export_run(string $mode): void {
    if ($mode !== 'all' && $mode !== 'page') {
        http_response_code(400);
        echo "Invalid mode";
        exit;
    }

    $src = trim((string)($_GET['src'] ?? ''));
    if ($src !== 'shipinglist' && $src !== 'rmalist') {
        http_response_code(400);
        echo "Invalid src";
        exit;
    }

    $pdo = dp_get_pdo();
    $whereInfo = dp_xlsx_build_where($_GET);

    // fallback 체크 (list 페이지와 동일하게: 날짜조건 0건 + 키워드 있으면 날짜 제외)
    $table = ($src === 'rmalist') ? 'rmalist' : 'ShipingList';

    $countSql = "SELECT COUNT(*) FROM {$table} {$whereInfo['whereSql']}";
    $st = $pdo->prepare($countSql);
    $st->execute($whereInfo['params']);
    $total = (int)$st->fetchColumn();

    $didFallback = false;
    $whereSql = $whereInfo['whereSql'];
    $params = $whereInfo['params'];

    if (!$whereInfo['forceFallback'] && $total === 0 && $whereInfo['hasAnyKeyword']) {
        $didFallback = true;
        $whereParts = $whereInfo['filterWhereParts'];
        $params = $whereInfo['filterParams'];
        $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

        $countSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
        $st = $pdo->prepare($countSql);
        $st->execute($params);
        $total = (int)$st->fetchColumn();
    }

    // 페이지/limit
    $limit = null;
    $offset = null;
    if ($mode === 'page') {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 100;
        $allowed = [50, 100, 200, 500];
        if (!in_array($perPage, $allowed, true)) $perPage = 100;

        $limit = $perPage;
        $offset = ($page - 1) * $perPage;
    }

    $selectPrefix = ($src === 'rmalist') ? 'rmalist' : 'ShipingList';

    $listSql = "
        SELECT {$selectPrefix}.*,
               (
                   SELECT MAX(COALESCE(r.return_datetime, r.return_date))
                   FROM `rmalist` r
                   WHERE r.small_pack_no = {$selectPrefix}.small_pack_no
               ) AS last_return_datetime
        FROM {$table}
        {$whereSql}
        ORDER BY ship_datetime DESC, id DESC
    ";

    if ($mode === 'page') {
        $listSql .= " LIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    if ($mode === 'page') {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    }
    $stmt->execute();

    $col = dp_xlsx_columns();

    // rows iterator: PDOStatement fetch in generator
    $rowsIter = (function() use ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // RETURNDATE 표시값 통일
            $retDisp = ($row['last_return_datetime'] ?? '') ?: (($row['return_date'] ?? '') ?: ($row['return_datetime'] ?? ''));
            if (is_string($retDisp) && substr($retDisp, 0, 10) === '0000-00-00') $retDisp = '';
            $row['__return_disp'] = (string)($retDisp ?? '');

            yield $row;
        }
    })();

    // temp paths
    $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dptest_xlsx_' . uniqid('', true);
    @mkdir($tmpBase, 0777, true);

    $sheetXml = $tmpBase . DIRECTORY_SEPARATOR . 'sheet1.xml';
    $zipPath  = $tmpBase . DIRECTORY_SEPARATOR . 'export.xlsx';

    dp_xlsx_generate_sheet_xml($sheetXml, $col, $rowsIter);
    dp_xlsx_make_zip($sheetXml, $zipPath);

    $downloadName = dp_xlsx_filename($src, $mode);
    dp_xlsx_output_file($zipPath, $downloadName);

    // cleanup (best-effort)
    @unlink($sheetXml);
    @unlink($zipPath);
    @rmdir($tmpBase);
    exit;
}
