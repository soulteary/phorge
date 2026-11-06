<?php

/** Caller syntax only. This descriptor never establishes recovery authority. */
final class PhorgeRecoveryRestoreRequest extends Phobject {
  public static function identifier($value) {
    if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/D', $value)) {
      throw new InvalidArgumentException('recovery_restore_identifier_invalid');
    }
    return $value;
  }

  public static function operation($value) {
    if (!is_string($value) ||
        !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{15,127}\z/D', $value)) {
      throw new InvalidArgumentException('recovery_restore_operation_invalid');
    }
    return $value;
  }

  public static function normalize(array $input) {
    $keys = array_keys($input); sort($keys, SORT_STRING);
    if ($keys !== array('native_stop_transaction','operation_id','reason','subject','ttl_seconds') ||
        !is_int($input['ttl_seconds']) || $input['ttl_seconds'] < 1 ||
        $input['ttl_seconds'] > 86400 || !is_string($input['reason']) ||
        trim($input['reason']) === '' || strlen($input['reason']) > 2048 ||
        !phutil_is_utf8($input['reason']) ||
        preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $input['reason'])) {
      throw new InvalidArgumentException('recovery_restore_input_invalid');
    }
    return array('schema_version'=>1, 'action'=>'native.enable',
      'subject'=>PhorgeRecoveryStopRequest::user($input['subject']),
      'native_stop_transaction'=>PhorgeRecoveryStopRequest::transactionID($input['native_stop_transaction']),
      'operation_id'=>self::operation($input['operation_id']),
      'reason'=>$input['reason'], 'ttl_seconds'=>$input['ttl_seconds']);
  }

  public static function digest(array $value) {
    return hash('sha256', json_encode(self::canonical($value, 0), JSON_THROW_ON_ERROR));
  }

  /** Stored descriptors remain syntax, never a certificate of source closure. */
  public static function storedInput(array $input) {
    if (($input['schema_version'] ?? null) !== 1 ||
        ($input['action'] ?? null) !== 'native.enable') {
      throw new RuntimeException('recovery_restore_storage_invalid');
    }
    $caller = $input;
    unset($caller['schema_version'], $caller['action']);
    try { $normalized = self::normalize($caller); }
    catch (InvalidArgumentException $error) {
      throw new RuntimeException('recovery_restore_storage_invalid', 0, $error);
    }
    if (self::digest($normalized) !== self::digest($input)) {
      throw new RuntimeException('recovery_restore_storage_invalid');
    }
    return $normalized;
  }

  public static function sourceVersion(array $source) {
    $version=$source['schema_version']??null;
    $keys=array('schema_version','action','subject','native_stop_transaction','category',
      'event_digest','identity','runtime_inventory_digest','families','media','organization','credentials',
      'native_web_API_disposition','old_media_credential_disposition','all_sources_complete','restoration_authorized');
    if(in_array($version,array(2,3),true)){$keys[]='restore_lineage';$keys[]='selected_producer_profile';}
    if($version===3){$keys[]='partial_facts';}
    $actual=array_keys($source);sort($actual,SORT_STRING);sort($keys,SORT_STRING);
    if(!in_array($version,array(1,2,3),true)||$actual!==$keys||
      $source['all_sources_complete']!==false||$source['restoration_authorized']!==false){
      throw new RuntimeException('recovery_restore_storage_invalid');}
    if(in_array($version,array(2,3),true)){
      $lineage=$source['restore_lineage'];
      $lineage_keys=is_array($lineage)?array_keys($lineage):array();sort($lineage_keys,SORT_STRING);
      if(!is_array($lineage)||$lineage_keys!==array('complete','configured_incarnation_digest','physical_restore_rehearsal_proved','provider_schema','scope')||
        ($lineage['provider_schema']??null)!==1||
        ($lineage['scope']??null)!=='isolated-current-configuration-binding-only'||
        ($lineage['physical_restore_rehearsal_proved']??null)!==false||($lineage['complete']??null)!==false||
        !is_string($lineage['configured_incarnation_digest']??null)||
        !preg_match('/\A[a-f0-9]{64}\z/D',$lineage['configured_incarnation_digest'])||
        $source['selected_producer_profile']!=='native-authsession-insert-fence-v1-not-all-producers'){
        throw new RuntimeException('recovery_restore_storage_invalid');}
    }
    if($version===3){
      $profiles=array(
        'application-config'=>array('selected-config-dictionaries-v1','process-local-endpoint-recapture'),
        'project-relationships'=>array('subject-project-rows-selected-history-v1','owned-writer-current-locking-reads'),
        'spaces'=>array('selected-space-rows-request-cache-v1','owned-writer-current-locking-reads'),
        'policy'=>array('selected-policy-namedpolicy-space-row-digests-v1','owned-writer-current-locking-reads'));
      $partial=$source['partial_facts'];
      $names=is_array($partial)?array_keys($partial):array();sort($names,SORT_STRING);
      $expected=array_keys($profiles);sort($expected,SORT_STRING);
      if($names!==$expected){throw new RuntimeException('recovery_restore_storage_invalid');}
      foreach($profiles as$family=>$profile){
        $entry=$partial[$family];$fields=is_array($entry)?array_keys($entry):array();sort($fields,SORT_STRING);
        if($fields!==array('complete','mode','provider_schema','scope','source_digest')||
          $entry['provider_schema']!==1||$entry['scope']!==$profile[0]||$entry['mode']!==$profile[1]||
          $entry['complete']!==false||!is_string($entry['source_digest'])||
          !preg_match('/\A[a-f0-9]{64}\z/D',$entry['source_digest'])){
          throw new RuntimeException('recovery_restore_storage_invalid');}
      }
    }
    return $version;
  }

  private static function canonical($value, $depth) {
    if ($depth > 16) { throw new InvalidArgumentException('recovery_restore_binding_budget'); }
    if (is_array($value)) {
      if (count($value) > 512) { throw new InvalidArgumentException('recovery_restore_binding_budget'); }
      if (!array_is_list($value)) { ksort($value, SORT_STRING); }
      foreach ($value as $key=>$item) { $value[$key] = self::canonical($item, $depth + 1); }
      return $value;
    }
    if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value)) {
      throw new InvalidArgumentException('recovery_restore_binding_type');
    }
    return $value;
  }
}
