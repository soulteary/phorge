#!/usr/bin/env python3
"""Replay actual Calendar observation shape; shape never authenticates execution."""
import argparse,json,re
from pathlib import Path
def describe(v,revision,producer):
 def need(x):
  if not x:raise ValueError('calendar_observation_refused')
 need(type(v)is dict and set(v)==set('schema source_revision producer_sha256 boot_id_sha256 transition_binding_sha256 boundary operation_start_ns commit_confirmed_return_ns persisted_uninvited_observed native_transaction_record_available attempts physical_SMTP_proved measurement_acceptance SLA_proved convergence_proved restoration_authorized'.split()))
 need(v['schema']=='calendar-physical-policy-operation-observation-v1'and v['source_revision']==revision and re.fullmatch('[a-f0-9]{40}',revision)and v['producer_sha256']==producer and re.fullmatch('[a-f0-9]{64}',producer))
 for k in ['boot_id_sha256','transition_binding_sha256']:need(type(v[k])is str and re.fullmatch('[a-f0-9]{64}',v[k]))
 def ns(x):need(type(x)is int and 0<=x<2**63);return x
 start=ns(v['operation_start_ns']);ack=ns(v['commit_confirmed_return_ns']);need(start<=ack)
 need(v['boundary']=='native-invitee-dao-save-no-outer-operation-call-return'and v['persisted_uninvited_observed']is True)
 for k in ['native_transaction_record_available','physical_SMTP_proved','measurement_acceptance','SLA_proved','convergence_proved','restoration_authorized']:need(v[k]is False)
 rows=v['attempts'];need(type(rows)is list and len(rows)==5);previous=-1
 for i,row in enumerate(rows):
  need(type(row)is dict and set(row)=={'label','start_ns','finish_ns','outcome'}and row['label']==['A','B','A','B','A'][i]and row['outcome']in ['allowed','denied','unknown'])
  a=ns(row['start_ns']);b=ns(row['finish_ns']);need(previous<=a<=b);previous=b
  need(b<=start if i<2 else ack<=a)
 need(all(row['outcome']=='allowed'for row in rows[:2]))
 denied=[row for row in rows[2:]if row['label']=='A'and row['outcome']=='denied']
 return {'profile':v['schema'],'source_revision':revision,'producer_sha256':producer,'first_A_denial_after_return_ns':None if not denied else [denied[0]['start_ns']-ack,denied[0]['finish_ns']-ack],'postcommit_A_outcomes':[x['outcome']for x in rows[2:]if x['label']=='A'],'postcommit_B_outcome':rows[3]['outcome'],'producer_attestation':'unverified-input','measurement_acceptance':False,'physical_SMTP_proved':False,'SLA_proved':False,'convergence_proved':False}
def decode(raw):
 def pairs(rows):
  d={}
  for k,v in rows:
   if k in d:raise ValueError('duplicate_JSON')
   d[k]=v
  return d
 if len(raw)>65536:raise ValueError('input_budget')
 return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda _:(_ for _ in()).throw(ValueError('nonfinite')))
def main():
 p=argparse.ArgumentParser();p.add_argument('--events',type=Path,required=True);p.add_argument('--source-revision',required=True);p.add_argument('--producer-sha256',required=True);a=p.parse_args()
 with a.events.open('rb')as f:raw=f.read(65537)
 print(json.dumps(describe(decode(raw),a.source_revision,a.producer_sha256),sort_keys=True))
if __name__=='__main__':main()
