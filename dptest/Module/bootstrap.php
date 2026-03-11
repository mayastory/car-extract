<?php
// Module bootstrap (modules refactor v3 cleanroot)
if (!defined('JTMES_ROOT')) {
    $root = realpath(__DIR__ . '/..');
    if ($root === false) { $root = dirname(__DIR__); }
    define('JTMES_ROOT', $root);
}
