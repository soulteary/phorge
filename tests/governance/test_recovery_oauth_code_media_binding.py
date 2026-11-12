"""Exact signed S1 media source metadata, no SQL or runtime execution."""
from pathlib import Path
import ast,hashlib,importlib.util,json,subprocess,sys,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import recovery_oauth_client_lifecycle_media_provider_delta as original_media_binding
import project_description_admission_delta as current_admission
def reduce_stageB(name,raw):
 view=current_admission.media_source_delta()
 return view.reduce_stageB(name,raw)if name in view.SOURCE_INPUTS else raw
class CurrentMediaBinding:
 SOURCE_INPUTS=original_media_binding.SOURCE_INPUTS
 @staticmethod
 def reduce_current(name,raw):
  # Exact current Stage C bytes first return to signed Stage B, then historical provider.
  return original_media_binding.reduce_current(name,reduce_stageB(name,raw))
media_binding=CurrentMediaBinding()
BASE='cc39f46941cb8f06eb3e7648f5da38db79be83dc'
TREE='1fe79ccc366ce45e863bf7fa3ac5d1b7b03a7a5a'
PATH='support/governance/media-session-docker/snapshot.py'
METADATA={PATH,'support/governance/media-session-ci/project.py','support/governance/media-session-docker/README.md','support/governance/media-session-ci/test_ci.py'}

# Test-only independent raw Git oracle. No snapshot object reader is used here.
_MEDIA_LEGACY_STAGE='b9f16952adb7f23f5966422645a7b4598114ae44'
_MEDIA_LEGACY_BASE='d95b3bd2253afb6fdce615f4041920bdce5df625'
_MEDIA_LEGACY_TREE='4af5ae154be5b9fd01565762edbb712450c41c34'
_MEDIA_OVERLAY_NAMES=('support/governance/media-session-positive/README.md', 'support/governance/media-session-positive/cases.php', 'support/governance/media-session-positive/diagnostic.php', 'support/governance/media-session-positive/profile.php', 'support/governance/media-session-positive/run.php', 'support/governance/media-session-positive/source-pins.json', 'support/governance/media-session-positive/test-diagnostic.php', 'support/governance/media-session-positive/test-guards.php')
_MEDIA_LEGACY_SEVEN=('support/governance/media-session-ci/N05-source-rebind-plan.json', 'support/governance/media-session-ci/project.py', 'support/governance/media-session-ci/test_ci.py', 'support/governance/media-session-docker/README.md', 'support/governance/media-session-docker/snapshot.py', 'support/governance/media-session-positive/run.php', 'support/governance/media-session-positive/source-pins.json')

_MEDIA_LEGACY_SEVEN_PINS={'support/governance/media-session-docker/snapshot.py': {'bytes': 6446, 'sha256': 'b9cea40d27a4fb2fce85d390b53cff471993a6a692d4709dfca20413dd065f3e', 'git_mode': '100644', 'git_blob': '9f2cf14a8332583172d30054c24d79b9bd11c90e'}, 'support/governance/media-session-docker/README.md': {'bytes': 11543, 'sha256': '069117e56492a2b6a3c8e704d6cbdb1d1ba218b1f938424a2fdeeb89259c52ee', 'git_mode': '100644', 'git_blob': 'd46d3e173d20d234d1b8546129ddaaf94c51874c'}, 'support/governance/media-session-ci/project.py': {'bytes': 11363, 'sha256': '0966bdb3ab17677c3dd7d1baad78eb9f692aed8477e937eda8fc32980462698c', 'git_mode': '100644', 'git_blob': 'c6e5cbb3cd4b13a22e0e9f3896d70bc20774f762'}, 'support/governance/media-session-ci/test_ci.py': {'bytes': 12866, 'sha256': '8a7074c6e19086d435492c6a4d1a52e2e402cbb83e3cb0b902ddbbe0ff4b4d4b', 'git_mode': '100644', 'git_blob': 'fc9bf512d66c856eeb77c64defcbe82d99ffd94f'}, 'support/governance/media-session-positive/source-pins.json': {'bytes': 2644, 'sha256': '5d708748e4bf47af83ae97edac0fb9f37ac89d41ce11b1d7d371fb595e24d222', 'git_mode': '100644', 'git_blob': '2e0462c5a577106f10fd4c3a1d0dd6ff3f9a2a08'}, 'support/governance/media-session-positive/run.php': {'bytes': 7527, 'sha256': 'bd3504a2212eaedafae27bbde8f6ca824086f8573d0cec95871af35a3f012f81', 'git_mode': '100644', 'git_blob': 'a0dab8602cb1d76449a7472d8fee956ad72496a5'}, 'support/governance/media-session-ci/N05-source-rebind-plan.json': {'bytes': 4001, 'sha256': 'fa67a418e188f52d5ff7af605c002d85ec313ac0dd95d71fac196ef3615c43f8', 'git_mode': '100644', 'git_blob': 'a822da97a158333c8a3ffbdf1922021087d06580'}}

def _media_need(value):
 if not value:raise ValueError('independent media Git oracle refused')
def _media_git(root,*args,input=None):
 import os
 env={k:v for k,v in os.environ.items()if not k.startswith('GIT_')}
 env.update(GIT_CONFIG_NOSYSTEM='1',GIT_CONFIG_GLOBAL=os.devnull,GIT_CONFIG_COUNT='0',GIT_NO_REPLACE_OBJECTS='1',GIT_NO_LAZY_FETCH='1',GIT_TERMINAL_PROMPT='0',GIT_OPTIONAL_LOCKS='0',LC_ALL='C')
 command=['/usr/bin/git','--no-replace-objects','-c','core.hooksPath='+os.devnull,'-c','core.attributesFile='+os.devnull,'-c','core.fsmonitor=false','-c','protocol.allow=never','-C',str(root),*args]
 try:return subprocess.run(command,input=input,check=True,capture_output=True,env=env,timeout=60).stdout
 except (subprocess.CalledProcessError,subprocess.TimeoutExpired):raise ValueError('independent media Git oracle refused')from None
def _media_oid(kind,raw):return hashlib.sha1(kind.encode()+b' '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
def _media_batch(raw,requested,checked):
 _media_need(len(requested)==len(set(requested)));result={};offset=0
 for oid in requested:
  end=raw.find(b'\n',offset);_media_need(end>=offset)
  try:observed,kind,size=raw[offset:end].decode('ascii').split();size=int(size)
  except (ValueError,UnicodeError):raise ValueError('independent media Git oracle refused')from None
  _media_need(observed==oid and oid not in result and (kind,size)==checked[oid])
  start=end+1;finish=start+size;_media_need(0<=size<=16*1024*1024 and finish<len(raw)and raw[finish:finish+1]==b'\n')
  body=raw[start:finish];_media_need(_media_oid(kind,body)==oid);result[oid]=(kind,body);offset=finish+1
 _media_need(offset==len(raw));return result
def media_git_oracle(root,revision):
 import re
 _media_need(type(revision)is str and re.fullmatch('[a-f0-9]{40}',revision))
 commit=_media_git(root,'cat-file','commit',revision);_media_need(_media_oid('commit',commit)==revision)
 match=re.match(rb'tree ([a-f0-9]{40})\n',commit);_media_need(match is not None);tree=match[1].decode()
 raw=_media_git(root,'ls-tree','-r','-t','-z','--full-tree',revision);leaves={};objects={tree:'tree'}
 for record in raw.split(b'\0'):
  if not record:continue
  try:info,name=record.split(b'\t',1);mode,kind,oid=info.decode('ascii').split();path=name.decode('utf-8')
  except (ValueError,UnicodeError):raise ValueError('independent media Git oracle refused')from None
  _media_need(path and not path.startswith('/')and '\\'not in path and all(part not in ('','.','..','.git')for part in path.split('/'))and all(ord(c)>=32 and ord(c)!=127 for c in path))
  _media_need(re.fullmatch('[a-f0-9]{40}',oid)and kind in ('tree','blob'))
  _media_need((kind=='tree'and mode=='040000')or(kind=='blob'and mode in ('100644','100755','120000')))
  if oid in objects:_media_need(objects[oid]==kind)
  objects[oid]=kind
  if kind=='blob':_media_need(path not in leaves);leaves[path]=(mode,oid)
 _media_need(0<len(leaves)<=12000 and len(objects)<=16000)
 requested=sorted(objects);payload=('\n'.join(requested)+'\n').encode();check=_media_git(root,'cat-file','--batch-check=%(objectname) %(objecttype) %(objectsize)',input=payload).splitlines();_media_need(len(check)==len(requested));checked={};total=0
 for oid,row in zip(requested,check):
  try:found,kind,size=row.decode('ascii').split();size=int(size)
  except (ValueError,UnicodeError):raise ValueError('independent media Git oracle refused')from None
  _media_need(found==oid and kind==objects[oid]and 0<=size<=16*1024*1024);checked[oid]=(kind,size);total+=size
 _media_need(total<=128*1024*1024)
 data=_media_batch(_media_git(root,'cat-file','--batch',input=payload),requested,checked);entries={}
 for path,(mode,oid)in leaves.items():
  kind,body=data[oid];_media_need(kind=='blob')
  entries[path]=('symlink',body.decode('utf-8'),0o777)if mode=='120000'else('file',body,0o755 if mode=='100755'else 0o644)
 return {'commit':revision,'root_tree':tree,'entries':entries,'leaf_count':len(leaves),'objects_verified':len(objects),'signature_verified':False}
def media_protected_path(name):
 prefixes=('src','resources','support/runtime','support/governance','conf','webroot','externals')
 excepted=('support/governance/media-session-positive','support/governance/media-session-docker','support/governance/media-session-ci')
 return any(name==p or name.startswith(p+'/')for p in prefixes)and not any(name==p or name.startswith(p+'/')for p in excepted)
def media_legacy_seven(root):
 # Always test a whole historical cohort, never classify an unknown mixed input as old.
 data=media_git_oracle(root,_MEDIA_LEGACY_STAGE)
 return {p:data['entries'][p]for p in _MEDIA_LEGACY_SEVEN}

def legacy_exact_media_seven(candidate):
 _media_need(type(candidate)is dict and set(candidate)==set(_MEDIA_LEGACY_SEVEN_PINS))
 for path,pin in _MEDIA_LEGACY_SEVEN_PINS.items():
  value=candidate[path];_media_need(type(value)is tuple and len(value)==3)
  kind,raw,mode=value
  _media_need(kind=='file'and type(raw)is bytes and mode==0o644 and len(raw)==pin['bytes']and hashlib.sha256(raw).hexdigest()==pin['sha256']and _media_oid('blob',raw)==pin['git_blob'])
 return candidate

class SignedSourceBinding(unittest.TestCase):
 def git(self,*args):return subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),*args])
 def test_exact_literal_sites_restore_signed_source_bytes(self):
  for name in METADATA:
   raw=(ROOT/name).read_bytes();self.assertEqual(media_binding.reduce_current(name,raw),self.git('show',BASE+':'+name))
   with self.assertRaises(ValueError):media_binding.reduce_current(name,raw+b'\n# unknown edit')
  self.assertEqual(self.git('rev-parse',BASE+'^{tree}').decode().strip(),TREE)
  # Exact legacy branch remains tested on its complete real historical payload,
  # not on whichever future BASE the current snapshot happens to declare.
  legacy=legacy_exact_media_seven(media_legacy_seven(ROOT))
  for name in [PATH,'support/governance/media-session-ci/project.py','support/governance/media-session-docker/README.md']:
   text=legacy[name][1].decode('utf-8')
   self.assertEqual(text.count(_MEDIA_LEGACY_BASE),1);self.assertEqual(text.count(_MEDIA_LEGACY_TREE),1)
 def test_all_current_metadata_and_hook_inverses_restore_exact_signed_S1(self):
  self.assertEqual(len(media_binding.SOURCE_INPUTS),10)
  for name in media_binding.SOURCE_INPUTS:
   raw=(ROOT/name).read_bytes();self.assertEqual(media_binding.reduce_current(name,raw),self.git('show',BASE+':'+name))
   bad=raw+b'\n# unknown metadata edit'
   with self.assertRaises(ValueError):media_binding.reduce_current(name,bad)
   with patch.object(media_binding,'SOURCE_INPUTS',{**media_binding.SOURCE_INPUTS,name:hashlib.sha256(bad).hexdigest()}):
    with self.assertRaises(ValueError):media_binding.reduce_current(name,bad)
 def test_snapshot_changes_only_base_and_tree_not_any_object_or_IO_guard(self):
  current=reduce_stageB(PATH,(ROOT/PATH).read_bytes());old=self.git('show',BASE+':'+PATH)
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  now=overlay(current);before=overlay(old);restored=current.replace(BASE.encode(),b'2d3aa2db8a89185d81fbdb235f5a26c347da5551').replace(TREE.encode(),b'6a4fd9d008ce2fc16d2c259a1145e798bc20ae7e')
  for name in now:
   if now[name]!=before[name]:self.assertEqual(restored.count(now[name].encode()),1);restored=restored.replace(now[name].encode(),before[name].encode(),1)
  self.assertEqual(restored,old)
 def test_exact_nineteen_pins_from_signed_Git_blobs_and_unchanged_eight_overlay(self):
  rel='support/governance/media-session-positive/source-pins.json';raw=(ROOT/rel).read_bytes();pins=json.loads(raw);self.assertEqual(len(pins),19)
  self.assertEqual(hashlib.sha256(raw).hexdigest(),'5d708748e4bf47af83ae97edac0fb9f37ac89d41ce11b1d7d371fb595e24d222')
  previous=json.loads(self.git('show',BASE+':'+rel));self.assertEqual(set(pins),set(previous));self.assertEqual({n for n in pins if pins[n]!=previous[n]},{'support/governance/completion/fixture.php'})
  for name,pin in pins.items():
   actual=(ROOT/name).read_bytes();self.assertEqual(hashlib.sha256(actual).hexdigest(),pin)
   if name!='support/governance/completion/fixture.php':self.assertEqual(actual,self.git('show',BASE+':'+name))
   else:
    self.assertEqual(pin,'8c69fdbc292b6b42f7764c0258e1edb671190d97f9977cf6aee57eac9e5ae09b');self.assertEqual(actual.replace(b",\n      '20261110.project.contentgrants.sql');",b');'),self.git('show',BASE+':'+name))
  self.assertEqual(hashlib.sha256((ROOT/'resources/sql/autopatches/20261110.project.contentgrants.sql').read_bytes()).hexdigest(),'16f043e830f29b246efd12c22569e66bf0d2b629f4b9dbd062ab0f30a487d27e')
  def overlay(raw):return next(ast.literal_eval(n.value)for n in ast.parse(raw).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='OVERLAY'for t in n.targets))
  current=overlay((ROOT/PATH).read_bytes());self.assertEqual(len(current),8);before=overlay(self.git('show',BASE+':'+PATH));self.assertEqual(set(current),set(before));self.assertEqual({n for n in current if current[n]!=before[n]},{'support/governance/media-session-positive/source-pins.json','support/governance/media-session-positive/run.php'})
  for name,pin in current.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),pin)
 def test_current_raw_Git_inventory_has_independent_full_domain_provenance(self):
  spec=importlib.util.spec_from_file_location('partial_media_count_snapshot',ROOT/PATH);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
  # BASE is a declared input; expected tree/domain/bytes come from native Git.
  expected=media_git_oracle(ROOT,module.BASE)
  entries,provenance=module.base_entries(ROOT)
  self.assertEqual(module.ROOT_TREE,expected['root_tree']);self.assertEqual(entries,expected['entries'])
  self.assertEqual(provenance['root_tree'],expected['root_tree']);self.assertEqual(provenance['commit'],expected['commit'])
  self.assertEqual(set(module.OVERLAY),set(_MEDIA_OVERLAY_NAMES))
  self.assertEqual(len(set(entries)|set(module.OVERLAY)),len(set(expected['entries'])|set(_MEDIA_OVERLAY_NAMES)))
 def test_all_other_fixture_runtime_and_source_guards_byteexact_signed_source(self):
  paths=self.git('ls-tree','-r','--name-only',BASE,'--','support/governance/media-session-ci','support/governance/media-session-docker','support/governance/media-session-positive').decode().splitlines();self.assertGreaterEqual(len(paths),17)
  for name in paths:
   raw=(ROOT/name).read_bytes()
   if name in media_binding.SOURCE_INPUTS:raw=media_binding.reduce_current(name,raw)
   self.assertEqual(raw,self.git('show',BASE+':'+name),name)
 def test_previous_signed_recipe_literals_are_exact_test_only_and_unknown_refused(self):
  import project_content_grant_source_delta as n05
  m=n05.media_source_delta()
  self.assertEqual(set(m.PREVIOUS_RECIPE_BYTES),set(m.SOURCE_INPUTS))
  for path,raw in m.PREVIOUS_RECIPE_BYTES.items():
   actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','3e02336621298f5877e30e05690f57e9a499bdda:'+path])
   self.assertEqual(raw,actual)
   self.assertEqual(m.reduce_previous_recipe(path,raw),m.BASE_BYTES[path])
   with self.assertRaises(ValueError):m.reduce_previous_recipe(path,raw+b'unknown')
 def test_repair_signed_recipe_predecessor_is_exact_and_unknown_refused(self):
  import project_content_grant_source_delta as n05
  m=n05.media_source_delta();self.assertEqual(set(m.REPAIR_RECIPE_BYTES),set(m.SOURCE_INPUTS))
  for path,raw in m.REPAIR_RECIPE_BYTES.items():
   actual=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','5665cf16663b0e44da2aed5a800835dc9cb563cf:'+path]);self.assertEqual(raw,actual)
   self.assertEqual(m.reduce_repair_recipe(path,raw),m.BASE_BYTES[path])
   with self.assertRaises(ValueError):m.reduce_repair_recipe(path,raw+b'unknown')
if __name__=='__main__':unittest.main()


class IndependentMediaGitOracle(unittest.TestCase):
 """Raw Git/test-oracle controls only, not media/current-source qualification."""
 def test_actual_legacy_domain_and_exact_whole_seven_not_current_field_tautology(self):
  actual=media_git_oracle(ROOT,_MEDIA_LEGACY_BASE)
  self.assertEqual(actual['root_tree'],_MEDIA_LEGACY_TREE);self.assertEqual(actual['leaf_count'],9303)
  legacy=legacy_exact_media_seven(media_legacy_seven(ROOT));self.assertEqual(set(legacy),set(_MEDIA_LEGACY_SEVEN))
  for path,value in legacy.items():
   bad=dict(legacy);bad[path]=(value[0],value[1]+b'\n# unknown',value[2])
   with self.subTest(path=path),self.assertRaises(ValueError):legacy_exact_media_seven(bad)
  bad=dict(legacy);bad.pop(next(iter(bad)))
  with self.assertRaises(ValueError):legacy_exact_media_seven(bad)
  bad=dict(legacy);bad['support/governance/media-session-ci/unknown.py']=next(iter(legacy.values()))
  with self.assertRaises(ValueError):legacy_exact_media_seven(bad)
 def test_batch_parser_requires_order_typed_hash_complete_domain_and_no_duplicate(self):
  body=b'raw object';oid=_media_oid('blob',body);checked={oid:('blob',len(body))};raw=(oid+' blob '+str(len(body))+'\n').encode()+body+b'\n'
  self.assertEqual(_media_batch(raw,[oid],checked),{oid:('blob',body)})
  for bad in [raw+b'extra',raw[:-1],raw.replace(b' blob ',b' tree ',1),raw.replace(body,b'bad object'),b'0'*40+b' missing\n']:
   with self.assertRaises(ValueError):_media_batch(bad,[oid],checked)
  with self.assertRaises(ValueError):_media_batch(raw+raw,[oid,oid],checked)
 def test_real_tiny_Git_modes_symlink_attributes_and_unknown_object(self):
  import tempfile,os
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as directory:
   root=Path(directory);(root/'nested').mkdir();(root/'nested/data').write_bytes(b'raw\r\nbytes\x00');(root/'tool').write_bytes(b'never executed');(root/'tool').chmod(0o755);(root/'link').symlink_to('nested/data');(root/'.gitattributes').write_text('nested/data export-ignore\n')
   def git(*args):return subprocess.check_output(['/usr/bin/git','-c','core.hooksPath='+os.devnull,'-c','core.attributesFile='+os.devnull,'-c','commit.gpgsign=false','-C',str(root),*args],stderr=subprocess.DEVNULL).decode().strip()
   git('init','-q');git('-c','core.autocrlf=false','add','.');git('-c','user.name=Isolated Test','-c','user.email=isolated@example.invalid','commit','-q','-m','Raw Git oracle fixture')
   revision=git('rev-parse','HEAD');actual=media_git_oracle(root,revision)
   self.assertEqual(actual['root_tree'],git('rev-parse','HEAD^{tree}'));self.assertEqual(actual['leaf_count'],4)
   self.assertEqual(actual['entries']['nested/data'],('file',b'raw\r\nbytes\x00',0o644));self.assertEqual(actual['entries']['tool'][2],0o755);self.assertEqual(actual['entries']['link'],('symlink','nested/data',0o777))
   for wrong in ['0'*40,git('rev-parse','HEAD:nested/data')]:
    with self.assertRaises(ValueError):media_git_oracle(root,wrong)
 def test_fixed_protected_policy_keeps_registry_and_all_original_roots(self):
  self.assertTrue(media_protected_path('support/governance/source-operation/registry-v1.json'))
  self.assertTrue(media_protected_path('src/unchanged.php'))
  self.assertFalse(media_protected_path('support/governance/media-session-ci/project.py'))
