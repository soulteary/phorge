import copy
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import traceback
import unittest
from contextlib import redirect_stderr, redirect_stdout
from unittest.mock import patch
from types import SimpleNamespace

spec=importlib.util.spec_from_file_location('preflight',Path(__file__).resolve().parents[2]/'scripts/operations/preflight.py')
preflight=importlib.util.module_from_spec(spec);spec.loader.exec_module(preflight)

class ProductionGate(unittest.TestCase):
    def fixture(self):
        services={'gorge-'+n:{'environment':{'GORGE_SERVICE_TOKEN':'fixture'}} for n in
                  ['render','conduit','file-storage','webhook','taskqueue','worker','db-api','mailer','search','maintenance','image']}
        services['mysql']={'environment':{'MYSQL_USER':'application','MYSQL_PASSWORD':'app-password'}}
        services['gorge-db-api']['environment'].update(GORGE_DB_MYSQL_USER='diagnostic_ro',GORGE_DB_MYSQL_PASS='ro-password')
        services['gorge-mailer']['environment']['GORGE_MAILER_TYPE']='smtp'
        services['gorge-search']['environment']['ES_HOST']='search:9200'
        services['gorge-notification']={'ports':[{'host_ip':'127.0.0.1','target':22281}]}
        services['gorge-worker']['stop_grace_period']='45s'
        return {'services':services}

    def test_valid_configuration(self):
        self.assertEqual(preflight.validate(self.fixture()),'diagnostic_ro')

    def test_open_services_test_backends_and_reused_account_refused(self):
        mutations=[lambda s:s['gorge-render']['environment'].clear(),
                   lambda s:s['gorge-mailer']['environment'].update(GORGE_MAILER_TYPE='test'),
                   lambda s:s['gorge-search']['environment'].update(GORGE_SEARCH_BACKENDS='[{"type":"test","hosts":["test"]}]'),
                   lambda s:s['gorge-search']['environment'].update(ES_HOST=''),
                   lambda s:s['gorge-db-api']['environment'].update(GORGE_DB_MYSQL_USER='application'),
                   lambda s:s['gorge-db-api']['environment'].update(GORGE_DB_MYSQL_PASS='app-password'),
                   lambda s:s['gorge-notification']['ports'][0].update(host_ip='0.0.0.0')]
        for mutate in mutations:
            cfg=copy.deepcopy(self.fixture());mutate(cfg['services'])
            with self.assertRaises(ValueError):preflight.validate(cfg)

    def test_write_admin_roles_and_delegation_refused(self):
        self.assertTrue(preflight.check_grant("GRANT SELECT, SHOW VIEW ON `phabricator\\_%`.* TO 'ro'@'%'"))
        self.assertTrue(preflight.check_grant("GRANT REPLICATION CLIENT ON *.* TO 'ro'@'%'"))
        for g in ["GRANT ALL PRIVILEGES ON *.* TO 'ro'@'%'", "GRANT SELECT, INSERT ON db.* TO 'ro'@'%'",
                  "GRANT SELECT ON *.* TO 'ro'@'%' WITH GRANT OPTION", "GRANT `admin`@`%` TO `ro`@`%`",
                  "GRANT CONNECTION_ADMIN ON *.* TO 'ro'@'%'"]:
            self.assertFalse(preflight.check_grant(g))

    def test_worker_shutdown_budget_must_fit_container_grace(self):
        for grace,drain,passed in [('45s','30',True),('1m0s','45',True),
                                  ('10s','30',False),('45s','60',False),
                                  ('45s','0',False),('forever','30',False)]:
            cfg=self.fixture()
            cfg['services']['gorge-worker']['stop_grace_period']=grace
            cfg['services']['gorge-worker']['environment']['GORGE_WORKER_DRAIN_TIMEOUT_SEC']=drain
            with self.subTest(grace=grace,drain=drain):
                if passed:preflight.validate(cfg)
                else:
                    with self.assertRaises(ValueError):preflight.validate(cfg)

    def test_mail_receipts_require_distinct_backend_identity(self):
        for keys in [[],[''],[' \t'],['primary','primary']]:
            cfg=self.fixture()
            cfg['services']['gorge-mailer']['environment']['GORGE_MAILER_CONFIG']=json.dumps(
                [{'type':'smtp','key':key} for key in keys])
            with self.subTest(keys=keys):
                with self.assertRaises(ValueError):preflight.validate(cfg)

    def test_actual_account_audit_keeps_password_off_arguments_and_cleans(self):
        result={'grants':["GRANT SELECT, SHOW VIEW ON db.* TO 'ro'@'172.1.2.3'",
                          "GRANT REPLICATION CLIENT ON *.* TO 'ro'@'172.1.2.3'"],
                'mandatoryRoles':''}
        for change,passed in [(None,True),('writer',False),('role',False)]:
            value=dict(result,grants=list(result['grants']))
            if change=='writer':value['grants'][0]="GRANT SELECT, UPDATE ON db.* TO 'ro'@'172.1.2.3'"
            if change=='role':value['mandatoryRoles']='admin'
            calls=[]
            def run(args,**kwargs):
                calls.append((args,kwargs));return SimpleNamespace(returncode=0,stdout=json.dumps(value))
            with patch.object(preflight.subprocess,'run',side_effect=run):
                if passed:preflight.actual_grants('database-client','sha256:'+'a'*64,dict(host='mysql',user='ro',password='private-password',port=3306))
                else:
                    with self.assertRaises(ValueError):preflight.actual_grants('database-client','sha256:'+'a'*64,dict(host='mysql',user='ro',password='private-password',port=3306))
            self.assertIn('container:database-client',calls[0][0])
            self.assertNotIn('private-password',' '.join(calls[0][0]))
            self.assertIn('private-password',calls[0][1]['input'])
            self.assertEqual(calls[-1][0][:3],['docker','rm','-f'])

    def test_actual_account_failures_are_classified_without_sensitive_output(self):
        secret='private-password-do-not-print'
        credentials=dict(host='private-db-host',user='private-user',password=secret,port=3306)
        cases=[(10,'Database privilege helper requires the PHP mysqli extension'),
               (11,'Actual database credential connection failed'),
               (12,'Database privilege query failed'),
               (125,'Database privilege helper container could not start'),
               (126,'Database privilege helper container could not start'),
               (127,'Database privilege helper container could not start'),
               (2,'Database privilege helper execution failed'),
               (OSError(secret),'Database privilege helper container could not start'),
               (subprocess.TimeoutExpired(['docker',secret],30,output=secret,stderr=secret),
                'Database privilege helper timed out')]
        for failure,message in cases:
            calls=[]
            def run(args,**kwargs):
                calls.append((args,kwargs))
                if args[1]=='rm':return SimpleNamespace(returncode=0)
                if isinstance(failure,Exception):raise failure
                return SimpleNamespace(returncode=failure,stdout=json.dumps(credentials),stderr=secret)
            output=io.StringIO()
            with self.subTest(failure=message),patch.object(preflight.subprocess,'run',side_effect=run),\
                    redirect_stdout(output),redirect_stderr(output):
                try:
                    preflight.actual_grants('database-client','fixture-php',credentials)
                except ValueError as error:
                    self.assertEqual(str(error),message)
                    formatted=''.join(traceback.format_exception(type(error),error,error.__traceback__))
                    self.assertNotIn(secret,formatted)
                    self.assertNotIn(credentials['host'],formatted)
                    self.assertNotIn(credentials['user'],formatted)
                else:
                    self.fail('Helper failure was accepted')
            self.assertEqual(output.getvalue(),'')
            self.assertNotIn(secret,' '.join(calls[0][0]))
            self.assertIn(secret,calls[0][1]['input'])
            helper=calls[0][0][calls[0][0].index('--name')+1]
            self.assertEqual(calls[-1][0],['docker','rm','-f',helper])
            self.assertEqual(calls[-1][1]['timeout'],10)

    def test_invalid_privilege_response_does_not_echo_helper_output(self):
        secret='private-password-do-not-print'
        for stdout in [secret,json.dumps(secret),json.dumps({'grants':[secret],'mandatoryRoles':''})]:
            result=SimpleNamespace(returncode=0,stdout=stdout,stderr=secret)
            with self.subTest(stdout=stdout),patch.object(preflight.subprocess,'run',return_value=result) as run:
                with self.assertRaises(ValueError) as caught:
                    preflight.actual_grants('database-client','fixture-php',
                                            dict(host='mysql',user='ro',password=secret,port=3306))
            self.assertNotIn(secret,str(caught.exception))
            self.assertEqual(run.call_args_list[-1].args[0][:3],['docker','rm','-f'])

    def test_cleanup_error_preserves_safe_helper_failure(self):
        for cleanup_error in [OSError('private-password'),
                              subprocess.TimeoutExpired(['docker','private-password'],10)]:
            with self.subTest(cleanup_error=type(cleanup_error).__name__),\
                    patch.object(preflight.subprocess,'run',side_effect=[
                        SimpleNamespace(returncode=125,stdout='private-password',stderr='private-password'),
                        cleanup_error]) as run:
                with self.assertRaisesRegex(ValueError,'^Database privilege helper container could not start$'):
                    preflight.actual_grants('database-client','fixture-php',
                                            dict(host='mysql',user='ro',password='private-password',port=3306))
            self.assertEqual(run.call_args_list[-1].args[0][:3],['docker','rm','-f'])

if __name__=='__main__':unittest.main()
