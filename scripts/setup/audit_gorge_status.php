#!/usr/bin/env php
<?php

require_once dirname(__FILE__).'/../../scripts/__init_script__.php';
require_once dirname(__FILE__).'/lib/gorge_status_runtime.php';
$args = new PhutilArgumentParser($argv);
$args->parseStandardArguments();
$args->parse(array(
  array('name' => 'local-only', 'help' => pht('Read configuration and local databases without HTTP probes.')),
  array('name'=>'previous-report','param'=>'path','help'=>pht('Compare net capacity growth with a previous report from the same physical databases.')),
  array('name' => 'runtime-file', 'param' => 'path',
    'help' => pht('Restricted JSON with worker/maintenance uri and token; never printed.')),
));
$runtime = array();
if ($args->getArg('runtime-file')) {
  $raw = Filesystem::readFile($args->getArg('runtime-file'));
  if (strlen($raw) > 65536) { throw new Exception(pht('Runtime descriptor is too large.')); }
  $runtime = phutil_json_decode($raw);
  if (!is_array($runtime)) { throw new Exception(pht('Invalid runtime descriptor.')); }
}
$report = gorge_status_runtime_report($args->getArg('local-only'), $runtime);
if ($args->getArg('previous-report')) {
  $raw=Filesystem::readFile($args->getArg('previous-report'));
  if (strlen($raw)>1024*1024) { throw new Exception(pht('Previous report too large.')); }
  $previous=phutil_json_decode($raw);
  if (!is_array($previous)) { throw new Exception(pht('Invalid previous report.')); }
  $report['capacityGrowth']=gorge_status_growth($report,$previous);
}
echo phutil_json_encode($report)."\n";
