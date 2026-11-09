<?php
// Synthetic ownFS only. Never dispatch wait/exec/prerequisite container modes.
define('MEDIA_HELPER_LIBRARY_ONLY',true);require __DIR__.'/helper.php';
$dir=sys_get_temp_dir().'/media-helper-'.bin2hex(random_bytes(8));mkdir($dir,0700);$root=realpath($dir);mkdir($root.'/source',0700);mkdir($root.'/private',0700);
try{
 file_put_contents($root.'/source/public.txt','synthetic');chmod($root.'/source/public.txt',0644);symlink('public.txt',$root.'/source/link');
 $i=media_helper_inventory($root.'/source');if(array_keys($i)!==array('link','public.txt')||$i['link']!==array('kind'=>'symlink','target'=>'public.txt')||$i['public.txt']['sha256']!==hash('sha256','synthetic')){throw new Exception('inventory');}
 $raw="{\"synthetic\":true}\n";$mask=umask();media_helper_inject($root.'/private',$raw);
 if(umask()!==$mask||file_get_contents($root.'/private/profile.json')!==$raw||!is_file($root.'/private/ready')||(fileperms($root.'/private/profile.json')&07777)!==0600||lstat($root.'/private/profile.json')['nlink']!==1){throw new Exception('private injection');}
 $rejected=false;set_error_handler(static function(){throw new RuntimeException('expected owned duplicate');});try{media_helper_inject($root.'/private',$raw);}catch(Throwable$e){$rejected=true;}finally{restore_error_handler();}
 if(!$rejected||file_get_contents($root.'/private/profile.json')!==$raw){throw new Exception('overwrite');}
 $denied=false;try{media_helper_main(array('helper','arbitrary'));}catch(RuntimeException$e){$denied=true;}if(!$denied){throw new Exception('mode');}
 echo "PHP helper ownFS: passed (host ".PHP_VERSION."); no container/runtime proof\n";
}finally{foreach(array('/source/link','/source/public.txt','/private/ready','/private/profile.json')as$f){if(file_exists($root.$f)||is_link($root.$f)){unlink($root.$f);}}rmdir($root.'/source');rmdir($root.'/private');rmdir($root);}
