"""Owned external anchor and real PHP consumer controls; no VM/build/SQL proof."""
import copy,hashlib,json,os,secrets,shutil,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'tests/governance/native_rr_ci'))
import anchor as a
import provenance as p
import runner as r
from test_native_rr_ci_provenance import info,REV
SOURCES={path:p.digest(path.encode())for path in sorted(p.OWNER_FILES+p.RECIPE_FILES)}
IDENTITY={'source_revision':REV,'run_id':'12','run_attempt':'1','source_kind':'pull-request-synthetic-merge'}
def build_bundle(path):
 path.mkdir();binary=b'synthetic-binary';raw=json.dumps(info()).encode();record={'schema_version':1,'PHP_executed':False,'SQL_executed':False,'go_binary_sha256':p.digest(b'go'),'source_revision':REV,'run_id':'12','run_attempt':'1','source_kind':'pull-request-synthetic-merge','PR_head_measured':False,'profile':a.PROFILE,'source_sha256':SOURCES,'source_clean_before_after':True,'build_info_verified':True,'build_info_sha256':p.digest(raw),'binary_sha256':p.digest(binary),'descriptor_is_execution_authority':False,'external_trusted_anchor_established':False}
 (path/'native-rr-process-owner').write_bytes(binary);(path/'build-info.json').write_bytes(raw);(path/'build-record.json').write_text(json.dumps(record));return record
class Anchor(unittest.TestCase):
 def fixture(self):
  owner=secrets.token_hex(16);directory=Path('/tmp')/('governance-native-rr-ci-'+owner);lease=a.create_seed(directory,IDENTITY,SOURCES,owner);self.addCleanup(lambda:shutil.rmtree(directory)if directory.exists()else None)
  tmp=tempfile.TemporaryDirectory();self.addCleanup(tmp.cleanup);build=Path(tmp.name)/'build';build_bundle(build)
  control=a.close_anchor(lease,build,info(),b'php',b'parser',SOURCES,b'go')
  return directory,lease,build,control
 def test_actual_seed_is_private_exclusive_external_control(self):
  directory,lease,build,control=self.fixture();self.assertEqual(0o700,directory.stat().st_mode&0o777);self.assertEqual(0o400,(directory/'seed.json').stat().st_mode&0o777);self.assertEqual(p.digest((directory/'anchor.json').read_bytes()),control['anchor_sha256'])
  with self.assertRaises(FileExistsError):a.close_anchor(lease,build,info(),b'php',b'parser',SOURCES,b'go')
 def test_changed_actual_binary_buildinfo_source_and_authority_refused(self):
  for change in ('binary','info','source','authority','run'):
   owner=secrets.token_hex(16);directory=Path('/tmp')/('governance-native-rr-ci-'+owner);lease=a.create_seed(directory,IDENTITY,SOURCES,owner)
   try:
    with tempfile.TemporaryDirectory()as tmp:
     build=Path(tmp)/'build';record=build_bundle(build);actual=info();sources=dict(SOURCES)
     if change=='binary':(build/'native-rr-process-owner').write_bytes(b'foreign')
     elif change=='info':actual['GoVersion']='go1.27.1'
     elif change=='source':sources.pop(next(iter(sources)))
     elif change=='authority':record['descriptor_is_execution_authority']=True;(build/'build-record.json').write_text(json.dumps(record))
     else:record['run_id']='13';(build/'build-record.json').write_text(json.dumps(record))
     with self.subTest(change=change),self.assertRaises(ValueError):a.close_anchor(lease,build,actual,b'php',b'parser',sources,b'go')
     self.assertFalse((directory/'anchor.json').exists())
   finally:shutil.rmtree(directory)
 def php(self,data):
  code='require $argv[1];$v=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);try{$r=GovernanceNativeRRCIProfile::verifyData($v["control"],$v["anchor"],$v["seed"],$v["sources"],$v["binary"],$v["php"],$v["parser"]);echo json_encode($r);}catch(Throwable $e){echo json_encode(array("refused"=>true));exit(3);}'
  return subprocess.run(['php','-r',code,str(ROOT/'support/governance/recovery/native-writer-rr-ci-profile.php')],input=json.dumps(data),capture_output=True,text=True)
 def test_real_PHP_consumer_requires_external_pin_and_all_sources(self):
  directory,lease,build,control=self.fixture();data={'control':control,'anchor':(directory/'anchor.json').read_text(),'seed':(directory/'seed.json').read_text(),'sources':SOURCES,'binary':'synthetic-binary','php':'php','parser':'parser'}
  result=self.php(data);self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('',result.stderr);self.assertFalse(json.loads(result.stdout)['production_authority'])
  for change in ('missing-control','wrong-external-pin','wrong-run','partial-source','binary','php','parser','unknown-profile','self-pin'):
   bad=copy.deepcopy(data)
   if change=='missing-control':bad['control']={}
   elif change=='wrong-external-pin':bad['control']['anchor_sha256']='0'*64
   elif change=='wrong-run':bad['control']['run_id']='13'
   elif change=='partial-source':bad['sources']={}
   elif change in ('binary','php','parser'):bad[change]='foreign'
   elif change=='unknown-profile':bad['control']['profile']='caller-selected'
   else:
    anchor=json.loads(bad['anchor']);anchor['binary_sha256']=p.digest(b'foreign');bad['anchor']=a.canonical(anchor).decode();bad['binary']='foreign' # independent control intentionally unchanged
   result=self.php(bad)
   with self.subTest(change=change):self.assertEqual(3,result.returncode,result.stderr);self.assertEqual({'refused':True},json.loads(result.stdout))
  # Exercise the actual private caller predicate in PHP, without minting a
  # profile or fabricating the fixed Linux binary/source paths on this host.
  script='require $argv[1];$v=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);try{if($v["open"]){GovernanceNativeRRCIProfile::open($v["control"]);}else{(new ReflectionMethod(GovernanceNativeRRCIProfile::class,"requireCaller"))->invoke(null,$v["control"]);}echo json_encode(array("caller_accepted"=>true,"profile_opened"=>false));}catch(Throwable $e){echo json_encode(array("refused"=>true,"reason"=>$e->getMessage()));exit(3);}'
  release_control=dict(control,source_kind='workflow-dispatch-checkout',directory='/not-a-profile')
  ref='refs/tags/2026.11.19-r3'
  release_env={'GITHUB_JOB':'owner-build','GITHUB_RUN_ID':'12','GITHUB_RUN_ATTEMPT':'1',
   'GITHUB_REPOSITORY':'soulteary/phorge','GITHUB_EVENT_NAME':'workflow_dispatch','GITHUB_REF':ref,
   'GITHUB_WORKFLOW_REF':'soulteary/phorge/.github/workflows/container-image.yml@'+ref,
   'GITHUB_SHA':REV,'GITHUB_WORKFLOW_SHA':REV,'NATIVE_RR_WORKFLOW_SHA':REV,
   'NATIVE_RR_EXPECTED_HEAD':REV,'NATIVE_RR_RELEASE_TAG':'2026.11.19-r3'}
  base_env={k:v for k,v in os.environ.items()if not k.startswith(('GITHUB_','NATIVE_RR_','PHORGE_GOVERNANCE_'))}
  def caller(env,ctl=release_control,opened=False):
   actual=dict(base_env,**env)
   if opened:actual.update(GITHUB_ACTIONS='true',PHORGE_GOVERNANCE_NATIVE_RR_EXECUTION_PROFILE=a.PROFILE,PHORGE_GOVERNANCE_ISOLATED_TEST='1',PHORGE_GOVERNANCE_MEASUREMENT_SOURCE_REVISION=REV)
   out=subprocess.run(['php','-r',script,str(ROOT/'support/governance/recovery/native-writer-rr-ci-profile.php')],input=json.dumps({'control':ctl,'open':opened}),env=actual,capture_output=True,text=True,timeout=5)
   self.assertEqual('',out.stderr);return out,json.loads(out.stdout)
  def accepted(env,ctl=release_control):
   out,result=caller(env,ctl);self.assertEqual(out.returncode,0,result);self.assertEqual(result,{'caller_accepted':True,'profile_opened':False})
  accepted(release_env)
  out,result=caller(release_env,opened=True)
  self.assertEqual(out.returncode,3);self.assertEqual(result,{'refused':True,'reason':'native_rr_CI_directory_refused'})
  for event,kind,direct_ref in [('pull_request','pull-request-synthetic-merge','refs/pull/12/merge'),('workflow_dispatch','workflow-dispatch-checkout','refs/heads/main'),('workflow_dispatch','workflow-dispatch-checkout','refs/tags/2026.11.19-r3')]:
   direct={'GITHUB_JOB':'owner-build','GITHUB_RUN_ID':'12','GITHUB_RUN_ATTEMPT':'1','GITHUB_EVENT_NAME':event,'GITHUB_REF':direct_ref,'GITHUB_WORKFLOW_REF':'soulteary/phorge/.github/workflows/native-rr-owner-build.yml@'+direct_ref}
   accepted(direct,dict(release_control,source_kind=kind))  # No new release vars required.
   bad=dict(direct,GITHUB_EVENT_NAME='push');out,result=caller(bad,dict(release_control,source_kind=kind))
   self.assertEqual(out.returncode,3);self.assertEqual(result['reason'],'native_rr_CI_event_unknown')
  for key in release_env:
   for value in ('foreign',''):
    env=dict(release_env);env[key]=value
    with self.subTest(caller_key=key,value=value):
     out,result=caller(env);self.assertEqual(out.returncode,3);self.assertTrue(result['refused'])
   env=dict(release_env);env.pop(key)
   with self.subTest(missing=key):out,result=caller(env);self.assertEqual(out.returncode,3)
  for key,value in [('source_revision','b'*40),('source_kind','pull-request-synthetic-merge'),('source_kind','unknown'),('run_id','13'),('run_attempt','2')]:
   with self.subTest(control=key):out,result=caller(release_env,dict(release_control,**{key:value}));self.assertEqual(out.returncode,3)
  for tag in ('2026.02.29-r1','2026.04.31-r1','2026.13.01-r1','2026.11.19-r0','2026.11.19-r01','2026.11.19-r1/other','2026.11.19-r1\n','2026.11.19-R1','latest'):
   bad=dict(release_env,NATIVE_RR_RELEASE_TAG=tag,GITHUB_REF='refs/tags/'+tag,GITHUB_WORKFLOW_REF='soulteary/phorge/.github/workflows/container-image.yml@refs/tags/'+tag)
   with self.subTest(tag=tag):out,result=caller(bad);self.assertEqual(out.returncode,3)
  for tag in ('2024.02.29-r1','2026.11.19-r1','2026.11.19-r123'):
   accepted(dict(release_env,NATIVE_RR_RELEASE_TAG=tag,GITHUB_REF='refs/tags/'+tag,GITHUB_WORKFLOW_REF='soulteary/phorge/.github/workflows/container-image.yml@refs/tags/'+tag))
  for workflow in ('other.yml','release-full.yml','container-image.yml.extra'):
   out,result=caller(dict(release_env,GITHUB_WORKFLOW_REF='soulteary/phorge/.github/workflows/'+workflow+'@'+ref));self.assertEqual(out.returncode,3)
  for field in ('run_id','run_attempt'):
   env=dict(release_env);env['GITHUB_RUN_ID'if field=='run_id'else'GITHUB_RUN_ATTEMPT']='0'
   out,result=caller(env,dict(release_control,**{field:'0'}));self.assertEqual(out.returncode,3)
 def test_unknown_owned_directory_retained_and_SQL_unpinned(self):
  with tempfile.TemporaryDirectory()as tmp:
   directory=Path(tmp)/'owned';directory.mkdir(mode=0o700);identity=directory.stat();(directory/'unknown').write_text('keep')
   with self.assertRaisesRegex(RuntimeError,'retained'):r.remove_owned_directory(directory,identity,('seed.json','anchor.json'))
   self.assertTrue((directory/'unknown').exists())
  with patch.dict(os.environ,{},clear=True),patch.object(r,'command',return_value=(REV+'\n').encode())as command:
   with self.assertRaisesRegex(ValueError,'fixed hosted CI identity'):r.main(['--go','/unknown/go','--execute-sql'])
   command.assert_called_once_with(['git','-C',str(r.ROOT),'rev-parse','HEAD'])
if __name__=='__main__':unittest.main()
