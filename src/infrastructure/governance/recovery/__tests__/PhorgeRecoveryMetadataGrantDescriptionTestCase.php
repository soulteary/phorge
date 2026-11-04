<?php

final class PhorgeRecoveryMetadataGrantDescriptionTestCase extends PhutilTestCase {
  public function testGrantDescriptionNeverBecomesAnAuthorityLease() {
    $grants=array('GRANT ALL PRIVILEGES ON *.* TO `synthetic`@`localhost` WITH GRANT OPTION');
    $data=PhorgeRecoveryMetadataGrantDescription::describe($grants,'NONE',null);
    $this->assertTrue($data['required_global_privileges_claimed']);
    $this->assertFalse($data['actual_live_privileges_proved']);
    $this->assertFalse($data['metadata_absence_proved']);
    $this->assertFalse($data['restoration_authorized']);
    $partial=PhorgeRecoveryMetadataGrantDescription::describe(array(
      'GRANT SELECT, PROCESS ON *.* TO `synthetic`@`localhost`',
      'GRANT TRIGGER ON `synthetic_user`.* TO `synthetic`@`localhost`'),'NONE',null);
    $this->assertFalse($partial['required_global_privileges_claimed']);
  }

  public function testUnknownRoleProxyAndPartialRevocationRefuse() {
    foreach(array(
      array(array('GRANT ALL PRIVILEGES ON *.* TO `synthetic`@`localhost`'),'`role`@`localhost`',null),
      array(array('GRANT ALL PRIVILEGES ON *.* TO `synthetic`@`localhost`'),'NONE','proxy'),
      array(array('GRANT `role`@`localhost` TO `synthetic`@`localhost`'),'NONE',null),
      array(array('GRANT ALL PRIVILEGES ON *.* TO `synthetic`@`localhost`',
        'REVOKE SELECT ON `hidden`.* FROM `synthetic`@`localhost`'),'NONE',null),
      array(array('GRANT SELECT ON *.* TO `synthetic`@`localhost`',
        'GRANT SELECT ON *.* TO `synthetic`@`localhost`'),'NONE',null),
      array(array('GRANT SELECT ON *.* TO `synthetic`@`localhost`'."\n"),'NONE',null),
      array(array(true),'NONE',null))as$case){
      $refused=false;
      try{PhorgeRecoveryMetadataGrantDescription::describe($case[0],$case[1],$case[2]);}
      catch(RuntimeException$error){$refused=$error->getMessage()==='recovery_metadata_grant_profile_unknown';}
      $this->assertTrue($refused);
    }
  }
}
