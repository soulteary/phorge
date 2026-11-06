"""Bounded public failure location; never print receipt or exception messages."""
import argparse,json,os,stat
FILES=frozenset({'file-parent-policy-cases.php','file-parent-policy-focused.php','file-source-cases.php','task-owner-source-cases.php','PhorgeRecoveryOwnedRRScope.php','PhorgeRecoveryFileSourceFacts.php','PhorgeGovernanceCompletionFixture.php','test-native-organization-mysql.php','AphrontMySQLiDatabaseConnection.php','AphrontBaseMySQLDatabaseConnection.php'})
TYPES=frozenset({'RuntimeException','Exception','Error','TypeError','ArgumentCountError','mysqli_sql_exception','PhutilAggregateException','PhabricatorPolicyException','AphrontQueryException'})
def project(data):
 out={'profile':'file-parent-failure-location-v1','passed':False,'diagnostic_available':False}
 if type(data)is not dict or data.get('passed')is not False or type(data.get('failure'))is not dict:return out
 f=data['failure'];out['diagnostic_available']=True
 out['stage']='focused-native-File-parent-policy' if f.get('stage')=='focused-native-File-parent-policy' else 'other'
 out['error_type']=f.get('error_type') if type(f.get('error_type'))is str and f['error_type'] in TYPES else 'other'
 out['source_file']=f.get('source_file') if type(f.get('source_file'))is str and f['source_file'] in FILES else 'other'
 for key in ('source_line','sql_errno'):
  value=f.get(key)
  if type(value)is int and 0<value<=1000000:out[key]=value
 return out
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--receipt',required=True);args=parser.parse_args()
 out=project(None)
 try:
  fd=os.open(args.receipt,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
  with os.fdopen(fd,'rb')as f:
   info=os.fstat(f.fileno())
   if not stat.S_ISREG(info.st_mode)or info.st_size>2097152:raise ValueError('bounded receipt required')
   raw=f.read(2097153)
   if len(raw)>2097152:raise ValueError('bounded receipt required')
   out=project(json.loads(raw))
 except Exception:pass
 print(json.dumps(out,sort_keys=True))
if __name__=='__main__':main()
