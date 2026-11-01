<?php
// Explicit test-only preload. Never modify production include_path or images.
$d=getenv('PERMISSION_ROUTES_PRIVATE');$r=dirname(__DIR__,3);
if(getenv('ATOMIC_TEST_DISPOSABLE')!=='1'||!is_file($d.'/owner.json')){throw new Exception('Owned XLSX fixture required.');}
$o=json_decode(file_get_contents($d.'/owner.json'),true);
if($o['root']!==$r||!hash_equals($o['configurationHash'],hash_file('sha256',$r.'/conf/local/local.json'))){throw new Exception('XLSX fixture ownership mismatch.');}
require getenv('PERMISSION_XLSX_AUTOLOAD');
if(Composer\InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet')!=='5.10.0'||!class_exists(PhpOffice\PhpSpreadsheet\Spreadsheet::class)){throw new Exception('Locked test-only XLSX dependency required.');}
