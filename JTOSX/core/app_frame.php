<?php
require_once __DIR__ . '/path.php';

function osx_app_header(string $title): void {
  $base = osx_base_path();
  $css = osx_public_url('/css/app.css');
  $bridge = osx_public_url('/js/app-bridge.js');
  echo "<!doctype html>\n<html lang=\"en\">\n<head>\n";
  echo "<meta charset=\"utf-8\" />\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n";
  echo "<title>" . htmlspecialchars($title) . "</title>\n";
  echo "<link rel=\"stylesheet\" href=\"" . htmlspecialchars($css) . "\" />\n";
  echo "</head>\n<body>\n";
  echo "<script>window.OSX_BASE=" . json_encode($base, JSON_UNESCAPED_SLASHES) . ";</script>\n";
  // Apply global theme (alanagoyal: next-themes default key is 'theme')
  echo "<script>(function(){\n";
  echo "  try {\n";
  echo "    var t = localStorage.getItem('theme') || 'system';\n";
  echo "    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;\n";
  echo "    var isDark = (t === 'dark') || (t === 'system' && prefersDark);\n";
  echo "    document.documentElement.classList.toggle('dark', !!isDark);\n";
  echo "  } catch (e) {}\n";
  echo "})();</script>\n";
  echo "<script src=\"" . htmlspecialchars($bridge) . "\"></script>\n";
}

function osx_app_footer(): void {
  echo "\n</body>\n</html>";
}
