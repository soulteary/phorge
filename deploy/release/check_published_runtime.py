#!/usr/bin/env python3
"""Execute both verified child manifests without aliasing a local index digest."""
import argparse
import json
from pathlib import Path
import re
import subprocess
from image_metadata import identity


def runtime_commands(metadata):
    if not isinstance(metadata,dict) or any(not isinstance(metadata.get(k),str) for k in ('repository','tag','sourceRevision','ref')):
        raise ValueError('Verified image identity metadata is required.')
    identity(metadata['repository'],metadata['tag'],metadata['sourceRevision'],metadata['ref'])
    if metadata.get('exists') is not True or metadata.get('overwritePermitted') is not False:
        raise ValueError('A verified immutable publication is required.')
    root=metadata.get('digest','');children=metadata.get('platformDigests',{})
    if not isinstance(root,str) or not re.fullmatch('sha256:[a-f0-9]{64}',root) or metadata.get('platforms')!=['amd64','arm64'] or not isinstance(children,dict) or set(children)!={'amd64','arm64'}:
        raise ValueError('Both verified runtime manifest digests are required.')
    if any(not isinstance(d,str) or not re.fullmatch('sha256:[a-f0-9]{64}',d) or d==root for d in children.values()) or len(set(children.values()))!=2:
        raise ValueError('Runtime child digests must be distinct from each other and the index.')
    commands=[]
    for arch in ('amd64','arm64'):
        ref='ghcr.io/'+metadata['repository']+'@'+children[arch]
        commands.append(['docker','pull','--platform','linux/'+arch,ref])
        commands.append(['docker','run','--rm','--network','none','--platform','linux/'+arch,'--entrypoint','php',ref,'/opt/phorge/phorge/deploy/acceptance/php_security.php'])
    return commands


def check(metadata,runner=subprocess.run):
    commands=runtime_commands(metadata)
    for command in commands:runner(command,check=True,timeout=180)
    return {'passed':True,'indexDigest':metadata['digest'],'sourceRevision':metadata['sourceRevision'],
            'release':metadata['tag'],'platformDigests':metadata['platformDigests'],
            'runtimeArchitectures':['amd64','arm64'],'networkDuringRuntime':'none','commandsPassed':len(commands)}


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--metadata',required=True,type=Path);p.add_argument('--output',required=True,type=Path);a=p.parse_args()
    result=check(json.loads(a.metadata.read_text()))
    a.output.write_text(json.dumps(result,indent=2)+'\n')


if __name__=='__main__':main()
