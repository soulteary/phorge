<?php

/** Pure secret-free API storage observations, never authentication authority. */
final class PhorgeRecoveryNativeAPIFacts extends Phobject {
  const MAX_ROWS = 64;
  const MAX_BYTES = 131072;

  public static function describe(array $groups, $target, $now) {
    if (!is_string($target) || !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D', $target) ||
        !is_int($now) || $now < 0) { throw new RuntimeException('recovery_api_shape_unknown'); }
    $expected = array('conduit','oauth_tokens','oauth_authorizations','oauth_codes','oauth_clients','legacy_sessions');
    $keys = array_keys($groups); sort($keys); $sorted = $expected; sort($sorted);
    if ($keys !== $sorted) { throw new RuntimeException('recovery_api_shape_unknown'); }
    $observations = array(); $sets = array(); $bytes = 0; $unknown = array(
      'oauth-external-provider-and-email-associations-uncovered',
      'cluster-and-plugin-credential-consumers-uncovered',
      'legacy-certificate-secret-generation-is-account-binding-only',
      'future-producer-and-restore-lineage-unfenced');
    foreach ($expected as $kind) {
      $rows = $groups[$kind];
      if (!is_array($rows) || count($rows) > self::MAX_ROWS) {
        throw new RuntimeException('recovery_api_capture_budget_unknown'); }
      $out = array(); $digests = array(); $ids = array();
      foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['id']) ||
            (!is_int($row['id']) && !is_string($row['id'])) ||
            !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$row['id']) ||
            (float)$row['id'] > 4294967295 || isset($ids[(string)$row['id']])) {
          throw new RuntimeException('recovery_api_shape_unknown'); }
        $profiles = array(
          'conduit'=>array('id','objectPHID','tokenType','token','expires','dateCreated','dateModified','tokenName'),
          'oauth_tokens'=>array('id','token','userPHID','clientPHID','dateCreated','dateModified'),
          'oauth_authorizations'=>array('id','phid','userPHID','clientPHID','dateCreated','dateModified','scope'),
          'oauth_codes'=>array('id','code','clientPHID','clientSecret','userPHID','dateCreated','dateModified','redirectURI'),
          'oauth_clients'=>array('id','phid','name','secret','redirectURI','creatorPHID','isTrusted','viewPolicy','editPolicy','dateCreated','dateModified','isDisabled'),
          'legacy_sessions'=>array('id','phid','userPHID','type','sessionKey','sessionStart','sessionExpires','highSecurityUntil','isPartial','signedLegalpadDocuments'));
        $fields=array_keys($row);sort($fields);$wanted=$profiles[$kind];sort($wanted);
        if($fields!==$wanted){throw new RuntimeException('recovery_api_shape_unknown');}
        foreach(array('dateCreated','dateModified','sessionStart','sessionExpires','highSecurityUntil')as$field){
          if(array_key_exists($field,$row)&&$row[$field]!==null&&
              ((!is_int($row[$field])&&!is_string($row[$field]))||
               !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$row[$field])||
               (float)$row[$field]>4294967295)) {throw new RuntimeException('recovery_api_shape_unknown');}}
        if($kind==='legacy_sessions'&&$row['type']!=='conduit'){throw new RuntimeException('recovery_api_shape_unknown');}
        $ids[(string)$row['id']] = true;
        foreach ($row as $value) {
          if ($value !== null && !is_string($value) && !is_int($value)) {
            throw new RuntimeException('recovery_api_shape_unknown'); }
          $length = $value === null ? 0 : strlen((string)$value);
          if ($length > 4096) { throw new RuntimeException('recovery_api_storage_budget_unknown'); }
          $bytes += $length + 32;
        }
        if ($bytes > self::MAX_BYTES) { throw new RuntimeException('recovery_api_capture_budget_unknown'); }
        if ($kind !== 'oauth_clients') {
          $owner = $kind === 'conduit' ? 'objectPHID' : 'userPHID';
          if (($row[$owner] ?? null) !== $target) { throw new RuntimeException('recovery_api_owner_unknown'); }
        }
        ksort($row); $digest = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        $digests[] = $digest; $state = 'stored-source-only';
        if ($kind === 'conduit') {
          if (!isset($row['tokenType'],$row['token']) || !is_string($row['token']) || strlen($row['token']) !== 32) {
            throw new RuntimeException('recovery_api_shape_unknown'); }
          if (!in_array($row['tokenType'], array('api','cli','clr'), true)) { $unknown[] = 'unknown-conduit-token-type'; }
          if ($row['tokenType'] === 'clr') { $unknown[] = 'cluster-token-requires-native-network-context'; }
          if ($row['expires'] !== null &&
              ((!is_int($row['expires'])&&!is_string($row['expires'])) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string)$row['expires']) ||
               (float)$row['expires'] > 4294967295)) { throw new RuntimeException('recovery_api_shape_unknown'); }
          $state = $row['expires'] !== null && (int)$row['expires'] < $now ? 'expired' : 'stored-token-not-authorized';
        }
        $out[] = array('row_binding_sha256'=>$digest,'observation'=>$state,'credential_permission'=>false);
      }
      sort($digests); $sets[$kind] = $digests; $observations[$kind] = $out;
    }
    $unknown = array_values(array_unique($unknown)); sort($unknown);
    return array('schema_version'=>1,'api_credentials'=>$observations,
      'storage_set_digest'=>hash('sha256',json_encode($sets,JSON_THROW_ON_ERROR)),
      'unknown_reasons'=>$unknown,'api_family_complete'=>false,'all_sources_complete'=>false,
      'can_execute'=>false,'source_writes'=>false,'consistency'=>'single-sample-no-execution-attestation');
  }
}
