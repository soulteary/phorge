<?php

// The registered name is kept for PHP API/resource compatibility. Only this
// project-owned copy may satisfy it; there is no external-directory fallback.
$gorge_runtime_root = __DIR__.'/src';
if (class_exists('PhutilBootloader', false)) {
  $gorge_loaded_root = PhutilBootloader::getInstance()
    ->getLibraryRoot('arcanist');
  if (realpath($gorge_loaded_root) !== realpath($gorge_runtime_root)) {
    throw new RuntimeException(
      'A different Arcanist library is already loaded. Use the project runtime.');
  }
} else {
  require_once $gorge_runtime_root.'/init/init-library.php';
}
unset($gorge_runtime_root, $gorge_loaded_root);
