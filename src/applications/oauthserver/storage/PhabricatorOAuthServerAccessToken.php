<?php

final class PhabricatorOAuthServerAccessToken
  extends PhabricatorOAuthServerDAO {

  protected function insertRecordIntoDatabase($mode) {
    if(class_exists(PhorgeRecoveryConduitProducerFence::class,false)){
      PhorgeRecoveryConduitProducerFence::assertWriterAvailable(null);}
    if(!PhorgeGovernanceRecoveryGuard::enabled()){
      return parent::insertRecordIntoDatabase($mode);
    }
    return PhorgeRecoveryConduitProducerFence::insertOAuthAccessToken($this,$mode,
      function()use($mode){return parent::insertRecordIntoDatabase($mode);});
  }

  protected $id;
  protected $token;
  protected $userPHID;
  protected $clientPHID;

  protected function getConfiguration() {
    return array(
      self::CONFIG_COLUMN_SCHEMA => array(
        'token' => 'text32',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'token' => array(
          'columns' => array('token'),
          'unique' => true,
        ),
      ),
    ) + parent::getConfiguration();
  }

}
