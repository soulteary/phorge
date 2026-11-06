"""Exact borrowed-owner source candidate checks; never physical runtime proof."""
import argparse,hashlib,json
from pathlib import Path
import check_completion as completion
import check_recovery_session_lease as source
ROOT=Path(__file__).resolve().parents[2]
SOURCE_INPUTS={'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': '94dec985d228df254fdaedca05719ab59633c585df14b6308fe7d2d128d0bfc8', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': 'a3a9c2ff5ab45ad825d817fcc4caeb0b34711a33e8b1210357c37a6dbe8b86bd'}
def validate(data,root=ROOT):
 expected={'schema':'n08-borrowed-owner-source-candidate-v1','source_sha256':SOURCE_INPUTS,
  'runtime_certified':False,'physical_SQL_cases_executed':False,
  'production_observer_factory_available':False,'business_submit_review_integration_proved':False,
  'all_sources_complete':False,'restoration_authorized':False,'execution_blocked':True}
 completion.fields(data,set(expected),'borrowed source candidate fields refused')
 for k,v in expected.items():completion.exact(data[k],v,'borrowed source candidate claims refused')
 if len(SOURCE_INPUTS)!=2:raise ValueError('borrowed source set refused')
 for path,pin in SOURCE_INPUTS.items():
  if hashlib.sha256(source.read_source_checked(Path(root)/path)).hexdigest()!=pin:raise ValueError('borrowed source bytes changed')
 return {'schema_valid':True,'runtime_certified':False,'source_inputs':2,
  'production_observer_factory_available':False,'restoration_authorized':False}
def main():
 p=argparse.ArgumentParser();p.add_argument('--source-artifact',type=Path,required=True);a=p.parse_args()
 data=json.loads(source.read_source_checked(a.source_artifact),object_pairs_hook=completion.strict_object,parse_constant=lambda x:(_ for _ in ()).throw(ValueError('nonfinite refused')))
 print(json.dumps(validate(data),sort_keys=True))
if __name__=='__main__':main()
