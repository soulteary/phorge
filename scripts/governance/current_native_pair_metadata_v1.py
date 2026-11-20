"""Exact CURRENT collector metadata inverse; returned parent bytes are DATA only."""
import hashlib
CURRENT_SHA='5e12dada7162f8c7d696366b3307fa6e0f9fc4a5886f96b4875adc1123f4d853'
PARENT_SHA='c2c614ab497e6590c13455bd1b495f9f9230c62acd066884b738d460023be300'
BYTES=124910
RESTORED_BYTES=125897
RETIREMENT_EDITS=[(113343, "REQUIRED_CHECKS = REQUIRED_CHECKS | frozenset(['retired-wiki-mail-refused-policy-on','retired-wiki-mail-refused-policy-off'])\nNATIVE['private_mail_tests']['wiki_content_publication_tests'] = {'status':'retired-runtime-removed','historical_mail_refused':True}\n", "REQUIRED_CHECKS = REQUIRED_CHECKS | WIKI_CONTENT_CHECKS\nNATIVE['private_mail_tests']['wiki_content_publication_tests'] = {'actual_existing_wiki_content_editor': True, 'actual_paired_queue_and_native_handler': True, 'before_commit_probe_is_prepare_only': True, 'real_before_commit_execute_race_proved': False, 'move_mail_claimed': False, 'automatic_retry_after_permanent_source_failure': False, 'HTTP_gateway_proved': False, 'Go_daemon_proved': False, 'SMTP_proved': False, 'samples': {'normal': {'handler_result': 'success', 'safe_subscriber_mail': True}, 'outer-commit': {'handler_result': 'success', 'safe_subscriber_mail': True}, 'outer-rollback': {'handler_result': 'permanent-failure', 'safe_subscriber_mail': False}}, 'independent_php_workers_executed': 6, 'private_directories_removed': 6, 'private_directories_remaining': 0, 'live_processes_after_cleanup': 0, 'actual_paired55_prose_diff': True, 'missing_render_transient_no_mail_proved': True, 'render_cleanup': {'binary_sha256': 'd05250617dfa12f0b7cfc9108423b55a91ce9dd56224c96335f847deb40c22d2', 'source_revision': '16318f87a017f82c28f3011b362364cf24b73ef8', 'process_stopped': True, 'private_storage_removed': True}}\n"), (119592, '', "    expected_wiki['render_cleanup']['binary_sha256']=render_digest\n")]
CURRENT_LITERAL=b"'source_revision': '16318f87a017f82c28f3011b362364cf24b73ef8'"
PARENT_LITERAL=b"'source_revision': '83351b0e7eba38e50ff076ba6763ca68ebc57e1b'"
def parent_DATA(raw,expected_parent_sha):
 if type(raw)is not bytes or type(expected_parent_sha)is not str or expected_parent_sha!=PARENT_SHA or len(raw)!=BYTES or hashlib.sha256(raw).hexdigest()!=CURRENT_SHA:raise ValueError('current_native_pair_metadata_refused')
 parts=[];cursor=0
 for offset,now,old in RETIREMENT_EDITS:
  now=now.encode();old=old.encode()
  if raw[offset:offset+len(now)]!=now:raise ValueError('current_native_retirement_refused')
  parts.extend((raw[cursor:offset],old));cursor=offset+len(now)
 raw=b''.join(parts)+raw[cursor:]
 if raw.count(CURRENT_LITERAL)!=1:raise ValueError('current_native_pair_metadata_refused')
 prior=raw.replace(CURRENT_LITERAL,PARENT_LITERAL,1)
 if len(prior)!=RESTORED_BYTES or hashlib.sha256(prior).hexdigest()!=PARENT_SHA:raise ValueError('current_native_pair_metadata_refused')
 return prior

def current_DATA(parent):
 if type(parent)is not bytes or len(parent)!=RESTORED_BYTES or hashlib.sha256(parent).hexdigest()!=PARENT_SHA:raise ValueError('current_native_pair_metadata_refused')
 restored=parent.replace(PARENT_LITERAL,CURRENT_LITERAL,1)
 parts=[];cursor=delta=0
 for offset,now,previous in RETIREMENT_EDITS:
  now=now.encode();previous=previous.encode();start=offset+delta
  if restored[start:start+len(previous)]!=previous:raise ValueError('current_native_retirement_refused')
  parts.extend((restored[cursor:start],now));cursor=start+len(previous);delta+=len(previous)-len(now)
 current=b''.join(parts)+restored[cursor:]
 if len(current)!=BYTES or hashlib.sha256(current).hexdigest()!=CURRENT_SHA:raise ValueError('current_native_pair_metadata_refused')
 return current
