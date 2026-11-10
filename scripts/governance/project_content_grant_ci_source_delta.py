"""Exact offline N05 CI admission only. Unknown or mixed bytes are refused."""
import hashlib,subprocess
from pathlib import Path
WORKFLOW='.github/workflows/project-content-grants-focused.yml'
PINS={'.github/workflows/project-content-grants-focused.yml': 'dd5de9d59d3cd125d9a5ff5b598d1c8fb1e4aadeed90334c2b5d30d6886aa769', 'support/governance/project-content-grants-ci/README.md': 'c40fa6c8f4438c93ae6aed9b39fec88ce3c13b323cc038df6077e81efcb7bdc4', 'support/governance/project-content-grants-ci/ci.py': '9447219998f716b59e28148c7b2064f291c98a700344be3bbc9542ae13a2de82', 'support/governance/project-content-grants-ci/media-C-rebind-plan.json': '83038f08ada1a654da5769ada6e304ddd80583f72aba428ad0696f4f7f322731', 'support/governance/project-content-grants-ci/prerequisites/native.php': '3fb91c6e8f51444c90305fa3ebddb607b36f52afa55df44a6e6f07a428a8fb6e', 'support/governance/project-content-grants-ci/prerequisites/provision.py': '77f887a24add6548591112417e7eb6f75735fceaa913c0010d3a4ad0eb03368f', 'support/governance/project-content-grants-ci/prerequisites-template.json': '099e16f72bd6cb39bf8088ba6cb713dfab84049ad510171728be2817a4a25d17', 'support/governance/project-content-grants-ci/qualification-template.json': 'daa5e545dc68a9621c383ef28ee0e24837fdd6b8294738a14d75923932f7954e', 'tests/governance/test_project_content_grant_media_source_delta.py': 'ea9596a70f659c513cb3a6f2a4e3cd64940bfd4942947cb4020b58bbcfbc70c9', 'support/governance/media-session-ci/N05-source-rebind-plan.json': 'ffd324d1fa486e2da592b56df63eda5f19b460b480eb60f7835d0536c8f0a4f0', 'scripts/governance/project_content_grant_source_delta.py': 'a39f78fb24ed90790c21e87d106a657c1f8842a1891e5c444e101d4a4667daed'}
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
