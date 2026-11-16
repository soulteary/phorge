"""Real filesystem DATA tests only. No source module/issuer/owner execution."""
import ast,hashlib,io,json,os,secrets,stat,sys,tarfile,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];P=ROOT/'scripts/governance/media_task_archive_v2.py';tree=ast.parse(P.read_text());nodes=[n for n in tree.body if isinstance(n,(ast.FunctionDef,ast.ClassDef))and n.name!='build_current'];ns={k:globals()[k]for k in ('hashlib','io','json','os','secrets','stat','sys','tarfile','Path')};ns['MAX']=268435456;exec(compile(ast.Module(body=nodes,type_ignores=[]),'<archive-filesystem-DATA-only>','exec'),ns)
ROWS={'src/file':('file',b'public synthetic\n',0o644),'bin/run':('file',b'#!/bin/sh\n',0o755),'webroot/link':('symlink','../src/file',0o777)}
class Controls(unittest.TestCase):
 def test_roundtrip_actual_held_fds(self):
  with tempfile.TemporaryDirectory()as tmp:
   out=Path(tmp).resolve();out.chmod(0o700);path,m=ns['publish_data'](out,ROWS,'1'*40)
   self.assertFalse(m['runtime_authority']);self.assertFalse(m['source_operation_exit_verified']);self.assertFalse(m['cleanup_proved'])
   self.assertEqual(len(list(out.iterdir())),4);self.assertEqual(path.stat().st_nlink,2)
   fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
   try:self.assertEqual(ns['readback_fd'](fd,ROWS)['archive_sha256'],m['archive_sha256'])
   finally:os.close(fd)
 def test_no_overwrite_existing_directory_contents(self):
  with tempfile.TemporaryDirectory()as tmp:
   out=Path(tmp).resolve();out.chmod(0o700);sentinel=out/'current-source.tar';sentinel.write_bytes(b'foreign');before=sentinel.stat()
   with self.assertRaises(ValueError):ns['publish_data'](out,ROWS,'1'*40)
   self.assertEqual(sentinel.read_bytes(),b'foreign');self.assertEqual(before.st_ino,sentinel.stat().st_ino)
 def test_output_alias_and_permissions(self):
  with tempfile.TemporaryDirectory()as tmp:
   root=Path(tmp).resolve();out=root/'real';out.mkdir(mode=0o700);alias=root/'alias';alias.symlink_to(out,target_is_directory=True)
   with self.assertRaises(ValueError):ns['publish_data'](alias,ROWS,'1'*40)
   out.chmod(0o755)
   with self.assertRaises(ValueError):ns['publish_data'](out,ROWS,'1'*40)
 def test_trailing_truncated_and_content_mutations(self):
  sink=io.BytesIO();ns['tar_to'](sink,ROWS);original=sink.getvalue()
  variants=[original+b'\0'*512,original[:-1],original[:1024],original.replace(b'public synthetic',b'PUBLIC synthetic',1)]
  for raw in variants:
   with self.subTest(size=len(raw)),tempfile.TemporaryFile()as f:
    f.write(raw);f.flush()
    with self.assertRaises(ValueError):ns['readback_fd'](f.fileno(),ROWS)
 def test_duplicate_unknown_mode_link_and_size(self):
  mutations=[{**ROWS,'extra':('file',b'x',0o644)},{**ROWS,'src/file':('file',b'bad',0o644)},{**ROWS,'bin/run':('file',ROWS['bin/run'][1],0o644)},{**ROWS,'webroot/link':('symlink','../bin/run',0o777)}]
  for rows in mutations:
   with tempfile.TemporaryFile()as f:
    ns['tar_to'](f,rows);f.flush()
    with self.assertRaises(ValueError):ns['readback_fd'](f.fileno(),ROWS)
  with tempfile.TemporaryFile()as f:
   with tarfile.open(fileobj=f,mode='w')as t:
    for _ in range(2):e=tarfile.TarInfo('src/file');e.size=1;t.addfile(e,io.BytesIO(b'x'))
   f.flush()
   with self.assertRaises(ValueError):ns['readback_fd'](f.fileno(),ROWS)
 def test_path_escape_collision_and_invalid_types(self):
  for rows in({'../bad':('file',b'x',0o644)},{'a':('symlink','../../bad',0o777)},{'a':('file',b'x',0o644),'a/b':('file',b'x',0o644)},{'a':('file',b'x',True)},{'a':('device',b'x',0o644)}):
   with self.assertRaises(ValueError):ns['validate_data'](rows)
 def test_genuine_owner_code_unchanged_not_executed(self):
  f=next(n for n in tree.body if isinstance(n,ast.FunctionDef)and n.name=='build_current');s=ast.unparse(f)
  self.assertIn('source.require_operation(operation, root)',s);self.assertGreaterEqual(s.count('source.require_task_operation(operation, root)'),3);self.assertIn('context.current(SELF)',s)
  for word in ('exec(', 'subprocess', 'snapshot.build','cleanup('):self.assertNotIn(word,s)
 def test_cross_link_raw_components_and_lengths(self):
  for rows in ({'a':('symlink','d/link/../../evil',0o777),'d/link':('symlink','../z',0o777),'z/file':('file',b'x',0o644)}, {'a':('symlink','file/../file',0o777),'file':('file',b'x',0o644)}, {'x'*20000:('file',b'x',0o644)}):
   with self.assertRaises(ValueError):ns['validate_data'](rows)
 def test_umask_enforced_private(self):
  previous=os.umask(0o200)
  try:
   with tempfile.TemporaryDirectory()as tmp:
    out=Path(tmp).resolve();out.chmod(0o700);path,m=ns['publish_data'](out,ROWS,'1'*40);self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600)
  finally:os.umask(previous)
 def test_published_archive_replacement_refuses(self):
  from unittest.mock import patch
  real=os.link
  def replace_after_inventory(src,dst,**kwargs):
   result=real(src,dst,**kwargs)
   if dst=='current-source-inventory.private.json':
    fd=kwargs['dst_dir_fd'];os.unlink('current-source.tar',dir_fd=fd);f=os.open('current-source.tar',os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600,dir_fd=fd);os.write(f,b'foreign');os.close(f)
   return result
  with tempfile.TemporaryDirectory()as tmp,patch.object(os,'link',replace_after_inventory):
   out=Path(tmp).resolve();out.chmod(0o700)
   with self.assertRaises(ValueError):ns['publish_data'](out,ROWS,'1'*40)
 def test_replacement_during_final_inventory_read_refuses(self):
  from unittest.mock import patch
  real=os.pread;done=[False]
  def replace_on_read(fd,count,offset):
   result=real(fd,count,offset)
   if not done[0] and result.startswith(b'{"actual_checkpoint"'):
    # Only once final names exist; private initial manifest check stays untouched.
    if (out/'current-source-inventory.private.json').exists():
     done[0]=True;(out/'current-source.tar').unlink();(out/'current-source.tar').write_bytes(b'foreign')
   return result
  with tempfile.TemporaryDirectory()as tmp:
   out=Path(tmp).resolve();out.chmod(0o700)
   with patch.object(os,'pread',replace_on_read),self.assertRaises(ValueError):ns['publish_data'](out,ROWS,'1'*40)
   self.assertTrue(done[0])

class OwnerOrchestrationControls(unittest.TestCase):
 def test_actual_current_owner_order_and_no_runtime_handoff(self):
  tree=ast.parse((ROOT/'scripts/governance/prepare_media_task_archive_v2.py').read_bytes())
  selected={n.name:n for n in tree.body if isinstance(n,ast.FunctionDef)}
  actual=selected['prepare_in_operation'];text=ast.unparse(actual)
  self.assertNotIn('open_task_source_operation',text)
  self.assertIn('source.require_task_operation(operation, root)',text)
  self.assertLess(text.index('freshness.verify_in_operation'),text.index('archive.build_current'))
  self.assertIn('context.current(SELF) == Path(__file__).read_bytes()',text)
  result=next(n.value for n in actual.body if isinstance(n,ast.Return));flags={k.value:v.value for k,v in zip(result.keys,result.values)if isinstance(k,ast.Constant)and isinstance(v,ast.Constant)}
  for key in('source_only_operation_exit_verified','archive_is_SourceOperation_issuer','archive_is_runtime_authorization','worker_or_SQL_executed','worker_readback_proved','cleanup_proved','runtime_acceptance'):self.assertIs(flags[key],False,key)
  wrapper=selected['prepare'];scope=next(n for n in wrapper.body if isinstance(n,ast.With));self.assertIn('open_task_source_operation',ast.unparse(scope))
  post=ast.unparse(ast.Module(body=wrapper.body[wrapper.body.index(scope)+1:],type_ignores=[]));self.assertIn("result['source_only_operation_exit_verified'] = True",post)
  self.assertNotIn("result['runtime_acceptance'] = True",post)
  importer=ast.unparse(selected['current_module']);self.assertIn('relative in {ARCHIVE, FRESHNESS}',importer)

if __name__=='__main__':unittest.main()
