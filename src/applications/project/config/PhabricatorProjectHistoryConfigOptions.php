<?php

final class PhabricatorProjectHistoryConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Project History');
  }

  public function getDescription() {
    return pht('Control project record publication without disabling old records.');
  }

  public function getGroup() {
    return 'apps';
  }

  public function getApplicationClassName() {
    return PhabricatorProjectApplication::class;
  }

  public function getOptions() {
    return array(
      $this->newOption('project.history-state', 'enum', 'off')
        ->setLocked(true)
        ->setEnumOptions(array(
          'off' => pht('Off'),
          'active' => pht('Active'),
          'read-only' => pht('Read Only'),
        ))
        ->setDescription(pht(
          'Off hides the history menu and rejects new records. Active allows '.
          'current project editors to publish. Read Only keeps the menu but '.
          'rejects publication. All states retain transaction readers, stable '.
          'record URLs, and current project and Feed policy checks.')),
    );
  }
}
