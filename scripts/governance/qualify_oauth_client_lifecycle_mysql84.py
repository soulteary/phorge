"""Qualify raw lifecycle52 evidence plus two real fixed-endpoint MySQL observations.
JSON checking is not independent execution/cryptographic provenance proof.
"""
import argparse,hashlib,json,re,subprocess
from pathlib import Path
import check_recovery_oauth_client_lifecycle as native
import check_recovery_partial_composition as shared
import check_completion as completion
IMAGE='mysql:8.4.13@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
FIELDS={'schema','phase','source_revision','version','server_uuid_sha256','observed_service_image'}
def runtime(before,after,revision):
 for phase,row in [('before',before),('after',after)]:
  if type(row)is not dict or set(row)!=FIELDS:raise ValueError('runtime shape')
  for name,value in {'schema':'native-oauth-authorization-mysql-runtime-v1','phase':phase,'source_revision':revision,'version':'8.4.13','observed_service_image':IMAGE}.items():
   completion.exact(row[name],value,'runtime prerequisite refused')
  if type(row['server_uuid_sha256'])is not str or re.fullmatch('[a-f0-9]{64}',row['server_uuid_sha256'])is None:raise ValueError('runtime UUID refused')
 if before['server_uuid_sha256']!=after['server_uuid_sha256']:raise ValueError('runtime changed')
 return {'mysql_version':'8.4.13','observed_service_image':IMAGE,'same_uuid_at_two_observations':True,'whole_window_or_future_immutability_proved':False}
def decode(raw):return json.loads(raw,object_pairs_hook=completion.strict_object,parse_constant=lambda _:(_ for _ in()).throw(ValueError('nonfinite')))
def qualify(receipt,before,after,*,revision,file_digest,root=native.ROOT):
 # Never trust a caller's already-accepted summary instead of the raw receipt.
 observed=runtime(before,after,revision)
 accepted=native.validate(receipt,root,source_revision=revision,file_digest=file_digest)
 return {**accepted,'database_runtime':observed}
def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--runtime-before',type=Path,required=True);p.add_argument('--runtime-after',type=Path,required=True);p.add_argument('--source-revision',required=True);p.add_argument('--file-binary',type=Path,required=True);p.add_argument('--file-binary-sha256',required=True);a=p.parse_args()
 try:
  if re.fullmatch('[a-f0-9]{40}',a.source_revision)is None or subprocess.check_output(['git','--no-replace-objects','-C',str(native.ROOT),'rev-parse','HEAD'],text=True).strip()!=a.source_revision:raise ValueError('source refused')
  digest=shared.read_checked(a.file_binary,binary=True)
  if digest!=a.file_binary_sha256 or re.fullmatch('[a-f0-9]{64}',a.file_binary_sha256)is None:raise ValueError('binary refused')
  raw={key:shared.read_checked(path)for key,path in [('receipt',a.receipt),('before',a.runtime_before),('after',a.runtime_after)]}
  result=qualify(decode(raw['receipt']),decode(raw['before']),decode(raw['after']),revision=a.source_revision,file_digest=digest)
  result['runtime_observation_sha256']={key:hashlib.sha256(raw[key]).hexdigest()for key in ['before','after']}
  print(json.dumps(result,sort_keys=True));return 0
 except Exception:
  print('{"passed":false,"scope":"native-oauth-client-lifecycle-mysql84-qualification-refused"}');return 1
if __name__=='__main__':raise SystemExit(main())
