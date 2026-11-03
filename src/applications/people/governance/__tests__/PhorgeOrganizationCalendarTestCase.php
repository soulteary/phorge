<?php

final class PhorgeOrganizationCalendarTestCase extends PhutilTestCase {
  private function refused(callable $call,$reason) {
    $seen=false;try{$call();}catch(InvalidArgumentException $error){$seen=$error->getMessage()===$reason;}
    $this->assertTrue($seen);
  }
  public function testDefault14Immediate0And30DayBoundaries() {
    $at=(new DateTimeImmutable('2026-11-03T09:15:00Z'))->getTimestamp();
    $default=PhorgeOrganizationCalendar::preview($at);
    $this->assertEqual($default['deadline_at'],PhorgeOrganizationCalendar::preview($at,14,'GMT')['deadline_at']);
    $this->assertEqual(14,$default['days']);$this->assertEqual($at+14*86400,$default['deadline_at']);
    $this->assertEqual($at,PhorgeOrganizationCalendar::preview($at,0)['deadline_at']);
    $this->assertEqual($at+30*86400,PhorgeOrganizationCalendar::preview($at,30)['deadline_at']);
    foreach(array(-1,31,true,'14')as$days){$this->refused(function()use($at,$days){
      PhorgeOrganizationCalendar::preview($at,$days);},'organization_handover_calendar_refused');}
    $this->refused(function()use($at){PhorgeOrganizationCalendar::preview($at,14,'+08:00');},'organization_handover_calendar_refused');
  }
  public function testSpringCalendarDaysPreserveLocalClock() {
    $at=(new DateTimeImmutable('2026-03-01T12:00:00-05:00'))->getTimestamp();
    $plan=PhorgeOrganizationCalendar::preview($at,14,'America/New_York');
    $this->assertEqual('2026-03-15 12:00:00',$plan['deadline_local']);
    $this->assertEqual(14*86400-3600,$plan['deadline_at']-$at);
    $this->assertEqual(-14400,$plan['deadline_offset']);
  }
  public function testAmbiguousDeadlineNeedsExplicitOffset() {
    $at=(new DateTimeImmutable('2026-10-18T01:30:00-04:00'))->getTimestamp();
    $plan=PhorgeOrganizationCalendar::preview($at,14,'America/New_York');
    $this->assertTrue($plan['resolution_required']);$this->assertEqual(null,$plan['deadline_at']);
    $this->assertEqual(array(-18000,-14400),$plan['candidate_offsets']);
    $early=PhorgeOrganizationCalendar::preview($at,14,'America/New_York',-14400);
    $late=PhorgeOrganizationCalendar::preview($at,14,'America/New_York',-18000);
    $this->assertEqual(3600,$late['deadline_at']-$early['deadline_at']);
    $this->refused(function()use($at){PhorgeOrganizationCalendar::preview($at,14,'America/New_York',0);},'organization_handover_offset_refused');
    $this->assertEqual($early['deadline_at'],PhorgeOrganizationCalendar::preview($early['deadline_at'],0,'America/New_York')['deadline_at']);
  }
  public function testNonexistentDeadlineNeverSilentlyNormalizes() {
    $at=(new DateTimeImmutable('2026-02-22T02:30:00-05:00'))->getTimestamp();
    $this->refused(function()use($at){PhorgeOrganizationCalendar::preview($at,14,'America/New_York');},'organization_handover_nonexistent_local_time');
  }
  public function testLeapCalendarAndFiniteSupportedEpoch() {
    $at=(new DateTimeImmutable('2028-02-15T10:00:00Z'))->getTimestamp();
    $this->assertEqual('2028-02-29 10:00:00',PhorgeOrganizationCalendar::preview($at)['deadline_local']);
    $this->refused(function(){PhorgeOrganizationCalendar::preview(253402300799,30);},'organization_handover_time_refused');
    $this->refused(function(){PhorgeOrganizationCalendar::preview(-1);},'organization_handover_time_refused');
  }
  public function testEveryExtensionAndCumulativeExceptionAreExplicit() {
    $at=(new DateTimeImmutable('2026-11-03T12:00:00Z'))->getTimestamp();
    $plan=PhorgeOrganizationCalendar::extension($at,16,'UTC',14,'Explicit review');
    $this->assertEqual(30,$plan['cumulative_days']);$this->assertTrue($plan['requires_independent_approval']);
    $this->refused(function()use($at){PhorgeOrganizationCalendar::extension($at,17,'UTC',14,'Explicit review');},'organization_handover_cumulative_exception_required');
    $this->assertEqual(31,PhorgeOrganizationCalendar::extension($at,17,'UTC',14,'Review','Exceptional handover')['cumulative_days']);
    $this->refused(function()use($at){PhorgeOrganizationCalendar::extension($at,1,'UTC',4294967295,'Review','Exception');},'organization_handover_extension_refused');
    $this->refused(function()use($at){PhorgeOrganizationCalendar::extension($at,0,'UTC',14,'Review');},'organization_handover_extension_refused');
    $this->refused(function()use($at){PhorgeOrganizationCalendar::extension($at,1,'UTC',14,'');},'organization_handover_extension_refused');
  }
}
