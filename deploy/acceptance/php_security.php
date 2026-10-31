<?php
// Run inside the actual Phorge acceptance image with its installed INI files.
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5 ||
    version_compare(PHP_VERSION, '8.5.12', '<')) {
  fwrite(STDERR, "Unsupported PHP runtime; require PHP 8.5.12+ on the 8.5 branch: ".PHP_VERSION."\n");
  exit(1);
}
if (ini_get('opcache.jit') !== 'disable' ||
    (int)ini_get('opcache.jit_buffer_size') !== 0) {
  fwrite(STDERR, "PHP JIT must be explicitly disabled\n");
  exit(1);
}
if (filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN) ||
    ini_get('session.use_strict_mode') !== '1') {
  fwrite(STDERR, "Effective PHP session security settings are unsafe\n");
  exit(1);
}
echo "Verified PHP ".PHP_VERSION." with hardened session settings\n";
