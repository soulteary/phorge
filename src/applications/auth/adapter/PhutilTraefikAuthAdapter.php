<?php

/**
 * Authentication adapter for Traefik Forward Auth.
 *
 * Identity is provided by HTTP headers set by Traefik after authentication
 * (X-Auth-User, X-Auth-Email, X-Auth-Name). The provider injects these
 * values into the adapter per request before calling getAccountIdentifiers().
 */
final class PhutilTraefikAuthAdapter extends PhutilAuthAdapter {

  private $accountID;
  private $accountEmail;
  private $accountRealName;
  private $accountName;

  public function getAdapterType() {
    return 'traefik';
  }

  public function getAdapterDomain() {
    return 'traefik';
  }

  public function setAccountID($account_id) {
    $this->accountID = $account_id;
    return $this;
  }

  public function getAccountID() {
    return $this->accountID;
  }

  public function setAccountEmail($email) {
    $this->accountEmail = $email;
    return $this;
  }

  public function getAccountEmail() {
    return $this->accountEmail;
  }

  public function setAccountRealName($name) {
    $this->accountRealName = $name;
    return $this;
  }

  public function getAccountRealName() {
    return $this->accountRealName;
  }

  public function setAccountName($name) {
    $this->accountName = $name;
    return $this;
  }

  public function getAccountName() {
    return $this->accountName;
  }

}
