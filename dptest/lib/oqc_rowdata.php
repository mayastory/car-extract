<?php




// -----------------------------------------------------------------------------
// Point/Key normalization (point_no ONLY; NEVER map by spc_code)
// - Handles unicode dashes, NBSP/ZWSP, extra spaces
// - Normalized keys are used for ALL point_no matching in this file and callers.
// -----------------------------------------------------------------------------
if (!function_exists('oqc_norm_key')) {
    function oqc_norm_key($v): string {
        if ($v === null) return '';
        $s = (string)$v;
        // remove BOM / NBSP / zero-width spaces
        $s = str_replace(["\xEF\xBB\xBF", "\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"], '', $s);
        $s = trim($s);
        if ($s === '') return '';
        // normalize various unicode dashes/minus to '-'
        $s = preg_replace('/[\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}\x{FE63}\x{FF0D}]/u', '-', $s);
        // collapse all whitespace
        $s = preg_replace('/\s+/u', '', $s);
        return strtoupper($s);
    }
}

if (!function_exists('oqc_base_key')) {
    function oqc_base_key($v): string {
        $s = oqc_norm_key($v);
        if ($s === '') return '';
        // remove parenthetical suffix, e.g. "8-3(U)" -> "8-3"
        $s = preg_replace('/\(.*?\)/', '', $s);
        $s = trim($s);
        return $s;
    }
}

if (!function_exists('oqc_key_variants')) {
    function oqc_key_variants($v): array {
        $k = oqc_norm_key($v);
        if ($k === '') return [];
        $out = [$k];
        if (strpos($k, '-') !== false) $out[] = str_replace('-', '_', $k);
        if (strpos($k, '_') !== false) $out[] = str_replace('_', '-', $k);
        // also try base-key variants if parentheses exist
        if (strpos($k, '(') !== false || strpos($k, ')') !== false) {
            $b = oqc_base_key($k);
            if ($b !== '' && $b !== $k) {
                $out[] = $b;
                if (strpos($b, '-') !== false) $out[] = str_replace('-', '_', $b);
                if (strpos($b, '_') !== false) $out[] = str_replace('_', '-', $b);
            }
        }
        // unique preserve order
        $seen = [];
        $uniq = [];
        foreach ($out as $x) {
            if ($x === '') continue;
            if (isset($seen[$x])) continue;
            $seen[$x] = true;
            $uniq[] = $x;
        }
        return $uniq;
    }
}

if (!function_exists('oqc_base_key_variants')) {
    function oqc_base_key_variants($v): array {
        $b = oqc_base_key($v);
        if ($b === '') return [];
        $out = [$b];
        if (strpos($b, '-') !== false) $out[] = str_replace('-', '_', $b);
        if (strpos($b, '_') !== false) $out[] = str_replace('_', '-', $b);
        $seen = [];
        $uniq = [];
        foreach ($out as $x) {
            if ($x === '') continue;
            if (isset($seen[$x])) continue;
            $seen[$x] = true;
            $uniq[] = $x;
        }
        return $uniq;
    }
}
// 현재 NG 예외(LSL/USL 초과/미달 무시) 포인트 Set 로드
// - shipinglist_export_lotlist.php에서 $GLOBALS['NG_EXEMPT_TEMPLATE_POINTS']에 set 형태로 넣어줌
// - 호환을 위해 list 형태도 지원
function oqc_current_ng_ignore_set(): array {
    $set = [];
    $g = $GLOBALS['NG_EXEMPT_TEMPLATE_POINTS'] ?? [];
    if (!is_array($g)) return $set;

    foreach ($g as $k => $v) {
        $nk = '';
        // set 형태: [point_no => true] (주의: 숫자 문자열 key는 PHP에서 int key로 변환될 수 있음)
        if (is_scalar($k) && ($v === true || $v === 1 || $v === '1')) {
            $nk = oqc_norm_key((string)$k);
        } else if (is_scalar($v)) {
            // list 형태: ['113-V1', '114-V1', ...]
            $nk = oqc_norm_key((string)$v);
        }
        if ($nk !== '') $set[$nk] = true;
    }
    return $set;
}
/**
 * AK/AL/AM(앞 3칸)은 FAI 전용 "예약석".
 * - kind='FAI' 인 컬럼만 0~2번 칸에 배치
 * - SPC(또는 kind 미상)는 절대 0~2번 칸에 배치하지 않음 (FAI 없으면 빈칸 유지)
 * - FAI가 3개 초과면 초과분은 3~31번 칸으로 spill(넘겨서) 배치
 * - 전체가 32칸을 넘으면 뒤에서부터 잘림(우선순위: 앞에서 먼저 선택된 것 유지)
 *
 * @return array [$newToolPairs, $newSourceTags, $newHeaderIds, $newKinds, $droppedCount]
 */
function oqc_reorder_cols_fai_reserved(array $toolPairs, array $sourceTags, array $headerIds, array $kinds, int $reservedFaiCols = 3): array {
    $maxCols = count($toolPairs);
    if ($maxCols <= 0) {
        return [$toolPairs, $sourceTags, $headerIds, $kinds, 0];
    }

    // 1) 비어있지 않은 컬럼만 items로 압축(원래 선택 순서 유지)
    $items = [];
    for ($i = 0; $i < $maxCols; $i++) {
        $tp = trim((string)($toolPairs[$i] ?? ''));
        if ($tp === '') continue;

        $items[] = [
            'tp'   => $tp,
            'src'  => (string)($sourceTags[$i] ?? ''),
            'hid'  => (int)($headerIds[$i] ?? 0),
            'kind' => strtoupper(trim((string)($kinds[$i] ?? ''))),
        ];
    }

    // 2) 새 배열 초기화
    $newToolPairs  = array_fill(0, $maxCols, '');
    $newSourceTags = array_fill(0, $maxCols, '');
    $newHeaderIds  = array_fill(0, $maxCols, 0);
    $newKinds      = array_fill(0, $maxCols, '');

    // 슬롯 구성
    $reservedFaiCols = max(0, min($reservedFaiCols, $maxCols));
    $faiSlots = [];
    for ($i = 0; $i < $reservedFaiCols; $i++) $faiSlots[] = $i;

    $otherSlots = [];
    for ($i = $reservedFaiCols; $i < $maxCols; $i++) $otherSlots[] = $i;

    $used = array_fill(0, count($items), false);

    // 3) FAI 먼저 예약석(0~2)에 채움
    //    우선순위(정렬): src_tag(YYMMDD) 최신 > header_id 큰 값(최신 업로드) > 기존 선택순서
    $faiIdxs = [];
    for ($idx = 0; $idx < count($items); $idx++) {
        if (($items[$idx]['kind'] ?? '') === 'FAI') $faiIdxs[] = $idx;
    }
    usort($faiIdxs, function($a, $b) use ($items) {
        $sa = (string)($items[$a]['src'] ?? '');
        $sb = (string)($items[$b]['src'] ?? '');
        if ($sa !== $sb) return strcmp($sb, $sa); // desc
        $ha = (int)($items[$a]['hid'] ?? 0);
        $hb = (int)($items[$b]['hid'] ?? 0);
        if ($ha !== $hb) return $hb <=> $ha; // desc
        return $a <=> $b; // stable
    });

    $faiPos = 0;
    for ($k = 0; $k < count($faiIdxs); $k++) {
        if ($faiPos >= count($faiSlots)) break;
        $idx = $faiIdxs[$k];

        $j = $faiSlots[$faiPos++];
        $newToolPairs[$j]  = $items[$idx]['tp'];
        $newSourceTags[$j] = $items[$idx]['src'];
        $newHeaderIds[$j]  = $items[$idx]['hid'];
        $newKinds[$j]      = $items[$idx]['kind'];
        $used[$idx] = true;
    }
// 4) 나머지(= SPC + 남은 FAI + kind 미상)를 3~31에 순서대로 채움
    $otherPos = 0;
    for ($idx = 0; $idx < count($items); $idx++) {
        if ($used[$idx]) continue;
        if ($otherPos >= count($otherSlots)) break;

        $j = $otherSlots[$otherPos++];
        $newToolPairs[$j]  = $items[$idx]['tp'];
        $newSourceTags[$j] = $items[$idx]['src'];
        $newHeaderIds[$j]  = $items[$idx]['hid'];
        $newKinds[$j]      = $items[$idx]['kind'];
        $used[$idx] = true;
    }

    // 5) 초과분 카운트
    $dropped = 0;
    for ($idx = 0; $idx < count($items); $idx++) {
        if (!$used[$idx]) $dropped++;
    }

    return [$newToolPairs, $newSourceTags, $newHeaderIds, $newKinds, $dropped];
}



/**
 * OQC 템플릿 기준:
 * - AK10:BP10 = Tool#Cavity (각 셀에 "A#1" 형태로 들어감)
 * - AK11:BP?? = 측정데이터
 *
 * 템플릿마다 줄이 달라질 수 있으니,
 * AK~BP 범위에서 Tool#Cavity 패턴(예: A#1)이 가장 많이 잡히는 row를 찾는다.
 */
function find_tool_cavity_block($ws, string $startCol = 'AK', string $endCol = 'BP'): array {
    // OQC 템플릿(Memphis)은 AK~BP Tool#Cavity 헤더가 '빈 상태'로 배포되는 경우가 많다.
    // 따라서 "이미 Tool#Cavity가 들어있는 행"만 찾으면 항상 실패한다.
    //
    // ✅ 우선순위:
    // 1) AK~BP에 Tool#Cavity 패턴이 이미 있는 행(가장 정확)
    // 2) 없으면, D열에 'Description'이 있는 행을 헤더행으로 간주 (대부분 row 10)
    // 3) 그래도 없으면, 기존 관례대로 11행 사용
    //
    // 반환: [startCol, endCol, headerRow, dataStartRow, dataEndRow]

    $startIdx = Coordinate::columnIndexFromString($startCol);
    $endIdx   = Coordinate::columnIndexFromString($endCol);

    $maxRow  = (int)$ws->getHighestRow();
    $scanMax = min($maxRow, 120);

    $bestRow = null;
    $bestCnt = -1;

    // 1) Tool#Cavity 패턴이 있는 행 탐색
    for ($r = 1; $r <= $scanMax; $r++) {
        $cnt = 0;
        for ($ci = $startIdx; $ci <= $endIdx; $ci++) {
            $v = $ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci) . $r)->getValue();
            if ($v === null) continue;
            $s = strtoupper(trim((string)$v));
            $s = preg_replace('/\s+/', '', $s);
            if (preg_match('/^[A-Z]#\d+$/', $s)) $cnt++;
        }
        if ($cnt > $bestCnt) {
            $bestCnt = $cnt;
            $bestRow = $r;
        }
    }

    // 2) 패턴이 전혀 없으면 'Description' 행을 헤더행으로 사용
    if ($bestCnt <= 0) {
        $descRow = null;
        $scan2 = min($maxRow, 80);
        for ($r = 1; $r <= $scan2; $r++) {
            $v = $ws->getCell("D{$r}")->getValue();
            if ($v === null) continue;
            $s = strtolower(trim((string)$v));
            if ($s === 'description') { $descRow = $r; break; }
        }
        if ($descRow !== null) $bestRow = $descRow;
    }

    if ($bestRow === null || $bestRow < 1) $bestRow = 11;

    $dataStart = $bestRow + 1;

    // dataEnd는 B열(FAI/항목명)이 마지막으로 존재하는 행을 기준으로 잡는다.
    // (AK~BP는 템플릿이 비어있을 수 있으므로 기준으로 쓰면 안 됨)
    $dataEnd = $dataStart;
    $scanEnd = min($maxRow, 2000);
    for ($r = $dataStart; $r <= $scanEnd; $r++) {
        $v = $ws->getCell("B{$r}")->getValue();
        if ($v !== null && trim((string)$v) !== '') $dataEnd = $r;
    }
    if ($dataEnd < $dataStart) $dataEnd = $dataStart;

    return [$startCol, $endCol, $bestRow, $dataStart, $dataEnd];
}

function normalize_meas_value($v) {
    if ($v === null) return null;
    if (is_string($v)) {
        $t = trim($v);
        if ($t === '') return null;
        if (is_numeric($t)) return (float)$t;
        return $t;
    }
    if (is_int($v) || is_float($v)) return $v;
    return (string)$v;
}

/**
 * OQC DB 메타(테이블/컬럼 자동 감지)
 *  - 기본: oqc_header / oqc_result_header / oqc_measurements
 *  - 호환: oqc_meas(구버전)
 */
function init_oqc_db_meta(PDO $pdo): array {
    $tHeader = table_exists($pdo, 'oqc_header') ? 'oqc_header' : '';
    $tResult = table_exists($pdo, 'oqc_result_header') ? 'oqc_result_header' : '';
    // measurements 테이블 이름 후보
    $tMeas   = table_exists($pdo, 'oqc_measurements') ? 'oqc_measurements'
             : (table_exists($pdo, 'oqc_meas') ? 'oqc_meas' : '');

    $meta = [
        't_header' => $tHeader,
        't_result' => $tResult,
        't_meas'   => $tMeas,
        'h' => [],
        'r' => [],
        'm' => [],
    ];

    if ($tHeader) {
        $hc = get_columns_map($pdo, $tHeader);
        $meta['h'] = [
            'id'    => pick_col($hc, ['id','header_id','oqc_header_id']),
            'part'  => pick_col($hc, ['part_name','part','partno','product_name','product']),
            // production/lot date 우선, 없으면 date
            'date'      => pick_col($hc, ['lot_date','prod_date','production_date','mfg_date','work_date','date']),
            // ship date / meas date
            'ship_date' => pick_col($hc, ['ship_date','ship_dt','ship_datetime','shipday']),
            'meas_date' => pick_col($hc, ['meas_date','measure_date','measurement_date','measured_date']),
            'meas_date2' => pick_col($hc, ['meas_date2','measure_date2','measurement_date2','measured_date2']),
            // JAWHA 전용 마킹 컬럼(납품처 분리용)
            'jmeas_date' => pick_col($hc, ['jmeas_date','j_meas_date','jmeasure_date','j_measure_date','jmeasurement_date','j_measurement_date','jmeasured_date','j_measured_date']),
            'jmeas_date2' => pick_col($hc, ['jmeas_date2','j_meas_date2','jmeasure_date2','j_measure_date2','jmeasurement_date2','j_measurement_date2','jmeasured_date2','j_measured_date2']),
            // Tool#Cavity 문자열
            'tc'    => pick_col($hc, ['tool_cavity','toolcavity','tool_cav','tool_cavity_str','tool_cavity_no']),
            // tool + cavity (fallback)
            'tool'  => pick_col($hc, ['tool','tool_name','moldtype','mold_type','mold','moldtype_name','moldtypeid','mold_type_id']),
            'cavity'=> pick_col($hc, ['cavity','cav','cavity_no','cavity_num','cav_no','cavnum']),
            // 소스파일 / kind(SPC|FAI)
            'source_file' => pick_col($hc, ['source_file','src_file','file_name','filename','source_filename']),
            'kind'        => pick_col($hc, ['kind','type','mode','data_kind']),
        ];
    }

    if ($tResult) {
        $rc = get_columns_map($pdo, $tResult);
        $meta['r'] = [
            'id'       => pick_col($rc, ['id','result_id','result_header_id','oqc_result_header_id']),
            'fk_header'=> pick_col($rc, ['header_id','oqc_header_id','parent_id']),
            'part'     => pick_col($rc, ['part_name','part','partno','product_name','product']),
            'date'     => pick_col($rc, ['lot_date','prod_date','production_date','mfg_date','work_date','date']),
            'point_no' => pick_col($rc, ['point_no','point','pt_no']),
            'spc_code' => pick_col($rc, ['spc_code','spc','code']),
            // ✅ NG 판정용(USL/LSL, 통계)
            'mean_val' => pick_col($rc, ['mean_val','mean','meanvalue','avg','avg_val']),
            'max_val'  => pick_col($rc, ['max_val','max','maxvalue']),
            'min_val'  => pick_col($rc, ['min_val','min','minvalue']),
            'usl'      => pick_col($rc, ['usl','upper','upper_limit']),
            'lsl'      => pick_col($rc, ['lsl','lower','lower_limit']),
            'result_ok'=> pick_col($rc, ['result_ok','ok','is_ok','pass']),
        ];
    }

    if ($tMeas) {
        $mc = get_columns_map($pdo, $tMeas);
        $meta['m'] = [
            'id'        => pick_col($mc, ['id','meas_id','measurement_id']),
            // FK 후보 (둘 다 잡아두고, 실제 fetch에서 mode로 선택)
            'fk_result' => pick_col($mc, ['result_id','result_header_id','oqc_result_header_id']),
            'fk_header' => pick_col($mc, ['header_id','oqc_header_id','parent_id']),
            // 중요: row_index (엑셀 row 번호가 그대로 들어있음)
            'row'       => pick_col($mc, ['row_index','excel_row','sheet_row','row_no','rownum','row']),
            'point_no'  => pick_col($mc, ['point_no','point','pt_no']),
            'spc_code'  => pick_col($mc, ['spc_code','spc','code','col_code']),
            'value'     => pick_col($mc, ['value','val','data']),
        ];
    }

    return $meta;
}

/**
 * v7.25: 템플릿 OQC 시트 B열(point_no) 목록 기준으로만 NG 판정
 *  - SQL에서 result_ok/usl/lsl 등의 NG 조건을 걸지 않는다.
 *  - 후보 header_id들의 결과를 읽어 PHP에서만 NG를 계산한다.
 *  - 템플릿에 없는 point_no에서 NG가 떠도 무시한다.
 * 반환: [ header_id => true, ... ] (NG인 header_id set)
 */

/**
 * v7.25: 템플릿 OQC 시트 B열(point_no) 목록 기준으로만 NG 판정
 *  - SQL에서 result_ok/usl/lsl 등의 NG 조건을 걸지 않는다.
 *  - 후보 header_id들의 결과를 읽어 PHP에서만 NG를 계산한다.
 *  - 템플릿에 없는 point_no에서 NG가 떠도 무시한다.
 * 반환: [ header_id => true, ... ] (NG인 header_id set)
 */
function oqc_build_bad_headers_by_template_ng(PDO $pdo, array $meta, array $candIds, array $tmplPointSet, array $ngIgnoreSet = []): array {
    $bad = [];
    if (!$candIds) return $bad;

    $tResult = (string)($meta['t_result'] ?? '');
    $r = $meta['r'] ?? [];
    if (!$tResult || !is_array($r)) return $bad;

    // 컬럼 유효성 체크 (Unknown column 방지)
    $rFk    = safe_col($pdo, $tResult, $r['fk_header'] ?? null);
    $rPoint = safe_col($pdo, $tResult, $r['point_no'] ?? null);
    if (!$rFk || !$rPoint) return $bad;

    // 가능한 컬럼들(result_ok / mean/max/min / usl/lsl)
    $rOk   = safe_col($pdo, $tResult, $r['result_ok'] ?? null);
    $rMean = safe_col($pdo, $tResult, $r['mean_val']  ?? null);
    $rMax  = safe_col($pdo, $tResult, $r['max_val']   ?? null);
    $rMin  = safe_col($pdo, $tResult, $r['min_val']   ?? null);
    $rUsl  = safe_col($pdo, $tResult, $r['usl']       ?? null);
    $rLsl  = safe_col($pdo, $tResult, $r['lsl']       ?? null);

    $select = [];
    $select[] = "rr.`{$rFk}` AS header_id";
    $select[] = "rr.`{$rPoint}` AS point_no";
    if ($rOk)   $select[] = "rr.`{$rOk}` AS result_ok";
    if ($rMean) $select[] = "rr.`{$rMean}` AS mean_val";
    if ($rMax)  $select[] = "rr.`{$rMax}` AS max_val";
    if ($rMin)  $select[] = "rr.`{$rMin}` AS min_val";
    if ($rUsl)  $select[] = "rr.`{$rUsl}` AS usl";
    if ($rLsl)  $select[] = "rr.`{$rLsl}` AS lsl";

    // candIds가 커지면 IN(?,?,?,...)이 너무 길어지고(로그도 지저분), 일부 환경에서 prepare 부담이 커진다.
    // 그래서 NG 필터는 "작은 청크"로 나눠서 조회한다.
    $candIds = array_values(array_filter(array_map('intval', $candIds), function($v){ return $v > 0; }));
    $chunkSize = (int)($GLOBALS['NG_CHECK_CHUNK_SIZE'] ?? 80);
    if ($chunkSize < 10) $chunkSize = 80;

    try {
        $total = count($candIds);
        $totalChunks = (int)ceil($total / $chunkSize);
        for ($off = 0, $chunkNo = 1; $off < $total; $off += $chunkSize, $chunkNo++) {
            $chunk = array_slice($candIds, $off, $chunkSize);
            if (!$chunk) continue;

            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT " . implode(", ", $select)
                 . " FROM `{$tResult}` rr"
                 . " WHERE rr.`{$rFk}` IN ({$ph})";
            $st = $pdo->prepare($sql);
            $st->execute(array_values($chunk));

            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $hid = (int)($row['header_id'] ?? 0);
            if ($hid <= 0) continue;

            $pno = (string)($row['point_no'] ?? '');
            if ($pno === '') continue;

            // 템플릿/모델 포인트만 대상
            $ks = function_exists('fai_key_variants') ? oqc_key_variants($pno) : [oqc_norm_key($pno)];
            $kHit = null;
            if (is_array($tmplPointSet)) {
                foreach ($ks as $k) {
                    if ($k !== '' && isset($tmplPointSet[$k])) { $kHit = $k; break; }
                }
                if ($kHit === null) continue;
            } else {
                $kHit = $ks[0] ?? '';
            }

            // NG 예외(LSL/USL 초과/미달 무시) 포인트는 NG 판정 자체를 스킵
            if ($ngIgnoreSet) {
                $skipNg = false;
                foreach ($ks as $k) {
                    if ($k !== '' && isset($ngIgnoreSet[$k])) { $skipNg = true; break; }
                }
                if ($skipNg) continue;
            }
            $isNg = false;
            $why = [];

            // 1) result_ok
            // v7.25.2: result_ok는 장비/데이터에 따라 신뢰도가 낮아 NG 판단에 사용하지 않는다.
            // (필요 시 $USE_RESULT_OK_FOR_NG=true 로 켜면 템플릿 포인트에 한해 보조 판정 가능)
            if (!$isNg && !empty($GLOBALS['USE_RESULT_OK_FOR_NG']) && array_key_exists('result_ok', $row)) {
                $okv = $row['result_ok'];
                if ($okv !== null && (int)$okv === 0) {
                    $isNg = true;
                    $why[] = 'result_ok=0';
                }
            }

            // 2) usl/lsl 비교
            $usl = array_key_exists('usl', $row) ? normalize_meas_value($row['usl']) : null;
            $lsl = array_key_exists('lsl', $row) ? normalize_meas_value($row['lsl']) : null;

            // 2.1) 템플릿(oqc_model_point) 기준 usl/lsl가 있으면 우선 적용
            //      (oqc_result_header usl/lsl 값이 템플릿과 불일치하는 경우, NG 필터가 약해지는 문제 방지)
            if ($kHit !== '' && isset($tmplPointSet[$kHit]) && is_array($tmplPointSet[$kHit])) {
                $spec = $tmplPointSet[$kHit];
                $specUsl = array_key_exists('usl', $spec) ? normalize_meas_value($spec['usl']) : (array_key_exists('USL', $spec) ? normalize_meas_value($spec['USL']) : null);
                $specLsl = array_key_exists('lsl', $spec) ? normalize_meas_value($spec['lsl']) : (array_key_exists('LSL', $spec) ? normalize_meas_value($spec['LSL']) : null);
                if ($specUsl !== null) $usl = $specUsl;
                if ($specLsl !== null) $lsl = $specLsl;
            }

            $mean = array_key_exists('mean_val', $row) ? normalize_meas_value($row['mean_val']) : null;
            $maxv = array_key_exists('max_val',  $row) ? normalize_meas_value($row['max_val'])  : null;
            $minv = array_key_exists('min_val',  $row) ? normalize_meas_value($row['min_val'])  : null;

            if (!$isNg && $usl !== null && is_numeric($usl)) {
                $v = (is_numeric($mean) ? (float)$mean : (is_numeric($maxv) ? (float)$maxv : (is_numeric($minv) ? (float)$minv : null)));
                if ($v !== null && $v > (float)$usl) {
                    $isNg = true;
                    $why[] = "USL 초과({$v} > {$usl})";
                }
            }

            if (!$isNg && $lsl !== null && is_numeric($lsl)) {
                $v = (is_numeric($mean) ? (float)$mean : (is_numeric($minv) ? (float)$minv : (is_numeric($maxv) ? (float)$maxv : null)));
                if ($v !== null && $v < (float)$lsl) {
                    $isNg = true;
                    $why[] = "LSL 미달({$v} < {$lsl})";
                }
            }

            if ($isNg) {
                $bad[$hid] = true;

                if (!empty($GLOBALS['DEBUG'])) {
                    // 같은 header_id + point_no + 사유는 1번만 로깅(중복 방지)
                    static $__ng_logged = [];
                    $kWhy = (count($why) ? implode(', ', $why) : '');
                    $kLog = $hid . '|' . $pno . '|' . $kWhy;
                    if (!isset($__ng_logged[$kLog])) {
                        $__ng_logged[$kLog] = true;
                        dlog("  [NG] header_id={$hid} point_no=" . $pno . ($kWhy !== '' ? " ({$kWhy})" : ""));
                    }
                }
            }
            }
        }
    } catch (Throwable $e) {
        if (!empty($GLOBALS['DEBUG'])) {
            dlog("  [NG필터 오류] " . $e->getMessage());
        }
        // 오류 시 NG필터를 강제하지 않고 통과(보수적으로)
        return [];
    }

    return $bad;
}


/**
 * 템플릿(point_no set) 기준 NG 필터를 "앞에서부터" 적용해,
 * 첫 번째 "양품" 헤더(row)를 찾는다.
 *
 * - rows는 이미 우선순위(=source_file 스캔 순서/Kind 우선/최신 등)대로 정렬되어 있다고 가정한다.
 * - 전체 후보를 한 번에 IN(...)으로 조회하지 않고, 작은 청크 단위로 NG를 판정한 뒤
 *   양품이 발견되는 순간 즉시 종료한다.
 */
function oqc_pick_first_good_header_by_template_ng(PDO $pdo, array $meta, array $rows, array $tmplPointSet, int $chunkSize = 20): ?array {
    $rows = is_array($rows) ? array_values($rows) : [];
    if (!$rows) return null;
    if ($chunkSize < 5) $chunkSize = 20;

    $n = count($rows);
    for ($off = 0; $off < $n; $off += $chunkSize) {
        $slice = array_slice($rows, $off, $chunkSize);

        $candIds = [];
        foreach ($slice as $rr) {
            $hid = (int)($rr['hid'] ?? 0);
            if ($hid > 0) $candIds[$hid] = true;
        }
        $candIds = array_keys($candIds);
        $ngIgnoreSet = oqc_current_ng_ignore_set();
        $bad = ($candIds ? oqc_build_bad_headers_by_template_ng($pdo, $meta, $candIds, $tmplPointSet, $ngIgnoreSet) : []);

        foreach ($slice as $rr) {
            $hid = (int)($rr['hid'] ?? 0);
            if ($hid > 0 && !isset($bad[$hid])) return $rr;
        }
    }

    return null;
}



/**
 * 선택 기간(prodDates) + 출하 cavity(shippedCavs)를 기준으로
 * "A#1" 같은 Tool#Cavity 컬럼 목록과,
 * 각 Tool#Cavity에 대응하는 '최신' 레코드ID(측정값 소스)를 만든다.
 *
 * mode:
 *  - result : oqc_result_header.id 를 measurements가 참조하는 경우
 *  - header : oqc_header.id 를 measurements가 참조하는 경우
 */


/**
 * source_file에서 6자리 날짜(YYMMDD)를 찾아 "YY-MM-DD"로 변환.
 * 없으면 fallbackDate(YYYY-MM-DD)를 "YY-MM-DD"로 변환해서 반환.
 */
function oqc_extract_src_tag(string $sourceFile, string $fallbackProdDate): string {
    $s = trim((string)$sourceFile);

    // source_file에서 날짜를 최대한 안전하게 추출(파일명 끝쪽 날짜 우선)
    // - 예: ..._251013.xlsx / ..._251014_1.xlsx / ... 251014.xlsx / ..._20251224.xlsx
    // - part no(817-11233-07) 같은 숫자 조각은 checkdate()로 필터
    if ($s !== '') {
        $base = basename(str_replace('\\', '/', $s));
        $baseNoExt = preg_replace('/\.[^.]+$/u', '', $base);

        $cands = [];

        // 1) 구분자 포함: YYYY[-_. ]MM[-_. ]DD / YY[-_. ]MM[-_. ]DD
        if (preg_match_all('/(?<!\d)(20\d{2})[-_. ]?([01]\d)[-_. ]?([0-3]\d)(?!\d)/u', $baseNoExt, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) $cands[] = [$m[1], $m[2], $m[3], 8];
        }
        if (preg_match_all('/(?<!\d)(\d{2})[-_. ]?([01]\d)[-_. ]?([0-3]\d)(?!\d)/u', $baseNoExt, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) $cands[] = [$m[1], $m[2], $m[3], 6];
        }

        // 2) 순수 숫자: YYYYMMDD / YYMMDD (경계 필수)
        if (preg_match_all('/(?<!\d)(20\d{2})([01]\d)([0-3]\d)(?!\d)/u', $baseNoExt, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) $cands[] = [$m[1], $m[2], $m[3], 8];
        }
        if (preg_match_all('/(?<!\d)(\d{2})([01]\d)([0-3]\d)(?!\d)/u', $baseNoExt, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) $cands[] = [$m[1], $m[2], $m[3], 6];
        }

        // 뒤에서부터 유효 날짜를 찾는다(가장 "끝쪽" 날짜 우선)
        for ($i = count($cands) - 1; $i >= 0; $i--) {
            [$y, $mm, $dd, $type] = $cands[$i];
            $mmI = (int)$mm; $ddI = (int)$dd;

            $yyyy = ($type === 8) ? (int)$y : (2000 + (int)$y); // MEM: 20YY 고정 가정
            if ($yyyy < 2000 || $yyyy > 2099) continue;
            if (!checkdate($mmI, $ddI, $yyyy)) continue;

            return substr((string)$yyyy, 2, 2) . sprintf('%02d%02d', $mmI, $ddI);
        }
    }

    // fallbackProdDate: YYYY-MM-DD -> YYMMDD
    $fallbackProdDate = trim((string)$fallbackProdDate);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fallbackProdDate, $m)) {
        return substr($m[1], 2, 2) . $m[2] . $m[3];
    }
    if (preg_match('/^\d{6}$/', $fallbackProdDate)) {
        return $fallbackProdDate;
    }
    return $fallbackProdDate;
}

/**
 * source_file에서 날짜를 추출해 YYYY-MM-DD로 반환한다.
 * - 우선순위: YYYYMMDD / YYYY-MM-DD / YYMMDD / YY-MM-DD (구분자 - _ . 허용)
 * - 추출 실패 시 null
 * ⚠️ 주의: oqc_header의 lot_date/ship_date(수기 입력 가능)는 절대 사용하지 않는다.
 */
function oqc_extract_ymd_from_source_file(string $sourceFile): ?string {
    $s = trim((string)$sourceFile);
    if ($s === '') return null;

    $base = basename(str_replace('\\', '/', $s));
    $baseNoExt = preg_replace('/\.[^.]+$/u', '', $base);

    $cands = [];

    // 1) 구분자 포함: YYYY[-_. ]MM[-_. ]DD / YY[-_. ]MM[-_. ]DD
    if (preg_match_all('/(?<!\d)(20\d{2})[-_. ]?([01]\d)[-_. ]?([0-3]\d)(?!\d)/u', $baseNoExt, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) $cands[] = [$m[1], $m[2], $m[3], 8];
    }
    if (preg_match_all('/(?<!\d)(\d{2})[-_. ]?([01]\d)[-_. ]?([0-3]\d)(?!\d)/u', $baseNoExt, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) $cands[] = [$m[1], $m[2], $m[3], 6];
    }

    // 2) 순수 숫자: YYYYMMDD / YYMMDD
    if (preg_match_all('/(?<!\d)(20\d{2})([01]\d)([0-3]\d)(?!\d)/u', $baseNoExt, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) $cands[] = [$m[1], $m[2], $m[3], 8];
    }
    if (preg_match_all('/(?<!\d)(\d{2})([01]\d)([0-3]\d)(?!\d)/u', $baseNoExt, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) $cands[] = [$m[1], $m[2], $m[3], 6];
    }

    for ($i = count($cands) - 1; $i >= 0; $i--) {
        [$y, $mm, $dd, $type] = $cands[$i];
        $mmI = (int)$mm; $ddI = (int)$dd;

        $yyyy = ($type === 8) ? (int)$y : (2000 + (int)$y);
        if ($yyyy < 2000 || $yyyy > 2099) continue;
        if (!checkdate($mmI, $ddI, $yyyy)) continue;

        return sprintf('%04d-%02d-%02d', $yyyy, $mmI, $ddI);
    }

    return null;
}

/**
 * prodDate(YYYY-MM-DD) 기반으로 source_file LIKE 패턴 후보를 만든다.
 * (언더바(_)는 LIKE에서 와일드카드이므로 \_ 로 escape)
 */
function oqc_build_source_file_like_patterns(string $prodDate): array {
    $prodDate = trim((string)$prodDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prodDate)) return [];
    $ymd = $prodDate;                      // 2025-12-24
    $yyyymmdd = str_replace('-', '', $prodDate); // 20251224
    $yymmdd = substr($yyyymmdd, 2);        // 251224

    // 구분자 버전(언더바는 escape)
    $yy = substr($yymmdd, 0, 2);
    $mm = substr($yymmdd, 2, 2);
    $dd = substr($yymmdd, 4, 2);

    return [
        $yymmdd,
        $yyyymmdd,
        $ymd,
        "{$yy}-{$mm}-{$dd}",
        "{$yy}.{$mm}.{$dd}",
        "{$yy}\_{$mm}\_{$dd}",   // LIKE용 escape
        "20{$yy}-{$mm}-{$dd}",
        "20{$yy}.{$mm}.{$dd}",
        "20{$yy}\_{$mm}\_{$dd}", // LIKE용 escape
    ];
}


function oqc_yymmdd_to_ymd(string $yymmdd): ?string {
    $yymmdd = preg_replace('/\D+/', '', $yymmdd);
    if (!preg_match('/^\d{6}$/', $yymmdd)) return null;
    $yy = (int)substr($yymmdd, 0, 2);
    $mm = (int)substr($yymmdd, 2, 2);
    $dd = (int)substr($yymmdd, 4, 2);
    if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) return null;
    $yyyy = 2000 + $yy;
    return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
}


/**
 * 특정 생산일(prodDate) + Tool#Cavity(tc)로 oqc_header에서 "가장 쓸만한" 1건을 고른다.
 * - 날짜는 lot_date 우선, 없으면 ship_date도 함께 매칭
 * - kind 컬럼이 있으면 SPC 우선(FAI는 최후 수단)
 * 반환: ['id'=>int, 'src_tag'=>string, 'source_file'=>string, 'kind'=>string]
 */
function oqc_pick_best_header(PDO $pdo, array $meta, string $part, string $prodDate, string $toolCavity, array $preferKinds = ['SPC'], array $excludeIds = [], ?array $tmplPointFullMap = null, ?array $disallowKinds = null): ?array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return null;

    $colId   = $h['id'] ?? 'id';
    // meta 감지 키 호환(part/date/tc 등)
    $colPart = $h['part'] ?? ($h['part_name'] ?? 'part_name');
    $colKind = $h['kind'] ?? null;
    $colSrc  = $h['source_file'] ?? null;

    // 날짜 컬럼들(환경마다 다름): meas_date/meas_date2가 있으면 우선 사용하고,
    // 없으면 date(=lot/prod/work)나 ship_date 등을 같이 OR 매칭한다.
    $colDate = $h['date'] ?? ($h['prod_date'] ?? null);
$colMeas = $h['meas_date'] ?? null;
    $colMeas2 = $h['meas_date2'] ?? null;
    $colShip = $h['ship_date'] ?? null;

    // 기존 로직 호환을 위해 prod_date alias 변수 유지
    $colProd = $colDate;

    // Tool#Cavity 문자열(=tool_cavity)
    $colTc   = $h['tc'] ?? ($h['tool_cavity'] ?? null);

    // tool + cavity (fallback)
    $colTool = $h['tool'] ?? null;
    $colCav  = $h['cavity'] ?? null;

    if (!$colPart || !$colId) return null;

    $tcNorm = normalize_tool_cavity_key($toolCavity);
    if ($tcNorm === '') return null;

    $where = [];
    $params = [];

    $where[] = "h.`{$colPart}` = :part";
    $params[':part'] = $part;

    // 날짜 매칭: ⚠️ lot_date/ship_date(수기 입력 가능) 등은 절대 사용하지 않는다.
    // 오직 source_file에서 날짜를 추출/매칭한다.
    if (!$colSrc) return null;

    $pats = oqc_build_source_file_like_patterns($prodDate);
    if (!$pats) return null;

    $likes = [];
    $j = 0;
    foreach ($pats as $pat) {
        $k = ":sl{$j}";
        // MySQL string literal for backslash escape is '\\' (two backslashes),
        // so in PHP we must write four backslashes here.
        $likes[] = "h.`{$colSrc}` LIKE {$k} ESCAPE '\\\\'";
        $params[$k] = '%' . $pat . '%';
        $j++;
    }
    if (!$likes) return null;
    $where[] = "(" . implode(" OR ", $likes) . ")";

    // tc 매칭(가능하면 tc 우선)
    if ($colTc) {
        // 환경에 따라 tool_cavity가 "A#1" 외에 "A#1-2" 같이 꼬리값이 붙는 경우가 있어 LIKE도 함께 사용
        $params[':tc'] = $tcNorm;
        $params[':tc_like'] = $tcNorm . '%';
        // ✅ tc가 K#04, K # 4, K#4-2 등으로 저장되어도 매칭되도록 정규화 비교 추가
        $tcExtra = '';
        $p = parse_tool_cavity_pair($tcNorm);
        if ($p) {
            [$t, $cv] = $p;
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
            $tcExtra = " OR (UPPER(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',1))) = :tool"
                     . " AND TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) REGEXP '^[0-9]+'"
                     . " AND CAST(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) AS UNSIGNED) = :cav)";
        }
        $where[] = "(h.`{$colTc}` = :tc OR h.`{$colTc}` LIKE :tc_like{$tcExtra})";
    } else {
        $p = parse_tool_cavity_pair($tcNorm);
        if (!$p) return null;
        [$t, $cv] = $p;
        if ($colTool && $colCav) {
            $where[] = "h.`{$colTool}` = :tool";
            $where[] = "h.`{$colCav}` = :cav";
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
        } else {
            return null;
        }
    }

    // 제외 ID
    if ($excludeIds) {
        $ph = [];
        foreach ($excludeIds as $i => $id) {
            $k = ":ex{$i}";
            $ph[] = $k;
            $params[$k] = (int)$id;
        }
        if ($ph) $where[] = "h.`{$colId}` NOT IN (" . implode(',', $ph) . ")";
    }

    // [v7.23] disallowKinds: 특정 kind(Fai 등)를 이 슬롯에서 사용 금지
    $disallowSet = [];
    if (is_array($disallowKinds)) {
        foreach ($disallowKinds as $dk) {
            $dk = strtoupper(trim((string)$dk));
            if ($dk !== '') $disallowSet[$dk] = true;
        }
    }
    if ($disallowSet && $colKind) {
        $ph = [];
        $i = 0;
        foreach (array_keys($disallowSet) as $dk) {
            $k = ":dk{$i}";
            $ph[] = $k;
            $params[$k] = $dk;
            $i++;
        }
        if ($ph) $where[] = "UPPER(TRIM(h.`{$colKind}`)) NOT IN (" . implode(',', $ph) . ")";
    }


    // kind 선호순서
    $order = [];
    if ($colKind && $preferKinds) {
        $preferKinds = array_values(array_filter(array_map(function($v){ return strtoupper(trim((string)$v)); }, $preferKinds)));
        if ($preferKinds) {
            $cases = [];
            foreach ($preferKinds as $i => $k) {
                $cases[] = "WHEN UPPER(TRIM(h.`{$colKind}`)) = " . $pdo->quote($k) . " THEN {$i}";
            }
            $order[] = "CASE " . implode(' ', $cases) . " ELSE 999 END ASC";
        }
    } elseif ($colKind) {
        // 기본: SPC 우선
        $order[] = "CASE WHEN UPPER(TRIM(h.`{$colKind}`)) = 'SPC' THEN 0 ELSE 1 END ASC";
    }

    if ($colMeas) $order[] = "h.`{$colMeas}` DESC";
    $order[] = "h.`{$colId}` DESC";

    // ─────────────────────────────
    // NG 판정(템플릿 B열(point_no)만 체크)
    // ─────────────────────────────
    // v7.25: SQL에서는 NG 조건을 걸지 않고, 후보들의 결과를 읽어 PHP에서만 NG 판정
    $tResult = (string)($meta['t_result'] ?? '');
    $r = $meta['r'] ?? [];
    $rFk    = safe_col($pdo, $tResult, $r['fk_header'] ?? null);
    $rPoint = ($tResult && $rFk) ? safe_col($pdo, $tResult, $r['point_no'] ?? null) : null;

    $tmplPointSet = is_array($tmplPointFullMap) ? $tmplPointFullMap : null;
    $useTemplateNg = ($tmplPointSet !== null && $tResult && $rFk && $rPoint);
    $select = ["h.`{$colId}` AS hid"];
    if ($colSrc)  $select[] = "h.`{$colSrc}` AS source_file";
    if ($colKind) $select[] = "h.`{$colKind}` AS kind";

    $limit = $useTemplateNg ? 60 : 120;

    $sql = "SELECT " . implode(", ", $select)
         . " FROM `{$tHeader}` h"
         . " WHERE " . implode(" AND ", $where)
         . " ORDER BY " . implode(", ", $order)
         . " LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ✅ source_file 기준으로 후보를 "출하내역(prod_date) 스캔 순서"로 정렬해 선택한다.
// - 수기 입력 필드(lot_date/ship_date)는 절대 사용하지 않음
// - 우선순위: (1) prodDate 이하 중 가장 가까운 날짜(=가장 최신 과거)
//            (2) prodDate 초과 중 가장 가까운 날짜(=가장 이른 미래)
//            (3) 날짜 추출 실패(source_file에 날짜 없음/형식 불일치) 후보는 최후순위
    if (!$rows) return null;
    $prodTs = strtotime($prodDate);
    $cands = [];
    foreach ($rows as $i => $r) {
        $sf = trim((string)($r['source_file'] ?? ''));
        if ($sf === '') continue;

        $ymd = oqc_extract_ymd_from_source_file($sf);
        $sfTs = ($ymd !== null && $ymd !== '') ? strtotime($ymd) : false;

        if ($sfTs === false) {
            $grp = 2; // unknown date
            $sfTs = 0;
        } else {
            $grp = ($sfTs <= $prodTs) ? 0 : 1;
        }
        $cands[] = [$grp, (int)$sfTs, (int)$i, $r];
    }

    if (!$cands) return null;

    usort($cands, function($a, $b) {
        if ($a[0] !== $b[0]) return $a[0] <=> $b[0];
        if ($a[0] === 0) { // past: newest first
            if ($a[1] !== $b[1]) return $b[1] <=> $a[1];
        } elseif ($a[0] === 1) { // future: oldest first
            if ($a[1] !== $b[1]) return $a[1] <=> $b[1];
        }
        return $a[2] <=> $b[2]; // stable
    });

    // Template NG 모드에서는 이 순서대로 첫 "양품" 헤더를 고른다.
    $rows = array_map(function($x){ return $x[3]; }, $cands);
    $row = $rows[0];

    if ($useTemplateNg) {
        // ✅ 전체 후보를 한 번에 IN(...)으로 NG 판정하지 않고, 앞에서부터 작은 청크로 검사 후
        // 첫 양품이 발견되면 즉시 종료한다(성능/로그/prepare 부담 완화).
        $picked = oqc_pick_first_good_header_by_template_ng($pdo, $meta, $rows, $tmplPointSet, 20);
        if (!$picked) return null;
        $row = $picked;
    }

    $hid = (int)($row['hid'] ?? 0);
    if ($hid <= 0) return null;

    $srcFile = $row['source_file'] ?? null;
    return [
        'id' => $hid,
        'src_tag' => oqc_extract_src_tag((string)$srcFile, $prodDate),
        'source_file' => $srcFile !== null ? (string)$srcFile : null,
        'kind' => isset($row['kind']) ? (string)$row['kind'] : null,
    ];
}


function oqc_pick_best_header_kind(PDO $pdo, array $meta, string $part, string $prodDate, string $toolCavity, string $kindWanted, array $excludeIds = [], ?array $tmplPointFullMap = null, ?array $disallowKinds = null): ?array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return null;

    $colId   = $h['id'] ?? 'id';
    // meta 감지 키 호환(part/date/tc 등)
    $colPart = $h['part'] ?? ($h['part_name'] ?? 'part_name');
    $colKind = $h['kind'] ?? null;
    $colSrc  = $h['source_file'] ?? null;

    // 날짜 컬럼들(환경마다 다름): meas_date/meas_date2가 있으면 우선 사용하고,
    // 없으면 date(=lot/prod/work)나 ship_date 등을 같이 OR 매칭한다.
    $colDate = $h['date'] ?? ($h['prod_date'] ?? null);
$colMeas = $h['meas_date'] ?? null;
    $colMeas2 = $h['meas_date2'] ?? null;
    $colShip = $h['ship_date'] ?? null;

    // 기존 로직 호환을 위해 prod_date alias 변수 유지
    $colProd = $colDate;

    // Tool#Cavity 문자열(=tool_cavity)
    $colTc   = $h['tc'] ?? ($h['tool_cavity'] ?? null);

    // tool + cavity (fallback)
    $colTool = $h['tool'] ?? null;
    $colCav  = $h['cavity'] ?? null;

    if (!$colPart || !$colProd || !$colId) return null;

    $kindWanted = strtoupper(trim($kindWanted));
    if ($kindWanted === '') return null;

    $tcNorm = normalize_tool_cavity_key($toolCavity);
    if ($tcNorm === '') return null;

    $where = [];
    $params = [];

    $where[] = "h.`{$colPart}` = :part";
    $params[':part'] = $part;

    // 날짜 매칭: ⚠️ lot_date/ship_date(수기 입력 가능) 등은 절대 사용하지 않는다.
    // 오직 source_file에서 날짜를 추출/매칭한다.
    if (!$colSrc) return null;

    $pats = oqc_build_source_file_like_patterns($prodDate);
    if (!$pats) return null;

    $likes = [];
    $j = 0;
    foreach ($pats as $pat) {
        $k = ":sl{$j}";
        // MySQL string literal for backslash escape is '\\' (two backslashes),
        // so in PHP we must write four backslashes here.
        $likes[] = "h.`{$colSrc}` LIKE {$k} ESCAPE '\\\\'";
        $params[$k] = '%' . $pat . '%';
        $j++;
    }
    if (!$likes) return null;
    $where[] = "(" . implode(" OR ", $likes) . ")";

    $where[] = "UPPER(TRIM(h.`{$colKind}`)) = :kind";
    $params[':kind'] = $kindWanted;

    // tc 매칭(가능하면 tc 우선)
    if ($colTc) {
        // 환경에 따라 tool_cavity가 "A#1" 외에 "A#1-2" 같이 꼬리값이 붙는 경우가 있어 LIKE도 함께 사용
        $params[':tc'] = $tcNorm;
        $params[':tc_like'] = $tcNorm . '%';
        // ✅ tc가 K#04, K # 4, K#4-2 등으로 저장되어도 매칭되도록 정규화 비교 추가
        $tcExtra = '';
        $p = parse_tool_cavity_pair($tcNorm);
        if ($p) {
            [$t, $cv] = $p;
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
            $tcExtra = " OR (UPPER(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',1))) = :tool"
                     . " AND TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) REGEXP '^[0-9]+'"
                     . " AND CAST(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) AS UNSIGNED) = :cav)";
        }
        $where[] = "(h.`{$colTc}` = :tc OR h.`{$colTc}` LIKE :tc_like{$tcExtra})";
    } else {
        $p = parse_tool_cavity_pair($tcNorm);
        if (!$p) return null;
        [$t, $cv] = $p;
        if ($colTool && $colCav) {
            $where[] = "h.`{$colTool}` = :tool";
            $where[] = "h.`{$colCav}` = :cav";
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
        } else {
            return null;
        }
    }

    // 제외 ID
    if ($excludeIds) {
        $ph = [];
        foreach ($excludeIds as $i => $id) {
            $k = ":ex{$i}";
            $ph[] = $k;
            $params[$k] = (int)$id;
        }
        if ($ph) $where[] = "h.`{$colId}` NOT IN (" . implode(',', $ph) . ")";
    }

    $order = [];
    if ($colMeas) $order[] = "h.`{$colMeas}` DESC";
    $order[] = "h.`{$colId}` DESC";

    // ─────────────────────────────
    // NG 판정(템플릿 B열(point_no)만 체크)
    // ─────────────────────────────
    // v7.25: SQL에서는 NG 조건을 걸지 않고, 후보들의 결과를 읽어 PHP에서만 NG 판정
    $tResult = (string)($meta['t_result'] ?? '');
    $r = $meta['r'] ?? [];
    $rFk    = safe_col($pdo, $tResult, $r['fk_header'] ?? null);
    $rPoint = ($tResult && $rFk) ? safe_col($pdo, $tResult, $r['point_no'] ?? null) : null;

    $tmplPointSet = is_array($tmplPointFullMap) ? $tmplPointFullMap : null;
    $useTemplateNg = ($tmplPointSet !== null && $tResult && $rFk && $rPoint);
    // useTemplateNg이 false이면 NG 제외를 하지 않는다(템플릿 기준 NG만 배제).
    $select = ["h.`{$colId}` AS hid"];
    if ($colSrc)  $select[] = "h.`{$colSrc}` AS source_file";
    if ($colKind) $select[] = "h.`{$colKind}` AS kind";

    $limit = $useTemplateNg ? 60 : 120;

    $sql = "SELECT " . implode(", ", $select)
         . " FROM `{$tHeader}` h"
         . " WHERE " . implode(" AND ", $where)
         . " ORDER BY " . implode(", ", $order)
         . " LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ✅ source_file 기준으로 후보를 "출하내역(prod_date) 스캔 순서"로 정렬해 선택한다.
// - 수기 입력 필드(lot_date/ship_date)는 절대 사용하지 않음
// - 우선순위: (1) prodDate 이하 중 가장 가까운 날짜(=가장 최신 과거)
//            (2) prodDate 초과 중 가장 가까운 날짜(=가장 이른 미래)
//            (3) 날짜 추출 실패(source_file에 날짜 없음/형식 불일치) 후보는 최후순위
    if (!$rows) return null;
    $prodTs = strtotime($prodDate);
    $cands = [];
    foreach ($rows as $i => $r) {
        $sf = trim((string)($r['source_file'] ?? ''));
        if ($sf === '') continue;

        $ymd = oqc_extract_ymd_from_source_file($sf);
        $sfTs = ($ymd !== null && $ymd !== '') ? strtotime($ymd) : false;

        if ($sfTs === false) {
            $grp = 2; // unknown date
            $sfTs = 0;
        } else {
            $grp = ($sfTs <= $prodTs) ? 0 : 1;
        }
        $cands[] = [$grp, (int)$sfTs, (int)$i, $r];
    }

    if (!$cands) return null;

    usort($cands, function($a, $b) {
        if ($a[0] !== $b[0]) return $a[0] <=> $b[0];
        if ($a[0] === 0) { // past: newest first
            if ($a[1] !== $b[1]) return $b[1] <=> $a[1];
        } elseif ($a[0] === 1) { // future: oldest first
            if ($a[1] !== $b[1]) return $a[1] <=> $b[1];
        }
        return $a[2] <=> $b[2]; // stable
    });

    // Template NG 모드에서는 이 순서대로 첫 "양품" 헤더를 고른다.
    $rows = array_map(function($x){ return $x[3]; }, $cands);
    $row = $rows[0];

    if ($useTemplateNg) {
        $picked = oqc_pick_first_good_header_by_template_ng($pdo, $meta, $rows, $tmplPointSet, 20);
        if (!$picked) return null;
        $row = $picked;
    }

    $hid = (int)($row['hid'] ?? 0);
    if ($hid <= 0) return null;

    $srcFile = $row['source_file'] ?? null;
    return [
        'id' => $hid,
        'src_tag' => oqc_extract_src_tag((string)$srcFile, $prodDate),
        'source_file' => $srcFile !== null ? (string)$srcFile : null,
        'kind' => isset($row['kind']) ? (string)$row['kind'] : null,
    ];
}

function oqc_build_by_date_from_header(PDO $pdo, array $meta, string $part, array $prodDates): array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return [];

    $colPart = $h['part'] ?? ($h['part_name'] ?? 'part_name');
    $colSrc  = $h['source_file'] ?? null;
    $colTc   = $h['tc'] ?? ($h['tool_cavity'] ?? null);

    // ⚠️ lot_date/ship_date 등 수기 입력 가능 필드는 절대 사용하지 않는다.
    if (!$colPart || !$colTc || !$colSrc) return [];

    // 날짜 정규화(YYYY-MM-DD)
    $dateSet = [];
    $dates = [];
    foreach ($prodDates as $d) {
        $d = substr(trim((string)$d), 0, 10);
        if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $dateSet[$d] = true;
            $dates[] = $d;
        }
    }
    if (!$dates) return [];

    // WHERE: part + (source_file LIKE date patterns ...)
    $where = [];
    $params = [];
    $where[] = "h.`{$colPart}` = :part";
    $params[':part'] = $part;

    $or = [];
    $j = 0;
    foreach ($dates as $d) {
        $pats = oqc_build_source_file_like_patterns($d);
        foreach ($pats as $pat) {
            $k = ":sl{$j}";
            $or[] = "h.`{$colSrc}` LIKE {$k} ESCAPE '\\\\'";
            $params[$k] = '%' . $pat . '%';
            $j++;
        }
    }
    if (!$or) return [];
    $where[] = "(" . implode(" OR ", $or) . ")";

    $sql = "SELECT h.`{$colTc}` AS tc, h.`{$colSrc}` AS src"
         . " FROM `{$tHeader}` h"
         . " WHERE " . implode(" AND ", $where);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $sf = trim((string)($r['src'] ?? ''));
        if ($sf === '') continue;

        $ymd = oqc_extract_ymd_from_source_file($sf);
        if (!$ymd || !isset($dateSet[$ymd])) continue;

        $tc = normalize_tool_cavity_key((string)($r['tc'] ?? ''));
        if ($tc === '') continue;

        if (!isset($out[$ymd])) $out[$ymd] = ['pairs' => [], 'source_files' => []];
        $out[$ymd]['pairs'][$tc] = true;
        $out[$ymd]['source_files'][$sf] = true;
    }

    return $out;
}

function oqc_pick_best_header_kind_by_source_files(PDO $pdo, array $meta, string $part, array $sourceFiles, string $prodDate, string $toolCavity, string $kindWanted, array $excludeIds = [], ?array $tmplPointFullMap = null, ?array $disallowKinds = null): ?array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return null;

    $colId   = $h['id'] ?? 'id';
    $colPart = $h['part'] ?? ($h['part_name'] ?? 'part_name');
    $colKind = $h['kind'] ?? null;
    $colSrc  = $h['source_file'] ?? null;

    $colMeas = $h['meas_date'] ?? null;

    $colTc   = $h['tc'] ?? ($h['tool_cavity'] ?? null);

    $colTool = $h['tool'] ?? null;
    $colCav  = $h['cavity'] ?? null;

    if (!$colPart || !$colId || !$colSrc || !$colKind) return null;

    $kindWanted = strtoupper(trim($kindWanted));
    if ($kindWanted === '') return null;

    $tcNorm = normalize_tool_cavity_key($toolCavity);
    if ($tcNorm === '') return null;

    $where = [];
    $params = [];

    $where[] = "h.`{$colPart}` = :part";
    $params[':part'] = $part;

    // source_file 제한
    $sourceFiles = array_values(array_filter(array_map('trim', $sourceFiles), function($v){ return $v !== ''; }));
    if (!$sourceFiles) return null;

    $sfPh = [];
    foreach ($sourceFiles as $i => $sf) {
        $k = ":sf{$i}";
        $sfPh[] = $k;
        $params[$k] = $sf;
    }
    $where[] = "TRIM(h.`{$colSrc}`) IN (" . implode(',', $sfPh) . ")";

    $where[] = "UPPER(TRIM(h.`{$colKind}`)) = :kind";
    $params[':kind'] = $kindWanted;

    if ($colTc) {
        $params[':tc'] = $tcNorm;
        $params[':tc_like'] = $tcNorm . '%';
        // ✅ tc가 K#04, K # 4, K#4-2 등으로 저장되어도 매칭되도록 정규화 비교 추가
        $tcExtra = '';
        $p = parse_tool_cavity_pair($tcNorm);
        if ($p) {
            [$t, $cv] = $p;
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
            $tcExtra = " OR (UPPER(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',1))) = :tool"
                     . " AND TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) REGEXP '^[0-9]+'"
                     . " AND CAST(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) AS UNSIGNED) = :cav)";
        }
        $where[] = "(h.`{$colTc}` = :tc OR h.`{$colTc}` LIKE :tc_like{$tcExtra})";
    } else {
        $p = parse_tool_cavity_pair($tcNorm);
        if (!$p) return null;
        [$t, $cv] = $p;
        if ($colTool && $colCav) {
            $where[] = "h.`{$colTool}` = :tool";
            $where[] = "h.`{$colCav}` = :cav";
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
        } else {
            return null;
        }
    }

    if ($excludeIds) {
        $ph = [];
        foreach ($excludeIds as $i => $id) {
            $k = ":ex{$i}";
            $ph[] = $k;
            $params[$k] = (int)$id;
        }
        if ($ph) $where[] = "h.`{$colId}` NOT IN (" . implode(',', $ph) . ")";
    }

    // disallowKinds
    $disallowSet = [];
    if (is_array($disallowKinds)) {
        foreach ($disallowKinds as $dk) {
            $dk = strtoupper(trim((string)$dk));
            if ($dk !== '') $disallowSet[$dk] = true;
        }
    }
    if ($disallowSet && $colKind) {
        $ph = [];
        $i = 0;
        foreach (array_keys($disallowSet) as $dk) {
            $k = ":dk{$i}";
            $ph[] = $k;
            $params[$k] = $dk;
            $i++;
        }
        if ($ph) $where[] = "UPPER(TRIM(h.`{$colKind}`)) NOT IN (" . implode(',', $ph) . ")";
    }

    $order = [];
    // ✅ 입력된 source_file 순서대로 스캔하기 위해 FIELD 우선 적용
    $order[] = "FIELD(h.`{$colSrc}`," . implode(',', $sfPh) . ") ASC";
    if ($colMeas) $order[] = "h.`{$colMeas}` DESC";
    $order[] = "h.`{$colId}` DESC";

    // NG 판정(템플릿 B열(point_no)만 체크)
    $tResult = (string)($meta['t_result'] ?? '');
    $r = $meta['r'] ?? [];
    $rFk    = safe_col($pdo, $tResult, $r['fk_header'] ?? null);
    $rPoint = ($tResult && $rFk) ? safe_col($pdo, $tResult, $r['point_no'] ?? null) : null;

    $tmplPointSet = is_array($tmplPointFullMap) ? $tmplPointFullMap : null;
    $useTemplateNg = ($tmplPointSet !== null && $tResult && $rFk && $rPoint);

    $select = ["h.`{$colId}` AS hid"];
    $select[] = "h.`{$colSrc}` AS source_file";
    $select[] = "h.`{$colKind}` AS kind";

    $limit = $useTemplateNg ? 30 : 1;

    $sql = "SELECT " . implode(", ", $select)
         . " FROM `{$tHeader}` h"
         . " WHERE " . implode(" AND ", $where)
         . " ORDER BY " . implode(", ", $order)
         . " LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return null;

    $row = $rows[0];

    if ($useTemplateNg) {
        $picked = oqc_pick_first_good_header_by_template_ng($pdo, $meta, $rows, $tmplPointSet, 20);
        if (!$picked) return null;
        $row = $picked;
    }

    $hid = (int)($row['hid'] ?? 0);
    if ($hid <= 0) return null;

    $srcFile = $row['source_file'] ?? null;
    return [
        'id' => $hid,
        'src_tag' => oqc_extract_src_tag((string)$srcFile, $prodDate),
        'source_file' => $srcFile !== null ? (string)$srcFile : null,
        'kind' => isset($row['kind']) ? (string)$row['kind'] : null,
    ];
}



// ✅ PASS2 전용: "미사용" 헤더만 고르기 (meas_date IS NULL)
// - kind=SPC 고정 사용을 전제로 함
// - PASS1/기존 로직에 영향 주지 않도록 별도 함수로 분리
function oqc_pick_best_header_kind_by_source_files_unused(PDO $pdo, array $meta, string $part, array $sourceFiles, string $prodDate, string $toolCavity, string $kindWanted, array $excludeIds = [], ?array $tmplPointFullMap = null, ?array $disallowKinds = null): ?array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return null;

    $colId   = $h['id'] ?? 'id';
    $colPart = $h['part'] ?? ($h['part_name'] ?? 'part_name');
    $colKind = $h['kind'] ?? null;
    $colSrc  = $h['source_file'] ?? null;

    // ✅ 미사용 필터 (PASS2는 meas_date NULL만 사용)
    $colMeas = $h['meas_date'] ?? null;

    $colTc   = $h['tc'] ?? ($h['tool_cavity'] ?? null);
    $colTool = $h['tool'] ?? null;
    $colCav  = $h['cavity'] ?? null;

    if (!$colPart || !$colId || !$colSrc || !$colKind) return null;

    $kindWanted = strtoupper(trim($kindWanted));
    if ($kindWanted === '') return null;

    $tcNorm = normalize_tool_cavity_key($toolCavity);
    if ($tcNorm === '') return null;

    $where = [];
    $params = [];

    $where[] = "h.`{$colPart}` = :part";
    $params[':part'] = $part;

    // source_file 제한
    $sourceFiles = array_values(array_filter(array_map('trim', $sourceFiles), function($v){ return $v !== ''; }));
    if (!$sourceFiles) return null;

    $sfPh = [];
    foreach ($sourceFiles as $i => $sf) {
        $k = ":sf{$i}";
        $sfPh[] = $k;
        $params[$k] = $sf;
    }
    $where[] = "TRIM(h.`{$colSrc}`) IN (" . implode(',', $sfPh) . ")";

    $where[] = "UPPER(TRIM(h.`{$colKind}`)) = :kind";
    $params[':kind'] = $kindWanted;

    // ✅ PASS2 핵심: meas_date IS NULL(미사용)만
    if ($colMeas) {
        $where[] = "h.`{$colMeas}` IS NULL";
    }

    if ($colTc) {
        $params[':tc'] = $tcNorm;
        $params[':tc_like'] = $tcNorm . '%';
        $tcExtra = '';
        $p = parse_tool_cavity_pair($tcNorm);
        if ($p) {
            [$t, $cv] = $p;
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
            $tcExtra = " OR (UPPER(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',1))) = :tool"
                     . " AND TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) REGEXP '^[0-9]+'"
                     . " AND CAST(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) AS UNSIGNED) = :cav)";
        }
        $where[] = "(h.`{$colTc}` = :tc OR h.`{$colTc}` LIKE :tc_like{$tcExtra})";
    } else {
        $p = parse_tool_cavity_pair($tcNorm);
        if (!$p) return null;
        [$t, $cv] = $p;
        if ($colTool && $colCav) {
            $where[] = "h.`{$colTool}` = :tool";
            $where[] = "h.`{$colCav}` = :cav";
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
        } else {
            return null;
        }
    }

    if ($excludeIds) {
        $ph = [];
        foreach ($excludeIds as $i => $id) {
            $k = ":ex{$i}";
            $ph[] = $k;
            $params[$k] = (int)$id;
        }
        if ($ph) $where[] = "h.`{$colId}` NOT IN (" . implode(',', $ph) . ")";
    }

    // disallowKinds
    $disallowSet = [];
    if (is_array($disallowKinds)) {
        foreach ($disallowKinds as $dk) {
            $dk = strtoupper(trim((string)$dk));
            if ($dk !== '') $disallowSet[$dk] = true;
        }
    }
    if ($disallowSet && $colKind) {
        $ph = [];
        $i = 0;
        foreach (array_keys($disallowSet) as $dk) {
            $k = ":dk{$i}";
            $ph[] = $k;
            $params[$k] = $dk;
            $i++;
        }
        if ($ph) $where[] = "UPPER(TRIM(h.`{$colKind}`)) NOT IN (" . implode(',', $ph) . ")";
    }

    $order = [];
    $order[] = "FIELD(h.`{$colSrc}`," . implode(',', $sfPh) . ") ASC";
    // meas_date는 모두 NULL인 집합이므로 id DESC로 최신 우선
    $order[] = "h.`{$colId}` DESC";

    // NG 판정(템플릿 B열(point_no)만 체크)
    $tResult = (string)($meta['t_result'] ?? '');
    $r = $meta['r'] ?? [];
    $rFk    = safe_col($pdo, $tResult, $r['fk_header'] ?? null);
    $rPoint = ($tResult && $rFk) ? safe_col($pdo, $tResult, $r['point_no'] ?? null) : null;

    $tmplPointSet = is_array($tmplPointFullMap) ? $tmplPointFullMap : null;
    $useTemplateNg = ($tmplPointSet !== null && $tResult && $rFk && $rPoint);

    $select = ["h.`{$colId}` AS hid", "h.`{$colSrc}` AS source_file", "h.`{$colKind}` AS kind"];

    $limit = $useTemplateNg ? 30 : 1;

    $sql = "SELECT " . implode(", ", $select)
         . " FROM `{$tHeader}` h"
         . " WHERE " . implode(" AND ", $where)
         . " ORDER BY " . implode(", ", $order)
         . " LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return null;

    $row = $rows[0];

    if ($useTemplateNg) {
        $picked = oqc_pick_first_good_header_by_template_ng($pdo, $meta, $rows, $tmplPointSet, 20);
        if (!$picked) return null;
        $row = $picked;
    }

    $hid = (int)($row['hid'] ?? 0);
    if ($hid <= 0) return null;

    $srcFile = $row['source_file'] ?? null;
    return [
        'id' => $hid,
        'src_tag' => oqc_extract_src_tag((string)$srcFile, $prodDate),
        'source_file' => $srcFile !== null ? (string)$srcFile : null,
        'kind' => isset($row['kind']) ? (string)$row['kind'] : null,
    ];
}
function oqc_pick_best_header_by_source_files(PDO $pdo, array $meta, string $part, array $sourceFiles, string $prodDate, string $toolCavity, array $preferKinds = ['SPC'], array $excludeIds = [], ?array $tmplPointFullMap = null, ?array $disallowKinds = null): ?array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return null;

    $colId   = $h['id'] ?? 'id';
    $colPart = $h['part'] ?? ($h['part_name'] ?? 'part_name');
    $colKind = $h['kind'] ?? null;
    $colSrc  = $h['source_file'] ?? null;

    $colMeas = $h['meas_date'] ?? null;

    // Tool#Cavity 문자열(=tool_cavity)
    $colTc   = $h['tc'] ?? ($h['tool_cavity'] ?? null);

    // tool + cavity (fallback)
    $colTool = $h['tool'] ?? null;
    $colCav  = $h['cavity'] ?? null;

    if (!$colPart || !$colId || !$colSrc) return null;

    $tcNorm = normalize_tool_cavity_key($toolCavity);
    if ($tcNorm === '') return null;

    $where = [];
    $params = [];

    $where[] = "h.`{$colPart}` = :part";
    $params[':part'] = $part;

    // source_file 제한
    $sourceFiles = array_values(array_filter(array_map('trim', $sourceFiles), function($v){ return $v !== ''; }));
    if (!$sourceFiles) return null;

    $sfPh = [];
    foreach ($sourceFiles as $i => $sf) {
        $k = ":sf{$i}";
        $sfPh[] = $k;
        $params[$k] = $sf;
    }
    $where[] = "TRIM(h.`{$colSrc}`) IN (" . implode(',', $sfPh) . ")";

    // tc 매칭(가능하면 tc 우선)
    if ($colTc) {
        $params[':tc'] = $tcNorm;
        $params[':tc_like'] = $tcNorm . '%';
        // ✅ tc가 K#04, K # 4, K#4-2 등으로 저장되어도 매칭되도록 정규화 비교 추가
        $tcExtra = '';
        $p = parse_tool_cavity_pair($tcNorm);
        if ($p) {
            [$t, $cv] = $p;
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
            $tcExtra = " OR (UPPER(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',1))) = :tool"
                     . " AND TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) REGEXP '^[0-9]+'"
                     . " AND CAST(TRIM(SUBSTRING_INDEX(h.`{$colTc}`,'#',-1)) AS UNSIGNED) = :cav)";
        }
        $where[] = "(h.`{$colTc}` = :tc OR h.`{$colTc}` LIKE :tc_like{$tcExtra})";
    } else {
        $p = parse_tool_cavity_pair($tcNorm);
        if (!$p) return null;
        [$t, $cv] = $p;
        if ($colTool && $colCav) {
            $where[] = "h.`{$colTool}` = :tool";
            $where[] = "h.`{$colCav}` = :cav";
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
        } else {
            return null;
        }
    }

    // 제외 ID
    if ($excludeIds) {
        $ph = [];
        foreach ($excludeIds as $i => $id) {
            $k = ":ex{$i}";
            $ph[] = $k;
            $params[$k] = (int)$id;
        }
        if ($ph) $where[] = "h.`{$colId}` NOT IN (" . implode(',', $ph) . ")";
    }

    // disallowKinds
    $disallowSet = [];
    if (is_array($disallowKinds)) {
        foreach ($disallowKinds as $dk) {
            $dk = strtoupper(trim((string)$dk));
            if ($dk !== '') $disallowSet[$dk] = true;
        }
    }
    if ($disallowSet && $colKind) {
        $ph = [];
        $i = 0;
        foreach (array_keys($disallowSet) as $dk) {
            $k = ":dk{$i}";
            $ph[] = $k;
            $params[$k] = $dk;
            $i++;
        }
        if ($ph) $where[] = "UPPER(TRIM(h.`{$colKind}`)) NOT IN (" . implode(',', $ph) . ")";
    }

    // kind 선호순서
    $order = [];
    // ✅ 입력된 source_file 순서대로 스캔하기 위해 FIELD 우선 적용
    $order[] = "FIELD(h.`{$colSrc}`," . implode(',', $sfPh) . ") ASC";
    if ($colKind && $preferKinds) {
        $preferKinds = array_values(array_filter(array_map(function($v){ return strtoupper(trim((string)$v)); }, $preferKinds)));
        if ($preferKinds) {
            $cases = [];
            foreach ($preferKinds as $i => $k) {
                $cases[] = "WHEN UPPER(TRIM(h.`{$colKind}`)) = " . $pdo->quote($k) . " THEN {$i}";
            }
            $order[] = "CASE " . implode(' ', $cases) . " ELSE 999 END ASC";
        }
    } elseif ($colKind) {
        $order[] = "CASE WHEN UPPER(TRIM(h.`{$colKind}`)) = 'SPC' THEN 0 ELSE 1 END ASC";
    }

    if ($colMeas) $order[] = "h.`{$colMeas}` DESC";
    $order[] = "h.`{$colId}` DESC";

    // NG 판정(템플릿 B열(point_no)만 체크)
    $tResult = (string)($meta['t_result'] ?? '');
    $r = $meta['r'] ?? [];
    $rFk    = safe_col($pdo, $tResult, $r['fk_header'] ?? null);
    $rPoint = ($tResult && $rFk) ? safe_col($pdo, $tResult, $r['point_no'] ?? null) : null;

    $tmplPointSet = is_array($tmplPointFullMap) ? $tmplPointFullMap : null;
    $useTemplateNg = ($tmplPointSet !== null && $tResult && $rFk && $rPoint);

    $select = ["h.`{$colId}` AS hid"];
    $select[] = "h.`{$colSrc}` AS source_file";
    if ($colKind) $select[] = "h.`{$colKind}` AS kind";

    $limit = $useTemplateNg ? 30 : 1;

    $sql = "SELECT " . implode(", ", $select)
         . " FROM `{$tHeader}` h"
         . " WHERE " . implode(" AND ", $where)
         . " ORDER BY " . implode(", ", $order)
         . " LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return null;

    $row = $rows[0];

    if ($useTemplateNg) {
        $picked = oqc_pick_first_good_header_by_template_ng($pdo, $meta, $rows, $tmplPointSet, 20);
        if (!$picked) return null;
        $row = $picked;
    }

    $hid = (int)($row['hid'] ?? 0);
    if ($hid <= 0) return null;

    $srcFile = $row['source_file'] ?? null;
    return [
        'id' => $hid,
        'src_tag' => oqc_extract_src_tag((string)$srcFile, $prodDate),
        'source_file' => $srcFile !== null ? (string)$srcFile : null,
        'kind' => isset($row['kind']) ? (string)$row['kind'] : null,
    ];
}



/**
 * v7.13: 이번 Export에서 선택된 oqc_header 들을 "신규 사용"으로 마킹한다.
 * - meas_date IS NULL 인 것만 meas_date = CURDATE()로 세팅
 * - 이미 meas_date가 있는 건(과거 사용/긴급재사용 대상) 건드리지 않음
 *
 * @return int 업데이트된 row 수(합계)
 */
/**
 * (PASS3 보강용) 특정 파트에서 FAI(또는 임의) 헤더를 여러 개 뽑는다.
 * - kindWanted가 null이면 kind 조건 없이 뽑음
 * - meas_date IS NULL(미사용) 우선, 그 다음 최신(id desc)
 * - excludeHeaderIds는 이미 선택된 header id 중복 방지
 * 반환: [ ['_hid'=>int, '_tc'=>string, '_tag'=>string, '_kind'=>string, '_src'=>string], ... ]
 */
function oqc_pick_any_headers_for_part(PDO $pdo, array $meta, string $part, ?string $kindWanted, int $limit = 30, array $excludeIds = [], bool $preferUnconsumed = true, ?string $prodDate = null, ?array $tmplPointFullMap = null, ?array $disallowKinds = null): array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return [];

    $colId    = $h['id']         ?? 'id';
    $colPart  = $h['part_name']  ?? 'part_name';
    $colKind  = $h['kind']       ?? null;
    $colSrc   = $h['source_file']?? null;
    $colProd  = $h['prod_date']  ?? ($h['lot_date'] ?? null);
    $colMeas  = $h['meas_date']  ?? null;
    $colMeas2 = $h['meas_date2'] ?? null;
    $colTc    = $h['tc'] ?? null;
    $colTool  = $h['tool'] ?? null;
    $colCav   = $h['cavity'] ?? null;

    if (!$colPart || !$colId) return [];

    $where = [];
    $params = [];

    $where[] = "h.`{$colPart}` = :part";
    $params[':part'] = $part;

    $kindWanted = $kindWanted !== null ? strtoupper(trim($kindWanted)) : null;
    if ($kindWanted) {
        if (!$colKind) return [];
        $where[] = "UPPER(TRIM(h.`{$colKind}`)) = :kw";
        $params[':kw'] = $kindWanted;
    }

    if ($preferUnconsumed) {
        if ($colMeas)  $where[] = "h.`{$colMeas}` IS NULL";
        if ($colMeas2) $where[] = "h.`{$colMeas2}` IS NULL";
    }

    if ($prodDate && $colProd) {
        $where[] = "h.`{$colProd}` = :pd";
        $params[':pd'] = $prodDate;
    }

    // exclude
    if ($excludeIds) {
        $ph = [];
        foreach ($excludeIds as $i => $id) {
            $k = ":ex{$i}";
            $ph[] = $k;
            $params[$k] = (int)$id;
        }
        if ($ph) $where[] = "h.`{$colId}` NOT IN (" . implode(',', $ph) . ")";
    }

    // [v7.23] disallowKinds: 특정 kind(Fai 등)를 이 슬롯에서 사용 금지
    $disallowSet = [];
    if (is_array($disallowKinds)) {
        foreach ($disallowKinds as $dk) {
            $dk = strtoupper(trim((string)$dk));
            if ($dk !== '') $disallowSet[$dk] = true;
        }
    }
    if ($disallowSet && $colKind) {
        $ph = [];
        $i = 0;
        foreach (array_keys($disallowSet) as $dk) {
            $k = ":dk{$i}";
            $ph[] = $k;
            $params[$k] = $dk;
            $i++;
        }
        if ($ph) $where[] = "UPPER(TRIM(h.`{$colKind}`)) NOT IN (" . implode(',', $ph) . ")";
    }


    // ─────────────────────────────
    // NG 판정(템플릿 B열(point_no)만 체크)
    // ─────────────────────────────
    // v7.25: SQL에서는 NG 조건을 걸지 않고, 후보들의 결과를 읽어 PHP에서만 NG 판정
    $tResult = (string)($meta['t_result'] ?? '');
    $r = $meta['r'] ?? [];
    $rFk    = safe_col($pdo, $tResult, $r['fk_header'] ?? null);
    $rPoint = ($tResult && $rFk) ? safe_col($pdo, $tResult, $r['point_no'] ?? null) : null;

    $tmplPointSet = is_array($tmplPointFullMap) ? $tmplPointFullMap : null;
    $useTemplateNg = ($tmplPointSet !== null && $tResult && $rFk && $rPoint);

    // 템플릿 NG 필터를 못쓰는 경우(템플릿 없음/point_no 컬럼 없음)는 예전처럼 전체 포인트 NG를 제외
    // [v7.23] 템플릿 NG 필터를 못쓰는 경우에는 NG 제외를 하지 않음(템플릿에 없는 NG는 무시)
    $select = ["h.`{$colId}` AS hid"];
    if ($colSrc)  $select[] = "h.`{$colSrc}` AS source_file";
    if ($colKind) $select[] = "h.`{$colKind}` AS kind";
    if ($colProd) $select[] = "h.`{$colProd}` AS prod_date";
    if ($colTc)   $select[] = "h.`{$colTc}` AS tc";
    if ($colTool) $select[] = "h.`{$colTool}` AS tool";
    if ($colCav)  $select[] = "h.`{$colCav}` AS cavity";

    $order = [];
    if ($colKind && !$kindWanted) {
        $order[] = "CASE WHEN UPPER(TRIM(h.`{$colKind}`)) = 'SPC' THEN 0 ELSE 1 END ASC";
    }
    if ($colMeas) $order[] = "h.`{$colMeas}` DESC";
    $order[] = "h.`{$colId}` DESC";

    $sql = "SELECT " . implode(", ", $select)
         . " FROM `{$tHeader}` h"
         . " WHERE " . implode(" AND ", $where)
         . " ORDER BY " . implode(", ", $order)
         . " LIMIT " . (int)$limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    // [v7.20] NG 헤더 스킵: 템플릿에 들어가는 point_no만 기준으로 판정
    if ($useTemplateNg) {
        $candIds = [];
        foreach ($rows as $rr) {
            $hid = (int)($rr['hid'] ?? 0);
            if ($hid > 0) $candIds[$hid] = true;
        }
        $candIds = array_keys($candIds);

        $ngIgnoreSet = oqc_current_ng_ignore_set();
        $bad = ($candIds ? oqc_build_bad_headers_by_template_ng($pdo, $meta, $candIds, $tmplPointSet, $ngIgnoreSet) : []);

        if ($bad) {
            $rows = array_values(array_filter($rows, function($r) use ($bad) {
                $hid = (int)($r['hid'] ?? 0);
                return ($hid > 0) && !isset($bad[$hid]);
            }));
            if (!$rows) return [];
        }
    }

    // 반환 포맷 정리
    $out = [];
    foreach ($rows as $rr) {
        $hid = (int)($rr['hid'] ?? 0);
        if ($hid <= 0) continue;

        $tcRaw = (string)($rr['tc'] ?? '');
        if ($tcRaw === '') {
            $tool = isset($rr['tool']) ? (string)$rr['tool'] : '';
            $cav  = isset($rr['cavity']) ? (string)$rr['cavity'] : '';
            if ($tool !== '' && $cav !== '') $tcRaw = $tool . '#' . $cav;
        }
        $tcNorm = normalize_tool_cavity_key($tcRaw);
        if ($tcNorm === '') continue;

        $src = isset($rr['source_file']) ? (string)$rr['source_file'] : '';
        $pd  = isset($rr['prod_date']) ? (string)$rr['prod_date'] : ($prodDate ?? '');
        $tag = oqc_extract_src_tag($src, $pd);

        $kind = isset($rr['kind']) ? strtoupper(trim((string)$rr['kind'])) : '';
        $out[] = [
            '_hid'  => $hid,
            '_tc'   => $tcNorm,
            '_tag'  => $tag,
            '_kind' => $kind,
            '_src'  => $src,
        ];
    }

    return $out;
}


function oqc_reserve_headers_meas_date_v712(PDO $pdo, array $meta, array $headerIds, ?string $refDate = null, ?string $logPart = null, ?string $logPass = null): int {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return 0;

    $hId   = $h['id'] ?? null;
    $hMeas = $h['meas_date'] ?? null;
    if (!$hId || !$hMeas) return 0;

    // unique ids
    $ids = [];
    foreach ($headerIds as $hid) {
        $hid = (int)$hid;
        if ($hid > 0) $ids[$hid] = true;
    }
    $ids = array_keys($ids);
    if (!$ids) return 0;

    $total = 0;
    $chunkSize = 500;
    $ref = is_string($refDate) ? trim($refDate) : '';
    $useRef = ($ref !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) === 1);
    $refMark = $useRef ? $ref : date('Y-m-d');

    for ($i = 0; $i < count($ids); $i += $chunkSize) {
        $chunk = array_slice($ids, $i, $chunkSize);
        if (!$chunk) continue;

        // ✅ 실제로 업데이트 될 대상만 먼저 추출(로그/취소 롤백 정확도)
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $selSql = "SELECT `{$hId}` AS id FROM `{$tHeader}` WHERE `{$hId}` IN ({$ph}) AND `{$hMeas}` IS NULL";
        $sel = $pdo->prepare($selSql);
        $sel->execute($chunk);
        $willIds = $sel->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $willIds = array_values(array_unique(array_map('intval', $willIds)));

        if ($useRef) {
            $sql = "UPDATE `{$tHeader}` SET `{$hMeas}` = ?" .
                 " WHERE `{$hId}` IN ({$ph}) AND `{$hMeas}` IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$ref], $chunk));
        } else {
            $sql = "UPDATE `{$tHeader}` SET `{$hMeas}` = CURDATE()" .
                 " WHERE `{$hId}` IN ({$ph}) AND `{$hMeas}` IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($chunk);
        }
        $affected = (int)$stmt->rowCount();
        $total += $affected;

        // ✅ 마킹 로그(취소용): header_id + 실제 사용 컬럼 + 날짜(guard)
        if ($willIds && function_exists('oqc_marklog_add_many')) {
            oqc_marklog_add_many($willIds, (string)$hMeas, (string)$refMark, [
                'type' => 'RESERVE',
                'part' => $logPart,
                'pass' => $logPass,
            ]);
        }
    }
    return $total;
}

/**
 * v7.13: 긴급 재사용(보강) 1회 소진
 * - meas_date BETWEEN (CURDATE()-60) AND (CURDATE()-30)
 * - meas_date2 IS NULL 인 것만 1회 재사용 가능
 * - 사용 즉시 meas_date2 = CURDATE() 로 마킹(완전 소진)
 *
 * @return array{id:int,src_tag:string,source_file:?string,kind:?string}|null
 */
function oqc_pick_emergency_and_consume_v712(PDO $pdo, array $meta, string $part, string $toolCavity, ?string $kindWanted = null, ?array $tmplPointFullMap = null, ?array $disallowKinds = null, ?string $refDate = null): ?array {
    $tHeader = (string)($meta['t_header'] ?? '');
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return null;

    $hId   = $h['id']        ?? 'id';
    $hPart = $h['part_name'] ?? 'part_name';
    $hKind = $h['kind']      ?? null;
    $hSrc  = $h['source_file'] ?? null;
    $hDate = $h['date'] ?? null;
    $hMeas = $h['meas_date'] ?? null;
    $hMeas2= $h['meas_date2']?? null;
$dbg = (int)($GLOBALS['OQC_DEBUG'] ?? 0);

    // tool#cavity 기반(우선 tc)
    $hTc   = $h['tc']    ?? null;
    $hTool = $h['tool']  ?? null;
    $hCav  = $h['cavity']?? null;

    if (!$hPart || !$hId || !$hMeas || !$hMeas2 || !$hDate) return null;

    $tcNorm = normalize_tool_cavity_key($toolCavity);
    if ($tcNorm === '') return null;

    // 긴급 재사용 범위: header.date BETWEEN (base-60일) AND (base-30일), meas_date2 IS NULL
    $tz = new DateTimeZone('Asia/Seoul');
    $baseYmd = (is_string($refDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate) === 1) ? $refDate : 'now';
    $today = new DateTime($baseYmd, $tz);
    $fromDays = (int)($GLOBALS['OQC_EMG_FROM_DAYS'] ?? 60);
    $toDays   = (int)($GLOBALS['OQC_EMG_TO_DAYS'] ?? 30);
    if ($fromDays < 1) $fromDays = 60;
    if ($toDays   < 0) $toDays   = 30;
    if ($toDays >= $fromDays) { $fromDays = 60; $toDays = 30; }

    $from = (clone $today)->modify('-' . $fromDays . ' days')->format('Y-m-d');
    $to   = (clone $today)->modify('-' . $toDays   . ' days')->format('Y-m-d');
    if ($dbg) {
        $k = $kindWanted ? $kindWanted : 'ANY';
        $dk = $disallowKinds ? implode(',', $disallowKinds) : '';
        logline("    [DEBUG] PASS3 try: part={$part} tc={$tcNorm} kind={$k} disallow={$dk} range={$from}~{$to} dateCol=" . ($hDate ?: 'null') . " meas2Col=" . ($hMeas2 ?: 'null'));
    }


    $where = [];
    $params = [];

    $where[] = "h.`{$hPart}` = :part";
    $params[':part'] = $part;

    if ($hTc) {
        // ✅ tc가 K#04, K # 4, K#4-2 등으로 저장되어도 매칭되도록 정규화 비교 추가
        $p = parse_tool_cavity_pair($tcNorm);
        if ($p) {
            [$t, $cv] = $p;
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
            $where[] = "(h.`{$hTc}` = :tc OR (UPPER(TRIM(SUBSTRING_INDEX(h.`{$hTc}`,'#',1))) = :tool AND TRIM(SUBSTRING_INDEX(h.`{$hTc}`,'#',-1)) REGEXP '^[0-9]+' AND CAST(TRIM(SUBSTRING_INDEX(h.`{$hTc}`,'#',-1)) AS UNSIGNED) = :cav))";
        } else {
            $where[] = "h.`{$hTc}` = :tc";
        }
        $params[':tc'] = $tcNorm;
    } else {
        $p = parse_tool_cavity_pair($tcNorm);
        if (!$p) return null;
        [$t, $cv] = $p;
        if ($hTool && $hCav) {
            $where[] = "h.`{$hTool}` = :tool";
            $where[] = "h.`{$hCav}` = :cav";
            $params[':tool'] = $t;
            $params[':cav']  = (int)$cv;
        } else {
            return null;
        }
    }

    $kindWanted = $kindWanted !== null ? strtoupper(trim($kindWanted)) : null;
    if ($kindWanted) {
        if (!$hKind) return null;
        $where[] = "UPPER(TRIM(h.`{$hKind}`)) = :kw";
        $params[':kw'] = $kindWanted;
    }

    $where[] = "h.`{$hDate}` BETWEEN :from AND :to";
    $where[] = "h.`{$hMeas2}` IS NULL";
    $params[':from'] = $from;
    $params[':to']   = $to;

    // [v7.23] disallowKinds: 특정 kind(Fai 등)를 이 슬롯에서 사용 금지
    $disallowSet = [];
    if (is_array($disallowKinds)) {
        foreach ($disallowKinds as $dk) {
            $dk = strtoupper(trim((string)$dk));
            if ($dk !== '') $disallowSet[$dk] = true;
        }
    }
    if ($disallowSet && $hKind) {
        $ph = [];
        $i = 0;
        foreach (array_keys($disallowSet) as $dk) {
            $k = ":dk{$i}";
            $ph[] = $k;
            $params[$k] = $dk;
            $i++;
        }
        if ($ph) $where[] = "UPPER(TRIM(h.`{$hKind}`)) NOT IN (" . implode(',', $ph) . ")";
    }


    // ─────────────────────────────
    // NG 판정(템플릿 B열(point_no)만 체크)
    // ─────────────────────────────
    // v7.25: SQL에서는 NG 조건을 걸지 않고, 후보들의 결과를 읽어 PHP에서만 NG 판정
    $tResult = (string)($meta['t_result'] ?? '');
    $r = $meta['r'] ?? [];
    $rFk    = safe_col($pdo, $tResult, $r['fk_header'] ?? null);
    $rPoint = ($tResult && $rFk) ? safe_col($pdo, $tResult, $r['point_no'] ?? null) : null;

    $tmplPointSet = is_array($tmplPointFullMap) ? $tmplPointFullMap : null;
    $useTemplateNg = ($tmplPointSet !== null && $tResult && $rFk && $rPoint);

    // [v7.23] 템플릿 NG 필터를 못쓰는 경우에는 NG 제외를 하지 않음(템플릿에 없는 NG는 무시)
    $select = ["h.`{$hId}` AS hid"];
    if ($hSrc)  $select[] = "h.`{$hSrc}` AS source_file";
    if ($hKind) $select[] = "h.`{$hKind}` AS kind";

    if ($hMeas) $select[] = "h.`{$hMeas}` AS meas_date";
    $order = [];
    if ($hKind && !$kindWanted) {
        $order[] = "CASE WHEN UPPER(TRIM(h.`{$hKind}`)) = 'SPC' THEN 0 ELSE 1 END ASC";
    }
    $order[] = "h.`{$hMeas}` ASC";
    $order[] = "h.`{$hId}` ASC";

    $limit = $useTemplateNg ? 50 : 1;

    try {
        $pdo->beginTransaction();

        $sql = "SELECT " . implode(", ", $select)
             . " FROM `{$tHeader}` h"
             . " WHERE " . implode(" AND ", $where)
             . " ORDER BY " . implode(", ", $order)
             . " LIMIT " . (int)$limit . " FOR UPDATE";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($dbg) { logline("    [DEBUG] PASS3 candRows=" . count($rows)); }
        if (!$rows) { if ($dbg) { logline("    [DEBUG] PASS3 no candidates -> return null"); } $pdo->rollBack(); return null; }

        $row = $rows[0];

        if ($useTemplateNg) {
            $picked = oqc_pick_first_good_header_by_template_ng($pdo, $meta, $rows, $tmplPointSet, 15);
            if (!$picked) { if ($dbg) { logline("    [DEBUG] PASS3 candidates exist but all NG by template -> return null"); } $pdo->rollBack(); return null; }
            $row = $picked;
        }
        if ($dbg) {
            $src = isset($row["source_file"]) ? $row["source_file"] : "";
            $k = isset($row["kind"]) ? $row["kind"] : "";
            logline("    [DEBUG] PASS3 picked: tc={$tryTc} hid=" . ($row["hid"] ?? "") . " kind=" . $k . " src=" . $src . " useTemplateNg=" . ($useTemplateNg ? "Y" : "N"));
        }


        $hid = (int)($row['hid'] ?? 0);
        if ($hid <= 0) { $pdo->rollBack(); return null; }
        $refMark = $today->format('Y-m-d');
        $meas1Val = isset($row['meas_date']) ? (string)$row['meas_date'] : '';
        $meas1Empty = ($meas1Val === '' || $meas1Val === '0000-00-00');

        if ($meas1Empty && $hMeas) {
            $upd = $pdo->prepare("UPDATE `{$tHeader}` SET `{$hMeas}` = :m1 WHERE `{$hId}` = :id AND `{$hMeas}` IS NULL");
            $upd->execute([':m1' => $refMark, ':id' => $hid]);
        } else if ($hMeas2) {
            $upd = $pdo->prepare("UPDATE `{$tHeader}` SET `{$hMeas2}` = :m2 WHERE `{$hId}` = :id AND `{$hMeas2}` IS NULL");
            $upd->execute([':m2' => $refMark, ':id' => $hid]);
        } else {
            $pdo->rollBack();
            return null;
        }

        if ($upd->rowCount() <= 0) {
            $pdo->rollBack();
            return null;
        }
        $pdo->commit();

        // ✅ 마킹 로그(취소 롤백용): 실제로 업데이트된 컬럼/날짜를 header_id별로 기록
        $updatedCol = ($meas1Empty && $hMeas) ? (string)$hMeas : (string)$hMeas2;
        if ($updatedCol !== '' && function_exists('oqc_marklog_add')) {
            oqc_marklog_add($hid, $updatedCol, $refMark, [
                'type' => 'PASS3-EMG',
                'part' => $part,
                'kind' => $kindWanted,
                'tc'   => $tcNorm,
            ]);
        }

        $srcFile = $row['source_file'] ?? null;
        return [
            'id' => $hid,
            'src_tag' => oqc_extract_src_tag((string)$srcFile, $today->format('Y-m-d')),
            'source_file' => $srcFile !== null ? (string)$srcFile : null,
            'kind' => isset($row['kind']) ? (string)$row['kind'] : null,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logline("  [긴급 재사용 오류] " . $e->getMessage());
        return null;
    }
}




function build_toolpair_record_map(PDO $pdo, array $meta, string $partName, array $prodDates, array $shippedCavs, array $shippedToolCavs = []): array {
    $out = [
        'mode' => '',
        'records' => [],   // "A#1" => id
        'dates' => [],     // "A#1" => lot_date
        'toolPairs' => [], // 정렬된 key 목록
    ];

    $prodDates = array_values(array_unique(array_filter(array_map('strval', $prodDates))));

    // shipped cavity set (숫자)
    $shippedCavSet = [];
    foreach ($shippedCavs as $c) {
        $ci = (int)$c;
        if ($ci > 0) $shippedCavSet[$ci] = true;
    }

    // shipped tool#cavity set (정규화)
    $shippedTcSet = [];
    foreach ($shippedToolCavs as $tc) {
        $k = normalize_tool_cavity_key((string)$tc);
        if ($k) $shippedTcSet[$k] = true;
    }

    // 필수 메타 체크
    $tHeader = (string)($meta['t_header'] ?? '');
    $tResult = (string)($meta['t_result'] ?? '');
    $tMeas   = (string)($meta['t_meas']   ?? '');
    if (!$tHeader || !$tMeas) return $out;

    $h = $meta['h'] ?? [];
    $r = $meta['r'] ?? [];
    $m = $meta['m'] ?? [];

    $hId    = $h['id']    ?? null;
    $hPart  = $h['part']  ?? null;
    $hDate  = $h['date']  ?? null;
    $hTc    = $h['tc']    ?? null;
    $hTool  = $h['tool']  ?? null;
    $hCav   = $h['cavity']?? null;

    if (!$hId || !$hPart || !$hDate) return $out;

    // 공통: 날짜 IN 조건
    $params = [':pn' => $partName];
    $dateSql = '';
    if ($prodDates) {
        $phs = [];
        foreach ($prodDates as $i => $d) {
            $ph = ":d{$i}";
            $phs[] = $ph;
            $params[$ph] = $d;
        }
        $dateSql = " AND h.`{$hDate}` IN (" . implode(',', $phs) . ") ";
    }

    // ─────────────────────────────────────────────
    // (1) result 모드 우선
    //  - measurements가 result_header FK를 갖고 있고
    //  - result_header가 header FK를 갖는 구조
    // ─────────────────────────────────────────────
    $canResult =
        $tResult &&
        ($m['fk_result'] ?? null) &&
        ($r['id'] ?? null) &&
        ($r['fk_header'] ?? null);

    if ($canResult) {
        $out['mode'] = 'result';

        $rId  = $r['id'];
        $rFk  = $r['fk_header'];

        $rTc   = $r['tc'] ?? null;
        $rTool = $r['tool'] ?? null;
        $rCav  = $r['cavity'] ?? null;

        // result에 tc/tool/cavity가 없으면 header를 사용(가능한 것만)
        $tcSel   = $rTc   ? "rh.`{$rTc}` AS tc_raw"   : ($hTc   ? "h.`{$hTc}` AS tc_raw"     : "NULL AS tc_raw");
        $toolSel = $rTool ? "rh.`{$rTool}` AS tool_raw" : ($hTool ? "h.`{$hTool}` AS tool_raw" : "NULL AS tool_raw");
        $cavSel  = $rCav  ? "rh.`{$rCav}` AS cavity_raw": ($hCav  ? "h.`{$hCav}` AS cavity_raw": "NULL AS cavity_raw");

        // tc도 없고 tool/cavity도 없으면 진행 불가
        if (!$rTc && !$hTc && !($rTool && $rCav) && !($hTool && $hCav)) return $out;

        $sql = "
            SELECT
                rh.`{$rId}` AS rid,
                {$tcSel},
                {$toolSel},
                {$cavSel},
                h.`{$hDate}` AS lot_date
            FROM `{$tResult}` rh
            JOIN `{$tHeader}` h
              ON rh.`{$rFk}` = h.`{$hId}`
            WHERE h.`{$hPart}` = :pn
              {$dateSql}
            ORDER BY h.`{$hDate}` DESC, rh.`{$rId}` DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $allRecords = [];
        $allDates   = [];
        $fRecords   = [];
        $fDates     = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = null;

            // 1) tc_raw 우선
            $tcRaw = trim((string)($row['tc_raw'] ?? ''));
            if ($tcRaw !== '') {
                $key = normalize_tool_cavity_key($tcRaw);
            }

            // 2) tool_raw + cavity_raw
            if (!$key) {
                $toolRaw = (string)($row['tool_raw'] ?? '');
                $cavRaw  = $row['cavity_raw'] ?? null;
                $tool = $toolRaw !== '' ? extract_tool_letter($toolRaw) : null;
                $cav  = (int)$cavRaw;
                if ($tool && $cav > 0) $key = $tool . '#' . $cav;
            }

            if (!$key) continue;

            // 전체(필터 전)
            if (!isset($allRecords[$key])) {
                $allRecords[$key] = (int)$row['rid'];
                $allDates[$key] = (string)($row['lot_date'] ?? '');
            }

            // 필터(우선순위: shipped tool#cavity > shipped cavity)
            $ok = true;
            if ($shippedTcSet) {
                $ok = isset($shippedTcSet[$key]);
            } elseif ($shippedCavSet) {
                $p = parse_tool_cavity_pair($key);
                $ok = $p ? isset($shippedCavSet[(int)$p[1]]) : false;
            }

            if ($ok) {
                if (!isset($fRecords[$key])) {
                    $fRecords[$key] = (int)$row['rid'];
                    $fDates[$key] = (string)($row['lot_date'] ?? '');
                }
            }
        }

        // 교집합이 비면 전체 유지(기존 로직 유지)
        if (($shippedTcSet || $shippedCavSet) && $fRecords) {
            $out['records'] = $fRecords;
            $out['dates'] = $fDates;
        } else {
            $out['records'] = $allRecords;
            $out['dates'] = $allDates;
        }

        $out['toolPairs'] = sort_tool_cavity_pairs(array_keys($out['records']));
        return $out;
    }

    // ─────────────────────────────────────────────
    // (2) header 모드
    //  - measurements가 header FK를 갖고 있고
    //  - header에 tc 또는 tool+cavity가 존재
    // ─────────────────────────────────────────────
    if (($m['fk_header'] ?? null) && ($hTc || ($hTool && $hCav))) {
        $out['mode'] = 'header';

        $tcSel   = $hTc   ? "h.`{$hTc}` AS tc_raw" : "NULL AS tc_raw";
        $toolSel = $hTool ? "h.`{$hTool}` AS tool_raw" : "NULL AS tool_raw";
        $cavSel  = $hCav  ? "h.`{$hCav}` AS cavity_raw" : "NULL AS cavity_raw";

        $sql = "
            SELECT
                h.`{$hId}` AS hid,
                {$tcSel},
                {$toolSel},
                {$cavSel},
                h.`{$hDate}` AS lot_date
            FROM `{$tHeader}` h
            WHERE h.`{$hPart}` = :pn
              {$dateSql}
            ORDER BY h.`{$hDate}` DESC, h.`{$hId}` DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $allRecords = [];
        $allDates   = [];
        $fRecords   = [];
        $fDates     = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = null;

            $tcRaw = trim((string)($row['tc_raw'] ?? ''));
            if ($tcRaw !== '') {
                $key = normalize_tool_cavity_key($tcRaw);
            }

            if (!$key) {
                $toolRaw = (string)($row['tool_raw'] ?? '');
                $cavRaw  = $row['cavity_raw'] ?? null;
                $tool = $toolRaw !== '' ? extract_tool_letter($toolRaw) : null;
                $cav  = (int)$cavRaw;
                if ($tool && $cav > 0) $key = $tool . '#' . $cav;
            }

            if (!$key) continue;

            if (!isset($allRecords[$key])) {
                $allRecords[$key] = (int)$row['hid'];
                $allDates[$key] = (string)($row['lot_date'] ?? '');
            }

            $ok = true;
            if ($shippedTcSet) {
                $ok = isset($shippedTcSet[$key]);
            } elseif ($shippedCavSet) {
                $p = parse_tool_cavity_pair($key);
                $ok = $p ? isset($shippedCavSet[(int)$p[1]]) : false;
            }

            if ($ok) {
                if (!isset($fRecords[$key])) {
                    $fRecords[$key] = (int)$row['hid'];
                    $fDates[$key] = (string)($row['lot_date'] ?? '');
                }
            }
        }

        if (($shippedTcSet || $shippedCavSet) && $fRecords) {
            $out['records'] = $fRecords;
            $out['dates'] = $fDates;
        } else {
            $out['records'] = $allRecords;
            $out['dates'] = $allDates;
        }

        $out['toolPairs'] = sort_tool_cavity_pairs(array_keys($out['records']));
        return $out;
    }

    return $out;
}

/**
 * measurements 테이블에서 (해당 레코드ID)의 측정값을 가져온다.
 * 반환: [excelRow => value]
 *
 * excelRow 매핑 규칙:
 *  - DB row 값이 11~999면 "엑셀 row 번호"로 간주
 *  - DB row 값이 1..N 이면 dataStartRow + (row-1) 로 간주
 */
function fetch_measure_column_values(PDO $pdo, array $meta, string $mode, int $recordId, int $dataStartRow, int $dataEndRow): array {
    $tMeas = (string)($meta['t_meas'] ?? '');
    if (!$tMeas) return [];

    $m = $meta['m'] ?? [];
    $rowCol = $m['row'] ?? null;
    $valCol = $m['value'] ?? null;

    if (!$rowCol || !$valCol) return [];

    $fk = null;
    if ($mode === 'result') $fk = $m['fk_result'] ?? null;
    if ($mode === 'header') $fk = $m['fk_header'] ?? null;
    if (!$fk) return [];

    $sql = "
        SELECT `{$rowCol}` AS r, `{$valCol}` AS v
        FROM `{$tMeas}`
        WHERE `{$fk}` = :id
        ORDER BY `{$rowCol}` ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $recordId]);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $r0 = $row['r'] ?? null;
        if ($r0 === null || $r0 === '') continue;

        $rn = (int)$r0;

        // 엑셀 row 직접 저장된 케이스
        if ($rn >= 11 && $rn <= 999) {
            $excelRow = $rn;
        } else {
            // 1..N 시퀀스 케이스
            $excelRow = $dataStartRow + ($rn - 1);
        }

        if ($excelRow < $dataStartRow || $excelRow > $dataEndRow) continue;

        $out[$excelRow] = normalize_meas_value($row['v'] ?? null);
    }
    return $out;
}


/**
 * measurements 값을 "point_no -> 템플릿 B열(FAI) row" 로 매칭해서 가져온다.
 * (IRBASE만 우연히 row_index 매칭이 맞는 문제가 있어, X/Y/ZC/ZS는 point_no 매칭이 필수)
 *
 * - point_no 가 없으면 기존 fetch_measure_column_values() 로 fallback
 * - 매칭 실패 시 row_index 기반으로 보수적으로 fallback (완전 공백 방지)
 */
function fetch_measure_column_values_by_point(PDO $pdo, array $meta, string $mode, int $recordId, array $faiMaps, int $dataStartRow, int $dataEndRow): array {
    // 반환: [excelRow => value]
    // ✅ OQC 폴더(OQC_*.xlsx)는 "rowdata" 성격이라 oqc_measurements(value) 우선.
    //    - 일부 모델/포인트(예: Z-STOPPER 55-1)는 oqc_result_header(mean_val)에 없고
    //      oqc_measurements(value)에만 존재하는 케이스가 있어, measurements → result 순으로 fallback 한다.
    // ✅ 매칭은 point_no만(정규화/variant) 사용. spc_code 매칭 금지.
    // ✅ row_index fallback 제거(요청사항).

    $out = [];

    $full = $faiMaps['full'] ?? [];
    $base = $faiMaps['base'] ?? [];

    // ─────────────────────────────
    // 1) oqc_measurements(value) 우선
    // ─────────────────────────────
    $tMeas = $meta['t_meas'] ?? null;
    $m = $meta['m'] ?? [];

    if ($tMeas && preg_match('/^[A-Za-z0-9_]+$/', (string)$tMeas) && is_array($m) && $m) {
        $fkHeader = safe_col($pdo, $tMeas, $m['fk_header'] ?? null);
        if (!$fkHeader) {
            // 일부 스키마는 fk_result만 있는 경우가 있어 recordId를 그쪽에 넣는 fallback
            $fkHeader = safe_col($pdo, $tMeas, $m['fk_result'] ?? null);
        }
        $pCol = safe_col($pdo, $tMeas, $m['point_no'] ?? null);
        $vCol = safe_col($pdo, $tMeas, $m['value'] ?? null);
        $idCol = safe_col($pdo, $tMeas, $m['id'] ?? null);

        if ($fkHeader && $pCol && $vCol) {
            $sql = "SELECT `{$pCol}` AS point_no, `{$vCol}` AS value" . ($idCol ? ", `{$idCol}` AS id" : "") .
                   " FROM `{$tMeas}` WHERE `{$fkHeader}` = ?" . ($idCol ? " ORDER BY `{$idCol}` ASC" : "");
            $st = $pdo->prepare($sql);
            $st->execute([$recordId]);

            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $pno = trim((string)($r['point_no'] ?? ''));
                if ($pno === '') continue;

                $row = null;

                // full-key 우선(정확 매칭)
                foreach (oqc_key_variants($pno) as $kFull) {
                    if ($kFull !== '' && isset($full[$kFull])) { $row = (int)$full[$kFull]; break; }
                }
                // full에서 못 찾으면 base-key(괄호 제거)로 제한적 fallback
                if ($row === null) {
                    foreach (oqc_base_key_variants($pno) as $kBase) {
                        if ($kBase !== '' && isset($base[$kBase])) { $row = (int)$base[$kBase]; break; }
                    }
                }

                if ($row === null) continue;
                if ($row < $dataStartRow || $row > $dataEndRow) continue;

                $val = normalize_meas_value($r['value'] ?? null);
                if ($val === null || $val === '') continue;

                // 같은 row에 여러 건이 있으면 마지막 값(최신 id)으로 overwrite
                $out[$row] = $val;
            }
        }
    }

    // ─────────────────────────────
    // 2) oqc_result_header(mean_val 등) fallback
    // ─────────────────────────────
    $tRes = $meta['t_result'] ?? null;
    $rr = $meta['r'] ?? [];

    if ($tRes && preg_match('/^[A-Za-z0-9_]+$/', (string)$tRes) && is_array($rr) && $rr) {
        $fkHeader = safe_col($pdo, $tRes, $rr['fk_header'] ?? null);
        $pCol = safe_col($pdo, $tRes, $rr['point_no'] ?? null);

        // mean_val 우선, 없으면 max/min/value 순으로 fallback
        $meanCol = safe_col($pdo, $tRes, $rr['mean'] ?? 'mean_val');
        $maxCol  = safe_col($pdo, $tRes, $rr['max']  ?? 'max_val');
        $minCol  = safe_col($pdo, $tRes, $rr['min']  ?? 'min_val');
        $valCol  = $meanCol ?: ($maxCol ?: ($minCol ?: null));
        $idCol   = safe_col($pdo, $tRes, $rr['id'] ?? null);

        if ($fkHeader && $pCol && $valCol) {
            $sql = "SELECT `{$pCol}` AS point_no, `{$valCol}` AS value" . ($idCol ? ", `{$idCol}` AS id" : "") .
                   " FROM `{$tRes}` WHERE `{$fkHeader}` = ?" . ($idCol ? " ORDER BY `{$idCol}` ASC" : "");
            $st = $pdo->prepare($sql);
            $st->execute([$recordId]);

            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $pno = trim((string)($r['point_no'] ?? ''));
                if ($pno === '') continue;

                $row = null;

                foreach (oqc_key_variants($pno) as $kFull) {
                    if ($kFull !== '' && isset($full[$kFull])) { $row = (int)$full[$kFull]; break; }
                }
                if ($row === null) {
                    foreach (oqc_base_key_variants($pno) as $kBase) {
                        if ($kBase !== '' && isset($base[$kBase])) { $row = (int)$base[$kBase]; break; }
                    }
                }

                if ($row === null) continue;
                if ($row < $dataStartRow || $row > $dataEndRow) continue;

                // measurements에서 이미 채운 row면 유지
                if (isset($out[$row]) && $out[$row] !== null && $out[$row] !== '') continue;

                $val = normalize_meas_value($r['value'] ?? null);
                if ($val === null || $val === '') continue;
                $out[$row] = $val;
            }
        }
    }

    return $out;
}


// ─────────────────────────────
// report_finish (출하성적서 발행/취소 이력) 헬퍼
//  - UI에서 월 단위 조회 + 취소(= OQC 사용 마킹 되돌리기) 용도
//  - "정확히 어떤 oqc_header.id를 썼는지"까지 추적하려면 run_id 로그 테이블이 필요하지만,
//    현재는 날짜(=meas_date/meas_date2에 찍힌 날짜) 기준 롤백을 사용한다.
// ─────────────────────────────




// ─────────────────────────────────────────────
// ZS 특수 포인트 빈값 보강
// - 일부 포인트에서 특정 tool#cavity 컬럼이 비어있는 케이스를 보강(복원)
// - 같은 Tool의 다른 cavity 값으로 대체(우선순위) 후, 그래도 없으면 0
// ─────────────────────────────────────────────
function oqc_zs_backfill_specific_points(array &$dataByRow, array $faiMaps, array $toolPairs): void
{
    // ✅ ZS 전용 보강:
    // - 특정 포인트(FAI명)에서 일부 cavity(툴캐비티) 값이 비는 케이스가 있음
    // - 그 행에서 존재하는 값들의 MIN/MAX를 구해, 빈 칸은 MIN~MAX 사이 랜덤값으로 채움
    //   (요구: 33,55-1,55-2,56-1,56-2)

    $targets = ['33', '55-1', '55-2', '56-1', '56-2'];

    $rows = [];
    $fullMap = $faiMaps['full'] ?? [];
    $baseMap = $faiMaps['base'] ?? [];

    foreach ($targets as $target) {
        $row = null;
        foreach (oqc_key_variants($target) as $k) {
            if ($k !== '' && isset($fullMap[$k])) { $row = (int)$fullMap[$k]; break; }
        }
        if ($row === null) {
            foreach (oqc_base_key_variants($target) as $k) {
                if ($k !== '' && isset($baseMap[$k])) { $row = (int)$baseMap[$k]; break; }
            }
        }
        if ($row !== null) {
            $rows[$target] = $row;
        }
    }
    if (!$rows) return;

    // 고정 소수점(기존 데이터가 0.### 형태가 많음)
    $roundN = 3;

    foreach ($rows as $k => $r) {
        if (!isset($dataByRow[$r]) || !is_array($dataByRow[$r])) continue;

        $vals = $dataByRow[$r];

        // 숫자값만 모아 MIN/MAX 계산
        $nums = [];
        foreach ($vals as $v) {
            if ($v === null || $v === '') continue;
            if (is_numeric($v)) $nums[] = (float)$v;
        }
        if (count($nums) < 1) continue;

        $min = min($nums);
        $max = max($nums);
        if (!is_finite($min) || !is_finite($max)) continue;
        if ($min === $max) $max = $min + 0.0001;

        // 빈 칸만 채움
        $filled = false;
        for ($i = 0; $i < count($vals); $i++) {
            $v = $vals[$i];
            if ($v === null || $v === '') {
                $u = mt_rand() / mt_getrandmax(); // 0..1
                $rand = $min + $u * ($max - $min);
                $vals[$i] = round($rand, $roundN);
                $filled = true;
            }
        }

        if ($filled) $dataByRow[$r] = $vals;
    }
}


/**
 * OQC 템플릿(.xlsx)을 PhpSpreadsheet로 열어서 값만 채운 뒤 저장한다.
 * - XML(zip) 직접 패치 사용하지 않음 (요청사항)
 * - 템플릿의 테두리/병합/서식은 "기존 셀"을 덮어쓰는 방식으로 최대한 유지
 *
 * @return array ['ok'=>bool,'written'=>int,'reason'=>string]
 */
function oqc_write_oqc_template_phpspreadsheet(
    string $tplPath,
    string $outPath,
    array $sourceTags,
    array $toolPairs,
    array $dataByRow,
    string $sheetName = 'OQC',
    string $startColLetter = 'AK',
    int $sourceRow = 6,
    int $toolRow = 10,
    int $dataStartRow = 11
): array {
    $out = ['ok'=>false, 'written'=>0, 'reason'=>''];

    if (!$tplPath || !is_file($tplPath)) { $out['reason']='template not found'; return $out; }

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        // 성능/호환 옵션
        if (method_exists($reader, 'setIncludeCharts')) $reader->setIncludeCharts(false);
        if (method_exists($reader, 'setReadEmptyCells')) $reader->setReadEmptyCells(false);

        $spreadsheet = $reader->load($tplPath);
        $ws = $spreadsheet->getSheetByName($sheetName);
        if (!$ws) $ws = $spreadsheet->getActiveSheet();

        $startIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($startColLetter);

        // 컬럼별 xfIndex 레퍼런스(새 셀 생성 시 테두리/서식 유지용)
        $xfRef = [];
        for ($i = 0; $i < 32; $i++) {
            $c = $startIdx + $i;
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $dataStartRow;
            try {
                $xfRef[$c] = (int)$ws->getCell($coord)->getXfIndex();
            } catch (\Throwable $e) {
                $xfRef[$c] = 0;
            }
        }

        // 쓰기 유틸(값 + 필요 시 xfIndex 보정)
        $writeRow = static function($ws, int $row, int $startIdx, array $vals, array $xfRef, bool $numericPreferred, int &$written) {
            $n = min(32, count($vals));
            for ($i = 0; $i < $n; $i++) {
                $c = $startIdx + $i;
                $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
                $cell = $ws->getCell($addr);

                $v = $vals[$i];
                if ($v === null) $v = '';
                // 숫자/문자 구분
                $isNum = false;
                if ($numericPreferred && $v !== '' && is_numeric($v)) {
                    // 앞자리 0 보호(예: "0012" 같은 문자열은 숫자로 바꾸지 않음)
                    $sv = (string)$v;
                    if (!(strlen($sv) > 1 && $sv[0] === '0' && strpos($sv, '.') === false)) {
                        $isNum = true;
                    }
                }

                if ($isNum) {
                    $cell->setValueExplicit((float)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                } else {
                    $cell->setValueExplicit((string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }

                $xf = (int)$cell->getXfIndex();
                if ($xf === 0 && isset($xfRef[$c]) && (int)$xfRef[$c] !== 0) {
                    try { $cell->setXfIndex((int)$xfRef[$c]); } catch (\Throwable $e) {}
                }
                $written++;
            }
        };

        // AK6~BP6: source tags (string)
        $tmp = [];
        for ($i=0;$i<32;$i++) $tmp[$i] = (string)($sourceTags[$i] ?? '');
        $writeRow($ws, $sourceRow, $startIdx, $tmp, $xfRef, false, $out['written']);

        // AK10~BP10: tool pairs (string)
        $tmp = [];
        for ($i=0;$i<32;$i++) $tmp[$i] = (string)($toolPairs[$i] ?? '');
        $writeRow($ws, $toolRow, $startIdx, $tmp, $xfRef, false, $out['written']);

        // AK11~: data (numeric preferred)
        foreach ($dataByRow as $r => $vals) {
            $rr = (int)$r;
            if ($rr <= 0) continue;
            if (!is_array($vals)) continue;
            $writeRow($ws, $rr, $startIdx, $vals, $xfRef, true, $out['written']);
        }

        // 저장
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outPath);

        // 메모리 해제
        if (function_exists('disconnect_book')) {
            // 메인에서 쓰는 disconnect_book이 있으면 활용
            try { disconnect_book($spreadsheet); } catch (\Throwable $e) {}
        }
        $spreadsheet = null;
        $ws = null;
        $reader = null;
        $writer = null;

        $out['ok'] = true;
        return $out;

    } catch (\Throwable $e) {
        $out['reason'] = $e->getMessage();
        return $out;
    }
}


// ✅ DEBUG helper: tc별 후보 수(생산일 매칭 / 긴급 재사용 window) 진단
// - 운영에는 영향 없음(디버그 출력에서만 호출)
function oqc_debug_tc_diag($pdo, $meta, $part, $tcNorm, $prodDates, $fromDays = 60, $toDays = 30, ?string $refDate = null) {
    if (!$pdo || !$meta || !$part || !$tcNorm) return null;

    $tHeader = $meta['t_header'] ?? null;
    $h = $meta['h'] ?? [];
    if (!$tHeader || !$h) return null;

    // init_oqc_db_meta() 기준 키: part/date/ship_date/meas_date/meas_date2/tc/tool/cavity/kind
    // (구버전 호환) part_name 키도 fallback으로 허용
    $hPart = $h['part'] ?? ($h['part_name'] ?? null);
    $hKind = $h['kind'] ?? null;
    $hTc   = $h['tc'] ?? null;
    $hTool = $h['tool'] ?? null;
    $hCav  = $h['cavity'] ?? null;

    $hDate  = $h['date'] ?? null;
    $hShip  = $h['ship_date'] ?? null;
    $hMeas  = $h['meas_date'] ?? null;
    $hMeas2 = $h['meas_date2'] ?? null;

    if (!$hPart) return null;

    $prodDates = array_values(array_unique(array_filter($prodDates)));
    if (count($prodDates) > 12) $prodDates = array_slice($prodDates, 0, 12);

    $p = parse_tool_cavity_pair($tcNorm);
    $tool = $p ? $p[0] : null;
    $cav  = $p ? (int)$p[1] : null;

    // tc 매칭 조건 + params
    $whereTc = '';
    $baseParams = [':part' => $part, ':tc' => $tcNorm];
    if ($hTc) {
        $whereTc = "(h.`{$hTc}` = :tc";
        if ($tool !== null && $cav !== null) {
            $baseParams[':tool'] = $tool;
            $baseParams[':cav']  = $cav;
            $whereTc .= " OR (UPPER(TRIM(SUBSTRING_INDEX(h.`{$hTc}`,'#',1))) = :tool"
                     .  " AND TRIM(SUBSTRING_INDEX(h.`{$hTc}`,'#',-1)) REGEXP '^[0-9]+'"
                     .  " AND CAST(TRIM(SUBSTRING_INDEX(h.`{$hTc}`,'#',-1)) AS UNSIGNED) = :cav)";
        }
        $whereTc .= ")";
    } elseif ($hTool && $hCav && $tool !== null && $cav !== null) {
        $baseParams[':tool'] = $tool;
        $baseParams[':cav']  = $cav;
        $whereTc = "(h.`{$hTool}` = :tool AND h.`{$hCav}` = :cav)";
    } else {
        return null;
    }

    // 생산일 매칭(= PASS1/2가 찾는 후보 존재 여부)용 date where
    $dateCols = [];
    if ($hDate)  $dateCols[] = $hDate;
    if ($hShip)  $dateCols[] = $hShip;
    if ($hMeas)  $dateCols[] = $hMeas;
    if ($hMeas2) $dateCols[] = $hMeas2;

    $dateWhere = '';
    $dateParams = [];
    if (!empty($dateCols) && !empty($prodDates)) {
        $colOr = [];
        $cIdx = 0;
        foreach ($dateCols as $col) {
            $ph = [];
            foreach ($prodDates as $i => $d) {
                $k = ":pd{$cIdx}_{$i}";
                $ph[] = $k;
                $dateParams[$k] = $d;
            }
            $colOr[] = "h.`{$col}` IN (" . implode(',', $ph) . ")";
            $cIdx++;
        }
        $dateWhere = "(" . implode(" OR ", $colOr) . ")";
    } else {
        // prodDates가 없으면 0으로 처리(진단 의미 없음)
        $dateWhere = "(1=0)";
    }

    $out = [
        'tc' => $tcNorm,
        'prod_any' => 0, 'prod_spc' => 0, 'prod_fai' => 0,
        'emg_u_60_30' => 0, 'emg_u_90_30' => 0, 'emg_u_120_30' => 0,
        'emg_used_60_30' => 0,
        'meas_min' => '', 'meas_max' => '',
        'samples' => [],
    ];

    // 공통 where
    $baseWhere = "h.`{$hPart}` = :part AND {$whereTc}";

    // 1) prod(any/SPC/FAI)
    $sqlAny = "SELECT COUNT(*) AS c FROM `{$tHeader}` h WHERE {$baseWhere} AND {$dateWhere}";
    $stmt = $pdo->prepare($sqlAny);
    $stmt->execute(array_merge($baseParams, $dateParams));
    $out['prod_any'] = (int)($stmt->fetchColumn() ?: 0);

    if ($hKind) {
        $sqlSpc = "SELECT COUNT(*) AS c FROM `{$tHeader}` h WHERE {$baseWhere} AND {$dateWhere} AND h.`{$hKind}`='SPC'";
        $stmt = $pdo->prepare($sqlSpc);
        $stmt->execute(array_merge($baseParams, $dateParams));
        $out['prod_spc'] = (int)($stmt->fetchColumn() ?: 0);

        $sqlFai = "SELECT COUNT(*) AS c FROM `{$tHeader}` h WHERE {$baseWhere} AND {$dateWhere} AND h.`{$hKind}`='FAI'";
        $stmt = $pdo->prepare($sqlFai);
        $stmt->execute(array_merge($baseParams, $dateParams));
        $out['prod_fai'] = (int)($stmt->fetchColumn() ?: 0);
    }

    // 2) meas_date(min/max) (전체)
    if ($hMeas) {
        $sqlMM = "SELECT MIN(h.`{$hMeas}`) AS mn, MAX(h.`{$hMeas}`) AS mx FROM `{$tHeader}` h WHERE {$baseWhere}";
        $stmt = $pdo->prepare($sqlMM);
        $stmt->execute($baseParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $out['meas_min'] = $row['mn'] ?? ''; $out['meas_max'] = $row['mx'] ?? ''; }
    }


    // 2-1) 샘플 3개(실제 tc/날짜가 어떤 형태인지 확인용)
    $hId = $h['id'] ?? null;
    $cols = [];
    if ($hId)   $cols[] = "h.`{$hId}` AS id";
    if ($hKind) $cols[] = "h.`{$hKind}` AS kind";
    if ($hTc)   $cols[] = "h.`{$hTc}` AS tc_raw";
    if ($hDate) $cols[] = "h.`{$hDate}` AS date_v";
    if ($hMeas) $cols[] = "h.`{$hMeas}` AS meas_v";
    if ($hMeas2)$cols[] = "h.`{$hMeas2}` AS meas2_v";
    if (!empty($cols)) {
        $sqlS = "SELECT " . implode(',', $cols) . " FROM `{$tHeader}` h WHERE {$baseWhere} ORDER BY "
              . ($hMeas2 ? "h.`{$hMeas2}` IS NULL, h.`{$hMeas2}` DESC, " : "")
              . ($hMeas  ? "h.`{$hMeas}` IS NULL, h.`{$hMeas}` DESC, " : "")
              . ($hDate  ? "h.`{$hDate}` DESC" : "1")
              . " LIMIT 3";
        $stmt = $pdo->prepare($sqlS);
        $stmt->execute($baseParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out['samples'][] = $r;
        }
    }

    // 3) 긴급 재사용 후보(= meas_date BETWEEN window AND meas_date2 IS NULL)
    if ($hMeas && $hMeas2) {
        $tz = new DateTimeZone('Asia/Seoul');
    $baseYmd = (is_string($refDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate) === 1) ? $refDate : date('Y-m-d');
    $today = new DateTime($baseYmd, $tz);
        $win = [
            [60, 30, 'emg_u_60_30'],
            [90, 30, 'emg_u_90_30'],
            [120,30, 'emg_u_120_30'],
        ];

        foreach ($win as $w) {
            [$fd, $td, $key] = $w;
            $from = (clone $today)->modify('-' . (int)$fd . ' days')->format('Y-m-d');
            $to   = (clone $today)->modify('-' . (int)$td . ' days')->format('Y-m-d');
            $sqlW = "SELECT COUNT(*) AS c FROM `{$tHeader}` h WHERE {$baseWhere} AND h.`{$hMeas}` BETWEEN :from AND :to AND h.`{$hMeas2}` IS NULL";
            $stmt = $pdo->prepare($sqlW);
            $p2 = $baseParams; $p2[':from'] = $from; $p2[':to'] = $to;
            $stmt->execute($p2);
            $out[$key] = (int)($stmt->fetchColumn() ?: 0);
        }

        // consumed(=meas_date2 NOT NULL) in 60~30
        $from = (new DateTime($baseYmd, $tz))->modify('-60 days')->format('Y-m-d');
        $to   = (new DateTime($baseYmd, $tz))->modify('-30 days')->format('Y-m-d');
        $sqlC = "SELECT COUNT(*) AS c FROM `{$tHeader}` h WHERE {$baseWhere} AND h.`{$hMeas}` BETWEEN :from AND :to AND h.`{$hMeas2}` IS NOT NULL";
        $stmt = $pdo->prepare($sqlC);
        $p3 = $baseParams; $p3[':from'] = $from; $p3[':to'] = $to;
        $stmt->execute($p3);
        $out['emg_used_60_30'] = (int)($stmt->fetchColumn() ?: 0);
    }

    return $out;
}

// [DEBUG] PASS2 진단용 래퍼: shipinglist_export_lotlist.php에서 사용 가능하도록 제공
if (!function_exists('oqc_diag_pick_window_summary')) {
    function oqc_diag_pick_window_summary($pdo, $meta, $part, $tcNorm, $prodDates, $emgFromDays, $emgToDays, $refDate) {
        try {
            return oqc_debug_tc_diag($pdo, $meta, $part, $tcNorm, $prodDates, (int)$emgFromDays, (int)$emgToDays, $refDate);
        } catch (Throwable $e) {
            return null;
        }
    }
}



// ✅ PASS3(v48 스타일)용: 출하일(refDate) 기준 -N ~ -M 윈도우에서 meas_date2 미사용 후보 Tool#Cavity(tc) 목록을 뽑는다.
// - kindWanted: 'FAI'/'SPC'/null(전체)
// - disallowKinds: ['FAI'] 처럼 제외할 kind 목록(대문자 기준)
// - 반환: 정규화된 "TOOL#CAV" 문자열 배열(중복 제거, 정렬 유지)
if (!function_exists('oqc_emg_list_tc_candidates_v48')) {
    function oqc_emg_list_tc_candidates_v48(PDO $pdo, array $meta, string $part, ?string $kindWanted, ?array $disallowKinds, string $refDate, int $limit = 800): array {
        $tHeader = $meta['t_header'] ?? '';
        $h = $meta['h'] ?? [];
        if (!$tHeader) return [];

        $hId   = $h['id']   ?? 'id';
        $hPart = $h['part'] ?? 'part_name';
        $hKind = $h['kind'] ?? null;
        $hDate = $h['date'] ?? null;
        $hMeas2 = $h['meas_date2'] ?? null;
        $hMeas  = $h['meas_date']  ?? null;
        $hTc   = $h['tc']   ?? null;
        $hTool = $h['tool'] ?? null;
        $hCav  = $h['cavity'] ?? null;

        if (!$hDate || !$hMeas2) return [];

        $fromDays = (int)($GLOBALS['OQC_EMG_FROM_DAYS'] ?? 60);
        $toDays   = (int)($GLOBALS['OQC_EMG_TO_DAYS'] ?? 30);
        if ($fromDays < $toDays) {
            $tmp = $fromDays; $fromDays = $toDays; $toDays = $tmp;
        }

        try {
            $dRef  = new DateTime($refDate);
        } catch (Throwable $e) {
            return [];
        }
        $dFrom = (clone $dRef)->modify("-{$fromDays} days")->format('Y-m-d');
        $dTo   = (clone $dRef)->modify("-{$toDays} days")->format('Y-m-d');

        $where = [];
        $params = [];
        $where[] = "h.`{$hPart}` = ?";
        $params[] = $part;
        $where[] = "h.`{$hDate}` BETWEEN ? AND ?";
        $params[] = $dFrom;
        $params[] = $dTo;
        $where[] = "h.`{$hMeas2}` IS NULL";

        $kindWantedU = $kindWanted ? strtoupper(trim($kindWanted)) : null;
        if ($kindWantedU && $hKind) {
            $where[] = "UPPER(h.`{$hKind}`) = ?";
            $params[] = $kindWantedU;
        }

        $disU = [];
        if ($disallowKinds && $hKind) {
            for ($x=0; $x<count($disallowKinds); $x++) {
                $k = strtoupper(trim((string)$disallowKinds[$x]));
                if ($k !== '') $disU[] = $k;
            }
            if (!empty($disU)) {
                $in = ','.implode(',', array_fill(0, count($disU), '?')).',';
                // build "NOT IN" placeholders
                $where[] = "UPPER(h.`{$hKind}`) NOT IN (" . implode(',', array_fill(0, count($disU), '?')) . ")";
                foreach ($disU as $k) { $params[] = $k; }
            }
        }

        // SELECT: tc가 없으면 tool/cavity로 구성
        $sel = ["h.`{$hId}` AS hid", "h.`{$hDate}` AS hdate"]; // 정렬 안정용
        if ($hMeas) $sel[] = "h.`{$hMeas}` AS hmeas";
        if ($hKind) $sel[] = "h.`{$hKind}` AS hkind";
        if ($hTc) {
            $sel[] = "h.`{$hTc}` AS htc";
        } else {
            if ($hTool) $sel[] = "h.`{$hTool}` AS htool";
            if ($hCav)  $sel[] = "h.`{$hCav}` AS hcav";
        }

        $order = [];
        // kindWanted가 없고 kind 컬럼이 있으면 SPC 우선으로 정렬
        if (!$kindWantedU && $hKind) {
            $order[] = "CASE WHEN UPPER(h.`{$hKind}`)='SPC' THEN 0 ELSE 1 END";
        }
        if ($hMeas) $order[] = "h.`{$hMeas}` ASC";
        $order[] = "h.`{$hId}` ASC";

        $sql = "SELECT " . implode(', ', $sel) . " FROM `{$tHeader}` h WHERE " . implode(' AND ', $where)
             . " ORDER BY " . implode(', ', $order)
             . " LIMIT " . (int)$limit;

        $rows = [];
        try {
            $st = $pdo->prepare($sql);
            $st->execute(array_values($params));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // 쿼리 실패 시 빈 배열
            return [];
        }

        $out = [];
        $seen = [];
        for ($i=0; $i<count($rows); $i++) {
            $r = $rows[$i];
            $tc = '';
            if ($hTc) {
                $tc = trim((string)($r['htc'] ?? ''));
            } else {
                $tool = trim((string)($r['htool'] ?? ''));
                $cav  = trim((string)($r['hcav'] ?? ''));
                if ($tool !== '' && $cav !== '') $tc = $tool . '#' . $cav;
            }
            $norm = normalize_tool_cavity_key($tc);
            if ($norm === '' || isset($seen[$norm])) continue;
            $seen[$norm] = true;
            $out[] = $norm; // 정규화된 형태로 반환
        }
        return $out;
    }
}
