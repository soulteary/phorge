<?php

/** Read-only runtime metadata inventory, never a subject assessment or permit. */
final class PhorgeGovernanceRecoverySourceInventory extends Phobject {
  const VERSION = 1;

  public static function requiredFamilies() {
    // Caller cannot remove requirements or declare any adapter complete.
    return array('account-stop','application-config','api-credentials','extensions-producers',
      'file-capabilities','media-credentials','object-automatic','organization',
      'policy','project-relationships','restore-lineage','spaces','web-credentials');
  }

  private static function roots() {
    return array('policy-object'=>'PhabricatorPolicyInterface',
      'policy-rule'=>'PhabricatorPolicyRule',
      'application'=>'PhabricatorApplication',
      'transaction-editor'=>'PhabricatorApplicationTransactionEditor',
      'configuration-source'=>'PhabricatorConfigSource',
      'configuration-options'=>'PhabricatorApplicationConfigOptions',
      'conduit-producer'=>'ConduitAPIMethod',
      'web-producer'=>'PhabricatorController',
      'cli-producer'=>'PhabricatorManagementWorkflow',
      'async-producer'=>'PhabricatorWorker');
  }

  private static function ancestry($class,array $parents,array &$memo,array $active=array()) {
    if(isset($memo[$class])){return $memo[$class];}
    if(isset($active[$class])||count($active)>128){throw new RuntimeException('recovery_inventory_class_graph_refused');}
    $active[$class]=true;$result=array($class=>true);
    $direct=idx($parents,$class,array());
    if(is_string($direct)){$direct=array($direct);}
    if(!is_array($direct)||!array_is_list($direct)||count($direct)>64){throw new RuntimeException('recovery_inventory_class_graph_refused');}
    foreach($direct as$parent){
      if(!is_string($parent)||strlen($parent)>256||!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$parent)){throw new RuntimeException('recovery_inventory_class_graph_refused');}
      $result+=self::ancestry($parent,$parents,$memo,$active);
    }
    return $memo[$class]=$result;
  }

  /** Public pure description is explicitly untrusted input, not runtime proof. */
  public static function describeMaps(array $maps,array $configuration_classes=array()) {
    if(count($maps)>8||count($configuration_classes)>64){throw new RuntimeException('recovery_inventory_budget');}
    $classes=array();$parents=array();
    foreach($maps as$library=>$map){
      if(!is_string($library)||strlen($library)>64||!preg_match('/\A[a-z][a-z0-9_-]*\z/D',$library)||!is_array($map)||!is_array(idx($map,'class'))||!is_array(idx($map,'xmap'))||
        count($map['class'])>8192||count($map['xmap'])>8192){throw new RuntimeException('recovery_inventory_map_refused');}
      foreach($map['class']as$class=>$path){
        if(!is_string($class)||strlen($class)>256||!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$class)||
          !is_string($path)||strlen($path)>512||!preg_match('@\A(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.php\z@D',$path)||
          strpos($path,'..')!==false||isset($classes[$class])){throw new RuntimeException('recovery_inventory_map_refused');}
        $classes[$class]=array('library'=>$library,'path'=>$path);
        if(count($classes)>8192){throw new RuntimeException('recovery_inventory_budget');}
      }
      foreach($map['xmap']as$class=>$values){
        if(!is_string($class)||array_key_exists($class,$parents)){throw new RuntimeException('recovery_inventory_class_graph_refused');}
        $parents[$class]=$values;
      }
    }
    ksort($classes,SORT_STRING);$consumers=array();$memo=array();$types=self::roots();
    foreach($classes as$class=>$location){
      $ancestors=self::ancestry($class,$parents,$memo);$kinds=array();
      foreach($types as$kind=>$root){if(isset($ancestors[$root])){$kinds[]=$kind;}}
      if($kinds){$consumers[]=array('class'=>$class,'library'=>$location['library'],'path'=>$location['path'],
        'kinds'=>$kinds,'authority_adapter_status'=>'uncovered','runtime_behavior_proved'=>false);}
    }
    foreach($configuration_classes as$class){if(!is_string($class)||strlen($class)>256||!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$class)){
      throw new RuntimeException('recovery_inventory_configuration_refused');}}
    $families=array();foreach(self::requiredFamilies()as$id){$families[]=array('id'=>$id,'status'=>'uncovered');}
    return array('schema'=>'native-recovery-source-inventory-v1','input_kind'=>'untrusted-map-description',
      'consumers'=>$consumers,'consumer_set_sha256'=>hash('sha256',serialize($consumers)),
      'configuration_source_classes'=>$configuration_classes,
      'configuration_shape_sha256'=>hash('sha256',serialize($configuration_classes)),'required_families'=>$families,
      'blockers'=>array('all-source-adapters-unimplemented','product-config-values-unassessed',
        'actual-source-rows-and-generations-unassessed','initial-stop-and-restore-lineage-unassessed'),
      'actor_authority'=>false,'all_sources_complete'=>false,'can_execute'=>false);
  }

  /** Uses actual trusted loader metadata. No constructor, query or source getter. */
  public static function inspectRuntime() {
    $profile=PhorgeGovernanceNativeRuntimeProfile::assertCurrent();
    $loader=PhutilBootloader::getInstance();$maps=array();
    foreach(array('arcanist','phorge')as$library){$maps[$library]=$loader->getLibraryMapWithoutExtensions($library);}
    $stack=(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->getValue();$configuration=array();
    if($stack!==null){
      if(get_class($stack)!==PhabricatorConfigStackSource::class){throw new RuntimeException('recovery_inventory_configuration_refused');}
      foreach($stack->getStack()as$source){$configuration[]=get_class($source);}
    }
    $result=self::describeMaps($maps,$configuration);
    $result['input_kind']='actual-fixed-native-loader-metadata';$result['runtime_profile']=$profile;
    // Exact map hashes version the loaded class collection, not the source
    // method bodies or deployment configuration. Those remain explicit gaps.
    $result['config_stack_initialized']=$stack!==null;
    return $result;
  }
}
