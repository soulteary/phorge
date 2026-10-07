#!/usr/bin/env python3
"""Read-only production configuration and optional database privilege gate."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import uuid

ROOT = Path(__file__).resolve().parents[2]
PROFILES = ['mailer', 'search', 'maintenance']
ALLOWED_PRIVILEGES = {'USAGE', 'SELECT', 'SHOW VIEW', 'REPLICATION CLIENT'}

def duration_seconds(value):
    # Docker Compose renders durations using Go's h/m/s/ms/us/ns syntax.
    units={'h':3600,'m':60,'s':1,'ms':0.001,'us':0.000001,'µs':0.000001,'ns':0.000000001}
    parts=re.findall(r'(\d+(?:\.\d+)?)(h|ms|m|us|µs|ns|s)',value)
    if not parts or ''.join(n+u for n,u in parts)!=value:
        raise ValueError('Worker stop grace must have an explicit duration')
    return sum(float(n)*units[u] for n,u in parts)

def check_grant(grant):
    # Unknown syntax, dynamic administration privileges, roles and GRANT OPTION
    # all fail closed. Never print grant text: it can contain account details.
    match = re.fullmatch(r'GRANT (.+?) ON (.+?) TO (.+)', grant)
    if not match or 'WITH GRANT OPTION' in grant:
        return False
    return all(p.strip() in ALLOWED_PRIVILEGES for p in match[1].split(','))

def actual_grants(container, php_image, credentials):
    # Share db-api's network namespace so MySQL selects exactly the same
    # user@host account, including more-specific IP/host grants. Passwords go
    # only through stdin, never through Docker arguments or diagnostic output.
    helper='phorge-preflight-'+uuid.uuid4().hex[:12]
    script='''mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
      $c=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
      $db=mysqli_init();
      $db->options(MYSQLI_OPT_CONNECT_TIMEOUT,5);
      $db->options(MYSQLI_OPT_READ_TIMEOUT,5);
      $db->real_connect($c['host'],$c['user'],$c['password'],null,$c['port']);
      $rows=$db->query('SHOW GRANTS'); $grants=array();
      while ($row=$rows->fetch_row()) {$grants[]=$row[0];}
      $roles=$db->query('SELECT @@GLOBAL.mandatory_roles')->fetch_row()[0];
      echo json_encode(array('grants'=>$grants,'mandatoryRoles'=>$roles));
    } catch (Throwable $e) {exit(2);}'''
    try:
        proc=subprocess.run(['docker','run','--rm','-i','--name',helper,
            '--network','container:'+container,'--read-only','--entrypoint','php',php_image,'-r',script],
            input=json.dumps(credentials),text=True,capture_output=True,timeout=30)
        if proc.returncode: raise ValueError('Actual database credential check failed')
        value=json.loads(proc.stdout)
        if not isinstance(value,dict) or not isinstance(value.get('mandatoryRoles'),str):
            raise ValueError('Database privilege response is incomplete')
        grants=value.get('grants')
        if not isinstance(grants,list) or not grants or value.get('mandatoryRoles') or not all(
                isinstance(g,str) and check_grant(g) for g in grants):
            raise ValueError('Actual DB API account has unsupported privileges or mandatory roles')
        privileges={p.strip() for g in grants for p in re.fullmatch(r'GRANT (.+?) ON (.+?) TO (.+)',g)[1].split(',')}
        if not {'SELECT','SHOW VIEW','REPLICATION CLIENT'}.issubset(privileges):
            raise ValueError('Actual DB API account is missing diagnostic privileges')
    finally:
        subprocess.run(['docker','rm','-f',helper],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)

def validate(config):
    services = config['services']
    def env(name): return services[name].get('environment', {})
    for name in ('render','conduit','file-storage','webhook','taskqueue','worker','db-api','mailer','search','maintenance','image'):
        if not env('gorge-'+name).get('GORGE_SERVICE_TOKEN'):
            raise ValueError('Missing authentication: '+name)
    db = env('gorge-db-api')
    user = db.get('GORGE_DB_MYSQL_USER', '')
    if not re.fullmatch(r'[A-Za-z0-9_]{1,32}', user) or user in ('root', env('mysql').get('MYSQL_USER')):
        raise ValueError('DB API requires a distinct read-only account')
    if not db.get('GORGE_DB_MYSQL_PASS') or db['GORGE_DB_MYSQL_PASS'] == env('mysql').get('MYSQL_PASSWORD'):
        raise ValueError('DB API must use a separate credential')
    # This gate supports the bundled single-node topology only. Custom node
    # files need a separate per-node grant audit rather than a false green.
    if db.get('GORGE_DB_CONFIG_FILE'):
        raise ValueError('Custom DB topology requires a per-node privilege gate')
    mail = env('gorge-mailer')
    if mail.get('GORGE_MAILER_CONFIG_FILE') or env('gorge-search').get('GORGE_SEARCH_CONFIG_FILE'):
        raise ValueError('Mounted backend configuration requires a separate configuration audit')
    specs = json.loads(mail['GORGE_MAILER_CONFIG']) if mail.get('GORGE_MAILER_CONFIG') else [
        {'type':mail.get('GORGE_MAILER_TYPE', ''),'key':mail.get('GORGE_MAILER_KEY') or 'default'}]
    if not isinstance(specs, list) or not specs or any(not isinstance(s, dict) or s.get('type') not in
            ('smtp','sendmail','mailgun','sendgrid','postmark','ses') for s in specs):
        raise ValueError('Production mail requires a real provider configuration')
    keys=[s.get('key') for s in specs]
    if any(not isinstance(k,str) or not k.strip() for k in keys) or len(set(keys))!=len(keys):
        raise ValueError('Production mail requires distinct nonempty backend receipt keys')
    search = env('gorge-search')
    specs = json.loads(search['GORGE_SEARCH_BACKENDS']) if search.get('GORGE_SEARCH_BACKENDS') else [
        {'type': search.get('GORGE_SEARCH_ENGINE') or 'elasticsearch',
         'hosts': [search.get('MEILI_HOST') if search.get('GORGE_SEARCH_ENGINE') == 'meilisearch' else search.get('ES_HOST')],
         'roles':['read','write']}]
    if not isinstance(specs, list) or not specs or any(not isinstance(s, dict) or s.get('type') not in
            ('elasticsearch','meilisearch') or not isinstance(s.get('hosts'), list) or
            not s['hosts'] or not all(isinstance(h,str) and h.strip() for h in s['hosts']) for s in specs):
        raise ValueError('Production search requires a configured real backend')
    roles = {role for spec in specs for role in spec.get('roles', ['read','write'])}
    if not {'read','write'}.issubset(roles):
        raise ValueError('Production search requires read and write roles')
    for port in services['gorge-notification'].get('ports', []):
        if port.get('host_ip') not in ('127.0.0.1','::1'):
            raise ValueError('Notification ports must be behind the TLS reverse proxy')
    drain=int(env('gorge-worker').get('GORGE_WORKER_DRAIN_TIMEOUT_SEC') or 30)
    grace=duration_seconds(services['gorge-worker'].get('stop_grace_period','10s'))
    if drain <= 0 or grace < drain+15:
        raise ValueError('Worker stop grace must allow drain and fenced queue reporting')
    return user

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--check-db-grants', action='store_true', help='Check the running bundled MySQL account; no writes')
    args=p.parse_args()
    base=['docker','compose','-f','docker-compose.yml','-f','docker-compose.production.yml']
    for profile in PROFILES: base+=['--profile',profile]
    cfg=subprocess.run(base+['config','--format','json'],cwd=ROOT,capture_output=True,text=True)
    if cfg.returncode: raise ValueError('Production Compose configuration was rejected')
    config=json.loads(cfg.stdout)
    user=validate(config)
    if args.check_db_grants:
        if not config['services']['gorge-db-api']['environment'].get('GORGE_DB_MYSQL_HOST') == 'mysql':
            raise ValueError('Privilege gate requires bundled MySQL')
        def inspect_service(name):
            ids=subprocess.check_output(base+['ps','-q',name],cwd=ROOT,text=True).split()
            if len(ids)!=1: raise ValueError('Exactly one running service is required for privilege audit')
            info=json.loads(subprocess.check_output(['docker','inspect',ids[0]],text=True))[0]
            if not info['State']['Running']: raise ValueError('Privilege audit service is not running')
            return info
        db_container=inspect_service('gorge-db-api')
        php_container=inspect_service('phorge')
        actual_env=dict(v.split('=',1) for v in db_container['Config']['Env'] if '=' in v)
        expected=config['services']['gorge-db-api']['environment']
        for key in ('GORGE_DB_MYSQL_HOST','GORGE_DB_MYSQL_USER','GORGE_DB_MYSQL_PASS','GORGE_DB_MYSQL_PORT','GORGE_DB_CONFIG_FILE'):
            if actual_env.get(key,'')!=expected.get(key,''):
                raise ValueError('Running DB API differs from the audited deployment configuration')
        actual_grants(db_container['Id'],php_container['Image'],{
            'host':actual_env['GORGE_DB_MYSQL_HOST'],'port':int(actual_env.get('GORGE_DB_MYSQL_PORT') or 3306),
            'user':user,'password':actual_env['GORGE_DB_MYSQL_PASS']})
    print(json.dumps({'state':'passed','scope':'production configuration'+(' and DB account privileges' if args.check_db_grants else ''),
                      'databasePrivileges':'verified' if args.check_db_grants else 'not_verified',
                      'backupRestore':'not_verified','providerDelivery':'not_verified'}))

if __name__ == '__main__':
    try: main()
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        raise SystemExit('Production preflight failed; check credentials, real backends, topology and account grants. No secret values were printed.')
