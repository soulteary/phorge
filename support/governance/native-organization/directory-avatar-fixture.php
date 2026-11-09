<?php

/** Core storage dependency for real native avatars, not a media backend test. */
function governance_directory_avatar_profile() {
  $uri=PhabricatorEnv::getEnvConfig('gorge.file.uri');
  $token=PhabricatorEnv::getEnvConfig('gorge.file.token');
  if(!is_string($uri)||!preg_match('/\Ahttp:\/\/127[.]0[.]0[.]1:([0-9]{1,5})\z/D',$uri,$m)||
    (int)$m[1]<1||(int)$m[1]>65535||!is_string($token)||!preg_match('/\A[0-9a-f]{64}\z/D',$token)||
    !PhabricatorGorgeFileStorageClient::isConfigured()||
    PhabricatorEnv::getEnvConfig('gorge.image.uri')!==null||
    PhabricatorEnv::getEnvConfig('gorge.image.builtin-mode')!=='legacy'||
    PhabricatorEnv::getEnvConfig('gorge.file.uploads')!==false||
    PhabricatorEnv::getEnvConfig('gorge.file.deletion-outbox')!==false||
    PhabricatorEnv::getEnvConfig('governance.media-exchange.enabled')!==false||
    PhabricatorEnv::getEnvConfig('gorge.notification.outbox')!==false||
    PhabricatorEnv::getEnvConfig('gorge.taskqueue.uri')!==null||
    !function_exists('imagecreatefromstring')||empty(gd_info()['PNG Support'])){
    throw new RuntimeException('directory_native_avatar_profile_refused');
  }
}
function governance_directory_avatar_bytes($bytes,$size,$hash) {
  if(!is_string($bytes)||strlen($bytes)<1||strlen($bytes)>1048576||
    strlen($bytes)!==$size||!is_string($hash)||
    !hash_equals($hash,PhabricatorFile::hashFileContent($bytes))||
    substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n"){
    throw new RuntimeException('directory_native_avatar_readback_refused');
  }
  $info=@getimagesizefromstring($bytes);
  if(!$info||$info[0]!==400||$info[1]!==400||$info['mime']!=='image/png'){
    throw new RuntimeException('directory_native_avatar_dimensions_refused');
  }
  $image=@imagecreatefromstring($bytes);
  if(!$image||imagesx($image)!==400||imagesy($image)!==400){
    throw new RuntimeException('directory_native_avatar_decode_refused');
  }
  unset($image);
  return array('readback_bytes'=>strlen($bytes),'readback_sha256'=>hash('sha256',$bytes),
    'width'=>400,'height'=>400,'actual_PNG_decode'=>true,'content_hash_match'=>true);
}
function governance_directory_prepare_avatars($fixture,array $users,callable $check) {
  if(get_class($fixture)!==PhorgeGovernanceCompletionFixture::class){throw new RuntimeException('directory_avatar_owned_fixture_required');}
  $fixture->assertOwned();governance_directory_avatar_profile();
  if(array_keys($users)!==array('admin','ordinary','parent','child','both','addedweb','addedapi')){
    throw new RuntimeException('directory_avatar_fixed_users_required');
  }
  $check(true,'directory-transports-paired-file-profile-isolated');$rows=array();
  foreach($users as$role=>$user){
    $file=(new PhabricatorFilesComposeAvatarBuiltinFile())->updateUser($user);
    $fresh=(new PhabricatorUser())->load($user->getID());
    $stored=(new PhabricatorFile())->load($file->getID());
    if(!$fresh||!$stored||$fresh->getPHID()!==$user->getPHID()||
      $stored->getPHID()!==$file->getPHID()||$stored->getStorageEngine()!=='gorge'||
      $fresh->getDefaultProfileImagePHID()!==$stored->getPHID()||
      $fresh->getDefaultProfileImageVersion()!==PhabricatorFilesComposeAvatarBuiltinFile::VERSION){
      throw new RuntimeException('directory_native_avatar_persistence_refused');
    }
    // Fresh File DAO forces the real Gorge reader, not the freshly generated
    // in-memory PNG or a manufactured cache/PHID standing in for persistence.
    $data=governance_directory_avatar_bytes($stored->loadFileData(),(int)$stored->getByteSize(),$stored->getContentHash());
    $check(true,'directory-transports-native-avatar-'.$role.'-stored-readback');
    $rows[]=array('role'=>$role,'storage_engine'=>'gorge','fresh_native_user_and_file_match'=>true)+$data;
  }
  return $rows;
}
