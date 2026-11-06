"""Execute actual fixture observers with explicit DB/view seams; never SQL proof."""
from pathlib import Path
import json,re,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]

def schema_rows():
 sql=(ROOT/'resources/sql/autopatches/20261102.people.organization.sql').read_text();tables={}
 for table in ['unit','member','appointment','operation','audit','approval']:
  body=sql.split('CREATE TABLE {$NAMESPACE}_user.organization_'+table+' (',1)[1].split(') ENGINE=',1)[0]
  columns=re.findall(r'^  ([A-Za-z][A-Za-z0-9]*) (?:VARBINARY|VARCHAR|LONGTEXT|INT|BIGINT)',body,re.M)
  if table=='unit':columns+=['visibilityModel','visibilityVersion','associatedDepartmentID','contactUserPHID']
  if table=='approval':columns+=['sourceParticipantVersion','sourceSubjectPHID','sourceRequesterPHID','sourceExecutorPHID']
  row={c:'fixed-'+c for c in columns}
  for c in ['id','version','validFrom','validUntil','createdAt','approvedAt','consumedAt','approverVersion','visibilityVersion','sourceParticipantVersion']:
   if c in row:row[c]='1'
  for c in ['resultJSON','beforeJSON','afterJSON','bindingJSON']:
   if c in row:row[c]='{}'
  if 'approverBindingJSON'in row:row['approverBindingJSON']=None
  if table=='operation':row['operationID']='operation-a'
  if table=='approval':row['approvalID']='approval-a'
  tables[table]=[row]
 return tables

class Fingerprint(unittest.TestCase):
 def run_php(self,code,*args):
  p=subprocess.run(['php','-r',code,str(ROOT),*args],capture_output=True,text=True,timeout=10)
  self.assertEqual(0,p.returncode,p.stderr);self.assertEqual('',p.stderr);return json.loads(p.stdout)
 def test_complete_row_changes_with_same_counts_all_six_tables_change_hash(self):
  code=r'''class PhorgeGovernanceOrganizationRequest{static function identifier($x){return is_string($x)&&preg_match('/\A[a-zA-Z0-9_.:-]+\z/D',$x);}}
require$argv[1].'/support/governance/organization/approval-workbench-cases.php';$GLOBALS['rows']=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR);$case=$argv[3];$GLOBALS['bodyReads']=0;
class Result{function __construct(private$rows){}function fetch_all($mode){return$this->rows;}}class Statement{private$id;function __construct(private$table){}function bind_param($type,&$id){$this->id=&$id;}function execute(){}function close(){}function get_result(){global$case;$GLOBALS['bodyReads']++;$rows=$GLOBALS['rows'][$this->table];if($case==='missing'){return new Result([]);}if($case==='payloadchanged'&&$this->table==='operation'){$rows[0]['resultJSON']='longer';}if($case==='identitychanged'){$key=isset($rows[0]['id'])?'id':(isset($rows[0]['approvalID'])?'approvalID':'operationID');$rows[0][$key]='wrong';}return new Result($rows);}}
class DB{function query($sql){global$case;preg_match('/organization_([a-z]+)/',$sql,$match);$table=$match[1];$headers=[];foreach($GLOBALS['rows'][$table]as$row){$key=array_key_exists('id',$row)?'id':(array_key_exists('approvalID',$row)?'approvalID':'operationID');$h=['rowKey'=>$row[$key]];preg_match_all('/OCTET_LENGTH\(`([^`]+)`\)/',$sql,$matches);foreach($matches[1]as$col){$h[$col.'Bytes']=$row[$col]===null?null:strlen($row[$col]);}if($table==='approval'){
 if($case==='oversize'){$h['bindingJSONBytes']=32769;}if($case==='null'){$h['bindingJSONBytes']=null;}if($case==='noncanonical'){$h['bindingJSONBytes']='02';}if($case==='missingnullable'){unset($h['approverBindingJSONBytes']);}}
 $headers[]=$h;}if($case==='rows'&&$table==='approval'){$headers=array_fill(0,65,$headers[0]);}if($case==='aggregate'&&$table==='approval'){$base=$headers[0];$headers=[];for($i=0;$i<64;$i++){$h=$base;$h['rowKey']='approval-'.$i;$h['bindingJSONBytes']=32768;$headers[]=$h;}}return new Result($headers);}function prepare($sql){preg_match('/organization_([a-z]+)/',$sql,$match);return new Statement($match[1]);}}
class Fixture{function assertOwned(){}function table($app,$table){return'`owned`.`'.$table.'`';}function getAdmin(){return new DB();}}
$accepted=false;$different=[];$before=null;try{$before=governance_approval_workbench_fingerprint(new Fixture());$accepted=true;if($case==='valid'){
 foreach(['unit'=>'name','member'=>'validFrom','appointment'=>'role','operation'=>'resultJSON','audit'=>'beforeJSON','approval'=>'approverDigest']as$table=>$column){$old=$GLOBALS['rows'][$table][0][$column];$GLOBALS['rows'][$table][0][$column]=$column==='validFrom'?'2':($column==='resultJSON'||$column==='beforeJSON'?'[]':'changed');$different[]=governance_approval_workbench_fingerprint(new Fixture())!==$before;$GLOBALS['rows'][$table][0][$column]=$old;}
 }}catch(Throwable$error){}echo json_encode(['accepted'=>$accepted,'different'=>$different,'body_reads'=>$GLOBALS['bodyReads'],'SQL_executed'=>false]);'''
  for case in ['valid','oversize','null','noncanonical','missingnullable','rows','aggregate','missing','payloadchanged','identitychanged']:
   with self.subTest(case=case):
    d=self.run_php(code,json.dumps(schema_rows()),case);self.assertEqual(case=='valid',d['accepted']);self.assertFalse(d['SQL_executed'])
    if case=='valid':self.assertEqual([True]*6,d['different'])
    elif case in ['oversize','null','noncanonical','missingnullable','rows','aggregate']:self.assertEqual(0,d['body_reads'])
 def test_review_matcher_and_stale_setup_reject_generic_or_unapproved(self):
  code=r'''class PhabricatorUser{}class PhutilSafeHTML{function __construct(private$text){}function getHTMLContent(){return$this->text;}}class AphrontView{protected$children=[];}class AphrontDialogView extends AphrontView{private$hidden;private$submitButton='Approve Original Request';function __construct(private$title,$intent,$bad){$this->hidden=[['approval',$intent['approval_id']],['form_action','approve']];$this->children=[phutil_tag('p',['class'=>'aphront-dialog-view-paragraph'],pht('Team: %s; user: %s; change: %s',$intent['team'],$intent['target_user'],'Add Membership and Administrator Appointment')),phutil_tag('p',['class'=>'aphront-dialog-view-paragraph'],'Membership: 0 to Unchanged / No End Date; appointment: 0 to Unchanged / No End Date'),new PhutilSafeHTML('fixed impact')];if($bad==='hidden'){$this->hidden[0][1]='wrong';}if($bad==='intent'){$this->children[0]=new PhutilSafeHTML('wrong impact');}}function getTitle(){return$this->title;}}class AphrontRedirectResponse{}
function pht($text,...$args){return$args?vsprintf($text,$args):$text;}function phutil_tag($tag,$attr,$value){return new PhutilSafeHTML($value);}function phabricator_datetime($value,$viewer){return(string)$value;}
require$argv[1].'/support/governance/organization/approval-workbench-cases.php';$intent=['approval_id'=>'a','team'=>'team','target_user'=>'subject','action'=>'member.add-and-appoint','member_from'=>0,'member_until'=>null,'appointment_from'=>0,'appointment_until'=>null];$matches=[];
foreach(['valid','generic','hidden','intent']as$bad){$dialog=new AphrontDialogView($bad==='generic'?'Approval Request Unavailable':'Review Compound Membership Change',$intent,$bad);$matches[]=governance_approval_workbench_review_matches($dialog,$intent,new PhabricatorUser());}
$ready=['approval_id'=>'a','state'=>'approved','approver'=>'reviewer'];$setup=[];foreach(['valid','dialog','submitted','wrong-approver']as$bad){$d=$ready;if($bad==='submitted'){$d['state']='submitted';}if($bad==='wrong-approver'){$d['approver']='wrong';}$accepted=false;try{governance_approval_workbench_approved_setup($bad==='dialog'?new AphrontDialogView('Unavailable',$intent,'generic'):new AphrontRedirectResponse(),$d,'a','reviewer');$accepted=true;}catch(Throwable$error){}$setup[]=$accepted;}
echo json_encode(['matches'=>$matches,'setup'=>$setup,'SQL_executed'=>false,'native_HTTP_server_executed'=>false]);'''
  d=self.run_php(code);self.assertEqual([True,False,False,False],d['matches']);self.assertEqual([True,False,False,False],d['setup']);self.assertFalse(d['SQL_executed'])
if __name__=='__main__':unittest.main()
