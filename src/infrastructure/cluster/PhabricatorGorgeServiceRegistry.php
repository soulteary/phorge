<?php

/**
 * Single catalog of Gorge capabilities known to this Phorge build.
 *
 * Clients use this catalog for endpoints, tokens and consumer ownership. The
 * unusual mailer, search and notification configuration shapes are registered
 * too, so deployment tooling can enumerate the complete control plane without
 * rediscovering services by scanning unrelated Config option classes.
 */
final class PhabricatorGorgeServiceRegistry extends Phobject {

  private static $services;

  public static function getService($key) {
    $services = self::getServices();
    if (!isset($services[$key])) {
      throw new Exception(pht('Unknown Gorge service "%s".', $key));
    }

    return $services[$key];
  }

  public static function getServices() {
    if (self::$services === null) {
      $services = array(
        self::newService('render', 'Gorge render service',
          'gorge.render.uri', 'gorge.render.token'),
        self::newService('conduit', 'Gorge Conduit gateway',
          'gorge.conduit.uri', 'gorge.conduit.token'),
        self::newService('notification', 'Gorge notification service',
          null, null, null, array('notification.servers')),
        self::newService('mailer', 'Gorge mailer service',
          null, null, null, array('cluster.mailers')),
        self::newService('search', 'Gorge search service',
          null, 'gorge.search.token', null, array('cluster.search')),
        self::newService('file', 'Gorge file storage service',
          'gorge.file.uri', 'gorge.file.token'),
        self::newService('webhook', 'Gorge webhook service',
          'gorge.webhook.uri', 'gorge.webhook.token',
          'gorge.webhook.owner'),
        self::newService('taskqueue', 'Gorge task queue service',
          'gorge.taskqueue.uri', 'gorge.taskqueue.token',
          'gorge.taskqueue.owner'),
        self::newService('db', 'Gorge database service',
          'gorge.db.uri', 'gorge.db.token'),
      );

      self::$services = mpull($services, null, 'getKey');
    }

    return self::$services;
  }

  private static function newService(
    $key,
    $name,
    $uri_key,
    $token_key,
    $owner_key = null,
    array $additional_keys = array()) {

    $keys = array();
    foreach (array($uri_key, $token_key, $owner_key) as $config_key) {
      if ($config_key !== null) {
        $keys[] = $config_key;
      }
    }
    $keys = array_values(array_unique(array_merge($keys, $additional_keys)));

    return id(new PhabricatorGorgeServiceSpec())
      ->setKey($key)
      ->setName($name)
      ->setURIKey($uri_key)
      ->setTokenKey($token_key)
      ->setOwnerKey($owner_key)
      ->setConfigurationKeys($keys);
  }

}
