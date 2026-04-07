<?php
require_once __DIR__ . '/../config.php';
$conn = db();
$res = $conn->query("SELECT 1 AS ok");
json_out(['ok'=>true,'db'=>($res?true:false),'time'=>date('c')]);
