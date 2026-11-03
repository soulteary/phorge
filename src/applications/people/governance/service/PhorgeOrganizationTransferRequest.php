<?php

/** Detached typed intent. Current identity, sources and approval are separate. */
final class PhorgeOrganizationTransferRequest extends Phobject {
  private static function id($value, $length = 64) {
    if (!PhorgeGovernanceOrganizationRequest::identifier($value, $length)) {
      throw new InvalidArgumentException('organization_transfer_identifier_refused');
    }
    return $value;
  }
  private static function user($value) {
    if (!is_string($value) || !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D', $value)) {
      throw new InvalidArgumentException('organization_transfer_identity_refused');
    }
    return $value;
  }
  private static function text($value) {
    if (!is_string($value) || trim($value) === '' || strlen($value) > 1024 || !phutil_is_utf8($value)) {
      throw new InvalidArgumentException('organization_transfer_reason_refused');
    }
    return $value;
  }
  private static function exact(array $value, array $keys) {
    sort($keys, SORT_STRING); $actual = array_keys($value); sort($actual, SORT_STRING);
    if ($actual !== $keys) { throw new InvalidArgumentException('organization_transfer_fields_refused'); }
  }
  public static function proposal(array $value) {
    if (!array_key_exists('handover_days', $value)) { $value['handover_days'] = 14; }
    self::exact($value, array('operation_id','transfer_id','subject_phid','responsible_phid',
      'from_department','to_department','primary_version','from_version','to_version',
      'handover_days','reason','acknowledge_no_project_permission_change'));
    if (!is_int($value['handover_days']) || $value['handover_days'] < 0 || $value['handover_days'] > 30 ||
        $value['acknowledge_no_project_permission_change'] !== true) {
      throw new InvalidArgumentException('organization_transfer_period_or_acknowledgement_refused');
    }
    $out = array('operation_id'=>self::id($value['operation_id'],128),
      'transfer_id'=>self::id($value['transfer_id']), 'subject_phid'=>self::user($value['subject_phid']),
      'responsible_phid'=>self::user($value['responsible_phid']),
      'from_department'=>self::id($value['from_department']), 'to_department'=>self::id($value['to_department']),
      'reason'=>self::text($value['reason']), 'handover_days'=>$value['handover_days'],
      'acknowledge_no_project_permission_change'=>true);
    if ($out['from_department'] === $out['to_department']) {
      throw new InvalidArgumentException('organization_transfer_same_department_refused');
    }
    foreach (array('primary_version','from_version','to_version') as $key) {
      try { $version = PhorgeOrganizationService::version($value[$key]); }
      catch (RuntimeException $error) { throw new InvalidArgumentException('organization_transfer_version_refused'); }
      if ($version < 1) { throw new InvalidArgumentException('organization_transfer_version_refused'); }
      $out[$key] = $version;
    }
    ksort($out, SORT_STRING); return $out;
  }

  public static function extension(array $value) {
    if (!array_key_exists('exception_reason',$value)) { $value['exception_reason']=null; }
    self::exact($value,array('operation_id','transfer_id','handover_id','expected_version',
      'handover_days','reason','exception_reason','acknowledge_no_project_permission_change'));
    if (!is_int($value['handover_days']) || $value['handover_days']<1 || $value['handover_days']>30 ||
        $value['acknowledge_no_project_permission_change']!==true) {
      throw new InvalidArgumentException('organization_transfer_extension_refused');
    }
    $out=self::handoverIntent($value);
    $out['transfer_id']=self::id($value['transfer_id']);
    $out['handover_days']=$value['handover_days'];
    $out['exception_reason']=$value['exception_reason']===null?null:self::text($value['exception_reason']);
    ksort($out,SORT_STRING);return $out;
  }

  public static function termination(array $value) {
    self::exact($value,array('operation_id','transfer_id','handover_id','expected_version',
      'reason','acknowledge_no_project_permission_change'));
    $out=self::handoverIntent($value);$out['transfer_id']=self::id($value['transfer_id']);
    ksort($out,SORT_STRING);return $out;
  }

  private static function handoverIntent(array $value) {
    if ($value['acknowledge_no_project_permission_change']!==true) {
      throw new InvalidArgumentException('organization_transfer_acknowledgement_refused');
    }
    try { $version=PhorgeOrganizationService::version($value['expected_version']); }
    catch(RuntimeException $error){throw new InvalidArgumentException('organization_transfer_version_refused');}
    if($version<1){throw new InvalidArgumentException('organization_transfer_version_refused');}
    return array('operation_id'=>self::id($value['operation_id'],128),
      'handover_id'=>self::id($value['handover_id']),'expected_version'=>$version,
      'reason'=>self::text($value['reason']),'acknowledge_no_project_permission_change'=>true);
  }

  public static function disposition(array $value) {
    self::exact($value,array('operation_id','handover_id','handover_version','project_phid',
      'decision','manual_status','expected_version','note'));
    if(!is_string($value['project_phid']) || !preg_match('/\APHID-PROJ-[a-zA-Z0-9]{20}\z/D',$value['project_phid']) ||
        !in_array($value['decision'],array('keep','remove','review'),true) ||
        !in_array($value['manual_status'],array('pending','acknowledged'),true) ||
        !is_string($value['note']) || strlen($value['note'])>1024 || !phutil_is_utf8($value['note'])){
      throw new InvalidArgumentException('organization_transfer_disposition_refused');
    }
    try{$version=PhorgeOrganizationService::version($value['expected_version']);
      $handover_version=PhorgeOrganizationService::version($value['handover_version']);}
    catch(RuntimeException $error){throw new InvalidArgumentException('organization_transfer_version_refused');}
    if($handover_version<1){throw new InvalidArgumentException('organization_transfer_version_refused');}
    $out=array('operation_id'=>self::id($value['operation_id'],128),
      'handover_id'=>self::id($value['handover_id']),'handover_version'=>$handover_version,
      'project_phid'=>$value['project_phid'],'decision'=>$value['decision'],
      'manual_status'=>$value['manual_status'],'expected_version'=>$version,'note'=>$value['note']);
    ksort($out,SORT_STRING);return $out;
  }

  public static function envelope(array $value,$propose) {
    if(!is_bool($propose)){throw new InvalidArgumentException('organization_transfer_transport_refused');}
    if(!array_key_exists('deadline_offset',$value)){$value['deadline_offset']=null;}
    self::exact($value,$propose?array('request','ttl_seconds','deadline_offset'):array('request','deadline_offset'));
    if(!is_array($value['request']) || ($value['deadline_offset']!==null &&
      (!is_int($value['deadline_offset'])||abs($value['deadline_offset'])>86400))){
      throw new InvalidArgumentException('organization_transfer_transport_refused');}
    $out=array('request'=>self::proposal($value['request']),'deadline_offset'=>$value['deadline_offset']);
    if($propose){PhorgeGovernanceOrganizationApprovalAdapter::validateTTL($value['ttl_seconds']);$out['ttl_seconds']=$value['ttl_seconds'];}
    return$out;
  }
}
