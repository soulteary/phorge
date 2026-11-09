"""Native PNG and configuration guards only; no File service or SQL execution."""
import hashlib,importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sp=importlib.util.spec_from_file_location('file_gate',ROOT/'scripts/governance/check_directory_transports_v1.py');gate=importlib.util.module_from_spec(sp);sp.loader.exec_module(gate)
class AvatarFixtureTest(unittest.TestCase):
    def php(self,body):
        setup="require$argv[1].'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($argv[1].'/src');require$argv[1].'/support/governance/native-organization/directory-avatar-fixture.php';$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$source=new PhabricatorConfigDictionarySource(array('phabricator.cache-namespace'=>'avatar-unit-only'));$stack->pushSource($source);(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());"
        p=subprocess.run(['php','-r',setup+body,str(ROOT)],capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');return json.loads(p.stdout)
    def test_missing_file_profile_refuses_without_opening_service(self):
        self.assertTrue(self.php("$refused=false;try{governance_directory_avatar_profile();}catch(RuntimeException$error){$refused=$error->getMessage()==='directory_native_avatar_profile_refused';}echo json_encode($refused);"))
    def test_only_owned_loopback_and_legacy_png_no_optional_backends(self):
        base="$source->setKeys(array('gorge.file.uri'=>'http://127.0.0.1:12345','gorge.file.token'=>str_repeat('d',64)));"
        self.assertTrue(self.php(base+"governance_directory_avatar_profile();echo 'true';"))
        for key,value in [('gorge.file.uri',"'https://example.invalid'"),('gorge.image.uri',"'http://127.0.0.1:1'"),('gorge.image.builtin-mode',"'shadow'"),('gorge.file.uploads','true'),('governance.media-exchange.enabled','true'),('gorge.notification.outbox','true')]:
            with self.subTest(key=key):self.assertTrue(self.php(base+"$source->setKeys(array('"+key+"'=>"+value+"));(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());$refused=false;try{governance_directory_avatar_profile();}catch(RuntimeException$error){$refused=$error->getMessage()==='directory_native_avatar_profile_refused';}echo json_encode($refused);"))
    def test_actual_PNG_encoder_decoder_and_metadata_hash(self):
        d=self.php("$image=imagecreatetruecolor(400,400);ob_start();imagepng($image);$bytes=ob_get_clean();$r=governance_directory_avatar_bytes($bytes,strlen($bytes),PhabricatorFile::hashFileContent($bytes));echo json_encode($r);")
        self.assertEqual((d['width'],d['height']),(400,400));self.assertTrue(d['actual_PNG_decode']);self.assertTrue(d['content_hash_match']);self.assertGreater(d['readback_bytes'],0)
    def test_real_PNG_wrong_size_hash_dimensions_or_nonPNG_refuses(self):
        d=self.php("$image=imagecreatetruecolor(400,400);ob_start();imagepng($image);$bytes=ob_get_clean();$hash=PhabricatorFile::hashFileContent($bytes);$tiny=imagecreatetruecolor(1,1);ob_start();imagepng($tiny);$small=ob_get_clean();$rows=array();foreach(array(array($bytes,strlen($bytes)+1,$hash),array($bytes,strlen($bytes),str_repeat('e',64)),array($small,strlen($small),PhabricatorFile::hashFileContent($small)),array('not-PNG',7,PhabricatorFile::hashFileContent('not-PNG')))as$args){$refused=false;try{governance_directory_avatar_bytes(...$args);}catch(RuntimeException$error){$refused=true;}$rows[]=$refused;}echo json_encode($rows);")
        self.assertEqual(d,[True]*4)
    def test_actual_ownFS_binary_hash_admission_not_execution(self):
        # Header-only data fixture, explicitly not a usable Go binary/provenance.
        data=bytearray(128);data[:6]=b'\x7fELF\x02\x01';data[18:20]=(62).to_bytes(2,'little')
        with tempfile.TemporaryDirectory()as directory:
            p=Path(directory)/'data-only';p.write_bytes(data);p.chmod(0o700);digest=hashlib.sha256(data).hexdigest()
            self.assertEqual(gate.file_binary_digest(str(p),digest),digest)
            with self.assertRaises(ValueError):gate.file_binary_digest(str(p),'0'*64)
            p.chmod(0o644)
            with self.assertRaises(ValueError):gate.file_binary_digest(str(p),digest)
            p.chmod(0o700);link=Path(directory)/'alias';os.link(p,link)
            with self.assertRaises(ValueError):gate.file_binary_digest(str(p),digest)
            link.unlink();p.write_bytes(b'not-an-ELF'*20)
            with self.assertRaises(ValueError):gate.file_binary_digest(str(p),hashlib.sha256(p.read_bytes()).hexdigest())
            with self.assertRaises(ValueError):gate.file_binary_digest(None,digest)
if __name__=='__main__':unittest.main()
