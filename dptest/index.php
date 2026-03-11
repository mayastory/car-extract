<?php
// Fallback entrypoint if .htaccess DirectoryIndex isn't applied.
// Safe: just forward to public/index.php
require __DIR__ . '/public/index.php';
