<?php

// Test-runner policy only. This file is never loaded by the application.
function phorge_atomic_validate_results(array $expected, array $rows,
  array $allowed_skips) {
  $errors = array();
  if (!$expected) {
    $errors[] = 'The selected package contains no test methods.';
  }
  $seen = array();
  foreach ($rows as $row) {
    $id = $row['id'];
    if (!isset($expected[$id])) {
      $errors[] = 'Unexpected test result: '.$id;
    }
    if (isset($seen[$id])) {
      $errors[] = 'Duplicate test result: '.$id;
    }
    $seen[$id] = true;
    if ($row['result'] === 'skip') {
      if (!isset($allowed_skips[$id]) || !strlen($allowed_skips[$id])) {
        $errors[] = 'Required test was skipped: '.$id;
      }
    } else if ($row['result'] !== 'pass') {
      $errors[] = 'Test did not pass: '.$id.' ('.$row['result'].')';
    }
  }
  foreach ($expected as $id => $_) {
    if (!isset($seen[$id])) {
      $errors[] = 'Expected test did not execute: '.$id;
    }
  }
  foreach ($allowed_skips as $id => $_) {
    if (!isset($expected[$id])) {
      $errors[] = 'Skip exception does not name a selected test: '.$id;
    }
  }
  return $errors;
}
