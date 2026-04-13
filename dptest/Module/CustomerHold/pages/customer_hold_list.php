<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once JTMES_ROOT . '/inc/common.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';

if (function_exists('dp_auth_guard')) {
    dp_auth_guard();
} else {
    dp_require_login();
}

date_default_timezone_set('Asia/Seoul');

function ch_json(array $data, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ch_read_json(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function ch_models(): array {
    return ['IR-BASE', 'Z-CARRIER', 'X-CARRIER', 'Y-CARRIER', 'Z-STOPPER'];
}

function ch_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = spl_object_hash($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1");
        $st->execute([':table' => $table]);
        return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function ch_ensure_schema(PDO $pdo): void {
    $sql1 = <<<SQL
CREATE TABLE IF NOT EXISTS `customer_hold_tool_status` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `part_name` VARCHAR(64) NOT NULL,
  `item_code` VARCHAR(128) NOT NULL DEFAULT '',
  `tool_text` TEXT NULL,
  `cavity_text` TEXT NULL,
  `affect_lot_text` TEXT NULL,
  `vendor_text` TEXT NULL,
  `type_text` TEXT NULL,
  `issue_description_text` MEDIUMTEXT NULL,
  `remark_text` MEDIUMTEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(64) NULL,
  `updated_by` VARCHAR(64) NULL,
  `deleted_by` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_part_active_sort` (`part_name`, `is_active`, `sort_order`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $sql2 = <<<SQL
CREATE TABLE IF NOT EXISTS `customer_hold_release_detail` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `holding_date_text` VARCHAR(64) NOT NULL DEFAULT '',
  `vendor_text` TEXT NULL,
  `parts_name_text` VARCHAR(128) NOT NULL DEFAULT '',
  `tool_text` TEXT NULL,
  `cavity_text` TEXT NULL,
  `affect_lot_text` TEXT NULL,
  `type_text` TEXT NULL,
  `issue_description_text` MEDIUMTEXT NULL,
  `status_text` VARCHAR(32) NOT NULL DEFAULT 'Ongoing',
  `release_date_text` VARCHAR(64) NOT NULL DEFAULT '',
  `note_text` MEDIUMTEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` VARCHAR(64) NULL,
  `updated_by` VARCHAR(64) NULL,
  `deleted_by` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql1);
    $pdo->exec($sql2);
}

function ch_current_user_lv(PDO $pdo): int {
    if (isset($_SESSION['ship_user_lv']) && $_SESSION['ship_user_lv'] !== '' && $_SESSION['ship_user_lv'] !== null) {
        return (int)$_SESSION['ship_user_lv'];
    }
    try {
        if (!empty($_SESSION['ship_user_no'])) {
            $st = $pdo->prepare('SELECT lv FROM `account` WHERE No = :no LIMIT 1');
            $st->execute([':no' => (int)$_SESSION['ship_user_no']]);
        } else {
            $st = $pdo->prepare('SELECT lv FROM `account` WHERE ID = :id LIMIT 1');
            $st->execute([':id' => (string)($_SESSION['ship_user_id'] ?? '')]);
        }
        $lv = $st->fetchColumn();
        if ($lv !== false && $lv !== null && $lv !== '') {
            $_SESSION['ship_user_lv'] = (int)$lv;
            return (int)$lv;
        }
    } catch (Throwable $e) {
    }
    return 0;
}

function ch_can_edit(PDO $pdo): bool {
    return ch_current_user_lv($pdo) >= 77;
}

function ch_norm(?string $value): string {
    $value = str_replace("\r\n", "\n", (string)$value);
    $value = str_replace("\r", "\n", $value);
    return trim($value);
}

function ch_any_nonempty(array $row, array $fields): bool {
    foreach ($fields as $field) {
        if (ch_norm((string)($row[$field] ?? '')) !== '') return true;
    }
    return false;
}

function ch_fetch_tool_status(PDO $pdo): array {
    $rows = [];
    if (!ch_table_exists($pdo, 'customer_hold_tool_status')) return $rows;
    $sql = "SELECT id, part_name, item_code, tool_text, cavity_text, affect_lot_text, vendor_text, type_text, issue_description_text, remark_text, sort_order
            FROM customer_hold_tool_status
            WHERE deleted_at IS NULL AND is_active = 1
            ORDER BY FIELD(part_name, 'IR-BASE', 'Z-CARRIER', 'X-CARRIER', 'Y-CARRIER', 'Z-STOPPER'), sort_order ASC, id ASC";
    foreach (($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $row['id'] = (int)$row['id'];
        $rows[] = array_map(static function ($v) {
            return $v === null ? '' : $v;
        }, $row);
    }
    return $rows;
}

function ch_fetch_release_details(PDO $pdo): array {
    $rows = [];
    if (!ch_table_exists($pdo, 'customer_hold_release_detail')) return $rows;
    $sql = "SELECT id, holding_date_text, vendor_text, parts_name_text, tool_text, cavity_text, affect_lot_text, type_text, issue_description_text, status_text, release_date_text, note_text, sort_order
            FROM customer_hold_release_detail
            WHERE deleted_at IS NULL
            ORDER BY sort_order ASC, id ASC";
    foreach (($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $row['id'] = (int)$row['id'];
        $rows[] = array_map(static function ($v) {
            return $v === null ? '' : $v;
        }, $row);
    }
    return $rows;
}

function ch_payload(PDO $pdo): array {
    return [
        'ok' => true,
        'models' => ch_models(),
        'edit_level' => 77,
        'user_lv' => ch_current_user_lv($pdo),
        'can_edit' => ch_can_edit($pdo),
        'tool_status' => ch_fetch_tool_status($pdo),
        'release_details' => ch_fetch_release_details($pdo),
    ];
}

function ch_save_tool_status(PDO $pdo, array $rows, string $userId): void {
    $fields = ['item_code', 'tool_text', 'cavity_text', 'affect_lot_text', 'vendor_text', 'type_text', 'issue_description_text', 'remark_text'];
    $models = array_flip(ch_models());

    $ins = $pdo->prepare("INSERT INTO customer_hold_tool_status
        (part_name, item_code, tool_text, cavity_text, affect_lot_text, vendor_text, type_text, issue_description_text, remark_text, sort_order, is_active, created_by, updated_by)
        VALUES
        (:part_name, :item_code, :tool_text, :cavity_text, :affect_lot_text, :vendor_text, :type_text, :issue_description_text, :remark_text, :sort_order, 1, :created_by, :updated_by)");

    $upd = $pdo->prepare("UPDATE customer_hold_tool_status SET
        part_name = :part_name,
        item_code = :item_code,
        tool_text = :tool_text,
        cavity_text = :cavity_text,
        affect_lot_text = :affect_lot_text,
        vendor_text = :vendor_text,
        type_text = :type_text,
        issue_description_text = :issue_description_text,
        remark_text = :remark_text,
        sort_order = :sort_order,
        is_active = 1,
        deleted_at = NULL,
        deleted_by = NULL,
        updated_by = :updated_by
        WHERE id = :id");

    $pdo->beginTransaction();
    try {
        foreach ($rows as $idx => $row) {
            if (!is_array($row)) continue;
            $part = strtoupper(trim((string)($row['part_name'] ?? '')));
            if (!isset($models[$part])) continue;
            if (!ch_any_nonempty($row, $fields)) continue;

            $data = [
                ':part_name' => $part,
                ':item_code' => ch_norm($row['item_code'] ?? ''),
                ':tool_text' => ch_norm($row['tool_text'] ?? ''),
                ':cavity_text' => ch_norm($row['cavity_text'] ?? ''),
                ':affect_lot_text' => ch_norm($row['affect_lot_text'] ?? ''),
                ':vendor_text' => ch_norm($row['vendor_text'] ?? ''),
                ':type_text' => ch_norm($row['type_text'] ?? ''),
                ':issue_description_text' => ch_norm($row['issue_description_text'] ?? ''),
                ':remark_text' => ch_norm($row['remark_text'] ?? ''),
                ':sort_order' => (int)($row['sort_order'] ?? ($idx + 1)),
                ':updated_by' => $userId,
            ];

            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $data[':id'] = $id;
                $upd->execute($data);
            } else {
                $data[':created_by'] = $userId;
                $ins->execute($data);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ch_save_release_details(PDO $pdo, array $rows, string $userId): void {
    $fields = ['holding_date_text', 'vendor_text', 'parts_name_text', 'tool_text', 'cavity_text', 'affect_lot_text', 'type_text', 'issue_description_text', 'status_text', 'release_date_text', 'note_text'];

    $ins = $pdo->prepare("INSERT INTO customer_hold_release_detail
        (holding_date_text, vendor_text, parts_name_text, tool_text, cavity_text, affect_lot_text, type_text, issue_description_text, status_text, release_date_text, note_text, sort_order, created_by, updated_by)
        VALUES
        (:holding_date_text, :vendor_text, :parts_name_text, :tool_text, :cavity_text, :affect_lot_text, :type_text, :issue_description_text, :status_text, :release_date_text, :note_text, :sort_order, :created_by, :updated_by)");

    $upd = $pdo->prepare("UPDATE customer_hold_release_detail SET
        holding_date_text = :holding_date_text,
        vendor_text = :vendor_text,
        parts_name_text = :parts_name_text,
        tool_text = :tool_text,
        cavity_text = :cavity_text,
        affect_lot_text = :affect_lot_text,
        type_text = :type_text,
        issue_description_text = :issue_description_text,
        status_text = :status_text,
        release_date_text = :release_date_text,
        note_text = :note_text,
        sort_order = :sort_order,
        deleted_at = NULL,
        deleted_by = NULL,
        updated_by = :updated_by
        WHERE id = :id");

    $pdo->beginTransaction();
    try {
        foreach ($rows as $idx => $row) {
            if (!is_array($row) || !ch_any_nonempty($row, $fields)) continue;
            $status = trim((string)($row['status_text'] ?? 'Ongoing'));
            if ($status === '') $status = 'Ongoing';
            if (!in_array($status, ['Ongoing', 'Close'], true)) $status = 'Ongoing';

            $data = [
                ':holding_date_text' => ch_norm($row['holding_date_text'] ?? ''),
                ':vendor_text' => ch_norm($row['vendor_text'] ?? ''),
                ':parts_name_text' => ch_norm($row['parts_name_text'] ?? ''),
                ':tool_text' => ch_norm($row['tool_text'] ?? ''),
                ':cavity_text' => ch_norm($row['cavity_text'] ?? ''),
                ':affect_lot_text' => ch_norm($row['affect_lot_text'] ?? ''),
                ':type_text' => ch_norm($row['type_text'] ?? ''),
                ':issue_description_text' => ch_norm($row['issue_description_text'] ?? ''),
                ':status_text' => $status,
                ':release_date_text' => ch_norm($row['release_date_text'] ?? ''),
                ':note_text' => ch_norm($row['note_text'] ?? ''),
                ':sort_order' => (int)($row['sort_order'] ?? ($idx + 1)),
                ':updated_by' => $userId,
            ];

            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $data[':id'] = $id;
                $upd->execute($data);
            } else {
                $data[':created_by'] = $userId;
                $ins->execute($data);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ch_soft_delete(PDO $pdo, string $table, int $id, string $userId): void {
    if ($id <= 0) throw new RuntimeException('잘못된 행 번호입니다.');
    $allowed = ['customer_hold_tool_status', 'customer_hold_release_detail'];
    if (!in_array($table, $allowed, true)) throw new RuntimeException('삭제 대상이 올바르지 않습니다.');
    $sql = $table === 'customer_hold_tool_status'
        ? "UPDATE {$table} SET is_active = 0, deleted_at = NOW(), deleted_by = :user WHERE id = :id"
        : "UPDATE {$table} SET deleted_at = NOW(), deleted_by = :user WHERE id = :id";
    $st = $pdo->prepare($sql);
    $st->execute([':user' => $userId, ':id' => $id]);
}

try {
    $pdo = dp_get_pdo();
    ch_ensure_schema($pdo);
} catch (Throwable $e) {
    if (isset($_GET['ajax'])) {
        ch_json(['ok' => false, 'message' => 'DB 연결 또는 테이블 준비에 실패했습니다: ' . $e->getMessage()], 500);
    }
    $fatalMessage = 'DB 연결 또는 테이블 준비에 실패했습니다: ' . $e->getMessage();
    $pdo = null;
}

if ($pdo && isset($_GET['ajax'])) {
    $action = (string)($_GET['action'] ?? '');
    $body = ch_read_json();
    $userId = (string)($_SESSION['ship_user_id'] ?? '');
    try {
        switch ($action) {
            case 'load':
                ch_json(ch_payload($pdo));
                break;

            case 'save_tool_status':
                if (!ch_can_edit($pdo)) ch_json(['ok' => false, 'message' => '레벨 77 이상만 저장할 수 있습니다.'], 403);
                ch_save_tool_status($pdo, (array)($body['rows'] ?? []), $userId);
                ch_json(ch_payload($pdo));
                break;

            case 'save_release_details':
                if (!ch_can_edit($pdo)) ch_json(['ok' => false, 'message' => '레벨 77 이상만 저장할 수 있습니다.'], 403);
                ch_save_release_details($pdo, (array)($body['rows'] ?? []), $userId);
                ch_json(ch_payload($pdo));
                break;

            case 'delete_tool_status':
                if (!ch_can_edit($pdo)) ch_json(['ok' => false, 'message' => '레벨 77 이상만 삭제할 수 있습니다.'], 403);
                ch_soft_delete($pdo, 'customer_hold_tool_status', (int)($body['id'] ?? 0), $userId);
                ch_json(ch_payload($pdo));
                break;

            case 'delete_release_detail':
                if (!ch_can_edit($pdo)) ch_json(['ok' => false, 'message' => '레벨 77 이상만 삭제할 수 있습니다.'], 403);
                ch_soft_delete($pdo, 'customer_hold_release_detail', (int)($body['id'] ?? 0), $userId);
                ch_json(ch_payload($pdo));
                break;

            default:
                ch_json(['ok' => false, 'message' => '지원하지 않는 요청입니다.'], 400);
        }
    } catch (Throwable $e) {
        ch_json(['ok' => false, 'message' => $e->getMessage()], 500);
    }
}

$boot = $pdo ? ch_payload($pdo) : [
    'ok' => false,
    'models' => ch_models(),
    'edit_level' => 77,
    'user_lv' => 0,
    'can_edit' => false,
    'tool_status' => [],
    'release_details' => [],
];
$embed = !empty($_GET['embed']);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>고객사 출하 홀딩 내역</title>
<style>
:root{
    --bg:#121417;
    --panel:#181c22;
    --panel-2:#20252d;
    --line:#374151;
    --line-2:#4b5563;
    --text:#e5e7eb;
    --muted:#9ca3af;
    --accent:#22c55e;
    --accent-2:#15803d;
    --warn:#f59e0b;
    --danger:#ef4444;
    --cell:#131820;
    --header:#1f2937;
    --sticky:rgba(24,28,34,0.96);
    --shadow:0 16px 32px rgba(0,0,0,.28);
}
*{box-sizing:border-box}
html,body{height:100%}
body{
    margin:0;
    color:var(--text);
    background:transparent;
    font-family:Segoe UI, Arial, sans-serif;
}
.page{
    padding:16px 18px 22px;
}
.card{
    background:linear-gradient(180deg, rgba(30,36,45,.96), rgba(18,22,28,.96));
    border:1px solid rgba(255,255,255,.08);
    border-radius:16px;
    box-shadow:var(--shadow);
    overflow:visible;
}
.toolbar{
    position:sticky;
    top:0;
    z-index:20;
    display:flex;
    gap:10px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    padding:14px 16px;
    background:var(--sticky);
    backdrop-filter:blur(8px);
    border-bottom:1px solid rgba(255,255,255,.08);
}
.toolbar .title-wrap{display:flex; flex-direction:column; gap:4px;}
.toolbar h1{margin:0; font-size:22px; line-height:1.1}
.toolbar .desc{color:var(--muted); font-size:13px}
.toolbar .right{display:flex; align-items:center; gap:8px; flex-wrap:wrap;}
.badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:8px 12px;
    border-radius:999px;
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.08);
    font-size:12px;
}
.badge.readonly{background:rgba(245,158,11,.16); border-color:rgba(245,158,11,.28); color:#fde68a}
.btn{
    appearance:none;
    border:1px solid rgba(255,255,255,.12);
    background:#21262e;
    color:var(--text);
    border-radius:10px;
    padding:9px 14px;
    font-size:13px;
    cursor:pointer;
    transition:.15s ease;
}
.btn:hover{filter:brightness(1.08)}
.btn:disabled{opacity:.45; cursor:not-allowed}
.btn.primary{background:linear-gradient(180deg, #22c55e, #15803d); border-color:#15803d; color:#08130c; font-weight:700}
.btn.ghost{background:rgba(255,255,255,.04)}
.tabs{
    display:flex;
    gap:8px;
    padding:12px 16px 0;
    flex-wrap:wrap;
}
.tab{
    appearance:none;
    border:1px solid rgba(255,255,255,.08);
    background:#1a2028;
    color:var(--text);
    border-radius:12px 12px 0 0;
    padding:10px 16px;
    cursor:pointer;
    font-weight:600;
}
.tab.active{
    background:#253040;
    border-color:rgba(34,197,94,.28);
    color:#dcfce7;
}
.tab-panel{display:none; padding:14px 16px 28px; overflow:visible;}
.tab-panel.active{display:block}
.notice{
    margin:0 16px 12px;
    padding:12px 14px;
    border-radius:12px;
    background:rgba(34,197,94,.10);
    border:1px solid rgba(34,197,94,.18);
    color:#dcfce7;
    font-size:13px;
}
.notice.warn{
    background:rgba(245,158,11,.10);
    border-color:rgba(245,158,11,.24);
    color:#fde68a;
}
.model-tabs{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin:0 0 12px;
}
.model-tab{
    appearance:none;
    border:1px solid rgba(255,255,255,.08);
    background:#1a2028;
    color:var(--text);
    border-radius:10px 10px 0 0;
    padding:8px 14px;
    cursor:pointer;
    font-weight:700;
}
.model-tab.active{
    background:#253040;
    border-color:rgba(34,197,94,.28);
    color:#dcfce7;
}
.model-section{
    margin-bottom:18px;
    border:1px solid rgba(255,255,255,.08);
    border-radius:14px;
    overflow:visible;
    background:rgba(255,255,255,.02);
}
.model-section.hidden-section{display:none}
.model-section.vendor-open{position:relative; z-index:40; padding-bottom:132px;}
.model-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
    background:rgba(255,255,255,.04);
    border-bottom:1px solid rgba(255,255,255,.08);
}
.model-head h2{
    margin:0;
    font-size:15px;
    letter-spacing:.02em;
}
.grid-wrap{
    overflow-x:auto;
    overflow-y:visible;
    max-width:100%;
    background:rgba(0,0,0,.08);
    position:relative;
}
.sheet{
    width:100%;
    min-width:1080px;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
}
.sheet th,
.sheet td{
    position:relative;
    border-right:1px solid var(--line);
    border-bottom:1px solid var(--line);
    background:var(--cell);
    vertical-align:middle;
}
.sheet thead th{
    position:sticky;
    top:0;
    z-index:3;
    background:var(--header);
    color:#d1fae5;
    text-align:left;
    padding:10px 10px;
    font-size:12px;
}
.sheet tr:first-child th:first-child,
.sheet tr:first-child td:first-child{border-left:1px solid var(--line)}
.sheet tbody tr:nth-child(even) td{background:#141a23}
.sheet tbody tr.blank-row td{background:#10161d}
.sheet td.row-no{
    width:48px;
    text-align:center;
    color:var(--muted);
    font-size:12px;
    padding:4px 6px;
    background:#11161d !important;
}
.sheet td.actions{
    width:64px;
    text-align:center;
    padding:3px 6px;
    background:#11161d !important;
}
.row-btn{
    width:24px;
    height:24px;
    border-radius:8px;
    border:1px solid rgba(255,255,255,.10);
    background:#232a34;
    color:#fff;
    cursor:pointer;
    line-height:1;
}
.row-btn:hover{background:#2b3440}
.row-btn.delete{color:#fecaca}
.cell-editor{
    width:100%;
    display:block;
    margin:0;
    background:transparent;
    color:var(--text);
    border:none;
    border-radius:0;
    outline:none;
    box-shadow:none;
    font:inherit;
    font-size:12px;
    line-height:1.16;
    padding:4px 6px;
    min-height:22px;
    appearance:none;
    -webkit-appearance:none;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.cell-editor.multiline{
    white-space:pre-wrap;
    word-break:break-word;
}
.cell-editor:empty:before{
    content:attr(data-placeholder);
    color:#6b7280;
}
.cell-editor.readonly{cursor:default}
.cell-editor.auto-filled{color:#dbeafe; opacity:.95}
.cell-editor[contenteditable="false"]{pointer-events:none}
.vendor-check{
    position:relative;
    min-width:96px;
}
.vendor-trigger{
    width:100%;
    min-height:22px;
    padding:4px 24px 4px 6px;
    border:none;
    background:transparent;
    color:var(--text);
    text-align:left;
    font:inherit;
    font-size:12px;
    line-height:1.16;
    cursor:pointer;
    position:relative;
}
.vendor-trigger::after{
    content:'▾';
    position:absolute;
    right:8px;
    top:50%;
    transform:translateY(-50%);
    color:#93c5fd;
    font-size:11px;
}
.vendor-trigger.placeholder{color:#6b7280}
.vendor-trigger[disabled]{cursor:default; opacity:.85}
.vendor-panel{
    position:absolute;
    left:0;
    top:calc(100% + 6px);
    min-width:120px;
    padding:8px 10px;
    border-radius:10px;
    background:#0f1720;
    border:1px solid rgba(255,255,255,.10);
    box-shadow:0 14px 28px rgba(0,0,0,.35);
    z-index:520;
}
.vendor-panel.hidden{display:none !important}
.vendor-option{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:12px;
    color:var(--text);
    padding:3px 0;
    cursor:pointer;
    user-select:none;
}
.vendor-option input{margin:0}
.sheet td:focus-within{
    outline:2px solid rgba(34,197,94,.40);
    outline-offset:-2px;
}
.status-select{
    width:100%;
    height:28px;
    background:#131820;
    color:var(--text);
    border:none;
    padding:4px 24px 4px 6px;
    font-size:12px;
    line-height:1.2;
    appearance:auto;
    -webkit-appearance:menulist;
}
.status-select:focus{outline:2px solid rgba(34,197,94,.40); outline-offset:-2px}
.statusbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin:0 16px 12px;
    padding:10px 12px;
    border-radius:12px;
    background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.08);
}
.statusbar .left{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
.dirty-dot{
    width:10px; height:10px; border-radius:999px; background:var(--warn); display:inline-block;
    box-shadow:0 0 0 4px rgba(245,158,11,.12);
}
.muted{color:var(--muted)}
.empty{
    padding:20px;
    text-align:center;
    color:var(--muted);
}
.hidden{display:none !important}
.small{font-size:12px}
@media (max-width: 900px){
    .page{padding:10px}
    .toolbar{padding:12px}
    .toolbar h1{font-size:18px}
    .tab-panel{padding:12px}
}
</style>
</head>
<body>
<div class="page">
    <div class="card">
        <div class="toolbar">
            <div class="title-wrap">
                <h1>고객사 출하 홀딩 내역</h1>
                <div class="desc">엑셀처럼 입력·수정·저장하는 웹 편집기. Tool Status와 홀딩 해제 세부 내역을 같은 화면에서 관리합니다.</div>
            </div>
            <div class="right">
                <span class="badge" id="userLvBadge">LV -</span>
                <span class="badge readonly hidden" id="readonlyBadge">읽기 전용 · 레벨 77 이상만 편집 가능</span>
                <button class="btn ghost" id="reloadBtn" type="button">새로고침</button>
                <button class="btn primary" id="saveBtn" type="button">저장</button>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" data-tab="toolStatus" type="button">Tool Status</button>
            <button class="tab" data-tab="releaseDetail" type="button">홀딩 해제 세부 내역</button>
        </div>

        <div class="statusbar">
            <div class="left">
                <span id="dirtyWrap" class="hidden"><span class="dirty-dot"></span></span>
                <span id="statusText">불러오는 중...</span>
            </div>
            <div class="muted small">마지막 빈 행에 입력하면 아래에 새 빈 행이 자동으로 생깁니다.</div>
        </div>

        <div id="fatalMessage" class="notice warn hidden"></div>
        <div id="readonlyMessage" class="notice warn hidden">현재 계정은 조회만 가능합니다. 레벨 77 이상부터 입력·수정·삭제·저장이 가능합니다.</div>

        <section class="tab-panel active" data-panel="toolStatus">
            <div class="model-tabs" id="toolModelTabs"></div>
            <div id="toolStatusRoot"></div>
        </section>

        <section class="tab-panel" data-panel="releaseDetail">
            <div class="model-section">
                <div class="model-head">
                    <h2>홀딩 해제 세부 내역</h2>
                    <button class="btn ghost small" type="button" id="releaseAddRowBtn">+ 행 추가</button>
                </div>
                <div class="grid-wrap">
                    <table class="sheet" id="releaseTable">
                        <colgroup>
                            <col style="width:48px">
                            <col style="width:64px">
                            <col style="width:120px">
                            <col style="width:120px">
                            <col style="width:130px">
                            <col style="width:100px">
                            <col style="width:100px">
                            <col style="width:120px">
                            <col style="width:110px">
                            <col style="width:220px">
                            <col style="width:110px">
                            <col style="width:120px">
                            <col style="width:220px">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th></th>
                                <th>Holding DATE</th>
                                <th>Vendor</th>
                                <th>Parts name</th>
                                <th>Tool</th>
                                <th>Cavity</th>
                                <th>Affect Lot</th>
                                <th>Type</th>
                                <th>Issue Description</th>
                                <th>Status</th>
                                <th>Release DATE</th>
                                <th>비고</th>
                            </tr>
                        </thead>
                        <tbody id="releaseBody"></tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
window.CUSTOMER_HOLD_BOOTSTRAP = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script>
(function(){
'use strict';

const endpoint = location.pathname + '?ajax=1';
const state = {
    models: [],
    canEdit: false,
    userLv: 0,
    dirty: false,
    activeToolModel: '',
    boot: window.CUSTOMER_HOLD_BOOTSTRAP || {}
};
const VENDOR_OPTIONS = ['자화', 'LGIT'];

const toolColumns = [
    { key: 'item_code', label: 'Item', placeholder: '' },
    { key: 'tool_text', label: 'Tool', placeholder: 'A / 1 / All' },
    { key: 'cavity_text', label: 'Cavity', placeholder: '1,2,4 / All' },
    { key: 'affect_lot_text', label: 'Affect Lot', placeholder: '전 Lot / ~2/21 Lot' },
    { key: 'vendor_text', label: 'Vendor', placeholder: '자화 / LGIT' },
    { key: 'type_text', label: 'Type', placeholder: '치수 / 외관' },
    { key: 'issue_description_text', label: 'Issue Description', placeholder: '홀딩 사유', multiline: true },
    { key: 'remark_text', label: 'Remark', placeholder: '비고 / 출하 가능 조건', multiline: true }
];

const toolEditableKeys = toolColumns.filter(function(col){ return col.key !== 'item_code'; }).map(function(col){ return col.key; });

const releaseColumns = [
    { key: 'holding_date_text', placeholder: '2026-04-13' },
    { key: 'vendor_text', placeholder: '자화 / LGIT' },
    { key: 'parts_name_text', placeholder: 'IR-BASE' },
    { key: 'tool_text', placeholder: 'A / 1 / All' },
    { key: 'cavity_text', placeholder: '1,2,4 / All' },
    { key: 'affect_lot_text', placeholder: '전 Lot / 특정 Lot' },
    { key: 'type_text', placeholder: '치수 / 외관' },
    { key: 'issue_description_text', placeholder: 'Issue Description', multiline: true },
    { key: 'status_text', type: 'status' },
    { key: 'release_date_text', placeholder: '해제일' },
    { key: 'note_text', placeholder: '비고', multiline: true }
];

const els = {
    toolModelTabs: document.getElementById('toolModelTabs'),
    toolStatusRoot: document.getElementById('toolStatusRoot'),
    releaseBody: document.getElementById('releaseBody'),
    fatalMessage: document.getElementById('fatalMessage'),
    readonlyMessage: document.getElementById('readonlyMessage'),
    userLvBadge: document.getElementById('userLvBadge'),
    readonlyBadge: document.getElementById('readonlyBadge'),
    statusText: document.getElementById('statusText'),
    dirtyWrap: document.getElementById('dirtyWrap'),
    saveBtn: document.getElementById('saveBtn'),
    reloadBtn: document.getElementById('reloadBtn'),
    releaseAddRowBtn: document.getElementById('releaseAddRowBtn')
};

function setStatus(text, isError){
    els.statusText.textContent = text || '';
    els.statusText.style.color = isError ? '#fecaca' : '';
}

function setDirty(flag){
    state.dirty = !!flag;
    els.dirtyWrap.classList.toggle('hidden', !state.dirty);
    if (state.dirty) {
        setStatus('저장되지 않은 변경사항이 있습니다.');
    } else if (!els.fatalMessage.classList.contains('hidden')) {
        setStatus('오류가 있어 저장할 수 없습니다.', true);
    } else {
        setStatus('저장 완료된 최신 상태입니다.');
    }
}

function showFatal(message){
    els.fatalMessage.textContent = message || '';
    els.fatalMessage.classList.toggle('hidden', !message);
    if (message) setStatus(message, true);
}

function normalizeText(value){
    return String(value == null ? '' : value).replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
}

function anyFilled(row, keys){
    return keys.some(function(key){
        return normalizeText(row[key]) !== '';
    });
}

function buildToolGroups(rows){
    const grouped = {};
    state.models.forEach(function(model){ grouped[model] = []; });
    (rows || []).forEach(function(row){
        const model = String(row.part_name || '').toUpperCase();
        if (!grouped[model]) grouped[model] = [];
        grouped[model].push(row);
    });
    return grouped;
}

function createEditor(value, placeholder, options){
    const opts = options || {};
    const el = document.createElement('div');
    el.className = 'cell-editor' + (opts.multiline ? ' multiline' : '');
    const editable = state.canEdit && !opts.readonly;
    if (!editable) el.classList.add('readonly');
    if (opts.readonly) el.classList.add('auto-filled');
    el.contentEditable = editable ? 'true' : 'false';
    el.spellcheck = false;
    el.dataset.placeholder = placeholder || '';
    el.textContent = value || '';
    return el;
}

function vendorDisplayText(raw){
    const values = String(raw || '').split(/\n+/).map(function(v){ return normalizeText(v); }).filter(Boolean);
    return values.length ? values.join(' / ') : '';
}

function createVendorDropdown(value){
    const wrap = document.createElement('div');
    wrap.className = 'vendor-check';
    wrap.dataset.value = normalizeText(value || '');

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'vendor-trigger';
    trigger.disabled = !state.canEdit;

    const panel = document.createElement('div');
    panel.className = 'vendor-panel hidden';

    VENDOR_OPTIONS.forEach(function(opt){
        const label = document.createElement('label');
        label.className = 'vendor-option';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.value = opt;
        input.disabled = !state.canEdit;
        label.appendChild(input);
        label.appendChild(document.createTextNode(opt));
        panel.appendChild(label);
    });

    wrap.appendChild(trigger);
    wrap.appendChild(panel);
    syncVendorDropdown(wrap);
    return wrap;
}

function syncVendorDropdown(wrap){
    const values = String(wrap.dataset.value || '').split(/\n+/).map(function(v){ return normalizeText(v); }).filter(Boolean);
    wrap.querySelectorAll('input[type="checkbox"]').forEach(function(box){
        box.checked = values.includes(box.value);
    });
    const trigger = wrap.querySelector('.vendor-trigger');
    const text = values.join(' / ');
    trigger.textContent = text || '자화 / LGIT';
    trigger.classList.toggle('placeholder', !text);
}

function toggleVendorDropdown(wrap, open){
    document.querySelectorAll('.vendor-panel').forEach(function(panel){
        if (!wrap || panel !== wrap.querySelector('.vendor-panel')) panel.classList.add('hidden');
    });
    document.querySelectorAll('.model-section.vendor-open').forEach(function(section){
        section.classList.remove('vendor-open');
    });
    if (!wrap || !state.canEdit) return;
    const panel = wrap.querySelector('.vendor-panel');
    if (!panel) return;
    const shouldOpen = open === undefined ? panel.classList.contains('hidden') : !!open;
    panel.classList.toggle('hidden', !shouldOpen);
    if (shouldOpen) {
        const section = wrap.closest('.model-section');
        if (section) section.classList.add('vendor-open');
    }
}

function createToolRow(model, row){
    row = row || {};
    if (!normalizeText(row.item_code)) row.item_code = model;
    const tr = document.createElement('tr');
    tr.dataset.model = model;
    tr.dataset.id = row.id ? String(row.id) : '';
    if (!row.id && !anyFilled(row, toolEditableKeys)) tr.classList.add('blank-row');

    const no = document.createElement('td');
    no.className = 'row-no';
    no.textContent = '';
    tr.appendChild(no);

    const actionTd = document.createElement('td');
    actionTd.className = 'actions';
    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'row-btn delete';
    delBtn.textContent = '✕';
    delBtn.title = row.id ? '행 삭제' : '빈 행 제거';
    delBtn.disabled = !state.canEdit;
    delBtn.addEventListener('click', function(){
        handleToolRowDelete(tr);
    });
    actionTd.appendChild(delBtn);
    tr.appendChild(actionTd);

    toolColumns.forEach(function(col){
        const td = document.createElement('td');
        td.dataset.field = col.key;
        if (col.key === 'vendor_text') {
            td.appendChild(createVendorDropdown(row[col.key] || ''));
        } else if (col.key === 'item_code') {
            const editor = createEditor(model, model, Object.assign({}, col, { readonly: true }));
            td.appendChild(editor);
        } else {
            const editor = createEditor(row[col.key] || '', col.placeholder || '', col);
            td.appendChild(editor);
        }
        tr.appendChild(td);
    });
    return tr;
}

function createReleaseRow(row){
    row = row || {};
    const tr = document.createElement('tr');
    tr.dataset.id = row.id ? String(row.id) : '';
    if (!row.id && !anyFilled(row, releaseColumns.map(c => c.key))) tr.classList.add('blank-row');

    const no = document.createElement('td');
    no.className = 'row-no';
    tr.appendChild(no);

    const actionTd = document.createElement('td');
    actionTd.className = 'actions';
    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'row-btn delete';
    delBtn.textContent = '✕';
    delBtn.title = row.id ? '행 삭제' : '빈 행 제거';
    delBtn.disabled = !state.canEdit;
    delBtn.addEventListener('click', function(){
        handleReleaseRowDelete(tr);
    });
    actionTd.appendChild(delBtn);
    tr.appendChild(actionTd);

    releaseColumns.forEach(function(col){
        const td = document.createElement('td');
        td.dataset.field = col.key;
        if (col.type === 'status') {
            const sel = document.createElement('select');
            sel.className = 'status-select';
            sel.disabled = !state.canEdit;
            ['Ongoing', 'Close'].forEach(function(opt){
                const option = document.createElement('option');
                option.value = opt;
                option.textContent = opt;
                if ((row[col.key] || 'Ongoing') === opt) option.selected = true;
                sel.appendChild(option);
            });
            td.appendChild(sel);
        } else if (col.key === 'vendor_text') {
            td.appendChild(createVendorDropdown(row[col.key] || ''));
        } else {
            td.appendChild(createEditor(row[col.key] || '', col.placeholder || '', col));
        }
        tr.appendChild(td);
    });
    return tr;
}

function renderToolStatus(rows){
    els.toolStatusRoot.innerHTML = '';
    const grouped = buildToolGroups(rows || []);
    state.models.forEach(function(model){
        const section = document.createElement('div');
        section.className = 'model-section';
        section.dataset.model = model;

        const head = document.createElement('div');
        head.className = 'model-head';
        const h2 = document.createElement('h2');
        h2.textContent = model;
        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn ghost small';
        addBtn.textContent = '+ 행 추가';
        addBtn.disabled = !state.canEdit;
        addBtn.addEventListener('click', function(){
            appendBlankToolRow(model);
        });
        head.appendChild(h2);
        head.appendChild(addBtn);
        section.appendChild(head);

        const wrap = document.createElement('div');
        wrap.className = 'grid-wrap';

        const table = document.createElement('table');
        table.className = 'sheet tool-sheet';
        table.dataset.model = model;

        const colgroup = document.createElement('colgroup');
        [
            '48px','64px','90px','90px','90px','120px','120px','110px','220px','260px'
        ].forEach(function(width){
            const col = document.createElement('col');
            col.style.width = width;
            colgroup.appendChild(col);
        });
        table.appendChild(colgroup);

        const thead = document.createElement('thead');
        const hr = document.createElement('tr');
        ['No', '', 'Item', 'Tool', 'Cavity', 'Affect Lot', 'Vendor', 'Type', 'Issue Description', 'Remark'].forEach(function(label){
            const th = document.createElement('th');
            th.textContent = label;
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        tbody.dataset.model = model;
        (grouped[model] || []).forEach(function(row){
            tbody.appendChild(createToolRow(model, row));
        });
        tbody.appendChild(createToolRow(model, {part_name:model}));
        table.appendChild(tbody);

        wrap.appendChild(table);
        section.appendChild(wrap);
        els.toolStatusRoot.appendChild(section);

        renumberRows(tbody);
        ensureToolBlankRow(model);
    });
}

function renderToolModelTabs(){
    els.toolModelTabs.innerHTML = '';
    state.models.forEach(function(model){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'model-tab';
        btn.dataset.model = model;
        btn.textContent = model;
        btn.addEventListener('click', function(){
            setActiveToolModel(model);
        });
        els.toolModelTabs.appendChild(btn);
    });
}

function setActiveToolModel(model){
    state.activeToolModel = model || (state.models[0] || '');
    document.querySelectorAll('#toolModelTabs .model-tab').forEach(function(btn){
        btn.classList.toggle('active', btn.dataset.model === state.activeToolModel);
    });
    document.querySelectorAll('#toolStatusRoot .model-section').forEach(function(section){
        section.classList.toggle('hidden-section', section.dataset.model !== state.activeToolModel);
    });
}

function renderReleaseDetails(rows){
    els.releaseBody.innerHTML = '';
    (rows || []).forEach(function(row){
        els.releaseBody.appendChild(createReleaseRow(row));
    });
    els.releaseBody.appendChild(createReleaseRow({}));
    renumberRows(els.releaseBody);
    ensureReleaseBlankRow();
}

function renumberRows(tbody){
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(function(tr, idx){
        const cell = tr.querySelector('.row-no');
        if (cell) cell.textContent = String(idx + 1);
    });
}

function rowDataFromTr(tr, columns){
    const out = { id: tr.dataset.id ? Number(tr.dataset.id) : 0 };
    columns.forEach(function(col){
        const td = tr.querySelector('td[data-field="' + col.key + '"]');
        if (!td) return;
        if (col.type === 'status') {
            const sel = td.querySelector('select');
            out[col.key] = sel ? sel.value : 'Ongoing';
        } else if (col.key === 'vendor_text') {
            const vendor = td.querySelector('.vendor-check');
            out[col.key] = vendor ? normalizeText(vendor.dataset.value || '') : '';
        } else {
            const editor = td.querySelector('.cell-editor');
            out[col.key] = editor ? normalizeText(editor.innerText || editor.textContent || '') : '';
        }
    });
    return out;
}

function collectToolStatusRows(){
    const rows = [];
    document.querySelectorAll('.tool-sheet tbody').forEach(function(tbody){
        const model = tbody.dataset.model;
        tbody.querySelectorAll('tr').forEach(function(tr, idx){
            const data = rowDataFromTr(tr, toolColumns);
            data.part_name = model;
            data.sort_order = idx + 1;
            if (data.id || anyFilled(data, toolEditableKeys)) {
                rows.push(data);
            }
        });
    });
    return rows;
}

function collectReleaseRows(){
    const rows = [];
    els.releaseBody.querySelectorAll('tr').forEach(function(tr, idx){
        const data = rowDataFromTr(tr, releaseColumns);
        data.sort_order = idx + 1;
        if (data.id || anyFilled(data, releaseColumns.map(c => c.key))) {
            rows.push(data);
        }
    });
    return rows;
}

function appendBlankToolRow(model){
    const tbody = document.querySelector('.tool-sheet tbody[data-model="' + CSS.escape(model) + '"]');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const last = rows.length ? rows[rows.length - 1] : null;
    if (last && isToolBlankRow(last)) {
        focusFirstEditable(last);
        return;
    }
    const blank = createToolRow(model, {part_name:model});
    tbody.appendChild(blank);
    renumberRows(tbody);
    focusFirstEditable(blank);
    setDirty(true);
}

function appendBlankReleaseRow(){
    const rows = Array.from(els.releaseBody.querySelectorAll('tr'));
    const last = rows.length ? rows[rows.length - 1] : null;
    if (last && isReleaseBlankRow(last)) {
        focusFirstEditable(last);
        return;
    }
    const blank = createReleaseRow({});
    els.releaseBody.appendChild(blank);
    renumberRows(els.releaseBody);
    focusFirstEditable(blank);
    setDirty(true);
}

function focusFirstEditable(tr){
    if (!tr) return;
    const target = tr.querySelector('.cell-editor[contenteditable="true"], .vendor-trigger:not([disabled]), select:not([disabled])');
    if (target) target.focus();
}

function isToolBlankRow(tr){
    const data = rowDataFromTr(tr, toolColumns);
    return !anyFilled(data, toolEditableKeys);
}

function isReleaseBlankRow(tr){
    const data = rowDataFromTr(tr, releaseColumns);
    return !anyFilled(data, releaseColumns.map(c => c.key));
}

function ensureToolBlankRow(model){
    const tbody = document.querySelector('.tool-sheet tbody[data-model="' + CSS.escape(model) + '"]');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const blanks = rows.filter(isToolBlankRow);
    if (!rows.length || !isToolBlankRow(rows[rows.length - 1])) {
        tbody.appendChild(createToolRow(model, {part_name:model}));
    }
    const finalRows = Array.from(tbody.querySelectorAll('tr'));
    finalRows.forEach(function(row, idx){
        row.classList.toggle('blank-row', isToolBlankRow(row));
        if (idx < finalRows.length - 1 && isToolBlankRow(row) && row.dataset.id === '') {
            row.remove();
        }
    });
    renumberRows(tbody);
}

function ensureReleaseBlankRow(){
    const rows = Array.from(els.releaseBody.querySelectorAll('tr'));
    if (!rows.length || !isReleaseBlankRow(rows[rows.length - 1])) {
        els.releaseBody.appendChild(createReleaseRow({}));
    }
    const finalRows = Array.from(els.releaseBody.querySelectorAll('tr'));
    finalRows.forEach(function(row, idx){
        row.classList.toggle('blank-row', isReleaseBlankRow(row));
        if (idx < finalRows.length - 1 && isReleaseBlankRow(row) && row.dataset.id === '') {
            row.remove();
        }
    });
    renumberRows(els.releaseBody);
}

function handleToolInput(tr){
    tr.classList.toggle('blank-row', isToolBlankRow(tr));
    const tbody = tr.parentElement;
    if (tbody) {
        ensureToolBlankRow(tbody.dataset.model);
        renumberRows(tbody);
    }
    setDirty(true);
}

function handleReleaseInput(tr){
    tr.classList.toggle('blank-row', isReleaseBlankRow(tr));
    ensureReleaseBlankRow();
    renumberRows(els.releaseBody);
    setDirty(true);
}

async function api(action, payload){
    const res = await fetch(endpoint + '&action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload || {}),
        credentials: 'same-origin'
    });
    const json = await res.json().catch(function(){ return {ok:false, message:'응답을 읽을 수 없습니다.'}; });
    if (!res.ok || !json.ok) {
        throw new Error(json.message || '요청에 실패했습니다.');
    }
    return json;
}

function applyPayload(payload){
    state.models = Array.isArray(payload.models) && payload.models.length ? payload.models : ['IR-BASE', 'Z-CARRIER', 'X-CARRIER', 'Y-CARRIER', 'Z-STOPPER'];
    state.canEdit = !!payload.can_edit;
    state.userLv = Number(payload.user_lv || 0);

    els.userLvBadge.textContent = 'LV ' + state.userLv;
    els.readonlyBadge.classList.toggle('hidden', state.canEdit);
    els.readonlyMessage.classList.toggle('hidden', state.canEdit);
    els.saveBtn.disabled = !state.canEdit;
    els.releaseAddRowBtn.disabled = !state.canEdit;

    renderToolStatus(payload.tool_status || []);
    renderToolModelTabs();
    setActiveToolModel(state.models.includes(state.activeToolModel) ? state.activeToolModel : (state.models[0] || ''));
    renderReleaseDetails(payload.release_details || []);
    setDirty(false);
    showFatal('');
}

async function saveCurrentTab(){
    if (!state.canEdit) return;
    try {
        const active = document.querySelector('.tab.active');
        const tab = active ? active.dataset.tab : 'toolStatus';
        setStatus('저장 중...');
        let payload;
        if (tab === 'releaseDetail') {
            payload = await api('save_release_details', { rows: collectReleaseRows() });
        } else {
            payload = await api('save_tool_status', { rows: collectToolStatusRows() });
        }
        applyPayload(payload);
        setStatus('저장되었습니다.');
    } catch (err) {
        showFatal(err.message || String(err));
    }
}

async function reloadAll(){
    try {
        setStatus('새로 불러오는 중...');
        const payload = await api('load', {});
        applyPayload(payload);
        setStatus('최신 상태로 다시 불러왔습니다.');
    } catch (err) {
        showFatal(err.message || String(err));
    }
}

async function handleToolRowDelete(tr){
    if (!state.canEdit) return;
    const id = Number(tr.dataset.id || 0);
    if (id > 0) {
        if (!confirm('이 행을 삭제할까요?')) return;
        try {
            const payload = await api('delete_tool_status', { id: id });
            applyPayload(payload);
            setStatus('행을 삭제했습니다.');
        } catch (err) {
            showFatal(err.message || String(err));
        }
        return;
    }
    const tbody = tr.parentElement;
    tr.remove();
    ensureToolBlankRow(tbody.dataset.model);
    renumberRows(tbody);
    setDirty(true);
}

async function handleReleaseRowDelete(tr){
    if (!state.canEdit) return;
    const id = Number(tr.dataset.id || 0);
    if (id > 0) {
        if (!confirm('이 행을 삭제할까요?')) return;
        try {
            const payload = await api('delete_release_detail', { id: id });
            applyPayload(payload);
            setStatus('행을 삭제했습니다.');
        } catch (err) {
            showFatal(err.message || String(err));
        }
        return;
    }
    tr.remove();
    ensureReleaseBlankRow();
    renumberRows(els.releaseBody);
    setDirty(true);
}

function bindTabs(){
    document.querySelectorAll('.tab').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.tab').forEach(function(el){ el.classList.remove('active'); });
            document.querySelectorAll('.tab-panel').forEach(function(el){ el.classList.remove('active'); });
            btn.classList.add('active');
            const panel = document.querySelector('.tab-panel[data-panel="' + btn.dataset.tab + '"]');
            if (panel) panel.classList.add('active');
        });
    });
}

function bindDelegation(){
    document.addEventListener('input', function(e){
        if (e.target.matches('.cell-editor[contenteditable="true"]')) {
            const tr = e.target.closest('.tool-sheet tbody tr');
            if (tr) {
                handleToolInput(tr);
                return;
            }
            const relTr = e.target.closest('#releaseBody tr');
            if (relTr) handleReleaseInput(relTr);
        }
    });

    document.addEventListener('keydown', function(e){
        const editor = e.target.closest('.cell-editor[contenteditable="true"]');
        if (!editor) return;
        if (!editor.classList.contains('multiline') && e.key === 'Enter') {
            e.preventDefault();
        }
    });

    document.addEventListener('click', function(e){
        const trigger = e.target.closest('.vendor-trigger');
        if (trigger) {
            const wrap = trigger.closest('.vendor-check');
            toggleVendorDropdown(wrap);
            return;
        }
        if (!e.target.closest('.vendor-check')) {
            toggleVendorDropdown(null, false);
        }
    });

    document.addEventListener('change', function(e){
        const box = e.target.closest('.vendor-check input[type="checkbox"]');
        if (box) {
            const wrap = box.closest('.vendor-check');
            const values = Array.from(wrap.querySelectorAll('input[type="checkbox"]:checked')).map(function(input){ return input.value; });
            wrap.dataset.value = values.join('\n');
            syncVendorDropdown(wrap);
            const toolTr = wrap.closest('.tool-sheet tbody tr');
            if (toolTr) {
                handleToolInput(toolTr);
                return;
            }
            const relTr = wrap.closest('#releaseBody tr');
            if (relTr) {
                handleReleaseInput(relTr);
                return;
            }
        }
        const relTr = e.target.closest('#releaseBody tr');
        if (relTr) handleReleaseInput(relTr);
    });
}

function initButtons(){
    els.saveBtn.addEventListener('click', saveCurrentTab);
    els.reloadBtn.addEventListener('click', function(){
        if (state.dirty && !confirm('저장하지 않은 변경사항을 버리고 다시 불러올까요?')) return;
        reloadAll();
    });
    els.releaseAddRowBtn.addEventListener('click', appendBlankReleaseRow);
}

window.addEventListener('beforeunload', function(e){
    if (!state.dirty) return;
    e.preventDefault();
    e.returnValue = '';
});

function init(){
    bindTabs();
    bindDelegation();
    initButtons();
    if (!state.boot || state.boot.ok === false && !state.boot.tool_status && !state.boot.release_details) {
        showFatal('초기 데이터를 불러오지 못했습니다.');
        return;
    }
    applyPayload(state.boot);
    if (!state.canEdit) {
        setStatus('조회 전용으로 열렸습니다.');
    }
}
init();
})();
</script>
</body>
</html>
