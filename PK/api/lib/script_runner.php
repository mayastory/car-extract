<?php
// api/lib/script_runner.php
// Minimal rAthena-like script runner for NPC bodies.
// Supported (initial):
//   mes "text"; next; close;
//   warp "Map", x, y;
//   getitem ITEM_TOKEN, qty;
//   delitem ITEM_TOKEN, qty;
//   countitem ITEM_TOKEN;   (stores into $last, also emits action for debug)
//   hasitem ITEM_TOKEN, qty; (stores into $last_bool, also emits action for debug)
//   setflag FLAG_NAME;
//   clearflag FLAG_NAME;
//   getflag FLAG_NAME;     (stores into $last)
//   flag FLAG_NAME;        (stores into $last_bool)
// Flow control (optional):
//   label:
//   goto label;
//   ifhasitem ITEM_TOKEN, qty goto label;
//   ifcountitem ITEM_TOKEN, >=, 1 goto label;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/item_runtime.php';
require_once __DIR__ . '/flag_runtime.php';

function sr_strip_comments(string $line): string {
  // remove //... (best-effort)
  $p = preg_match('/(^|\s)\/\//', $line, $m, PREG_OFFSET_CAPTURE);
  if ($p && isset($m[0][1])) {
    $pos = $m[0][1];
    $cut = strpos($line, '//', $pos);
    if ($cut !== false) return substr($line, 0, $cut);
  }
  return $line;
}

function sr_unquote(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (($s[0] === '"' && substr($s, -1) === '"') || ($s[0] === "'" && substr($s, -1) === "'")) {
    return substr($s, 1, -1);
  }
  return $s;
}

function sr_split_args(string $s): array {
  // Split by commas, but keep quoted strings intact.
  $out = [];
  $cur = '';
  $q = null;
  $n = strlen($s);
  for ($i=0; $i<$n; $i++) {
    $ch = $s[$i];
    if ($q !== null) {
      if ($ch === $q) {
        $q = null;
        $cur .= $ch;
      } else {
        $cur .= $ch;
      }
      continue;
    }
    if ($ch === '"' || $ch === "'") {
      $q = $ch;
      $cur .= $ch;
      continue;
    }
    if ($ch === ',') {
      $out[] = trim($cur);
      $cur = '';
      continue;
    }
    $cur .= $ch;
  }
  if (trim($cur) !== '' || !empty($out)) $out[] = trim($cur);

  // Fallback: if no commas, split by whitespace (but keep quoted)
  if (count($out) <= 1) {
    $toks = [];
    $cur = '';
    $q = null;
    for ($i=0; $i<$n; $i++) {
      $ch = $s[$i];
      if ($q !== null) {
        $cur .= $ch;
        if ($ch === $q) $q = null;
        continue;
      }
      if ($ch === '"' || $ch === "'") {
        $q = $ch;
        $cur .= $ch;
        continue;
      }
      if (ctype_space($ch)) {
        if (trim($cur) !== '') { $toks[] = trim($cur); $cur = ''; }
        continue;
      }
      $cur .= $ch;
    }
    if (trim($cur) !== '') $toks[] = trim($cur);
    if (count($toks) > 1) return $toks;
  }

  return $out;
}

function sr_is_label(string $line, ?string &$labelOut=null): bool {
  if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*$/', $line, $m)) {
    $labelOut = (string)$m[1];
    return true;
  }
  return false;
}

function script_run_body(mysqli $conn, int $player_id, string $body, array $opts = []): array {
  $maxSteps = isset($opts['max_steps']) ? max(50, (int)$opts['max_steps']) : 500;
  $applyWarp = isset($opts['apply_warp']) ? (bool)$opts['apply_warp'] : true;

  $linesRaw = preg_split("/\r\n|\n|\r/", $body);
  $lines = [];
  foreach ($linesRaw as $ln) {
    $ln = sr_strip_comments((string)$ln);
    $ln = trim($ln);
    if ($ln === '') continue;

    // allow multiple statements per line separated by ';'
    $parts = explode(';', $ln);
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === '') continue;
      $lines[] = $p;
    }
  }

  // label pass
  $labels = [];
  for ($i=0; $i<count($lines); $i++) {
    $lbl = null;
    if (sr_is_label($lines[$i], $lbl) && $lbl !== null) {
      $labels[$lbl] = $i;
    }
  }

  $dialogPages = [];
  $curPage = '';
  $dialogClosed = false;

  $actions = [];
  $vars = [
    'last' => 0,
    'last_bool' => false,
  ];

  $pc = 0;
  $steps = 0;
  while ($pc < count($lines) && $steps < $maxSteps) {
    $steps++;
    $line = $lines[$pc];

    // labels are no-ops
    $lbl = null;
    if (sr_is_label($line, $lbl)) { $pc++; continue; }

    // normalize
    $lineTrim = trim($line);

    // commands: keyword [args...]
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\b(.*)$/', $lineTrim, $m)) {
      $cmd = strtolower((string)$m[1]);
      $argStr = trim((string)$m[2]);

      // --- flow control
      if ($cmd === 'goto') {
        $a = sr_split_args($argStr);
        $to = isset($a[0]) ? trim($a[0]) : '';
        if ($to !== '' && isset($labels[$to])) { $pc = (int)$labels[$to] + 1; continue; }
        $actions[] = ['type'=>'warn','cmd'=>'goto','detail'=>'LABEL_NOT_FOUND','label'=>$to];
        $pc++; continue;
      }

      if ($cmd === 'ifhasitem') {
        // ifhasitem ITEM, QTY goto LABEL
        $a = sr_split_args($argStr);
        $itemTok = $a[0] ?? '';
        $qty = isset($a[1]) ? (int)trim($a[1]) : 1;
        $label = '';
        for ($k=0; $k<count($a); $k++) {
          if (strtolower((string)$a[$k]) === 'goto' && isset($a[$k+1])) { $label = trim((string)$a[$k+1]); break; }
        }
        if ($label === '' && isset($a[2]) && stripos((string)$a[2],'goto') === 0) {
          // tolerate "gotoLabel"
          $label = trim(substr((string)$a[2], 4));
        }
        $has = player_item_has($conn, $player_id, $itemTok, max(1,$qty));
        $vars['last_bool'] = $has;
        $actions[] = ['type'=>'cond','cond'=>'hasitem','item'=>$itemTok,'need'=>$qty,'result'=>$has];
        if ($has && $label !== '' && isset($labels[$label])) { $pc = (int)$labels[$label] + 1; continue; }
        $pc++; continue;
      }

      if ($cmd === 'ifcountitem') {
        // ifcountitem ITEM, OP, NUM goto LABEL
        $a = sr_split_args($argStr);
        $itemTok = $a[0] ?? '';
        $op = isset($a[1]) ? trim((string)$a[1]) : '>=';
        $num = isset($a[2]) ? (int)trim((string)$a[2]) : 1;

        $label = '';
        for ($k=0; $k<count($a); $k++) {
          if (strtolower((string)$a[$k]) === 'goto' && isset($a[$k+1])) { $label = trim((string)$a[$k+1]); break; }
        }

        $cnt = player_item_count($conn, $player_id, $itemTok);
        $vars['last'] = $cnt;
        $ok = false;
        switch ($op) {
          case '==': $ok = ($cnt == $num); break;
          case '!=': $ok = ($cnt != $num); break;
          case '>=': $ok = ($cnt >= $num); break;
          case '<=': $ok = ($cnt <= $num); break;
          case '>':  $ok = ($cnt > $num); break;
          case '<':  $ok = ($cnt < $num); break;
          default:   $ok = ($cnt >= $num); break;
        }
        $actions[] = ['type'=>'cond','cond'=>'countitem','item'=>$itemTok,'op'=>$op,'num'=>$num,'count'=>$cnt,'result'=>$ok];
        if ($ok && $label !== '' && isset($labels[$label])) { $pc = (int)$labels[$label] + 1; continue; }
        $pc++; continue;
      }

      // --- dialog
      if ($cmd === 'mes') {
        // mes "text"
        $text = sr_unquote(trim($argStr));
        if ($curPage !== '') $curPage .= "\n";
        $curPage .= $text;
        $pc++; continue;
      }
      if ($cmd === 'next') {
        if (trim($curPage) !== '') $dialogPages[] = $curPage;
        $curPage = '';
        $pc++; continue;
      }
      if ($cmd === 'close' || $cmd === 'end') {
        if (trim($curPage) !== '') $dialogPages[] = $curPage;
        $curPage = '';
        $dialogClosed = true;
        $pc++; break;
      }

      // --- warp
      if ($cmd === 'warp') {
        // warp "Map", x, y
        $a = sr_split_args($argStr);
        $map = isset($a[0]) ? sr_unquote((string)$a[0]) : '';
        $x = isset($a[1]) ? (int)trim((string)$a[1]) : 0;
        $y = isset($a[2]) ? (int)trim((string)$a[2]) : 0;
        $actions[] = ['type'=>'warp','map'=>$map,'x'=>$x,'y'=>$y];

        if ($applyWarp && $map !== '' && $player_id > 0) {
          $stmt = $conn->prepare('UPDATE player SET map_id=?, x=?, y=?, updated_at=CURRENT_TIMESTAMP WHERE player_id=?');
          if ($stmt) {
            $stmt->bind_param('siii', $map, $x, $y, $player_id);
            $stmt->execute();
            $stmt->close();
          }
        }
        $pc++; continue;
      }

      // --- item ops
      if ($cmd === 'getitem') {
        $a = sr_split_args($argStr);
        $itemTok = $a[0] ?? '';
        $qty = isset($a[1]) ? (int)trim((string)$a[1]) : 1;
        $r = player_item_add($conn, $player_id, $itemTok, max(1,$qty));
        $actions[] = ['type'=>'getitem','item'=>$itemTok,'qty_delta'=>max(1,$qty),'result'=>$r];
        $pc++; continue;
      }
      if ($cmd === 'delitem') {
        $a = sr_split_args($argStr);
        $itemTok = $a[0] ?? '';
        $qty = isset($a[1]) ? (int)trim((string)$a[1]) : 1;
        $r = player_item_remove($conn, $player_id, $itemTok, max(1,$qty));
        $actions[] = ['type'=>'delitem','item'=>$itemTok,'qty_delta'=>max(1,$qty),'result'=>$r];
        $pc++; continue;
      }
      if ($cmd === 'countitem') {
        $a = sr_split_args($argStr);
        $itemTok = $a[0] ?? '';
        $cnt = player_item_count($conn, $player_id, $itemTok);
        $vars['last'] = $cnt;
        $actions[] = ['type'=>'countitem','item'=>$itemTok,'count'=>$cnt];
        $pc++; continue;
      }
      if ($cmd === 'hasitem') {
        $a = sr_split_args($argStr);
        $itemTok = $a[0] ?? '';
        $qty = isset($a[1]) ? (int)trim((string)$a[1]) : 1;
        $has = player_item_has($conn, $player_id, $itemTok, max(1,$qty));
        $vars['last_bool'] = $has;
        $actions[] = ['type'=>'hasitem','item'=>$itemTok,'need'=>max(1,$qty),'has'=>$has];
        $pc++; continue;
      }

      // --- flags (one-time events)
      if ($cmd === 'setflag') {
        $a = sr_split_args($argStr);
        $f = $a[0] ?? '';
        $ok = player_flag_set($conn, $player_id, $f, 1);
        $actions[] = ['type'=>'flag','op'=>'set','flag'=>flag_normalize((string)$f),'ok'=>$ok];
        $pc++; continue;
      }
      if ($cmd === 'clearflag') {
        $a = sr_split_args($argStr);
        $f = $a[0] ?? '';
        $ok = player_flag_clear($conn, $player_id, $f);
        $actions[] = ['type'=>'flag','op'=>'clear','flag'=>flag_normalize((string)$f),'ok'=>$ok];
        $pc++; continue;
      }
      if ($cmd === 'getflag') {
        $a = sr_split_args($argStr);
        $f = $a[0] ?? '';
        $v = player_flag_get($conn, $player_id, $f);
        $vars['last'] = (int)$v;
        $actions[] = ['type'=>'flag','op'=>'get','flag'=>flag_normalize((string)$f),'value'=>$v];
        $pc++; continue;
      }
      if ($cmd === 'flag') {
        $a = sr_split_args($argStr);
        $f = $a[0] ?? '';
        $v = player_flag_get($conn, $player_id, $f);
        $vars['last_bool'] = ($v != 0);
        $actions[] = ['type'=>'flag','op'=>'has','flag'=>flag_normalize((string)$f),'value'=>$v];
        $pc++; continue;
      }

      // Unknown command: keep for debug
      $actions[] = ['type'=>'unknown','line'=>$lineTrim];
      $pc++; continue;
    }

    $actions[] = ['type'=>'unknown','line'=>$lineTrim];
    $pc++;
  }

  if ($steps >= $maxSteps) {
    $actions[] = ['type'=>'warn','detail'=>'MAX_STEPS_REACHED','max_steps'=>$maxSteps];
  }

  if (trim($curPage) !== '') $dialogPages[] = $curPage;

  return [
    'ok' => true,
    'dialog' => [
      'pages' => $dialogPages,
      'closed' => $dialogClosed,
    ],
    'actions' => $actions,
    'vars' => $vars,
    'meta' => [
      'steps' => $steps,
      'lines' => count($lines),
      'max_steps' => $maxSteps,
    ],
  ];
}
