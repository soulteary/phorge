<?php
// Export the current PHP planner as an oracle, without pixel decode or DB writes.
require_once dirname(__DIR__).'/bootstrap.php';
$method = new ReflectionMethod('PhabricatorFileThumbnailTransform', 'computeDimensions');
$rows = array();
foreach (array(array(1,1), array(16,9), array(9,16), array(1000,10),
  array(10,1000), array(101,77), array(77,101), array(640,480),
  array(480,640), array(400,400), array(526,263)) as $input) {
  foreach (id(new PhabricatorFileThumbnailTransform())->generateTransforms() as $recipe) {
    $props = new ReflectionObject($recipe);
    $x = $props->getProperty('dstX');
    $y = $props->getProperty('dstY');
    $up = $props->getProperty('scaleUp');
    $d = $method->invoke($recipe, $input[0], $input[1], $x->getValue($recipe), $y->getValue($recipe));
    $px = min($d['dst_x'], $d['use_x']);
    $py = min($d['dst_y'], $d['use_y']);
    if (!$up->getValue($recipe)) {
      $px = min($px, $d['copy_x']); $py = min($py, $d['copy_y']);
    }
    $rows[] = array('Width' => $input[0], 'Height' => $input[1],
      'Recipe' => $recipe->getTransformKey(), 'Plan' => array(
      'Width' => (int)$d['dst_x'], 'Height' => (int)$d['dst_y'],
      'CropX' => (int)(($input[0] - $d['copy_x']) / 2),
      'CropY' => (int)(($input[1] - $d['copy_y']) / 2),
      'CropWidth' => (int)$d['copy_x'], 'CropHeight' => (int)$d['copy_y'],
      'CopyWidth' => (int)$px, 'CopyHeight' => (int)$py,
      'OffsetX' => (int)(($d['dst_x'] - $px) / 2),
      'OffsetY' => (int)(($d['dst_y'] - $py) / 2)));
  }
}
echo json_encode($rows, JSON_PRETTY_PRINT)."\n";
