"""Actual native transaction-stack methods with no-SQL query capture only.
Reflection supplies cleanup state, never a live authorization or Store proof.
"""
import unittest
from test_project_description_execution import php
SETUP=r'''
class TaskLifecycleCapture extends AphrontDatabaseConnection {
 public $queries=[];public $failCommit=false;
 public function query($pattern){$this->queries[]=$pattern;if($pattern==='COMMIT'&&$this->failCommit){throw new RuntimeException('synthetic_ack_loss');}return $this;}
 public function getInsertID(){} public function getAffectedRows(){} public function selectAllResults(){}
 public function executeQuery(PhutilQueryString $query){throw new RuntimeException('no_sql');}
 public function executeRawQueries(array $raw_queries){throw new RuntimeException('no_sql');}
 public function close(){} public function openConnection(){throw new RuntimeException('no_sql');}
 public function simulateErrorOnNextQuery($error){}
 public function escapeBinaryString($v){throw new RuntimeException("no_sql");}
 public function escapeUTF8String($v){throw new RuntimeException("no_sql");}
 public function escapeColumnName($v){throw new RuntimeException("no_sql");}
 public function escapeMultilineComment($v){throw new RuntimeException("no_sql");}
 public function escapeStringForLikeClause($v){throw new RuntimeException("no_sql");}
}
function lifecycleScope($conn,$state='open',$owned='owned'){
 $s=(new ReflectionClass(PhorgeTaskContentGrantWriteScope::class))->newInstanceWithoutConstructor();
 foreach(['connection'=>$conn,'state'=>$state,'transactionOwnership'=>$owned,'ownedTransactionState'=>(new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($conn),'ownedGeneration'=>(new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($conn)->getGeneration()]as$k=>$v){(new ReflectionProperty(PhorgeTaskContentGrantWriteScope::class,$k))->setValue($s,$v);}registerLifecycle($s,$conn);return $s;
}
function registerLifecycle($s,$c){$p=new ReflectionProperty(PhorgeTaskContentGrantWriteScope::class,'active');$v=$p->getValue();$v[spl_object_id($c)]=WeakReference::create($s);$p->setValue(null,$v);}
class TaskLifecycleCache {public $value='current';public $writes=[];public function setKey($k,$v){$this->value=$v;$this->writes[]=[$k,$v];}public function deleteKeys($k){$this->value=null;$this->writes[]=$k;}}
function cacheLifecycle($s,$cache,$prior){(new ReflectionProperty(PhabricatorCaches::class,'requestCache'))->setValue(null,$cache);foreach(['cache'=>$cache,'cachePrior'=>['key'=>$prior],'cacheMissing'=>new stdClass()]as$k=>$v){(new ReflectionProperty(PhorgeTaskContentGrantWriteScope::class,$k))->setValue($s,$v);}(new ReflectionProperty(PhorgeTaskContentGrantWriteScope::class,'cacheFrameOwner'))->setValue(null,WeakReference::create($s));}
function admissionLifecycle($c){(new ReflectionMethod(PhorgeTaskContentGrantWriteScope::class,'assertAdmissionReservation'))->invoke(null,$c);}
function commitLifecycle($s){(new ReflectionMethod(PhorgeTaskContentGrantWriteScope::class,'finishOwnedTransaction'))->invoke($s);}
'''
class TaskWriteLifecycle(unittest.TestCase):
 def test_committed_scope_close_preserves_later_foreign_native_transaction(self):
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$s=lifecycleScope($c);commitLifecycle($s);$c->openTransaction();$s->close();$s->close();$result=['queries'=>$c->queries,'foreign_active'=>$c->isInsideTransaction()];$c->killTransaction();echo json_encode($result);")
  self.assertEqual(d,{'queries':['START TRANSACTION','COMMIT','START TRANSACTION'],'foreign_active':True})
 def test_owned_error_rolls_back_exactly_once(self):
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$s=lifecycleScope($c);$s->close();$s->close();echo json_encode(['queries'=>$c->queries,'active'=>$c->isInsideTransaction()]);")
  self.assertEqual(d,{'queries':['START TRANSACTION','ROLLBACK'],'active':False})
 def test_foreign_nested_depth_refuses_cleanup_and_commit_without_killing(self):
  d=php(SETUP+r"$out=[];foreach(['close','commit']as$m){$c=new TaskLifecycleCapture();$c->openTransaction();$s=lifecycleScope($c);$c->openTransaction();$errors=[];try{if($m==='close'){$s->close();}else{commitLifecycle($s);}}catch(RuntimeException$e){$errors[]=$e->getMessage();}try{$s->close();}catch(RuntimeException$e){$errors[]=$e->getMessage();}$out[]=['queries'=>$c->queries,'errors'=>$errors,'active'=>$c->isInsideTransaction(),'source'=>PhorgeTaskContentGrantWriteScope::isSourceConnection($c)];$c->killTransaction();$c->killTransaction();}echo json_encode($out);")
  for d in d:
   self.assertEqual(len(d['queries']),2);self.assertTrue(d['queries'][1].startswith('SAVEPOINT '));self.assertTrue(d['active']);self.assertFalse(d['source']);self.assertEqual(d['errors'][-1],'task_scope_cleanup_failed')
 def test_commit_ack_loss_depth_zero_stays_unknown_quarantined_no_retry_or_rollback(self):
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$s=lifecycleScope($c);$c->failCommit=true;$errors=[];try{commitLifecycle($s);}catch(RuntimeException$e){$errors[]=$e->getMessage();}foreach([1,2]as$i){try{$s->close();}catch(RuntimeException$e){$errors[]=$e->getMessage();}}$q=(new ReflectionProperty(PhorgeTaskContentGrantWriteScope::class,'quarantined'))->getValue();echo json_encode(['queries'=>$c->queries,'active'=>$c->isInsideTransaction(),'errors'=>$errors,'quarantined'=>$q[spl_object_id($c)]->get()===$c,'source'=>PhorgeTaskContentGrantWriteScope::isSourceConnection($c)]);")
  self.assertEqual(d,{'queries':['START TRANSACTION','COMMIT'],'active':False,'errors':['task_commit_outcome_unknown','task_scope_cleanup_failed','task_scope_cleanup_failed'],'quarantined':True,'source':False})
 def test_committed_reservation_refuses_admission_before_begin_then_normal_close_allows_next(self):
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$a=lifecycleScope($c);registerLifecycle($a,$c);$cache=new TaskLifecycleCache();cacheLifecycle($a,$cache,'original');commitLifecycle($a);$before=$c->queries;$error=null;try{admissionLifecycle($c);}catch(RuntimeException$e){$error=$e->getMessage();}$unchanged=$before===$c->queries;$a->close();admissionLifecycle($c);$c->openTransaction();$b=lifecycleScope($c);registerLifecycle($b,$c);cacheLifecycle($b,$cache,'original');$live=PhorgeTaskContentGrantWriteScope::isSourceConnection($c);$b->close();echo json_encode(['error'=>$error,'admission_no_queries'=>$unchanged,'new_source'=>$live,'queries'=>$c->queries,'cache'=>$cache->value]);")
  self.assertEqual(d,{'error':'task_cache_frame_busy','admission_no_queries':True,'new_source':True,'queries':['START TRANSACTION','COMMIT','START TRANSACTION','ROLLBACK'],'cache':'original'})
 def test_old_committed_close_does_not_unregister_or_restore_over_later_exact_owner(self):
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$a=lifecycleScope($c);registerLifecycle($a,$c);$cache=new TaskLifecycleCache();cacheLifecycle($a,$cache,'old-prior');commitLifecycle($a);$c->openTransaction();$b=lifecycleScope($c);registerLifecycle($b,$c);cacheLifecycle($b,$cache,'new-prior');$before=PhorgeTaskContentGrantWriteScope::isSourceConnection($c);$error=null;try{$a->close();}catch(RuntimeException$e){$error=$e->getMessage();}$result=['before'=>$before,'after'=>PhorgeTaskContentGrantWriteScope::isSourceConnection($c),'active'=>$c->isInsideTransaction(),'cache'=>$cache->value,'writes'=>$cache->writes,'queries'=>$c->queries,'error'=>$error];$b->close();$result['after_new_close_cache']=$cache->value;$result['after_new_close_source']=PhorgeTaskContentGrantWriteScope::isSourceConnection($c);echo json_encode($result);")
  self.assertEqual(d,{'before':True,'after':True,'active':True,'cache':'current','writes':[],'queries':['START TRANSACTION','COMMIT','START TRANSACTION'],'error':'task_scope_cleanup_failed','after_new_close_cache':'new-prior','after_new_close_source':False})
 def test_commit_ack_loss_quarantine_blocks_later_admission_without_queries(self):
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$a=lifecycleScope($c);registerLifecycle($a,$c);$c->failCommit=true;try{commitLifecycle($a);}catch(RuntimeException$e){}try{$a->close();}catch(RuntimeException$e){}$error=null;try{admissionLifecycle($c);}catch(RuntimeException$e){$error=$e->getMessage();}echo json_encode(['error'=>$error,'queries'=>$c->queries]);")
  self.assertEqual(d,{'error':'task_connection_quarantined','queries':['START TRANSACTION','COMMIT']})
class TaskGenerationRefusals(unittest.TestCase):
 def test_same_state_reused_new_generation_is_not_owned(self):
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$s=lifecycleScope($c);registerLifecycle($s,$c);$old=(new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($c);$g=$old->getGeneration();$c->killTransaction();$c->openTransaction();$before=$c->queries;$reason=null;try{$s->close();}catch(Throwable$e){$reason=$e->getMessage();}$result=['same'=>$old===(new ReflectionProperty(AphrontDatabaseConnection::class,'transactionState'))->getValue($c),'new_generation'=>$old->getGeneration()>$g,'active'=>$c->isInsideTransaction(),'no_added_query'=>$before===$c->queries,'reason'=>$reason];$c->killTransaction();echo json_encode($result);")
  self.assertEqual(d,{'same':True,'new_generation':True,'active':True,'no_added_query':True,'reason':'task_scope_cleanup_failed'})
 def test_foreign_owner_cannot_commit_before_native_save(self):
  d=php(SETUP+r"$c=new TaskLifecycleCapture();$c->openTransaction();$s=lifecycleScope($c);$new=lifecycleScope($c);registerLifecycle($new,$c);$before=$c->queries;$reason=null;try{commitLifecycle($s);}catch(Throwable$e){$reason=$e->getMessage();}echo json_encode(['no_added_query'=>$before===$c->queries,'active'=>$c->isInsideTransaction(),'reason'=>$reason]);")
  self.assertEqual(d,{'no_added_query':True,'active':True,'reason':'task_owned_transaction_depth_refused'})
if __name__=='__main__':unittest.main()
