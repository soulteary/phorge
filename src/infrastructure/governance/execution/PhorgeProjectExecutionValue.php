<?php

/** Bounded detached values, never identity or execution authority. */
final class PhorgeProjectExecutionValue extends Phobject {
  public static function canonical($value) {
    $budget=array('nodes'=>0,'bytes'=>0);
    return json_encode(self::copyValue($value,0,$budget),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  }
  private static function copyValue($value,$depth,array &$budget) {
    if ($depth>10 || ++$budget['nodes']>8192) { throw new RuntimeException('project_execution_value_budget'); }
    if (is_array($value)) {
      if (count($value)>512) { throw new RuntimeException('project_execution_value_budget'); }
      if (!array_is_list($value)) { ksort($value,SORT_STRING); }
      $copy=array();
      foreach ($value as $key=>$item) {
        if (is_string($key) && strlen($key)>256) { throw new RuntimeException('project_execution_value_budget'); }
        $budget['bytes']+=is_string($key)?strlen($key):8;
        if ($budget['bytes']>262144) { throw new RuntimeException('project_execution_value_budget'); }
        $copy[$key]=self::copyValue($item,$depth+1,$budget);
      }
      return $copy;
    }
    if (!is_null($value) && !is_bool($value) && !is_int($value) && !is_string($value)) {
      throw new RuntimeException('project_execution_value_type');
    }
    $budget['bytes']+=is_string($value)?strlen($value):8;
    if ($budget['bytes']>262144) { throw new RuntimeException('project_execution_value_budget'); }
    return $value;
  }
  public static function digest($value) { return hash('sha256',self::canonical($value)); }
  public static function identifier($value) {
    if (!is_string($value) || !preg_match('/\A[a-f0-9]{32,64}\z/',$value)) {
      throw new RuntimeException('project_execution_identifier_refused');
    }
    return $value;
  }
}
