<?php

final class PhorgeExtensionsConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Extensions');
  }

  public function getDescription() {
    return pht('Managing and installing extensions');
  }

  public function getGroup() {
    return 'core';
  }

  public function getApplicationClassName() {
    return PhorgeExtensionsApplication::class;
  }

  public function getOptions() {
    $options = array();

    $default_install_dir = Filesystem::resolvePath(
      '../../managed-extensions/',
      phutil_get_library_root('phorge'));

    $options[] = $this->newOption(
      'extensions.install-dir',
      'string',
      $default_install_dir)
      ->setLocked(true)
      ->setDescription(pht('Location to download and install extensions to.'));

    $default_extension_store = array(
      array(
        'name' => 'Phorge',
        'uri' => 'https://extensions.phorge.it/',
      ),
    );

    $options[] = $this->newOption(
      'extensions.extension-stores',
      'wild',
      $default_extension_store)
      ->setLocked(true)
      ->setDescription(pht('Allowed Extension Stores to use.'));

    $options[] = $this->newOption(
      'phorge.product-profile',
      'string',
      'full')
      ->setLocked(true)
      ->setDescription(
        pht(
          'Deployment profile. The "collaboration" profile keeps Maniphest, ' .
          'projects, documents and chat while an external forge owns code. ' .
          'The deployment configuration source owns this value in the ' .
          'default container stack.'));

    $options[] = $this->newOption(
      'gorge.service-policy',
      'string',
      PhabricatorGorgeServiceSpec::POLICY_REQUIRED)
      ->setLocked(true)
      ->setDescription(
        pht(
          'Failure policy for configured Gorge services. "required" exposes '.
          'service failures, "fallback" temporarily allows native '.
          'implementations, and "off" disables request-routed services. '.
          'The "render" service is an exception for difference generation: '.
          'the native GNU and PHP difference engines have been removed, so '.
          'neither "fallback" nor "off" has a local implementation to select '.
          'and raw or prose differences fail while the service is '.
          'unavailable.'));

    $options[] = $this->newOption(
      'gorge.service-policies',
      'wild',
      array())
      ->setLocked(true)
      ->setDescription(
        pht(
          'Optional per-service overrides for "gorge.service-policy", keyed '.
          'by Gorge service registry identifier.'));

    $options[] = $this->newOption(
      'gitea.uri',
      'string',
      null)
      ->setLocked(true)
      ->setDescription(
        pht('Base URI of the Gitea instance shown in the global navigation.'));

    return $options;
  }

}
