"""Exact public empty-fixture transcript + real own-buffer lifecycle, no DB."""
import hashlib,importlib.util,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];HELPER='support/governance/native-organization/directory-restore-progress.php'
s=importlib.util.spec_from_file_location('progress_gate',ROOT/'scripts/governance/check_directory_transports_v1.py');g=importlib.util.module_from_spec(s);s.loader.exec_module(g)
RAW=''.join(x+'\n'for x in g.RESTORE_LINES.values())
class RestoreProgressTest(unittest.TestCase):
 def php(self,code):
  p=subprocess.run(['php','-r',"require$argv[1].'/"+HELPER+"';"+code,str(ROOT)],capture_output=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,b'');return json.loads(p.stdout)
 def test_actual_owned_capture_separates_only_exact_six_lines_and_leaves_business_bytes_unchanged(self):
  encoded=__import__('base64').b64encode(RAW.encode()).decode();d=self.php("ob_start();echo 'BUSINESS_BEFORE';$before=ob_get_level();$proof=governance_directory_capture_restore_progress(static function(){echo base64_decode('"+encoded+"');},$argv[1]);echo 'BUSINESS_AFTER';$same=ob_get_level()===$before;$business=ob_get_clean();echo json_encode(array('proof'=>$proof,'same_depth'=>$same,'business'=>$business));")
  self.assertTrue(d['same_depth']);self.assertEqual(d['business'],'BUSINESS_BEFOREBUSINESS_AFTER');g.validate_restore_progress(d['proof'],ROOT);self.assertEqual(d['proof']['sha256'],hashlib.sha256(RAW.encode()).hexdigest())
 def test_exact_set_once_accepts_dependency_order_permutation_without_trimming(self):
  reverse=''.join(x+'\n'for x in reversed(list(g.RESTORE_LINES.values())));encoded=__import__('base64').b64encode(reverse.encode()).decode();proof=self.php("echo json_encode(governance_directory_restore_progress_validate(base64_decode('"+encoded+"'),$argv[1]));");g.validate_restore_progress(proof,ROOT);self.assertNotEqual(proof['sha256'],hashlib.sha256(RAW.encode()).hexdigest())
 def test_unknown_warning_private_extra_duplicate_missing_crlf_or_overflow_refuse(self):
  invalid=[RAW+'PRIVATE_EXTRA\n',RAW+'Warning: synthetic\n',RAW+next(iter(g.RESTORE_LINES.values()))+'\n',''.join(RAW.splitlines(keepends=True)[:-1]),RAW.replace('\n','\r\n'),RAW.rstrip('\n'),RAW+'X'*5000]
  for raw in invalid:
   encoded=__import__('base64').b64encode(raw.encode()).decode();self.assertTrue(self.php("ob_start();$refused=false;try{governance_directory_capture_restore_progress(static function(){echo base64_decode('"+encoded+"');},$argv[1]);}catch(RuntimeException$error){$refused=true;}$outer=ob_get_clean();echo json_encode($refused&&$outer==='');"))
 def test_original_primary_Throwable_identity_retained_even_with_unknown_progress(self):
  self.assertTrue(self.php("ob_start();$primary=new RuntimeException('PRIVATE_PRIMARY');$same=false;try{governance_directory_capture_restore_progress(static function()use($primary){echo 'PRIVATE_PROGRESS';throw$primary;},$argv[1]);}catch(Throwable$error){$same=$error===$primary;}$outer=ob_get_clean();echo json_encode($same&&$outer==='');"))
 def test_same_depth_foreign_and_deeper_foreign_buffers_are_not_popped(self):
  for action in ["ob_end_clean();ob_start();echo 'FOREIGN';", "ob_start();echo 'FOREIGN';"]:
   self.assertTrue(self.php("ob_start();$base=ob_get_level();$refused=false;try{governance_directory_capture_restore_progress(static function(){"+action+"},$argv[1]);}catch(RuntimeException$error){$refused=true;}$foreign_still=ob_get_level()>$base;$foreign=ob_get_clean();while(ob_get_level()>$base){ob_end_clean();}ob_end_clean();echo json_encode($refused&&$foreign_still&&$foreign==='FOREIGN');"))
 def test_proof_missing_or_modified_never_upgrades_legacy_receipt(self):
  from test_directory_transports_receipt_v1 import template,FILE_DIGEST
  good=template();g.validate(good,file_digest=FILE_DIGEST)
  mutations=[('profile','legacy'),('bytes',0),('sha256','a'*64),('line_count',True),('exact_six_empty_lines_once',False),('unknown_output_accepted',True),('source_sha256',{})]
  for key,value in mutations:
   d=template();d['restore_progress'][key]=value
   with self.subTest(key=key),self.assertRaises(ValueError):g.validate(d,file_digest=FILE_DIGEST)
  for mode in ['missing','old-profile']:
   d=template()
   if mode=='missing':del d['restore_progress']
   else:d['batch']='native-directory-transports-v1';d['scope']='owned-native-directory-transport-matrix-with-paired-file'
   with self.assertRaises(ValueError):g.validate(d,file_digest=FILE_DIGEST)
 def test_exact_six_source_echoes_and_PHP_classifier_match_pinned_source(self):
  actual=self.php('echo json_encode(governance_directory_restore_progress_lines());');self.assertEqual(actual,g.RESTORE_LINES)
  for name,line in actual.items():
   raw=(ROOT/'resources/sql/autopatches'/name).read_text();self.assertEqual(raw.count("echo pht('"+line+"').\"\\n\";"),1,name)
 def test_selected_validator_explicit_profile_inverse_preserves_all_old_assertions(self):
  import ast
  before=ast.parse(subprocess.check_output(['git','-C',str(ROOT),'show','fd2d7662085d55d33ca0b6cb90983538a2d01d7e:scripts/governance/check_directory_transports_v1.py']))
  after=ast.parse((ROOT/'scripts/governance/check_directory_transports_v1.py').read_bytes())
  class Inverse(ast.NodeTransformer):
   def visit_Expr(self,node):
    call=node.value
    if isinstance(call,ast.Call)and isinstance(call.func,ast.Name):
     if call.func.id=='validate_restore_progress':return None
     if call.func.id=='mark'and len(call.args)==1 and isinstance(call.args[0],ast.Constant)and call.args[0].value=='restore-progress':return None
    return self.generic_visit(node)
   def visit_Set(self,node):
    node.elts=[x for x in node.elts if not(isinstance(x,ast.Constant)and x.value=='restore_progress')];return self.generic_visit(node)
   def visit_Constant(self,node):
    mapping={'native-directory-transports-v2-restore-progress':'native-directory-transports-v1','owned-native-directory-transport-matrix-with-paired-file-and-exact-restore-progress':'owned-native-directory-transport-matrix-with-paired-file','native-directory-transports-accepted-v2-exact-restore-progress':'native-directory-transports-accepted-v1-file-dependency'}
    if isinstance(node.value,str)and node.value in mapping:return ast.Constant(value=mapping[node.value])
    return node
   def visit_Call(self,node):
    node.keywords=[x for x in node.keywords if x.arg!='restore_progress'];return self.generic_visit(node)
  old=next(x for x in before.body if isinstance(x,ast.FunctionDef)and x.name=='validate');new=next(x for x in after.body if isinstance(x,ast.FunctionDef)and x.name=='validate')
  self.assertEqual(ast.dump(old,include_attributes=False),ast.dump(Inverse().visit(new),include_attributes=False))
if __name__=='__main__':unittest.main()
