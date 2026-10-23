<?php
// Run inside the actual Phorge acceptance image with its installed INI files.
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3 ||
    version_compare(PHP_VERSION, '8.3.36', '<')) {
  fwrite(STDERR, "PHP runtime is below the security baseline: ".PHP_VERSION."\n");
  exit(1);
}
if (filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN) ||
    ini_get('session.use_strict_mode') !== '1') {
  fwrite(STDERR, "Effective PHP session security settings are unsafe\n");
  exit(1);
}
echo "Verified PHP ".PHP_VERSION." with hardened session settings\n";
