"""Exact shared worker terminal gate inverse. Never revive historical exit proof."""
import hashlib
PATH='support/governance/native-organization/workers.php'
CURRENT_SHA256='07df892319f7eaac68bde2181df05e067190336334085620875bc05fabeefcbc'
BASE_SHA256='11df3cffa66837d56ffaf720a65846ed33e4aede6213893ffb5db817436f169d'
ADDED=b"  $deadline=microtime(true)+15;$terminal=null;\n  if(!is_resource($worker['process'])){throw new RuntimeException('native_worker_terminal_handle_missing');}\n  do{\n    if(microtime(true)>=$deadline){throw new RuntimeException('native_worker_timeout');}\n    $status=proc_get_status($worker['process']);\n    if(microtime(true)>=$deadline){throw new RuntimeException('native_worker_timeout');}\n    if(!is_array($status)||!is_bool($status['running']??null)){\n      throw new RuntimeException('native_worker_terminal_unknown');}\n    if($status['running']===false){$terminal=$status;break;}\n    usleep(20000);\n  }while(microtime(true)<$deadline);\n  if($terminal===null){throw new RuntimeException('native_worker_timeout');}\n  // proc_close may return -1 after status was consumed. Only the actual owned\n  // handle's terminal status can prove exit0; valid JSON is never that proof.\n  $exit=$terminal['exitcode']??null;$closed=proc_close($worker['process']);\n  $worker['process']=null;$handle['process']=null;\n  if(!is_int($exit)||$exit!==0||($terminal['signaled']??null)!==false||\n    ($terminal['stopped']??null)!==false||!is_int($closed)||!in_array($closed,array(0,-1),true)){\n    throw new RuntimeException('native_worker_nonzero_or_unknown_terminal');\n  }\n"
REMOVED=b"  $deadline=microtime(true)+15;\n  while (is_resource($worker['process']) && proc_get_status($worker['process'])['running'] && microtime(true)<$deadline) { usleep(20000); }\n  if (is_resource($worker['process']) && proc_get_status($worker['process'])['running']) {\n    throw new RuntimeException('native_worker_timeout');\n  }\n  if (is_resource($worker['process'])) { proc_close($worker['process']); }\n  $worker['process']=null; $handle['process']=null;\n"
def reduce_current(path,raw):
 if path!=PATH or type(raw)is not bytes or hashlib.sha256(raw).hexdigest()!=CURRENT_SHA256 or raw.count(ADDED)!=1:raise ValueError('unreviewed worker terminal source')
 prior=raw.replace(ADDED,REMOVED,1)
 if hashlib.sha256(prior).hexdigest()!=BASE_SHA256:raise ValueError('worker terminal inverse changed other bytes')
 return prior
def reduce_if_selected(path,raw):
 if path!=PATH:return raw
 if type(raw)is bytes and hashlib.sha256(raw).hexdigest()==BASE_SHA256:return raw
 return reduce_current(path,raw)
