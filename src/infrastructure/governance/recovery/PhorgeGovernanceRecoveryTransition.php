<?php

/** Read-only native qualification classification, never an enabling permit. */
final class PhorgeGovernanceRecoveryTransition extends Phobject {

  private static function normalize(array $state) {
    $keys=array_keys($state);sort($keys,SORT_STRING);
    if($keys!==array('isApproved','isDisabled','isMailingList','isSystemAgent','userPHID')||
      !is_string($state['userPHID'])||
      !preg_match('/\APHID-USER-[a-zA-Z0-9]{20}\z/D',$state['userPHID'])){
      throw new InvalidArgumentException('recovery_native_state_refused');
    }
    foreach(array('isApproved','isDisabled','isMailingList','isSystemAgent')as$key){
      $value=$state[$key];
      if(!in_array($value,array(false,true,0,1,'0','1'),true)){
        throw new InvalidArgumentException('recovery_native_flag_refused');
      }
      $state[$key]=($value===true||$value===1||$value==='1');
    }
    return $state;
  }

  /** A later source/stop ledger must distinguish first approval from recovery. */
  public static function describe(array $before,array $after) {
    $before=self::normalize($before);$after=self::normalize($after);
    if($before['userPHID']!==$after['userPHID']){
      throw new InvalidArgumentException('recovery_subject_identity_changed');
    }
    $changes=array();
    if($before['isDisabled']&&!$after['isDisabled']){$changes[]='native.enable';}
    if(!$before['isApproved']&&$after['isApproved']){$changes[]='native.approval';}
    if($before['isSystemAgent']&&!$after['isSystemAgent']){$changes[]='native.system-agent-exit';}
    if($before['isMailingList']&&!$after['isMailingList']){$changes[]='native.mailing-list-exit';}
    // Identity-kind changes are not assumed to be equivalent to a human login
    // change: native API eligibility and identity continuity need their own audit.
    return array(
      'schema'=>'native-account-transition-observation-v1',
      'userPHID'=>$before['userPHID'],
      'qualification_increase_candidates'=>$changes,
      'needs_source_assessment'=>(bool)$changes,
      'initial_approval_distinction_required'=>in_array('native.approval',$changes,true),
      'identity_kind_changed'=>$before['isSystemAgent']!==$after['isSystemAgent']||
        $before['isMailingList']!==$after['isMailingList'],
      'can_execute'=>false,
      'all_sources_reviewed'=>false,
      'activation_blocked'=>true);
  }
}
