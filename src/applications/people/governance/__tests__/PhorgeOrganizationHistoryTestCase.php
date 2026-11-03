<?php

final class PhorgeOrganizationHistoryTestCase extends PhutilTestCase {
  public function testHistoryCursorIsCanonicalAndBounded() {
    foreach(array(null,0,'0',1,'100')as$value){$this->assertEqual((int)$value,PhorgeOrganizationService::auditCursor($value));}
    foreach(array(true,false,-1,'01','1 ','1.0','1e2','-0',(string)PHP_INT_MAX.'0')as$value){
      $refused=false;try{PhorgeOrganizationService::auditCursor($value);}catch(InvalidArgumentException$error){$refused=true;}
      $this->assertTrue($refused);
    }
  }
  public function testExact365DayReportNeverImpliesDeletion() {
    $period=365*86400;
    $this->assertFalse(PhorgeOrganizationService::auditRetentionCandidate(0,$period-1));
    $this->assertTrue(PhorgeOrganizationService::auditRetentionCandidate(0,$period));
    $this->assertTrue(PhorgeOrganizationService::auditRetentionCandidate(0,$period+1));
    $this->assertFalse(PhorgeOrganizationService::auditRetentionCandidate($period+1,$period));
    $this->assertFalse(PhorgeOrganizationService::auditRetentionCandidate(0,0));
  }
}
