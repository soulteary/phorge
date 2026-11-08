"""Exact borrowed-owner source candidate checks; never physical runtime proof."""
import argparse,hashlib,json
from pathlib import Path
import check_completion as completion
import check_recovery_session_lease as source
ROOT=Path(__file__).resolve().parents[2]
import recovery_conduit_source_delta as conduit_delta
import recovery_assessment_source_delta as assessment_delta
V4_PINS={'src/applications/conduit/storage/PhabricatorConduitToken.php': '6979f97a01b5c24d1d205a23fcb0397270c9159a98e302267cbce09fbf5593e1', 'src/applications/auth/storage/PhabricatorAuthSession.php': '8e9efa7fa53676ec28e13d8b3e02a99096894ebf05f53fdd6366d9199d212a56', 'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': 'b2978b1bca3eea757c4be7e4fdbf84038fb61493e23f2cf39b1421162d6ebc04', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': '5d2bf5ef3cd86da509428de2a63af35f1ca11a7de8b3665bd80f061c7c6f42c4', 'src/infrastructure/governance/recovery/PhorgeRecoveryNativeCaptureScope.php': '3ed39e16e9c4243019404bfe3030b55aeb49666247938c24c943df182f8eb9a8', 'src/infrastructure/governance/recovery/PhorgeRecoveryConduitProducerFence.php': '9f2f670b730233a5024343a22cdeb935e4d0c46188105ff2d247ab4a8cb5fd81', 'src/applications/oauthserver/storage/PhabricatorOAuthServerAccessToken.php': '4abee85463c6f49856612ff0df5d1519f5d63c2ab87469587f1b472f6c6d2199'}
V5_PINS={**conduit_delta.SOURCE_INPUTS,**assessment_delta.SOURCE_INPUTS}
SOURCE_INPUTS={'src/infrastructure/governance/recovery/PhorgeRecoveryRestoreService.php': 'baffc7626462c6c1af2e7765d1901d9b9342a9063a553410dc4f207d466aef9f', 'src/infrastructure/governance/recovery/PhorgeRecoverySessionProducerFence.php': 'a3a9c2ff5ab45ad825d817fcc4caeb0b34711a33e8b1210357c37a6dbe8b86bd'}
def validate(data,root=ROOT):
 version=data.get('schema')
 assessment=version=='n08-assessment-native-credential-source-v5'
 oauth=version=='n08-oauth-access-owner-source-candidate-v4'
 current=version=='n08-conduit-owner-source-candidate-v3'
 legacy_v2=version=='n08-conduit-owner-source-candidate-v2'
 pins=V5_PINS if assessment else V4_PINS if oauth else conduit_delta.V3_PINS if current else conduit_delta.V2_PINS if legacy_v2 else SOURCE_INPUTS
 expected={'schema':'n08-assessment-native-credential-source-v5' if assessment else 'n08-oauth-access-owner-source-candidate-v4' if oauth else 'n08-conduit-owner-source-candidate-v3' if current else 'n08-conduit-owner-source-candidate-v2' if legacy_v2 else 'n08-borrowed-owner-source-candidate-v1','source_sha256':pins,
  'runtime_certified':False,'physical_SQL_cases_executed':False,
  'production_observer_factory_available':False,'business_submit_review_integration_proved':False,
  'all_sources_complete':False,'restoration_authorized':False,'execution_blocked':True}
 completion.fields(data,set(expected),'borrowed source candidate fields refused')
 for k,v in expected.items():completion.exact(data[k],v,'borrowed source candidate claims refused')
 if len(SOURCE_INPUTS)!=2:raise ValueError('borrowed source set refused')
 for path,pin in pins.items():
  raw=source.read_source_checked(Path(root)/path)
  if oauth:raw=assessment_delta.reduce_current(path,raw)
  if current and path in conduit_delta.oauth_delta.SOURCE_INPUTS:raw=conduit_delta.oauth_delta.reduce_current(path,raw)
  if not current and not oauth and not assessment:
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
