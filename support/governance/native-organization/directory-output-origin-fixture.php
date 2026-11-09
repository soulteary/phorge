<?php

/** Own-memory observer control only; not a business/transport/SQL fixture. */
function governance_directory_output_origin_test_write($private_argument) {
  echo $private_argument;
}
function governance_directory_output_origin_test_inner_image() {
  $image=imagecreatetruecolor(2,2);
  ob_start();imagepng($image);$bytes=ob_get_clean();unset($image);
  return $bytes;
}

function governance_directory_output_origin_test_C_warning($private_argument) {
  trigger_error($private_argument,E_USER_WARNING);
}

function governance_directory_output_origin_test_C_png() {
  $image=imagecreatetruecolor(2,2);imagepng($image);unset($image);
}
