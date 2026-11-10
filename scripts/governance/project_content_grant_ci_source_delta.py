"""Exact offline N05 CI admission only. Unknown or mixed bytes are refused."""
import hashlib,subprocess
from pathlib import Path
WORKFLOW='.github/workflows/project-content-grants-focused.yml'
PINS={'.github/workflows/project-content-grants-focused.yml': 'ab3b28dfcf70c0eb378a3e2a2555f69025849ecf2edf6fa65ce65025cc7d2fc7', 'support/governance/project-content-grants-ci/README.md': '765f4ebf444c33d9cf9ea4e1414bc0ed9a64617a5bc54c27d1527d80edffd374', 'support/governance/project-content-grants-ci/ci.py': '9e990374658dde4f4e10d814407c1d5f9a70bcc44a536ec62fd43737e70328bf', 'support/governance/project-content-grants-ci/media-C-rebind-plan.json': '83038f08ada1a654da5769ada6e304ddd80583f72aba428ad0696f4f7f322731', 'support/governance/project-content-grants-ci/prerequisites/native.php': '3fb91c6e8f51444c90305fa3ebddb607b36f52afa55df44a6e6f07a428a8fb6e', 'support/governance/project-content-grants-ci/prerequisites/provision.py': '4f72cfaf65f70762c2525fd71b6ac066d859e6e8214c3cea108a31327f081d9d', 'support/governance/project-content-grants-ci/prerequisites-template.json': '07c1f919220906a7bc19b1b42fc2bf6812e1809b00422c77147d6a3b37adb185', 'support/governance/project-content-grants-ci/qualification-template.json': '4a25ffd8d271d4f10c2e32eb2b3b81330c9006d158f9acc272f1fe2ebf55201e', 'tests/governance/test_project_content_grant_media_source_delta.py': '9652267a9f33fa7c2788167a1ab647f0b32e7c48a5ce75e451998d55755b1159', 'support/governance/media-session-ci/N05-source-rebind-plan.json': '3f18e1c4490474124efe7b2a74436087c022d9de5804291d2a397a24309fa58f', 'scripts/governance/project_content_grant_source_delta.py': 'fa7b2171d0fb38a02ce4f5b93458f50f9c2ac843cad014bdd762ba7610890a3f'}
def assert_workflow(path,raw):
 if path!=WORKFLOW or hashlib.sha256(raw).hexdigest()!=PINS[WORKFLOW]:raise ValueError('unreviewed N05 CI workflow')
 return True
def assert_cohort(root):
 for name,pin in PINS.items():
  if hashlib.sha256((Path(root)/name).read_bytes()).hexdigest()!=pin:raise ValueError('unreviewed N05 CI source cohort')
 return set(PINS)
def historical_inputs(root,baseline,changed,current):
 selected=set(current)&set(PINS)
 if not selected:return changed,current
 assert_cohort(root)
 changed=set(changed);current=dict(current)
 for name in selected:
  if hashlib.sha256(current[name]).hexdigest()!=PINS[name]:raise ValueError('unreviewed N05 CI bytes')
  old=subprocess.run(['git','-C',str(root),'cat-file','-e',baseline+':'+name],capture_output=True)
  if old.returncode==0:raise ValueError('N05 CI unexpectedly replaces historical path')
  changed.discard(name);current.pop(name)
 return changed,current
