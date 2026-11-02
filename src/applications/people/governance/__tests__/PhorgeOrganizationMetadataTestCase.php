<?php

final class PhorgeOrganizationMetadataTestCase extends PhutilTestCase {
  public function testMetadataIsDetachedAndCannotSelectAnotherVisibilityModel() {
    $department='department-a';
    $input=array('associated_department_id'=>&$department,'contact_user_phid'=>null,
      'expected_version'=>1,'operation_id'=>'meta-1','unit_id'=>'team-a');
    $value=PhorgeOrganizationRequest::metadata($input);$department='department-b';
    $this->assertEqual('department-a',$value['associated_department_id']);
    $input['visibility_model']='public';
    $this->assertException(InvalidArgumentException::class,function() use ($input) { return PhorgeOrganizationRequest::metadata($input); });
  }
  public function testMetadataReferenceAndVersionsAreStrict() {
    $base=array('associated_department_id'=>null,'contact_user_phid'=>null,
      'expected_version'=>1,'operation_id'=>'meta-2','unit_id'=>'team-a');
    foreach(array(true,0,'1',PHP_INT_MAX) as $version){$input=$base;$input['expected_version']=$version;
      $this->assertException(InvalidArgumentException::class,function() use ($input) { return PhorgeOrganizationRequest::metadata($input); });}
    $input=$base;$input['contact_user_phid']='team-admin';
    $this->assertException(InvalidArgumentException::class,function() use ($input) { return PhorgeOrganizationRequest::metadata($input); });
  }
  public function testClearDepartmentKeepsExplicitExistingVersionFence() {
    $input=array('expected_version'=>2,'operation_id'=>'clear-1','user_phid'=>'PHID-USER-aaaaaaaaaaaaaaaaaaaa');
    $this->assertEqual($input,PhorgeOrganizationRequest::clearDepartment($input));
    $input['expected_version']=0;
    $this->assertException(InvalidArgumentException::class,function() use ($input) { return PhorgeOrganizationRequest::clearDepartment($input); });
  }
  public function testDatasourceViewerAloneDoesNotSupplyCredentials() {
    $source=(new PhorgeOrganizationDatasource())->setViewer((new PhabricatorUser())->setIsAdmin(1));
    $this->assertEqual('/people/organization/typeahead/',$source->getDatasourceURI());
    $this->assertEqual(null,$source->getBrowseURI());
    $this->assertException(RuntimeException::class,function() use ($source) { return $source->loadResults(); });
  }
}
