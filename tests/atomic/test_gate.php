<?php

require_once __DIR__.'/gate.php';

$required = array('Fixture::testOne' => true);
$pass = array(array('id' => 'Fixture::testOne', 'result' => 'pass'));
$skip = array(array('id' => 'Fixture::testOne', 'result' => 'skip'));
$check = function($value, $description) {
  if (!$value) { throw new Exception($description); }
};
$check(phorge_atomic_validate_results($required, $pass, array()) === array(),
  'A complete passing selection was rejected.');
$check((bool)phorge_atomic_validate_results(array(), array(), array()),
  'An empty selection passed.');
$check((bool)phorge_atomic_validate_results($required, array(), array()),
  'A missing result passed.');
$check((bool)phorge_atomic_validate_results($required, array_merge($pass, $pass), array()),
  'Duplicate results passed.');
$check((bool)phorge_atomic_validate_results($required, $skip, array()),
  'An undocumented skip passed.');
$check((bool)phorge_atomic_validate_results($required, $skip,
  array('Fixture::testOne' => '')), 'An empty skip reason passed.');
$check(phorge_atomic_validate_results($required, $skip,
  array('Fixture::testOne' => 'Explicitly optional transport deadline.')) === array(),
  'A documented selected skip failed.');
$check((bool)phorge_atomic_validate_results($required, $pass,
  array('Other::testMissing' => 'Stale exception.')), 'A stale skip exception passed.');
foreach (array('fail', 'broken', 'unsound', 'postponed', '') as $result) {
  $bad = array(array('id' => 'Fixture::testOne', 'result' => $result));
  $check((bool)phorge_atomic_validate_results($required, $bad, array()),
    'A nonpassing result passed: '.$result);
}
$check((bool)phorge_atomic_validate_results($required,
  array(array('id' => 'Other::testUnknown', 'result' => 'pass')), array()),
  'An unexpected result replaced the required result.');
echo "Atomic runner result-policy regressions passed (14 checks).\n";
