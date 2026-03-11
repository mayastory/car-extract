<?php
// shipinglist_tool_cavity.php : 품번명+툴 / Cavity / 생산일자별 출하 수량 피벗
// - 품번명 드롭다운만 사용 (모델 선택 제거)
// - 조회기간은 ship_datetime 기준, 컷오프 08:30 적용
//   예) 2025-12-09 ~ 2025-12-09 → 2025-12-08 08:30:00 ~ 2025-12-09 08:30:00

session_start();
require_once __DIR__ . '/config/dp_config.php';

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// 로그인 체크
if (empty($_SESSION['ship_user_id'])) {
    header('Location: index.php');
    exit;
}

try {
    $pdo = dp_get_pdo();
} catch (PDOException $e) {
    die("DB 접속 실패: " . h($e->getMessage()));
}

// ─────────────────────────────
// 필터 값 (목록 페이지와 동일)
// ─────────────────────────────
$today = date('Y-m-d');

$fromDate        = $_GET['from_date']        ?? $today;
$toDate          = $_GET['to_date']          ?? $today;
$filterShipTo    = trim($_GET['ship_to']     ?? '');
$filterPackBc    = trim($_GET['pack_bc']     ?? '');
$filterSmallNo   = trim($_GET['small_no']    ?? '');
$filterTrayNo    = trim($_GET['tray_no']     ?? '');
$filterPnameLike = trim($_GET['part_name']   ?? '');   // 목록 페이지에서 LIKE로 넘어온 값
$filterPartExact = trim($_GET['part_name_exact'] ?? ''); // 이 화면에서 드롭다운으로 고른 정확 품번명

// ─────────────────────────────
// WHERE 절 구성 (컷오프 08:30 적용)
// ─────────────────────────────
$whereParts = [];
$params     = [];

// 컷오프 시간
$cutoffTime = '08:30:00';

if ($fromDate !== '' && $toDate !== '') {
    // 예: 12-09 ~ 12-09  → 12-08 08:30 ~ 12-09 08:30
    $fromTs = strtotime($fromDate . ' ' . $cutoffTime . ' -1 day');
    $toTs   = strtotime($toDate   . ' ' . $cutoffTime);

    $fromDt = date('Y-m-d H:i:s', $fromTs);
    $toDt   = date('Y-m-d H:i:s', $toTs);

    $whereParts[]        = 'ship_datetime >= :from_dt';
    $params[':from_dt']  = $fromDt;

    // 끝은 "< to_dt" 로 (08:30:00 미만)
    $whereParts[]        = 'ship_datetime < :to_dt';
    $params[':to_dt']    = $toDt;
} else {
    // 혹시 한쪽만 들어오는 경우를 위한 안전장치 (기본 00~23:59 방식)
    if ($fromDate !== '') {
        $whereParts[]         = 'ship_datetime >= :from_dt';
        $params[':from_dt']   = $fromDate . ' 00:00:00';
    }
    if ($toDate !== '') {
        $whereParts[]         = 'ship_datetime <= :to_dt';
        $params[':to_dt']     = $toDate . ' 23:59:59';
    }
}

if ($filterShipTo !== '') {
    $whereParts[]         = 'ship_to LIKE :ship_to';
    $params[':ship_to']   = '%' . $filterShipTo . '%';
}
if ($filterPackBc !== '') {
    $whereParts[]         = 'pack_barcode LIKE :pack_bc';
    $params[':pack_bc']   = '%' . $filterPackBc . '%';
}
if ($filterSmallNo !== '') {
    $whereParts[]         = 'small_pack_no LIKE :small_no';
    $params[':small_no']  = '%' . $filterSmallNo . '%';
}
if ($filterTrayNo !== '') {
    $whereParts[]         = 'tray_no LIKE :tray_no';
    $params[':tray_no']   = '%' . $filterTrayNo . '%';
}
if ($filterPnameLike !== '') {
    $whereParts[]             = 'part_name LIKE :part_name';
    $params[':part_name']     = '%' . $filterPnameLike . '%';
}

// base 조건 백업 (품번명 드롭다운 만들 때 사용)
$baseWhereParts = $whereParts;
$baseParams     = $params;

// ─────────────────────────────
// 1) 품번명 드롭다운용 DISTINCT 리스트
// ─────────────────────────────
$pnWhereParts   = $baseWhereParts;
$pnWhereParts[] = "part_name IS NOT NULL AND part_name <> ''";
$pnWhereSql     = 'WHERE ' . implode(' AND ', $pnWhereParts);

$sql = "
    SELECT DISTINCT part_name
    FROM ShipingList
    {$pnWhereSql}
    ORDER BY part_name
";
$stmt = $pdo->prepare($sql);
$stmt->execute($baseParams);
$partNameList = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ─────────────────────────────
// 2) 실제 집계용 WHERE : 품번명 exact 추가
// ─────────────────────────────
if ($filterPartExact !== '') {
    $whereParts[]               = 'part_name = :part_name_exact';
    $params[':part_name_exact'] = $filterPartExact;
}

$whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// ─────────────────────────────
// 3) 생산일자 목록 (컬럼 헤더용, prod_date 기준)
// ─────────────────────────────
$sql = "
    SELECT DISTINCT prod_date
    FROM ShipingList
    {$whereSql}
    ORDER BY prod_date
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dateRows = $stmt->fetchAll(PDO::FETCH_COLUMN);
$dates = $dateRows ?: [];

// ─────────────────────────────
// 4) 품번명(제품명) + 툴(차수) / Cavity / 생산일자별 수량 집계
//    정렬: 제품명 → 툴 → Cavity
// ─────────────────────────────
$sql = "
    SELECT
        part_name,
        revision,
        cavity,
        prod_date,
        SUM(qty) AS total_qty
    FROM ShipingList
    {$whereSql}
    GROUP BY part_name, revision, cavity, prod_date
    ORDER BY part_name, revision, cavity, prod_date
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// [제품명][툴][cavity]['total' / 'by_date'][prod_date]
$data = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $partName = $row['part_name'];
    $tool     = $row['revision'];      // 툴(차수)
    $cavity   = (string)$row['cavity'];
    if ($cavity === '' || $cavity === null) {
        $cavity = '-';
    }
    $prodDate = $row['prod_date'];
    $qty      = (int)$row['total_qty'];

    if (!isset($data[$partName])) {
        $data[$partName] = [];
    }
    if (!isset($data[$partName][$tool])) {
        $data[$partName][$tool] = [
            'cavities' => []
        ];
    }
    if (!isset($data[$partName][$tool]['cavities'][$cavity])) {
        $data[$partName][$tool]['cavities'][$cavity] = [
            'total'   => 0,
            'by_date' => [],
        ];
    }

    $data[$partName][$tool]['cavities'][$cavity]['by_date'][$prodDate] = $qty;
    $data[$partName][$tool]['cavities'][$cavity]['total']             += $qty;
}

// 정렬: 제품명 → 툴 → Cavity
ksort($data);
foreach ($data as $pn => &$toolArr) {
    ksort($toolArr);
    foreach ($toolArr as $tool => &$cavArr) {
        $cavs = $cavArr['cavities'];
        ksort($cavs, SORT_NATURAL);
        $cavArr['cavities'] = $cavs;
    }
}
unset($toolArr, $cavArr);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>Tool &amp; Cavity별 출하 수량 (생산일자 기준)</title>
    <style>
        body {
            margin:0;
            padding:10px 16px 20px;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:#202124;
            color:#e8eaed;
        }
        h1 {
            font-size:18px;
            margin:0 0 4px;
        }
        .sub {
            font-size:11px;
            color:#9aa0a6;
            margin-bottom:6px;
        }
        .model-form {
            margin:0 0 10px;
            font-size:12px;
            display:flex;
            gap:10px;
            align-items:center;
        }
        .model-form label {
            margin-right:4px;
        }
        .model-form select {
            font-size:12px;
            padding:3px 6px;
            border-radius:4px;
            border:1px solid #5f6368;
            background:#303134;
            color:#e8eaed;
        }
        .model-form select:focus {
            outline:none;
            border-color:#8ab4f8;
        }

        .table-wrap {
            border:1px solid #3c4043;
            border-radius:10px;
            overflow:auto;
            background:#2b2b2b;
        }
        table {
            border-collapse:collapse;
            font-size:11px;
            min-width:1600px;
            width:100%;
        }
        thead {
            background:#3b4738;
        }
        th, td {
            border:1px solid #50634b;
            padding:4px 6px;
            white-space:nowrap;
            text-align:center;
        }
        th {
            font-weight:600;
        }
        tbody tr:nth-child(even) {
            background:#262729;
        }
        tbody tr:nth-child(odd) {
            background:#2b2b2b;
        }
        .num {
            text-align:right;
        }
        .ea {
            color:#c8e6c9;
        }
        .part-cell {
            background:#344530;
            font-weight:600;
        }
    </style>
</head>
<body>
<h1>Tool &amp; Cavity별 출하 수량 (생산일자 기준)</h1>
<div class="sub">
    조회기간(출하일자): <?= h($fromDate) ?> ~ <?= h($toDate) ?>
    <?php if ($filterPartExact !== ''): ?>
        &nbsp;|&nbsp; 품번명: <?= h($filterPartExact) ?>
    <?php elseif ($filterPnameLike !== ''): ?>
        &nbsp;|&nbsp; 품번명(검색): <?= h($filterPnameLike) ?>
    <?php endif; ?>
    <?php if ($filterShipTo !== ''): ?>
        &nbsp;|&nbsp; 납품처: <?= h($filterShipTo) ?>
    <?php endif; ?>
</div>

<!-- 품번명 선택창 -->
<form method="get" class="model-form">
    <!-- 기존 필터 유지용 hidden -->
    <input type="hidden" name="from_date" value="<?= h($fromDate) ?>">
    <input type="hidden" name="to_date"   value="<?= h($toDate) ?>">
    <input type="hidden" name="ship_to"   value="<?= h($filterShipTo) ?>">
    <input type="hidden" name="pack_bc"   value="<?= h($filterPackBc) ?>">
    <input type="hidden" name="small_no"  value="<?= h($filterSmallNo) ?>">
    <input type="hidden" name="tray_no"   value="<?= h($filterTrayNo) ?>">
    <input type="hidden" name="part_name" value="<?= h($filterPnameLike) ?>">

    <label for="part_name_exact">품번명</label>
    <select name="part_name_exact" id="part_name_exact" onchange="this.form.submit()">
        <option value="">전체</option>
        <?php foreach ($partNameList as $pn): ?>
            <option value="<?= h($pn) ?>" <?= ($filterPartExact === $pn ? 'selected' : '') ?>>
                <?= h($pn) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th rowspan="2">품번명 / 툴(차수)</th>
            <th rowspan="2">Cavity</th>
            <th rowspan="2">합계(EA)</th>
            <?php if (!empty($dates)): ?>
                <th colspan="<?= count($dates) ?>">생산일자별 수량 (EA)</th>
            <?php else: ?>
                <th>생산일자별 수량 (EA)</th>
            <?php endif; ?>
        </tr>
        <tr>
            <?php if (!empty($dates)): ?>
                <?php foreach ($dates as $d): ?>
                    <th><?= h($d) ?></th>
                <?php endforeach; ?>
            <?php else: ?>
                <th>-</th>
            <?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($data)): ?>
            <tr>
                <td colspan="<?= 3 + max(1, count($dates)) ?>" style="text-align:center; padding:12px 0;">
                    해당 조건의 출하 데이터가 없습니다.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($data as $partName => $toolArr): ?>
                <?php foreach ($toolArr as $tool => $toolInfo): ?>
                    <?php
                    $cavities = $toolInfo['cavities'];
                    $rowspan  = count($cavities);
                    $first    = true;
                    ?>
                    <?php foreach ($cavities as $cavity => $cinfo): ?>
                        <tr>
                            <?php if ($first): ?>
                                <td class="part-cell" rowspan="<?= $rowspan ?>">
                                    <?= h($partName . ' ' . $tool) ?>
                                </td>
                                <?php $first = false; ?>
                            <?php endif; ?>

                            <td><?= h($cavity) ?></td>
                            <td class="num">
                                <?php if ($cinfo['total'] > 0): ?>
                                    <?= number_format($cinfo['total']) ?> <span class="ea">EA</span>
                                <?php endif; ?>
                            </td>

                            <?php if (!empty($dates)): ?>
                                <?php foreach ($dates as $d): ?>
                                    <?php $q = $cinfo['by_date'][$d] ?? 0; ?>
                                    <td class="num">
                                        <?php if ($q > 0): ?>
                                            <?= number_format($q) ?> <span class="ea">EA</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <td></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
