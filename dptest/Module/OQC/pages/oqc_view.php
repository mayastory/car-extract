<?php
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }


// oqc_view.php : OQC 측정 데이터 조회 (NG 표시 포함, 납품처 선택에 따라 meas_date / jmeas_date 표시)

session_start();
require_once JTMES_ROOT . '/config/dp_config.php';

// ✅ embed=1 이면(쉘/iframe 내부) 배경/패딩을 투명하게
$EMBED = !empty($_GET['embed']);
// ✅ 단독 접근이면(쉘 밖) 사이드바/유저바/매트릭스 배경 출력
if (!$EMBED) {
    require_once JTMES_ROOT . '/inc/sidebar.php';
    require_once JTMES_ROOT . '/inc/dp_userbar.php';
}

require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fmt_spec_4')) {
    function fmt_spec_4($v): string {
        if ($v === null || $v === '') return '';
        if (!is_numeric((string)$v)) return (string)$v;
        return number_format((float)$v, 4, '.', '');
    }
}

function table_columns(PDO $pdo, string $table): array
{
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[strtolower($r['Field'])] = true;
    }
    return $cols;
}

try {
    $pdo = dp_get_pdo();
} catch (PDOException $e) {
    die('DB 접속 실패: ' . h($e->getMessage()));
}

$headerCols = table_columns($pdo, 'oqc_header');

if (!function_exists('dp_session_user_no_local')) {
    function dp_session_user_no_local(): ?int {
        foreach (['ship_user_no','user_no','dp_user_no','account_no','no','No'] as $k) {
            if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) return (int)$_SESSION[$k];
        }
        return null;
    }
}

if (!function_exists('dp_session_user_id_local')) {
    function dp_session_user_id_local(): ?string {
        foreach (['ship_user_id','user_id','userid','username','id','ID'] as $k) {
            if (!empty($_SESSION[$k])) return (string)$_SESSION[$k];
        }
        return null;
    }
}

if (!function_exists('dp_current_account_row_local')) {
    function dp_current_account_row_local(PDO $pdo): ?array {
        $no = dp_session_user_no_local();
        if ($no !== null) {
            $st = $pdo->prepare('SELECT `No`,`ID`,`NAME`,`lv`,`role`,`status` FROM `account` WHERE `No` = :no LIMIT 1');
            $st->execute([':no' => $no]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }

        $id = dp_session_user_id_local();
        if ($id !== null && $id !== '') {
            $st = $pdo->prepare('SELECT `No`,`ID`,`NAME`,`lv`,`role`,`status` FROM `account` WHERE `ID` = :id LIMIT 1');
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }

        return null;
    }
}

$viewerAccount = dp_current_account_row_local($pdo);
$viewerLv = (int)($viewerAccount['lv'] ?? 0);
$canManualMeasEdit = ($viewerLv >= 98);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'oqc_manual_meas_save')) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$canManualMeasEdit) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => '권한이 없습니다.']);
        exit;
    }

    $headerId = (int)($_POST['header_id'] ?? 0);
    $postCustomer = $_POST['customer'] ?? 'LG';
    if ($postCustomer !== 'LG' && $postCustomer !== 'JH') $postCustomer = 'LG';
    $postKey1 = ($postCustomer === 'JH') ? 'jmeas_date' : 'meas_date';
    $postKey2 = ($postCustomer === 'JH') ? 'jmeas_date2' : 'meas_date2';

    if ($headerId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'header_id가 올바르지 않습니다.']);
        exit;
    }
    if (!isset($headerCols[$postKey1])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => '측정일 컬럼이 없습니다.']);
        exit;
    }

    $date1 = trim((string)($_POST['date1'] ?? ''));
    $date2 = trim((string)($_POST['date2'] ?? ''));
    foreach (['date1' => $date1, 'date2' => $date2] as $label => $val) {
        if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $label . ' 형식이 올바르지 않습니다.']);
            exit;
        }
    }

    $check = $pdo->prepare('SELECT `id`,`part_name`,`tool_cavity`,`excel_col`,`kind` FROM `oqc_header` WHERE `id` = :id LIMIT 1');
    $check->execute([':id' => $headerId]);
    $target = $check->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => '대상 헤더를 찾을 수 없습니다.']);
        exit;
    }

    if (isset($headerCols[$postKey2])) {
        $sql = "UPDATE `oqc_header` SET `{$postKey1}` = :d1, `{$postKey2}` = :d2 WHERE `id` = :id LIMIT 1";
    } else {
        $sql = "UPDATE `oqc_header` SET `{$postKey1}` = :d1 WHERE `id` = :id LIMIT 1";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':d1', ($date1 === '' ? null : $date1), ($date1 === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
    if (isset($headerCols[$postKey2])) {
        $stmt->bindValue(':d2', ($date2 === '' ? null : $date2), ($date2 === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
    }
    $stmt->bindValue(':id', $headerId, PDO::PARAM_INT);
    $stmt->execute();

    $recommend = '';
    if ($date1 === '') $recommend = $postKey1;
    elseif (isset($headerCols[$postKey2]) && $date2 === '') $recommend = $postKey2;
    else $recommend = '직접 수정';

    echo json_encode([
        'ok' => true,
        'message' => '저장되었습니다.',
        'header_id' => $headerId,
        'date1' => $date1,
        'date2' => $date2,
        'key1' => $postKey1,
        'key2' => isset($headerCols[$postKey2]) ? $postKey2 : '',
        'recommend' => $recommend,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 검색 파라미터
$part_name  = $_GET['part_name'] ?? '';
$ship_date  = $_GET['ship_date'] ?? '';
if ($ship_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ship_date)) $ship_date = '';
$kind       = $_GET['kind']      ?? 'ALL';



// 납품처(선택값) : 엘지이노텍(주) / 자화전자(주)
// - LG 선택: meas_date, meas_date2 사용
// - JH 선택: jmeas_date, jmeas_date2 사용
$customer = $_GET['customer'] ?? 'LG';
if ($customer !== 'LG' && $customer !== 'JH') $customer = 'LG';
$customerLabel = ($customer === 'JH') ? '자화전자(주)' : '엘지이노텍(주)';

$measKey1 = ($customer === 'JH') ? 'jmeas_date'  : 'meas_date';
$measKey2 = ($customer === 'JH') ? 'jmeas_date2' : 'meas_date2';
// 품번 리스트
$stmt = $pdo->query("SELECT DISTINCT part_name FROM oqc_header ORDER BY part_name");
$part_list = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 결과
$headers = [];
$row_defs = [];
$rows = [];

// NG/spec 맵
$ngMap   = []; // [header_id][point_no] => true
$specMap = []; // [point_no] => ['usl'=>, 'lsl'=>]

if ($part_name !== '' && $ship_date !== '') {

    $params = [
        ':part_name' => $part_name,
        ':ship_date' => $ship_date,
    ];

    $kind_sql = '';
    if ($kind === 'FAI' || $kind === 'SPC') {
        $kind_sql = " AND h.kind = :kind";
        $params[':kind'] = $kind;
    }

    // ship_date 컬럼이 있으면 ship_date 우선, 없으면 lot_date로만 조회
    if (isset($headerCols['ship_date'])) {
        $dateWhere = " (h.ship_date = :ship_date OR (h.ship_date IS NULL AND h.lot_date = :ship_date)) ";
    } else {
        $dateWhere = " h.lot_date = :ship_date ";
    }

    // meas_date / meas_date2 (또는 jmeas_date / jmeas_date2) 컬럼이 있으면 같이 SELECT
    $selMeas  = isset($headerCols[$measKey1]) ? ", h.`{$measKey1}`" : "";
    $selMeas2 = isset($headerCols[$measKey2]) ? ", h.`{$measKey2}`" : "";
$sql = "
        SELECT
            h.id,
		    h.part_name
		    {$selMeas}{$selMeas2},
            " . (isset($headerCols['ship_date']) ? "h.ship_date," : "") . "
            h.lot_date,
            h.tool_cavity,
            h.kind,
            h.source_file,
            h.excel_col
        FROM oqc_header h
        WHERE h.part_name = :part_name
          AND {$dateWhere}
          {$kind_sql}
        ORDER BY h.excel_col
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($headers) {
        $base_header_id = (int)$headers[0]['id'];

        $stmt = $pdo->prepare("
            SELECT row_index, point_no, spc_code
            FROM oqc_measurements
            WHERE header_id = :hid
            ORDER BY row_index
        ");
        $stmt->execute([':hid' => $base_header_id]);
        $row_defs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($row_defs as $rd) {
            $ri = (int)$rd['row_index'];
            $rows[$ri] = [
                'point_no' => $rd['point_no'],
                'spc_code' => $rd['spc_code'],
                'values'   => [],
            ];
        }

        $header_ids = array_column($headers, 'id');
        $in = implode(',', array_fill(0, count($header_ids), '?'));

        $stmt = $pdo->prepare("
            SELECT header_id, row_index, value
            FROM oqc_measurements
            WHERE header_id IN ($in)
            ORDER BY row_index, header_id
        ");
        $stmt->execute($header_ids);
        $datas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($datas as $d) {
            $ri = (int)$d['row_index'];
            if (!isset($rows[$ri])) continue;
            $rows[$ri]['values'][$d['header_id']] = $d['value'];
        }

        // oqc_result_header가 있으면 NG/spec 읽기
        try {
            // specMap: point_no별 usl/lsl(중복 있으면 MAX로)
            $stmt = $pdo->prepare("
                SELECT point_no,
                       MAX(usl) AS usl,
                       MAX(lsl) AS lsl
                FROM oqc_result_header
                WHERE header_id IN ($in)
                GROUP BY point_no
            ");
            $stmt->execute($header_ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $specMap[$r['point_no']] = ['usl' => $r['usl'], 'lsl' => $r['lsl']];
            }

            // NG만 따로 맵
            $stmt = $pdo->prepare("
                SELECT header_id, point_no
                FROM oqc_result_header
                WHERE header_id IN ($in)
                  AND result_ok = 0
            ");
            $stmt->execute($header_ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hid = (int)$r['header_id'];
                $pno = $r['point_no'];
                $ngMap[$hid][$pno] = true;
            }
        } catch (Throwable $e) {
            // result_header 없거나 비어있으면 무시
        }
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <title>OQC 측정 데이터 조회</title>
    <style>
:root{
  --bg:#202124; --card:#2b2b2b; --fg:#e8eaed;
  --muted:#9aa0a6; --border:#5f6368;
  --accent:#4f8cff; --accent2:#8ab4f8;
  --danger:#e85d5d;
  --radius:14px;
  --sticky1:72px; --sticky2:72px; --sticky3:88px; --sticky4:88px;
  --ctl-h:34px;
}
body{
  margin:0;
  padding:18px;
  font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif,"Apple Color Emoji","Segoe UI Emoji";
  background:var(--bg);
  color:var(--fg);
  font-size:13px;
}

/* ✅ 좌측 레일(72px) 공간 확보 */
.dp-page{ padding-left:72px; box-sizing:border-box; }


<?php if (!empty($EMBED)): ?>
body{background: transparent !important; padding:0 !important;}
.wrap{max-width:none; margin:0; padding:14px;}
<?php endif; ?>
.wrap{max-width:1400px;margin:0 auto;}
h1{font-size:20px;margin:0 0 12px;}

.filters{
  display:flex;
  gap:14px;
  align-items:flex-end;
  flex-wrap:wrap;
  background:var(--card);
  border-radius:var(--radius);
  padding:14px 18px;
  box-shadow:0 8px 20px rgba(0,0,0,0.45);
  border:1px solid rgba(255,255,255,0.10);
  margin-bottom:12px;
}
.filters label{
  display:flex;
  flex-direction:column;
  gap:4px;
  font-size:13px;
  color:#d7dbe0;
}
.filters input[type="date"],
.filters select{
  height:var(--ctl-h);
  padding:0 10px;
  border-radius:10px;
  border:1px solid var(--border);
  background:var(--bg);
  color:var(--fg);
  font-size:13px;
}
.filters input[type="date"]{padding:0 8px;}
.filters input[type="date"]:focus,
.filters select:focus{
  outline:none;
  border-color:var(--accent2);
  box-shadow:0 0 0 1px var(--accent2);
}
.filters button{
  height:var(--ctl-h);
  padding:0 14px;
  border-radius:12px;
  border:1px solid rgba(29,185,84,0.55);
  font-size:12.5px;
  font-weight:650;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  user-select:none;
  box-shadow:0 6px 14px rgba(0,0,0,0.26);
  background:rgba(29,185,84,0.18);
  color:var(--fg);
}
.filters button:hover{ background:rgba(29,185,84,0.28); }
.filters button:active{transform:translateY(1px);}

.msg{
  margin:12px 0 0;
  padding:10px 12px;
  border-radius:12px;
  background:rgba(255,255,255,0.06);
  border:1px solid rgba(255,255,255,0.10);
  color:var(--muted);
  font-size:12.5px;
}

.summary{
  margin:0 0 12px;
  padding:10px 12px;
  border-radius:12px;
  background:rgba(0,0,0,0.08);
  border:1px solid rgba(255,255,255,0.10);
  color:var(--muted);
  font-size:12px;
  display:flex;
  flex-wrap:wrap;
  gap:12px;
  align-items:center;
}
.summary strong{color:var(--fg);}
.summary span{white-space:nowrap;}
.summary span.file{
  white-space:normal;
  overflow-wrap:anywhere;
}

.table-wrap{
  overflow-x:auto;
  overflow-y:visible; /* ✅ 세로는 페이지 스크롤로 */
  border-radius:12px;
  border:1px solid rgba(255,255,255,0.10);
  background:var(--card); /* ✅ 원래 회색톤 */
}
table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  min-width:900px;
  font-size:11.5px;
}
th, td{box-sizing:border-box;}

thead th{
  position:relative; /* ✅ float header가 담당 */
  z-index:2;
  background:#303134;
  color:#cbd5e1;
  font-size:11px;
  text-align:center;
  padding:8px 10px;
  border-bottom:1px solid rgba(255,255,255,0.10);
  white-space:nowrap;
}
tbody td{
  padding:8px 10px;
  font-size:11.5px;
  color:var(--fg);
  border-bottom:1px solid rgba(255,255,255,0.06);
  white-space:nowrap;
  text-align:center;
}

tbody tr td{ background:var(--card); }
tbody tr:nth-child(even) td{ background:#26282c; }
tbody tr:hover td{background:rgba(29,185,84,0.08);}

th.col-head div.sub{font-size:10px;color:#57d68d;}

th.num, td.num{width:var(--sticky1);min-width:var(--sticky1);max-width:var(--sticky1);}
th.spc, td.spc{width:var(--sticky2);min-width:var(--sticky2);max-width:var(--sticky2);}
th.spec, td.spec{width:var(--sticky3);min-width:var(--sticky3);max-width:var(--sticky3);}
th.spec2, td.spec2{width:var(--sticky4);min-width:var(--sticky4);max-width:var(--sticky4);}

th.sticky, td.sticky{
  position:sticky;
  left:0;
  text-align:center;
}
th.sticky2, td.sticky2{
  position:sticky;
  left:var(--sticky1);
  text-align:center;
}
th.sticky3, td.sticky3{
  position:sticky;
  left:calc(var(--sticky1) + var(--sticky2));
  text-align:center;
}
th.sticky4, td.sticky4{
  position:sticky;
  left:calc(var(--sticky1) + var(--sticky2) + var(--sticky3));
  text-align:center;
  box-shadow: 2px 0 0 rgba(255,255,255,0.10);
}

/* ✅ 왼쪽 고정(FAI/SPC/USL/LSL) 배경 고정 */
th.sticky, td.sticky{ background:#202124; z-index:5; }
th.sticky2, td.sticky2{ background:#202124; z-index:5; }
th.sticky3, td.sticky3{ background:#202124; z-index:5; }
th.sticky4, td.sticky4{ background:#202124; z-index:5; }
thead th.sticky, thead th.sticky2, thead th.sticky3, thead th.sticky4{
  z-index:5;
  background:#303134;
}
tbody td.sticky, tbody td.sticky2, tbody td.sticky3, tbody td.sticky4{
  z-index:3;
  background:#202124;
}
tbody tr:hover td.sticky, tbody tr:hover td.sticky2, tbody tr:hover td.sticky3, tbody tr:hover td.sticky4{
  background:rgba(29,185,84,0.10);
}

td.ng{
  background:rgba(232,93,93,0.12) !important;
  border-bottom-color:rgba(232,93,93,0.22);
  color:#ffd1d1;
  font-weight:700;
}

/* ✅ 페이지 스크롤용 고정 헤더(클론) */
.float-head{
  position:fixed;
  top:0;
  left:0;
  width:0;
  display:none;
  z-index:9999;
}
.float-head .float-scroller{
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:none;
  background:#303134;
  border-radius:12px 12px 0 0;
  border:1px solid rgba(255,255,255,0.10);
  border-bottom:0;
}
.float-head .float-scroller::-webkit-scrollbar{display:none;}
.float-head table{
  border-collapse:separate;
  border-spacing:0;
  width:max-content;
  min-width:100%;
}
.float-head thead th{
  background:#303134;
}
.float-head th.sticky,
.float-head th.sticky2{
  background:#202124;
}

.manual-date-trigger.can-edit{cursor:pointer; user-select:none;}
.manual-date-trigger.can-edit:hover{color:#98ffbd; text-decoration:underline;}
.manual-date-trigger.readonly{cursor:default;}

.manual-meas-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,0.56); z-index:10040;
  display:none; align-items:center; justify-content:center; padding:18px;
}
.manual-meas-backdrop.open{display:flex;}
.manual-meas-modal{
  width:min(520px, calc(100vw - 24px));
  background:#23262b; color:var(--fg); border:1px solid rgba(255,255,255,0.12);
  border-radius:18px; box-shadow:0 18px 40px rgba(0,0,0,0.48); overflow:hidden;
}
.manual-meas-head{padding:14px 18px; border-bottom:1px solid rgba(255,255,255,0.08); font-size:20px; font-weight:800;}
.manual-meas-body{padding:18px;}
.manual-meas-meta{font-size:13px; line-height:1.7; margin-bottom:14px;}
.manual-meas-meta strong{color:#fff;}
.manual-meas-current{margin:0 0 14px; padding:12px 14px; border-radius:14px; background:#1e2126; border:1px solid rgba(255,255,255,0.08);}
.manual-meas-row{display:flex; align-items:center; gap:10px; margin-top:8px;}
.manual-meas-row:first-child{margin-top:0;}
.manual-meas-key{width:96px; min-width:96px; color:#b7c0cb; font-size:12.5px;}
.manual-meas-row input[type="date"]{flex:1; height:36px; border-radius:10px; border:1px solid var(--border); background:#121417; color:var(--fg); padding:0 10px;}
.manual-meas-row input[type="date"]:focus{outline:none; border-color:var(--accent2); box-shadow:0 0 0 1px var(--accent2);}
.manual-meas-row button{height:36px; padding:0 11px; border-radius:10px; border:1px solid rgba(255,255,255,0.12); background:#343942; color:#fff; cursor:pointer; font-size:12px; font-weight:700;}
.manual-meas-row button:hover{background:#414754;}
.manual-meas-hint{margin-top:12px; color:#8de6b0; font-size:13px; font-weight:700;}
.manual-meas-error{display:none; margin-top:12px; color:#ffb4b4; font-size:12.5px;}
.manual-meas-error.show{display:block;}
.manual-meas-foot{display:flex; justify-content:flex-end; gap:10px; padding:14px 18px; border-top:1px solid rgba(255,255,255,0.08);}
.manual-meas-foot button{height:38px; min-width:72px; border-radius:12px; border:1px solid rgba(255,255,255,0.12); background:#343942; color:#fff; cursor:pointer; font-weight:700;}
.manual-meas-foot button.primary{background:rgba(29,185,84,0.22); border-color:rgba(29,185,84,0.55);}
.manual-meas-foot button.primary:hover{background:rgba(29,185,84,0.30);}
.manual-meas-foot button:hover{background:#414754;}

</style>
</head>
<body>
<?php if (empty($EMBED)):
// 좌측 메뉴 + 상단 유저바 (페이지마다 자동 적용)
echo dp_sidebar_render('oqc');
echo dp_render_userbar([
  'admin_badge_mode' => 'modal',
  'admin_iframe_src' => 'admin_settings',
  'logout_action'    => 'logout'
]);
endif; ?>
<?php if (!empty($EMBED)): ?>
<style>
  /* ✅ iframe(embed)에서는 배경/좌측패딩 제거해서 쉘 배경이 보이게 */
  body{background: transparent !important; padding:0 !important;}
  .dp-page{padding-left:0 !important;}

  /* ✅ 테이블/요약이 반투명(rgba)이면 매트릭스가 비쳐서 글라스처럼 보임.
        embed 모드에서는 카드/테이블 영역을 불투명으로 강제해서 1번(DPTest.zip) 느낌 유지 */
  .filters,.summary,.msg{background:#2b2b2b !important; backdrop-filter:none !important; -webkit-backdrop-filter:none !important;}
  .table-wrap{background:#202124 !important; backdrop-filter:none !important; -webkit-backdrop-filter:none !important;}
  table{background:#202124 !important;}
  thead th{background:#303134 !important;}
  tbody td{background:#202124 !important;}
  tbody tr:hover td{background:rgba(29,185,84,0.08) !important;}
</style>
<?php endif; ?>
<div class="dp-page">
<div class="wrap">
    <h1>OQC 측정 데이터 조회</h1>

    <form method="get" class="filters">
        <?php if (!empty($EMBED)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>
            납품처
            <select name="customer">
                <option value="LG" <?= $customer === 'LG' ? 'selected' : '' ?>>엘지이노텍(주)</option>
                <option value="JH" <?= $customer === 'JH' ? 'selected' : '' ?>>자화전자(주)</option>
            </select>
        </label>

        <label>
            모델명
            <select name="part_name">
                <option value="">-- 선택 --</option>
                <?php foreach ($part_list as $p): ?>
                    <option value="<?=h($p)?>" <?= $p === $part_name ? 'selected' : '' ?>>
                        <?=h($p)?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            측정일
            <input type="date" name="ship_date"
       value="<?=h($ship_date)?>"
       min="2000-01-01"
       max="9999-12-31">
        </label>

        <button type="submit">조회</button>
    </form>

    <?php if ($part_name && $ship_date && !$headers): ?>
        <div class="msg">
            해당 조건의 데이터가 없습니다.
            (납품처: <?=h($customerLabel)?>, 모델: <?=h($part_name)?>, 출하 기준 날짜: <?=h($ship_date)?>)
        </div>
    <?php endif; ?>

    <?php if ($headers): ?>
        <div class="summary">
            <span>납품처: <strong><?=h($customerLabel)?></strong></span>
            <span>모델: <strong><?=h($headers[0]['part_name'])?></strong></span>
            <span>출하 기준 날짜: <strong><?=h($ship_date)?></strong></span>
            <span>NG: <strong style="color:#ffb4b4;">빨간색</strong></span>
            <span class="file">파일: <?=h($headers[0]['source_file'])?></span>
        </div>

        <div id="floatHead" class="float-head" aria-hidden="true"><div class="float-scroller"></div></div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="sticky num">FAI</th>
                    <th class="sticky2 spc">SPC</th>
                    <th class="sticky3 spec">USL</th>
                    <th class="sticky4 spec2">LSL</th>
                    <?php foreach ($headers as $hrow): ?>
                        <th class="col-head">
                            <div class="top">
                                <?php
                                // ✅ meas_date2가 있으면 위에, meas_date는 아래에 표시
                                $top2 = $hrow[$measKey2] ?? null;
                                $top1 = $hrow[$measKey1] ?? null;

                                if ($top2 && $top1) {
                                    echo h($top2) . '<br>' . h($top1);
                                } elseif ($top1) {
                                    echo h($top1);
                                } elseif ($top2) {
                                    echo h($top2);
                                } else {
                                    echo '&nbsp;';
                                }
                                ?>
                            </div>
                            <div class="main"><?=h($hrow['tool_cavity'])?></div>
                            <?php $curDate1 = (string)($hrow[$measKey1] ?? ''); $curDate2 = (string)($hrow[$measKey2] ?? ''); ?>
                            <div
                                class="sub manual-date-trigger <?= $canManualMeasEdit ? 'can-edit' : 'readonly' ?>"
                                data-header-id="<?= (int)$hrow['id'] ?>"
                                data-customer="<?= h($customer) ?>"
                                data-customer-label="<?= h($customerLabel) ?>"
                                data-part-name="<?= h($headers[0]['part_name']) ?>"
                                data-tool-cavity="<?= h($hrow['tool_cavity']) ?>"
                                data-excel-col="<?= h($hrow['excel_col']) ?>"
                                data-kind="<?= h($hrow['kind']) ?>"
                                data-date1="<?= h($curDate1) ?>"
                                data-date2="<?= h($curDate2) ?>"
                                data-key1="<?= h($measKey1) ?>"
                                data-key2="<?= h($measKey2) ?>"
                                title="<?= $canManualMeasEdit ? '더블클릭하여 측정일 수동 입력/수정/삭제' : '' ?>"
                            ><?=h($hrow['excel_col'])?> (<?=h($hrow['kind'])?>)</div>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $ri => $row): ?>
                    <?php
                    $pno = $row['point_no'];
                    $spec = $specMap[$pno] ?? null;
                    $usl = ($spec && $spec['usl'] !== null && $spec['usl'] !== '') ? fmt_spec_4($spec['usl']) : '';
                    $lsl = ($spec && $spec['lsl'] !== null && $spec['lsl'] !== '') ? fmt_spec_4($spec['lsl']) : '';
                    ?>
                    <tr>
                        <td class="sticky num"><?=h($row['point_no'])?></td>
                        <td class="sticky2 spc"><?=h($row['spc_code'])?></td>
                        <td class="sticky3 spec"><?= $usl === '' ? '' : h($usl) ?></td>
                        <td class="sticky4 spec2"><?= $lsl === '' ? '' : h($lsl) ?></td>
                        <?php foreach ($headers as $hrow): ?>
                            <?php
                            $hid = (int)$hrow['id'];
                            $val = $row['values'][$hid] ?? '';
                            $dispVal = ($val === '' || $val === null) ? '' : fmt_spec_4($val);
                            $isNg = (strpos((string)$pno, '(DC)') === false) && isset($ngMap[$hid][$pno]);
                            ?>
                            <td class="<?= $isNg ? 'ng' : '' ?>"><?= $dispVal === '' ? '' : h($dispVal) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($headers && $canManualMeasEdit): ?>
    <div id="manualMeasBackdrop" class="manual-meas-backdrop" aria-hidden="true">
        <div class="manual-meas-modal" role="dialog" aria-modal="true" aria-labelledby="manualMeasTitle">
            <div class="manual-meas-head" id="manualMeasTitle">측정일 수동 입력</div>
            <form id="manualMeasForm">
                <input type="hidden" name="action" value="oqc_manual_meas_save">
                <input type="hidden" name="header_id" id="manualMeasHeaderId" value="">
                <input type="hidden" name="customer" id="manualMeasCustomer" value="">
                <div class="manual-meas-body">
                    <div class="manual-meas-meta">
                        <div>납품처: <strong id="manualMeasCustomerLabel"></strong></div>
                        <div>모델: <strong id="manualMeasPartName"></strong></div>
                        <div>Tool#Cavity: <strong id="manualMeasToolCavity"></strong></div>
                        <div>열: <strong id="manualMeasExcelCol"></strong></div>
                    </div>
                    <div class="manual-meas-current">
                        <div class="manual-meas-row">
                            <div class="manual-meas-key" id="manualMeasKey1">meas_date</div>
                            <input type="date" name="date1" id="manualMeasDate1" min="2000-01-01" max="9999-12-31">
                            <button type="button" data-clear="1">삭제</button>
                        </div>
                        <div class="manual-meas-row">
                            <div class="manual-meas-key" id="manualMeasKey2">meas_date2</div>
                            <input type="date" name="date2" id="manualMeasDate2" min="2000-01-01" max="9999-12-31">
                            <button type="button" data-clear="2">삭제</button>
                        </div>
                        <div class="manual-meas-hint" id="manualMeasHint"></div>
                        <div class="manual-meas-error" id="manualMeasError"></div>
                    </div>
                </div>
                <div class="manual-meas-foot">
                    <button type="button" id="manualMeasCancel">취소</button>
                    <button type="submit" class="primary" id="manualMeasSave">저장</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php if (empty($EMBED)): ?>
<!-- ✅ 매트릭스 배경 외부 연결 (config/matrix_bg.php에서 설정) -->
<?php
$__mb = @include JTMES_ROOT . '/config/matrix_bg.php';
if (!is_array($__mb)) {
  $__mb = ['enabled'=>true,'text'=>'01','speed'=>1.15,'size'=>16,'zIndex'=>0,'scanlines'=>true,'vignette'=>true];
}
?>
<script>
  window.MATRIX_BG = <?php echo json_encode($__mb, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/matrix-bg.js"></script>
<?php endif; ?>


<script>
(function(){
  const floatHead = document.getElementById('floatHead');
  const tableWrap = document.querySelector('.table-wrap');
  if(!floatHead || !tableWrap) return;

  const table = tableWrap.querySelector('table');
  const thead = table ? table.querySelector('thead') : null;
  if(!table || !thead) return;

  const scroller = floatHead.querySelector('.float-scroller');
  if(!scroller) return;

  // Build floating header table (thead clone)
  const headTable = document.createElement('table');
  const headThead = thead.cloneNode(true);
  headTable.appendChild(headThead);

  scroller.innerHTML = '';
  scroller.appendChild(headTable);

  // Ensure colgroup exists on both tables for exact width locking
  function ensureColgroup(tbl, colCount){
    let cg = tbl.querySelector('colgroup');
    if(!cg){
      cg = document.createElement('colgroup');
      for(let i=0;i<colCount;i++){
        const c = document.createElement('col');
        cg.appendChild(c);
      }
      tbl.insertBefore(cg, tbl.firstChild);
    }else{
      // normalize count
      const cols = cg.querySelectorAll('col');
      if(cols.length < colCount){
        for(let i=cols.length;i<colCount;i++){
          cg.appendChild(document.createElement('col'));
        }
      }
    }
    return cg;
  }

  function syncWidths(){
    const headRow = thead.querySelector('tr');
    if(!headRow) return;
    const ths = headRow.children;
    const colCount = ths.length;

    const cg1 = ensureColgroup(table, colCount);
    const cg2 = ensureColgroup(headTable, colCount);

    const cols1 = cg1.querySelectorAll('col');
    const cols2 = cg2.querySelectorAll('col');

    // Measure widths from the original header cells (most stable)
    const widths = [];
    for(let i=0;i<colCount;i++){
      const w = ths[i].getBoundingClientRect().width;
      widths.push(Math.max(40, Math.round(w)));
    }

    for(let i=0;i<colCount;i++){
      cols1[i].style.width = widths[i] + 'px';
      cols2[i].style.width = widths[i] + 'px';
    }

    // Match floating scroller width/position to tableWrap
    const rect = tableWrap.getBoundingClientRect();
    floatHead.style.left = rect.left + 'px';
    floatHead.style.width = rect.width + 'px';
    scroller.style.width = rect.width + 'px';

    // Keep scrollLeft aligned
    scroller.scrollLeft = tableWrap.scrollLeft;
  }

  function updateVisibility(){
    const rect = tableWrap.getBoundingClientRect();
    // show when table top passed viewport top, and table bottom still below top
    const show = rect.top < 0 && rect.bottom > 0;
    floatHead.style.display = show ? 'block' : 'none';
    if(show){
      // align position with tableWrap left/width every time
      floatHead.style.left = rect.left + 'px';
      floatHead.style.width = rect.width + 'px';
      scroller.style.width = rect.width + 'px';
    }
  }

  // Sync horizontal scroll
  let lock = false;
  tableWrap.addEventListener('scroll', function(){
    if(lock) return;
    lock = true;
    scroller.scrollLeft = tableWrap.scrollLeft;
    lock = false;
  }, {passive:true});

  scroller.addEventListener('scroll', function(){
    if(lock) return;
    lock = true;
    tableWrap.scrollLeft = scroller.scrollLeft;
    lock = false;
  }, {passive:true});

  window.addEventListener('scroll', updateVisibility, {passive:true});
  window.addEventListener('resize', function(){
    syncWidths();
    updateVisibility();
  });

  // initial
  requestAnimationFrame(function(){
    syncWidths();
    updateVisibility();
  });
})();
</script>

<?php if ($headers && $canManualMeasEdit): ?>
<script>
(function(){
  const backdrop = document.getElementById('manualMeasBackdrop');
  const form = document.getElementById('manualMeasForm');
  if (!backdrop || !form) return;

  const headerIdEl = document.getElementById('manualMeasHeaderId');
  const customerEl = document.getElementById('manualMeasCustomer');
  const customerLabelEl = document.getElementById('manualMeasCustomerLabel');
  const partNameEl = document.getElementById('manualMeasPartName');
  const toolCavityEl = document.getElementById('manualMeasToolCavity');
  const excelColEl = document.getElementById('manualMeasExcelCol');
  const key1El = document.getElementById('manualMeasKey1');
  const key2El = document.getElementById('manualMeasKey2');
  const date1El = document.getElementById('manualMeasDate1');
  const date2El = document.getElementById('manualMeasDate2');
  const hintEl = document.getElementById('manualMeasHint');
  const errorEl = document.getElementById('manualMeasError');
  const cancelBtn = document.getElementById('manualMeasCancel');
  const saveBtn = document.getElementById('manualMeasSave');
  const triggers = document.querySelectorAll('.manual-date-trigger.can-edit');

  let activeTrigger = null;

  function showError(msg){
    errorEl.textContent = msg || '';
    errorEl.classList.toggle('show', !!msg);
  }

  function updateHint(){
    const key1 = key1El.textContent.trim();
    const key2 = key2El.textContent.trim();
    const v1 = date1El.value.trim();
    const v2 = date2El.value.trim();
    let text = '';
    if (!v1) text = '저장 대상 추천: ' + key1;
    else if (key2 && !v2) text = '저장 대상 추천: ' + key2;
    else text = '저장 대상 추천: 직접 수정';
    hintEl.textContent = text;
  }

  function openModal(trigger){
    activeTrigger = trigger;
    const ds = trigger.dataset;
    headerIdEl.value = ds.headerId || '';
    customerEl.value = ds.customer || 'LG';
    customerLabelEl.textContent = ds.customerLabel || '';
    partNameEl.textContent = ds.partName || '';
    toolCavityEl.textContent = ds.toolCavity || '';
    excelColEl.textContent = ((ds.excelCol || '') + ' (' + (ds.kind || '') + ')').trim();
    key1El.textContent = ds.key1 || 'meas_date';
    key2El.textContent = ds.key2 || 'meas_date2';
    date1El.value = ds.date1 || '';
    date2El.value = ds.date2 || '';
    showError('');
    updateHint();
    backdrop.classList.add('open');
    backdrop.setAttribute('aria-hidden', 'false');
    if (!date1El.value) date1El.focus();
    else date2El.focus();
  }

  function closeModal(){
    backdrop.classList.remove('open');
    backdrop.setAttribute('aria-hidden', 'true');
    showError('');
    if (activeTrigger) activeTrigger.focus();
  }

  triggers.forEach(function(el){
    el.addEventListener('dblclick', function(ev){
      ev.preventDefault();
      openModal(el);
    });
  });

  [date1El, date2El].forEach(function(el){
    el.addEventListener('input', updateHint);
    el.addEventListener('change', updateHint);
  });

  backdrop.querySelectorAll('[data-clear]').forEach(function(btn){
    btn.addEventListener('click', function(){
      const which = btn.getAttribute('data-clear');
      if (which === '1') date1El.value = '';
      if (which === '2') date2El.value = '';
      updateHint();
    });
  });

  cancelBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', function(ev){
    if (ev.target === backdrop) closeModal();
  });
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape' && backdrop.classList.contains('open')) closeModal();
  });

  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    showError('');
    saveBtn.disabled = true;
    saveBtn.textContent = '저장 중...';

    const fd = new FormData(form);
    fetch(window.location.href, { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
      .then(async function(res){
        const data = await res.json().catch(function(){ return null; });
        if (!res.ok || !data || !data.ok) {
          throw new Error((data && data.message) ? data.message : '저장에 실패했습니다.');
        }
        if (activeTrigger) {
          activeTrigger.dataset.date1 = data.date1 || '';
          activeTrigger.dataset.date2 = data.date2 || '';
        }
        window.location.reload();
      })
      .catch(function(err){
        showError(err && err.message ? err.message : '저장에 실패했습니다.');
      })
      .finally(function(){
        saveBtn.disabled = false;
        saveBtn.textContent = '저장';
      });
  });
})();
</script>
<?php endif; ?>

</div>
</body>
</html>
