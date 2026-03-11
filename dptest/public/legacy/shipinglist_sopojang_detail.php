<?php
// Legacy entrypoint (bridge only)
// This file lives under /public/legacy and is reached via root .htaccess rewrite.
require_once __DIR__ . '/../../Module/bootstrap.php';
require JTMES_ROOT . '/Module/Shipinglist/pages/shipinglist_sopojang_detail.php';
