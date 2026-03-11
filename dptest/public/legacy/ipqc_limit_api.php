<?php
/**
 * Public entrypoint for IPQC USL/LSL update.
 *
 * Note:
 * - /Module is protected by .htaccess (403)
 * - Root .htaccess rewrites *.php requests to /public/legacy/*.php
 *   therefore this file is reachable as /<BASE>/ipqc_limit_api.php
 */

require_once __DIR__ . '/../../Module/IPQC/lib/ipqc_limit_api.php';
