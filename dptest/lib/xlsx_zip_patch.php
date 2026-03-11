<?php
// ─────────────────────────────────────────────────────────────
// XLSX ZIP 패치(템플릿 보존용)
//  - PhpSpreadsheet로 "열고 다시 저장"하면
//    도형/선/차트 등 일부 오브젝트가 깨지거나 사라질 수 있어서,
//    OQC는 템플릿(.xlsx)을 그대로 복사한 뒤 시트 XML의 셀 값만 교체한다.
// ─────────────────────────────────────────────────────────────

function xlsx_get_sheet_path_by_name(ZipArchive $zip, string $sheetName = 'OQC'): ?string {
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) return null;

    // 1) name="OQC" 의 r:id 찾기
    $rid = null;
    $pattern = '/<sheet\\b[^>]*\\bname="' . preg_quote($sheetName, '/') . '"[^>]*\\br:id="([^"]+)"[^>]*\\/>/i';
    if (preg_match($pattern, $wb, $m)) {
        $rid = $m[1];
    } else {
        // fallback: 첫 번째 sheet
        if (preg_match('/<sheet\\b[^>]*\\br:id="([^"]+)"[^>]*\\/>/i', $wb, $m2)) {
            $rid = $m2[1];
        }
    }
    if (!$rid) return null;

    // 2) workbook rels에서 해당 rId의 Target(worksheets/sheetX.xml) 찾기
    $pattern2 = '/<Relationship\\b[^>]*\\bId="' . preg_quote($rid, '/') . '"[^>]*\\bTarget="([^"]+)"[^>]*\\/>/i';
    if (!preg_match($pattern2, $rels, $m3)) return null;
    $target = ltrim($m3[1], '/');
    if (stripos($target, 'xl/') === 0) return $target;
    return 'xl/' . $target;
}

function xlsx_get_dimension_end_row(string $sheetXml): int {
    // <dimension ref="A1:BP2000"/> 같은 형태
    if (preg_match('/<dimension\\b[^>]*\\bref="[^"]*:(?:[A-Z]+)(\\d+)"[^>]*\\/>/i', $sheetXml, $m)) {
        return (int)$m[1];
    }
    // fallback: row r="..."
    $max = 0;
    if (preg_match_all('/<row\\b[^>]*\\br="(\\d+)"/i', $sheetXml, $mm)) {
        foreach ($mm[1] as $r) {
            $ri = (int)$r;
            if ($ri > $max) $max = $ri;
        }
    }
    return $max > 0 ? $max : 2000;
}


/**
 * XLSX 템플릿에서 특정 행(열 범위)의 "표시 문자열"을 읽어온다.
 * - sharedStrings / inlineStr 모두 대응
 * - 반환 길이 = (endCol-startCol+1) 로 고정(빈칸은 '')
 */
function xlsx_read_row_values_from_xlsx(string $xlsxPath, string $sheetName, int $rowNum, string $startCol, string $endCol): array {
    $startIdx = xlsx_col_to_index($startCol);
    $endIdx   = xlsx_col_to_index($endCol);
    $need     = $endIdx - $startIdx + 1;
    if ($need <= 0) return [];

    $out = array_fill(0, $need, '');

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) return $out;

    $sheetPath = xlsx_get_sheet_path_by_name($zip, $sheetName);
    if (!$sheetPath) { $zip->close(); return $out; }

    $xml = $zip->getFromName($sheetPath);
    if ($xml === false) { $zip->close(); return $out; }

    // shared strings 로드
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $domSS = new DOMDocument();
        $domSS->preserveWhiteSpace = false;
        if (@$domSS->loadXML($ssXml)) {
            $xpSS = new DOMXPath($domSS);
            $xpSS->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($xpSS->query('//x:si') as $si) {
                if (!($si instanceof DOMElement)) continue;
                $txt = '';
                // rich text 포함: 모든 <t>를 이어붙임
                foreach ($xpSS->query('.//x:t', $si) as $t) {
                    if ($t instanceof DOMElement) $txt .= $t->textContent;
                }
                $shared[] = $txt;
            }
        }
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!@$dom->loadXML($xml)) { $zip->close(); return $out; }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    // 해당 row의 cell만 읽음
    $rowNode = $xp->query('//x:sheetData//x:row[@r="' . $rowNum . '"]')->item(0);
    if (!($rowNode instanceof DOMElement)) { $zip->close(); return $out; }

    foreach ($xp->query('./x:c', $rowNode) as $c) {
        if (!($c instanceof DOMElement)) continue;
        $r = $c->getAttribute('r');
        $p = xlsx_parse_cell_ref($r);
        if (!$p) continue;
        [$colL, $rowN] = $p;
        if ((int)$rowN !== (int)$rowNum) continue;

        $ci = xlsx_col_to_index($colL);
        if ($ci < $startIdx || $ci > $endIdx) continue;

        $text = '';
        $tAttr = $c->getAttribute('t');

        if ($tAttr === 'inlineStr') {
            $t = $xp->query('./x:is/x:t', $c)->item(0);
            if ($t instanceof DOMElement) $text = $t->textContent;
        } elseif ($tAttr === 's') {
            $v = $xp->query('./x:v', $c)->item(0);
            if ($v instanceof DOMElement) {
                $idx = (int)$v->textContent;
                $text = $shared[$idx] ?? '';
            }
        } else {
            // 숫자/일반: v를 그대로 문자열로
            $v = $xp->query('./x:v', $c)->item(0);
            if ($v instanceof DOMElement) $text = $v->textContent;
        }

        $out[$ci - $startIdx] = (string)$text;
    }

    $zip->close();
    return $out;
}


function xlsx_parse_cell_ref(string $ref): ?array {
    if (!preg_match('/^([A-Z]+)(\\d+)$/', strtoupper($ref), $m)) return null;
    return [$m[1], (int)$m[2]];
}
/**
 * FAI / point_no normalize & mapping helpers
 * - 템플릿 B열(FAI) 문자열과 DB point_no 문자열을 안정적으로 매칭하기 위해 사용
 */
function normalize_fai_key(?string $s): string {
    $s = (string)($s ?? '');
    $s = trim($s);
    if ($s === '') return '';
    $s = preg_replace('/\s+/u', '', $s);                 // whitespace 제거
    $s = str_replace(["–","—","−"], "-", $s);            // 다양한 대시 통일
    $s = strtoupper($s);
    // 보고서 SPC 등에 붙는 접두어 "FAI" 제거 (예: FAI98-V1 -> 98-V1, FAI 114-1 -> 114-1)
    if (preg_match('/^FAI(?=\d)/', $s)) $s = substr($s, 3);
    return $s;
}

function fai_base_key(?string $s): string {
    $s = (string)($s ?? '');
    if ($s === '') return '';
    $s2 = preg_replace('/\([^\)]*\)/u', '', $s);         // (...) 제거 (D1/P1 등)
    return normalize_fai_key($s2);
}

/**
 * point_no 매칭용 키 변형 목록을 만든다.
 * - 절대 '-'를 제거하지 않는다. (cavity 구분자 유지)
 * - '_' <-> '-' 혼용만 보정하기 위해 "대체 변형"을 추가로 제공한다.
 *   (예: 44_P1 <-> 44-P1)
 */
function fai_key_variants(?string $s): array {
  $base = normalize_fai_key($s);
  if ($base === '') return [];

  $v = [];
  $add = function(string $x) use (&$v) {
    if ($x === '') return;
    if (!isset($v[$x])) $v[$x] = true;
  };

  // 1) 기본(정규화)
  $add($base);

  // 2) '_' <-> '-' 교환
  if (strpos($base, '_') !== false) $add(str_replace('_', '-', $base));
  if (strpos($base, '-') !== false) $add(str_replace('-', '_', $base));

  // 3) 괄호 표현 차이: 30-1(P2) <-> 30-1P2 / 30-1-P2 등
  if (strpos($base, '(') !== false || strpos($base, ')') !== false) {
    $noPar = str_replace(['(', ')'], '', $base);
    $add($noPar); // 30-1(P2) -> 30-1P2
    $add(str_replace(['(', ')'], ['-', ''], $base)); // 30-1(P2) -> 30-1-P2
  }

  // 4) 28-P1 <-> 28(P1) / 28P1
  if (preg_match('/^(\d+)-P(\d+)$/i', $base, $m)) {
    $add($m[1] . '(P' . $m[2] . ')');
    $add($m[1] . 'P' . $m[2]);
    $add($m[1] . '_P' . $m[2]);
  }

  // 5) 30-1(P2) 류: 30-1(P2) <-> 30-1-P2 / 30-1P2
  if (preg_match('/^(\d+)-(\d+)\(P(\d+)\)$/i', $base, $m)) {
    $add($m[1] . '-' . $m[2] . '-P' . $m[3]);
    $add($m[1] . '-' . $m[2] . 'P' . $m[3]);
    $add($m[1] . '_' . $m[2] . '_P' . $m[3]);
  }

  // 6) 103-V1 / 86-A3 같은 "숫자-문자숫자" 케이스는 '-' 유무가 섞여 있을 수 있어 보조로 허용
  if (preg_match('/^\d+-[A-Z]\d+$/i', $base)) {
    $add(str_replace('-', '', $base)); // 103-V1 -> 103V1
  }

  return array_keys($v);
}

function fai_base_key_variants(?string $s): array {
  $base = fai_base_key($s);
  if ($base === '') return [];

  $v = [];
  $add = function(string $x) use (&$v) {
    if ($x === '') return;
    if (!isset($v[$x])) $v[$x] = true;
  };

  $add($base);

  // '_' <-> '-' 교환
  if (strpos($base, '_') !== false) $add(str_replace('_', '-', $base));
  if (strpos($base, '-') !== false) $add(str_replace('-', '_', $base));

  // "숫자-문자숫자" 케이스만 '-' 제거 보조 허용
  if (preg_match('/^\d+-[A-Z]\d+$/i', $base)) {
    $add(str_replace('-', '', $base));
  }

  return array_keys($v);
}

function db__safe_table_name(string $table, string $fallback = 'oqc_model_point'): string {
    $t = trim($table);
    if ($t === '') return $fallback;
    // allow only [A-Za-z0-9_]
    if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) return $fallback;
    return $t;
}

function db_fetch_model_points(PDO $pdo, string $modelName, string $table = 'oqc_model_point', bool $activeOnly = true): array {
    $table = db__safe_table_name($table, 'oqc_model_point');
    $sql = "SELECT point_no, sort_order"
         . " FROM `{$table}`"
         . " WHERE model_name = ?"
         . ($activeOnly ? " AND active = 1" : "")
         . " ORDER BY sort_order ASC";

    $st = $pdo->prepare($sql);
    $st->execute([$modelName]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** 반환: [NORMALIZED_POINT_NO => true, ...] */
function db_build_point_set(PDO $pdo, string $modelName, string $table = 'oqc_model_point', bool $activeOnly = true): array {
    $rows = db_fetch_model_points($pdo, $modelName, $table, $activeOnly);
    $set = [];
    foreach ($rows as $r) {
        $p = trim((string)($r['point_no'] ?? ''));
        if ($p === '') continue;
        $k = normalize_fai_key($p);
        if ($k !== '') $set[$k] = true;
    }
    return $set;
}

// Return: [NORMALIZED_POINT_NO => true, ...]
// NG(LSL/USL 초과/미달) 판정에서 제외할 point_no 목록을 DB에서 읽어온다.
// - 테이블이 없으면 빈 배열 반환
function db_build_ng_ignore_set(PDO $pdo, string $modelName, string $table = 'oqc_ng_ignore_point', bool $activeOnly = true): array {
    $set = [];
    try {
        // table exists?
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $stmt->execute([$table]);
        $exists = (int)$stmt->fetchColumn();
        if ($exists <= 0) return $set;

        $sql = "SELECT point_no FROM `{$table}` WHERE model_name=?";
        if ($activeOnly) $sql .= " AND active=1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$modelName]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $k = normalize_fai_key((string)($r['point_no'] ?? ''));
            if ($k !== '') $set[$k] = true;
        }
    } catch (Throwable $e) {
        // ignore
        return $set;
    }
    return $set;
}

/**
 * 반환: ['full'=>[NORMALIZED_POINT_NO => row], 'base'=>[BASE_KEY => row]]
 * base는 괄호 제거로 충돌이 자주 나므로, "유일한 키"만 유지한다.
 */
function db_build_fai_row_maps(PDO $pdo, string $modelName, int $startRow = 11, string $table = 'oqc_model_point', bool $activeOnly = true): array {
    $rows = db_fetch_model_points($pdo, $modelName, $table, $activeOnly);
    $maps = ['full' => [], 'base' => []];

    $i = 0;
    $baseRow = [];
    $baseCollision = [];

    foreach ($rows as $r) {
        $p = trim((string)($r['point_no'] ?? ''));
        if ($p === '') continue;

        $i++;
        $order = (int)($r['sort_order'] ?? 0);
        if ($order <= 0) $order = $i;
        $row = $startRow + ($order - 1);

        $full = normalize_fai_key($p);
        if ($full !== '') $maps['full'][$full] = $row;

        $base = fai_base_key($p); // 괄호 제거
        if ($base !== '' && $base !== $full) {
            if (!isset($baseRow[$base])) {
                $baseRow[$base] = $row;
            } else if ($baseRow[$base] !== $row) {
                $baseCollision[$base] = true;
            }
        }
    }

    foreach ($baseRow as $k => $row) {
        if (isset($baseCollision[$k])) continue; // 충돌 키는 버림
        $maps['base'][$k] = $row;
    }

    return $maps;
}


/**
 * 템플릿 OQC 시트의 B열(FAI)을 읽어서 "FAI -> 엑셀 row" 맵을 만든다.
 * returns ['full'=>[FAI=>row], 'base'=>[FAI_BASE=>row]]
 */
// NOTE:
// 템플릿의 OQC 시트 B열에는 "포인트 목록" 외에도 다른 영역(요약/표/기타 값)에서 숫자/포인트처럼 보이는 값이
// 들어갈 수 있다. NG 필터는 "템플릿의 포인트 목록"만을 기준으로 해야 하므로,
// B열 전체를 무차별 수집하지 않고 "포인트 목록이 시작되는 구간"에서 연속 영역만 수집한다.
// - 기본 시작 행은 11(사용자 템플릿 공통)
// - 시작 이후 빈칸(셀 없음 포함)이 일정 개수 연속되면 목록 종료로 간주
function xlsx_build_fai_row_maps(string $xlsxPath, string $sheetName = 'OQC', string $faiCol = 'B', int $startRow = 11, int $blankStop = 25, int $maxScanRows = 3000): array {
    $maps = ['full' => [], 'base' => []];

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) return $maps;

    // shared strings
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false && $ssXml !== '') {
        $domSS = new DOMDocument();
        $domSS->preserveWhiteSpace = false;
        if (@$domSS->loadXML($ssXml)) {
            $xpSS = new DOMXPath($domSS);
            $xpSS->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $siNodes = $xpSS->query('//x:si');
            if ($siNodes) {
                foreach ($siNodes as $si) {
                    $tNodes = $xpSS->query('.//x:t', $si);
                    $buf = '';
                    foreach ($tNodes as $tn) $buf .= $tn->textContent;
                    $shared[] = $buf;
                }
            }
        }
    }

    $sheetPath = xlsx_get_sheet_path_by_name($zip, $sheetName);
    if (!$sheetPath) { $zip->close(); return $maps; }

    $xml = $zip->getFromName($sheetPath);
    if ($xml === false || $xml === '') { $zip->close(); return $maps; }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!@$dom->loadXML($xml)) { $zip->close(); return $maps; }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $col = strtoupper($faiCol);

    // 1) B열 셀을 row => rawValue 로 수집(희소(sparse) 구조)
    $rowVals = [];
    $nodes = $xp->query('//x:sheetData//x:c[starts-with(@r, "' . $col . '")]');
    if ($nodes) {
        foreach ($nodes as $c) {
            if (!($c instanceof DOMElement)) continue;
            $ref = strtoupper($c->getAttribute('r'));
            if (!preg_match('/^' . preg_quote($col, '/') . '(\d+)$/', $ref, $mm)) continue;
            $rowNum = (int)$mm[1];
            if ($rowNum <= 0) continue;

            $t = $c->getAttribute('t');
            $val = '';
            if ($t === 's') {
                $vNode = $xp->query('./x:v', $c)->item(0);
                if ($vNode) {
                    $idx = (int)$vNode->textContent;
                    $val = $shared[$idx] ?? '';
                }
            } elseif ($t === 'inlineStr') {
                $tNodes = $xp->query('./x:is//x:t', $c);
                if ($tNodes) {
                    $buf = '';
                    foreach ($tNodes as $tn) $buf .= $tn->textContent;
                    $val = $buf;
                }
            } else {
                $vNode = $xp->query('./x:v', $c)->item(0);
                if ($vNode) $val = $vNode->textContent;
            }

            $val = trim((string)$val);
            if ($val === '') continue;
            // 동일 row에 여러번 나오진 않지만, 혹시라도 처음 값 우선
            if (!isset($rowVals[$rowNum])) $rowVals[$rowNum] = $val;
        }
    }

    // 2) 포인트 목록 시작(startRow)부터 "연속 영역"만 스캔
    $startRow = max(1, (int)$startRow);
    $blankStop = max(5, (int)$blankStop);
    $maxScanRows = max(200, (int)$maxScanRows);

    // 스캔 상한: 실제 데이터가 있는 최대 row + 여유
    $maxRowSeen = 0;
    if ($rowVals) $maxRowSeen = max(array_keys($rowVals));
    $scanEnd = min($maxScanRows, max($startRow + 300, $maxRowSeen + 50));

    $blankCnt = 0;
    $collected = 0;
    for ($r = $startRow; $r <= $scanEnd; $r++) {
        $val = $rowVals[$r] ?? '';
        $val = trim((string)$val);
        if ($val === '') {
            // 셀이 아예 없는 row도 빈칸으로 취급
            if ($collected > 0) {
                $blankCnt++;
                if ($blankCnt >= $blankStop) break;
            }
            continue;
        }

        $blankCnt = 0;

        $norm = normalize_fai_key($val);
        if ($norm === '' || $norm === 'FAI') continue;
        // 측정 포인트처럼 보이는 값만 (숫자 포함 또는 R- 시작)
        if (!preg_match('/\d/', $norm) && strpos($norm, 'R-') !== 0) continue;

        if (!isset($maps['full'][$norm])) $maps['full'][$norm] = $r;
        $base = fai_base_key($val);
        if ($base !== '' && !isset($maps['base'][$base])) $maps['base'][$base] = $r;
        $collected++;
    }

    $zip->close();
    return $maps;
}




/**
 * Range ref parser (e.g. "AK10:BP500" or "AK10")
 * returns [startCol, startRow, endCol, endRow]
 */
function xlsx_parse_range_ref(string $ref): ?array {
    $ref = strtoupper(trim($ref));
    if ($ref === '') return null;
    if (strpos($ref, ':') === false) {
        $a = xlsx_parse_cell_ref($ref);
        if (!$a) return null;
        return [$a[0], $a[1], $a[0], $a[1]];
    }
    [$a, $b] = explode(':', $ref, 2);
    $ca = xlsx_parse_cell_ref($a);
    $cb = xlsx_parse_cell_ref($b);
    if (!$ca || !$cb) return null;
    return [$ca[0], $ca[1], $cb[0], $cb[1]];
}

function xlsx_range_intersects_block(string $ref, string $startCol, string $endCol, int $startRow, int $endRow): bool {
    $r = xlsx_parse_range_ref($ref);
    if (!$r) return false;
    [$sc, $sr, $ec, $er] = $r;

    $scI = xlsx_col_to_index($sc);
    $ecI = xlsx_col_to_index($ec);
    if ($scI > $ecI) { $tmp = $scI; $scI = $ecI; $ecI = $tmp; }

    if ($sr > $er) { $tmp = $sr; $sr = $er; $er = $tmp; }

    $bcS = xlsx_col_to_index($startCol);
    $bcE = xlsx_col_to_index($endCol);
    if ($bcS > $bcE) { $tmp = $bcS; $bcS = $bcE; $bcE = $tmp; }

    if ($er < $startRow || $sr > $endRow) return false;
    if ($ecI < $bcS || $scI > $bcE) return false;
    return true;
}

/**
 * Remove merged-cell definitions that overlap our injection block.
 * (If the block is merged, Excel will show only the top-left cell value.)
 */
function xlsx_remove_merges_in_block(DOMXPath $xp, string $startCol, string $endCol, int $startRow, int $endRow): int {
    $removed = 0;

    // namespace-safe (works regardless of prefix)
    $nodes = $xp->query('//*[local-name()="mergeCells"]/*[local-name()="mergeCell"]');
    if ($nodes) {
        foreach ($nodes as $mergeCell) {
            if (!($mergeCell instanceof DOMElement)) continue;
            $ref = $mergeCell->getAttribute('ref');
            if ($ref !== '' && xlsx_range_intersects_block($ref, $startCol, $endCol, $startRow, $endRow)) {
                $parent = $mergeCell->parentNode;
                if ($parent) $parent->removeChild($mergeCell);
                $removed++;
            }
        }
    }

    // cleanup empty mergeCells container & fix count attr
    $mcList = $xp->query('//*[local-name()="mergeCells"]');
    if ($mcList) {
        foreach ($mcList as $mc) {
            if (!($mc instanceof DOMElement)) continue;
            $childCount = 0;
            foreach ($mc->childNodes as $ch) {
                if ($ch instanceof DOMElement && $ch->localName === 'mergeCell') $childCount++;
            }
            if ($childCount <= 0) {
                $p = $mc->parentNode;
                if ($p) $p->removeChild($mc);
            } else {
                if ($mc->hasAttribute('count')) $mc->setAttribute('count', (string)$childCount);
            }
        }
    }

    return $removed;
}

/**
 * Try to resolve OQC template path even if the mapped path doesn't exist.
 * (Helps when folder/name slightly differs on a machine.)
 */
function resolve_oqc_template_path(string $part, ?string $mappedPath, string $baseDir): ?string {
    if ($mappedPath) {
        clearstatcache(true, $mappedPath);
        if (is_file($mappedPath)) return $mappedPath;
    }

    $kwMap = [
        'MEM-IR-BASE'    => ['IR BASE', 'IR_BASE', 'IRBASE'],
        'MEM-X-CARRIER'  => ['X CARRIER', 'X_CARRIER', 'XCARRIER'],
        'MEM-Y-CARRIER'  => ['Y CARRIER', 'Y_CARRIER', 'YCARRIER'],
        'MEM-Z-CARRIER'  => ['Z CARRIER', 'Z_CARRIER', 'ZCARRIER'],
        'MEM-Z-STOPPER'  => ['Z STOPPER', 'Z_STOPPER', 'ZSTOPPER'],
    ];
    $keywords = $kwMap[$part] ?? [];

    $dirs = [
        $baseDir . DIRECTORY_SEPARATOR . 'oqc templates',
        $baseDir . DIRECTORY_SEPARATOR . 'oqc_templates',
        $baseDir . DIRECTORY_SEPARATOR . 'oqc-templates',
        $baseDir,
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        $list = @scandir($dir);
        if (!$list) continue;

        foreach ($list as $fn) {
            if ($fn === '.' || $fn === '..') continue;
            if (!preg_match('/\.xlsx$/i', $fn)) continue;

            $full = $dir . DIRECTORY_SEPARATOR . $fn;
            if (!is_file($full)) continue;

            $upper = strtoupper($fn);
            foreach ($keywords as $kw) {
                $kw = strtoupper($kw);
                if ($kw !== '' && strpos($upper, $kw) !== false) {
                    return $full;
                }
            }
        }
    }
    return null;
}

function xlsx_col_to_index(string $col): int {
    $col = strtoupper($col);
    $n = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n;
}

function xlsx_index_to_col(int $index): string {
    $s = '';
    while ($index > 0) {
        $m = ($index - 1) % 26;
        $s = chr(65 + $m) . $s;
        $index = (int)(($index - 1) / 26);
    }
    return $s;
}

function xlsx_set_cell_value(DOMDocument $dom, DOMElement $c, $value): void {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    // 기존 value 노드 제거 (v, is). (f=수식은 템플릿에 거의 없지만, 있으면 그대로 두고 싶다면 여기서 제거하지 말 것)
    $toRemove = [];
    foreach ($c->childNodes as $ch) {
        if ($ch instanceof DOMElement) {
            $ln = $ch->localName;
            if ($ln === 'v' || $ln === 'is') $toRemove[] = $ch;
        }
    }
    foreach ($toRemove as $rm) $c->removeChild($rm);

    if ($value === null || $value === '') {
        // 비움: 타입만 정리 (Excel은 값 노드 없으면 빈 셀)
        if ($c->hasAttribute('t')) {
            // t="s" 남아도 큰 문제는 없지만, 깔끔히 제거
            $c->removeAttribute('t');
        }
        return;
    }

    if (is_numeric($value)) {
        if ($c->hasAttribute('t')) $c->removeAttribute('t');
        $v = $dom->createElementNS($ns, 'v', (string)$value);
        $c->appendChild($v);
        return;
    }

    // 문자열: sharedStrings 건드리지 않으려고 inlineStr 사용
    $c->setAttribute('t', 'inlineStr');
    $is = $dom->createElementNS($ns, 'is');
    $t  = $dom->createElementNS($ns, 't');
    $sv = (string)$value;
    if (preg_match('/^\\s|\\s$/u', $sv)) {
        $t->setAttribute('xml:space', 'preserve');
    }
    $t->appendChild($dom->createTextNode($sv));
    $is->appendChild($t);
    $c->appendChild($is);
}

function xlsx_ensure_row(DOMDocument $dom, DOMXPath $xp, int $rowNum): DOMElement {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rowNode = $xp->query('//x:sheetData/x:row[@r="' . $rowNum . '"]')->item(0);
    if ($rowNode instanceof DOMElement) return $rowNode;

    $sheetData = $xp->query('//x:sheetData')->item(0);
    if (!$sheetData) throw new RuntimeException('sheetData not found');

    $rowNode = $dom->createElementNS($ns, 'row');
    $rowNode->setAttribute('r', (string)$rowNum);
    $sheetData->appendChild($rowNode);
    return $rowNode;
}

function xlsx_ensure_cell(DOMDocument $dom, DOMXPath $xp, DOMElement $rowNode, string $cellRef, ?string $styleS = null): DOMElement {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $c = $xp->query('x:c[@r="' . $cellRef . '"]', $rowNode)->item(0);
    if ($c instanceof DOMElement) return $c;

    $c = $dom->createElementNS($ns, 'c');
    $c->setAttribute('r', $cellRef);
    if ($styleS !== null && $styleS !== '') {
        $c->setAttribute('s', $styleS);
    }
    $rowNode->appendChild($c);
    return $c;
}

/**
 * OQC 템플릿 파일을 "복사"한 뒤,
 * 지정 시트(OQC)의 AK10~BP10(툴캐비티), AK11~BP??(데이터)만 값 교체.
 * (도형/줄/차트/매크로 등 템플릿 레이아웃 보존 목적)
 */
function xlsx_mark_full_calc_on_load(ZipArchive $zip): void {
    $wbPath = 'xl/workbook.xml';
    $xml = $zip->getFromName($wbPath);
    if ($xml === false || $xml === '') return;

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    if (!@ $dom->loadXML($xml)) return;

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wb = $xp->query('/x:workbook')->item(0);
    if (!($wb instanceof DOMElement)) return;

    $calcPr = $xp->query('/x:workbook/x:calcPr')->item(0);
    if (!($calcPr instanceof DOMElement)) {
        $calcPr = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'calcPr');
        $wb->appendChild($calcPr);
    }
    $calcPr->setAttribute('calcMode', 'auto');
    $calcPr->setAttribute('fullCalcOnLoad', '1');
    $calcPr->setAttribute('forceFullCalc', '1');
    $calcPr->setAttribute('calcCompleted', '0');

    $zip->addFromString($wbPath, $dom->saveXML());
}

function patch_oqc_xlsx_preserve_template(
    string $templatePath,
    string $outPath,
    array $sourceTags,
    array $toolPairs,
    array $dataByRow,
    string $sheetName = 'OQC',
    string $startCol = 'AK',
    string $endCol = 'BP',
    int $sourceRow = 6,
    int $toolRow = 10,
    int $dataStartRow = 11
): array {
    if (!@copy($templatePath, $outPath)) {
        throw new RuntimeException("템플릿 복사 실패: $templatePath → $outPath");
    }

    $zip = new ZipArchive();
    if ($zip->open($outPath) !== true) {
        throw new RuntimeException("XLSX ZIP 열기 실패: $outPath");
    }

    $sheetPath = xlsx_get_sheet_path_by_name($zip, $sheetName);
    if (!$sheetPath) {
        $zip->close();
        throw new RuntimeException("시트 경로 찾기 실패: $sheetName");
    }

    $sheetXml = $zip->getFromName($sheetPath);
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException("시트 XML 읽기 실패: $sheetPath");
    }

    $endRow = xlsx_get_dimension_end_row($sheetXml);
    // 과도한 범위 방지 (템플릿 dimension이 1,048,576 같은 걸로 잡혀 있으면 폭발함)
    if ($endRow < $dataStartRow) $endRow = $dataStartRow;
    if ($endRow > 20000) $endRow = 20000;

    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    if (!$dom->loadXML($sheetXml)) {
        $zip->close();
        throw new RuntimeException("시트 XML 파싱 실패: $sheetPath");
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('x', $ns);

    $startIdx = xlsx_col_to_index($startCol);
    $endIdx   = xlsx_col_to_index($endCol);

    // 스타일 복제용: AK6 / AK10 / AK11 셀의 style index(s)
    $styleSrc  = null;
    $styleTool = null;
    $styleData = null;

    $cSrc = $xp->query('//x:c[@r="' . $startCol . $sourceRow . '"]')->item(0);
    if ($cSrc instanceof DOMElement && $cSrc->hasAttribute('s')) $styleSrc = $cSrc->getAttribute('s');

    $cTool = $xp->query('//x:c[@r="' . $startCol . $toolRow . '"]')->item(0);
    if ($cTool instanceof DOMElement && $cTool->hasAttribute('s')) $styleTool = $cTool->getAttribute('s');

    $cData = $xp->query('//x:c[@r="' . $startCol . $dataStartRow . '"]')->item(0);
    if ($cData instanceof DOMElement && $cData->hasAttribute('s')) $styleData = $cData->getAttribute('s');

    // 컬럼별 스타일 맵(테두리/서식 유지)
    $getStyleMap = function(int $rowNum) use ($xp, $startIdx, $endIdx): array {
        $map = [];
        $nodes = $xp->query('//x:sheetData//x:row[@r="' . $rowNum . '"]/x:c');
        if ($nodes) {
            foreach ($nodes as $node) {
                if (!($node instanceof DOMElement)) continue;
                $r = strtoupper($node->getAttribute('r'));
                if ($r === '') continue;
                if (!preg_match('/^([A-Z]+)\d+$/', $r, $mm)) continue;
                $ci = xlsx_col_to_index($mm[1]);
                if ($ci < $startIdx || $ci > $endIdx) continue;
                if ($node->hasAttribute('s')) $map[$ci] = $node->getAttribute('s');
            }
        }
        return $map;
    };

    $styleMapSrc  = $getStyleMap($sourceRow);
    $styleMapTool = $getStyleMap($toolRow);
    $styleMapData = $getStyleMap($dataStartRow);


    // 1) 기존 값 제거: (AK~BP) 범위에서
    //    - sourceRow
    //    - toolRow ~ endRow
    // 값(v/is)만 비움(수식(f)은 건드리지 않음)
    foreach ($xp->query('//x:sheetData//x:c') as $c) {
        if (!($c instanceof DOMElement)) continue;
        $r = $c->getAttribute('r');
        $p = xlsx_parse_cell_ref($r);
        if (!$p) continue;
        [$colL, $rowN] = $p;
        $colI = xlsx_col_to_index($colL);
        if ($colI < $startIdx || $colI > $endIdx) continue;
        if (!($rowN == $sourceRow || ($rowN >= $toolRow && $rowN <= $endRow))) continue;

        xlsx_set_cell_value($dom, $c, '');
    }

    $width = ($endIdx - $startIdx + 1);

    // 2) Source row 채우기 (AK6:BP6)
    $rowNode = xlsx_ensure_row($dom, $xp, $sourceRow);
    for ($i = 0; $i < $width; $i++) {
        $col = xlsx_index_to_col($startIdx + $i);
        $ref = $col . $sourceRow;
        $val = $sourceTags[$i] ?? '';
        $styleS = $styleMapSrc[$startIdx + $i] ?? $styleSrc;
        $c = xlsx_ensure_cell($dom, $xp, $rowNode, $ref, $styleS);
        xlsx_set_cell_value($dom, $c, $val);
    }

    
        // 2.5) KIND 라벨 row: 템플릿 원본 유지(수정 안 함)

// 3) Tool row 채우기 (AK10:BP10)
    $rowNode = xlsx_ensure_row($dom, $xp, $toolRow);
    $toolCount = 0;
    for ($i = 0; $i < $width; $i++) {
        $col = xlsx_index_to_col($startIdx + $i);
        $ref = $col . $toolRow;
        $val = $toolPairs[$i] ?? '';
        $styleS = $styleMapTool[$startIdx + $i] ?? $styleTool;
        $c = xlsx_ensure_cell($dom, $xp, $rowNode, $ref, $styleS);
        xlsx_set_cell_value($dom, $c, $val);
        if ($val !== '') $toolCount++;
    }

    // 4) 데이터 채우기 (row_index 그대로)
    $dataRowsTouched = [];
    foreach ($dataByRow as $rowNum => $vals) {
        $rowNum = (int)$rowNum;
        if ($rowNum < $dataStartRow || $rowNum > $endRow) continue;
        $rowNode = xlsx_ensure_row($dom, $xp, $rowNum);
        for ($i = 0; $i < $width; $i++) {
            $col = xlsx_index_to_col($startIdx + $i);
            $ref = $col . $rowNum;
            $val = $vals[$i] ?? '';
            if ($val === '' || $val === null) continue;
            $styleS = $styleMapData[$startIdx + $i] ?? $styleData;
            $c = xlsx_ensure_cell($dom, $xp, $rowNode, $ref, $styleS);
            xlsx_set_cell_value($dom, $c, $val);
        }
        $dataRowsTouched[$rowNum] = true;
    }

    $newXml = $dom->saveXML();
    $zip->addFromString($sheetPath, $newXml);
    // OQC 템플릿 상단 LET 수식(AK2/AK4/AK5 등) 캐시값 강제 재계산
    xlsx_mark_full_calc_on_load($zip);
    $zip->close();

    return [
        'sheetPath' => $sheetPath,
        'endRow' => $endRow,
        'toolCount' => $toolCount,
        'dataRows' => count($dataRowsTouched),
    ];
}
/** 스타일 복제를 chunk로 쪼개서 적용 */
function duplicate_style_chunked($sheet, string $srcRange, int $startRow, int $endRow, string $colStart, string $colEnd, int $chunk): void {
    if ($endRow < $startRow) return;
    $srcStyle = $sheet->getStyle($srcRange);
    for ($r = $startRow; $r <= $endRow; $r += $chunk) {
        $to = min($endRow, $r + $chunk - 1);
        $sheet->duplicateStyle($srcStyle, "{$colStart}{$r}:{$colEnd}{$to}");
    }
}

// ────────────────────────────────────────────────────────────
// Report UX: set active tab + select A1 on each sheet (ZIP/XML patch)
// - Keeps styles/merges intact (no PhpSpreadsheet save())
// - $firstSheetName: sheet name to show first (e.g. "Waiver Summary")
// ────────────────────────────────────────────────────────────
function xlsx_set_active_sheet_and_a1(string $xlsxPath, string $firstSheetName = 'Waiver Summary'): void {
    if (!is_file($xlsxPath)) return;

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) return;

    try {
        // Load workbook.xml
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) return;

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($workbookXml)) return;

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        // Determine activeTab index by sheet name, and resolve the corresponding worksheet XML path
        $sheets = $xp->query('//x:sheets/x:sheet');
        $activeTab = 0;
        $activeSheetRid = '';
        if ($sheets && $sheets->length > 0) {
            for ($i = 0; $i < $sheets->length; $i++) {
                /** @var DOMElement $sh */
                $sh = $sheets->item($i);
                $nm = $sh->getAttribute('name');
                if ($nm === $firstSheetName) {
                    $activeTab = $i;
                    // r:id attribute can be accessed via getAttribute("r:id") even with namespaces preserved
                    $activeSheetRid = $sh->getAttribute('r:id');
                    break;
                }
            }
        }

        // Resolve active worksheet path via workbook rels (so we can control tabSelected to avoid "Group" mode)
        $activeSheetPath = '';
        if ($activeSheetRid !== '') {
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($relsXml !== false && $relsXml !== '') {
                $rdom = new DOMDocument();
                $rdom->preserveWhiteSpace = true;
                $rdom->formatOutput = false;
                if (@$rdom->loadXML($relsXml)) {
                    $rx = new DOMXPath($rdom);
                    $rx->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
                    $rels = $rx->query('//r:Relationship[@Id="' . htmlspecialchars($activeSheetRid, ENT_QUOTES) . '"]');
                    if ($rels && $rels->length > 0) {
                        /** @var DOMElement $rel */
                        $rel = $rels->item(0);
                        $target = $rel->getAttribute('Target');
                        $target = ltrim($target, '/');
                        if (stripos($target, 'xl/') === 0) $activeSheetPath = $target;
                        else $activeSheetPath = 'xl/' . $target;
                    }
                }
            }
        }

        // Ensure workbookView exists and set activeTab
        $wv = $xp->query('//x:bookViews/x:workbookView')->item(0);
        if (!$wv) {
            // create <bookViews><workbookView/></bookViews> under <workbook>
            $workbookNode = $xp->query('/x:workbook')->item(0);
            if ($workbookNode) {
                $bookViews = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'bookViews');
                $workbookView = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'workbookView');
                $bookViews->appendChild($workbookView);
                // Insert bookViews near start (after fileVersion if present)
                $firstChild = $workbookNode->firstChild;
                $workbookNode->insertBefore($bookViews, $firstChild);
                $wv = $workbookView;
            }
        }
        if ($wv instanceof DOMElement) {
            $wv->setAttribute('activeTab', (string)$activeTab);
        }

        // Write back workbook.xml
        $zip->addFromString('xl/workbook.xml', $dom->saveXML());

        // Patch each worksheet selection to A1 and ensure only ONE sheet is tabSelected
        // (If multiple worksheets have tabSelected="1", Excel opens in "Group" mode.)
        $wsPrefix = 'xl/worksheets/';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $name = $stat['name'];
            if (strpos($name, $wsPrefix) !== 0) continue;
            if (substr($name, -4) !== '.xml') continue;

            $wsXml = $zip->getFromName($name);
            if ($wsXml === false) continue;

            $wd = new DOMDocument();
            $wd->preserveWhiteSpace = true;
            $wd->formatOutput = false;
            if (!@$wd->loadXML($wsXml)) continue;

            $wx = new DOMXPath($wd);
            $wx->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            /** @var DOMElement|null $sheetView */
            $sheetView = $wx->query('//x:sheetViews/x:sheetView')->item(0);
            if (!$sheetView) {
                $worksheetNode = $wx->query('/x:worksheet')->item(0);
                if (!$worksheetNode) { continue; }
                $sheetViews = $wd->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'sheetViews');
                $sheetView  = $wd->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'sheetView');
                $sheetView->setAttribute('workbookViewId', '0');
                $sheetViews->appendChild($sheetView);
                // Insert before sheetFormatPr if exists, else at top
                $ref = $wx->query('/x:worksheet/x:sheetFormatPr')->item(0);
                $worksheetNode->insertBefore($sheetViews, $ref ?: $worksheetNode->firstChild);
            }

            // Set view top-left / selection
            if ($sheetView instanceof DOMElement) {
                $sheetView->setAttribute('topLeftCell', 'A1');

                // Clear any existing tabSelected on all sheets
                if ($sheetView->hasAttribute('tabSelected')) {
                    $sheetView->removeAttribute('tabSelected');
                }
                // Set tabSelected only on the resolved active sheet (prevents "Group" selection)
                if ($activeSheetPath !== '' && $name === $activeSheetPath) {
                    $sheetView->setAttribute('tabSelected', '1');
                }
                /** @var DOMElement|null $sel */
                $sel = $wx->query('.//x:selection', $sheetView)->item(0);
                if (!$sel) {
                    $sel = $wd->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'selection');
                    $sheetView->appendChild($sel);
                }
                if ($sel instanceof DOMElement) {
                    $sel->setAttribute('activeCell', 'A1');
                    $sel->setAttribute('sqref', 'A1');
                }
            }

            $zip->addFromString($name, $wd->saveXML());
        }

    } finally {
        $zip->close();
    }
}
