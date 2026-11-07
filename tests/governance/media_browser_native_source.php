<?php
/** Fixed public source read only. Prefix must be the reviewed captured 1ce helper. */
const NM_REQUEST_SHA256=null;
const NM_FIXTURE='support/governance/native-organization/media-wire-fixture.php';
function nm_need($x){if($x!==true){throw new RuntimeException('native_media_source_measurement_unproved');}}
function nm_digest($x){return is_string($x)&&preg_match('/\A[a-f0-9]{64}\z/D',$x)===1;}
function nm_map_digest(array $map){ksort($map,SORT_STRING);return hash('sha256',json_encode($map,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
function nm_fixture_digest($root,array $files,array $aliases){
  nm_need(isset($files[NM_FIXTURE])&&!array_key_exists(NM_FIXTURE,$aliases));
  $path=$root.'/'.NM_FIXTURE;$chain=wb_source_chain($root,NM_FIXTURE);$a=wb_source_stat($path);
  nm_need(($a['mode']&0170000)===0100000&&$a['uid']===posix_geteuid()&&$a['nlink']===1&&$a['size']>0&&$a['size']<=1048576);
  $f=@fopen($path,'r+b');nm_need(is_resource($f));
  try{
    $b=fstat($f);nm_need(is_array($b)&&wb_source_stamp($b)===wb_source_stamp($a));
    $h=hash_init('sha256');nm_need(hash_update_stream($h,$f,$a['size']+1)===$a['size']);$first=hash_final($h);
    nm_need(fseek($f,0)===0);$h=hash_init('sha256');nm_need(hash_update_stream($h,$f,$a['size']+1)===$a['size']);$second=hash_final($h);
    nm_need(hash_equals($first,$second)&&hash_equals($files[NM_FIXTURE],$first)&&wb_source_stamp(fstat($f))===wb_source_stamp($a)&&wb_source_stamp(wb_source_stat($path))===wb_source_stamp($a));
    foreach($chain as$p=>$s){nm_need(wb_source_stamp(wb_source_stat($p))===$s);}return $first;
  }finally{fclose($f);}
}
function nm_measure($root,array $v){
  $keys=array_keys($v);sort($keys);$wanted=array('aliases','files','identity','measurement_nonce');sort($wanted);nm_need($keys===$wanted);
  nm_need(is_array($v['files'])&&is_array($v['aliases'])&&is_array($v['identity'])&&nm_digest($v['measurement_nonce']));
  $full=wb_source_verify($root,$v['files'],$v['aliases'],$v['identity']);
  nm_need($full===array('passed'=>true,'files'=>count($v['files'])));
  $actual=nm_fixture_digest($root,$v['files'],$v['aliases']);$s=wb_source_stat($root);
  nm_need($s['dev']===$v['identity']['dev']&&$s['ino']===$v['identity']['ino']);
  return array('scope'=>'owned-native-media-full-source-measurement-v1','passed'=>true,
    'files'=>count($v['files']),'aliases'=>count($v['aliases']),
    'manifest_sha256'=>nm_map_digest($v['files']),'aliases_sha256'=>nm_map_digest($v['aliases']),
    'source_root_identity'=>array('dev'=>$s['dev'],'ino'=>$s['ino']),
    'actual_fixture_sha256'=>$actual,'measurement_nonce'=>$v['measurement_nonce']);
}
function nm_main(){
  try{
    nm_need(nm_digest(NM_REQUEST_SHA256));
    $raw=stream_get_contents(STDIN,4194305);nm_need(is_string($raw)&&strlen($raw)<=4194304&&hash_equals(NM_REQUEST_SHA256,hash('sha256',$raw)));
    nm_need(PHP_VERSION==='8.5.12'&&PHP_OS_FAMILY==='Linux'&&posix_geteuid()===0);
    $v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);nm_need(is_array($v));
    echo json_encode(nm_measure('/tmp/governance-src',$v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
  }catch(Throwable$e){fwrite(STDERR,'native_media_source_measurement_unproved');exit(1);}
}
