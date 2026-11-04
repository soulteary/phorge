#!/usr/bin/env python3
"""Extract an actual reviewed renderer image; no service starts or public ports."""
import argparse,hashlib,json,os,re,secrets,shutil,subprocess,tempfile
from pathlib import Path
EXPECTED='55f8ba8e57b179a7dee92ad43c77038ca322f61c'

def image_identity(info,revision):
    if revision!=EXPECTED or type(info)is not dict or type(info.get('Id'))is not str or not re.fullmatch(r'sha256:[a-f0-9]{64}',info['Id']):
        raise ValueError('reviewed_renderer_image_required')
    config=info.get('Config');labels=config.get('Labels')if type(config)is dict else None
    if type(labels)is not dict or labels.get('phorge.gorge-revision')!=revision:raise ValueError('renderer_source_label_refused')
    return info['Id']

def extract(output,provenance,revision,runner=subprocess.run):
    output=Path(output);provenance=Path(provenance)
    if output.exists()or output.is_symlink()or provenance.exists()or provenance.is_symlink():raise ValueError('renderer_output_exists')
    def run(args,check=True):return runner(args,check=check,capture_output=True,text=True)
    info=json.loads(run(['docker','image','inspect','gorge-render:atomic']).stdout)
    if type(info)is not list or len(info)!=1:raise ValueError('renderer_image_metadata_refused')
    image=image_identity(info[0],revision);owner=secrets.token_hex(16);name='atomic-native-renderer-'+owner;create_attempted=False
    output.parent.mkdir(parents=True,exist_ok=True);provenance.parent.mkdir(parents=True,exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='owned-renderer-',dir=output.parent)as private:
        os.chmod(private,0o700)
        try:
            create_attempted=True
            run(['docker','create','--name',name,'--label','governance.fixture.owner='+owner,
                '--network','none','--read-only',image])
            target=Path(private)/'gorge-render'
            run(['docker','cp',name+':/usr/local/bin/gorge-render',str(target)])
            if not target.is_file()or target.is_symlink()or not 0<target.stat().st_size<=134217728:raise ValueError('renderer_binary_refused')
            digest=hashlib.sha256(target.read_bytes()).hexdigest()
            # Exclusive create preserves any unexpected prior output.
            with output.open('xb')as destination,target.open('rb')as source:shutil.copyfileobj(source,destination)
            output.chmod(0o755)
        finally:
            if create_attempted:
                # Creation may have reached the daemon even when its CLI reply
                # was lost. Inspect this registered name on every attempted create.
                inspection=run(['docker','inspect',name],check=False)
                if inspection.returncode==0:
                    inspected=json.loads(inspection.stdout)
                    if(type(inspected)is not list or len(inspected)!=1 or inspected[0].get('Image')!=image or
                        inspected[0].get('Config',{}).get('Labels',{}).get('governance.fixture.owner')!=owner or
                        inspected[0].get('State',{}).get('Running')is not False):raise ValueError('renderer_container_owner_refused')
                    run(['docker','rm',name])
                elif not(inspection.stdout.strip()in ('','[]')and inspection.stderr.strip()in (
                    'Error: No such object: '+name,'Error: No such container: '+name,
                    'Error response from daemon: No such object: '+name,'Error response from daemon: No such container: '+name)):
                    raise ValueError('renderer_container_absence_unproved')
    record={'reviewed_revision':revision,'image_id':image,'binary_sha256':digest,
        'source_image_revision_label_verified':True,
        'build_provenance_boundary':'Source cleanliness and locked build bases are verified by the preceding atomic build_gorge job step, not inferred from the image label alone.',
        'binary_copied_from_actual_image':True,'temporary_container_removed':True,'service_started':False,'public_ports':0,
        'scope':'isolated CI image-to-binary provenance, not release attestation or deployment'}
    with provenance.open('x')as stream:json.dump(record,stream,indent=2);stream.write('\n')
    return record

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--output',type=Path,required=True)
    p.add_argument('--provenance',type=Path,required=True);p.add_argument('--revision',required=True);a=p.parse_args()
    extract(a.output,a.provenance,a.revision)
if __name__=='__main__':main()
