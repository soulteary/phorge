"""Execute actual CI phase shells with owned files and fixed process collaborators.
No database, Docker, native fixture or binary execution is performed.
"""
from pathlib import Path
import copy,json,os,re,shlex,shutil,subprocess,sys,tempfile,textwrap,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_oauth_access_failure as projector
import test_recovery_oauth_access_sql as fixture
ATOMIC=ROOT/'.github/workflows/atomic-feature-regressions.yml'
FOCUSED=ROOT/'.github/workflows/oauth-access-producer-focused.yml'
def phase(which):
 text=(ATOMIC if which=='atomic'else FOCUSED).read_text()
 if which=='atomic':text=text.split('# BEGIN isolated OAuth access-token SQL8',1)[1]
 else:text=text.split('      - name: Run only actual native OAuth',1)[1]
 return textwrap.dedent(text.split('        run: |\n',1)[1].split('      - uses:',1)[0])
def historical_atomic_source(root=ROOT):
 import project_description_admission_delta as current
 root=Path(root).resolve();current.assert_current_recipe(root)
 path='.github/workflows/atomic-feature-regressions.yml'
 if path not in current.CURRENT_METADATA:raise ValueError('Atomic workflow current metadata not admitted')
 raw=current.description.checked_bytes(root/path)
 previous=current._normalize_metadata(path,raw)
 expected=subprocess.check_output(['git','--no-replace-objects','-C',str(root),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path])
 if previous!=expected:raise ValueError('unknown Atomic workflow historical stage')
 return previous.decode('utf-8')
class OAuthCI(unittest.TestCase):
 def test_fixed_20b_Atomic_stage_still_reconstructs_exact_e804_workflow(self):
  path='.github/workflows/atomic-feature-regressions.yml'
  raw=subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','20b138b4cd9a2cf61522613b4a8e5e21eca7341b:'+path]).decode()
  prior=re.sub(r'      # BEGIN isolated OAuth access-token SQL8[^\n]*\n.*?      # END isolated OAuth access-token SQL8\.\n','',raw,flags=re.S)
  self.assertEqual(prior.encode(),subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show','e804125e8ad0b716cbbbba59818fb140691bf4f0:'+path]))
 def test_Atomic_removing_new_block_recovers_original_workflow_and_Conduit_workflow_unchanged(self):
  s=historical_atomic_source();s=re.sub(r'      # BEGIN isolated OAuth access-token SQL8[^\n]*\n.*?      # END isolated OAuth access-token SQL8\.\n','',s,flags=re.S)
  self.assertEqual(s.encode(),subprocess.check_output(['git','-C',str(ROOT),'show','e804125e8ad0b716cbbbba59818fb140691bf4f0:.github/workflows/atomic-feature-regressions.yml']))
  path='.github/workflows/conduit-producer-focused.yml';self.assertEqual((ROOT/path).read_bytes(),subprocess.check_output(['git','-C',str(ROOT),'show','e804125e8ad0b716cbbbba59818fb140691bf4f0:'+path]))
 def test_actual_all_new_Bash_blocks_syntax_and_owned_artifact_conditions(self):
  for p in [ATOMIC,FOCUSED]:
   s=p.read_text();blocks=re.findall(r'        run: \|\n((?:(?:          .*|)\n)*)',s)
   for block in blocks:
    r=subprocess.run(['bash','-n'],input=textwrap.dedent(block),capture_output=True,text=True);self.assertEqual(r.returncode,0,r.stderr)
  self.assertIn('if: success()',FOCUSED.read_text());self.assertNotIn('if: always()',FOCUSED.read_text());self.assertIn("failure() && steps.oauth-access-owner.outputs.diagnostic_owned == 'true'",FOCUSED.read_text())
 def execute(self,which,mode):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   t=Path(tmp);bin=t/'tools';bin.mkdir();data=t/'runtime';data.mkdir();output=data/('governance-oauth-access-producer-owner'if which=='atomic'else'governance-oauth-access-focused');failure=data/('governance-oauth-access-producer-failure'if which=='atomic'else'oauth-access-focused-failure')
   if which=='focused':output.mkdir()
   if mode=='foreign-failure':failure.mkdir();(failure/'foreign').write_text('preserve')
   file=data/'gorge-file-storage'if which=='atomic'else data/'oauth-access-focused-binary/gorge-file-storage';file.parent.mkdir(exist_ok=True);file.write_bytes(b'File DATA only, never executed');file.chmod(0o700)
   for n in ['governance-batch-1/native.json','governance-native-organization/accepted.json']:(data/n).parent.mkdir(exist_ok=True);(data/n).write_text('{}')
   maps=t/'maps';maps.write_text(''.join(__import__('hashlib').sha256((ROOT/n).read_bytes()).hexdigest()+'  '+str(ROOT/n)+'\n'for n in ['src/__phutil_library_map__.php','support/runtime/src/__phutil_library_map__.php','support/runtime/manifest.json']))
   if which=='focused':shutil.copyfile(maps,output/'verified-maps.sha256')
   revision=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD']).decode().strip();receipt=fixture.OAuthAccessWiring().template(__import__('hashlib').sha256(file.read_bytes()).hexdigest());receipt['native']=fixture.gate.summary(revision)
   failed={'batch':'native-oauth-access-producer-owner','passed':False,'failure':{'stage':'focused-native-oauth-access-producer-owner','error_type':'RuntimeException','source_file':'oauth-access-producer.php','source_line':17,'message':'PRIVATE_TOKEN_PASSWORD_SQL','token':'PRIVATE_TOKEN_PASSWORD_SQL'}}
   raw=t/'native.json';raw.write_text(json.dumps(receipt if mode=='success'else failed))
   if mode=='stderr-only':raw.write_text('')
   early=json.dumps({'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-oauth-access-producer-owner','error_type':'RuntimeException','source_file':'oauth-access-cases.php','source_line':29,'password':'PRIVATE_TOKEN_PASSWORD_SQL'})
   php=shutil.which('php');py=shutil.which('python3')
   wrappers={'php':'if [[ "$1" == "support/governance/native-organization/test-oauth-access-owner-mysql.php" ]]; then cat '+shlex.quote(str(raw))+"; printf '%s\\n' "+shlex.quote(early if mode=='stderr-only'else'PRIVATE_TOKEN_PASSWORD_SQL')+" >&2; exit "+('0'if mode=='success'else'37')+'; fi\nexec '+shlex.quote(php)+' "$@"','python3':('if [[ "$2" == "scripts/governance/project_oauth_access_failure.py" ]]; then exit 41; fi\n'if mode=='projector-failure'else'')+'exec '+shlex.quote(py)+' "$@"'}
   if mode=='cat-failure':wrappers['cat']='exit 52'
   for n,body in wrappers.items():p=bin/n;p.write_text('#!/bin/bash\n'+body+'\n');p.chmod(0o700)
   marker=t/'github-output'
   if mode=='marker-failure':marker.mkdir()
   env={**os.environ,'PATH':str(bin)+':'+os.environ['PATH'],'RUNNER_TEMP':str(data),'GITHUB_SHA':revision,'GITHUB_OUTPUT':str(marker),'GORGE_TEST_MYSQL_PASSWORD':'synthetic-disposable-only'}
   body=phase(which).replace('/tmp/atomic-maps.sha256',str(maps));r=subprocess.run(['bash','-euo','pipefail','-c',body],cwd=ROOT,env=env,capture_output=True,text=True,timeout=15)
   accepted=(output/'accepted.json').exists();pending=(output/'accepted.pending.json').exists();safe=failure/'diagnostic.json';owned=marker.is_file()and'diagnostic_owned=true'in marker.read_text();result={'rc':r.returncode,'accepted':accepted,'pending':pending,'owned':owned,'safe':json.loads(safe.read_bytes())if safe.exists()else None,'stderr':r.stderr,'foreign':(failure/'foreign').read_text()if(failure/'foreign').exists()else None};return result
 def test_actual_phase_success_strict_consumer_and_pending_atomic_publication(self):
  for which in ['atomic','focused']:
   d=self.execute(which,'success');self.assertEqual(d['rc'],0,d['stderr']);self.assertTrue(d['accepted']);self.assertFalse(d['pending']or d['owned'])
 def test_actual_failure_projector_marker_cat_and_foreign_directory_preserve_native37(self):
  for which in ['atomic','focused']:
   for mode in ['native-failure','projector-failure','marker-failure','cat-failure','foreign-failure','stderr-only']:
    d=self.execute(which,mode);self.assertEqual(d['rc'],37,(which,mode,d));self.assertFalse(d['accepted']);self.assertNotIn('PRIVATE_TOKEN_PASSWORD_SQL',d['stderr'])
    if mode in ['projector-failure','marker-failure','foreign-failure']:self.assertFalse(d['owned'])
    if mode=='foreign-failure':self.assertEqual(d['foreign'],'preserve')
    if mode in ['native-failure','stderr-only']:self.assertEqual(d['safe']['scope'],'native-oauth-access-producer-failure-v1');self.assertTrue(d['owned'])
class SafeOAuthProjection(unittest.TestCase):
 def test_real_pinned_PHP_projection_drops_credentials_query_and_unknown_input(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp)/'receipt';p.touch(mode=0o600)
   p.write_text(json.dumps({'batch':'native-oauth-access-producer-owner','passed':False,'failure':{'error_type':'RuntimeException','source_file':'oauth-access-cases.php','source_line':19,'message':'PRIVATE SQL TOKEN','password':'PRIVATE SQL TOKEN'}}));v=projector.project(p);self.assertTrue(v['diagnostic_available']);self.assertNotIn('PRIVATE',json.dumps(v));self.assertEqual(v['scope'],'native-oauth-access-producer-failure-v1')
   for raw in ['{"passed":true}', '{"passed":false,"passed":false}', 'PRIVATE INVALID']:
    p.write_text(raw);self.assertFalse(projector.project(p)['diagnostic_available'])
 def test_actual_private_stderr_only_fixed_early_protocol_is_reprojected(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   receipt=Path(tmp)/'receipt';receipt.touch(mode=0o600);err=Path(tmp)/'native.stderr.private';err.touch(mode=0o600)
   early={'scope':'native-conduit-producer-failure-v1','passed':False,'stage':'focused-native-oauth-access-producer-owner','error_type':'RuntimeException','source_file':'oauth-access-cases.php','source_line':31,'password':'PRIVATE SQL TOKEN'}
   err.write_text('PRIVATE raw fatal text\n'+json.dumps(early)+'\n');v=projector.project(receipt,err);self.assertTrue(v['diagnostic_available']);self.assertEqual(v['source_line'],31);self.assertNotIn('PRIVATE',json.dumps(v))
   receipt.write_text(json.dumps({'passed':True}));self.assertFalse(projector.project(receipt,err)['diagnostic_available']);receipt.write_text('')
   for raw in ['PRIVATE unknown',json.dumps(dict(early,stage='focused-native-conduit-producer-owner')),'X'*65537]:
    err.write_text(raw);self.assertFalse(projector.project(receipt,err)['diagnostic_available'])
 def test_changed_PHP_or_bad_projected_scope_never_returns_untrusted_bytes(self):
  with tempfile.TemporaryDirectory(dir=Path(tempfile.gettempdir()).resolve())as tmp:
   p=Path(tmp)/'receipt';p.touch(mode=0o600);p.write_text(json.dumps({'batch':'native-oauth-access-producer-owner','passed':False,'failure':{}}))
   with patch.object(projector.gate,'read_source_checked',return_value=b'<?php\necho "PRIVATE";'),patch.object(projector.subprocess,'run',side_effect=AssertionError('untrusted PHP')):self.assertFalse(projector.project(p)['diagnostic_available'])
   with patch.object(projector.subprocess,'run',return_value=__import__('types').SimpleNamespace(returncode=0,stderr='',stdout='{"scope":"PRIVATE"}')):self.assertFalse(projector.project(p)['diagnostic_available'])
if __name__=='__main__':unittest.main()
