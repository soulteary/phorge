<?php

/** Independent Task switch. Project feature flags never enable Task grants. */
final class PhorgeTaskContentGrantConfiguration extends Phobject {
  const KEY = 'governance.task-content-grants.enabled';
  public static function assertEnabled() {
    $value=PhabricatorEnv::getEnvConfigIfExists(self::KEY,null);
    if (!is_bool($value)) {
      throw new RuntimeException('task_content_configuration_unknown');
    }
    if ($value!==true) { throw new RuntimeException('task_content_grant_disabled'); }
  }
  /** Exact keys only. This list does not admit an execution scope. */
  public static function keys() {
    $keys=PhorgeProjectExecutionCatalog::configurationKeys();
    $keys[]=self::KEY; $keys=array_values(array_unique($keys));
    sort($keys,SORT_STRING); return $keys;
  }
}
