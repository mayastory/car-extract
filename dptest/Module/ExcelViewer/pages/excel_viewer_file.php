<?php
// excel_viewer_file.php
// ✅ 단일 Excel 파일을 브라우저에서 표로 보기 (시트 탭 포함)

declare(strict_types=1);
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }


@date_default_timezone_set('Asia/Seoul');
session_start();

require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();
require_once JTMES_ROOT . '/vendor/autoload.php';
require_once JTMES_ROOT . '/lib/excel_viewer_lib.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$p = (string)($_GET['p'] ?? '');
if ($p === '') {
    http_response_code(400);
    die('missing p');
}

$resolved = ev_resolve_path($p);
if (!$resolved['ok']) {
    http_response_code(404);
    die('invalid path');
}

$file = $resolved['abs'];
if (!is_file($file) || !ev_is_excel_file($file)) {
    http_response_code(404);
    die('file not found');
}

$sheetIdx = (int)($_GET['s'] ?? 0);
$sheetName = (string)($_GET['sheet'] ?? '');

// 렌더 제한 (너무 큰 시트는 브라우저가 죽음)
$maxRows = max(1, (int)($_GET['rows'] ?? 250));
$maxCols = max(1, (int)($_GET['cols'] ?? 80));
$mode    = (string)($_GET['mode'] ?? 'data'); // data | html

try {
    $reader = IOFactory::createReaderForFile($file);
    // ✅ 기본은 빠른 데이터 모드
    if (method_exists($reader, 'setReadDataOnly')) {
        $reader->setReadDataOnly($mode !== 'html');
    }
    // 수식 계산 비용 줄이기
    if (method_exists($reader, 'setPreCalculateFormulas')) {
        $reader->setPreCalculateFormulas(false);
    }

    $spreadsheet = $reader->load($file);
} catch (Throwable $e) {
    http_response_code(500);
    die('엑셀 로드 실패: ' . ev_h($e->getMessage()));
}

$sheetCount = $spreadsheet->getSheetCount();
if ($sheetName !== '') {
    $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName($sheetName));
    if (is_int($idx)) $sheetIdx = $idx;
}
if ($sheetIdx < 0) $sheetIdx = 0;
if ($sheetIdx >= $sheetCount) $sheetIdx = $sheetCount - 1;

$sheet = $spreadsheet->getSheet($sheetIdx);

// 데이터 범위 계산
$highestRow = (int)$sheet->getHighestDataRow();
$highestColStr = (string)$sheet->getHighestDataColumn();
$highestCol = max(1, Coordinate::columnIndexFromString($highestColStr));

$useRows = min($highestRow, $maxRows);
$useCols = min($highestCol, $maxCols);
$endColStr = Coordinate::stringFromColumnIndex($useCols);

// toArray로 빠르게
$range = 'A1:' . $endColStr . $useRows;
$grid = $sheet->rangeToArray($range, null, true, true, true);

// 시트 목록
$sheets = [];
for ($i = 0; $i < $sheetCount; $i++) {
    $sheets[] = $spreadsheet->getSheet($i)->getTitle();
}

// 파일 표시명
$basename = basename($file);
$relLabel = $resolved['rel'] ?? $basename;

?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= ev_h($basename) ?></title>
  <style>
    :root{ --bg:#0f1115; --panel:#151923; --panel2:#0b0d12; --line:#2a3242; --txt:#e7e9ee; --muted:#9aa3b2; --accent:#2d6cdf; }
    html,body{height:100%;}
    body{margin:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Apple SD Gothic Neo","Malgun Gothic",sans-serif;}
    .top{position:sticky;top:0;z-index:10;background:rgba(15,17,21,.92);backdrop-filter: blur(8px);border-bottom:1px solid var(--line);}
    .bar{display:flex;gap:10px;align-items:center;padding:10px 12px;}
    .title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:40vw;}
    .meta{font-size:12px;color:var(--muted);}
    .tabs{display:flex;gap:6px;flex-wrap:wrap;padding:0 12px 10px 12px;}
    .tab{border:1px solid var(--line);background:var(--panel);color:var(--txt);padding:6px 10px;border-radius:10px;font-size:12px;text-decoration:none;}
    .tab.active{border-color:var(--accent);}
    .controls{margin-left:auto;display:flex;gap:6px;align-items:center;}
    .pill{border:1px solid var(--line);background:var(--panel);color:var(--txt);padding:6px 10px;border-radius:10px;font-size:12px;text-decoration:none;}
    .pill:hover,.tab:hover{filter:brightness(1.08)}
    .wrap{padding:12px;}
    .hint{font-size:12px;color:var(--muted);margin:6px 0 12px;}
    .gridWrap{overflow:auto;border:1px solid var(--line);border-radius:12px;background:var(--panel2);}
    table{border-collapse:collapse;width:max-content;min-width:100%;}
    th,td{border:1px solid var(--line);padding:4px 6px;font-size:12px;white-space:nowrap;}
    th{position:sticky;top:0;background:#111523;z-index:2;}
    td.rowh{position:sticky;left:0;background:#111523;z-index:1;color:var(--muted);text-align:right;}
    th.corner{position:sticky;left:0;z-index:3;background:#111523;}
    .muted{color:var(--muted)}
  </style>
</head>
<body>

<div class="top">
  <div class="bar">
    <div>
      <div class="title"><?= ev_h($relLabel) ?></div>
      <div class="meta">sheet <?= ($sheetIdx+1) ?>/<?= $sheetCount ?> · range <?= ev_h($range) ?> (표시는 rows=<?= (int)$maxRows ?>, cols=<?= (int)$maxCols ?> 제한)</div>
    </div>
    <div class="controls">
      <?php
        $base = 'excel_viewer_file.php?p=' . rawurlencode($p) . '&s=' . $sheetIdx . '&rows=' . $maxRows . '&cols=' . $maxCols;
        $modeUrl = 'excel_viewer_file.php?p=' . rawurlencode($p) . '&s=' . $sheetIdx . '&rows=' . $maxRows . '&cols=' . $maxCols;
      ?>
      <a class="pill" href="<?= ev_h($modeUrl . '&mode=data') ?>">빠르게(데이터)</a>
      <a class="pill" href="<?= ev_h($modeUrl . '&mode=html') ?>">느리지만(서식)</a>
      <a class="pill" href="<?= ev_h($base . '&rows=1000&cols=160&mode=' . ev_h($mode)) ?>">더 크게</a>
    </div>
  </div>

  <div class="tabs">
    <?php foreach ($sheets as $i => $nm):
      $u = 'excel_viewer_file.php?p=' . rawurlencode($p) . '&s=' . $i . '&rows=' . $maxRows . '&cols=' . $maxCols . '&mode=' . rawurlencode($mode);
    ?>
      <a class="tab <?= ($i===$sheetIdx?'active':'') ?>" href="<?= ev_h($u) ?>"><?= ev_h($nm) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="wrap">
  <div class="hint">
    · 너무 큰 시트는 브라우저가 느려질 수 있어서 기본으로 일부만 보여줘. (위 “더 크게” 버튼)
    <span class="muted">· 완전 엑셀과 동일한 색/병합/도형은 웹에서 100% 재현이 어렵고, 일단 “값 중심”으로 보는 뷰어야.</span>
  </div>

  <div class="gridWrap">
    <table>
      <thead>
        <tr>
          <th class="corner">#</th>
          <?php for ($c=1; $c<=$useCols; $c++):
            $colName = Coordinate::stringFromColumnIndex($c);
          ?>
            <th><?= ev_h($colName) ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php
          $r = 0;
          foreach ($grid as $rowIdx => $row) {
            $r++;
            echo '<tr>';
            echo '<td class="rowh">' . (int)$rowIdx . '</td>';
            for ($c=1; $c<=$useCols; $c++) {
              $colName = Coordinate::stringFromColumnIndex($c);
              $val = $row[$colName] ?? '';
              // 값이 배열이면(드문 케이스) 문자열로
              if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
              echo '<td>' . ev_h((string)$val) . '</td>';
            }
            echo '</tr>';
          }
        ?>
      </tbody>
    </table>
  </div>
</div>

</body>
</html>
