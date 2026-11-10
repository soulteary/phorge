"""Closed legacy-test views only. Native runtime readers never import this file."""
import ast,hashlib,subprocess,sys
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_content_grant_source_delta as n05
CURRENT_REVISION='89260b8508e319a05a3cce476692ca1d55081d98'
PRIOR_REVISION='4c2245300dc1d78d042b95190dd537eb6b1f9861'
CURRENT_PINS={'scripts/governance/check_recovery_session_lease.py': 'b222848a6ac45504711a8d5cefea0fc8d5c5a9363988e531f9a845edef598a5f', 'scripts/governance/check_recovery_borrowed_session.py': 'e46ca3a5cccdbdbc307e5be0c88608f1dab7d15c7e381dcb22d99dae1c489d08', 'scripts/governance/check_recovery_conduit_producer.py': '85fc58733b00c653ac4b7abe0271d06fb9e94677c16f1708dea2811f75458e0a', 'scripts/governance/check_recovery_assessment_credentials.py': '546e8cfe00e8814171f25b267ea3a2f43632c7594b1fcf6c16fc3989281dc82a', 'scripts/governance/check_recovery_assessment_credentials_v2.py': '628d75d4e6fe26e1b84509d401c605896ad819c1197750bb4ec17edfa8d59dac', 'scripts/governance/check_recovery_oauth_access_producer_v3.py': '159d246e0ac3462e335a85cf034b5beaa3c75cd5f11ae4175e0136adb823d177'}
CLOSED_PATHS=('scripts/governance/check_recovery_session_lease.py', 'scripts/governance/check_recovery_borrowed_session.py', 'scripts/governance/check_recovery_conduit_producer.py', 'scripts/governance/check_recovery_assessment_credentials.py', 'scripts/governance/check_recovery_assessment_credentials_v2.py', 'scripts/governance/check_recovery_oauth_access_producer_v3.py')
@lru_cache(maxsize=32)
def git_bytes(revision,path):
 return subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',revision+':'+path])
def require(value):
 if not value:raise ValueError('unreviewed N05 legacy test view')
def verify_current_cohort(root):
 root=Path(root);n05.assert_production_cohort(root)
 for name,pin in n05.FIXTURE_METADATA_PINS.items():
  raw=(root/name).read_bytes();require(hashlib.sha256(raw).hexdigest()==pin and raw==git_bytes(CURRENT_REVISION,name))
 require(set(CURRENT_PINS)==set(CLOSED_PATHS))
 for name in CLOSED_PATHS:
  raw=(root/name).read_bytes()
  require(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==CURRENT_PINS[name] and raw==git_bytes(CURRENT_REVISION,name))
def historical_tooling_source(path,root=ROOT):
 require(path in CLOSED_PATHS);verify_current_cohort(root)
 raw=(Path(root)/path).read_bytes();prior=n05.reduce_if_selected(path,raw)
 require(prior==git_bytes(PRIOR_REVISION,path));return prior
def historical_source_inputs(path,root=ROOT):
 tree=ast.parse(historical_tooling_source(path,root))
 return next(ast.literal_eval(n.value)for n in tree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='SOURCE_INPUTS'for t in n.targets))
def historical_library_map(raw,root=ROOT):
 verify_current_cohort(root);path='src/__phutil_library_map__.php'
 require(type(raw)is bytes and raw==git_bytes(CURRENT_REVISION,path))
 prior=n05.reduce_production(path,raw);require(prior==git_bytes(PRIOR_REVISION,path));return prior
