<?php

/** Runtime first-family catalog. It never grants native capabilities. */
final class PhorgeProjectExecutionCatalog extends Phobject {
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
    return self::classify($editor,$object,$xactions);
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
    return array('governance.project-approval.enabled','policy.allow-public','policy.locked',
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
