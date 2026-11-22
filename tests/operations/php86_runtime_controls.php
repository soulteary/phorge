<?php

// Execute in either baseline or candidate containers; never load deployment data.
$root = dirname(__DIR__, 2);
require_once $root.'/support/runtime/php-version.php';
$checks = array();
$check = static function($value, $name) use (&$checks) {
  if ($value !== true) { throw new RuntimeException('PHP compatibility control failed: '.$name); }
  $checks[] = $name;
};
$check(phorge_php_version_is_supported('8.5.13') &&
  phorge_php_version_is_supported('8.5.14') &&
  !phorge_php_version_is_supported('8.6.0'), 'production-branch-unchanged');
$old = array();
foreach (array('PHORGE_PHP_COMPATIBILITY_PROFILE','PHORGE_GOVERNANCE_ISOLATED_TEST',
  'PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $key) {
  $old[$key] = getenv($key);
}
$refuse = static function($version) use ($check) {
  try { phorge_assert_supported_php_version($version); }
  catch (RuntimeException $e) { return; }
  throw new RuntimeException('Unsupported candidate admitted.');
};
try {
  putenv('PHORGE_PHP_COMPATIBILITY_PROFILE');
  $refuse('8.6.0');$check(true,'candidate-never-default');
  putenv('PHORGE_PHP_COMPATIBILITY_PROFILE=php86-isolated-v1');
  putenv('PHORGE_GOVERNANCE_ISOLATED_TEST');
  $refuse(PHP_VERSION);$check(true,'second-explicit-opt-in-required');
  putenv('PHORGE_GOVERNANCE_ISOLATED_TEST=1');
  foreach (array('php86','production','1') as $profile) {
    putenv('PHORGE_PHP_COMPATIBILITY_PROFILE='.$profile);$refuse(PHP_VERSION);
  }
  $check(true,'unknown-profiles-refused');
  putenv('PHORGE_PHP_COMPATIBILITY_PROFILE=php86-isolated-v1');
  foreach (array('8.6.0RC1','8.6.0-dev','8.7.0','9.0.0','8.6.0'."\n") as $version) {
    $refuse($version);
  }
  $check(true,'prerelease-other-branch-and-forged-version-refused');
  foreach (array('PHABRICATOR_ENV','PHORGE_DEPLOYMENT_CONFIG','PHORGE_CONTROL_PLANE') as $key) {
    putenv($key.'=synthetic-deployment-selector');$refuse(PHP_VERSION);putenv($key);
  }
  $check(true,'deployment-selectors-refused');
  if (PHP_VERSION === '8.6.0') {
    phorge_assert_supported_php_version(PHP_VERSION);
    $check(true,'actual-candidate-explicitly-admitted');
    $runtime = static function(array $flags) use ($root) {
      $process=proc_open(array_merge(array(PHP_BINARY),$flags,
        array($root.'/scripts/runtime/verify-php86-candidate.php')),
        array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
      if(!is_resource($process)){throw new RuntimeException('Runtime verifier child failed.');}
      fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);
      stream_get_contents($pipes[2]);fclose($pipes[2]);
      return array(proc_close($process),$out);
    };
    $valid=$runtime(array());
    $check($valid[0]===0&&json_decode($valid[1],true)['passed']===true,
      'actual-runtime-verifier-positive');
    foreach(array('jit-mode'=>array('-d','opcache.jit=tracing'),
      'jit-buffer'=>array('-d','opcache.jit_buffer_size=1M'),
      'session-strict'=>array('-d','session.use_strict_mode=0'),
      'upload-progress'=>array('-d','session.upload_progress.enabled=1'),
      'missing-security-settings'=>array('-n')) as $name=>$flags){
      $check($runtime($flags)[0]!==0,'runtime-verifier-refuses-'.$name);
    }
    $sandbox = sys_get_temp_dir().'/php86-gate-'.bin2hex(random_bytes(8));
    mkdir($sandbox,0700);mkdir($sandbox.'/support',0700);
    mkdir($sandbox.'/support/runtime',0700);mkdir($sandbox.'/conf',0700);
    mkdir($sandbox.'/conf/local',0700);
    copy($root.'/support/runtime/php-version.php',$sandbox.'/support/runtime/php-version.php');
    $child = static function() use ($sandbox) {
      $process = proc_open(array(PHP_BINARY,'-r',
        'require $argv[1]; phorge_assert_supported_php_version(PHP_VERSION);',
        $sandbox.'/support/runtime/php-version.php'),
        array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
      if (!is_resource($process)) { throw new RuntimeException('Candidate gate child failed.'); }
      fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);
      stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
      return array($code,$out);
    };
    try {
      $check($child()===array(0,''),'clean-candidate-child-admitted');
      foreach (array('local.json','deployment.json','ENVIRONMENT') as $name) {
        $path=$sandbox.'/conf/local/'.$name;file_put_contents($path,'synthetic-only');
        try { $check($child()[0]!==0,'deployment-file-refused-'.$name); }
        finally { unlink($path); }
        symlink($sandbox.'/missing',$path);
        try { $check($child()[0]!==0,'dangling-deployment-link-refused-'.$name); }
        finally { unlink($path); }
      }
    } finally {
      unlink($sandbox.'/support/runtime/php-version.php');
      rmdir($sandbox.'/support/runtime');rmdir($sandbox.'/support');
      rmdir($sandbox.'/conf/local');rmdir($sandbox.'/conf');rmdir($sandbox);
    }
  } else {
    $refuse(PHP_VERSION);$check(true,'baseline-cannot-claim-candidate-profile');
    putenv('PHORGE_PHP_COMPATIBILITY_PROFILE');
  }
  require_once $root.'/support/runtime/bootstrap.php';
  $directories = array();
  try {
    for ($i=0;$i<20;$i++) {
      $path = Filesystem::createTemporaryDirectory('php86-control-',0700);
      $directories[] = $path;
      $check(preg_match('/php86-control-[a-f0-9]{16}\z/', $path) === 1,
        'temporary-name-'.$i);
      $check((fileperms($path)&0777) === 0700, 'temporary-mode-'.$i);
    }
    $check(count(array_unique($directories))===20, 'temporary-directories-distinct');
  } finally {
    foreach (array_reverse($directories) as $path) { rmdir($path); }
  }
} finally {
  foreach ($old as $key=>$value) { putenv($value===false?$key:$key.'='.$value); }
}
echo json_encode(array('passed'=>true,'runtime'=>PHP_VERSION,'checks'=>$checks,
  'production_changed'=>false),JSON_THROW_ON_ERROR)."\n";
