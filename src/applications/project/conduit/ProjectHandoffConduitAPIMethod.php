<?php

final class ProjectHandoffConduitAPIMethod extends ProjectConduitAPIMethod {
  public function getAPIMethodName() { return 'project.handoff'; }
  public function getMethodDescription() { return pht('Preview or confirm an explicit project permission handoff.'); }
  protected function defineParamTypes() { return array('action'=>'required string','payload'=>'required dict'); }
  protected function defineReturnType() { return 'dict'; }
  protected function defineErrorTypes() { return array('ERR-HANDOFF'=>pht('The handoff is unavailable or its preview is no longer current.')); }
  protected function execute(ConduitAPIRequest $request) {
    try{
      $context=$request->getGovernanceActorContext();$payload=$request->getValue('payload');
      if(!is_array($payload)){throw new InvalidArgumentException('handoff_payload');}
      switch($request->getValue('action')){
        case 'preview':return PhorgeProjectHandoffService::preview($context,$payload);
        case 'commit':return PhorgeProjectHandoffService::commit($context,$payload);
        default:throw new InvalidArgumentException('handoff_action');
      }
    }catch(Throwable$e){throw new ConduitException('ERR-HANDOFF');}
  }
}
