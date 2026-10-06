<?php

final class PhabricatorSearchSourceConduitAPIMethod extends ConduitAPIMethod {
  public function getAPIMethodName() { return 'search.source'; }
  public function getMethodDescription() {
    return pht('Describe source ranges or materialize a bounded search scan page.');
  }
  public function shouldRequireAuthentication() { return false; }
  protected function defineParamTypes() {
    return array('phase' => 'required string', 'className' => 'optional string',
      'afterID' => 'optional string', 'upperID' => 'optional string');
  }
  protected function defineReturnType() { return 'map<string, wild>'; }
  public static function assertEnabledToken($token) {
    PhabricatorSearchExportConduitAPIMethod::assertServiceToken($token);
    if (!PhabricatorEnv::getEnvConfig('gorge.search.source-scan') ||
        !PhabricatorEnv::getEnvConfig('gorge.search.projection-shadow')) {
      throw new Exception(pht('Search source scanning and shadow capture must be enabled.'));
    }
  }
  protected function execute(ConduitAPIRequest $request) {
    self::assertEnabledToken(AphrontRequest::getHTTPHeader('X-Service-Token'));
    $provider = new PhabricatorSearchSourceProvider();
    switch ($request->getValue('phase')) {
      case 'catalog': return $provider->catalog();
      case 'scan': return $provider->scan($request->getValue('className'),
        $request->getValue('afterID'), $request->getValue('upperID'));
      default: throw new InvalidArgumentException(pht('Unknown source scan phase.'));
    }
  }
}
