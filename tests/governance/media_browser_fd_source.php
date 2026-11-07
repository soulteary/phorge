<?php
/** Read-only source verification candidate; no automatic runtime entry point. */
function wb_source_need($value) {
  if ($value !== true) { throw new RuntimeException('source_identity_unproved'); }
}
function wb_source_stat($path) {
  clearstatcache(true, $path);
  $value = @lstat($path);
  wb_source_need(is_array($value));
  return $value;
}
function wb_source_stamp(array $value) {
  return array_intersect_key($value, array_flip(array(
    'dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime')));
}
function wb_source_path($path) {
  if (!is_string($path) || strlen($path) > 4096 ||
      !preg_match('~\A[A-Za-z0-9_./@-]+\z~D', $path)) { return false; }
  foreach (explode('/', $path) as $part) {
    if ($part === '' || $part === '.' || $part === '..') { return false; }
  }
  return true;
}
function wb_source_chain($root, $relative) {
  $result = array(); $path = $root;
  $parts = explode('/', $relative); array_pop($parts);
  foreach (array_merge(array(null), $parts) as $part) {
    if ($part !== null) { $path .= '/'.$part; }
    $value = wb_source_stat($path);
    wb_source_need(($value['mode'] & 0170000) === 0040000 &&
      $value['uid'] === posix_geteuid());
    $result[$path] = wb_source_stamp($value);
  }
  return $result;
}
function wb_source_hash_file($root, $relative, $expected, array $aliases, $hook = null) {
  $path = $root.'/'.$relative;
  $chain = wb_source_chain($root, $relative);
  $entry = wb_source_stat($path);
  if (array_key_exists($relative, $aliases)) {
    wb_source_need(($entry['mode'] & 0170000) === 0120000 &&
      readlink($path) === $aliases[$relative]);
    $target = realpath($path);
    wb_source_need(is_string($target) && str_starts_with($target, $root.'/'));
    $targetRelative = substr($target, strlen($root) + 1);
    wb_source_need(wb_source_path($targetRelative) &&
      isset($expected[$targetRelative]) &&
      $expected[$targetRelative] === $expected[$relative] &&
      !array_key_exists($targetRelative, $aliases));
    $chain += wb_source_chain($root, $targetRelative);
  } else {
    wb_source_need(($entry['mode'] & 0170000) === 0100000);
    $target = $path;
  }
  $targetEntry = wb_source_stat($target);
  wb_source_need(($targetEntry['mode'] & 0170000) === 0100000 &&
    $targetEntry['uid'] === posix_geteuid() &&
    $targetEntry['nlink'] === 1 && $targetEntry['size'] <= 67108864);
  if ($hook !== null) { $hook('before-open', $path); }
  // RDWR avoids a blocking read-only FIFO open after a path replacement.
  // This function never writes through the stream.
  $stream = @fopen($target, 'r+b');
  wb_source_need(is_resource($stream));
  try {
    $fd = fstat($stream);
    wb_source_need(is_array($fd) &&
      ($fd['mode'] & 0170000) === 0100000 &&
      wb_source_stamp($fd) === wb_source_stamp($targetEntry));
    $hash = hash_init('sha256');
    $bytes = hash_update_stream($hash, $stream, $fd['size'] + 1);
    wb_source_need($bytes === $fd['size'] &&
      hash_final($hash) === $expected[$relative]);
    if ($hook !== null) { $hook('after-hash', $path); }
    wb_source_need(fseek($stream, 0) === 0);
    $hash = hash_init('sha256');
    wb_source_need(hash_update_stream($hash, $stream, $fd['size'] + 1) === $fd['size'] &&
      hash_final($hash) === $expected[$relative]);
    wb_source_need(wb_source_stamp(fstat($stream)) === wb_source_stamp($fd) &&
      wb_source_stamp(wb_source_stat($target)) === wb_source_stamp($fd) &&
      wb_source_stamp(wb_source_stat($path)) === wb_source_stamp($entry));
    foreach ($chain as $directory => $stamp) {
      wb_source_need(wb_source_stamp(wb_source_stat($directory)) === $stamp);
    }
  } finally { fclose($stream); }
}
/** Host seam accepts a root; production binding must fix it externally. */
function wb_source_verify($root, array $expected, array $aliases, array $identity, $hook = null) {
  wb_source_need(is_string($root) && realpath($root) === $root &&
    count($expected) > 0 && count($expected) <= 10000);
  $before = wb_source_stat($root);
  wb_source_need(($before['mode'] & 0170000) === 0040000 &&
    $before['uid'] === posix_geteuid() &&
    array_keys($identity) === array('dev', 'ino') &&
    is_int($identity['dev']) && is_int($identity['ino']) &&
    $before['dev'] === $identity['dev'] && $before['ino'] === $identity['ino']);
  $directories = array('' => true);
  foreach ($expected as $relative => $digest) {
    wb_source_need(wb_source_path($relative) && is_string($digest) &&
      preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1);
    $parent = dirname($relative);
    while ($parent !== '.') { $directories[$parent] = true; $parent = dirname($parent); }
  }
  foreach ($aliases as $relative => $target) {
    wb_source_need(isset($expected[$relative]) && is_string($target) &&
      strlen($target) <= 4096 && $target !== '' && $target[0] !== '/');
  }
  $seen = array(); $stack = array(''); $total = 0;
  while ($stack) {
    $relative = array_pop($stack); $directory = $root.($relative === '' ? '' : '/'.$relative);
    $directoryStat = wb_source_stat($directory);
    wb_source_need(($directoryStat['mode'] & 0170000) === 0040000);
    foreach (new DirectoryIterator($directory) as $entry) {
      if ($entry->isDot()) { continue; }
      $name = ($relative === '' ? '' : $relative.'/').$entry->getFilename();
      wb_source_need(wb_source_path($name));
      $value = wb_source_stat($root.'/'.$name); $type = $value['mode'] & 0170000;
      if ($type === 0040000) {
        wb_source_need(isset($directories[$name])); $stack[] = $name; continue;
      }
      wb_source_need(isset($expected[$name]) && !isset($seen[$name]) &&
        ($type === 0100000 || $type === 0120000));
      wb_source_hash_file($root, $name, $expected, $aliases, $hook);
      $seen[$name] = true; $total += $value['size'];
      wb_source_need($total <= 1073741824);
    }
    wb_source_need(wb_source_stamp(wb_source_stat($directory)) === wb_source_stamp($directoryStat));
  }
  wb_source_need(count($seen) === count($expected) &&
    wb_source_stamp(wb_source_stat($root)) === wb_source_stamp($before));
  return array('passed' => true, 'files' => count($seen));
}
