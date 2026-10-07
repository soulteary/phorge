#!/usr/bin/env python3
"""Single Docker acceptance entry. Creates a unique isolated project per run."""
import argparse
import datetime
import json
import os
from pathlib import Path
import subprocess
import tempfile
import uuid

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path)
parser.add_argument('--gorge-dir', type=Path, default=ROOT.parent/'gorge')
parser.add_argument('--check', action='store_true', help='Validate topology without builds or containers')
args = parser.parse_args()
gorge = args.gorge_dir.resolve()
if not (gorge/'go/go.mod').is_file(): raise SystemExit('Gorge source checkout not found')
lock = json.loads((ROOT / 'deploy/acceptance/build-lock.json').read_text())
project = 'gorge-accept-' + uuid.uuid4().hex[:12]
out = (args.output or Path(tempfile.gettempdir()) / project).resolve()
if out == ROOT or ROOT in out.parents or out == gorge or gorge in out.parents:
    raise SystemExit('Output must be outside both source checkouts')
out.mkdir(parents=True, exist_ok=True)
if (out/'delivery-manifest.json').exists(): raise SystemExit('Refusing to overwrite an existing acceptance result')
env = dict(os.environ)
for name, value in lock['images'].items(): env['LOCK_' + name.upper()] = value
env.update(LOCK_ARCANIST=lock['arcanist'], LOCK_APCU=lock['apcu'], LOCK_APCU_SHA256=lock['apcuSHA256'], LOCK_SNAPSHOT=lock['debianSnapshot'],
           ACCEPTANCE_GORGE_DIR=str(gorge), ACCEPTANCE_OUTPUT=str(out), ACCEPTANCE_BASE_IMAGE=project+':base')
# An empty env file prevents application .env from leaking into disposable fixtures.
cmd = ['docker', 'compose', '--env-file', '/dev/null', '-p', project,
       '-f', str(ROOT/'deploy/acceptance/compose.yml')]
def run(tail):
    with (out/'execution.log').open('a') as log:
        process=subprocess.Popen(cmd+tail,cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
        for line in process.stdout:
            print(line,end='',flush=True);log.write(line)
        rc=process.wait()
    if rc: raise subprocess.CalledProcessError(rc,cmd+tail)
if args.check:
    config=json.loads(subprocess.check_output(cmd+['--profile','build','config','--format','json'],cwd=ROOT,env=env,text=True))
    for name, service in config['services'].items():
        if service.get('ports') or service.get('container_name'):
            raise SystemExit('Acceptance must not publish ports or reuse container names')
    for image in lock['images'].values():
        import re
        if not re.fullmatch(r'.+@sha256:[0-9a-f]{64}',image):
            raise SystemExit('All fixture/base images must use immutable digests')
    print('Isolated acceptance configuration is valid.'); raise SystemExit(0)
manifest = {'schemaVersion':1, 'startedAt':datetime.datetime.now(datetime.timezone.utc).isoformat(),
            'project':project, 'result':'running', 'buildLock':lock, 'images':[]}
import sys
sys.path.insert(0,str(ROOT/'tests/contract/worker'))
from acceptance_manifest import source_identity
manifest.update(phorge=source_identity(ROOT),gorge=source_identity(gorge))
rc = 1
try:
    run(['--profile','build','build','base'])
    run(['build','runner','image'])
    run(['up','-d','mysql','redis','s3','image'])
    run(['create','--no-deps','runner'])
    ids = subprocess.check_output(cmd+['images','-q'], cwd=ROOT, env=env, text=True).split()
    for identity in sorted(set(ids)):
        info = json.loads(subprocess.check_output(['docker','image','inspect',identity],text=True))[0]
        manifest['images'].append({'id':info['Id'],'repoDigests':info.get('RepoDigests',[]),'architecture':info.get('Architecture'),'os':info.get('Os')})
    run(['up','--no-deps','--abort-on-container-exit','--exit-code-from','runner','runner'])
    result = json.loads((out/'result.json').read_text())
    manifest['stages']=result['stages']
    manifest['notCovered']=result['notCovered']
    manifest.update(result=result['result'], phorge=result['phorge'], gorge=result['gorge'], arcanist=result['arcanist'])
    rc = 0 if result['result']=='passed' else 1
except (subprocess.CalledProcessError, OSError, ValueError):
    manifest['result']='failed'
finally:
    if (out/'result.json').exists():
        try:
            detail=json.loads((out/'result.json').read_text())
            for key in ['stages','notCovered','arcanist']:
                if key in detail: manifest[key]=detail[key]
        except (OSError,ValueError): pass
    manifest['finishedAt']=datetime.datetime.now(datetime.timezone.utc).isoformat()
    (out/'delivery-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    subprocess.run(cmd+['logs','--no-color'],cwd=ROOT,env=env,stdout=(out/'fixtures.log').open('w'),stderr=subprocess.STDOUT)
    subprocess.run(cmd+['down','--volumes','--remove-orphans'],cwd=ROOT,env=env)
print('Acceptance '+manifest['result']+'; results: '+str(out))
raise SystemExit(rc)
