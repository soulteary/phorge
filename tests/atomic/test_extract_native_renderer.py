"""CLI lifecycle unit contracts, not evidence that Docker ran on this host."""
import hashlib,importlib.util,json,pathlib,subprocess,tempfile,unittest
P=pathlib.Path(__file__).with_name('extract_native_renderer.py')
spec=importlib.util.spec_from_file_location('extract_renderer',P);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
IMAGE='sha256:'+'a'*64
class DockerContract:
    def __init__(self,fail_copy=False,partial_create=False):self.commands=[];self.fail_copy=fail_copy;self.partial_create=partial_create;self.owner=None
    def __call__(self,args,**kwargs):
        self.commands.append(args);out=''
        if args[:3]==['docker','image','inspect']:
            out=json.dumps([{'Id':IMAGE,'Config':{'Labels':{'phorge.gorge-revision':m.EXPECTED}}}])
        elif args[:2]==['docker','create']:
            self.owner=args[args.index('--label')+1].split('=',1)[1];self.name=args[args.index('--name')+1]
            if args[-1]!=IMAGE:raise AssertionError('must create by inspected immutable image ID')
            if self.partial_create:raise subprocess.CalledProcessError(1,args)
        elif args[:2]==['docker','cp']:
            if self.fail_copy:raise subprocess.CalledProcessError(1,args)
            pathlib.Path(args[-1]).write_bytes(b'synthetic renderer contract bytes')
        elif args[:2]==['docker','inspect']:
            out=json.dumps([{'Image':IMAGE,'Config':{'Labels':{'governance.fixture.owner':self.owner}},'State':{'Running':False}}])
        elif args[:2]!=['docker','rm']:raise AssertionError(args[:2])
        return subprocess.CompletedProcess(args,0,stdout=out,stderr='')
class RendererExtract(unittest.TestCase):
    def test_source_label_and_image_identity_are_required(self):
        info={'Id':IMAGE,'Config':{'Labels':{'phorge.gorge-revision':m.EXPECTED}}}
        self.assertEqual(IMAGE,m.image_identity(info,m.EXPECTED))
        for value,revision in [({'Id':IMAGE},m.EXPECTED),(info,'f'*40),({'Id':True},m.EXPECTED)]:
            with self.subTest(value=value),self.assertRaises(ValueError):m.image_identity(value,revision)
    def test_extraction_binds_actual_copied_bytes_and_removes_owned_container(self):
        with tempfile.TemporaryDirectory()as d:
            out=pathlib.Path(d)/'renderer';proof=pathlib.Path(d)/'proof.json';runner=DockerContract()
            value=m.extract(out,proof,m.EXPECTED,runner)
            self.assertEqual(hashlib.sha256(out.read_bytes()).hexdigest(),value['binary_sha256'])
            self.assertEqual(value,json.loads(proof.read_text()));self.assertFalse(value['service_started'])
            self.assertEqual(0,value['public_ports']);self.assertEqual(['docker','rm',runner.name],runner.commands[-1])
            self.assertFalse(any(a[:2]==['docker','start']for a in runner.commands))
    def test_copy_failure_still_removes_only_registered_owned_container(self):
        with tempfile.TemporaryDirectory()as d:
            runner=DockerContract(fail_copy=True);proof=pathlib.Path(d)/'proof.json'
            with self.assertRaises(subprocess.CalledProcessError):m.extract(pathlib.Path(d)/'renderer',proof,m.EXPECTED,runner)
            self.assertEqual(['docker','rm',runner.name],runner.commands[-1]);self.assertFalse(proof.exists())
    def test_lost_create_reply_still_inspects_and_cleans_only_owned_container(self):
        with tempfile.TemporaryDirectory()as d:
            runner=DockerContract(partial_create=True);proof=pathlib.Path(d)/'proof.json'
            with self.assertRaises(subprocess.CalledProcessError):m.extract(pathlib.Path(d)/'renderer',proof,m.EXPECTED,runner)
            self.assertEqual(['docker','rm',runner.name],runner.commands[-1]);self.assertFalse(proof.exists())
            self.assertFalse(any(a[:2]==['docker','cp']for a in runner.commands))
        class ForeignAfterPartial(DockerContract):
            def __call__(self,args,**kwargs):
                if args[:2]==['docker','inspect']:self.owner='foreign-owner'
                return super().__call__(args,**kwargs)
        with tempfile.TemporaryDirectory()as d:
            runner=ForeignAfterPartial(partial_create=True)
            with self.assertRaisesRegex(ValueError,'^renderer_container_owner_refused$'):
                m.extract(pathlib.Path(d)/'renderer',pathlib.Path(d)/'proof.json',m.EXPECTED,runner)
            self.assertFalse(any(a[:2]==['docker','rm']for a in runner.commands))

    def test_existing_output_is_never_overwritten(self):
        with tempfile.TemporaryDirectory()as d:
            p=pathlib.Path(d)/'renderer';p.write_bytes(b'unknown prior bytes');runner=DockerContract()
            with self.assertRaises(ValueError):m.extract(p,pathlib.Path(d)/'proof.json',m.EXPECTED,runner)
            self.assertEqual(b'unknown prior bytes',p.read_bytes());self.assertEqual([],runner.commands)
