#!/usr/bin/env python3
"""Owned, no-public-port project handoff SQL and optional browser regression."""
import argparse,hashlib,json,os,re,secrets,socket,subprocess,tempfile,time
from pathlib import Path

ROOT=Path(__file__).resolve().parents[3]

def command(args,**kwargs):
    return subprocess.run(args,capture_output=True,**kwargs)

def image_id(image):
    if not image or image.startswith('-') or '\n' in image:
        raise ValueError('explicit local image required')
    r=command(['docker','image','inspect','--format','{{.Id}}',image],timeout=15)
    value=r.stdout.decode().strip()
    if r.returncode or not value.startswith('sha256:') or len(value)!=71:
        raise ValueError('local image identity required')
    return value

def source_digest():
    names=subprocess.check_output(['git','-C',str(ROOT),'ls-files','--cached','--others','--exclude-standard','-z']).split(b'\0')
    rows=[]
    for name in sorted(set(names)):
        if not name:continue
        p=ROOT/os.fsdecode(name)
        value='link:'+os.readlink(p) if p.is_symlink() else hashlib.sha256(p.read_bytes()).hexdigest()
        rows.append([os.fsdecode(name),value])
    return hashlib.sha256(json.dumps(rows,separators=(',',':')).encode()).hexdigest()

def cleanup_owned(ids,owner,runner=command):
    for cid in reversed(ids):
        if not isinstance(cid,str) or not re.fullmatch('[a-f0-9]{64}',cid):
            raise ValueError('exact owned container ID required')
        label=runner(['docker','inspect','--format','{{index .Config.Labels "acceptance.owner"}}',cid],timeout=10)
        if label.returncode or label.stdout.decode().strip()!=owner:
            raise RuntimeError('cleanup ownership refused')
        if runner(['docker','rm','-f',cid],timeout=20).returncode:
            raise RuntimeError('owned cleanup failed')

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php-image',required=True)
    parser.add_argument('--mysql-image',default='mysql:8.4.13')
    parser.add_argument('--gorge-file-binary',type=Path,required=True)
    parser.add_argument('--browser',action='store_true')
    parser.add_argument('--playwright-module')
    parser.add_argument('--chromium')
    parser.add_argument('--output',type=Path)
    args=parser.parse_args(argv)
    os.umask(0o077)
    out=args.output.resolve() if args.output else Path(tempfile.mkdtemp(prefix='project-handoff-'))
    if out.is_relative_to(ROOT):parser.error('output must be outside the checkout')
    if args.output:out.mkdir(mode=0o700)
    result={'passed':False,'production_changed':False,'public_ports':False,
            'existing_feature_flags_changed':False,'browser_requested':args.browser}
    ids=[];relay=None
    owner='project-handoff-'+secrets.token_hex(8)
    try:
        php_image=image_id(args.php_image);mysql_image=image_id(args.mysql_image)
        result.update(php_image_id=php_image,mysql_image_id=mysql_image)
        version=command(['docker','run','--rm','--network','none','--entrypoint','php',php_image,'-r','echo PHP_VERSION;'],timeout=20)
        if version.returncode or version.stdout!=b'8.5.13':raise ValueError('PHP 8.5.13 required')
        binary=args.gorge_file_binary.resolve(strict=True)
        if not binary.is_file():raise ValueError('paired file executable required')
        binary_sha=hashlib.sha256(binary.read_bytes()).hexdigest()
        result['gorge_file_sha256']=binary_sha
        before=source_digest();result['source_manifest_sha256']=before
        password=secrets.token_hex(24)
        env=out/'db.env';env.write_text('MYSQL_ROOT_PASSWORD='+password+'\n');env.chmod(0o600)
        r=command(['docker','run','-d','--name',owner+'-db','--label','acceptance.owner='+owner,
          '--network','none','--memory','1536m','--pids-limit','256','--tmpfs','/var/lib/mysql:rw,nosuid,size=1g',
          '--env-file',str(env),mysql_image,'--mysql-native-password=ON','--skip-log-bin','--innodb-buffer-pool-size=128M'],timeout=30)
        if r.returncode:raise RuntimeError('owned database start failed')
        db=r.stdout.decode().strip();ids.append(db)
        for _ in range(90):
            if command(['docker','exec',db,'mysqladmin','ping','-h','127.0.0.1','--silent'],timeout=10).returncode==0:break
            time.sleep(1)
        else:raise RuntimeError('owned database readiness failed')
        text='PHORGE_GOVERNANCE_ISOLATED_TEST=1\nPHORGE_GOVERNANCE_FIXTURE_DSN=mysql:host=127.0.0.1;port=3306;dbname=governance_fixture_product;charset=utf8mb4\nPHORGE_GOVERNANCE_FIXTURE_USER=root\nPHORGE_GOVERNANCE_FIXTURE_PASSWORD='+password+'\nPHORGE_GOVERNANCE_GORGE_FILE_BINARY=/opt/test-gorge-file\nPHORGE_GOVERNANCE_GORGE_FILE_SHA256='+binary_sha+'\n'
        if args.browser:
            with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
            text+='PHORGE_DIRECTORY_BROWSER_ORIGIN=http://directory.localhost:'+str(port)+'\n'
        testenv=out/'test.env';testenv.write_text(text);testenv.chmod(0o600)
        r=command(['docker','run','-d','--name',owner+'-php','--label','acceptance.owner='+owner,
          '--network','container:'+db,'--memory','1024m','--pids-limit','128','--read-only',
          '--tmpfs','/tmp:rw,nosuid,size=256m','--env-file',str(testenv),
          '-v',str(binary)+':/opt/test-gorge-file:ro','-v',str(ROOT)+':/opt/phorge/phorge:ro',
          '-w','/opt/phorge/phorge','--entrypoint','php',php_image,
          'support/governance/native-organization/project-handoff-run.php'],timeout=30)
        if r.returncode:raise RuntimeError('owned PHP start failed')
        php=r.stdout.decode().strip();ids.append(php)
        browser_ok=not args.browser
        if args.browser:
            for _ in range(240):
                r=command(['docker','exec',php,'php','-r',"$p='/tmp/directory-browser-location';if(!is_file($p)){exit(2);}$d=file_get_contents($p);readfile($d.'/client.json');"],timeout=10)
                if r.returncode==0:client=json.loads(r.stdout);break
                running=command(['docker','inspect','--format','{{.State.Running}}',php],timeout=10)
                if running.stdout.strip()!=b'true':raise RuntimeError('fixture ended before browser readiness')
                time.sleep(1)
            else:raise RuntimeError('browser readiness expired')
            import sys
            sys.path.insert(0,str(ROOT/'tests/governance/directory_product'))
            from docker_relay import Relay
            relay=Relay(php,port);relay.start()
            private=out/'client.private.json';private.write_text(json.dumps(client));private.chmod(0o600)
            env=os.environ.copy()
            if args.playwright_module:env['PHORGE_TEST_PLAYWRIGHT']=args.playwright_module
            if args.chromium:env['PHORGE_TEST_CHROMIUM']=args.chromium
            r=command(['node',str(Path(__file__).with_name('browser.cjs')),str(private),str(out/'browser.json')],env=env,timeout=180)
            (out/'browser-command.private.json').write_text(json.dumps({'returncode':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode()}))
            b=json.loads((out/'browser.json').read_bytes());browser_ok=r.returncode==0 and b.get('passed')is True and b.get('browser_closed')is True
            result.update(browser_passed=browser_ok,browser_check_count=len(b['checks']))
            r=command(['docker','exec',php,'php','-r',"$d=file_get_contents('/tmp/directory-browser-location');$c=json_decode(file_get_contents($d.'/client.json'),true);if(!hash_equals($c['owner'],file_get_contents($d.'/owner'))){exit(1);}$f=fopen($d.'/finished','x');fwrite($f,$c['owner']);fclose($f);chmod($d.'/finished',0600);"],timeout=10)
            if r.returncode:raise RuntimeError('owned browser release failed')
        waited=command(['docker','wait',php],timeout=700)
        logs=command(['docker','logs',php],timeout=10)
        (out/'sql.json').write_bytes(logs.stdout);(out/'sql.stderr.private.log').write_bytes(logs.stderr)
        sql=json.loads(logs.stdout)
        if source_digest()!=before:raise RuntimeError('source changed during test')
        result.update(sql_passed=waited.returncode==0 and waited.stdout.strip()==b'0' and sql.get('passed')is True,
          sql_check_count=sql['check_count'],source_unchanged=True)
        result['passed']=result['sql_passed'] and browser_ok
    except Exception as error:
        result['failure_class']=type(error).__name__
        # Failure diagnostics remain local; never print container logs or credentials.
        if len(ids)>1:
            r=command(['docker','logs',ids[-1]],timeout=10)
            (out/'failed-fixture.private.log').write_bytes(r.stdout+r.stderr)
    finally:
        try:
            if relay:relay.close()
            cleanup_owned(ids,owner)
            result['owned_containers_removed']=True
            for name in ('db.env','test.env','client.private.json'):
                (out/name).unlink(missing_ok=True)
        except Exception as error:
            result['passed']=False;result['cleanup_failure_class']=type(error).__name__
        (out/'result.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps({'passed':result['passed'],'output':str(out),'sql_checks':result.get('sql_check_count'),
      'browser_checks':result.get('browser_check_count'),'cleanup':result.get('owned_containers_removed')},sort_keys=True))
    return 0 if result['passed'] else 1

if __name__=='__main__':raise SystemExit(main())
