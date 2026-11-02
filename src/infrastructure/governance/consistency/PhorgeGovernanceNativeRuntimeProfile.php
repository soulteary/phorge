<?php

/** Fail-closed runtime-library boundary for the isolated fixed native cut. */
final class PhorgeGovernanceNativeRuntimeProfile extends Phobject {

  public static function validate(
    array $libraries, array $roots, array $maps, array $base_maps,
    array $extension_maps = array()) {

    $expected = array('arcanist', 'phorge');
    sort($libraries, SORT_STRING);
    if ($libraries !== $expected || array_keys($roots) !== $expected ||
        array_keys($maps) !== $expected || array_keys($base_maps) !== $expected ||
        $extension_maps) {
      throw new RuntimeException('native_runtime_library_profile_refused');
    }
    $root = dirname(__DIR__, 4);
    $wanted = array('arcanist' => realpath($root.'/support/runtime/src'),
      'phorge' => realpath($root.'/src'));
    $hashes = array();
    foreach ($expected as $library) {
      if (!$wanted[$library] || !is_string($roots[$library]) ||
          realpath($roots[$library]) !== $wanted[$library] ||
          !is_array($maps[$library]) || !is_array($base_maps[$library]) ||
          $maps[$library] !== $base_maps[$library]) {
        throw new RuntimeException('native_runtime_root_or_extension_refused');
      }
      $hashes[$library] = hash('sha256', serialize($maps[$library]));
    }
    return array('runtime_profile' => 'fixed-native-arcanist-phorge',
      'libraries' => $expected, 'canonical_roots_verified' => true,
      'library_maps_unextended' => true, 'map_sha256' => $hashes);
  }

  public static function assertCurrent() {
    $loader = PhutilBootloader::getInstance();
    $libraries = $loader->getAllLibraries();
    $roots = array();
    $maps = array();
    $base = array();
    foreach (array('arcanist', 'phorge') as $library) {
      if (!in_array($library, $libraries, true)) {
        throw new RuntimeException('native_runtime_library_profile_refused');
      }
      $roots[$library] = $loader->getLibraryRoot($library);
      $maps[$library] = $loader->getLibraryMap($library);
      $base[$library] = $loader->getLibraryMapWithoutExtensions($library);
    }
    // Equal merged maps alone can hide a duplicate/no-op extension entry.
    $extensions = (new ReflectionProperty(PhutilBootloader::class, 'extensionMaps'))
      ->getValue($loader);
    return self::validate($libraries, $roots, $maps, $base, $extensions);
  }
}
