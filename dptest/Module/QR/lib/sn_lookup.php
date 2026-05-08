<?php
if (!function_exists('qr_sn_json_encode_warnings')) {
    function qr_sn_json_encode_warnings(array $warnings): string {
        return json_encode(array_values($warnings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function qr_sn_year_from_code(string $code): ?int {
    $code = strtoupper(trim($code));
    if ($code === '' || !preg_match('/^[A-Z]$/', $code)) return null;
    // 규격표 기준: B=2021, C=2022, D=2023 ...
    return 2019 + (ord($code) - 64);
}

function qr_sn_calc_mfg_date(string $yearCode, string $weekText, string $dayText): string {
    $year = qr_sn_year_from_code($yearCode);
    if ($year === null || !ctype_digit($weekText) || !ctype_digit($dayText)) return '';

    $week = (int)$weekText;
    $day = (int)$dayText;
    if ($week < 1 || $week > 54 || $day < 1 || $day > 7) return '';

    try {
        $jan1 = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $jan1N = (int)$jan1->format('N'); // 월=1 ... 일=7
        $daysToFirstMonday = (8 - $jan1N) % 7;
        $firstMonday = $jan1->modify('+' . $daysToFirstMonday . ' days');
        return $firstMonday->modify('+' . (((int)$week - 1) * 7 + ((int)$day - 1)) . ' days')->format('Y-m-d');
    } catch (Throwable $e) {
        return '';
    }
}

function qr_sn_parse(string $raw): array {
    $sn = strtoupper(preg_replace('/\s+/', '', trim($raw)));
    if ($sn === '') {
        throw new InvalidArgumentException('SN을 입력해 주세요.');
    }
    if (strlen($sn) < 21) {
        throw new InvalidArgumentException('SN 길이가 부족합니다. 21자리 형식인지 확인해 주세요.');
    }

    $companyCode = substr($sn, 0, 1);
    $plantCode = substr($sn, 1, 1);
    $programCode = substr($sn, 2, 1);
    $yearCode = substr($sn, 3, 1);
    $weekCode = substr($sn, 4, 2);
    $dayCode = substr($sn, 6, 1);
    $sequence = substr($sn, 7, 5);
    $typeCode = substr($sn, 12, 1);
    $delimiter1 = substr($sn, 13, 1);
    $lineCode = substr($sn, 14, 1);
    $equipmentNo = substr($sn, 15, 2);
    $moldCode = substr($sn, 17, 1);
    $cavity = substr($sn, 18, 1);
    $delimiter2 = substr($sn, 19, 1);
    $revision = substr($sn, 20, 1);

    $companyMap = ['D' => 'Dpamstech'];
    $plantMap = ['G' => 'Gunpo 공장'];
    $programMap = ['V' => 'Varo Program'];
    $modelMap = [
        'B' => 'MEM-IR-BASE',
        'R' => 'MEM-IR-BASE',
        'X' => 'MEM-X-CARRIER',
        'Y' => 'MEM-Y-CARRIER',
        'Z' => 'MEM-Z-CARRIER',
        'S' => 'MEM-Z-STOPPER',
    ];
    $weekdayMap = ['1' => '월요일', '2' => '화요일', '3' => '수요일', '4' => '목요일', '5' => '금요일', '6' => '토요일', '7' => '일요일'];

    $mfgDate = qr_sn_calc_mfg_date($yearCode, $weekCode, $dayCode);
    $warnings = [];

    if ($delimiter1 !== '+') $warnings[] = '14번째 Delimiter가 +가 아닙니다.';
    if ($delimiter2 !== '+') $warnings[] = '20번째 Delimiter가 +가 아닙니다.';
    if (!preg_match('/^[A-Z]$/', $companyCode)) $warnings[] = '회사코드 형식 확인 필요';
    if (!preg_match('/^[A-Z]$/', $plantCode)) $warnings[] = '공장코드 형식 확인 필요';
    if (!preg_match('/^[A-Z]$/', $programCode)) $warnings[] = '프로그램코드 형식 확인 필요';
    if (!preg_match('/^\d{2}$/', $weekCode)) $warnings[] = '주차는 숫자 2자리여야 합니다.';
    if (!preg_match('/^[1-7]$/', $dayCode)) $warnings[] = '요일 코드는 1~7이어야 합니다.';
    if (!preg_match('/^\d{5}$/', $sequence)) $warnings[] = '생산순서는 숫자 5자리여야 합니다.';
    if (!preg_match('/^\d{2}$/', $equipmentNo)) $warnings[] = '설비번호는 숫자 2자리여야 합니다.';
    if (!preg_match('/^[1-9]$/', $cavity)) $warnings[] = '캐비티는 숫자 1자리여야 합니다.';

    return [
        'sn_code' => $sn,
        'company_code' => $companyCode,
        'company_name' => $companyMap[$companyCode] ?? '미등록 회사코드',
        'plant_code' => $plantCode,
        'plant_name' => $plantMap[$plantCode] ?? '미등록 공장코드',
        'program_code' => $programCode,
        'program_name' => $programMap[$programCode] ?? '미등록 프로그램코드',
        'year_code' => $yearCode,
        'year' => qr_sn_year_from_code($yearCode),
        'week_code' => $weekCode,
        'day_code' => $dayCode,
        'weekday_name' => $weekdayMap[$dayCode] ?? $dayCode,
        'mfg_date' => $mfgDate,
        'sequence_code' => $sequence,
        'model_code' => $typeCode,
        'model_name' => $modelMap[$typeCode] ?? '미등록 모델코드',
        // 이전 버전 호환용
        'type_code' => $typeCode,
        'type_name' => $modelMap[$typeCode] ?? '미등록 모델코드',
        'delimiter1' => $delimiter1,
        'line_code' => $lineCode,
        'equipment_no' => $equipmentNo,
        'mold_code' => $moldCode,
        'cavity' => $cavity,
        'delimiter2' => $delimiter2,
        'revision' => $revision,
        'warnings' => $warnings,
        'display_rows' => [
            ['회사', $companyCode, $companyMap[$companyCode] ?? '미등록 회사코드'],
            ['공장', $plantCode, $plantMap[$plantCode] ?? '미등록 공장코드'],
            ['프로그램', $programCode, $programMap[$programCode] ?? '미등록 프로그램코드'],
            ['제조일자', $mfgDate !== '' ? $mfgDate : ($yearCode . $weekCode . $dayCode), '년도 ' . $yearCode . ' / ' . $weekCode . '주차 / ' . ($weekdayMap[$dayCode] ?? $dayCode)],
            ['생산순서', $sequence, '생산순서 숫자 5자리'],
            ['모델', $typeCode, $modelMap[$typeCode] ?? '미등록 모델코드'],
            ['생산라인', $lineCode, $lineCode . ' 라인'],
            ['설비번호', $equipmentNo, $lineCode . '열 ' . ((int)$equipmentNo) . '번째 설비'],
            ['금형번호', $moldCode, '금형번호 / 제품 Version'],
            ['캐비티', $cavity, $cavity . ' Cavity'],
            ['리비전', $revision, '리비전 ' . $revision],
        ],
    ];
}

function qr_sn_column_exists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function qr_sn_add_column_if_missing(PDO $pdo, string $column, string $definition): void {
    if (!qr_sn_column_exists($pdo, 'qr_sn_lookup_log', $column)) {
        $pdo->exec("ALTER TABLE qr_sn_lookup_log ADD COLUMN {$column} {$definition}");
    }
}

function qr_sn_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS qr_sn_lookup_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_no BIGINT UNSIGNED DEFAULT NULL,
        account_id VARCHAR(100) NOT NULL DEFAULT '',
        scanner_name VARCHAR(100) DEFAULT NULL,
        sn_code VARCHAR(80) NOT NULL,
        company_code CHAR(1) DEFAULT NULL,
        company_name VARCHAR(80) DEFAULT NULL,
        plant_code CHAR(1) DEFAULT NULL,
        plant_name VARCHAR(80) DEFAULT NULL,
        program_code CHAR(1) DEFAULT NULL,
        program_name VARCHAR(80) DEFAULT NULL,
        year_code CHAR(1) DEFAULT NULL,
        week_code CHAR(2) DEFAULT NULL,
        day_code CHAR(1) DEFAULT NULL,
        mfg_date DATE DEFAULT NULL,
        sequence_code CHAR(5) DEFAULT NULL,
        model_code CHAR(1) DEFAULT NULL,
        model_name VARCHAR(80) DEFAULT NULL,
        type_code CHAR(1) DEFAULT NULL,
        type_name VARCHAR(80) DEFAULT NULL,
        line_code CHAR(1) DEFAULT NULL,
        equipment_no CHAR(2) DEFAULT NULL,
        mold_code CHAR(1) DEFAULT NULL,
        cavity CHAR(1) DEFAULT NULL,
        revision CHAR(1) DEFAULT NULL,
        warnings TEXT DEFAULT NULL,
        remote_ip VARCHAR(45) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_account_created (account_id, created_at),
        KEY idx_account_no_created (account_no, created_at),
        KEY idx_sn_code (sn_code),
        KEY idx_mfg_date (mfg_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    qr_sn_add_column_if_missing($pdo, 'model_code', "CHAR(1) DEFAULT NULL AFTER sequence_code");
    qr_sn_add_column_if_missing($pdo, 'model_name', "VARCHAR(80) DEFAULT NULL AFTER model_code");
    qr_sn_add_column_if_missing($pdo, 'type_code', "CHAR(1) DEFAULT NULL AFTER model_name");
    qr_sn_add_column_if_missing($pdo, 'type_name', "VARCHAR(80) DEFAULT NULL AFTER type_code");
    qr_sn_add_column_if_missing($pdo, 'line_code', "CHAR(1) DEFAULT NULL AFTER type_name");
    qr_sn_add_column_if_missing($pdo, 'equipment_no', "CHAR(2) DEFAULT NULL AFTER line_code");
    qr_sn_add_column_if_missing($pdo, 'mold_code', "CHAR(1) DEFAULT NULL AFTER equipment_no");
    qr_sn_add_column_if_missing($pdo, 'cavity', "CHAR(1) DEFAULT NULL AFTER mold_code");
    qr_sn_add_column_if_missing($pdo, 'revision', "CHAR(1) DEFAULT NULL AFTER cavity");
}

function qr_sn_insert_lookup(PDO $pdo, array $parsed): int {
    $accountNo = qr_current_account_no();
    $accountId = qr_current_account_id();
    $scannerName = qr_current_scanner_name();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $st = $pdo->prepare("INSERT INTO qr_sn_lookup_log
        (account_no, account_id, scanner_name, sn_code, company_code, company_name, plant_code, plant_name, program_code, program_name, year_code, week_code, day_code, mfg_date, sequence_code, model_code, model_name, type_code, type_name, line_code, equipment_no, mold_code, cavity, revision, warnings, remote_ip, user_agent)
        VALUES
        (:account_no, :account_id, :scanner_name, :sn_code, :company_code, :company_name, :plant_code, :plant_name, :program_code, :program_name, :year_code, :week_code, :day_code, :mfg_date, :sequence_code, :model_code, :model_name, :type_code, :type_name, :line_code, :equipment_no, :mold_code, :cavity, :revision, :warnings, :remote_ip, :user_agent)");

    $st->bindValue(':account_no', $accountNo, $accountNo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':account_id', $accountId, PDO::PARAM_STR);
    $st->bindValue(':scanner_name', $scannerName, PDO::PARAM_STR);
    $st->bindValue(':sn_code', $parsed['sn_code'], PDO::PARAM_STR);
    $st->bindValue(':company_code', $parsed['company_code'], PDO::PARAM_STR);
    $st->bindValue(':company_name', $parsed['company_name'], PDO::PARAM_STR);
    $st->bindValue(':plant_code', $parsed['plant_code'], PDO::PARAM_STR);
    $st->bindValue(':plant_name', $parsed['plant_name'], PDO::PARAM_STR);
    $st->bindValue(':program_code', $parsed['program_code'], PDO::PARAM_STR);
    $st->bindValue(':program_name', $parsed['program_name'], PDO::PARAM_STR);
    $st->bindValue(':year_code', $parsed['year_code'], PDO::PARAM_STR);
    $st->bindValue(':week_code', $parsed['week_code'], PDO::PARAM_STR);
    $st->bindValue(':day_code', $parsed['day_code'], PDO::PARAM_STR);
    if (($parsed['mfg_date'] ?? '') === '') $st->bindValue(':mfg_date', null, PDO::PARAM_NULL);
    else $st->bindValue(':mfg_date', $parsed['mfg_date'], PDO::PARAM_STR);
    $st->bindValue(':sequence_code', $parsed['sequence_code'], PDO::PARAM_STR);
    $st->bindValue(':model_code', $parsed['model_code'], PDO::PARAM_STR);
    $st->bindValue(':model_name', $parsed['model_name'], PDO::PARAM_STR);
    $st->bindValue(':type_code', $parsed['type_code'], PDO::PARAM_STR);
    $st->bindValue(':type_name', $parsed['type_name'], PDO::PARAM_STR);
    $st->bindValue(':line_code', $parsed['line_code'], PDO::PARAM_STR);
    $st->bindValue(':equipment_no', $parsed['equipment_no'], PDO::PARAM_STR);
    $st->bindValue(':mold_code', $parsed['mold_code'], PDO::PARAM_STR);
    $st->bindValue(':cavity', $parsed['cavity'], PDO::PARAM_STR);
    $st->bindValue(':revision', $parsed['revision'], PDO::PARAM_STR);
    $st->bindValue(':warnings', qr_sn_json_encode_warnings($parsed['warnings'] ?? []), PDO::PARAM_STR);
    $st->bindValue(':remote_ip', $ip, PDO::PARAM_STR);
    $st->bindValue(':user_agent', $ua, PDO::PARAM_STR);
    $st->execute();

    return (int)$pdo->lastInsertId();
}

function qr_sn_fetch_recent(PDO $pdo, int $limit = 80): array {
    $accountNo = qr_current_account_no();
    $accountId = qr_current_account_id();
    $limit = max(1, min(500, $limit));

    if ($accountNo !== null) {
        $st = $pdo->prepare("SELECT * FROM qr_sn_lookup_log WHERE account_no = :account_no ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        $st->execute([':account_no' => $accountNo]);
    } else {
        $st = $pdo->prepare("SELECT * FROM qr_sn_lookup_log WHERE account_id = :account_id ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        $st->execute([':account_id' => $accountId]);
    }

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function qr_sn_csv_download(PDO $pdo): void {
    $rows = qr_sn_fetch_recent($pdo, 500);
    $fileName = 'sn_lookup_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['조회시간', 'SN', '회사', '공장', '프로그램', '제조일자', '생산순서', '모델', '생산라인', '설비번호', '금형번호', '캐비티', '리비전', '주의사항']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'] ?? '',
            $row['sn_code'] ?? '',
            $row['company_name'] ?? '',
            $row['plant_name'] ?? '',
            $row['program_name'] ?? '',
            $row['mfg_date'] ?? '',
            $row['sequence_code'] ?? '',
            $row['model_name'] ?? ($row['type_name'] ?? ''),
            $row['line_code'] ?? '',
            $row['equipment_no'] ?? '',
            $row['mold_code'] ?? '',
            $row['cavity'] ?? '',
            $row['revision'] ?? '',
            $row['warnings'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}
