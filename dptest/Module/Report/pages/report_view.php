<?php
// report_view.php
// ✅ 발행내역 1건(report_finish.id) 전용 Excel Viewer (A안)
// - exports/reports/rf_{id}/ 아래 파일만 보여줌
// - 왼쪽은 "디렉토리 트리" UI(발행건만)로 고정 표시

declare(strict_types=1);
// [modules-refactor] JTMES_ROOT for relocated pages
if (!defined('JTMES_ROOT')) { define('JTMES_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)); }


@date_default_timezone_set('Asia/Seoul');
session_start();

require_once JTMES_ROOT . '/config/dp_config.php';
require_once JTMES_ROOT . '/lib/auth_guard.php';
dp_auth_guard();
require_once JTMES_ROOT . '/lib/oqc_report.php';

$sn = $_SERVER['SCRIPT_NAME'] ?? '';
$seg = explode('/', trim($sn, '/'));
$app = $seg[0] ?? '';
$APP_BASE = $app !== '' ? '/' . $app : '';


function rv_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('missing id'); }

$baseRel  = report_files_rel($id);
$baseAbs  = report_files_abs($id);
$baseReal = realpath($baseAbs);

function build_tree_from_dir(string $baseReal): array {
  $tree = ['_dirs'=>[], '_files'=>[]];

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseReal, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($it as $info) {
    /** @var SplFileInfo $info */
    if ($info->isDir()) continue;
    $abs = $info->getPathname();
    $rel = substr($abs, strlen($baseReal) + 1);
    $rel = str_replace('\\', '/', $rel);
    // 숨김/메타 제외(원하면 여기에서 더 추가)
    if ($rel === 'meta.json') continue;

    $parts = array_values(array_filter(explode('/', $rel), fn($p)=>$p!=='' && $p!=='.'));
    if (!$parts) continue;

    $node =& $tree;
    $pathAcc = '';
    for ($i=0; $i<count($parts); $i++) {
      $p = $parts[$i];
      $isLast = ($i === count($parts)-1);
      if ($isLast) {
        $node['_files'][$p] = $rel; // name => relpath
      } else {
        if (!isset($node['_dirs'][$p])) $node['_dirs'][$p] = ['_dirs'=>[], '_files'=>[]];
        $node =& $node['_dirs'][$p];
      }
    }
    unset($node);
  }

  // 정렬(폴더/파일)
  $sortTree = function(array &$n) use (&$sortTree) {
    if (isset($n['_dirs'])) { ksort($n['_dirs'], SORT_NATURAL|SORT_FLAG_CASE); foreach ($n['_dirs'] as &$d) $sortTree($d); }
    if (isset($n['_files'])) { ksort($n['_files'], SORT_NATURAL|SORT_FLAG_CASE); }
  };
  $sortTree($tree);

  return $tree;
}

$tree = ($baseReal && is_dir($baseReal)) ? build_tree_from_dir($baseReal) : null;

// 첫 파일(자동 오픈)
$firstFile = '';
if ($tree) {
  $stack = [$tree];
  $pathStack = [''];
  // DFS로 첫 file 찾기(정렬된 상태)
  $findFirst = function($node, $prefix) use (&$findFirst, &$firstFile) {
    if ($firstFile) return;
    if (!empty($node['_files'])) {
      foreach ($node['_files'] as $name=>$rel) { $firstFile = $rel; return; }
    }
    if (!empty($node['_dirs'])) {
      foreach ($node['_dirs'] as $dname=>$child) { $findFirst($child, $prefix.$dname.'/'); if ($firstFile) return; }
    }
  };
  $findFirst($tree, '');
}

function node_id(string $path): string { return 'n_' . substr(md5($path), 0, 10); }

function render_folder(string $label, string $path, array $node, int $depth, bool $open=true, bool $wrapper=false): void {
  $id = node_id($path);
  $cls = 'node folder' . ($open ? '' : ' closed') . ($wrapper ? ' wrapnode' : '');
  $pm = $open ? '−' : '+';
  $pad = 8 + $depth * 16;
  $icon = $wrapper ? '🗂️' : '📁';
  ?>
  <li class="<?= $cls ?>" id="<?= rv_h($id) ?>">
    <div class="row" style="padding-left:<?= (int)$pad ?>px" onclick="toggleNode('<?= rv_h($id) ?>')">
      <span class="pm"><?= rv_h($pm) ?></span>
      <span class="emoji"><?= rv_h($icon) ?></span>
      <span class="name"><?= rv_h($label) ?></span>
    </div>
    <ul class="children">
      <?php
      // files first
      if (!empty($node['_files'])) {
        foreach ($node['_files'] as $fname=>$rel) {
          $fpad = 8 + ($depth+1)*16;
          ?>
          <li class="node file" data-rel="<?= rv_h($rel) ?>">
            <div class="row file-row" style="padding-left:<?= (int)$fpad ?>px" onclick="openFile('<?= rv_h($rel) ?>', this.parentNode)">
              <span class="sp"></span>
              <span class="emoji">📄</span>
              <span class="name"><?= rv_h($fname) ?></span>
            </div>
          </li>
          <?php
        }
      }
      // dirs
      if (!empty($node['_dirs'])) {
        foreach ($node['_dirs'] as $dname=>$child) {
          render_folder($dname, $path . $dname . '/', $child, $depth+1, true, false);
        }
      }
      ?>
    </ul>
  </li>
  <?php
}

?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>발행 결과 #<?= (int)$id ?></title>
<style>
  :root{
    --bg:#0b0d10;
    --panel:#0f1318;
    --line:#232a33;
    --muted:#9aa4b2;
    --text:#e7eef8;
    --accent:#3b82f6;
  }
  html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font:13px/1.35 system-ui,-apple-system,Segoe UI,Roboto,"Segoe UI Emoji","Segoe UI Symbol",Apple SD Gothic Neo,"Apple Color Emoji","Noto Color Emoji",Noto Sans KR,sans-serif;}
  .header{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);background:rgba(255,255,255,0.02);}
  .header .title{font-weight:700}
  .header .meta{margin-left:auto;color:var(--muted);font-size:12px}
  .layout{display:flex;height:calc(100% - 44px);}
  .sidebar{width:320px;min-width:260px;max-width:420px;border-right:1px solid var(--line);background:var(--panel);overflow:auto;}
  .content{flex:1;position:relative;}
  iframe.viewer{position:absolute;inset:0;width:100%;height:100%;border:0;background:#111;}
  .empty{padding:14px;color:var(--muted);}

  /* tree */
  .tree{padding:10px 6px 14px 6px;}
  ul{list-style:none;margin:0;padding:0;}
  .node{user-select:none;}
  .row{display:flex;align-items:center;gap:6px;height:28px;border-radius:8px;margin:2px 6px;cursor:default;}
  .row:hover{background:rgba(255,255,255,0.05);}
  .folder > .row{cursor:pointer;}
  .pm{
    width:14px;height:14px;display:inline-flex;align-items:center;justify-content:center;
    border:1px solid rgba(255,255,255,0.35);border-radius:2px;
    font-size:12px;line-height:1;background:rgba(0,0,0,0.25);color:var(--text);
    flex:0 0 14px;
  }
  .sp{width:14px;flex:0 0 14px;} /* 파일행에서 pm 자리 맞추기 */
  .emoji{width:14px;flex:0 0 14px;text-align:center;line-height:14px;font-size:14px;}
  .ico{width:14px;height:14px;flex:0 0 14px;border-radius:3px;background:rgba(255,255,255,0.12);position:relative;}
  .ico.folder::after{content:"";position:absolute;left:2px;top:3px;width:10px;height:8px;border:1px solid rgba(255,255,255,0.55);border-top-left-radius:2px;border-top-right-radius:2px;}
  .ico.file::after{content:"";position:absolute;left:3px;top:2px;width:8px;height:10px;border:1px solid rgba(255,255,255,0.55);border-radius:2px;}
  .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .children{margin:0;padding:0;}
  .folder.closed > .children{display:none;}
  .node.file.active > .row{background:rgba(59,130,246,0.22);outline:1px solid rgba(59,130,246,0.35);}
  .wrapnode > .row{font-weight:700;}
</style>
</head>
<body>
  <div class="header">
    <div class="title">발행 결과 보기 #<?= (int)$id ?></div>
    <div class="meta"><?= rv_h($baseRel) ?></div>
  </div>

  <div class="layout">
    <aside class="sidebar">
      <div class="tree">
        <?php if (!$baseReal || !is_dir($baseReal)): ?>
          <div class="empty">발행 결과 폴더가 없습니다. (취소/삭제되었거나 저장 실패)</div>
        <?php else: ?>
          <ul>
            <?php
              // wrapper: report_finish -> rf_{id} -> 실제 폴더 내용
              render_folder('report_finish', 'report_finish/', ['_dirs'=>['rf_'.$id=>$tree], '_files'=>[]], 0, true, true);
            ?>
          </ul>
        <?php endif; ?>
      </div>
    </aside>

    <main class="content">
      <iframe id="viewer" class="viewer" src=""></iframe>
    </main>
  </div>

<script>
  function toggleNode(id){
    var li = document.getElementById(id);
    if(!li) return;
    li.classList.toggle('closed');
    var pm = li.querySelector(':scope > .row .pm');
    if(pm) pm.textContent = li.classList.contains('closed') ? '+' : '−';
  }

  function openFile(rel, li){
    document.querySelectorAll('.node.file.active').forEach(function(n){ n.classList.remove('active'); });
    if(li && li.classList) li.classList.add('active');
    var url = 'report_view_file.php?id=<?= (int)$id ?>&f=' + encodeURIComponent(rel);
    document.getElementById('viewer').src = url;
  }

  // auto-open first file
  <?php if ($firstFile !== ''): ?>
    window.addEventListener('load', function(){
      var sel = document.querySelector('.node.file[data-rel="<?= rv_h($firstFile) ?>"]');
      if(sel) openFile('<?= rv_h($firstFile) ?>', sel);
      else openFile('<?= rv_h($firstFile) ?>', null);
    });
  <?php endif; ?>
</script>
</body>
</html>
