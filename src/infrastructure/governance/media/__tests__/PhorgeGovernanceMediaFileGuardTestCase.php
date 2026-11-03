<?php

final class PhorgeGovernanceMediaFileGuardTestCase extends PhutilTestCase {
  public function testActiveFormatsCannotBecomeInlineThroughMimeConfiguration(){
    foreach(array('text/html','image/svg+xml','application/javascript','text/javascript','application/xml','text/xml','application/pdf','text/css','application/xhtml+xml') as $mime){
      $this->assertFalse(PhorgeGovernanceMediaFileGuard::safeInlineMime($mime));
    }
    foreach(array('image/png','image/jpeg','text/plain; charset=UTF-8','video/mp4') as $mime){
      $this->assertTrue(PhorgeGovernanceMediaFileGuard::safeInlineMime($mime));
    }
  }
  public function testNativeResourceMapUsesEmptyUnmatchedInstanceCapture(){
    $application=new PhabricatorFilesApplication();
    $routes=(new ReflectionMethod(PhabricatorFilesApplication::class,'getResourceSubroutes'))->invoke($application);
    $map=(new AphrontRoutingMap())->setSite(new PhabricatorResourceSite())->setApplication($application)->setRoutes(array('/file/'=>$routes));
    $plain=$map->routePath('/file/data/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/name');
    $foreign=$map->routePath('/file/data/@foreign/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/name');
    $this->assertEqual('',$plain->getURIData()['instance']);
    $this->assertEqual('foreign',$foreign->getURIData()['instance']);
  }
  public function testStaticSourcesAreFixedProducerBytesNotAvatarFileAuthority(){
    $sources=PhorgeGovernanceMediaStaticController::sources();$this->assertEqual(4,count($sources));
    foreach($sources as $digest=>$entry){
      $this->assertEqual($digest,hash('sha256',$entry['data']));
      $this->assertTrue(strpos($entry['name'],'favicon/default-')===0);
      $this->assertFalse(isset($entry['filePHID']));
    }
  }
}
