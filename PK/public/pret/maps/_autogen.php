<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function fail_out(int $code, string $err, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => 0, 'err' => $err], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$map = (string)($_GET['map'] ?? '');
if ($map === '' || !preg_match('/^[A-Za-z0-9_]+$/', $map)) {
    fail_out(400, 'BAD_MAP');
}

$target = __DIR__ . DIRECTORY_SEPARATOR . $map . '.json';
if (is_file($target)) {
    readfile($target);
    exit;
}

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$publicPos = strpos($scriptName, '/public/');
$rootPath = ($publicPos !== false) ? substr($scriptName, 0, $publicPos) : preg_replace('#/pret/maps/_autogen\.php$#', '', $scriptName);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$apiUrl = $scheme . '://' . $host . $rootPath . '/api/pret/map.php?map=' . rawurlencode($map);

$respBody = false;
$status = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $respBody = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} else {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $respBody = @file_get_contents($apiUrl, false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }
}

if ($respBody === false || $status >= 400) {
    fail_out(($status >= 400 ? $status : 502), 'PRET_API_CALL_FAILED', ['map' => $map, 'apiUrl' => $apiUrl]);
}

$j = json_decode((string)$respBody, true);
if (!is_array($j) || empty($j['ok'])) {
    fail_out(502, 'PRET_API_BAD_JSON', ['map' => $map]);
}

clearstatcache(true, $target);
if (is_file($target)) {
    readfile($target);
    exit;
}

$mapUrl = (string)($j['mapUrl'] ?? '');
if ($mapUrl !== '') {
    $mapBase = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $guess = null;
    if (str_starts_with($mapUrl, './pret/maps/')) {
        $guess = __DIR__ . DIRECTORY_SEPARATOR . basename($mapUrl);
    } elseif (preg_match('#/pret/maps/([A-Za-z0-9_]+)\.json$#', $mapUrl, $m)) {
        $guess = __DIR__ . DIRECTORY_SEPARATOR . $m[1] . '.json';
    }
    if ($guess && is_file($guess)) {
        readfile($guess);
        exit;
    }
}

fail_out(500, 'PRET_JSON_NOT_WRITTEN', ['map' => $map, 'apiUrl' => $apiUrl]);
