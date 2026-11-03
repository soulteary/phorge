<?php

final class PhabricatorFaviconRefQuery extends Phobject {

  private $refs;

  /**
   * @param array<PhabricatorFaviconRef> $refs
   */
  public function withRefs(array $refs) {
    assert_instances_of($refs, PhabricatorFaviconRef::class);
    $this->refs = $refs;
    return $this;
  }

  public function execute() {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $refs = $this->refs;
    // Default public favicon bytes have their own immutable resource route.
    // Do not publish a shared File object (which may also be an avatar).
    if(PhabricatorEnv::getEnvConfigIfExists('governance.media-exchange.http-enabled',false)===true &&
      !PhabricatorEnv::getEnvConfig('ui.favicons')){
      $remaining=array();foreach($refs as $key=>$ref){
        if(array_filter($ref->getEmblems(),static function($value){return $value!==null;})){$remaining[$key]=$ref;continue;}
        $ref->setURI(PhorgeGovernanceMediaStaticController::defaultFaviconURI($ref->getWidth(),$ref->getHeight()));
      }
      $refs=$remaining;if(!$refs){return $this->refs;}
    }

    $config_digest = PhabricatorFaviconRef::newConfigurationDigest();

    $ref_map = array();
    foreach ($refs as $ref) {
      $ref_digest = $ref->newDigest();
      $ref_key = "favicon({$config_digest},{$ref_digest},8)";

      $ref
        ->setViewer($viewer)
        ->setCacheKey($ref_key);

      $ref_map[$ref_key] = $ref;
    }

    $cache = PhabricatorCaches::getImmutableCache();
    $ref_hits = $cache->getKeys(array_keys($ref_map));

    foreach ($ref_hits as $ref_key => $ref_uri) {
      $ref_map[$ref_key]->setURI($ref_uri);
      unset($ref_map[$ref_key]);
    }

    if ($ref_map) {
      $new_map = array();
      foreach ($ref_map as $ref_key => $ref) {
        $ref_uri = $ref->newURI();
        $ref->setURI($ref_uri);
        $new_map[$ref_key] = $ref_uri;
      }

      $cache->setKeys($new_map);
    }

    return $this->refs;
  }


}
