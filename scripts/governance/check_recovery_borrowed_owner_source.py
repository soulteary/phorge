"""Exact borrowed-owner source candidate checks; never physical runtime proof."""
import argparse,hashlib,json
from pathlib import Path
import check_completion as completion
import check_recovery_session_lease as source
ROOT=Path(__file__).resolve().parents[2]
import recovery_conduit_source_delta as conduit_delta
SOURCE_INPUTS={'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': 'baffc7626462c6c1af2e7765d1901d9b9342a9063a553410dc4f207d466aef9f', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': 'a3a9c2ff5ab45ad825d817fcc4caeb0b34711a33e8b1210357c37a6dbe8b86bd'}
def validate(data,root=ROOT):
 version=data.get('schema')
 current=version=='n08-conduit-owner-source-candidate-v3'
 legacy_v2=version=='n08-conduit-owner-source-candidate-v2'
 pins=conduit_delta.SOURCE_INPUTS if current else conduit_delta.V2_PINS if legacy_v2 else SOURCE_INPUTS
 expected={'schema':'n08-conduit-owner-source-candidate-v3' if current else 'n08-conduit-owner-source-candidate-v2' if legacy_v2 else 'n08-borrowed-owner-source-candidate-v1','source_sha256':pins,
  'runtime_certified':False,'physical_SQL_cases_executed':False,
  'production_observer_factory_available':False,'business_submit_review_integration_proved':False,
  'all_sources_complete':False,'restoration_authorized':False,'execution_blocked':True}
 completion.fields(data,set(expected),'borrowed source candidate fields refused')
 for k,v in expected.items():completion.exact(data[k],v,'borrowed source candidate claims refused')
 if len(SOURCE_INPUTS)!=2:raise ValueError('borrowed source set refused')
 for path,pin in pins.items():
  raw=source.read_source_checked(Path(root)/path)
  if not current:
   try:raw=conduit_delta.reduce_v2(path,raw) if legacy_v2 else conduit_delta.reduce_owner(path,raw)
   except ValueError as ex:raise ValueError('borrowed source bytes changed') from ex
  if hashlib.sha256(raw).hexdigest()!=pin:raise ValueError('borrowed source bytes changed')
 return {'schema_valid':True,'runtime_certified':False,'source_inputs':len(pins),
  'production_observer_factory_available':False,'restoration_authorized':False}
def main():
 p=argparse.ArgumentParser();p.add_argument('--source-artifact',type=Path,required=True);a=p.parse_args()
 data=json.loads(source.read_source_checked(a.source_artifact),object_pairs_hook=completion.strict_object,parse_constant=lambda x:(_ for _ in ()).throw(ValueError('nonfinite refused')))
 print(json.dumps(validate(data),sort_keys=True))
if __name__=='__main__':main()
