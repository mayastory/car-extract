<?php
// Legacy entrypoint for OQC status modal.
// /oqc_status_modal.php is reached through root .htaccess and must bridge to the module page.
require_once __DIR__ . '/../../Module/bootstrap.php';
require JTMES_ROOT . '/Module/OQC/pages/oqc_status_modal.php';
