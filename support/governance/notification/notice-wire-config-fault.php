<?php
/** Test-only genuine source failure; never returns an authorization boolean. */
final class PhorgeNoticeWireFaultSource extends PhabricatorConfigProxySource {
  private $key;
  public function __construct(PhabricatorConfigSource $source,$key){
    if(!in_array($key,array('governance.notifications.enabled','governance.private-mail.enabled'),true)){
      throw new RuntimeException('notice_wire_fault_key_refused');}
    $this->key=$key;$this->setSource($source);
  }
  public function getKeys(array $keys){
    if(in_array($this->key,$keys,true)){throw new RuntimeException('notice_wire_configuration_source_failed');}
    return parent::getKeys($keys);
  }
}
function governance_notice_wire_install_fault(array$config){
  $directory=$config['directory'];$path=$directory.'/notice-fault.json';
  if(!file_exists($path)){return;}
  $fault=governance_notice_wire_json($directory,'notice-fault.json',1024);$keys=array_keys($fault);sort($keys);
  if($keys!==array('key','owner')||($fault['owner']??null)!==$config['base']['owner']||
    !in_array($fault['key'],array('governance.notifications.enabled','governance.private-mail.enabled'),true)){
    throw new RuntimeException('notice_wire_fault_owner_refused');}
  $stack=PhabricatorEnv::getConfigSourceStack();
  $source=new PhorgeNoticeWireFaultSource($stack,$fault['key']);
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,(new PhabricatorConfigStackSource())->pushSource($source));
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
}
