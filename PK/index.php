<?php
// Portable redirect: works regardless of folder name.
$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$base = rtrim($base, '/');
header('Location: ' . $base . '/public/');
exit;
