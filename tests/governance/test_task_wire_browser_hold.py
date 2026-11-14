"""Pure/owned-FS lifecycle controls. No SQL, browser, worker or SMTP proof."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tests/governance/private_mail_ci'))
import task_browser as browser

class TaskBrowserHold(unittest.TestCase):
    def setUp(self):
        base=Path('/tmp').resolve();self.directory=base/('phorge-private-mail-wire-'+os.urandom(12).hex())
        self.directory.mkdir(mode=0o700);self.addCleanup(lambda:shutil.rmtree(self.directory,ignore_errors=True))
        self.owner='a'*64;(self.directory/'owner').write_bytes(self.owner.encode());(self.directory/'owner').chmod(0o600)
        self.output_dir=Path(tempfile.mkdtemp(dir=base));self.output_dir.chmod(0o700);self.addCleanup(lambda:shutil.rmtree(self.output_dir,ignore_errors=True))
        self.output=self.output_dir/'browser-control.json';self.original_identity=self.directory.stat()
        self.runtime=SimpleNamespace(directory=self.directory,owner=self.owner,assert_owned=self.assert_owned)
        self.ready={'task_phid':'PHID-TASK-'+'b'*20,'task_path':'/T1','publish_task_id':1,'callback_origin':'http://127.0.0.1:12345'}
        self.native={**self.ready,'notification_key':'123','notification_path':'/notification/individual/?key=123','owner':self.owner,'scope':'owned-native-task-browser','browser_proved_by_preparation':False,'source_revision':'c'*40,'origin':self.ready['callback_origin'],
                     'fixture_writer_close_proof':{'owned_fixture_writer_connection_closed':True,'exact_original_thread_absent':True}}
        self.identity={'pid':123,'uid':os.getuid(),'start':456,'group':123,'session':123}
        self.patcher=patch.object(browser,'process_identity',return_value=self.identity);self.patcher.start();self.addCleanup(self.patcher.stop)
    def assert_owned(self):
        info=self.directory.stat()
        if (info.st_dev,info.st_ino,info.st_mode)!=(self.original_identity.st_dev,self.original_identity.st_ino,self.original_identity.st_mode) or (self.directory/'owner').read_bytes()!=self.owner.encode():
            raise ValueError('unadmitted_owned_FS_collaborator_refused')
    def hold(self,seconds=120):return browser.BrowserHold(self.runtime,self.ready,self.native,'c'*40,self.output,seconds)
    def command(self,hold,**changes):
        value={'scope':'owned-native-task-browser-finalize-v1','owner':self.owner,'source_revision':'c'*40,'hold_id':hold.hold_id,'resource_identity_sha256':hold.resource_sha256,'sequence':1,'action':'finalize',**changes}
        browser.write_private(self.directory/'task-browser-finalize.json',value)
    def test_normal_finalize_is_owner_bound_and_no_browser_pass(self):
        hold=self.hold();result=browser.request_finalize(self.output,self.directory/'owner','c'*40)
        self.assertEqual(result['state'],'finalize_requested');self.assertFalse(result['browser_proved'])
        self.assertEqual(hold.poll(),'finalize-requested')
        value=json.loads(browser.read_private(self.output));self.assertFalse(value['cleanup_proved'])
        self.assertFalse(value['browser_proved']);self.assertNotIn('password',value);self.assertNotIn('owner',value)
        self.assertNotIn(self.owner,self.output.read_text());self.assertEqual(stat.S_IMODE(self.output.stat().st_mode),0o600)
    def test_wrong_command_owner_fails_closed(self):
        hold=self.hold();self.command(hold,owner='d'*64)
        with self.assertRaises(ValueError):hold.poll()
        self.assertEqual(hold.state,'failed')
        with self.assertRaises(ValueError):hold.poll()
    def test_wrong_source_or_task_hold_id_refused(self):
        for field in ('source_revision','hold_id','resource_identity_sha256','action','sequence'):
            with self.subTest(field=field):
                self.output.unlink(missing_ok=True);(self.directory/'task-browser-finalize.json').unlink(missing_ok=True)
                hold=self.hold();self.command(hold,**{field:'wrong'})
                with self.assertRaises(ValueError):hold.poll()
    def test_unknown_fields_and_duplicate_JSON_refused(self):
        hold=self.hold();self.command(hold,unknown=True)
        with self.assertRaises(ValueError):hold.poll()
        (self.directory/'task-browser-finalize.json').unlink();self.output.unlink();hold=self.hold()
        path=self.directory/'task-browser-finalize.json';path.write_bytes(b'{"owner":"x","owner":"y"}');path.chmod(0o600)
        with self.assertRaises(ValueError):hold.poll()
    def test_timeout_is_failure_state_not_cleanup_proof(self):
        hold=self.hold();hold.deadline=0
        self.assertEqual(hold.poll(),'timed-out')
        self.assertFalse(json.loads(browser.read_private(self.output))['cleanup_proved'])
        with self.assertRaises(ValueError):hold.poll()
    def test_duplicate_finalize_no_overwrite(self):
        hold=self.hold();browser.request_finalize(self.output,self.directory/'owner','c'*40);before=(self.directory/'task-browser-finalize.json').read_bytes()
        with self.assertRaises(FileExistsError):browser.request_finalize(self.output,self.directory/'owner','c'*40)
        self.assertEqual((self.directory/'task-browser-finalize.json').read_bytes(),before)
        hold.poll()
        with self.assertRaises(ValueError):hold.poll()
    def test_parent_crash_requires_recovery_without_release_or_retry(self):
        self.hold()
        with patch.object(browser,'process_identity',side_effect=ValueError('parent gone')):
            result=browser.request_finalize(self.output,self.directory/'owner','c'*40)
        self.assertEqual(result,{'state':'recovery_required','cleanup_authorized':False,'restart_authorized':False})
        self.assertFalse((self.directory/'release').exists());self.assertFalse((self.directory/'task-browser-finalize.json').exists())
    def test_recycled_parent_identity_cannot_finalize(self):
        self.hold()
        with patch.object(browser,'process_identity',return_value={**self.identity,'start':457}):
            self.assertEqual(browser.request_finalize(self.output,self.directory/'owner','c'*40)['state'],'recovery_required')
    def test_owner_file_path_or_bytes_cannot_be_substituted(self):
        self.hold()
        with self.assertRaises(ValueError):browser.request_finalize(self.output,self.output,'c'*40)
        (self.directory/'owner').write_bytes(b'd'*64)
        with self.assertRaises(ValueError):browser.request_finalize(self.output,self.directory/'owner','c'*40)
        self.assertFalse((self.directory/'task-browser-finalize.json').exists())
    def test_private_reader_symlink_hardlink_and_shared_mode_refused(self):
        self.hold();alias=self.output_dir/'alias';alias.symlink_to(self.output)
        with self.assertRaises(OSError):browser.read_private(alias)
        alias.unlink();os.link(self.output,alias)
        with self.assertRaises(ValueError):browser.read_private(self.output)
        alias.unlink();self.output.chmod(0o644)
        with self.assertRaises(ValueError):browser.read_private(self.output)
    def test_bad_lease_or_foreign_Task_fails_before_ready_file(self):
        for seconds in (0,119,601,True):
            with self.assertRaises(ValueError):self.hold(seconds)
            self.assertFalse(self.output.exists())
        self.native['task_phid']='PHID-TASK-'+'e'*20
        with self.assertRaises(ValueError):self.hold()
        self.assertFalse(self.output.exists())
    def test_keeper_or_server_exit_is_not_success(self):
        hold=self.hold()
        with self.assertRaises(ValueError):hold.wait(SimpleNamespace(poll=lambda:0),SimpleNamespace(poll=lambda:None))
        self.assertEqual(hold.state,'failed')
    def test_actual_native_route_helpers_are_exact_task_and_auth_only(self):
        php=shutil.which('php');self.assertIsNotNone(php)
        path=ROOT/'support/governance/notification/task-wire-browser.php'
        script='''require $argv[1];$b=array('task_phid'=>'PHID-TASK-'.str_repeat('b',20),'task_path'=>'/T1','login_path'=>'/auth/login/password:self/');$o='http://127.0.0.1:12345';$h='127.0.0.1:12345';$yes=array('/T1','/object/PHID-TASK-'.str_repeat('b',20).'/','/auth/start/','/auth/login/password:self/','/logout/','/res/public.css');foreach($yes as$p){if(governance_task_browser_route($h,$p,$o,'GET',$b)!==$p){exit(3);}}$no=array('/T2','/object/PHID-TASK-'.str_repeat('c',20).'/','/api/user.search','/file/data/private','//evil','/res/%2e%2e/x');foreach($no as$p){try{governance_task_browser_route($h,$p,$o,'GET',$b);exit(4);}catch(RuntimeException$e){}}try{governance_task_browser_route($h,'/T1',$o,'POST',$b);exit(5);}catch(RuntimeException$e){}echo 'bounded-route-controls-pass';'''
        result=subprocess.run([php,'-r',script,str(path)],capture_output=True,text=True,timeout=10)
        self.assertEqual((result.returncode,result.stdout),(0,'bounded-route-controls-pass'),result.stderr)
    def test_no_retained_history_admits_no_notification_route(self):
        self.native['notification_key']=None;self.native['notification_path']=None
        hold=self.hold();self.assertIsNone(hold.receipt['notification_path'])
    def test_native_notification_route_accepts_only_owned_single_key(self):
        php=shutil.which('php');self.assertIsNotNone(php)
        code="""require $argv[1];$b=array('task_phid'=>'PHID-TASK-'.str_repeat('b',20),'task_path'=>'/T1','login_path'=>'/auth/login/password:self/','notification_key'=>'123','notification_path'=>'/notification/individual/?key=123');$o='http://127.0.0.1:12345';$h='127.0.0.1:12345';if(governance_task_browser_route($h,$b['notification_path'],$o,'GET',$b)!=='/notification/individual/'){exit(2);}$no=array('/notification/','/notification/individual/','/notification/individual/?key=124','/notification/individual/?key=123&key=124','/notification/individual/?key=123&user=foreign','/notification/individual/?key=%31%32%33');foreach($no as$p){try{governance_task_browser_route($h,$p,$o,'GET',$b);exit(3);}catch(RuntimeException$e){}}echo 'single-owned-key-pass';"""
        p=subprocess.run([php,'-r',code,str(ROOT/'support/governance/notification/task-wire-browser.php')],capture_output=True,text=True,timeout=10)
        self.assertEqual((p.returncode,p.stdout),(0,'single-owned-key-pass'),p.stderr)
    def test_actual_parent_finally_orders_cleanup_and_blocks_unknown(self):
        import ast
        import run_task
        tree=ast.parse((ROOT/'tests/governance/private_mail_ci/run_task.py').read_bytes())
        function=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=='_run_task_with_source_operation')
        final=next(n.finalbody for n in function.body if isinstance(n,ast.Try) and n.finalbody)
        code=compile(ast.Module(body=final,type_ignores=[]),'<actual-parent-finally>','exec')
        for fault in ('none','stop','SQL0','timeout','early','default'):
            with self.subTest(fault=fault):
                directory=self.output_dir/fault;directory.mkdir(mode=0o700);events=[]
                class Child:
                    pid=123
                    def __init__(inner,name):inner.name=name;inner.returncode=None
                    def poll(inner):return inner.returncode
                    def wait(inner,timeout):
                        self.assertIn('release',events);inner.returncode=0
                fixture=Child('fixture');server=Child('server');mailer=Child('mailer')
                class Runtime:
                    children=[fixture,server,mailer];owner='a'*64
                    def _stop_group(inner,child):
                        events.append('stop-'+child.name)
                        if fault=='stop' and child is server:raise RuntimeError('unadmitted stop fault')
                        child.returncode=0
                    def _members(inner,pid):return []
                    def write(inner,name,raw):
                        if name=='release':self.assertEqual(raw,inner.owner.encode())
                        events.append(name)
                    def write_flag(inner,name):
                        self.assertEqual(name,'release')
                        inner.write(name,inner.owner.encode())
                    def close(inner,remove):events.append('close-'+str(remove));return {'live_children':0,'private_directory_removed':remove}
                def cleanup(runtime,config):
                    self.assertIn('stop-server',events);self.assertIn('stop-mailer',events);events.append('queue-and-SQL0')
                    if fault=='SQL0':raise RuntimeError('unadmitted SQL observer fault')
                    return True
                native={'passed':True,'cleanup_failures':[],'cleanup':{'ownedSchemasRemoved':55,'ownedRuntimeUsersRemoved':4,'readerRemoved':True},
                    'native':{'owned_service_cleanup':{key:{'process_stopped':True,'private_storage_removed':True} for key in ('queue','capture')}},
                    'file_service_cleanup':{'process_stopped':True,'private_storage_removed':True}}
                log=directory/'fixture.log';log.write_text(json.dumps(native));log.chmod(0o600)
                namespace={'json':json,'runtime':Runtime(),'fixture':fixture,'directory':directory,'config':{},'passed':fault not in ('timeout','early'),
                    'native':None,'native_clean':False,'prepared':True,'cleanup':None,'browser_attempted':fault not in ('default','early'),'browser_hold':fault!='default',
                    'consumer_stop_proved':True,'browser_cleanup_admitted':False,'browser_cleanup_admission':cleanup,'private_file':run_task.private_file}
                exec(code,namespace)
                if fault in ('none','timeout','early','default'):
                    self.assertIn('release',events);self.assertTrue(namespace['native_clean'])
                else:
                    self.assertNotIn('release',events);self.assertFalse(namespace['native_clean'])
                    self.assertFalse(namespace['cleanup']['private_directory_removed'])
                if fault in ('stop','default'):self.assertNotIn('queue-and-SQL0',events)
                if fault in ('timeout','early'):self.assertFalse(namespace['passed'])
    def test_actual_native_browser_transaction_guard_covers_each_writer(self):
        php=shutil.which('php');self.assertIsNotNone(php)
        classes=('ManiphestTask','PhabricatorFile','PhabricatorWorkerActiveTask','PhabricatorMetaMTAMail',
                 'PhabricatorUser','PhabricatorUserEmail','PhabricatorAuthPassword','PhabricatorAuthProviderConfig')
        code='class UnitConnection {function __construct(public $name){} function isInsideTransaction(){return $GLOBALS["bad"]===$this->name;}} class UnitDAO {function establishConnection($role){return new UnitConnection(get_class($this));}}'
        code+=''.join('class '+name+' extends UnitDAO {}' for name in classes)
        code+='require $argv[1];require $argv[2];$GLOBALS["bad"]=null;governance_task_browser_no_transactions();'
        code+='foreach('+str(list(classes)).replace('[','array(').replace(']',')')+' as $name){$GLOBALS["bad"]=$name;try{governance_task_browser_no_transactions();exit(3);}catch(RuntimeException $e){}}echo "all8-writer-guards-refused";'
        result=subprocess.run([php,'-r',code,str(ROOT/'support/governance/notification/task-wire-producer.php'),str(ROOT/'support/governance/notification/task-wire-browser.php')],capture_output=True,text=True,timeout=10)
        self.assertEqual((result.returncode,result.stdout),(0,'all8-writer-guards-refused'),result.stderr)
    def test_finalize_wrong_package_source_and_bad_writer_proof_refused(self):
        self.hold()
        with self.assertRaises(ValueError):browser.request_finalize(self.output,self.directory/'owner','d'*40)
        self.assertFalse((self.directory/'task-browser-finalize.json').exists())
        self.output.unlink();self.native['fixture_writer_close_proof']['exact_original_thread_absent']=False
        with self.assertRaises(ValueError):self.hold()
        self.assertFalse(self.output.exists())
    def test_actual_private_native_manifest_binds_same_ready_task_and_source(self):
        php=shutil.which('php');self.assertIsNotNone(php)
        ready={**self.ready,'owner':self.owner}
        native={**self.native,'browser_cookie_injected':False}
        browser.write_private(self.directory/'ready.json',ready)
        browser.write_private(self.directory/'task-browser-native.json',native)
        script='''require $argv[1];$d=$argv[2];$o=str_repeat('a',64);$base=array('owner'=>$o,'callback_origin'=>'http://127.0.0.1:12345');putenv('PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION='.str_repeat('c',40));governance_task_browser_manifest($d,$base);$p=$d.'/task-browser-native.json';$v=json_decode(file_get_contents($p),true);foreach(array('task_phid','task_path','publish_task_id','source_revision')as$key){$bad=$v;$bad[$key]=$key==='publish_task_id'?2:'wrong';file_put_contents($p,json_encode($bad));try{governance_task_browser_manifest($d,$base);exit(4);}catch(RuntimeException$e){}}try{governance_task_browser_read($d,$o,'owner');exit(5);}catch(RuntimeException$e){}echo 'manifest-lineage-controls-pass';'''
        result=subprocess.run([php,'-r',script,str(ROOT/'support/governance/notification/task-wire-browser.php'),str(self.directory)],env={**os.environ,'TMPDIR':str(self.directory.parent)},capture_output=True,text=True,timeout=10)
        self.assertEqual((result.returncode,result.stdout),(0,'manifest-lineage-controls-pass'),result.stderr)
