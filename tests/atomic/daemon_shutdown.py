#!/usr/bin/env python3
"""Exercise daemon role stop policy in disposable, network-isolated containers."""
import argparse,base64,hashlib,json,os,re,subprocess,tempfile,time,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
LABEL='io.phorge.acceptance.daemon-signal'
FAKE='''#!/bin/sh
set -eu
case "$1" in
 start) touch /tmp/daemon-signal-running ;;
 status) test -f /tmp/daemon-signal-running ;;
 stop) rm -f /tmp/daemon-signal-running; echo DAEMON_SIGNAL_STOP_HANDLER_REACHED ;;
 *) exit 64 ;;
esac
'''
BOOT='''set -eu
mkdir -p /opt/phorge/phorge/bin
printf %s "$FAKE_PHD_B64" | base64 -d > /opt/phorge/phorge/bin/phd
chmod 755 /opt/phorge/phorge/bin/phd
printf %s "$FOREGROUND_B64" | base64 -d > /tmp/phd-foreground
exec bash /tmp/phd-foreground
'''
def main():
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('--engine',choices=['docker','podman'],default='docker');p.add_argument('--output',required=True,type=Path);a=p.parse_args()
 if os.environ.get('ATOMIC_TEST_DISPOSABLE')!='1':p.error('Explicit disposable test acknowledgement required.')
 if any(os.environ.get(k) for k in ('DOCKER_HOST','DOCKER_CONTEXT','CONTAINER_HOST','CONTAINER_CONNECTION')):p.error('Inherited container connection selectors are refused.')
 if a.output.exists() or a.output.resolve().is_relative_to(ROOT):p.error('Use a fresh report outside the source checkout.')
 engine=['docker','--context','default'] if a.engine=='docker' else ['podman']
 owner=uuid.uuid4().hex;names=[];report={'schemaVersion':1,'passed':False,'network':'none','productionAccessed':False,'cases':[]};failed=None
 def command(args,timeout=60):
  r=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
  if r.returncode:raise RuntimeError('Container or Compose command failed: '+args[0]+' '+args[1])
  return r.stdout
 def inspect(name):return json.loads(command(engine+['inspect',name]))[0]
 try:
  with tempfile.TemporaryDirectory(prefix='phorge-daemon-config-') as tmp:
   empty=Path(tmp)/'empty.env';empty.write_text('')
   config=json.loads(command(['docker','compose','--project-directory',str(ROOT),'--env-file',str(empty),'-p','phorge-daemon-signal-contract','-f',str(ROOT/'docker-compose.yml'),'config','--format','json']))
  daemon=config['services']['phorge-daemon'];web=config['services']['phorge']
  assert daemon['stop_signal']=='SIGTERM' and daemon['stop_grace_period']=='45s'
  assert web.get('stop_signal') is None, 'Do not change the Apache web role stop signal.'
  image=json.loads((ROOT/'deploy/acceptance/build-lock.json').read_text())['images']['php']
  assert re.fullmatch(r'docker.io/library/php@sha256:[a-f0-9]{64}',image)
  command(engine+['pull',image],timeout=300)
  info=json.loads(command(engine+['image','inspect',image]))[0]
  assert info['Config']['StopSignal']=='SIGWINCH', 'The locked Apache base stop policy changed.'
  wrapper=(ROOT/'docker/phd-foreground.sh').read_bytes()
  report.update(baseImage=image,baseStopSignal='SIGWINCH',daemonStopSignal=daemon['stop_signal'],daemonGracePeriodSeconds=45,webStopSignalUnchanged=True,foregroundScriptSHA256=hashlib.sha256(wrapper).hexdigest(),sourceRevision=command(['git','-C',str(ROOT),'rev-parse','HEAD']).strip(),worktreeDirty=bool(command(['git','-C',str(ROOT),'status','--porcelain']).strip()))
  for kind,signal,deadline in [('inherited-apache-negative-control',None,3),('configured-daemon-graceful-stop',daemon['stop_signal'],45)]:
   name='phorge-daemon-signal-'+owner[:12]+'-'+str(len(names));names.append(name)
   args=engine+['create','--name',name,'--label',LABEL+'='+owner,'--network','none','--read-only','--no-healthcheck','--memory','128m','--pids-limit','64','--tmpfs','/opt/phorge:rw,exec,size=1m,mode=755','--tmpfs','/tmp:rw,size=4m,mode=1777','--env','FAKE_PHD_B64='+base64.b64encode(FAKE.encode()).decode(),'--env','FOREGROUND_B64='+base64.b64encode(wrapper).decode(),'--entrypoint','/bin/sh']
   if signal:args+=['--stop-signal',signal]
   command(args+[image,'-c',BOOT]);created=inspect(name)
   assert created['HostConfig']['NetworkMode']=='none' and not created['HostConfig'].get('Privileged') and not any(m.get('Type')=='bind' for m in created.get('Mounts',[]))
   command(engine+['start',name])
   ready=time.monotonic()+15
   while True:
    state=inspect(name)
    if not state['State']['Running']:raise RuntimeError('Synthetic daemon exited before signal test.')
    r=subprocess.run(engine+['exec',name,'test','-f','/tmp/daemon-signal-running'],capture_output=True,timeout=10)
    if r.returncode==0:break
    if time.monotonic()>ready:raise RuntimeError('Synthetic daemon did not become ready.')
    time.sleep(.1)
   began=time.monotonic();command(engine+['stop','-t',str(deadline),name],timeout=deadline+20);elapsed=time.monotonic()-began
   state=inspect(name);logs=command(engine+['logs',name]);handled='DAEMON_SIGNAL_STOP_HANDLER_REACHED' in logs;code=state['State']['ExitCode']
   assert not state['State']['Running'] and not state['State'].get('OOMKilled')
   if signal:assert handled and code!=137 and elapsed<deadline
   else:assert not handled and code==137
   report['cases'].append({'name':kind,'signal':signal or 'SIGWINCH','stopHandlerReached':handled,'exitCode':code,'forcedTerminationExpected':signal is None,'passed':True})
  report['passed']=True
 except BaseException as ex:
  failed=ex;report['failureType']=type(ex).__name__;report['failureStage']=str(ex) if isinstance(ex,RuntimeError) else 'Contract assertion failed.'
 finally:
  cleanup=True
  owned=subprocess.run(engine+['ps','-aq','--filter','label='+LABEL+'='+owner],capture_output=True,text=True,timeout=20)
  if owned.returncode:cleanup=False
  for cid in owned.stdout.split() if owned.returncode==0 else []:
   found=subprocess.run(engine+['inspect',cid],capture_output=True,text=True,timeout=15)
   if found.returncode:cleanup=False;continue
   info=json.loads(found.stdout)[0]
   if info['Config'].get('Labels',{}).get(LABEL)!=owner:cleanup=False;continue
   cleanup &= subprocess.run(engine+['rm','-f',cid],capture_output=True,timeout=30).returncode==0
  remaining=subprocess.run(engine+['ps','-aq','--filter','label='+LABEL+'='+owner],capture_output=True,text=True,timeout=20)
  cleanup &= remaining.returncode==0 and not remaining.stdout.strip()
  report['cleanupPassed']=cleanup;report['passed'] &= cleanup
  a.output.parent.mkdir(parents=True,exist_ok=True);a.output.write_text(json.dumps(report,indent=2)+'\n')
  print(json.dumps(report))
 if failed:raise SystemExit('Daemon stop regression failed: '+type(failed).__name__)
 if not report['passed']:raise SystemExit('Owned fixture cleanup failed.')
if __name__=='__main__':main()
