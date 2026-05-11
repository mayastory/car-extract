<?php
// Legacy entrypoint for IPQC MSOP viewer.
// /{install-root}/ipqc_msop_view.php is rewritten here by dptest/.htaccess.
if (!defined('JTMES_ROOT')) {
  define('JTMES_ROOT', realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2));
}
require_once JTMES_ROOT . '/Module/IPQC/pages/ipqc_msop_view.php';
