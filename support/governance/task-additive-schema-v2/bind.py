"""Root one-literal private producer binding. Never executes PHP/SQL or edits source."""
import argparse,hashlib,importlib.util,json,os,stat
from pathlib import Path
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('Task_schema_private_binder_validator',HERE/'validate.py');gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
def bind(profile_path,expected_profile_sha256,output):
 p=gate.profile(profile_path,expected_profile_sha256);root=Path(p['source_root']);raw=(root/'support/governance/task-additive-schema-v2/capture.php').read_bytes()
 marker=b'\nconst N05_TASK_ADDITIVE_PROFILE_SHA256=null;\n';gate.need(raw.count(marker)==1)
 bound=raw.replace(marker,("\nconst N05_TASK_ADDITIVE_PROFILE_SHA256='"+expected_profile_sha256+"';\n").encode(),1)
 out=Path(output);gate.need(out.is_absolute()and out.parent.resolve()==out.parent and not out.exists()and not out.is_symlink());out.mkdir(mode=0o700)
 def put(path,value):
  fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
  with os.fdopen(fd,'wb')as f:f.write(value);f.flush();os.fsync(f.fileno())
 put(out/'capture-bound.php',bound);receipt={'schema':'Task-additive-schema-private-producer-binding-v1','profile_sha256':expected_profile_sha256,'source_template_sha256':hashlib.sha256(raw).hexdigest(),'bound_producer_sha256':hashlib.sha256(bound).hexdigest(),'changed_literals':1,'source_modified':False,'SQL_executed':False,'PHP_executed':False,'private_profile_secret_exported':False};put(out/'binding.json',(json.dumps(receipt,sort_keys=True)+'\n').encode());return receipt
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--profile',required=True);parser.add_argument('--expected-profile-sha256',required=True);parser.add_argument('--output',required=True);a=parser.parse_args()
 try:print(json.dumps(bind(a.profile,a.expected_profile_sha256,a.output),sort_keys=True))
 except Exception:print('{"Task_schema_bound":false,"SQL_executed":false,"PHP_executed":false}');raise SystemExit(1)
