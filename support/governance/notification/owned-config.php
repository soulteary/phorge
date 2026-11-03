<?php
/** Private fixture configuration only; never a deployment loader. */
function governance_private_mail_config($directory){
  if(!is_string($directory)||is_link($directory)||realpath($directory)!==$directory||
    realpath(dirname($directory))!==realpath(sys_get_temp_dir())||
    !preg_match('/\Aphorge-private-mail-wire-[a-f0-9]{24}\z/',basename($directory))||
    (fileperms($directory)&0777)!==0700){throw new RuntimeException('private_mail_directory_refused');}
  foreach(array('owner','router.json')as$name){$path=$directory.'/'.$name;
    if(!is_file($path)||is_link($path)||(fileperms($path)&0777)!==0600||filesize($path)>65536){throw new RuntimeException('private_mail_config_refused');}}
  $data=json_decode(file_get_contents($directory.'/router.json'),true,16,JSON_THROW_ON_ERROR);
  $keys=array_keys($data);sort($keys);
  if($keys!==array('callback_origin','file_token','file_uri','fixture_nonce','mailer_token','mailer_uri','owner','service_token','source_sha256','writer')){
    throw new RuntimeException('private_mail_config_shape_refused');}
  foreach(array('owner','fixture_nonce','file_token','mailer_token','service_token','source_sha256')as$key){
    if(!is_string($data[$key])||!preg_match('/\A[a-f0-9]{64}\z/',$data[$key])){throw new RuntimeException('private_mail_config_value_refused');}}
  if(!hash_equals($data['owner'],file_get_contents($directory.'/owner'))){throw new RuntimeException('private_mail_owner_refused');}
  foreach(array('callback_origin','file_uri','mailer_uri')as$key){
    if(!is_string($data[$key])||!preg_match('@\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z@D',$data[$key],$m)||(int)$m[1]>65535){
      throw new RuntimeException('private_mail_loopback_required');}}
  $writer=$data['writer'];$keys=array_keys($writer);sort($keys);
  if($keys!==array('host','namespace','password','port','user')||$writer['host']!=='127.0.0.1'||
    !is_int($writer['port'])||$writer['port']<1||$writer['port']>65535||
    !is_string($writer['namespace'])||!preg_match('/\Agovernance_native_[a-f0-9]{16}\z/',$writer['namespace'])||
    !is_string($writer['user'])||!preg_match('/\Agov_c_writer_[a-f0-9]{12}\z/',$writer['user'])||
    !is_string($writer['password'])||!preg_match('/\A[a-f0-9]{48}\z/',$writer['password'])){throw new RuntimeException('private_mail_writer_refused');}
  return$data;
}
function governance_private_mail_proof(array$data){
  $writer=$data['writer'];mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
  $conn=new mysqli($writer['host'],$writer['user'],$writer['password'],'',$writer['port']);$conn->set_charset('utf8mb4');
  try{
    $seen=array();foreach($conn->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM)as$row){$grant=$row[0];
      if(preg_match('/\AGRANT USAGE ON \*\.\* TO /',$grant)){continue;}
      if(str_contains($grant,'WITH GRANT OPTION')||!preg_match('/\AGRANT (.+) ON `([^`]+)`\.\* TO /',$grant,$m)){
        throw new RuntimeException('private_mail_writer_grants_refused');}
      $db=str_replace('\\_','_',$m[2]);if(!preg_match('/\A'.preg_quote($writer['namespace'],'/').'_[a-z_]+\z/',$db)){
        throw new RuntimeException('private_mail_writer_scope_refused');}
      $actual=array_map('trim',explode(',',$m[1]));sort($actual);$expected=$db===$writer['namespace'].'_governance'?array('SELECT'):array('DELETE','INSERT','SELECT','UPDATE');
      if($actual!==$expected){throw new RuntimeException('private_mail_writer_grants_refused');}$seen[$db]=true;
    }
    if(!isset($seen[$writer['namespace'].'_user'],$seen[$writer['namespace'].'_metamta'])){throw new RuntimeException('private_mail_writer_scope_refused');}
    $row=$conn->query('SELECT marker,quickstart_sha256,owner_nonce FROM `'.$writer['namespace'].'_governance`.`fixture_guard` WHERE id=1')->fetch_assoc();
    if(!$row||$row['marker']!=='N07.NATIVE-SOURCE.v1:synthetic-only'||!hash_equals($data['source_sha256'],$row['quickstart_sha256'])||
      !hash_equals($data['fixture_nonce'],$row['owner_nonce'])){throw new RuntimeException('private_mail_fixture_lineage_refused');}
    return$conn;
  }catch(Throwable$error){$conn->close();throw$error;}
}
function governance_private_mail_install_env(array$data){
  if(PhabricatorEnv::getConfigSourceStack()!==null||(new ReflectionProperty(LiskDAO::class,'connections'))->getValue()||AphrontWriteGuard::isGuardActive()){
    throw new RuntimeException('private_mail_inherited_context_refused');}
  $w=$data['writer'];$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
  $stack->pushSource(new PhabricatorConfigDictionarySource(array('mysql.host'=>$w['host'],'mysql.port'=>$w['port'],
    'mysql.user'=>$w['user'],'mysql.pass'=>$w['password'],'storage.default-namespace'=>$w['namespace'],
    'phabricator.cache-namespace'=>$w['namespace'],'phabricator.base-uri'=>$data['callback_origin'].'/',
    'phabricator.production-uri'=>$data['callback_origin'].'/','cluster.databases'=>array(),'cluster.search'=>array(),
    'security.require-https'=>false,'policy.allow-public'=>false,'governance.organization.enabled'=>false,
    'governance.media-exchange.enabled'=>false,'governance.private-mail.enabled'=>true,'metamta.gorge-delivery-mode'=>'native',
    'gorge.file.uri'=>$data['file_uri'],'gorge.file.token'=>$data['file_token'],
    'gorge.conduit.uri'=>$data['callback_origin'],'gorge.conduit.token'=>$data['service_token'],
    'gorge.service-policies'=>array('file'=>'required','mailer'=>'required','conduit'=>'required'),
    'cluster.mailers'=>array(array('key'=>'isolated-physical','type'=>'gorge','inbound'=>false,
      'options'=>array('uri'=>$data['mailer_uri'],'token'=>$data['mailer_token']))))));
  (new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
  (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  (new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,null);
  // Real fixed native application setting is reloaded in every fresh callback.
  // No caller-supplied capability boolean or arbitrary config key is accepted.
  $entry=PhabricatorConfigEntry::loadConfigEntry('phabricator.application-settings');
  if($entry->getID()&&!$entry->getIsDeleted()){
    $value=$entry->getValue();$app=(new PhabricatorPeopleApplication())->getPHID();
    if(!is_array($value)||array_keys($value)!==array($app)||
      !isset($value[$app]['policy'][PeopleCreateUsersCapability::CAPABILITY])){
      throw new RuntimeException('private_mail_invite_setting_refused');}
    foreach($stack->getStack()as$source){if(get_class($source)===PhabricatorConfigDictionarySource::class){
      $source->setKeys(array('phabricator.application-settings'=>$value));break;}}
    (new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
  }
}
