"""Exact offline N05 CI admission only. Unknown or mixed bytes are refused."""
import hashlib,subprocess
from pathlib import Path
WORKFLOW='.github/workflows/project-content-grants-focused.yml'
PINS={'.github/workflows/project-content-grants-focused.yml': '5289f1f45c7ef88d4d2759fe353a6d127a9f086e5c562073897aa88359961c02', 'support/governance/project-content-grants-ci/README.md': 'b3b5df8b10e874a2a2466b5ce30e53140346c77b3d1f815f1c751e39b6fc2aca', 'support/governance/project-content-grants-ci/ci.py': 'dbb8eb883d1624b391fec7605e8c3cd05aef32d40b316668b36e692f64570867', 'support/governance/project-content-grants-ci/media-C-rebind-plan.json': '83038f08ada1a654da5769ada6e304ddd80583f72aba428ad0696f4f7f322731', 'support/governance/project-content-grants-ci/prerequisites/native.php': '3fb91c6e8f51444c90305fa3ebddb607b36f52afa55df44a6e6f07a428a8fb6e', 'support/governance/project-content-grants-ci/prerequisites/provision.py': '220450cf1b4c611784c753d01ff18fb15a3340e9b2127163ca9bb37d5cca3e87', 'support/governance/project-content-grants-ci/prerequisites-template.json': 'f50a87c8c5f47cfa25a275ec946e374111e256a62748bc3e253509552912e9a8', 'support/governance/project-content-grants-ci/qualification-template.json': 'ed0e5611ef3eb31db695e5f38631d56615ab6085ca96c029a494dceb6ec6a543', 'tests/governance/test_project_content_grant_media_source_delta.py': '47bd29954173e7c5de1d8a078e8b5636ad04c9e2786e9c8ad785cd9eaef69230', 'support/governance/media-session-ci/N05-source-rebind-plan.json': '660d5bb757bdfc6e392ef94cd7604219c95d0ef6fe64eebcbdd1031be7cc99d9', 'scripts/governance/project_content_grant_source_delta.py': '504a56f056bebcddce0f7e793c1a63d2d56a9a64aec11f12013efb09fdc476bc'}
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
