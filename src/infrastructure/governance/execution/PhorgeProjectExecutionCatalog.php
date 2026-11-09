<?php

/** Runtime first-family catalog. It never grants native capabilities. */
final class PhorgeProjectExecutionCatalog extends Phobject {
  private static $nativeForms;
  public static function enabled() {
    $missing=new stdClass();
    $value=PhabricatorEnv::getEnvConfigIfExists('governance.project-approval.enabled',$missing);
    if ($value===$missing) {
      if ((new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->getValue()===null) { return false; }
      throw new RuntimeException('project_execution_feature_flag_unknown');
    }
    if (!is_bool($value)) { throw new RuntimeException('project_execution_configuration_control_type_refused'); }
    return $value;
  }
  public static function decide($editor,$object,array $xactions) {
    if (get_class($object)!==PhabricatorProject::class || !self::enabled()) { return 'native'; }
    $native_form=self::classifyNativeForm($editor,$object,$xactions);
    if ($native_form!==null) { return $native_form; }
    return self::classify($editor,$object,$xactions);
  }
  /** Scoped provenance, never an authorization grant or a request flag. */
  public static function openNativeForm($engine,$editor,$object,$request,$config,array $xactions) {
    if (get_class($object)!==PhabricatorProject::class || !$object->getID() || !self::enabled()) { return null; }
    $trace=debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT|DEBUG_BACKTRACE_IGNORE_ARGS,2);
    self::assertNativeFormCall($trace[1]??array(),$engine,$editor,$object,$request,$config);
    if (self::$nativeForms===null) { self::$nativeForms=new WeakMap(); }
    if (isset(self::$nativeForms[$editor])) { throw new RuntimeException('project_execution_native_form_existing'); }
    $token=new stdClass();
    self::$nativeForms[$editor]=array('token'=>$token,'object'=>$object,'request'=>$request,
      'digest'=>self::nativeFormDigest($xactions),'originals'=>self::formOriginals($xactions));
    return $token;
  }
  public static function closeNativeForm($editor,$token) {
    if ($token===null) { return; }
    if (self::$nativeForms===null || !isset(self::$nativeForms[$editor]) ||
        self::$nativeForms[$editor]['token']!==$token) {
      throw new RuntimeException('project_execution_native_form_ownership_refused');
    }
    unset(self::$nativeForms[$editor]);
  }
  private static function assertNativeFormCall(array $frame,$engine,$editor,$object,$request,$config) {
    if (($frame['class']??null)!==PhabricatorEditEngine::class ||
        ($frame['function']??null)!=='buildEditResponse' || ($frame['object']??null)!==$engine ||
        get_class($engine)!==PhabricatorProjectEditEngine::class ||
        get_class($editor)!==PhabricatorProjectTransactionEditor::class ||
        get_class($object)!==PhabricatorProject::class || !$object->getID() ||
        !($request instanceof AphrontRequest) ||
        get_class($config)!==PhabricatorEditEngineConfiguration::class ||
        $config->getEngine()!==$engine || $config->getEngineKey()!==$engine->getEngineKey() ||
        $config->getIsDisabled() || !$config->getIsEdit() ||
        get_class($engine->getController())!==PhabricatorProjectEditController::class ||
        $engine->getController()->getEngine()!==$engine ||
        $engine->getController()->getRequest()!==$request || $editor->getRequest()!==$request ||
        !$request->isFormOrHisecPost() || !$request->getBool('editEngine') ||
        !$editor->getActor() || $editor->getActor()!==$request->getUser()) {
      throw new RuntimeException('project_execution_native_form_provenance_refused');
    }
  }
  private static function nativeFormDigest(array $xactions) {
    if (!$xactions || count($xactions)>32) { throw new RuntimeException('project_execution_native_form_budget'); }
    $rows=array();
    foreach ($xactions as $xaction) {
      if (get_class($xaction)!==PhabricatorProjectTransaction::class ||
          $xaction->getAuthorPHID()!==null || $xaction->getComment()!==null || $xaction->getObjectPHID()!==null) {
        throw new RuntimeException('project_execution_native_form_envelope_refused');
      }
      $rows[]=array('instance'=>spl_object_id($xaction),'type'=>$xaction->getTransactionType(),
        'new'=>$xaction->getNewValue(),'metadata'=>$xaction->getMetadata());
    }
    return PhorgeProjectExecutionValue::digest($rows);
  }
  private static function formOriginals(array $xactions) {
    $originals=array();
    foreach($xactions as$xaction){$originals[spl_object_id($xaction)]=array(
      'object'=>$xaction,'type'=>$xaction->getTransactionType(),'new'=>$xaction->getNewValue(),
      'metadata'=>$xaction->getMetadata());}
    return $originals;
  }
  /** Refusal classification only: this cannot authorize or discard any effect. */
  public static function hasKnownAdditionalFormEffects($editor,$object,array $xactions,$type,$policy) {
    if(self::$nativeForms===null||!isset(self::$nativeForms[$editor])){return false;}
    $proof=self::$nativeForms[$editor];
    if($proof['object']!==$object||$proof['request']!==$editor->getRequest()||!$editor->getActor()) {return false;}
    return self::knownAdditionalEffects($proof['originals'],$xactions,$type,$policy,$editor->getActor()->getPHID());
  }
  private static function knownAdditionalEffects(array $originals,array $xactions,$type,$policy,$author) {
    if(count($xactions)<2||count($xactions)>32||!in_array($type,array(
      PhabricatorTransactions::TYPE_VIEW_POLICY,PhabricatorTransactions::TYPE_EDIT_POLICY),true)){return false;}
    $policies=0;$extra=0;$seen=array();
    foreach($xactions as$xaction){
      if(get_class($xaction)!==PhabricatorProjectTransaction::class){return false;}
      $id=spl_object_id($xaction);$raw=$originals[$id]??null;
      if(isset($seen[$id])||!is_array($raw)||$raw['object']!==$xaction||$raw['type']!==$xaction->getTransactionType()||
        $raw['new']!==$xaction->getNewValue()||$xaction->getComment()!==null||$xaction->getAuthorPHID()!==$author){return false;}
      $seen[$id]=true;$metadata=$xaction->getMetadata();$original=$raw['metadata'];
      foreach(array('core.default','core.mfa')as$key){
        if(array_key_exists($key,$metadata)){if($metadata[$key]!==true){return false;}unset($metadata[$key]);}
        if(array_key_exists($key,$original)){if($original[$key]!==true){return false;}unset($original[$key]);}
      }
      if($metadata!==$original){return false;}
      $actual=$xaction->getTransactionType();$value=$xaction->getNewValue();
      if($actual===$type){
        if($raw['metadata']!==array()||$metadata!==array()||$value!==$policy){return false;}$policies++;continue;
      }
      if($actual===PhabricatorTransactions::TYPE_CUSTOMFIELD){
        if($metadata!==array('customfield:key'=>'std:project:internal:description')||($value!==null&&!is_string($value))){return false;}
      }else if(in_array($actual,array(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,
          PhabricatorProjectColorTransaction::TRANSACTIONTYPE,PhabricatorProjectIconTransaction::TRANSACTIONTYPE),true)){
        if($metadata!==array()||!is_string($value)){return false;}
      }else if($actual===PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE){
        if($metadata!==array()||!is_array($value)||!array_is_list($value)){return false;}
        foreach($value as$slug){if(!is_string($slug)){return false;}}
      }else{return false;}
      $extra++;
    }
    return $policies===1&&$extra>=1;
  }
  private static function classifyNativeForm($editor,$object,array $xactions) {
    if (self::$nativeForms===null || !isset(self::$nativeForms[$editor])) { return null; }
    $proof=self::$nativeForms[$editor];
    if ($proof['object']!==$object || $proof['request']!==$editor->getRequest() ||
        !hash_equals($proof['digest'],self::nativeFormDigest($xactions))) {
      throw new RuntimeException('project_execution_native_form_binding_changed');
    }
    $candidate=null;
    $defaults=array(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,
      PhabricatorProjectColorTransaction::TRANSACTIONTYPE,PhabricatorProjectIconTransaction::TRANSACTIONTYPE,
      PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE,PhabricatorTransactions::TYPE_VIEW_POLICY,
      PhabricatorTransactions::TYPE_EDIT_POLICY,PhabricatorTransactions::TYPE_JOIN_POLICY,
      PhabricatorTransactions::TYPE_SPACE,PhabricatorProjectParentTransaction::TRANSACTIONTYPE,
      PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE);
    foreach ($xactions as $xaction) {
      $metadata=$xaction->getMetadata();$type=$xaction->getTransactionType();
      if ($metadata===array() && in_array($type,array(PhabricatorTransactions::TYPE_VIEW_POLICY,
          PhabricatorTransactions::TYPE_EDIT_POLICY),true)) {
        if ($candidate!==null) { throw new RuntimeException('project_execution_native_form_multiple_changes'); }
        self::policy($xaction->getNewValue());$candidate=$type;continue;
      }
      // Genuine native content fields may change without a policy ticket.
      // With a policy candidate, retain them all: native filtering and the
      // final one-effect closure must reject any second effective change.
      $content=array(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,
        PhabricatorProjectColorTransaction::TRANSACTIONTYPE,
        PhabricatorProjectIconTransaction::TRANSACTIONTYPE,
        PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE);
      if (($metadata===array() && in_array($type,$content,true)) ||
          ($type===PhabricatorTransactions::TYPE_CUSTOMFIELD &&
           $metadata===array('customfield:key'=>'std:project:internal:description'))) {
        continue;
      }
      // These flags only identify a possible unchanged form field. Native
      // populate/validate/filter still run under the original scope, whose
      // beforeEffects requires exactly the one approved effective transaction.
      if (($metadata['core.default']??null)!==true) { throw new RuntimeException('project_execution_native_form_multiple_changes'); }
      unset($metadata['core.default']);
      if ($type===PhabricatorTransactions::TYPE_CUSTOMFIELD) {
        if ($metadata!==array('customfield:key'=>'std:project:internal:description')) {
          throw new RuntimeException('project_execution_native_form_envelope_refused');
        }
      } else if (!in_array($type,$defaults,true) || $metadata!==array()) {
        throw new RuntimeException('project_execution_native_form_envelope_refused');
      }
      if (in_array($type,array(PhabricatorProjectParentTransaction::TRANSACTIONTYPE,
          PhabricatorProjectMilestoneTransaction::TRANSACTIONTYPE),true) && $xaction->getNewValue()!==null) {
        throw new RuntimeException('project_execution_native_form_hierarchy_refused');
      }
    }
    // This result exists only inside the exact native form lease. Default
    // flags never grant policy authority; the DAO still rejects any effective
    // sensitive write without its separately authorized native scope.
    if ($candidate===null) { return 'native'; }
    return $candidate===PhabricatorTransactions::TYPE_VIEW_POLICY?'view-policy':'edit-policy';
  }
  public static function policyTransaction($editor,$object,array $xactions,$decision) {
    $type=$decision==='view-policy'?PhabricatorTransactions::TYPE_VIEW_POLICY:
      ($decision==='edit-policy'?PhabricatorTransactions::TYPE_EDIT_POLICY:null);
    if ($type===null) { throw new RuntimeException('project_execution_policy_candidate_refused'); }
    $form=self::classifyNativeForm($editor,$object,$xactions);
    if ($form!==null) {
      if ($form!==$decision) { throw new RuntimeException('project_execution_native_form_binding_changed'); }
      foreach ($xactions as $xaction) {
        if ($xaction->getTransactionType()===$type && $xaction->getMetadata()===array()) { return $xaction; }
      }
    } else if (count($xactions)===1 && isset($xactions[0]) && $xactions[0]->getTransactionType()===$type) {
      return $xactions[0];
    }
    throw new RuntimeException('project_execution_policy_candidate_refused');
  }
  // Private shape classifier, not an authorization or caller-selectable flag.
  private static function classify($editor,$object,array $xactions) {
    if (get_class($editor)!==PhabricatorProjectTransactionEditor::class || count($xactions)>32) {
      throw new RuntimeException('project_execution_entry_unsupported');
    }
    $sensitive=false;
    $low=array(PhabricatorProjectNameTransaction::TRANSACTIONTYPE,
      PhabricatorProjectColorTransaction::TRANSACTIONTYPE,
      PhabricatorProjectIconTransaction::TRANSACTIONTYPE,
      PhabricatorProjectSlugsTransaction::TRANSACTIONTYPE,
      PhabricatorTransactions::TYPE_COMMENT,PhabricatorTransactions::TYPE_SUBSCRIBERS);
    foreach ($xactions as $xaction) {
      if (get_class($xaction)!==PhabricatorProjectTransaction::class) {
        throw new RuntimeException('project_execution_envelope_unsupported');
      }
      $type=$xaction->getTransactionType();
      if (in_array($type,$low,true)) { continue; }
      if ($type===PhabricatorTransactions::TYPE_CUSTOMFIELD &&
          $xaction->getMetadataValue('customfield:key')==='std:project:internal:description') { continue; }
      if ($type===PhabricatorTransactions::TYPE_EDGE &&
          $xaction->getMetadataValue('edge:type')===PhabricatorProjectProjectHasMemberEdgeType::EDGECONST &&
          self::isSelfMembership($xaction->getNewValue(),$editor->getActingAsPHID())) {
        if(count($xactions)!==1 || $xaction->getComment()!==null || $xaction->getAuthorPHID()!==null ||
          $xaction->getMetadata()!==array('edge:type'=>PhabricatorProjectProjectHasMemberEdgeType::EDGECONST)){
          throw new RuntimeException('project_execution_membership_envelope_unsupported');
        }
        return array_keys($xaction->getNewValue())[0]==='+'?'self-join':'self-leave';
      }
      if ($type===PhabricatorTransactions::TYPE_EDGE &&
          $xaction->getMetadataValue('edge:type')===PhabricatorProjectProjectHasMemberEdgeType::EDGECONST) {
        if (count($xactions)!==1) { throw new RuntimeException('project_execution_member_batch_unsupported'); }
        $intent=PhorgeProjectExecutionIntent::newFromNativeMembership($xaction);
        return $intent->isAddition()?'members-add':'members-remove';
      }
      $sensitive=true;
    }
    if (!$sensitive) { return 'native'; }
    if ($object->getID()===null || count($xactions)!==1 ||
        !in_array($xactions[0]->getTransactionType(),array(PhabricatorTransactions::TYPE_EDIT_POLICY,PhabricatorTransactions::TYPE_VIEW_POLICY),true) ||
        $xactions[0]->getComment()!==null) {
      throw new RuntimeException('project_execution_expansion_unsupported');
    }
    if ($xactions[0]->getAuthorPHID()!==null || $xactions[0]->getMetadata()!==array()) {
      throw new RuntimeException('project_execution_envelope_unsupported');
    }
    self::policy($xactions[0]->getNewValue());
    return $xactions[0]->getTransactionType()===PhabricatorTransactions::TYPE_VIEW_POLICY?'view-policy':'edit-policy';
  }
  public static function policy($policy) {
    if (!is_string($policy) || !in_array($policy,array(PhabricatorPolicies::POLICY_USER,
      PhabricatorPolicies::POLICY_ADMIN,PhabricatorPolicies::POLICY_NOONE,
      PhabricatorPolicies::POLICY_PUBLIC),true) &&
      !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/',$policy)) {
      throw new RuntimeException('project_execution_policy_unsupported');
    }
    return $policy;
  }
  private static function isSelfMembership($value,$actor) {
    if (!is_array($value) || count($value)!==1 || !is_string($actor)) { return false; }
    $op=array_keys($value)[0];
    return in_array($op,array('+','-'),true) && $value[$op]===array($actor=>$actor);
  }
  public static function configurationKeys() {
    return array('governance.project-approval.enabled','governance.project-content-grants.enabled','governance.organization.enabled','policy.allow-public','policy.locked',
      'phabricator.application-settings','phabricator.uninstalled-applications',
      'phabricator.show-prototypes','phorge.product-profile','load-libraries',
      'cluster.databases','gorge.db.uri','mysql.host','mysql.port','mysql.user',
      'mysql.pass','storage.default-namespace','security.require-multi-factor-auth');
  }
  public static function entries() {
    return array(
      'PROJECT.EDITOR.ALL'=>'PhabricatorApplicationTransactionEditor::applyTransactions',
      'PROJECT.DAO.POLICY'=>'PhabricatorProject::save',
      'PROJECT.WEB.EDIT'=>'PhabricatorProjectEditEngine',
      'PROJECT.API.EDIT'=>'ProjectEditConduitAPIMethod',
      'PROJECT.WEB.MEMBER.ADD'=>'PhabricatorProjectMembersAddController',
      'PROJECT.WEB.MEMBER.REMOVE'=>'PhabricatorProjectMembersRemoveController',
      'PROJECT.WEB.JOIN.LEAVE'=>'PhabricatorProjectUpdateController',
      'PROJECT.LEGACY.MEMBER'=>'PhorgeProjectLegacyMembersTransaction',
      'PROJECT.EDGE.DIRECT'=>'PhabricatorEdgeEditor::save',
      'CONFIG.UI.EDIT'=>'PhabricatorConfigEditController',
      'CONFIG.EDITOR'=>'PhabricatorConfigEditor',
      'CONFIG.CLI.SET'=>'PhabricatorConfigManagementSetWorkflow',
      'CONFIG.CLI.DELETE'=>'PhabricatorConfigManagementDeleteWorkflow',
      'CONFIG.CLI.MIGRATE'=>'PhabricatorConfigManagementMigrateWorkflow',
      'CONFIG.DAO.SAVE'=>'LiskDAO::save',
      'CONFIG.DAO.INSERT'=>'PhabricatorConfigEntry::insert',
      'CONFIG.DAO.UPDATE'=>'PhabricatorConfigEntry::update',
      'CONFIG.DAO.REPLACE'=>'PhabricatorConfigEntry::replace',
      'CONFIG.DAO.DELETE'=>'PhabricatorConfigEntry::delete',
      'PRODUCER.RULE'=>'HeraldRule', 'PRODUCER.FIREHOSE'=>'HeraldWebhook');
  }
}
