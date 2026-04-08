<?php
require_once __DIR__ . '/core/path.php';

$base = osx_base_path();
$initial = $OSX_INITIAL ?? ['appId' => 'notes', 'noteSlug' => 'about-me', 'filePath' => null];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>macOS Shell (PHP)</title>
  <link rel="icon" href="<?php echo htmlspecialchars(osx_public_url('/finder.png')); ?>" />
  <link rel="preload" as="image" href="<?php echo htmlspecialchars(osx_public_url('/headshot.jpg')); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars(osx_public_url('/css/osx.css')); ?>" />
</head>
<body>
  <div id="osx-root" class="osx-root" data-base="<?php echo htmlspecialchars($base); ?>"></div>

  <script>
    window.OSX_BASE = <?php echo json_encode($base, JSON_UNESCAPED_SLASHES); ?>;
    window.OSX_INITIAL = <?php echo json_encode($initial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script src="<?php echo htmlspecialchars(osx_public_url('/js/osx-icons.js')); ?>"></script>
  <script src="<?php echo htmlspecialchars(osx_public_url('/js/osx-shell.js')); ?>"></script>
</body>
</html>
