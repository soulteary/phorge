"""Safe failure projection: actual native Throwable + inert malformed inputs."""
import copy,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sp=importlib.util.spec_from_file_location('directory_safe_failure',ROOT/'scripts/governance/project_directory_transport_failure_v1.py');projector=importlib.util.module_from_spec(sp);sp.loader.exec_module(projector)
SOURCE='src/applications/people/governance/service/PhorgeOrganizationRequest.php'
def marker():return dict(schema='native-directory-failure-marker-v1',kind='primary',**{'class':'InvalidArgumentException'},source=SOURCE,line=1,stage='focused-native-directory-transports')
def raw(item):return projector.PREFIX+json.dumps(item).encode()+b'\n'
class SafeFailureTest(unittest.TestCase):
    def php(self,body):
        setup="require$argv[1].'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($argv[1].'/src');require$argv[1].'/support/governance/native-organization/directory-transport-diagnostic.php';"
        p=subprocess.run(['php','-r',setup+body,str(ROOT)],capture_output=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);return p
    def test_actual_native_request_failure_projects_only_class_public_path_line_stage(self):
        p=self.php("try{PhorgeOrganizationRequest::normalize(array('token'=>'PRIVATE_TOKEN_CANARY'));}catch(Throwable$error){$same=$error;governance_directory_transport_emit_failure($error,$argv[1],'focused-native-directory-transports');echo json_encode($same===$error);}")
        self.assertEqual(json.loads(p.stdout),True);self.assertNotIn(b'PRIVATE_TOKEN_CANARY',p.stderr)
        d=projector.project(p.stderr);item=d['failures'][0]
        self.assertEqual(item['class'],'InvalidArgumentException');self.assertEqual(item['source'],SOURCE);self.assertGreater(item['line'],0)
        self.assertFalse(d['selected_matrix_accepted']);self.assertFalse(d['runtime_authority'])
    def test_actual_exception_message_and_outside_source_never_print(self):
        p=self.php("$error=new RuntimeException('mysql:password=PRIVATE_PASSWORD session=PRIVATE_SESSION');governance_directory_transport_emit_failure($error,$argv[1],'unknown injected PRIVATE_STAGE');echo 'true';")
        self.assertNotIn(b'PRIVATE_',p.stderr);item=projector.project(p.stderr)['failures'][0]
        self.assertEqual(item['class'],'RuntimeException');self.assertEqual(item['source'],'unknown-source');self.assertEqual(item['line'],0);self.assertEqual(item['stage'],'unknown-stage')
    def test_raw_private_stderr_ignored_and_not_copied_into_safe_result(self):
        d=projector.project(b'mysql:host=PRIVATE_DSN token=PRIVATE_TOKEN person=PRIVATE_PERSON\n'+raw(marker()))
        self.assertNotIn('PRIVATE_',json.dumps(d));self.assertEqual(len(d['failures']),1)
    def test_extra_fields_raw_payload_and_unknown_class_or_stage_refuse(self):
        for k,v in [('message','PRIVATE_MESSAGE'),('args',['PRIVATE_ARGS']),('token','PRIVATE_TOKEN'),('class','PRIVATE_CLASS'),('stage','PRIVATE_STAGE'),('kind','PRIVATE_KIND')]:
            d=marker();d[k]=v
            with self.subTest(k=k),self.assertRaises(ValueError):projector.project(raw(d))
    def test_path_escape_private_absolute_untracked_and_line_types_refuse(self):
        for path in ['/private/PRIVATE_PERSON.php','../private.php','src/../private.php','src//private.php','src/PRIVATE_UNTRACKED.php','https://example.invalid/file.php','src/file.php\nPRIVATE_TOKEN']:
            d=marker();d['source']=path
            with self.subTest(path=path),self.assertRaises(ValueError):projector.project(raw(d))
        for number in [True,'1',0,-1,1000001]:
            d=marker();d['line']=number
            with self.subTest(number=number),self.assertRaises(ValueError):projector.project(raw(d))
    def test_missing_duplicate_junk_and_oversize_markers_refuse(self):
        cases=[b'raw failure only PRIVATE_MESSAGE',raw(marker())+raw(marker()),projector.PREFIX+b'{"schema":1,"schema":2}\n',raw(marker()).rstrip()+b'TRAILING\n',b'X'*(1024*1024+1)]
        for data in cases:
            with self.subTest(length=len(data)),self.assertRaises(ValueError):projector.project(data)
        for key in marker():
            d=marker();d.pop(key)
            with self.subTest(key=key),self.assertRaises(ValueError):projector.project(raw(d))
    def test_owned_special_alias_and_big_stderr_refuse_before_read(self):
        with tempfile.TemporaryDirectory()as directory:
            root=Path(directory);p=root/'fifo';os.mkfifo(p)
            with self.assertRaises(ValueError):projector.raw_stderr(p)
            p.unlink();p.write_bytes(raw(marker()));alias=root/'link';alias.symlink_to(p)
            with self.assertRaises(OSError):projector.raw_stderr(alias)
            alias.unlink();os.link(p,alias)
            with self.assertRaises(ValueError):projector.raw_stderr(p)
            alias.unlink()
            with p.open('wb')as f:f.truncate(1024*1024+1)
            with self.assertRaises(ValueError):projector.raw_stderr(p)
    def test_cleanup_kind_and_stage_are_explicit_bounded_diagnostic_only(self):
        d=marker();d['kind']='cleanup';d['stage']='owned-cleanup';result=projector.project(raw(d))
        self.assertEqual(result['failures'],[d]);self.assertTrue(result['diagnostic_only']);self.assertFalse(result['selected_matrix_accepted'])
class WorkflowExitTest(unittest.TestCase):
    def run_handler(self, output_kind, corrected=True):
        source=(ROOT/'.github/workflows/native-directory-transports-v1.yml').read_text()
        start=source.index(' || {\n',source.index('php support/governance/native-organization/test-directory-transports-v1-mysql.php'))
        end=source.index('\n          }\n          test ! -s',start)+len('\n          }')
        handler=source[start+len(' || '):end]
        guarded="if [ -n \"${GITHUB_OUTPUT-}\" ] && echo 'safe_diagnostic_owned=true' >> \"$GITHUB_OUTPUT\"; then :; fi"
        self.assertEqual(handler.count(guarded),1)
        if not corrected:
            handler=handler.replace(guarded,"echo 'safe_diagnostic_owned=true' >> \"$GITHUB_OUTPUT\"",1)
        # Only the successful diagnostic projector is a collaborator. The exact
        # handler, native37, set-euo, real filesystem and append fault are real.
        # No PHP matrix, SQL or actual projector success is claimed here.
        script="set -euo pipefail\numask 077\npython3(){ printf '%s\\n' '{\"diagnostic_only\":true}'; }\nnative37(){ /bin/bash -c 'exit 37'; }\nnative37 || "+handler+"\n"
        with tempfile.TemporaryDirectory()as directory:
            root=Path(directory);env={'PATH':'/usr/bin:/bin','RUNNER_TEMP':str(root),'GITHUB_SHA':'0'*40}
            out=root/'output'
            if output_kind=='directory':out.mkdir();env['GITHUB_OUTPUT']=str(out)
            elif output_kind=='valid':env['GITHUB_OUTPUT']=str(out)
            elif output_kind=='empty':env['GITHUB_OUTPUT']=''
            elif output_kind!='unset':self.fail('unknown test fixture')
            result=subprocess.run(['/bin/bash'],input=script,text=True,env=env,capture_output=True,timeout=5)
            self.assertTrue((root/'directory-transports-safe-failure-v1/diagnostic.json').is_file())
            if output_kind=='valid':self.assertEqual(out.read_text(),'safe_diagnostic_owned=true\n')
            return result.returncode
    def test_actual_unguarded_append_reproduces_native37_becoming1(self):
        self.assertEqual(self.run_handler('directory',False),1)
    def test_native37_survives_unset_output(self):self.assertEqual(self.run_handler('unset'),37)
    def test_native37_survives_empty_output(self):self.assertEqual(self.run_handler('empty'),37)
    def test_native37_survives_unwritable_directory_output(self):self.assertEqual(self.run_handler('directory'),37)
    def test_native37_survives_successful_output_append(self):self.assertEqual(self.run_handler('valid'),37)
if __name__=='__main__':unittest.main()
