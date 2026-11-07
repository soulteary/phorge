import copy,hashlib,json,os,subprocess,tempfile,types,unittest
from pathlib import Path
from unittest.mock import patch
import media_browser_native_measurement as m
import media_browser_account_switch_v3 as consumer
ROOT=Path(__file__).resolve().parents[2]
class Measurement(unittest.TestCase):
 def setup_native(self,root):
  rel=m.FIXTURE;p=root/rel;p.parent.mkdir(parents=True);p.write_bytes(b'<?php /* synthetic signed fixture bytes */\n');target=root/'target.php';target.write_bytes(b'<?php // target\n');(root/'alias.php').symlink_to('target.php')
  fs={rel:m.digest(p.read_bytes()),'target.php':m.digest(target.read_bytes()),'alias.php':m.digest(target.read_bytes())};aliases={'alias.php':'target.php'};ident={'dev':root.stat().st_dev,'ino':root.stat().st_ino};contract={'source_head':'a'*40,'source_tree':'b'*40,'app_origin':'https://app.synthetic.invalid','file_origin':'https://files.synthetic.invalid','transport_proof_sha256':m.digest(('c'*64).encode())}
  e={'files':3,'aliases':1,'manifest_sha256':m.digest(m.canonical(fs)),'aliases_sha256':m.digest(m.canonical(aliases)),'source_root_identity':ident,'native_tuple':contract,'local_forward_port':29091,'receiver_sha256':m.RECEIVER_SHA256}
  cfg={'owner':'d'*64,'app_origin':contract['app_origin'],'file_origin':contract['file_origin'],'transport_proof':'c'*64};records=[];commands=[]
  class Native:
   owner='d'*64
   b={'source_head':'a'*40,'source_tree':'b'*40,'source_files':fs,'source_aliases':aliases,'source_identity':ident,'ports':{'forward':29091},'transport_proof':'c'*64,'container':'e'*64}
   server_thread=types.SimpleNamespace(is_alive=lambda:True)
   server=types.SimpleNamespace(socket=types.SimpleNamespace(getsockname=lambda:('127.0.0.1',29091),fileno=lambda:3))
   def read_file(self,name):self_name=name;assert self_name=='router.json';return json.dumps(cfg).encode()
   def command(self,args,raw,timeout):
    commands.append(args);assert args[:2]==['exec','-i']and args[3:6]==['/usr/local/bin/php','-r',args[5]];code=args[-1];assert code.endswith('nm_main();')
    # Exact actual receiver functions/1ce filesystem verifier; only fixed guest
    # root/platform dispatch is a declared host collaborator (no VM).
    final="$raw=stream_get_contents(STDIN);nm_need(hash_equals(NM_REQUEST_SHA256,hash('sha256',$raw)));echo json_encode(nm_measure("+json.dumps(str(root))+",json_decode($raw,true,32,JSON_THROW_ON_ERROR)));"
    code=code[:-len('nm_main();')]+final
    r=subprocess.run(['php','-r',code],input=raw,capture_output=True,timeout=10)
    if r.returncode!=0:raise RuntimeError('host_php_measurement_refused')
    assert r.stderr==b'';return r.stdout
   def record(self,name,value):records.append((name,value))
  packet={'local_native_contract':contract,'local_forward_port':29091};proof={'passed':True,'native_fixture_owned':True,'whole_source_current':True,'browser_session_preseeded':False,'source_identity':ident,'forward_port':29091,**contract}
  return Native(),e,packet,proof,records,commands,cfg,fs
 def bindings(self,e,fs):
  pins={'source_revision':'a'*40,'fixture_sha256':fs[m.FIXTURE],'switch_sha256':'1'*64,'baseline_flow_sha256':'2'*64,'legacy_consumer_sha256':'3'*64,'normal_TLS_provider_sha256':'4'*64,'legacy_obligations_sha256':'5'*64}
  review=json.dumps({'scope':consumer.SCOPE,'approved':True,'source_pins':pins,'normal_TLS_required':True,'public_route_authorized':False,'deployment':False,'native_source':e}).encode()
  return patch.multiple(consumer,ROOT_REVIEW_BYTES=review,ROOT_REVIEW_SHA256=hashlib.sha256(review).hexdigest())
 def test_actual_fullSOURCE_and_fixture_doublehash_before_dispatch(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();n,e,p,proof,records,commands,cfg,fs=self.setup_native(root)
   with self.bindings(e,fs),patch.object(m,'FD_SOURCE_PATH',str(ROOT/'tests/governance/media_browser_fd_source.php')):
    seen=[];r=m.execute_measured_stage(n,p,proof,lambda actual:seen.append(actual)or'next-stage')
   self.assertEqual(r,'next-stage');self.assertEqual(len(commands),1);self.assertEqual(len(seen),1);self.assertEqual(seen[0]['actual_fixture_sha256'],fs[m.FIXTURE]);self.assertEqual(records[0][0],'actual-native-source-and-fixture-measurement');self.assertNotIn('transport_proof',seen[0])
 def test_real_file_mutation_unknown_file_and_alias_target_refuse_no_dispatch(self):
  for fault in ['fixture','extra','alias']:
   with self.subTest(fault=fault),tempfile.TemporaryDirectory()as tmp:
    root=Path(tmp).resolve();n,e,p,proof,records,commands,cfg,fs=self.setup_native(root)
    if fault=='fixture':(root/m.FIXTURE).write_bytes(b'changed')
    elif fault=='extra':(root/'unknown').write_bytes(b'extra')
    else:(root/'alias.php').unlink();(root/'alias.php').symlink_to('foreign.php')
    with self.bindings(e,fs),patch.object(m,'FD_SOURCE_PATH',str(ROOT/'tests/governance/media_browser_fd_source.php')):
     with self.assertRaises(RuntimeError):m.execute_measured_stage(n,p,proof,lambda x:self.fail('dispatch'))
    self.assertEqual(records,[])
 def test_actual_router_owner_origin_proof_and_live_port_fail_before_command(self):
  for fault in ['owner','origin','proof','port','thread']:
   with self.subTest(fault=fault),tempfile.TemporaryDirectory()as tmp:
    n,e,p,proof,records,commands,cfg,fs=self.setup_native(Path(tmp).resolve())
    if fault=='owner':cfg['owner']='f'*64
    elif fault=='origin':cfg['app_origin']='https://foreign.invalid'
    elif fault=='proof':cfg['transport_proof']='0'*64
    elif fault=='port':n.server=types.SimpleNamespace(socket=types.SimpleNamespace(getsockname=lambda:('127.0.0.1',29092),fileno=lambda:3))
    else:n.server_thread=types.SimpleNamespace(is_alive=lambda:False)
    with self.bindings(e,fs),patch.object(m,'FD_SOURCE_PATH',str(ROOT/'tests/governance/media_browser_fd_source.php')):
     with self.assertRaises(RuntimeError):m.collect(n,p,proof)
    self.assertEqual(commands,[])
 def test_null_roles_before_native_read_or_transport(self):
  class Native:
   def __getattr__(self,n):raise AssertionError('native touched')
  with self.assertRaises(consumer.Unproved):m.collect(Native(),{}, {})
 def test_1ce_prefix_and_receiver_null_main_no_execution(self):
  self.assertEqual(m.digest((ROOT/'tests/governance/media_browser_fd_source.php').read_bytes()),m.FD_SHA)
  self.assertEqual(m.digest(Path(m.RECEIVER_PATH).read_bytes()),m.RECEIVER_SHA256)
  r=subprocess.run(['php','-l',str(m.RECEIVER_PATH)],capture_output=True,timeout=10);self.assertEqual(r.returncode,0)

 def test_receiver_null_packet_role_refuses_without_stdin(self):
  code=(ROOT/'tests/governance/media_browser_fd_source.php').read_text()[5:]+Path(m.RECEIVER_PATH).read_text()[5:]+'\nnm_main();'
  p=subprocess.Popen(['php','-r',code],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  try:
   p.wait(timeout=2);out,err=p.communicate(timeout=2);self.assertEqual(p.returncode,1);self.assertEqual(out,b'');self.assertEqual(err,b'native_media_source_measurement_unproved')
  finally:
   if p.poll()is None:p.terminate();p.wait(timeout=2)
