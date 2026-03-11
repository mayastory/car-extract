<?php
// oqc_upload.php (FAST XML IMPORT)
// - xlsx/xlsm: ZipArchive + XMLReader로 OQC 시트 XML 직접 파싱 (PhpSpreadsheet 제거)
// - 수식 셀은 캐시(<v>)만 사용: 캐시 없으면 NULL 처리 + missing_cache++
// - 다중 업로드
// - 다건 INSERT
// - 시간 분해 출력(load/parse/db)

ini_set('max_execution_time', '600');
ini_set('max_input_time', '600');
ini_set('memory_limit', '1024M');
set_time_limit(600);

session_start();
require_once __DIR__ . '/config/dp_config.php';

$errors = [];
$successes = [];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- 파일명 파싱 ----------
function detect_part_name_from_filename(string $filename): string {
    $lower = mb_strtolower($filename, 'UTF-8');
    if (strpos($lower, 'ir base') !== false || strpos($lower, 'ir_base') !== false) return 'MEM-IR-BASE';
    if (strpos($lower, 'x carrier') !== false || strpos($lower, 'x_carrier') !== false) return 'MEM-X-CARRIER';
    if (strpos($lower, 'y carrier') !== false || strpos($lower, 'y_carrier') !== false) return 'MEM-Y-CARRIER';
    if (strpos($lower, 'z carrier') !== false || strpos($lower, 'z_carrier') !== false) return 'MEM-Z-CARRIER';
    if (strpos($lower, 'z stopper') !== false || strpos($lower, 'z_stopper') !== false) return 'MEM-Z-STOPPER';
    return $filename;
}

function detect_lot_date_from_filename(string $filename): ?string
{
    // 확장자 제거한 파일명만
    $base = pathinfo($filename, PATHINFO_FILENAME);

    // 1) _YYYYMMDD_ 형태도 지원 (예: _20250814_)
    if (preg_match_all('/(?:^|_)(\d{8})(?=_|$)/', $base, $m8) && !empty($m8[1])) {
        $cand = end($m8[1]); // 마지막 매치
        $yyyy = (int)substr($cand, 0, 4);
        $mm   = (int)substr($cand, 4, 2);
        $dd   = (int)substr($cand, 6, 2);
        return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
    }

    // 2) _YYMMDD_ 형태 지원 (예: _250814_ , _250814_OK_1)
    if (preg_match_all('/(?:^|_)(\d{6})(?=_|$)/', $base, $m6) && !empty($m6[1])) {
        $cand = end($m6[1]); // 마지막 매치
        $yy = (int)substr($cand, 0, 2);
        $mm = (int)substr($cand, 2, 2);
        $dd = (int)substr($cand, 4, 2);
        return sprintf('20%02d-%02d-%02d', $yy, $mm, $dd);
    }

    return null;
}

function normalize_multi_files(array $files): array {
    $out = [];
    if (!isset($files['name']) || !is_array($files['name'])) return $out;
    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        $out[] = [
            'name'     => $files['name'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'][$i] ?? 0,
        ];
    }
    return $out;
}

function table_columns(PDO $pdo, string $table): array {
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[strtolower($r['Field'])] = true;
    return $cols;
}

// ---------- Excel 유틸 ----------
function col_letters_to_index(string $letters): int {
    $letters = strtoupper($letters);
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n;
}

function cell_ref_to_col_row(string $ref): array {
    if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) return [0, 0];
    return [col_letters_to_index($m[1]), (int)$m[2]];
}

function excel_serial_to_date($serial): ?DateTime {
    if (!is_numeric($serial)) return null;
    $base = new DateTime('1899-12-30');
    $days = (int)$serial;
    if ($days !== 0) $base->add(new DateInterval('P' . $days . 'D'));
    return $base;
}

function parse_kor_mmdd_to_date(string $s, int $year): ?string {
    $s = trim($s);
    if ($s === '') return null;

    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일\s*$/u', $s, $m)) {
        return sprintf('%04d-%02d-%02d', $year, (int)$m[1], (int)$m[2]);
    }
    return null;
}

function parse_meas_date_value($raw, int $year): ?string {
    if ($raw === null || $raw === '') return null;

    if ($raw instanceof DateTimeInterface) return $raw->format('Y-m-d');

    if (is_numeric($raw)) {
        $dt = excel_serial_to_date($raw);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    if (is_string($raw)) {
        $p = parse_kor_mmdd_to_date($raw, $year);
        if ($p) return $p;
    }
    return null;
}

function to_float_or_null($v): ?float {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (float)$v;
    if (is_string($v)) {
        $s = str_replace([',', ' '], '', trim($v));
        if ($s === '' || !is_numeric($s)) return null;
        return (float)$s;
    }
    return null;
}

function normalize_point_no_string(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    // Keep non-numeric point labels as-is (e.g., '55-1(P1)')
    if (!preg_match('/^-?\d+(?:\.\d+)?$/', $s)) return $s;
    // If it looks like an over-precise float coming from xlsx XML, trim it safely.
    // We only normalize when fractional part is very long to avoid altering legitimate high-precision labels.
    if (strpos($s, '.') !== false) {
        $frac = substr($s, strpos($s, '.') + 1);
        if (strlen($frac) > 6) {
            $f = (float)$s;
            // 10 decimals is plenty; then trim trailing zeros/dot
            $s2 = rtrim(rtrim(number_format($f, 10, '.', ''), '0'), '.');
            return $s2;
        }
    }
    return $s;
}


// ---------- ZIP / XML ----------
function open_zip_entry_to_temp(ZipArchive $zip, string $entry): string {
    $stream = $zip->getStream($entry);
    if (!$stream) throw new RuntimeException("ZIP 엔트리 열기 실패: {$entry}");
    $tmp = tempnam(sys_get_temp_dir(), 'oqcxml_');
    $out = fopen($tmp, 'wb');
    stream_copy_to_stream($stream, $out);
    fclose($out);
    fclose($stream);
    return $tmp;
}

function load_shared_strings(ZipArchive $zip): array {
    if ($zip->locateName('xl/sharedStrings.xml') === false) return [];
    $tmp = open_zip_entry_to_temp($zip, 'xl/sharedStrings.xml');

    $xr = new XMLReader();
    $xr->open($tmp);

    $strings = [];
    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'si') {
            $depth = $xr->depth;
            $text = '';
            while ($xr->read()) {
                if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 't') {
                    $text .= $xr->readString();
                }
                if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'si' && $xr->depth === $depth) break;
            }
            $strings[] = $text;
        }
    }

    $xr->close();
    @unlink($tmp);
    return $strings;
}

function find_oqc_sheet_entry(ZipArchive $zip): string {
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) throw new RuntimeException("workbook.xml 또는 rels를 찾을 수 없습니다.");

    $wbXml = simplexml_load_string($wb);
    if (!$wbXml) throw new RuntimeException("workbook.xml 파싱 실패");

    $wbXml->registerXPathNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wbXml->registerXPathNamespace('r',  'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $sheets = $wbXml->xpath('//ns:sheets/ns:sheet[@name="OQC"]');
    if (!$sheets || !isset($sheets[0])) throw new RuntimeException('"OQC" 시트를 workbook.xml에서 찾을 수 없습니다.');

    $rid = (string)$sheets[0]->attributes('r', true)['id'];
    if ($rid === '') throw new RuntimeException('OQC 시트 r:id를 찾을 수 없습니다.');

    $relsXml = simplexml_load_string($rels);
    if (!$relsXml) throw new RuntimeException("workbook.xml.rels 파싱 실패");

    $relsXml->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relNodes = $relsXml->xpath('//rel:Relationship[@Id="'.$rid.'"]');
    if (!$relNodes || !isset($relNodes[0])) throw new RuntimeException("rels에서 {$rid} 타겟을 찾을 수 없습니다.");

    $target = (string)$relNodes[0]['Target']; // e.g. worksheets/sheet2.xml
    if ($target === '') throw new RuntimeException("rels Target 비어있음");

    $entry = 'xl/' . ltrim($target, '/');
    if ($zip->locateName($entry) === false) throw new RuntimeException("시트 파일을 ZIP에서 찾을 수 없습니다: {$entry}");
    return $entry;
}

/**
 * <c> 셀 요소 파싱
 * - 수식이면 <f> 존재, 캐시는 <v>
 * - 캐시(<v>)가 없으면 null 리턴 + missing_cache++
 */
function read_cell(XMLReader $xr, array $shared, int &$missingCache): array {
    $ref = $xr->getAttribute('r') ?? '';
    $t   = $xr->getAttribute('t') ?? ''; // s, inlineStr, b, str, n(없음)
    [$col, $row] = cell_ref_to_col_row($ref);

    $isFormula = false;
    $vText = null;
    $inlineText = '';

    if ($xr->isEmptyElement) {
        return [$row, $col, null, false];
    }

    $depth = $xr->depth;
    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT) {
            if ($xr->name === 'f') {
                $isFormula = true;
            } elseif ($xr->name === 'v') {
                $vText = $xr->readString();
            } elseif ($xr->name === 't' && $t === 'inlineStr') {
                $inlineText .= $xr->readString();
            }
        }
        if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'c' && $xr->depth === $depth) break;
    }

    if ($t === 's') {
        $idx = is_numeric($vText) ? (int)$vText : -1;
        $val = ($idx >= 0 && isset($shared[$idx])) ? $shared[$idx] : '';
        return [$row, $col, $val, $isFormula];
    }
    if ($t === 'inlineStr') {
        return [$row, $col, $inlineText, $isFormula];
    }
    if ($t === 'b') {
        return [$row, $col, ($vText === '1') ? 1 : 0, $isFormula];
    }

    // 숫자/문자/수식 캐시
    if ($isFormula) {
        if ($vText === null || $vText === '') {
            $missingCache++;
            return [$row, $col, null, true];
        }
        return [$row, $col, $vText, true];
    }
    return [$row, $col, $vText, false];
}

// ---------- 다건 INSERT ----------
function bulk_insert(PDO $pdo, string $sqlPrefix, array $rows, int $colsPerRow, int $chunkRows = 400): int {
    $inserted = 0;
    $n = count($rows);
    for ($i = 0; $i < $n; $i += $chunkRows) {
        $chunk = array_slice($rows, $i, $chunkRows);
        if (!$chunk) continue;

        $phOne = '(' . implode(',', array_fill(0, $colsPerRow, '?')) . ')';
        $sql = $sqlPrefix . implode(',', array_fill(0, count($chunk), $phOne));

        $params = [];
        foreach ($chunk as $r) foreach ($r as $v) $params[] = $v;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inserted += count($chunk);
    }
    return $inserted;
}

// ---------- 핵심 처리 ----------
function process_one_file(PDO $pdo, array $headerCols, string $tmpPath, string $origName): array {
    if (!preg_match('/\.(xlsx|xlsm)$/i', $origName)) {
        throw new RuntimeException("지원하지 않는 파일 형식: {$origName}");
    }

    $tLoad0 = microtime(true);

    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        throw new RuntimeException("ZIP 열기 실패(엑셀이 손상되었거나 권한 문제): {$origName}");
    }

    $sheetEntry = find_oqc_sheet_entry($zip);
    $shared = load_shared_strings($zip);

    $missingCache = 0;
    $firstDataRow = 11;
    $firstDataCol = col_letters_to_index('AK');

    // --- 1) META 스캔: row 4~6(날짜), 9~10(FAI/USL/LSL/툴명) ---
    $meta = [
        'row10' => [],  // col => string
        'row9'  => [],
        'date4' => [],  // col => raw
        'date5' => [],
        'date6' => [],
        'resultStartCol' => null,
        'uslCol' => null,
        'lslCol' => null,
    ];

    $tmpSheet = open_zip_entry_to_temp($zip, $sheetEntry);
    $xr = new XMLReader();
    $xr->open($tmpSheet);

    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'c') {
            [$r, $c, $val, $isFormula] = read_cell($xr, $shared, $missingCache);
            if ($r === 10) $meta['row10'][$c] = is_string($val) ? trim($val) : (string)$val;
            elseif ($r === 9) $meta['row9'][$c]  = is_string($val) ? trim($val) : (string)$val;
            elseif ($r === 6) $meta['date6'][$c] = $val;
            elseif ($r === 5) $meta['date5'][$c] = $val;
            elseif ($r === 4) $meta['date4'][$c] = $val;
        }
    }
    $xr->close();
    @unlink($tmpSheet);

    // resultStartCol: row10에서 'FAI'
    foreach ($meta['row10'] as $c => $s) {
        if (mb_strtoupper(trim((string)$s), 'UTF-8') === 'FAI') {
            $meta['resultStartCol'] = (int)$c;
            break;
        }
    }
    if (!$meta['resultStartCol']) throw new RuntimeException("row10에서 'FAI' 컬럼을 찾지 못함(템플릿 확인 필요)");

    // USL/LSL: row10 우선, 없으면 row9
    foreach ([$meta['row10'], $meta['row9']] as $rowMap) {
        if (!$meta['uslCol']) {
            foreach ($rowMap as $c => $s) {
                $u = mb_strtoupper(trim((string)$s), 'UTF-8');
                if ($u === 'USL' || mb_strpos($u, 'USL') !== false) { $meta['uslCol'] = (int)$c; break; }
            }
        }
        if (!$meta['lslCol']) {
            foreach ($rowMap as $c => $s) {
                $u = mb_strtoupper(trim((string)$s), 'UTF-8');
                if ($u === 'LSL' || mb_strpos($u, 'LSL') !== false) { $meta['lslCol'] = (int)$c; break; }
            }
        }
    }

    $measureEndCol = $meta['resultStartCol'] - 1;
    if ($measureEndCol < $firstDataCol) throw new RuntimeException("측정 컬럼 범위가 비정상입니다(AK ~ FAI-1).");

    $tLoad = microtime(true) - $tLoad0;

    // --- 2) DATA 스캔: row>=11에서 B,C,USL,LSL,AK..measureEnd ---
    $tParse0 = microtime(true);

    $pointNoByRow = [];
    $spcByRow = [];
    $uslByRow = [];
    $lslByRow = [];

    $measByCol = [];      // col => [[row,val], ...]
    $filledByCol = [];    // col => count
    $lastDataRowUsed = 0;
    $lastPointRowSeen = 0;

    $tmpSheet2 = open_zip_entry_to_temp($zip, $sheetEntry);
    $xr2 = new XMLReader();
    $xr2->open($tmpSheet2);

    while ($xr2->read()) {
        if ($xr2->nodeType === XMLReader::ELEMENT && $xr2->name === 'c') {
            [$r, $c, $val, $isFormula] = read_cell($xr2, $shared, $missingCache);

            if ($r < 11 || $r > 1500) continue;

            if ($c === 2) { // B point
                $s = is_string($val) ? trim($val) : (string)$val;
                $s = normalize_point_no_string($s);
                if ($s !== '') {
                    $pointNoByRow[$r] = $s;
                    if ($r > $lastPointRowSeen) $lastPointRowSeen = $r;
                }
                continue;
            }
            if ($c === 3) { // C spc
                $s = is_string($val) ? trim($val) : (string)$val;
                if ($s !== '') $spcByRow[$r] = $s;
                continue;
            }

            if ($meta['uslCol'] && $c === $meta['uslCol']) {
                $f = to_float_or_null($val);
                if ($f !== null) $uslByRow[$r] = $f;
                continue;
            }
            if ($meta['lslCol'] && $c === $meta['lslCol']) {
                $f = to_float_or_null($val);
                if ($f !== null) $lslByRow[$r] = $f;
                continue;
            }

            if ($c >= $firstDataCol && $c <= $measureEndCol) {
                $f = to_float_or_null($val);
                if ($f === null) continue;

                if (!isset($measByCol[$c])) $measByCol[$c] = [];
                $measByCol[$c][] = [$r, $f];

                $filledByCol[$c] = ($filledByCol[$c] ?? 0) + 1;
                if ($r > $lastDataRowUsed) $lastDataRowUsed = $r;
            }
        }
    }

    $xr2->close();
    @unlink($tmpSheet2);

    $tParse = microtime(true) - $tParse0;

    // totalRows는 실제 값이 들어간 마지막 row 기준으로 잡는 게 제일 안정적
    $lastPointRow = ($lastDataRowUsed > 0) ? $lastDataRowUsed : $lastPointRowSeen;
    if ($lastPointRow < $firstDataRow) $lastPointRow = $firstDataRow;
    $totalRows = max(1, $lastPointRow - $firstDataRow + 1);

    // --- 3) DB 처리 ---
    $tDb0 = microtime(true);

    $partName = detect_part_name_from_filename($origName);
    $lotDateStr = detect_lot_date_from_filename($origName);
    if (!$lotDateStr) throw new RuntimeException("파일명에서 날짜(YYMMDD)를 찾지 못함: {$origName}");
    $shipDateStr = $lotDateStr;
    $year = (int)substr($lotDateStr, 0, 4);

    // 중복 삭제
    $stmtOld = $pdo->prepare("
        SELECT id
        FROM oqc_header
        WHERE part_name = ?
          AND source_file = ?
          AND (
                " . (isset($headerCols['ship_date']) ? "ship_date = ?" : "1=0") . "
                OR lot_date = ?
              )
    ");
    $bind = [$partName, $origName];
    if (isset($headerCols['ship_date'])) $bind[] = $shipDateStr;
    $bind[] = $lotDateStr;

    $stmtOld->execute($bind);
    $oldIds = $stmtOld->fetchAll(PDO::FETCH_COLUMN);
    if ($oldIds) {
        $in = implode(',', array_fill(0, count($oldIds), '?'));
        $pdo->prepare("DELETE FROM oqc_measurements WHERE header_id IN ($in)")->execute($oldIds);
        $pdo->prepare("DELETE FROM oqc_result_header WHERE header_id IN ($in)")->execute($oldIds);
        $pdo->prepare("DELETE FROM oqc_header WHERE id IN ($in)")->execute($oldIds);
    }

    // header insert 준비
    $fields = ['part_name', 'tool_cavity', 'kind', 'source_file', 'excel_col'];
    if (isset($headerCols['ship_date'])) $fields[] = 'ship_date';
    if (isset($headerCols['lot_date']))  $fields[] = 'lot_date';
    if (isset($headerCols['meas_date'])) $fields[] = 'meas_date';

    $placeholders = array_map(fn($f) => ':' . $f, $fields);
    $stmtHeader = $pdo->prepare("
        INSERT INTO oqc_header (" . implode(',', $fields) . ")
        VALUES (" . implode(',', $placeholders) . ")
    ");

    $headerInserted = 0;
    $measInserted = 0;
    $resInserted = 0;

    // colIndex -> Excel Letter (필요한 범위만 변환)
    $colLetters = [];
    for ($c = $firstDataCol; $c <= $measureEndCol; $c++) {
        $colLetters[$c] = ''; // lazy
    }
    $idxToLetters = function(int $idx) {
        $s = '';
        while ($idx > 0) {
            $m = ($idx - 1) % 26;
            $s = chr(65 + $m) . $s;
            $idx = intdiv($idx - 1, 26);
        }
        return $s;
    };

    // 실제 값이 있는 컬럼만 헤더 생성
    foreach ($measByCol as $colIdx => $pairs) {
        $filled = $filledByCol[$colIdx] ?? 0;
        if ($filled <= 0) continue;

        // tool_cavity: row10의 해당 컬럼 값
        $toolCav = trim((string)($meta['row10'][$colIdx] ?? ''));
        if ($toolCav === '') $toolCav = '(UNKNOWN)';

        // meas_date: row6 > row5 > row4
        $raw6 = $meta['date6'][$colIdx] ?? null;
        $raw5 = $meta['date5'][$colIdx] ?? null;
        $raw4 = $meta['date4'][$colIdx] ?? null;
        $measDateStr = parse_meas_date_value($raw6, $year)
                    ?? parse_meas_date_value($raw5, $year)
                    ?? parse_meas_date_value($raw4, $year);

        $ratio = ($totalRows > 0) ? ($filled / $totalRows) : 0.0;

// ✅ KIND 결정 규칙(슬롯 고정)
// - AK/AL/AM(첫 3칸) : FAI
// - 나머지(AN~)      : SPC
$slotIdx = (int)$colIdx - (int)$firstDataCol; // 0=AK, 1=AL, 2=AM ...
$kind = ($slotIdx >= 0 && $slotIdx < 3) ? 'FAI' : 'SPC';
if ($colLetters[$colIdx] === '') $colLetters[$colIdx] = $idxToLetters($colIdx);
        $excelColLetter = $colLetters[$colIdx];

        $hp = [
            ':part_name'   => $partName,
            ':tool_cavity' => $toolCav,
            ':kind'        => $kind,
            ':source_file' => $origName,
            ':excel_col'   => $excelColLetter,
        ];
        if (isset($headerCols['ship_date'])) $hp[':ship_date'] = $shipDateStr;
        if (isset($headerCols['lot_date']))  $hp[':lot_date']  = $lotDateStr;
        if (isset($headerCols['meas_date'])) $hp[':meas_date'] = $measDateStr;

        $stmtHeader->execute($hp);
        $headerId = (int)$pdo->lastInsertId();
        $headerInserted++;

        // measurements/result rows 만들기
        $measBulk = [];
        $resBulk  = [];

        foreach ($pairs as [$row, $val]) {
            $pointNo = $pointNoByRow[$row] ?? (string)$row;
            $spcCode = $spcByRow[$row] ?? null;

            $measBulk[] = [$headerId, $pointNo, $spcCode, $val, $row];

            $usl = $uslByRow[$row] ?? null;
            $lsl = $lslByRow[$row] ?? null;

            $ok = null;
            if ($usl !== null || $lsl !== null) {
                $ok = 1;
                if ($usl !== null && $val > $usl) $ok = 0;
                if ($lsl !== null && $val < $lsl) $ok = 0;
            }

            $resBulk[] = [$headerId, $pointNo, $val, $val, $val, $usl, $lsl, $ok];
        }

        $measInserted += bulk_insert(
            $pdo,
            "INSERT INTO oqc_measurements (header_id, point_no, spc_code, value, row_index) VALUES ",
            $measBulk, 5, 600
        );

        $resInserted += bulk_insert(
            $pdo,
            "INSERT INTO oqc_result_header (header_id, point_no, max_val, min_val, mean_val, usl, lsl, result_ok) VALUES ",
            $resBulk, 8, 500
        );
    }

    $tDb = microtime(true) - $tDb0;

    $zip->close();

    return [
        'part_name' => $partName,
        'ship_date' => $shipDateStr,
        'source_file' => $origName,
        'header' => $headerInserted,
        'meas'   => $measInserted,
        'res'    => $resInserted,
        'missing_cache' => $missingCache,
        't_load' => $tLoad,
        't_parse' => $tParse,
        't_db' => $tDb,
        'cols_scanned' => max(0, $measureEndCol - $firstDataCol + 1),
        'cols_considered' => count($measByCol),
        'total_rows_used' => $totalRows,
    ];
}

// ---------- POST 처리 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_FILES['oqc_files'])) throw new RuntimeException('업로드 파일이 없습니다.');

        $files = normalize_multi_files($_FILES['oqc_files']);
        if (!$files) throw new RuntimeException('업로드 파일이 없습니다.');

        $pdo = dp_get_pdo();
        $headerCols = table_columns($pdo, 'oqc_header');

        foreach ($files as $f) {
            $name = $f['name'] ?? '';
            if ($name === '') continue;

            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = "{$name} : 업로드 오류 코드(" . ($f['error'] ?? -1) . ")";
                continue;
            }
            if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
                $errors[] = "{$name} : 임시파일이 올바르지 않습니다.";
                continue;
            }

            try {
                $pdo->beginTransaction();
                $t0 = microtime(true);

                $info = process_one_file($pdo, $headerCols, $f['tmp_name'], $name);

                $pdo->commit();
                $dt = microtime(true) - $t0;

                $successes[] =
                    "{$info['source_file']} / {$info['part_name']} / {$info['ship_date']} "
                    . "(header {$info['header']}, meas {$info['meas']}, res {$info['res']}) "
                    . "missing_cache={$info['missing_cache']} / "
                    . "load=" . number_format($info['t_load'], 2) . "s, "
                    . "parse=" . number_format($info['t_parse'], 2) . "s, "
                    . "db=" . number_format($info['t_db'], 2) . "s / "
                    . "cols(scanned={$info['cols_scanned']}, considered={$info['cols_considered']}), rows_used={$info['total_rows_used']} / "
                    . "total=" . number_format($dt, 2) . "s";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = "{$name} : " . $e->getMessage();
            }
        }

        if (!$successes && !$errors) $errors[] = '업로드할 파일이 없습니다.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>OQC 측정 데이터 업로드</title>
    <style>
        body { background:#111827; color:#e5e7eb; font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .wrap { max-width:920px; margin:40px auto; padding:24px 28px; background:#020617; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,0.6); }
        h1 { font-size:22px; margin:0 0 16px; }
        label { display:block; margin:18px 0 6px; font-size:14px; color:#9ca3af; }
        input[type="file"], input[type="submit"] { width:100%; box-sizing:border-box; }
        input[type="file"] { padding:8px; border-radius:8px; border:1px solid #374151; background:#020617; color:#e5e7eb; }
        input[type="submit"] { margin-top:18px; padding:10px 16px; border-radius:999px; border:none; background:#2563eb; color:white; font-weight:600; cursor:pointer; }
        input[type="submit"]:hover { background:#1d4ed8; }
        .msg { margin-top:14px; padding:10px 12px; border-radius:8px; font-size:13px; }
        .msg.error { background:#451a1a; color:#fecaca; border:1px solid #b91c1c; }
        .msg.ok { background:#022c22; color:#bbf7d0; border:1px solid #16a34a; }
        ul { margin:8px 0 0 18px; }
        li { margin:6px 0; line-height:1.35; }
        .hint { margin-top:10px; font-size:12px; color:#9ca3af; line-height:1.4; }
        code { background:#0b1020; padding:1px 6px; border-radius:6px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>OQC 측정 데이터 업로드</h1>

    <?php if ($successes): ?>
        <div class="msg ok">
            업로드 성공:
            <ul><?php foreach ($successes as $s): ?><li><?=h($s)?></li><?php endforeach; ?></ul>

        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="msg error">
            업로드 실패/경고:
            <ul><?php foreach ($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <label for="oqc_files">OQC 엑셀 파일 여러 개 선택 (.xlsx / .xlsm)</label>
        <input type="file" id="oqc_files" name="oqc_files[]" accept=".xlsx,.xlsm" multiple required>
        <input type="submit" value="업로드 &amp; DB 등록">
    </form>

</div>
</body>
</html>