<?php

final class PhutilProseDiffTestCase
  extends PhabricatorTestCase {

  public function testTrimApart() {
    $map = array(
      '' => array(),
      'a' => array('a'),
      ' a ' => array(
        ' ',
        'a',
        ' ',
      ),
      ' a' => array(
        ' ',
        'a',
      ),
      'a ' => array(
        'a',
        ' ',
      ),
      ' a b ' => array(
        ' ',
        'a b',
        ' ',
      ),
    );

    foreach ($map as $input => $expect) {
      $actual = PhutilProseDifferenceEngine::trimApart($input);
      $this->assertEqual(
        $expect,
        $actual,
        pht('Trim Apart: %s', $input));
    }
  }

  /**
   * Summarization is still computed here, so it is still tested here.
   *
   * The cases which used to assert how the text was split into parts -- edit
   * smoothing, punctuation handling, whole-word rewrites -- described the
   * behavior of the local implementation which has been removed. Those
   * expectations now belong to the Gorge render service and are verified in
   * its own suite; there is nothing left in this process to assert them
   * against. What remains on this side is turning a part list into a summary,
   * so these cases feed the part list a service response would carry and
   * check the elision.
   */
  public function testProseSummaryParts() {
    $this->assertSummaryProseParts(
      "a\nb\nc\nd\ne\nf\ng\nh\n",
      "a\nb\nc\nd\nX\nf\ng\nh\n",
      array(
        array('=', "a\nb\nc\nd\n"),
        array('-', 'e'),
        array('+', 'X'),
        array('=', "\nf\ng\nh\n"),
      ),
      array(
        '.',
        "= d\n",
        '- e',
        '+ X',
        "= \nf",
        '.',
      ),
      pht('Summary diff with middle change.'));

    $this->assertSummaryProseParts(
      "a\nb\nc\nd\ne\nf\ng\nh\n",
      "X\nb\nc\nd\ne\nf\ng\nh\n",
      array(
        array('-', 'a'),
        array('+', 'X'),
        array('=', "\nb\nc\nd\ne\nf\ng\nh\n"),
      ),
      array(
        '- a',
        '+ X',
        "= \nb",
        '.',
      ),
      pht('Summary diff with head change.'));

    $this->assertSummaryProseParts(
      "a\nb\nc\nd\ne\nf\ng\nh\n",
      "a\nb\nc\nd\ne\nf\ng\nX\n",
      array(
        array('=', "a\nb\nc\nd\ne\nf\ng\n"),
        array('-', 'h'),
        array('+', 'X'),
        array('=', "\n"),
      ),
      array(
        '.',
        "= g\n",
        '- h',
        '+ X',
        "= \n",
      ),
      pht('Summary diff with last change.'));
  }

  /**
   * A response which does not reproduce the inputs must be rejected.
   *
   * This is the integrity check which replaces "the local algorithm can not
   * lose text by construction", so it is the one guarantee this side still
   * owns about the part list itself.
   */
  public function testProsePartsMustReconstructInput() {
    $old = "a\nb\n";
    $new = "a\nc\n";

    $caught = null;
    try {
      PhabricatorGorgeDiffClient::newProseDiffFromData(
        array(
          'parts' => array(
            array('type' => '=', 'text' => "a\n"),
            array('type' => '-', 'text' => 'b'),
            array('type' => '+', 'text' => 'c'),
            // The trailing newline both inputs end with is missing here.
          ),
        ),
        $old,
        $new);
    } catch (Exception $ex) {
      $caught = $ex;
    }

    $this->assertTrue(
      ($caught instanceof Exception),
      pht('Parts which do not reconstruct the inputs are rejected.'));

    $diff = PhabricatorGorgeDiffClient::newProseDiffFromData(
      array(
        'parts' => array(
          array('type' => '=', 'text' => "a\n"),
          array('type' => '-', 'text' => 'b'),
          array('type' => '+', 'text' => 'c'),
          array('type' => '=', 'text' => "\n"),
        ),
      ),
      $old,
      $new);

    $this->assertParts(
      array(
        "= a\n",
        '- b',
        '+ c',
        "= \n",
      ),
      $diff->getParts(),
      pht('Parts which reconstruct the inputs are accepted.'));
  }

  /**
   * Without a render endpoint there is no prose diff at all any more.
   */
  public function testProseDiffRequiresConfiguredService() {
    $env = PhabricatorEnv::beginScopedEnv();
    $env->overrideEnvConfig('gorge.render.uri', null);

    $caught = null;
    try {
      id(new PhutilProseDifferenceEngine())->getDiff('a', 'b');
    } catch (Exception $ex) {
      $caught = $ex;
    }

    unset($env);

    $this->assertTrue(
      ($caught instanceof Exception),
      pht('Prose diff fails when the render service is not configured.'));
  }

  private function assertSummaryProseParts(
    $old,
    $new,
    array $service_parts,
    array $expect_parts,
    $label) {

    $parts = array();
    foreach ($service_parts as $service_part) {
      list($type, $text) = $service_part;
      $parts[] = array(
        'type' => $type,
        'text' => $text,
      );
    }

    $diff = PhabricatorGorgeDiffClient::newProseDiffFromData(
      array('parts' => $parts),
      $old,
      $new);

    $this->assertParts($expect_parts, $diff->getSummaryParts(), $label);
  }

  private function assertParts(
    array $expect,
    array $actual_parts,
    $label) {

    $actual = array();
    foreach ($actual_parts as $actual_part) {
      $type = $actual_part['type'];
      $text = $actual_part['text'];

      switch ($type) {
        case '.':
          $actual[] = $type;
          break;
        default:
          $actual[] = "{$type} {$text}";
          break;
      }
    }

    $this->assertEqual($expect, $actual, $label);
  }


}
