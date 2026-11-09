"""One exact additional Authz20 workflow; no historical runtime claim."""
import hashlib
WORKFLOW='.github/workflows/oauth-authorization-producer-focused.yml'
WORKFLOW_SHA256='dd271ba8d3590edf2d07d77e29b824aaef475d63775759f2482f06b68ba73bf7'
def assert_workflow(path,raw):
 if path!=WORKFLOW or type(raw)is not bytes or hashlib.sha256(raw).hexdigest()!=WORKFLOW_SHA256:raise ValueError('unreviewed authorization workflow')
