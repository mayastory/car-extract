<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }


// shipinglist_main.php : 로그인 후 메인 화면 (XLS/XLSX 업로드 + 관리자)

session_start();
require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';

// ★ 대용량 엑셀 업로드용 (로컬 XAMPP에서만 사용 권장)
ini_set('memory_limit', '1024M');   // 메모리 1GB까지
set_time_limit(1200);               // 최대 실행시간 1200초(20분)


// ── 엑셀 라이브러리 준비 여부 체크 ─────────────────────
$excelLibReady = false;
$autoloadPath  = JTMES_ROOT . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    // \PhpOffice\PhpSpreadsheet\IOFactory 를 쓸 수 있는 상태
    $excelLibReady = true;
}

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// 로그인 체크 + 단일 세션(동시접속 차단)
if (function_exists('dp_auth_guard')) {
    dp_auth_guard();
} elseif (empty($_SESSION['ship_user_id'])) {
    header('Location: index.php');
    exit;
}

$userId   = $_SESSION['ship_user_id'];
$userRole = $_SESSION['ship_user_role'] ?? 'user';
$isAdmin  = ($userRole === 'admin');

$resultMessage   = '';
$adminMessage    = '';
$openAdminModal  = false;

$pendingUsers = [];
$allAccounts  = [];

$mode = $_POST['mode'] ?? '';

// 로그아웃
if ($mode === 'logout') {
    // DB의 session_token도 비워서 다음 요청에서 즉시 무효화되게
    try {
        $pdo = dp_get_pdo();
        if (!empty($_SESSION['ship_user_no'])) {
            $st = $pdo->prepare('UPDATE `account` SET session_token=NULL, session_token_updated_at=NOW() WHERE No=:no');
            $st->execute([':no' => (int)$_SESSION['ship_user_no']]);
        } elseif (!empty($_SESSION['ship_user_id'])) {
            $st = $pdo->prepare('UPDATE `account` SET session_token=NULL, session_token_updated_at=NOW() WHERE ID=:id');
            $st->execute([':id' => (string)$_SESSION['ship_user_id']]);
        }
    } catch (Throwable $e) {
        // ignore
    }
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// ─────────────────────────────
// XLS/XLSX 업로드 (관리자만)
// ─────────────────────────────
if ($mode === 'upload_excel') {
    if (!$isAdmin) {
        $resultMessage = "엑셀 업로드 권한이 없습니다. (관리자 전용 기능)";
    } else {
        if (!$excelLibReady) {
            $resultMessage =
                "엑셀(XLS/XLSX) 업로드 라이브러리가 설치되어 있지 않습니다.\n".
                "다음 명령을 한 번만 실행해 주세요:\n\n".
                "cd C:\\xampp\\htdocs\\DPTest\n".
                "composer require phpoffice/phpspreadsheet";
        } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $resultMessage = "엑셀 파일 업로드에 실패했습니다.";
        } else {
            $tmpPath  = $_FILES['excel_file']['tmp_name'];
            $origName = $_FILES['excel_file']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['xls','xlsx'], true)) {
                $resultMessage = "XLS 또는 XLSX 형식의 파일만 업로드할 수 있습니다.";
            } else {
                try {
                    $pdo = dp_get_pdo();
                } catch (PDOException $e) {
                    $resultMessage = "DB 접속 실패: " . $e->getMessage();
                }

                if ($resultMessage === '') {
                    try {
                        // 엑셀 읽기
                        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
                        $sheet       = $spreadsheet->getActiveSheet();
                        $highestRow    = $sheet->getHighestRow();
                        $highestColumn = $sheet->getHighestColumn();

                        // 1행 헤더 읽어서 "컬럼문자(A,B,…)" => "헤더텍스트" 매핑
                        $header = [];
                        foreach (range('A', $highestColumn) as $col) {
                            $cellVal = $sheet->getCell($col.'1')->getValue();
                            $header[$col] = trim((string)$cellVal);
                        }

                        // 엑셀 헤더 텍스트 ↔ DB 필드명 매핑
                        $needMap = [
                            'warehouse'        => '창고',
                            'pack_date'        => '포장일자',
                            'prod_date'        => '생산일자',
                            'facility'         => '설비',
                            'cavity'           => 'CAVITY',
                            'part_code'        => '품번코드',
                            'revision'         => '차수',
                            'customer_part_no' => '고객사 품번',
                            'part_name'        => '품번명',
                            'model'            => '모델',
                            'project'          => '프로젝트',
                            'small_pack_no'    => '소포장 NO',
                            'tray_no'          => 'Tray NO',
                            'ship_to'          => '납품처',
                            'qty'              => '출고수량',
                            'ship_datetime'    => '출하일자',
                            'customer_lot_id'  => '고객 LOTID',
                            'pack_barcode'     => '포장바코드',
                            'pack_no'          => '포장번호',
                        ];

                        // 각 필드가 어느 컬럼에 있는지 찾기 (예: warehouse => 'A')
                        $colIndex = [];
                        foreach ($needMap as $key => $korName) {
                            $foundCol = null;
                            foreach ($header as $col => $text) {
                                if ($text === $korName) {
                                    $foundCol = $col;
                                    break;
                                }
                            }
                            if ($foundCol === null) {
                                $resultMessage = "엑셀 헤더에서 '{$korName}' 컬럼을 찾을 수 없습니다.";
                                break;
                            }
                            $colIndex[$key] = $foundCol;
                        }

                        if ($resultMessage === '') {
                            $sql = "
                                INSERT INTO ShipingList (
                                    warehouse, pack_date, prod_date, facility, cavity,
                                    part_code, revision, customer_part_no, part_name, model,
                                    project, small_pack_no, tray_no, ship_to, qty,
                                    ship_datetime, customer_lot_id, pack_barcode, pack_no
                                ) VALUES (
                                    :warehouse, :pack_date, :prod_date, :facility, :cavity,
                                    :part_code, :revision, :customer_part_no, :part_name, :model,
                                    :project, :small_pack_no, :tray_no, :ship_to, :qty,
                                    :ship_datetime, :customer_lot_id, :pack_barcode, :pack_no
                                )
                                ON DUPLICATE KEY UPDATE
                                    warehouse        = VALUES(warehouse),
                                    pack_date        = VALUES(pack_date),
                                    prod_date        = VALUES(prod_date),
                                    facility         = VALUES(facility),
                                    cavity           = VALUES(cavity),
                                    part_code        = VALUES(part_code),
                                    revision         = VALUES(revision),
                                    customer_part_no = VALUES(customer_part_no),
                                    part_name        = VALUES(part_name),
                                    model            = VALUES(model),
                                    project          = VALUES(project),
                                    small_pack_no    = VALUES(small_pack_no),
                                    tray_no          = VALUES(tray_no),
                                    ship_to          = VALUES(ship_to),
                                    qty              = VALUES(qty),
                                    ship_datetime    = VALUES(ship_datetime),
                                    customer_lot_id  = VALUES(customer_lot_id),
                                    pack_barcode     = VALUES(pack_barcode),
                                    pack_no          = VALUES(pack_no)
                            ";
                            $stmt = $pdo->prepare($sql);

                            $processed = 0;
                            $skipped   = 0;

                            // 행 전체가 비었는지 확인할 때 사용할 필드들
                            $checkCols = array_values($colIndex);

                            $pdo->beginTransaction();

                            // 2행부터 실제 데이터
                            for ($row = 2; $row <= $highestRow; $row++) {

                                // 필요한 컬럼 기준으로 "완전 빈 행"인지 체크
                                $rowAllEmpty = true;
                                foreach ($checkCols as $col) {
                                    $val = $sheet->getCell($col.$row)->getFormattedValue();
                                    if (trim((string)$val) !== '') {
                                        $rowAllEmpty = false;
                                        break;
                                    }
                                }
                                if ($rowAllEmpty) {
                                    $skipped++;
                                    continue;
                                }

                                // 헬퍼: 특정 필드(raw 값, 포맷 적용)
                                $get = function(string $key) use ($sheet, $colIndex, $row) {
                                    $col = $colIndex[$key] ?? null;
                                    if ($col === null) return '';
                                    $cell = $sheet->getCell($col.$row);
                                    // getFormattedValue() 로 날짜/숫자 등 포맷 적용된 문자열 가져오기
                                    return $cell->getFormattedValue();
                                };

                                $warehouse       = trim((string)$get('warehouse'));
                                $packDateRaw     = trim((string)$get('pack_date'));
                                $prodDateRaw     = trim((string)$get('prod_date'));
                                $facility        = trim((string)$get('facility'));
                                $cavityRaw       = trim((string)$get('cavity'));
                                $partCode        = trim((string)$get('part_code'));
                                $revision        = trim((string)$get('revision'));
                                $customerPartNo  = trim((string)$get('customer_part_no'));
                                $partName        = trim((string)$get('part_name'));
                                $model           = trim((string)$get('model'));
                                $project         = trim((string)$get('project'));
                                $smallPackNo     = trim((string)$get('small_pack_no'));
                                $trayNo          = trim((string)$get('tray_no'));
                                $shipTo          = trim((string)$get('ship_to'));
                                $qtyRaw          = trim((string)$get('qty'));
                                $shipDtRaw       = trim((string)$get('ship_datetime'));
                                $customerLotId   = trim((string)$get('customer_lot_id'));
                                $packBarcode     = trim((string)$get('pack_barcode'));
                                $packNo          = trim((string)$get('pack_no'));

                                // 포장바코드 없으면 스킵 (기존 CSV 로직과 동일)
                                if ($packBarcode === '') {
                                    $skipped++;
                                    continue;
                                }

                                // 날짜 변환 (엑셀에서 '2025-11-24' 같은 문자열로 들어온다고 가정)
                                $packDate = $packDateRaw !== '' ? date('Y-m-d', strtotime($packDateRaw)) : null;
                                $prodDate = $prodDateRaw !== '' ? date('Y-m-d', strtotime($prodDateRaw)) : null;
                                $shipDt   = $shipDtRaw   !== '' ? date('Y-m-d H:i:s', strtotime($shipDtRaw)) : null;

                                $cavity = ($cavityRaw !== '') ? (int)$cavityRaw : null;
                                $qty    = ($qtyRaw    !== '') ? (int)$qtyRaw    : null;

                                $stmt->execute([
                                    ':warehouse'        => $warehouse,
                                    ':pack_date'        => $packDate,
                                    ':prod_date'        => $prodDate,
                                    ':facility'         => $facility,
                                    ':cavity'           => $cavity,
                                    ':part_code'        => $partCode,
                                    ':revision'         => $revision,
                                    ':customer_part_no' => $customerPartNo,
                                    ':part_name'        => $partName,
                                    ':model'            => $model,
                                    ':project'          => $project,
                                    ':small_pack_no'    => $smallPackNo,
                                    ':tray_no'          => $trayNo,
                                    ':ship_to'          => $shipTo,
                                    ':qty'              => $qty,
                                    ':ship_datetime'    => $shipDt,
                                    ':customer_lot_id'  => $customerLotId,
                                    ':pack_barcode'     => $packBarcode,
                                    ':pack_no'          => $packNo,
                                ]);

                                $processed++;
                            }

                            $pdo->commit();

                            $resultMessage =
                                "엑셀 처리 완료\n".
                                "총 처리 행: {$processed}행\n".
                                "스킵된 행: {$skipped}행";
                        }
                    } catch (\Throwable $e) {
                        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $resultMessage = "엑셀 처리 중 오류: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// ─────────────────────────────
// 가입 승인/거절
// ─────────────────────────────
if ($mode === 'approve_user' || $mode === 'reject_user') {
    if ($isAdmin) {
        $targetNo  = (int)($_POST['target_no'] ?? 0);
        $newStatus = ($mode === 'approve_user') ? 'approved' : 'rejected';

        try {
            $pdo = dp_get_pdo();
            $stmt = $pdo->prepare("
                UPDATE `account`
                   SET status = :status
                 WHERE No = :no
                   AND role = 'user'
            ");
            $stmt->execute([
                ':status' => $newStatus,
                ':no'     => $targetNo,
            ]);

            if ($stmt->rowCount() > 0) {
                $adminMessage = ($newStatus === 'approved')
                    ? "선택한 계정을 승인했습니다."
                    : "선택한 계정을 거절 처리했습니다.";
            } else {
                $adminMessage = "대상 계정을 찾을 수 없거나 이미 처리된 계정입니다.";
            }
        } catch (PDOException $e) {
            $adminMessage = "승인/거절 처리 중 오류: " . $e->getMessage();
        }
    } else {
        $adminMessage = "관리자 권한이 없습니다.";
    }
    $openAdminModal = true;
}

// ─────────────────────────────
// 비밀번호 변경
// ─────────────────────────────
if ($mode === 'change_password') {
    if ($isAdmin) {
        $targetNo = (int)($_POST['pw_target_no'] ?? 0);
        $pw1      = trim($_POST['pw_new']  ?? '');
        $pw2      = trim($_POST['pw_new2'] ?? '');

        if ($targetNo <= 0 || $pw1 === '' || $pw2 === '') {
            $adminMessage = "대상 계정과 새 비밀번호를 모두 입력하세요.";
        } elseif ($pw1 !== $pw2) {
            $adminMessage = "새 비밀번호가 서로 일치하지 않습니다.";
        } else {
            try {
                $pdo = dp_get_pdo();
                $stmt = $pdo->prepare("
                    UPDATE `account`
                       SET PW = :pw
                     WHERE No = :no
                ");
                $stmt->execute([
                    ':pw' => password_hash($pw1, PASSWORD_DEFAULT),
                    ':no' => $targetNo,
                ]);

                if ($stmt->rowCount() > 0) {
                    $adminMessage = "비밀번호를 변경했습니다.";
                } else {
                    $adminMessage = "대상 계정을 찾을 수 없습니다.";
                }
            } catch (PDOException $e) {
                $adminMessage = "비밀번호 변경 중 오류: " . $e->getMessage();
            }
        }
    } else {
        $adminMessage = "관리자 권한이 없습니다.";
    }
    $openAdminModal = true;
}

// ─────────────────────────────
// 관리자용 계정 목록 로딩
// ─────────────────────────────
if ($isAdmin) {
    try {
        $pdo = isset($pdo) ? $pdo : dp_get_pdo();
        $stmt = $pdo->query("
            SELECT No, ID, role, status, created_at, last_login_at
              FROM `account`
             ORDER BY No ASC
        ");
        $allAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allAccounts as $acc) {
            if ($acc['role'] === 'user' && $acc['status'] === 'pending') {
                $pendingUsers[] = $acc;
            }
        }
    } catch (PDOException $e) {
        $adminMessage .= "\n[계정 목록 로딩 실패] " . $e->getMessage();
        $openAdminModal = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>ShipingList 업로드</title>
    <style>
        body {
            margin:0;
            padding:0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:#202124;
            color:#f1f3f4;
        }
        .page-wrap {
            max-width:1100px;
            margin:20px auto;
            padding:0 12px 30px;
            box-sizing:border-box;
        }
        .top-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:16px;
        }
        .top-bar-title {
            font-size:18px;
            font-weight:600;
        }
        .top-bar-user {
            font-size:13px;
            color:#9aa0a6;
            margin-right:10px;
        }
        .badge-role {
            display:inline-block;
            padding:2px 8px;
            border-radius:999px;
            font-size:11px;
            margin-left:6px;
        }
        .badge-admin {
            background:#ffb300;
            color:#000;
            cursor:pointer;
        }
        .badge-user {
            background:#3c4043;
            color:#e8eaed;
        }
        .btn-logout {
            padding:6px 16px;
            border-radius:999px;
            border:1px solid #555;
            background:#303134;
            color:#f1f3f4;
            font-size:13px;
            cursor:pointer;
        }
        .btn-logout:hover { background:#3c4043; }
        .card {
            background:#2b2b2b;
            border-radius:18px;
            padding:18px 20px;
            box-shadow:0 10px 26px rgba(0,0,0,0.5);
            margin-bottom:16px;
        }
        .card h3 {
            margin:0 0 10px;
            font-size:16px;
        }
        .file-row {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
            margin-top:8px;
        }
        .file-input {
            padding:6px 10px;
            border-radius:999px;
            background:#1f1f1f;
            border:1px dashed #555;
            font-size:13px;
            color:#e8eaed;
        }
        .btn-primary {
            padding:8px 18px;
            border-radius:999px;
            border:none;
            background:#4f8cff;
            color:#fff;
            font-size:13px;
            font-weight:600;
            cursor:pointer;
        }
        .btn-primary:hover { background:#6ea0ff; }
        .text-muted {
            font-size:12px;
            color:#9aa0a6;
            margin-top:6px;
            white-space:pre-line;
        }
        .result-box {
            font-size:13px;
            white-space:pre-line;
        }

        /* 관리자 팝업 */
        .admin-backdrop {
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.55);
            display:none;
            z-index:950;
        }
        .admin-backdrop.show { display:block; }

        .admin-modal {
            position:fixed;
            left:50%;
            top:50%;
            transform:translate(-50%, -50%);
            background:#2b2b2b;
            border-radius:18px;
            box-shadow:0 20px 40px rgba(0,0,0,0.7);
            width:640px;
            max-width:95%;
            max-height:90vh;
            display:none;
            flex-direction:column;
            z-index:951;
        }
        .admin-modal.show { display:flex; }
        .admin-header {
            padding:12px 18px;
            border-bottom:1px solid #3c4043;
            display:flex;
            justify-content:space-between;
            align-items:center;
            cursor:move;
        }
        .admin-header-title {
            font-size:15px;
            font-weight:600;
        }
        .admin-close {
            border:none;
            background:transparent;
            color:#f1f3f4;
            font-size:18px;
            cursor:pointer;
        }
        .admin-body {
            padding:12px 18px 16px;
            overflow:auto;
        }
        .admin-tabs {
            display:flex;
            gap:6px;
            margin-bottom:10px;
        }
        .admin-tab-btn {
            border:none;
            border-radius:999px;
            padding:6px 14px;
            font-size:13px;
            background:#3c4043;
            color:#e8eaed;
            cursor:pointer;
        }
        .admin-tab-btn.active {
            background:#4f8cff;
            color:#fff;
        }
        .admin-tab {
            display:none;
            font-size:13px;
        }
        .admin-tab.active { display:block; }
        table.admin-table {
            width:100%;
            border-collapse:collapse;
            font-size:12px;
        }
        table.admin-table th,
        table.admin-table td {
            padding:4px 6px;
            border-bottom:1px solid #3c4043;
            text-align:left;
        }
        table.admin-table th {
            background:#303134;
            position:sticky;
            top:0;
        }
        .btn-mini {
            padding:3px 8px;
            border-radius:999px;
            border:none;
            font-size:11px;
            cursor:pointer;
        }
        .btn-approve { background:#34a853; color:#fff; }
        .btn-reject  { background:#ea4335; color:#fff; }
        .admin-msg {
            margin-top:8px;
            font-size:12px;
            white-space:pre-line;
            color:#81c995;
        }
        .admin-msg-err { color:#f28b82; }
        .inline-form {
            display:inline-block;
            margin:0 2px;
        }
        .admin-body input[type="password"],
        .admin-body select {
            padding:5px 8px;
            border-radius:999px;
            border:1px solid #555;
            background:#1f1f1f;
            color:#f1f3f4;
            font-size:12px;
            margin-bottom:6px;
        }
    </style>
</head>
<body>

<div class="page-wrap">
    <div class="top-bar">
        <div class="top-bar-title">ShipingList 업로드 (XLS / XLSX)</div>
        <div style="display:flex; align-items:center;">
            <div class="top-bar-user">
                로그인: <?php echo h($userId); ?>
                <?php if ($isAdmin): ?>
                    <span class="badge-role badge-admin" id="adminBadge">관리자</span>
                <?php else: ?>
                    <span class="badge-role badge-user">일반계정</span>
                <?php endif; ?>
            </div>
            <form method="post" style="margin:0;">
                <button type="submit" name="mode" value="logout" class="btn-logout">로그아웃</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>1. 엑셀 파일 선택 (XLS / XLSX)</h3>

        <?php if ($isAdmin): ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="mode" value="upload_excel">
                <div class="file-row">
                    <input type="file" name="excel_file" accept=".xls,.xlsx" required class="file-input">
                    <button type="submit" class="btn-primary">업로드 및 DB 반영</button>
                </div>
            </form>
            <div class="text-muted">
엑셀에서 바로 저장한 .xlsx / .xls 파일을 업로드합니다.
        <?php else: ?>
            <div class="text-muted">
엑셀 업로드는 관리자만 가능합니다.
            </div>
        <?php endif; ?>
    </div>

    <?php if ($resultMessage !== ''): ?>
        <div class="card">
            <h3>2. 처리 결과</h3>
            <div class="result-box">
                <?php echo nl2br(h($resultMessage)); ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
    <!-- 관리자 팝업 -->
    <div class="admin-backdrop" id="adminBackdrop"></div>
    <div class="admin-modal" id="adminModal">
        <div class="admin-header" id="adminHeader">
            <div class="admin-header-title">관리자 설정</div>
            <button type="button" class="admin-close admin-modal-close">&times;</button>
        </div>
        <div class="admin-body">
            <div class="admin-tabs">
                <button type="button" class="admin-tab-btn active" data-target="tab-approve">가입 승인</button>
                <button type="button" class="admin-tab-btn" data-target="tab-password">비밀번호 변경</button>
            </div>

            <!-- 가입 승인 탭 -->
            <div class="admin-tab active" id="tab-approve">
                <?php if (empty($pendingUsers)): ?>
                    <div class="text-muted">가입 승인 대기 중인 계정이 없습니다.</div>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>ID</th>
                                <th>역할</th>
                                <th>상태</th>
                                <th>생성시간</th>
                                <th>마지막 로그인</th>
                                <th>승인/거절</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingUsers as $u): ?>
                                <tr>
                                    <td><?php echo (int)$u['No']; ?></td>
                                    <td><?php echo h($u['ID']); ?></td>
                                    <td><?php echo h($u['role']); ?></td>
                                    <td><?php echo h($u['status']); ?></td>
                                    <td><?php echo h($u['created_at']); ?></td>
                                    <td><?php echo h($u['last_login_at']); ?></td>
                                    <td>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="mode" value="approve_user">
                                            <input type="hidden" name="target_no" value="<?php echo (int)$u['No']; ?>">
                                            <button type="submit" class="btn-mini btn-approve">승인</button>
                                        </form>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="mode" value="reject_user">
                                            <input type="hidden" name="target_no" value="<?php echo (int)$u['No']; ?>">
                                            <button type="submit" class="btn-mini btn-reject">거절</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- 비밀번호 변경 탭 -->
            <div class="admin-tab" id="tab-password">
                <form method="post">
                    <input type="hidden" name="mode" value="change_password">
                    <div style="margin-bottom:6px;">
                        <label style="font-size:12px;">대상 계정</label><br>
                        <select name="pw_target_no" required>
                            <option value="">선택하세요</option>
                            <?php foreach ($allAccounts as $acc): ?>
                                <option value="<?php echo (int)$acc['No']; ?>">
                                    <?php echo (int)$acc['No']; ?> - <?php echo h($acc['ID']); ?> (<?php echo h($acc['role']); ?>/<?php echo h($acc['status']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;">새 비밀번호</label><br>
                        <input type="password" name="pw_new" required>
                    </div>
                    <div>
                        <label style="font-size:12px;">새 비밀번호 확인</label><br>
                        <input type="password" name="pw_new2" required>
                    </div>
                    <div style="margin-top:6px;">
                        <button type="submit" class="btn-primary">비밀번호 변경</button>
                    </div>
                </form>
            </div>

            <?php if ($adminMessage !== ''): ?>
                <div class="admin-msg<?php echo (strpos($adminMessage, '오류') !== false || strpos($adminMessage, '없습니다') !== false) ? ' admin-msg-err' : ''; ?>">
                    <?php echo nl2br(h(ltrim($adminMessage))); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function makeDraggable(modal, handle) {
        if (!modal || !handle) return;
        handle.style.cursor = 'move';

        let isDown = false;
        let startX = 0, startY = 0;
        let origX = 0, origY = 0;

        handle.addEventListener('mousedown', function (e) {
            isDown = true;
            startX = e.clientX;
            startY = e.clientY;

            const rect = modal.getBoundingClientRect();
            origX = rect.left;
            origY = rect.top;

            modal.style.position = 'fixed';
            modal.style.transform = 'none';
            modal.style.transition = 'none';

            function onMove(ev) {
                if (!isDown) return;
                const dx = ev.clientX - startX;
                const dy = ev.clientY - startY;
                modal.style.left = (origX + dx) + 'px';
                modal.style.top  = (origY + dy) + 'px';
            }
            function onUp() {
                isDown = false;
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            e.preventDefault();
        });
    }

    var adminBadge    = document.getElementById('adminBadge');
    var adminModal    = document.getElementById('adminModal');
    var adminBackdrop = document.getElementById('adminBackdrop');
    var adminHeader   = document.getElementById('adminHeader');

    function openAdminModal() {
        if (!adminModal || !adminBackdrop) return;
        adminModal.classList.add('show');
        adminBackdrop.classList.add('show');
    }
    function closeAdminModal() {
        if (!adminModal || !adminBackdrop) return;
        adminModal.classList.remove('show');
        adminBackdrop.classList.remove('show');
    }

    if (adminBadge)    adminBadge.addEventListener('click', openAdminModal);
    if (adminBackdrop) adminBackdrop.addEventListener('click', closeAdminModal);

    var closeBtns = document.querySelectorAll('.admin-modal-close');
    closeBtns.forEach(function (btn) {
        btn.addEventListener('click', closeAdminModal);
    });

    var tabBtns = document.querySelectorAll('.admin-tab-btn');
    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-target');
            tabBtns.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');

            document.querySelectorAll('.admin-tab').forEach(function (tab) {
                if (tab.id === target) tab.classList.add('active');
                else tab.classList.remove('active');
            });
        });
    });

    if (adminModal && adminHeader) {
        makeDraggable(adminModal, adminHeader);
    }

    <?php if ($openAdminModal && $isAdmin): ?>
    openAdminModal();
    <?php endif; ?>
});
</script>

</body>
</html>
