
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
    $key = strtolower($table);
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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_hold_tool_status (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            part_name VARCHAR(100) NOT NULL,
            item_code VARCHAR(255) DEFAULT NULL,
            tool_text VARCHAR(255) DEFAULT NULL,
            cavity_text VARCHAR(255) DEFAULT NULL,
            affect_lot_text VARCHAR(255) DEFAULT NULL,
            vendor_text VARCHAR(255) DEFAULT NULL,
            type_text VARCHAR(255) DEFAULT NULL,
            issue_description_text TEXT DEFAULT NULL,
            remark_text TEXT DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(100) DEFAULT NULL,
            updated_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            KEY idx_part_sort (part_name, sort_order),
            KEY idx_active (is_active, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_hold_release_detail (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            holding_date_text VARCHAR(255) DEFAULT NULL,
            vendor_text VARCHAR(255) DEFAULT NULL,
            parts_name_text VARCHAR(255) DEFAULT NULL,
            tool_text VARCHAR(255) DEFAULT NULL,
            cavity_text VARCHAR(255) DEFAULT NULL,
            affect_lot_text VARCHAR(255) DEFAULT NULL,
            type_text VARCHAR(255) DEFAULT NULL,
            issue_description_text TEXT DEFAULT NULL,
            status_text VARCHAR(100) DEFAULT NULL,
            release_date_text VARCHAR(255) DEFAULT NULL,
            note_text TEXT DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by VARCHAR(100) DEFAULT NULL,
            updated_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            KEY idx_sort (sort_order),
            KEY idx_deleted (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
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
    } catch (Throwable $e) {}
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
    if (!ch_table_exists($pdo, 'customer_hold_tool_status')) return [];
    $sql = "SELECT id, part_name, item_code, tool_text, cavity_text, affect_lot_text, vendor_text, type_text, issue_description_text, remark_text, sort_order
            FROM customer_hold_tool_status
            WHERE deleted_at IS NULL AND is_active = 1
            ORDER BY FIELD(part_name, 'IR-BASE', 'Z-CARRIER', 'X-CARRIER', 'Y-CARRIER', 'Z-STOPPER'), sort_order ASC, id ASC";
    $rows = [];
    foreach (($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $row['id'] = (int)$row['id'];
        $rows[] = array_map(static function ($v) { return $v === null ? '' : $v; }, $row);
    }
    return $rows;
}

function ch_fetch_release_details(PDO $pdo): array {
    if (!ch_table_exists($pdo, 'customer_hold_release_detail')) return [];
    $sql = "SELECT id, holding_date_text, vendor_text, parts_name_text, tool_text, cavity_text, affect_lot_text, type_text, issue_description_text, status_text, release_date_text, note_text, sort_order
            FROM customer_hold_release_detail
            WHERE deleted_at IS NULL
            ORDER BY sort_order ASC, id ASC";
    $rows = [];
    foreach (($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $row['id'] = (int)$row['id'];
        $rows[] = array_map(static function ($v) { return $v === null ? '' : $v; }, $row);
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
    $ins = $pdo->prepare("INSERT INTO customer_hold_tool_status (part_name, item_code, tool_text, cavity_text, affect_lot_text, vendor_text, type_text, issue_description_text, remark_text, sort_order, is_active, created_by, updated_by)
                          VALUES (:part_name, :item_code, :tool_text, :cavity_text, :affect_lot_text, :vendor_text, :type_text, :issue_description_text, :remark_text, :sort_order, 1, :created_by, :updated_by)");
    $upd = $pdo->prepare("UPDATE customer_hold_tool_status
                          SET part_name = :part_name, item_code = :item_code, tool_text = :tool_text, cavity_text = :cavity_text, affect_lot_text = :affect_lot_text,
                              vendor_text = :vendor_text, type_text = :type_text, issue_description_text = :issue_description_text, remark_text = :remark_text,
                              sort_order = :sort_order, is_active = 1, deleted_at = NULL, deleted_by = NULL, updated_by = :updated_by
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
    $ins = $pdo->prepare("INSERT INTO customer_hold_release_detail (holding_date_text, vendor_text, parts_name_text, tool_text, cavity_text, affect_lot_text, type_text, issue_description_text, status_text, release_date_text, note_text, sort_order, created_by, updated_by)
                          VALUES (:holding_date_text, :vendor_text, :parts_name_text, :tool_text, :cavity_text, :affect_lot_text, :type_text, :issue_description_text, :status_text, :release_date_text, :note_text, :sort_order, :created_by, :updated_by)");
    $upd = $pdo->prepare("UPDATE customer_hold_release_detail
                          SET holding_date_text = :holding_date_text, vendor_text = :vendor_text, parts_name_text = :parts_name_text, tool_text = :tool_text, cavity_text = :cavity_text,
                              affect_lot_text = :affect_lot_text, type_text = :type_text, issue_description_text = :issue_description_text, status_text = :status_text,
                              release_date_text = :release_date_text, note_text = :note_text, sort_order = :sort_order, deleted_at = NULL, deleted_by = NULL, updated_by = :updated_by
                          WHERE id = :id");
    $pdo->beginTransaction();
    try {
        foreach ($rows as $idx => $row) {
            if (!is_array($row) || !ch_any_nonempty($row, $fields)) continue;
            $status = trim((string)($row['status_text'] ?? ''));
            if ($status !== '' && !in_array($status, ['Ongoing', 'Close'], true)) $status = '';
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
 --accent-2:#16a34a;
 --danger:#ef4444;
 --header:#1b2536;
 --cell:#101722;
 --cell-alt:#131b27;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
 margin:0;
 background:transparent;
 color:var(--text);
 font-family:Segoe UI, Apple SD Gothic Neo, Malgun Gothic, sans-serif;
}
.page{
 padding:16px;
}
.panel{
 background:rgba(10,14,20,.82);
 border:1px solid rgba(255,255,255,.08);
 border-radius:16px;
 overflow:hidden;
}
.panel-head{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:12px;
 padding:14px 16px;
 background:rgba(10,14,20,.88);
 border-bottom:1px solid rgba(255,255,255,.08);
}
.panel-head h1{margin:0;font-size:18px;font-weight:700}
.head-actions{display:flex;gap:10px;align-items:center}
.badge{
 padding:8px 12px;
 border-radius:999px;
 background:#232a34;
 border:1px solid rgba(255,255,255,.10);
 font-size:12px;
}
.btn{
 border:1px solid rgba(255,255,255,.10);
 background:#232a34;
 color:#fff;
 height:38px;
 padding:0 14px;
 border-radius:12px;
 font-weight:600;
 cursor:pointer;
}
.btn:hover{background:#2b3440}
.btn.primary{background:#16a34a;border-color:#15803d}
.btn.primary:hover{background:#15803d}
.content{padding:14px 16px 18px}
.top-tabs{
 display:flex;
 gap:10px;
 margin-bottom:10px;
}
.top-tab{
 padding:10px 18px;
 border-radius:14px 14px 0 0;
 background:#1a2231;
 color:#fff;
 border:1px solid rgba(255,255,255,.10);
 cursor:pointer;
 font-weight:700;
}
.top-tab.active{background:#203149;border-color:#315074;color:#d1fae5}
.status-strip{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:12px;
 padding:10px 14px;
 border:1px solid rgba(255,255,255,.08);
 border-radius:14px;
 background:rgba(255,255,255,.03);
 margin-bottom:14px;
}
.status-text{font-size:14px;font-weight:600}
.status-hint{font-size:12px;color:var(--muted);text-align:right}
.hidden{display:none !important}

.sheet-panel{
 background:rgba(15,24,18,.45);
 border:1px solid rgba(255,255,255,.07);
 border-radius:16px;
 overflow:hidden;
}
.sheet-head{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:12px;
 padding:10px 14px 0;
 background:rgba(255,255,255,.03);
 border-bottom:1px solid rgba(255,255,255,.08);
}
.model-tabs{
 display:flex;
 gap:8px;
 align-items:flex-end;
 flex-wrap:wrap;
}
.model-tab{
 padding:8px 16px;
 border-radius:12px 12px 0 0;
 background:#1a2231;
 color:#fff;
 border:1px solid rgba(255,255,255,.10);
 cursor:pointer;
 font-weight:700;
}
.model-tab.active{background:#203149;border-color:#315074;color:#d1fae5}
.sheet-title{padding:12px 14px;font-size:15px;font-weight:700}
.model-section.hidden-section{display:none}
.grid-wrap{
 overflow:auto;
 max-width:100%;
 background:rgba(0,0,0,.08);
}
.sheet{
 width:auto;
 min-width:100%;
 border-collapse:separate;
 border-spacing:0;
 table-layout:auto;
}
.sheet th,.sheet td{
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
 padding:10px 10px;
 font-size:12px;
 white-space:nowrap;
}
.sheet tr:first-child th:first-child,
.sheet tr:first-child td:first-child{border-left:1px solid var(--line)}
.sheet tbody tr:nth-child(even) td{background:#141a23}
.sheet tbody tr:hover td{background:#162131}
.sheet td.dirty-cell{background:#FFC7CE !important;color:#111827 !important}
.sheet tbody tr:hover td.dirty-cell{background:#FFC7CE !important}
.sheet td.dirty-cell .cell-input,.sheet td.dirty-cell .cell-textarea,.sheet td.dirty-cell .status-select,.sheet td.dirty-cell .vendor-trigger{color:#111827 !important}
.sheet td.dirty-cell .vendor-trigger::after,.sheet td.dirty-cell .select-wrap::after{color:#111827 !important}
.tool-tab-inline{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:0}
.tool-tab-inline .model-tab{margin:0}
.sheet td.actions{
 width:74px;
 min-width:74px;
 padding:4px 6px;
 background:#11161d !important;
}
.action-buttons{display:flex;justify-content:center;align-items:center;gap:6px}
.row-btn{
 width:24px;height:24px;border-radius:8px;border:1px solid rgba(255,255,255,.10);
 background:#232a34;color:#fff;cursor:pointer;line-height:1;
}
.row-btn:hover{background:#2b3440}
.row-btn.add{color:#bbf7d0}
.row-btn.delete{color:#fecaca}
.sheet td[data-field="tool_text"].merged-master{vertical-align:middle}
.sheet td.selected-range{position:relative;background:rgba(34,197,94,.12) !important}
.sheet td.selected-range.dirty-cell,.sheet tbody tr:hover td.selected-range.dirty-cell{background:#FFC7CE !important;color:#111827 !important}
.sheet td.selected-range.dirty-cell .cell-input,.sheet td.selected-range.dirty-cell .cell-textarea,.sheet td.selected-range.dirty-cell .vendor-trigger,.sheet td.selected-range.dirty-cell .status-select{color:#111827 !important}
.sheet td.selected-range.dirty-cell .vendor-trigger::after,.sheet td.selected-range.dirty-cell .select-wrap::after{color:#111827 !important}
.sheet td.selected-range::after{content:'';position:absolute;left:-1px;right:-1px;top:-1px;bottom:-1px;border-left:2px solid #22c55e;border-right:2px solid #22c55e;pointer-events:none;z-index:3}
.sheet td.selected-range.selection-top::after,.sheet td.selected-range.selection-single::after{border-top:2px solid #22c55e}
.sheet td.selected-range.selection-bottom::after,.sheet td.selected-range.selection-single::after{border-bottom:2px solid #22c55e}
.sheet td.selected-range .cell-input,.sheet td.selected-range .cell-textarea,.sheet td.selected-range .vendor-trigger,.sheet td.selected-range .status-select{position:relative;z-index:4}
body.cell-selecting,body.cell-selecting *{user-select:none !important}
.tool-merge-menu{position:fixed;left:-9999px;top:-9999px;min-width:140px;padding:6px;border-radius:10px;background:#0f1720;border:1px solid rgba(255,255,255,.12);box-shadow:0 16px 30px rgba(0,0,0,.38);z-index:2600}
.tool-merge-menu.hidden{display:none !important}
.tool-merge-menu button{display:block;width:100%;border:none;background:transparent;color:var(--text);text-align:left;padding:8px 10px;border-radius:8px;font:inherit;font-size:13px;cursor:pointer}
.tool-merge-menu button:hover{background:rgba(255,255,255,.08)}
.tool-merge-menu button[disabled]{opacity:.45;cursor:default}
.tool-merge-menu button[disabled]:hover{background:transparent}
.cell-editor,.cell-input,.cell-textarea,.status-select,.vendor-trigger{
 width:100%;
 display:block;
 margin:0;
 background:transparent;
 color:var(--text);
 border:none;
 outline:none;
 box-shadow:none;
 font:inherit;
 font-size:12px;
 line-height:1.2;
 text-align:center;
}
.cell-input,.vendor-trigger,.status-select{padding:6px 18px 6px 8px; min-height:32px}
.cell-textarea{
 resize:none;
 overflow:hidden;
 padding:8px 8px;
 min-height:32px;
 white-space:pre-wrap;
 word-break:break-word;
}
.cell-input{appearance:none;-webkit-appearance:none}
.cell-input.readonly,.cell-textarea.readonly,.vendor-trigger[disabled],.status-select[disabled]{opacity:.95;cursor:default}
.cell-input.short{white-space:nowrap}
.vendor-check{position:relative; min-width:72px}
.vendor-trigger{
 position:relative;
 cursor:pointer;
}
.vendor-trigger::after{
 content:'▾';
 position:absolute;
 right:8px; top:50%;
 transform:translateY(-50%);
 color:#93c5fd;
 font-size:11px;
 pointer-events:none;
}
.vendor-trigger.empty{color:#93c5fd}
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
.vendor-option{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text);padding:3px 0;cursor:pointer;user-select:none}
.vendor-option input{margin:0}
.status-select{
 appearance:none;-webkit-appearance:none;
 cursor:pointer;
 padding-right:24px;
}
.select-wrap{position:relative; min-width:74px}
.select-wrap::after{
 content:'▾';
 position:absolute;
 right:8px; top:50%;
 transform:translateY(-50%);
 color:#93c5fd;
 font-size:11px;
 pointer-events:none;
}
.readonly-message{
 margin-top:10px;
 padding:10px 14px;
 border-radius:12px;
 background:rgba(239,68,68,.10);
 border:1px solid rgba(239,68,68,.18);
 color:#fecaca;
 font-size:13px;
}
.toolsheet col.col-actions{width:74px}
.toolsheet col.col-item{width:1%}
.toolsheet col.col-tool{width:1%}
.toolsheet col.col-cavity{width:1%}
.toolsheet col.col-affect{width:1%}
.toolsheet col.col-vendor{width:1%}
.toolsheet col.col-type{width:1%}
.toolsheet col.col-issue{width:180px}
.toolsheet col.col-remark{width:auto}
.release-table col.col-actions{width:74px}
.release-table col.col-date{width:1%}
.release-table col.col-vendor{width:1%}
.release-table col.col-parts{width:1%}
.release-table col.col-tool{width:1%}
.release-table col.col-cavity{width:1%}
.release-table col.col-affect{width:1%}
.release-table col.col-type{width:1%}
.release-table col.col-issue{width:180px}
.release-table col.col-status{width:1%}
.release-table col.col-release-date{width:1%}
.release-table col.col-note{width:auto}
@media (max-width:1200px){
 .panel-head{flex-wrap:wrap}
 .status-strip{flex-direction:column;align-items:flex-start}
 .status-hint{text-align:left}
}
</style>
</head>
<body>
<div class="page">
<div class="panel">
    <div class="panel-head">
        <h1>고객사 출하 홀딩 내역</h1>
        <div class="head-actions">
            <span class="badge" id="userLvBadge">LV -</span>
            <button type="button" class="btn" id="reloadBtn">새로고침</button>
            <button type="button" class="btn primary" id="saveBtn">저장</button>
        </div>
    </div>
    <div class="content">
        <div class="top-tabs">
            <button type="button" class="top-tab active" data-tab="tool">Tool Status</button>
            <button type="button" class="top-tab" data-tab="release">홀딩 해제 세부 내역</button>
        </div>

        <div class="status-strip">
            <div class="status-text" id="statusText">불러오는 중...</div>
            <div class="status-hint">마지막 빈 행에 입력하면 아래에 새 빈 행이 자동으로 생깁니다.</div>
        </div>

        <div class="readonly-message hidden" id="readonlyMessage">현재 계정은 조회만 가능합니다. 레벨 77 이상부터 입력·수정·삭제·저장이 가능합니다.</div>

        <section id="toolTab">
            <div class="sheet-panel">
                <div class="sheet-head">
                    <div class="model-tabs" id="toolModelTabs"></div>
                    <button type="button" class="btn" id="toolAddRowBtn">+ 행 추가</button>
                </div>
                <div id="toolStatusRoot"></div>
            </div>
        </section>

        <section id="releaseTab" class="hidden">
            <div class="sheet-panel">
                <div class="sheet-head">
                    <div class="sheet-title">홀딩 해제 세부 내역</div>
                    <button type="button" class="btn" id="releaseAddRowBtn">+ 행 추가</button>
                </div>
                <div class="grid-wrap">
                    <table class="sheet release-table">
                        <colgroup>
                            <col class="col-actions">
                            <col class="col-date">
                            <col class="col-vendor">
                            <col class="col-parts">
                            <col class="col-tool">
                            <col class="col-cavity">
                            <col class="col-affect">
                            <col class="col-type">
                            <col class="col-issue">
                            <col class="col-status">
                            <col class="col-release-date">
                            <col class="col-note">
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
    boot: window.CUSTOMER_HOLD_BOOTSTRAP || {},
    toolStatusRows: [],
    releaseDetails: [],
    dirtyToolCells: new Set(),
    dirtyToolStructureCells: new Set(),
    dirtyReleaseCells: new Set(),
    originalToolValues: new Map(),
    originalReleaseValues: new Map(),
    nextCid: 1,
    toolSelection: null,
    toolSelectionDrag: null,
    toolSuppressClickSelection: false,
    toolManualMerges: {},
    toolMergeMenu: null
};

const MODELS = ['IR-BASE','Z-CARRIER','X-CARRIER','Y-CARRIER','Z-STOPPER'];
const TOOL_TYPE_OPTIONS = ['치수','외관'];
const RELEASE_TYPE_OPTIONS = ['Cosmetic','Dimension'];
const VENDOR_OPTIONS = ['자화','LGIT'];
const CAVITY_OPTIONS = ['ALL','1','2','3','4'];

const toolColumns = [
    { key: 'item_code', label: 'Item' },
    { key: 'tool_text', label: 'Tool' },
    { key: 'cavity_text', label: 'Cavity', checkbox: 'cavity' },
    { key: 'affect_lot_text', label: 'Affect Lot' },
    { key: 'vendor_text', label: 'Vendor', checkbox: 'vendor' },
    { key: 'type_text', label: 'Type', checkbox: 'toolType' },
    { key: 'issue_description_text', label: 'Issue Description', multiline: true },
    { key: 'remark_text', label: 'Remark', multiline: true, flex: true }
];

const releaseColumns = [
    { key: 'holding_date_text' },
    { key: 'vendor_text', checkbox: 'vendor' },
    { key: 'parts_name_text' },
    { key: 'tool_text' },
    { key: 'cavity_text', checkbox: 'cavity' },
    { key: 'affect_lot_text' },
    { key: 'type_text', checkbox: 'releaseType' },
    { key: 'issue_description_text', multiline: true },
    { key: 'status_text', status: true },
    { key: 'release_date_text' },
    { key: 'note_text', multiline: true, flex: true }
];

const els = {
    topTabs: Array.from(document.querySelectorAll('.top-tab')),
    toolTab: document.getElementById('toolTab'),
    releaseTab: document.getElementById('releaseTab'),
    toolModelTabs: document.getElementById('toolModelTabs'),
    toolStatusRoot: document.getElementById('toolStatusRoot'),
    releaseBody: document.getElementById('releaseBody'),
    readonlyMessage: document.getElementById('readonlyMessage'),
    userLvBadge: document.getElementById('userLvBadge'),
    statusText: document.getElementById('statusText'),
    saveBtn: document.getElementById('saveBtn'),
    reloadBtn: document.getElementById('reloadBtn'),
    toolAddRowBtn: document.getElementById('toolAddRowBtn'),
    releaseAddRowBtn: document.getElementById('releaseAddRowBtn')
};

function setStatus(text, isError){
    els.statusText.textContent = text || '';
    els.statusText.style.color = isError ? '#fecaca' : '';
}

function isToolManualMergeField(field){
    return ['item_code','tool_text','affect_lot_text','issue_description_text','remark_text'].includes(String(field || ''));
}

function normalizeRange(range){
    if (!range) return null;
    const start = Math.max(0, Number(range.start) || 0);
    const end = Math.max(start, Number(range.end) || 0);
    return {start:start, end:end};
}

function normalizeRanges(ranges){
    const list = (Array.isArray(ranges) ? ranges : []).map(normalizeRange).filter(Boolean).sort(function(a, b){
        return a.start - b.start || a.end - b.end;
    });
    const out = [];
    list.forEach(function(range){
        const last = out[out.length - 1];
        if (last && range.start <= last.end + 1) {
            last.end = Math.max(last.end, range.end);
        } else {
            out.push({start:range.start, end:range.end});
        }
    });
    return out;
}

function rangesOverlap(a, b){
    return !!a && !!b && a.start <= b.end && b.start <= a.end;
}

function peekToolManualState(model, field){
    const byModel = state.toolManualMerges[String(model || '').toUpperCase()];
    if (!byModel) return null;
    return byModel[String(field || '')] || null;
}

function getToolManualState(model, field){
    const key = String(model || '').toUpperCase();
    if (!state.toolManualMerges[key]) state.toolManualMerges[key] = {};
    if (!state.toolManualMerges[key][field]) {
        state.toolManualMerges[key][field] = {merge:[], split:[]};
    }
    return state.toolManualMerges[key][field];
}

function clearToolSelection(){
    state.toolSelection = null;
    applyToolSelectionDom();
    hideToolMergeMenu();
}

function clearToolManualState(model){
    if (model) delete state.toolManualMerges[String(model || '').toUpperCase()];
    else state.toolManualMerges = {};
    if (state.toolSelection && (!model || String(state.toolSelection.model || '').toUpperCase() === String(model || '').toUpperCase())) {
        state.toolSelection = null;
    }
    applyToolSelectionDom();
    hideToolMergeMenu();
}

function setToolSelection(model, field, startRow, endRow){
    if (!isToolManualMergeField(field)) return;
    state.toolSelection = {
        model: String(model || '').toUpperCase(),
        field: String(field || ''),
        startRow: Math.min(Number(startRow) || 0, Number(endRow) || 0),
        endRow: Math.max(Number(startRow) || 0, Number(endRow) || 0)
    };
    applyToolSelectionDom();
}

function getToolSelection(){
    return state.toolSelection ? {
        model: state.toolSelection.model,
        field: state.toolSelection.field,
        startRow: state.toolSelection.startRow,
        endRow: state.toolSelection.endRow
    } : null;
}

function getToolRowsForModel(model){
    const grouped = buildToolGroups(state.toolStatusRows);
    return grouped[String(model || '').toUpperCase()] || [];
}

function getToolSelectionRows(sel){
    if (!sel) return null;
    const rows = getToolRowsForModel(sel.model);
    if (sel.startRow < 0 || sel.endRow >= rows.length) return null;
    return rows.slice(sel.startRow, sel.endRow + 1);
}

function isSelectionSingleToolBlock(sel){
    if (!sel) return false;
    if (sel.field === 'item_code' || sel.field === 'tool_text') return true;
    const rows = getToolSelectionRows(sel);
    if (!rows || !rows.length) return false;
    const baseTool = normalizeText(rows[0].tool_text || '');
    return rows.every(function(row){ return normalizeText(row.tool_text || '') === baseTool; });
}

function isToolMergeSelectionValid(sel){
    if (!sel || !isToolManualMergeField(sel.field) || sel.endRow <= sel.startRow) return false;
    const rows = getToolSelectionRows(sel);
    return !!(rows && rows.length);
}

function getToolMergedRangesForSelection(sel){
    if (!sel || !isToolManualMergeField(sel.field)) return [];
    const rows = getToolRowsForModel(sel.model);
    if (!rows.length || sel.endRow >= rows.length) return [];
    const merge = buildToolRowspans(rows, sel.model);
    const spans = merge.spans[sel.field] || [];
    const ranges = [];
    for (let i = 0; i < spans.length; i++) {
        const span = Number(spans[i] || 1);
        if (span <= 1) continue;
        const end = i + span - 1;
        if (!(end < sel.startRow || i > sel.endRow)) {
            ranges.push({start:i, end:end});
        }
    }
    return normalizeRanges(ranges);
}

function selectionIntersectsAnyToolMerge(sel){
    return getToolMergedRangesForSelection(sel).length > 0;
}

function fillToolSelectionValue(sel){
    if (!sel || !isToolManualMergeField(sel.field)) return;
    const rows = getToolRowsForModel(sel.model);
    if (!rows.length || sel.startRow < 0 || sel.endRow >= rows.length) return;
    const baseRow = rows[sel.startRow];
    if (!baseRow) return;
    let nextValue = baseRow[sel.field] || '';
    if (sel.field === 'tool_text') nextValue = normalizeText(nextValue).toUpperCase();
    for (let idx = sel.startRow; idx <= sel.endRow; idx++) {
        const row = rows[idx];
        if (!row) continue;
        ensureCid(row);
        seedToolOriginals(row);
        row[sel.field] = nextValue;
        setToolFieldDirtyState(row, sel.field, nextValue, null);
    }
}

function setToolStructureRangeDirty(model, field, startRow, endRow, dirty){
    if (!isToolManualMergeField(field)) return;
    const rows = getToolRowsForModel(model);
    if (!rows.length) {
        refreshDirtyFlag();
        return;
    }
    const start = Math.max(0, Number(startRow) || 0);
    const end = Math.min(rows.length - 1, Math.max(start, Number(endRow) || 0));
    for (let idx = start; idx <= end; idx++) {
        const row = rows[idx];
        if (!row) continue;
        ensureCid(row);
        seedToolOriginals(row);
        if (dirty) markToolStructureDirty(row, field);
        else clearToolStructureDirty(row, field);
        syncToolCellDirtyDom(row, field, isToolDirty(row, field));
    }
    refreshDirtyFlag();
}

function addToolMergeRange(model, field, startRow, endRow){
    const target = normalizeRange({start:startRow, end:endRow});
    const bucket = getToolManualState(model, field);
    bucket.merge = normalizeRanges(bucket.merge.filter(function(range){ return !rangesOverlap(range, target); }).concat([target]));
    bucket.split = normalizeRanges(bucket.split.filter(function(range){ return !rangesOverlap(range, target); }));
}

function addToolSplitRange(model, field, startRow, endRow){
    const target = normalizeRange({start:startRow, end:endRow});
    const bucket = getToolManualState(model, field);
    bucket.merge = normalizeRanges(bucket.merge.filter(function(range){ return !rangesOverlap(range, target); }));
    bucket.split = normalizeRanges(bucket.split.concat([target]));
}

function findRangeStarting(ranges, index){
    return (Array.isArray(ranges) ? ranges : []).find(function(range){ return range.start === index; }) || null;
}

function rangeContains(ranges, index){
    return (Array.isArray(ranges) ? ranges : []).some(function(range){ return index >= range.start && index <= range.end; });
}

function applyToolSelectionDom(){
    const table = els.toolStatusRoot ? els.toolStatusRoot.querySelector('table.toolsheet') : null;
    if (!table) return;
    const sel = getToolSelection();
    table.querySelectorAll('td.selected-range').forEach(function(td){
        td.classList.remove('selected-range','selection-top','selection-bottom','selection-single');
    });
    if (!sel || sel.model !== String(state.activeToolModel || '').toUpperCase()) return;
    table.querySelectorAll('tbody td[data-field][data-row-index]').forEach(function(td){
        const field = td.getAttribute('data-field');
        if (field !== sel.field) return;
        const start = Number(td.getAttribute('data-row-index') || 0);
        const span = Number(td.getAttribute('data-row-span') || 1);
        const end = start + Math.max(1, span) - 1;
        if (end < sel.startRow || start > sel.endRow) return;
        td.classList.add('selected-range');
        const touchesTop = sel.startRow >= start && sel.startRow <= end;
        const touchesBottom = sel.endRow >= start && sel.endRow <= end;
        if (touchesTop) td.classList.add('selection-top');
        if (touchesBottom) td.classList.add('selection-bottom');
        if (touchesTop && touchesBottom) td.classList.add('selection-single');
    });
}

function ensureToolMergeMenu(){
    if (state.toolMergeMenu) return state.toolMergeMenu;
    const menu = document.createElement('div');
    menu.className = 'tool-merge-menu hidden';
    const mergeBtn = document.createElement('button');
    mergeBtn.type = 'button';
    mergeBtn.setAttribute('data-action', 'merge');
    mergeBtn.textContent = '병합';
    const unmergeBtn = document.createElement('button');
    unmergeBtn.type = 'button';
    unmergeBtn.setAttribute('data-action', 'unmerge');
    unmergeBtn.textContent = '병합 해제';
    menu.appendChild(mergeBtn);
    menu.appendChild(unmergeBtn);
    menu.addEventListener('mousedown', function(ev){
        ev.stopPropagation();
    });
    menu.addEventListener('click', function(ev){
        const btn = ev.target.closest('button[data-action]');
        if (!btn || btn.disabled) return;
        const sel = getToolSelection();
        if (!sel) return;
        if (btn.getAttribute('data-action') === 'merge') {
            if (!isToolMergeSelectionValid(sel)) {
                setStatus('같은 열의 연속 영역만 병합할 수 있습니다.', true);
                hideToolMergeMenu();
                return;
            }
            fillToolSelectionValue(sel);
            addToolMergeRange(sel.model, sel.field, sel.startRow, sel.endRow);
            setToolStructureRangeDirty(sel.model, sel.field, sel.startRow, sel.endRow, true);
            renderToolStatus();
            setToolSelection(sel.model, sel.field, sel.startRow, sel.endRow);
            setStatus('선택 영역을 병합했습니다.', false);
        } else {
            const ranges = getToolMergedRangesForSelection(sel);
            if (!ranges.length) {
                setStatus('해제할 병합 영역이 없습니다.', true);
                hideToolMergeMenu();
                return;
            }
            ranges.forEach(function(range){
                addToolSplitRange(sel.model, sel.field, range.start, range.end);
                setToolStructureRangeDirty(sel.model, sel.field, range.start, range.end, true);
            });
            renderToolStatus();
            const first = ranges[0];
            const last = ranges[ranges.length - 1];
            setToolSelection(sel.model, sel.field, first.start, last.end);
            setStatus('선택 영역 병합을 해제했습니다.', false);
        }
        hideToolMergeMenu();
    });
    document.body.appendChild(menu);
    state.toolMergeMenu = menu;
    return menu;
}

function hideToolMergeMenu(){
    const menu = ensureToolMergeMenu();
    menu.classList.add('hidden');
    menu.style.left = '-9999px';
    menu.style.top = '-9999px';
}

function showToolMergeMenu(x, y){
    const sel = getToolSelection();
    if (!sel || !state.canEdit) return;
    const menu = ensureToolMergeMenu();
    const canMerge = isToolMergeSelectionValid(sel);
    const canUnmerge = selectionIntersectsAnyToolMerge(sel);
    menu.querySelector('[data-action="merge"]').disabled = !canMerge;
    menu.querySelector('[data-action="unmerge"]').disabled = !canUnmerge;
    menu.classList.remove('hidden');
    menu.style.left = Math.max(8, x) + 'px';
    menu.style.top = Math.max(8, y) + 'px';
}

function getToolCellMeta(target){
    const td = target && target.closest ? target.closest('.toolsheet td[data-field]') : null;
    if (!td) return null;
    const tr = td.parentElement;
    if (tr && tr.classList.contains('blank-row')) return null;
    const field = String(td.getAttribute('data-field') || '');
    if (!isToolManualMergeField(field)) return null;
    const rowIndex = Number(td.getAttribute('data-row-index') || 0);
    if (!Number.isFinite(rowIndex)) return null;
    return {
        td: td,
        field: field,
        rowIndex: rowIndex,
        rowSpan: Number(td.getAttribute('data-row-span') || 1),
        model: String(state.activeToolModel || '').toUpperCase()
    };
}

function setDirty(flag){
    state.dirty = !!flag;
    setStatus(flag ? '저장되지 않은 변경사항이 있습니다.' : '저장 완료된 최신 상태입니다.', false);
}

function nextCid(){ return 'cid_' + (state.nextCid++); }
function ensureCid(row){ if (row && !row._cid) row._cid = nextCid(); return row; }
function toolRowIdentity(row){ row = ensureCid(row || {}); return (Number(row.id) > 0 ? 'id:' + Number(row.id) : row._cid); }
function releaseRowIdentity(row){ row = ensureCid(row || {}); return (Number(row.id) > 0 ? 'id:' + Number(row.id) : row._cid); }
function toolDirtyKey(row, field){ return toolRowIdentity(row) + '|' + field; }
function releaseDirtyKey(row, field){ return releaseRowIdentity(row) + '|' + field; }
function markToolDirty(row, field){ state.dirtyToolCells.add(toolDirtyKey(row, field)); }
function markToolStructureDirty(row, field){ state.dirtyToolStructureCells.add(toolDirtyKey(row, field)); }
function markReleaseDirty(row, field){ state.dirtyReleaseCells.add(releaseDirtyKey(row, field)); }
function clearToolDirty(row, field){ state.dirtyToolCells.delete(toolDirtyKey(row, field)); }
function clearToolStructureDirty(row, field){ state.dirtyToolStructureCells.delete(toolDirtyKey(row, field)); }
function clearReleaseDirty(row, field){ state.dirtyReleaseCells.delete(releaseDirtyKey(row, field)); }
function isToolDirty(row, field){ return state.dirtyToolCells.has(toolDirtyKey(row, field)) || state.dirtyToolStructureCells.has(toolDirtyKey(row, field)); }
function isReleaseDirty(row, field){ return state.dirtyReleaseCells.has(releaseDirtyKey(row, field)); }
function toolOriginalKey(row, field){ return toolRowIdentity(row) + '|' + field; }
function releaseOriginalKey(row, field){ return releaseRowIdentity(row) + '|' + field; }
function setToolOriginal(row, field, value){ state.originalToolValues.set(toolOriginalKey(row, field), normalizeText(value)); }
function setReleaseOriginal(row, field, value){ state.originalReleaseValues.set(releaseOriginalKey(row, field), normalizeText(value)); }
function getToolOriginal(row, field){ return state.originalToolValues.has(toolOriginalKey(row, field)) ? state.originalToolValues.get(toolOriginalKey(row, field)) : ''; }
function getReleaseOriginal(row, field){ return state.originalReleaseValues.has(releaseOriginalKey(row, field)) ? state.originalReleaseValues.get(releaseOriginalKey(row, field)) : ''; }
function seedToolOriginals(row){
    ensureCid(row || {});
    ['item_code','tool_text','cavity_text','affect_lot_text','vendor_text','type_text','issue_description_text','remark_text'].forEach(function(field){
        if (!state.originalToolValues.has(toolOriginalKey(row, field))) setToolOriginal(row, field, row[field] || '');
    });
}
function seedReleaseOriginals(row){
    ensureCid(row || {});
    ['holding_date_text','vendor_text','parts_name_text','tool_text','cavity_text','affect_lot_text','type_text','issue_description_text','status_text','release_date_text','note_text'].forEach(function(field){
        if (!state.originalReleaseValues.has(releaseOriginalKey(row, field))) setReleaseOriginal(row, field, row[field] || '');
    });
}
function refreshDirtyFlag(){
    setDirty(state.dirtyToolCells.size > 0 || state.dirtyToolStructureCells.size > 0 || state.dirtyReleaseCells.size > 0);
}
function purgeToolRowState(row){
    const prefix = toolRowIdentity(row) + '|';
    Array.from(state.dirtyToolCells).forEach(function(key){ if (key.indexOf(prefix) === 0) state.dirtyToolCells.delete(key); });
    Array.from(state.dirtyToolStructureCells).forEach(function(key){ if (key.indexOf(prefix) === 0) state.dirtyToolStructureCells.delete(key); });
    Array.from(state.originalToolValues.keys()).forEach(function(key){ if (key.indexOf(prefix) === 0) state.originalToolValues.delete(key); });
}
function purgeReleaseRowState(row){
    const prefix = releaseRowIdentity(row) + '|';
    Array.from(state.dirtyReleaseCells).forEach(function(key){ if (key.indexOf(prefix) === 0) state.dirtyReleaseCells.delete(key); });
    Array.from(state.originalReleaseValues.keys()).forEach(function(key){ if (key.indexOf(prefix) === 0) state.originalReleaseValues.delete(key); });
}
function syncToolCellDirtyDom(row, field, isDirty){
    const rowId = toolRowIdentity(row);
    document.querySelectorAll('td[data-rowid="' + rowId + '"][data-field="' + field + '"]').forEach(function(td){
        td.classList.toggle('dirty-cell', !!isDirty);
    });
}
function syncReleaseCellDirtyDom(row, field, isDirty){
    const rowId = releaseRowIdentity(row);
    document.querySelectorAll('td[data-rowid="' + rowId + '"][data-field="' + field + '"]').forEach(function(td){
        td.classList.toggle('dirty-cell', !!isDirty);
    });
}
function setToolFieldDirtyState(row, field, value, cell){
    const isDirty = normalizeText(value) !== getToolOriginal(row, field);
    if (isDirty) markToolDirty(row, field); else clearToolDirty(row, field);
    const finalDirty = isToolDirty(row, field);
    if (cell) cell.classList.toggle('dirty-cell', finalDirty);
    syncToolCellDirtyDom(row, field, finalDirty);
    refreshDirtyFlag();
}
function setReleaseFieldDirtyState(row, field, value, cell){
    const isDirty = normalizeText(value) !== getReleaseOriginal(row, field);
    if (isDirty) markReleaseDirty(row, field); else clearReleaseDirty(row, field);
    if (cell) cell.classList.toggle('dirty-cell', isDirty);
    syncReleaseCellDirtyDom(row, field, isDirty);
    refreshDirtyFlag();
}

function normalizeText(value){
    return String(value == null ? '' : value).replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
}

function anyFilled(row, keys){
    return keys.some(function(key){ return normalizeText(row[key]) !== ''; });
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
        const aPrimary = toolPrimaryKey(a && a.tool_text);
        const bPrimary = toolPrimaryKey(b && b.tool_text);
        if (aPrimary !== bPrimary) return aPrimary.localeCompare(bPrimary, 'en', { numeric:true, sensitivity:'base' });
        const aFull = toolFullKey(a && a.tool_text);
        const bFull = toolFullKey(b && b.tool_text);
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

function toolBlankRow(model){
    return {
        id: 0,
        part_name: model,
        item_code: model,
        tool_text: '',
        cavity_text: '',
        affect_lot_text: '',
        vendor_text: '',
        type_text: '',
        issue_description_text: '',
        remark_text: '',
        sort_order: 999999
    };
}

function releaseBlankRow(){
    return {
        id: 0,
        holding_date_text: '',
        vendor_text: '',
        parts_name_text: '',
        tool_text: '',
        cavity_text: '',
        affect_lot_text: '',
        type_text: '',
        issue_description_text: '',
        status_text: '',
        release_date_text: '',
        note_text: '',
        sort_order: 999999
    };
}

function buildToolRowsForRender(model){
    const grouped = buildToolGroups(state.toolStatusRows);
    const rows = (grouped[model] || []).slice();
    rows.push(toolBlankRow(model));
    return rows;
}

function buildReleaseRowsForRender(){
    const rows = (state.releaseDetails || []).slice().sort(function(a,b){
        return ((Number(a.sort_order)||0) - (Number(b.sort_order)||0));
    });
    rows.push(releaseBlankRow());
    return rows;
}

function createInput(value, opts){
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'cell-input short';
    input.value = value || '';
    if (opts && opts.readonly) {
        input.readOnly = true;
        input.classList.add('readonly');
    }
    return input;
}

function rememberEditorValue(el, getter){
    if (!el) return;
    const read = typeof getter === 'function' ? getter : function(){ return typeof el.value === 'string' ? el.value : ''; };
    if (!el.dataset) return;
    el.dataset.escapeOriginal = read() || '';
}

function attachEscCancel(el, getter, setter, onRevert){
    if (!el) return;
    const read = typeof getter === 'function' ? getter : function(){ return typeof el.value === 'string' ? el.value : ''; };
    const write = typeof setter === 'function' ? setter : function(next){ if (typeof el.value === 'string') el.value = next; };
    el.addEventListener('focus', function(){
        rememberEditorValue(el, read);
    });
    el.addEventListener('keydown', function(e){
        if (e.key !== 'Escape') return;
        e.preventDefault();
        e.stopPropagation();
        const original = (el.dataset && typeof el.dataset.escapeOriginal === 'string') ? el.dataset.escapeOriginal : '';
        const current = read() || '';
        if (current !== original) {
            write(original);
            if (typeof onRevert === 'function') onRevert(original);
        }
        try { el.blur(); } catch (_e) {}
    });
    el.addEventListener('blur', function(){
        rememberEditorValue(el, read);
    });
}

function autoResizeTextarea(el){
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.max(32, el.scrollHeight) + 'px';
}

function createTextarea(value, opts){
    const el = document.createElement('textarea');
    el.className = 'cell-textarea';
    el.value = value || '';
    el.rows = 1;
    if (opts && opts.readonly) {
        el.readOnly = true;
        el.classList.add('readonly');
    }
    setTimeout(function(){ autoResizeTextarea(el); }, 0);
    el.addEventListener('input', function(){ autoResizeTextarea(el); });
    return el;
}

function optionLabel(values){
    const arr = (values || []).filter(Boolean);
    return arr.join(' / ');
}

function splitMultiValue(value){
    return normalizeText(value).split(/[\n,\/]+/).map(function(v){ return normalizeText(v); }).filter(Boolean);
}

function formatSelection(values, mode){
    const arr = Array.from(new Set((values || []).map(function(v){ return normalizeText(v).toUpperCase(); }).filter(Boolean)));
    if (!arr.length) return '';
    if (mode === 'vendor') {
        return arr.map(function(v){ return v === 'LGIT' ? 'LGIT' : '자화'; }).join(' / ');
    }
    if (mode === 'toolType') {
        return arr.map(function(v){ return v === '외관' ? '외관' : '치수'; }).join(' / ');
    }
    if (mode === 'releaseType') {
        return arr.map(function(v){
            if (v === 'COSMETIC') return 'Cosmetic';
            if (v === 'DIMENSION') return 'Dimension';
            return v;
        }).join(' / ');
    }
    if (mode === 'cavity') {
        if (arr.includes('ALL')) return 'ALL';
        return ['1','2','3','4'].filter(function(v){ return arr.includes(v); }).join(' / ');
    }
    return arr.join(' / ');
}

function createCheckboxDropdown(value, mode, editable, onChange){
    const wrap = document.createElement('div');
    wrap.className = 'vendor-check';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'vendor-trigger';
    trigger.textContent = formatSelection(splitMultiValue(value), mode);
    if (!trigger.textContent) trigger.classList.add('empty');
    if (!editable) trigger.disabled = true;
    const panel = document.createElement('div');
    panel.className = 'vendor-panel hidden';

    let options = [];
    if (mode === 'vendor') options = VENDOR_OPTIONS;
    else if (mode === 'cavity') options = CAVITY_OPTIONS;
    else if (mode === 'toolType') options = TOOL_TYPE_OPTIONS;
    else if (mode === 'releaseType') options = RELEASE_TYPE_OPTIONS;

    let selected = splitMultiValue(value).map(function(v){ return mode === 'releaseType' ? v : v.toUpperCase(); });
    if (mode === 'vendor') selected = selected.map(function(v){ return v.toUpperCase() === 'LGIT' ? 'LGIT' : '자화'; });
    if (mode === 'toolType') selected = selected.map(function(v){ return v === '외관' ? '외관' : '치수'; });
    if (mode === 'releaseType') selected = selected.map(function(v){
        const t = String(v).toLowerCase();
        return t === 'cosmetic' ? 'Cosmetic' : (t === 'dimension' ? 'Dimension' : v);
    });
    if (mode === 'cavity') selected = selected.map(function(v){ return String(v).toUpperCase(); });
    let selectedSnapshot = selected.slice();

    function syncPanelChecks(){
        Array.from(panel.querySelectorAll('input[type="checkbox"]')).forEach(function(box){
            const v = box.getAttribute('data-value');
            box.checked = selected.includes(v);
        });
    }

    function commit(notify){
        const text = formatSelection(selected, mode);
        trigger.textContent = text;
        if (text) trigger.classList.remove('empty'); else trigger.classList.add('empty');
        if (notify !== false && onChange) onChange(text);
    }

    options.forEach(function(opt){
        const label = document.createElement('label');
        label.className = 'vendor-option';
        const check = document.createElement('input');
        check.type = 'checkbox';
        check.checked = selected.includes(opt);
        check.addEventListener('change', function(){
            let next = selected.slice();
            if (mode === 'cavity') {
                if (opt === 'ALL') {
                    next = check.checked ? ['ALL'] : [];
                } else {
                    next = next.filter(function(v){ return v !== 'ALL'; });
                    if (check.checked) next.push(opt); else next = next.filter(function(v){ return v !== opt; });
                }
            } else {
                if (check.checked) next.push(opt);
                else next = next.filter(function(v){ return v !== opt; });
            }
            selected = Array.from(new Set(next));
            syncPanelChecks();
            commit(true);
        });
        check.setAttribute('data-value', opt);
        const span = document.createElement('span');
        span.textContent = opt;
        label.appendChild(check);
        label.appendChild(span);
        panel.appendChild(label);
    });

    function positionPanel(){
        panel.classList.remove('hidden');
        panel.style.left = '-9999px';
        panel.style.top = '-9999px';
        const rect = trigger.getBoundingClientRect();
        const w = Math.max(rect.width, 120);
        panel.style.minWidth = w + 'px';
        const panelRect = panel.getBoundingClientRect();
        let left = rect.left;
        let top = rect.bottom + 4;
        if (left + panelRect.width > window.innerWidth - 8) left = window.innerWidth - panelRect.width - 8;
        if (top + panelRect.height > window.innerHeight - 8) top = rect.top - panelRect.height - 4;
        if (left < 8) left = 8;
        if (top < 8) top = 8;
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }

    function closePanel(){
        panel.classList.add('hidden');
        panel.style.left = '-9999px';
        panel.style.top = '-9999px';
    }

    function revertToSnapshot(){
        selected = selectedSnapshot.slice();
        syncPanelChecks();
        commit(true);
    }

    if (editable) {
        trigger.addEventListener('click', function(e){
            e.preventDefault();
            const open = panel.classList.contains('hidden');
            document.querySelectorAll('.vendor-panel').forEach(function(p){
                p.classList.add('hidden');
                p.style.left='-9999px'; p.style.top='-9999px';
            });
            if (open) {
                selectedSnapshot = selected.slice();
                positionPanel();
            }
        });
        [trigger, panel].forEach(function(node){
            node.addEventListener('keydown', function(e){
                if (e.key !== 'Escape') return;
                e.preventDefault();
                e.stopPropagation();
                revertToSnapshot();
                closePanel();
                try { trigger.focus({preventScroll:true}); } catch (_e) { try { trigger.focus(); } catch (_e2) {} }
            });
        });
    }

    document.addEventListener('click', function(e){
        if (!wrap.contains(e.target) && !panel.contains(e.target)) closePanel();
    });
    window.addEventListener('resize', closePanel);
    window.addEventListener('scroll', closePanel, true);

    wrap.appendChild(trigger);
    document.body.appendChild(panel);
    commit(false);
    return {wrap:wrap, panel:panel, close:closePanel};
}

function createStatusSelect(value, editable){
    const wrap = document.createElement('div');
    wrap.className = 'select-wrap';
    const sel = document.createElement('select');
    sel.className = 'status-select';
    [['',''],['Ongoing','Ongoing'],['Close','Close']].forEach(function(pair){
        const opt = document.createElement('option');
        opt.value = pair[0];
        opt.textContent = pair[1];
        sel.appendChild(opt);
    });
    sel.value = value || '';
    sel.disabled = !editable;
    wrap.appendChild(sel);
    return {wrap:wrap, input:sel};
}

function markDirtyAndEnsureBlank(){
    setDirty(true);
}

function focusEditable(el, caretPos){
    if (!el || typeof el.focus !== 'function') return;
    try { el.focus({preventScroll:true}); } catch (_e) { try { el.focus(); } catch (_e2) {} }
    const pos = typeof caretPos === 'number' ? caretPos : (typeof el.value === 'string' ? el.value.length : 0);
    if (typeof el.setSelectionRange === 'function') {
        try { el.setSelectionRange(pos, pos); } catch (_e) {}
    }
}

function focusToolField(model, visibleIndex, field, caretPos){
    requestAnimationFrame(function(){
        const table = els.toolStatusRoot.querySelector('table.toolsheet');
        if (!table) return;
        const row = table.querySelectorAll('tbody tr')[visibleIndex];
        if (!row) return;
        const cell = row.querySelector('td[data-field="' + field + '"]');
        if (!cell) return;
        const el = cell.querySelector('input, textarea, select, button.vendor-trigger');
        focusEditable(el, caretPos);
    });
}

function focusReleaseField(visibleIndex, field, caretPos){
    requestAnimationFrame(function(){
        const row = els.releaseBody.querySelectorAll('tr')[visibleIndex];
        if (!row) return;
        const cell = row.querySelectorAll('td')[releaseColumns.findIndex(function(col){ return col.key === field; }) + 1];
        if (!cell) return;
        const el = cell.querySelector('input, textarea, select, button.vendor-trigger');
        focusEditable(el, caretPos);
    });
}

function updateToolRowField(model, visibleIndex, field, value, meta){
    const fields = ['tool_text','cavity_text','affect_lot_text','vendor_text','type_text','issue_description_text','remark_text'];
    const grouped = buildToolGroups(state.toolStatusRows);
    const rows = grouped[model] || [];
    const isNew = visibleIndex >= rows.length;
    const normalized = field === 'tool_text' ? normalizeText(value).toUpperCase() : value;
    const mergeableSharedFields = ['tool_text','affect_lot_text','issue_description_text','remark_text'];
    const mergeSpan = (!isNew && meta && meta.span > 1 && mergeableSharedFields.includes(field)) ? meta.span : 1;

    let target = isNew ? toolBlankRow(model) : rows[visibleIndex];
    ensureCid(target);
    seedToolOriginals(target);
    const wasBlank = !anyFilled(target, fields);
    const previous = target[field] || '';

    if (mergeSpan > 1) {
        const affectedRows = [];
        for (let idx = visibleIndex; idx < Math.min(visibleIndex + mergeSpan, rows.length); idx++) {
            const sharedRow = rows[idx];
            ensureCid(sharedRow);
            seedToolOriginals(sharedRow);
            sharedRow[field] = normalized;
            setToolFieldDirtyState(sharedRow, field, normalized, null);
            affectedRows.push(sharedRow);
        }
        if (meta && meta.cell) {
            const hasDirty = affectedRows.some(function(sharedRow){ return isToolDirty(sharedRow, field); });
            meta.cell.classList.toggle('dirty-cell', hasDirty);
            meta.cell.setAttribute('data-measure', normalized);
        }
        target = affectedRows[0] || target;
    } else {
        target[field] = normalized;
        if (previous !== normalized) {
            setToolFieldDirtyState(target, field, normalized, meta && meta.cell ? meta.cell : null);
        }
        if (meta && meta.cell) meta.cell.setAttribute('data-measure', normalized);
    }

    if (isNew) rows.push(target);
    grouped[model] = rows;
    state.toolStatusRows = []
        .concat(grouped['IR-BASE'] || [])
        .concat(grouped['Z-CARRIER'] || [])
        .concat(grouped['X-CARRIER'] || [])
        .concat(grouped['Y-CARRIER'] || [])
        .concat(grouped['Z-STOPPER'] || []);

    const needsReorder = field === 'tool_text' || field === 'item_code';
    const becameFilled = wasBlank && anyFilled(target, fields);

    if (needsReorder) {
        const regrouped = buildToolGroups(state.toolStatusRows);
        state.toolStatusRows = []
            .concat(regrouped['IR-BASE'] || [])
            .concat(regrouped['Z-CARRIER'] || [])
            .concat(regrouped['X-CARRIER'] || [])
            .concat(regrouped['Y-CARRIER'] || [])
            .concat(regrouped['Z-STOPPER'] || []);
    }

    renumberToolRows(model);
    refreshDirtyFlag();

    if (needsReorder || isNew) clearToolManualState(model);

    if (needsReorder || becameFilled || isNew) {
        renderCurrent();
        focusToolField(model, visibleIndex, field, meta && typeof meta.caretPos === 'number' ? meta.caretPos : undefined);
    } else {
        applyToolSelectionDom();
    }
}

function renumberToolRows(model){
    const grouped = buildToolGroups(state.toolStatusRows);
    const rows = grouped[model] || [];
    rows.forEach(function(row, idx){ row.sort_order = idx + 1; });
    state.toolStatusRows = []
        .concat(grouped['IR-BASE'] || [])
        .concat(grouped['Z-CARRIER'] || [])
        .concat(grouped['X-CARRIER'] || [])
        .concat(grouped['Y-CARRIER'] || [])
        .concat(grouped['Z-STOPPER'] || []);
}

function insertToolRowBelow(model, visibleIndex){
    clearToolManualState(model);
    const grouped = buildToolGroups(state.toolStatusRows);
    const rows = grouped[model] || [];
    const base = rows[visibleIndex] || toolBlankRow(model);
    const next = ensureCid({
        id: 0,
        part_name: model,
        item_code: model,
        tool_text: base.tool_text || '',
        cavity_text: '',
        affect_lot_text: '',
        vendor_text: '',
        type_text: '',
        issue_description_text: '',
        remark_text: '',
        sort_order: (visibleIndex + 1.5)
    });
    seedToolOriginals(next);
    seedReleaseOriginals(next);
    rows.splice(Math.min(visibleIndex + 1, rows.length), 0, next);
    rows.forEach(function(row, idx){ row.sort_order = idx + 1; });
    grouped[model] = rows;
    state.toolStatusRows = []
        .concat(grouped['IR-BASE'] || [])
        .concat(grouped['Z-CARRIER'] || [])
        .concat(grouped['X-CARRIER'] || [])
        .concat(grouped['Y-CARRIER'] || [])
        .concat(grouped['Z-STOPPER'] || []);
    refreshDirtyFlag();
    renderCurrent();
}

async function deleteToolRow(row){
    if (!row) return;
    clearToolManualState(String(row.part_name || '').toUpperCase());
    const rowId = toolRowIdentity(row);
    if (Number(row.id) > 0) {
        setStatus('삭제 중...', false);
        await request('delete_tool_status', {id: Number(row.id)});
    }
    purgeToolRowState(row);
    state.toolStatusRows = state.toolStatusRows.filter(function(item){ return toolRowIdentity(item) !== rowId; });
    refreshDirtyFlag();
    renderCurrent();
}

function updateReleaseRowField(visibleIndex, field, value, meta){
    const fields = ['holding_date_text','vendor_text','parts_name_text','tool_text','cavity_text','affect_lot_text','type_text','issue_description_text','status_text','release_date_text','note_text'];
    const rows = state.releaseDetails.slice().sort(function(a,b){ return ((Number(a.sort_order)||0) - (Number(b.sort_order)||0)); });
    const isNew = visibleIndex >= rows.length;
    const target = ensureCid(isNew ? releaseBlankRow() : rows[visibleIndex]);
    seedReleaseOriginals(target);
    const wasBlank = !anyFilled(target, fields);
    const previous = target[field] || '';
    target[field] = value;
    if (previous !== value) {
        setReleaseFieldDirtyState(target, field, value, meta && meta.cell ? meta.cell : null);
    }
    if (isNew) rows.push(target);
    rows.forEach(function(row, idx){ row.sort_order = idx + 1; });
    state.releaseDetails = rows;
    refreshDirtyFlag();
    const becameFilled = wasBlank && anyFilled(target, fields);
    if (becameFilled || isNew) {
        renderReleaseBody();
        focusReleaseField(visibleIndex, field, meta && typeof meta.caretPos === 'number' ? meta.caretPos : undefined);
    }
}

function insertReleaseRowBelow(visibleIndex){
    const rows = state.releaseDetails.slice().sort(function(a,b){ return ((Number(a.sort_order)||0) - (Number(b.sort_order)||0)); });
    const base = rows[visibleIndex] || releaseBlankRow();
    const next = ensureCid({
        id: 0,
        holding_date_text: base.holding_date_text || '',
        vendor_text: base.vendor_text || '',
        parts_name_text: base.parts_name_text || '',
        tool_text: base.tool_text || '',
        cavity_text: base.cavity_text || '',
        affect_lot_text: base.affect_lot_text || '',
        type_text: base.type_text || '',
        issue_description_text: '',
        status_text: '',
        release_date_text: '',
        note_text: '',
        sort_order: visibleIndex + 1.5
    });
    rows.splice(Math.min(visibleIndex + 1, rows.length), 0, next);
    rows.forEach(function(row, idx){ row.sort_order = idx + 1; });
    state.releaseDetails = rows;
    refreshDirtyFlag();
    renderReleaseBody();
}

async function deleteReleaseRow(row){
    if (!row) return;
    const rowId = releaseRowIdentity(row);
    if (Number(row.id) > 0) {
        setStatus('삭제 중...', false);
        await request('delete_release_detail', {id: Number(row.id)});
    }
    purgeReleaseRowState(row);
    state.releaseDetails = state.releaseDetails.filter(function(item){ return releaseRowIdentity(item) !== rowId; });
    refreshDirtyFlag();
    renderReleaseBody();
}

function buildToolRowspans(rows, modelName){
    const mergeFields = ['item_code','tool_text','affect_lot_text','issue_description_text','remark_text'];
    const spans = {};
    const hidden = {};
    const model = String(modelName || state.activeToolModel || '').toUpperCase();
    mergeFields.forEach(function(field){
        spans[field] = new Array(rows.length).fill(1);
        hidden[field] = new Set();
    });

    function applySpan(field, start, end){
        if (start < 0 || end <= start || end >= rows.length) return;
        spans[field][start] = end - start + 1;
        for (let idx = start + 1; idx <= end; idx++) hidden[field].add(idx);
    }

    function fieldState(field){
        return peekToolManualState(model, field) || {merge:[], split:[]};
    }

    function buildLinearField(field, valueGetter, allowAuto){
        const manual = fieldState(field);
        let idx = 0;
        while (idx < rows.length) {
            const forced = findRangeStarting(manual.merge, idx);
            if (forced) {
                applySpan(field, idx, Math.min(forced.end, rows.length - 1));
                idx = Math.min(forced.end, rows.length - 1) + 1;
                continue;
            }
            if (rangeContains(manual.split, idx)) { idx += 1; continue; }
            if (!allowAuto) { idx += 1; continue; }
            const value = normalizeText(valueGetter(rows[idx], idx));
            if (!value) { idx += 1; continue; }
            let end = idx + 1;
            while (end < rows.length) {
                if (findRangeStarting(manual.merge, end) || rangeContains(manual.split, end)) break;
                if (normalizeText(valueGetter(rows[end], end)) !== value) break;
                end += 1;
            }
            applySpan(field, idx, end - 1);
            idx = end;
        }
    }

    buildLinearField('item_code', function(row){ return row.item_code || model; }, true);
    buildLinearField('tool_text', function(row){ return row.tool_text || ''; }, true);
    buildLinearField('affect_lot_text', function(row){ return row.affect_lot_text || ''; }, false);

    let i = 0;
    while (i < rows.length) {
        const toolKey = normalizeText(rows[i].tool_text);
        if (!toolKey) { i += 1; continue; }
        let j = i + 1;
        while (j < rows.length && normalizeText(rows[j].tool_text) === toolKey) j += 1;

        i = j;
    }

    ['issue_description_text','remark_text'].forEach(function(field){
        const manual = fieldState(field);
        let start = 0;
        while (start < rows.length) {
            const forced = findRangeStarting(manual.merge, start);
            if (forced) {
                applySpan(field, start, Math.min(forced.end, rows.length - 1));
                start = Math.min(forced.end, rows.length - 1) + 1;
                continue;
            }
            if (rangeContains(manual.split, start)) { start += 1; continue; }
            const toolKey = normalizeText(rows[start].tool_text || '');
            const value = normalizeText(rows[start][field] || '');
            if (!toolKey || !value) { start += 1; continue; }
            let end = start + 1;
            while (end < rows.length) {
                if (findRangeStarting(manual.merge, end) || rangeContains(manual.split, end)) break;
                if (normalizeText(rows[end].tool_text || '') !== toolKey) break;
                if (normalizeText(rows[end][field] || '') !== value) break;
                end += 1;
            }
            applySpan(field, start, end - 1);
            start = end;
        }
    });

    return {spans, hidden};
}

function renderToolModelTabs(target){
    const host = target || els.toolModelTabs;
    if (!host) return;
    host.innerHTML = '';
    state.models.forEach(function(model){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'model-tab' + (state.activeToolModel === model ? ' active' : '');
        btn.textContent = model;
        btn.addEventListener('click', function(){
            state.activeToolModel = model;
            renderToolStatus();
        });
        host.appendChild(btn);
    });
}

function measureToolTable(table){
    if (!table) return;
    const fieldOrder = ['actions','item_code','tool_text','cavity_text','affect_lot_text','vendor_text','type_text','issue_description_text','remark_text'];
    const widths = {
        actions:74,
        item_code:70,
        tool_text:55,
        cavity_text:55,
        affect_lot_text:70,
        vendor_text:58,
        type_text:52,
        issue_description_text:110,
        remark_text:150
    };
    const ctx = document.createElement('canvas').getContext('2d');
    ctx.font = '600 12px Segoe UI';
    table.querySelectorAll('thead th[data-field]').forEach(function(th){
        const field = th.getAttribute('data-field');
        widths[field] = Math.max(widths[field] || 0, Math.ceil(ctx.measureText(th.textContent.trim()).width) + 28);
    });
    table.querySelectorAll('tbody td[data-field]').forEach(function(td){
        const field = td.getAttribute('data-field');
        if (!field || field === 'remark_text') return;
        const text = normalizeText(td.getAttribute('data-measure') || td.textContent || '');
        if (!text) return;
        const sample = text.split('\n').sort(function(a,b){ return b.length - a.length; })[0] || '';
        widths[field] = Math.max(widths[field] || 0, Math.ceil(ctx.measureText(sample).width) + (field === 'issue_description_text' ? 44 : 32));
    });
    const colMap = {
        actions:'.col-actions',
        item_code:'.col-item',
        tool_text:'.col-tool',
        cavity_text:'.col-cavity',
        affect_lot_text:'.col-affect',
        vendor_text:'.col-vendor',
        type_text:'.col-type',
        issue_description_text:'.col-issue',
        remark_text:'.col-remark'
    };
    table.querySelector(colMap.actions).style.width = widths.actions + 'px';
    table.querySelector(colMap.item_code).style.width = widths.item_code + 'px';
    table.querySelector(colMap.tool_text).style.width = widths.tool_text + 'px';
    table.querySelector(colMap.cavity_text).style.width = widths.cavity_text + 'px';
    table.querySelector(colMap.affect_lot_text).style.width = widths.affect_lot_text + 'px';
    table.querySelector(colMap.vendor_text).style.width = widths.vendor_text + 'px';
    table.querySelector(colMap.type_text).style.width = widths.type_text + 'px';
    table.querySelector(colMap.issue_description_text).style.width = Math.min(Math.max(widths.issue_description_text, 120), 240) + 'px';
    table.querySelector(colMap.remark_text).style.width = 'auto';
}

function renderToolStatus(){
    els.toolStatusRoot.innerHTML = '';
    const model = state.activeToolModel || state.models[0] || MODELS[0];
    const head = els.toolTab.querySelector('.sheet-head');
    if (head) {
        head.innerHTML = '';
        const inlineTabs = document.createElement('div');
        inlineTabs.className = 'sheet-title tool-tab-inline';
        renderToolModelTabs(inlineTabs);
        head.appendChild(inlineTabs);
        head.appendChild(els.toolAddRowBtn);
    }
    if (els.toolModelTabs) els.toolModelTabs.innerHTML = '';
    const rows = buildToolRowsForRender(model);
    const merge = buildToolRowspans(rows, model);
    const section = document.createElement('div');
    section.className = 'model-section';

    const wrap = document.createElement('div');
    wrap.className = 'grid-wrap';
    const table = document.createElement('table');
    table.className = 'sheet toolsheet';
    table.innerHTML = `
        <colgroup>
            <col class="col-actions">
            <col class="col-item">
            <col class="col-tool">
            <col class="col-cavity">
            <col class="col-affect">
            <col class="col-vendor">
            <col class="col-type">
            <col class="col-issue">
            <col class="col-remark">
        </colgroup>
        <thead>
            <tr>
                <th data-field="actions"></th>
                <th data-field="item_code">Item</th>
                <th data-field="tool_text">Tool</th>
                <th data-field="cavity_text">Cavity</th>
                <th data-field="affect_lot_text">Affect Lot</th>
                <th data-field="vendor_text">Vendor</th>
                <th data-field="type_text">Type</th>
                <th data-field="issue_description_text">Issue Description</th>
                <th data-field="remark_text">Remark</th>
            </tr>
        </thead>
        <tbody></tbody>
    `;
    const tbody = table.querySelector('tbody');

    rows.forEach(function(row, visibleIndex){
        const tr = document.createElement('tr');
        tr.setAttribute('data-rowid', toolRowIdentity(row));
        tr.setAttribute('data-row-index', visibleIndex);
        const isBlank = visibleIndex === rows.length - 1 && !anyFilled(row, ['tool_text','cavity_text','affect_lot_text','vendor_text','type_text','issue_description_text','remark_text']);
        if (isBlank) tr.classList.add('blank-row');

        const actionTd = document.createElement('td');
        actionTd.className = 'actions';
        actionTd.setAttribute('data-field','actions');
        const btnWrap = document.createElement('div');
        btnWrap.className = 'action-buttons';
        const addBtn = document.createElement('button');
        addBtn.type = 'button'; addBtn.className = 'row-btn add'; addBtn.textContent = '+';
        addBtn.disabled = !state.canEdit;
        addBtn.addEventListener('click', function(){ insertToolRowBelow(model, visibleIndex); });
        const delBtn = document.createElement('button');
        delBtn.type = 'button'; delBtn.className = 'row-btn delete'; delBtn.textContent = '×';
        delBtn.disabled = !state.canEdit;
        delBtn.addEventListener('click', function(){ deleteToolRow(row).catch(function(err){ setStatus(err.message || '행 삭제 실패', true); }); });
        btnWrap.appendChild(addBtn);
        btnWrap.appendChild(delBtn);
        actionTd.appendChild(btnWrap);
        tr.appendChild(actionTd);

        toolColumns.forEach(function(col){
            const fieldHidden = merge.hidden[col.key];
            if (fieldHidden && fieldHidden.has(visibleIndex)) return;

            const td = document.createElement('td');
            td.setAttribute('data-field', col.key);
            td.setAttribute('data-rowid', toolRowIdentity(row));
            td.setAttribute('data-row-index', visibleIndex);
            const currentValue = col.key === 'item_code' ? (row.item_code || model) : (row[col.key] || '');
            const mergeSpan = merge.spans[col.key] ? merge.spans[col.key][visibleIndex] : 1;
            td.setAttribute('data-row-span', mergeSpan);
            td.setAttribute('data-measure', currentValue);
            if (isToolDirty(row, col.key)) td.classList.add('dirty-cell');
            if (mergeSpan > 1) {
                td.rowSpan = mergeSpan;
                td.classList.add('merged-master');
            }
            let inputEl;
            if (col.key === 'item_code') {
                inputEl = createInput(model, {readonly:true});
                inputEl.value = model;
                td.appendChild(inputEl);
            } else if (col.checkbox === 'vendor') {
                const dd = createCheckboxDropdown(currentValue, 'vendor', state.canEdit, function(text){
                    updateToolRowField(model, visibleIndex, col.key, text, {cell: td, span: mergeSpan});
                });
                td.appendChild(dd.wrap);
            } else if (col.checkbox === 'cavity') {
                const dd = createCheckboxDropdown(currentValue, 'cavity', state.canEdit, function(text){
                    updateToolRowField(model, visibleIndex, col.key, text, {cell: td, span: mergeSpan});
                });
                td.appendChild(dd.wrap);
            } else if (col.checkbox === 'toolType') {
                const dd = createCheckboxDropdown(currentValue, 'toolType', state.canEdit, function(text){
                    updateToolRowField(model, visibleIndex, col.key, text, {cell: td, span: mergeSpan});
                });
                td.appendChild(dd.wrap);
            } else if (col.multiline) {
                inputEl = createTextarea(currentValue, {readonly:!state.canEdit});
                inputEl.addEventListener('input', function(){
                    updateToolRowField(model, visibleIndex, col.key, inputEl.value, {cell: td, span: mergeSpan});
                });
                attachEscCancel(inputEl, function(){ return inputEl.value; }, function(next){ inputEl.value = next; autoResizeTextarea(inputEl); }, function(original){
                    updateToolRowField(model, visibleIndex, col.key, original, {cell: td, span: mergeSpan});
                });
                td.appendChild(inputEl);
            } else {
                inputEl = createInput(currentValue, {readonly:!state.canEdit});
                inputEl.addEventListener('input', function(){
                    updateToolRowField(model, visibleIndex, col.key, inputEl.value, {cell: td, span: mergeSpan});
                });
                attachEscCancel(inputEl, function(){ return inputEl.value; }, function(next){ inputEl.value = next; }, function(original){
                    updateToolRowField(model, visibleIndex, col.key, original, {cell: td, span: mergeSpan});
                });
                td.appendChild(inputEl);
            }
            tr.appendChild(td);
        });

        tbody.appendChild(tr);
    });

    wrap.appendChild(table);
    section.appendChild(wrap);
    els.toolStatusRoot.appendChild(section);
    applyToolSelectionDom();
    requestAnimationFrame(function(){ measureToolTable(table); applyToolSelectionDom(); });
}

document.addEventListener('mousedown', function(ev){
    if (ev.button !== 0) return;
    if (ev.target.closest && ev.target.closest('.tool-merge-menu')) return;
    hideToolMergeMenu();
    const meta = getToolCellMeta(ev.target);
    if (!meta || !state.canEdit) return;
    state.toolSelectionDrag = {
        model: meta.model,
        field: meta.field,
        anchorRow: meta.rowIndex,
        startX: ev.clientX,
        startY: ev.clientY,
        active: false
    };
});

document.addEventListener('mousemove', function(ev){
    const drag = state.toolSelectionDrag;
    if (!drag) return;
    const moved = Math.abs(ev.clientX - drag.startX) > 3 || Math.abs(ev.clientY - drag.startY) > 3;
    if (!drag.active && moved) {
        drag.active = true;
        document.body.classList.add('cell-selecting');
        setToolSelection(drag.model, drag.field, drag.anchorRow, drag.anchorRow);
    }
    if (!drag.active) return;
    const meta = getToolCellMeta(ev.target);
    if (!meta || meta.model !== drag.model || meta.field !== drag.field) return;
    setToolSelection(drag.model, drag.field, drag.anchorRow, meta.rowIndex);
});

document.addEventListener('mouseup', function(ev){
    const drag = state.toolSelectionDrag;
    if (!drag) return;
    state.toolSelectionDrag = null;
    document.body.classList.remove('cell-selecting');
    if (drag.active) {
        state.toolSuppressClickSelection = {x:ev.clientX, y:ev.clientY, at:Date.now()};
        ev.preventDefault();
        return;
    }
    const meta = getToolCellMeta(ev.target);
    if (meta && meta.model === drag.model && meta.field === drag.field) {
        setToolSelection(meta.model, meta.field, meta.rowIndex, meta.rowIndex);
    }
});

document.addEventListener('contextmenu', function(ev){
    const meta = getToolCellMeta(ev.target);
    if (!meta || !state.canEdit) {
        hideToolMergeMenu();
        return;
    }
    ev.preventDefault();
    const sel = getToolSelection();
    if (!sel || sel.model !== meta.model || sel.field !== meta.field || meta.rowIndex < sel.startRow || meta.rowIndex > sel.endRow) {
        setToolSelection(meta.model, meta.field, meta.rowIndex, meta.rowIndex);
    }
    showToolMergeMenu(ev.clientX, ev.clientY);
});

document.addEventListener('click', function(ev){
    if (ev.target.closest && ev.target.closest('.tool-merge-menu')) return;
    if (state.toolSuppressClickSelection) {
        const suppress = state.toolSuppressClickSelection;
        state.toolSuppressClickSelection = false;
        const sameClick = (suppress === true) || (suppress && Math.abs((Number(suppress.x) || 0) - ev.clientX) <= 4 && Math.abs((Number(suppress.y) || 0) - ev.clientY) <= 4 && (Date.now() - (Number(suppress.at) || 0)) < 600);
        if (sameClick) {
            hideToolMergeMenu();
            return;
        }
    }
    const meta = getToolCellMeta(ev.target);
    if (meta && state.canEdit) {
        const sel = getToolSelection();
        if (!sel || sel.model !== meta.model || sel.field !== meta.field || sel.startRow !== meta.rowIndex || sel.endRow !== meta.rowIndex) {
            setToolSelection(meta.model, meta.field, meta.rowIndex, meta.rowIndex);
        }
        hideToolMergeMenu();
        return;
    }
    hideToolMergeMenu();
    if (!(ev.target.closest && ev.target.closest('.toolsheet'))) {
        clearToolSelection();
    }
});

document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape') hideToolMergeMenu();
});

function renderReleaseBody(){
    els.releaseBody.innerHTML = '';
    const rows = buildReleaseRowsForRender();
    rows.forEach(function(row, visibleIndex){
        const tr = document.createElement('tr');
        tr.setAttribute('data-rowid', toolRowIdentity(row));
        const isBlank = visibleIndex === rows.length - 1 && !anyFilled(row, ['holding_date_text','vendor_text','parts_name_text','tool_text','cavity_text','affect_lot_text','type_text','issue_description_text','status_text','release_date_text','note_text']);
        if (isBlank) tr.classList.add('blank-row');

        const actionTd = document.createElement('td');
        actionTd.className = 'actions';
        const btnWrap = document.createElement('div');
        btnWrap.className = 'action-buttons';
        const addBtn = document.createElement('button');
        addBtn.type = 'button'; addBtn.className = 'row-btn add'; addBtn.textContent = '+';
        addBtn.disabled = !state.canEdit;
        addBtn.addEventListener('click', function(){ insertReleaseRowBelow(visibleIndex); });
        const delBtn = document.createElement('button');
        delBtn.type = 'button'; delBtn.className = 'row-btn delete'; delBtn.textContent = '×';
        delBtn.disabled = !state.canEdit;
        delBtn.addEventListener('click', function(){ deleteReleaseRow(row).catch(function(err){ setStatus(err.message || '행 삭제 실패', true); }); });
        btnWrap.appendChild(addBtn);
        btnWrap.appendChild(delBtn);
        actionTd.appendChild(btnWrap);
        tr.appendChild(actionTd);

        releaseColumns.forEach(function(col){
            const td = document.createElement('td');
            td.setAttribute('data-field', col.key);
            td.setAttribute('data-rowid', releaseRowIdentity(row));
            const currentValue = row[col.key] || '';
            if (isReleaseDirty(row, col.key)) td.classList.add('dirty-cell');
            if (col.checkbox === 'vendor') {
                const dd = createCheckboxDropdown(currentValue, 'vendor', state.canEdit, function(text){
                    updateReleaseRowField(visibleIndex, col.key, text, {cell: td});
                });
                td.appendChild(dd.wrap);
            } else if (col.checkbox === 'cavity') {
                const dd = createCheckboxDropdown(currentValue, 'cavity', state.canEdit, function(text){
                    updateReleaseRowField(visibleIndex, col.key, text, {cell: td});
                });
                td.appendChild(dd.wrap);
            } else if (col.checkbox === 'releaseType') {
                const dd = createCheckboxDropdown(currentValue, 'releaseType', state.canEdit, function(text){
                    updateReleaseRowField(visibleIndex, col.key, text, {cell: td});
                });
                td.appendChild(dd.wrap);
            } else if (col.status) {
                const status = createStatusSelect(currentValue, state.canEdit);
                status.input.addEventListener('change', function(){
                    updateReleaseRowField(visibleIndex, col.key, status.input.value, {cell: td});
                });
                attachEscCancel(status.input, function(){ return status.input.value; }, function(next){ status.input.value = next; }, function(original){
                    updateReleaseRowField(visibleIndex, col.key, original, {cell: td});
                });
                td.appendChild(status.wrap);
            } else if (col.multiline) {
                const ta = createTextarea(currentValue, {readonly:!state.canEdit});
                ta.addEventListener('input', function(){
                    updateReleaseRowField(visibleIndex, col.key, ta.value, {cell: td});
                });
                attachEscCancel(ta, function(){ return ta.value; }, function(next){ ta.value = next; autoResizeTextarea(ta); }, function(original){
                    updateReleaseRowField(visibleIndex, col.key, original, {cell: td});
                });
                td.appendChild(ta);
            } else {
                const input = createInput(currentValue, {readonly:!state.canEdit});
                input.addEventListener('input', function(){
                    updateReleaseRowField(visibleIndex, col.key, input.value, {cell: td});
                });
                attachEscCancel(input, function(){ return input.value; }, function(next){ input.value = next; }, function(original){
                    updateReleaseRowField(visibleIndex, col.key, original, {cell: td});
                });
                td.appendChild(input);
            }
            tr.appendChild(td);
        });
        els.releaseBody.appendChild(tr);
    });
}

function renderCurrent(){
    renderToolStatus();
    renderReleaseBody();
    els.userLvBadge.textContent = 'LV ' + state.userLv;
    els.readonlyMessage.classList.toggle('hidden', state.canEdit);
    els.saveBtn.disabled = !state.canEdit;
}

async function request(action, payload){
    const res = await fetch(endpoint + '&action=' + encodeURIComponent(action), {
        method: payload ? 'POST' : 'GET',
        headers: payload ? {'Content-Type':'application/json'} : {},
        body: payload ? JSON.stringify(payload) : undefined,
        credentials: 'same-origin'
    });
    const data = await res.json().catch(function(){ return {ok:false, message:'응답을 읽지 못했습니다.'}; });
    if (!res.ok || !data.ok) throw new Error(data && data.message ? data.message : '요청에 실패했습니다.');
    return data;
}

function applyPayload(data){
    state.models = Array.isArray(data.models) && data.models.length ? data.models : MODELS.slice();
    state.userLv = Number(data.user_lv || 0);
    state.canEdit = !!data.can_edit;
    state.toolStatusRows = Array.isArray(data.tool_status) ? data.tool_status.map(function(row){
        const next = ensureCid(Object.assign({}, row, { part_name: String(row.part_name || '').toUpperCase(), item_code: String(row.item_code || row.part_name || '').toUpperCase() }));
        return next;
    }) : [];
    state.releaseDetails = Array.isArray(data.release_details) ? data.release_details.map(function(row){ return ensureCid(Object.assign({}, row)); }) : [];
    state.dirtyToolCells.clear();
    state.dirtyToolStructureCells.clear();
    state.dirtyReleaseCells.clear();
    state.originalToolValues.clear();
    state.originalReleaseValues.clear();
    state.toolStatusRows.forEach(seedToolOriginals);
    state.releaseDetails.forEach(seedReleaseOriginals);
    if (!state.activeToolModel || !state.models.includes(state.activeToolModel)) state.activeToolModel = state.models[0] || MODELS[0];
    setDirty(false);
    renderCurrent();
}

async function loadAll(){
    setStatus('불러오는 중...', false);
    const data = await request('load');
    applyPayload(data);
}

async function saveAll(){
    if (!state.canEdit) return;
    setStatus('저장 중...', false);
    const grouped = buildToolGroups(state.toolStatusRows);
    const toolRows = [];
    state.models.forEach(function(model){
        (grouped[model] || []).forEach(function(row, idx){
            if (!anyFilled(row, ['tool_text','cavity_text','affect_lot_text','vendor_text','type_text','issue_description_text','remark_text'])) return;
            toolRows.push({
                id: Number(row.id) || 0,
                part_name: model,
                item_code: model,
                tool_text: normalizeText(row.tool_text || ''),
                cavity_text: normalizeText(row.cavity_text || ''),
                affect_lot_text: normalizeText(row.affect_lot_text || ''),
                vendor_text: normalizeText(row.vendor_text || ''),
                type_text: normalizeText(row.type_text || ''),
                issue_description_text: normalizeText(row.issue_description_text || ''),
                remark_text: normalizeText(row.remark_text || ''),
                sort_order: idx + 1
            });
        });
    });

    const releaseRows = buildReleaseRowsForRender().filter(function(row){
        return anyFilled(row, ['holding_date_text','vendor_text','parts_name_text','tool_text','cavity_text','affect_lot_text','type_text','issue_description_text','status_text','release_date_text','note_text']);
    }).map(function(row, idx){
        return {
            id: Number(row.id) || 0,
            holding_date_text: normalizeText(row.holding_date_text || ''),
            vendor_text: normalizeText(row.vendor_text || ''),
            parts_name_text: normalizeText(row.parts_name_text || ''),
            tool_text: normalizeText(row.tool_text || ''),
            cavity_text: normalizeText(row.cavity_text || ''),
            affect_lot_text: normalizeText(row.affect_lot_text || ''),
            type_text: normalizeText(row.type_text || ''),
            issue_description_text: normalizeText(row.issue_description_text || ''),
            status_text: normalizeText(row.status_text || ''),
            release_date_text: normalizeText(row.release_date_text || ''),
            note_text: normalizeText(row.note_text || ''),
            sort_order: idx + 1
        };
    });

    await request('save_tool_status', {rows: toolRows});
    const data = await request('save_release_details', {rows: releaseRows});
    applyPayload(data);
}

els.topTabs.forEach(function(btn){
    btn.addEventListener('click', function(){
        const tab = btn.getAttribute('data-tab');
        els.topTabs.forEach(function(x){ x.classList.toggle('active', x === btn); });
        els.toolTab.classList.toggle('hidden', tab !== 'tool');
        els.releaseTab.classList.toggle('hidden', tab !== 'release');
    });
});

els.reloadBtn.addEventListener('click', function(){ loadAll().catch(function(err){ setStatus(err.message || '불러오기 실패', true); }); });
els.saveBtn.addEventListener('click', function(){ saveAll().catch(function(err){ setStatus(err.message || '저장 실패', true); }); });
els.toolAddRowBtn.addEventListener('click', function(){ insertToolRowBelow(state.activeToolModel || state.models[0], buildToolRowsForRender(state.activeToolModel || state.models[0]).length - 2); });
els.releaseAddRowBtn.addEventListener('click', function(){ insertReleaseRowBelow(buildReleaseRowsForRender().length - 2); });

loadAll().catch(function(err){
    setStatus(err.message || '불러오기 실패', true);
});
})();
</script>
</body>
</html>
