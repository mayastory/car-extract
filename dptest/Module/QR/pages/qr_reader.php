<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once JTMES_ROOT . '/inc/common.php';
$configFile = JTMES_ROOT . '/config/dp_config.php';
if (is_file($configFile)) {
    require_once $configFile;
}
require_once JTMES_ROOT . '/lib/auth_guard.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (function_exists('dp_auth_guard')) {
    dp_auth_guard();
} else {
    dp_require_login();
}

date_default_timezone_set('Asia/Seoul');

function qr_json(array $data, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qr_read_json(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function qr_current_account_no(): ?int {
    foreach (['ship_user_no', 'user_no', 'dp_user_no', 'account_no', 'no', 'No'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) return (int)$_SESSION[$key];
    }
    return null;
}

function qr_current_account_id(): string {
    foreach (['ship_user_id', 'user_id', 'userid', 'username', 'id', 'ID'] as $key) {
        if (!empty($_SESSION[$key])) return (string)$_SESSION[$key];
    }
    return '';
}

function qr_current_scanner_name(): string {
    foreach (['ship_user_name', 'user_name', 'username', 'name', 'ship_user_id', 'user_id'] as $key) {
        if (!empty($_SESSION[$key])) return (string)$_SESSION[$key];
    }
    return qr_current_account_id();
}

require_once __DIR__ . '/../lib/sn_lookup.php';

function qr_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS qr_scan_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_no BIGINT UNSIGNED DEFAULT NULL,
        account_id VARCHAR(100) NOT NULL DEFAULT '',
        scanner_name VARCHAR(100) DEFAULT NULL,
        scan_source VARCHAR(50) NOT NULL DEFAULT '',
        raw_code VARCHAR(255) NOT NULL,
        label_code VARCHAR(100) DEFAULT NULL,
        barcode VARCHAR(100) DEFAULT NULL,
        dp_code VARCHAR(100) DEFAULT NULL,
        model_suffix CHAR(3) DEFAULT NULL,
        model_name VARCHAR(50) DEFAULT NULL,
        lot_date DATE DEFAULT NULL,
        cavity VARCHAR(10) DEFAULT NULL,
        tool VARCHAR(10) DEFAULT NULL,
        ea INT UNSIGNED DEFAULT NULL,
        remote_ip VARCHAR(45) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_account_created (account_id, created_at),
        KEY idx_account_no_created (account_no, created_at),
        KEY idx_barcode (barcode),
        KEY idx_dp_code (dp_code),
        KEY idx_lot_model (lot_date, model_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function qr_excel_weekday_index_from_code(int $dayDigit): ?int {
    $map = [1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 7, 7 => 1];
    return $map[$dayDigit] ?? null;
}

function qr_calc_lot_from_dp(string $dp): string {
    $dp = trim($dp);
    if (strlen($dp) < 7) return '';

    $yearDigit = substr($dp, 3, 1);
    $weekText = substr($dp, 4, 2);
    $dayText = substr($dp, 6, 1);
    if (!ctype_digit($yearDigit) || !ctype_digit($weekText) || !ctype_digit($dayText)) return '';

    $year = (int)('202' . $yearDigit);
    $week = (int)$weekText;
    $dayDigit = (int)$dayText;
    if ($week < 1 || $week > 54) return '';

    $findIndex = qr_excel_weekday_index_from_code($dayDigit);
    if ($findIndex === null) return '';

    try {
        $data = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $data = $data->modify('+' . (7 * ($week - 1)) . ' days');
        $weekdayData = (int)$data->format('N');
        $offset = $findIndex - $weekdayData - 1;
        return $data->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    } catch (Throwable $e) {
        return '';
    }
}

function qr_model_from_suffix(string $suffix): string {
    $suffix = strtoupper(trim($suffix));
    $map = [
        'BBM' => 'MEM-IR-BASE',
        'BXM' => 'MEM-X-CARRIER',
        'BYM' => 'MEM-Y-CARRIER',
        'BZM' => 'MEM-Z-CARRIER',
        'BSM' => 'MEM-Z-STOPPER',
    ];
    return $map[$suffix] ?? $suffix;
}

function qr_parse_scan_code(string $raw): array {
    $raw = trim($raw);
    $parts = array_values(array_filter(array_map('trim', explode('/', $raw)), static fn($v) => $v !== ''));

    $labelCode = $parts[0] ?? '';
    $ea = null;
    $dp = '';

    foreach ($parts as $part) {
        if ($ea === null && preg_match('/^\d+$/', $part)) {
            $ea = (int)$part;
        }
        if ($dp === '' && preg_match('/^DP/i', $part)) {
            $dp = $part;
        }
    }

    if ($dp === '' && !empty($parts)) {
        $dp = $parts[count($parts) - 1];
    }

    $dpUpper = strtoupper($dp);
    $modelSuffix = strlen($dpUpper) >= 3 ? substr($dpUpper, -3) : '';

    return [
        'raw_code' => $raw,
        'label_code' => $labelCode,
        'barcode' => $dp,
        'dp_code' => $dp,
        'model_suffix' => $modelSuffix,
        'model_name' => qr_model_from_suffix($modelSuffix),
        'lot_date' => qr_calc_lot_from_dp($dp),
        'tool' => strlen($dp) >= 12 ? substr($dp, 11, 1) : '',
        'cavity' => strlen($dp) >= 13 ? substr($dp, 12, 1) : '',
        'ea' => $ea,
    ];
}

function qr_normalize_tool_cavity_from_dp(array $row): array {
    $dp = (string)($row['dp_code'] ?? '');
    if (strlen($dp) >= 13) {
        $row['tool'] = substr($dp, 11, 1);
        $row['cavity'] = substr($dp, 12, 1);
    }
    return $row;
}

function qr_split_scan_codes(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];

    $parts = preg_split('/[\r\n,;]+/', $raw);
    $codes = [];
    $seen = [];

    foreach ($parts as $part) {
        $code = trim((string)$part);
        if ($code === '') continue;
        if (isset($seen[$code])) continue;
        $seen[$code] = true;
        $codes[] = $code;
    }

    return $codes;
}

function qr_fetch_recent(PDO $pdo, int $limit = 80): array {
    $accountNo = qr_current_account_no();
    $accountId = qr_current_account_id();
    $limit = max(1, min(300, $limit));
    $scanLimit = max($limit * 10, 300);

    if ($accountNo !== null) {
        $st = $pdo->prepare("SELECT id, account_no, account_id, scanner_name, scan_source, raw_code, label_code, barcode, dp_code, model_suffix, model_name, lot_date, cavity, tool, ea, remote_ip, created_at
            FROM qr_scan_log
            WHERE account_no = :account_no
            ORDER BY created_at DESC, id DESC
            LIMIT {$scanLimit}");
        $st->execute([':account_no' => $accountNo]);
    } else {
        $st = $pdo->prepare("SELECT id, account_no, account_id, scanner_name, scan_source, raw_code, label_code, barcode, dp_code, model_suffix, model_name, lot_date, cavity, tool, ea, remote_ip, created_at
            FROM qr_scan_log
            WHERE account_id = :account_id
            ORDER BY created_at DESC, id DESC
            LIMIT {$scanLimit}");
        $st->execute([':account_id' => $accountId]);
    }

    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $seen = [];
    $out = [];

    foreach ($rows as $row) {
        $key = trim((string)($row['dp_code'] ?? ''));
        if ($key === '') $key = trim((string)($row['barcode'] ?? ''));
        if ($key === '') $key = trim((string)($row['raw_code'] ?? ''));
        if ($key === '') $key = 'id:' . (string)($row['id'] ?? '');

        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $row = qr_normalize_tool_cavity_from_dp($row);
        $out[] = $row;

        if (count($out) >= $limit) break;
    }

    return $out;
}

function qr_clear_history(PDO $pdo): int {
    $accountNo = qr_current_account_no();
    $accountId = qr_current_account_id();

    if ($accountNo !== null) {
        $st = $pdo->prepare("DELETE FROM qr_scan_log WHERE account_no = :account_no");
        $st->bindValue(':account_no', $accountNo, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount();
    }

    $st = $pdo->prepare("DELETE FROM qr_scan_log WHERE account_id = :account_id");
    $st->bindValue(':account_id', $accountId, PDO::PARAM_STR);
    $st->execute();
    return $st->rowCount();
}

function qr_duplicate_scan_id(PDO $pdo, array $parsed): ?int {
    $accountNo = qr_current_account_no();
    $accountId = qr_current_account_id();
    $dpCode = trim((string)($parsed['dp_code'] ?? ''));
    $barcode = trim((string)($parsed['barcode'] ?? ''));
    $rawCode = trim((string)($parsed['raw_code'] ?? ''));

    $conds = [];
    $params = [];

    if ($accountNo !== null) {
        $conds[] = 'account_no = :account_no';
        $params[':account_no'] = $accountNo;
    } else {
        $conds[] = 'account_id = :account_id';
        $params[':account_id'] = $accountId;
    }

    $codeConds = [];
    if ($dpCode !== '') {
        $codeConds[] = 'dp_code = :dp_code';
        $params[':dp_code'] = $dpCode;
    }
    if ($barcode !== '') {
        $codeConds[] = 'barcode = :barcode';
        $params[':barcode'] = $barcode;
    }
    if ($rawCode !== '') {
        $codeConds[] = 'raw_code = :raw_code';
        $params[':raw_code'] = $rawCode;
    }

    if (!$codeConds) return null;

    $sql = "SELECT id FROM qr_scan_log WHERE " . implode(' AND ', $conds) . " AND (" . implode(' OR ', $codeConds) . ") ORDER BY created_at DESC, id DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':account_no') $st->bindValue($key, (int)$value, PDO::PARAM_INT);
        else $st->bindValue($key, (string)$value, PDO::PARAM_STR);
    }
    $st->execute();
    $id = $st->fetchColumn();

    return $id ? (int)$id : null;
}

function qr_insert_scan(PDO $pdo, array $parsed, string $source): int {
    $accountNo = qr_current_account_no();
    $accountId = qr_current_account_id();
    $scannerName = qr_current_scanner_name();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $st = $pdo->prepare("INSERT INTO qr_scan_log
        (account_no, account_id, scanner_name, scan_source, raw_code, label_code, barcode, dp_code, model_suffix, model_name, lot_date, cavity, tool, ea, remote_ip, user_agent)
        VALUES
        (:account_no, :account_id, :scanner_name, :scan_source, :raw_code, :label_code, :barcode, :dp_code, :model_suffix, :model_name, :lot_date, :cavity, :tool, :ea, :remote_ip, :user_agent)");

    $st->bindValue(':account_no', $accountNo, $accountNo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':account_id', $accountId, PDO::PARAM_STR);
    $st->bindValue(':scanner_name', $scannerName, PDO::PARAM_STR);
    $st->bindValue(':scan_source', $source, PDO::PARAM_STR);
    $st->bindValue(':raw_code', $parsed['raw_code'], PDO::PARAM_STR);
    $st->bindValue(':label_code', $parsed['label_code'], PDO::PARAM_STR);
    $st->bindValue(':barcode', $parsed['barcode'], PDO::PARAM_STR);
    $st->bindValue(':dp_code', $parsed['dp_code'], PDO::PARAM_STR);
    $st->bindValue(':model_suffix', $parsed['model_suffix'], PDO::PARAM_STR);
    $st->bindValue(':model_name', $parsed['model_name'], PDO::PARAM_STR);
    if ($parsed['lot_date'] === '') $st->bindValue(':lot_date', null, PDO::PARAM_NULL);
    else $st->bindValue(':lot_date', $parsed['lot_date'], PDO::PARAM_STR);
    $st->bindValue(':cavity', $parsed['cavity'], PDO::PARAM_STR);
    $st->bindValue(':tool', $parsed['tool'], PDO::PARAM_STR);
    if ($parsed['ea'] === null) $st->bindValue(':ea', null, PDO::PARAM_NULL);
    else $st->bindValue(':ea', (int)$parsed['ea'], PDO::PARAM_INT);
    $st->bindValue(':remote_ip', $ip, PDO::PARAM_STR);
    $st->bindValue(':user_agent', $ua, PDO::PARAM_STR);
    $st->execute();

    return (int)$pdo->lastInsertId();
}

function qr_csv_download(PDO $pdo): void {
    $rows = qr_fetch_recent($pdo, 300);
    $fileName = 'qr_scan_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Scanned At', 'Account ID', 'Scanner', 'Source', 'Raw', 'Label Code', 'Barcode', 'DP', 'Model Suffix', 'Model', 'LOT', 'Tool', 'Cavity', 'ea', 'IP']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'] ?? '',
            $row['account_id'] ?? '',
            $row['scanner_name'] ?? '',
            $row['scan_source'] ?? '',
            $row['raw_code'] ?? '',
            $row['label_code'] ?? '',
            $row['barcode'] ?? '',
            $row['dp_code'] ?? '',
            $row['model_suffix'] ?? '',
            $row['model_name'] ?? '',
            $row['lot_date'] ?? '',
            $row['tool'] ?? '',
            $row['cavity'] ?? '',
            $row['ea'] ?? '',
            $row['remote_ip'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

try {
    if (!function_exists('dp_get_pdo')) {
        throw new RuntimeException('dp_get_pdo()를 찾을 수 없습니다. config/dp_config.php의 DB 연결 설정을 확인해 주세요.');
    }
    $pdo = dp_get_pdo();
    qr_ensure_schema($pdo);
    qr_sn_ensure_schema($pdo);
} catch (Throwable $e) {
    if (isset($_GET['ajax'])) {
        qr_json(['ok' => false, 'message' => 'DB 연결 또는 테이블 준비 실패: ' . $e->getMessage()], 500);
    }
    $fatalMessage = 'DB 연결 또는 테이블 준비 실패: ' . $e->getMessage();
    $pdo = null;
}

if ($pdo && isset($_GET['download']) && $_GET['download'] === 'csv') {
    qr_csv_download($pdo);
}
if ($pdo && isset($_GET['download']) && $_GET['download'] === 'sn_csv') {
    qr_sn_csv_download($pdo);
}

if ($pdo && isset($_GET['ajax'])) {
    $action = (string)($_GET['action'] ?? '');
    try {
        if ($action === 'load') {
            qr_json(['ok' => true, 'rows' => qr_fetch_recent($pdo, 80), 'account_id' => qr_current_account_id(), 'account_no' => qr_current_account_no()]);
        }
        if ($action === 'save') {
            $body = qr_read_json();
            $code = trim((string)($body['code'] ?? ''));
            $source = trim((string)($body['source'] ?? 'manual'));
            if ($code === '') qr_json(['ok' => false, 'message' => '코드값이 비어 있습니다.'], 400);
            $parsed = qr_parse_scan_code($code);
            $duplicateId = qr_duplicate_scan_id($pdo, $parsed);
            if ($duplicateId !== null) {
                qr_json([
                    'ok' => true,
                    'duplicate' => true,
                    'id' => $duplicateId,
                    'message' => '이미 저장된 코드입니다.',
                    'parsed' => $parsed,
                    'rows' => qr_fetch_recent($pdo, 80)
                ]);
            }
            $id = qr_insert_scan($pdo, $parsed, $source);
            qr_json(['ok' => true, 'id' => $id, 'parsed' => $parsed, 'rows' => qr_fetch_recent($pdo, 80)]);
        }
        if ($action === 'save_multi') {
            $body = qr_read_json();
            $raw = trim((string)($body['code'] ?? ''));
            $source = trim((string)($body['source'] ?? 'manual'));
            $codes = qr_split_scan_codes($raw);
            if (!$codes) qr_json(['ok' => false, 'message' => '코드값이 비어 있습니다.'], 400);

            $ids = [];
            $parsedList = [];
            $skipped = [];

            foreach ($codes as $code) {
                $parsed = qr_parse_scan_code($code);
                $duplicateId = qr_duplicate_scan_id($pdo, $parsed);
                if ($duplicateId !== null) {
                    $skipped[] = $parsed['dp_code'] ?: $code;
                    continue;
                }

                $ids[] = qr_insert_scan($pdo, $parsed, $source);
                $parsedList[] = $parsed;
            }

            qr_json([
                'ok' => true,
                'ids' => $ids,
                'inserted_count' => count($parsedList),
                'skipped_count' => count($skipped),
                'skipped' => $skipped,
                'parsed_list' => $parsedList,
                'rows' => qr_fetch_recent($pdo, 80)
            ]);
        }
        if ($action === 'sn_lookup') {
            $body = qr_read_json();
            $snRaw = trim((string)($body['sn'] ?? ''));
            $codes = qr_sn_split_codes($snRaw);
            if (!$codes) qr_json(['ok' => false, 'message' => 'SN을 입력해 주세요.'], 400);

            $parsedList = [];
            $ids = [];
            $skipped = [];

            foreach ($codes as $sn) {
                $duplicateId = qr_sn_duplicate_lookup_id($pdo, $sn);
                if ($duplicateId !== null) {
                    $skipped[] = $sn;
                    continue;
                }

                $parsed = qr_sn_parse($sn);
                $ids[] = qr_sn_insert_lookup($pdo, $parsed);
                $parsedList[] = $parsed;
            }

            qr_json([
                'ok' => true,
                'ids' => $ids,
                'count' => count($parsedList),
                'inserted_count' => count($parsedList),
                'skipped_count' => count($skipped),
                'skipped' => $skipped,
                'duplicate' => count($parsedList) === 0 && count($skipped) > 0,
                'parsed' => $parsedList[0] ?? null,
                'parsed_list' => $parsedList,
                'rows' => qr_sn_fetch_recent($pdo, 80)
            ]);
        }
        if ($action === 'sn_clear_history') {
            $deleted = qr_sn_clear_history($pdo);
            qr_json(['ok' => true, 'deleted' => $deleted, 'rows' => qr_sn_fetch_recent($pdo, 80)]);
        }
        if ($action === 'clear_history') {
            $deleted = qr_clear_history($pdo);
            qr_json(['ok' => true, 'deleted' => $deleted, 'rows' => qr_fetch_recent($pdo, 80)]);
        }
        qr_json(['ok' => false, 'message' => '지원하지 않는 요청입니다.'], 400);
    } catch (Throwable $e) {
        qr_json(['ok' => false, 'message' => $e->getMessage()], 500);
    }
}

$embed = !empty($_GET['embed']);
$accountId = qr_current_account_id();
$recentRows = $pdo ? qr_fetch_recent($pdo, 20) : [];
$snRecentRows = $pdo ? qr_sn_fetch_recent($pdo, 80) : [];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>QR 리더기</title>
<style>
:root{--bg:#071025;--card:#0b1430;--card2:#101b3c;--line:#223056;--text:#eef2ff;--muted:#a9b4d0;--accent:#3f6df1;--green:#2ee66b;--green2:#9cff8f;--danger:#ff7676;--warn:#ffe58b;}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans KR",sans-serif;background:transparent;color:var(--text);} .page{max-width:1180px;margin:0 auto;padding:18px 16px 40px;}
.card{background:rgba(8,18,42,.94);border:1px solid rgba(120,145,205,.22);border-radius:24px;padding:16px;box-shadow:0 18px 42px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.04);margin-bottom:14px;}
h1{font-size:24px;margin:0 0 8px;}h2{font-size:17px;margin:0 0 12px}.muted{color:var(--muted)}.ok{color:#8effa1}.bad{color:#ffb0b0}.warn{color:#ffe58b}.small{font-size:13px;line-height:1.45}
.topline{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}.topline h1{margin:0}.badge{border:1px solid rgba(156,255,143,.35);background:rgba(46,230,107,.08);color:#dfffe6;border-radius:999px;padding:8px 11px;font-size:13px;font-weight:800;}
.scanGrid{display:grid;grid-template-columns:minmax(300px,440px) 1fr;gap:14px;align-items:start}.videoWrap{position:relative;aspect-ratio:3/4;width:100%;max-height:72vh;border-radius:22px;overflow:hidden;background:#000;border:1px solid rgba(255,255,255,.08);}video{width:100%;height:100%;object-fit:cover;background:#000}.mask{position:absolute;inset:0;pointer-events:none;background:rgba(0,0,0,.12)}
.scanArea{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(76vw,360px);height:min(46vw,210px);max-width:84%;border:3px solid rgba(46,230,107,.96);border-radius:24px;box-shadow:0 0 0 9999px rgba(0,0,0,.28),0 0 0 1px rgba(255,255,255,.12) inset,0 0 18px rgba(46,230,107,.25);background:linear-gradient(to bottom,rgba(46,230,107,.04),rgba(46,230,107,.01));}.corner{position:absolute;width:34px;height:34px;border-color:var(--green2);border-style:solid;filter:drop-shadow(0 0 7px rgba(156,255,143,.45))}.tl{left:-3px;top:-3px;border-width:5px 0 0 5px;border-top-left-radius:18px}.tr{right:-3px;top:-3px;border-width:5px 5px 0 0;border-top-right-radius:18px}.bl{left:-3px;bottom:-3px;border-width:0 0 5px 5px;border-bottom-left-radius:18px}.br{right:-3px;bottom:-3px;border-width:0 5px 5px 0;border-bottom-right-radius:18px}.crosshairH,.crosshairV,.crossDot{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%)}.crosshairH{width:52px;height:2px;background:rgba(156,255,143,.95);box-shadow:0 0 10px rgba(156,255,143,.45)}.crosshairV{width:2px;height:52px;background:rgba(156,255,143,.95);box-shadow:0 0 10px rgba(156,255,143,.45)}.crossDot{width:8px;height:8px;border-radius:50%;background:var(--green2);box-shadow:0 0 10px rgba(156,255,143,.85)}.scanLine{position:absolute;left:8px;right:8px;top:12px;height:3px;background:linear-gradient(90deg,rgba(46,230,107,0),rgba(156,255,143,.96),rgba(46,230,107,0));box-shadow:0 0 14px rgba(46,230,107,.7);border-radius:999px;animation:scanMove 2.1s linear infinite;}@keyframes scanMove{0%{top:12px;opacity:.95}45%{opacity:1}100%{top:calc(100% - 15px);opacity:.92}}
.guideText{position:absolute;left:50%;bottom:16px;transform:translateX(-50%);background:rgba(6,14,28,.74);color:#f1fff4;border:1px solid rgba(46,230,107,.35);border-radius:999px;padding:9px 12px;font-size:13px;text-align:center;width:min(92%,480px);backdrop-filter:blur(6px);box-shadow:0 6px 18px rgba(0,0,0,.2);}
.controls{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}button,.btn{appearance:none;border:none;border-radius:16px;padding:14px 14px;font-size:16px;font-weight:800;background:var(--accent);color:#fff;cursor:pointer;text-align:center;text-decoration:none;box-shadow:0 10px 24px rgba(63,109,241,.26);}button.secondary,.btn.secondary{background:#293658;box-shadow:none}textarea.multiInput{width:100%;min-height:46px;max-height:220px;resize:vertical;padding:14px 16px;border-radius:16px;border:1px solid rgba(255,255,255,.09);background:#252f49;color:#fff;font-size:16px;outline:none;font-family:inherit;line-height:1.45;}input[type=text]{width:100%;padding:14px 16px;border-radius:16px;border:1px solid rgba(255,255,255,.09);background:#252f49;color:#fff;font-size:16px;outline:none;}.manualGrid{display:grid;grid-template-columns:1fr auto;gap:10px}.result{font-size:16px;font-weight:800;word-break:break-all;margin-top:12px;line-height:1.5;white-space:pre-line}.statusBox{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:14px;line-height:1.55;font-size:14px;white-space:pre-line;}.diagGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.diagRow{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:start;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.05)}.diagVal{font-weight:800;text-align:right;word-break:break-word}.tableWrap{overflow:auto;border-radius:14px;border:1px solid rgba(255,255,255,.08)}table{width:100%;border-collapse:collapse;font-size:13px;min-width:980px}th,td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.07);text-align:center;white-space:nowrap}th{background:rgba(255,255,255,.06);color:#dce6ff}td{color:#eef2ff}.fatal{border-color:rgba(255,118,118,.5);background:rgba(255,118,118,.08)}
@media (max-width:880px){.scanGrid{grid-template-columns:1fr}.diagGrid{grid-template-columns:1fr}.page{padding:12px}.controls,.manualGrid{grid-template-columns:1fr}h1{font-size:22px}.videoWrap{max-height:70vh}.scanArea{height:min(52vw,220px)}.guideText{font-size:12px}}
.qr-hidden-diagnostics{display:none !important;}
.qr-hidden-status{display:none !important;}
.tableWrap table th,.tableWrap table td{text-align:center !important;vertical-align:middle;}

.tabCard{padding:0 12px 0;overflow:visible;border-radius:18px 18px 0 0}
.qrTabs{display:flex;align-items:flex-end;gap:6px;flex-wrap:wrap;margin:0}
.qrTab{appearance:none;border:1px solid rgba(120,145,205,.34) !important;border-bottom-color:rgba(120,145,205,.28) !important;background:rgba(21,34,58,.68) !important;color:var(--text) !important;padding:12px 18px 11px !important;border-radius:12px 12px 0 0 !important;font-size:16px !important;font-weight:800 !important;line-height:1 !important;text-decoration:none !important;cursor:pointer;box-shadow:none !important;min-height:auto !important;position:relative;overflow:visible}
.qrTab:hover{background:rgba(30,47,78,.82) !important}
.qrTab.active{background:rgba(8,18,42,.98) !important;border-color:rgba(120,145,205,.42) !important;border-top-color:#61d58a !important;border-top-width:4px !important;border-bottom-color:rgba(8,18,42,.98) !important;transform:none !important;box-shadow:none !important;padding-top:9px !important}
.qrTab.download{background:rgba(255,199,206,.20) !important;border-color:rgba(255,199,206,.62) !important;color:#ffe7eb !important}
.qrTab.download:hover{background:rgba(255,199,206,.30) !important;border-color:rgba(255,199,206,.78) !important}
.qrPanel{display:none}
.qrPanel.active{display:block}
@media (max-width:640px){.qrTabs{gap:6px}.qrTab{font-size:14px !important;padding:10px 12px !important}}


.historyHeader{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.historyHeader h2{margin:0}
.clearHistoryBtn{appearance:none;border:1px solid rgba(255,170,170,.55);background:rgba(255,170,170,.14);color:#ffd8d8;border-radius:14px;padding:10px 16px;font-size:14px;font-weight:800;cursor:pointer;box-shadow:none;white-space:nowrap}
.clearHistoryBtn:hover{background:rgba(255,170,170,.24);border-color:rgba(255,170,170,.76)}
.qrPanel{display:none}
.qrPanel.active{display:block}


.snLookupGrid{align-items:center}
.snResult{margin-top:12px;font-size:15px;font-weight:800;line-height:1.55;white-space:pre-line;word-break:break-all}
.snTableWrap{margin-top:12px}
.snTable{min-width:620px}
.snTable th:nth-child(1),.snTable td:nth-child(1){width:170px}
.snTable td:nth-child(2){font-weight:800;color:#eaf2ff}
.snTable td:nth-child(3){color:#c7d4ee}.snCsvBtn{text-decoration:none;display:inline-flex;align-items:center;justify-content:center}

.qrHistoryManualGrid{align-items:center;margin-bottom:0}
.qrHistoryTableWrap{margin-top:12px}

.qrManualTextarea{width:100%;min-height:48px;max-height:120px;resize:vertical;padding:14px 16px;border-radius:16px;border:1px solid rgba(255,255,255,.09);background:#252f49;color:#fff;font-size:16px;outline:none;font-family:inherit}

.tabCard::after{content:none !important;}

/* QR/SN 입력부 녹색 계열 정리 */
.qrPanel textarea.multiInput,
.qrPanel input[type=text]{
    background:rgba(7,37,24,.82) !important;
    border-color:rgba(97,213,138,.42) !important;
    color:#effff3 !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.05), 0 0 0 1px rgba(97,213,138,.06) !important;
}
.qrPanel textarea.multiInput::placeholder,
.qrPanel input[type=text]::placeholder{
    color:rgba(218,245,226,.48) !important;
}
.qrPanel textarea.multiInput:focus,
.qrPanel input[type=text]:focus{
    border-color:rgba(97,213,138,.88) !important;
    box-shadow:0 0 0 3px rgba(97,213,138,.16), inset 0 1px 0 rgba(255,255,255,.06) !important;
}
#manualSaveBtn,
#snLookupBtn{
    background:linear-gradient(180deg, rgba(61,184,104,.96), rgba(28,126,69,.96)) !important;
    color:#f4fff6 !important;
    border:1px solid rgba(144,245,170,.42) !important;
    box-shadow:0 10px 22px rgba(24,112,60,.24), inset 0 1px 0 rgba(255,255,255,.18) !important;
}
#manualSaveBtn:hover,
#snLookupBtn:hover{
    background:linear-gradient(180deg, rgba(76,204,121,.98), rgba(35,145,79,.98)) !important;
}


/* 바코드/SN 수동 입력 textarea까지 녹색 계열 강제 적용 */
#manualCode,
#snInput,
textarea#manualCode,
textarea#snInput,
textarea.multiInput,
.qrPanel textarea,
.qrPanel input[type=text]{
    background:rgba(7,37,24,.88) !important;
    border:1px solid rgba(97,213,138,.58) !important;
    color:#effff3 !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.05), 0 0 0 1px rgba(97,213,138,.08) !important;
    caret-color:#9cffb5 !important;
}
#manualCode::placeholder,
#snInput::placeholder,
textarea#manualCode::placeholder,
textarea#snInput::placeholder,
textarea.multiInput::placeholder,
.qrPanel textarea::placeholder,
.qrPanel input[type=text]::placeholder{
    color:rgba(218,245,226,.48) !important;
}
#manualCode:focus,
#snInput:focus,
textarea#manualCode:focus,
textarea#snInput:focus,
textarea.multiInput:focus,
.qrPanel textarea:focus,
.qrPanel input[type=text]:focus{
    border-color:rgba(97,213,138,.95) !important;
    box-shadow:0 0 0 3px rgba(97,213,138,.18), inset 0 1px 0 rgba(255,255,255,.06) !important;
}

</style>
</head>
<body>
<div class="page">
    <div class="card tabCard">
        <div class="qrTabs" role="tablist" aria-label="QR 리더기 탭">
            <button type="button" class="qrTab active" data-tab-target="reader" aria-selected="true">QR 리더기</button>
            <button type="button" class="qrTab" data-tab-target="history" aria-selected="false">바코드 내역</button>
            <button type="button" class="qrTab" data-tab-target="sn" aria-selected="false">SN 내역</button>
            <a class="qrTab download" id="dynamicCsvDownload" href="?download=csv">QR CSV 다운로드</a>
        </div>
    </div>

    <?php if (!empty($fatalMessage)): ?>
    <div class="card fatal"><div class="result bad"><?= h($fatalMessage) ?></div></div>
    <?php endif; ?>

    <div class="qrPanel active" id="qrPanelReader">
    <div class="scanGrid">
        <div class="card">
            <h2>카메라 스캔</h2>
            <div class="videoWrap">
                <video id="video" autoplay playsinline muted></video>
                <div class="mask"></div>
                <div class="scanArea" aria-hidden="true">
                    <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
                    <div class="crosshairH"></div><div class="crosshairV"></div><div class="crossDot"></div><div class="scanLine"></div>
                </div>
                <div class="guideText">초록 가이드 안에 QR 또는 바코드를 맞춰 주세요.</div>
            </div>
            <div class="controls">
                <button id="startBtn" type="button">카메라 시작</button>
                <button id="stopBtn" type="button" class="secondary">카메라 중지</button>
            </div>
            <div id="result" class="result muted"></div>
        </div>

        <div>
            <div class="qr-hidden-status card">
                <h2>상태</h2>
                <div id="supportMessage" class="statusBox">브라우저 기능 확인 중...</div>
            </div>
            <div class="qr-hidden-diagnostics card">
                <h2>브라우저 진단</h2>
                <div class="diagGrid">
                    <div class="diagRow"><div>protocol</div><div class="diagVal" id="dProtocol"></div></div>
                    <div class="diagRow"><div>secure</div><div class="diagVal" id="dSecure"></div></div>
                    <div class="diagRow"><div>mediaDevices</div><div class="diagVal" id="dMedia"></div></div>
                    <div class="diagRow"><div>getUserMedia</div><div class="diagVal" id="dGum"></div></div>
                    <div class="diagRow"><div>BarcodeDetector</div><div class="diagVal" id="dBarcode"></div></div>
                    <div class="diagRow"><div>ZXing</div><div class="diagVal" id="dZxing"></div></div>
                </div>
                <div class="muted small" id="dUA" style="margin-top:10px;word-break:break-all"></div>
            </div>
        </div>
    </div>
    </div>

    <div class="qrPanel" id="qrPanelSn">
    <div class="card">
        <div class="historyHeader">
            <h2>SN 내역</h2>
            <button type="button" class="clearHistoryBtn" id="snClearHistoryBtn">비우기</button>
        </div>
        <div class="manualGrid snLookupGrid">
            <textarea id="snInput" class="multiInput" placeholder="예: DGVD18510001R+A10A4+B, DGMG13445216B+E08H4+B" autocomplete="off"></textarea>
            <button id="snLookupBtn" type="button">저장</button>
        </div>
        <div id="snResult" class="snResult muted">SN을 입력하고 저장을 눌러 주세요.</div>
        <div class="tableWrap snTableWrap" id="snTableWrap" style="display:none">
            <table class="snTable">
                <thead>
                    <tr><th>항목</th><th>값</th><th>정의</th></tr>
                </thead>
                <tbody id="snRowsBody"></tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h2>SN 조회 내역</h2>
        <div class="tableWrap">
            <table>
                <thead>
                    <tr><th>조회시간</th><th>SN</th><th>제조일자</th><th>회사</th><th>공장</th><th>프로그램</th><th>생산순서</th><th>모델</th><th>라인</th><th>설비</th><th>금형</th><th>캐비티</th><th>리비전</th></tr>
                </thead>
                <tbody id="snHistoryBody">
                <?php foreach ($snRecentRows as $row): ?>
                    <tr>
                        <td><?= h((string)($row['created_at'] ?? '')) ?></td>
                        <td><?= h((string)($row['sn_code'] ?? '')) ?></td>
                        <td><?= h((string)($row['mfg_date'] ?? '')) ?></td>
                        <td><?= h((string)($row['company_name'] ?? '')) ?></td>
                        <td><?= h((string)($row['plant_name'] ?? '')) ?></td>
                        <td><?= h((string)($row['program_name'] ?? '')) ?></td>
                        <td><?= h(trim((string)($row['sequence_shift_name'] ?? '') . ' ' . (string)($row['sequence_no_name'] ?? ''))) ?></td>
                        <td><?= h((string)($row['model_name'] ?? ($row['type_name'] ?? ''))) ?></td>
                        <td><?= h((string)($row['line_code'] ?? '')) ?></td>
                        <td><?= h((string)($row['equipment_no'] ?? '')) ?></td>
                        <td><?= h((string)($row['mold_code'] ?? '')) ?></td>
                        <td><?= h((string)($row['cavity'] ?? '')) ?></td>
                        <td><?= h((string)($row['revision'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>

    <div class="qrPanel" id="qrPanelHistory">
    <div class="card">
        <div class="historyHeader">
            <h2>바코드 내역</h2>
            <button type="button" class="clearHistoryBtn" id="clearHistoryBtn">비우기</button>
        </div>
        <div class="manualGrid qrHistoryManualGrid">
            <textarea id="manualCode" class="qrManualTextarea" placeholder="예: 3HUR00021A/480/DP261672730G3BZM, 3HUR00021A/480/DP261672730G3BZM"></textarea>
            <button id="manualSaveBtn" type="button">저장</button>
        </div>
        <div id="manualResult" class="snResult muted">QR을 직접 입력하거나 여러 개를 콤마/줄바꿈으로 붙여 넣을 수 있습니다.</div>
        <div class="tableWrap qrHistoryTableWrap">
            <table>
                <thead>
                    <tr><th>시간</th><th>모델</th><th>LOT</th><th>Tool</th><th>Cavity</th><th>ea</th><th>DP</th></tr>
                </thead>
                <tbody id="rowsBody">
                <?php foreach ($recentRows as $row): ?>
                    <tr>
                        <td><?= h((string)($row['created_at'] ?? '')) ?></td>
                        <td><?= h((string)($row['model_name'] ?? '')) ?></td>
                        <td><?= h((string)($row['lot_date'] ?? '')) ?></td>
                        <td><?= h((string)($row['tool'] ?? '')) ?></td>
                        <td><?= h((string)($row['cavity'] ?? '')) ?></td>
                        <td><?= h((string)($row['ea'] ?? '')) ?></td>
                        <td><?= h((string)($row['dp_code'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
</div>
<audio id="qrSuccessSound" src="../sound/Coin.wav" preload="auto" playsinline></audio>
<script src="https://unpkg.com/@zxing/library@0.21.3/umd/index.min.js"></script>
<script>
(() => {
    const video = document.getElementById('video');
    const resultEl = document.getElementById('result');
    const supportMessage = document.getElementById('supportMessage');
    const manualCode = document.getElementById('manualCode');
    const manualResult = document.getElementById('manualResult');
    const rowsBody = document.getElementById('rowsBody');
    const qrSuccessSound = document.getElementById('qrSuccessSound');
    const clearHistoryBtn = document.getElementById('clearHistoryBtn');
    const tabButtons = Array.from(document.querySelectorAll('[data-tab-target]'));
    const snInput = document.getElementById('snInput');
    const snLookupBtn = document.getElementById('snLookupBtn');
    const snResult = document.getElementById('snResult');
    const snRowsBody = document.getElementById('snRowsBody');
    const snTableWrap = document.getElementById('snTableWrap');
    const dynamicCsvDownload = document.getElementById('dynamicCsvDownload');
    const snHistoryBody = document.getElementById('snHistoryBody');
    const snClearHistoryBtn = document.getElementById('snClearHistoryBtn');
    const hasSecure = window.isSecureContext === true;
    const hasMediaDevices = !!navigator.mediaDevices;
    const hasGetUserMedia = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    const hasBarcodeDetector = typeof window.BarcodeDetector !== 'undefined';
    const hasZXing = !!(window.ZXing && window.ZXing.BrowserMultiFormatReader);
    let stream = null;
    let detectorTimer = null;
    let zxingReader = null;
    let scanning = false;
    const lastSavedByCode = new Map();
    let userTabClicking = false;

    setDiag('dProtocol', location.protocol, true);
    setDiag('dSecure', String(hasSecure), hasSecure);
    setDiag('dMedia', String(hasMediaDevices), hasMediaDevices);
    setDiag('dGum', String(hasGetUserMedia), hasGetUserMedia);
    setDiag('dBarcode', String(hasBarcodeDetector), hasBarcodeDetector);
    setDiag('dZxing', hasZXing ? '사용 가능' : '로드 실패', hasZXing);
    document.getElementById('dUA').textContent = navigator.userAgent;
    supportMessage.textContent = '';

    function activateTab(name) {
        if (!userTabClicking) return;
        document.querySelectorAll('.qrPanel').forEach(panel => panel.classList.remove('active'));
        tabButtons.forEach(btn => {
            const active = btn.dataset.tabTarget === name;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        const panelMap = {
            reader: 'qrPanelReader',
            history: 'qrPanelHistory',
            sn: 'qrPanelSn'
        };
        const panel = document.getElementById(panelMap[name] || 'qrPanelReader');
        if (panel) panel.classList.add('active');

        if (dynamicCsvDownload) {
            const url = new URL(location.href);
            url.searchParams.delete('ajax');
            url.searchParams.delete('action');
            if (name === 'sn') {
                url.searchParams.set('download', 'sn_csv');
                dynamicCsvDownload.textContent = 'SN CSV 다운로드';
            } else {
                url.searchParams.set('download', 'csv');
                dynamicCsvDownload.textContent = 'QR CSV 다운로드';
            }
            dynamicCsvDownload.href = url.toString();
        }
    }
    function ajaxUrl(action) {
        const url = new URL(location.href);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('action', action);
        url.searchParams.delete('download');
        return url.toString();
    }
    function setDiag(id, text, ok) {
        const el = document.getElementById(id);
        el.textContent = text;
        el.className = 'diagVal ' + (ok ? 'ok' : 'warn');
    }
    function supportText() {
        return '';
    }
    function playSuccessSound() {
        if (!qrSuccessSound) return;
        try {
            qrSuccessSound.currentTime = 0;
            const p = qrSuccessSound.play();
            if (p && typeof p.catch === 'function') p.catch(() => {});
        } catch (_) {}
    }
    function setStatus(text, cls='muted') {
        const msg = String(text || '');
        const silentPrefixes = [
            '카메라 시작됨',
            '카메라 중지됨.',
            'ZXing fallback',
            '아직 스캔된 값',
            '카메라는 시작되었지만 자동 인식 엔진이 없습니다.'
        ];
        if (!msg || silentPrefixes.some(prefix => msg.startsWith(prefix))) {
            resultEl.textContent = '';
            resultEl.className = 'result muted';
            return;
        }
        resultEl.textContent = msg;
        resultEl.className = 'result ' + cls;
    }
    function esc(v) {
        return String(v ?? '').replace(/[&<>"]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]));
    }
    function renderRows(rows) {
        if (!Array.isArray(rows)) return;
        rowsBody.innerHTML = rows.map(row => `<tr>
            <td>${esc(row.created_at)}</td><td>${esc(row.model_name)}</td><td>${esc(row.lot_date)}</td><td>${esc(row.tool)}</td><td>${esc(row.cavity)}</td><td>${esc(row.ea)}</td><td>${esc(row.dp_code)}</td>
        </tr>`).join('');
    }
    function normalizeScannedCode(code) {
        return String(code || '').trim().toUpperCase().replace(/\s+/g, '');
    }
    function isSnCode(code) {
        const s = normalizeScannedCode(code);
        // SN 규격: 21자리, 14/20번째 + delimiter 포함
        // 예: DGMG13445216B+E08H4+B
        return /^[A-Z]{4}\d{3}\d{5}[A-Z]\+[A-Z]\d{2}[A-Z]\d\+[A-Z]$/.test(s);
    }
    async function saveSnCode(code, source) {
        const res = await fetch(ajaxUrl('sn_lookup'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({sn: code, source})
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || 'SN 저장 실패');

        const list = Array.isArray(data.parsed_list) ? data.parsed_list : (data.parsed ? [data.parsed] : []);
        renderSnHistory(data.rows || []);

        if (Number(data.inserted_count || 0) > 0) {
            const parsed = list[0] || {};
            if (snResult) {
                snResult.textContent = `SN 스캔 완료\n${parsed.sn_code || ''}`;
                snResult.className = 'snResult ok';
            }
            playSuccessSound();
            try { navigator.vibrate && navigator.vibrate(90); } catch (_) {}
        } else {
            if (snResult) {
                snResult.textContent = `이미 저장된 SN\n${(data.skipped || [code])[0] || code}`;
                snResult.className = 'snResult warn';
            }
        }
    }
    async function saveCode(code, source) {
        const res = await fetch(ajaxUrl('save'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({code, source})
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || '저장 실패');
        const p = data.parsed || {};
        if (data.duplicate) {
            setStatus(`이미 저장된 코드\nDP: ${p.dp_code || ''}`, 'warn');
            renderRows(data.rows || []);
            return;
        }
        setStatus(`스캔 완료\nModel: ${p.model_name || ''}\nDP: ${p.dp_code || ''}\nLOT: ${p.lot_date || ''}\nTool: ${p.tool || ''} / Cavity: ${p.cavity || ''} / ea: ${p.ea ?? ''}`, 'ok');
        renderRows(data.rows || []);
        playSuccessSound();
        try { navigator.vibrate && navigator.vibrate(90); } catch (_) {}
    }
    function setManualResult(text, cls='muted') {
        if (!manualResult) return;
        manualResult.textContent = text || '';
        manualResult.className = 'snResult ' + cls;
    }
    async function saveManualCodes() {
        const code = manualCode ? manualCode.value.trim() : '';
        if (!code) {
            setManualResult('수동 입력값을 넣어 주세요.', 'bad');
            if (manualCode) manualCode.focus();
            return;
        }

        const res = await fetch(ajaxUrl('save_multi'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({code, source: 'manual'})
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || '저장 실패');

        const inserted = Number(data.inserted_count || 0);
        const skipped = Number(data.skipped_count || 0);
        const msg = [];
        if (inserted > 0) msg.push(`QR ${inserted}건 저장 완료`);
        if (skipped > 0) msg.push(`중복 ${skipped}건 제외`);
        if (!msg.length) msg.push('처리할 신규 QR이 없습니다.');
        if (skipped > 0 && Array.isArray(data.skipped) && data.skipped.length <= 5) {
            msg.push(`중복 QR: ${data.skipped.join(', ')}`);
        }

        setManualResult(msg.join('\n'), skipped > 0 ? 'warn' : 'ok');
        renderRows(data.rows || []);

        if (manualCode) {
            manualCode.select();
        }
    }
    async function handleDetected(code, source) {
        code = String(code || '').trim();
        if (!code) return;
        const dedupeKey = normalizeScannedCode(code);
        const now = Date.now();
        const last = lastSavedByCode.get(dedupeKey) || 0;
        if (now - last < 10000) return;
        lastSavedByCode.set(dedupeKey, now);

        try {
            if (isSnCode(code)) {
                await saveSnCode(code, source);
            } else {
                await saveCode(code, source);
                }
        } catch (e) {
            setStatus('저장 오류: ' + e.message, 'bad');
        }
    }
    function renderSnDetail(parsed) {
        if (!parsed || !Array.isArray(parsed.display_rows)) return;
        snRowsBody.innerHTML = parsed.display_rows.map(row => `<tr>
            <td>${esc(row[0])}</td>
            <td>${esc(row[1])}</td>
            <td>${esc(row[2])}</td>
        </tr>`).join('');
        snTableWrap.style.display = '';
    }
    function renderSnHistory(rows) {
        if (!snHistoryBody || !Array.isArray(rows)) return;
        snHistoryBody.innerHTML = rows.map(row => `<tr>
            <td>${esc(row.created_at)}</td>
            <td>${esc(row.sn_code)}</td>
            <td>${esc(row.mfg_date)}</td>
            <td>${esc(row.company_name)}</td>
            <td>${esc(row.plant_name)}</td>
            <td>${esc(row.program_name)}</td>
            <td>${esc(String((row.sequence_shift_name || '') + ' ' + (row.sequence_no_name || '')).trim())}</td>
            <td>${esc(row.model_name || row.type_name)}</td>
            <td>${esc(row.line_code)}</td>
            <td>${esc(row.equipment_no)}</td>
            <td>${esc(row.mold_code)}</td>
            <td>${esc(row.cavity)}</td>
            <td>${esc(row.revision)}</td>
        </tr>`).join('');
    }
    async function renderSnLookup() {
        try {
            const sn = snInput ? snInput.value : '';
            const res = await fetch(ajaxUrl('sn_lookup'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({sn})
            });
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.message || 'SN 저장 실패');

            const list = Array.isArray(data.parsed_list) ? data.parsed_list : (data.parsed ? [data.parsed] : []);
            const warningItems = [];
            list.forEach(item => {
                const warnings = Array.isArray(item.warnings) ? item.warnings : [];
                if (warnings.length) warningItems.push(`${item.sn_code || ''}: ${warnings.join(' / ')}`);
            });

            const insertedCount = Number(data.inserted_count ?? list.length);
            const skippedCount = Number(data.skipped_count ?? 0);
            const skipped = Array.isArray(data.skipped) ? data.skipped : [];

            if (list.length > 1 || skippedCount > 0) {
                const msg = [];
                if (insertedCount > 0) msg.push(`SN ${insertedCount}건 추가 완료`);
                if (skippedCount > 0) msg.push(`중복 ${skippedCount}건 제외`);
                if (warningItems.length) msg.push(`주의: ${warningItems.join('\n')}`);
                if (skippedCount > 0 && skipped.length <= 5) msg.push(`중복 SN: ${skipped.join(', ')}`);
                snResult.textContent = msg.join('\n') || '처리할 신규 SN이 없습니다.';
                snResult.className = 'snResult ' + ((warningItems.length || skippedCount > 0) ? 'warn' : 'ok');
                if (snRowsBody) snRowsBody.innerHTML = '';
                if (snTableWrap) snTableWrap.style.display = 'none';
            } else {
                const parsed = list[0] || {};
                const warnings = Array.isArray(parsed.warnings) ? parsed.warnings : [];
                snResult.textContent = warnings.length
                    ? `저장 완료: ${parsed.sn_code || ''}\n주의: ${warnings.join(' / ')}`
                    : `저장 완료: ${parsed.sn_code || ''}`;
                snResult.className = 'snResult ' + (warnings.length ? 'warn' : 'ok');
                renderSnDetail(parsed);
            }

            renderSnHistory(data.rows || []);
        } catch (e) {
            snResult.textContent = e.message || 'SN 저장 실패';
            snResult.className = 'snResult bad';
            snRowsBody.innerHTML = '';
            snTableWrap.style.display = 'none';
        }
    }
    async function clearSnHistory() {
        const ok = window.confirm('내 SN 조회 이력을 전체 비울까요?');
        if (!ok) return;

        const res = await fetch(ajaxUrl('sn_clear_history'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({clear: true})
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || 'SN 이력 비우기 실패');

        renderSnHistory(data.rows || []);
        if (snRowsBody) snRowsBody.innerHTML = '';
        if (snTableWrap) snTableWrap.style.display = 'none';
        if (snResult) {
            snResult.textContent = `SN 조회 이력 비우기 완료\n삭제 건수: ${data.deleted ?? 0}`;
            snResult.className = 'snResult ok';
        }
    }
    async function clearHistory() {
        const ok = window.confirm('내 QR 이력을 전체 비울까요?');
        if (!ok) return;
        const res = await fetch(ajaxUrl('clear_history'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({clear: true})
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || '비우기 실패');
        renderRows(data.rows || []);
        setStatus(`QR 이력 비우기 완료
삭제 건수: ${data.deleted ?? 0}`, 'ok');
    }
    async function startCamera() {
        if (scanning) return;
        if (!hasGetUserMedia) { setStatus('이 브라우저에서는 카메라를 사용할 수 없습니다.', 'bad'); return; }
        try {
            stream = await navigator.mediaDevices.getUserMedia({audio:false, video:{facingMode:{ideal:'environment'}, width:{ideal:1280}, height:{ideal:720}}});
            video.srcObject = stream;
            await video.play();
            scanning = true;
            setStatus('카메라 시작됨. 초록 가이드 안에 코드를 맞춰 주세요.', 'ok');
            if (hasBarcodeDetector) startBarcodeDetectorLoop();
            else if (hasZXing) startZXing();
            else setStatus('카메라는 시작되었지만 자동 인식 엔진이 없습니다.', 'warn');
        } catch (e) {
            setStatus('카메라 시작 실패: ' + e.message, 'bad');
        }
    }
    function stopCamera() {
        scanning = false;
        if (detectorTimer) { clearTimeout(detectorTimer); detectorTimer = null; }
        if (zxingReader) { try { zxingReader.reset(); } catch (_) {} zxingReader = null; }
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        video.srcObject = null;
        setStatus('카메라 중지됨.', 'muted');
    }
    async function startBarcodeDetectorLoop() {
        const detector = new BarcodeDetector({formats:['qr_code','ean_13','ean_8','code_128','upc_a','upc_e','code_39','itf']});
        const loop = async () => {
            if (!scanning) return;
            if (!video.videoWidth) {
                detectorTimer = setTimeout(loop, 220);
                return;
            }
            try {
                const codes = await detector.detect(video);
                if (codes && codes.length && codes[0].rawValue) await handleDetected(codes[0].rawValue, 'BarcodeDetector');
            } catch (_) {}
            detectorTimer = setTimeout(loop, 220);
        };
        loop();
    }
    function startZXing() {
        try {
            zxingReader = new ZXing.BrowserMultiFormatReader();
            zxingReader.decodeFromVideoElementContinuously(video, (result) => {
                if (result && result.text) handleDetected(result.text, 'ZXing');
            });
            // ZXing fallback 상태 문구는 화면에 표시하지 않음
        } catch (e) {
            setStatus('ZXing 시작 실패: ' + e.message, 'bad');
        }
    }
    document.getElementById('startBtn').addEventListener('click', startCamera);
    document.getElementById('stopBtn').addEventListener('click', stopCamera);
    document.getElementById('manualSaveBtn').addEventListener('click', async () => {
        try { await saveManualCodes(); } catch (e) { setManualResult('저장 오류: ' + e.message, 'bad'); }
    });
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            userTabClicking = true;
            try {
                activateTab(btn.dataset.tabTarget || 'reader');
            } finally {
                userTabClicking = false;
            }
        });
    });
    if (snLookupBtn) {
        snLookupBtn.addEventListener('click', renderSnLookup);
    }
    if (snInput) {
        snInput.addEventListener('keydown', e => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                renderSnLookup();
            }
        });
    }
    if (snClearHistoryBtn) {
        snClearHistoryBtn.addEventListener('click', async () => {
            try { await clearSnHistory(); } catch (e) {
                if (snResult) {
                    snResult.textContent = 'SN 비우기 오류: ' + e.message;
                    snResult.className = 'snResult bad';
                } else {
                    setStatus('SN 비우기 오류: ' + e.message, 'bad');
                }
            }
        });
    }
    if (clearHistoryBtn) {
        clearHistoryBtn.addEventListener('click', async () => {
            try { await clearHistory(); } catch (e) { setStatus('비우기 오류: ' + e.message, 'bad'); }
        });
    }
    manualCode.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); document.getElementById('manualSaveBtn').click(); } });
    window.addEventListener('beforeunload', stopCamera);
})();
</script>
</body>
</html>
