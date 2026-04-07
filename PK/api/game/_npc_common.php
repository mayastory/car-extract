<?php
// api/game/_npc_common.php
// rAthena-style NPC script scanner (minimal subset)
//
// Features:
// - Scans script/npc/{common,fr,lg}/ (ignores other paths)
// - Extracts NPC placements (map,x,y,dir,type,name,sprite,sprite_key)
// - For 'script' NPCs, captures the {...} body as plain text
// - For 'shop' NPCs, captures item tokens after the sprite id
//
// Header formats supported:
//   PalletTown,8,12,0 script Guide 0,{
//   PalletTown,8,12,0 boy script Guide 0,{   // sprite_key = boy
//
// NOTE: sprite_key is an asset key for client rendering (e.g. "boy").
//       sprite (numeric) is kept for compatibility.

function npc_root_dir(): string {
  $p = realpath(__DIR__ . '/../../script');
  if ($p && is_dir($p)) {
    if (is_dir($p . '/npc') || is_dir($p . '/map')) return $p;
  }
  return __DIR__ . '/../../script';
}

function npc_cache_path(): string {
  $p = realpath(__DIR__ . '/../../cache');
  if (!$p) $p = __DIR__ . '/../../cache';
  $dir = $p . '/npc';
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir . '/npc_index.json';
}

function npc_pick_scan_base(string $root): string {
  $npc = rtrim($root, '/\\') . '/npc';
  if (is_dir($npc)) return $npc;
  return $root;
}

function npc_is_allowed_rel(string $rel): bool {
  $rel = str_replace('\\', '/', $rel);
  return (bool)preg_match('~^(common|fr|lg)/~', $rel);
}

function npc_only_game_ver_from_rel(string $rel): int {
  $rel = str_replace('\\', '/', $rel);
  if (stripos($rel, 'fr/') === 0) return 1;
  if (stripos($rel, 'lg/') === 0) return 2;
  return 0; // common
}

function npc_scan_signature(string $base): array {
  $count = 0;
  $maxM = 0;

  $baseReal = realpath($base);
  if (!$baseReal) $baseReal = $base;

  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    if ($ext !== 'txt' && $ext !== 'npc') continue;

    $abs = $f->getRealPath();
    if (!$abs) continue;
    $rel = str_replace('\\','/', substr($abs, strlen($baseReal) + 1));
    if (!npc_is_allowed_rel($rel)) continue;

    $count++;
    $m = $f->getMTime();
    if ($m > $maxM) $maxM = $m;
  }

  return ['count'=>$count, 'max_mtime'=>$maxM];
}

function npc_strip_line_comment(string $line): string {
  // Remove // comments (best-effort). Keep URLs like http:// by only stripping if // is preceded by whitespace or start.
  $p = preg_match('/(^|\s)\/\//', $line, $m, PREG_OFFSET_CAPTURE);
  if ($p && isset($m[0][1])) {
    $pos = $m[0][1];
    $cut = strpos($line, '//', $pos);
    if ($cut !== false) return substr($line, 0, $cut);
  }
  return $line;
}

function npc_parse_header(string $line): ?array {
  $line = trim(npc_strip_line_comment($line));
  if ($line === '') return null;

  if (!preg_match('/^([^,]+)\s*,\s*(-?\d+)\s*,\s*(-?\d+)\s*,\s*(-?\d+)\s+(.+)$/', $line, $m)) return null;
  $map = trim($m[1]);
  $x = intval($m[2], 10);
  $y = intval($m[3], 10);
  $dir = intval($m[4], 10);
  $rest = trim($m[5]);

  $sprite_key = '';
  $type = '';
  $tail = '';

  // 1) Standard: "script ..." or "shop ..."
  if (preg_match('/^(script|shop)\s+(.+)$/i', $rest, $m2)) {
    $type = strtolower($m2[1]);
    $tail = trim($m2[2]);
  }
  // 2) Extended: "<sprite_key> script ..." or "<sprite_key> shop ..."
  else if (preg_match('/^(\S+)\s+(script|shop)\s+(.+)$/i', $rest, $m2)) {
    $sprite_key = trim($m2[1]);
    $type = strtolower($m2[2]);
    $tail = trim($m2[3]);
  } else {
    return null;
  }

  if ($type === 'script') {
    // find "<sprite>[,k=v...],{"
    // examples:
    //   PalletTown,3,10,0 boy script SignLady 1,{
    //   PalletTown,3,10,0 boy script Oak 1,hide_if_flag=FLAG_HIDE_OAK_IN_PALLET_TOWN,{
    if (!preg_match('/\s(-?\d+)\s*((?:,\s*[^,{]+)*)\s*,\s*\{/', $tail, $m3, PREG_OFFSET_CAPTURE)) return null;
    $sprite = intval($m3[1][0], 10);
    $extrasRaw = (string)($m3[2][0] ?? '');
    $extras = [];
    if ($extrasRaw !== '') {
      foreach (explode(',', $extrasRaw) as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        if (strpos($tok, '=') !== false) {
          [$k,$v] = explode('=', $tok, 2);
          $k = trim($k);
          $v = trim($v);
          if ($k !== '') $extras[$k] = $v;
        }
      }
    }
    $name = trim(substr($tail, 0, $m3[0][1])); // up to " <sprite>[...],,{"
    $after = substr($tail, $m3[0][1] + strlen($m3[0][0])); // after "{"
    return ['map'=>$map,'x'=>$x,'y'=>$y,'dir'=>$dir,'type'=>$type,'name'=>$name,'sprite'=>$sprite,'sprite_key'=>$sprite_key,'extras'=>$extras,'after_brace'=>$after];
  }

  if ($type === 'shop') {
    // find "<sprite>,"
    if (!preg_match('/\s(-?\d+)\s*,\s*/', $tail, $m3, PREG_OFFSET_CAPTURE)) return null;
    $sprite = intval($m3[1][0], 10);
    $name = trim(substr($tail, 0, $m3[0][1]));
    $after = substr($tail, $m3[0][1] + strlen($m3[0][0])); // after comma
    return ['map'=>$map,'x'=>$x,'y'=>$y,'dir'=>$dir,'type'=>$type,'name'=>$name,'sprite'=>$sprite,'sprite_key'=>$sprite_key,'after_comma'=>$after];
  }

  return null;
}

function npc_count_braces(string $s): int {
  $open = substr_count($s, '{');
  $close = substr_count($s, '}');
  return $open - $close;
}

function npc_scan_all(bool $force=false): array {
  $root = npc_root_dir();
  if (!is_dir($root)) return ['signature'=>['count'=>0,'max_mtime'=>0], 'npcs'=>[], 'base'=>$root, 'note'=>'npc dir missing'];

  $base = npc_pick_scan_base($root);
  $sig = npc_scan_signature($base);
  $cachePath = npc_cache_path();

  $baseReal = realpath($base);
  if (!$baseReal) $baseReal = $base;

  if (!$force && is_file($cachePath)) {
    $raw = @file_get_contents($cachePath);
    if ($raw !== false) {
      $j = json_decode($raw, true);
      if (is_array($j) && isset($j['signature']) && is_array($j['signature'])) {
        $cs = $j['signature'];
        if (($cs['count'] ?? null) === $sig['count'] && ($cs['max_mtime'] ?? null) === $sig['max_mtime'] && (($j['base'] ?? null) === $base)) {
          return $j;
        }
      }
    }
  }

  $out = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    if ($ext !== 'txt' && $ext !== 'npc') continue;

    $abs = $f->getRealPath();
    if (!$abs) continue;

    $rel = str_replace('\\','/', substr($abs, strlen($baseReal) + 1));
    if (!npc_is_allowed_rel($rel)) continue;

    $lines = @file($abs, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) continue;

    $only_ver = npc_only_game_ver_from_rel($rel);

    $n = count($lines);
    for ($i=0; $i<$n; $i++) {
      $hdr = npc_parse_header($lines[$i]);
      if (!$hdr) continue;

      $npc = [
        'id' => sha1($rel . '|' . $i . '|' . $hdr['map'] . '|' . $hdr['x'] . '|' . $hdr['y'] . '|' . $hdr['name']),
        'map' => $hdr['map'],
        'x' => $hdr['x'],
        'y' => $hdr['y'],
        'dir' => $hdr['dir'],
        'type' => $hdr['type'],
        'name' => $hdr['name'],
        'sprite' => $hdr['sprite'],
        'sprite_key' => (string)($hdr['sprite_key'] ?? ''),
        'only_game_ver' => $only_ver,
        'source' => $rel,
        'line' => $i + 1,
      ];

      // Optional header extras embedded after sprite id: "1,hide_if_flag=FLAG_X,{"
      if (isset($hdr['extras']) && is_array($hdr['extras'])) {
        foreach ($hdr['extras'] as $k=>$v) {
          $k = trim((string)$k);
          if ($k === '') continue;
          $npc[$k] = (string)$v;
        }
      }

      if ($hdr['type'] === 'shop') {
        $itemsRaw = trim($hdr['after_comma'] ?? '');
        $items = [];
        if ($itemsRaw !== '') {
          foreach (explode(',', $itemsRaw) as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            $items[] = $tok;
          }
        }
        $npc['items'] = $items;
        $out[] = $npc;
        continue;
      }

      // script: capture block body until matching }
      $body = '';
      $depth = 1;
      $firstAfter = (string)($hdr['after_brace'] ?? '');
      if (trim($firstAfter) !== '') {
        $body .= $firstAfter . "\n";
        $depth += npc_count_braces($firstAfter);
      }

      $j = $i + 1;
      while ($j < $n && $depth > 0) {
        $line = $lines[$j];
        $depth += npc_count_braces($line);
        if ($depth <= 0) {
          $pos = strrpos($line, '}');
          if ($pos !== false) {
            $keep = substr($line, 0, $pos);
            if (trim($keep) !== '') $body .= $keep . "\n";
          }
          break;
        } else {
          $body .= $line . "\n";
        }
        $j++;
      }

      $npc['body'] = $body;
      $out[] = $npc;

      // move index to end of block to avoid re-parsing inside
      $i = $j;
    }
  }

  $payload = [
    'signature' => $sig,
    'base' => $base,
    'npcs' => $out,
    'generated_at' => date('c'),
  ];
  @file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return $payload;
}

function npc_list_for_map(string $mapId, bool $force=false): array {
  $idx = npc_scan_all($force);
  $mapId = trim($mapId);
  $res = [];
  if (!isset($idx['npcs']) || !is_array($idx['npcs'])) return $res;
  foreach ($idx['npcs'] as $n) {
    if (!is_array($n)) continue;
    if (($n['map'] ?? '') === $mapId) $res[] = $n;
  }
  return $res;
}
