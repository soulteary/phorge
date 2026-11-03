<?php

final class PhorgeGovernanceMediaHTTPProtocolTestCase extends PhutilTestCase {
  public function testOriginIsStrictHTTPSAuthorityOnly(){
    $this->assertEqual('https://media.example.invalid',PhorgeGovernanceMediaHTTPProtocol::origin('https://media.example.invalid/'));
    $this->assertEqual('https://media.example.invalid:9443',PhorgeGovernanceMediaHTTPProtocol::origin('https://media.example.invalid:9443/'));
    $refused=false;try{PhorgeGovernanceMediaHTTPProtocol::assertSeparateOrigins('https://same.example.invalid:9443','https://same.example.invalid:8443');}
    catch(RuntimeException $e){$refused=true;}$this->assertTrue($refused);
    foreach(array('http://media.example.invalid','https://user@media.example.invalid','https://media.example.invalid/path',
      'https://media.example.invalid?x','https://media.example.invalid/#x','https://media.example.invalid:65536/',
      'https://media.example.invalid.','https://.media.example.invalid','https://-media.example.invalid','https://media-.example.invalid',
      '//media.example.invalid','https://media..example.invalid','https://*.example.invalid') as $origin){
      $refused=false;try{PhorgeGovernanceMediaHTTPProtocol::origin($origin);}catch(RuntimeException $e){$refused=true;}
      $this->assertTrue($refused);
    }
  }
  public function testSameSiteUsesPublicAndPrivateSuffixBoundaries(){
    foreach(array(
      array('app.example.co.uk','files.example.co.uk'),
      array('app.tenant.github.io','files.tenant.github.io'),
      array('app.www.ck','files.www.ck'),
      array('app.xn--85x722f.xn--55qx5d.cn','files.xn--85x722f.xn--55qx5d.cn'),
      array('app.fixture.invalid','files.fixture.invalid')) as $hosts){
      PhorgeGovernanceMediaHTTPProtocol::assertSeparateOrigins('https://'.$hosts[0],'https://'.$hosts[1]);
      $this->assertEqual(PhorgeGovernanceMediaHTTPProtocol::siteDomain($hosts[0]),
        PhorgeGovernanceMediaHTTPProtocol::siteDomain($hosts[1]));
    }
    foreach(array(
      array('app.example.com','files.other.com'),
      array('example.co.uk','other.co.uk'),array('a.github.io','b.github.io'),
      array('a.foo.ck','b.foo.ck'),array('co.uk','files.co.uk'),
      array('127.0.0.1','127.0.0.2'),array('127.1','127.0.0.1'),
      array('2130706433','127.0.0.1'),array('0x7f000001','127.0.0.1')) as $hosts){
      $refused=false;
      try{PhorgeGovernanceMediaHTTPProtocol::assertSeparateOrigins('https://'.$hosts[0],'https://'.$hosts[1]);}
      catch(RuntimeException $error){$refused=true;}
      $this->assertTrue($refused,implode(' / ',$hosts));
    }
  }
  public function testPinnedUpstreamPublicSuffixCorpus(){
    $path=dirname(__FILE__,6).'/resources/governance/publicsuffix/test-cases.json';
    $data=json_decode(Filesystem::readFile($path),true,512,JSON_THROW_ON_ERROR);
    $this->assertTrue(count($data['cases'])>=50);
    foreach($data['cases'] as $case){
      $actual=null;
      try{$actual=PhorgeGovernanceMediaHTTPProtocol::siteDomain($case[0]);}
      catch(RuntimeException $error){}
      $this->assertEqual($case[1],$actual,var_export($case[0],true));
    }
  }
  public function testPayloadRejectsDuplicateEscapedKeysAndNonStringValues(){
    $valid='{"path":"/file/data/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/name","client_nonce":"'.str_repeat('a',64).'"}';
    $parsed=PhorgeGovernanceMediaHTTPProtocol::parsePayload($valid,array('client_nonce','path'));
    $this->assertEqual(str_repeat('a',64),$parsed['client_nonce']);
    foreach(array('{"path":"a","path":"b"}','{"path":"a","pa\\u0074h":"b"}',
      '{"path":null}','{"path":1}','{"path":[]}','{"path":{}}','{"path":"a",}',
      '{"path":"a"}junk','["a"]','{"unknown":"a"}') as $raw){
      $refused=false;try{PhorgeGovernanceMediaHTTPProtocol::parsePayload($raw,array('path'));}catch(Throwable $e){$refused=true;}
      $this->assertTrue($refused);
    }
  }
  public function testCookiesAreHostOnlyAndNeverParentDomainOrScriptReadable(){
    $response=PhorgeGovernanceMediaHTTPProtocol::setCookie(PhorgeGovernanceMediaHTTPProtocol::json(array('ready'=>true)),
      PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE,str_repeat('a',64),time()+3600);
    $cookies=array();foreach($response->getHeaders() as $h){if($h[0]==='Set-Cookie'){$cookies[]=$h[1];}}
    $this->assertEqual(1,count($cookies));$cookie=$cookies[0];
    foreach(array('__Host-phorge-media=','Path=/','Secure','HttpOnly','SameSite=Strict') as $required){$this->assertTrue(strpos($cookie,$required)!==false);}
    $this->assertFalse(stripos($cookie,'Domain=')!==false);
    $this->assertFalse(strpos($response->buildResponseString(),str_repeat('a',64))!==false);
    $saved_server=$_SERVER;$saved_cookie=$_COOKIE;$name=PhorgeGovernanceMediaHTTPProtocol::CREDENTIAL_COOKIE;
    $request=new AphrontRequest('files.example.invalid','/');
    try{
      $_COOKIE[$name]=str_repeat('b',64);$_SERVER['HTTP_COOKIE']='';
      $this->assertEqual(null,PhorgeGovernanceMediaHTTPProtocol::cookie($request,$name));
      $_SERVER['HTTP_COOKIE']=$name.'='.str_repeat('a',64);
      $this->assertEqual(str_repeat('a',64),PhorgeGovernanceMediaHTTPProtocol::cookie($request,$name));
      foreach(array('%5F%5FHost-phorge-media','"'.$name.'"','..Host-phorge-media') as $alias){
        $_SERVER['HTTP_COOKIE']=$alias.'='.str_repeat('a',64);
        $this->assertEqual(null,PhorgeGovernanceMediaHTTPProtocol::cookie($request,$name));
      }
      foreach(array($name.'='.str_repeat('a',64).'; '.$name.'='.str_repeat('b',64),
        $name.'="'.str_repeat('a',64).'"',$name.'=%61'.str_repeat('a',63)) as $wire){
        $_SERVER['HTTP_COOKIE']=$wire;$refused=false;
        try{PhorgeGovernanceMediaHTTPProtocol::cookie($request,$name);}catch(RuntimeException $e){$refused=true;}
        $this->assertTrue($refused);
      }
    }finally{$_SERVER=$saved_server;$_COOKIE=$saved_cookie;}

  }
  public function testFileCookieFirewallPreservesRawAmbiguityAndNoNativeCredentials(){
    $a=str_repeat('a',64);$b=str_repeat('b',64);
    $raw='phsid=native-secret; phusr=private; __Host-phorge-media='.$a.'; __Host-phorge-media-browser='.$b;
    $this->assertEqual('__Host-phorge-media='.$a.'; __Host-phorge-media-browser='.$b,
      PhorgeGovernanceMediaHTTPProtocol::fileCookieHeader($raw));
    $duplicate='__Host-phorge-media='.$a.'; __Host-phorge-media='.$b;
    $this->assertEqual($duplicate,PhorgeGovernanceMediaHTTPProtocol::fileCookieHeader($duplicate));
    foreach(array('__Host-phorge-media%3D='.$a,'__Host-phorge-media_browser='.$a,'phsid='.$a)as$alias){
      $this->assertEqual('',PhorgeGovernanceMediaHTTPProtocol::fileCookieHeader($alias));
    }
    $this->assertEqual('',PhorgeGovernanceMediaHTTPProtocol::fileCookieHeader(str_repeat('x',8193)));
  }
}
