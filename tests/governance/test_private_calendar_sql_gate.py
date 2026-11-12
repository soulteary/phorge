"""Synthetic receipt mutation controls; never actual SQL or SMTP evidence."""
import copy
import hashlib
import tempfile
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / 'scripts/governance'
sys.path.insert(0, str(SCRIPTS))
import check_private_calendar_reminders as gate

spec = importlib.util.spec_from_file_location('calendar_native_template', ROOT / 'tests/governance/test_native_organization.py')
native_template = importlib.util.module_from_spec(spec)
spec.loader.exec_module(native_template)
REVISION = 'a' * 40
FILE = 'b' * 64
QUEUE = 'c' * 64

def template():
    data = native_template.template()
    data.update(batch='native-private-calendar-reminders', scope='owned-native-calendar-reminders-no-SMTP',
        native=gate.summary(REVISION, QUEUE), checks=sorted(gate.obligations()), check_count=len(gate.obligations()),
        worker_cleanup={'private_directories_removed': 0, 'owned_processes_stopped': 0, 'cleanup_failures': 0,
            'private_directories_remaining': 0, 'live_processes_after_cleanup': 0, 'workers_registered': 0},
        file_service_cleanup={'kind': 'file', 'binary_sha256': FILE, 'process_stopped': True, 'private_storage_removed': True},
        cleanup={'ownedSchemasRemoved': 55, 'ownedRuntimeUsersRemoved': 2, 'readerRemoved': True})
    return data

class CalendarSQLGateTests(unittest.TestCase):
    def validate(self, data, revision=REVISION, file=FILE, queue=QUEUE):
        return gate.validate(data, source_revision=revision, file_digest=file, queue_digest=queue)

    def test_actual_source_function_defines_exact_twenty_one_obligations_and_twelve_sources(self):
        result = subprocess.run(['php', '-r',
            'require $argv[1];echo json_encode([governance_calendar_reminder_obligations(),governance_calendar_reminder_source_inputs()]);',
            str(ROOT / 'support/governance/notification/calendar-reminder-cases.php')],
            capture_output=True, text=True, check=True, timeout=10)
        self.assertEqual('', result.stderr)
        cases, sources = json.loads(result.stdout)
        self.assertEqual(set(cases), gate.CASES)
        self.assertEqual(set(sources), set(gate.SOURCE_INPUTS))
        self.assertEqual(12, len(gate.sources()))

    def test_actual_scoped_configuration_disables_unrelated_media_hooks_and_restores_parent(self):
        code = r"""require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
require $argv[1].'/support/governance/notification/calendar-reminder-cases.php';
$values=array('governance.private-mail.enabled'=>false,'phabricator.show-prototypes'=>false,'governance.media-exchange.enabled'=>true,
'governance.media-exchange.http-enabled'=>true,'governance.organization.enabled'=>true,'governance.notifications.enabled'=>true,
'gorge.taskqueue.owner'=>'phorge','gorge.taskqueue.uri'=>null,'gorge.taskqueue.token'=>null,
'cluster.mailers'=>array(array('key'=>'unchanged-parent-sentinel')));
$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
$stack->pushSource(new PhabricatorConfigDictionarySource($values));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());
$read=static function()use($values){$out=array();foreach($values as$key=>$unused){$out[$key]=PhabricatorEnv::getEnvConfig($key);}return$out;};
$before=$read();$count=count($stack->getStack());
$scope=governance_calendar_reminder_scope(array('uri'=>'http://127.0.0.1:1','token'=>str_repeat('a',64)));
$during=$read();$added=count($stack->getStack())===$count+1;
unset($scope);$after=$read();
echo json_encode(array('before'=>$before,'during'=>$during,'after'=>$after,'added_one_scope'=>$added,
'restored_stack_depth'=>count($stack->getStack())===$count),JSON_THROW_ON_ERROR);"""
        result = subprocess.run(['php', '-r', code, str(ROOT)], capture_output=True, text=True, check=True, timeout=15)
        self.assertEqual('', result.stderr)
        value = json.loads(result.stdout)
        self.assertEqual(value['before'], value['after'])
        self.assertIs(value['added_one_scope'], True)
        self.assertIs(value['restored_stack_depth'], True)
        self.assertIs(value['during']['governance.private-mail.enabled'], True)
        self.assertIs(value['before']['phabricator.show-prototypes'], False)
        self.assertIs(value['during']['phabricator.show-prototypes'], True)
        self.assertIs(value['after']['phabricator.show-prototypes'], False)
        for key in ('governance.media-exchange.enabled', 'governance.media-exchange.http-enabled',
                    'governance.organization.enabled', 'governance.notifications.enabled'):
            self.assertIs(value['during'][key], False)
        self.assertEqual('gorge', value['during']['gorge.taskqueue.owner'])
        self.assertEqual('http://127.0.0.1:1', value['during']['gorge.taskqueue.uri'])
        self.assertEqual('calendar-isolated-serialization', value['during']['cluster.mailers'][0]['key'])

    def test_historical_source_inverses_reject_unrelated_changes_even_if_pin_refreshed(self):
        for name, inverse in (
                ('src/applications/calendar/notifications/PhabricatorCalendarNotificationEngine.php', gate.reduce_reviewed_calendar_engine),
                ('support/governance/native-organization/test-native-organization-mysql.php', gate.reduce_reviewed_calendar_driver)):
            raw = (ROOT / name).read_bytes()
            baseline = subprocess.check_output(['git', '-C', str(ROOT), 'show',
                '59b623978b82964581ccc769221121a03938a2ff:' + name])
            self.assertEqual(baseline, inverse(raw))
            changed = raw + b'\n// unrelated source change\n'
            with self.subTest(name=name), self.assertRaises(ValueError): inverse(changed)
            prior = gate.SOURCE_INPUTS[name]
            try:
                gate.SOURCE_INPUTS[name] = hashlib.sha256(changed).hexdigest()
                with self.assertRaises(ValueError): inverse(changed)
            finally:
                gate.SOURCE_INPUTS[name] = prior

    def test_exact_synthetic_receipt_accepts_only_narrow_scope(self):
        value = self.validate(template())
        self.assertEqual(32, value['checks'])
        self.assertEqual(21, value['calendar_obligations'])
        self.assertTrue(value['activation_blocked'])
        for key in ('physical_SMTP_or_browser_proved', 'native_global_lock_or_daemon_proved',
                    'ephemeral_ghost_delivery_supported', 'all_calendar_producer_paths_complete'):
            self.assertIs(value[key], False)

    def test_no_missing_duplicate_extra_or_boolean_checks(self):
        for action in ('missing', 'duplicate', 'extra', 'bool'):
            data = template()
            if action == 'missing': data['checks'].pop()
            if action == 'duplicate': data['checks'][0] = data['checks'][1]
            if action == 'extra': data['checks'].append('imagined-SMTP-pass')
            if action == 'bool': data['checks'][0] = True
            data['check_count'] = len(data['checks'])
            with self.subTest(action=action), self.assertRaises(ValueError): self.validate(data)

    def test_revision_and_independent_service_digests_must_match(self):
        for kwargs in ({'revision': 'd' * 40}, {'file': 'd' * 64}, {'queue': 'd' * 64},
                       {'revision': 'main'}, {'file': None}, {'queue': True}):
            with self.subTest(kwargs=kwargs), self.assertRaises(ValueError): self.validate(template(), **kwargs)

    def test_source_pins_and_profile_cannot_be_substituted(self):
        for action in ('pin', 'missing-pin', 'profile', 'obligation'):
            data = template()
            if action == 'pin': data['native']['source_inputs_sha256'][next(iter(gate.SOURCE_INPUTS))] = 'd' * 64
            if action == 'missing-pin': data['native']['source_inputs_sha256'].pop(next(iter(gate.SOURCE_INPUTS)))
            if action == 'profile': data['native']['profile'] = 'all-calendar-production'
            if action == 'obligation': data['native']['executed_case_obligations'].pop()
            with self.subTest(action=action), self.assertRaises(ValueError): self.validate(data)

    def test_host_only_proof_cannot_promote_daemon_physical_or_complete_flags(self):
        for key in ('native_global_lock_or_daemon_proved', 'physical_SMTP_or_browser_proved',
                    'ephemeral_ghost_delivery_supported', 'all_calendar_producer_paths_complete', 'activation_blocked'):
            data = template(); data['native'][key] = not data['native'][key]
            with self.subTest(key=key), self.assertRaises(ValueError): self.validate(data)

    def test_process_storage_schema_or_reader_cleanup_failure_refuses(self):
        for section, key in (('native', 'queue_cleanup'), ('file_service_cleanup', 'process_stopped'),
                             ('file_service_cleanup', 'private_storage_removed'), ('cleanup', 'readerRemoved'),
                             ('worker_cleanup', 'live_processes_after_cleanup')):
            data = template()
            if section == 'native': data[section][key]['process_stopped'] = False
            elif section == 'worker_cleanup': data[section][key] = 1
            else: data[section][key] = False
            with self.subTest(section=section, key=key), self.assertRaises(ValueError): self.validate(data)

    def test_public_cli_requires_both_independent_binary_bytes_and_strict_json(self):
        with tempfile.TemporaryDirectory() as directory:
            private = Path(directory).resolve()
            file = private / 'file'; file.touch(mode=0o700); file.write_bytes(b'synthetic-file-digest-input')
            queue = private / 'queue'; queue.touch(mode=0o700); queue.write_bytes(b'synthetic-queue-digest-input')
            file_digest = hashlib.sha256(file.read_bytes()).hexdigest()
            queue_digest = hashlib.sha256(queue.read_bytes()).hexdigest()
            data = template()
            data['file_service_cleanup']['binary_sha256'] = file_digest
            data['native']['queue_cleanup']['binary_sha256'] = queue_digest
            receipt = private / 'receipt.json'; receipt.touch(mode=0o600)
            command = [sys.executable, '-B', str(SCRIPTS / 'check_private_calendar_reminders.py'),
                '--receipt', str(receipt), '--source-revision', REVISION,
                '--file-binary', str(file), '--file-binary-sha256', file_digest,
                '--queue-binary', str(queue), '--queue-binary-sha256', queue_digest]
            receipt.write_text(json.dumps(data))
            accepted = subprocess.run(command, capture_output=True, text=True, timeout=20)
            self.assertEqual(0, accepted.returncode, accepted.stderr)
            self.assertIs(json.loads(accepted.stdout)['physical_SMTP_or_browser_proved'], False)
            queue.write_bytes(b'changed-after-expected-digest')
            denied = subprocess.run(command, capture_output=True, text=True, timeout=20)
            self.assertNotEqual(0, denied.returncode)
            self.assertEqual('', denied.stdout)
            queue.write_bytes(b'synthetic-queue-digest-input')
            receipt.write_text(json.dumps(data).replace('"passed": true', '"passed": false, "passed": true', 1))
            denied = subprocess.run(command, capture_output=True, text=True, timeout=20)
            self.assertNotEqual(0, denied.returncode)
            self.assertEqual('', denied.stdout)

    def test_runtime_or_permission_scope_change_refuses(self):
        for key, value in (('php_version', '8.5.8'), ('passed', False), ('native_permission_axes_changed', True),
                           ('production_optional_features_enabled', True), ('production_deployment', True),
                           ('terminal_failure_cleanup_rollbacks', 1), ('check_count', True)):
            data = template(); data[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError): self.validate(data)

class NativeCalendarFactoryEntry(unittest.TestCase):
    def test_fixture_factory_executes_actual_native_initializer_without_SQL(self):
        # Only Spaces request-cache and User UTC settings are collaborators.
        # Disable DB/network entry functions even if an accidental dependency
        # starts trying to connect; never use this as physical SQL evidence.
        code = r"""
require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());
$stack->pushSource(new PhabricatorConfigDictionarySource(array('phabricator.base-uri'=>'https://app.example.invalid/')));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());PhabricatorEnv::setLocaleCode('en_US');
$user=(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat('u',20));
(new ReflectionProperty(PhabricatorUser::class,'settingCache'))->setValue($user,array('timezone'=>'UTC'));
(new ReflectionProperty(PhabricatorUser::class,'settingCacheKeys'))->setValue($user,array('timezone'=>true));
// Explicit empty Spaces request-cache collaborator: no DB or permission proof.
PhabricatorCaches::getRequestCache()->setKey(PhabricatorSpacesNamespaceQuery::KEY_ALL,array());
$source=file_get_contents($argv[1].'/support/governance/notification/calendar-reminder-cases.php');
if(!preg_match('/PhabricatorCalendarEvent::([A-Za-z_][A-Za-z0-9_]*)\(\$host\)/',$source,$matches))throw new RuntimeException('factory source absent');
$method=new ReflectionMethod(PhabricatorCalendarEvent::class,$matches[1]);
$event=$method->invoke(null,$user);
$old_error=null;try{PhabricatorCalendarEvent::initializeNewEvent($user);}catch(Throwable$error){$old_error=get_class($error);}
echo json_encode(array('factory'=>$method->getName(),'public_static'=>$method->isPublic()&&$method->isStatic(),
'defined_in_native_class'=>realpath($method->getFileName())===realpath($argv[1].'/src/applications/calendar/storage/PhabricatorCalendarEvent.php'),
'actual_class'=>get_class($event),'host_matches'=>$event->getHostPHID()===$user->getPHID(),'space'=>$event->getSpacePHID(),
'unsaved'=>$event->getID()===null,'mysqli_init_disabled'=>!function_exists('mysqli_init'),'old_error'=>$old_error));
"""
        result = subprocess.run(['php', '-d',
            'disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect',
            '-r', code, str(ROOT)], capture_output=True, text=True, timeout=15)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('', result.stderr)
        self.assertEqual({'factory':'initializeNewCalendarEvent','public_static':True,
            'defined_in_native_class':True,'actual_class':'PhabricatorCalendarEvent',
            'host_matches':True,'space':None,'unsaved':True,'mysqli_init_disabled':True,
            'old_error':'Error'}, json.loads(result.stdout))


class NativeCalendarApplicationGate(unittest.TestCase):
    def test_actual_prototype_install_gate_explains_both_normal_queries_and_scope_restores_default(self):
        code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');require $argv[1].'/support/governance/notification/calendar-reminder-cases.php';
$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$stack->pushSource(new PhabricatorConfigDictionarySource(['governance.private-mail.enabled'=>false,'phabricator.show-prototypes'=>false]));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,[]);PhabricatorEnv::setLocaleCode('en_US');
$user=static fn($letter)=>(new PhabricatorUser())->setPHID('PHID-USER-'.str_repeat($letter,20));
$normal=(new PhabricatorCalendarEventQuery())->setViewer($user('b'))->withPHIDs(['PHID-CEVT-'.str_repeat('c',20)]);
$before=['prototype'=>(new PhabricatorCalendarApplication())->isPrototype(),'installed'=>(new PhabricatorCalendarApplication())->isInstalled(),'normal_gate'=>$normal->canViewerUseQueryApplication(),'normal_query_absent'=>$normal->executeOne()===null,'omnipotent_gate'=>(new PhabricatorCalendarEventQuery())->setViewer(PhabricatorUser::getOmnipotentUser())->canViewerUseQueryApplication()];
$scope=governance_calendar_reminder_scope(['uri'=>'http://127.0.0.1:1','token'=>str_repeat('a',64)]);
// Fresh users are constructed after the selected scope in the actual SQL
// fixture. Do not clear or weaken the native per-viewer application cache.
$during=['show_prototypes'=>PhabricatorEnv::getEnvConfig('phabricator.show-prototypes'),'installed'=>(new PhabricatorCalendarApplication())->isInstalled(),'normal_gate'=>(new PhabricatorCalendarEventQuery())->setViewer($user('d'))->canViewerUseQueryApplication()];unset($scope);
$after=['show_prototypes'=>PhabricatorEnv::getEnvConfig('phabricator.show-prototypes'),'installed'=>(new PhabricatorCalendarApplication())->isInstalled(),'normal_gate'=>(new PhabricatorCalendarEventQuery())->setViewer($user('e'))->canViewerUseQueryApplication()];
echo json_encode(['before'=>$before,'during'=>$during,'after'=>$after,'mysqli_disabled'=>!function_exists('mysqli_init')]);'''
        result=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT)],capture_output=True,text=True,timeout=15)
        self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('',result.stderr)
        self.assertEqual({'before':{'prototype':True,'installed':False,'normal_gate':False,'normal_query_absent':True,'omnipotent_gate':True},'during':{'show_prototypes':True,'installed':True,'normal_gate':True},'after':{'show_prototypes':False,'installed':False,'normal_gate':False},'mysqli_disabled':True},json.loads(result.stdout))

class CalendarNativeDenialShapeTest(unittest.TestCase):
    def test_actual_fixture_block_accepts_only_native_denial_shapes(self):
        source=(ROOT/'support/governance/notification/calendar-reminder-cases.php').read_text()
        start=source.index('    try {\n    $visible=')
        end=source.index('    $callback=',start)
        block=source[start:end]
        # Actual final native method explicitly enables exception-shaped denial.
        native=(ROOT/'src/infrastructure/query/policy/PhabricatorPolicyAwareQuery.php').read_text()
        method=native[native.index('  final public function executeOne()'):native.index('  final public function executeOne()')+600]
        self.assertIn('$this->setRaisePolicyExceptions(true);',method)
        self.assertIn('throw $ex;',method)
        code=r'''require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
class PhabricatorCalendarEventQuery {
 public function setViewer($v){return $this;} public function withPHIDs($p){return $this;}
 public function executeOne(){if($GLOBALS['failure'])throw $GLOBALS['failure'];return $GLOBALS['value'];}
}
$recipient=null;$first=[new class {function getPHID(){return 'PHID-EVNT-host-control';}}];
$record=static function($ok,$label){$GLOBALS['accepted']=$ok;};
$block=base64_decode($argv[2]);$result=[];
foreach(['absent','policy','visible','other','error'] as $mode){
 $GLOBALS['value']=$mode==='visible'?new stdClass():null;
 $GLOBALS['failure']=$mode==='policy'?new PhabricatorPolicyException('host-control'):($mode==='other'?new RuntimeException('host-control'):($mode==='error'?new Error('host-control'):null));
 $GLOBALS['accepted']=null;$caught=null;try{eval($block);}catch(Throwable $ex){$caught=$ex;}
 $result[$mode]=['accepted'=>$GLOBALS['accepted'],'same_exception'=>$caught!==null&&$caught===$GLOBALS['failure']];
}
echo json_encode($result);'''
        import base64
        result=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect','-r',code,str(ROOT),base64.b64encode(block.encode()).decode()],capture_output=True,text=True,timeout=15)
        self.assertEqual(0,result.returncode,result.stderr)
        self.assertEqual({'absent':{'accepted':True,'same_exception':False},'policy':{'accepted':True,'same_exception':False},'visible':{'accepted':False,'same_exception':False},'other':{'accepted':None,'same_exception':True},'error':{'accepted':None,'same_exception':True}},json.loads(result.stdout))


class CalendarWireSourceProvenanceTests(unittest.TestCase):
    def test_actual_php_raw_source_map_matches_summary_without_changing_historical_pins(self):
        code = r"require $argv[1].'/support/governance/notification/calendar-reminder-cases.php';$out=array();foreach(governance_calendar_reminder_source_inputs()as$p){$out[$p]=hash_file('sha256',$argv[1].'/'.$p);}echo json_encode($out,JSON_THROW_ON_ERROR);"
        result = subprocess.run(['php', '-r', code, str(ROOT)], capture_output=True,
                                text=True, check=True, timeout=10)
        self.assertEqual('', result.stderr)
        self.assertEqual(json.loads(result.stdout), gate.summary(REVISION, QUEUE, root=ROOT)['source_inputs_sha256'])
        self.assertEqual(gate.SOURCE_INPUTS, gate.sources(ROOT))
        path = 'support/governance/native-organization/test-native-organization-mysql.php'
        self.assertEqual(hashlib.sha256((ROOT / path).read_bytes()).hexdigest(), gate.wire_sources(ROOT)[path])

    def test_exact_historical_source_root_remains_accepted_and_unknown_driver_is_rejected(self):
        path = 'support/governance/native-organization/test-native-organization-mysql.php'
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp).resolve()
            for name in gate.SOURCE_INPUTS:
                dest = root / name
                dest.parent.mkdir(parents=True, exist_ok=True)
                raw = (ROOT / name).read_bytes()
                if name == path:
                    raw = gate.conduit_delta.reduce_policy_cleanup_driver(raw)
                dest.write_bytes(raw)
            self.assertEqual(gate.SOURCE_INPUTS, gate.sources(root))
            self.assertEqual(gate.SOURCE_INPUTS, gate.summary(REVISION, QUEUE, root=root)['source_inputs_sha256'])
            (root / path).write_bytes((ROOT / path).read_bytes() + b'\n// unrelated raw source mutation\n')
            for consumer in (gate.sources, gate.wire_sources):
                with self.subTest(consumer=consumer.__name__), self.assertRaises(ValueError):
                    consumer(root)
            with self.assertRaises(ValueError):
                gate.summary(REVISION, QUEUE, root=root)

    def test_unrelated_source_mutation_cannot_be_certified_by_raw_wire_projection(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp).resolve()
            for name in gate.SOURCE_INPUTS:
                dest = root / name
                dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes((ROOT / name).read_bytes())
            path = 'support/governance/notification/calendar-reminder-cases.php'
            (root / path).write_bytes((root / path).read_bytes() + b'\n// unrelated mutation\n')
            with self.assertRaises(ValueError):
                gate.wire_sources(root)

if __name__ == '__main__':
    unittest.main()
