<?php
require __DIR__.'/diagnostic.php';
$root=dirname(__DIR__,3);$secret='secret-query-token-PHID-USER-private-canary';
try{throw new RuntimeException($secret);}catch(Throwable$e){$d=media_positive_diagnostic($e,$root,'primary');}
if(array_keys($d)!==array('phase','exception_class','source','line')||$d['exception_class']!=='RuntimeException'||$d['source']!=='support/governance/media-session-positive/test-diagnostic.php'||$d['line']<1){throw new Exception('public hint');}
$json=json_encode($d);if(str_contains($json,$secret)||str_contains($json,$root)||str_contains($json,'message')||str_contains($json,'trace')){throw new Exception('leak');}
$dir=sys_get_temp_dir().'/private-source-canary-'.bin2hex(random_bytes(8));mkdir($dir,0700);$file=$dir.'/credential-private-canary.php';file_put_contents($file,'<?php return new RuntimeException("'.$secret.'");');
try{$external=require$file;$d=media_positive_diagnostic($external,$root,'cleanup-native-writer');if($d['source']!=='external'||$d['line']!==0||str_contains(json_encode($d),'canary')){throw new Exception('external path leak');}}
finally{unlink($file);rmdir($dir);}
if(media_positive_diagnostic(null,$root,'primary')!==null){throw new Exception('success diagnostic');}
$rejected=false;try{media_positive_diagnostic(new Exception($secret),$root,$secret);}catch(InvalidArgumentException$e){$rejected=true;}if(!$rejected){throw new Exception('phase allowlist');}
echo "safe-diagnostic-controls: passed; no SQL/bootstrap\n";
