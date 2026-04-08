<?php
require_once __DIR__ . '/../core/http.php';
require_once __DIR__ . '/../core/desktop_scan.php';

$apps = osx_scan_desktop_apps();
osx_json(['ok' => true, 'apps' => $apps]);
