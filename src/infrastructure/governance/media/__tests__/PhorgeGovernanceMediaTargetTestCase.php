<?php

final class PhorgeGovernanceMediaTargetTestCase extends PhutilTestCase {
  public function testNativeUnicodeFileNameLimitFitsBoundedTargets() {
    $instance=str_repeat('i',64);$key=str_repeat('k',64);$phid='PHID-FILE-aaaaaaaaaaaaaaaaaaaa';
    foreach(array(str_repeat('测',250).'.xlsx',str_repeat('😀',250).'.txt')as$name){
      $normalized=PhabricatorFile::normalizeFileName($name);
      $path='/file/download/@'.$instance.'/'.$key.'/'.$phid.'/'.phutil_escape_uri($normalized);
      $this->assertTrue(strlen($path)>2048);
      $this->assertTrue(strlen($path)<=PhorgeGovernanceMediaTarget::MAX_RETURN_PATH_BYTES);
      $target=PhorgeGovernanceMediaTarget::fromPath($path,$instance);
      $this->assertEqual($path,$target->getReturnPath());
      $this->assertEqual($normalized,phutil_json_decode($target->getCanonicalJSON())['filename']);
    }
    $refused=false;try{PhorgeGovernanceMediaTarget::fromPath('/file/data/key/'.$phid.'/'.
      str_repeat('x',PhorgeGovernanceMediaTarget::MAX_FILENAME_BYTES+1));}
    catch(InvalidArgumentException$error){$refused=true;}
    $this->assertTrue($refused);
  }
  public function testNativeDataDownloadTransformAndUnicodeCanonicalization() {
    $phid='PHID-FILE-aaaaaaaaaaaaaaaaaaaa';
    foreach(array('/file/data/key/'.$phid.'/report.txt','/file/download/key/'.$phid.'/'.rawurlencode('部门报告.xlsx'),
      '/file/xform/thumbnail/'.$phid.'/key/') as $path){
      $target=PhorgeGovernanceMediaTarget::fromPath($path);
      $this->assertEqual($path,$target->getReturnPath());
      $this->assertEqual(64,strlen($target->getDigest()));
      $this->assertEqual($target->getDigest(),PhorgeGovernanceMediaTarget::fromPath($path)->getDigest());
    }
    $path='/file/data/@native_instance/key/'.$phid.'/name';
    $this->assertEqual($path,PhorgeGovernanceMediaTarget::fromPath($path,'native_instance')->getReturnPath());
  }
  public function testAmbiguousAndForeignTargetsAreRefused() {
    $base='/file/data/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/';
    foreach(array($base.'..',$base.'.',$base.'a/b',$base.'%2fsecret',$base.'%5Csecret',$base.'%252fsecret',
      $base.'%00',$base.'%0A',$base.'a?token=x',$base.'a#x',$base.'%61',$base.'%e9%83%a8',
      '//other.invalid'.$base.'name','https://other.invalid'.$base.'name',
      '/file/data/@other/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/name',
      '/file/data/key/PHID-USER-aaaaaaaaaaaaaaaaaaaa/name',
      '/file/xform/thumbnail/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/key/?regenerate=1') as $path){
      $refused=false;try{PhorgeGovernanceMediaTarget::fromPath($path);}catch(InvalidArgumentException $e){$refused=true;}
      $this->assertTrue($refused);
    }
  }
  public function testTargetFieldsCannotBeMutatedOrSerialized() {
    $path='/file/data/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/name';
    $target=PhorgeGovernanceMediaTarget::fromPath($path);$json=phutil_json_decode($target->getCanonicalJSON());
    $json['return_path']='/auth/login/';
    $this->assertEqual($path,$target->getReturnPath());
    $refused=false;try{serialize($target);}catch(RuntimeException $e){$refused=true;}
    $this->assertTrue($refused);
    $this->assertFalse(PhorgeGovernanceMediaTarget::fromPath(str_replace('/data/','/download/',$path))->getDigest()===$target->getDigest());
  }
}
