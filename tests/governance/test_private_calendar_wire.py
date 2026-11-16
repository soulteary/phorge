"""Actual test-only binding, owned publication and cleanup controls; no SQL."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
import sys
sys.path.insert(0,str(ROOT/'scripts/governance'))
import check_recovery_file_parent_policy as driver_admission

class PrivateCalendarWireHostControls(unittest.TestCase):
    def invoke(self, name, count, native_factory=False):
        result = subprocess.run(
            ['php', *(['-d', 'disable_functions=mysqli_init,mysqli_connect,stream_socket_client,fsockopen,pfsockopen,socket_connect'] if native_factory else []), str(ROOT / 'support/governance/notification/tests' / name)],
            capture_output=True, text=True, check=True, timeout=15)
        self.assertEqual('', result.stderr)
        value = json.loads(result.stdout)
        self.assertEqual(count, value['passed'])
        for key in ('SQL', 'SMTP', 'browser'):
            self.assertIs(False, value[key])
        return value

    def test_native_mail_occurrence_binding_positive_and_negative_controls(self):
        self.invoke('calendar-wire-binding-host.php', 32)

    def test_pre_ready_identity_private_filesystem_publication_controls(self):
        self.invoke('calendar-wire-cleanup-identity-host.php', 33)

    def test_pre_ready_identity_projects_only_owned_credential_free_fields(self):
        self.invoke('calendar-wire-identity-source-host.php', 2)

    def test_early_identity_publishes_before_any_post_create_operation(self):
        source = (ROOT / 'support/governance/native-organization/test-native-organization-mysql.php').read_text()
        begin = source.index('  $fixture->create();')
        end = source.index('  $summary = $fixture->getSummary();', begin)
        block = source[begin:end]
        self.assertIn('if($calendar_wire)', block)
        self.assertIn('governance_calendar_wire_publish_cleanup_identity(governance_calendar_wire_fixture_identity($fixture));', block)
        self.assertNotIn('start_gorge', block)
        self.assertNotIn('getAdmin', block)

    def test_actual_owned_filesystem_publication_and_no_overwrite(self):
        value = self.invoke('calendar-wire-publication-host.php', 33)
        self.assertIs(True, value['actual_filesystem'])

    def test_partial_start_primary_and_all_cleanup_failures_preserved(self):
        value = self.invoke('calendar-wire-lifecycle-host.php', 10)
        self.assertIs(False, value['actual_process_cleanup'])

    def test_real_native_factory_entry_without_DB_access(self):
        value = self.invoke('calendar-wire-factory-host.php', 2, native_factory=True)
        self.assertIs(True, value['actual_unsaved_native_factory'])

    def test_actual_regular_FIFO_and_replacement_safe_zero_write_reads(self):
        value = self.invoke('calendar-wire-reader-host.php', 29)
        self.assertIs(True, value['actual_regular_FIFO'])

    def test_native_browser_bootstrap_with_explicit_storage_collaborators(self):
        value = self.invoke('calendar-wire-browser-host.php', 17)
        self.assertIs(True, value['native_storage_collaborators'])

    def test_owned_private_profile_selects_only_two_native_event_routes(self):
        value = self.invoke('calendar-wire-profile-host.php', 23)
        self.assertIs(True, value['actual_private_FS_and_route'])

    def test_actual_queue_quiescence_adapter_keeps_notice_default_and_typed_ack(self):
        value = self.invoke('calendar-wire-quiesce-host.php', 11)
        self.assertIs(True, value['actual_owned_request_FS'])
        self.assertIs(False, value['actual_service_stop'])

    def test_fixed_driver_phase_and_exact_baseline_inverse_refuse_other_changes(self):
        import hashlib
        import sys
        sys.path.insert(0, str(ROOT / 'scripts/governance'))
        import check_private_calendar_reminders as gate
        name = 'support/governance/native-organization/test-native-organization-mysql.php'
        raw = driver_admission.admitted_native_driver_source(ROOT, (ROOT / name).read_bytes())
        base = subprocess.check_output(['git', '-C', str(ROOT), 'show',
            'a20b0e80a8ec79e369e43ce39a309993b6e639d6:' + name])
        self.assertEqual(base, gate.reduce_reviewed_calendar_wire_driver(raw))
        for changed in (raw + b'\n// unrelated driver change\n',
                        raw.replace(b'($calendar_wire??false)&&', b'true&&', 1),
                        raw.replace(b"require_once __DIR__.'/workers.php';", b'', 1)):
            pin = gate.SOURCE_INPUTS[name]
            try:
                gate.SOURCE_INPUTS[name] = hashlib.sha256(changed).hexdigest()
                with self.assertRaises(ValueError):
                    gate.reduce_reviewed_calendar_wire_driver(changed)
            finally:
                gate.SOURCE_INPUTS[name] = pin

    def test_actual_empty_native_worker_registry_has_six_exact_zero_fields(self):
        result = subprocess.run(['php', '-r',
            "require $argv[1].'/support/governance/native-organization/workers.php';"
            "echo json_encode(governance_native_cleanup_workers());", str(ROOT)],
            capture_output=True, text=True, check=True, timeout=15)
        self.assertEqual('', result.stderr)
        self.assertEqual(dict(private_directories_removed=0, owned_processes_stopped=0,
            cleanup_failures=0, private_directories_remaining=0,
            live_processes_after_cleanup=0, workers_registered=0), json.loads(result.stdout))

    def test_actual_driver_calendar_guard_retains_unknown_and_keeps_other_phases(self):
        code = r'''$task_browser=false;$root=$argv[1];$s=file_get_contents($root.'/support/governance/native-organization/test-native-organization-mysql.php');$a=strrpos($s,'  if($borrowed_unknown){');$b=strpos($s,'  if(!$borrowed_unknown){if ($control !== null)',$a);$block=substr($s,$a,$b-$a);class CleanupFixture{function cleanup(){$GLOBALS['drops']++;return[];}}$out=[];foreach([[true,false],[true,true],[true,1],[true,null],[false,false]]as$case){[$calendar_wire,$proof]=$case;$phase='calendar-reminder-wire-hold';$borrowed_unknown=false;$native_rr=false;$media_remote=false;$workbench_http=false;$notice_history=false;$GLOBALS['governance_workbench_http_cleanup_permitted']=$proof;$fixture=new CleanupFixture();$cleanup_failures=[];$checks=[];$drops=0;eval($block);$out[]=['drops'=>$drops,'failures'=>$cleanup_failures];}echo json_encode($out);'''
        result = subprocess.run(['php', '-r', code, str(ROOT)], capture_output=True,
            text=True, check=True, timeout=15)
        self.assertEqual('', result.stderr)
        rows = json.loads(result.stdout)
        self.assertEqual([0, 1, 0, 0, 1], [r['drops'] for r in rows])
        for index in (0, 2, 3):
            self.assertEqual('calendar-wire-parent-or-live-writer-cleanup-unproved',
                rows[index]['failures'][0]['stage'])

    def test_actual_driver_initialization_blocks_cleanup_on_early_startup_errors(self):
        source = (ROOT / 'support/governance/native-organization/test-native-organization-mysql.php').read_text()
        guard = "if($calendar_wire){$GLOBALS['governance_workbench_http_cleanup_permitted']=false;}"
        self.assertEqual(1, source.count(guard))
        self.assertLess(source.index(guard), source.index('$fixture = null;'))
        self.assertLess(source.index(guard), source.index("$stage='owned-real-gorge-file-service';"))
        code = r'''$task_browser=false;$s=file_get_contents($argv[1].'/support/governance/native-organization/test-native-organization-mysql.php');$a=strpos($s,'$calendar_wire=$phase===');$b=strpos($s,"$"."GLOBALS['governance_session_lease_cleanup']",$a);$init=substr($s,$a,$b-$a);$a=strrpos($s,'  if($borrowed_unknown){');$b=strpos($s,'  if(!$borrowed_unknown){if ($control !== null)',$a);$guard=substr($s,$a,$b-$a);class EarlyCleanupFixture{function cleanup(){$GLOBALS['drops']++;return[];}}$out=[];foreach(['calendar-reminder-wire-hold','calendar-reminders']as$phase){foreach(['file-start','queue-start','capture-start','password-provider','producer']as$stage){$fixture=new EarlyCleanupFixture();$borrowed_unknown=false;$native_rr=false;$media_remote=false;$notice_history=false;$cleanup_failures=[];$checks=[];$drops=0;$primary=new RuntimeException($stage);try{eval($init);throw $primary;}catch(Throwable $error){$same=$error===$primary;}finally{eval($guard);}$out[]=['phase'=>$phase,'stage'=>$stage,'drops'=>$drops,'same_primary'=>$same,'failures'=>$cleanup_failures];}}echo json_encode($out);'''
        result = subprocess.run(['php', '-r', code, str(ROOT)], capture_output=True,
            text=True, check=True, timeout=15)
        self.assertEqual('', result.stderr)
        rows = json.loads(result.stdout)
        self.assertEqual(10, len(rows))
        for row in rows:
            self.assertIs(True, row['same_primary'])
            calendar = row['phase'] == 'calendar-reminder-wire-hold'
            self.assertEqual(0 if calendar else 1, row['drops'])
            if calendar:
                self.assertEqual('calendar-wire-parent-or-live-writer-cleanup-unproved',
                    row['failures'][0]['stage'])
            else:
                self.assertEqual([], row['failures'])

    def test_actual_keeper_loop_requires_quiescence_then_canonical_SQL_zero(self):
        value = self.invoke('calendar-wire-release-host.php', 11)
        self.assertIs(True, value['actual_keeper_loop'])
        self.assertIs(True, value['service_SQL_collaborators'])

    def test_calendar_delegates_reverse_to_exact_default_history_config_and_router(self):
        additions = {
            'support/governance/notification/notice-history-browser.php': [(
                b"  $directory=getenv('PHORGE_GOVERNANCE_PRIVATE_MAIL_DIR');\n  if(is_string($directory)&&(file_exists($directory.'/calendar-browser.json')||is_link($directory.'/calendar-browser.json'))){\n    require_once __DIR__.'/calendar-wire-profile.php';$profile=governance_calendar_wire_selected_profile($directory);$allowed=array();\n    foreach($profile['events']as$event){$allowed[]=$event['path'];$allowed[]='/object/'.$event['event_phid'].'/';}\n    $history=in_array($path,$allowed,true);\n  }\n", b'')],
            'support/governance/notification/notice-history-browser-router.php': [(
                b"  if(($config['profile_kind']??null)!=='calendar-native-browser-v1'){governance_notice_history_manifest($config['directory'],$data);}",
                b"  governance_notice_history_manifest($config['directory'],$data);")],
            'support/governance/notification/notice-wire-config.php': [(
                b"  $base=governance_private_mail_config($directory);\n  require_once __DIR__.'/calendar-wire-profile.php';$calendar=governance_calendar_wire_selected_profile($directory);\n  if($calendar!==null){return governance_calendar_wire_config($directory,$base,$calendar);}\n  $extra=",
                b"  $base=governance_private_mail_config($directory);$extra="), (
                b"  if(($config['profile_kind']??null)==='calendar-native-browser-v1'){governance_calendar_wire_install_env($config);return;}\n", b'')],
        }
        for name, pairs in additions.items():
            raw = (ROOT / name).read_bytes()
            for added, old in pairs:
                self.assertEqual(1, raw.count(added), name)
                raw = raw.replace(added, old, 1)
            baseline = subprocess.check_output(['git', '-C', str(ROOT), 'show',
                'a20b0e80a8ec79e369e43ce39a309993b6e639d6:' + name])
            self.assertEqual(baseline, raw, name)
