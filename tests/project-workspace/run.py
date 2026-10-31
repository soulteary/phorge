#!/usr/bin/env python3
"""Run project workspace acceptance in disposable Docker containers.

Requires explicitly selected local images, Node.js and Playwright/Chromium.
No existing service, deployment configuration, session or data volume is used.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import select
import shutil
import signal
import socket
import socketserver
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
TESTS = ROOT / 'tests' / 'project-workspace'


def call(command, **kwargs):
    return subprocess.run(command, text=True, capture_output=True, **kwargs)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--phorge-image', required=True,
                        help='Existing local image built with the repository Dockerfile.')
    parser.add_argument('--mysql-image', required=True,
                        help='Existing local MySQL 8 image. Images are never pulled.')
    parser.add_argument('--gorge-image', required=True,
                        help='Existing local Gorge file-storage image (for native builtin images).')
    parser.add_argument('--output', type=Path,
                        help='New artifact directory (default: a private temporary directory).')
    parser.add_argument('--playwright-module', type=Path,
                        help='Optional existing Playwright index.mjs; otherwise use npm ci here.')
    parser.add_argument('--container-root', default='/opt/phorge/phorge',
                        help='Absolute source path inside the disposable container.')
    parser.add_argument('--skip-native', action='store_true',
                        help='Run browser checks without rerunning the 18 native fixture tests.')
    args = parser.parse_args()
    if not re.fullmatch(r'/[A-Za-z0-9_/-]+', args.container_root):
        parser.error('The container root must be a simple absolute path.')
    if '..' in Path(args.container_root).parts:
        parser.error('The container root may not traverse parent directories.')
    if args.output:
        output = args.output.resolve()
        output.mkdir(mode=0o700, parents=True, exist_ok=False)
    else:
        output = Path(tempfile.mkdtemp(prefix='phorge-workspace-artifacts-'))
    output.chmod(0o700)
    run = 'phorge-workspace-' + secrets.token_hex(6)
    private = Path(tempfile.mkdtemp(prefix=run + '-'))
    private.chmod(0o700)
    network, database, application = run+'-net', run+'-db', run+'-web'
    files = run+'-files'
    password = secrets.token_hex(32)
    file_token = secrets.token_hex(32)
    report = {'run': run, 'passed': False,
              'scope': 'Disposable source snapshot, database, synthetic users and sessions.',
              'output': str(output), 'checks': [], 'cleanup': []}
    created = []
    proxy = None
    proxy_thread = None

    def redact(value):
        return value.replace(password, '[REDACTED]').replace(file_token, '[REDACTED]')

    def require(command, **kwargs):
        result = call(command, **kwargs)
        if result.returncode:
            raise RuntimeError(redact(result.stderr.strip()) or 'Command failed.')
        return result

    def phase(name, command, **kwargs):
        print('Running '+name+' in disposable containers.', flush=True)
        result = call(command, **kwargs)
        (output/(name+'.log')).write_text(redact(result.stdout+result.stderr))
        report['checks'].append({'name': name, 'exitCode': result.returncode})
        if result.returncode:
            raise RuntimeError(name+' failed; inspect its private artifact log.')
        return result

    def interrupted(signum, frame):
        raise RuntimeError('Acceptance interrupted by signal '+str(signum)+'.')

    previous = {sig: signal.signal(sig, interrupted)
                for sig in (signal.SIGTERM, signal.SIGINT)}
    try:
        # Resolve the browser module before allocating containers. No npm install
        # or Chromium download is implicit in this runner.
        if args.playwright_module:
            module = args.playwright_module.resolve()
            if not module.is_file():
                raise RuntimeError('The selected Playwright module does not exist.')
            module_uri = module.as_uri()
        else:
            module_uri = require(['node', '--input-type=module', '-e',
                                  'console.log(import.meta.resolve("playwright"))'],
                                 cwd=TESTS).stdout.strip()
        browser_env = dict(os.environ, WORKSPACE_ACCEPTANCE='1',
                           WORKSPACE_PLAYWRIGHT_MODULE=module_uri)
        require(['node', '--input-type=module', '-e',
                 'const {chromium} = await import(process.env.WORKSPACE_PLAYWRIGHT_MODULE); '
                 'const browser = await chromium.launch({headless:true}); await browser.close();'],
                cwd=TESTS, env=browser_env)
        for key, image in (('phorge', args.phorge_image), ('mysql', args.mysql_image),
                           ('gorge', args.gorge_image)):
            report[key+'ImageID'] = require([
                'docker', 'image', 'inspect', '--format', '{{.Id}}', image
            ]).stdout.strip()

        # Copy versioned working-tree files only. A user's local configuration,
        # ignored extensions, node_modules and repository credentials never enter
        # the container. Mount the isolated generated configuration separately.
        source = private/'source'
        source.mkdir(mode=0o755)
        files = require(['git', '-C', str(ROOT), 'ls-files', '-z']).stdout.split('\0')
        digest = hashlib.sha256()
        count = 0
        for name in files:
            if not name:
                continue
            relative = Path(name)
            if relative.is_absolute() or '..' in relative.parts:
                raise RuntimeError('Unexpected source path.')
            if name in ('conf/local/local.json', 'support/preamble.php'):
                raise RuntimeError('Local configuration must not be versioned in the test source.')
            original, target = ROOT/relative, source/relative
            target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            if original.is_symlink():
                original.resolve().relative_to(ROOT)
                target.symlink_to(os.readlink(original))
                content = os.readlink(original).encode()
            else:
                shutil.copyfile(original, target)
                target.chmod(0o755 if os.access(original, os.X_OK) else 0o644)
                content = original.read_bytes()
            digest.update(name.encode()+b'\0'+hashlib.sha256(content).digest())
            count += 1
        for name in ('run.py', 'fixture.php', 'browser.mjs'):
            if not (source/'tests'/'project-workspace'/name).is_file():
                raise RuntimeError('Commit or stage the acceptance source before running it.')
        report['sourceSnapshot'] = {'trackedFileCount': count, 'sha256': digest.hexdigest()}

        class Forwarder(socketserver.BaseRequestHandler):
            def handle(self):
                try:
                    with socket.create_connection((self.server.target, 80), timeout=10) as upstream:
                        upstream.settimeout(None)
                        while True:
                            readable, _, _ = select.select([self.request, upstream], [], [], 30)
                            if not readable:
                                return
                            for current in readable:
                                data = current.recv(65536)
                                if not data:
                                    return
                                other = upstream if current is self.request else self.request
                                other.sendall(data)
                except OSError:
                    return

        class Server(socketserver.ThreadingTCPServer):
            daemon_threads = True
            block_on_close = False

        # An internal Docker network has no external route or published ports.
        # A temporary host-loopback relay exposes only this synthetic web server.
        proxy = Server(('127.0.0.1', 0), Forwarder)
        origin = 'http://127.0.0.1:'+str(proxy.server_address[1])
        report['origin'] = origin
        config = {
            'mysql.host': database, 'mysql.port': 3306,
            'mysql.user': 'root', 'mysql.pass': password,
            'storage.default-namespace': 'workspace_acceptance_'+run.rsplit('-', 1)[1],
            'phabricator.base-uri': origin+'/',
            'phabricator.allowed-uris': [origin+'/'],
            'security.require-https': False, 'auth.require-email-verification': False,
            'phabricator.show-prototypes': True, 'phabricator.silent': True,
            'notification.servers': [], 'gorge.integrations': {},
            'gorge.taskqueue.owner': 'phorge',
            'gorge.file.uri': 'http://'+files+':8100',
            'gorge.file.token': file_token,
        }
        conf = private/'conf'
        conf.mkdir(mode=0o755)
        (conf/'local.json').write_text(json.dumps(config))
        (conf/'local.json').chmod(0o644)
        mysql_env = private/'mysql.env'
        mysql_env.write_text('MYSQL_ROOT_PASSWORD='+password+'\nMYSQL_ROOT_HOST=%\n')
        mysql_env.chmod(0o600)
        gorge_env = private/'gorge.env'
        gorge_env.write_text('GORGE_LISTEN_ADDR=:8100\nGORGE_SERVICE_TOKEN='+file_token+
                             '\nGORGE_FILE_LOCAL_DISK_PATH=/var/lib/gorge/files\n')
        gorge_env.chmod(0o600)
        apache = private/'apache.conf'
        apache.write_text((ROOT/'docker'/'phorge-apache.conf').read_text().replace(
            '/opt/phorge/phorge', args.container_root))
        apache.chmod(0o644)
        require(['docker', 'network', 'create', '--internal', network])
        created.append(('network', network))
        require(['docker', 'run', '-d', '--pull=never', '--name', database,
                 '--network', network, '--memory', '1g', '--cpus', '1',
                 '--tmpfs', '/var/lib/mysql:rw,size=1g',
                 '--env-file', str(mysql_env), report['mysqlImageID'],
                 '--innodb-buffer-pool-size=128M', '--max-allowed-packet=134217728',
                 '--sql-mode=STRICT_ALL_TABLES', '--local-infile=0'])
        created.append(('container', database))
        deadline = time.monotonic()+180
        print('Waiting for the disposable database.', flush=True)
        while time.monotonic() < deadline:
            ready = call(['docker', 'exec', database, 'sh', '-ec',
                          'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=TCP '
                          '-h 127.0.0.1 -uroot -NBe "SELECT 1"'])
            if ready.returncode == 0:
                break
            time.sleep(2)
        else:
            raise RuntimeError('The disposable database did not become ready.')
        require(['docker', 'run', '-d', '--pull=never', '--name', files,
                 '--network', network, '--memory', '128m', '--cpus', '0.5',
                 '--tmpfs', '/var/lib/gorge/files:rw,size=64m,mode=1777',
                 '--env-file', str(gorge_env), report['gorgeImageID']])
        created.append(('container', files))
        deadline = time.monotonic()+30
        while time.monotonic() < deadline:
            ready = call(['docker', 'exec', files, 'wget', '-qO-',
                          'http://127.0.0.1:8100/readyz'])
            if ready.returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('The disposable file service did not become ready.')
        mounts = [
            '--mount', f'type=bind,src={source},dst={args.container_root},readonly',
            '--mount', f'type=bind,src={conf},dst={args.container_root}/conf/local,readonly',
            '--mount', f'type=bind,src={apache},dst=/etc/apache2/sites-available/000-default.conf,readonly',
        ]
        require(['docker', 'run', '-d', '--pull=never', '--name', application,
                 '--network', network, '--memory', '1g', '--cpus', '1',
                 '--entrypoint', 'apache2-foreground', *mounts, report['phorgeImageID']])
        created.append(('container', application))
        root = args.container_root
        execute = ['docker', 'exec', '-i', '-e', 'WORKSPACE_ACCEPTANCE=1',
                   '-e', 'WORKSPACE_ACCEPTANCE_RUN='+run,
                   '-e', 'WORKSPACE_PHORGE_ROOT='+root, application, 'php']
        phase('schema-bootstrap', execute+[root+'/bin/storage', 'upgrade', '--force'])
        if not args.skip_native:
            phase('native-regressions', execute+[root+'/bin/unit', '--no-coverage',
                  'src/applications/project/__tests__/TeamProjectProgressTestCase.php'])
        # This command returns synthetic session tokens: do not save its raw
        # stdout in logs. The private fixture is removed with this run's config.
        fixture = json.loads(require(execute+[root+'/tests/project-workspace/fixture.php']).stdout)
        fixture['applicationRoot'] = root
        fixture_path = private/'fixture.json'
        fixture_path.write_text(json.dumps(fixture))
        fixture_path.chmod(0o600)
        details = json.loads(require(['docker', 'inspect', application]).stdout)[0]
        proxy.target = details['NetworkSettings']['Networks'][network]['IPAddress']
        if not proxy.target:
            raise RuntimeError('The disposable application has no internal address.')
        proxy_thread = threading.Thread(target=proxy.serve_forever, daemon=True)
        proxy_thread.start()
        deadline = time.monotonic()+45
        while time.monotonic() < deadline:
            try:
                urllib.request.urlopen(origin+'/project/', timeout=3).close()
                break
            except urllib.error.HTTPError:
                break
            except (OSError, urllib.error.URLError):
                time.sleep(1)
        else:
            raise RuntimeError('The disposable HTTP application did not become reachable.')
        phase('browser', ['node', str(source/'tests'/'project-workspace'/'browser.mjs'),
                         origin, str(fixture_path), str(output)], env=browser_env)
        verified = phase('native-persistence',
                         execute+[root+'/tests/project-workspace/fixture.php', '--verify'],
                         input=json.dumps(fixture))
        report['nativeVerification'] = json.loads(verified.stdout)
        report['passed'] = report['nativeVerification']['passed']
    except Exception as error:
        report['error'] = redact(str(error))
    finally:
        if proxy_thread:
            proxy.shutdown()
            proxy_thread.join(timeout=5)
        if proxy:
            proxy.server_close()
        if any(name == application for _, name in created):
            logs = call(['docker', 'logs', '--tail', '100', application])
            (output/'apache.log').write_text(redact(logs.stdout+logs.stderr))
        for kind, name in reversed(created):
            command = ['docker', 'rm', '-f', name] if kind == 'container' else [
                'docker', 'network', 'rm', name]
            removed = call(command)
            report['cleanup'].append({'resource': name, 'removed': removed.returncode == 0})
        report['passed'] = report['passed'] and all(row['removed'] for row in report['cleanup'])
        shutil.rmtree(private)
        for artifact in output.iterdir():
            if artifact.is_file():
                artifact.chmod(0o600)
        (output/'report.json').write_text(json.dumps(report, indent=2)+'\n')
        (output/'report.json').chmod(0o600)
        for sig, handler in previous.items():
            signal.signal(sig, handler)
        print(json.dumps({'passed': report['passed'], 'output': str(output),
                          'error': report.get('error')}, indent=2))
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
