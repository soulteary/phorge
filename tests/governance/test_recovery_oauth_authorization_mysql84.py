"""Pure runtime qualification controls; no MySQL, Docker or application execution."""
import copy,hashlib,json,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts/governance'))
import qualify_oauth_authorization_mysql84 as qualifier
import check_recovery_oauth_authorization_producer_v1 as native
import test_recovery_oauth_authorization_sql as sql_controls
import test_native_organization as base
class MySQL84Qualification(unittest.TestCase):
 def observation(self,phase):return {'schema':'native-oauth-authorization-mysql-runtime-v1','phase':phase,'source_revision':'a'*40,'version':'8.4.13','server_uuid_sha256':'c'*64,'observed_service_image':qualifier.IMAGE}
 def receipt(self):
  d=base.template();labels=sorted(native.obligations());d.update(batch='native-oauth-authorization-producer-owner',scope='owned-native-oauth-authorization-producer-owner-no-permit',checks=labels,check_count=30,native=native.summary('a'*40,sql_controls.AuthorizationSQL().identities()),file_service_cleanup={'kind':'file','binary_sha256':'b'*64,'process_stopped':True,'private_storage_removed':True},successful_owned_file_service_startups=1,worker_cleanup={'private_directories_removed':4,'owned_processes_stopped':0,'cleanup_failures':0,'private_directories_remaining':0,'live_processes_after_cleanup':0,'workers_registered':4});return d
 def test_real_PHP_projection_requires_exact_version_shape_source_and_observed_image(self):
  code=r'''require$argv[1].'/scripts/governance/probe_oauth_authorization_mysql84.php';$in=json_decode(stream_get_contents(STDIN),true);$out=[];foreach($in as$x){try{$out[]=oauth_authorization_mysql84_projection($x['row'],$x['phase'],$x['revision'],$x['image']);}catch(Throwable$e){$out[]=false;}}echo json_encode($out);'''
  row={'server_uuid':'12345678-1234-1234-1234-123456789abc','version':'8.4.13'};good={'row':row,'phase':'before','revision':'a'*40,'image':qualifier.IMAGE};cases=[good]
  for key,value in [('version','8.0.44'),('version','8.4.12'),('version','CANARY_SECRET'),('version',True),('server_uuid','CANARY_SECRET'),('extra','CANARY_SECRET')]:
   c=copy.deepcopy(good);c['row'][key]=value;cases.append(c)
  for key,value in [('phase','unknown'),('revision','short'),('image','mysql:8.4.13')]:c=copy.deepcopy(good);c[key]=value;cases.append(c)
  p=subprocess.run(['php','-d','disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen','-r',code,str(ROOT)],input=json.dumps(cases),capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,0,p.stderr);v=json.loads(p.stdout);self.assertEqual(v[0]['version'],'8.4.13');self.assertEqual(v[0]['server_uuid_sha256'],hashlib.sha256(row['server_uuid'].encode()).hexdigest());self.assertEqual(v[1:],[False]*(len(cases)-1));self.assertNotIn('CANARY',p.stdout+p.stderr)
 def test_probe_invalid_input_refuses_before_native_connection_and_never_leaks(self):
  p=subprocess.run(['php','-d','disable_functions=mysqli_init','scripts/governance/probe_oauth_authorization_mysql84.php','CANARY_SECRET','a'*40],cwd=ROOT,capture_output=True,text=True,timeout=10);self.assertEqual(p.returncode,1);self.assertEqual(p.stdout,'');self.assertEqual(json.loads(p.stderr),{'schema':'native-oauth-authorization-mysql-runtime-failure-v1','reason':'arguments'});self.assertNotIn('CANARY',p.stderr)
 def test_valid_runtime_and_actual_strict_native_consumer_required(self):
  out=qualifier.qualify(self.receipt(),self.observation('before'),self.observation('after'),revision='a'*40,file_digest='b'*64);self.assertEqual(out['SQL_obligations'],20);self.assertEqual(out['database_runtime']['mysql_version'],'8.4.13');self.assertFalse(out['database_runtime']['whole_window_or_future_immutability_proved'])
  for name in ['version','image','source','UUID','phase','missing','extra','native-check','already-accepted']:
   before=self.observation('before');after=self.observation('after');data=self.receipt()
   if name=='version':before['version']='8.0.44'
   elif name=='image':after['observed_service_image']='mysql:8.4.13'
   elif name=='source':after['source_revision']='d'*40
   elif name=='UUID':after['server_uuid_sha256']='d'*64
   elif name=='phase':before['phase']='after'
   elif name=='missing':del before['version']
   elif name=='extra':before['token']='CANARY_SECRET'
   elif name=='native-check':data['checks'].pop()
   else:data={'passed':True,'SQL_obligations':20}
   with self.assertRaises((ValueError,KeyError)):qualifier.qualify(data,before,after,revision='a'*40,file_digest='b'*64)
 def test_duplicate_or_nonfinite_runtime_JSON_refuses(self):
  for raw in ['{"version":"8.4.13","version":"8.4.13"}','{"n":NaN}']:
   with self.assertRaises(ValueError):qualifier.decode(raw)
 def test_workflow_pins_actual_baseline_and_brackets_native_SQL_with_real_probe(self):
  import yaml
  s=(ROOT/'.github/workflows/oauth-authorization-producer-focused.yml').read_text();d=yaml.safe_load(s);self.assertEqual(d['jobs']['oauth-authorization-owner']['services']['mysql']['image'],qualifier.IMAGE)
  before=s.index('probe_oauth_authorization_mysql84.php before');native_phase=s.index('test-oauth-authorization-owner-mysql.php --oauth-authorization-producer-owner');after=s.index('probe_oauth_authorization_mysql84.php after');qualified=s.index('qualify_oauth_authorization_mysql84.py --runtime-before');self.assertLess(before,native_phase);self.assertLess(native_phase,after);self.assertLess(after,qualified);self.assertEqual(s.count('docker inspect "$MYSQL_CONTAINER" --format'),2)
  probe=(ROOT/'scripts/governance/probe_oauth_authorization_mysql84.php').read_text();self.assertIn("'SELECT VERSION() AS version,@@server_uuid AS server_uuid'",probe);self.assertIn("real_connect('127.0.0.1','root',$password,null,3306)",probe);self.assertNotIn('file_get_contents(',probe)
if __name__=='__main__':unittest.main()
