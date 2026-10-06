<?php

final class PhabricatorTransientImageTestTransform extends PhabricatorFileTransform {
  public function getTransformName() { return 'Transient image test'; }
  public function getTransformKey() { return 'transient-image-test'; }
  public function generateTransforms() { return array(); }
  public function canApplyTransform(PhabricatorFile $file) { return true; }
  public function applyTransform(PhabricatorFile $file) {
    throw new PhabricatorGorgeImageTransientException('Transient test failure');
  }
  public function getDefaultTransform(PhabricatorFile $file) {
    return id(new PhabricatorFile())->makeEphemeral()
      ->setPHID('PHID-FILE-transient-fallback');
  }
}
