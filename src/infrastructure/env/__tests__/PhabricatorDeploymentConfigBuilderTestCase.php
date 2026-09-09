<?php

final class PhabricatorDeploymentConfigBuilderTestCase
  extends PhabricatorTestCase {

  public function testSearchHostShape() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $script = $root.'/scripts/setup/build_deployment_config.php';

    $directory = Filesystem::createTemporaryDirectory();
    $local_path = $directory.'/local.json';
    $deployment_path = $directory.'/deployment.json';

    try {
      Filesystem::writeFile($local_path, "{}\n");

      execx(
        'env -i '.
        'GORGE_SEARCH_MODE=enable '.
        'GORGE_SEARCH_HOST=gorge-search '.
        'GORGE_SEARCH_PORT=8120 '.
        'GORGE_SEARCH_PROTOCOL=http '.
        '%s %s full %s %s',
        PHP_BINARY,
        $script,
        $deployment_path,
        $local_path);

      $config = phutil_json_decode(
        Filesystem::readFile($deployment_path));
      $search = $config['cluster.search'];

      $this->assertEqual(
        array(
          array(
            'type' => 'gorge',
            'hosts' => array(
              array(
                'host' => 'gorge-search',
                'port' => 8120,
                'protocol' => 'http',
                'roles' => array(
                  'read' => true,
                  'write' => true,
                ),
              ),
            ),
          ),
        ),
        $search);

      // Exercise the same validation which Config applies at runtime. This
      // catches the tempting but invalid shorthand "hosts: [hostname]".
      PhabricatorClusterSearchConfigType::validateValue($search);
    } finally {
      Filesystem::remove($directory);
    }
  }

}
