"""Failure-only bounded metadata from captured pinned PHP. Never print raw receipt."""
import argparse,hashlib,json,subprocess
from pathlib import Path
import check_recovery_conduit_producer as gate
import check_recovery_partial_composition as shared
import check_completion as completion

def project(path):
 out={'scope':'native-conduit-producer-diagnostic-unavailable-v1','passed':False,'diagnostic_available':False}
 try:
  data=json.loads(shared.read_checked(path),object_pairs_hook=completion.strict_object,parse_constant=lambda _:(_ for _ in()).throw(ValueError('nonfinite')))
  if type(data)is not dict or data.get('passed')is not False or type(data.get('failure'))is not dict or data.get('batch')!='native-conduit-producer-owner':raise ValueError('failure only')
  relative='support/governance/recovery/conduit-diagnostic.php';source=gate.read_source_checked(gate.ROOT/relative)
  if hashlib.sha256(source).hexdigest()!=gate.SOURCE_INPUTS[relative]or not source.startswith(b'<?php\n'):raise ValueError('fixed projector required')
  value=data['failure'].get('conduit_diagnostic',data['failure'])
  code=source[6:].decode()+"\necho json_encode(governance_recovery_conduit_failure_projection(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR)));"
  r=subprocess.run(['php','-r',code],input=json.dumps(value),capture_output=True,text=True,timeout=5)
  if r.returncode!=0 or r.stderr or len(r.stdout)>16384:raise ValueError('projection unavailable')
  out=json.loads(r.stdout,object_pairs_hook=completion.strict_object)
 except (ValueError,TypeError,OSError,KeyError,UnicodeError,subprocess.TimeoutExpired):pass
 return out

def main():
 p=argparse.ArgumentParser();p.add_argument('--receipt',type=Path,required=True);a=p.parse_args()
 print(json.dumps(project(a.receipt),sort_keys=True))
if __name__=='__main__':main()
