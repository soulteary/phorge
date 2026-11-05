<?php
/** Test-only CI data consumer. This never authorizes recovery or a production path. */
final class GovernanceNativeRRCIProfile {
  private const SOURCES=array('.github/workflows/native-rr-owner-build.yml','scripts/governance/check_completion.py','scripts/governance/check_native_user_rr_mdl.py','support/governance/recovery/native-writer-rr-ci-profile.php','support/governance/recovery/native-writer-rr-parent.php','support/governance/recovery/owned-process/go.mod','support/governance/recovery/owned-process/go.sum','support/governance/recovery/owned-process/main_linux.go','support/governance/recovery/owned-process/request.go','support/governance/recovery/owned-process/request_test.go','tests/governance/native_rr_ci/anchor.py','tests/governance/native_rr_ci/build_owner.py','tests/governance/native_rr_ci/execution.py','tests/governance/native_rr_ci/provenance.py','tests/governance/native_rr_ci/runner.py');
  private $binary;private $php;private $control;
  private function __construct(){}
  private function __clone(){}
  public function __serialize(){throw new RuntimeException('native_rr_CI_profile_not_serializable');}
  public function __unserialize(array$value){throw new RuntimeException('native_rr_CI_profile_not_serializable');}
  public function __debugInfo(){return array('profile'=>'isolated-native-RR21-linux-amd64-v1','production_authority'=>false);}
  private static function canonical($value){
    if(is_array($value)){if(!array_is_list($value)){ksort($value);}foreach($value as$key=>$item){$value[$key]=self::normalize($item);}}
    return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
  }
  private static function normalize($value){if(is_array($value)){if(!array_is_list($value)){ksort($value);}foreach($value as$key=>$item){$value[$key]=self::normalize($item);}}return$value;}
  private static function json($raw){$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($value)||self::canonical($value)!==$raw){throw new RuntimeException('native_rr_CI_noncanonical_data');}return$value;}
  private static function fields(array$value,array$keys){$actual=array_keys($value);sort($actual);sort($keys);if($actual!==$keys){throw new RuntimeException('native_rr_CI_shape_unknown');}}
  private static function file($path,$mode,$limit){
    clearstatcache(true,$path);$before=lstat($path);if(!$before||is_link($path)||!is_file($path)||($before['mode']&0777)!==$mode||$before['uid']!==posix_geteuid()||$before['nlink']!==1||$before['size']>$limit){throw new RuntimeException('native_rr_CI_private_file_unknown');}
    $raw=file_get_contents($path);clearstatcache(true,$path);$after=lstat($path);if(!is_string($raw)||!$after||$before['dev']!==$after['dev']||$before['ino']!==$after['ino']||strlen($raw)!==$before['size']){throw new RuntimeException('native_rr_CI_file_changed');}return$raw;
  }
  /** Values originate from the fixed parent runner, never from the descriptor. */
  public static function verifyData(array$control,$anchorRaw,$seedRaw,array$currentSources,$binaryBytes,$phpBytes,$parserBytes){
    self::fields($control,array('schema_version','profile','directory','dev','ino','anchor_sha256','source_revision','run_id','run_attempt','source_kind','PR_head_measured'));
    if($control['schema_version']!==1||$control['profile']!=='isolated-native-RR21-linux-amd64-v1'||!is_int($control['dev'])||!is_int($control['ino'])||$control['dev']<0||$control['ino']<0||!is_string($control['directory'])||!preg_match('/\A\/tmp\/governance-native-rr-ci-([a-f0-9]{32})\z/D',$control['directory'],$match)||!is_string($control['source_revision'])||!preg_match('/\A[a-f0-9]{40}\z/D',$control['source_revision'])||!is_string($control['anchor_sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$control['anchor_sha256'])){throw new RuntimeException('native_rr_CI_control_refused');}
    if(!in_array($control['source_kind'],array('pull-request-synthetic-merge','workflow-dispatch-checkout'),true)||$control['PR_head_measured']!==false){throw new RuntimeException('native_rr_CI_source_kind_unknown');}
    foreach(array('run_id','run_attempt')as$key){if(!is_string($control[$key])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$control[$key])){throw new RuntimeException('native_rr_CI_run_unknown');}}
    if(!hash_equals($control['anchor_sha256'],hash('sha256',$anchorRaw))){throw new RuntimeException('native_rr_CI_external_anchor_mismatch');}
    $anchor=self::json($anchorRaw);$seed=self::json($seedRaw);$common=array('schema_version','profile','owner','source_revision','run_id','run_attempt','source_kind','PR_head_measured','source_sha256');
    self::fields($seed,$common);self::fields($anchor,array_merge($common,array('seed_sha256','build_record_sha256','build_info_sha256','binary_sha256','php_binary_sha256','parser_sha256','go_binary_sha256')));
    foreach($common as$key){if($anchor[$key]!==$seed[$key]){throw new RuntimeException('native_rr_CI_seed_changed');}}
    foreach(array('schema_version','profile','source_revision','run_id','run_attempt','source_kind','PR_head_measured')as$key){if($anchor[$key]!==$control[$key]){throw new RuntimeException('native_rr_CI_wrong_run');}}
    $expectedSources=self::SOURCES;$actualSources=array_keys($currentSources);sort($actualSources);if($actualSources!==$expectedSources){throw new RuntimeException('native_rr_CI_incomplete_source_binding');}foreach($currentSources as$pin){if(!is_string($pin)||!preg_match('/\A[a-f0-9]{64}\z/D',$pin)){throw new RuntimeException('native_rr_CI_source_digest_unknown');}}
    if($anchor['owner']!==$match[1]||$anchor['source_sha256']!==$currentSources||!hash_equals($anchor['seed_sha256'],hash('sha256',$seedRaw))){throw new RuntimeException('native_rr_CI_source_or_owner_changed');}
    foreach(array('build_record_sha256','build_info_sha256','binary_sha256','php_binary_sha256','parser_sha256','go_binary_sha256')as$key){if(!is_string($anchor[$key])||!preg_match('/\A[a-f0-9]{64}\z/D',$anchor[$key])){throw new RuntimeException('native_rr_CI_digest_unknown');}}
    foreach(array(array('binary_sha256',$binaryBytes),array('php_binary_sha256',$phpBytes),array('parser_sha256',$parserBytes))as$pair){if(!is_string($pair[1])||!hash_equals($anchor[$pair[0]],hash('sha256',$pair[1]))){throw new RuntimeException('native_rr_CI_actual_bytes_changed');}}
    return array('profile'=>$control['profile'],'source_revision'=>$control['source_revision'],'production_authority'=>false);
  }
  public static function fromEnvironment(){
    $raw=getenv('PHORGE_GOVERNANCE_NATIVE_RR_CI_CONTROL');if(!is_string($raw)||strlen($raw)>4096){throw new RuntimeException('native_rr_CI_external_control_missing');}return self::open(self::json($raw));
  }
  public static function open(array$control){
    if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_NATIVE_RR_EXECUTION_PROFILE')!=='isolated-native-RR21-linux-amd64-v1'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('GITHUB_SHA')!==($control['source_revision']??null)||getenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION')!==($control['source_revision']??null)){throw new RuntimeException('native_rr_CI_fixed_runner_required');}
    if(getenv('GITHUB_JOB')!=='owner-build'||getenv('GITHUB_WORKFLOW_REF')!=='soulteary/phorge/.github/workflows/native-rr-owner-build.yml@'.getenv('GITHUB_REF')||getenv('GITHUB_RUN_ID')!==($control['run_id']??null)||getenv('GITHUB_RUN_ATTEMPT')!==($control['run_attempt']??null)){throw new RuntimeException('native_rr_CI_untrusted_workflow_or_run');}
    $event=getenv('GITHUB_EVENT_NAME');$ref=getenv('GITHUB_REF');if((($control['source_kind']??null)==='pull-request-synthetic-merge'&&($event!=='pull_request'||!preg_match('/\Arefs\/pull\/[1-9][0-9]*\/merge\z/D',$ref)))||(($control['source_kind']??null)==='workflow-dispatch-checkout'&&($event!=='workflow_dispatch'||!preg_match('/\Arefs\/(?:heads|tags)\/[A-Za-z0-9_.\/\-]+\z/D',$ref)))){throw new RuntimeException('native_rr_CI_event_unknown');}
    $directory=$control['directory']??null;if(!is_string($directory)||!preg_match('/\A\/tmp\/governance-native-rr-ci-[a-f0-9]{32}\z/D',$directory)){throw new RuntimeException('native_rr_CI_directory_refused');}
    clearstatcache(true,$directory);$s=lstat($directory);if(!$s||is_link($directory)||!is_dir($directory)||($s['mode']&0777)!==0700||$s['uid']!==posix_geteuid()||$s['dev']!==($control['dev']??null)||$s['ino']!==($control['ino']??null)){throw new RuntimeException('native_rr_CI_directory_owner_unknown');}
    $anchor=self::file($directory.'/anchor.json',0400,65536);$seed=self::file($directory.'/seed.json',0400,65536);$decoded=self::json($seed);$sources=array();
    $sourceRows=$decoded['source_sha256']??null;if(!is_array($sourceRows)){throw new RuntimeException('native_rr_CI_source_set_unknown');}$sourceKeys=array_keys($sourceRows);sort($sourceKeys);if($sourceKeys!==self::SOURCES){throw new RuntimeException('native_rr_CI_source_set_unknown');}
    foreach($sourceRows as$path=>$pin){if(!is_string($path)||!preg_match('/\A(?:support\/governance\/recovery\/|scripts\/governance\/|tests\/governance\/native_rr_ci\/|\.github\/workflows\/)[A-Za-z0-9_\/.\-]+\z/D',$path)||str_contains($path,'..')){throw new RuntimeException('native_rr_CI_source_path_refused');}$source='/tmp/governance-src/'.$path;clearstatcache(true,$source);if(is_link($source)||!is_file($source))throw new RuntimeException('native_rr_CI_source_unknown');$sources[$path]=hash_file('sha256',$source);}
    $binary='/tmp/governance-bin/native-rr-process-owner-ci';$php='/usr/bin/php';$parser='/tmp/governance-src/support/runtime/src/parser/xhpast/bin/xhpast';
    foreach(array($binary,$php,$parser)as$path){clearstatcache(true,$path);if(!is_file($path)||!is_executable($path)||filesize($path)<1||filesize($path)>33554432){throw new RuntimeException('native_rr_CI_actual_executable_missing');}}
    $binaryStat=lstat($binary);$parserStat=lstat($parser);if(!$binaryStat||!$parserStat||$binaryStat['uid']!==posix_geteuid()||$parserStat['uid']!==posix_geteuid()||$binaryStat['nlink']!==1||$parserStat['nlink']!==1||($binaryStat['mode']&0777)!==0500){throw new RuntimeException('native_rr_CI_private_binary_unknown');}
    if(is_link($binary)||is_link($parser)){throw new RuntimeException('native_rr_CI_executable_alias_refused');}
    self::verifyData($control,$anchor,$seed,$sources,file_get_contents($binary),file_get_contents($php),file_get_contents($parser));$self=new self();$self->binary=$binary;$self->php=$php;$self->control=$control;return$self;
  }
  public function getBinary(){$current=self::open($this->control);return$current->binary;}
  public function getPHP(){$current=self::open($this->control);return$current->php;}
}
