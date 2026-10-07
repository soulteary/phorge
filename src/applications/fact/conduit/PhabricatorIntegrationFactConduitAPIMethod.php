<?php

final class PhabricatorIntegrationFactConduitAPIMethod extends ConduitAPIMethod {
  public function getAPIMethodName() { return 'integration.fact'; }
  public function getMethodDescription() { return pht('Export a bounded page of semantic fact datapoints.'); }
  public function shouldRequireAuthentication() { return false; }
  protected function defineParamTypes() {
    return array('phase' => 'required string', 'className' => 'optional string',
      'position' => 'optional string');
  }
  protected function defineReturnType() { return 'wild'; }
  protected function execute(ConduitAPIRequest $request) {
    PhabricatorIntegrationInboundConduitAPIMethod::assertToken();
    if (!PhabricatorGorgeIntegrationClient::enabled('fact')) {
      throw new Exception(pht('Gorge fact projection is disabled.'));
    }
    $iterators = PhabricatorFactDaemon::getAllApplicationIterators();
    if ($request->getValue('phase') === 'catalog') { return array_keys($iterators); }
    $name = $request->getValue('className');
    $position = $request->getValue('position');
    if ($request->getValue('phase') !== 'scan' || !isset($iterators[$name]) ||
        ($position && !preg_match('/\A[0-9]+(?::[0-9]+)?\z/', $position))) {
      throw new Exception(pht('Invalid fact scan.'));
    }
    $iterator = $iterators[$name]->setPageSize(20)->setPosition($position);
    $viewer = PhabricatorUser::getOmnipotentUser();
    $engines = PhabricatorFactEngine::loadAllEngines();
    foreach ($engines as $engine) { $engine->setViewer($viewer); }
    $objects = array(); $next = $position; $total = 0;
    foreach ($iterator as $cursor => $object) {
      $facts = array();
      foreach ($engines as $engine) {
        if (!$engine->supportsDatapointsForObject($object)) { continue; }
        foreach ($engine->newDatapointsForObject($object) as $fact) {
          $facts[] = array('key' => $fact->getKey(),
            'objectPHID' => $fact->getObjectPHID(),
            'dimensionPHID' => $fact->getDimensionPHID(),
            'value' => (string)$fact->getValue(), 'epoch' => (int)$fact->getEpoch());
        }
      }
      $total += count($facts);
      if ($total > 4096) { throw new Exception(pht('Fact page exceeds datapoint limit.')); }
      $objects[] = array('phid' => $object->getPHID(), 'facts' => $facts);
      $next = (string)$cursor;
      if (count($objects) >= 20) { break; }
    }
    return array('next' => (string)$next, 'objects' => $objects);
  }
}
