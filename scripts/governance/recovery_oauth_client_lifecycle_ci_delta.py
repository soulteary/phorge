"""One reviewed additive workflow; never a production or SQL result permit."""
import hashlib
WORKFLOW='.github/workflows/oauth-client-lifecycle-focused.yml'
WORKFLOW_SHA256='9036405ba276507a05b59e1be4cf5e2bd200d41bb0cb266daa8e93b48c3b5dce'
def assert_workflow(path,raw):
 if path!=WORKFLOW or type(raw)is not bytes or hashlib.sha256(raw).hexdigest()!=WORKFLOW_SHA256:raise ValueError('unreviewed lifecycle workflow')
 return True
