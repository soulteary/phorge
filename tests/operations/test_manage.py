import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import time
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('operations', Path(__file__).resolve().parents[2]/'scripts/operations/manage.py')
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)


class SafetyTests(unittest.TestCase):
    def bundle(self, root):
        (root/'database.sql').write_text('CREATE DATABASE test;')
        with tarfile.open(root/'volume-0.tar', 'w') as t:
            payload=b'file data'
            member=tarfile.TarInfo('file'); member.size=len(payload)
            t.addfile(member, io.BytesIO(payload))
        manifest={'format':1,'state':'complete','createdAt':'2026-10-07T00:00:00+00:00',
                  'mysqlImage':'sha256:'+'a'*64,'databases':['test'], 'tablesPerDatabase':{'test':0},
                  'volumes':[{'artifact':'volume-0.tar','sourceName':'fixture'}],
                  'files':{n:ops.sha(root/n) for n in ['database.sql','volume-0.tar']}}
        ops.save_json(root/'manifest.json',manifest)
        return manifest

    def test_corrupt_sql_blocks_restore_before_docker(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); self.bundle(root)
            (root/'database.sql').write_text('changed')
            with patch.object(ops,'command') as docker:
                with self.assertRaises(ValueError): ops.restore_test(type('Args',(),{'directory':tmp})())
                docker.assert_not_called()

    def test_incomplete_backup_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); m=self.bundle(root); m['state']='incomplete';ops.save_json(root/'manifest.json',m)
            with self.assertRaises(ValueError): ops.verify(root)

    def test_archive_path_and_symlink_escape_refused(self):
        for name, link in [('../outside',''),('escape','/etc')]:
            with tempfile.TemporaryDirectory() as tmp:
                p=Path(tmp)/'bad.tar'
                with tarfile.open(p,'w') as t:
                    member=tarfile.TarInfo(name)
                    if link: member.type=tarfile.SYMTYPE;member.linkname=link
                    t.addfile(member)
                with self.assertRaises(ValueError):ops.safe_tar(p)

    def test_manifest_path_escape_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);m=self.bundle(root);m['files']['../outside']='0'*64;ops.save_json(root/'manifest.json',m)
            with self.assertRaises(ValueError):ops.verify(root)

    def test_untrusted_sql_identifier_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);m=self.bundle(root);m['databases']=["bad' SQL"];m['tablesPerDatabase']={"bad' SQL":0};ops.save_json(root/'manifest.json',m)
            with self.assertRaises(ValueError):ops.verify(root)

    def test_archive_keeps_source_and_verifies_copy(self):
        with tempfile.TemporaryDirectory() as tmp:
            source=Path(tmp)/'source';source.mkdir();self.bundle(source)
            destination=Path(tmp)/'archive'
            ops.archive_bundle(source,destination)
            self.assertTrue((source/'database.sql').exists())
            ops.verify(destination)

    def test_replaced_identity_does_not_report_growth(self):
        now=time.time()
        def audit(epoch,identity,size):return {'generatedEpoch':epoch,'inventory':{'persistentCapacity':{'data':{'inbox':{'state':'observed','physicalDatabaseIdentity':identity,'data':{'records':1,'approximateDataBytes':size,'approximateIndexBytes':0}}}}}}
        r=ops.evaluate(audit(now,'new',200),audit(now-86400,'old',100),now=now)
        self.assertNotIn('netAllocatedBytesPerDay',r['capacity'][0])
        r=ops.evaluate(audit(now,'same',200),audit(now-86400,'same',100),now=now)
        self.assertEqual(r['capacity'][0]['netAllocatedBytesPerDay'],100)

    def test_unknown_overdue_truncation_and_missing_observation_alert(self):
        r=ops.evaluate({'generatedEpoch':100,'service':{'state':'unavailable'},'health':{'unknownEffects':1,'oldestOverdueSeconds':301,'truncated':True}},now=100)
        codes={a['code'] for a in r['alerts']}
        self.assertTrue({'UNKNOWN_EFFECT','BACKLOG_OVERDUE','CAPACITY_PARTIAL','OBSERVATION_UNAVAILABLE'} <= codes)

    def test_future_and_old_audits_alert(self):
        for epoch in [1,2000]:
            r=ops.evaluate({'generatedEpoch':epoch},now=1000)
            self.assertIn('AUDIT_STALE',{a['code'] for a in r['alerts']})

    def test_zero_available_space_is_critical(self):
        for usage in [{'data':{'availableBytes':0}}, {'availableBytes':0}]:
            r=ops.evaluate({'generatedEpoch':100,'uploadUsage':usage},now=100)
            self.assertIn('VOLUME_LOW_SPACE',{a['code'] for a in r['alerts']})

    def test_volume_bytes_and_permissions_detect_restore_damage(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);self.bundle(root)
            before=ops.tar_inventory(root/'volume-0.tar')
            with tarfile.open(root/'volume-1.tar','w') as t:
                member=tarfile.TarInfo('file');member.size=9;member.mode=0o600
                t.addfile(member,io.BytesIO(b'bad-bytes'))
            self.assertNotEqual(before,ops.tar_inventory(root/'volume-1.tar'))

    def test_backup_alerts_require_matching_restore_receipt(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);self.bundle(root)
            r=ops.evaluate({'generatedEpoch':100},now=100,bundle=root)
            self.assertIn('RESTORE_UNVERIFIED',{a['code'] for a in r['alerts']})
            ops.save_json(root/'restore-test.json',{'state':'restore-tested','testedAt':'2026-10-07T00:00:00+00:00','manifestSHA256':'bad'})
            r=ops.evaluate({'generatedEpoch':100},now=100,bundle=root)
            self.assertIn('RESTORE_UNVERIFIED',{a['code'] for a in r['alerts']})

    def test_disabled_upload_capacity_does_not_raise_false_alarm(self):
        report={'generatedEpoch':100,'uploadUsage':{'state':'unavailable'},'services':{'file':{'uploadObservation':{'state':'observed','capabilities':{'enabled':False}}}}}
        r=ops.evaluate(report,now=100)
        self.assertNotIn('OBSERVATION_UNAVAILABLE',{a['code'] for a in r['alerts']})
        report['services']['file']['uploadObservation']['state']='unavailable'
        r=ops.evaluate(report,now=100)
        self.assertIn('OBSERVATION_UNAVAILABLE',{a['code'] for a in r['alerts']})

    def test_no_business_delete_is_authorized(self):
        self.assertFalse(ops.evaluate({'generatedEpoch':100},now=100)['businessArchive']['onlineDeletionAllowed'])

    def test_backup_requires_exclusive_access_before_docker(self):
        with patch.object(ops,'command') as docker:
            with self.assertRaises(ValueError):ops.backup(type('Args',(),{'exclusive_access':False})())
            docker.assert_not_called()

    def test_wait_mysql_times_out_when_initialization_server_keeps_responding(self):
        with patch.object(ops, 'sql', return_value='1'), patch.object(ops.time, 'monotonic', side_effect=[0, 1, 181]), patch.object(ops.time, 'sleep') as sleep:
            with self.assertRaisesRegex(RuntimeError, 'readiness timeout'):
                ops.wait_mysql('fixture')
            sleep.assert_called_once_with(1)

    def test_cleanup_continues_after_container_removal_failure(self):
        with patch.object(ops, 'command', side_effect=[RuntimeError('failed'), b'', b'']) as docker:
            with self.assertRaisesRegex(RuntimeError, 'Cleanup incomplete'):
                ops.cleanup_resources('fixture', ['first', 'second'])
            self.assertEqual(docker.call_count, 3)
            self.assertEqual(docker.call_args_list[-1].args[0], ['docker', 'volume', 'rm', 'first'])

    def test_concurrent_backup_is_refused(self):
        with ops.backup_lock('unit-test-database'):
            with self.assertRaisesRegex(ValueError, 'already running'):
                with ops.backup_lock('unit-test-database'):
                    self.fail('Concurrent backup entered')
        with ops.backup_lock('unit-test-database'):
            pass

    def test_malformed_volume_inventory_is_refused_before_docker(self):
        for volumes in [None, [{'artifact':'database.sql','sourceName':'fixture'}],
                        [{'artifact':'volume-0.tar','sourceName':'fixture'}]*2]:
            with tempfile.TemporaryDirectory() as tmp:
                root=Path(tmp); manifest=self.bundle(root);manifest['volumes']=volumes
                ops.save_json(root/'manifest.json',manifest)
                with patch.object(ops,'command') as docker:
                    with self.assertRaises(ValueError):
                        ops.restore_test(type('Args',(),{'directory':tmp})())
                    docker.assert_not_called()

    def test_future_restore_receipt_is_critical(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);self.bundle(root)
            ops.save_json(root/'restore-test.json',{'format':1,'state':'restore-tested','testedAt':'2099-01-01T00:00:00+00:00','manifestSHA256':ops.sha(root/'manifest.json'),'databases':1,'volumes':1,'businessIntegrity':'not_verified','scope':'checksum-volume-bytes-and-permissions-sql-import-schema-counts'})
            result=ops.evaluate({'generatedEpoch':100},now=100,bundle=root)
            self.assertIn('RESTORE_TEST_FUTURE',{a['code'] for a in result['alerts']})

    def test_string_backlog_counter_is_detected(self):
        result=ops.evaluate({'generatedEpoch':100,'health':{'oldestOverdueSeconds':'301'}},now=100)
        self.assertIn('BACKLOG_OVERDUE',{a['code'] for a in result['alerts']})

    def test_failed_backup_restarts_every_original_writer(self):
        mysql={'Id':'database','Image':'sha256:'+'a'*64}
        containers=[{'Id':'database','State':{'Running':True}},
                    {'Id':'writer-one','State':{'Running':True}},
                    {'Id':'writer-two','State':{'Running':True}},
                    {'Id':'stopped','State':{'Running':False}}]
        for c in containers: c['Image']=mysql['Image']
        calls=[]
        def docker(args, **kwargs):
            calls.append(args)
            if args[1]=='stop': raise RuntimeError('Stop failed')
            if args[1]=='start' and args[-1]=='writer-one': raise RuntimeError('Start failed')
            return b''
        with tempfile.TemporaryDirectory() as tmp, patch.object(ops.Stack,'inspect',return_value=(containers,mysql,{})), patch.object(ops,'sql',side_effect=['event_scheduler\tOFF','0']), patch.object(ops,'command',side_effect=docker):
            args=type('Args',(),{'exclusive_access':True,'compose':['unused.yml'],'directory':str(Path(tmp)/'backup')})()
            with self.assertRaisesRegex(RuntimeError,'restart incomplete'):
                ops.backup_locked(args)
            self.assertEqual([c[-1] for c in calls if c[1]=='start'],['writer-one','writer-two'])
            manifest=json.loads((Path(tmp)/'backup'/'manifest.json').read_text())
            self.assertEqual(manifest['state'],'incomplete')
            self.assertEqual(manifest['originalRunningContainers'],['writer-one','writer-two'])

    def test_same_project_untracked_volume_writer_is_refused(self):
        labels={'com.docker.compose.project':'fixture','com.docker.compose.service':'mysql'}
        mysql={'Id':'database','State':{'Running':True},'Config':{'Labels':labels},
               'Mounts':[{'Type':'volume','Name':'shared','RW':True}]}
        outsider={'Id':'oneoff','Config':{'Labels':labels},'Mounts':[{'Name':'shared','RW':True}]}
        with patch.object(ops,'command',side_effect=[b'database',json.dumps([mysql]).encode(),b'database oneoff',json.dumps([mysql,outsider]).encode()]):
            with self.assertRaisesRegex(ValueError,'untracked'):
                ops.Stack(['unused.yml']).inspect()

    def test_missing_audit_sections_are_critical(self):
        result=ops.evaluate({'generatedEpoch':100},now=100)
        self.assertIn('AUDIT_INCOMPLETE',{a['code'] for a in result['alerts']})

    def test_missing_prior_timestamp_suppresses_growth(self):
        result=ops.evaluate({'generatedEpoch':100}, {'generatedEpoch':None},now=100)
        self.assertEqual(result['capacity'],[])

    def test_file_path_outside_volume_is_refused(self):
        mysql={'Id':'database','State':{'Running':True},'Config':{'Labels':{'com.docker.compose.service':'mysql'}},'Mounts':[]}
        writer={'Id':'files','State':{'Running':True},'Config':{'Env':['GORGE_FILE_LOCAL_DISK_PATH=/unmounted/files']},'Mounts':[]}
        with patch.object(ops,'command',side_effect=[b'database files',json.dumps([mysql,writer]).encode()]):
            with self.assertRaisesRegex(ValueError,'outside captured volumes'):
                ops.Stack(['unused.yml']).inspect()

    def test_archive_rejects_unsuccessful_restore_receipt(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'source';root.mkdir();self.bundle(root)
            ops.save_json(root/'restore-test.json',{'state':'failed','manifestSHA256':ops.sha(root/'manifest.json')})
            destination=Path(tmp)/'archive'
            with self.assertRaisesRegex(ValueError,'restore receipt'):
                ops.archive_bundle(root,destination)
            self.assertFalse(destination.exists())

    def valid_receipt(self, root):
        return {'format':1,'state':'restore-tested','testedAt':'2026-10-07T00:01:00+00:00',
                'manifestSHA256':ops.sha(root/'manifest.json'),'databases':1,'volumes':1,
                'businessIntegrity':'not_verified','scope':'checksum-volume-bytes-and-permissions-sql-import-schema-counts'}

    def test_restore_receipt_validates_scope_inventory_and_time(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);manifest=self.bundle(root)
            for changes in [{'volumes':2}, {'businessIntegrity':'verified'}, {'format':True},
                            {'testedAt':'2026-10-06T00:00:00+00:00'}, {'testedAt':'2026-10-07T00:01:00'}]:
                receipt=self.valid_receipt(root);receipt.update(changes)
                ops.save_json(root/'restore-test.json',receipt)
                with self.assertRaises(ValueError):ops.read_receipt(root,manifest)
            ops.save_json(root/'restore-test.json',self.valid_receipt(root))
            self.assertEqual(ops.read_receipt(root,manifest)['state'],'restore-tested')

    def test_timezone_missing_manifest_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);manifest=self.bundle(root);manifest['createdAt']='2026-10-07T00:00:00'
            ops.save_json(root/'manifest.json',manifest)
            with self.assertRaises(ValueError):ops.verify(root)

    def test_save_json_does_not_follow_fixed_temporary_symlink(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);victim=root/'victim';victim.write_text('keep')
            (root/'credentials.tmp').symlink_to(victim)
            ops.save_json(root/'credentials.json',{'secret':'protected'})
            self.assertEqual(victim.read_text(),'keep')
            self.assertEqual((root/'credentials.json').stat().st_mode & 0o777,0o600)

    def test_nonfinite_boolean_and_huge_audit_timestamps_alert(self):
        for epoch in [float('nan'),float('inf'),True,10**1000]:
            result=ops.evaluate({'generatedEpoch':epoch},now=100)
            self.assertIn('AUDIT_STALE',{a['code'] for a in result['alerts']})

    def test_malformed_sections_do_not_crash_evaluator(self):
        for key in ['services','inventory','uploadUsage']:
            for value in [None,[],False,'untrusted']:
                result=ops.evaluate({'generatedEpoch':100,key:value},now=100)
                self.assertIn('AUDIT_INVALID',{a['code'] for a in result['alerts']})

    def test_missing_or_negative_capacity_is_not_zero(self):
        for data in [{}, {'records':0,'approximateDataBytes':-1,'approximateIndexBytes':0}]:
            result=ops.evaluate({'generatedEpoch':100,'inventory':{'persistentCapacity':{'data':{'table':{'state':'observed','data':data}}}}},now=100)
            self.assertEqual(result['capacity'],[])
            self.assertIn('CAPACITY_INVALID',{a['code'] for a in result['alerts']})

    def test_enabled_upload_without_space_observation_is_critical(self):
        result=ops.evaluate({'generatedEpoch':100,'services':{'file':{'uploadObservation':{'state':'observed','capabilities':{'enabled':True}}}}},now=100)
        self.assertIn('VOLUME_CAPACITY_UNOBSERVED',{a['code'] for a in result['alerts']})

    def test_invalid_domain_counter_is_critical(self):
        for value in ['²',-1,None,float('nan')]:
            result=ops.evaluate({'generatedEpoch':100,'health':{'oldestOverdueSeconds':value}},now=100)
            self.assertIn('COUNTER_INVALID',{a['code'] for a in result['alerts']})

    def test_docker_timeout_does_not_echo_sensitive_arguments(self):
        import subprocess
        with patch.object(ops.subprocess,'run',side_effect=subprocess.TimeoutExpired(['docker','SECRET'],1)):
            with self.assertRaisesRegex(RuntimeError,'timed out') as result:
                ops.command(['docker','SECRET'],timeout=1)
            self.assertNotIn('SECRET',str(result.exception))

    def test_helper_failure_attempts_resource_cleanup(self):
        with patch.object(ops,'command',side_effect=[RuntimeError('failed'),b'']) as docker:
            with self.assertRaises(RuntimeError):ops.helper_run(['docker','run','--rm','fixture'])
            run=docker.call_args_list[0].args[0]
            self.assertEqual(docker.call_args_list[1].args[0],['docker','rm','-f',run[3]])

    def test_database_change_after_lock_is_refused_before_stop(self):
        with patch.object(ops.Stack,'inspect',return_value=([],{'Id':'replacement'},{})),patch.object(ops,'command') as docker:
            with self.assertRaisesRegex(ValueError,'container changed'):
                ops.backup_locked(type('Args',(),{'exclusive_access':True,'compose':['unused']})(),expected_mysql='original')
            docker.assert_not_called()

    def test_archive_source_destination_overlap_is_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);self.bundle(root)
            with self.assertRaisesRegex(ValueError,'overlap'):
                ops.archive_bundle(root,root/'nested')

    def test_complete_report_has_no_schema_false_alarm(self):
        domains=['scheduler','cleanup','files','deletions','queue','feedOutbox','mailOutbox','inboundReceipts','persistentCapacity','fact']
        report={'reportVersion':1,'generatedEpoch':100,'services':{name:{'configured':False} for name in ['render','conduit','image','file','taskqueue','webhook','db','mailer','search','integrations']},
                'inventory':{d:{'state':'observed','data':{} if d=='persistentCapacity' else []} for d in domains}}
        result=ops.evaluate(report,now=100)
        self.assertEqual({a['code'] for a in result['alerts']},{'BACKUP_UNOBSERVED'})

    def test_stopped_phorge_config_can_be_inspected(self):
        mysql={'Id':'database','State':{'Running':True},'Config':{'Labels':{'com.docker.compose.service':'mysql'}},'Mounts':[]}
        phorge={'Id':'web','State':{'Running':False},'Config':{'Labels':{'com.docker.compose.service':'phorge'}},'Mounts':[{'Type':'volume','Name':'conf','Destination':'/opt/phorge/phorge/conf/local','RW':True}]}
        copied=io.BytesIO()
        with tarfile.open(fileobj=copied,mode='w') as t:
            m=tarfile.TarInfo('local.json');m.size=2;t.addfile(m,io.BytesIO(b'{}'))
        with patch.object(ops,'command',side_effect=[b'database web',json.dumps([mysql,phorge]).encode(),b'database',json.dumps([mysql]).encode(),copied.getvalue()]) as docker:
            ops.Stack(['unused']).inspect()
            self.assertEqual(docker.call_args.args[0][1],'cp')

    def test_quiescence_rejects_restart_and_event_activation(self):
        mysql={'Id':'db','State':{'Running':True}}
        stopped={'Id':'app','State':{'Running':False}}
        running={'Id':'app','State':{'Running':True}}
        with patch.object(ops.Stack,'inspect',return_value=([mysql,running],mysql,{})):
            with self.assertRaisesRegex(ValueError,'restarted'):
                ops.assert_quiescent(ops.Stack(['unused']),[mysql,stopped],mysql,{})
        with patch.object(ops.Stack,'inspect',return_value=([mysql,stopped],mysql,{})),patch.object(ops,'sql',side_effect=['event_scheduler\tON','1']):
            with self.assertRaisesRegex(ValueError,'event writer'):
                ops.assert_quiescent(ops.Stack(['unused']),[mysql,stopped],mysql,{})

    def test_scheduler_identity_mismatch_and_missing_queue_identity_alert(self):
        result=ops.evaluate({'generatedEpoch':100,'schedulerIdentityMatch':False,'services':{'taskqueue':{'configured':True}}},now=100)
        self.assertTrue({'SCHEDULER_IDENTITY_MISMATCH','QUEUE_IDENTITY_UNOBSERVED'} <= {a['code'] for a in result['alerts']})

    def test_deterministic_root_schema_fuzz_does_not_crash(self):
        import random
        randomizer=random.Random(17)
        values=[None,False,True,0,-1,100,'0','bad',[],{},float('nan'),float('inf'),10**1000]
        keys=['generatedEpoch','services','inventory','uploadUsage','queuePhysicalIdentityMatch','reportVersion','health']
        for case in range(1000):
            report={'generatedEpoch':100}
            for key in randomizer.sample(keys,randomizer.randrange(1,5)):
                report[key]=randomizer.choice(values)
            with self.subTest(case=case):
                result=ops.evaluate(report,now=100)
                self.assertTrue(any(a['severity']=='critical' for a in result['alerts']))

    def test_cli_invalid_capacity_does_not_echo_input(self):
        import contextlib
        with tempfile.TemporaryDirectory() as tmp:
            audit=Path(tmp)/'audit.json'
            audit.write_text(json.dumps({'generatedEpoch':100,'uploadUsage':{'availableBytes':'SECRET-CREDENTIAL'}}))
            stdout=io.StringIO();stderr=io.StringIO()
            with patch('sys.argv',['ops','report',str(audit)]),contextlib.redirect_stdout(stdout),contextlib.redirect_stderr(stderr):
                self.assertEqual(ops.main(),2)
            self.assertNotIn('SECRET-CREDENTIAL',stdout.getvalue()+stderr.getvalue())

    def test_mount_shadowing_refuses_unbacked_file_path(self):
        container={'Mounts':[{'Type':'volume','Destination':'/data'}, {'Type':'tmpfs','Destination':'/data/uploads'}]}
        with self.assertRaises(ValueError):ops.require_volume_path(container,'/data/uploads/objects','upload root')
        ops.require_volume_path(container,'/data/files','file root')

    def test_restore_rejects_source_mutation_before_issuing_receipt(self):
        for change in ['sql','manifest']:
            with tempfile.TemporaryDirectory() as tmp:
                root=Path(tmp);manifest=self.bundle(root);manifest['volumes']=[]
                ops.save_json(root/'manifest.json',manifest)
                def docker(args,**kwargs):
                    if args[:3]==['docker','exec','-i']:
                        if change=='sql':(root/'database.sql').write_text('changed')
                        else:
                            manifest['mysqlImage']='sha256:'+'b'*64
                            ops.save_json(root/'manifest.json',manifest)
                    return b''
                with patch.object(ops,'command',side_effect=docker),patch.object(ops,'sql',side_effect=['0','0']):
                    with self.assertRaises(ValueError):
                        ops.restore_test(type('Args',(),{'directory':tmp})())
                self.assertFalse((root/'restore-test.json').exists())
                self.assertFalse(list(root.glob('.phorge-restore-*-resources.json')))

    def test_archive_interrupt_removes_partial_destination(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'source';root.mkdir();self.bundle(root)
            with patch.object(ops.shutil,'copyfile',side_effect=KeyboardInterrupt):
                with self.assertRaises(KeyboardInterrupt):ops.archive_bundle(root,Path(tmp)/'archive')
            self.assertFalse(list(Path(tmp).glob('archive.partial-*')))


if __name__=='__main__':unittest.main()
