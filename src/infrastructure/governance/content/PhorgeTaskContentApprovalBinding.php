<?php

/** Value-only separation of common object facts and specific actor credentials. */
final class PhorgeTaskContentApprovalBinding extends Phobject {
  public static function sharedSources(array $sources) {
    $keys=array_keys($sources);sort($keys,SORT_STRING);
    if ($keys!==array('configuration','credential','nativeTransactionID','spaces','task')) {
      throw new InvalidArgumentException('task_approval_source_shape_refused');
    }
    foreach (array('configuration','credential','spaces','task') as $key) {
      if (!is_string($sources[$key]) || !preg_match('/\A[a-f0-9]{64}\z/D',$sources[$key])) {
        throw new InvalidArgumentException('task_approval_source_digest_refused');
      }
    }
    if (!is_string($sources['nativeTransactionID']) || !preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$sources['nativeTransactionID'])) {
      throw new InvalidArgumentException('task_approval_generation_refused');
    }
    unset($sources['credential']);ksort($sources,SORT_STRING);return $sources;
  }
}
