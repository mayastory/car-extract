<?php
// core/desktop_scan.php

require_once __DIR__ . '/path.php';
require_once __DIR__ . '/app_config.php';

function osx_desktop_dir(): string {
  return osx_fs_path('(desktop)');
}

function osx_scan_desktop_apps(): array {
  $desktop = osx_desktop_dir();
  $apps = [];
  if (!is_dir($desktop)) return $apps;

  $all = osx_app_config_all();
  $configMap = [];
  foreach ($all as $a) $configMap[$a['id']] = $a;

  $entries = scandir($desktop);
  if (!$entries) return $apps;

  foreach ($entries as $name) {
    if ($name === '.' || $name === '..') continue;
    $appDir = $desktop . '/' . $name;
    if (!is_dir($appDir)) continue;

    // app must have app.php to be visible
    if (!is_file($appDir . '/app.php')) continue;

    $id = $name;
    $base = $configMap[$id] ?? [
      'id' => $id,
      'name' => ucfirst($id),
      'icon' => '/finder.png',
      'description' => '',
      'accentColor' => '#8E8E93',
      'defaultPosition' => ['x' => 120, 'y' => 80],
      'defaultSize' => ['width' => 800, 'height' => 600],
      'minSize' => ['width' => 400, 'height' => 300],
      'menuBarTitle' => ucfirst($id),
      'showOnDockByDefault' => true,
      'multiWindow' => false,
      'cascadeOffset' => 0,
    ];

    $manifestPath = $appDir . '/manifest.json';
    if (is_file($manifestPath)) {
      $json = json_decode(@file_get_contents($manifestPath), true);
      if (is_array($json)) {
        // Allow overriding known keys
        foreach (['name','icon','description','accentColor','defaultPosition','defaultSize','minSize','menuBarTitle','showOnDockByDefault','multiWindow','cascadeOffset'] as $k) {
          if (array_key_exists($k, $json)) $base[$k] = $json[$k];
        }
        if (isset($json['id'])) $base['id'] = (string)$json['id'];
      }
    }

    // App iframe URL (shell opens this)
    $base['appUrl'] = osx_public_url('(desktop)/' . rawurlencode($id) . '/app.php');

    $apps[] = $base;
  }

  // Keep the same ordering as alanagoyal APPS (ground truth) when present
  $order = array_map(fn($a) => $a['id'], osx_app_config_all());
  usort($apps, function($a, $b) use ($order) {
    $ia = array_search($a['id'], $order, true);
    $ib = array_search($b['id'], $order, true);
    $ia = ($ia === false) ? 9999 : $ia;
    $ib = ($ib === false) ? 9999 : $ib;
    if ($ia === $ib) return strcmp($a['id'], $b['id']);
    return $ia <=> $ib;
  });

  return $apps;
}
