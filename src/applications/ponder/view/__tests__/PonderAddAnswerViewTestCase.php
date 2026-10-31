<?php

final class PonderAddAnswerViewTestCase extends PhabricatorTestCase {

  public function testAnonymousQuestionDoesNotLoadAnswers() {
    // Deliberately leave answers unattached. An anonymous viewer must never
    // inspect them or build an answer form, for either open or closed questions.
    foreach (array(PonderQuestionStatus::STATUS_OPEN,
                   PonderQuestionStatus::STATUS_CLOSED_RESOLVED) as $status) {
      $question = id(new PonderQuestion())->setID(42)->setStatus($status);
      $view = id(new PonderAddAnswerView())
        ->setViewer(new PhabricatorUser())
        ->setQuestion($question)
        ->setActionURI('/ponder/answer/');
      $output = (string)hsprintf('%s', $view);
      $this->assertTrue(strpos($output, '/auth/start/') !== false);
      $this->assertFalse(strpos($output, 'answer-content') !== false);
    }
  }

  public function testExistingAuthorCanNotAnswerTwice() {
    $viewer = id(new PhabricatorUser())->setPHID('PHID-USER-test');
    $answer = id(new PonderAnswer())->setAuthorPHID($viewer->getPHID());
    $question = id(new PonderQuestion())
      ->setID(42)
      ->setStatus(PonderQuestionStatus::STATUS_OPEN)
      ->attachAnswers(array($answer));
    $view = id(new PonderAddAnswerView())
      ->setViewer($viewer)
      ->setQuestion($question)
      ->setActionURI('/ponder/answer/');
    $output = (string)hsprintf('%s', $view);
    $this->assertFalse(strpos($output, 'answer-content') !== false);
    $this->assertFalse(strpos($output, '/auth/start/') !== false);
  }

}
