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

  public function testHighlightFallbackPolicy() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'fallback');
    PhabricatorGorgeServiceSpec::resetFallbackCounts();

    try {
      $this->newFailingHighlightFuture()->resolve();
      $this->assertFailure(pht('Expected highlighting to fail.'));
    } catch (PhutilSyntaxHighlighterException $ex) {
      $this->assertEqual(
        array('render.highlight' => 1),
        PhabricatorGorgeServiceSpec::getFallbackCounts());
    }

    PhabricatorGorgeServiceSpec::resetFallbackCounts();
    unset($env);
  }

  public function testHighlightRequiredPolicy() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.service-policy', 'required');

    $caught = null;
    try {
      $this->newFailingHighlightFuture()->resolve();
    } catch (Exception $ex) {
      $caught = $ex;
    }

    $this->assertTrue($caught instanceof Exception);
    $this->assertFalse(
      $caught instanceof PhutilSyntaxHighlighterException);

    unset($env);
  }

  private function newFailingHighlightFuture() {
    $result = array(new Exception('render unavailable'), '');
    return new PhabricatorGorgeHighlightFuture(
      new ImmediateFuture($result),
      'http://gorge-render:8140/api/highlight/render');
  }

}
