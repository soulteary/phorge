"""Closed legacy-test views only. Native runtime readers never import this file."""
import ast,hashlib,subprocess,sys
from functools import lru_cache
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/governance'))
import project_content_grant_source_delta as n05
CURRENT_REVISION='512282be298c7996fc6370156c333d7557b1685a'
PRIOR_REVISION='4c2245300dc1d78d042b95190dd537eb6b1f9861'
CURRENT_PINS={'scripts/governance/check_recovery_session_lease.py': 'b222848a6ac45504711a8d5cefea0fc8d5c5a9363988e531f9a845edef598a5f', 'scripts/governance/check_recovery_borrowed_session.py': 'e46ca3a5cccdbdbc307e5be0c88608f1dab7d15c7e381dcb22d99dae1c489d08', 'scripts/governance/check_recovery_conduit_producer.py': '85fc58733b00c653ac4b7abe0271d06fb9e94677c16f1708dea2811f75458e0a', 'scripts/governance/check_recovery_assessment_credentials.py': '546e8cfe00e8814171f25b267ea3a2f43632c7594b1fcf6c16fc3989281dc82a', 'scripts/governance/check_recovery_assessment_credentials_v2.py': '628d75d4e6fe26e1b84509d401c605896ad819c1197750bb4ec17edfa8d59dac', 'scripts/governance/check_recovery_oauth_access_producer_v3.py': '159d246e0ac3462e335a85cf034b5beaa3c75cd5f11ae4175e0136adb823d177'}
CLOSED_PATHS=('scripts/governance/check_recovery_session_lease.py', 'scripts/governance/check_recovery_borrowed_session.py', 'scripts/governance/check_recovery_conduit_producer.py', 'scripts/governance/check_recovery_assessment_credentials.py', 'scripts/governance/check_recovery_assessment_credentials_v2.py', 'scripts/governance/check_recovery_oauth_access_producer_v3.py')
REPAIR_SOURCE_PINS={'scripts/governance/check_completion.py': '39956a43c26607ac5ebb761ec25f686b2314326511a082f80efe4bf3a2258649', 'scripts/governance/check_native_organization.py': '0191dca0cbbf00384153a46a0ed8cadcbd5c846d1e7486a34d18d15cb5151c3a', 'scripts/governance/check_native_user_rr_mdl.py': '45f5097603be4862928426cb346145805d13165ee03b8e07124837988e8c8a1f', 'scripts/governance/check_recovery_org_lifecycle.py': 'a41589fa595c5eccad6a500508a0394f54d1e9dae2d212984d3aa0224164954e', 'scripts/governance/check_recovery_org_metadata.py': 'c17cbfe4a3a876943e52d875a1e4c2e5e57c44c9cb6cce6e41c187de8a5a72cc', 'scripts/governance/check_recovery_policy_isolation.py': '977702a4fff5fec35807c4f36eee31bf263bd8aff0215c6104bbae29dbf8c127', 'docs/governance/native-directory-callers.json': '9fb8310e407d46858575eb6b181d1dcdf92a0cc3737eeec7d818f74764fe0745', 'scripts/governance/check_native_directory.py': '84a499315ae6f93a6313a2e6d33a81c2e4ec51696d9c8cdb60ca6a2a3791e4ab', 'support/governance/project-content-grants-qualification/native.php': '68fc4a94a86140c7c0a6ef01af0d6daa3e5d5982c20c89061c8d9b64650d869d'}
METADATA_PATHS=('docs/governance/native-directory-callers.json', 'tests/governance/test_recovery_partial_schema_coordination.py')
METADATA_CURRENT_PINS={'docs/governance/native-directory-callers.json': '9fb8310e407d46858575eb6b181d1dcdf92a0cc3737eeec7d818f74764fe0745', 'tests/governance/test_recovery_partial_schema_coordination.py': '27ba8211721188e4ad8c202bfd3e835d99be4213f9d3ef878f3befbec95d38a7'}
METADATA_PRIOR_PINS={'docs/governance/native-directory-callers.json': 'ec8dd1d0be18c3299399e2a071ffe2b28629ceee4baa813c4e7dffc28b3f083c', 'tests/governance/test_recovery_partial_schema_coordination.py': '7fa46e52878fe19ec2f623d92789590d07ccbab0aae6c0bc85b8efdbc9e28965'}
METADATA_PRIOR_REVISION='89260b8508e319a05a3cce476692ca1d55081d98'
@lru_cache(maxsize=64)
def git_bytes(revision,path):
 return subprocess.check_output(['git','--no-replace-objects','-C',str(ROOT),'show',revision+':'+path])
def require(value):
 if not value:raise ValueError('unreviewed N05 legacy test view')
def verify_current_cohort(root):
 root=Path(root);n05.assert_production_cohort(root)
 for name,pin in REPAIR_SOURCE_PINS.items():
  raw=(root/name).read_bytes();require(type(raw)is bytes and hashlib.sha256(raw).hexdigest()==pin and raw==git_bytes(CURRENT_REVISION,name))
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

def historical_metadata_source(path,raw,root=ROOT):
 # Only exact current test-audit metadata; actual readers retain raw current bytes.
 require(path in METADATA_PATHS);verify_current_cohort(root)
 require(set(METADATA_CURRENT_PINS)==set(METADATA_PRIOR_PINS)==set(METADATA_PATHS))
 for name in METADATA_PATHS:
  current=(Path(root)/name).read_bytes()
  require(type(current)is bytes and hashlib.sha256(current).hexdigest()==METADATA_CURRENT_PINS[name] and current==git_bytes(CURRENT_REVISION,name))
 require(type(raw)is bytes and raw==(Path(root)/path).read_bytes())
 prior=git_bytes(METADATA_PRIOR_REVISION,path);require(hashlib.sha256(prior).hexdigest()==METADATA_PRIOR_PINS[path]);return prior
