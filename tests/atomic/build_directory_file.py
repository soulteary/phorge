#!/usr/bin/env python3
"""Build only the paired core File fixture; reuse locked-base inspection."""
import argparse,hashlib,json,re,runpy,subprocess
from pathlib import Path
_SHARED=runpy.run_path(str(Path(__file__).with_name('build_gorge.py')),run_name='directory_file_shared_base_inspection')
def build_file(gorge,revision,runner=subprocess.run):
    gorge=Path(gorge).resolve()
    if type(revision) is not str or not re.fullmatch('[a-f0-9]{40}',revision):raise ValueError('immutable File fixture revision required')
    def git(*args):return runner(['git','-C',str(gorge),*args],capture_output=True,text=True,check=True).stdout
    if git('rev-parse','HEAD').strip()!=revision:raise ValueError('paired File source mismatch')
    if git('status','--porcelain','--untracked-files=all').strip():raise ValueError('paired File checkout must be clean')
    raw=git('show',revision+':deploy/release/build-bases.json');lock=json.loads(raw);images=lock.get('images') if type(lock)is dict else None
    if type(images)is not dict or set(images)!={'go','alpine'}:raise ValueError('exact paired bases required')
    verified=_SHARED['inspect_bases'](gorge,lock,runner)
    runner(['docker','build','--build-arg','SERVICE=gorge-file-storage','--build-arg','PORT=8100',
        '--build-arg','GO_BASE_IMAGE='+images['go'],'--build-arg','RUNTIME_BASE_IMAGE='+images['alpine'],
        '--label','phorge.gorge-revision='+revision,'--tag','gorge-file-storage:atomic',str(gorge/'go')],check=True)
    return dict(pairedGorgeRevision=revision,buildBasesSHA256=hashlib.sha256(raw.encode()).hexdigest(),verifiedBases=verified,servicesBuilt=['gorge-file-storage'])
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--gorge-dir',required=True);p.add_argument('--revision',required=True);a=p.parse_args()
    print(json.dumps(build_file(a.gorge_dir,a.revision),sort_keys=True))
