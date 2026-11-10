<?php

abstract class PhabricatorOAuthServerDAO extends PhabricatorLiskDAO {

  /** Reject retained/reserved ownership before native lifecycle body I/O. */
  final public static function assertRecoveryLifecycleWriteAvailable() {
    if(class_exists(PhorgeRecoveryConduitProducerFence::class,false)){
      PhorgeRecoveryConduitProducerFence::assertWriterAvailable(null);}
    if(class_exists(PhorgeRecoveryRestoreService::class,false)){
      PhorgeRecoveryRestoreService::assertOAuthLifecycleWriteAvailable();}
    if(class_exists(PhorgeRecoverySessionProducerFence::class,false)){
      PhorgeRecoverySessionProducerFence::assertOAuthLifecycleWriteAvailable();}
  }

  public function delete() {
    self::assertRecoveryLifecycleWriteAvailable();
    return parent::delete();
  }

  public function getApplicationName() {
    return 'oauth_server';
  }

}
