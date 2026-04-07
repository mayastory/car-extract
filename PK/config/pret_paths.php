<?php
// config/pret_paths.php
// Central paths used by PRET export API.
//
// ✔️ If you want to override Packege path without touching this file:
//   - Create: config/pret_paths.local.php
//   - Return: ['packege_root' => 'D:/.../Packege']
//   - (Optional) you can also set env var POKEMON_PACK_ROOT.

$packRoot = realpath(__DIR__ . '/../Packege') ?: (__DIR__ . '/../Packege');

// Local override file (ignored by git if you want)
$local = __DIR__ . '/pret_paths.local.php';
if (is_file($local)) {
  $ov = include $local;
  if (is_array($ov) && !empty($ov['packege_root'])) {
    $packRoot = $ov['packege_root'];
  }
}

// Optional environment override
$envRoot = getenv('POKEMON_PACK_ROOT');
if ($envRoot && is_dir($envRoot)) {
  $packRoot = $envRoot;
}

return [
  // Absolute path to Packege root (decompiled data).
  // Default: <project_root>/Packege
  'packege_root' => $packRoot,

  // Where to write generated public assets
  // Default: <project_root>/public/pret
  'public_pret_root' => realpath(__DIR__ . '/../public/pret') ?: (__DIR__ . '/../public/pret'),

  // Cache root (server side)
  'cache_root' => realpath(__DIR__ . '/../cache/pret') ?: (__DIR__ . '/../cache/pret'),
];
