<?php

final class PhorgeRecoveryStopTestCase extends PhutilTestCase {
  private function subject() { return 'PHID-USER-'.str_repeat('a', 20); }
  private function event() {
    return array('id' => '42', 'objectPHID' => $this->subject(),
      'authorPHID' => 'PHID-USER-'.str_repeat('b', 20),
      'transactionType' => 'user.disable', 'oldValue' => 'false', 'newValue' => 'true');
  }

  public function testCanonicalRequestsRejectCoercionAndAmbiguousClassifications() {
    $valid = array($this->subject(), '42', 'technical-pause', 'Synthetic maintenance', 'stop-test-operation-0001');
    $request = call_user_func_array(array(PhorgeRecoveryStopRequest::class, 'validate'), $valid);
    $this->assertEqual('42', $request['native_disable_transaction']);
    $this->assertEqual('18446744073709551615', PhorgeRecoveryStopRequest::transactionID('18446744073709551615'));
    $mutations = array(array(0, 'PHID-USER-short'), array(1, '042'), array(1, 42.0),
      array(1, true), array(1, '18446744073709551616'), array(2, 'unknown'), array(2, 'technical'),
      array(3, ''), array(3, "bad\0reason"), array(3, str_repeat('x', 2049)),
      array(3, "\xff"), array(4, 'short'), array(4, str_repeat('x', 129)));
    foreach ($mutations as $mutation) {
      $args = $valid; $args[$mutation[0]] = $mutation[1]; $refused = false;
      try { call_user_func_array(array(PhorgeRecoveryStopRequest::class, 'validate'), $args); }
      catch (InvalidArgumentException $error) { $refused = true; }
      $this->assertTrue($refused);
    }
  }

  public function testOnlyAnExactNativeDisableTransitionIsEvidence() {
    $row = $this->event();
    $digest = PhorgeRecoveryStopRequest::event($row, $this->subject(), '42');
    $this->assertEqual(64, strlen($digest));
    foreach (array(array('newValue', 'false'), array('newValue', '1'), array('newValue', '"true"'),
      array('oldValue', 'true'), array('oldValue', null), array('transactionType', 'user.approve'),
      array('objectPHID', 'PHID-USER-'.str_repeat('c', 20)), array('id', '43')) as $mutation) {
      $changed = $row; $changed[$mutation[0]] = $mutation[1]; $refused = false;
      try { PhorgeRecoveryStopRequest::event($changed, $this->subject(), '42'); }
      catch (RuntimeException $error) { $refused = true; }
      $this->assertTrue($refused);
    }
  }

  public function testStopEventBindingIncludesNativeAuthorAndTransaction() {
    $row = $this->event();
    $before = PhorgeRecoveryStopRequest::event($row, $this->subject(), '42');
    $row['authorPHID'] = 'PHID-USER-'.str_repeat('c', 20);
    $this->assertTrue($before !== PhorgeRecoveryStopRequest::event($row, $this->subject(), '42'));
    $row = $this->event(); $row['id'] = '43';
    $this->assertTrue($before !== PhorgeRecoveryStopRequest::event($row, $this->subject(), '43'));
  }

  public function testReadOnlyDAORejectsEveryNativeORMMutationEntry() {
    foreach (array('save', 'insert', 'update', 'replace', 'delete') as $method) {
      $refused = false;
      try { (new PhorgeRecoveryStop())->$method(); }
      catch (RuntimeException $error) { $refused = true; }
      $this->assertTrue($refused);
    }
  }
}
