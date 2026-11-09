<?php
require __DIR__.'/profile.php';
function guard_refused($f){try{$f();}catch(Throwable$e){return;}throw new Exception('expected refusal');}
$p=array('acknowledge_synthetic_DDL'=>true,'host'=>'127.0.0.1','password'=>'synthetic-not-a-credential','port'=>3306,'user'=>'fixture');
if(media_positive_profile($p)!==$p){throw new Exception('shape');}
foreach(array('acknowledge_synthetic_DDL'=>false,'host'=>'example.com','port'=>'3306','user'=>'bad user','password'=>array())as$k=>$v){guard_refused(function()use($p,$k,$v){$p[$k]=$v;media_positive_profile($p);});}
guard_refused(function()use($p){$p['dbname']='production';media_positive_profile($p);});
$dir=sys_get_temp_dir().'/media-positive-guard-'.bin2hex(random_bytes(8));mkdir($dir,0700);$dir=realpath($dir);$file=$dir.'/profile.json';ksort($p,SORT_STRING);file_put_contents($file,json_encode($p,JSON_UNESCAPED_SLASHES)."\n");chmod($file,0600);
try{
 if(media_positive_profile_file($file)!==$p){throw new Exception('held profile');}
 chmod($file,0644);guard_refused(function()use($file){media_positive_profile_file($file);});chmod($file,0600);
 link($file,$dir.'/link');guard_refused(function()use($file){media_positive_profile_file($file);});unlink($dir.'/link');
 symlink($file,$dir.'/symlink');guard_refused(function()use($dir){media_positive_profile_file($dir.'/symlink');});unlink($dir.'/symlink');
 file_put_contents($file,'{"host":"secret-canary","host":"127.0.0.1"}');guard_refused(function()use($file){media_positive_profile_file($file);});
}finally{unlink($file);rmdir($dir);}
echo "profile-guards: passed; no SQL/bootstrap\n";
