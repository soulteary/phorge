<?php

final class AlmanacInventoryBoundaryTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true);
  }

  public function testNativeCreationCapabilitiesRemainRestricted() {
    $user = $this->generateNewTestUser();
    $app = new PhabricatorAlmanacApplication();
    foreach (array(
      AlmanacCreateDevicesCapability::CAPABILITY,
      AlmanacCreateNetworksCapability::CAPABILITY,
      AlmanacCreateServicesCapability::CAPABILITY,
      AlmanacCreateNamespacesCapability::CAPABILITY,
      AlmanacManageClusterServicesCapability::CAPABILITY,
    ) as $capability) {
      $this->assertFalse(
        PhabricatorPolicyFilter::hasCapability($user, $app, $capability));
    }
    $this->assertFalse(isset(
      AlmanacServiceType::getCreatableServiceTypes()['drydock.pool']));
  }

  public function testMissingServiceReturnsNotFound() {
    $user = $this->generateNewTestUser();
    foreach (array(array(), array('serviceID' => 2147483647)) as $data) {
      $response = $this->newBindingController($user, $data);
      $this->assertTrue($response instanceof Aphront404Response);
    }
  }

  public function testOrdinaryUserCanReadButCannotEdit() {
    $user = $this->generateNewTestUser();
    list($device, $network, $service, $interface, $binding) =
      $this->newInventory();
    foreach (array($device, $network, $service, $interface, $binding) as $object) {
      $query_class = get_class($object).'Query';
      $found = id(new $query_class())->setViewer($user)
        ->withIDs(array($object->getID()))->executeOne();
      $this->assertTrue($found !== null);
      $this->assertFalse(PhabricatorPolicyFilter::hasCapability(
        $user, $found, PhabricatorPolicyCapability::CAN_EDIT));
    }
    $denied = false;
    try {
      $this->newBindingController($user, array('serviceID' => $service->getID()));
    } catch (PhabricatorPolicyException $ex) {
      $denied = true;
    }
    $this->assertTrue($denied);
  }

  public function testBindingRequiresVisibleServiceDeviceAndNetwork() {
    $user = $this->generateNewTestUser();
    foreach (array('device', 'network', 'service') as $hidden) {
      list($device, $network, $service, $interface, $binding) =
        $this->newInventory();
      $objects = array('device' => $device, 'network' => $network,
        'service' => $service);
      $objects[$hidden]->setViewPolicy(PhabricatorPolicies::POLICY_ADMIN)->save();
      $result = id(new AlmanacBindingQuery())->setViewer($user)
        ->withPHIDs(array($binding->getPHID()))->execute();
      $this->assertEqual(array(), $result, $hidden);
      if ($hidden !== 'service') {
        $result = id(new AlmanacInterfaceQuery())->setViewer($user)
          ->withPHIDs(array($interface->getPHID()))->execute();
        $this->assertEqual(array(), $result, $hidden);
      }
    }
  }

  public function testDeniedTransactionDoesNotChangeDevice() {
    $user = $this->generateNewTestUser();
    list($device) = $this->newInventory();
    $name = $device->getName();
    $denied = false;
    try {
      id(new AlmanacDeviceEditor())->setActor($user)
        ->setContentSource(PhabricatorContentSource::newForSource(
          PhabricatorUnitTestContentSource::SOURCECONST))
        ->applyTransactions($device, array(
          id(new AlmanacDeviceTransaction())
            ->setTransactionType(AlmanacDeviceNameTransaction::TRANSACTIONTYPE)
            ->setNewValue('denied-rename'),
        ));
    } catch (PhabricatorPolicyException $ex) {
      $denied = true;
    }
    $this->assertTrue($denied);
    $fresh = id(new AlmanacDevice())->load($device->getID());
    $this->assertEqual($name, $fresh->getName());
  }

  public function testConduitAndSelectorOmitRestrictedRelations() {
    $user = $this->generateNewTestUser();
    list($device, $network, $service, $interface, $binding) =
      $this->newInventory();
    $params = array('constraints' => array('ids' => array($binding->getID())));
    $request = id(new ConduitAPIRequest($params, true))->setUser($user);
    $result = id(new AlmanacBindingSearchConduitAPIMethod())
      ->executeMethod($request);
    $this->assertEqual(1, count($result['data']));
    $network->setViewPolicy(PhabricatorPolicies::POLICY_ADMIN)->save();
    $result = id(new AlmanacBindingSearchConduitAPIMethod())
      ->executeMethod($request);
    $this->assertEqual(array(), $result['data']);
    $result = id(new AlmanacInterfaceDatasource())->setViewer($user)
      ->setQuery($device->getName())->loadResults();
    $this->assertEqual(array(), $result);
  }

  public function testRestrictedBindingRoutesDenyOrdinaryUser() {
    $user = $this->generateNewTestUser();
    list($device, $network, $service, $interface, $binding) =
      $this->newInventory();
    foreach (array('AlmanacBindingEditController',
      'AlmanacBindingDisableController') as $class) {
      $request = id(new AphrontRequest('example.test', '/almanac/binding/'))
        ->setUser($user)->setURIMap(array('id' => $binding->getID()))
        ->setRequestData(array());
      $denied = false;
      try {
        id(new $class())->setRequest($request)->handleRequest($request);
      } catch (PhabricatorPolicyException $ex) {
        $denied = true;
      }
      $this->assertTrue($denied, $class);
    }
  }

  public function testBindingTransactionsPreserveDeviceAndServiceLinks() {
    $admin = $this->generateNewTestUser()->setIsAdmin(1)->save();
    list($device, $network, $service) = $this->newInventory();
    $interface = AlmanacInterface::initializeNewInterface()
      ->setDevicePHID($device->getPHID())->setNetworkPHID($network->getPHID())
      ->setAddress('192.0.2.21')->setPort(9443)->save();
    $binding = AlmanacBinding::initializeNewBinding($service);
    id(new AlmanacBindingEditor())->setActor($admin)
      ->setContentSource(PhabricatorContentSource::newForSource(
        PhabricatorUnitTestContentSource::SOURCECONST))
      ->applyTransactions($binding, array(
        id(new AlmanacBindingTransaction())
          ->setTransactionType(AlmanacBindingInterfaceTransaction::TRANSACTIONTYPE)
          ->setNewValue($interface->getPHID()),
      ));
    $fresh = id(new AlmanacBindingQuery())->setViewer($admin)
      ->withIDs(array($binding->getID()))->executeOne();
    $this->assertEqual($device->getPHID(), $fresh->getDevicePHID());
    $this->assertEqual($service->getPHID(), $fresh->getServicePHID());
    $this->assertEqual($interface->getPHID(), $fresh->getInterfacePHID());
    $this->assertFalse((bool)$fresh->getIsDisabled());
    id(new AlmanacBindingEditor())->setActor($admin)
      ->setContentSource(PhabricatorContentSource::newForSource(
        PhabricatorUnitTestContentSource::SOURCECONST))
      ->applyTransactions($fresh, array(
        id(new AlmanacBindingTransaction())
          ->setTransactionType(AlmanacBindingDisableTransaction::TRANSACTIONTYPE)
          ->setNewValue(1),
      ));
    $disabled = id(new AlmanacBinding())->load($binding->getID());
    $this->assertTrue((bool)$disabled->getIsDisabled());
    $this->assertEqual($device->getPHID(), $disabled->getDevicePHID());
  }

  private function newBindingController(PhabricatorUser $user, array $data) {
    $old = idx($_SERVER, 'REQUEST_METHOD');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
      $request = id(new AphrontRequest('example.test', '/almanac/binding/edit/'))
        ->setUser($user)->setURIMap(array('id' => null))->setRequestData($data);
      return id(new AlmanacBindingEditController())->setRequest($request)
        ->handleRequest($request);
    } finally {
      if ($old === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $old;
      }
    }
  }

  private function newInventory() {
    $seed = $this->getNextObjectSeed();
    $device = AlmanacDevice::initializeNewDevice()
      ->setName('test-device-'.$seed)->save();
    $network = AlmanacNetwork::initializeNewNetwork()
      ->setName('test-network-'.$seed)->save();
    $service = AlmanacService::initializeNewService('almanac.custom')
      ->setName('test-service-'.$seed)->save();
    $interface = AlmanacInterface::initializeNewInterface()
      ->setDevicePHID($device->getPHID())->setNetworkPHID($network->getPHID())
      ->setAddress('192.0.2.20')->setPort(8443)->save();
    $binding = AlmanacBinding::initializeNewBinding($service)
      ->setDevicePHID($device->getPHID())
      ->setInterfacePHID($interface->getPHID())->save();
    return array($device, $network, $service, $interface, $binding);
  }

}
