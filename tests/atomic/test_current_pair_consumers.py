"""Cross-consumer current source pins; PHP guard seams never start a service."""
import ast,json,pathlib,re,shutil,subprocess,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
OLD='83351b0e7eba38e50ff076ba6763ca68ebc57e1b'
HELPER='support/governance/notification/wiki-render-service.php'
GATE='scripts/governance/check_native_organization.py'
def consumers(root):
    expected=json.loads((root/'tests/atomic/paired-gorge.json').read_text())['revision']
    helper=(root/HELPER).read_text()
    guards=re.findall(r"(?:\$revision|\$data\['source_revision'\])!=='([a-f0-9]{40})'",helper)
    if guards != [expected,expected]:raise ValueError('Wiki live source guards differ from reviewed test pair')
    tree=ast.parse((root/GATE).read_text());matches=[]
    for node in tree.body:
        if isinstance(node,ast.Assign) and len(node.targets)==1 and ast.unparse(node.targets[0])=="NATIVE['private_mail_tests']['wiki_content_publication_tests']":
            state=ast.literal_eval(node.value)
            if state != {'status':'retired-runtime-removed','historical_mail_refused':True}:
                raise ValueError('Retired Wiki collector must not claim live render coverage')
            matches.append(expected)
    if matches != [expected]:raise ValueError('Wiki strict collector differs from reviewed test pair')
    return expected
class CurrentPairConsumers(unittest.TestCase):
    def test_current_JSON_live_helper_and_strict_collector_are_consistent(self):
        self.assertEqual(consumers(ROOT),'16318f87a017f82c28f3011b362364cf24b73ef8')
    def test_each_stale_live_consumer_reproduces_the_guard_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory)
            for name in (HELPER,GATE,'tests/atomic/paired-gorge.json'):
                target=root/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes((ROOT/name).read_bytes())
            current=consumers(root)
            for name in (HELPER,GATE):
                original=(root/name).read_text()
                changed=original.replace(current,OLD) if name==HELPER else original.replace('retired-runtime-removed','unverified')
                self.assertNotEqual(changed,original)
                (root/name).write_text(changed)
                with self.subTest(name=name),self.assertRaises(ValueError):consumers(root)
                (root/name).write_text(original)
    @unittest.skipUnless(shutil.which('php'),'PHP required for actual source guard controls')
    def test_actual_start_prelude_and_child_ready_guard_current_pass_stale_refused(self):
        current=consumers(ROOT);helper=(ROOT/HELPER).read_text()
        # Execute the actual pre-socket startup body, stopping before any socket/proc call.
        prefix=helper.split('function governance_wiki_render_start($fixture) {',1)[1].split("  $socket=",1)[0]
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory);binary=root/'binary';binary.write_bytes(b'synthetic-not-executed');binary.chmod(0o700)
            for revision,passed in ((current,True),(OLD,False),('main',False)):
                code="class PhorgeGovernanceCompletionFixture{function assertOwned(){}};function probe($fixture){"+prefix+"return true;}putenv('PHORGE_GOVERNANCE_GORGE_RENDER_BINARY='.$argv[1]);putenv('PHORGE_GOVERNANCE_GORGE_RENDER_SHA256='.hash_file('sha256',$argv[1]));putenv('PHORGE_GOVERNANCE_GORGE_RENDER_SOURCE_REVISION='.$argv[2]);try{echo probe(new PhorgeGovernanceCompletionFixture())?'accepted':'invalid';}catch(RuntimeException $e){echo 'refused';}"
                result=subprocess.run(['php','-r',code,str(binary),revision],capture_output=True,text=True,timeout=5)
                self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stderr,'');self.assertEqual(result.stdout,'accepted'if passed else'refused')
                data={'owner':'a'*64,'uri':'http://127.0.0.1:1234','token':'b'*64,'source_revision':revision};path=root/'render.json';path.write_text(json.dumps(data));path.chmod(0o600)
                code="class ScopeSeam{function overrideEnvConfig($k,$v){return $this;}}class PhabricatorEnv{static function getEnvConfig($k){return [];}static function beginScopedEnv(){return new ScopeSeam();}}require $argv[1];try{governance_wiki_render_scope($argv[2],str_repeat('a',64));echo 'accepted';}catch(RuntimeException $e){echo 'refused';}"
                result=subprocess.run(['php','-r',code,str(ROOT/HELPER),str(root)],capture_output=True,text=True,timeout=5)
                self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(result.stderr,'');self.assertEqual(result.stdout,'accepted'if passed else'refused')
