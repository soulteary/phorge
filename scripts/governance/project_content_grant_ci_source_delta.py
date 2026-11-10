"""Exact offline N05 CI admission only. Unknown or mixed bytes are refused."""
import hashlib,subprocess
from pathlib import Path
WORKFLOW='.github/workflows/project-content-grants-focused.yml'
PINS={'.github/workflows/project-content-grants-focused.yml': '679944d3f40a127e710e2c37ea9d8ae1ad62185cb8f8435bea9c21328ef3cc46', 'support/governance/project-content-grants-ci/README.md': 'c4fb13b1c056efa95a1acb4919f09b65d609556f966b2a37efd4b0e5feb42356', 'support/governance/project-content-grants-ci/ci.py': '02a11786473f455c2ec8e954f7a09ab1de295b2e8777dd7e5086dcc7dbe89d2f', 'support/governance/project-content-grants-ci/media-C-rebind-plan.json': '83038f08ada1a654da5769ada6e304ddd80583f72aba428ad0696f4f7f322731', 'support/governance/project-content-grants-ci/prerequisites/native.php': '3fb91c6e8f51444c90305fa3ebddb607b36f52afa55df44a6e6f07a428a8fb6e', 'support/governance/project-content-grants-ci/prerequisites/provision.py': '9c941b99272206df143a7ca8efc7afa262c2a2088cf1d0c8a579c8431938568c', 'support/governance/project-content-grants-ci/prerequisites-template.json': 'a42df15f61e96ddf7505707599ec8332002d564210891a712cf87fcd17be26ed', 'support/governance/project-content-grants-ci/qualification-template.json': '805b512c035831f4bea34f76c56dad78a3f4c6ae6cb50024fa494b3f91b7a513', 'tests/governance/test_project_content_grant_media_source_delta.py': '9d15eeeaca2ee14a62c0b281bddc11a4ee71e9b938d17f5228ac0cd17aa3bcc8', 'support/governance/media-session-ci/N05-source-rebind-plan.json': '4ef574dcbfe401cdb060e4336a10dcd5b8ca22c69d61fbd4ecacd9fcb134c61e', 'scripts/governance/project_content_grant_source_delta.py': '850639a696bb0bea35778fbc4e4fcb25d6f6930900489366dd309c8338cd97d7'}
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
