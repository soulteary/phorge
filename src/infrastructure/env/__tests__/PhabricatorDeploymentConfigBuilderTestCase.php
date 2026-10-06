<?php

final class PhabricatorDeploymentConfigBuilderTestCase
  extends PhabricatorTestCase {

  public function testImageRolloutValidation() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $directory = Filesystem::createTemporaryDirectory();
    try {
      $local = $directory.'/local.json';
      $deployment = $directory.'/deployment.json';
      Filesystem::writeFile($local, '{}');
      execx('env -i GORGE_IMAGE_URI=http://image:8190 GORGE_IMAGE_TOKEN=test-only '.
        'GORGE_IMAGE_MODE=shadow GORGE_IMAGE_SHADOW_PERCENT=25 %s %s full %s %s',
        PHP_BINARY, $root.'/scripts/setup/build_deployment_config.php',
        $deployment, $local);
      $config = phutil_json_decode(Filesystem::readFile($deployment));
      $this->assertEqual('shadow', $config['gorge.image.mode']);
      $this->assertEqual(25, $config['gorge.image.shadow-percent']);
      $this->assertEqual('http://image:8190', $config['gorge.image.uri']);
      foreach (array('GORGE_IMAGE_MODE=invalid',
        'GORGE_IMAGE_SHADOW_PERCENT=101') as $setting) {
        $caught = false;
        try {
          execx('env -i %s %s %s full %s %s', $setting, PHP_BINARY,
            $root.'/scripts/setup/build_deployment_config.php', $deployment, $local);
        } catch (CommandException $ex) {
          $caught = true;
        }
        $this->assertTrue($caught);
      }
    } finally {
      Filesystem::remove($directory);
    }
  }

  public function testFeedPolicyPublishedWithDeployment() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $directory = Filesystem::createTemporaryDirectory();
    try {
      $local = $directory.'/local.json';
      $deployment = $directory.'/deployment.json';
      Filesystem::writeFile($local, phutil_json_encode(array(
        'feed.http-hooks' => array('https://example.test/feed'),
        'phabricator.silent' => true,
      )));
      execx('env -i %s %s full %s %s', PHP_BINARY,
        $root.'/scripts/setup/build_deployment_config.php', $deployment, $local);
      $config = phutil_json_decode(Filesystem::readFile($deployment));
      $policy = phutil_json_decode(
        Filesystem::readFile($directory.'/feed-policy.json'));
      $this->assertEqual($config['feed.http-hooks'], $policy['uris']);
      $this->assertEqual($config['phabricator.silent'], $policy['silent']);
      $this->assertEqual(true, $policy['silent']);
    } finally {
      Filesystem::remove($directory);
    }
  }

  public function testNotificationPolicyAndIndependentOutbox() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $directory = Filesystem::createTemporaryDirectory();
    try {
      $local = $directory.'/local.json';
      $deployment = $directory.'/deployment.json';
      Filesystem::writeFile($local, phutil_json_encode(array(
        'cluster.instance' => 'tenant a',
        'gorge.service-policies' => array('notification' => 'fallback'),
        'notification.servers' => array(
          array('type' => 'admin', 'host' => 'notify', 'port' => 22281,
            'protocol' => 'http', 'path' => 'notify'),
          array('type' => 'admin', 'host' => 'disabled', 'port' => 22281,
            'protocol' => 'http', 'disabled' => true),
          array('type' => 'client', 'host' => 'public', 'port' => 443,
            'protocol' => 'https'),
        ),
      )));
      execx('env -i GORGE_NOTIFICATION_OUTBOX=true %s %s full %s %s',
        PHP_BINARY, $root.'/scripts/setup/build_deployment_config.php',
        $deployment, $local);
      $config = phutil_json_decode(Filesystem::readFile($deployment));
      $policy = phutil_json_decode(Filesystem::readFile(
        $directory.'/notification-policy.json'));
      $this->assertEqual(true, $config['gorge.notification.outbox']);
      $this->assertEqual('tenant a', $policy['instance']);
      $this->assertEqual('fallback', $policy['mode']);
      $this->assertEqual(array('http://notify:22281/notify/'), $policy['endpoints']);
      execx('env -i %s %s full %s %s', PHP_BINARY,
        $root.'/scripts/setup/build_deployment_config.php', $deployment, $local);
      $config = phutil_json_decode(Filesystem::readFile($deployment));
      $this->assertEqual(false, $config['gorge.notification.outbox']);
    } finally {
      Filesystem::remove($directory);
    }
  }

  public function testNotificationDisableAndInstanceResetOverrideOldDeployment() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $directory = Filesystem::createTemporaryDirectory();
    try {
      $local = $directory.'/local.json';
      $deployment = $directory.'/deployment.json';
      Filesystem::writeFile($deployment, phutil_json_encode(array(
        'cluster.instance' => 'old-instance',
      )));
      Filesystem::writeFile($local, phutil_json_encode(array(
        'cluster.instance' => null,
        'notification.servers' => array(array(
          'type' => 'admin', 'host' => 'old', 'port' => 22281,
          'protocol' => 'http',
        )),
      )));
      execx('env -i GORGE_NOTIFICATION_MODE=disable %s %s full %s %s',
        PHP_BINARY, $root.'/scripts/setup/build_deployment_config.php',
        $deployment, $local);
      $config = phutil_json_decode(Filesystem::readFile($deployment));
      $policy = phutil_json_decode(Filesystem::readFile(
        $directory.'/notification-policy.json'));
      $this->assertEqual(array(), $config['notification.servers']);
      $this->assertEqual(null, $config['cluster.instance']);
      $this->assertEqual(array(), $policy['endpoints']);
      $this->assertEqual('default', $policy['instance']);
    } finally {
      Filesystem::remove($directory);
    }
  }

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

  public function testRegenerationRefreshesUserManagedValues() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $script = $root.'/scripts/setup/build_deployment_config.php';

    $directory = Filesystem::createTemporaryDirectory();
    $local_path = $directory.'/local.json';
    $deployment_path = $directory.'/deployment.json';

    $local = array(
      'phabricator.allowed-uris' => array('https://current.example/'),
      'cluster.mailers' => array(
        array(
          'key' => 'current-smtp',
          'type' => 'smtp',
        ),
      ),
      'cluster.search' => array(
        array(
          'type' => 'elasticsearch',
          'hosts' => array(),
        ),
      ),
    );

    $stale = array(
      'phabricator.allowed-uris' => array('https://stale.example/'),
      'cluster.mailers' => array(
        array(
          'key' => 'stale-smtp',
          'type' => 'smtp',
        ),
        array(
          'key' => 'gorge-mailer',
          'type' => 'gorge',
        ),
      ),
      'cluster.search' => array(
        array(
          'type' => 'mysql',
        ),
        array(
          'type' => 'gorge',
          'hosts' => array(),
        ),
      ),
      'gitea.uri' => 'https://stale-gitea.example/',
    );

    try {
      Filesystem::writeFile(
        $local_path,
        phutil_json_encode($local));
      Filesystem::writeFile(
        $deployment_path,
        phutil_json_encode($stale));

      execx(
        'env -i '.
        'GORGE_CONDUIT_UPSTREAM_URL=http://phorge:80 '.
        'GORGE_MAILER_MODE=disable '.
        'GORGE_SEARCH_MODE=disable '.
        'GITEA_BASE_URI= '.
        '%s %s collaboration %s %s',
        PHP_BINARY,
        $script,
        $deployment_path,
        $local_path);

      $config = phutil_json_decode(
        Filesystem::readFile($deployment_path));

      $this->assertEqual(
        array(
          'https://current.example/',
          'http://phorge/',
        ),
        $config['phabricator.allowed-uris']);
      $this->assertEqual(
        $local['cluster.mailers'],
        $config['cluster.mailers']);
      $this->assertEqual(
        $local['cluster.search'],
        $config['cluster.search']);
      $this->assertFalse(array_key_exists('gitea.uri', $config));

      // Optional profile jobs do not receive GITEA_BASE_URI. Absence means
      // they must preserve the value installed by the base migration job;
      // only the explicit empty value above removes it.
      $config['gitea.uri'] = 'https://gitea.example/';
      Filesystem::writeFile(
        $deployment_path,
        phutil_json_encode($config));
      execx(
        'env -i GORGE_MAILER_MODE=disable '.
        '%s %s collaboration %s %s',
        PHP_BINARY,
        $script,
        $deployment_path,
        $local_path);
      $config = phutil_json_decode(
        Filesystem::readFile($deployment_path));
      $this->assertEqual(
        'https://gitea.example/',
        $config['gitea.uri']);
    } finally {
      Filesystem::remove($directory);
    }
  }

  public function testLegacyLocalStateMigration() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $script = $root.'/scripts/setup/manage_collaboration_local.php';

    $directory = Filesystem::createTemporaryDirectory();
    $local_path = $directory.'/local.json';
    $profile_state_path = $directory.'/profile-state.json';
    $taskqueue_state_path = $directory.'/taskqueue-state.json';
    $notification_state_path = $directory.'/notification-state.json';

    $local = array(
      'phorge.product-profile' => 'collaboration',
      'gorge.diff.enabled' => false,
      'gitea.uri' => 'https://gitea.example/',
      'gorge.taskqueue.uri' => 'http://gorge-taskqueue:8090',
      'phd.taskmasters' => 0,
      'notification.servers' => array(
        array(
          'type' => 'admin',
          'host' => 'gorge-notification',
          'port' => 22281,
        ),
      ),
    );

    try {
      Filesystem::writeFile(
        $local_path,
        phutil_json_encode($local));
      Filesystem::writeFile(
        $taskqueue_state_path,
        phutil_json_encode(
          array(
            'present' => false,
            'value' => null,
          )));
      Filesystem::writeFile(
        $notification_state_path,
        phutil_json_encode(
          array(
            'present' => false,
            'value' => null,
          )));
      $local_owner = fileowner($local_path);
      $local_group = filegroup($local_path);

      execx(
        '%s %s full %s %s %s %s',
        PHP_BINARY,
        $script,
        $local_path,
        $profile_state_path,
        $taskqueue_state_path,
        $notification_state_path);

      $config = phutil_json_decode(
        Filesystem::readFile($local_path));
      $this->assertEqual('full', $config['phorge.product-profile']);
      $this->assertFalse(array_key_exists('gorge.diff.enabled', $config));
      $this->assertFalse(array_key_exists('gitea.uri', $config));
      $this->assertFalse(array_key_exists('phd.taskmasters', $config));
      $this->assertFalse(array_key_exists('notification.servers', $config));
      $this->assertFalse(Filesystem::pathExists($taskqueue_state_path));
      $this->assertFalse(Filesystem::pathExists($notification_state_path));
      $this->assertEqual($local_owner, fileowner($local_path));
      $this->assertEqual($local_group, filegroup($local_path));
    } finally {
      Filesystem::remove($directory);
    }
  }

  public function testGorgePolicyDefaultsToRequired() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $script = $root.'/scripts/setup/build_deployment_config.php';

    $directory = Filesystem::createTemporaryDirectory();
    $local_path = $directory.'/local.json';
    $deployment_path = $directory.'/deployment.json';

    try {
      Filesystem::writeFile($local_path, "{}\n");
      execx(
        'env -i %s %s full %s %s',
        PHP_BINARY,
        $script,
        $deployment_path,
        $local_path);

      $config = phutil_json_decode(Filesystem::readFile($deployment_path));
      $this->assertEqual('required', $config['gorge.service-policy']);
    } finally {
      Filesystem::remove($directory);
    }
  }

  public function testGorgePolicyCanEnableMigrationFallback() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $script = $root.'/scripts/setup/build_deployment_config.php';

    $directory = Filesystem::createTemporaryDirectory();
    $local_path = $directory.'/local.json';
    $deployment_path = $directory.'/deployment.json';

    try {
      Filesystem::writeFile($local_path, "{}\n");
      execx(
        'env -i PHORGE_GORGE_POLICY=fallback %s %s full %s %s',
        PHP_BINARY,
        $script,
        $deployment_path,
        $local_path);

      $config = phutil_json_decode(Filesystem::readFile($deployment_path));
      $this->assertEqual('fallback', $config['gorge.service-policy']);
    } finally {
      Filesystem::remove($directory);
    }
  }

  public function testSearchOffPreservesNativeService() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $script = $root.'/scripts/setup/build_deployment_config.php';

    $directory = Filesystem::createTemporaryDirectory();
    $local_path = $directory.'/local.json';
    $deployment_path = $directory.'/deployment.json';

    try {
      Filesystem::writeFile($local_path, "{}\n");
      execx(
        'env -i '.
        'PHORGE_GORGE_POLICY=off '.
        'GORGE_SEARCH_MODE=enable '.
        'GORGE_SEARCH_HOST=gorge-search '.
        'GORGE_SEARCH_KEEP_MYSQL=0 '.
        '%s %s full %s %s',
        PHP_BINARY,
        $script,
        $deployment_path,
        $local_path);

      $config = phutil_json_decode(Filesystem::readFile($deployment_path));
      $this->assertEqual(
        array('gorge', 'mysql'),
        ipull($config['cluster.search'], 'type'));
    } finally {
      Filesystem::remove($directory);
    }
  }

  public function testSearchFallbackPreservesNativeService() {
    $root = dirname(phutil_get_library_root('phabricator'));
    $script = $root.'/scripts/setup/build_deployment_config.php';

    $directory = Filesystem::createTemporaryDirectory();
    $local_path = $directory.'/local.json';
    $deployment_path = $directory.'/deployment.json';

    try {
      Filesystem::writeFile($local_path, "{}\n");
      execx(
        'env -i '.
        'PHORGE_GORGE_POLICY=fallback '.
        'GORGE_SEARCH_MODE=enable '.
        'GORGE_SEARCH_HOST=gorge-search '.
        'GORGE_SEARCH_KEEP_MYSQL=0 '.
        '%s %s full %s %s',
        PHP_BINARY,
        $script,
        $deployment_path,
        $local_path);

      $config = phutil_json_decode(Filesystem::readFile($deployment_path));
      $this->assertEqual(
        array('gorge', 'mysql'),
        ipull($config['cluster.search'], 'type'));
    } finally {
      Filesystem::remove($directory);
    }
  }

}
