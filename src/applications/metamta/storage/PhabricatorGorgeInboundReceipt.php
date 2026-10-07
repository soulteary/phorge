<?php

final class PhabricatorGorgeInboundReceipt extends PhabricatorMetaMTADAO {
  protected $eventID;
  protected $digest;
  protected $state;
  protected $mailID;
  public function getTableName() { return 'metamta_gorgeinboundreceipt'; }
  protected function getConfiguration() {
    return array(self::CONFIG_COLUMN_SCHEMA => array(
      'eventID' => 'text64', 'digest' => 'text64', 'state' => 'text16',
      'mailID' => 'id?',
    ), self::CONFIG_KEY_SCHEMA => array('eventID' => array(
      'columns' => array('eventID'), 'unique' => true,
    ))) + parent::getConfiguration();
  }
}
