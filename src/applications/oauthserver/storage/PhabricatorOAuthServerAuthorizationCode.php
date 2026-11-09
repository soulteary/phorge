<?php

final class PhabricatorOAuthServerAuthorizationCode
  extends PhabricatorOAuthServerDAO {

  protected function insertRecordIntoDatabase($mode) {
    if(class_exists(PhorgeRecoveryConduitProducerFence::class,false)){
      PhorgeRecoveryConduitProducerFence::assertWriterAvailable(null);}
    if(!PhorgeGovernanceRecoveryGuard::enabled()){
      return parent::insertRecordIntoDatabase($mode);
    }
    return PhorgeRecoveryConduitProducerFence::insertOAuthAuthorizationCode($this,$mode,
      function()use($mode){return parent::insertRecordIntoDatabase($mode);});
  }

  protected $id;
  protected $code;
  protected $clientPHID;
  protected $clientSecret;
  protected $userPHID;
  protected $redirectURI;

  protected function getConfiguration() {
    return array(
      self::CONFIG_COLUMN_SCHEMA => array(
        'code' => 'text32',
        'clientSecret' => 'text32',
        'redirectURI' => 'text255',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'code' => array(
          'columns' => array('code'),
          'unique' => true,
        ),
      ),
    ) + parent::getConfiguration();
  }

}
