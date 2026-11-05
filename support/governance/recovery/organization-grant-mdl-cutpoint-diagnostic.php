<?php
/** Failure-only metadata, never a blocker/authorization decision. No SQL_TEXT. */
function governance_grant_cutpoint_uint($value){
  return is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$value)&&
    (strlen($value)<20||strcmp($value,'18446744073709551615')<=0);
}
function governance_grant_cutpoint_compare($a,$b){
  if(!governance_grant_cutpoint_uint($a)||!governance_grant_cutpoint_uint($b)){throw new RuntimeException('grant_cutpoint_shape_unknown');}
  return strlen($a)===strlen($b)?strcmp($a,$b):(strlen($a)<strlen($b)?-1:1);
}
/** Decimal uint64 arithmetic; never cast identifiers through signed PHP integers. */
function governance_grant_cutpoint_increment($value){
  if(!governance_grant_cutpoint_uint($value)){throw new RuntimeException('grant_cutpoint_shape_unknown');}
  if($value==='18446744073709551615'){return null;}
  $digits=str_split($value);$carry=1;
  for($i=count($digits)-1;$i>=0&&$carry;$i--){$digit=ord($digits[$i])-48+1;$digits[$i]=chr(48+$digit%10);$carry=$digit===10?1:0;}
  return($carry?'1':'').implode('',$digits);
}
function governance_grant_cutpoint_predecessor($value){
  if(!governance_grant_cutpoint_uint($value)||$value==='0'){throw new RuntimeException('grant_cutpoint_shape_unknown');}
  $digits=str_split($value);
  for($i=count($digits)-1;$i>=0;$i--){if($digits[$i]!=='0'){$digits[$i]=chr(ord($digits[$i])-1);break;}$digits[$i]='9';}
  $out=ltrim(implode('',$digits),'0');return$out===''?'0':$out;
}
function governance_grant_cutpoint_summary(array$families,$cutpoint,array$transactions,$ownerThread){
  if(!governance_grant_cutpoint_uint($ownerThread)||$ownerThread==='0'||!governance_grant_cutpoint_uint($cutpoint)||$cutpoint==='0'){return array('cutpoint_available'=>false);}
  $summary=array('cutpoint_available'=>true,'exact_matches'=>array(),'interval_matches'=>array(),
    'statement_end_equals_cutpoint'=>0,'statement_counter_boundary_count'=>0,
    'statement_extended_interval_count'=>0,'statement_boundary_parent_active_transaction_count'=>0,
    'statement_select_extended_count'=>0,'statement_boundary_overlap_with_next_start'=>false,
    'statement_extended_interval_ambiguous'=>false,'statement_parent_active_transaction'=>0,
    'exact_chain_to_active_transaction'=>0,'exact_chain_parent_unavailable'=>0,
    'exact_chain_cycle_or_depth_limit'=>0,'duplicate_event_versions'=>false,'rows_truncated'=>false,
    'statement_parent_types'=>array('transaction'=>0,'statement'=>0,'stage'=>0,'wait'=>0,'none'=>0,'unknown'=>0));
  $events=array();$active=array();
  if(count($transactions)>1){throw new RuntimeException('grant_cutpoint_transaction_ambiguous');}
  foreach($transactions as$row){
    $keys=array_keys($row);sort($keys);
    if($keys!==array('END_EVENT_ID','EVENT_ID','STATE','THREAD_ID')||$row['THREAD_ID']!==$ownerThread||!governance_grant_cutpoint_uint($row['EVENT_ID'])||!in_array($row['STATE'],array('ACTIVE','COMMITTED','ROLLED BACK'),true)||($row['END_EVENT_ID']!==null&&!governance_grant_cutpoint_uint($row['END_EVENT_ID']))){throw new RuntimeException('grant_cutpoint_transaction_unknown');}
    if(($row['STATE']??null)==='ACTIVE'&&($row['END_EVENT_ID']??null)===null&&governance_grant_cutpoint_uint($row['EVENT_ID']??null)){
      $active[$row['EVENT_ID']]=true;
    }
  }
  foreach(array('statement','stage','wait')as$kind){
    if(!isset($families[$kind])||!is_array($families[$kind])||count($families[$kind])>34){throw new RuntimeException('grant_cutpoint_shape_unknown');}
    $rows=$families[$kind];$seen=array();$summary['exact_matches'][$kind]=0;$summary['interval_matches'][$kind]=0;
    foreach($rows as$row){
      $keys=array_keys($row);sort($keys);
      if($keys!==array('END_EVENT_ID','EVENT_ID','EVENT_NAME','NESTING_EVENT_ID','NESTING_EVENT_TYPE','THREAD_ID')||
        $row['THREAD_ID']!==$ownerThread||!governance_grant_cutpoint_uint($row['THREAD_ID'])||!governance_grant_cutpoint_uint($row['EVENT_ID'])||
        ($row['END_EVENT_ID']!==null&&!governance_grant_cutpoint_uint($row['END_EVENT_ID']))||
        ($row['NESTING_EVENT_ID']!==null&&!governance_grant_cutpoint_uint($row['NESTING_EVENT_ID']))||
        !in_array($row['NESTING_EVENT_TYPE'],array(null,'TRANSACTION','STATEMENT','STAGE','WAIT'),true)||
        !is_string($row['EVENT_NAME'])||strlen($row['EVENT_NAME'])>128){throw new RuntimeException('grant_cutpoint_shape_unknown');}
      $key=$row['THREAD_ID'].':'.$row['EVENT_ID'];
      if(isset($seen[$key])){if($seen[$key]!==$row){$summary['duplicate_event_versions']=true;}continue;}
      $seen[$key]=$row;$events[$kind.':'.$row['EVENT_ID']]=$row;
      if($row['EVENT_ID']===$cutpoint){$summary['exact_matches'][$kind]++;}
      $contains=governance_grant_cutpoint_compare($row['EVENT_ID'],$cutpoint)<0&&
        ($row['END_EVENT_ID']===null||governance_grant_cutpoint_compare($cutpoint,$row['END_EVENT_ID'])<=0);
      $boundary=$kind==='statement'&&$row['END_EVENT_ID']!==null&&
        governance_grant_cutpoint_compare($row['EVENT_ID'],$cutpoint)<0&&
        governance_grant_cutpoint_increment($row['END_EVENT_ID'])===$cutpoint;
      if($kind==='statement'&&($contains||$boundary)){
        $summary['statement_extended_interval_count']++;
        if($row['EVENT_NAME']==='statement/sql/select'){$summary['statement_select_extended_count']++;}
        if($boundary){
          $summary['statement_counter_boundary_count']++;
          if($row['NESTING_EVENT_TYPE']==='TRANSACTION'&&isset($active[$row['NESTING_EVENT_ID']])){$summary['statement_boundary_parent_active_transaction_count']++;}
        }
      }
      if($contains){
        $summary['interval_matches'][$kind]++;
        if($kind==='statement'){
          if($row['END_EVENT_ID']===$cutpoint){$summary['statement_end_equals_cutpoint']++;}
          $type=$row['NESTING_EVENT_TYPE']===null?'none':strtolower($row['NESTING_EVENT_TYPE']);
          $summary['statement_parent_types'][$type]++;
          if($row['NESTING_EVENT_TYPE']==='TRANSACTION'&&isset($active[$row['NESTING_EVENT_ID']])){
            $summary['statement_parent_active_transaction']++;
          }
        }
      }
    }
  }
  foreach(array('statement','stage','wait')as$kind){
    $key=$kind.':'.$cutpoint;if(!isset($events[$key])){continue;}$visited=array();$finished=false;
    for($depth=0;$depth<8;$depth++){
      if(isset($visited[$key])){break;}$visited[$key]=true;$row=$events[$key];
      if($row['NESTING_EVENT_TYPE']==='TRANSACTION'&&isset($active[$row['NESTING_EVENT_ID']])){
        $summary['exact_chain_to_active_transaction']++;$finished=true;break;
      }
      $parent=strtolower($row['NESTING_EVENT_TYPE']??'none').':'.($row['NESTING_EVENT_ID']??'none');
      if(!isset($events[$parent])){$summary['exact_chain_parent_unavailable']++;$finished=true;break;}
      $key=$parent;
    }
    if(!$finished){$summary['exact_chain_cycle_or_depth_limit']++;}
  }
  $summary['statement_boundary_overlap_with_next_start']=$summary['statement_counter_boundary_count']>0&&$summary['exact_matches']['statement']>0;
  $summary['statement_extended_interval_ambiguous']=$summary['statement_extended_interval_count']>1||
    $summary['statement_boundary_overlap_with_next_start']||$summary['duplicate_event_versions'];
  // Counts never turn this diagnostic into an ownership/fence proof.
  $summary['complete_nesting_or_blocker_proved']=false;return$summary;
}
function governance_grant_cutpoint_collect($fixture,mysqli$control,mysqli$fencer,mysqli$revoker){
  if(PHP_SAPI!=='cli'||getenv('PHORGE_GOVERNANCE_ISOLATED_TEST')!=='1'||
    getenv('PHORGE_GOVERNANCE_GRANT_MDL_DIAGNOSTIC')!=='1'||
    get_class($fixture)!==PhorgeGovernanceCompletionFixture::class){throw new RuntimeException('grant_cutpoint_test_profile_required');}
  $fixture->assertOwned();
  $out=array('schema_version'=>1,'diagnostic_only'=>true,'runtime_success_inferred'=>false,
    'SQL_TEXT_collected'=>false,'consumers'=>array(),'instruments'=>array(),'roles'=>array());
  $consumerNames=array('global_instrumentation','thread_instrumentation','events_transactions_current',
    'events_statements_current','events_statements_history','events_stages_current','events_stages_history',
    'events_waits_current','events_waits_history');
  foreach($consumerNames as$name){
    $rows=governance_recovery_grant_mdl_rows($control,"SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME='".$name."'");
    $out['consumers'][$name]=count($rows)===1&&in_array($rows[0]['ENABLED'],array('YES','NO'),true)?strtolower($rows[0]['ENABLED']):'unknown';
  }
  foreach(array('mdl'=>'wait/lock/metadata/sql/mdl','select'=>'statement/sql/select','revoke'=>'statement/sql/revoke')as$label=>$name){
    $rows=governance_recovery_grant_mdl_rows($control,"SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME='".$name."'");
    $out['instruments'][$label]=count($rows)===1&&in_array($rows[0]['ENABLED'],array('YES','NO'),true)?strtolower($rows[0]['ENABLED']):'unknown';
  }
  $rows=governance_recovery_grant_mdl_rows($control,'SELECT @@performance_schema_events_statements_history_size statements,@@performance_schema_events_stages_history_size stages,@@performance_schema_events_waits_history_size waits,@@performance_schema_max_sql_text_length text_limit');
  $out['history_capacity']=array();
  foreach(array('statements','stages','waits','text_limit')as$key){
    $value=count($rows)===1?(string)$rows[0][$key]:null;
    $out['history_capacity'][$key]=governance_grant_cutpoint_uint($value)&&strlen($value)<=9?(int)$value:null;
  }
  $tickets=governance_recovery_grant_mdl_tickets($control);$out['tickets_truncated']=count($tickets)>64;
  foreach(array('fencer'=>$fencer,'revoker'=>$revoker)as$role=>$connection){
    $matches=array();foreach($tickets as$row){if($row['PROCESSLIST_ID']===(string)$connection->thread_id&&
      $row['LOCK_STATUS']===($role==='fencer'?'GRANTED':'PENDING')&&$row['LOCK_TYPE']===($role==='fencer'?'SHARED_WRITE':'SHARED_READ_ONLY')){$matches[]=$row;}}
    $item=array('candidate_count'=>count($matches));
    if(count($matches)!==1||$out['tickets_truncated']){$item['cutpoint_available']=false;$out['roles'][$role]=$item;continue;}
    $ticket=$matches[0];$thread=$ticket['OWNER_THREAD_ID'];$point=$ticket['OWNER_EVENT_ID'];
    if(!governance_grant_cutpoint_uint($thread)||!governance_grant_cutpoint_uint($point)){
      $item['cutpoint_available']=false;$out['roles'][$role]=$item;continue;
    }
    $threads=governance_recovery_grant_mdl_rows($control,'SELECT INSTRUMENTED,HISTORY FROM performance_schema.threads WHERE THREAD_ID='.$thread.' AND PROCESSLIST_ID='.(int)$connection->thread_id);
    $item['thread_instrumented']=count($threads)===1&&in_array($threads[0]['INSTRUMENTED'],array('YES','NO'),true)?strtolower($threads[0]['INSTRUMENTED']):'unknown';
    $item['thread_history']=count($threads)===1&&in_array($threads[0]['HISTORY'],array('YES','NO'),true)?strtolower($threads[0]['HISTORY']):'unknown';
    $tx=governance_recovery_grant_mdl_rows($control,'SELECT CAST(THREAD_ID AS CHAR) THREAD_ID,CAST(EVENT_ID AS CHAR) EVENT_ID,STATE,CAST(END_EVENT_ID AS CHAR) END_EVENT_ID FROM performance_schema.events_transactions_current WHERE THREAD_ID='.$thread.' LIMIT 2');
    $families=array();$truncated=false;
    foreach(array('statement'=>'statements','stage'=>'stages','wait'=>'waits')as$kind=>$suffix){
      $families[$kind]=array();
      foreach(array('current','history')as$storage){
        $lower=$kind==='statement'?governance_grant_cutpoint_predecessor($point):$point;
        $rows=governance_recovery_grant_mdl_rows($control,'SELECT CAST(THREAD_ID AS CHAR) THREAD_ID,CAST(EVENT_ID AS CHAR) EVENT_ID,CAST(END_EVENT_ID AS CHAR) END_EVENT_ID,EVENT_NAME,CAST(NESTING_EVENT_ID AS CHAR) NESTING_EVENT_ID,NESTING_EVENT_TYPE FROM performance_schema.events_'.$suffix.'_'.$storage.' WHERE THREAD_ID='.$thread.' AND (EVENT_ID='.$point.' OR (EVENT_ID<'.$point.' AND (END_EVENT_ID>='.$lower.' OR END_EVENT_ID IS NULL))) ORDER BY EVENT_ID DESC LIMIT 17');
        if(count($rows)>16){$truncated=true;}$families[$kind]=array_merge($families[$kind],$rows);
      }
    }
    $item+=governance_grant_cutpoint_summary($families,$point,$tx,$thread);$item['rows_truncated']=$truncated;$out['roles'][$role]=$item;
  }
  return$out;
}
