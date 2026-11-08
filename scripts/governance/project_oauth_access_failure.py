"""Failure-only bounded metadata from captured pinned PHP. Never print raw receipt."""
import argparse,hashlib,json,subprocess
from pathlib import Path
import check_recovery_oauth_access_producer_v3 as gate
import check_recovery_partial_composition as shared
import check_completion as completion

def project(path,stderr=None):
 out={'scope':'native-oauth-access-producer-diagnostic-unavailable-v1','passed':False,'diagnostic_available':False}
 try:
  value=None;data=None
  try:data=json.loads(shared.read_checked(path),object_pairs_hook=completion.strict_object,parse_constant=lambda _:(_ for _ in()).throw(ValueError('nonfinite')))
  except (ValueError,TypeError,OSError,UnicodeError,RecursionError):pass
  if data is not None:
   if type(data)is not dict or data.get('passed')is not False or type(data.get('failure'))is not dict or data.get('batch')!='native-oauth-access-producer-owner':return out
   value=data['failure'].get('oauth_access_diagnostic',data['failure'])
  elif stderr is not None:
   # Private native stderr is never printed/uploaded. Only a fixed early JSON
   # protocol line may be reprojected when the ordinary receipt was not written.
   raw=shared.read_checked(stderr)
   if len(raw)>65536:return out
   for line in raw.splitlines()[:128]:
    if len(line)>16384:continue
    try:row=json.loads(line,object_pairs_hook=completion.strict_object,parse_constant=lambda _:(_ for _ in()).throw(ValueError('nonfinite')))
    except (ValueError,TypeError,UnicodeError,RecursionError):continue
    if type(row)is dict and row.get('scope')=='native-conduit-producer-failure-v1'and row.get('passed')is False and row.get('stage')=='focused-native-oauth-access-producer-owner':
     value=row;break
  if value is None:return out
  relative='support/governance/recovery/oauth-access-diagnostic.php';source=gate.read_source_checked(gate.ROOT/relative)
  if hashlib.sha256(source).hexdigest()!=gate.SOURCE_INPUTS[relative]or not source.startswith(b'<?php\n'):raise ValueError('fixed projector required')
  code=source[6:].decode()+"\necho json_encode(governance_recovery_oauth_access_failure_projection(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR)));"
  r=subprocess.run(['php','-r',code],input=json.dumps(value),capture_output=True,text=True,timeout=5)
  if r.returncode!=0 or r.stderr or len(r.stdout)>16384:raise ValueError('projection unavailable')
  projected=json.loads(r.stdout,object_pairs_hook=completion.strict_object)
  # The immutable reviewed PHP helper retains its historical scope spelling.
  # Rewrite only that fixed scope after its strict field projection.
  if type(projected)is not dict or projected.get('scope')!='native-conduit-producer-failure-v1':raise ValueError('fixed projection shape')
  projected['scope']='native-oauth-access-producer-failure-v1'
  out=projected
 except (ValueError,TypeError,OSError,KeyError,UnicodeError,subprocess.TimeoutExpired,RecursionError):pass
 return out

def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);p.add_argument('--stderr',type=Path);a=p.parse_args()
 print(json.dumps(project(a.receipt,a.stderr),sort_keys=True))
if __name__=='__main__':main()
