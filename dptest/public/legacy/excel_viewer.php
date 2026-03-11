<?php
// Legacy entrypoint (kept for backward-compatible URLs)
// This file lives under /public/legacy and is reached via root .htaccess rewrite.
require_once __DIR__ . '/../../Module/bootstrap.php';
require JTMES_ROOT . '/Module/ExcelViewer/pages/excel_viewer.php';
