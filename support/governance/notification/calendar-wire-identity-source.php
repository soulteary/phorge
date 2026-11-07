<?php
/** Test-only projection from the live owned fixture; never a cleanup permit. */
function governance_calendar_wire_fixture_identity($fixture) {
  $fixture->assertOwned();
  $reader=$fixture->getReaderConfig();$writer=$fixture->getWriterConfig();$summary=$fixture->getSummary();
  // Private test-fixture fields are read in the same process which created them.
  // No candidate database nonce is adopted as authority for a later cleanup.
  $configs=(new ReflectionProperty($fixture,'configs'))->getValue($fixture);
  $tables=(new ReflectionProperty($fixture,'tables'))->getValue($fixture);
  $nonce=(new ReflectionProperty($fixture,'ownerNonce'))->getValue($fixture);
  $namespace=$fixture->getNamespace();$schemas=array();
  foreach(array_keys($tables)as$app){$schemas[]=$namespace.'_'.$app;}
  sort($schemas,SORT_STRING);
  return array('namespace'=>$namespace,'owner_nonce'=>$nonce,
    'quickstart_sha256'=>$summary['quickstart_sha256'],
    'writer_user'=>$writer['user'],'setup_user'=>$configs['setup']['user'],
    'reader_user'=>$reader['user'],'schemas'=>$schemas);
}
