#!/usr/bin/env php
<?php

// Print every symbol a Phutil library map declares, one per line.
//
// The map is a PHP file which calls phutil_register_library_map(), so reading
// it means defining that function and requiring the file. That is cheaper and
// more accurate than parsing it, and it works on an old revision of the map
// piped in from "git show".

function phutil_register_library_map(array $map) {
  foreach (array('class', 'function') as $kind) {
    if (empty($map[$kind])) {
      continue;
    }
    foreach (array_keys($map[$kind]) as $symbol) {
      echo $symbol, "\n";
    }
  }
}

if ($argc !== 2) {
  fwrite(STDERR, "Usage: print-library-symbols.php <library-map.php>\n");
  exit(1);
}

require $argv[1];
