"""Owned same-run anchor creation; caller hashes and descriptors are never trust roots."""
import hashlib,json,os,re,stat
from pathlib import Path
from provenance import decode,digest,source_bindings,verify_build_info,OWNER_FILES,RECIPE_FILES
PROFILE='isolated-native-RR21-linux-amd64-v1'
def canonical(value):return(json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=True)+'\n').encode()
def write_once(directory,name,raw,mode=0o400):
 if name not in ('seed.json','anchor.json'):raise ValueError('fixed anchor file required')
 fd=os.open(directory/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'wb')as stream:
  stream.write(raw);stream.flush();os.fsync(stream.fileno());identity=os.fstat(stream.fileno())
 os.chmod(directory/name,mode)
 current=(directory/name).lstat()
 if(current.st_dev,current.st_ino)!=(identity.st_dev,identity.st_ino)or(directory/name).read_bytes()!=raw:raise RuntimeError('anchor readback unknown')
 fd=os.open(directory,os.O_RDONLY|os.O_DIRECTORY)
 try:os.fsync(fd)
 finally:os.close(fd)
 return digest(raw)
def create_seed(directory,identity,sources,owner):
 if not re.fullmatch('[a-f0-9]{32}',owner)or directory.name!='governance-native-rr-ci-'+owner or directory.parent!=Path('/tmp'):raise ValueError('fixed owned anchor directory required')
 directory.mkdir(mode=0o700);info=directory.lstat()
 seed={'schema_version':1,'profile':PROFILE,'owner':owner,'source_revision':identity['source_revision'],'run_id':identity['run_id'],'run_attempt':identity['run_attempt'],'source_kind':identity['source_kind'],'PR_head_measured':False,'source_sha256':sources}
 pin=write_once(directory,'seed.json',canonical(seed))
 return {'directory':str(directory),'dev':info.st_dev,'ino':info.st_ino,'seed_sha256':pin,'seed':seed}
def close_anchor(lease,build_dir,actual_info,php_bytes,parser_bytes,current_sources,go_bytes):
 directory=Path(lease['directory']);info=directory.lstat()
 if directory.is_symlink()or(info.st_dev,info.st_ino)!=(lease['dev'],lease['ino'])or stat.S_IMODE(info.st_mode)!=0o700:raise ValueError('anchor ownership changed')
 seed_raw=(directory/'seed.json').read_bytes()
 if digest(seed_raw)!=lease['seed_sha256']or decode(seed_raw)!=lease['seed']or current_sources!=lease['seed']['source_sha256']:raise ValueError('independent seed changed')
 seed=lease['seed'];record_raw=(build_dir/'build-record.json').read_bytes();record=decode(record_raw);binary=(build_dir/'native-rr-process-owner').read_bytes();info_raw=(build_dir/'build-info.json').read_bytes()
 expected_record={'schema_version','source_revision','source_kind','PR_head_measured','run_id','run_attempt','profile','source_sha256','binary_sha256','build_info_sha256','go_binary_sha256','source_clean_before_after','build_info_verified','descriptor_is_execution_authority','external_trusted_anchor_established','PHP_executed','SQL_executed'}
 if type(record)is not dict or set(record)!=expected_record or type(record['schema_version'])is not int or record['schema_version']!=1 or record['PHP_executed']is not False or record['SQL_executed']is not False or record['go_binary_sha256']!=digest(go_bytes):raise ValueError('fixed actual build record required')
 # The fixed runner obtained actual_info by rerunning the trusted Go tool on
 # actual bytes; the descriptor's own digest is not accepted as that evidence.
 verify_build_info(actual_info,seed['source_revision'],'amd64')
 if actual_info!=decode(info_raw)or record['build_info_sha256']!=digest(info_raw)or record['binary_sha256']!=digest(binary):raise ValueError('actual artifact mismatch')
 if record['source_revision']!=seed['source_revision']or record['run_id']!=seed['run_id']or record['run_attempt']!=seed['run_attempt']or record['profile']!=PROFILE or record['source_kind']!=seed['source_kind']or record['PR_head_measured']is not False or record['source_sha256']!=current_sources or record['source_clean_before_after']is not True or record['build_info_verified']is not True:raise ValueError('same-run build binding refused')
 if record['descriptor_is_execution_authority']is not False or record['external_trusted_anchor_established']is not False:raise ValueError('descriptor authority claim refused')
 anchor={**seed,'seed_sha256':lease['seed_sha256'],'build_record_sha256':digest(record_raw),'build_info_sha256':digest(info_raw),'binary_sha256':digest(binary),'php_binary_sha256':digest(php_bytes),'parser_sha256':digest(parser_bytes),'go_binary_sha256':digest(go_bytes)}
 pin=write_once(directory,'anchor.json',canonical(anchor))
 # This expected control is held by the fixed parent runner separately from
 # anchor/descriptor bytes. It must never be recovered from an input bundle.
 return {'schema_version':1,'profile':PROFILE,'directory':str(directory),'dev':lease['dev'],'ino':lease['ino'],'anchor_sha256':pin,'source_revision':seed['source_revision'],'run_id':seed['run_id'],'run_attempt':seed['run_attempt'],'source_kind':seed['source_kind'],'PR_head_measured':False}
