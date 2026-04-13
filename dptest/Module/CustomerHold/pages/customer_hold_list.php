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
.model-tabs{display:none !important}
.model-head-tabs{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
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
    transition:margin-bottom .14s ease;
}
.model-section.hidden-section{display:none}
.model-head{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:10px;
    padding:8px 12px 2px;
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
    min-width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:auto;
}
.sheet th,
.sheet td{
    position:relative;
    border-right:1px solid var(--line);
    border-bottom:1px solid var(--line);
    background:var(--cell);
    vertical-align:middle;
    text-align:center;
}
.sheet thead th{
    position:sticky;
    top:0;
    z-index:3;
    background:var(--header);
    color:#d1fae5;
    text-align:center;
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
    width:82px;
    text-align:center;
    padding:3px 6px;
    background:#11161d !important;
}
.action-buttons{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
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
.row-btn.add{color:#bbf7d0}
.row-btn.delete{color:#fecaca}
.sheet td[data-field="tool_text"].merged-master{
    vertical-align:middle;
}
.sheet tbody tr:hover td{
    background:rgba(59,130,246,.12) !important;
}
.sheet tbody tr:hover td .cell-editor,
.sheet tbody tr:hover td .vendor-trigger,
.sheet tbody tr:hover td .status-select{
    color:#eef6ff;
}
.cell-editor{
    width:auto;
    min-width:100%;
    box-sizing:border-box;
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
    text-align:center;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    resize:none;
}
input.cell-editor{height:22px}
textarea.cell-editor{
    min-height:22px;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:hidden;
}
.cell-editor::placeholder{
    color:#6b7280;
    opacity:1;
}
.cell-editor.readonly{
    cursor:default;
    pointer-events:none;
}
.cell-editor.auto-filled{color:#dbeafe; opacity:.95}
.vendor-check{
    position:relative;
    min-width:96px;
}
.vendor-trigger{
    width:auto;
    min-width:100%;
    box-sizing:border-box;
    min-height:22px;
    padding:4px 24px 4px 6px;
    border:none;
    background:transparent;
    color:var(--text);
    text-align:center;
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
    position:fixed;
    left:-9999px;
    top:-9999px;
    min-width:120px;
    padding:8px 10px;
    border-radius:10px;
    background:#0f1720;
    border:1px solid rgba(255,255,255,.10);
    box-shadow:0 14px 28px rgba(0,0,0,.35);
    z-index:2400;
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
    width:auto;
    min-width:100%;
    box-sizing:border-box;
    height:28px;
    background:#131820;
    color:var(--text);
    border:none;
    padding:4px 24px 4px 6px;
    font-size:12px;
    text-align:center;
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
                            <col style="width:72px">
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
const CAVITY_OPTIONS = ['ALL', '1', '2', '3', '4'];
const TOOL_TYPE_OPTIONS = ['치수', '외관'];
const RELEASE_TYPE_OPTIONS = ['Cosmetic', 'Dimension'];

const toolColumns = [
    { key: 'item_code', label: 'Item', placeholder: '' },
    { key: 'tool_text', label: 'Tool', placeholder: '' },
    { key: 'cavity_text', label: 'Cavity', placeholder: '' },
    { key: 'affect_lot_text', label: 'Affect Lot', placeholder: '' },
    { key: 'vendor_text', label: 'Vendor', placeholder: '' },
    { key: 'type_text', label: 'Type', placeholder: '' },
    { key: 'issue_description_text', label: 'Issue Description', placeholder: '', multiline: true },
    { key: 'remark_text', label: 'Remark', placeholder: '', multiline: true }
];

const toolEditableKeys = toolColumns.filter(function(col){ return col.key !== 'item_code'; }).map(function(col){ return col.key; });

const releaseColumns = [
    { key: 'holding_date_text', placeholder: '' },
    { key: 'vendor_text', placeholder: '' },
    { key: 'parts_name_text', placeholder: '' },
    { key: 'tool_text', placeholder: '' },
    { key: 'cavity_text', placeholder: '' },
    { key: 'affect_lot_text', placeholder: '' },
    { key: 'type_text', placeholder: '' },
    { key: 'issue_description_text', placeholder: '', multiline: true },
    { key: 'status_text', type: 'status' },
    { key: 'release_date_text', placeholder: '' },
    { key: 'note_text', placeholder: '', multiline: true }
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

function toolPrimaryKey(value){
    const raw = normalizeText(value || '').toUpperCase();
    if (!raw) return 'ZZZZ';
    const firstLine = raw.split(/\n+/)[0] || '';
    const token = firstLine.split(/[\s\/,]+/).map(function(v){ return normalizeText(v); }).filter(Boolean)[0] || firstLine;
    if (!token) return 'ZZZZ';
    if (token === 'ALL') return 'ZZZY';
    return token;
}

function toolFullKey(value){
    const raw = normalizeText(value || '').toUpperCase();
    return raw === '' ? 'ZZZZ' : raw;
}

function sortToolRowsForModel(rows){
    return (rows || []).slice().sort(function(a, b){
        const aTool = normalizeText(a && a.tool_text);
        const bTool = normalizeText(b && b.tool_text);
        const aPrimary = toolPrimaryKey(aTool);
        const bPrimary = toolPrimaryKey(bTool);
        if (aPrimary !== bPrimary) return aPrimary.localeCompare(bPrimary, 'en', { numeric:true, sensitivity:'base' });
        const aFull = toolFullKey(aTool);
        const bFull = toolFullKey(bTool);
        if (aFull !== bFull) return aFull.localeCompare(bFull, 'en', { numeric:true, sensitivity:'base' });
        return ((a && Number(a.sort_order)) || 0) - ((b && Number(b.sort_order)) || 0);
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
    Object.keys(grouped).forEach(function(model){
        grouped[model] = sortToolRowsForModel(grouped[model]);
    });
    return grouped;
}

let __chMeasureEl = null;
function getMeasureEl(){
    if (__chMeasureEl && document.body.contains(__chMeasureEl)) return __chMeasureEl;
    __chMeasureEl = document.createElement('span');
    __chMeasureEl.style.position = 'absolute';
    __chMeasureEl.style.left = '-99999px';
    __chMeasureEl.style.top = '-99999px';
    __chMeasureEl.style.visibility = 'hidden';
    __chMeasureEl.style.whiteSpace = 'pre';
    __chMeasureEl.style.pointerEvents = 'none';
    document.body.appendChild(__chMeasureEl);
    return __chMeasureEl;
}

function measureEditorText(el, text){
    const probe = getMeasureEl();
    const cs = window.getComputedStyle(el);
    probe.style.fontFamily = cs.fontFamily;
    probe.style.fontSize = cs.fontSize;
    probe.style.fontWeight = cs.fontWeight;
    probe.style.fontStyle = cs.fontStyle;
    probe.style.letterSpacing = cs.letterSpacing;
    probe.style.textTransform = cs.textTransform;
    probe.style.lineHeight = cs.lineHeight;
    probe.textContent = String(text || '') || '\u00A0';
    return Math.ceil(probe.getBoundingClientRect().width);
}

function autosizeEditor(el){
    if (!el) return;
    const td = el.closest('td');
    if (el.tagName === 'TEXTAREA') {
        el.style.height = 'auto';
        const lines = String(el.value || '').split('\n');
        const longestLine = lines.reduce(function(acc, line){
            return line.length > acc.length ? line : acc;
        }, '');
        const minWidth = td ? Math.max(120, td.clientWidth - 12) : 120;
        const targetWidth = Math.max(minWidth, measureEditorText(el, longestLine) + 18);
        el.style.width = Math.min(960, targetWidth) + 'px';
        el.style.height = Math.max(22, el.scrollHeight) + 'px';
    } else {
        const raw = String(el.value || '');
        const text = raw === '' ? '\u00A0' : raw;
        const minWidth = td ? Math.max(42, td.clientWidth - 12) : 42;
        const targetWidth = Math.max(minWidth, measureEditorText(el, text) + 18);
        el.style.width = Math.min(420, targetWidth) + 'px';
    }
}

function autosizeDropdown(wrap){
    if (!wrap) return;
    const trigger = wrap.querySelector('.vendor-trigger');
    if (!trigger) return;
    const text = splitDropdownValues(wrap.dataset.value || '').join(' / ');
    const td = wrap.closest('td');
    const minWidth = td ? Math.max(72, td.clientWidth - 12) : 72;
    const targetWidth = Math.max(minWidth, measureEditorText(trigger, text || '\u00A0') + 30);
    trigger.style.width = Math.min(280, targetWidth) + 'px';
}

function autosizeStatusSelect(sel){
    if (!sel) return;
    const text = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
    const td = sel.closest('td');
    const minWidth = td ? Math.max(78, td.clientWidth - 12) : 78;
    const targetWidth = Math.max(minWidth, measureEditorText(sel, text || '\u00A0') + 30);
    sel.style.width = Math.min(180, targetWidth) + 'px';
}

function autosizeRow(tr){
    if (!tr) return;
    tr.querySelectorAll('.cell-editor').forEach(autosizeEditor);
    tr.querySelectorAll('.vendor-check').forEach(autosizeDropdown);
    tr.querySelectorAll('.status-select').forEach(autosizeStatusSelect);
}

function autosizeAllRows(root){
    const scope = root || document;
    scope.querySelectorAll('tbody tr').forEach(autosizeRow);
}

let __toolWidthSyncRaf = 0;
function ensureToolColgroup(table){
    if (!table) return null;
    let colgroup = table.querySelector('colgroup.tool-colgroup');
    const count = table.querySelectorAll('thead tr:first-child > *').length;
    if (!colgroup) {
        colgroup = document.createElement('colgroup');
        colgroup.className = 'tool-colgroup';
        for (let i = 0; i < count; i += 1) {
            colgroup.appendChild(document.createElement('col'));
        }
        table.insertBefore(colgroup, table.firstChild);
    } else {
        while (colgroup.children.length < count) {
            colgroup.appendChild(document.createElement('col'));
        }
        while (colgroup.children.length > count) {
            colgroup.removeChild(colgroup.lastChild);
        }
    }
    return colgroup;
}

function getActiveToolSection(){
    return document.querySelector('#toolStatusRoot .model-section:not(.hidden-section)')
        || document.querySelector('#toolStatusRoot .model-section');
}

function toolSheetIntrinsicWidthForCell(cell, idx){
    if (!cell) return 0;
    if (idx === 0) return 68;

    const editor = cell.querySelector('.cell-editor');
    const trigger = cell.querySelector('.vendor-trigger');
    const statusSel = cell.querySelector('.status-select');

    let text = '';
    if (statusSel) {
        text = normalizeText(statusSel.value || statusSel.options[statusSel.selectedIndex]?.text || '');
    } else if (trigger) {
        text = normalizeText((trigger.dataset.value || '').replace(/\s*\/\s*/g, ' / '));
    } else if (editor) {
        if (editor.tagName === 'TEXTAREA') {
            const lines = String(editor.value || '').split('
');
            text = lines.reduce(function(acc, line){
                return line.length > acc.length ? line : acc;
            }, '');
        } else {
            text = String(editor.value || '');
        }
        text = normalizeText(text);
    } else {
        text = normalizeText(cell.textContent || '');
    }

    const probe = editor || trigger || statusSel || cell;
    const labelText = text === '' ? ' ' : text;
    let width = measureEditorText(probe, labelText) + 26;

    if (idx === 1) width = Math.max(92, Math.min(140, width));       // Item
    else if (idx === 2) width = Math.max(56, Math.min(96, width));   // Tool
    else if (idx === 3) width = Math.max(72, Math.min(120, width));  // Cavity
    else if (idx === 4) width = Math.max(88, Math.min(150, width));  // Affect Lot
    else if (idx === 5) width = Math.max(72, Math.min(110, width));  // Vendor
    else if (idx === 6) width = Math.max(70, Math.min(110, width));  // Type
    else if (idx === 7) width = Math.max(120, Math.min(320, width)); // Issue Description
    else if (idx === 8) width = Math.max(220, width);                // Remark (min only)

    return Math.ceil(width);
}

function syncToolSheetColumnWidths(target){
    const table = target && target.matches && target.matches('table.tool-sheet')
        ? target
        : (target && target.closest ? target.closest('table.tool-sheet') : null)
            || (target && target.querySelector ? target.querySelector('table.tool-sheet') : null)
            || (getActiveToolSection() ? getActiveToolSection().querySelector('table.tool-sheet') : null);
    if (!table) return;

    const colgroup = ensureToolColgroup(table);
    if (!colgroup) return;

    const cols = Array.from(colgroup.children);
    cols.forEach(function(col){
        col.style.width = '';
        col.style.minWidth = '';
    });

    const widths = [];
    const headerCells = table.querySelectorAll('thead tr:first-child > *');
    Array.from(headerCells).forEach(function(cell, idx){
        widths[idx] = Math.max(widths[idx] || 0, toolSheetIntrinsicWidthForCell(cell, idx));
    });

    table.querySelectorAll('tbody tr').forEach(function(row){
        Array.from(row.children).forEach(function(cell, idx){
            if (!cell || cell.style.display === 'none') return;
            widths[idx] = Math.max(widths[idx] || 0, toolSheetIntrinsicWidthForCell(cell, idx));
        });
    });

    cols.forEach(function(col, idx){
        if (idx === 8) {
            col.style.minWidth = Math.max(220, widths[idx] || 0) + 'px';
            col.style.width = '';
            return;
        }
        const width = widths[idx] || 0;
        if (width > 0) {
            col.style.width = width + 'px';
            col.style.minWidth = width + 'px';
        }
    });
}

function scheduleToolSheetColumnWidthSync(target){
    if (__toolWidthSyncRaf) cancelAnimationFrame(__toolWidthSyncRaf);
    __toolWidthSyncRaf = requestAnimationFrame(function(){
        __toolWidthSyncRaf = 0;
        syncToolSheetColumnWidths(target);
    });
}

function createEditor(value, placeholder, options){
    const opts = options || {};
    const multiline = !!opts.multiline;
    const el = document.createElement(multiline ? 'textarea' : 'input');
    if (!multiline) el.type = 'text';
    el.className = 'cell-editor' + (multiline ? ' multiline' : '');
    const editable = state.canEdit && !opts.readonly;
    if (!editable) {
        el.classList.add('readonly');
        el.readOnly = true;
        el.tabIndex = -1;
    }
    if (opts.readonly) el.classList.add('auto-filled');
    el.spellcheck = false;
    el.placeholder = placeholder || '';
    el.value = value || '';
    if (multiline) {
        el.rows = 1;
        el.wrap = 'soft';
    }
    return el;
}

function splitDropdownValues(raw){
    return String(raw || '').split(/\n+/).map(function(v){ return normalizeText(v); }).filter(Boolean);
}

function joinDropdownValues(values){
    return (values || []).map(function(v){ return normalizeText(v); }).filter(Boolean).join('\n');
}

function createCheckDropdown(value, options, placeholder, fieldKey){
    const wrap = document.createElement('div');
    wrap.className = 'vendor-check';
    wrap.dataset.value = normalizeText(value || '');
    wrap.dataset.field = fieldKey || '';
    wrap.dataset.placeholder = placeholder || '';

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'vendor-trigger';
    trigger.disabled = !state.canEdit;

    const panel = document.createElement('div');
    panel.className = 'vendor-panel hidden';

    (options || []).forEach(function(opt){
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
    syncCheckDropdown(wrap);
    return wrap;
}

function createVendorDropdown(value){
    return createCheckDropdown(value, VENDOR_OPTIONS, '', 'vendor_text');
}

function createCavityDropdown(value){
    return createCheckDropdown(value, CAVITY_OPTIONS, '', 'cavity_text');
}

function createTypeDropdownTool(value){
    return createCheckDropdown(value, TOOL_TYPE_OPTIONS, '', 'type_text');
}

function createTypeDropdownRelease(value){
    return createCheckDropdown(value, RELEASE_TYPE_OPTIONS, '', 'type_text');
}

function syncCheckDropdown(wrap){
    const values = splitDropdownValues(wrap.dataset.value || '');
    wrap.querySelectorAll('input[type="checkbox"]').forEach(function(box){
        box.checked = values.includes(box.value);
    });
    const trigger = wrap.querySelector('.vendor-trigger');
    const text = values.join(' / ');
    const placeholder = wrap.dataset.placeholder || '';
    trigger.textContent = text || placeholder || '\u00A0';
    trigger.classList.toggle('placeholder', !text);
}

function positionVendorPanel(wrap){
    if (!wrap) return;
    const panel = wrap.querySelector('.vendor-panel');
    const trigger = wrap.querySelector('.vendor-trigger');
    if (!panel || !trigger || panel.classList.contains('hidden')) return;

    panel.style.left = '-9999px';
    panel.style.top = '-9999px';
    const triggerRect = trigger.getBoundingClientRect();
    const panelRect = panel.getBoundingClientRect();
    const viewportW = window.innerWidth || document.documentElement.clientWidth || 0;
    const viewportH = window.innerHeight || document.documentElement.clientHeight || 0;

    let left = triggerRect.left;
    let top = triggerRect.bottom + 6;

    if (left + panelRect.width + 12 > viewportW) {
        left = Math.max(8, viewportW - panelRect.width - 12);
    }
    if (top + panelRect.height + 12 > viewportH) {
        const aboveTop = triggerRect.top - panelRect.height - 6;
        if (aboveTop >= 8) {
            top = aboveTop;
        } else {
            top = Math.max(8, viewportH - panelRect.height - 12);
        }
    }

    panel.style.left = Math.round(left) + 'px';
    panel.style.top = Math.round(top) + 'px';
}

function insertToolRowBelow(tr){
    const tbody = tr && tr.parentElement;
    if (!tbody) return;
    const model = tbody.dataset.model || '';
    const currentData = rowDataFromTr(tr, toolColumns);
    const newRow = createToolRow(model, {
        part_name: model,
        item_code: model,
        tool_text: normalizeText(currentData.tool_text || '')
    });
    newRow.dataset.keepBlank = '1';
    tr.insertAdjacentElement('afterend', newRow);
    ensureToolBlankRow(model);
    applyToolRowMerges(tbody);
    renumberRows(tbody);
    requestAnimationFrame(function(){ autosizeRow(newRow); });
    focusFirstEditable(newRow);
    setDirty(true);
}

function sortToolTbodyRows(tbody){
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const nonBlank = [];
    const blanks = [];
    rows.forEach(function(tr, idx){
        const data = rowDataFromTr(tr, toolColumns);
        const entry = { tr: tr, idx: idx, data: data };
        if (isToolBlankRow(tr)) blanks.push(entry);
        else nonBlank.push(entry);
    });
    nonBlank.sort(function(a, b){
        const aPrimary = toolPrimaryKey(a.data.tool_text);
        const bPrimary = toolPrimaryKey(b.data.tool_text);
        if (aPrimary !== bPrimary) return aPrimary.localeCompare(bPrimary, 'en', { numeric:true, sensitivity:'base' });
        const aFull = toolFullKey(a.data.tool_text);
        const bFull = toolFullKey(b.data.tool_text);
        if (aFull !== bFull) return aFull.localeCompare(bFull, 'en', { numeric:true, sensitivity:'base' });
        return a.idx - b.idx;
    });
    nonBlank.concat(blanks).forEach(function(entry){
        tbody.appendChild(entry.tr);
    });
}

function resetToolRowMerges(tbody){
    if (!tbody) return;
    tbody.querySelectorAll('td[data-field="tool_text"]').forEach(function(td){
        td.style.display = '';
        td.rowSpan = 1;
        td.classList.remove('merged-master');
    });
}

function applyToolRowMerges(tbody){
    if (!tbody) return;
    sortToolTbodyRows(tbody);
    resetToolRowMerges(tbody);
    const rows = Array.from(tbody.querySelectorAll('tr'));
    let startTd = null;
    let startValue = '';
    let span = 0;

    function flush(){
        if (startTd && span > 1) {
            startTd.rowSpan = span;
            startTd.classList.add('merged-master');
        }
        startTd = null;
        startValue = '';
        span = 0;
    }

    rows.forEach(function(tr){
        const td = tr.querySelector('td[data-field="tool_text"]');
        if (!td) {
            flush();
            return;
        }
        const editor = td.querySelector('.cell-editor');
        const value = normalizeText(editor ? (editor.value || '') : '');
        const mergeKey = toolPrimaryKey(value);
        const shouldMerge = value !== '' && !tr.classList.contains('blank-row');
        if (!shouldMerge) {
            flush();
            return;
        }
        if (!startTd) {
            startTd = td;
            startValue = mergeKey;
            span = 1;
            return;
        }
        if (mergeKey === startValue) {
            td.style.display = 'none';
            span += 1;
            return;
        }
        flush();
        startTd = td;
        startValue = mergeKey;
        span = 1;
    });
    flush();
}

function syncMergedToolGroup(td){
    if (!td || td.dataset.field !== 'tool_text' || td.style.display === 'none' || td.rowSpan <= 1) return;
    const editor = td.querySelector('.cell-editor');
    const value = editor ? (editor.value || '') : '';
    let tr = td.parentElement;
    for (let i = 1; i < td.rowSpan; i += 1) {
        tr = tr && tr.nextElementSibling;
        if (!tr) break;
        const dupTd = tr.querySelector('td[data-field="tool_text"]');
        const dupEditor = dupTd ? dupTd.querySelector('.cell-editor') : null;
        if (dupEditor) dupEditor.value = value;
    }
}

function toggleVendorDropdown(wrap, open){
    document.querySelectorAll('.vendor-panel').forEach(function(panel){
        if (!wrap || panel !== wrap.querySelector('.vendor-panel')) {
            panel.classList.add('hidden');
            panel.style.left = '-9999px';
            panel.style.top = '-9999px';
        }
    });
    if (!wrap || !state.canEdit) return;
    const panel = wrap.querySelector('.vendor-panel');
    if (!panel) return;
    const shouldOpen = open === undefined ? panel.classList.contains('hidden') : !!open;
    panel.classList.toggle('hidden', !shouldOpen);
    if (shouldOpen) {
        positionVendorPanel(wrap);
    } else {
        panel.style.left = '-9999px';
        panel.style.top = '-9999px';
    }
}

function createToolRow(model, row){
    row = row || {};
    if (!normalizeText(row.item_code)) row.item_code = model;
    const tr = document.createElement('tr');
    tr.dataset.model = model;
    tr.dataset.id = row.id ? String(row.id) : '';
    if (!row.id && !anyFilled(row, toolEditableKeys)) tr.classList.add('blank-row');

    const actionTd = document.createElement('td');
    actionTd.className = 'actions';
    const actionWrap = document.createElement('div');
    actionWrap.className = 'action-buttons';

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'row-btn add';
    addBtn.textContent = '+';
    addBtn.title = '현재 행 아래에 행 추가';
    addBtn.disabled = !state.canEdit;
    addBtn.addEventListener('click', function(){
        insertToolRowBelow(tr);
    });
    actionWrap.appendChild(addBtn);

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'row-btn delete';
    delBtn.textContent = '✕';
    delBtn.title = row.id ? '행 삭제' : '빈 행 제거';
    delBtn.disabled = !state.canEdit;
    delBtn.addEventListener('click', function(){
        handleToolRowDelete(tr);
    });
    actionWrap.appendChild(delBtn);

    actionTd.appendChild(actionWrap);
    tr.appendChild(actionTd);

    toolColumns.forEach(function(col){
        const td = document.createElement('td');
        td.dataset.field = col.key;
        if (col.key === 'vendor_text') {
            td.appendChild(createVendorDropdown(row[col.key] || ''));
        } else if (col.key === 'cavity_text') {
            td.appendChild(createCavityDropdown(row[col.key] || ''));
        } else if (col.key === 'type_text') {
            td.appendChild(createTypeDropdownTool(row[col.key] || ''));
        } else if (col.key === 'item_code') {
            const editor = createEditor(model, model, Object.assign({}, col, { readonly: true }));
            td.appendChild(editor);
        } else {
            const editor = createEditor(row[col.key] || '', col.placeholder || '', col);
            td.appendChild(editor);
        }
        tr.appendChild(td);
    });
    requestAnimationFrame(function(){ autosizeRow(tr); });
    return tr;
}

function createReleaseRow(row){
    row = row || {};
    const tr = document.createElement('tr');
    tr.dataset.id = row.id ? String(row.id) : '';
    if (!row.id && !anyFilled(row, releaseColumns.map(c => c.key))) tr.classList.add('blank-row');

    const actionTd = document.createElement('td');
    actionTd.className = 'actions';

    const actionWrap = document.createElement('div');
    actionWrap.className = 'action-buttons';

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'row-btn add';
    addBtn.textContent = '+';
    addBtn.title = '현재 행 아래에 행 추가';
    addBtn.disabled = !state.canEdit;
    addBtn.addEventListener('click', function(){
        insertReleaseRowBelow(tr);
    });
    actionWrap.appendChild(addBtn);

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'row-btn delete';
    delBtn.textContent = '✕';
    delBtn.title = row.id ? '행 삭제' : '빈 행 제거';
    delBtn.disabled = !state.canEdit;
    delBtn.addEventListener('click', function(){
        handleReleaseRowDelete(tr);
    });
    actionWrap.appendChild(delBtn);

    actionTd.appendChild(actionWrap);
    tr.appendChild(actionTd);

    releaseColumns.forEach(function(col){
        const td = document.createElement('td');
        td.dataset.field = col.key;
        if (col.type === 'status') {
            const sel = document.createElement('select');
            sel.className = 'status-select';
            sel.disabled = !state.canEdit;
            const currentStatus = normalizeText(row[col.key] || '');
            [
                { value: '', text: '' },
                { value: 'Ongoing', text: 'Ongoing' },
                { value: 'Close', text: 'Close' }
            ].forEach(function(opt){
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.text;
                if (currentStatus === opt.value) option.selected = true;
                sel.appendChild(option);
            });
            td.appendChild(sel);
        } else if (col.key === 'vendor_text') {
            td.appendChild(createVendorDropdown(row[col.key] || ''));
        } else if (col.key === 'cavity_text') {
            td.appendChild(createCavityDropdown(row[col.key] || ''));
        } else if (col.key === 'type_text') {
            td.appendChild(createTypeDropdownRelease(row[col.key] || ''));
        } else {
            td.appendChild(createEditor(row[col.key] || '', col.placeholder || '', col));
        }
        tr.appendChild(td);
    });
    requestAnimationFrame(function(){ autosizeRow(tr); });
    return tr;
}

function createModelHeadTabs(){
    const wrap = document.createElement('div');
    wrap.className = 'model-head-tabs';
    state.models.forEach(function(model){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'model-tab';
        btn.dataset.model = model;
        btn.textContent = model;
        btn.addEventListener('click', function(){
            setActiveToolModel(model);
        });
        wrap.appendChild(btn);
    });
    return wrap;
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
        head.appendChild(createModelHeadTabs());
        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn ghost small';
        addBtn.textContent = '+ 행 추가';
        addBtn.disabled = !state.canEdit;
        addBtn.addEventListener('click', function(){
            appendBlankToolRow(model);
        });
        head.appendChild(addBtn);
        section.appendChild(head);

        const wrap = document.createElement('div');
        wrap.className = 'grid-wrap';

        const table = document.createElement('table');
        table.className = 'sheet tool-sheet';
        table.dataset.model = model;

        const thead = document.createElement('thead');
        const hr = document.createElement('tr');
        ['', 'Item', 'Tool', 'Cavity', 'Affect Lot', 'Vendor', 'Type', 'Issue Description', 'Remark'].forEach(function(label){
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
        applyToolRowMerges(tbody);
        autosizeAllRows(section);
    });
    scheduleToolSheetColumnWidthSync(getActiveToolSection());
}

function renderToolModelTabs(){
    if (els.toolModelTabs) els.toolModelTabs.innerHTML = '';
}

function setActiveToolModel(model){
    state.activeToolModel = model || (state.models[0] || '');
    document.querySelectorAll('#toolStatusRoot .model-tab').forEach(function(btn){
        btn.classList.toggle('active', btn.dataset.model === state.activeToolModel);
    });
    document.querySelectorAll('#toolStatusRoot .model-section').forEach(function(section){
        section.classList.toggle('hidden-section', section.dataset.model !== state.activeToolModel);
    });
    requestAnimationFrame(function(){
        const activeSection = document.querySelector('#toolStatusRoot .model-section[data-model="' + CSS.escape(state.activeToolModel) + '"]');
        if (activeSection) autosizeAllRows(activeSection);
        scheduleToolSheetColumnWidthSync(activeSection);
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
            out[col.key] = sel ? normalizeText(sel.value || '') : '';
        } else {
            const dropdown = td.querySelector('.vendor-check');
            if (dropdown) {
                out[col.key] = normalizeText(dropdown.dataset.value || '');
            } else {
                const editor = td.querySelector('.cell-editor');
                out[col.key] = editor ? normalizeText(editor.value || '') : '';
            }
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
        last.dataset.keepBlank = '1';
        const autoBlank = createToolRow(model, {part_name:model});
        tbody.appendChild(autoBlank);
        renumberRows(tbody);
        applyToolRowMerges(tbody);
        requestAnimationFrame(function(){ autosizeAllRows(tbody); scheduleToolSheetColumnWidthSync(tbody); });
        focusFirstEditable(last);
        setDirty(true);
        return;
    }
    const blank = createToolRow(model, {part_name:model});
    blank.dataset.keepBlank = '1';
    tbody.appendChild(blank);
    ensureToolBlankRow(model);
    renumberRows(tbody);
    applyToolRowMerges(tbody);
    requestAnimationFrame(function(){ autosizeRow(blank); scheduleToolSheetColumnWidthSync(blank); });
    focusFirstEditable(blank);
    setDirty(true);
}

function appendBlankReleaseRow(){
    const rows = Array.from(els.releaseBody.querySelectorAll('tr'));
    const last = rows.length ? rows[rows.length - 1] : null;
    if (last && isReleaseBlankRow(last)) {
        last.dataset.keepBlank = '1';
        const autoBlank = createReleaseRow({});
        els.releaseBody.appendChild(autoBlank);
        renumberRows(els.releaseBody);
        requestAnimationFrame(function(){ autosizeAllRows(els.releaseBody); });
        focusFirstEditable(last);
        setDirty(true);
        return;
    }
    const blank = createReleaseRow({});
    blank.dataset.keepBlank = '1';
    els.releaseBody.appendChild(blank);
    ensureReleaseBlankRow();
    renumberRows(els.releaseBody);
    focusFirstEditable(blank);
    setDirty(true);
}

function insertReleaseRowBelow(tr){
    const tbody = tr && tr.parentElement;
    if (!tbody) return;
    const currentData = rowDataFromTr(tr, releaseColumns);
    const newRow = createReleaseRow({
        holding_date_text: normalizeText(currentData.holding_date_text || ''),
        vendor_text: normalizeText(currentData.vendor_text || ''),
        parts_name_text: normalizeText(currentData.parts_name_text || ''),
        tool_text: normalizeText(currentData.tool_text || ''),
        cavity_text: normalizeText(currentData.cavity_text || ''),
        affect_lot_text: normalizeText(currentData.affect_lot_text || ''),
        type_text: normalizeText(currentData.type_text || ''),
        status_text: ''
    });
    newRow.dataset.keepBlank = '1';
    tr.insertAdjacentElement('afterend', newRow);
    ensureReleaseBlankRow();
    renumberRows(els.releaseBody);
    focusFirstEditable(newRow);
    setDirty(true);
}

function focusFirstEditable(tr){
    if (!tr) return;
    const candidates = Array.from(tr.querySelectorAll('.cell-editor:not([readonly]):not([disabled]), .vendor-trigger:not([disabled]), select:not([disabled])'));
    const target = candidates.find(function(el){
        return el.offsetParent !== null && el.getClientRects().length > 0;
    }) || candidates[0];
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
    const last = rows.length ? rows[rows.length - 1] : null;
    if (!rows.length || !isToolBlankRow(last) || last.dataset.keepBlank === '1') {
        tbody.appendChild(createToolRow(model, {part_name:model}));
    }
    const finalRows = Array.from(tbody.querySelectorAll('tr'));
    finalRows.forEach(function(row, idx){
        const blank = isToolBlankRow(row);
        row.classList.toggle('blank-row', blank);
        if (idx < finalRows.length - 1 && blank && row.dataset.id === '' && row.dataset.keepBlank !== '1') {
            row.remove();
        }
    });
    applyToolRowMerges(tbody);
    renumberRows(tbody);
}

function ensureReleaseBlankRow(){
    const rows = Array.from(els.releaseBody.querySelectorAll('tr'));
    const last = rows.length ? rows[rows.length - 1] : null;
    if (!rows.length || !isReleaseBlankRow(last) || last.dataset.keepBlank === '1') {
        els.releaseBody.appendChild(createReleaseRow({}));
    }
    const finalRows = Array.from(els.releaseBody.querySelectorAll('tr'));
    finalRows.forEach(function(row, idx){
        const blank = isReleaseBlankRow(row);
        row.classList.toggle('blank-row', blank);
        if (idx < finalRows.length - 1 && blank && row.dataset.id === '' && row.dataset.keepBlank !== '1') {
            row.remove();
        }
    });
    renumberRows(els.releaseBody);
}

function handleToolInput(tr, changedField){
    if (!isToolBlankRow(tr)) delete tr.dataset.keepBlank;
    tr.classList.toggle('blank-row', isToolBlankRow(tr));
    const tbody = tr.parentElement;
    if (tbody) {
        if (changedField === 'tool_text') {
            const td = tr.querySelector('td[data-field="tool_text"]');
            syncMergedToolGroup(td);
        }
        ensureToolBlankRow(tbody.dataset.model);
        applyToolRowMerges(tbody);
        renumberRows(tbody);
    }
    requestAnimationFrame(function(){ autosizeRow(tr); scheduleToolSheetColumnWidthSync(tr); });
    setDirty(true);
}

function handleReleaseInput(tr){
    if (!isReleaseBlankRow(tr)) delete tr.dataset.keepBlank;
    tr.classList.toggle('blank-row', isReleaseBlankRow(tr));
    ensureReleaseBlankRow();
    renumberRows(els.releaseBody);
    requestAnimationFrame(function(){ autosizeRow(tr); scheduleToolSheetColumnWidthSync(tr); });
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
    requestAnimationFrame(function(){ autosizeAllRows(document); scheduleToolSheetColumnWidthSync(getActiveToolSection()); });
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
    applyToolRowMerges(tbody);
    renumberRows(tbody);
    scheduleToolSheetColumnWidthSync(tbody);
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
            requestAnimationFrame(function(){
                if (!panel) return;
                if (btn.dataset.tab === 'toolStatus') {
                    const activeSection = document.querySelector('#toolStatusRoot .model-section[data-model="' + CSS.escape(state.activeToolModel) + '"]');
                    if (activeSection) autosizeAllRows(activeSection);
                    return;
                }
                autosizeAllRows(panel);
            });
        });
    });
}

function bindDelegation(){
    document.addEventListener('input', function(e){
        if (e.target.matches('.cell-editor:not([readonly])')) {
            autosizeEditor(e.target);
            const tr = e.target.closest('.tool-sheet tbody tr');
            if (tr) {
                const td = e.target.closest('td[data-field]');
                handleToolInput(tr, td ? td.dataset.field : '');
                return;
            }
            const relTr = e.target.closest('#releaseBody tr');
            if (relTr) handleReleaseInput(relTr);
        }
    });

    document.addEventListener('keydown', function(e){
        const editor = e.target.closest('.cell-editor:not([readonly])');
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
            let values = Array.from(wrap.querySelectorAll('input[type="checkbox"]:checked')).map(function(input){ return input.value; });
            if (wrap.dataset.field === 'cavity_text') {
                if (box.value === 'ALL' && box.checked) {
                    values = ['ALL'];
                    wrap.querySelectorAll('input[type="checkbox"]').forEach(function(input){
                        input.checked = input.value === 'ALL';
                    });
                } else {
                    values = values.filter(function(v){ return v !== 'ALL'; });
                    wrap.querySelectorAll('input[type="checkbox"]').forEach(function(input){
                        if (input.value === 'ALL') input.checked = false;
                    });
                }
                values = CAVITY_OPTIONS.filter(function(opt){ return values.includes(opt); });
            }
            wrap.dataset.value = joinDropdownValues(values);
            syncCheckDropdown(wrap);
            autosizeDropdown(wrap);
            const changedField = wrap.dataset.field || '';
            const toolTr = wrap.closest('.tool-sheet tbody tr');
            if (toolTr) {
                handleToolInput(toolTr, changedField);
                return;
            }
            const relTr = wrap.closest('#releaseBody tr');
            if (relTr) {
                handleReleaseInput(relTr);
                return;
            }
        }
        const statusSelect = e.target.closest('.status-select');
        if (statusSelect) autosizeStatusSelect(statusSelect);
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

window.addEventListener('resize', function(){
    const openPanel = document.querySelector('.vendor-panel:not(.hidden)');
    if (!openPanel) return;
    positionVendorPanel(openPanel.closest('.vendor-check'));
});

window.addEventListener('scroll', function(){
    const openPanel = document.querySelector('.vendor-panel:not(.hidden)');
    if (!openPanel) return;
    positionVendorPanel(openPanel.closest('.vendor-check'));
}, true);

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
