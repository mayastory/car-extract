<?php
require_once __DIR__ . '/common.php';

if (!function_exists('dp_level_icon_relpath_by_lv')) {
    function dp_level_icon_relpath_by_lv(?int $lv): string {
        $lv = (int)($lv ?? 0);
        if ($lv <= 0) return '';
        static $map = null;
        if ($map === null) {
            $map = [];
            $root = realpath(__DIR__ . '/..');
            $assets = $root ? ($root . DIRECTORY_SEPARATOR . 'assets') : (__DIR__ . '/../assets');
            if (is_dir($assets)) {
                try {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        if (!$f->isFile()) continue;
                        $ext = strtolower((string)$f->getExtension());
                        if (!in_array($ext, ['gif','png','jpg','jpeg','webp','svg'], true)) continue;
                        $base = strtolower((string)$f->getBasename('.' . $ext));
                        if (!preg_match('/(\d{2})(?!.*\d)/', $base, $m)) continue;
                        $n = (int)$m[1];
                        if ($n <= 0) continue;
                        $full = str_replace('\\', '/', (string)$f->getPathname());
                        $rel = $root ? ltrim(str_replace('\\', '/', substr($full, strlen(str_replace('\\', '/', $root)))), '/') : '';
                        if ($rel === '' || strpos($rel, 'assets/') !== 0) continue;
                        $hay = strtolower($rel . ' ' . $base);
                        $score = 0;
                        if (strpos($hay, 'level') !== false) $score += 100;
                        if (strpos($hay, 'icon')  !== false) $score += 40;
                        if (preg_match('/(^|[^a-z])lv\d{2}$/', $base)) $score += 20;
                        $score -= strlen($rel) / 1000.0;
                        if (!isset($map[$n]) || $score > $map[$n]['score']) {
                            $map[$n] = ['rel' => $rel, 'score' => $score];
                        }
                    }
                } catch (Throwable $e) {
                    // best effort
                }
            }
        }
        return (string)($map[$lv]['rel'] ?? '');
    }
}

if (!function_exists('dp_level_icon_url_by_lv')) {
    function dp_level_icon_url_by_lv(?int $lv): string {
        $rel = dp_level_icon_relpath_by_lv($lv);
        return $rel !== '' ? dp_url($rel) : '';
    }
}

if (!function_exists('dp_account_label_meta')) {
    function dp_account_label_meta(PDO $pdo, ?string $raw): array {
        $raw = trim((string)($raw ?? ''));
        if ($raw === '') return ['display' => '', 'lv' => null, 'id' => '', 'name' => ''];
        static $cache = [];
        if (array_key_exists($raw, $cache)) return $cache[$raw];
        $meta = ['display' => $raw, 'lv' => null, 'id' => '', 'name' => ''];
        try {
            $st = $pdo->prepare("SELECT `ID`,`NAME`,`lv` FROM `account` WHERE `ID` = :v OR `NAME` = :v LIMIT 1");
            $st->execute([':v' => $raw]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $id = trim((string)($row['ID'] ?? ''));
                $name = trim((string)($row['NAME'] ?? ''));
                $meta['id'] = $id;
                $meta['name'] = $name;
                $meta['display'] = ($name !== '' ? $name : ($id !== '' ? $id : $raw));
                $meta['lv'] = isset($row['lv']) && $row['lv'] !== '' ? (int)$row['lv'] : null;
            }
        } catch (Throwable $e) {
            // best effort
        }
        return $cache[$raw] = $meta;
    }
}

if (!function_exists('dp_render_level_identity_html')) {
    function dp_render_level_identity_html(string $label, ?int $lv = null, array $opt = []): string {
        $label = trim($label);
        if ($label === '') return '';
        $iconUrl = dp_level_icon_url_by_lv($lv);
        $wrapClass = trim((string)($opt['class'] ?? 'dp-level-identity'));
        $labelClass = trim((string)($opt['label_class'] ?? 'dp-level-identity-label'));
        $size = max(12, (int)($opt['size'] ?? 16));
        $gap = max(2, (int)($opt['gap'] ?? 4));
        $img = '';
        if ($iconUrl !== '') {
            $img = '<img src="' . h($iconUrl) . '" alt="lv' . h(str_pad((string)((int)$lv), 2, '0', STR_PAD_LEFT)) . '" '
                 . 'style="width:' . $size . 'px;height:' . $size . 'px;display:block;flex:0 0 auto;object-fit:contain;image-rendering:pixelated;">';
        }
        return '<span class="' . h($wrapClass) . '" style="display:inline-flex;align-items:center;gap:' . $gap . 'px;vertical-align:middle;min-width:0;">'
             . $img
             . '<span class="' . h($labelClass) . '">' . h($label) . '</span>'
             . '</span>';
    }
}

if (!function_exists('dp_render_account_label_html')) {
    function dp_render_account_label_html(PDO $pdo, ?string $raw, array $opt = []): string {
        $meta = dp_account_label_meta($pdo, $raw);
        return dp_render_level_identity_html((string)($meta['display'] ?? ''), $meta['lv'] ?? null, $opt);
    }
}
