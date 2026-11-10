"""Exact current fixture source profile; pure catalog/DDL evidence, never SQL."""
import copy,hashlib,json,re,subprocess,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_completion as completion
import check_native_organization as native
NEW='20261110.project.contentgrants.sql'

def restore():
 return {'quickstart_sha256':hashlib.sha256((ROOT/'resources/sql/quickstart.sql').read_bytes()).hexdigest(),
  'app_schema_count':54,'owned_schema_count':55,'quickstart_source_table_count':500,'current_table_count':549,
  'restore_phase_workers_started':0,'restore_phase_network_endpoints_configured':0,
  'patch_audit':{'source_patch_count':1575,'applied_patch_count':1575,'pending_patch_keys':[],
   'current_patch_ledger_complete':True,'migrations_executed':38,
   'migration_source_hashes':{'phabricator:'+n:hashlib.sha256((ROOT/'resources/sql/autopatches'/n).read_bytes()).hexdigest()for n in completion.PATCHES}}}

class CurrentRestoreProfile(unittest.TestCase):
 def test_actual_native_catalog_and_quickstart_ledger_have_exact38_pending(self):
  code='require "support/runtime/src/__phutil_library_init__.php";phutil_load_library("src");$p=PhabricatorSQLPatchList::buildAllPatches();$r=[];foreach($p as $k=>$v){$r[$k]=$v->isDead();}echo json_encode($r);'
  proc=subprocess.run(['php','-r',code],cwd=ROOT,capture_output=True,text=True,timeout=30)
  self.assertEqual(proc.returncode,0,proc.stderr);catalog=json.loads(proc.stdout);self.assertEqual(len(catalog),1575)
  raw=(ROOT/'resources/sql/quickstart.sql').read_text();ledger=raw.split('INSERT INTO `patch_status` VALUES\n',1)[1].split(';',1)[0]
  applied=set(re.findall(r"\('([^']+)'",ledger));self.assertEqual(len(applied),1537)
  pending={k for k,dead in catalog.items()if not dead and k not in applied}
  self.assertEqual(pending,{'phabricator:'+n for n in completion.PATCHES});self.assertEqual(len(pending),38)
  self.assertFalse(catalog['phabricator:'+NEW]);self.assertNotIn('phabricator:'+NEW,applied)
 def test_current_source_DDL_exactly_accounts_for549_tables(self):
  quick=(ROOT/'resources/sql/quickstart.sql').read_text();self.assertEqual(len(re.findall(r'\bCREATE TABLE\b',quick,re.I)),500)
  total=0;old=0
  self.assertEqual(len(completion.PATCHES),len(set(completion.PATCHES)));self.assertEqual(len(completion.PATCHES),38)
  for name in completion.PATCHES:
   raw=(ROOT/'resources/sql/autopatches'/name).read_text()
   # No approved migration removes/renames tables; PHP migrations only mutate rows.
   self.assertNotRegex(raw,r'(?i)\b(?:DROP|RENAME) TABLE\b')
   if name.endswith('.php'):
    self.assertNotRegex(raw,r'(?i)\bCREATE TABLE\b');continue
   count=len(re.findall(r'\bCREATE TABLE\b',raw,re.I));total+=count
   if name!=NEW:old+=count
  self.assertEqual(old,46);self.assertEqual(total,49);self.assertEqual(500+total,549)
  raw=(ROOT/'resources/sql/autopatches'/NEW).read_bytes();self.assertEqual(hashlib.sha256(raw).hexdigest(),'16f043e830f29b246efd12c22569e66bf0d2b629f4b9dbd062ab0f30a487d27e')
  self.assertEqual(len(re.findall(rb'CREATE TABLE',raw)),3);self.assertNotRegex(raw,rb'(?i)\b(?:ALTER|DROP|RENAME)\b')
 def test_current_full_profile_accepted_and_unknown_missing_old_profiles_refused(self):
  native.validate_restore(restore(),ROOT)
  for key,values in [('current_table_count',[546,548,550,True,'549']),('source_patch_count',[1574,1576,True,'1575']),('migrations_executed',[37,39,True,'38'])]:
   for value in values:
    d=restore();target=d if key=='current_table_count'else d['patch_audit'];target[key]=value
    if key=='source_patch_count':target['applied_patch_count']=value
    with self.subTest(key=key,value=value),self.assertRaises(ValueError):native.validate_restore(d,ROOT)
  for mode in ('missing','unknown','wrong-hash','pending','incomplete'):
   d=restore();audit=d['patch_audit'];hashes=audit['migration_source_hashes']
   if mode=='missing':del hashes['phabricator:'+NEW]
   elif mode=='unknown':hashes['phabricator:20990101.unreviewed.sql']='0'*64
   elif mode=='wrong-hash':hashes['phabricator:'+NEW]='0'*64
   elif mode=='pending':audit['pending_patch_keys']=['phabricator:'+NEW]
   else:audit['current_patch_ledger_complete']=False
   with self.subTest(mode=mode),self.assertRaises(ValueError):native.validate_restore(d,ROOT)


 def test_full_completion_consumer_keeps_exact_count_and_migration_guards(self):
  import test_completion
  completion.validate(test_completion.valid_template(),ROOT)
  for key,values in [('current_table_count',[500,546,550,True,'549']),('source_patch_count',[1563,1574,1576,True,'1575'])]:
   for value in values:
    d=test_completion.valid_template();target=d['source_restore']if key=='current_table_count'else d['source_restore']['patch_audit'];target[key]=value
    if key=='source_patch_count':target['applied_patch_count']=value
    with self.subTest(key=key,value=value),self.assertRaises(ValueError):completion.validate(d,ROOT)
  for mode in ('missing','unknown','bad-hash','wrong-count'):
   d=test_completion.valid_template();audit=d['source_restore']['patch_audit'];hashes=audit['migration_source_hashes']
   if mode=='missing':del hashes['phabricator:'+NEW]
   elif mode=='unknown':hashes['phabricator:20990101.unreviewed.sql']='0'*64
   elif mode=='bad-hash':hashes['phabricator:'+NEW]='0'*64
   else:audit['migrations_executed']=37
   with self.subTest(mode=mode),self.assertRaises(ValueError):completion.validate(d,ROOT)

if __name__=='__main__':unittest.main()
