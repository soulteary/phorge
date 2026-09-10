<?php

/**
 * Retained as a class-map compatibility shim for extensions which reference
 * the historical setup check.
 *
 * The fork no longer installs or executes Pygments. Advanced highlighting is
 * owned by Gorge, so there is no local binary to discover or recommend.
 */
final class PhabricatorPygmentSetupCheck extends PhabricatorSetupCheck {

  public function getDefaultGroup() {
    return self::GROUP_OTHER;
  }

  protected function executeChecks() {
    return;
  }

}
