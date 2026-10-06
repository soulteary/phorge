<?php

final class PhabricatorGorgeImageSetupCheck extends PhabricatorSetupCheck {
  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    $mode = PhabricatorEnv::getEnvConfig('gorge.image.mode');
    if ($mode === 'legacy') {
      return;
    }
    try {
      if (!in_array($mode, array('shadow', 'gorge'))) {
        throw new Exception(pht('Invalid Gorge image mode.'));
      }
      $caps = id(new PhabricatorGorgeImageClient())->getCapabilities();
      if (idx($caps, 'protocolVersion') !== 1 ||
          idx($caps, 'recipeRevision') !== PhabricatorGorgeImageClient::REVISION) {
        throw new Exception(pht('Incompatible Gorge image protocol.'));
      }
      foreach (array('profile', 'pinboard', 'thumbgrid', 'preview', 'workcard') as $key) {
        if (!isset($caps['recipes'][$key])) {
          throw new Exception(pht('Gorge image recipe "%s" is missing.', $key));
        }
      }
    } catch (Exception $ex) {
      $this->newIssue('gorge.image.unavailable')
        ->setName(pht('Gorge Image Service Unavailable'))
        ->setMessage($ex->getMessage())
        ->addRelatedPhabricatorConfig('gorge.image.uri')
        ->addRelatedPhabricatorConfig('gorge.image.mode');
    }
  }
}
