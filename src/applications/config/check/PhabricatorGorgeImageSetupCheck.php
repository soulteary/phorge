<?php

final class PhabricatorGorgeImageSetupCheck extends PhabricatorSetupCheck {
  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    $mode = PhabricatorEnv::getEnvConfig('gorge.image.mode');
    $meme = PhabricatorEnv::getEnvConfig('gorge.image.meme-mode');
    $builtin = PhabricatorEnv::getEnvConfig('gorge.image.builtin-mode');
    if ($mode === 'legacy' && $meme === 'legacy' && $builtin === 'legacy') {
      return;
    }
    try {
      if (!in_array($mode, array('legacy', 'shadow', 'gorge')) ||
          !in_array($meme, array('legacy', 'shadow', 'gorge')) ||
          !in_array($builtin, array('legacy', 'shadow', 'gorge'))) {
        throw new Exception(pht('Invalid Gorge image mode.'));
      }
      $caps = id(new PhabricatorGorgeImageClient())->getCapabilities();
      if (idx($caps, 'protocolVersion') !== 1 ||
          idx($caps, 'recipeRevision') !== PhabricatorGorgeImageClient::REVISION) {
        throw new Exception(pht('Incompatible Gorge image protocol.'));
      }
      if ($meme !== 'legacy' && (idx(idx($caps, 'meme', array()), 'revision') !== 'meme-v1' ||
          !idx(idx($caps, 'meme', array()), 'fontRevision'))) {
        throw new Exception(pht('Gorge Meme recipe is unavailable.'));
      }
      if ($builtin !== 'legacy' && idx(idx($caps, 'compose', array()), 'revision') !== 'compose-v1') {
        throw new Exception(pht('Gorge composition recipes are unavailable.'));
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
