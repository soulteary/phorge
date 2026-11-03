import copy
import importlib.util
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('measurement',ROOT/'support/governance/revocation-measurement/consumer.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

def event():
    return {'schema':1,'source_revision':'a'*40,'producer_sha256':'b'*64,'family':'media',
        'clock':{'kind':'monotonic_ns','boot_id_sha256':'c'*64},
        'transition':{'id':'d'*64,'action':'disable','native_commit_id_sha256':'e'*64,'boundary':'native-transaction-operation-call-return','operation_start_ns':90,'commit_confirmed_return_ns':100},
        'attempts':[]}

def attempt(i,start,end,outcome):
    return {'id':i,'transition_id':'d'*64,'boot_id_sha256':'c'*64,'start_ns':start,'finish_ns':end,'outcome':outcome}

class RevocationMeasurementTest(unittest.TestCase):
    def test_first_denial_is_bracketed_not_convergence_or_sla(self):
        e=event();e['attempts']=[attempt(1,99,110,'denied'),attempt(2,111,115,'transport-error'),attempt(3,120,140,'denied'),attempt(4,145,150,'allowed')]
        r=m.describe_observation(e)
        self.assertEqual(r['first_denied_observation'],{'attempt_id':3,'from_commit_confirmed_return_ns':[20,40],'from_actual_commit_bracket_ns':[20,50]})
        self.assertTrue(r['later_allowed_observed']);self.assertFalse(r['convergence_proved']);self.assertFalse(r['measurement_acceptance']);self.assertFalse(r['SLA_proved'])

    def test_no_denial_and_transport_error_do_not_become_revocation(self):
        e=event();e['attempts']=[attempt(1,101,110,'unknown'),attempt(2,111,112,'transport-error')]
        self.assertIsNone(m.describe_observation(e)['first_denied_observation'])

    def test_cross_boot_or_other_transition_never_produces_interval(self):
        for key,value in [('boot_id_sha256','f'*64),('transition_id','f'*64)]:
            e=event();e['attempts']=[attempt(1,101,102,'denied')];e['attempts'][0][key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):m.describe_observation(e)

    def test_clock_rollback_bool_and_unknown_raw_fields_rejected(self):
        mutations=[lambda e:e['transition'].update(commit_confirmed_return_ns=80),lambda e:e['transition'].update(commit_confirmed_return_ns=True),lambda e:e.update(raw_token='private'),lambda e:e.update(attempts=[attempt(1,110,100,'denied')])]
        for change in mutations:
            e=event();change(e)
            with self.assertRaises(ValueError):m.describe_observation(e)

    def test_reenable_is_new_transition_not_old_denial_convergence(self):
        e=event();e['transition']['action']='reenable';e['transition']['id']='f'*64;e['attempts']=[attempt(1,101,102,'allowed')]
        with self.assertRaises(ValueError):m.describe_observation(e)
        e['attempts'][0]['transition_id']='f'*64
        self.assertIsNone(m.describe_observation(e)['first_denied_observation'])

    def test_overlapping_requests_cannot_invert_first_observation(self):
        e=event();e['attempts']=[attempt(1,110,300,'denied'),attempt(2,120,150,'allowed')]
        with self.assertRaises(ValueError):m.describe_observation(e)

    def test_missing_commit_and_duplicate_keys_cannot_replay_boolean_receipt(self):
        with self.assertRaises(ValueError):m.describe_observation({'passed':True})
        with self.assertRaises(ValueError):m.strict_object([('schema',1),('schema',1)])


class NativeQueryEnvelopeTest(unittest.TestCase):
    def sample(self):
        e=event();e['family']='app-session';e['attempts']=[attempt(1,60,70,'allowed'),attempt(2,110,115,'denied'),attempt(3,120,125,'denied')]
        return {'profile':'native-session-authenticated-project-query-only','actual_source_checked':True,
            'actual_independent_query_processes':3,'HTTP_proved':False,'gateway_proved':False,
            'long_connection_proved':False,'SLA_proved':False,'convergence_proved':False,
            'event':e,'native_source_binding_sha256':'f'*64}

    def test_source_bound_contract_cannot_claim_execution_from_json(self):
        r=m.describe_native_query_receipt(self.sample(),'a'*40,'b'*64)
        self.assertFalse(r['measurement_acceptance'])
        self.assertEqual(r['producer_attestation'],'source-bound-envelope-execution-attestation-required')
        with self.assertRaises(ValueError):m.describe_native_query_receipt(self.sample(),'f'*40,'b'*64)

    def test_native_control_and_scope_flags_cannot_be_promoted(self):
        for key in ('HTTP_proved','gateway_proved','SLA_proved'):
            e=self.sample();e[key]=True
            with self.assertRaises(ValueError):m.describe_native_query_receipt(e,'a'*40,'b'*64)
        e=self.sample();e['event']['attempts'][0]['outcome']='denied'
        with self.assertRaises(ValueError):m.describe_native_query_receipt(e,'a'*40,'b'*64)


class NativeTokenEnvelopeTest(unittest.TestCase):
    def sample(self):
        e=event();e['schema']=2;e['family']='api-token';e['transition']['action']='revoke'
        e['transition']['boundary']='native-token-dao-delete-own-transaction'
        del e['transition']['native_commit_id_sha256'];e['transition']['operation_binding_sha256']='d'*64
        e['attempts']=[attempt(1,60,70,'allowed'),attempt(2,110,115,'denied'),attempt(3,120,125,'denied')]
        return {'profile':'native-conduit-token-revoke-authentication-only','actual_source_checked':True,
            'actual_independent_authentication_processes':3,'token_rows_deleted':1,
            'native_transaction_record_available':False,'HTTP_proved':False,'gateway_proved':False,
            'SLA_proved':False,'convergence_proved':False,'event':e,
            'before_source_binding_sha256':'e'*64,'after_source_binding_sha256':'f'*64}

    def test_token_v2_stays_separate_from_query_v1_and_native_record_claim(self):
        r=m.describe_native_token_receipt(self.sample(),'a'*40,'b'*64)
        self.assertEqual(r['schema'],2);self.assertFalse(r['measurement_acceptance'])
        e=self.sample();e['native_transaction_record_available']=True
        with self.assertRaises(ValueError):m.describe_native_token_receipt(e,'a'*40,'b'*64)
        e=self.sample();e['event']['transition']['native_commit_id_sha256']='f'*64
        with self.assertRaises(ValueError):m.describe_native_token_receipt(e,'a'*40,'b'*64)

    def test_unknown_authentication_or_missing_delete_proof_cannot_pass(self):
        e=self.sample();e['event']['attempts'][1]['outcome']='unknown'
        with self.assertRaises(ValueError):m.describe_native_token_receipt(e,'a'*40,'b'*64)
        for count in (True,0,2):
            e=self.sample();e['token_rows_deleted']=count
            with self.assertRaises(ValueError):m.describe_native_token_receipt(e,'a'*40,'b'*64)
        e=self.sample();e['after_source_binding_sha256']=e['before_source_binding_sha256']
        with self.assertRaises(ValueError):m.describe_native_token_receipt(e,'a'*40,'b'*64)


class NativeHTTPEnvelopeTest(unittest.TestCase):
    def sample(self):
        e=NativeTokenEnvelopeTest().sample()['event'];e['schema']=3
        e['transition']['boundary']='native-token-dao-delete-http-new-requests'
        return {'profile':'native-conduit-http-token-revocation-only','HTTP_proved':True,
            'gateway_proved':False,'long_connection_proved':False,'SLA_proved':False,'convergence_proved':False,
            'actual_HTTP_requests':4,'actual_independent_HTTP_processes':3,'native_parse_error_control_observed':True,
            'token_rows_deleted':1,'native_transaction_record_available':False,
            'server_cleanup':{'started':3,'stopped':3,'directories_removed':3,'remaining':0,'live':0},
            'router_sha256':'e'*64,'before_source_binding_sha256':'e'*64,'after_source_binding_sha256':'f'*64,'event':e}

    def test_actual_HTTP_shape_does_not_promote_gateway_or_method_only_profile(self):
        r=m.describe_native_http_receipt(self.sample(),'a'*40,'b'*64,'e'*64)
        self.assertFalse(r['measurement_acceptance']);self.assertFalse(r['SLA_proved'])
        with self.assertRaises(ValueError):m.describe_native_token_receipt(self.sample(),'a'*40,'b'*64)
        for key,value in [('HTTP_proved',False),('gateway_proved',True),('native_parse_error_control_observed',False),('actual_independent_HTTP_processes',True)]:
            item=self.sample();item[key]=value
            with self.subTest(key=key),self.assertRaises(ValueError):m.describe_native_http_receipt(item,'a'*40,'b'*64,'e'*64)

    def test_unknown_denial_changed_router_and_incomplete_cleanup_refused(self):
        item=self.sample();item['event']['attempts'][1]['outcome']='transport-error'
        with self.assertRaises(ValueError):m.describe_native_http_receipt(item,'a'*40,'b'*64,'e'*64)
        with self.assertRaises(ValueError):m.describe_native_http_receipt(self.sample(),'a'*40,'b'*64,'f'*64)
        item=self.sample();item['server_cleanup']['stopped']=2
        with self.assertRaises(ValueError):m.describe_native_http_receipt(item,'a'*40,'b'*64,'e'*64)
