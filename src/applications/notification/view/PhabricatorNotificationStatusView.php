<?php

final class PhabricatorNotificationStatusView extends AphrontTagView {

  private $request;
  private $didInitializeBehavior = false;

  public function setRequest(AphrontRequest $request) {
    $this->request = $request;
    return $this;
  }

  protected function getTagAttributes() {
    if ($this->getClientState() !== 'connecting' ||
        $this->didInitializeBehavior) {
      return array(
        'class' => 'aphlict-connection-status',
      );
    }

    if (!$this->getID()) {
      $this->setID(celerity_generate_unique_node_id());
    }

    Javelin::initBehavior(
      'aphlict-status',
      array(
        'nodeID' => $this->getID(),
        'pht' => array(
          'setup' => pht('Setting Up Client'),
          'open' => pht('Connected'),
          'closed' => pht('Disconnected'),
        ),
        'icon' => array(
          'open' => array(
            'icon' => 'fa-circle',
            'color' => 'green',
          ),
          'setup' => array(
            'icon' => 'fa-circle',
            'color' => 'yellow',
          ),
          'closed' => array(
            'icon' => 'fa-circle',
            'color' => 'red',
          ),
        ),
      ));
    $this->didInitializeBehavior = true;

    return array(
      'class' => 'aphlict-connection-status',
    );
  }

  protected function getTagContent() {
    $state = $this->getClientState();
    $icon = 'fa-circle-o grey';
    switch ($state) {
      case 'disabled':
        $message = pht('Notifications are disabled.');
        break;
      case 'notenabled':
        $message = pht('Notification server not enabled');
        break;
      case 'noclients':
        $message = pht('No enabled browser notification servers.');
        break;
      case 'protocol':
        $message = pht(
          'No notification server supports this page protocol (%s).',
          $this->getClientProtocol());
        $icon = 'fa-exclamation-triangle yellow';
        break;
      case 'signin':
        $message = pht('Sign in to connect to notifications.');
        break;
      case 'unavailable':
        $message = pht('Notification connection status is unavailable.');
        break;
      case 'connecting':
        $message = pht('Connecting...');
        $icon = 'fa-circle-o yellow';
        break;
    }

    return $this->buildMessageView(
      'aphlict-connection-status-'.$state,
      $icon,
      $message);
  }

  private function getClientState() {
    $service = PhabricatorGorgeServiceRegistry::getService('notification');
    if ($service->isDisabled()) {
      return 'disabled';
    }

    if (!PhabricatorEnv::getEnvConfig('notification.servers')) {
      return 'notenabled';
    }

    $clients = array();
    foreach (PhabricatorNotificationServerRef::getEnabledServers() as $server) {
      if (!$server->isAdminServer()) {
        $clients[] = $server;
      }
    }
    if (!$clients) {
      return 'noclients';
    }

    if (!$this->request || !$this->hasViewer()) {
      return 'unavailable';
    }

    // Match the conditions under which StandardPageView starts JX.Aphlict.
    $servers = PhabricatorNotificationServerRef::getEnabledClientServers(
      $this->getClientProtocol());
    if (!$servers) {
      return 'protocol';
    }

    if (!$this->getViewer()->isLoggedIn()) {
      return 'signin';
    }

    return 'connecting';
  }

  private function getClientProtocol() {
    return $this->request->isHTTPS() ? 'https' : 'http';
  }

  /**
   * Create an icon and a message.
   *
   * @param  string $class_name Raw CSS class name(s) space separated
   * @param  string $icon_name  Icon name
   * @param  string $text       Text to be shown
   * @return array
   */
  private function buildMessageView($class_name, $icon_name, $text) {
    $icon = id(new PHUIIconView())
      ->setIcon($icon_name);

    $message = phutil_tag(
      'span',
      array(
        'class' => 'connection-status-text '.$class_name,
      ),
      $text);

    return array(
      $icon,
      $message,
    );
  }

}
