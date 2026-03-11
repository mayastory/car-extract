<?php
function ensure_report_finish_table(PDO $pdo): void {
    // 1) create if not exists (최신 스키마)
    $createSql = "
CREATE TABLE IF NOT EXISTS report_finish (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_date        DATE NOT NULL,
  to_date          DATE NOT NULL,
  ship_to          VARCHAR(255) NOT NULL,
  finished_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  parts_json       TEXT NULL,
  pdf_rel          VARCHAR(255) NULL,
  pdf_name         VARCHAR(255) NULL,
  oqc_new_count    INT NOT NULL DEFAULT 0,
  oqc_emg_count    INT NOT NULL DEFAULT 0,
  is_canceled      TINYINT(1) NOT NULL DEFAULT 0,
  canceled_at      DATETIME NULL,
  canceled_by      VARCHAR(80) NULL,
  cancel_note      VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
    try {
        $pdo->exec($createSql);
    } catch (Throwable $e) {
        error_log('[report_finish] create failed: ' . $e->getMessage());
        // 생성 실패해도 아래 마이그레이션 시도는 스킵
        return;
    }

    // 2) 마이그레이션(예전 report_finish가 이미 존재하지만 컬럼이 부족한 경우)
    try {
        $cols = get_columns_map($pdo, 'report_finish');
        if (!$cols) return;

        $alts = [];

        if (!isset($cols['parts_json']))    $alts[] = "ADD COLUMN parts_json TEXT NULL AFTER finished_at";
        if (!isset($cols['pdf_rel']))       $alts[] = "ADD COLUMN pdf_rel VARCHAR(255) NULL AFTER parts_json";
        if (!isset($cols['pdf_name']))      $alts[] = "ADD COLUMN pdf_name VARCHAR(255) NULL AFTER pdf_rel";

        if (!isset($cols['oqc_new_count'])) $alts[] = "ADD COLUMN oqc_new_count INT NOT NULL DEFAULT 0 AFTER pdf_name";
        if (!isset($cols['oqc_emg_count'])) $alts[] = "ADD COLUMN oqc_emg_count INT NOT NULL DEFAULT 0 AFTER oqc_new_count";

        if (!isset($cols['is_canceled']))   $alts[] = "ADD COLUMN is_canceled TINYINT(1) NOT NULL DEFAULT 0 AFTER oqc_emg_count";
        if (!isset($cols['canceled_at']))   $alts[] = "ADD COLUMN canceled_at DATETIME NULL AFTER is_canceled";
        if (!isset($cols['canceled_by']))   $alts[] = "ADD COLUMN canceled_by VARCHAR(80) NULL AFTER canceled_at";
        if (!isset($cols['cancel_note']))   $alts[] = "ADD COLUMN cancel_note VARCHAR(255) NULL AFTER canceled_by";

        if ($alts) {
            $pdo->exec("ALTER TABLE report_finish " . implode(', ', $alts));
        }
    } catch (Throwable $e) {
        // 마이그레이션 실패해도 전체 동작을 막지는 않음
        error_log('[report_finish] migrate failed: ' . $e->getMessage());
    }
}

function report_finish_cols(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = get_columns_map($pdo, 'report_finish');
    return $cache;
}

/**
 * Report file storage (viewer용)
 * - 발행(report_finish.id) 단위로 exports/reports/rf_{id}/ 아래에 결과 XLSX/ZIP(선택)을 보관
 * - 취소 시 해당 폴더를 같이 삭제
 */
function report_files_rel(int $id): string {
  return 'exports/reports/rf_' . $id;
}
function report_files_abs(int $id): string {
  return dirname(__DIR__) . DIRECTORY_SEPARATOR . report_files_rel($id);
}
function report_files_delete(int $id): bool {
  $dir = report_files_abs($id);
  if (!is_dir($dir)) return true;
  try {
    $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
    $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($ri as $f) {
      /** @var SplFileInfo $f */
      $p = $f->getPathname();
      if ($f->isDir()) @rmdir($p);
      else @unlink($p);
    }
    @rmdir($dir);
    return !is_dir($dir);
  } catch (Throwable $e) {
    return false;
  }
}
function report_files_store(int $id, array $createdFiles, array $createdOqcFiles, array $meta = []): array {
  $dstAbs = report_files_abs($id);
  $dstRel = report_files_rel($id);

  if (!is_dir(dirname($dstAbs))) @mkdir(dirname($dstAbs), 0775, true);
  if (!is_dir($dstAbs)) @mkdir($dstAbs, 0775, true);

  $copiedMain = 0;
  foreach ($createdFiles as $it) {
    $src = null; $name = null;
    if (is_array($it)) {
      $src = $it['path'] ?? ($it[0] ?? null);
      $name = $it['name'] ?? ($it[1] ?? null);
    } else if (is_string($it)) {
      $src = $it;
      $name = basename($it);
    }
    if (!$src || !is_file($src)) continue;
    if (!$name) $name = basename($src);
    $dst = $dstAbs . DIRECTORY_SEPARATOR . basename($name);
    if (@copy($src, $dst)) $copiedMain++;
  }

  $oqcAbs = $dstAbs . DIRECTORY_SEPARATOR . 'oqc';
  if (!is_dir($oqcAbs)) @mkdir($oqcAbs, 0775, true);
  $copiedOqc = 0;
  foreach ($createdOqcFiles as $src) {
    if (!is_string($src) || !is_file($src)) continue;
    $dst = $oqcAbs . DIRECTORY_SEPARATOR . basename($src);
    if (@copy($src, $dst)) $copiedOqc++;
  }

  // meta.json
  $meta2 = $meta;
  $meta2['report_id'] = $id;
  $meta2['stored_at'] = date('c');
  @file_put_contents($dstAbs . DIRECTORY_SEPARATOR . 'meta.json',
    json_encode($meta2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
  );

  return [
    'ok' => (is_dir($dstAbs)),
    'dir_rel' => $dstRel,
    'main_count' => $copiedMain,
    'oqc_count' => $copiedOqc,
  ];
}


function report_finish_insert(PDO $pdo, array $row): void {
    ensure_report_finish_table($pdo);
    $cols = report_finish_cols($pdo);
    if (!$cols) return;

    // 실제 존재하는 컬럼만 insert (DB 스키마가 조금 달라도 깨지지 않게)
    $allowed = [
        'from_date','to_date','ship_to','finished_at',
        'parts_json','pdf_rel','pdf_name','oqc_new_count','oqc_emg_count',
        'is_canceled','canceled_at','canceled_by','cancel_note'
    ];

    $fields = [];
    $ph = [];
    $params = [];
    foreach ($allowed as $k) {
        if (!isset($cols[$k])) continue;              // 해당 컬럼이 DB에 없으면 skip
        if (!array_key_exists($k, $row)) continue;    // 값이 없으면 skip
        $fields[] = "`{$cols[$k]}`";
        $ph[] = ":" . $k;
        $params[":" . $k] = $row[$k];
    }

    if (!$fields) return;

    $sql = "INSERT INTO report_finish (" . implode(',', $fields) . ") VALUES (" . implode(',', $ph) . ")";
    $st = $pdo->prepare($sql);
    $st->execute($params);
}

function report_finish_get(PDO $pdo, int $id): ?array {
    ensure_report_finish_table($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM report_finish WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// report_finish.pdf_rel 경로의 PDF 파일을 서버에서 삭제하고,
// DB의 pdf_rel/pdf_name도 NULL로 비움 (취소 시 사용)
function report_finish_delete_pdf(PDO $pdo, int $id, string $baseDir): array {
    ensure_report_finish_table($pdo);

    $rf = report_finish_get($pdo, $id);
    if (!$rf) return ['ok' => false, 'msg' => '발행 내역이 없습니다.'];

    $pdfRel = (string)($rf['pdf_rel'] ?? '');
    if ($pdfRel === '') {
        // DB만 정리
        try {
            $pdo->prepare("UPDATE report_finish SET pdf_rel=NULL, pdf_name=NULL WHERE id=:id")
                ->execute([':id' => $id]);
        } catch (Throwable $e) {}
        return ['ok' => true, 'deleted' => false, 'msg' => 'PDF 없음'];
    }

    $baseDir = rtrim($baseDir, '/\\');
    $abs = $baseDir . DIRECTORY_SEPARATOR . ltrim($pdfRel, '/\\');

    // 안전장치: exports/ 아래만 삭제 허용
    $exportsBase = $baseDir . DIRECTORY_SEPARATOR . 'exports';
    $exportsReal = realpath($exportsBase) ?: $exportsBase;
    $absReal = realpath($abs) ?: $abs;

    $deleted = false;
    if (is_file($absReal) && strpos($absReal, $exportsReal) === 0) {
        $deleted = @unlink($absReal);
    }

    try {
        $pdo->prepare("UPDATE report_finish SET pdf_rel=NULL, pdf_name=NULL WHERE id=:id")
            ->execute([':id' => $id]);
    } catch (Throwable $e) {}

    return ['ok' => true, 'deleted' => $deleted, 'path' => $pdfRel];
}

function report_finish_list_month(PDO $pdo, string $ym): array {
    ensure_report_finish_table($pdo);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

    $start = $ym . '-01';
    $startTs = strtotime($start);
    $end = date('Y-m-d', strtotime('+1 month', $startTs)); // exclusive

    // finished_at가 있으면 finished_at 기준, 없으면 created_at 대체
    $cols = report_finish_cols($pdo);
    $dtCol = $cols['finished_at'] ?? ($cols['created_at'] ?? null);

    if (!$dtCol) return [];

    $sql = "
        SELECT *
        FROM report_finish
        WHERE `{$dtCol}` >= :s
          AND `{$dtCol}` <  :e
        ORDER BY `{$dtCol}` DESC, id DESC
        LIMIT 500
    ";
    try {
        $st = $pdo->prepare($sql);
        $st->execute([':s' => $start . ' 00:00:00', ':e' => $end . ' 00:00:00']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function report_finish_mark_canceled(PDO $pdo, int $id, ?string $by = null, ?string $note = null): void {
    ensure_report_finish_table($pdo);
    $cols = report_finish_cols($pdo);
    if (!$cols) return;

    $set = [];
    $params = [':id' => $id];

    if (isset($cols['is_canceled'])) { $set[] = "`{$cols['is_canceled']}`=1"; }
    if (isset($cols['canceled_at'])) { $set[] = "`{$cols['canceled_at']}`=NOW()"; }
    if ($by !== null && isset($cols['canceled_by'])) { $set[] = "`{$cols['canceled_by']}`=:by"; $params[':by']=$by; }
    if ($note !== null && isset($cols['cancel_note'])) { $set[] = "`{$cols['cancel_note']}`=:note"; $params[':note']=$note; }

    if (!$set) return;

    $sql = "UPDATE report_finish SET " . implode(',', $set) . " WHERE id=:id";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
    } catch (Throwable $e) {}
}

/**
 * OQC 사용 마킹 롤백
 * - meas_date = :date   → meas_date = NULL
 * - meas_date2 = :date  → meas_date2 = NULL
 * - parts 지정 시 part_name IN (...) 조건 추가(가능하면)
 * 반환: ['meas_date'=>int, 'meas_date2'=>int, 'date'=>YYYY-MM-DD, 'parts'=>[...] ]
 */
function oqc_rollback_marks(PDO $pdo, string $dateYmd, array $parts = [], bool $dryRun = false): array {
    $out = [
        'date' => $dateYmd,
        'parts' => array_values($parts),
        'dry' => $dryRun,
        'meas_date' => 0,
        'meas_date2' => 0,
    ];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) return $out;

    $hCols = get_columns_map($pdo, 'oqc_header');
    if (!$hCols) return $out;

    $colMeas  = pick_col($hCols, ['meas_date']);
    $colMeas2 = pick_col($hCols, ['meas_date2']);
    $colPart  = pick_col($hCols, ['part_name','productname','product_name']);

    $wherePart = '';
    $paramsPart = [];
    if ($parts && $colPart) {
        $ph = [];
        foreach (array_values($parts) as $i => $pn) {
            $k = ":p{$i}";
            $ph[] = $k;
            $paramsPart[$k] = $pn;
        }
        if ($ph) $wherePart = " AND `{$colPart}` IN (" . implode(',', $ph) . ")";
    }

    $doOne = function(?string $col, string $key) use ($pdo, $dateYmd, $wherePart, $paramsPart, $dryRun, &$out) {
        if (!$col) return;
        if ($dryRun) {
            $sql = "SELECT COUNT(*) FROM oqc_header WHERE `{$col}` = :d{$wherePart}";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([':d' => $dateYmd], $paramsPart));
            $out[$key] = (int)$st->fetchColumn();
        } else {
            $sql = "UPDATE oqc_header SET `{$col}` = NULL WHERE `{$col}` = :d{$wherePart}";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([':d' => $dateYmd], $paramsPart));
            $out[$key] = $st->rowCount();
        }
    };

    $doOne($colMeas, 'meas_date');
    $doOne($colMeas2, 'meas_date2');

    return $out;
}


// ─────────────────────────────
function rollback_oqc_by_issue_date(PDO $pdo, string $issueYmd, array $parts = []): array {
    // parts가 있으면 part_name IN (...) 으로 한정
    $parts = array_values(array_unique(array_filter($parts, function($v){
        return is_string($v) && $v !== '';
    })));

    $updated1 = 0;
    $updated2 = 0;

    // meas_date
    if (!empty($parts)) {
        $in = implode(',', array_fill(0, count($parts), '?'));
        $sql = "UPDATE oqc_header SET meas_date=NULL WHERE meas_date = ? AND part_name IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$issueYmd], $parts));
        $updated1 = $stmt->rowCount();
    } else {
        $stmt = $pdo->prepare("UPDATE oqc_header SET meas_date=NULL WHERE meas_date = :d");
        $stmt->execute([':d' => $issueYmd]);
        $updated1 = $stmt->rowCount();
    }

    // meas_date2
    if (!empty($parts)) {
        $in = implode(',', array_fill(0, count($parts), '?'));
        $sql = "UPDATE oqc_header SET meas_date2=NULL WHERE meas_date2 = ? AND part_name IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$issueYmd], $parts));
        $updated2 = $stmt->rowCount();
    } else {
        $stmt = $pdo->prepare("UPDATE oqc_header SET meas_date2=NULL WHERE meas_date2 = :d");
        $stmt->execute([':d' => $issueYmd]);
        $updated2 = $stmt->rowCount();
    }

    return ['meas_date' => $updated1, 'meas_date2' => $updated2];
}

function cancel_report_finish(PDO $pdo, int $id, string $byUser = 'system'): array {
    ensure_report_finish_table($pdo);

    $stmt = $pdo->prepare("SELECT * FROM report_finish WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $rf = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rf) return ['ok' => false, 'msg' => '발행 내역이 없습니다.'];
    if ((int)($rf['is_canceled'] ?? 0) === 1) return ['ok' => false, 'msg' => '이미 취소된 내역입니다.'];

    $issueYmd = (string)($rf['to_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueYmd)) {
        // fallback: finished_at(구버전 호환)
        $issueYmd = substr((string)($rf['finished_at'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueYmd)) return ['ok' => false, 'msg' => '기준일(to_date) 파싱 실패'];
    }

    $parts = [];
    $pj = $rf['parts_json'] ?? null;
    if (is_string($pj) && $pj !== '') {
        $tmp = json_decode($pj, true);
        if (is_array($tmp)) {
            // parts_json 호환:
            //  - 옛날: ["MEM-IR-BASE","MEM-X-CARRIER",...]
            //  - 최신: {"parts":[{"part":"MEM-IR-BASE","ship_qty":123},...], "total_ship_qty":...}
            if (array_keys($tmp) === range(0, count($tmp) - 1)) {
                // numeric array
                foreach ($tmp as $v) {
                    if (is_string($v) && $v !== '') $parts[] = $v;
                    elseif (is_array($v) && isset($v['part']) && is_string($v['part'])) $parts[] = $v['part'];
                }
            } elseif (isset($tmp['parts']) && is_array($tmp['parts'])) {
                foreach ($tmp['parts'] as $it) {
                    if (is_string($it) && $it !== '') $parts[] = $it;
                    elseif (is_array($it) && isset($it['part']) && is_string($it['part'])) $parts[] = $it['part'];
                }
            }
            $parts = array_values(array_unique(array_filter($parts, function($v){
                return is_string($v) && $v !== '';
            })));
        }
    }

    try {
        $pdo->beginTransaction();

        $rb = rollback_oqc_by_issue_date($pdo, $issueYmd, $parts);

        $up = $pdo->prepare("
            UPDATE report_finish
            SET is_canceled=1, canceled_at=NOW(), canceled_by=:by, cancel_note=:note
            WHERE id=:id
        ");
        $up->execute([
            ':by'   => $byUser,
            ':note' => "rollback: {$issueYmd}",
            ':id'   => $id,
        ]);

        $pdo->commit();

        $delOk = report_files_delete($id);
        $out = ['ok' => true, 'msg' => '취소 완료', 'rollback' => $rb, 'issue_date' => $issueYmd, 'files_deleted' => $delOk];
        if (!$delOk) $out['warn'] = '서버 파일 삭제 실패(권한/잠금 확인)';
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => '취소 실패: ' . $e->getMessage()];
    }
}


// ─────────────────────────────
// OQC Rowdata ↔ 성적서 포인트 매핑(자동 채움용)
// - 매핑.xlsx(IR/XC/YC/ZC/ZS)에서 추출하여 코드에 내장
// - pairs: ['r'=>성적서 포인트, 'o'=>OQC Rowdata 포인트]
// ─────────────────────────────
if (!isset($GLOBALS['OQC_REPORT_POINT_MAP'])) {
$OQC_REPORT_POINT_MAP = [
        'MEM-IR-BASE' => [
            'pairs' => [
                ['r'=>'1','o'=>'1'],
                ['r'=>'3-1','o'=>'3-D3'],
                ['r'=>'3-2','o'=>'3-D4'],
                ['r'=>'4','o'=>'4'],
                ['r'=>'5','o'=>'5'],
                ['r'=>'6-1','o'=>'6-D1'],
                ['r'=>'6-2','o'=>'6-D2'],
                ['r'=>'8-1','o'=>'8-1 (V2)'],
                ['r'=>'8-2','o'=>'8-2 (V1)'],
                ['r'=>'8-3','o'=>'8-3 (U)'],
                ['r'=>'9','o'=>'9'],
                ['r'=>'10','o'=>'10'],
                ['r'=>'13','o'=>'13'],
                ['r'=>'14_1','o'=>'14-P1'],
                ['r'=>'14_2','o'=>'14-P2'],
                ['r'=>'17-1','o'=>'17-P1'],
                ['r'=>'17-2','o'=>'17-P2'],
                ['r'=>'17-3','o'=>'17-P3'],
                ['r'=>'18','o'=>'18'],
                ['r'=>'19','o'=>'19'],
                ['r'=>'20-1','o'=>'20-P1'],
                ['r'=>'20-2','o'=>'20-P2'],
                ['r'=>'20-3','o'=>'20-P3'],
                ['r'=>'21-1 (P73)','o'=>'21-P73'],
                ['r'=>'21-2 (P52)','o'=>'21-P52'],
                ['r'=>'21-3 (P36)','o'=>'21-P36'],
                ['r'=>'21-4 (P21)','o'=>'21-P21'],
                ['r'=>'21-5 (P2)','o'=>'21-P2'],
                ['r'=>'21-6 (P1)','o'=>'21-P1'],
                ['r'=>'22-1','o'=>'22-S1'],
                ['r'=>'22-2','o'=>'22-S2'],
                ['r'=>'22-3','o'=>'22-S3'],
                ['r'=>'22-4','o'=>'22-S4'],
                ['r'=>'22-5','o'=>'22-S5'],
                ['r'=>'23-1','o'=>'23-S6'],
                ['r'=>'23-2','o'=>'23-S7'],
                ['r'=>'25-A','o'=>'25'],
                ['r'=>'26','o'=>'26'],
                ['r'=>'27-1','o'=>'27-P1'],
                ['r'=>'27-2','o'=>'27-P2'],
                ['r'=>'28-1','o'=>'28-P1'],
                ['r'=>'28-2','o'=>'28-P2'],
                ['r'=>'29','o'=>'29'],
                ['r'=>'30','o'=>'30'],
                ['r'=>'31','o'=>'31'],
                ['r'=>'32-1','o'=>'32-P1'],
                ['r'=>'32-2','o'=>'32-P2'],
                ['r'=>'33-1','o'=>'33-P1'],
                ['r'=>'33-2','o'=>'33-P2'],
                ['r'=>'35-1','o'=>'35-P1'],
                ['r'=>'35-2','o'=>'35-P2'],
                ['r'=>'37-1','o'=>'37-P4'],
                ['r'=>'37-2','o'=>'37-P5'],
                ['r'=>'37-3','o'=>'37-P6'],
                ['r'=>'38-1','o'=>'38-P1'],
                ['r'=>'38-2','o'=>'38-P2'],
                ['r'=>'39-1','o'=>'39-P1'],
                ['r'=>'39-2','o'=>'39-P2'],
                ['r'=>'40-1','o'=>'40-P1'],
                ['r'=>'40-2','o'=>'40-P2'],
                ['r'=>'40-3','o'=>'40-P3'],
                ['r'=>'41-1','o'=>'41-P1'],
                ['r'=>'41-2','o'=>'41-P2'],
                ['r'=>'41-3','o'=>'41-P3'],
                ['r'=>'41-4','o'=>'41-P4'],
                ['r'=>'42-1','o'=>'42-P1'],
                ['r'=>'42-2','o'=>'42-P2'],
                ['r'=>'42-3','o'=>'42-P3'],
                ['r'=>'43','o'=>'43'],
                ['r'=>'44-1','o'=>'44-P73'],
                ['r'=>'44-2','o'=>'44-P52'],
                ['r'=>'44-3','o'=>'44-P36'],
                ['r'=>'44-4','o'=>'44-P21'],
                ['r'=>'44-5','o'=>'44_P2'],
                ['r'=>'44-6','o'=>'44_P1'],
                ['r'=>'45-1','o'=>'45-1'],
                ['r'=>'45-2','o'=>'45-2'],
                ['r'=>'45-3','o'=>'45-3'],
                ['r'=>'46-1','o'=>'46-P1'],
                ['r'=>'46-2','o'=>'46-P2'],
                ['r'=>'60','o'=>'60'],
                ['r'=>'61-1','o'=>'61-U'],
                ['r'=>'61-2','o'=>'61-M'],
                ['r'=>'61-3','o'=>'61-D'],
                ['r'=>'63','o'=>'63'],
                ['r'=>'64-1','o'=>'64-U'],
                ['r'=>'64-2','o'=>'64-M'],
                ['r'=>'64-3','o'=>'64-D'],
                ['r'=>'65-1','o'=>'65-U'],
                ['r'=>'65-2','o'=>'65-M'],
                ['r'=>'65-3','o'=>'65-D'],
                ['r'=>'66','o'=>'66'],
                ['r'=>'67','o'=>'67'],
                ['r'=>'68-1','o'=>'68-P1'],
                ['r'=>'68-2','o'=>'68-P2'],
                ['r'=>'68-3','o'=>'68-P3'],
                ['r'=>'69-1','o'=>'69-P1'],
                ['r'=>'69-2','o'=>'69-P2'],
                ['r'=>'69-3','o'=>'69-P3'],
                ['r'=>'70','o'=>'70'],
                ['r'=>'71-1','o'=>'71-P1'],
                ['r'=>'71-2','o'=>'71-P2'],
                ['r'=>'72-1','o'=>'72-P1'],
                ['r'=>'72-2','o'=>'72-P2'],
                ['r'=>'73-1','o'=>'73-P1'],
                ['r'=>'73-2','o'=>'73-P2'],
                ['r'=>'74-1','o'=>'74-P1'],
                ['r'=>'74-2','o'=>'74-P2'],
                ['r'=>'75-1','o'=>'75-P1'],
                ['r'=>'75-2','o'=>'75-P2'],
                ['r'=>'76','o'=>'76'],
                ['r'=>'77','o'=>'77'],
                ['r'=>'78-1','o'=>'78-P1'],
                ['r'=>'78-2','o'=>'78-P2'],
                ['r'=>'82-1','o'=>'82-C1'],
                ['r'=>'82-2','o'=>'82-C2'],
                ['r'=>'82-3','o'=>'82-C3'],
                ['r'=>'82-4','o'=>'82-C4'],
                ['r'=>'82-5','o'=>'82-C5'],
                ['r'=>'82-6','o'=>'82-C6'],
                ['r'=>'86','o'=>'86'],
                ['r'=>'87-1','o'=>'87-P1'],
                ['r'=>'87-2','o'=>'87-P2'],
                ['r'=>'87-3','o'=>'87-P3'],
                ['r'=>'88-1','o'=>'88-P1'],
                ['r'=>'88-2','o'=>'88-P2'],
                ['r'=>'89-1','o'=>'89-P1'],
                ['r'=>'89-2','o'=>'89-P3'],
                ['r'=>'94-1','o'=>'94-E1'],
                ['r'=>'94-2','o'=>'94-E2'],
                ['r'=>'94-3','o'=>'94-E3'],
                ['r'=>'94-4','o'=>'94-E4'],
                ['r'=>'94-5','o'=>'94-E5'],
                ['r'=>'94-6','o'=>'94-E6'],
                ['r'=>'94-7','o'=>'94-E7'],
                ['r'=>'94-8','o'=>'94-E8'],
                ['r'=>'94-9','o'=>'94-E9'],
                ['r'=>'94-10','o'=>'94-E10'],
                ['r'=>'94-11','o'=>'94-E11'],
                ['r'=>'94-12','o'=>'94-E12'],
                ['r'=>'94-13','o'=>'94-E13'],
                ['r'=>'94-14','o'=>'94-E14'],
                ['r'=>'94-15','o'=>'94-E15'],
                ['r'=>'94-16','o'=>'94-E16'],
                ['r'=>'94-17','o'=>'94-E17'],
                ['r'=>'94-18','o'=>'94-E18'],
                ['r'=>'94-19','o'=>'94-E19'],
                ['r'=>'94-20','o'=>'94-E20'],
                ['r'=>'94-21','o'=>'94-E21'],
                ['r'=>'97','o'=>'97'],
                ['r'=>'98','o'=>'98'],
                ['r'=>'99','o'=>'99'],
                ['r'=>'100','o'=>'100'],
                ['r'=>'101-1','o'=>'101-1'],
                ['r'=>'101-2','o'=>'101-2'],
                ['r'=>'101-3','o'=>'101-3'],
                ['r'=>'101-4','o'=>'101-4'],
                ['r'=>'102','o'=>'102'],
                ['r'=>'103','o'=>'103'],
                ['r'=>'105-1','o'=>'105-K1'],
                ['r'=>'105-2','o'=>'105-K2'],
                ['r'=>'105-3','o'=>'105-K3'],
                ['r'=>'106-1','o'=>'106-K4'],
                ['r'=>'106-2','o'=>'106-K5'],
                ['r'=>'106-3','o'=>'106-K6'],
                ['r'=>'107-1','o'=>'107-P1'],
                ['r'=>'107-2','o'=>'107-P2'],
                ['r'=>'107-3','o'=>'107-P3'],
                ['r'=>'108-1','o'=>'108-P4'],
                ['r'=>'108-2','o'=>'108-P8'],
                ['r'=>'109-1','o'=>'109-P4'],
                ['r'=>'109-2','o'=>'109-P8'],
                ['r'=>'110-1','o'=>'110-P5'],
                ['r'=>'110-2','o'=>'110-P6'],
                ['r'=>'111','o'=>'111'],
                ['r'=>'112-V1','o'=>'112-V1'],
                ['r'=>'113-V1','o'=>'113-V1'],
                ['r'=>'114-V1','o'=>'114-V1'],
                ['r'=>'115-V1','o'=>'115-V1'],
                ['r'=>'116-V1','o'=>'116-V1'],
                ['r'=>'117-V1','o'=>'117-V1'],
                ['r'=>'112-V2','o'=>'112-V2'],
                ['r'=>'113-V2','o'=>'113-V2'],
                ['r'=>'114-V2','o'=>'114-V2'],
                ['r'=>'115-V2','o'=>'115-V2'],
                ['r'=>'116-V2','o'=>'116-V2'],
                ['r'=>'117-V2','o'=>'117-V2'],
                ['r'=>'118','o'=>'118'],
                ['r'=>'119','o'=>'119'],
                ['r'=>'120','o'=>'120'],
                ['r'=>'121','o'=>'121'],
                ['r'=>'122','o'=>'122'],
                ['r'=>'123','o'=>'123'],
                ['r'=>'124','o'=>'124'],
                ['r'=>'125','o'=>'125'],
                ['r'=>'126','o'=>'126'],
                ['r'=>'127-1','o'=>'127-P1'],
                ['r'=>'127-2','o'=>'127-P2'],
                ['r'=>'128-1','o'=>'128-P1'],
                ['r'=>'128-2','o'=>'128-P2'],
                ['r'=>'129-1','o'=>'129-P1'],
                ['r'=>'129-2','o'=>'129-P2'],
                ['r'=>'130-1','o'=>'130-P1'],
                ['r'=>'130-2','o'=>'130-P2'],
                ['r'=>'131','o'=>'131'],
                ['r'=>'132','o'=>'132'],
                ['r'=>'133','o'=>'133'],
                ['r'=>'135','o'=>'135'],
                ['r'=>'136-1(l1)','o'=>'136-P1'],
                ['r'=>'136-2(l1)','o'=>'136-P2'],
                ['r'=>'136-3(l1)','o'=>'136-P3'],
                ['r'=>'137','o'=>'137'],
                ['r'=>'138-1','o'=>'138-P1'],
                ['r'=>'138-2','o'=>'138-P2'],
                ['r'=>'138-3','o'=>'138-P3'],
                ['r'=>'139','o'=>'139'],
                ['r'=>'140','o'=>'140'],
                ['r'=>'141','o'=>'141'],
                ['r'=>'143','o'=>'143'],
                ['r'=>'144','o'=>'144'],
                ['r'=>'145-1','o'=>'145-Plastic'],
                ['r'=>'145-2','o'=>'145-Terminal'],
                ['r'=>'145-3','o'=>'145'],
                ['r'=>'146-1','o'=>'146-Plastic'],
                ['r'=>'146-2','o'=>'146-Terminal'],
                ['r'=>'146-3','o'=>'146'],
                ['r'=>'147','o'=>'147'],
                ['r'=>'148','o'=>'148'],
                ['r'=>'149-1','o'=>'149-S8'],
                ['r'=>'149-2','o'=>'149-S9'],
                ['r'=>'150','o'=>'150'],
                ['r'=>'151','o'=>'151'],
                ['r'=>'152','o'=>'152'],
                ['r'=>'153','o'=>'153'],
                ['r'=>'154','o'=>'154'],
                ['r'=>'155','o'=>'155'],
                ['r'=>'157','o'=>'157'],
                ['r'=>'158','o'=>'158'],
            ],
        ],
        'MEM-X-CARRIER' => [
            'pairs' => [
                ['r'=>'4-1','o'=>'4-1-a'],
                ['r'=>'4-2','o'=>'4-2-b'],
                ['r'=>'4-3','o'=>'4-3-c'],
                ['r'=>'6-1','o'=>'6-1-a'],
                ['r'=>'6-2','o'=>'6-2-b'],
                ['r'=>'6-3','o'=>'6-3-c'],
                ['r'=>'8','o'=>'8'],
                ['r'=>'9','o'=>'9'],
                ['r'=>'10-1','o'=>'10-1-d'],
                ['r'=>'10-2','o'=>'10-2-e'],
                ['r'=>'10-3','o'=>'10-3-f'],
                ['r'=>'23','o'=>'23'],
                ['r'=>'24','o'=>'24'],
                ['r'=>'25A-1','o'=>'25A-d'],
                ['r'=>'25A-2','o'=>'25A-e'],
                ['r'=>'25A-3','o'=>'25A-f'],
                ['r'=>'26','o'=>'26'],
                ['r'=>'27','o'=>'27'],
                ['r'=>'28','o'=>'28'],
                ['r'=>'30','o'=>'30'],
                ['r'=>'31','o'=>'31'],
                ['r'=>'32','o'=>'32'],
                ['r'=>'33','o'=>'33'],
                ['r'=>'34','o'=>'34'],
                ['r'=>'35','o'=>'35'],
                ['r'=>'36','o'=>'36'],
                ['r'=>'37','o'=>'37'],
                ['r'=>'38','o'=>'38'],
                ['r'=>'39','o'=>'39'],
                ['r'=>'40-1','o'=>'40-1'],
                ['r'=>'40-2','o'=>'40-2'],
                ['r'=>'40-3','o'=>'40-3'],
                ['r'=>'40-4','o'=>'40-4'],
                ['r'=>'41','o'=>'41'],
                ['r'=>'54','o'=>'54'],
                ['r'=>'55','o'=>'55'],
                ['r'=>'56','o'=>'56'],
                ['r'=>'57','o'=>'57'],
                ['r'=>'58','o'=>'58'],
                ['r'=>'59','o'=>'59'],
                ['r'=>'60','o'=>'60'],
                ['r'=>'61','o'=>'61'],
                ['r'=>'62','o'=>'62'],
                ['r'=>'63','o'=>'63'],
                ['r'=>'64','o'=>'64'],
                ['r'=>'65','o'=>'65'],
                ['r'=>'66','o'=>'66'],
                ['r'=>'68-1','o'=>'68-1'],
                ['r'=>'68-2','o'=>'68-2'],
                ['r'=>'69','o'=>'69'],
                ['r'=>'70-1','o'=>'70-1'],
                ['r'=>'70-2','o'=>'70-2'],
                ['r'=>'71','o'=>'71'],
            ],
        ],
        'MEM-Y-CARRIER' => [
            'pairs' => [
                ['r'=>'3-1','o'=>'3-1(P1)'],
                ['r'=>'3-2','o'=>'3-2(P2)'],
                ['r'=>'3-3','o'=>'3-3(P3)'],
                ['r'=>'4','o'=>'4'],
                ['r'=>'5','o'=>'5'],
                ['r'=>'6','o'=>'6'],
                ['r'=>'7','o'=>'7'],
                ['r'=>'8-1','o'=>'8-1(P1)'],
                ['r'=>'8-2','o'=>'8-2(P2)'],
                ['r'=>'9','o'=>'9'],
                ['r'=>'10','o'=>'10'],
                ['r'=>'11','o'=>'11'],
                ['r'=>'12-1','o'=>'12-1(P1)'],
                ['r'=>'12-2','o'=>'12-2(P2)'],
                ['r'=>'13-1','o'=>'13-1(P1)'],
                ['r'=>'13-2','o'=>'13-2(P2)'],
                ['r'=>'13-3','o'=>'13-3(P3)'],
                ['r'=>'14-1','o'=>'14-1(P1)'],
                ['r'=>'14-2','o'=>'14-2(P2)'],
                ['r'=>'14-3','o'=>'14-3(P3)'],
                ['r'=>'16_1','o'=>'16-1(P1)'],
                ['r'=>'16_2','o'=>'16-2(P2)'],
                ['r'=>'18-1','o'=>'18-1(P1)'],
                ['r'=>'18-2','o'=>'18-2(P2)'],
                ['r'=>'18-3','o'=>'18-3(P3)'],
                ['r'=>'19-1','o'=>'19-1(P1)'],
                ['r'=>'19-2','o'=>'19-2(P2)'],
                ['r'=>'19-3','o'=>'19-3(P3)'],
                ['r'=>'24-1','o'=>'24-1(P1)'],
                ['r'=>'24-2','o'=>'24-2(P2)'],
                ['r'=>'25','o'=>'25'],
                ['r'=>'26','o'=>'26'],
                ['r'=>'27-1','o'=>'27-1(B1)'],
                ['r'=>'27-2','o'=>'27-2(B2)'],
                ['r'=>'27-3','o'=>'27-3(B3)'],
                ['r'=>'27-4','o'=>'27-4(B4)'],
                ['r'=>'28','o'=>'28'],
                ['r'=>'29','o'=>'29'],
                ['r'=>'30-1','o'=>'30-1(P1)'],
                ['r'=>'30-2','o'=>'30-2(P2)'],
                ['r'=>'31-1','o'=>'31-1(P1)'],
                ['r'=>'31-2','o'=>'31-2(P3)'],
                ['r'=>'31-3','o'=>'31-3(P5)'],
                ['r'=>'32-1','o'=>'32-1(P2)'],
                ['r'=>'32-2','o'=>'32-2(P4)'],
                ['r'=>'32-3','o'=>'32-3(P6)'],
                ['r'=>'33','o'=>'33'],
                ['r'=>'34-1','o'=>'34-1(P1)'],
                ['r'=>'34-2','o'=>'34-2(P3)'],
                ['r'=>'34-3','o'=>'34-3(P5)'],
                ['r'=>'35-1','o'=>'35-1(P1)'],
                ['r'=>'35-2','o'=>'35-2(P3)'],
                ['r'=>'36','o'=>'36'],
                ['r'=>'37-1','o'=>'37-1'],
                ['r'=>'37-2','o'=>'37-2'],
                ['r'=>'38-1','o'=>'38-1(P1)'],
                ['r'=>'38-2','o'=>'38-2(P2)'],
                ['r'=>'39','o'=>'39'],
                ['r'=>'41','o'=>'41'],
                ['r'=>'42','o'=>'42'],
                ['r'=>'43','o'=>'43'],
                ['r'=>'44','o'=>'44'],
                ['r'=>'45','o'=>'45'],
                ['r'=>'46','o'=>'46'],
                ['r'=>'48-1','o'=>'48-1(P2)'],
                ['r'=>'48-2','o'=>'48-2(P4)'],
                ['r'=>'48-3','o'=>'48-3(P6)'],
                ['r'=>'49','o'=>'49'],
                ['r'=>'50-1','o'=>'50-1(P1)'],
                ['r'=>'50-2','o'=>'50-2(P2)'],
                ['r'=>'50-3','o'=>'50-3(P3)'],
                ['r'=>'51-1','o'=>'51-1(P1)'],
                ['r'=>'51-2','o'=>'51-2(P2)'],
                ['r'=>'51-3','o'=>'51-3(P3)'],
                ['r'=>'52-1','o'=>'52-1'],
                ['r'=>'52-2','o'=>'52-2'],
                ['r'=>'52-3','o'=>'52-3'],
                ['r'=>'53-1','o'=>'53-1'],
                ['r'=>'53-2','o'=>'53-2'],
                ['r'=>'56','o'=>'56'],
                ['r'=>'57','o'=>'57'],
                ['r'=>'58-1','o'=>'58-1(P1)'],
                ['r'=>'58-2','o'=>'58-2(P2)'],
                ['r'=>'59-1','o'=>'59-1(P1)'],
                ['r'=>'59-2','o'=>'59-2(P2)'],
                ['r'=>'60-1','o'=>'60-1(P1)'],
                ['r'=>'60-2','o'=>'60-2(P2)'],
                ['r'=>'61','o'=>'61'],
                ['r'=>'62-1','o'=>'62-1(P1)'],
                ['r'=>'62-2','o'=>'62-2(P2)'],
                ['r'=>'63-1','o'=>'63-1(P1)'],
                ['r'=>'63-2','o'=>'63-2(P2)'],
                ['r'=>'64','o'=>'64'],
                ['r'=>'65-1','o'=>'65-1(P2)'],
                ['r'=>'65-2','o'=>'65-2(P4)'],
                ['r'=>'66-1','o'=>'66-1(P1)'],
                ['r'=>'66-2','o'=>'66-2(P2)'],
                ['r'=>'67_1','o'=>'67-1A(P1)'],
                ['r'=>'67_2','o'=>'67-2A(P2)'],
                ['r'=>'67_3','o'=>'67-3A(P3)'],
                ['r'=>'67_4','o'=>'67-4A(P4)'],
                ['r'=>'67_5','o'=>'67-5A(P5)'],
                ['r'=>'67_6','o'=>'67-6A(P6)'],
                ['r'=>'67_7','o'=>'67-7A(P7)'],
                ['r'=>'67_8','o'=>'67-8A(P8)'],
                ['r'=>'67_9','o'=>'67-9A(P9)'],
                ['r'=>'67_10','o'=>'67-10A(P10)'],
                ['r'=>'67_11','o'=>'67-11A(P11)'],
                ['r'=>'67_12','o'=>'67-12A(P12)'],
                ['r'=>'67_13','o'=>'67-13A(P13)'],
                ['r'=>'70','o'=>'70'],
                ['r'=>'71','o'=>'71'],
                ['r'=>'72-1','o'=>'72-1(P1)'],
                ['r'=>'72-2','o'=>'72-2(P2)'],
                ['r'=>'72-3','o'=>'72-3(P3)'],
                ['r'=>'73-1','o'=>'73-1(P1)'],
                ['r'=>'73-2','o'=>'73-2(P2)'],
                ['r'=>'73-3','o'=>'73-3(P3)'],
                ['r'=>'74','o'=>'74'],
                ['r'=>'80','o'=>'80'],
                ['r'=>'81','o'=>'81'],
                ['r'=>'82','o'=>'82'],
                ['r'=>'83','o'=>'83'],
                ['r'=>'84','o'=>'84'],
                ['r'=>'85-1','o'=>'85-1(A1)'],
                ['r'=>'85-2','o'=>'85-2(A2)'],
                ['r'=>'85-3','o'=>'85-3(A3)'],
                ['r'=>'85-4','o'=>'85-4(A4)'],
                ['r'=>'85-5','o'=>'85-5(A5)'],
                ['r'=>'85-6','o'=>'85-6(A6)'],
                ['r'=>'86-1','o'=>'86-1(A1)'],
                ['r'=>'86-2','o'=>'86-2(A2)'],
                ['r'=>'86-3','o'=>'86-3(A3)'],
                ['r'=>'86-4','o'=>'86-4(A4)'],
                ['r'=>'86-5','o'=>'86-5(A5)'],
                ['r'=>'86-6','o'=>'86-6(A6)'],
                ['r'=>'90-1','o'=>'90-1(M1)'],
                ['r'=>'90-2','o'=>'90-2(M2)'],
                ['r'=>'91','o'=>'91'],
                ['r'=>'92','o'=>'92'],
                ['r'=>'94-1','o'=>'94-1(C1)'],
                ['r'=>'94-2','o'=>'94-2(C2)'],
                ['r'=>'95','o'=>'95'],
                ['r'=>'96','o'=>'96'],
                ['r'=>'97','o'=>'97'],
                ['r'=>'99','o'=>'99'],
                ['r'=>'100','o'=>'100'],
            ],
        ],
        'MEM-Z-CARRIER' => [
            'pairs' => [
                ['r'=>'2-1','o'=>'2-1'],
                ['r'=>'2-2','o'=>'2-2'],
                ['r'=>'2-3','o'=>'2-3'],
                ['r'=>'2-4','o'=>'2-4'],
                ['r'=>'3','o'=>'3'],
                ['r'=>'4-a','o'=>'4-a'],
                ['r'=>'4-b','o'=>'4-b'],
                ['r'=>'4-c','o'=>'4-c'],
                ['r'=>'6-a','o'=>'6-a'],
                ['r'=>'6-b','o'=>'6-b'],
                ['r'=>'6-c','o'=>'6-c'],
                ['r'=>'7','o'=>'7'],
                ['r'=>'8','o'=>'8'],
                ['r'=>'9_1','o'=>'9-1(D1)'],
                ['r'=>'9_2','o'=>'9-2(D2)'],
                ['r'=>'9_3','o'=>'9-3(D3)'],
                ['r'=>'9_4','o'=>'9-4(D4)'],
                ['r'=>'9_5','o'=>'9-5(D5)'],
                ['r'=>'9_6','o'=>'9-6(D6)'],
                ['r'=>'9_7','o'=>'9-7(D7)'],
                ['r'=>'9_8','o'=>'9-8(D8)'],
                ['r'=>'11','o'=>'11'],
                ['r'=>'12-1','o'=>'12-C1'],
                ['r'=>'12-2','o'=>'12-C2'],
                ['r'=>'12-3','o'=>'12-C3'],
                ['r'=>'12-4','o'=>'12-C4'],
                ['r'=>'13-1','o'=>'13-1(P1)'],
                ['r'=>'13-2','o'=>'13-2(P2)'],
                ['r'=>'14-1','o'=>'14-1(P1-P2)'],
                ['r'=>'14-2','o'=>'14-2(P3-P4)'],
                ['r'=>'15-1','o'=>'L-15-P1'],
                ['r'=>'15-2','o'=>'L-15-P2'],
                ['r'=>'15-3','o'=>'R-15-Q1'],
                ['r'=>'15-4','o'=>'R-15-Q2'],
                ['r'=>'20','o'=>'20'],
                ['r'=>'21','o'=>'21'],
                ['r'=>'22','o'=>'22'],
                ['r'=>'23','o'=>'23'],
                ['r'=>'24','o'=>'24'],
                ['r'=>'25-1','o'=>'25-1(P1)'],
                ['r'=>'25-2','o'=>'25-2(P2)'],
                ['r'=>'26-1','o'=>'26-1'],
                ['r'=>'26-2','o'=>'26-2'],
                ['r'=>'27','o'=>'27'],
                ['r'=>'28','o'=>'28'],
                ['r'=>'29','o'=>'29'],
                ['r'=>'31','o'=>'31'],
                ['r'=>'33','o'=>'33'],
                ['r'=>'34','o'=>'34'],
                ['r'=>'35-1','o'=>'35-1(P1)'],
                ['r'=>'35-2','o'=>'35-2(P2)'],
                ['r'=>'36','o'=>'36'],
                ['r'=>'37-1','o'=>'37-1(C1)'],
                ['r'=>'37-2','o'=>'37-2(C2)'],
                ['r'=>'38-1','o'=>'38-1(C1)'],
                ['r'=>'38-2','o'=>'38-2(C2)'],
                ['r'=>'39','o'=>'39'],
                ['r'=>'40','o'=>'40'],
                ['r'=>'41','o'=>'41'],
                ['r'=>'45','o'=>'45'],
                ['r'=>'46','o'=>'46'],
                ['r'=>'47','o'=>'47'],
                ['r'=>'50','o'=>'50'],
                ['r'=>'51','o'=>'51'],
                ['r'=>'52','o'=>'52'],
                ['r'=>'53','o'=>'53'],
                ['r'=>'54-1','o'=>'54-P1'],
                ['r'=>'54-2','o'=>'54-P2'],
                ['r'=>'55-1','o'=>'55-P1'],
                ['r'=>'55-2','o'=>'55-P2'],
                ['r'=>'56-1','o'=>'56-1(P1)'],
                ['r'=>'56-2','o'=>'56-2(P2)'],
                ['r'=>'57','o'=>'57'],
                ['r'=>'66','o'=>'66'],
                ['r'=>'67','o'=>'67'],
                ['r'=>'68','o'=>'68'],
                ['r'=>'69-1','o'=>'69-1'],
                ['r'=>'69-2','o'=>'69-2'],
                ['r'=>'70-1','o'=>'70-1'],
                ['r'=>'70-2','o'=>'70-2'],
                ['r'=>'80','o'=>'80'],
                ['r'=>'81','o'=>'81'],
                ['r'=>'82','o'=>'82'],
                ['r'=>'83','o'=>'83'],
                ['r'=>'84','o'=>'84'],
                ['r'=>'85','o'=>'85'],
                ['r'=>'86-1','o'=>'86-A1'],
                ['r'=>'86-2','o'=>'86-A2'],
                ['r'=>'86-3','o'=>'86-A3'],
                ['r'=>'86-4','o'=>'86-A4'],
                ['r'=>'86-5','o'=>'86-A5'],
                ['r'=>'86-6','o'=>'86-A6'],
                ['r'=>'87','o'=>'87'],
                ['r'=>'88-1','o'=>'88-A1'],
                ['r'=>'88-2','o'=>'88-A2'],
                ['r'=>'88-3','o'=>'88-A3'],
                ['r'=>'88-4','o'=>'88-A4'],
                ['r'=>'88-5','o'=>'88-A5'],
                ['r'=>'88-6','o'=>'88-A6'],
                ['r'=>'89','o'=>'89'],
                ['r'=>'96-1','o'=>'96-1'],
                ['r'=>'96-2','o'=>'96-2'],
                ['r'=>'97-1','o'=>'97-1'],
                ['r'=>'97-2','o'=>'97-2'],
                ['r'=>'98-V1','o'=>'98-V1'],
                ['r'=>'99-V1','o'=>'99-V1'],
                ['r'=>'100-V1','o'=>'100-V1'],
                ['r'=>'101-V1','o'=>'101-V1'],
                ['r'=>'102-V1','o'=>'102-V1'],
                ['r'=>'103-V1','o'=>'103-V1'],
                ['r'=>'98-V2','o'=>'98-V2'],
                ['r'=>'99-V2','o'=>'99-V2'],
                ['r'=>'100-V2','o'=>'100-V2'],
                ['r'=>'101-V2','o'=>'101-V2'],
                ['r'=>'102-V2','o'=>'102-V2'],
                ['r'=>'103-V2','o'=>'103-V2'],
                ['r'=>'104-V3','o'=>'104-V3'],
                ['r'=>'105-V3','o'=>'105-V3'],
                ['r'=>'106-V3','o'=>'106-V3'],
                ['r'=>'107-V3','o'=>'107-V3'],
                ['r'=>'108-V3','o'=>'108-V3'],
                ['r'=>'109-V3','o'=>'109-V3'],
                ['r'=>'104-V4','o'=>'104-V4'],
                ['r'=>'105-V4','o'=>'105-V4'],
                ['r'=>'106-V4','o'=>'106-V4'],
                ['r'=>'107-V4','o'=>'107-V4'],
                ['r'=>'108-V4','o'=>'108-V4'],
                ['r'=>'109-V4','o'=>'109-V4'],
                ['r'=>'111-1','o'=>'111-1'],
                ['r'=>'111-2','o'=>'111-2'],
                ['r'=>'111-3','o'=>'111-3'],
                ['r'=>'111-4','o'=>'111-4'],
                ['r'=>'111-5','o'=>'111-5'],
                ['r'=>'111-6','o'=>'111-6'],
                ['r'=>'111-7','o'=>'111-7'],
                ['r'=>'111-8','o'=>'111-8'],
                ['r'=>'111-9','o'=>'111-9'],
                ['r'=>'111-10','o'=>'111-10'],
                ['r'=>'111-11','o'=>'111-11'],
                ['r'=>'111-12','o'=>'111-12'],
                ['r'=>'111-13','o'=>'111-13'],
                ['r'=>'111-14','o'=>'111-14'],
                ['r'=>'111-15','o'=>'111-15'],
                ['r'=>'113','o'=>'113'],
                ['r'=>'114-1','o'=>'114-1'],
                ['r'=>'114-2','o'=>'114-2'],
                ['r'=>'115','o'=>'115'],
                ['r'=>'116_1','o'=>'116-1'],
                ['r'=>'116_2','o'=>'116-2'],
                ['r'=>'117-1','o'=>'117-1'],
                ['r'=>'117-2','o'=>'117-2'],
            ],
        ],
        'MEM-Z-STOPPER' => [
            'pairs' => [
                ['r'=>'1','o'=>'1'],
                ['r'=>'2-1','o'=>'2-1'],
                ['r'=>'2-2','o'=>'2-2'],
                ['r'=>'2-3','o'=>'2-3'],
                ['r'=>'2-4','o'=>'2-4'],
                ['r'=>'5-1','o'=>'5-1'],
                ['r'=>'5-2','o'=>'5-2'],
                ['r'=>'6-1','o'=>'6-1'],
                ['r'=>'6-2','o'=>'6-2'],
                ['r'=>'7-1','o'=>'7-1'],
                ['r'=>'7-2','o'=>'7-2'],
                ['r'=>'7-3','o'=>'7-3'],
                ['r'=>'7-4','o'=>'7-4'],
                ['r'=>'9-1','o'=>'9-1'],
                ['r'=>'9-2','o'=>'9-2'],
                ['r'=>'10-1','o'=>'10-1'],
                ['r'=>'10-2','o'=>'10-2'],
                ['r'=>'12','o'=>'12'],
                ['r'=>'13','o'=>'13'],
                ['r'=>'14','o'=>'14'],
                ['r'=>'15','o'=>'15'],
                ['r'=>'16','o'=>'16'],
                ['r'=>'17-1','o'=>'17-1'],
                ['r'=>'17-2','o'=>'17-2'],
                ['r'=>'19-1','o'=>'19-1'],
                ['r'=>'19-2','o'=>'19-2'],
                ['r'=>'20','o'=>'20'],
                ['r'=>'21','o'=>'21'],
                ['r'=>'22','o'=>'22'],
                ['r'=>'23','o'=>'23'],
                ['r'=>'24-1','o'=>'24-1'],
                ['r'=>'24-2','o'=>'24-2'],
                ['r'=>'25-1','o'=>'25-1'],
                ['r'=>'25-2','o'=>'25-2'],
                ['r'=>'26','o'=>'26'],
                ['r'=>'27-1','o'=>'27-1'],
                ['r'=>'27-2','o'=>'27-2'],
                ['r'=>'28','o'=>'28'],
                ['r'=>'29','o'=>'29'],
                ['r'=>'31','o'=>'31'],
                ['r'=>'32','o'=>'32'],
                ['r'=>'33','o'=>'33'],
                ['r'=>'34-1','o'=>'34-1'],
                ['r'=>'34-2','o'=>'34-2'],
                ['r'=>'35-1','o'=>'35-1'],
                ['r'=>'35-2','o'=>'35-2'],
                ['r'=>'39-1','o'=>'39-1'],
                ['r'=>'39-2','o'=>'39-2'],
                ['r'=>'40','o'=>'40'],
                ['r'=>'41','o'=>'41'],
                ['r'=>'42','o'=>'42'],
                ['r'=>'43','o'=>'43'],
                ['r'=>'44-1','o'=>'44-1'],
                ['r'=>'44-2','o'=>'44-2'],
                ['r'=>'46','o'=>'46'],
                ['r'=>'47','o'=>'47'],
                ['r'=>'48','o'=>'48'],
                ['r'=>'49','o'=>'49'],
                ['r'=>'50-1','o'=>'50-1'],
                ['r'=>'50-2','o'=>'50-2'],
                ['r'=>'51-1','o'=>'51-1'],
                ['r'=>'51-2','o'=>'51-2'],
                ['r'=>'52-1','o'=>'52-1'],
                ['r'=>'52-2','o'=>'52-2'],
                ['r'=>'53-1','o'=>'53-1'],
                ['r'=>'53-2','o'=>'53-2'],
                ['r'=>'54-1','o'=>'54-1'],
                ['r'=>'54-2','o'=>'54-2'],
                ['r'=>'54-3','o'=>'54-3'],
                ['r'=>'54-4','o'=>'54-4'],
                ['r'=>'55-1','o'=>'55-1'],
                ['r'=>'55-2','o'=>'55-2'],
                ['r'=>'56-1','o'=>'56-1'],
                ['r'=>'56-2','o'=>'56-2'],
                ['r'=>'59','o'=>'59'],
                ['r'=>'60','o'=>'60'],
                ['r'=>'61','o'=>'61'],
                ['r'=>'62','o'=>'62'],
            ],
        ],
];

    $GLOBALS['OQC_REPORT_POINT_MAP'] = $OQC_REPORT_POINT_MAP;
}

if (!function_exists('patch_oqc_report_from_rowdata')) {
    /**
     * OQC_REPORT 채우기 (PhpSpreadsheet)
     * - XML 직접 편집 금지(엑셀 복구 오류 방지)
     * - 보고서 템플릿의 테두리/스타일 유지: "참조행"의 xfIndex를 가져와, 새로 생성된 셀(기본 xf=0)에 적용
     * - FAI 시트: (IR/XC/YC/ZC) L~N / (ZS) J~L  (3칸)
     * - SPC 시트: (IR/XC/YC/ZC) AM~BR / (ZS) AK~BP (32칸)
     * - 키 매핑: $OQC_REPORT_POINT_MAP[PART] 를 사용 (보고서 키 -> rowdata 키)
     */
    function patch_oqc_report_from_rowdata(
    string $reportPath,
    string $part,
    array $pairs,
    array $faiMaps,
    array $toolPairs,
    array $dataByRow,
    bool $isJawha = false
): array {
    // ✅ ZIP(XML) 패치 방식: 값만 교체 → 테두리/도형/레이아웃 보존
    $out = ['ok' => false, 'mapped' => 0, 'written' => 0, 'reason' => ''];

    try {
        if (!is_file($reportPath)) {
            throw new RuntimeException('report not found');
        }
        if (empty($pairs)) {
            $out['ok'] = true;
            $out['reason'] = 'no pairs';
            return $out;
        }

        $isZS = ($part === 'MEM-Z-STOPPER');
        $faiStartCol = $isZS ? 'J'  : 'L';   // FAI 헤더/데이터 시작
        $spcStartCol = $isZS ? 'AK' : 'AM';  // SPC 헤더/데이터 시작

        // ✅ 납품처(템플릿)별 REPORT FAI/SPC 시작열 (dst에 "직접" 입력)
        // - shipinglist_export_lotlist.php 상단의 $GLOBALS['REPORT_FAI_SPC_COLCFG'] 로 제어
        $colcfg = $GLOBALS['REPORT_FAI_SPC_COLCFG'] ?? null;
        if (is_array($colcfg) && !empty($colcfg['enabled'])) {
            $vendorKey = ($isJawha ? 'JAWHA' : 'LGIT');

            // 모델별 override 우선
            $mcfg = null;
            if (isset($colcfg['by_model']) && is_array($colcfg['by_model']) && isset($colcfg['by_model'][$part]) && is_array($colcfg['by_model'][$part])) {
                $mcfg = $colcfg['by_model'][$part];
            }

            $dst = null;
            if (is_array($mcfg) && isset($mcfg['dst']) && is_array($mcfg['dst']) && isset($mcfg['dst'][$vendorKey]) && is_array($mcfg['dst'][$vendorKey])) {
                $dst = $mcfg['dst'][$vendorKey];
            } elseif (isset($colcfg['dst']) && is_array($colcfg['dst']) && isset($colcfg['dst'][$vendorKey]) && is_array($colcfg['dst'][$vendorKey])) {
                $dst = $colcfg['dst'][$vendorKey];
            }

            $normCol = function($v, int $width): string {
                $s = strtoupper(trim((string)($v ?? '')));
                if ($s === '') return '';
                // FAI(3칸) 입력 편의: "LMN" / "L-N" / "L~N" 형태면 start만 사용
                if ($width === 3) {
                    if (preg_match('/^[A-Z]{3}$/', $s)) {
                        $a = ord($s[0]); $b = ord($s[1]); $c = ord($s[2]);
                        if ($b === $a + 1 && $c === $b + 1) return $s[0];
                    }
                    if (preg_match('/^([A-Z]{1,3})\s*[-~:]\s*([A-Z]{1,3})$/', $s, $mm)) {
                        return strtoupper($mm[1]);
                    }
                }
                if (!preg_match('/^[A-Z]{1,3}$/', $s)) return '';
                return $s;
            };

            if (is_array($dst)) {
                $cF = $normCol($dst['FAI'] ?? '', 3);
                $cS = $normCol($dst['SPC'] ?? '', 32);
                if ($cF !== '') $faiStartCol = $cF;
                if ($cS !== '') $spcStartCol = $cS;
            }
        }
        $headerRow   = 5;
        $dataRowRef  = 6; // 서식 참조용

        // 1) 성적서 템플릿에서 행 매핑(FAI: B열, SPC: C열)
        $rFaiMaps = xlsx_build_fai_row_maps($reportPath, 'FAI', 'B', 6);
        $rSpcMaps = xlsx_build_fai_row_maps($reportPath, 'SPC', 'C', 6);

        $zip = new ZipArchive();
        if ($zip->open($reportPath) !== true) {
            throw new RuntimeException('zip open failed');
        }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        // ─────────────────────────────
        // FAI 시트 패치
        // ─────────────────────────────
        $faiPath = xlsx_get_sheet_path_by_name($zip, 'FAI');
        if (!$faiPath) throw new RuntimeException('FAI sheet not found');
        $faiXml = $zip->getFromName($faiPath);
        if ($faiXml === false) throw new RuntimeException('FAI xml read failed');

        $domF = new DOMDocument();
        $domF->preserveWhiteSpace = false;
        $domF->formatOutput = false;
        if (!$domF->loadXML($faiXml)) throw new RuntimeException('FAI xml parse failed');
        $xpF = new DOMXPath($domF);
        $xpF->registerNamespace('x', $ns);

        $getStyleMap = function(DOMXPath $xp, int $rowNum, int $startIdx, int $endIdx): array {
            $map = [];
            $nodes = $xp->query('//x:sheetData//x:row[@r="' . $rowNum . '"]/x:c');
            if ($nodes) {
                foreach ($nodes as $node) {
                    if (!($node instanceof DOMElement)) continue;
                    $r = strtoupper($node->getAttribute('r'));
                    if (!preg_match('/^([A-Z]+)\d+$/', $r, $mm)) continue;
                    $ci = xlsx_col_to_index($mm[1]);
                    if ($ci < $startIdx || $ci > $endIdx) continue;
                    if ($node->hasAttribute('s')) $map[$ci] = $node->getAttribute('s');
                }
            }
            return $map;
        };

        $faiStartIdx = xlsx_col_to_index($faiStartCol);
        $faiEndIdx   = $faiStartIdx + 3 - 1;
        $faiStyleHdr = $getStyleMap($xpF, $headerRow, $faiStartIdx, $faiEndIdx);
        $faiStyleDat = $getStyleMap($xpF, $dataRowRef, $faiStartIdx, $faiEndIdx);

        // 헤더(툴캐비티 3칸)
        $rowNode = xlsx_ensure_row($domF, $xpF, $headerRow);
        for ($i = 0; $i < 3; $i++) {
            $col = xlsx_index_to_col($faiStartIdx + $i);
            $ref = $col . $headerRow;
            $styleS = $faiStyleHdr[$faiStartIdx + $i] ?? null;
            $c = xlsx_ensure_cell($domF, $xpF, $rowNode, $ref, $styleS);
            xlsx_set_cell_value($domF, $c, (string)($toolPairs[$i] ?? ''));
        }

        // 데이터(행 매핑)
        $mapped = 0;
        $written = 0;
        // 데이터(행 매핑)
        $mapped = 0;
        $written = 0;

        // pairs -> map (reportKey -> oqcKey)  +  강력한 fallback(동일키/패턴키)
        $pairMap = [];
        foreach ($pairs as $pair) {
            $rkeyRaw = $pair['report'] ?? $pair['r'] ?? $pair['R'] ?? ($pair[0] ?? '');
            $okeyRaw = $pair['oqc']    ?? $pair['o'] ?? $pair['O'] ?? ($pair[1] ?? '');
            $rk = normalize_fai_key($rkeyRaw);
            $ok = normalize_fai_key($okeyRaw);
            if ($rk === '' || $ok === '') continue;
            $pairMap[$rk] = $ok;

            // base-key(괄호/부가설명 제거)도 같은 okey로 연결
            $rb = fai_base_key($rkeyRaw);
            if ($rb !== '' && !isset($pairMap[$rb])) $pairMap[$rb] = $ok;
        }

        $resolveOkey = function(string $rkeyN) use ($pairMap, $faiMaps): string {
            if (isset($pairMap[$rkeyN])) return $pairMap[$rkeyN];

            // ✅ 1) 템플릿(OQC B열)에 같은 키가 있으면 그대로(=identity)
            if (isset($faiMaps['full'][$rkeyN])) return $rkeyN;

            // ✅ 2) 112-1 / 112-2 같은 패턴: 템플릿에 V/S/P 변형이 있으면 자동 연결
            if (preg_match('/^(\d+)-([0-9]+)$/', $rkeyN, $m)) {
                $a = $m[1]; $b = $m[2];

                $cand = $a . '-V' . $b;
                if (isset($faiMaps['full'][$cand])) return $cand;

                $cand = $a . '-S' . $b;
                if (isset($faiMaps['full'][$cand])) return $cand;

                $cand = $a . '-P' . $b;
                if (isset($faiMaps['full'][$cand])) return $cand;
            }

            // ✅ 3) 21-1(P73) / 22-1(S1) 같은 패턴: 괄호 안 토큰으로 매칭
            if (preg_match('/^(\d+)-\d+\((P\d+|S\d+|V\d+)\)$/', $rkeyN, $m)) {
                $cand = $m[1] . '-' . $m[2];
                if (isset($faiMaps['full'][$cand])) return $cand;
            }

            return '';
        };

        // ✅ FAI 시트는 "보고서에 존재하는 키" 기준으로 전부 채우기
        foreach ($rFaiMaps['full'] as $rkeyN => $rRow) {
            $rRow = (int)$rRow;
            if ($rRow < 6) continue; // 헤더/설명 라인 제외

            $okeyN = $resolveOkey((string)$rkeyN);
            if ($okeyN === '') continue;

            $oRow = $faiMaps['full'][$okeyN] ?? null;
            if (!$oRow) continue;

            $vals = $dataByRow[(int)$oRow] ?? null;
            if (!is_array($vals)) continue;

            $mapped++;
            $rowNode = xlsx_ensure_row($domF, $xpF, $rRow);
            for ($i = 0; $i < 3; $i++) {
                $v = $vals[$i] ?? '';
                $col = xlsx_index_to_col($faiStartIdx + $i);
                $ref = $col . $rRow;
                $styleS = $faiStyleDat[$faiStartIdx + $i] ?? null;
                $c = xlsx_ensure_cell($domF, $xpF, $rowNode, $ref, $styleS);
                xlsx_set_cell_value($domF, $c, $v);
                if ($v !== '' && $v !== null) $written++;
            }
        }

        // ─────────────────────────────
        // SPC 시트 패치
        // ─────────────────────────────
        $spcPath = xlsx_get_sheet_path_by_name($zip, 'SPC');
        if (!$spcPath) throw new RuntimeException('SPC sheet not found');
        $spcXml = $zip->getFromName($spcPath);
        if ($spcXml === false) throw new RuntimeException('SPC xml read failed');

        $domS = new DOMDocument();
        $domS->preserveWhiteSpace = false;
        $domS->formatOutput = false;
        if (!$domS->loadXML($spcXml)) throw new RuntimeException('SPC xml parse failed');
        $xpS = new DOMXPath($domS);
        $xpS->registerNamespace('x', $ns);

        $spcStartIdx = xlsx_col_to_index($spcStartCol);
        $spcEndIdx   = $spcStartIdx + 32 - 1;
        $spcStyleHdr = $getStyleMap($xpS, $headerRow, $spcStartIdx, $spcEndIdx);
        $spcStyleDat = $getStyleMap($xpS, $dataRowRef, $spcStartIdx, $spcEndIdx);

        // 헤더(툴캐비티 32칸)
        $rowNode = xlsx_ensure_row($domS, $xpS, $headerRow);
        for ($i = 0; $i < 32; $i++) {
            $col = xlsx_index_to_col($spcStartIdx + $i);
            $ref = $col . $headerRow;
            $styleS = $spcStyleHdr[$spcStartIdx + $i] ?? null;
            $c = xlsx_ensure_cell($domS, $xpS, $rowNode, $ref, $styleS);
            xlsx_set_cell_value($domS, $c, (string)($toolPairs[$i] ?? ''));
        }

        // 데이터(행 매핑)
        // 데이터(행 매핑) - ✅ 보고서에 있는 키 기준(매핑 누락 방지)
        foreach ($rSpcMaps['full'] as $rkeyN => $rRow) {
            $rRow = (int)$rRow;
            if ($rRow < 6) continue;

            $okeyN = $resolveOkey((string)$rkeyN);
            if ($okeyN === '') continue;

            $oRow = $faiMaps['full'][$okeyN] ?? null;
            if (!$oRow) continue;

            $vals = $dataByRow[(int)$oRow] ?? null;
            if (!is_array($vals)) continue;

            $rowNode = xlsx_ensure_row($domS, $xpS, (int)$rRow);
            for ($i = 0; $i < 32; $i++) {
                $v = $vals[$i] ?? '';
                $col = xlsx_index_to_col($spcStartIdx + $i);
                $ref = $col . $rRow;
                $styleS = $spcStyleDat[$spcStartIdx + $i] ?? null;
                $c = xlsx_ensure_cell($domS, $xpS, $rowNode, $ref, $styleS);
                xlsx_set_cell_value($domS, $c, $v);
                if ($v !== '' && $v !== null) $written++;
            }
        }

        // ZIP에 반영
        $zip->addFromString($faiPath, $domF->saveXML());
        $zip->addFromString($spcPath, $domS->saveXML());
        $zip->close();

        $out['ok'] = true;
        $out['mapped'] = $mapped;
        $out['written'] = $written;
        $out['reason'] = ($mapped > 0 ? 'ok' : 'no matched pairs (check pairs keys: report/oqc or r/o)');

        // UX: open "Waiver Summary" first and select A1 on each sheet (no style changes)
        try {
            if (function_exists('xlsx_set_active_sheet_and_a1')) {
                xlsx_set_active_sheet_and_a1($reportPath, 'Waiver Summary');
            }
        } catch (Throwable $e) {
            // ignore UX patch failures
        }
        return $out;

    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['reason'] = 'patch failed: ' . $e->getMessage();
        return $out;
    }
}


}
