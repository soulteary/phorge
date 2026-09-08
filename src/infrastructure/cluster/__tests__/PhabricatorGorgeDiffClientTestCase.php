<?php

final class PhabricatorGorgeDiffClientTestCase extends PhabricatorTestCase {

  public function testBuildProseDiffFromServiceData() {
    $old = 'The quick brown fox';
    $new = 'The slow brown cat';

    $data = array(
      'parts' => array(
        array('type' => '=', 'text' => 'The '),
        array('type' => '-', 'text' => 'quick'),
        array('type' => '+', 'text' => 'slow'),
        array('type' => '=', 'text' => ' brown '),
        array('type' => '-', 'text' => 'fox'),
        array('type' => '+', 'text' => 'cat'),
      ),
    );

    $diff = PhabricatorGorgeDiffClient::newProseDiffFromData(
      $data,
      $old,
      $new);

    $this->assertEqual($data['parts'], $diff->getParts());
  }

  public function testRejectProseDiffWhichLosesInput() {
    $this->assertException(
      'Exception',
      array($this, 'buildLossyProseDiff'));
  }

  public function buildLossyProseDiff() {
    PhabricatorGorgeDiffClient::newProseDiffFromData(
      array(
        'parts' => array(
          array('type' => '=', 'text' => 'same'),
        ),
      ),
      'same old suffix',
      'same new suffix');
  }

}
