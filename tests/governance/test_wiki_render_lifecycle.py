"""Real filesystem ownership negatives; no process, SQL or authorization proof."""
import json,pathlib,shutil,subprocess,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]

@unittest.skipUnless(shutil.which('php'),'PHP required for owned filesystem cleanup tests')
class WikiRenderLifecycle(unittest.TestCase):
    def probe(self,mode):
        with tempfile.TemporaryDirectory(prefix='wiki-render-lifecycle-') as parent:
            script=r'''
require $argv[1];$directory=$argv[2].'/owned';mkdir($directory,0700);$owner=str_repeat('a',64);
$service=array('process'=>null,'private'=>$directory,'identity'=>lstat($directory),'owner'=>$owner,
 'marker_ready'=>$argv[3]!=='partial','binary_sha256'=>str_repeat('b',64),'source_revision'=>str_repeat('c',40));
if($argv[3]!=='missing'){file_put_contents($directory.'/owner',$argv[3]==='partial'?substr($owner,0,12):$owner);chmod($directory.'/owner',0600);}
if($argv[3]==='unknown'){file_put_contents($directory.'/unknown','PRIVATE_TEST_FILE');}
$refused=false;$reason=null;try{$receipt=governance_wiki_render_stop($service);}catch(RuntimeException$e){$refused=true;$reason=$e->getMessage();}
$unknown=file_exists($directory.'/unknown');$remaining=is_dir($directory);
if($remaining){foreach(array('owner','unknown')as$name){if(file_exists($directory.'/'.$name)){unlink($directory.'/'.$name);}}rmdir($directory);}
echo json_encode(array('refused'=>$refused,'reason'=>$reason,'unknown_preserved'=>$unknown,'directory_retained'=>$remaining,
 'cleanup_receipt'=>$receipt??null));
'''
            result=subprocess.run(['php','-r',script,str(ROOT/'support/governance/notification/wiki-render-service.php'),parent,mode],capture_output=True,text=True)
            self.assertEqual(0,result.returncode);self.assertEqual('',result.stderr)
            return json.loads(result.stdout)
    def test_partial_owner_write_failure_cleans_only_owned_directory(self):
        value=self.probe('partial');self.assertFalse(value['refused']);self.assertFalse(value['directory_retained'])
        self.assertEqual({'binary_sha256':'b'*64,'source_revision':'c'*40,'process_stopped':True,'private_storage_removed':True},value['cleanup_receipt'])
    def test_missing_expected_owner_and_unknown_files_refuse_cleanup(self):
        for mode,reason in [('missing','wiki_render_cleanup_owner_refused'),('unknown','wiki_render_cleanup_unknown_file')]:
            with self.subTest(mode=mode):
                value=self.probe(mode);self.assertTrue(value['refused']);self.assertEqual(reason,value['reason'])
                self.assertTrue(value['directory_retained']);self.assertIsNone(value['cleanup_receipt'])
                if mode=='unknown':self.assertTrue(value['unknown_preserved'])
