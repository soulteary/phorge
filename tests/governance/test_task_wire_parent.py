"""Source/owned-FS controls only: no SQL, services, worker or SMTP execution."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
HARNESS=ROOT/'tests/governance/private_mail_ci'
sys.path.insert(0,str(HARNESS))
import run_task

class TaskWireParentControls(unittest.TestCase):
    def php(self,code,*args):
        p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen',
                          '-r',code,*map(str,args)],capture_output=True,text=True,check=True,timeout=10)
        self.assertEqual('',p.stderr)
        return json.loads(p.stdout)

    def test_actual_write_guard_checks_each_distinct_native_handle(self):
        result=self.php(r'''
require $argv[1];
class ScopeConn {function __construct(public $name){} function isInsideTransaction(){return $this->name===$GLOBALS['bad'];}}
class ScopeDAO {function establishConnection($mode){if($mode!=='w'){throw new Exception('mode');}return new ScopeConn(get_class($this));}}
class ManiphestTask extends ScopeDAO{} class PhabricatorFile extends ScopeDAO{}
class PhabricatorWorkerActiveTask extends ScopeDAO{} class PhabricatorMetaMTAMail extends ScopeDAO{}
$GLOBALS['bad']='';governance_task_wire_assert_no_inherited_writes();$n=1;
foreach(array('ManiphestTask','PhabricatorFile','PhabricatorWorkerActiveTask','PhabricatorMetaMTAMail')as$name){
 $GLOBALS['bad']=$name;try{governance_task_wire_assert_no_inherited_writes();throw new Exception('accepted');}
 catch(RuntimeException $expected){$n++;}}
echo json_encode(array('checks'=>$n,'SQL'=>false));
''',ROOT/'support/governance/notification/task-wire-producer.php')
        self.assertEqual({'checks':5,'SQL':False},result)
        source=(ROOT/'support/governance/notification/task-wire-producer.php').read_text()
        method=source[source.index('function governance_task_wire_enqueue'):source.index('function governance_task_wire_collect')]
        self.assertLess(method.index('governance_task_wire_assert_no_inherited_writes();'),method.index('PhabricatorFile::newFromFileData'))

    def test_actual_native_lineage_function_rejects_other_mail_and_duplicate_followup(self):
        result=self.php(r'''
require $argv[1];function head($a){return reset($a);}
class PhabricatorWorkerTaskData {function load($id){return new Datum($GLOBALS['data'][$id]);}}
class Datum {function __construct(private $value){} function getData(){return $this->value;}}
class PhabricatorWorkerActiveTask {
 function __construct(public $id=0,public $class='',public $data=0){}
 function getID(){return $this->id;}function getTaskClass(){return $this->class;}function getDataID(){return $this->data;}
 function loadAllWhere(...$a){return $GLOBALS['active'];}
}
class PhabricatorWorkerArchiveTask extends PhabricatorWorkerActiveTask {
 const RESULT_SUCCESS=0;
 function load($id){return $GLOBALS['archive'][$id]??null;}
 function getResult(){return 0;}
 function loadAllWhere(...$a){return array_values(array_filter($GLOBALS['archive'],fn($v)=>$v->class==='GorgeMailSubmitWorker'));}
}
class Outbox {function getQueueTaskID(){return 101;}}
$phid='PHID-MTAM-'.str_repeat('a',20);$delivery='mail/'.$phid.'/1';$binding=array('mail_id'=>7,'mail_phid'=>$phid);
$snapshot=array('schemaVersion'=>1,'mailID'=>7,'deliveryID'=>$delivery);
$GLOBALS['active']=array();$GLOBALS['archive']=array(101=>new PhabricatorWorkerArchiveTask(101,'GorgeMailDeliveryWorker',201),102=>new PhabricatorWorkerArchiveTask(102,'GorgeMailSubmitWorker',202));
$GLOBALS['data']=array(201=>7,202=>array('mailID'=>7,'deliveryID'=>$delivery));
$v=governance_task_wire_worker_evidence($binding,new Outbox(),$snapshot);
if($v!==array('prepare_task_id'=>101,'prepare_archive_success'=>true,'submit_task_id'=>102,'submit_archive_success'=>true)){throw new Exception('positive');}$n=1;
$GLOBALS['data'][201]=8;try{governance_task_wire_worker_evidence($binding,new Outbox(),$snapshot);throw new Exception('othermail');}catch(RuntimeException $expected){$n++;}
$GLOBALS['data'][201]=7;$bad=$snapshot;$bad['deliveryID']='other';
try{governance_task_wire_worker_evidence($binding,new Outbox(),$bad);throw new Exception('otherdelivery');}catch(RuntimeException $expected){$n++;}
$GLOBALS['active'][]=new PhabricatorWorkerActiveTask(103,'GorgeMailSubmitWorker',202);
try{governance_task_wire_worker_evidence($binding,new Outbox(),$snapshot);throw new Exception('duplicate');}catch(RuntimeException $expected){$n++;}
echo json_encode(array('checks'=>$n,'SQL'=>false,'actual_worker'=>false));
''',ROOT/'support/governance/notification/task-wire-producer.php')
        self.assertEqual({'checks':4,'SQL':False,'actual_worker':False},result)

    def test_actual_private_fs_commands_and_atomic_no_overwrite_publication(self):
        result=self.php(r'''
require $argv[1];$d=realpath(sys_get_temp_dir()).'/phorge-private-mail-wire-'.bin2hex(random_bytes(12));mkdir($d,0700);
$o=str_repeat('a',64);file_put_contents($d.'/owner',$o);chmod($d.'/owner',0600);$n=0;
try{
 if(governance_task_wire_command($d,$o,1)!==null){throw new Exception('absent');}$n++;
 $p=$d.'/task-command-1.json';file_put_contents($p,json_encode(array('owner'=>$o,'sequence'=>1,'action'=>'inspect')));chmod($p,0600);
 if(governance_task_wire_command($d,$o,1)!=='inspect'){throw new Exception('command');}$n++;
 governance_task_wire_publish($d,$o,'task-observation-1.json',array('owner'=>$o,'sequence'=>1));$n++;
 try{governance_task_wire_publish($d,$o,'task-observation-1.json',array());throw new Exception('overwrite');}catch(RuntimeException $expected){$n++;}
 foreach(array(0,65,true,'1')as$seq){try{governance_task_wire_command($d,$o,$seq);throw new Exception('sequence');}catch(RuntimeException $expected){$n++;}}
 file_put_contents($p,json_encode(array('owner'=>$o,'sequence'=>1,'action'=>'execute-arbitrary-task')));chmod($p,0600);
 try{governance_task_wire_command($d,$o,1);throw new Exception('action');}catch(RuntimeException $expected){$n++;}
 unlink($p);symlink($d.'/owner',$p);
 try{governance_task_wire_command($d,$o,1);throw new Exception('symlink');}catch(RuntimeException $expected){$n++;}
 try{governance_task_wire_command($d,str_repeat('b',64),1);throw new Exception('owner');}catch(RuntimeException $expected){$n++;}
 echo json_encode(array('checks'=>$n,'SQL'=>false,'SMTP'=>false));
}finally{foreach(scandir($d)as$f){if($f!=='.'&&$f!=='..'){unlink($d.'/'.$f);}}rmdir($d);}
''',ROOT/'support/governance/notification/task-wire-protocol.php')
        self.assertEqual({'checks':11,'SQL':False,'SMTP':False},result)

    def test_worker_manifest_exact_bytes_revision_and_closed_shape(self):
        import hashlib
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'worker';p.write_bytes(b'synthetic-not-executed');p.chmod(0o700)
            config={'gorge_revision':'a'*40}
            value={'path':str(p),'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'source_revision':'a'*40}
            self.assertEqual(value,run_task.validate_worker(value,config))
            for mutation in ({'source_revision':'b'*40},{'sha256':'0'*64},{'unexpected':True}):
                with self.subTest(mutation=mutation),self.assertRaises(ValueError):run_task.validate_worker(value|mutation,config)
            p.write_bytes(b'changed')
            with self.assertRaises(ValueError):run_task.validate_worker(value,config)

    def test_actual_parent_profiles_do_not_accept_arbitrary_worker_classes(self):
        ready={'queue_uri':'http://127.0.0.1:1234','queue_token':'a'*64,'callback_origin':'http://127.0.0.1:1235','mailer_token':'b'*64,
               'relay_database':{'user':'synthetic','password':'synthetic','port':3306,'database':'synthetic'}}
        for classes in (('PhabricatorApplicationTransactionPublishWorker',),('GorgeMailDeliveryWorker',),('GorgeMailSubmitWorker',)):
            env=run_task.worker_environment(ready,'c'*64,'http://127.0.0.1:1236',Path('/tmp/synthetic'),1237,classes)
            self.assertEqual(','.join(classes),env['GORGE_WORKER_TASK_CLASS_FILTER'])
            self.assertNotIn('GORGE_WORKER_MAIL_OUTBOX_DSN',env)
        for classes in ((),('ArbitraryWorker',),('GorgeMailSubmitWorker','PhabricatorApplicationTransactionPublishWorker')):
            with self.subTest(classes=classes),self.assertRaises(ValueError):run_task.worker_environment(ready,'x','http://127.0.0.1:1236',Path('/tmp/synthetic'),1237,classes)

    def test_actual_git_source_admission_refuses_dirty_untracked_helpers(self):
        # Exercise the real Git dirty guard independently of the checkout state.
        # This component fixture never enters a runtime SourceOperation.
        import shutil
        import task_source
        from runtime import digest,ROLES
        from pure_inventory import inventory
        names=task_source.declared_files(ROOT,run_task.TASK_FILES,frozenset())
        with tempfile.TemporaryDirectory()as temporary:
            private=Path(temporary).resolve();source=private/'source';source.mkdir()
            for name in names:
                target=source/name;target.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(ROOT/name,target);target.chmod(0o644)
            task_source.git(source,'init','--quiet')
            task_source.git(source,'add','.')
            task_source.git(source,'-c','user.name=Component','-c','user.email=component@example.invalid','-c','commit.gpgsign=false','commit','--quiet','-m','Complete owned Task source component')
            revision=task_source.git(source,'rev-parse','HEAD').decode().strip()
            binary=private/'synthetic-binary';binary.write_bytes(b'synthetic configuration validation only; never executed\n');binary.chmod(0o700)
            php=shutil.which('php');self.assertIsNotNone(php)
            config={'schema':1,'engine':'linux-local','source_root':str(source),'source_revision':revision,
                    'source_sha256':{name:digest(source/name)for name in names},'php':str(Path(php).resolve()),
                    'binaries':{role:{'path':str(binary),'sha256':digest(binary),'source_revision':revision}for role in ROLES},
                    'gorge_revision':revision,'database':{'host':'127.0.0.1','port':3306,'database':'governance_fixture_bootstrap','user':'root','password_env':'TEST_PASSWORD'},
                    'expected_pure_methods':len(inventory(ROOT))}
            self.assertEqual(set(config['source_sha256']),task_source.declared_files(source,run_task.TASK_FILES,frozenset()))
            self.assertIsNone(run_task.validate_task_source(config))
            unknown=source/'owned-untracked-helper.py';unknown.write_bytes(b'owned untracked fixture\n')
            try:
                with self.assertRaisesRegex(ValueError,'^task_clean_complete_source_required$'):run_task.validate_task_source(config)
            finally:unknown.unlink()
            self.assertIsNone(run_task.validate_task_source(config))
            name=sorted(run_task.TASK_FILES)[0];target=source/name;original=target.read_bytes()
            target.write_bytes(original+b'\nowned tracked drift\n')
            changed=dict(config);changed['source_sha256']=dict(config['source_sha256']);changed['source_sha256'][name]=digest(target)
            try:
                with self.assertRaisesRegex(ValueError,'^task_clean_complete_source_required$'):run_task.validate_task_source(changed)
            finally:target.write_bytes(original)
            self.assertIsNone(run_task.validate_task_source(config))

    def test_default_five_role_and_hosted_five_case_contracts_are_unchanged(self):
        from runtime import ROLES
        self.assertEqual(frozenset(('file','taskqueue','mailer','observer','owlmail')),ROLES)
        before=subprocess.check_output(['git','-C',str(ROOT),'show','c4b2ae7cc72a91ff942f08f9b4725104f114640c:tests/governance/private_mail_ci/run_hosted.py'])
        self.assertEqual(before,(HARNESS/'run_hosted.py').read_bytes())

    def test_new_driver_branch_inverse_preserves_all_old_default_bytes(self):
        name='support/governance/native-organization/test-native-organization-mysql.php'
        current=(ROOT/name).read_text()
        # The merged main retains the independently reviewed PR142 cleanup fence.
        # Normalize only its exact signed-main source bytes for this old fixture view.
        import recovery_conduit_source_delta as driver_view
        module_path='scripts/governance/recovery_conduit_source_delta.py'
        self.assertEqual(Path(driver_view.__file__).resolve(),ROOT/module_path)
        reviewed=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','7a9c45c68a3632b7163071a9668c75b3c85c1491:'+module_path])
        self.assertEqual((ROOT/module_path).read_bytes(),reviewed)
        cleanup=driver_view.POLICY_CLEANUP_DRIVER_ADDITION
        self.assertEqual(284,len(cleanup))
        self.assertEqual('1940642ae67ae23451133f5f986bd4ad0106bc5ee083461bdf7bc7aca0bf0943',__import__('hashlib').sha256(cleanup).hexdigest())
        self.assertEqual(1,current.count(cleanup.decode('utf-8')))
        current=current.replace(cleanup.decode('utf-8'),'',1)
        browser_changes=(
            ("'--task-mail-browser-wire-hold'=>'task-mail-browser-wire-hold',",''),
            ("$task_browser=$phase==='task-mail-browser-wire-hold';\nif($task_browser){$GLOBALS['governance_workbench_http_cleanup_permitted']=false;}\n$task_wire=$task_browser||$phase==='task-mail-wire-hold';", "$task_wire=$phase==='task-mail-wire-hold';"),
            ('governance_task_wire_hold($fixture,$check,$task_browser);','governance_task_wire_hold($fixture,$check);'),
            ('($workbench_http||$notice_history||$task_browser)&&','($workbench_http||$notice_history)&&'),
        )
        for new,old in browser_changes:
            self.assertEqual(1,current.count(new));current=current.replace(new,old,1)
        self.assertEqual('7e2fca73949a6e1f0aee35f09ae68a5f6d48faed04910362b3a33fefa70f7604',__import__('hashlib').sha256(current.encode()).hexdigest())
        changes=(
            ("'--task-mail-wire-hold'=>'task-mail-wire-hold',",''),
            ("$task_wire=$phase==='task-mail-wire-hold';\n",''),
            ("$private_mail_wire=$task_wire||$phase===","$private_mail_wire=$phase==="),
            ("      if($task_wire){require_once dirname(__DIR__).'/notification/task-wire-hold.php';$native=governance_task_wire_hold($fixture,$check); }\n      elseif($notice_admin_wire)","      if($notice_admin_wire)"),
        )
        # Exact source spelling, no flexible regex that could erase other changes.
        changes=changes[:3]+((changes[3][0].replace('; }',';}'),changes[3][1]),)
        for new,old in changes:
            self.assertEqual(1,current.count(new))
            current=current.replace(new,old,1)
        before=subprocess.check_output(['git','-C',str(ROOT),'show','c4b2ae7cc72a91ff942f08f9b4725104f114640c:'+name])
        self.assertEqual(before,current.encode())
        self.assertNotEqual(before,(current+'\n// unknown change\n').encode())

    def test_queued_hold_has_no_manual_publication_execution(self):
        hold=(ROOT/'support/governance/notification/task-wire-hold.php').read_text()
        self.assertIn('governance_task_wire_enqueue(',hold)
        self.assertNotIn('governance_task_wire_produce(',hold)
        self.assertNotIn('executeTask(',hold)
        self.assertEqual(('allow','revoke-before-publication','revoke-after-prepare'),run_task.CASES)
        # Paired Gorge GET /delivery uses data.result, unlike POST /execute.
        identity='mail/synthetic/1'
        for state in ('accepted','failed','unknown','expired','cancelled','prepared','retry_wait','submitting'):
            result={'deliveryID':identity,'state':state}
            raw=json.dumps({'data':{'result':result,'attempts':[],'deadline':123}}).encode()
            self.assertEqual(run_task.delivery_inspection_terminal(raw,identity),result if state in ('accepted','failed','unknown','expired','cancelled')else None)
        for value in ({'data':{'state':'accepted','deliveryID':identity}}, {'data':{'result':{'deliveryID':'other','state':'accepted'}}}, {'data':{'result':{'deliveryID':identity,'state':'future'}}}, {'data':{'result':None}}, [], {'data':{'result':{'deliveryID':identity,'state':True}}}):
            with self.assertRaises(ValueError):run_task.delivery_inspection_terminal(json.dumps(value).encode(),identity)
        for expected in ('',None,True,1):
            with self.assertRaises(ValueError):run_task.delivery_inspection_terminal(json.dumps({'data':{'result':{'deliveryID':expected,'state':'accepted'}}}).encode(),expected)
        for actual in ('',None,True,1):
            with self.assertRaises(ValueError):run_task.delivery_inspection_terminal(json.dumps({'data':{'result':{'deliveryID':actual,'state':'accepted'}}}).encode(),identity)
        for case,result,want in (
            ('allow',{'state':'accepted','attempt':1},True),
            ('allow',{'state':'cancelled','attempt':0},False),
            ('revoke-after-prepare',{'state':'cancelled','attempt':0},True),
            ('revoke-after-prepare',{'state':'failed','attempt':0},False),
            ('revoke-after-prepare',{'state':'accepted','attempt':0},False),
            ('revoke-after-prepare',{'state':'cancelled','attempt':1},False),
            ('revoke-after-prepare',{'state':'cancelled','attempt':False},False),
            ('revoke-after-prepare',{'state':'cancelled','attempt':'0'},False),
            ('revoke-after-prepare',{'state':'cancelled'},False),
            ('revoke-before-publication',{'state':'cancelled','attempt':0},False),
            ('unknown',{'state':'accepted'},False)):
            self.assertIs(run_task.delivery_outcome_matches(result,case),want)



    def test_current_uncommitted_harness_cannot_receive_source_admission(self):
        # Real base Git has no new helper: dirty source is deliberately blocked.
        from unittest.mock import patch
        config={'source_sha256':{}}
        with patch.object(run_task,'validate_config',return_value=config):
            with self.assertRaisesRegex(ValueError,'task_source_closure_missing'):run_task.validate_task_source(config)

if __name__=='__main__':unittest.main()

class TaskTypedSourceControls(unittest.TestCase):
    """Component-only synthetic Git source; never a qualified native recipe."""
    def fixture(self,directory):
        import task_source
        root=Path(directory).resolve()/'source';root.mkdir()
        for name in task_source.PARENT_FILES:
            p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'synthetic component source\n');p.chmod(0o644)
        subprocess.run(['/usr/bin/git','init','--quiet',str(root)],check=True)
        subprocess.run(['/usr/bin/git','-C',str(root),'add','.'],check=True)
        subprocess.run(['/usr/bin/git','-C',str(root),'-c','user.name=Component','-c','user.email=component@example.invalid','-c','commit.gpgsign=false','commit','--quiet','-m','Synthetic component fixture'],check=True)
        rev=task_source.git(root,'rev-parse','HEAD').decode().strip()
        return root,rev,task_source.PARENT_FILES
    def test_typed_manifest_actual_Git_modes_and_unknown_or_corrupt_source(self):
        import task_source
        with tempfile.TemporaryDirectory()as td:
            root,rev,paths=self.fixture(td);value=task_source.typed_manifest(root,rev,paths)
            config={'source_root':str(root),'source_revision':rev,'source_sha256':{n:p['sha256']for n,p in value['files'].items()}}
            self.assertEqual(value,task_source.validate_manifest(value,config,paths))
            for key,bad in [('runtime_qualified',True),('source_tree','0'*40),('source_revision','0'*40)]:
                with self.subTest(key=key),self.assertRaises(ValueError):task_source.validate_manifest(value|{key:bad},config,paths)
            wrong=json.loads(json.dumps(value));wrong['files'][next(iter(paths))]['bytes']=True
            with self.assertRaises(ValueError):task_source.validate_manifest(wrong,config,paths)
            p=root/next(iter(paths));old=p.read_bytes();p.write_bytes(old+b'unknown')
            with self.assertRaises(ValueError):task_source.typed_manifest(root,rev,paths)
            p.write_bytes(old);p.chmod(0o600)
            with self.assertRaises(ValueError):task_source.typed_manifest(root,rev,paths)
            p.chmod(0o644);extra=root/'unknown.py';extra.write_bytes(b'unknown')
            with self.assertRaises(ValueError):task_source.typed_manifest(root,rev,paths)
    def test_parent_process_different_physical_root_is_not_a_source_capability(self):
        import task_source
        with tempfile.TemporaryDirectory()as td:
            root,rev,paths=self.fixture(td);value=task_source.typed_manifest(root,rev,paths)
            config={'source_root':str(root),'source_revision':rev,'source_sha256':{n:p['sha256']for n,p in value['files'].items()}}
            with self.assertRaises(ValueError):task_source.validate_parent_modules(config)
    def test_duplicate_private_sidecar_and_source_alias_are_refused(self):
        import task_source
        with tempfile.TemporaryDirectory()as td:
            root=Path(td).resolve();p=root/'manifest';p.write_text('{"schema":1,"schema":1}');p.chmod(0o600)
            with self.assertRaises(ValueError):task_source.load_manifest(p)
            target=root/'source';target.write_bytes(b'owned');target.chmod(0o644);link=root/'alias';link.symlink_to(target)
            with self.assertRaises(ValueError):task_source.read_source(root,'alias')
            with self.assertRaises(ValueError):task_source.read_source(root,'../source')

class TaskHistoryReadControls(unittest.TestCase):
    php=TaskWireParentControls.php
    def test_exact_owned_history_retention_and_current_visibility_are_separate(self):
        value=self.php(r'''
require $argv[1];
class PhorgeGovernanceNativeRuntimeProfile {static function assertCurrent(){}}
class PhabricatorUser {function getPHID(){return 'recipient';}}
class TaskObject {function getPHID(){return 'task';}}
class OwnedFixture {function assertOwned(){}}
function head($v){return reset($v);}
class Notification {function getChronologicalKey(){return '123';}}
class PhabricatorFeedStoryNotification {function loadAllWhere($query,...$args){if($query!=='primaryObjectPHID=%s AND userPHID=%s'||$args!==array('task','recipient'))throw new Exception('scope');return array(new Notification());}}
class PhabricatorFeedStoryData {function establishConnection($mode){if($mode!=='r')throw new Exception('write');return null;}}
function queryfx_one($connection,$query,$dao,$key){if($query!=='SELECT * FROM %R WHERE chronologicalKey=%s'||$key!=='123')throw new Exception('scope');return array('chronologicalKey'=>'123','storyType'=>'PhabricatorApplicationTransactionFeedStory','storyData'=>'{"objectPHID":"task"}');}
class Story {function getPrimaryObjectPHID(){return $GLOBALS['object'];}function getChronologicalKey(){return '123';}}
class PhabricatorFeedStory {static function loadAllFromRows($rows,$viewer){if(array_keys($rows)!==array(123))throw new Exception('key');return $GLOBALS['visible']?array(new Story()):array();}}
class PhabricatorNotificationQuery {function setViewer($u){return $this;}function withUserPHIDs($p){if($p!==array('recipient'))throw new Exception('recipient');return $this;}function withKeys($k){if($k!==array('123'))throw new Exception('keys');return $this;}function execute(){return $GLOBALS['visible']?array(new Story()):array();}}
$e=array('mode'=>GovernanceTaskWireMode::QUEUED,'recipient_phid'=>'recipient','task_phid'=>'task','task'=>new TaskObject());$f=new OwnedFixture();$u=new PhabricatorUser();$GLOBALS['object']='task';$GLOBALS['visible']=true;
$a=governance_task_wire_history($f,$e,$u);$GLOBALS['visible']=false;$b=governance_task_wire_history($f,$e,$u);
$GLOBALS['object']='foreign';$GLOBALS['visible']=true;$refused=false;try{governance_task_wire_history($f,$e,$u);}catch(RuntimeException $x){$refused=true;}
echo json_encode(array('visible'=>$a,'revoked'=>$b,'foreign_refused'=>$refused));
''',ROOT/'support/governance/notification/task-wire-producer.php')
        self.assertEqual({'retained':True,'current_visible':True,'key':'123'},value['visible'])
        self.assertEqual({'retained':True,'current_visible':False,'key':'123'},value['revoked'])
        self.assertTrue(value['foreign_refused'])

class TaskPureRootCoverage(unittest.TestCase):
    def test_actual_native_class_inventory_is_in_typed_Task_source_domain(self):
        from task_source import pure_source_files,read_source
        names=pure_source_files(ROOT)
        self.assertTrue(names)
        from pure_inventory import inventory
        ids=inventory(ROOT)
        self.assertEqual({x.split('::')[0]for x in ids},{Path(n).stem for n in names})
        for name in names:self.assertTrue(read_source(ROOT,name))

class TaskSourceAdmissionControls(unittest.TestCase):
    def test_declared_Task_domain_includes_every_default_transport_and_gate_source(self):
        import task_source
        from runtime import SOURCE_FILES
        actual=task_source.declared_files(ROOT,run_task.TASK_FILES,run_task.BROWSER_FILES)
        self.assertEqual(actual,run_task.TASK_FILES|run_task.BROWSER_FILES|SOURCE_FILES|task_source.GATE_FILES|task_source.pure_source_files(ROOT))
        self.assertTrue(SOURCE_FILES<=actual)
        self.assertTrue(task_source.GATE_FILES<=actual)
        for name in actual:self.assertTrue(task_source.read_source(ROOT,name))
    def test_unknown_current_revision_refuses_before_issuing_operation(self):
        import task_source
        revision=subprocess.check_output(['/usr/bin/git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
        with self.assertRaisesRegex(ValueError,'task_current_source_admission_refused'):
            with task_source.admitted_source({'source_root':str(ROOT),'source_revision':'0'*40}):
                self.fail('unknown source revision must not issue a runtime operation')
    def test_fake_operation_and_foreign_code_root_cannot_enter_owned_runner(self):
        import task_source
        with tempfile.TemporaryDirectory()as td:
            with self.assertRaises(ValueError):task_source.operation_module({'source_root':str(Path(td).resolve())})
        with self.assertRaises(ValueError):
            run_task._run_task_with_source_operation({'source_root':str(ROOT)},None,'allow',_source_operation=object())
    def test_native_pure_receipt_ids_not_only_count_or_passed_flag(self):
        from pure_inventory import inventory,validate_pure
        ids=inventory(ROOT);rows=[{'id':i,'result':'pass'}for i in sorted(ids)]
        receipt={'passed':True,'runtime':'8.5.12','expected_methods':len(ids),'results':rows}
        self.assertTrue(validate_pure(receipt,ids,len(ids)))
        for altered in (rows[:-1],rows[:-1]+[rows[0]],rows[:-1]+[{'id':'foreign::test','result':'pass'}]):
            with self.subTest(length=len(altered)):self.assertFalse(validate_pure(receipt|{'results':altered},ids,len(ids)))
    def test_outer_parent_returns_only_after_actual_source_operation_exit(self):
        import ast
        tree=ast.parse((HARNESS/'run_task.py').read_bytes())
        wrapper=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='run_task')
        contexts=[n for n in ast.walk(wrapper)if isinstance(n,ast.With)]
        self.assertEqual(len(contexts),1)
        self.assertEqual(ast.unparse(contexts[0].items[0].context_expr),'admitted_source(config)')
        self.assertFalse(any(isinstance(n,ast.Return)for n in ast.walk(contexts[0])))
        self.assertIsInstance(wrapper.body[-1],ast.Return)
        source=ast.parse((HARNESS/'task_source.py').read_bytes())
        gate=next(n for n in source.body if isinstance(n,ast.FunctionDef)and n.name=='admitted_source')
        self.assertEqual(ast.unparse(gate.body[1]),'_native_and_source_cli_gates(config)')
        self.assertEqual(ast.unparse(gate.body[-1]),'_native_and_source_cli_gates(config)')
        scopes=[n for n in ast.walk(gate)if isinstance(n,ast.With)]
        self.assertEqual([ast.unparse(n.items[0].context_expr)for n in scopes],["module.open_source_operation(Path(config['source_root']))",'_protected_source_witness(config, module, operation)'])
        self.assertEqual(sum(isinstance(n,ast.Yield)for n in ast.walk(scopes[1])),1)
        witness=next(n for n in source.body if isinstance(n,ast.FunctionDef)and n.name=='_protected_source_witness')
        guarded=next(n for n in witness.body if isinstance(n,ast.Try))
        self.assertEqual(sum(isinstance(n,ast.Yield)for n in ast.walk(guarded)),1)
        final=ast.unparse(ast.Module(body=guarded.finalbody,type_ignores=[]))
        self.assertIn('prepared_context(config, operation)',final)
        self.assertIn('_protected_capture(root, revision, snapshot, freshness, expected, extras) == captured',final)
        self.assertIn('ledger.verify_exit(operation)',final)
        self.assertTrue(final.endswith('module.require_operation(operation, root)'))
        native=next(n for n in source.body if isinstance(n,ast.FunctionDef)and n.name=='_native_and_source_cli_gates')
        probes=[n for n in ast.walk(native)if isinstance(n,ast.Call)and ast.unparse(n.func)=='subprocess.run']
        self.assertEqual(len(probes),2)
        self.assertNotIn('timeout',{k.arg for k in probes[0].keywords})
        self.assertEqual([ast.literal_eval(k.value)for k in probes[1].keywords if k.arg=='timeout'],[10])

class TaskDeferredExitControls(unittest.TestCase):
    def test_unit_collaborator_exit_refusal_cannot_publish_pass_or_remove_evidence(self):
        """Sequencing collaborator only; no issued SourceOperation or runtime."""
        from contextlib import contextmanager
        from unittest.mock import patch
        for refuse in (False,True):
            events=[]
            @contextmanager
            def gate(config):
                events.append('entry');yield object();events.append('exit')
                if refuse:raise ValueError('unit-source-exit-refusal')
            class Owned:
                def remove_closed_directory(self):
                    events.append('remove');return {'live_children':0,'private_directory_removed':True}
            def inner(*args,**kwargs):
                events.append('inner');return {'passed':False,'actual_queue_worker_proved':False,'SMTP_case_proved':False,'source_exit_proved':False,'cleanup':{'live_children':0,'private_directory_removed':False}},Owned(),True
            with patch.object(run_task,'validate_task_source'),patch.object(run_task,'validate_worker'),patch.object(run_task,'validate_manifest'),patch.object(run_task,'declared_files',return_value=frozenset()),patch.object(run_task,'admitted_source',gate),patch.object(run_task,'_run_task_with_source_operation',inner):
                result=run_task.run_task({'source_root':str(ROOT)},None,'allow')
            self.assertEqual(result['passed'],not refuse)
            self.assertEqual(result['source_exit_proved'],not refuse)
            self.assertEqual(events,['entry','inner','exit']+([]if refuse else['remove']))
            if refuse:self.assertFalse(result['cleanup']['private_directory_removed'])
    def test_actual_owned_FS_stop_and_later_finalization_are_two_distinct_phases(self):
        """Owned-FS component only; no server, binary, DB or native acceptance."""
        from runtime import OwnedRuntime
        for corrupt in (False,True):
            with tempfile.TemporaryDirectory()as td:
                root=Path(td).resolve()/'owned';root.mkdir(mode=0o700)
                marker=root/'owner';marker.write_text('a'*64);marker.chmod(0o600)
                runtime=object.__new__(OwnedRuntime);runtime.directory=root;runtime.identity=root.stat();runtime.owner='a'*64;runtime.children=[];runtime.closed=False
                stopped=runtime.close(remove=False)
                self.assertEqual(stopped['live_children'],0);self.assertFalse(stopped['private_directory_removed']);self.assertTrue(marker.exists())
                if corrupt:
                    marker.write_text('b'*64)
                    with self.assertRaises(ValueError):runtime.remove_closed_directory()
                    self.assertTrue(marker.exists())
                else:
                    self.assertEqual(runtime.remove_closed_directory(),{'live_children':0,'private_directory_removed':True})
                    self.assertFalse(root.exists())


class TaskSourceHeldFDControls(unittest.TestCase):
    """Real subprocess FIFO swap schedules are bounded by a parent timeout."""
    def fifo_swap(self,manifest):
        program=r'''
import os,sys,tempfile
from pathlib import Path
sys.path.insert(0,sys.argv[1]);import task_source
with tempfile.TemporaryDirectory()as temporary:
 root=Path(temporary).resolve();path=root/'owned.json';path.write_bytes(b'{}');path.chmod(0o600 if sys.argv[2]=='manifest'else 0o644)
 original=os.open;fired=[];opened=[]
 def scheduled(name,flags,*args,**kwargs):
  if Path(name)==path and not fired:
   fired.append(True);path.unlink();os.mkfifo(path,0o600)
  fd=original(name,flags,*args,**kwargs);opened.append(fd);return fd
 task_source.os.open=scheduled
 try:
  if sys.argv[2]=='manifest':task_source.load_manifest(path)
  else:task_source.read_source(root,'owned.json')
 except (ValueError,OSError):
  assert fired
  for fd in opened:
   try:os.fstat(fd)
   except OSError:pass
   else:raise AssertionError('owned fd leaked')
  print('refused')
 else:raise AssertionError('FIFO accepted')
'''
        result=subprocess.run([sys.executable,'-B','-c',program,str(HARNESS),'manifest'if manifest else'source'],capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stdout,'refused\n');self.assertEqual(result.stderr,'')
    def test_actual_source_regular_to_fifo_before_open_refuses_without_block(self):
        self.fifo_swap(False)
    def test_actual_private_manifest_regular_to_fifo_before_open_refuses_without_block(self):
        self.fifo_swap(True)
    def parent_change(self,manifest):
        import task_source
        from unittest.mock import patch
        with tempfile.TemporaryDirectory()as temporary:
            root=Path(temporary).resolve();path=root/'owned.json';path.write_bytes(b'{}');path.chmod(0o600 if manifest else 0o644)
            original=os.read;changed=[]
            def scheduled(fd,count):
                raw=original(fd,count)
                if not changed:changed.append(True);root.chmod(0o750)
                return raw
            try:
                with patch.object(task_source.os,'read',scheduled),self.assertRaises(ValueError):
                    if manifest:task_source.load_manifest(path)
                    else:task_source.read_source(root,'owned.json')
                self.assertTrue(changed)
            finally:root.chmod(0o700)
    def test_actual_source_parent_identity_mode_rechecked(self):
        self.parent_change(False)
    def test_actual_manifest_parent_identity_mode_rechecked(self):
        self.parent_change(True)



class TaskProtectedWitnessFilesystemControls(unittest.TestCase):
    """Actual private filesystem observations; no operation capability is faked."""
    def test_protected_leaf_exact_content_mode_and_restore_identity(self):
        import task_source
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary).resolve();path=root/'source.php'
            path.write_bytes(b'private synthetic source\n');path.chmod(0o644)
            expected=('file',path.read_bytes(),0o644)
            initial=task_source._protected_leaf(root,'source.php',expected)
            self.assertEqual(initial,task_source._protected_leaf(root,'source.php',expected))
            path.write_bytes(b'private synthetic drift!\n')
            with self.assertRaises(ValueError):task_source._protected_leaf(root,'source.php',expected)
            path.write_bytes(expected[1]);path.chmod(0o600)
            with self.assertRaises(ValueError):task_source._protected_leaf(root,'source.php',expected)
            path.chmod(0o644)
            restored=task_source._protected_leaf(root,'source.php',expected)
            self.assertNotEqual(initial,restored)
            self.assertEqual(initial[1],restored[1])
    def test_protected_leaf_symlink_target_and_hardlink_refused(self):
        import task_source
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary).resolve();target=root/'target';target.write_bytes(b'synthetic');target.chmod(0o644)
            link=root/'link';link.symlink_to('target');link_mode=link.lstat().st_mode&0o777
            task_source._protected_leaf(root,'link',('symlink','target',link_mode))
            link.unlink();link.symlink_to('different')
            with self.assertRaises(ValueError):task_source._protected_leaf(root,'link',('symlink','target',link_mode))
            os.link(target,root/'hardlink')
            with self.assertRaises(ValueError):task_source._protected_leaf(root,'target',('file',b'synthetic',0o644))
    def test_protected_leaf_fifo_refuses_without_open(self):
        import task_source
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary).resolve();os.mkfifo(root/'pipe',0o600)
            with self.assertRaises(ValueError):task_source._protected_leaf(root,'pipe',('file',b'not FIFO',0o644))
    def test_protected_leaf_rechecks_parent_after_actual_read(self):
        import task_source
        from unittest.mock import patch
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary).resolve();path=root/'source';path.write_bytes(b'synthetic');path.chmod(0o644)
            original=os.pread;fired=[]
            def scheduled(fd,size,offset):
                raw=original(fd,size,offset)
                if not fired:fired.append(True);root.chmod(0o750)
                return raw
            try:
                with patch.object(task_source.os,'pread',scheduled),self.assertRaises(ValueError):
                    task_source._protected_leaf(root,'source',('file',b'synthetic',0o644))
                self.assertTrue(fired)
            finally:root.chmod(0o700)
    def test_protected_leaf_rechecks_path_replacement_after_actual_read(self):
        import task_source
        from unittest.mock import patch
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary).resolve();path=root/'source';path.write_bytes(b'synthetic');path.chmod(0o644)
            original=os.pread;fired=[]
            def scheduled(fd,size,offset):
                raw=original(fd,size,offset)
                if not fired:
                    fired.append(True);replacement=root/'replacement';replacement.write_bytes(b'synthetic');replacement.chmod(0o644);replacement.replace(path)
                return raw
            with patch.object(task_source.os,'pread',scheduled),self.assertRaises(ValueError):
                task_source._protected_leaf(root,'source',('file',b'synthetic',0o644))
            self.assertTrue(fired)
    def test_protected_capture_real_git_domain_and_eight_overlays(self):
        """A component-only extra is not a prepared-operation authorization."""
        import task_source
        import shutil
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary).resolve();native='src/synthetic.php'
            path=root/native;path.parent.mkdir();path.write_bytes(b'synthetic protected source\n');path.chmod(0o644)
            original_snapshot=ROOT/'support/governance/media-session-docker/snapshot.py'
            import ast
            overlay=next(ast.literal_eval(n.value) for n in ast.parse(original_snapshot.read_bytes()).body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='OVERLAY' for t in n.targets))
            module_names=('support/governance/media-session-docker/snapshot.py','support/governance/media-session-ci/freshness.py')
            for name in set(overlay)|set(module_names):
                target=root/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/name,target);target.chmod(0o644)
            task_source.git(root,'init','--quiet');task_source.git(root,'add','.')
            task_source.git(root,'-c','user.name=Component','-c','user.email=component@example.invalid','-c','commit.gpgsign=false','commit','--quiet','-m','Owned protected source component')
            revision=task_source.git(root,'rev-parse','HEAD').decode().strip()
            import types,builtins
            snapshot=types.ModuleType('component_snapshot');snapshot.__file__=str(root/module_names[0])
            exec(compile((root/module_names[0]).read_bytes(),snapshot.__file__,'exec'),snapshot.__dict__)
            freshness=types.ModuleType('component_freshness');freshness.__file__=str(root/module_names[1])
            original_import=builtins.__import__
            def private_import(name,globals=None,locals=None,fromlist=(),level=0):
                if name=='snapshot' and level==0:return snapshot
                return original_import(name,globals,locals,fromlist,level)
            freshness.__dict__['__builtins__']=dict(vars(builtins),__import__=private_import)
            old_path=list(sys.path)
            try:exec(compile((root/module_names[1]).read_bytes(),freshness.__file__,'exec'),freshness.__dict__)
            finally:sys.path[:]=old_path
            self.assertIs(freshness.snapshot,snapshot)
            expected={name:row for name,row in freshness.head_entries(root,revision).items() if freshness.protected(name)}
            extra='support/runtime/component-only-extra';extra_path=root/extra;extra_path.parent.mkdir(parents=True);extra_path.write_bytes(b'component-only extra\n');extra_path.chmod(0o644)
            extras=frozenset({extra})
            initial=task_source._protected_capture(root,revision,snapshot,freshness,expected,extras)
            self.assertEqual(initial,task_source._protected_capture(root,revision,snapshot,freshness,expected,extras))
            unknown=root/'src/unreviewed.php';unknown.write_bytes(b'unknown');unknown.chmod(0o644)
            try:
                with self.assertRaises(ValueError):task_source._protected_capture(root,revision,snapshot,freshness,expected,extras)
            finally:unknown.unlink()
            original=path.read_bytes();path.write_bytes(b'changed protected source\n')
            try:
                with self.assertRaises(ValueError):task_source._protected_capture(root,revision,snapshot,freshness,expected,extras)
            finally:path.write_bytes(original)
            name=next(iter(overlay));overlay_path=root/name;original_overlay=overlay_path.read_bytes();overlay_path.write_bytes(original_overlay+b'unknown')
            try:
                with self.assertRaises(ValueError):task_source._protected_capture(root,revision,snapshot,freshness,expected,extras)
            finally:overlay_path.write_bytes(original_overlay)
            path.unlink()
            with self.assertRaises(ValueError):task_source._protected_capture(root,revision,snapshot,freshness,expected,extras)
